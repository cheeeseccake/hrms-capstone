<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('accounts.manage');

$pdo = lcAdminDatabase();
$currentAdminId = (int) $_SESSION['lc_admin']['id'];

function newHireEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function newHireRedirect(): never
{
    header('Location: /admin/new-hire.php');
    exit;
}

$message = '';
$error = '';

/*
 * Display the generated initial password only once after account creation.
 */
$createdAccount = $_SESSION['lc_new_hire_created'] ?? null;
unset($_SESSION['lc_new_hire_created']);

/*
 * Create an HRMS login account for an eligible Legal employee.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your session token is invalid. Refresh the page and try again.');
    }

    $employeeIdRaw = trim((string) ($_POST['employee_id'] ?? ''));

    if (!preg_match('/^[0-9]{1,11}$/', $employeeIdRaw)) {
        $error = 'Select a valid employee.';
    } else {
        $employeeId = (int) $employeeIdRaw;

        try {
            $pdo->beginTransaction();

            /*
             * Lock and revalidate the employee record before creating
             * an account. The role and employment status must still qualify.
             */
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
                    e.role_id,
                    r.role_name
                FROM em_employees e
                INNER JOIN em_roles r
                    ON r.role_id = e.role_id
                WHERE e.employee_id = ?
                  AND e.role_id IN (8, 26, 27)
                  AND r.status = 'Active'
                  AND e.employment_status = 'Active'
                  AND COALESCE(e.is_archived, 0) = 0
                LIMIT 1
                FOR UPDATE
            ");
            $employeeStmt->execute([$employeeId]);
            $employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                throw new RuntimeException(
                    'This employee is not eligible for a Legal and Compliance HRMS account.'
                );
            }

            /*
             * Check for an existing account. This includes deactivated
             * accounts; this page does not reactivate existing accounts.
             */
            $accountCheck = $pdo->prepare("
                SELECT user_id
                FROM user_account
                WHERE employee_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $accountCheck->execute([$employeeId]);

            if ($accountCheck->fetchColumn()) {
                throw new RuntimeException(
                    'This employee already has an HRMS account. No duplicate account was created.'
                );
            }

            /*
             * Generate a random initial password.
             * Only its bcrypt hash is stored in user_account.
             */
            $initialPassword = bin2hex(random_bytes(12));
            $passwordHash = password_hash($initialPassword, PASSWORD_BCRYPT);

            if ($passwordHash === false) {
                throw new RuntimeException('The initial password could not be generated.');
            }

            $insert = $pdo->prepare("
                INSERT INTO user_account
                    (employee_id, password, status, first_time_login, created_at)
                VALUES
                    (?, ?, 'Active', 1, NOW())
            ");
            $insert->execute([$employeeId, $passwordHash]);

            $newUserId = (int) $pdo->lastInsertId();

            $employeeName = trim(implode(' ', array_filter([
                (string) $employee['first_name'],
                (string) ($employee['middle_name'] ?? ''),
                (string) $employee['last_name'],
                (string) ($employee['suffix'] ?? '')
            ], static fn($part) => trim($part) !== '')));

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                'hrms_employee_account_created',
                'Created HRMS login account ID ' . $newUserId .
                ' for employee ID ' . $employeeId .
                ' (' . $employeeName . ').'
            );

            $pdo->commit();

            /*
             * Keep the initial password in the administrator session
             * only long enough to display it once after redirect.
             */
            $_SESSION['lc_new_hire_created'] = [
                'employee_id' => $employeeId,
                'employee_name' => $employeeName,
                'password' => $initialPassword
            ];

            newHireRedirect();

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                $error = 'An HRMS account already exists for this employee. No duplicate account was created.';
            } else {
                $error = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'The HRMS account could not be created. Please try again or contact the system administrator.';

                error_log('New Hire account creation error: ' . $e->getMessage());
            }
        }
    }
}

/*
 * Summary counts for active, non-archived Legal employees.
 */
