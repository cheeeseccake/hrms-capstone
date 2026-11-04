<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function disclaimerResponse(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data);
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    disclaimerResponse(405, [
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
}

if (empty($_SESSION['employee_id'])) {
    disclaimerResponse(401, [
        'success' => false,
        'message' => 'Your session has expired. Please log in again.'
    ]);
}

if ((int) ($_SESSION['role_id'] ?? 0) === 27) {
    disclaimerResponse(200, [
        'success' => true
    ]);
}

$expectedToken = $_SESSION['disclaimer_csrf_token'] ?? '';
$submittedToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

if (
    !is_string($expectedToken) ||
    $expectedToken === '' ||
    !is_string($submittedToken) ||
    !hash_equals($expectedToken, $submittedToken)
) {
    disclaimerResponse(403, [
        'success' => false,
        'message' => 'Security verification failed. Reload the page and try again.'
    ]);
}

if (empty($_SESSION['show_login_disclaimer'])) {
    disclaimerResponse(409, [
        'success' => false,
        'message' => 'No pending disclaimer acknowledgment was found.'
    ]);
}

// Only this server-side action releases the restriction.
$_SESSION['disclaimer_acknowledged'] = true;
unset($_SESSION['show_login_disclaimer']);

$redirect = $_SESSION['disclaimer_return_to']
    ?? '/modules/compliance/index.php?page=dashboard-overview';

// Only allow a local application path.
if (
    !is_string($redirect) ||
    !str_starts_with($redirect, '/') ||
    str_starts_with($redirect, '//')
) {
    $redirect = '/modules/compliance/index.php?page=dashboard-overview';
}

disclaimerResponse(200, [
    'success' => true,
    'message' => 'Disclaimer acknowledgment recorded.',
    'redirect' => $redirect
]);
