<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function employeeLookupResponse(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    employeeLookupResponse(405, [
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
}

// Run the full portal guard: idle timeout, account status, role status,
// and refreshed permissions are checked before processing this request.
lcAdminRequireLogin();

if (!lcAdminHasPermission('accounts.manage')) {
    employeeLookupResponse(403, [
        'success' => false,
        'message' => 'You do not have permission to search employees for portal registration.'
    ]);
}

if (!lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
    employeeLookupResponse(419, [
        'success' => false,
        'message' => 'Your session token is invalid. Refresh the page and try again.'
    ]);
}

$employeeIdRaw = trim((string) ($_POST['employee_id'] ?? ''));

if (!preg_match('/^[0-9]{1,11}$/', $employeeIdRaw) || (int) $employeeIdRaw < 1) {
    employeeLookupResponse(422, [
        'success' => false,
        'message' => 'Enter a valid numeric Employee ID.'
    ]);
}

try {
    $pdo = lcAdminDatabase();

    $stmt = $pdo->prepare("
        SELECT
            e.employee_id,
            e.first_name,
            e.last_name,
            e.middle_name,
            e.suffix,
            e.email,
            er.role_name
        FROM em_employees e
        INNER JOIN em_roles er ON er.role_id = e.role_id
        WHERE e.employee_id = ?
          AND e.role_id IN (8, 26, 27)
          AND er.status = 'Active'
          AND e.employment_status = 'Active'
          AND COALESCE(e.is_archived, 0) = 0
          AND NOT EXISTS (
              SELECT 1
              FROM lc_admin_accounts a
              WHERE a.employee_id = e.employee_id
          )
        LIMIT 1
    ");

    $stmt->execute([(int) $employeeIdRaw]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$employee) {
        employeeLookupResponse(404, [
            'success' => false,
            'message' => 'Employee not found, ineligible, inactive, archived, or already registered for the portal.'
        ]);
    }

    $firstName = trim((string) $employee['first_name']);
    $lastName = trim((string) $employee['last_name']);

    $displayName = trim(implode(' ', array_filter([
        $firstName,
        (string) ($employee['middle_name'] ?? ''),
        $lastName,
        (string) ($employee['suffix'] ?? '')
    ], static fn($part) => trim($part) !== '')));

    $firstAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $firstName);
    $lastAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lastName);

    $firstInitial = strtolower(substr(
        preg_replace('/[^A-Za-z0-9]/', '', (string) $firstAscii),
        0,
        1
    ));

    $lastPrefix = strtolower(substr(
        preg_replace('/[^A-Za-z0-9]/', '', (string) $lastAscii),
        0,
        4
    ));

    $employeeId = (int) $employee['employee_id'];
    $employeeSuffix = substr(
        str_pad((string) $employeeId, 2, '0', STR_PAD_LEFT),
        -2
    );

    $username = $firstInitial . $lastPrefix . $employeeSuffix;
    $email = trim((string) $employee['email']);

    if ($firstInitial === '' || $lastPrefix === '' || $displayName === '') {
        employeeLookupResponse(422, [
            'success' => false,
            'message' => 'The employee name is incomplete. Update the HRMS employee record first.'
        ]);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        employeeLookupResponse(422, [
            'success' => false,
            'message' => 'The employee must have a valid HRMS email address before portal registration.'
        ]);
    }

    $usernameCheck = $pdo->prepare("
        SELECT id
        FROM lc_admin_accounts
        WHERE username = ?
        LIMIT 1
    ");
    $usernameCheck->execute([$username]);

    if ($usernameCheck->fetchColumn()) {
        employeeLookupResponse(409, [
            'success' => false,
            'message' => 'The automatically generated username is already assigned. Please ask the portal administrator to resolve the username conflict.'
        ]);
    }

    employeeLookupResponse(200, [
        'success' => true,
        'employee' => [
            'employee_id' => $employeeId,
            'username' => $username,
            'display_name' => $displayName,
            'email' => $email,
            'role_name' => (string) $employee['role_name']
        ]
    ]);

} catch (Throwable $exception) {
    error_log('LC admin employee lookup failed: ' . $exception->getMessage());

    employeeLookupResponse(500, [
        'success' => false,
        'message' => 'The employee lookup could not be completed. Please try again.'
    ]);
}
