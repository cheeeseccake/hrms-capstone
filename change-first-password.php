<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/database/db.php';

$restrictedUserId = (int) ($_SESSION['first_time_login_user_id'] ?? 0);
$restrictedEmployeeId = (int) ($_SESSION['first_time_login_employee_id'] ?? 0);
$startedAt = (int) ($_SESSION['first_time_login_started_at'] ?? 0);

$clearFirstLoginSession = static function (): void {
    unset(
        $_SESSION['first_time_login_user_id'],
        $_SESSION['first_time_login_employee_id'],
        $_SESSION['first_time_login_started_at'],
        $_SESSION['first_time_login_csrf']
    );
};

if (
    $restrictedUserId <= 0 ||
    $restrictedEmployeeId <= 0 ||
    $startedAt <= 0 ||
    (time() - $startedAt) > 900
) {
    $clearFirstLoginSession();
    header('Location: index.php');
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }
} catch (Throwable $e) {
    error_log('FIRST_LOGIN database initialization failed: ' . $e->getMessage());
    http_response_code(500);
    exit('The service is temporarily unavailable. Please try again later.');
}

if (empty($_SESSION['first_time_login_csrf'])) {
    $_SESSION['first_time_login_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['first_time_login_csrf'];
$error = '';

/*
|--------------------------------------------------------------------------
| Revalidate the restricted account on every request.
|--------------------------------------------------------------------------
*/

try {
    $accountSql = "
        SELECT
            u.user_id,
            u.employee_id,
            u.password,
            u.status AS account_status,
            u.first_time_login,
            e.employment_status,
            COALESCE(e.is_archived, 0) AS is_archived
        FROM user_account u
        INNER JOIN em_employees e
            ON e.employee_id = u.employee_id
        WHERE u.user_id = ?
          AND u.employee_id = ?
        LIMIT 1
    ";

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $db->beginTransaction();
        $accountSql .= " FOR UPDATE";
    }

    $accountStmt = $db->prepare($accountSql);
    $accountStmt->execute([
        $restrictedUserId,
        $restrictedEmployeeId
    ]);

    $account = $accountStmt->fetch(PDO::FETCH_ASSOC);

    $eligible = $account
        && $account['account_status'] === 'Active'
        && $account['employment_status'] === 'Active'
        && (int) $account['is_archived'] === 0
        && (int) $account['first_time_login'] === 1;

    if (!$eligible) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        $clearFirstLoginSession();
        header('Location: index.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Process first-time password change.
    |--------------------------------------------------------------------------
    */

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $submittedToken = (string) ($_POST['csrf_token'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (
            $submittedToken === '' ||
            !hash_equals($csrfToken, $submittedToken)
        ) {
            throw new InvalidArgumentException(
                'Your session token is invalid. Refresh the page and try again.'
            );
        }

        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException(
                'Your new password must contain at least 8 characters.'
            );
        }

        if (preg_match('/[0-9]/', $newPassword) !== 1) {
            throw new InvalidArgumentException(
                'Your new password must contain at least one number.'
            );
        }

        if (preg_match('/[^A-Za-z0-9\\s]/', $newPassword) !== 1) {
            throw new InvalidArgumentException(
                'Your new password must contain at least one special character.'
            );
        }

        if ($newPassword !== $confirmPassword) {
            throw new InvalidArgumentException(
                'The password confirmation does not match.'
            );
        }

        if (password_verify($newPassword, (string) $account['password'])) {
            throw new InvalidArgumentException(
                'Your new password must be different from your temporary password.'
            );
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

        if ($newPasswordHash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        $updateStmt = $db->prepare("
            UPDATE user_account
            SET
                password = ?,
                first_time_login = 0,
                password_changed_at = NOW(),
                failed_login_attempts = 0,
                locked_until = NULL,
                updated_at = NOW()
            WHERE user_id = ?
              AND employee_id = ?
              AND status = 'Active'
              AND first_time_login = 1
        ");

        $updateStmt->execute([
            $newPasswordHash,
            $restrictedUserId,
            $restrictedEmployeeId
        ]);

        if ($updateStmt->rowCount() !== 1) {
            throw new RuntimeException(
                'The first-time password update did not affect exactly one account.'
            );
        }

        $db->commit();

        $clearFirstLoginSession();
        session_regenerate_id(true);

        header('Location: index.php?first_login=success');
        exit;
    }

} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    $error = $e->getMessage();

} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log(
        'FIRST_LOGIN password update failed for user_id=' .
        $restrictedUserId . ': ' . $e->getMessage()
    );

    $error = 'We could not update your password. Please try again.';
}

function ft_h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Set Your Password | Bestlink HRMS</title>

    <link rel="stylesheet" href="styles.css?v=announcements-top-left-20261027-144524">
    <link rel="stylesheet" href="assets/first-time-login.css?v=8">

    <style>
        .first-login-notice {
            margin: 0 0 22px;
            padding: 13px 15px;
            border: 1px solid #d7e6f5;
            border-radius: 5px;
            background: #f2f7fc;
            color: #38536b;
            font-size: 12px;
            line-height: 1.7;
        }

        .first-login-error {
            margin: 0 0 18px;
            padding: 12px 14px;
            border: 1px solid #fecaca;
            border-radius: 5px;
            background: #fef2f2;
            color: #b42318;
            font-size: 13px;
            line-height: 1.6;
        }

        .first-login-password-note {
            margin: -12px 0 18px;
            color: #687985;
            font-size: 12px;
            line-height: 1.6;
        }

        .first-login-form .field {
            margin-bottom: 18px;
        }

        .first-login-form input[type="password"]:focus {
            outline: none;
            border-color: #285b91 !important;
            box-shadow: 0 0 0 3px rgba(40, 91, 145, 0.12);
        }

        .first-login-form .forgot-button:disabled {
            opacity: .55;
            cursor: not-allowed;
        }

        @media (prefers-reduced-motion: reduce) {
            .first-login-form * {
                scroll-behavior: auto !important;
                transition: none !important;
            }
        }
    </style>
</head>

<body>

<div class="forgot-page login-container">

    <section class="brand-panel" aria-label="BCP institutional information">
        <?php require __DIR__ . '/includes/public-announcements.php'; ?>

        <footer class="brand-footer">
            <span>BCP · Bulacan Campus</span>
            <span>Human Resource Management System</span>
        </footer>
    </section>

    <main class="forgot-card">

        <div class="forgot-header">
            <img
                src="assets/bcp-logo.png"
                alt="Bestlink College of the Philippines"
                class="forgot-logo"
            >
            <h1>BESTLINK HRMS</h1>
        </div>

        <div class="forgot-body">

            <h2>Set Your New Password</h2>

            <p class="forgot-description">
                Welcome to Bestlink HRMS. Before accessing your account,
                please replace your temporary password with a new password
                that only you know.
            </p>

            <div class="first-login-notice">
                <strong>First-time account setup</strong><br>
                This step is required before you can access the HRMS.
                Your temporary password will no longer work after this change.
            </div>

            <?php if ($error !== ''): ?>
                <div class="first-login-error" role="alert">
                    <?= ft_h($error) ?>
                </div>
            <?php endif; ?>

            <form
                method="post"
                class="first-login-form"
                autocomplete="on"
                id="firstLoginPasswordForm"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= ft_h($csrfToken) ?>"
                >

                <div class="field">
                    <label for="new_password">New Password</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="8"
                        maxlength="4096"
                        placeholder="Enter your new password"
                        autocomplete="new-password"
                        required
                        autofocus
                    >
                </div>

                <p class="first-login-password-note">
                    Your password must contain at least 8 characters.
                </p>

                <div class="password-strength" id="passwordStrength" hidden>
                    <div class="password-strength-heading">
                        <span>Password strength</span>
                        <strong id="passwordStrengthText">Enter a password</strong>
                    </div>
                    <div class="password-strength-track">
                        <div class="password-strength-bar" id="passwordStrengthBar"></div>
                    </div>
                    <ul class="password-rules">
                        <li id="ruleLength">○ At least 8 characters</li>
                        <li id="ruleNumber">○ At least one number (0–9)</li>
                        <li id="ruleSpecial">○ At least one special character (!@#$%)</li>
                        <li id="ruleCase">○ Uppercase and lowercase letters</li>
                    </ul>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm New Password</label>
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        minlength="8"
                        maxlength="4096"
                        placeholder="Re-enter your new password"
                        autocomplete="new-password"
                        required
                    >
                    <p
                        id="passwordMatchMessage"
                        class="password-match-message"
                        aria-live="polite"
                    ></p>
                </div>

                <button
                    type="submit"
                    class="forgot-button"
                    id="firstLoginSubmit"
                    disabled
                >
                    Save New Password
                </button>
            </form>

            <a href="index.php" class="back-login">
                Return to Login
            </a>

        </div>
    </main>
</div>

<script>
(function () {
    const form = document.getElementById('firstLoginPasswordForm');
    const password = document.getElementById('new_password');
    const confirm = document.getElementById('confirm_password');
    const box = document.getElementById('passwordStrength');
    const label = document.getElementById('passwordStrengthText');
    const bar = document.getElementById('passwordStrengthBar');
    const match = document.getElementById('passwordMatchMessage');
    const button = document.getElementById('firstLoginSubmit');

    if (!form || !password || !confirm || !box || !label || !bar || !match || !button) return;

    // Keep the active password field visible, including when the mobile keyboard opens.
    let scrollFrame = null;

    function keepActiveFieldVisible(field) {
        if (!field || document.activeElement !== field) return;

        if (scrollFrame) {
            cancelAnimationFrame(scrollFrame);
        }

        scrollFrame = requestAnimationFrame(function () {
            const rect = field.getBoundingClientRect();
            const viewportHeight = window.visualViewport
                ? window.visualViewport.height
                : window.innerHeight;

            const topSpace = 24;
            const bottomSpace = 28;

            if (rect.bottom > viewportHeight - bottomSpace) {
                window.scrollBy({
                    top: rect.bottom - viewportHeight + bottomSpace,
                    behavior: 'smooth'
                });
            } else if (rect.top < topSpace) {
                window.scrollBy({
                    top: rect.top - topSpace,
                    behavior: 'smooth'
                });
            }
        });
    }

    [password, confirm].forEach(function (field) {
        field.addEventListener('focus', function () {
            setTimeout(function () {
                keepActiveFieldVisible(field);
            }, 250);
        });

        field.addEventListener('input', function () {
            keepActiveFieldVisible(field);
        });
    });

    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            keepActiveFieldVisible(document.activeElement);
        });

        window.visualViewport.addEventListener('scroll', function () {
            keepActiveFieldVisible(document.activeElement);
        });
    }

    const tests = {
        ruleLength: v => v.length >= 8,
        ruleNumber: v => /[0-9]/.test(v),
        ruleSpecial: v => /[^A-Za-z0-9\s]/.test(v),
        ruleCase: v => /[a-z]/.test(v) && /[A-Z]/.test(v)
    };

    function refresh() {
        const v = password.value;

        const results = Object.entries(tests).map(([id, test]) => {
            const el = document.getElementById(id);
            const ok = test(v);
            el.classList.toggle('is-valid', ok);
            el.textContent = (ok ? '✓ ' : '○ ') + ({
                ruleLength: 'At least 8 characters',
                ruleNumber: 'At least one number (0–9)',
                ruleSpecial: 'At least one special character (!@#$%)',
                ruleCase: 'Uppercase and lowercase letters'
            })[id];
            return ok;
        });

        const requiredOK = tests.ruleLength(v) &&
                           tests.ruleNumber(v) &&
                           tests.ruleSpecial(v);

        box.hidden = !v;

        const score = results.filter(Boolean).length;
        let strength = 'Weak', width = '25%', cls = '';

        if (score >= 4 && v.length >= 12) {
            strength = 'Strong'; width = '100%'; cls = 'is-strong';
        } else if (score >= 3) {
            strength = 'Good'; width = '75%'; cls = 'is-good';
        } else if (score >= 2) {
            strength = 'Fair'; width = '50%'; cls = 'is-fair';
        }

        label.textContent = v ? strength : 'Enter a password';
        bar.className = 'password-strength-bar ' + cls;
        bar.style.width = v ? width : '0%';

        const same = v.length > 0 && v === confirm.value;

        match.textContent = !confirm.value ? '' :
            same ? '✓ Passwords match.' : 'Passwords do not match.';
        match.classList.toggle('is-valid', same);
        match.classList.toggle('is-invalid', !!confirm.value && !same);

        button.disabled = !(requiredOK && same);

        password.setCustomValidity(requiredOK ? '' :
            'Use at least 8 characters, one number, and one special character.');
        confirm.setCustomValidity(!confirm.value || same ? '' :
            'The passwords do not match.');
    }

    password.addEventListener('input', refresh);
    confirm.addEventListener('input', refresh);

    form.addEventListener('submit', function (e) {
        refresh();
        if (!form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
        }
    });

    refresh();
})();
</script>

</body>
</html>
