<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('roles.view');

$pdo = lcAdminDatabase();
$currentAdminId = (int) $_SESSION['lc_admin']['id'];
$canManage = lcAdminHasPermission('roles.manage');
$canManageAccess = lcAdminHasPermission('lawyer_portal.manage');

$message = '';
$error = '';

function roleEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function roleRedirect(?int $id = null): never
{
    $url = '/admin/portal-roles.php';
    if ($id !== null && $id > 0) {
        $url .= '?edit=' . $id;
    }
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (
        in_array($action, ['save', 'toggle'], true)
        && !$canManage
    ) {
        http_response_code(403);
        exit('You do not have permission to manage portal roles.');
    }

    if (
        in_array($action, ['grant_access', 'revoke_access'], true)
        && !$canManageAccess
    ) {
        http_response_code(403);
        exit('You do not have permission to manage Legal and Compliance access.');
    }

    if (!lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Your session token is invalid. Refresh the page and try again.');
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'save') {
            $roleId = (int) ($_POST['role_id'] ?? 0);
            $roleName = trim((string) ($_POST['role_name'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $permissionIds = $_POST['permissions'] ?? [];

            if ($roleId < 0) {
                throw new RuntimeException('Invalid role selected.');
            }

            if ($roleName === '' || mb_strlen($roleName) > 100) {
                throw new RuntimeException('Enter a role name with no more than 100 characters.');
            }

            if (mb_strlen($description) > 255) {
                throw new RuntimeException('The description must not exceed 255 characters.');
            }

            if (!is_array($permissionIds)) {
                throw new RuntimeException('Invalid permission selection.');
            }

            $permissionIds = array_values(array_unique(array_filter(
                array_map('intval', $permissionIds),
                static fn($id) => $id > 0
            )));

            if ($roleId > 0) {
                $lock = $pdo->prepare("
                    SELECT id, role_name, status
                    FROM lc_admin_roles
                    WHERE id = ?
                    FOR UPDATE
                ");
                $lock->execute([$roleId]);
                $existingRole = $lock->fetch(PDO::FETCH_ASSOC);

                if (!$existingRole) {
                    throw new RuntimeException('The selected role could not be found.');
                }

                if ($roleId === 1) {
                    $roleName = (string) $existingRole['role_name'];
                }

                $stmt = $pdo->prepare("
                    UPDATE lc_admin_roles
                    SET role_name = ?, description = ?
                    WHERE id = ?
                ");
                $stmt->execute([$roleName, $description, $roleId]);

                $logAction = 'role_updated';
                $logDescription = 'Updated portal role: ' . $roleName;
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO lc_admin_roles (role_name, description, status)
                    VALUES (?, ?, 'Active')
                ");
                $stmt->execute([$roleName, $description]);
                $roleId = (int) $pdo->lastInsertId();

                $logAction = 'role_created';
                $logDescription = 'Created portal role: ' . $roleName;
            }

            if ($permissionIds) {
                $placeholders = implode(',', array_fill(0, count($permissionIds), '?'));
                $check = $pdo->prepare("
                    SELECT id
                    FROM lc_admin_permissions
                    WHERE id IN ($placeholders)
                ");
                $check->execute($permissionIds);
                $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));

                if (count($validIds) !== count($permissionIds)) {
                    throw new RuntimeException('One or more selected permissions are invalid.');
                }
            }

            $delete = $pdo->prepare("
                DELETE FROM lc_admin_role_permissions
                WHERE role_id = ?
            ");
            $delete->execute([$roleId]);

            if ($permissionIds) {
                $insert = $pdo->prepare("
                    INSERT INTO lc_admin_role_permissions (role_id, permission_id)
                    VALUES (?, ?)
                ");

                foreach ($permissionIds as $permissionId) {
                    $insert->execute([$roleId, $permissionId]);
                }
            }

            // Never allow a permission change to remove the last active
            // administrator who can manage portal accounts.
            $adminCheck = $pdo->query("
                SELECT COUNT(*)
                FROM lc_admin_accounts a
                INNER JOIN lc_admin_roles r ON r.id = a.role_id
                INNER JOIN lc_admin_role_permissions rp ON rp.role_id = r.id
                INNER JOIN lc_admin_permissions p ON p.id = rp.permission_id
                WHERE a.status = 'Active'
                  AND r.status = 'Active'
                  AND p.permission_key = 'accounts.manage'
            ");

            if ((int) $adminCheck->fetchColumn() < 1) {
                throw new RuntimeException(
                    'This change would leave the portal without an active administrator who can manage accounts. Keep accounts.manage assigned to at least one active administrator.'
                );
            }

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                $logAction,
                $logDescription
            );

            $pdo->commit();
            roleRedirect($roleId);
        }

        if ($action === 'toggle') {
            $roleId = (int) ($_POST['role_id'] ?? 0);

            if ($roleId < 1 || $roleId === 1) {
                throw new RuntimeException('The protected Portal Administrator role cannot be deactivated.');
            }

            $lock = $pdo->prepare("
                SELECT id, role_name, status
                FROM lc_admin_roles
                WHERE id = ?
                FOR UPDATE
            ");
            $lock->execute([$roleId]);
            $role = $lock->fetch(PDO::FETCH_ASSOC);

            if (!$role) {
                throw new RuntimeException('The selected role could not be found.');
            }

            $newStatus = $role['status'] === 'Active' ? 'Inactive' : 'Active';

            if ($newStatus === 'Inactive') {
                $assigned = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM lc_admin_accounts
                    WHERE role_id = ?
                      AND status = 'Active'
                ");
                $assigned->execute([$roleId]);

                if ((int) $assigned->fetchColumn() > 0) {
                    throw new RuntimeException(
                        'This role has active administrator accounts. Reassign or disable those accounts before deactivating the role.'
                    );
                }
            }

            $update = $pdo->prepare("
                UPDATE lc_admin_roles
                SET status = ?
                WHERE id = ?
            ");
            $update->execute([$newStatus, $roleId]);

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                'role_status_changed',
                'Changed portal role ' . $role['role_name'] . ' to ' . $newStatus
            );

            $pdo->commit();
            roleRedirect($roleId);
        }

        if (in_array($action, ['grant_access', 'revoke_access'], true)) {
            $employeeId = filter_var(
                $_POST['employee_id'] ?? null,
                FILTER_VALIDATE_INT
            );

            if (!$employeeId || $employeeId <= 0) {
                throw new RuntimeException('Invalid employee selected.');
            }

            $employeeCheck = $pdo->prepare("
                SELECT
                    e.employee_id,
                    e.role_id,
                    e.employment_status,
                    COALESCE(e.is_archived, 0) AS is_archived,
                    ua.status AS account_status
                FROM em_employees e
                LEFT JOIN user_account ua
                    ON ua.employee_id = e.employee_id
                WHERE e.employee_id = ?
                LIMIT 1
                FOR UPDATE
            ");
            $employeeCheck->execute([$employeeId]);
            $employee = $employeeCheck->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                throw new RuntimeException('Employee record was not found.');
            }

            if ($action === 'grant_access') {
                if (!in_array((int) $employee['role_id'], [8, 26, 27], true)) {
                    throw new RuntimeException(
                        'Access is limited to Legal Officer, Chief Legal Officer, and Legal Counsel roles.'
                    );
                }

                if (
                    $employee['employment_status'] !== 'Active'
                    || (int) $employee['is_archived'] !== 0
                ) {
                    throw new RuntimeException(
                        'Access can only be granted to active, non-archived employees.'
                    );
                }

                if ($employee['account_status'] !== 'Active') {
                    throw new RuntimeException(
                        'The employee must have an active HRMS login account before access can be granted.'
                    );
                }

                $save = $pdo->prepare("
                    INSERT INTO lc_lawyer_portal_access (
                        employee_id,
                        is_enabled,
                        granted_by_admin_id,
                        granted_at,
                        updated_by_admin_id,
                        updated_at
                    )
                    VALUES (?, 1, ?, NOW(), ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        is_enabled = 1,
                        granted_by_admin_id = VALUES(granted_by_admin_id),
                        granted_at = VALUES(granted_at),
                        updated_by_admin_id = VALUES(updated_by_admin_id),
                        updated_at = NOW()
                ");
                $save->execute([
                    $employeeId,
                    $currentAdminId,
                    $currentAdminId
                ]);

                lcAdminLogActivity(
                    $pdo,
                    $currentAdminId,
                    'legal_compliance_access_granted',
                    "Granted Legal and Compliance access to HRMS employee ID {$employeeId}."
                );

                $pdo->commit();
                header('Location: /admin/portal-roles.php?access_status=granted#legal-compliance-access');
                exit;
            }

            $revoke = $pdo->prepare("
                INSERT INTO lc_lawyer_portal_access (
                    employee_id,
                    is_enabled,
                    updated_by_admin_id,
                    updated_at
                )
                VALUES (?, 0, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    is_enabled = 0,
                    updated_by_admin_id = VALUES(updated_by_admin_id),
                    updated_at = NOW()
            ");
            $revoke->execute([$employeeId, $currentAdminId]);

            lcAdminLogActivity(
                $pdo,
                $currentAdminId,
                'legal_compliance_access_revoked',
                "Revoked Legal and Compliance access for HRMS employee ID {$employeeId}."
            );

            $pdo->commit();
            header('Location: /admin/portal-roles.php?access_status=revoked#legal-compliance-access');
            exit;
        }

        throw new RuntimeException('Unknown role management action.');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception instanceof PDOException
            ? 'The role could not be saved. Check whether the role name is already in use.'
            : $exception->getMessage();
    }
}

$roles = $pdo->query("
    SELECT
        r.id,
        r.role_name,
        r.description,
        r.status,
        r.created_at,
        COUNT(DISTINCT a.id) AS assigned_accounts,
        COUNT(DISTINCT CASE WHEN a.status = 'Active' THEN a.id END) AS active_accounts
    FROM lc_admin_roles r
    LEFT JOIN lc_admin_accounts a ON a.role_id = r.id
    GROUP BY r.id, r.role_name, r.description, r.status, r.created_at
    ORDER BY r.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$permissions = $pdo->query("
    SELECT id, permission_key, permission_name, description, category
    FROM lc_admin_permissions
    ORDER BY category ASC, permission_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
$selectedPermissions = [];

if ($editId > 0) {
    $stmt = $pdo->prepare("
        SELECT id, role_name, description, status
        FROM lc_admin_roles
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$editId]);
    $editing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($editing) {
        $stmt = $pdo->prepare("
            SELECT permission_id
            FROM lc_admin_role_permissions
            WHERE role_id = ?
        ");
        $stmt->execute([$editId]);
        $selectedPermissions = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}

$groupedPermissions = [];
foreach ($permissions as $permission) {
    $category = trim((string) ($permission['category'] ?? 'Other')) ?: 'Other';
    $groupedPermissions[$category][] = $permission;
}

$accessSearch = trim((string) ($_GET['access_search'] ?? ''));
$legalAccessEmployees = [];

if ($canManageAccess) {
    $accessSql = "
        SELECT
            e.employee_id,
            e.employee_code,
            e.first_name,
            e.middle_name,
            e.last_name,
            e.suffix,
            e.email,
            e.role_id,
            r.role_name,
            e.employment_status,
            COALESCE(e.is_archived, 0) AS is_archived,
            ua.status AS account_status,
            a.is_enabled,
            a.granted_at,
            a.updated_at
        FROM em_employees e
        LEFT JOIN em_roles r
            ON r.role_id = e.role_id
        LEFT JOIN user_account ua
            ON ua.employee_id = e.employee_id
        LEFT JOIN lc_lawyer_portal_access a
            ON a.employee_id = e.employee_id
        WHERE e.role_id IN (8, 26, 27)
    ";

    $accessParams = [];

    if ($accessSearch !== '') {
        $accessSql .= "
            AND (
                CAST(e.employee_id AS CHAR) LIKE :id
                OR e.employee_code LIKE :code
                OR CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name) LIKE :name
                OR e.email LIKE :email
            )
        ";

        $term = '%' . $accessSearch . '%';
        $accessParams = [
            ':id' => $term,
            ':code' => $term,
            ':name' => $term,
            ':email' => $term
        ];
    }

    $accessSql .= "
        ORDER BY
            CASE
                WHEN e.employment_status = 'Active'
                 AND COALESCE(e.is_archived, 0) = 0
                 AND ua.status = 'Active'
                THEN 0 ELSE 1
            END,
            e.last_name ASC,
            e.first_name ASC
        LIMIT 200
    ";

    $accessStmt = $pdo->prepare($accessSql);
    $accessStmt->execute($accessParams);
    $legalAccessEmployees = $accessStmt->fetchAll(PDO::FETCH_ASSOC);
}

$accessStatus = (string) ($_GET['access_status'] ?? '');

$admin = $_SESSION['lc_admin'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Roles and Legal and Compliance Access | Bestlink HRMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f6fb;
            color: #1d2b42;
            font-family: Montserrat, Arial, sans-serif;
        }
        .topbar {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 30px;
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
            font-size: 11px;
        }
        .account strong { display: block; font-size: 12px; }
        .account small { color: #718096; }
        .logout, .back-link {
            color: #244d83;
            font: inherit;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }
        .logout { padding: 0; border: 0; background: transparent; cursor: pointer; }
        .content { max-width: 1200px; margin: 0 auto; padding: 34px 30px; }
        h1 { margin: 0; font-size: 23px; }
        .subtitle { margin: 8px 0 24px; color: #718096; font-size: 12px; line-height: 1.7; }
        .panel {
            margin-bottom: 20px;
            padding: 22px 24px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 8px;
        }
        .panel h2 { margin: 0 0 17px; font-size: 14px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 11px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #edf1f6; text-align: left; vertical-align: middle; }
        th { color: #718096; font-size: 10px; font-weight: 700; white-space: nowrap; }
        td { color: #34445b; }
        .status { display: inline-block; padding: 5px 8px; border-radius: 4px; background: #edf7f0; color: #27623b; font-size: 10px; font-weight: 700; }
        .status.inactive { background: #f1f3f6; color: #667085; }
        .muted { color: #718096; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        a.action-link { color: #244d83; font-weight: 700; text-decoration: none; }
        button, .button {
            display: inline-block;
            padding: 9px 13px;
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
        button.secondary, .button.secondary { background: #fff; color: #244d83; }
        button:disabled { opacity: .55; cursor: not-allowed; }
        label { display: block; margin-bottom: 7px; color: #34445b; font-size: 11px; font-weight: 700; }
        input[type="text"], textarea {
            width: 100%;
            padding: 10px 11px;
            border: 1px solid #d7e0ec;
            border-radius: 4px;
            background: #fff;
            color: #1d2b42;
            font: inherit;
            font-size: 12px;
        }
        textarea { min-height: 75px; resize: vertical; }
        .field { margin-bottom: 17px; }
        .permission-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
        .permission-group { padding: 14px; border: 1px solid #e4eaf2; border-radius: 5px; }
        .permission-group h3 { margin: 0 0 12px; color: #244d83; font-size: 11px; }
        .permission-item { display: flex; gap: 9px; align-items: flex-start; margin: 10px 0; }
        .permission-item input { margin-top: 2px; }
        .permission-item label { margin: 0; font-weight: 600; line-height: 1.5; }
        .permission-item small { display: block; margin-top: 3px; color: #718096; font-size: 10px; font-weight: 400; line-height: 1.5; }
        .notice { margin-bottom: 18px; padding: 12px 14px; border-radius: 4px; font-size: 11px; line-height: 1.7; }
        .notice.error { background: #fff1f0; color: #9b2c2c; border: 1px solid #fed7d7; }
        .notice.success { background: #edf7f0; color: #27623b; border: 1px solid #cde8d4; }
        .protected { color: #718096; font-size: 10px; }
        @media (max-width: 700px) {
            .topbar { padding: 14px 18px; }
            .content { padding: 24px 16px; }
            .permission-grid { grid-template-columns: 1fr; }
            .account { gap: 10px; }
        }
    
    .page-header-title {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        min-width: 0;
    }

    .page-header-title .subtitle {
        margin: 6px 0 0;
    }

</style>
<style id="role-management-header-layout">

    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        width: 100%;
        margin-bottom: 12px;
    }

    .page-header h1 {
        margin: 0;
    }

    .page-header .back-link {
        margin-left: auto;
        white-space: nowrap;
    }

    @media (max-width: 480px) {
        .page-header {
            flex-wrap: wrap;
        }
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
    <div class="page-header">
        <div class="page-header-title">
            <h1>Role Management</h1>
            <p class="subtitle">Create portal roles and assign permissions for the separate Legal and Compliance administration system.</p>
        </div>
        <nav class="admin-breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Dashboard</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Role Management</span>
        </nav>
    </div>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= roleEscape($error) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Portal Roles</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Role</th>
                        <th>Description</th>
                        <th>Assigned Accounts</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($roles as $role): ?>
                    <tr>
                        <td>
                            <strong><?= roleEscape($role['role_name']) ?></strong>
                            <?php if ((int) $role['id'] === 1): ?>
                                <div class="protected">Protected master role</div>
                            <?php endif; ?>
                        </td>
                        <td><?= roleEscape($role['description'] ?? '') ?></td>
                        <td><?= (int) $role['assigned_accounts'] ?> total / <?= (int) $role['active_accounts'] ?> active</td>
                        <td>
                            <span class="status<?= $role['status'] === 'Active' ? '' : ' inactive' ?>">
                                <?= roleEscape($role['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions">
                                <?php if ($canManage): ?>
                                    <a class="action-link" href="/admin/portal-roles.php?edit=<?= (int) $role['id'] ?>">Edit</a>
                                    <?php if ((int) $role['id'] !== 1): ?>
                                        <form method="POST" onsubmit="return confirm('Change the status of this role?');">
                                            <input type="hidden" name="csrf_token" value="<?= roleEscape(lcAdminCsrfToken()) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
                                            <button class="secondary" type="submit">
                                                <?= $role['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="muted">View only</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$roles): ?>
                    <tr><td colspan="5" class="muted">No portal roles have been created.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($canManageAccess): ?>
    <section class="panel" id="legal-compliance-access">
        <h2>Legal and Compliance Access</h2>
        <p class="muted" style="font-size:11px;line-height:1.7;margin:-7px 0 18px;">
            Manage which existing HRMS legal employees can access the Legal and Compliance workspace.
            This does not change their HRMS role or login account.
        </p>

        <?php if ($accessStatus === 'granted'): ?>
            <div class="notice success">Legal and Compliance access was granted successfully.</div>
        <?php elseif ($accessStatus === 'revoked'): ?>
            <div class="notice success">Legal and Compliance access was revoked successfully.</div>
        <?php endif; ?>

        <form method="GET" action="/admin/portal-roles.php"
              style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
            <input
                type="text"
                name="access_search"
                value="<?= roleEscape($accessSearch) ?>"
                placeholder="Search employee name, ID, code or email"
                aria-label="Search legal employees"
                style="max-width:340px;"
            >
            <button type="submit" class="secondary">Search</button>
            <?php if ($accessSearch !== ''): ?>
                <a class="action-link" href="/admin/portal-roles.php#legal-compliance-access">Clear</a>
            <?php endif; ?>
        </form>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>HRMS Role</th>
                        <th>HRMS Account</th>
                        <th>Lawyer Portal</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($legalAccessEmployees as $employee): ?>
                    <?php
                        $employeeRoleId = (int) $employee['role_id'];
                        $hasExplicitAccess = $employee['is_enabled'] !== null;
                        $effectiveAccess = $hasExplicitAccess
                            ? (int) $employee['is_enabled'] === 1
                            : in_array($employeeRoleId, [26, 27], true);

                        $employeeEligible =
                            $employee['employment_status'] === 'Active'
                            && (int) $employee['is_archived'] === 0
                            && $employee['account_status'] === 'Active';

                        $employeeName = trim(implode(' ', array_filter([
                            $employee['first_name'] ?? '',
                            $employee['middle_name'] ?? '',
                            $employee['last_name'] ?? '',
                            $employee['suffix'] ?? ''
                        ])));

                        if (!$employeeEligible && $effectiveAccess) {
                            $accessLabel = 'Blocked - inactive or ineligible';
                        } elseif ($effectiveAccess && !$hasExplicitAccess) {
                            $accessLabel = 'Enabled by role';
                        } elseif ($effectiveAccess) {
                            $accessLabel = 'Enabled';
                        } elseif ($hasExplicitAccess) {
                            $accessLabel = 'Revoked';
                        } else {
                            $accessLabel = 'Not granted';
                        }
                    ?>
                    <tr>
                        <td>
                            <strong><?= roleEscape($employeeName) ?></strong>
                            <div class="muted">
                                ID: <?= (int) $employee['employee_id'] ?>
                                <?php if (!empty($employee['employee_code'])): ?>
                                    · <?= roleEscape($employee['employee_code']) ?>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= roleEscape($employee['role_name'] ?? 'Unassigned') ?></td>
                        <td>
                            <?= roleEscape($employee['account_status'] ?? 'No account') ?>
                            <?php if ($employee['employment_status'] !== 'Active' || (int) $employee['is_archived'] === 1): ?>
                                <div class="muted">Employee inactive or archived</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status<?= $effectiveAccess && $employeeEligible ? '' : ' inactive' ?>">
                                <?= roleEscape($accessLabel) ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST"
                                  onsubmit="return confirm('<?= $effectiveAccess ? 'Revoke' : 'Grant' ?> Legal and Compliance access for this employee?');">
                                <input type="hidden" name="csrf_token" value="<?= roleEscape(lcAdminCsrfToken()) ?>">
                                <input type="hidden" name="employee_id" value="<?= (int) $employee['employee_id'] ?>">
                                <input type="hidden" name="action" value="<?= $effectiveAccess ? 'revoke_access' : 'grant_access' ?>">

                                <?php if ($effectiveAccess): ?>
                                    <button type="submit" class="secondary">Revoke Access</button>
                                <?php else: ?>
                                    <button type="submit" <?= !$employeeEligible ? 'disabled' : '' ?>>
                                        Grant Access
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$legalAccessEmployees): ?>
                    <tr>
                        <td colspan="5" class="muted">No matching legal employees were found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <p class="protected" style="margin-top:14px;line-height:1.7;">
            Access is limited to Legal Officer, Chief Legal Officer, and Legal Counsel.
            Employees must have an active HRMS account. Existing role-based access for
            Chief Legal Officer and Legal Counsel is retained unless explicitly revoked.
        </p>
    </section>
    <?php endif; ?>

    <?php if ($canManage): ?>
    <section class="panel" id="role-editor">
        <h2><?= $editing ? 'Edit Portal Role' : 'Create Portal Role' ?></h2>

        <?php if ($editing && (int) $editing['id'] === 1): ?>
            <div class="notice success">
                This is the protected master role. Its name and active status cannot be changed.
                Its permissions can be adjusted only while at least one active account retains account-management access.
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= roleEscape(lcAdminCsrfToken()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="role_id" value="<?= (int) ($editing['id'] ?? 0) ?>">

            <div class="field">
                <label for="role_name">Role Name</label>
                <input
                    type="text"
                    id="role_name"
                    name="role_name"
                    maxlength="100"
                    required
                    value="<?= roleEscape($editing['role_name'] ?? '') ?>"
                    <?= $editing && (int) $editing['id'] === 1 ? 'readonly' : '' ?>
                    placeholder="Example: Legal Officer">
            </div>

            <div class="field">
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="255"
                    placeholder="Describe the responsibilities of this portal role."><?= roleEscape($editing['description'] ?? '') ?></textarea>
            </div>

            <div class="field">
                <label>Portal Permissions</label>
                <p class="muted" style="font-size:10px;line-height:1.7;">
                    Select only the permissions required for this role. Permissions are enforced by the portal on the server.
                </p>

                <div class="permission-grid">
                    <?php foreach ($groupedPermissions as $category => $items): ?>
                        <div class="permission-group">
                            <h3><?= roleEscape($category) ?></h3>
                            <?php foreach ($items as $permission): ?>
                                <div class="permission-item">
                                    <input
                                        type="checkbox"
                                        id="permission_<?= (int) $permission['id'] ?>"
                                        name="permissions[]"
                                        value="<?= (int) $permission['id'] ?>"
                                        <?= in_array((int) $permission['id'], $selectedPermissions, true) ? 'checked' : '' ?>>
                                    <label for="permission_<?= (int) $permission['id'] ?>">
                                        <?= roleEscape($permission['permission_name']) ?>
                                        <small><?= roleEscape($permission['description'] ?? $permission['permission_key']) ?></small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="actions">
                <button type="submit"><?= $editing ? 'Save Role Changes' : 'Create Role' ?></button>
                <?php if ($editing): ?>
                    <a class="button secondary" href="/admin/portal-roles.php">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
    <?php endif; ?>
</main>
<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>
</body>
</html>
