<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['employee_id'])) {
    header('Location: /index.php');
    exit();
}

$disclaimerReturnTo = $_SESSION['disclaimer_return_to'] ?? '/modules/compliance/index.php';

if (
    !is_string($disclaimerReturnTo) ||
    !str_starts_with($disclaimerReturnTo, '/') ||
    str_starts_with($disclaimerReturnTo, '//')
) {
    $disclaimerReturnTo = '/modules/compliance/index.php';
}

// Preserve the existing Lawyer Portal exemption.
if ((int) ($_SESSION['role_id'] ?? 0) === 27) {
    header('Location: ' . $disclaimerReturnTo);
    exit();
}

if (
    empty($_SESSION['show_login_disclaimer']) ||
    !empty($_SESSION['disclaimer_acknowledged'])
) {
    header('Location: ' . $disclaimerReturnTo);
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>System Disclaimer | BESTLINK HRMS</title>
    <style>
        html, body {
            margin: 0;
            min-height: 100%;
            background: #f4f6fa;
            font-family: Montserrat, Arial, sans-serif;
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/login-disclaimer.php'; ?>
</body>
</html>
