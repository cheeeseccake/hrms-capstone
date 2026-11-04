<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequireLogin();

if (empty($_SESSION['lc_admin']['must_change_password'])) {
    header('Location: /admin/');
    exit;
}

$error = '';
$success = '';

try {
    $pdo = lcAdminDatabase();
} catch (Throwable $e) {
    error_log('LC Admin password page error: ' . $e->getMessage());
    http_response_code(503);
    exit('The administrator portal is temporarily unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcAdminVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Refresh the page and try again.';
    } else {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("
            SELECT password_hash
            FROM lc_admin_accounts
            WHERE id = ? AND status = 'Active'
            LIMIT 1
        ");
        $stmt->execute([(int)$_SESSION['lc_admin']['id']]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($current, $hash)) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 14) {
            $error = 'New password must contain at least 14 characters.';
        } elseif ($new !== $confirm) {
            $error = 'Password confirmation does not match.';
        } elseif ($new === $current) {
            $error = 'Choose a password different from your current password.';
        } else {
            try {
                $pdo->beginTransaction();

                $update = $pdo->prepare("
                    UPDATE lc_admin_accounts
                    SET password_hash = ?,
                        must_change_password = 0,
                        password_changed_at = NOW(),
                        failed_login_attempts = 0,
                        locked_until = NULL,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $update->execute([
                    password_hash($new, PASSWORD_DEFAULT),
                    (int)$_SESSION['lc_admin']['id']
                ]);

                lcAdminLogActivity(
                    $pdo,
                    (int)$_SESSION['lc_admin']['id'],
                    'password_changed',
                    'Administrator changed the portal password.'
                );

                $pdo->commit();

                $_SESSION['lc_admin']['must_change_password'] = 0;
                session_regenerate_id(true);

                header('Location: /admin/');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('LC Admin password update error: ' . $e->getMessage());
                $error = 'Unable to update the password. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Change Administrator Password</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            background: #f3f6fb;
            color: #1d2b42;
            font-family: Arial, sans-serif;
        }
        main {
            width: 100%;
            max-width: 440px;
            padding: 30px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 8px;
        }
        h1 { font-size: 21px; }
        p { color: #68788e; font-size: 13px; line-height: 1.7; }
        label { display: block; margin: 18px 0 7px; font-size: 12px; font-weight: 700; }
        input {
            width: 100%;
            height: 43px;
            padding: 10px;
            border: 1px solid #d7e0ec;
            border-radius: 5px;
        }
        button {
            width: 100%;
            height: 44px;
            margin-top: 22px;
            border: 0;
            border-radius: 5px;
            background: #173f7a;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .error { color: #a52a2a; }
    </style>
</head>
<body>
<main>
    <h1>Change Your Password</h1>
    <p>Your administrator account requires a password update before continuing.</p>

    <?php if ($error !== ''): ?>
        <p class="error" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </p>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="csrf_token"
            value="<?= htmlspecialchars(lcAdminCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">

        <label for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password"
            autocomplete="current-password" required>

        <label for="new_password">New Password</label>
        <input type="password" id="new_password" name="new_password"
            minlength="14" autocomplete="new-password" required>

        <label for="confirm_password">Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password"
            minlength="14" autocomplete="new-password" required>

        <button type="submit">Update Password</button>
    </form>
</main>
</body>
</html>
