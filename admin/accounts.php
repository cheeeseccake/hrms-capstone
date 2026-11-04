<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('accounts.view');

$pdo = lcAdminDatabase();
$currentAdminId = (int) $_SESSION['lc_admin']['id'];
$canManage = lcAdminHasPermission('accounts.manage');

$message = '';
$error = '';

function accountEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function accountRedirect(): never
{
    header('Location: /admin/accounts.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage administrator accounts.');
    }

    if (!lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your session token is invalid. Refresh the page and try again.');
    }

    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo->beginTransaction();

        if ($action === 'create') {
            $employeeIdRaw = trim((string) ($_POST['employee_id'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $roleId = (int) ($_POST['role_id'] ?? 0);

            if (!preg_match('/^[0-9]{1,11}$/', $employeeIdRaw)) {
                throw new RuntimeException('Enter a valid Employee ID and search for the employee first.');
            }

            $employeeId = (int) $employeeIdRaw;

            if ($employeeId < 1) {
                throw new RuntimeException('Enter a valid Employee ID.');
            }

            if (strlen($password) < 14) {
                throw new RuntimeException('The initial password must contain at least 14 characters.');
            }

            // Recheck eligibility and lock the HRMS employee record during creation.
            $employeeStmt = $pdo->prepare("
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
                      FROM lc_admin_accounts existing
                      WHERE existing.employee_id = e.employee_id
                  )
                LIMIT 1
                FOR UPDATE
            ");
            $employeeStmt->execute([$employeeId]);
            $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                throw new RuntimeException('Employee not found, ineligible, inactive, archived, or already registered for the portal.');
            }

            $firstName = trim((string) $employee['first_name']);
            $lastName = trim((string) $employee['last_name']);
            $displayName = trim(implode(' ', array_filter([
                $firstName,
                (string) ($employee['middle_name'] ?? ''),
                $lastName,
                (string) ($employee['suffix'] ?? '')
            ], static fn($part) => trim($part) !== '')));

            // Transliterate names so the generated username remains URL/login safe.
            $firstAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $firstName);
            $lastAscii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lastName);
            $firstInitial = strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $firstAscii), 0, 1));
            $lastPrefix = strtolower(substr(preg_replace('/[^A-Za-z0-9]/', '', (string) $lastAscii), 0, 4));
            $employeeSuffix = substr(str_pad((string) $employeeId, 2, '0', STR_PAD_LEFT), -2);
            $username = $firstInitial . $lastPrefix . $employeeSuffix;

            $email = trim((string) $employee['email']);

            if ($firstInitial === '' || $lastPrefix === '' || $displayName === '') {
                throw new RuntimeException('The employee name is incomplete. Update the HRMS employee record first.');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                throw new RuntimeException('The employee must have a valid HRMS email address before portal registration.');
            }

            $roleCheck = $pdo->prepare("
                SELECT id FROM lc_admin_roles
                WHERE id = ? AND status = 'Active'
                LIMIT 1
            ");
            $roleCheck->execute([$roleId]);

            if (!$roleCheck->fetchColumn()) {
                throw new RuntimeException('Select an active portal role.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO lc_admin_accounts
                    (employee_id, username, email, display_name, password_hash,
                     role_id, status, must_change_password, password_changed_at)
                VALUES (?, ?, ?, ?, ?, ?, 'Active', 1, NULL)
            ");

            $stmt->execute([
                $employeeId,
                $username,
                $email,
                $displayName,
                password_hash($password, PASSWORD_DEFAULT),
                $roleId
            ]);

            $newId = (int) $pdo->lastInsertId();

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                'admin_account_created',
                'Created portal administrator account ID ' . $newId .
                ' (' . $username . ').'
            );

            $message = 'Administrator account created. The new administrator must change the initial password at first login.';

        } elseif ($action === 'update') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $roleId = (int) ($_POST['role_id'] ?? 0);

            if ($accountId < 1) {
                throw new RuntimeException('Invalid administrator account.');
            }

            $existing = $pdo->prepare("
                SELECT id, role_id
                FROM lc_admin_accounts
                WHERE id = ?
                FOR UPDATE
            ");
            $existing->execute([$accountId]);
            $oldAccount = $existing->fetch(PDO::FETCH_ASSOC);

            if (!$oldAccount) {
                throw new RuntimeException('Administrator account not found.');
            }

            if ($accountId === $currentAdminId && $roleId !== (int) $oldAccount['role_id']) {
                throw new RuntimeException('You cannot change your own portal role.');
            }

            $roleCheck = $pdo->prepare("
                SELECT id FROM lc_admin_roles
                WHERE id = ? AND status = 'Active'
                LIMIT 1
            ");
            $roleCheck->execute([$roleId]);

            if (!$roleCheck->fetchColumn()) {
                throw new RuntimeException('Select an active portal role.');
            }

            $stmt = $pdo->prepare("
                UPDATE lc_admin_accounts
                SET role_id = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$roleId, $accountId]);

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                'admin_account_updated',
                'Updated portal administrator account ID ' . $accountId .
                ' (' . $username . ').'
            );

            $message = 'Administrator account details updated.';

        } elseif ($action === 'toggle_status') {
            $accountId = (int) ($_POST['account_id'] ?? 0);

            if ($accountId < 1) {
                throw new RuntimeException('Invalid administrator account.');
            }

            if ($accountId === $currentAdminId) {
                throw new RuntimeException('You cannot disable your own administrator account.');
            }

            // Serialize administrator status changes against the active portal roles.
            // Other code that changes role permissions or account eligibility should
            // use the same role-row locking convention.
            $lockRoles = $pdo->query("
                SELECT id
                FROM lc_admin_roles
                WHERE status = 'Active'
                ORDER BY id
                FOR UPDATE
            ");
            $lockRoles->fetchAll(PDO::FETCH_COLUMN);

            $stmt = $pdo->prepare("
                SELECT id, username, status, role_id
                FROM lc_admin_accounts
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$accountId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('Administrator account not found.');
            }

            $newStatus = $target['status'] === 'Active' ? 'Disabled' : 'Active';

            if ($newStatus === 'Active') {
                $roleCheck = $pdo->prepare("
                    SELECT id
                    FROM lc_admin_roles
                    WHERE id = ? AND status = 'Active'
                    LIMIT 1
                ");
                $roleCheck->execute([(int) $target['role_id']]);

                if (!$roleCheck->fetchColumn()) {
                    throw new RuntimeException('This account cannot be enabled because its assigned portal role is inactive.');
                }
            }

            if ($newStatus === 'Disabled') {
                $checkLastAdmin = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM lc_admin_accounts a
                    INNER JOIN lc_admin_roles r ON r.id = a.role_id
                    INNER JOIN lc_admin_role_permissions rp ON rp.role_id = r.id
                    INNER JOIN lc_admin_permissions p ON p.id = rp.permission_id
                    WHERE a.status = 'Active'
                      AND a.id <> ?
                      AND r.status = 'Active'
                      AND p.permission_key = 'accounts.manage'
                ");
                $checkLastAdmin->execute([$accountId]);

                if ((int) $checkLastAdmin->fetchColumn() < 1) {
                    throw new RuntimeException('This account cannot be disabled because it may be the last active account with account-management permission.');
                }
            }

            $update = $pdo->prepare("
                UPDATE lc_admin_accounts
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([$newStatus, $accountId]);

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                $newStatus === 'Disabled'
                    ? 'admin_account_disabled'
                    : 'admin_account_enabled',
                ucfirst($newStatus) . ' portal administrator account ID ' .
                $accountId . ' (' . $target['username'] . ').'
            );

            $message = 'Administrator account status changed to ' . $newStatus . '.';

        } elseif ($action === 'reset_password') {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');

            if ($accountId < 1) {
                throw new RuntimeException('Invalid administrator account.');
            }

            if (strlen($password) < 14) {
                throw new RuntimeException('The temporary password must contain at least 14 characters.');
            }

            $stmt = $pdo->prepare("
                SELECT id, username, status
                FROM lc_admin_accounts
                WHERE id = ?
                FOR UPDATE
            ");
            $stmt->execute([$accountId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('Administrator account not found.');
            }

            if ($target['status'] !== 'Active') {
                throw new RuntimeException('Enable the account before resetting its password.');
            }

            $update = $pdo->prepare("
                UPDATE lc_admin_accounts
                SET password_hash = ?,
                    must_change_password = 1,
                    password_changed_at = NULL,
                    failed_login_attempts = 0,
                    locked_until = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $update->execute([
                password_hash($password, PASSWORD_DEFAULT),
                $accountId
            ]);

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                'admin_password_reset',
                'Reset password for portal administrator account ID ' .
                $accountId . ' (' . $target['username'] .
                '). Password change required at next login.'
            );

            $message = 'Password reset successfully. The account must change the temporary password at next login.';

        } else {
            throw new RuntimeException('Unknown account action.');
        }

        $pdo->commit();

        $_SESSION['lc_admin_flash'] = $message;
        accountRedirect();

    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('LC admin account operation failed: ' . $exception->getMessage());

        if ((string) $exception->getCode() === '23000') {
            $error = 'The username or email address is already assigned to another portal administrator.';
        } else {
            $error = 'The account operation could not be completed. Please review the details and try again.';
        }

    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    }
}

if (isset($_SESSION['lc_admin_flash'])) {
    $message = (string) $_SESSION['lc_admin_flash'];
    unset($_SESSION['lc_admin_flash']);
}

$search = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT
        a.id,
        a.employee_id,
        a.username,
        a.email,
        a.display_name,
        a.role_id,
        a.status,
        a.must_change_password,
        a.last_login_at,
        a.created_at,
        r.role_name
    FROM lc_admin_accounts a
    INNER JOIN lc_admin_roles r ON r.id = a.role_id
";

$params = [];

if ($search !== '') {
    $sql .= "
        WHERE CAST(a.employee_id AS CHAR) LIKE ?
           OR a.username LIKE ?
           OR a.email LIKE ?
           OR a.display_name LIKE ?
           OR r.role_name LIKE ?
    ";
    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, $term];
}

$sql .= " ORDER BY a.created_at DESC, a.id DESC";

$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$accounts = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$roles = $pdo->query("
    SELECT id, role_name
    FROM lc_admin_roles
    WHERE status = 'Active'
    ORDER BY role_name
")->fetchAll(PDO::FETCH_ASSOC);

$csrf = lcAdminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Administrator Accounts | Bestlink HRMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/admin/assets/css/admin.css">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f6fb;
            color: #1d2b42;
            font-family: Montserrat, Arial, sans-serif;
            font-size: 12px;
        }
        .topbar {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            padding: 12px 30px;
            background: #fff;
            border-bottom: 1px solid #e4eaf2;
        }
        .brand { font-size: 14px; font-weight: 800; }
        .brand span {
            display: block;
            margin-top: 4px;
            color: #718096;
            font-size: 10px;
            font-weight: 500;
        }
        .account {
            display: flex;
            align-items: center;
            gap: 18px;
        }
        .account strong { display: block; font-size: 12px; }
        .account small { color: #718096; }
        .logout {
            padding: 0;
            border: 0;
            background: transparent;
            color: #244d83;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }
        .content { max-width: 1400px; margin: auto; padding: 32px 30px; }
        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 22px;
        }
        h1 { margin: 0; font-size: 22px; }
        .subtitle { margin: 8px 0 0; color: #718096; font-size: 11px; }
        .panel {
            margin-bottom: 18px;
            padding: 22px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 8px;
        }
        .panel h2 { margin: 0 0 16px; font-size: 14px; }
        .notice {
            margin-bottom: 16px;
            padding: 12px 14px;
            border: 1px solid #cfe5d5;
            border-radius: 5px;
            background: #f0f8f2;
            color: #245b35;
            line-height: 1.6;
        }
        .error {
            border-color: #efcaca;
            background: #fff4f4;
            color: #9b2929;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
        }
        .field { min-width: 0; }
        .field label {
            display: block;
            margin-bottom: 7px;
            color: #52627a;
            font-size: 10px;
            font-weight: 700;
        }
        .field input, .field select {
            width: 100%;
            min-height: 39px;
            padding: 9px 10px;
            border: 1px solid #d7dfeb;
            border-radius: 5px;
            background: #fff;
            color: #1d2b42;
            font: inherit;
            font-size: 11px;
        }
        .field input:focus, .field select:focus {
            outline: 2px solid #c8d9f0;
            border-color: #416b9e;
        }
        .form-actions {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-top: 16px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex;
            min-height: 35px;
            align-items: center;
            justify-content: center;
            padding: 8px 13px;
            border: 1px solid #244d83;
            border-radius: 5px;
            background: #244d83;
            color: #fff;
            font: inherit;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-secondary {
            border-color: #d5deea;
            background: #fff;
            color: #344b69;
        }
        .btn-danger {
            border-color: #a63b3b;
            background: #fff;
            color: #a63b3b;
        }
        .btn:focus-visible {
            outline: 2px solid #244d83;
            outline-offset: 2px;
        }
        .search-row {
            display: flex;
            gap: 9px;
            margin-bottom: 16px;
        }
        .search-row input {
            width: min(360px, 100%);
            min-height: 36px;
            padding: 8px 10px;
            border: 1px solid #d7dfeb;
            border-radius: 5px;
            font: inherit;
        }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; min-width: 850px; }
        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #f8fafd;
            color: #62728a;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .3px;
        }
        td { font-size: 10px; }
        td strong { display: block; font-size: 11px; }
        td small { display: block; margin-top: 4px; color: #78869a; }
        .status {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 4px;
            background: #edf7f0;
            color: #27623b;
            font-size: 9px;
            font-weight: 700;
        }
        .status.disabled { background: #fff0f0; color: #a33a3a; }
        .status.change { margin-top: 5px; background: #fff6e7; color: #8a5c16; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; }
        details { margin-top: 7px; }
        details summary {
            color: #244d83;
            cursor: pointer;
            font-size: 10px;
            font-weight: 700;
        }
        .inline-form {
            min-width: 220px;
            margin-top: 9px;
            padding: 12px;
            border: 1px solid #e1e7ef;
            border-radius: 5px;
            background: #f9fbfd;
        }
        .inline-form input, .inline-form select {
            width: 100%;
            min-height: 34px;
            margin-bottom: 8px;
            padding: 7px;
            border: 1px solid #d7dfeb;
            border-radius: 4px;
            font: inherit;
            font-size: 10px;
        }
        .muted { color: #8290a2; }
        .back-link { color: #244d83; text-decoration: none; font-weight: 700; }
        @media (max-width: 900px) {
            .form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 600px) {
            .topbar { padding: 14px 17px; align-items: flex-start; }
            .content { padding: 23px 15px; }
            .form-grid { grid-template-columns: 1fr; }
            .page-head { display: block; }
            .account { gap: 10px; }
            .panel { padding: 16px; }
        }
    </style>
<style id="admin-breadcrumb-style">
.admin-breadcrumb {
    display: inline-flex;
    align-items: center;
    justify-content: flex-end;
    gap: 9px;
    margin-left: auto;
    white-space: nowrap;
    font-size: 13px;
    line-height: 1.5;
    flex-shrink: 0;
}

.admin-breadcrumb a {
    color: #2563eb;
    text-decoration: none;
    font-weight: 600;
}

.admin-breadcrumb a:hover {
    text-decoration: underline;
}

.admin-breadcrumb span[aria-hidden="true"] {
    color: #94a3b8;
}

.admin-breadcrumb span[aria-current="page"] {
    color: #64748b;
    font-weight: 500;
}

@media (max-width: 600px) {
    .admin-breadcrumb {
        white-space: normal;
        flex-wrap: wrap;
    }
}
</style>

    <link rel="stylesheet" href="/admin/admin-content-layout.css?v=20261102-030252">
    <link rel="stylesheet" href="/modules/compliance/css/pages/admin-topbar.css?v=20261102-030955">
</head>
<body>
<?php require __DIR__ . '/includes/admin-topbar.php'; ?>

<main class="content">
    <div class="page-head">
        <div>
            <h1>Administrator Accounts</h1>
            <p class="subtitle">Manage access to the separate Legal and Compliance administration portal.</p>
        </div>
        <nav class="admin-breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Dashboard</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Administrator Accounts</span>
        </nav>
    </div>

    <?php if ($message !== ''): ?>
        <div class="notice"><?= accountEscape($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= accountEscape($error) ?></div>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <section class="panel">
        <h2>Create Administrator Account</h2>

        <?php if (!$roles): ?>
            <p class="muted">There are no active portal roles available.</p>
        <?php else: ?>
        <form method="POST" id="create-admin-form" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">
                <div class="field">
                    <label for="employee_id">HRMS Employee ID</label>
                    <input id="employee_id" name="employee_id" inputmode="numeric"
                        pattern="[0-9]{1,11}" maxlength="11" required>
                    <div class="form-actions">
                        <button class="btn btn-secondary" type="button" id="search-employee">
                            Search Employee
                        </button>
                    </div>
                    <p id="employee-lookup-message" class="muted" aria-live="polite">
                        Search an active Legal Officer, Chief Legal Officer, or Legal Counsel.
                    </p>
                </div>

                <div class="field">
                    <label for="new_username">Generated Username</label>
                    <input id="new_username" readonly aria-readonly="true" placeholder="Generated after search">
                </div>

                <div class="field">
                    <label for="new_display_name">Display Name</label>
                    <input id="new_display_name" readonly aria-readonly="true" placeholder="Retrieved from HRMS">
                </div>

                <div class="field">
                    <label for="new_email">HRMS Email Address</label>
                    <input id="new_email" type="email" readonly aria-readonly="true" placeholder="Retrieved from HRMS">
                </div>

                <div class="field">
                    <label for="new_hrms_role">HRMS Position</label>
                    <input id="new_hrms_role" readonly aria-readonly="true" placeholder="Retrieved from HRMS">
                </div>

                <div class="field">
                    <label for="new_role">Portal Role</label>
                    <select id="new_role" name="role_id" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= (int) $role['id'] ?>">
                                <?= accountEscape($role['role_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="new_password">Temporary Password</label>
                    <input id="new_password" name="password" type="password"
                        minlength="14" required autocomplete="new-password">
                </div>
            </div>

            <div class="form-actions">
                <button class="btn" type="submit" id="create-admin-button" disabled>
                    Create Administrator
                </button>
            </div>
            <p class="muted">Username format: first initial + first four letters of last name + last two digits of Employee ID. Use a temporary password of at least 14 characters. The administrator must change it at first login.</p>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Administrator Directory</h2>

        <form class="search-row" method="GET">
            <input type="search" name="q" value="<?= accountEscape($search) ?>"
                placeholder="Search username, name, email, or role">
            <button class="btn" type="submit">Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-secondary" href="/admin/accounts.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Administrator</th>
                        <th>Username</th>
                        <th>Portal Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$accounts): ?>
                    <tr><td colspan="7" class="muted">No administrator accounts found.</td></tr>
                <?php endif; ?>

                <?php foreach ($accounts as $account): ?>
                    <?php
                    $accountId = (int) $account['id'];
                    $isSelf = $accountId === $currentAdminId;
                    ?>
                    <tr>
                        <td><?= accountEscape($account['employee_id'] ?? '—') ?></td>
                        <td>
                            <strong><?= accountEscape($account['display_name']) ?></strong>
                            <small><?= accountEscape($account['email'] ?: 'No email address') ?></small>
                            <?php if ((int) $account['must_change_password'] === 1): ?>
                                <span class="status change">Password change required</span>
                            <?php endif; ?>
                        </td>
                        <td><?= accountEscape($account['username']) ?></td>
                        <td><?= accountEscape($account['role_name']) ?></td>
                        <td>
                            <span class="status <?= $account['status'] === 'Disabled' ? 'disabled' : '' ?>">
                                <?= accountEscape($account['status']) ?>
                            </span>
                            <?php if ($isSelf): ?><small>Current account</small><?php endif; ?>
                        </td>
                        <td>
                            <?= $account['last_login_at']
                                ? accountEscape($account['last_login_at'])
                                : '<span class="muted">Never</span>' ?>
                        </td>
                        <td>
                            <?php if ($canManage): ?>
                            <div class="actions">
                                <details>
                                    <summary>Edit</summary>
                                    <form class="inline-form" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
                                        <input type="hidden" name="action" value="update">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">

                                        <p class="muted">
                                            Employee ID: <?= accountEscape($account['employee_id'] ?? '') ?><br>
                                            Username: <?= accountEscape($account['username']) ?><br>
                                            Name: <?= accountEscape($account['display_name']) ?><br>
                                            Email: <?= accountEscape($account['email'] ?? '') ?>
                                        </p>

                                        <label>Portal Role</label>
                                        <select name="role_id" required <?= $isSelf ? 'disabled' : '' ?>>
                                            <?php foreach ($roles as $role): ?>
                                                <option value="<?= (int) $role['id'] ?>"
                                                    <?= (int) $role['id'] === (int) $account['role_id'] ? 'selected' : '' ?>>
                                                    <?= accountEscape($role['role_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php if ($isSelf): ?>
                                            <input type="hidden" name="role_id" value="<?= (int) $account['role_id'] ?>">
                                            <small class="muted">Your own role cannot be changed here.</small>
                                        <?php endif; ?>

                                        <button class="btn" type="submit">Save Changes</button>
                                    </form>
                                </details>

                                <details>
                                    <summary>Reset Password</summary>
                                    <form class="inline-form" method="POST" autocomplete="off">
                                        <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="account_id" value="<?= $accountId ?>">

                                        <label>New Temporary Password</label>
                                        <input type="password" name="password" minlength="14"
                                            required autocomplete="new-password">
                                        <button class="btn" type="submit">Reset Password</button>
                                    </form>
                                </details>

                                <?php if (!$isSelf): ?>
                                <form method="POST"
                                    onsubmit="return confirm('Change this administrator account status?');">
                                    <input type="hidden" name="csrf_token" value="<?= accountEscape($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="account_id" value="<?= $accountId ?>">
                                    <button class="btn <?= $account['status'] === 'Active' ? 'btn-danger' : 'btn-secondary' ?>"
                                        type="submit">
                                        <?= $account['status'] === 'Active' ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                                <span class="muted">View only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php if ($canManage && $roles): ?>
<script>
(() => {
    const employeeInput = document.getElementById('employee_id');
    const searchButton = document.getElementById('search-employee');
    const createButton = document.getElementById('create-admin-button');
    const createForm = document.getElementById('create-admin-form');
    const message = document.getElementById('employee-lookup-message');
    const usernameField = document.getElementById('new_username');
    const nameField = document.getElementById('new_display_name');
    const emailField = document.getElementById('new_email');
    const hrmsRoleField = document.getElementById('new_hrms_role');
    const csrf = createForm.querySelector('input[name="csrf_token"]').value;

    let employeeVerified = false;

    function clearEmployeeDetails() {
        employeeVerified = false;
        usernameField.value = '';
        nameField.value = '';
        emailField.value = '';
        hrmsRoleField.value = '';
        createButton.disabled = true;
    }

    async function searchEmployee() {
        const employeeId = employeeInput.value.trim();
        clearEmployeeDetails();

        if (!/^[0-9]{1,11}$/.test(employeeId) || Number(employeeId) < 1) {
            message.textContent = 'Enter a valid numeric Employee ID.';
            return;
        }

        searchButton.disabled = true;
        message.textContent = 'Searching HRMS employee records...';

        try {
            const response = await fetch('/admin/employee-lookup.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'Accept': 'application/json'
                },
                body: new URLSearchParams({
                    csrf_token: csrf,
                    employee_id: employeeId
                })
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Employee lookup failed.');
            }

            usernameField.value = data.employee.username;
            nameField.value = data.employee.display_name;
            emailField.value = data.employee.email;
            hrmsRoleField.value = data.employee.role_name;

            employeeVerified = true;
            createButton.disabled = false;
            message.textContent = 'Employee verified. Review the details and complete the portal account fields.';
        } catch (error) {
            clearEmployeeDetails();
            message.textContent = error.message || 'Unable to search the employee. Please try again.';
        } finally {
            searchButton.disabled = false;
        }
    }

    searchButton.addEventListener('click', searchEmployee);

    employeeInput.addEventListener('input', () => {
        clearEmployeeDetails();
        message.textContent = 'Employee ID changed. Search again to verify the employee.';
    });

    employeeInput.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            searchEmployee();
        }
    });

    createForm.addEventListener('submit', event => {
        if (!employeeVerified) {
            event.preventDefault();
            message.textContent = 'Search and verify an eligible employee before creating the account.';
        }
    });
})();
</script>
<?php endif; ?>

<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>
</body>
</html>
