<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('roles.view');

$pdo = lcAdminDatabase();

$currentAdminId = (int) ($_SESSION['lc_admin']['id'] ?? 0);
$canManage = lcAdminHasPermission('roles.manage');

date_default_timezone_set('Asia/Manila');

function employeeRoleEscape($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function employeeRoleName(array $employee): string
{
    return trim(implode(' ', array_filter([
        $employee['first_name'] ?? '',
        $employee['middle_name'] ?? '',
        $employee['last_name'] ?? '',
        $employee['suffix'] ?? ''
    ], static fn($value) => trim((string) $value) !== '')));
}

$message = '';
$error = '';

$search = trim((string) ($_GET['search'] ?? ''));
$selectedEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$status = (string) ($_GET['status'] ?? '');

if ($status === 'updated') {
    $message = 'Employee HRMS role was successfully updated and recorded in the role history.';
}

/*
|--------------------------------------------------------------------------
| Save employee role change
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to change employee HRMS roles.');
    }

    if (!lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your session token is invalid. Refresh the page and try again.');
    }

    try {
        $employeeId = filter_var(
            $_POST['employee_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $newRoleId = filter_var(
            $_POST['new_role_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (!$employeeId || $employeeId < 1) {
            throw new RuntimeException('Select a valid employee.');
        }

        if (!$newRoleId || $newRoleId < 1) {
            throw new RuntimeException('Select a valid target HRMS role.');
        }

        $dateObject = DateTime::createFromFormat('!Y-m-d', $effectiveDate);
        $dateErrors = DateTime::getLastErrors();

        if (
            !$dateObject
            || ($dateErrors !== false && (
                $dateErrors['warning_count'] > 0
                || $dateErrors['error_count'] > 0
            ))
            || $dateObject->format('Y-m-d') !== $effectiveDate
        ) {
            throw new RuntimeException('Enter a valid effective date.');
        }

        if ($reason === '') {
            throw new RuntimeException('Enter the reason for the role change.');
        }

        if (mb_strlen($reason) > 1000) {
            throw new RuntimeException('The reason must not exceed 1,000 characters.');
        }

        $pdo->beginTransaction();

        /*
         * Lock the employee record so concurrent changes cannot overwrite
         * one another without being detected.
         */
        $employeeStmt = $pdo->prepare("
            SELECT
                e.employee_id,
                e.employee_code,
                e.first_name,
                e.middle_name,
                e.last_name,
                e.suffix,
                e.role_id,
                e.employment_status,
                COALESCE(e.is_archived, 0) AS is_archived
            FROM em_employees e
            WHERE e.employee_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $employeeStmt->execute([$employeeId]);
        $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

        if (!$employee) {
            throw new RuntimeException('Employee record was not found.');
        }

        if (
            !in_array(
                $employee['employment_status'],
                ['Active', 'Probationary'],
                true
            )
            || (int) $employee['is_archived'] !== 0
        ) {
            throw new RuntimeException(
                'Only active or probationary, non-archived employees can have their roles changed.'
            );
        }

        $oldRoleId = $employee['role_id'] !== null
            ? (int) $employee['role_id']
            : null;

        if ($oldRoleId === $newRoleId) {
            throw new RuntimeException(
                'The selected role is already assigned to this employee.'
            );
        }

        $roleStmt = $pdo->prepare("
            SELECT role_id, role_name
            FROM em_roles
            WHERE role_id = ?
              AND role_id IN (8, 26, 27)
              AND status = 'Active'
            LIMIT 1
        ");

        $roleStmt->execute([$newRoleId]);
        $newRole = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$newRole) {
            throw new RuntimeException(
                'The selected HRMS role does not exist or is inactive.'
            );
        }

        $oldRoleName = null;

        if ($oldRoleId !== null) {
            $oldRoleStmt = $pdo->prepare("
                SELECT role_name
                FROM em_roles
                WHERE role_id = ?
                LIMIT 1
            ");

            $oldRoleStmt->execute([$oldRoleId]);
            $oldRoleName = $oldRoleStmt->fetchColumn() ?: 'Unknown role';
        }

        $employeeName = employeeRoleName($employee);

        /*
         * Update only the employee's HRMS role assignment.
         * Employee identity, login, password and other records remain intact.
         */
        $updateStmt = $pdo->prepare("
            UPDATE em_employees
            SET role_id = ?,
                updated_at = NOW()
            WHERE employee_id = ?
        ");

        $updateStmt->execute([$newRoleId, $employeeId]);

        /*
         * Save a historical snapshot of the old and new role names.
         */
        $historyStmt = $pdo->prepare("
            INSERT INTO lc_employee_role_history (
                employee_id,
                employee_code,
                employee_name,
                old_role_id,
                old_role_name,
                new_role_id,
                new_role_name,
                changed_by_admin_id,
                effective_date,
                reason
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $historyStmt->execute([
            $employeeId,
            $employee['employee_code'],
            $employeeName,
            $oldRoleId,
            $oldRoleName,
            $newRoleId,
            $newRole['role_name'],
            $currentAdminId,
            $effectiveDate,
            $reason
        ]);

        lcAdminLogActivity(
            $pdo,
            $currentAdminId,
            'employee_hrms_role_changed',
            "Changed HRMS role for employee {$employeeName} (ID {$employeeId}) from "
            . ($oldRoleName ?? 'Unassigned')
            . " to {$newRole['role_name']}. Effective date: {$effectiveDate}. Reason: {$reason}"
        );

        $pdo->commit();

        header(
            'Location: /admin/roles.php?employee_id='
            . $employeeId
            . '&status=updated'
        );
        exit;

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception instanceof PDOException
            ? 'The role change could not be saved. No employee role update was completed.'
            : $exception->getMessage();

        $selectedEmployeeId = (int) ($_POST['employee_id'] ?? 0);
    }
}

/*
|--------------------------------------------------------------------------
| Load active HRMS roles
|--------------------------------------------------------------------------
*/

$roles = $pdo->query("
    SELECT role_id, role_name, description
    FROM em_roles
    WHERE status = 'Active'
      AND role_id IN (8, 26, 27)
    ORDER BY role_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Employee search
|--------------------------------------------------------------------------
*/

$searchResults = [];

if ($search !== '') {

    $searchTerm = '%' . $search . '%';

    $searchStmt = $pdo->prepare("
        SELECT
            e.employee_id,
            e.employee_code,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
            e.employment_status,
            r.role_name,
            d.department_name,
            p.position_name
        FROM em_employees e
        LEFT JOIN em_roles r
            ON r.role_id = e.role_id
        LEFT JOIN em_departments d
            ON d.department_id = e.department_id
        LEFT JOIN em_positions p
            ON p.position_id = e.position_id
        WHERE e.employment_status IN ('Active', 'Probationary')
          AND COALESCE(e.is_archived, 0) = 0
          AND (
                CAST(e.employee_id AS CHAR) LIKE :employee_id
                OR e.employee_code LIKE :employee_code
                OR CONCAT_WS(
                    ' ',
                    e.first_name,
                    e.middle_name,
                    e.last_name,
                    e.suffix
                ) LIKE :employee_name
                OR e.email LIKE :email
          )
        ORDER BY e.last_name ASC, e.first_name ASC
        LIMIT 100
    ");

    $searchStmt->execute([
        ':employee_id' => $searchTerm,
        ':employee_code' => $searchTerm,
        ':employee_name' => $searchTerm,
        ':email' => $searchTerm
    ]);

    $searchResults = $searchStmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Selected employee details
|--------------------------------------------------------------------------
*/

$selectedEmployee = null;

if ($selectedEmployeeId > 0) {

    $employeeStmt = $pdo->prepare("
        SELECT
            e.employee_id,
            e.employee_code,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
            e.email,
            e.employment_status,
            COALESCE(e.is_archived, 0) AS is_archived,
            e.role_id,
            r.role_name,
            d.department_name,
            p.position_name,
            ua.status AS account_status
        FROM em_employees e
        LEFT JOIN em_roles r
            ON r.role_id = e.role_id
        LEFT JOIN em_departments d
            ON d.department_id = e.department_id
        LEFT JOIN em_positions p
            ON p.position_id = e.position_id
        LEFT JOIN user_account ua
            ON ua.employee_id = e.employee_id
        WHERE e.employee_id = ?
        LIMIT 1
    ");

    $employeeStmt->execute([$selectedEmployeeId]);
    $selectedEmployee = $employeeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($selectedEmployee) {
        $selectedEmployee['full_name'] = employeeRoleName($selectedEmployee);
    }
}

/*
|--------------------------------------------------------------------------
| Role change history
|--------------------------------------------------------------------------
*/

$historySql = "
    SELECT
        id,
        employee_id,
        employee_code,
        employee_name,
        old_role_name,
        new_role_name,
        changed_by_admin_id,
        effective_date,
        reason,
        created_at
    FROM lc_employee_role_history
";

$historyParams = [];

if ($selectedEmployeeId > 0) {
    $historySql .= " WHERE employee_id = ?";
    $historyParams[] = $selectedEmployeeId;
}

$historySql .= " ORDER BY created_at DESC, id DESC LIMIT 50";

$historyStmt = $pdo->prepare($historySql);
$historyStmt->execute($historyParams);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedEmployeeEligible = $selectedEmployee
    && in_array(
        $selectedEmployee['employment_status'],
        ['Active', 'Probationary'],
        true
    )
    && (int) $selectedEmployee['is_archived'] === 0;

$admin = $_SESSION['lc_admin'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>Employee Role Management | Bestlink HRMS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/admin/admin-content-layout.css?v=20261102-<?= $selectedEmployeeId ?>">
    <link rel="stylesheet" href="/modules/compliance/css/pages/admin-topbar.css?v=20261102-030955">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: Montserrat, Arial, sans-serif;
            color: #1d2b42;
            background: #f3f6fb;
        }

        .content {
            padding-top: 60px;
        }

        .page-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
        }

        .page-header h1 {
            margin: 0;
            font-size: 23px;
            font-weight: 700;
        }

        .subtitle {
            margin: 7px 0 0;
            color: #718096;
            font-size: 12px;
            line-height: 1.7;
        }

        .admin-breadcrumb {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 9px;
            font-size: 11px;
            white-space: nowrap;
        }

        .admin-breadcrumb a,
        .text-link {
            color: #24518a;
            font-weight: 700;
            text-decoration: none;
        }

        .admin-breadcrumb span {
            color: #718096;
        }

        .panel {
            width: 100%;
            margin-bottom: 18px;
            padding: 22px 24px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 7px;
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 14px;
            font-weight: 700;
        }

        .panel-description {
            margin: 0 0 18px;
            color: #718096;
            font-size: 11px;
            line-height: 1.7;
        }

        .search-form {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            align-items: center;
        }

        input[type="text"],
        input[type="date"],
        select,
        textarea {
            width: 100%;
            min-height: 39px;
            padding: 9px 11px;
            border: 1px solid #d7e0ec;
            border-radius: 4px;
            background: #fff;
            color: #1d2b42;
            font: inherit;
            font-size: 11px;
        }

        .search-form input {
            max-width: 420px;
        }

        textarea {
            min-height: 95px;
            resize: vertical;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: #34445b;
            font-size: 11px;
            font-weight: 700;
        }

        .field {
            margin-bottom: 17px;
        }

        button,
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 8px 13px;
            border: 1px solid #244d83;
            border-radius: 4px;
            background: #244d83;
            color: #fff;
            font-family: inherit;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        button.secondary,
        .button.secondary {
            background: #fff;
            color: #244d83;
        }

        button:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        .notice {
            margin-bottom: 17px;
            padding: 12px 14px;
            border: 1px solid;
            border-radius: 4px;
            font-size: 11px;
            line-height: 1.7;
        }

        .notice.success {
            color: #27623b;
            background: #edf7f0;
            border-color: #cde8d4;
        }

        .notice.error {
            color: #9b2c2c;
            background: #fff1f0;
            border-color: #fed7d7;
        }

        .notice.warning {
            color: #78551c;
            background: #fff8e8;
            border-color: #f1dfb8;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
        }

        th,
        td {
            padding: 11px 10px;
            border-bottom: 1px solid #edf1f6;
            text-align: left;
            vertical-align: middle;
        }

        th {
            color: #718096;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        td {
            color: #34445b;
            line-height: 1.6;
        }

        .muted {
            color: #718096;
            font-size: 10px;
        }

        .employee-name {
            color: #1d2b42;
            font-weight: 700;
        }

        .status {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 4px;
            background: #edf7f0;
            color: #27623b;
            font-size: 10px;
            font-weight: 700;
        }

        .status.inactive {
            background: #f1f3f6;
            color: #667085;
        }

        .employee-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin: 18px 0;
        }

        .summary-item {
            min-width: 0;
            padding: 13px;
            background: #f7f9fc;
            border: 1px solid #e8edf4;
            border-radius: 5px;
        }

        .summary-label {
            display: block;
            margin-bottom: 6px;
            color: #718096;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .summary-value {
            display: block;
            overflow-wrap: anywhere;
            color: #1d2b42;
            font-size: 12px;
            font-weight: 700;
        }

        .role-change-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .protected-note {
            margin-top: 13px;
            color: #718096;
            font-size: 10px;
            line-height: 1.7;
        }

        .form-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 9px;
        }

        .empty-state {
            padding: 18px 10px;
            color: #718096;
            text-align: center;
        }

        .history-reason {
            max-width: 320px;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        @media (max-width: 700px) {
            .content {
                padding: 60px 15px 24px;
            }

            .panel {
                padding: 17px 15px;
            }

            .page-header {
                flex-direction: column;
            }

            .employee-summary,
            .role-change-grid {
                grid-template-columns: 1fr;
            }

            .search-form {
                align-items: stretch;
            }

            .search-form input {
                max-width: none;
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/admin-topbar.php'; ?>

<main class="content">

    <div class="page-header">
        <div>
            <h1>Employee Role Management</h1>
            <p class="subtitle">
                Update HRMS role assignments when an existing employee is promoted or officially reassigned.
            </p>
        </div>

        <nav class="admin-breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Dashboard</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Employee Role Management</span>
        </nav>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice success">
            <?= employeeRoleEscape($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error">
            <?= employeeRoleEscape($error) ?>
        </div>
    <?php endif; ?>

    <section class="panel">
        <h2>Find Employee</h2>

        <p class="panel-description">
            Search existing HRMS employee records by employee name, employee ID, employee code, or email address.
            No new employee record will be created.
        </p>

        <form method="GET" action="/admin/roles.php" class="search-form">
            <input
                type="text"
                name="search"
                value="<?= employeeRoleEscape($search) ?>"
                placeholder="Enter employee name, ID, code or email"
                aria-label="Search existing employees"
                required
            >

            <button type="submit">Search Employee</button>

            <?php if ($search !== '' || $selectedEmployeeId > 0): ?>
                <a class="button secondary" href="/admin/roles.php">Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($search !== ''): ?>
            <div class="table-wrap" style="margin-top:20px;">
                <table>
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Position</th>
                            <th>Current HRMS Role</th>
                            <th>Employment</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($searchResults as $result): ?>
                        <tr>
                            <td>
                                <strong class="employee-name">
                                    <?= employeeRoleEscape(employeeRoleName($result)) ?>
                                </strong>
                                <div class="muted">
                                    ID: <?= (int) $result['employee_id'] ?>
                                    <?php if (!empty($result['employee_code'])): ?>
                                        · <?= employeeRoleEscape($result['employee_code']) ?>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td><?= employeeRoleEscape($result['department_name'] ?? 'Not assigned') ?></td>
                            <td><?= employeeRoleEscape($result['position_name'] ?? 'Not assigned') ?></td>
                            <td><?= employeeRoleEscape($result['role_name'] ?? 'Unassigned') ?></td>
                            <td><?= employeeRoleEscape($result['employment_status']) ?></td>
                            <td>
                                <a class="text-link"
                                   href="/admin/roles.php?search=<?= rawurlencode($search) ?>&employee_id=<?= (int) $result['employee_id'] ?>">
                                    Select Employee
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$searchResults): ?>
                        <tr>
                            <td colspan="6" class="empty-state">
                                No active or probationary employees matched your search.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <p class="protected-note">
                Search results are limited to 100 employees per search.
                Resigned, terminated, and archived employees are excluded.
            </p>
        <?php endif; ?>
    </section>

    <?php if ($selectedEmployee): ?>

        <section class="panel">
            <h2>Selected Employee</h2>

            <div class="employee-summary">
                <div class="summary-item">
                    <span class="summary-label">Employee Name</span>
                    <span class="summary-value">
                        <?= employeeRoleEscape($selectedEmployee['full_name']) ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Employee ID / Code</span>
                    <span class="summary-value">
                        <?= (int) $selectedEmployee['employee_id'] ?>
                        <?php if (!empty($selectedEmployee['employee_code'])): ?>
                            / <?= employeeRoleEscape($selectedEmployee['employee_code']) ?>
                        <?php endif; ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Current HRMS Role</span>
                    <span class="summary-value">
                        <?= employeeRoleEscape($selectedEmployee['role_name'] ?? 'Unassigned') ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Department</span>
                    <span class="summary-value">
                        <?= employeeRoleEscape($selectedEmployee['department_name'] ?? 'Not assigned') ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Position</span>
                    <span class="summary-value">
                        <?= employeeRoleEscape($selectedEmployee['position_name'] ?? 'Not assigned') ?>
                    </span>
                </div>

                <div class="summary-item">
                    <span class="summary-label">Employment / Login Status</span>
                    <span class="summary-value">
                        <?= employeeRoleEscape($selectedEmployee['employment_status']) ?>
                        /
                        <?= employeeRoleEscape($selectedEmployee['account_status'] ?? 'No account') ?>
                    </span>
                </div>
            </div>

            <?php if (!$selectedEmployeeEligible): ?>

                <div class="notice warning">
                    This employee is not eligible for a role change because the employment status is inactive
                    or the employee record is archived.
                </div>

            <?php elseif (!$canManage): ?>

                <div class="notice warning">
                    You have view-only access. The roles.manage permission is required to change employee roles.
                </div>

            <?php else: ?>

                <div class="notice warning">
                    Changing an HRMS role can change the employee's access across the HRMS system,
                    including modules outside Legal and Compliance. This does not grant or revoke
                    separate Lawyer Portal access.
                </div>

                <form
                    method="POST"
                    action="/admin/roles.php"
                    onsubmit="return confirmRoleChange(this);"
                >
                    <input type="hidden"
                           name="csrf_token"
                           value="<?= employeeRoleEscape(lcAdminCsrfToken()) ?>">

                    <input type="hidden"
                           name="employee_id"
                           value="<?= (int) $selectedEmployee['employee_id'] ?>">

                    <div class="role-change-grid">

                        <div class="field">
                            <label for="new_role_id">New HRMS Role</label>

                            <select id="new_role_id" name="new_role_id" required>
                                <option value="">Select new role</option>

                                <?php foreach ($roles as $role): ?>
                                    <option
                                        value="<?= (int) $role['role_id'] ?>"
                                        data-role-name="<?= employeeRoleEscape($role['role_name']) ?>"
                                        <?= (int) $role['role_id'] === (int) $selectedEmployee['role_id'] ? 'disabled' : '' ?>
                                    >
                                        <?= employeeRoleEscape($role['role_name']) ?>
                                        <?= (int) $role['role_id'] === (int) $selectedEmployee['role_id'] ? ' (Current Role)' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="effective_date">Effective Date</label>
                            <input
                                type="date"
                                id="effective_date"
                                name="effective_date"
                                value="<?= date('Y-m-d') ?>"
                                required
                            >
                        </div>

                    </div>

                    <div class="field">
                        <label for="reason">Reason for Role Change</label>
                        <textarea
                            id="reason"
                            name="reason"
                            maxlength="1000"
                            required
                            placeholder="Enter the promotion, reassignment, or approved role-change reason."
                        ></textarea>
                    </div>

                    <div class="form-actions">
                        <button type="submit">Review and Confirm Role Change</button>

                        <a class="button secondary"
                           href="/admin/roles.php?search=<?= rawurlencode($search) ?>">
                            Cancel
                        </a>
                    </div>
                </form>

            <?php endif; ?>
        </section>

    <?php elseif ($selectedEmployeeId > 0): ?>

        <div class="notice error">
            The selected employee could not be found.
        </div>

    <?php endif; ?>

    <section class="panel">
        <h2>
            <?= $selectedEmployeeId > 0 ? 'Selected Employee Role History' : 'Recent Employee Role Changes' ?>
        </h2>

        <p class="panel-description">
            Historical records show the previous role, new role, effective date, administrator, and recorded reason.
            <?= $selectedEmployeeId > 0 ? 'Only changes for the selected employee are displayed.' : 'The latest 50 changes are displayed.' ?>
        </p>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Previous Role</th>
                        <th>New Role</th>
                        <th>Effective Date</th>
                        <th>Reason</th>
                        <th>Changed By</th>
                        <th>Recorded At</th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ($history as $record): ?>
                    <tr>
                        <td>
                            <strong><?= employeeRoleEscape($record['employee_name']) ?></strong>
                            <div class="muted">
                                ID: <?= (int) $record['employee_id'] ?>
                                <?php if (!empty($record['employee_code'])): ?>
                                    · <?= employeeRoleEscape($record['employee_code']) ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td><?= employeeRoleEscape($record['old_role_name'] ?? 'Unassigned') ?></td>
                        <td><strong><?= employeeRoleEscape($record['new_role_name']) ?></strong></td>
                        <td><?= employeeRoleEscape($record['effective_date']) ?></td>
                        <td class="history-reason"><?= employeeRoleEscape($record['reason']) ?></td>
                        <td>Admin #<?= (int) $record['changed_by_admin_id'] ?></td>
                        <td><?= employeeRoleEscape($record['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$history): ?>
                    <tr>
                        <td colspan="7" class="empty-state">
                            No employee role changes have been recorded yet.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <p class="protected-note">
        Portal role and permission administration remains separate.
        <a class="text-link" href="/admin/portal-roles.php">Open Portal Roles and Permissions</a>.
    </p>

</main>

<script>
function confirmRoleChange(form) {
    const employeeName = <?= json_encode($selectedEmployee['full_name'] ?? '') ?>;
    const currentRole = <?= json_encode($selectedEmployee['role_name'] ?? 'Unassigned') ?>;

    const roleSelect = form.querySelector('[name="new_role_id"]');
    const selectedOption = roleSelect.options[roleSelect.selectedIndex];

    if (!selectedOption || !selectedOption.value) {
        alert('Select the new HRMS role.');
        return false;
    }

    const newRole = selectedOption.dataset.roleName || selectedOption.text;
    const effectiveDate = form.querySelector('[name="effective_date"]').value;
    const reason = form.querySelector('[name="reason"]').value.trim();

    if (!reason) {
        alert('Enter the reason for the role change.');
        return false;
    }

    return confirm(
        'CONFIRM EMPLOYEE ROLE CHANGE\n\n'
        + 'Employee: ' + employeeName + '\n'
        + 'Current role: ' + currentRole + '\n'
        + 'New role: ' + newRole + '\n'
        + 'Effective date: ' + effectiveDate + '\n\n'
        + 'Reason: ' + reason + '\n\n'
        + 'This change updates the employee HRMS role and may affect access across other HRMS modules.\n\n'
        + 'Continue?'
    );
}
</script>

<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>

</body>
</html>
