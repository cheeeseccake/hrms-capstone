<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$timeout = 1800;

// Helper to detect API/fetch requests
$isApiRequest = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ||
    !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

// Redirect to login if not authenticated
if (!isset($_SESSION['employee_id'])) {
    if ($isApiRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized. Please log in.']);
        exit();
    }
    header('Location: /index.php');
    exit();
}

// Check for session timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    if ($isApiRequest) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit();
    }
    header('Location: /index.php?reason=timeout');
    exit();
}

$_SESSION['last_activity'] = time();

// Enforce the system disclaimer until the server records acknowledgment.
// Lawyer Portal (role 27) retains its existing exemption.
if (
    !empty($_SESSION['show_login_disclaimer']) &&
    empty($_SESSION['disclaimer_acknowledged']) &&
    (int) ($_SESSION['role_id'] ?? 0) !== 27
) {
    if ($isApiRequest) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'disclaimer_required' => true,
            'redirect' => '/auth/disclaimer.php',
            'message' => 'Please acknowledge the system disclaimer before continuing.'
        ]);
        exit();
    }

    header('Location: /auth/disclaimer.php');
    exit();
}

$current_employee_name = $_SESSION['employee_name'] ?? 'Unknown';
$current_employee_id   = $_SESSION['employee_id'];
$current_employee_code = $_SESSION['employee_code'] ?? '';
$current_department_id   = $_SESSION['department_id'] ?? null;
$current_department_name = $_SESSION['department_name'] ?? 'Unknown';
$current_position_id   = $_SESSION['position_id'] ?? null;
$current_position_name = $_SESSION['position_name'] ?? 'Unknown';
$current_role_id   = $_SESSION['role_id'];
$current_role_name = $_SESSION['role_name'];
