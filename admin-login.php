<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/includes/auth.php';

if (lcAdminIsAuthenticated()) {
    header('Location: /admin/');
    exit;
}

$error = '';
$notice = '';

if (isset($_GET['expired'])) {
    $notice = 'Your session expired. Please sign in again.';
}

try {
    $pdo = lcAdminDatabase();
} catch (Throwable $e) {
    error_log('LC Admin database error: ' . $e->getMessage());
    http_response_code(503);
    exit('The administrator portal is temporarily unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = $_POST['csrf_token'] ?? null;

    if (!lcAdminVerifyCsrf(is_string($csrf) ? $csrf : null)) {
        $error = 'Your session token is invalid. Refresh and try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Enter your username and password.';
    } elseif (strlen($username) > 80 || strlen($password) > 4096) {
        $error = 'Invalid login details.';
    } else {
        try {
            if (lcAdminAuthenticate($pdo, $username, $password)) {
                if (!empty($_SESSION['lc_admin']['must_change_password'])) {
                    header('Location: /admin/change-password.php');
                } else {
                    header('Location: /admin/');
                }
                exit;
            }

            $error = 'Invalid login details or account temporarily locked.';
        } catch (Throwable $e) {
            error_log('LC Admin authentication error: ' . $e->getMessage());
            $error = 'Unable to complete sign in. Please try again later.';
        }
    }
}

$csrfToken = lcAdminCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>System Administrator | Bestlink HRMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f3f6fb;
            color: #17243a;
            font-family: Montserrat, Arial, sans-serif;
        }
        .login-card {
            width: 100%;
            max-width: 430px;
            padding: 42px 38px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 10px;
            box-shadow: 0 12px 36px rgba(24, 48, 82, .08);
        }
        .brand {
            text-align: center;
            margin-bottom: 30px;
        }
        .brand img {
            width: 76px;
            height: 76px;
            object-fit: contain;
            margin-bottom: 16px;
        }
        .brand h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -.4px;
        }
        .brand p {
            margin: 8px 0 0;
            color: #718096;
            font-size: 12px;
            line-height: 1.6;
        }
        .section-title {
            margin: 0 0 22px;
            font-size: 14px;
            font-weight: 700;
            color: #243b60;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            font-weight: 600;
        }
        input {
            display: block;
            width: 100%;
            height: 46px;
            margin-bottom: 19px;
            padding: 0 13px;
            border: 1px solid #d7e0ec;
            border-radius: 6px;
            background: #fff;
            color: #17243a;
            font: inherit;
            font-size: 13px;
        }
        input:focus {
            outline: none;
            border-color: #2457a7;
            box-shadow: 0 0 0 3px rgba(36, 87, 167, .11);
        }
        button {
            width: 100%;
            height: 46px;
            margin-top: 4px;
            border: 0;
            border-radius: 6px;
            background: #173f7a;
            color: #fff;
            font: inherit;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
        }
        button:hover { background: #123363; }
        .message {
            margin-bottom: 18px;
            padding: 12px;
            border-radius: 6px;
            font-size: 12px;
            line-height: 1.6;
        }
        .error { background: #fff0f0; color: #a52a2a; }
        .notice { background: #edf4ff; color: #24518d; }
        .footer {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #edf0f5;
            color: #8995a7;
            text-align: center;
            font-size: 10px;
            line-height: 1.7;
        }
        @media (max-width: 480px) {
            .login-card { padding: 34px 24px; }
        }
    </style>
</head>
<body>
    <main class="login-card">
        <div class="brand">
            <img src="/assets/bcp-logo.png" alt="Bestlink College of the Philippines">
            <h1>BESTLINK HRMS</h1>
            <p>Legal and Compliance System Administration</p>
        </div>

        <h2 class="section-title">System Administrator Sign In</h2>

        <?php if ($error !== ''): ?>
            <div class="message error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($notice !== ''): ?>
            <div class="message notice" role="status">
                <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/admin-login.php" autocomplete="on">
            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"
            >

            <label for="username">Administrator Username</label>
            <input
                type="text"
                id="username"
                name="username"
                maxlength="80"
                autocomplete="username"
                required
                autofocus
            >

            <label for="password">Password</label>
            <input
                type="password"
                id="password"
                name="password"
                autocomplete="current-password"
                required
            >

            <button type="submit">Sign In</button>
        </form>

        <div class="footer">
            Authorized administrator access only.<br>
            Bestlink College of the Philippines
        </div>
    </main>
</body>
</html>
