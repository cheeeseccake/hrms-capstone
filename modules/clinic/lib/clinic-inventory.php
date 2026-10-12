<?php
declare(strict_types=1);

function clinic_inventory_fetch(PDO $db): array
{
    $statement = $db->query("
        SELECT medicine_id, medicine_name, generic_name, category, dosage_form, strength,
               current_stock, reorder_level, expiry_date, manufacturer, storage_requirements, status
        FROM cm_medicine_inventory
        ORDER BY medicine_name, medicine_id
    ");
    $items = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $quantity = max(0, (int) $row['current_stock']);
        $minimum = max(0, (int) $row['reorder_level']);
        $expires = (string) ($row['expiry_date'] ?? '');
        $status = (string) $row['status'] === 'Expired'
            ? 'Expired'
            : clinic_inventory_status($quantity, $minimum, $expires);
        $unitLabels = [
            'Tablet' => 'Tablets',
            'Capsule' => 'Capsules',
            'Liquid' => 'Bottles',
            'Injection' => 'Vials',
            'Ointment' => 'Tubes',
            'Other' => 'Units',
        ];
        $items[] = [
            'id' => 'MED-' . (int) $row['medicine_id'],
            'medicineId' => (int) $row['medicine_id'],
            'name' => (string) $row['medicine_name'],
            'strength' => (string) ($row['strength'] ?? ''),
            'category' => 'Medicine',
            'medicineCategory' => (string) ($row['category'] ?? ''),
            'quantity' => $quantity,
            'unit' => $unitLabels[$row['dosage_form'] ?? ''] ?? 'Units',
            'dosageForm' => (string) ($row['dosage_form'] ?? 'Other'),
            'minimum' => $minimum,
            'expires' => $expires,
            'status' => $status,
            'description' => (string) ($row['generic_name'] ?? ''),
            'supplier' => (string) ($row['manufacturer'] ?? ''),
            'batch' => '',
            'storage' => (string) ($row['storage_requirements'] ?? ''),
            'received' => '',
        ];
    }
    return $items;
}

function clinic_inventory_status(int $quantity, int $minimum, string $expires): string
{
    $today = new DateTimeImmutable('today');
    if ($expires !== '') {
        try {
            $expiryDate = new DateTimeImmutable($expires);
            if ($expiryDate < $today) return 'Expired';
            if ($expiryDate <= $today->modify('+30 days') && $quantity > 0) return 'Expiring Soon';
        } catch (Throwable $e) {
            error_log('Invalid clinic medicine expiration date: ' . $e->getMessage());
        }
    }
    if ($quantity <= 0) return 'Out of Stock';
    if ($quantity <= $minimum) return 'Low Stock';
    return 'Available';
}

function clinic_inventory_transactions(PDO $db, int $limit = 100): array
{
    $limit = max(1, min($limit, 500));
    $statement = $db->query("
        SELECT t.transaction_id, i.medicine_name, i.strength, t.quantity_change, t.transaction_date, t.personnel
        FROM cm_medicine_inventory_transactions t
        INNER JOIN cm_medicine_inventory i ON i.medicine_id = t.medicine_id
        ORDER BY t.transaction_id DESC
        LIMIT {$limit}
    ");
    return array_map(static function (array $transaction): array {
        return [
            'id' => 'TRX-' . (int) $transaction['transaction_id'],
            'name' => trim((string) $transaction['medicine_name'] . ((string) ($transaction['strength'] ?? '') !== '' ? ' ' . $transaction['strength'] : '')),
            'quantity' => (int) $transaction['quantity_change'],
            'date' => (string) $transaction['transaction_date'],
            'personnel' => (string) $transaction['personnel'],
        ];
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
}