$summaryStmt = $pdo->query("
    SELECT
        COUNT(*) AS total_employees,
        SUM(CASE WHEN u.user_id IS NOT NULL THEN 1 ELSE 0 END) AS with_accounts,
        SUM(CASE WHEN u.user_id IS NULL THEN 1 ELSE 0 END) AS without_accounts
    FROM em_employees e
    INNER JOIN em_roles r
        ON r.role_id = e.role_id
    LEFT JOIN user_account u
        ON u.employee_id = e.employee_id
    WHERE e.role_id IN (8, 26, 27)
      AND r.status = 'Active'
      AND e.employment_status = 'Active'
      AND COALESCE(e.is_archived, 0) = 0
");
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$totalEmployees = (int) ($summary['total_employees'] ?? 0);
$withAccounts = (int) ($summary['with_accounts'] ?? 0);
$withoutAccounts = (int) ($summary['without_accounts'] ?? 0);

/*
 * Show all eligible Legal employees and identify existing accounts.
 */
$search = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT
        e.employee_id,
        e.employee_code,
        CONCAT_WS(
            ' ',
            NULLIF(TRIM(e.first_name), ''),
            NULLIF(TRIM(e.middle_name), ''),
            NULLIF(TRIM(e.last_name), ''),
            NULLIF(TRIM(e.suffix), '')
        ) AS employee_name,
        e.email,
        e.hire_date,
        e.employment_status,
        r.role_name,
        u.user_id AS existing_user_id,
        u.status AS account_status
    FROM em_employees e
    INNER JOIN em_roles r
        ON r.role_id = e.role_id
    LEFT JOIN user_account u
        ON u.employee_id = e.employee_id
    WHERE e.role_id IN (8, 26, 27)
      AND r.status = 'Active'
      AND e.employment_status = 'Active'
      AND COALESCE(e.is_archived, 0) = 0
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            CAST(e.employee_id AS CHAR) LIKE ?
            OR e.employee_code LIKE ?
            OR e.first_name LIKE ?
            OR e.middle_name LIKE ?
            OR e.last_name LIKE ?
            OR r.role_name LIKE ?
        )
    ";

    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, $term, $term];
}

$sql .= "
    ORDER BY
        CASE
            WHEN r.role_name LIKE '%Chief%' THEN 1
            WHEN r.role_name LIKE '%Counsel%' THEN 2
            ELSE 3
        END,
        e.last_name,
        e.first_name
";

$listStmt = $pdo->prepare($sql);
$listStmt->execute($params);
$employees = $listStmt->fetchAll(PDO::FETCH_ASSOC);

