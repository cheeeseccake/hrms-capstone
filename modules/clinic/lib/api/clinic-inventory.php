<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=utf-8');

function clinic_inventory_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require_once dirname(__DIR__, 4) . '/auth/session.php';
require_once dirname(__DIR__, 4) . '/database/db.php';
require_once dirname(__DIR__) . '/clinic-inventory.php';

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) throw new RuntimeException('Database connection unavailable.');
} catch (Throwable $e) {
    error_log('Clinic inventory DB connection error: ' . $e->getMessage());
    clinic_inventory_json(503, ['success' => false, 'message' => 'Clinic inventory is unavailable.']);
}

$actorId = (int) ($_SESSION['employee_id'] ?? 0);
$roleName = trim((string) ($_SESSION['role_name'] ?? ''));
$roleId = (int) ($_SESSION['role_id'] ?? 0);
$allowedRoleIds = [];
try {
    $roles = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
    $roles->execute();
    $allowedRoleIds = array_values(array_filter(array_map('intval', explode(',', (string) $roles->fetchColumn())), static fn(int $id): bool => $id > 0));
} catch (Throwable $e) {
    // Use the same explicit role-name fallback as clinic visit recording.
}
$allowed = $allowedRoleIds !== []
    ? in_array($roleId, $allowedRoleIds, true)
    : in_array($roleName, ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
if ($actorId <= 0 || !$allowed) {
    clinic_inventory_json(403, ['success' => false, 'message' => 'You are not authorized to access clinic inventory.']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET') {
        $items = clinic_inventory_fetch($db);
        clinic_inventory_json(200, ['success' => true, 'items' => $items, 'transactions' => clinic_inventory_transactions($db)]);
    }

    if ($method !== 'POST') clinic_inventory_json(405, ['success' => false, 'message' => 'Unsupported request method.']);
    $input = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($input)) clinic_inventory_json(400, ['success' => false, 'message' => 'Invalid request body.']);
    $sessionToken = (string) ($_SESSION['eh_csrf_token'] ?? '');
    $requestToken = (string) ($input['csrf_token'] ?? '');
    if ($sessionToken === '' || $requestToken === '' || !hash_equals($sessionToken, $requestToken)) {
        clinic_inventory_json(403, ['success' => false, 'message' => 'Your session token expired. Refresh the page and try again.']);
    }

    $action = (string) ($input['action'] ?? '');
    if ($action === 'create' || $action === 'update') {
        $name = trim((string) ($input['name'] ?? ''));
        $medicineId = filter_var($input['medicine_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
        $minimum = filter_var($input['minimum'] ?? 0, FILTER_VALIDATE_INT);
        $expires = trim((string) ($input['expires'] ?? ''));
        $unitToDosage = [
            'Tablets' => 'Tablet', 'Capsules' => 'Capsule', 'Bottles' => 'Liquid',
            'Vials' => 'Injection', 'Tubes' => 'Ointment', 'Pieces' => 'Other',
            'Boxes' => 'Other', 'Packs' => 'Other', 'Units' => 'Other',
        ];
        $unit = (string) ($input['unit'] ?? 'Units');
        if ($name === '' || mb_strlen($name) > 200 || !isset($unitToDosage[$unit])
            || $quantity === false || $quantity === null || $quantity < 0 || $quantity > 2147483647
            || $minimum === false || $minimum === null || $minimum < 0 || $minimum > 2147483647
            || ($action === 'update' && (!$medicineId || $medicineId <= 0))) {
            clinic_inventory_json(422, ['success' => false, 'message' => 'Check the item name, unit, and stock values.']);
        }
        if ($expires !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires)
            || !checkdate((int) substr($expires, 5, 2), (int) substr($expires, 8, 2), (int) substr($expires, 0, 4)))) {
            clinic_inventory_json(422, ['success' => false, 'message' => 'Enter a valid expiration date.']);
        }
        $genericName = trim((string) ($input['description'] ?? ''));
        $manufacturer = trim((string) ($input['supplier'] ?? ''));
        $storage = trim((string) ($input['storage'] ?? ''));
        if (mb_strlen($genericName) > 200 || mb_strlen($manufacturer) > 200 || mb_strlen($storage) > 500) {
            clinic_inventory_json(422, ['success' => false, 'message' => 'One or more medicine details exceed the allowed length.']);
        }
        $db->beginTransaction();
        $status = clinic_inventory_status((int) $quantity, (int) $minimum, $expires);
        if ($status === 'Expiring Soon') $status = 'Available';
        if ($action === 'create') {
            $save = $db->prepare("
                INSERT INTO cm_medicine_inventory
                    (medicine_name, generic_name, category, dosage_form, current_stock, reorder_level,
                     expiry_date, manufacturer, storage_requirements, status, created_by)
                VALUES (:name, :generic, 'Medicine', :form, :stock, :minimum,
                        :expires, :manufacturer, :storage, :status, :actor)
            ");
            $save->execute([
                ':name' => $name, ':generic' => $genericName ?: null, ':form' => $unitToDosage[$unit],
                ':stock' => $quantity, ':minimum' => $minimum, ':expires' => $expires ?: null,
                ':manufacturer' => $manufacturer ?: null, ':storage' => $storage ?: null, ':status' => $status,
                ':actor' => (int) ($_SESSION['user_id'] ?? 0) ?: null,
            ]);
            if ((int) $quantity > 0) {
                $initialStock = $db->prepare("
                    INSERT INTO cm_medicine_inventory_transactions
                        (medicine_id, movement_type, quantity_change, transaction_date, personnel, notes)
                    VALUES (:id, 'stock_in', :quantity, CURDATE(), :personnel, 'Initial stock on registration')
                ");
                $initialStock->execute([
                    ':id' => (int) $db->lastInsertId(),
                    ':quantity' => (int) $quantity,
                    ':personnel' => substr((string) ($_SESSION['employee_name'] ?? 'Clinic staff'), 0, 160),
                ]);
            }
        } else {
            $lock = $db->prepare('SELECT current_stock FROM cm_medicine_inventory WHERE medicine_id = :id FOR UPDATE');
            $lock->execute([':id' => $medicineId]);
            $previousStock = $lock->fetchColumn();
            if ($previousStock === false) {
                $db->rollBack();
                clinic_inventory_json(404, ['success' => false, 'message' => 'The medicine was not found.']);
            }
            $save = $db->prepare("
                UPDATE cm_medicine_inventory
                SET medicine_name = :name, generic_name = :generic, dosage_form = :form,
                    current_stock = :stock, reorder_level = :minimum, expiry_date = :expires,
                    manufacturer = :manufacturer, storage_requirements = :storage, status = :status
                WHERE medicine_id = :id
            ");
            $save->execute([
                ':name' => $name, ':generic' => $genericName ?: null, ':form' => $unitToDosage[$unit],
                ':stock' => $quantity, ':minimum' => $minimum, ':expires' => $expires ?: null,
                ':manufacturer' => $manufacturer ?: null, ':storage' => $storage ?: null, ':status' => $status,
                ':id' => $medicineId,
            ]);
            $adjustment = (int) $quantity - (int) $previousStock;
            if ($adjustment !== 0) {
                $log = $db->prepare("
                    INSERT INTO cm_medicine_inventory_transactions
                        (medicine_id, movement_type, quantity_change, transaction_date, personnel, notes)
                    VALUES (:id, 'adjustment', :change, CURDATE(), :personnel, 'Inventory item edited')
                ");
                $log->execute([
                    ':id' => $medicineId, ':change' => $adjustment,
                    ':personnel' => substr((string) ($_SESSION['employee_name'] ?? 'Clinic staff'), 0, 160),
                ]);
            }
        }
        $db->commit();
        clinic_inventory_json(200, [
            'success' => true,
            'message' => $action === 'create' ? 'Medicine added to inventory.' : 'Medicine details updated.',
            'items' => clinic_inventory_fetch($db),
            'transactions' => clinic_inventory_transactions($db),
        ]);
    }

    $quantity = filter_var($input['quantity'] ?? null, FILTER_VALIDATE_INT);
    $medicineId = filter_var($input['medicine_id'] ?? null, FILTER_VALIDATE_INT);
    if ($quantity === false || $quantity === null || $quantity <= 0 || $quantity > 2147483647) {
        clinic_inventory_json(422, ['success' => false, 'message' => 'Enter a whole-number quantity greater than zero.']);
    }
    if ($medicineId === false || $medicineId === null || $medicineId <= 0) {
        clinic_inventory_json(422, ['success' => false, 'message' => 'Select a valid medicine.']);
    }

    $db->beginTransaction();
    $lock = $db->prepare('SELECT medicine_id, medicine_name, current_stock, reorder_level, expiry_date, status FROM cm_medicine_inventory WHERE medicine_id = :id FOR UPDATE');
    $lock->execute([':id' => $medicineId]);
    $medicine = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$medicine) {
        $db->rollBack();
        clinic_inventory_json(404, ['success' => false, 'message' => 'The selected medicine was not found.']);
    }

    if ($action === 'dispense') {
        $expires = (string) ($medicine['expiry_date'] ?? '');
        if ((string) $medicine['status'] === 'Expired' || ($expires !== '' && $expires < date('Y-m-d'))) {
            $db->rollBack();
            clinic_inventory_json(422, ['success' => false, 'message' => 'Expired medicine cannot be dispensed.']);
        }
        if ((int) $medicine['current_stock'] < $quantity) {
            $db->rollBack();
            clinic_inventory_json(409, ['success' => false, 'message' => 'Available stock changed. Refresh inventory and try again.']);
        }
        $movement = 'dispense';
        $quantityChange = -$quantity;
    } elseif ($action === 'stock-in') {
        $movement = 'stock_in';
        $quantityChange = $quantity;
    } else {
        $db->rollBack();
        clinic_inventory_json(422, ['success' => false, 'message' => 'Unsupported inventory action.']);
    }

    $date = (string) ($input['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
        $db->rollBack();
        clinic_inventory_json(422, ['success' => false, 'message' => 'Enter a valid transaction date.']);
    }
    $personnel = trim((string) ($input['personnel'] ?? $_SESSION['employee_name'] ?? ''));
    if ($personnel === '' || mb_strlen($personnel) > 160) {
        $db->rollBack();
        clinic_inventory_json(422, ['success' => false, 'message' => 'Enter the responsible personnel name.']);
    }
    $notes = trim((string) ($input['notes'] ?? ''));
    $requestedExpiry = trim((string) ($input['expires'] ?? ''));
    if ($requestedExpiry !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedExpiry)
        || !checkdate((int) substr($requestedExpiry, 5, 2), (int) substr($requestedExpiry, 8, 2), (int) substr($requestedExpiry, 0, 4)))) {
        $db->rollBack();
        clinic_inventory_json(422, ['success' => false, 'message' => 'Enter a valid expiration date.']);
    }
    $visitId = filter_var($input['visit_id'] ?? null, FILTER_VALIDATE_INT);
    $newStock = (int) $medicine['current_stock'] + $quantityChange;
    $effectiveExpiry = $requestedExpiry !== '' ? $requestedExpiry : (string) ($medicine['expiry_date'] ?? '');
    $status = clinic_inventory_status($newStock, (int) $medicine['reorder_level'], $effectiveExpiry);
    if ($status === 'Expiring Soon') $status = 'Available';

    $expiryUpdate = $action === 'stock-in' && $requestedExpiry !== '' ? ', expiry_date = :expires' : '';
    $manufacturerUpdate = $action === 'stock-in' && trim((string) ($input['supplier'] ?? '')) !== '' ? ', manufacturer = :manufacturer' : '';
    $update = $db->prepare('UPDATE cm_medicine_inventory SET current_stock = :stock, status = :status' . $expiryUpdate . $manufacturerUpdate . ' WHERE medicine_id = :id');
    $updateParameters = [':stock' => $newStock, ':status' => $status, ':id' => $medicineId];
    if ($expiryUpdate !== '') $updateParameters[':expires'] = $requestedExpiry;
    if ($manufacturerUpdate !== '') $updateParameters[':manufacturer'] = mb_substr(trim((string) $input['supplier']), 0, 200);
    $update->execute($updateParameters);
    $transaction = $db->prepare("
        INSERT INTO cm_medicine_inventory_transactions
            (medicine_id, movement_type, quantity_change, transaction_date, personnel, notes, visit_id)
        VALUES (:medicine_id, :movement, :quantity, :date, :personnel, :notes, :visit_id)
    ");
    $transaction->execute([
        ':medicine_id' => $medicineId,
        ':movement' => $movement,
        ':quantity' => $quantityChange,
        ':date' => $date,
        ':personnel' => $personnel,
        ':notes' => mb_substr($notes, 0, 500),
        ':visit_id' => $visitId ?: null,
    ]);
    $db->commit();
    clinic_inventory_json(200, [
        'success' => true,
        'message' => $action === 'dispense' ? 'Medicine dispensed and inventory updated.' : 'Stock received and inventory updated.',
        'items' => clinic_inventory_fetch($db),
        'transactions' => clinic_inventory_transactions($db),
        'transaction' => [
            'id' => 'TRX-' . (int) $db->lastInsertId(),
            'name' => (string) $medicine['medicine_name'],
            'quantity' => $quantityChange,
            'date' => $date,
            'personnel' => $personnel,
        ],
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('Clinic inventory API error: ' . $e->getMessage());
    clinic_inventory_json(500, ['success' => false, 'message' => 'The inventory change could not be completed.']);
}
