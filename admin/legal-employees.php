<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('accounts.view');

$pdo = lcAdminDatabase();

function legalEmployeeEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$search = trim((string) ($_GET['q'] ?? ''));

$sql = "
    SELECT
        e.employee_id,
        CONCAT_WS(
            ' ',
            NULLIF(TRIM(e.first_name), ''),
            NULLIF(TRIM(e.middle_name), ''),
            NULLIF(TRIM(e.last_name), ''),
            NULLIF(TRIM(e.suffix), '')
        ) AS employee_name,
        e.email,
        e.employment_status,
        r.role_name,
        r.status AS role_status,
        u.user_id,
        u.status AS account_status,
        u.last_login
    FROM em_employees e
    INNER JOIN em_roles r
        ON r.role_id = e.role_id
    LEFT JOIN user_account u
        ON u.employee_id = e.employee_id
    WHERE LOWER(r.role_name) LIKE '%legal%'
";

$params = [];

if ($search !== '') {
    $sql .= "
        AND (
            CAST(e.employee_id AS CHAR) LIKE ?
            OR e.first_name LIKE ?
            OR e.middle_name LIKE ?
            OR e.last_name LIKE ?
            OR r.role_name LIKE ?
        )
    ";

    $term = '%' . $search . '%';
    $params = [$term, $term, $term, $term, $term];
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

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalEmployees = count($employees);
$withAccounts = 0;
$withoutAccounts = 0;

foreach ($employees as $employee) {
    if ($employee['user_id'] !== null) {
        $withAccounts++;
    } else {
        $withoutAccounts++;
    }
}

$admin = $_SESSION['lc_admin'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Legal Employees | Bestlink HRMS</title>

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

        h1 { margin: 0; font-size: 22px; }

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
            border-radius: 8px;
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
            border-radius: 8px;
        }

        .panel h2 {
            margin: 0 0 16px;
            font-size: 14px;
        }

        .search-row {
            display: flex;
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
            min-height: 36px;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
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

        .table-wrap { overflow-x: auto; }

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

        td { font-size: 10px; }

        td strong {
            display: block;
            font-size: 11px;
        }

        td small {
            display: block;
            margin-top: 4px;
            color: #78869a;
        }

        .status {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 4px;
            background: #edf7f0;
            color: #27623b;
            font-size: 9px;
            font-weight: 700;
        }

        .status.inactive {
            background: #fff0f0;
            color: #a33a3a;
        }

        .status.neutral {
            background: #f0f2f5;
            color: #596579;
        }

        .notice {
            margin-bottom: 16px;
            padding: 12px 14px;
            border: 1px solid #dce5f0;
            border-radius: 5px;
            background: #f8fafd;
            color: #52627a;
            font-size: 10px;
            line-height: 1.7;
        }

        .muted { color: #8290a2; }

        @media (max-width: 700px) {
            .topbar { padding: 14px 17px; }
            .content { padding: 23px 15px; }
            .page-head { display: block; }
            .back-link { display: inline-block; margin-top: 14px; }
            .summary-grid { grid-template-columns: 1fr; }
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
            <h1>Legal Employees</h1>
            <p class="subtitle">
                Employees assigned to Legal roles in the existing HRMS employee records.
            </p>
        </div>
        <nav class="admin-breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Dashboard</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Legal Employees</span>
        </nav>
    </div>

    <div class="summary-grid">
        <section class="summary-card">
            <span class="summary-label">Legal Employees</span>
            <strong class="summary-value"><?= $totalEmployees ?></strong>
        </section>

        <section class="summary-card">
            <span class="summary-label">With HRMS Login Accounts</span>
            <strong class="summary-value"><?= $withAccounts ?></strong>
        </section>

        <section class="summary-card">
            <span class="summary-label">Without HRMS Login Accounts</span>
            <strong class="summary-value"><?= $withoutAccounts ?></strong>
        </section>
    </div>

    <section class="panel">
        <h2>Legal Employee Directory</h2>

        <div class="notice">
            This directory is read-only. Employee information and login-account
            details are retrieved from the existing HRMS records. Changes to
            employee accounts must be handled through the existing HRMS process.
        </div>

        <form class="search-row" method="GET">
            <input type="search"
                   name="q"
                   value="<?= legalEmployeeEscape($search) ?>"
                   placeholder="Search Employee ID, name, or Legal role">
            <button class="btn" type="submit">Search</button>

            <?php if ($search !== ''): ?>
                <a class="btn btn-secondary" href="/admin/legal-employees.php">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Employee ID</th>
                        <th>Employee</th>
                        <th>Legal Role</th>
                        <th>Employment</th>
                        <th>HRMS Account</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$employees): ?>
                    <tr>
                        <td colspan="6" class="muted">
                            No employees assigned to Legal roles were found.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($employees as $employee): ?>
                    <?php
                    $employmentStatus = (string)($employee['employment_status'] ?? 'Unknown');
                    $accountStatus = $employee['user_id'] === null
                        ? 'No login account'
                        : (string)($employee['account_status'] ?? 'Unknown');

                    $employmentClass = $employmentStatus === 'Active' ? '' : 'inactive';
                    $accountClass = $accountStatus === 'Active'
                        ? ''
                        : ($accountStatus === 'No login account' ? 'neutral' : 'inactive');
                    ?>
                    <tr>
                        <td><?= legalEmployeeEscape($employee['employee_id']) ?></td>
                        <td>
                            <strong><?= legalEmployeeEscape($employee['employee_name'] ?: 'Unnamed employee') ?></strong>
                            <small><?= legalEmployeeEscape($employee['email'] ?: 'No email recorded') ?></small>
                        </td>
                        <td><?= legalEmployeeEscape($employee['role_name']) ?></td>
                        <td>
                            <span class="status <?= $employmentClass ?>">
                                <?= legalEmployeeEscape($employmentStatus) ?>
                            </span>
                        </td>
                        <td>
                            <span class="status <?= $accountClass ?>">
                                <?= legalEmployeeEscape($accountStatus) ?>
                            </span>
                        </td>
                        <td>
                            <?php if (!empty($employee['last_login'])): ?>
                                <?= legalEmployeeEscape($employee['last_login']) ?>
                            <?php else: ?>
                                <span class="muted">Never recorded</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>
</body>
</html>