$admin = $_SESSION['lc_admin'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>New Hire | Bestlink HRMS</title>

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

        .brand {
            font-size: 14px;
            font-weight: 800;
        }

        .brand span {
            display: block;
            margin-top: 4px;
            color: #718096;
            font-size: 10px;
            font-weight: 500;
        }

        .content {
            max-width: 1400px;
            margin: auto;
            padding: 32px 30px;
        }

        .page-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            margin-bottom: 22px;
        }

        h1 {
            margin: 0;
            font-size: 22px;
        }

        .subtitle {
            margin: 8px 0 0;
            color: #718096;
            font-size: 11px;
            line-height: 1.7;
        }

        .back-link {
            color: #244d83;
            text-decoration: none;
            font-weight: 700;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            padding: 18px 20px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 7px;
        }

        .summary-label {
            color: #718096;
            font-size: 10px;
            font-weight: 600;
        }

        .summary-value {
            display: block;
            margin-top: 8px;
            color: #1d2b42;
            font-size: 23px;
            font-weight: 700;
        }

        .panel {
            padding: 22px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 7px;
        }

        .panel h2 {
            margin: 0 0 16px;
            font-size: 14px;
        }

        .notice,
        .alert {
            margin-bottom: 16px;
            padding: 12px 14px;
            border: 1px solid #dce5f0;
            border-radius: 5px;
            background: #f8fafd;
            color: #52627a;
            font-size: 10px;
            line-height: 1.7;
        }

        .alert-error {
            border-color: #efcccc;
            background: #fff7f7;
            color: #963d3d;
        }

        .alert-success {
            border-color: #cce5d4;
            background: #f3faf5;
            color: #245b38;
        }

        .credential-box {
            margin-top: 10px;
            padding: 13px;
            border: 1px solid #dce5f0;
            border-radius: 5px;
            background: #fff;
        }

        .credential-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 9px;
        }

        .credential-value {
            overflow-wrap: anywhere;
            color: #1d2b42;
            font-family: monospace;
            font-size: 13px;
            font-weight: 700;
        }

        .search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 9px;
            margin-bottom: 16px;
        }

        .search-row input {
            width: min(390px, 100%);
            min-height: 36px;
            padding: 8px 10px;
            border: 1px solid #d7dfeb;
            border-radius: 5px;
            font: inherit;
        }

        .btn {
            display: inline-flex;
            min-height: 34px;
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
            text-decoration: none;
            cursor: pointer;
        }

        .btn-secondary {
            border-color: #d5deea;
            background: #fff;
            color: #344b69;
        }

        .btn:disabled {
            opacity: .6;
            cursor: not-allowed;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

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

        td {
            font-size: 10px;
        }

        td strong {
            display: block;
            font-size: 11px;
        }

        td small {
            display: block;
            margin-top: 4px;
            color: #78869a;
        }

        .muted {
            color: #8290a2;
        }

        .create-form {
            margin: 0;
        }

        .create-button {
            white-space: nowrap;
        }

        .account-status {
            display: inline-block;
            padding: 6px 9px;
            border: 1px solid #dce5f0;
            border-radius: 5px;
            background: #f3f6fb;
            color: #52627a;
            font-size: 9px;
            font-weight: 800;
            white-space: nowrap;
        }

        .account-status small {
            font-weight: 500;
        }

        @media (max-width: 700px) {
            .topbar { padding: 14px 17px; }
            .content { padding: 23px 15px; }
            .page-head { display: block; }
            .back-link { display: inline-block; margin-top: 14px; }
            .summary-grid { grid-template-columns: 1fr; }
            .panel { padding: 16px; }
            .credential-row { align-items: flex-start; flex-direction: column; }
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
            <h1>New Hire</h1>
            <p class="subtitle">
                Create HRMS login accounts for eligible employees assigned to Legal and Compliance.
            </p>
        </div>
        <nav class="admin-breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Dashboard</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">New Hire</span>
        </nav>
    </div>

    <div class="summary-grid">
        <section class="summary-card">
            <span class="summary-label">Active Legal Employees</span>
            <strong class="summary-value"><?= $totalEmployees ?></strong>
        </section>

        <section class="summary-card">
            <span class="summary-label">Already Have HRMS Accounts</span>
            <strong class="summary-value"><?= $withAccounts ?></strong>
        </section>

        <section class="summary-card">
            <span class="summary-label">Without HRMS Accounts</span>
            <strong class="summary-value"><?= $withoutAccounts ?></strong>
        </section>
    </div>

    <section class="panel">
        <h2>Employee Account Registration</h2>

        <div class="notice">
            Only active, non-archived employees assigned to an active Legal role
            are listed. Creating an account does not create a new employee record
            or change the employee's existing role. The employee will sign in
            using their Employee ID and initial password.
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert">
                <?= newHireEscape($error) ?>
            </div>
        <?php endif; ?>

        <?php if (is_array($createdAccount)): ?>
            <div class="alert alert-success" role="status">
                <strong>HRMS account created successfully.</strong>
                <div>
                    Employee:
                    <?= newHireEscape($createdAccount['employee_name'] ?? '') ?>
                    (ID: <?= newHireEscape($createdAccount['employee_id'] ?? '') ?>)
                </div>

                <div class="credential-box">
                    <strong>Initial login password</strong>
                    <div class="credential-row">
                        <span class="credential-value"><?= newHireEscape($createdAccount['password'] ?? '') ?></span>
                        <button type="button"
                                class="btn btn-secondary"
                                id="copyInitialPassword">
                            Copy Password
                        </button>
                    </div>
                    <small>
                        Copy and securely provide this password to the employee.
                        It will not be displayed again after leaving or refreshing this page.
                    </small>
                </div>
            </div>
        <?php endif; ?>

        <form class="search-row" method="GET" action="/admin/new-hire.php">
            <input type="search"
                   name="q"
                   value="<?= newHireEscape($search) ?>"
                   placeholder="Search Employee ID, employee code, name, or role">
            <button class="btn" type="submit">Search</button>

            <?php if ($search !== ''): ?>
                <a class="btn btn-secondary" href="/admin/new-hire.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Employee</th>
                        <th>Legal Role</th>
                        <th>Email</th>
                        <th>Hire Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$employees): ?>
                    <tr>
                        <td colspan="6" class="muted">
                            No eligible Legal employees were found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td>
                            <strong><?= newHireEscape($employee['employee_id']) ?></strong>
                            <small><?= newHireEscape($employee['employee_code'] ?? '') ?></small>
                        </td>
                        <td>
                            <strong><?= newHireEscape($employee['employee_name']) ?></strong>
                        </td>
                        <td><?= newHireEscape($employee['role_name']) ?></td>
                        <td><?= newHireEscape($employee['email'] ?: 'Not provided') ?></td>
                        <td>
                            <?= !empty($employee['hire_date'])
                                ? newHireEscape(date('M d, Y', strtotime($employee['hire_date'])))
                                : '<span class="muted">Not recorded</span>' ?>
                        </td>
                        <td>
                            <?php if (!empty($employee['existing_user_id'])): ?>
                                <span class="account-status">
                                    Existing Account
                                    <small>
                                        (<?= newHireEscape($employee['account_status'] ?? 'Unknown') ?>)
                                    </small>
                                </span>
                            <?php else: ?>
                                <form class="create-form"
                                      method="POST"
                                      action="/admin/new-hire.php"
                                      onsubmit="return confirm('Create an HRMS login account for this employee?');">
                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= newHireEscape(lcAdminCsrfToken()) ?>">
                                    <input type="hidden"
                                           name="employee_id"
                                           value="<?= newHireEscape($employee['employee_id']) ?>">
                                    <button type="submit" class="btn create-button">
                                        Create HRMS Account
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php if (is_array($createdAccount)): ?>
<script>
document.getElementById('copyInitialPassword')?.addEventListener('click', async function () {
    const password = <?= json_encode(
        (string) ($createdAccount['password'] ?? ''),
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ) ?>;

    try {
        await navigator.clipboard.writeText(password);
        this.textContent = 'Copied';
    } catch (error) {
        this.textContent = 'Copy unavailable';
    }
});
</script>
<?php endif; ?>

<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>

</body>
</html>
