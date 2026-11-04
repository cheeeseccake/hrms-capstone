<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
    !lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid logout request.');
}

if (lcAdminIsAuthenticated()) {
    try {
        $pdo = lcAdminDatabase();

        lcAdminLogActivity(
            $pdo,
            (int)$_SESSION['lc_admin']['id'],
            'admin_logout',
            'Administrator signed out of the Legal and Compliance portal.'
        );
    } catch (Throwable $e) {
        error_log('LC Admin logout logging error: ' . $e->getMessage());
    }
}

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Lax'
    ]);
}

session_destroy();

header('Location: /admin-login.php');
exit;
