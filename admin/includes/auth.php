<?php
declare(strict_types=1);

/*
 * Separate Legal and Compliance Administrator Authentication.
 * This file does not use or modify the employee authentication session.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');

    session_name('LC_ADMIN_SESSID');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (
            isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

function lcAdminDatabase(): PDO
{
    require_once dirname(__DIR__, 2) . '/database/db.php';

    $db = new Database();

    if ($db->hasConnectionError()) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $pdo = $db->getConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function lcAdminClientIp(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function lcAdminUserAgent(): string
{
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function lcAdminLogLogin(
    PDO $pdo,
    ?int $adminId,
    string $username,
    string $event
): void {
    $stmt = $pdo->prepare("
        INSERT INTO lc_admin_login_logs
            (admin_account_id, username_attempted, event_type, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $adminId,
        substr($username, 0, 80),
        $event,
        lcAdminClientIp(),
        lcAdminUserAgent()
    ]);
}

function lcAdminLogActivity(
    PDO $pdo,
    int $adminId,
    string $action,
    string $description
): void {
    $stmt = $pdo->prepare("
        INSERT INTO lc_admin_activity_logs
            (admin_account_id, action, target_type, target_id, description, ip_address, user_agent)
        VALUES (?, ?, 'administrator_portal', ?, ?, ?, ?)
    ");

    $stmt->execute([
        $adminId,
        $action,
        '',
        $description,
        lcAdminClientIp(),
        lcAdminUserAgent()
    ]);
}

function lcAdminCsrfToken(): string
{
    if (empty($_SESSION['lc_admin_csrf'])) {
        $_SESSION['lc_admin_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['lc_admin_csrf'];
}

function lcAdminVerifyCsrf(?string $token): bool
{
    return isset($_SESSION['lc_admin_csrf']) &&
        is_string($token) &&
        hash_equals($_SESSION['lc_admin_csrf'], $token);
}

function lcAdminIsAuthenticated(): bool
{
    return isset(
        $_SESSION['lc_admin']['id'],
        $_SESSION['lc_admin']['username'],
        $_SESSION['lc_admin']['role_id']
    );
}

function lcAdminRequireLogin(): void
{
    if (!lcAdminIsAuthenticated()) {
        header('Location: /admin-login.php');
        exit;
    }

    $lastActivity = (int)($_SESSION['lc_admin_last_activity'] ?? 0);

    if ($lastActivity > 0 && (time() - $lastActivity) > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);

        header('Location: /admin-login.php?expired=1');
        exit;
    }

    try {
        $pdo = lcAdminDatabase();

        $stmt = $pdo->prepare("
            SELECT a.status, a.must_change_password,
                   a.role_id, r.role_name, r.status AS role_status
            FROM lc_admin_accounts a
            INNER JOIN lc_admin_roles r ON r.id = a.role_id
            WHERE a.id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)$_SESSION['lc_admin']['id']]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$current ||
            $current['status'] !== 'Active' ||
            $current['role_status'] !== 'Active'
        ) {
            $_SESSION = [];
            session_regenerate_id(true);
            header('Location: /admin-login.php?disabled=1');
            exit;
        }

        $_SESSION['lc_admin']['role_id'] = (int)$current['role_id'];
        $_SESSION['lc_admin']['role_name'] = $current['role_name'];
        $_SESSION['lc_admin']['must_change_password'] =
            (int)$current['must_change_password'];

        $permissions = $pdo->prepare("
            SELECT p.permission_key
            FROM lc_admin_role_permissions rp
            INNER JOIN lc_admin_permissions p
                ON p.id = rp.permission_id
            WHERE rp.role_id = ?
        ");
        $permissions->execute([(int)$current['role_id']]);

        $_SESSION['lc_admin_permissions'] =
            $permissions->fetchAll(PDO::FETCH_COLUMN);

    } catch (Throwable $e) {
        error_log('LC Admin session validation error: ' . $e->getMessage());
        http_response_code(503);
        exit('Administrator access verification is temporarily unavailable.');
    }

    $currentPage = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if (
        !empty($_SESSION['lc_admin']['must_change_password']) &&
        $currentPage !== 'change-password.php'
    ) {
        header('Location: /admin/change-password.php');
        exit;
    }

    $_SESSION['lc_admin_last_activity'] = time();
}

function lcAdminHasPermission(string $permission): bool
{
    return in_array(
        $permission,
        $_SESSION['lc_admin_permissions'] ?? [],
        true
    );
}

function lcAdminRequirePermission(string $permission): void
{
    lcAdminRequireLogin();

    if (!lcAdminHasPermission($permission)) {
        http_response_code(403);
        exit('Access denied.');
    }
}

function lcAdminAuthenticate(
    PDO $pdo,
    string $username,
    string $password
): bool {
    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.username,
            a.email,
            a.display_name,
            a.password_hash,
            a.role_id,
            a.status,
            a.must_change_password,
            a.failed_login_attempts,
            a.locked_until,
            r.role_name
        FROM lc_admin_accounts a
        INNER JOIN lc_admin_roles r ON r.id = a.role_id
        WHERE a.username = ?
        LIMIT 1
    ");

    $stmt->execute([$username]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        lcAdminLogLogin($pdo, null, $username, 'failed');
        password_verify($password, '$2y$10$G9VQcyPZZc7TzVhBrT7GeuK6kLBF9FEpEryX7DbdKufAGDtuWfpzi');
        return false;
    }

    $adminId = (int)$account['id'];

    if ($account['status'] !== 'Active') {
        lcAdminLogLogin($pdo, $adminId, $username, 'disabled_account');
        return false;
    }

    $lockedUntil = $account['locked_until'];

    if ($lockedUntil && strtotime((string)$lockedUntil) > time()) {
        lcAdminLogLogin($pdo, $adminId, $username, 'locked_account');
        return false;
    }

    if (!password_verify($password, $account['password_hash'])) {
        $attempts = (int)$account['failed_login_attempts'] + 1;
        $lock = $attempts >= 5
            ? date('Y-m-d H:i:s', time() + 900)
            : null;

        $update = $pdo->prepare("
            UPDATE lc_admin_accounts
            SET failed_login_attempts = ?,
                locked_until = ?,
                updated_at = NOW()
            WHERE id = ?
        ");

        $update->execute([$attempts, $lock, $adminId]);

        lcAdminLogLogin($pdo, $adminId, $username, 'failed');

        return false;
    }

    $roleCheck = $pdo->prepare("
        SELECT status
        FROM lc_admin_roles
        WHERE id = ?
        LIMIT 1
    ");

    $roleCheck->execute([(int)$account['role_id']]);

    if ($roleCheck->fetchColumn() !== 'Active') {
        lcAdminLogLogin($pdo, $adminId, $username, 'inactive_role');
        return false;
    }

    $update = $pdo->prepare("
        UPDATE lc_admin_accounts
        SET failed_login_attempts = 0,
            locked_until = NULL,
            last_login_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");

    $update->execute([$adminId]);

    $permissionQuery = $pdo->prepare("
        SELECT p.permission_key
        FROM lc_admin_role_permissions rp
        INNER JOIN lc_admin_permissions p
            ON p.id = rp.permission_id
        WHERE rp.role_id = ?
    ");

    $permissionQuery->execute([(int)$account['role_id']]);

    session_regenerate_id(true);

    $_SESSION['lc_admin'] = [
        'id' => $adminId,
        'username' => $account['username'],
        'email' => $account['email'],
        'display_name' => $account['display_name'],
        'role_id' => (int)$account['role_id'],
        'role_name' => $account['role_name'],
        'must_change_password' => (int)$account['must_change_password']
    ];

    $_SESSION['lc_admin_permissions'] =
        $permissionQuery->fetchAll(PDO::FETCH_COLUMN);

    $_SESSION['lc_admin_last_activity'] = time();
    $_SESSION['lc_admin_csrf'] = bin2hex(random_bytes(32));

    lcAdminLogLogin($pdo, $adminId, $username, 'success');

    lcAdminLogActivity(
        $pdo,
        $adminId,
        'admin_login',
        'Administrator signed in to the Legal and Compliance portal.'
    );

    return true;
}
