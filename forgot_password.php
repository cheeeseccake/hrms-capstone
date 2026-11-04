<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/database/db.php';
require_once __DIR__ . '/modules/compliance/lib/services/EmailService.php';

use App\Services\EmailService;

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    exit('Database connection unavailable.');
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrfToken(): string
{
    if (empty($_SESSION['forgot_password_csrf'])) {
        $_SESSION['forgot_password_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['forgot_password_csrf'];
}

function verifyCsrf(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['forgot_password_csrf'])
        && hash_equals($_SESSION['forgot_password_csrf'], $token);
}

function clientIp(): string
{
    /*
     * Do not trust X-Forwarded-For for authentication decisions.
     * LiteSpeed/PHP provides REMOTE_ADDR directly.
     */
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function clearResetSession(): void
{
    unset(
        $_SESSION['password_reset_employee_id'],
        $_SESSION['password_reset_otp_id'],
        $_SESSION['password_reset_verified']
    );
}

function genericRecoveryMessage(): string
{
    return 'If the Employee ID is eligible for account recovery, a verification code has been sent to the registered email address.';
}

/*
|--------------------------------------------------------------------------
| State
|--------------------------------------------------------------------------
*/

$step = 'request';
$message = '';
$error = '';

if (!empty($_SESSION['password_reset_employee_id'])) {
    $step = 'otp';
}

if (!empty($_SESSION['password_reset_verified'])) {
    $step = 'reset';
}

/*
|--------------------------------------------------------------------------
| POST handling
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    error_log(
        'FORGOT_PASSWORD DEBUG POST session_id=' . session_id() .
        ' employee_id=' . ($_SESSION['password_reset_employee_id'] ?? 'MISSING') .
        ' otp_id=' . ($_SESSION['password_reset_otp_id'] ?? 'MISSING') .
        ' verified=' . ($_SESSION['password_reset_verified'] ?? 'MISSING') .
        ' csrf_present=' . (!empty($_SESSION['forgot_password_csrf']) ? 'YES' : 'NO')
    );

    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {
        $debugSession = 'SESSION=' . session_id()
            . ' | EMPLOYEE=' . ($_SESSION['password_reset_employee_id'] ?? 'MISSING')
            . ' | OTP_ID=' . ($_SESSION['password_reset_otp_id'] ?? 'MISSING')
            . ' | VERIFIED=' . ($_SESSION['password_reset_verified'] ?? 'MISSING')
            . ' | CSRF=' . (!empty($_SESSION['forgot_password_csrf']) ? 'YES' : 'NO');

        $action = $_POST['action'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | REQUEST OTP
        |--------------------------------------------------------------------------
        */

        if ($action === 'request_otp') {

            $employeeId = trim((string)($_POST['employee_id'] ?? ''));

            if ($employeeId === '' || !ctype_digit($employeeId)) {
                $error = 'Please enter a valid Employee ID.';
            } else {

                $employeeId = (int)$employeeId;
                $ip = clientIp();

                /*
                 * No OTP request rate limit.
                 *
                 * Every valid request generates a new OTP.
                 * The previous OTP is invalidated before the new one
                 * is created below.
                 */

                if ($message === '' && $error === '') {

                    /*
                     * Find an actual account and its registered email.
                     */
                    $stmt = $db->prepare("
                        SELECT
                            e.employee_id,
                            e.first_name,
                            e.last_name,
                            e.email
                        FROM em_employees e
                        INNER JOIN user_account u
                            ON u.employee_id = e.employee_id
                        WHERE e.employee_id = :employee_id
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':employee_id' => $employeeId
                    ]);

                    $employee = $stmt->fetch();

                    /*
                     * Keep the response generic if the account does not exist.
                     */
                    if (!$employee || empty($employee['email'])) {
                        $message = genericRecoveryMessage();
                        $step = 'otp';
                    } else {


                        $stmt = $db->prepare("
                            UPDATE password_reset_otps
                            SET used_at = NOW()
                            WHERE employee_id = :employee_id
                              AND used_at IS NULL
                        ");

                        $stmt->execute([
                            ':employee_id' => $employeeId
                        ]);

                        /*
                         * Cryptographically secure six-digit OTP.
                         */
                        $otp = (string)random_int(100000, 999999);
                        $otpHash = password_hash($otp, PASSWORD_DEFAULT);

                        /*
                         * OTP expires in 60 seconds.
                         */
                        $stmt = $db->prepare("
                            INSERT INTO password_reset_otps
                            (
                                employee_id,
                                otp_hash,
                                expires_at,
                                requested_ip
                            )
                            VALUES
                            (
                                :employee_id,
                                :otp_hash,
                                DATE_ADD(NOW(), INTERVAL 60 SECOND),
                                :requested_ip
                            )
                        ");

                        $stmt->execute([
                            ':employee_id' => $employeeId,
                            ':otp_hash' => $otpHash,
                            ':requested_ip' => $ip
                        ]);

                        $otpId = (int)$db->lastInsertId();

                        /*
                         * Send the OTP using the existing PHPMailer service.
                         */
                        $emailService = EmailService::getInstance();

                        $fullName = trim(
                            (string)$employee['first_name'] . ' ' .
                            (string)$employee['last_name']
                        );

                        $subject = 'Bestlink HRMS Password Reset Verification Code';

                        $body = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Reset Verification</title>

<style id="shared-auth-layout">
/* Match login and password recovery layout */
@media (min-width: 901px) {
    body .forgot-page {
        display: grid !important;
        grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) !important;
        align-items: stretch !important;
        justify-content: stretch !important;
        gap: 0 !important;
        width: 100% !important;
        min-height: 100vh !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    body .forgot-page > .brand-panel {
        width: 100% !important;
        min-width: 0 !important;
        min-height: 100vh !important;
        box-sizing: border-box !important;
    }

    body .forgot-page > .forgot-card {
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 100vh !important;
        margin: 0 !important;
        padding: clamp(24px, 2.5vw, 40px) !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #f8fafc !important;
        box-sizing: border-box !important;
        overflow: visible !important;
    }
}

/* Shared content width and vertical rhythm */
body .forgot-page > .forgot-card > .forgot-header,
body .forgot-page > .forgot-card > .forgot-body {
    width: 100% !important;
    max-width: 520px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    box-sizing: border-box !important;
}

body .forgot-page > .forgot-card > .forgot-header {
    margin-top: 0 !important;
    margin-bottom: 34px !important;
    padding: 0 !important;
    text-align: center !important;
    background: transparent !important;
}

body .forgot-page .forgot-header .forgot-logo {
    display: block !important;
    width: 72px !important;
    height: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    margin: 0 auto 27px !important;
    object-fit: contain !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: transparent !important;
}

body .forgot-page .forgot-header h1 {
    margin: 0 !important;
    color: #183b56 !important;
    font-size: 24px !important;
    font-weight: 650 !important;
    line-height: 1.3 !important;
    letter-spacing: -0.4px !important;
    text-align: center !important;
}

body .forgot-page > .forgot-card > .forgot-body {
    padding: 0 !important;
}

body .forgot-page .request-header-content {
    width: 100% !important;
    margin: 0 0 26px !important;
    text-align: center !important;
}

body .forgot-page .request-header-content h2,
body .forgot-page .forgot-body > h2 {
    margin: 0 0 12px !important;
    color: #183b56 !important;
    font-size: 19px !important;
    font-weight: 600 !important;
    line-height: 1.4 !important;
}

body .forgot-page .forgot-description {
    margin: 0 0 22px !important;
    color: #687985 !important;
    font-size: 13px !important;
    line-height: 1.8 !important;
}

body .forgot-page .field {
    width: 100% !important;
    margin: 0 0 23px !important;
    box-sizing: border-box !important;
}

body .forgot-page .field label {
    display: block !important;
    margin: 0 0 9px !important;
    color: #344054 !important;
    font-size: 13px !important;
    font-weight: 600 !important;
}

body .forgot-page .field input:not(.otp-input) {
    display: block !important;
    width: 100% !important;
    height: 49px !important;
    min-height: 49px !important;
    padding: 0 14px !important;
    border: 1px solid #d5dee9 !important;
    border-radius: 5px !important;
    background: #fff !important;
    color: #263238 !important;
    font-size: 14px !important;
    box-sizing: border-box !important;
}

body .forgot-page .forgot-button {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    width: 100% !important;
    min-height: 49px !important;
    height: auto !important;
    margin: 0 !important;
    padding: 12px 18px !important;
    border: 1px solid #183b56 !important;
    border-radius: 5px !important;
    background: #183b56 !important;
    color: #fff !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    box-sizing: border-box !important;
}

body .forgot-page .forgot-button:hover {
    background: #285b91 !important;
    border-color: #285b91 !important;
}

body .forgot-page .back-login {
    display: block !important;
    width: 100% !important;
    margin: 26px auto 0 !important;
    color: #285b91 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    text-align: center !important;
}

@media (max-width: 900px) {
    body .forgot-page > .forgot-card {
        box-sizing: border-box !important;
    }

    body .forgot-page > .forgot-card > .forgot-header,
    body .forgot-page > .forgot-card > .forgot-body {
        max-width: 410px !important;
    }
}

@media (max-width: 480px) {
    body .forgot-page > .forgot-card {
        padding: 23px 20px !important;
    }

    body .forgot-page > .forgot-card > .forgot-header {
        margin-bottom: 27px !important;
    }

    body .forgot-page .forgot-header .forgot-logo {
        margin-bottom: 22px !important;
    }
}
</style>


<style id="exact-brand-alignment">
body .brand-panel {
    position: relative !important;
    box-sizing: border-box !important;
}

body .brand-panel > .brand-identity {
    position: absolute !important;
    top: 40px !important;
    left: 48px !important;
    right: 48px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
}

body .brand-panel > .brand-identity img {
    display: block !important;
    flex: 0 0 72px !important;
    width: 72px !important;
    height: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    min-height: 72px !important;
    max-height: 72px !important;
    margin: 0 !important;
    padding: 0 !important;
    object-fit: contain !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

body .brand-panel > .brand-identity .brand-school-name {
    display: block !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    font-size: 17px !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    letter-spacing: .025em !important;
}

body .brand-panel > .brand-identity .brand-school-name > span {
    display: block !important;
}

body .brand-panel > .brand-footer {
    position: absolute !important;
    right: 48px !important;
    bottom: 32px !important;
    left: 48px !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-end !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    font-size: 11px !important;
    line-height: 1.6 !important;
}

body .brand-panel > .brand-footer span {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: none !important;
}

body .brand-panel > .brand-footer span:last-child {
    text-align: right !important;
}

@media (max-width: 760px) {
    body .brand-panel > .brand-identity {
        top: 24px !important;
        left: 22px !important;
        right: 22px !important;
        gap: 12px !important;
    }

    body .brand-panel > .brand-identity img {
        flex-basis: 56px !important;
        width: 56px !important;
        height: 56px !important;
        min-width: 56px !important;
        max-width: 56px !important;
        min-height: 56px !important;
        max-height: 56px !important;
    }

    body .brand-panel > .brand-identity .brand-school-name {
        font-size: 14px !important;
    }

    body .brand-panel > .brand-footer {
        right: 22px !important;
        bottom: 20px !important;
        left: 22px !important;
    }
}
</style>

</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:600px;margin:30px auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e1e7ef;">
        <div style="background:#0b4f8a;padding:24px;text-align:center;">
            <h2 style="margin:0;color:#ffffff;font-size:22px;">Bestlink HRMS</h2>
            <p style="margin:7px 0 0;color:#dcecff;font-size:14px;">Password Recovery</p>
        </div>

        <div style="padding:30px;">
            <p style="font-size:15px;color:#333333;">
                Hello ' . h($fullName) . ',
            </p>

            <p style="font-size:15px;color:#333333;line-height:1.6;">
                We received a request to reset your Bestlink HRMS password.
                Use the verification code below to continue:
            </p>

            <div style="margin:25px 0;text-align:center;">
                <span style="display:inline-block;background:#eef5fc;border:1px solid #cbdff2;border-radius:8px;padding:16px 28px;color:#0b4f8a;font-size:30px;font-weight:bold;letter-spacing:7px;">
                    ' . h($otp) . '
                </span>
            </div>

            <p style="font-size:14px;color:#555555;line-height:1.6;">
                This verification code will expire in <strong>60 seconds</strong>.
                Do not share this code with anyone.
            </p>

            <p style="font-size:13px;color:#777777;line-height:1.6;">
                If you did not request a password reset, you can safely ignore this email.
            </p>
        </div>

        <div style="padding:16px 30px;background:#f8fafc;border-top:1px solid #e5eaf0;">
            <p style="margin:0;font-size:12px;color:#777777;text-align:center;">
                Bestlink College of the Philippines — Human Resources
            </p>
        </div>
    </div>



</body>
</html>
';

                        $altBody =
                            "Bestlink HRMS Password Recovery\n\n" .
                            "Hello " . $fullName . ",\n\n" .
                            "Your verification code is: " . $otp . "\n\n" .
                            "This code expires in 60 seconds.\n" .
                            "Do not share this code with anyone.";

                        $sent = $emailService->send(
                            (string)$employee['email'],
                            $subject,
                            $body,
                            $altBody
                        );

                        if ($sent) {

                            $_SESSION['password_reset_employee_id'] = $employeeId;
                            $_SESSION['password_reset_otp_id'] = $otpId;
                            $_SESSION['password_reset_verified'] = false;

                            error_log(
                                'FORGOT_PASSWORD DEBUG SESSION_SET session_id=' . session_id() .
                                ' employee_id=' . $_SESSION['password_reset_employee_id'] .
                                ' otp_id=' . $_SESSION['password_reset_otp_id'] .
                                ' verified=' . ($_SESSION['password_reset_verified'] ? 'true' : 'false')
                            );

                            $step = 'otp';
                            $message = genericRecoveryMessage();

                        } else {

                            /*
                             * Email failed, so invalidate this OTP.
                             */
                            $stmt = $db->prepare("
                                UPDATE password_reset_otps
                                SET used_at = NOW()
                                WHERE id = :id
                            ");

                            $stmt->execute([
                                ':id' => $otpId
                            ]);

                            $message = 'We could not send the verification code right now. Please try again later.';
                            $step = 'request';
                        }
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | VERIFY OTP
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'verify_otp') {

            $otp = trim((string)($_POST['otp'] ?? ''));
            $employeeId = (int)($_SESSION['password_reset_employee_id'] ?? 0);
            $otpId = (int)($_SESSION['password_reset_otp_id'] ?? 0);

            if ($employeeId <= 0 || $otpId <= 0) {
                clearResetSession();
                $error = 'Your password recovery session has expired. Please request a new code.';
                $step = 'request';

            } elseif (!preg_match('/^\d{6}$/', $otp)) {
                $error = 'Please enter the 6-digit verification code.';
                $step = 'otp';

            } else {

                $stmt = $db->prepare("
                    SELECT
                        id,
                        otp_hash,
                        expires_at,
                        attempts,
                        verified_at,
                        used_at
                    FROM password_reset_otps
                    WHERE id = :id
                      AND employee_id = :employee_id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':id' => $otpId,
                    ':employee_id' => $employeeId
                ]);

                $reset = $stmt->fetch();

                if (!$reset) {

                    clearResetSession();
                    $error = 'Invalid or expired verification code.';
                    $step = 'request';

                } elseif (!empty($reset['used_at'])) {

                    clearResetSession();
                    $error = 'This verification code is no longer valid.';
                    $step = 'request';

                } elseif (!empty($reset['verified_at'])) {

                    $_SESSION['password_reset_verified'] = true;
                    $step = 'reset';

                } elseif (strtotime((string)$reset['expires_at']) <= time()) {

                    $error = 'This verification code has expired. Please request a new code.';
                    $step = 'otp';

                } elseif ((int)$reset['attempts'] >= 5) {

                    $error = 'Too many incorrect attempts. Please request a new verification code.';
                    clearResetSession();
                    $step = 'request';

                } else {

                    /*
                     * Count the attempt before verification.
                     */
                    $stmt = $db->prepare("
                        UPDATE password_reset_otps
                        SET attempts = attempts + 1
                        WHERE id = :id
                          AND employee_id = :employee_id
                          AND used_at IS NULL
                          AND verified_at IS NULL
                          AND attempts < 5
                    ");

                    $stmt->execute([
                        ':id' => $otpId,
                        ':employee_id' => $employeeId
                    ]);

                    if (password_verify($otp, (string)$reset['otp_hash'])) {

                        $stmt = $db->prepare("
                            UPDATE password_reset_otps
                            SET verified_at = NOW()
                            WHERE id = :id
                              AND employee_id = :employee_id
                              AND used_at IS NULL
                        ");

                        $stmt->execute([
                            ':id' => $otpId,
                            ':employee_id' => $employeeId
                        ]);

                        $_SESSION['password_reset_verified'] = true;

                        $step = 'reset';
                        $message = 'Verification successful. You can now create a new password.';

                    } else {

                        $newAttempts = ((int)$reset['attempts']) + 1;

                        if ($newAttempts >= 5) {
                            clearResetSession();
                            $error = 'Too many incorrect attempts. Please request a new verification code.';
                            $step = 'request';
                        } else {
                            $remaining = 5 - $newAttempts;
                            $error = 'Incorrect verification code. Attempts remaining: ' . $remaining . '.';
                            $step = 'otp';
                        }
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | RESET PASSWORD
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'reset_password') {

            $employeeId = (int)($_SESSION['password_reset_employee_id'] ?? 0);
            $otpId = (int)($_SESSION['password_reset_otp_id'] ?? 0);
            $verified = !empty($_SESSION['password_reset_verified']);

            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');

            if ($employeeId <= 0 || $otpId <= 0 || !$verified) {

                clearResetSession();
                $error = 'Your password recovery session has expired. Please start again.';
                $step = 'request';

            } elseif (strlen($newPassword) < 8) {

                $error = 'Your new password must contain at least 8 characters.';
                $step = 'reset';

            } elseif ($newPassword !== $confirmPassword) {

                $error = 'The passwords do not match.';
                $step = 'reset';

            } else {

                /*
                 * Verify the OTP is still valid and has been verified.
                 */
                $stmt = $db->prepare("
                    SELECT id, expires_at, verified_at, used_at
                    FROM password_reset_otps
                    WHERE id = :id
                      AND employee_id = :employee_id
                    LIMIT 1
                ");

                $stmt->execute([
                    ':id' => $otpId,
                    ':employee_id' => $employeeId
                ]);

                $reset = $stmt->fetch();

                if (
                    !$reset ||
                    empty($reset['verified_at']) ||
                    !empty($reset['used_at']) ||
                    strtotime((string)$reset['expires_at']) <= time()
                ) {

                    clearResetSession();
                    $error = 'Your verification session has expired. Please request a new code.';
                    $step = 'request';

                } else {

                    $newPasswordHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $db->beginTransaction();

                    try {

                        $stmt = $db->prepare("
                            UPDATE user_account
                            SET
                                password = :password,
                                password_changed_at = NOW(),
                                failed_login_attempts = 0,
                                locked_until = NULL,
                                updated_at = NOW()
                            WHERE employee_id = :employee_id
                            LIMIT 1
                        ");

                        $stmt->execute([
                            ':password' => $newPasswordHash,
                            ':employee_id' => $employeeId
                        ]);

                        if ($stmt->rowCount() !== 1) {
                            throw new RuntimeException('Password update did not affect an account.');
                        }

                        $stmt = $db->prepare("
                            UPDATE password_reset_otps
                            SET used_at = NOW()
                            WHERE id = :id
                              AND employee_id = :employee_id
                        ");

                        $stmt->execute([
                            ':id' => $otpId,
                            ':employee_id' => $employeeId
                        ]);

                        $db->commit();

                        clearResetSession();

                        /*
                         * Regenerate the session ID after successful recovery.
                         */
                        session_regenerate_id(true);

                        header('Location: index.php?password_reset=success');
                        exit;

                    } catch (Throwable $e) {

                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }

                        error_log(
                            'Password reset update failed for employee_id=' .
                            $employeeId . ': ' . $e->getMessage()
                        );

                        $error = 'We could not update your password. Please try again.';
                        $step = 'reset';
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | START OVER
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'start_over') {

            clearResetSession();
            $step = 'request';
            $message = '';

        } else {

            $error = 'Invalid recovery request.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Successful reset message
|--------------------------------------------------------------------------
*/

if (isset($_GET['password_reset']) && $_GET['password_reset'] === 'success') {
    $step = 'success';
}

/*
|--------------------------------------------------------------------------
| Page title
|--------------------------------------------------------------------------
*/

$pageTitle = 'Forgotten User ID or Password';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?> | Bestlink HRMS</title>

    <link rel="stylesheet" href="styles.css?v=announcements-top-left-20261027-144524">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: #f3f7fb;
            /* Use the shared HRMS typography */
            color: #263238;
        }

        .forgot-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px 18px;
        }

        .forgot-card {
            width: 100%;
            max-width: 440px;
            background: #ffffff;
            border: 1px solid #dfe7ef;
            border-radius: 12px;
            box-shadow: 0 8px 28px rgba(0, 54, 105, 0.08);
            overflow: hidden;
        }

        .forgot-header {
    margin-top: 20px !important;
            background: #ffffff !important;
            color: #000000 !important;
            border-radius: 0 !important;
        }

        .forgot-logo {
            width: 70px !important;
            height: 70px !important;
            object-fit: contain;
            border-radius: 0 !important;
            background: transparent !important;
            background-color: transparent !important;
        }

        .forgot-header h1 {
            margin: 0;
            color: #ffffff;
            font-size: 21px;
            font-weight: 600;
        }

        .forgot-header p {
            margin: 7px 0 0;
            color: #dcecff;
            font-size: 13px;
        }

        .forgot-body {
            padding: 28px;
        }

        .forgot-body h2 {
            margin: 0 0 8px;
            font-size: 19px;
            color: #183b56;
        }

        .forgot-description {
            margin: 0 0 22px;
            color: #687985;
            font-size: 13px;
            line-height: 1.6;
        }

        .field {
            margin-bottom: 17px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: #344955;
            font-size: 13px;
            font-weight: 600;
        }

        .field input {
            width: 100%;
            height: 44px;
            padding: 0 13px;
            border: 1px solid #ccd8e3;
            border-radius: 7px;
            outline: none;
            font-size: 14px;
            color: #263238;
            background: #ffffff;
        }

        .field input:focus {
            border-color: #2d78b7;
            box-shadow: 0 0 0 3px rgba(45, 120, 183, 0.10);
        }

        .otp-input {
            text-align: center;
            font-size: 22px !important;
            font-weight: 600;
            letter-spacing: 6px;
        }

        .password-note {
            margin-top: -9px;
            margin-bottom: 16px;
            font-size: 12px;
            color: #788791;
        }

        .message,
        .error {
            margin-bottom: 18px;
            padding: 11px 13px;
            border-radius: 7px;
            font-size: 13px;
            line-height: 1.5;
        }

        .message {
            background: #eef7ff;
            border: 1px solid #cfe4f7;
            color: #145a8d;
        }

        .error {
            background: #fff4f4;
            border: 1px solid #efcccc;
            color: #a13b3b;
        }

        .forgot-button {
            width: 100%;
            height: 44px;
            border: 0;
            border-radius: 7px;
            background: #0b4f8a;
            color: #ffffff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .forgot-button:hover {
            background: #083e6d;
        }

        .secondary-button {
            display: block;
            width: 100%;
            margin-top: 10px;
            height: 42px;
            border: 1px solid #c9d7e4;
            border-radius: 7px;
            background: #ffffff;
            color: #31556e;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
        }

        .secondary-button:hover {
            background: #f5f8fb;
        }

        .back-login {
            display: block;
            margin-top: 20px;
            text-align: center;
            color: #1769a8;
            text-decoration: none;
            font-size: 13px;
        }

        .back-login:hover {
            text-decoration: underline;
        }

        .success-icon {
            width: 54px;
            height: 54px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: #eaf5ff;
            color: #0b4f8a;
            font-size: 26px;
            font-weight: bold;
        }

        .success-content {
            text-align: center;
        }

        @media (max-width: 480px) {
            .forgot-page {
                padding: 18px 12px;
            }

            .forgot-body {
                padding: 23px 20px;
            }

            .forgot-header {
                padding: 22px 20px;
            }
        }
    
        .forgot-contents #loginBtn {
            background: linear-gradient(to right, rgba(37, 0, 150, 1), rgba(15, 0, 59, 1)) !important;
            color: #ffffff !important;
            border: none !important;
        }

        .forgot-contents #loginBtn:hover {
            background: linear-gradient(to right, rgba(45, 0, 180, 1), rgba(20, 0, 75, 1)) !important;
        }

        
        .forgot-header {
            width: 100%;
            text-align: center !important;
            background: #ffffff !important;
            color: #000000 !important;
            border-radius: 0 !important;
        }

        .forgot-header .forgot-logo {
            display: block;
            margin: 0 auto;
            width: 70px !important;
            height: 70px !important;
            object-fit: contain;
            border-radius: 0 !important;
            background: transparent !important;
            background-color: transparent !important;
        }

        .forgot-header h1,
        .forgot-header p {
            text-align: center !important;
            color: #000000 !important;
        }

        
        .forgot-button {
            width: 100% !important;
            box-sizing: border-box !important;
            background: linear-gradient(to right, rgba(37, 0, 150, 1), rgba(15, 0, 59, 1)) !important;
            color: #ffffff !important;
            border: none !important;
            border-radius: 0 !important;
            cursor: pointer;
        }

        .forgot-button:hover {
            background: linear-gradient(to right, rgba(45, 0, 180, 1), rgba(20, 0, 75, 1)) !important;
        }

        
        .forgot-card {
            width: 360px !important;
            max-width: calc(100% - 30px) !important;
            box-sizing: border-box !important;
        }

        .forgot-body {
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .forgot-body form {
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .forgot-body .field {
            width: 100% !important;
            box-sizing: border-box !important;
        }

        .forgot-body .field input#employee_id {
            width: 100% !important;
            box-sizing: border-box !important;
            display: block !important;
        }

        .forgot-button {
            width: 100% !important;
            box-sizing: border-box !important;
            display: block !important;
            background: linear-gradient(to right, rgba(37, 0, 150, 1), rgba(15, 0, 59, 1)) !important;
            color: #ffffff !important;
            border: none !important;
        }

        
.forgot-body h2 {
    font-size: 18px !important;
}


.forgot-body h2 {
    font-size: 15px !important;
}


.forgot-header .forgot-logo {
    margin-bottom: 10px !important;
}


.forgot-description {
    font-size: 10px !important;
}


.request-header-content {
    width: 100%;
    text-align: center;
    box-sizing: border-box;
}

.request-header-content h2 {
    font-size: 15px !important;
    margin-top: 15px;
    margin-bottom: 8px;
}

.request-header-content .forgot-description {
    font-size: 10px !important;
    margin-bottom: 0;
}


/* Recovery form spacing */
.forgot-body .field {
    margin-top: 30px !important;
}

.forgot-body .forgot-button {
    margin-top: 15px !important;
}

.forgot-body .back-login {
    display: inline-block;
    margin-top: 15px !important;
}


.forgot-body .back-login {
    display: block !important;
    width: 100%;
    text-align: center !important;
    margin-left: auto !important;
    margin-right: auto !important;
}


/* Real-time password strength */
.password-strength { margin: -10px 0 20px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
.password-strength[hidden] { display: none !important; }
.password-strength-heading { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 9px; color: #475569; font-size: 11px; }
.password-strength-heading strong { font-size: 11px; }
.password-strength-track { height: 5px; overflow: hidden; background: #e2e8f0; border-radius: 20px; }
.password-strength-bar { width: 0; height: 100%; background: #dc2626; border-radius: inherit; transition: width .2s ease, background .2s ease; }
.password-strength-bar.is-fair { background: #ea580c; }
.password-strength-bar.is-good { background: #ca8a04; }
.password-strength-bar.is-strong { background: #16a34a; }
.password-rules { display: grid; gap: 7px; padding: 0; margin: 12px 0 0; list-style: none; }
.password-rules li { color: #64748b; font-size: 11px; line-height: 1.5; }
.password-rules li.is-valid { color: #15803d; }
.password-match-message { margin: 6px 0 0; font-size: 11px; }
.password-match-message.is-valid { color: #15803d; }
.password-match-message.is-invalid { color: #dc2626; }
#resetPasswordButton:disabled { opacity: .55; cursor: not-allowed; }
@media (prefers-reduced-motion: reduce) { .password-strength-bar { transition: none; } }


/* Compact password reset page */
.forgot-page {
    padding-top: 12px !important;
    padding-bottom: 12px !important;
}

.forgot-card {
    margin-top: 0 !important;
    margin-bottom: 0 !important;
}

.forgot-header {
    padding-top: 14px !important;
    padding-bottom: 10px !important;
}

.forgot-logo {
    width: 54px !important;
    height: 54px !important;
    margin-bottom: 6px !important;
}

.forgot-header h1 {
    margin-top: 4px !important;
    margin-bottom: 2px !important;
}

.forgot-header p {
    margin-bottom: 0 !important;
}

.forgot-body {
    padding-top: 16px !important;
    padding-bottom: 18px !important;
}

.forgot-body h2 {
    margin-top: 0 !important;
    margin-bottom: 8px !important;
}

.forgot-description {
    margin-bottom: 14px !important;
}

.field {
    margin-bottom: 10px !important;
}

.password-note {
    margin-top: 4px !important;
    margin-bottom: 8px !important;
}

.password-strength {
    margin-top: 6px !important;
    margin-bottom: 12px !important;
    padding: 10px !important;
}

.password-rules {
    margin-top: 6px !important;
    margin-bottom: 0 !important;
}

.password-rules li {
    margin-bottom: 2px !important;
}

.password-match-message {
    margin-top: 4px !important;
    margin-bottom: 0 !important;
}

.forgot-button {
    margin-top: 8px !important;
}


/* Center the complete recovery content inside the right panel */
.forgot-page > .forgot-card {
    display: flex !important;
    flex-direction: column;
    align-items: center;
    justify-content: center !important;
    min-height: 100vh;
    padding: 40px 36px !important;
}

.forgot-card > .forgot-header,
.forgot-card > .forgot-body {
    width: 100%;
    max-width: 420px;
    margin-left: auto !important;
    margin-right: auto !important;
}

.forgot-card > .forgot-header {
    padding-bottom: 22px !important;
}

.forgot-card > .forgot-body {
    padding: 0 !important;
}

@media (max-width: 760px) {
    .forgot-page > .forgot-card {
        min-height: 100vh;
        padding: 35px 24px !important;
    }
}


/* Center the recovery card within the right grid panel */
.forgot-page {
    display: grid !important;
    grid-template-columns: minmax(0, 1.2fr) minmax(400px, 0.8fr) !important;
    align-items: stretch !important;
    justify-content: stretch !important;
    gap: 0 !important;
    min-height: 100vh;
    padding: 0 !important;
}

.forgot-page > .brand-panel {
    grid-column: 1;
    grid-row: 1;
    min-width: 0;
    min-height: 100vh;
}

.forgot-page > .forgot-card {
    grid-column: 2;
    grid-row: 1;
    display: flex !important;
    flex-direction: column;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    max-width: none !important;
    min-width: 0;
    min-height: 100vh;
    margin: 0 !important;
    padding: 40px 36px !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: #ffffff !important;
}

.forgot-card > .forgot-header,
.forgot-card > .forgot-body {
    width: 100% !important;
    max-width: 420px !important;
    margin-left: auto !important;
    margin-right: auto !important;
}

.forgot-card > .forgot-header {
    padding: 0 0 22px !important;
}

.forgot-card > .forgot-body {
    padding: 0 !important;
}

@media (max-width: 1050px) and (min-width: 761px) {
    .forgot-page {
        grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr) !important;
    }

    .forgot-page > .forgot-card {
        padding: 36px 26px !important;
    }
}

@media (max-width: 760px) {
    .forgot-page {
        display: flex !important;
        flex-direction: column;
        min-height: 100vh;
    }

    .forgot-page > .brand-panel {
        display: block !important;
        width: 100% !important;
        min-height: 180px !important;
    }

    .forgot-page > .forgot-card {
        display: flex !important;
        width: 100% !important;
        min-height: 100vh;
        padding: 35px 24px !important;
    }
}

/* Match the main HRMS login layout */
        .forgot-page {
            display: grid !important;
            grid-template-columns: minmax(0, 3fr) minmax(400px, 2fr) !important;
            align-items: stretch;
            justify-content: stretch;
            gap: 0;
            min-height: 100vh;
            padding: 0;
            background: #ffffff;
        }

        .forgot-page > .brand-panel {
            width: 100%;
            min-height: 100vh;
        }

        .forgot-page > .forgot-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            width: 100%;
            max-width: none;
            min-width: 0;
            min-height: 100vh;
            margin: 0;
            padding: 48px 36px;
            overflow: visible;
            border: 0;
            border-radius: 0;
            background: #ffffff;
            box-shadow: none;
        }

        .forgot-card > .forgot-header,
        .forgot-card > .forgot-body {
            width: 100%;
            max-width: 420px;
        }

        .forgot-card > .forgot-header {
            margin: 0 !important;
            padding: 0 0 22px !important;
            text-align: left !important;
            background: #ffffff !important;
            color: #202b3c !important;
        }

        .forgot-card .forgot-logo {
            display: block;
            width: 65px !important;
            height: 65px !important;
            margin: 0 0 22px !important;
            object-fit: contain;
        }

        .forgot-card .forgot-header h1 {
            margin: 0 0 6px !important;
            color: #202b3c !important;
            font-size: 26px;
            font-weight: 650;
            line-height: 1.25;
            text-align: left !important;
        }

        .forgot-card .forgot-header p {
            margin: 0 !important;
            color: #718096 !important;
            font-size: 14px;
            text-align: left !important;
        }

        .forgot-card > .forgot-body {
            padding: 0 !important;
        }

        .forgot-card .request-header-content {
            text-align: left;
        }

        .forgot-card .request-header-content h2,
        .forgot-card .forgot-body > h2 {
            margin: 0 0 10px !important;
            color: #202b3c;
            font-size: 21px !important;
            font-weight: 600;
            line-height: 1.35;
        }

        .forgot-card .forgot-description,
        .forgot-card .request-header-content .forgot-description {
            margin: 0 0 24px !important;
            color: #718096;
            font-size: 14px !important;
            line-height: 1.65;
        }

        .forgot-card .forgot-body .field {
            margin: 0 0 18px !important;
        }

        .forgot-card .forgot-body .field label {
            color: #344054;
            font-size: 13px;
            font-weight: 600;
        }

        .forgot-card .forgot-body .field input {
            height: 46px;
            border: 1px solid #dce3eb;
            border-radius: 5px;
            background: #ffffff;
            font-size: 14px;
        }

        .forgot-card .forgot-body .field input:focus {
            border-color: #285b91;
            box-shadow: 0 0 0 3px rgba(40, 91, 145, 0.10);
        }

        .forgot-card .forgot-button {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100% !important;
            min-height: 46px;
            margin: 8px 0 0 !important;
            padding: 12px 16px;
            border: 0 !important;
            border-radius: 5px !important;
            background: #285b91 !important;
            color: #ffffff !important;
            font-size: 14px;
            font-weight: 600;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }

        .forgot-card .forgot-button:hover {
            background: #204a78 !important;
        }

        .forgot-card .back-login {
            display: block !important;
            margin: 22px 0 0 !important;
            color: #285b91;
            font-size: 13px;
            text-align: center !important;
            text-decoration: none;
        }

        .forgot-card .back-login:hover {
            text-decoration: underline;
        }

        .forgot-card .message,
        .forgot-card .error {
            margin: 0 0 16px;
        }

        .forgot-card .secondary-button {
            width: 100%;
            min-height: 42px;
            margin-top: 12px;
            border: 1px solid #dce3eb;
            border-radius: 5px;
            background: #ffffff;
            color: #344054;
            cursor: pointer;
        }

        @media (max-width: 1050px) {
            .forgot-page {
                grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            }

            .forgot-page > .forgot-card {
                padding: 38px 28px;
            }
        }

        @media (max-width: 760px) {
            .forgot-page {
                display: flex;
                flex-direction: column;
                min-height: 100vh;
                padding: 0;
            }

            .forgot-page > .brand-panel {
                display: none;
            }

            .forgot-page > .forgot-card {
                flex: 1;
                justify-content: center;
                min-height: 100vh;
                padding: 35px 24px;
            }
        }


/* =====================================================
   ALIGN RECOVERY PAGE WITH THE MAIN LOGIN DESIGN
   ===================================================== */

body {
    margin: 0 !important;
    font-family: "Segoe UI", Arial, sans-serif !important;
    -webkit-font-smoothing: antialiased;
}

.forgot-page {
    display: grid !important;
    grid-template-columns: minmax(0, 1.2fr) minmax(400px, 0.8fr) !important;
    align-items: stretch !important;
    gap: 0 !important;
    width: 100%;
    min-height: 100vh;
    margin: 0 !important;
    padding: 0 !important;
    background: #ffffff !important;
}

/* Reuse the login page's original left branding panel */
.forgot-page > .brand-panel {
    position: relative;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    grid-column: 1;
    grid-row: 1;
    width: 100%;
    min-width: 0;
    min-height: 100vh;
    box-sizing: border-box;
}

/* Center the recovery content within the right panel */
.forgot-page > .forgot-card {
    grid-column: 2;
    grid-row: 1;
    display: flex !important;
    flex-direction: column;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    max-width: none !important;
    min-width: 0;
    min-height: 100vh;
    box-sizing: border-box;
    margin: 0 !important;
    padding: 40px 36px !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: #ffffff !important;
    box-shadow: none !important;
}

/* Keep the heading and form aligned to the same left edge */
.forgot-card > .forgot-header,
.forgot-card > .forgot-body {
    width: 100% !important;
    max-width: 420px !important;
    box-sizing: border-box;
    margin-right: auto !important;
    margin-left: auto !important;
}

.forgot-card > .forgot-header {
    padding: 0 0 24px !important;
    text-align: left !important;
    background: #ffffff !important;
}

.forgot-card .forgot-logo {
    display: block;
    width: 65px !important;
    height: 65px !important;
    margin: 0 0 22px !important;
    object-fit: contain;
}

.forgot-card .forgot-header h1 {
    margin: 0 0 6px !important;
    color: #202b3c !important;
    font-family: inherit !important;
    font-size: 27px !important;
    font-weight: 600 !important;
    line-height: 1.3 !important;
    letter-spacing: -0.7px;
    text-align: left !important;
}

.forgot-card .forgot-header p {
    margin: 12px 0 0 !important;
    color: #718096 !important;
    font-family: inherit !important;
    font-size: 13px !important;
    line-height: 1.8 !important;
    text-align: left !important;
}

.forgot-card > .forgot-body {
    padding: 0 !important;
}

.forgot-card .request-header-content,
.forgot-card .forgot-body > h2 {
    text-align: left !important;
}

.forgot-card .request-header-content h2,
.forgot-card .forgot-body > h2 {
    margin: 0 0 10px !important;
    color: #202b3c !important;
    font-family: inherit !important;
    font-size: 21px !important;
    font-weight: 600 !important;
    line-height: 1.35 !important;
    letter-spacing: normal !important;
}

.forgot-card .forgot-description {
    margin: 0 0 24px !important;
    color: #718096 !important;
    font-family: inherit !important;
    font-size: 13px !important;
    line-height: 1.8 !important;
}

.forgot-card .field {
    margin-bottom: 18px !important;
}

.forgot-card .field label {
    display: block;
    margin-bottom: 8px;
    color: #344054 !important;
    font-family: inherit !important;
    font-size: 13px !important;
    font-weight: 600 !important;
}

.forgot-card .field input {
    width: 100%;
    min-height: 46px;
    box-sizing: border-box;
    border: 1px solid #dce3eb !important;
    border-radius: 5px !important;
    background: #ffffff !important;
    color: #202b3c !important;
    font-family: inherit !important;
    font-size: 14px !important;
}

.forgot-card .field input:focus {
    border-color: #285b91 !important;
    box-shadow: 0 0 0 3px rgba(40, 91, 145, 0.10) !important;
    outline: none;
}

.forgot-card .forgot-button {
    display: flex !important;
    align-items: center;
    justify-content: center;
    width: 100% !important;
    min-height: 46px;
    box-sizing: border-box;
    margin: 8px 0 0 !important;
    padding: 12px 16px !important;
    border: 0 !important;
    border-radius: 5px !important;
    background: #285b91 !important;
    color: #ffffff !important;
    font-family: inherit !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    text-align: center;
    cursor: pointer;
}

.forgot-card .forgot-button:hover {
    background: #204a78 !important;
}

.forgot-card .back-login {
    display: block !important;
    margin: 22px 0 0 !important;
    color: #285b91 !important;
    font-family: inherit !important;
    font-size: 13px !important;
    text-align: center !important;
    text-decoration: none;
}

.forgot-card .back-login:hover {
    text-decoration: underline;
}

@media (max-width: 1050px) and (min-width: 761px) {
    .forgot-page {
        grid-template-columns: minmax(0, 0.9fr) minmax(400px, 1fr) !important;
    }

    .forgot-page > .brand-panel {
        padding-right: 34px;
        padding-left: 34px;
    }

    .forgot-page > .forgot-card {
        padding: 36px 28px !important;
    }
}

@media (max-width: 760px) {
    .forgot-page {
        display: flex !important;
        flex-direction: column;
        min-height: 100vh;
        background: #f4f7fb !important;
    }

    .forgot-page > .brand-panel {
        display: none !important;
    }

    .forgot-page > .forgot-card {
        flex: 1;
        justify-content: center !important;
        min-height: 100vh;
        padding: 35px 24px !important;
    }

    .forgot-card > .forgot-header,
    .forgot-card > .forgot-body {
        max-width: 420px !important;
    }
}



/* Center all recovery content within the right-side panel */
.forgot-card > .forgot-header,
.forgot-card > .forgot-body {
    width: 100% !important;
    max-width: 420px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    box-sizing: border-box;
}

.forgot-card > .forgot-header {
    text-align: center !important;
}

.forgot-card > .forgot-header .forgot-logo {
    display: block !important;
    margin: 0 auto 20px !important;
}

.forgot-card > .forgot-header h1,
.forgot-card > .forgot-header p {
    text-align: center !important;
}

.forgot-card > .forgot-body {
    text-align: center !important;
}

.forgot-card .request-header-content,
.forgot-card .forgot-body > h2 {
    text-align: center !important;
}

.forgot-card .forgot-description {
    text-align: center !important;
    margin-left: auto !important;
    margin-right: auto !important;
}

.forgot-card .field {
    text-align: left !important;
}

.forgot-card .forgot-button {
    width: 100% !important;
}

.forgot-card .back-login {
    display: block !important;
    width: 100% !important;
    text-align: center !important;
}
\n
/* Match login page heading typography. */
.forgot-card > .forgot-header h1 {
    margin: 0 !important;
    color: var(--navy-deep) !important;
    font-family: "Segoe UI", Arial, sans-serif !important;
    font-size: 30px !important;
    font-weight: 750 !important;
    line-height: 1.3 !important;
    letter-spacing: -0.7px !important;
}

@media (max-width: 1050px) {
    .forgot-card > .forgot-header h1 {
        font-size: 27px !important;
    }
}

@media (max-width: 760px) {
    .forgot-card > .forgot-header h1 {
        font-size: 25px !important;
    }
}


/* Enforce the recovery page heading weight. */
.forgot-card .forgot-header h1 {
    font-family: "Segoe UI", Arial, sans-serif !important;
    font-weight: 700 !important;
}


/* Center and soften recovery description */
.request-header-content .forgot-description {
    width: 100%;
    max-width: 330px;
    margin: 12px auto 0 !important;
    color: var(--muted) !important;
    font-size: 12px !important;
    font-weight: 400 !important;
    line-height: 1.8 !important;
    text-align: center !important;
    box-sizing: border-box;
}

/* Center recovery description in panel */
.forgot-body .request-header-content {
    width: 100%;
    text-align: center;
    box-sizing: border-box;
}

.forgot-body .request-header-content .forgot-description {
    display: block;
    width: 100%;
    max-width: 330px;
    margin: 12px auto 0 !important;
    text-align: center !important;
    color: var(--muted) !important;
    font-size: 12px !important;
    font-weight: 400 !important;
    line-height: 1.8 !important;
    box-sizing: border-box;
}

/* Left-align recovery heading and description */
.forgot-body .request-header-content {
    width: 100%;
    text-align: left !important;
    box-sizing: border-box;
}

.forgot-body .request-header-content h2,
.forgot-body .request-header-content .forgot-description {
    text-align: left !important;
}

.forgot-body .request-header-content .forgot-description {
    margin-left: 0 !important;
    margin-right: 0 !important;
}

/* Full-width recovery description */
.forgot-body .request-header-content {
    width: 100%;
    box-sizing: border-box;
}

.forgot-body .request-header-content .forgot-description {
    display: block;
    width: 100% !important;
    max-width: none !important;
    margin: 12px 0 20px !important;
    padding: 0;
    color: var(--muted) !important;
    font-size: 12px !important;
    font-weight: 400 !important;
    line-height: 1.8 !important;
    box-sizing: border-box;
}

/* Recovery heading spacing and size */
.forgot-body .request-header-content h2 {
    margin-top: 12px !important;
    font-size: 14px !important;
    line-height: 1.4 !important;
}

/* Slightly raise recovery page logo and title */
.forgot-card > .forgot-header {
    transform: translateY(-6px);
}



/* =========================================================
   BCP AUTH DESIGN REFINEMENT - 2026
   Scoped to login and account-recovery screens.
   ========================================================= */

html {
    min-height: 100%;
    -webkit-text-size-adjust: 100%;
}

body {
    min-width: 320px;
    min-height: 100vh;
    margin: 0;
    color: #202b3c;
    background: #f3f6fa;
    font-family: "Segoe UI", Arial, sans-serif;
    -webkit-font-smoothing: antialiased;
}

.forgot-page {
    display: grid !important;
    grid-template-columns: 60% 40% !important;
    align-items: stretch;
    width: 100%;
    min-height: 100vh;
    margin: 0;
    padding: 0;
    background: #f7f9fc;
}

.forgot-page > .brand-panel {
    display: flex;
    position: relative;
    flex-direction: column;
    justify-content: space-between;
    min-width: 0;
    min-height: 100vh;
    margin: 0;
    padding: 48px clamp(32px, 5.5vw, 88px) 32px;
    overflow: hidden;
    color: #fff;
    background-color: #17365c;
    background-image:
        linear-gradient(180deg, rgba(12, 35, 61, .68), rgba(12, 35, 61, .82));
    background-position: center;
    background-repeat: no-repeat;
    background-size: cover;
    border: 0;
    border-radius: 0;
    box-shadow: none;
}

.forgot-page > .brand-panel .brand-identity {
    margin: 0;
    text-decoration: none;
}

.forgot-page > .brand-panel .brand-message {
    width: 100%;
    max-width: 510px;
    margin: 60px 0 75px;
}

.forgot-page > .brand-panel .brand-message h2 {
    color: #fff;
    font-size: clamp(38px, 4.1vw, 62px);
    font-weight: 600;
    line-height: 1.13;
    letter-spacing: -1.2px;
}

.forgot-page > .brand-panel .brand-eyebrow {
    color: #b9cce1;
}

.forgot-page > .brand-panel .brand-description {
    color: #fff;
}

.forgot-page > .brand-panel .brand-supporting-text,
.forgot-page > .brand-panel .brand-footer {
    color: #c3d0df;
}

.forgot-page > .forgot-card {
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-self: stretch;
    width: 100%;
    min-width: 0;
    min-height: 100vh;
    margin: 0;
    padding: 52px clamp(28px, 4vw, 64px);
    border: 0;
    border-radius: 0;
    background: #f7f9fc;
    box-shadow: none;
}

.forgot-card > .forgot-header,
.forgot-card > .forgot-body {
    width: 100%;
    max-width: 400px;
    margin-right: auto;
    margin-left: auto;
    box-sizing: border-box;
}

.forgot-card > .forgot-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 0;
    margin-bottom: 25px;
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
    transform: none;
}

.forgot-card .forgot-logo {
    display: block;
    width: 66px;
    height: 66px;
    margin: 0 auto 18px;
    object-fit: contain;
}

.forgot-card > .forgot-header h1 {
    margin: 0;
    color: #102944;
    font-family: "Segoe UI", Arial, sans-serif;
    font-size: 27px;
    font-weight: 700;
    line-height: 1.3;
    letter-spacing: -.45px;
    text-align: center;
}

.forgot-card > .forgot-body {
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
}

.forgot-card .request-header-content {
    margin: 0 0 27px;
}

.forgot-card .request-header-content h2,
.forgot-card .forgot-body > h2 {
    margin: 0 0 10px;
    color: #102944;
    font-size: 24px;
    font-weight: 650;
    line-height: 1.35;
    letter-spacing: -.4px;
    text-align: left;
}

.forgot-card .forgot-description {
    margin: 0;
    color: #66758a;
    font-size: 13px;
    font-weight: 400;
    line-height: 1.75;
    text-align: left;
}

.forgot-card .field {
    margin: 0 0 21px;
}

.forgot-card .field label {
    display: block;
    margin: 0 0 8px;
    color: #344054;
    font-size: 13px;
    font-weight: 600;
}

.forgot-card .field input,
.forgot-card .field input#employee_id,
.forgot-card input[type="password"],
.forgot-card input[type="text"] {
    display: block;
    width: 100%;
    min-width: 0;
    min-height: 49px;
    padding: 12px 14px;
    border: 1px solid #d5dee8;
    border-radius: 4px;
    outline: none;
    background: #fff;
    color: #202b3c;
    font-family: inherit;
    font-size: 14px;
    line-height: 1.5;
    box-shadow: none;
    transition: border-color .15s ease, box-shadow .15s ease;
}

.forgot-card .field input::placeholder {
    color: #98a2b3;
    opacity: 1;
}

.forgot-card .field input:hover {
    border-color: #aab9ca;
}

.forgot-card .field input:focus,
.forgot-card input[type="password"]:focus,
.forgot-card input[type="text"]:focus {
    border-color: #285b91;
    outline: none;
    box-shadow: 0 0 0 3px rgba(40, 91, 145, .12);
}

.forgot-card .forgot-button,
.forgot-card button[type="submit"] {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    min-height: 49px;
    margin-top: 5px;
    padding: 12px 18px;
    border: 1px solid #17365c;
    border-radius: 4px;
    background: #17365c;
    color: #fff;
    font-family: inherit;
    font-size: 14px;
    font-weight: 650;
    line-height: 1.5;
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease;
}

.forgot-card .forgot-button:hover,
.forgot-card button[type="submit"]:hover {
    border-color: #204a78;
    background: #204a78;
}

.forgot-card .forgot-button:focus-visible,
.forgot-card button[type="submit"]:focus-visible {
    outline: 3px solid rgba(40, 91, 145, .24);
    outline-offset: 3px;
}

.forgot-card .forgot-button:disabled,
.forgot-card button[type="submit"]:disabled {
    cursor: not-allowed;
    opacity: .65;
}

.forgot-card .back-login {
    display: block;
    width: fit-content;
    margin: 23px auto 0;
    color: #285b91;
    font-size: 13px;
    font-weight: 600;
    line-height: 1.6;
    text-align: center;
    text-decoration: none;
}

.forgot-card .back-login:hover {
    color: #204a78;
    text-decoration: underline;
}

.forgot-card .back-login:focus-visible {
    outline: 2px solid #285b91;
    outline-offset: 4px;
}

.forgot-card .message,
.forgot-card .error {
    font-size: 13px;
    line-height: 1.65;
    overflow-wrap: anywhere;
}

.forgot-card .password-strength {
    margin: 12px 0 20px;
    padding: 14px;
    border: 1px solid #e0e6ee;
    border-radius: 4px;
    background: #fff;
    color: #344054;
    font-size: 12px;
    line-height: 1.7;
}

.forgot-card .password-strength-bar {
    height: 4px;
    border-radius: 2px;
    transition: width .15s ease;
}

.forgot-card #passwordMatchMessage {
    margin-top: 8px;
    font-size: 12px;
    line-height: 1.6;
}

.forgot-card #passwordMatchMessage.is-valid {
    color: #23734b;
}

.forgot-card #passwordMatchMessage.is-invalid {
    color: #b42318;
}

@media (max-width: 1050px) and (min-width: 761px) {
    .forgot-page {
        grid-template-columns: 52% 48% !important;
    }

    .forgot-page > .brand-panel {
        padding-right: 34px;
        padding-left: 34px;
    }

    .forgot-page > .forgot-card {
        padding-right: 30px;
        padding-left: 30px;
    }
}

@media (max-width: 760px) {
    .forgot-page {
        display: flex !important;
        flex-direction: column;
        min-height: 100vh;
        padding: 28px 18px 20px;
        background: #f3f6fa;
    }

    .forgot-page > .brand-panel {
        display: none !important;
    }

    .forgot-page > .forgot-card {
        justify-content: center;
        align-self: center;
        width: 100%;
        max-width: 460px;
        min-height: 0;
        margin: auto;
        padding: 32px 27px;
        border: 1px solid #e3e9f1;
        border-radius: 5px;
        background: #fff;
        box-shadow: 0 8px 28px rgba(16, 41, 68, .055);
    }

    .forgot-card > .forgot-header {
        margin-bottom: 25px;
    }

    .forgot-card .forgot-logo {
        width: 60px;
        height: 60px;
    }

    .forgot-card > .forgot-header h1 {
        font-size: 25px;
    }

    .forgot-card .request-header-content h2,
    .forgot-card .forgot-body > h2 {
        font-size: 22px;
    }
}

@media (max-width: 380px) {
    .forgot-page {
        padding-right: 12px;
        padding-left: 12px;
    }

    .forgot-page > .forgot-card {
        padding: 27px 20px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .forgot-card *,
    .forgot-card *::before,
    .forgot-card *::after {
        transition: none !important;
        animation: none !important;
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

    <div class="forgot-card">

        <div class="forgot-header">
            <img
                src="assets/bcp-logo.png"
                alt="Bestlink College of the Philippines"
                class="forgot-logo"
            >

            
            
        <h1>BESTLINK HRMS</h1>
        </div>

        <div class="forgot-body">

            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
                
            <?php endif; ?>

            <?php if ($step === 'success'): ?>

                <div class="success-content">
                    <div class="success-icon">✓</div>

                    <h2>Password Updated</h2>

                    <p class="forgot-description">
                        Your password has been successfully changed.
                        You can now sign in using your new password.
                    </p>

                    <a href="index.php" class="forgot-button"
                       style="display:flex;align-items:center;justify-content:center;text-decoration:none;">
                        Back to Login
                    </a>
                </div>

            <?php elseif ($step === 'request'): ?>

                <div class="request-header-content">
                    <h2>Forgot Password?</h2>

                    <p class="forgot-description">
                        Enter your Employee ID. If your account is eligible for
                        recovery, a verification code will be sent to your
                        registered email address.
                    </p>
                </div>

                <?php if ($message !== ''): ?>
                    <div class="message"><?= h($message) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="request_otp">

                    <div class="field">
                        <label for="employee_id">Employee ID</label>
                        <input
                            type="text"
                            id="employee_id"
                            name="employee_id"
                            placeholder="Enter your Employee ID"
                            required
                            autofocus
                        >
                    </div>

                    <button type="submit" class="forgot-button">
                        Send Verification Code
                    </button>
                    <script id="OTP_REQUEST_DUPLICATE_GUARD">
                    (function () {
                        const form = document.currentScript.closest('form');
                        if (!form) return;

                        const button = form.querySelector('button[type="submit"]');
                        if (!button) return;

                        let locked = false;

                        form.addEventListener('submit', function (event) {
                            if (locked) {
                                event.preventDefault();
                                return;
                            }

                            locked = true;
                            button.disabled = true;
                            button.textContent = 'Please wait (60s)';
                            let remaining = 60;

                            const timer = window.setInterval(function () {
                                remaining--;

                                if (remaining <= 0) {
                                    window.clearInterval(timer);
                                    locked = false;
                                    button.disabled = false;
                                    button.textContent = 'Send Verification Code';
                                } else {
                                    button.textContent =
                                        'Please wait (' + remaining + 's)';
                                }
                            }, 1000);
                        });
                    })();
                    </script>
                </form>

                <a href="index.php" class="back-login">
                    Back to Login
                </a>

            <?php elseif ($step === 'otp'): ?>

                <h2>Verify Your Email</h2>

                <p class="forgot-description">
                    Enter the 6-digit verification code sent to your
                    registered email address. The code is valid for 60 seconds.
                </p>

                <?php if ($message !== ''): ?>
                    <div class="message"><?= h($message) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="verify_otp">

                    <div class="field">
                        <label for="otp">Verification Code</label>
                        <input
                            type="text"
                            id="otp"
                            name="otp"
                            class="otp-input"
                            inputmode="numeric"
                            pattern="[0-9]{6}"
                            maxlength="6"
                            placeholder="000000"
                            required
                            autofocus
                        >
                    </div>

                    <button type="submit" class="forgot-button">
                        Verify Code
                    </button>
                </form>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="start_over">

                    <button type="submit" class="secondary-button">
                        Start Over
                    </button>
                </form>

            <?php elseif ($step === 'reset'): ?>

                <h2>Create New Password</h2>

                <p class="forgot-description">
                    Your email has been verified. Create a new password
                    for your HRMS account.
                </p>

                <?php if ($message !== ''): ?>
                    <div class="message"><?= h($message) ?></div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                    <div class="error"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="reset_password">

                    <div class="field">
                        <label for="new_password">New Password</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            minlength="8"
                            placeholder="Enter new password"
                            required
                            autofocus
                        >
                    </div>

                    
<p class="password-note">
                        Password must contain at least 8 characters.
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
                            placeholder="Re-enter new password"
                            required
                        >
<p id="passwordMatchMessage" class="password-match-message" aria-live="polite"></p>
                    </div>

                    <button type="submit" class="forgot-button">
                        Reset Password
                    </button>
                </form>

            <?php endif; ?>

        </div>
    </div>
</div>


<script>
(function () {
    const password = document.getElementById('new_password');
    const confirm = document.getElementById('confirm_password');
    const box = document.getElementById('passwordStrength');
    const label = document.getElementById('passwordStrengthText');
    const bar = document.getElementById('passwordStrengthBar');
    const match = document.getElementById('passwordMatchMessage');
    const form = password && password.closest('form');
    const button = form && form.querySelector('button[type="submit"]');

    if (!password || !confirm || !box || !label || !bar || !match || !form || !button) return;

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


<style id="final-forgot-layout">
@media (min-width: 761px) {
    .forgot-page {
        display: grid !important;
        grid-template-columns: minmax(0, 7fr) minmax(0, 3fr) !important;
        width: 100% !important;
    }
    .forgot-page > .brand-panel,
    .forgot-page > .forgot-card {
        min-width: 0 !important;
        width: auto !important;
    }
}
</style>


<style id="forgot-brand-width-final">
@media (min-width: 761px) {
    .forgot-page > .brand-panel {
        box-sizing: border-box !important;
        padding-left: clamp(28px, 3.5vw, 64px) !important;
        padding-right: clamp(28px, 3.5vw, 64px) !important;
    }

    .forgot-page > .brand-panel .brand-identity {
        display: flex !important;
        width: 100% !important;
        max-width: none !important;
        box-sizing: border-box !important;
        align-items: center !important;
        gap: 18px !important;
    }

    .forgot-page > .brand-panel .brand-identity img {
        flex: 0 0 110px !important;
        width: 110px !important;
        height: 110px !important;
        object-fit: contain !important;
    }

    .forgot-page > .brand-panel .brand-school-name {
        flex: 1 1 auto !important;
        min-width: 0 !important;
        width: auto !important;
        font-size: clamp(15px, 1.2vw, 20px) !important;
        overflow-wrap: anywhere;
    }

    .forgot-page > .brand-panel .brand-message,
    .forgot-page > .brand-panel .brand-eyebrow,
    .forgot-page > .brand-panel .brand-description,
    .forgot-page > .brand-panel .brand-supporting-text {
        width: 100% !important;
        max-width: none !important;
        box-sizing: border-box !important;
    }

    .forgot-page > .brand-panel .brand-message h2 {
        width: 100% !important;
        max-width: none !important;
        font-size: clamp(48px, 5.4vw, 88px) !important;
        line-height: 1.04 !important;
        letter-spacing: -0.045em !important;
        overflow-wrap: normal;
    }

    .forgot-page > .brand-panel .brand-footer {
        display: flex !important;
        width: 100% !important;
        max-width: none !important;
        box-sizing: border-box !important;
        justify-content: space-between !important;
        align-items: flex-start !important;
        gap: 16px 24px !important;
    }

    .forgot-page > .brand-panel .brand-footer span {
        flex: 1 1 0 !important;
        min-width: 0 !important;
        max-width: none !important;
        overflow-wrap: anywhere;
    }

    .forgot-page > .brand-panel .brand-footer span:last-child {
        text-align: right;
    }
}
</style>


<style>
/* FINAL LOGIN-MATCHED BRAND PANEL HEIGHT */
@media (min-width: 761px) {
    body .forgot-page.login-container {
        min-height: 100vh !important;
        height: 100vh !important;
        align-items: stretch !important;
    }

    body .forgot-page.login-container > .brand-panel {
        height: 100% !important;
        min-height: 100% !important;
        align-self: stretch !important;
        position: relative !important;
        box-sizing: border-box !important;
    }
}
</style>

<style>
/* EMPLOYEE ID INPUT USER ICON */
body .field:has(#employee_id) {
    position: relative !important;
}

body .field:has(#employee_id)::after {
    content: "";
    position: absolute;
    left: 14px;
    top: 39px;
    width: 19px;
    height: 19px;
    pointer-events: none;
    z-index: 2;
    background: no-repeat center / contain url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M16 2v2'/%3E%3Cpath d='M7 21v-2a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2'/%3E%3Cpath d='M8 2v2'/%3E%3Ccircle cx='12' cy='10' r='3'/%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3C/svg%3E");
}

body input#employee_id {
    padding-left: 44px !important;
}
</style>
</body>
</html>




<style>
/* Final fix: preserve transparent BCP logo on forgot-password page */
.forgot-page > .brand-panel .brand-identity img {
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    padding: 0 !important;
    filter: none !important;
}
</style>

<style>
/* Forgot password: 70% branding panel, 30% form panel */
@media (min-width: 901px) {
    .forgot-page {
        grid-template-columns: minmax(0, 7fr) minmax(0, 3fr) !important;
    }
}
</style>

<style>

/* Shared login and password-recovery branding alignment */
body .brand-panel {
    position: relative !important;
    box-sizing: border-box !important;
}

body .brand-panel > .brand-identity {
    position: absolute !important;
    top: clamp(28px, 4.5vh, 48px) !important;
    left: clamp(28px, 3.5vw, 64px) !important;
    right: clamp(28px, 3.5vw, 64px) !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    text-decoration: none !important;
}

body .brand-panel > .brand-identity img {
    display: block !important;
    flex: 0 0 72px !important;
    width: 72px !important;
    height: 72px !important;
    max-width: 72px !important;
    margin: 0 !important;
    padding: 0 !important;
    object-fit: contain !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

body .brand-panel > .brand-identity .brand-school-name {
    display: block !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    width: auto !important;
    margin: 0 !important;
    font-size: clamp(14px, 1.15vw, 18px) !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    letter-spacing: 0.025em !important;
    overflow-wrap: normal !important;
}

body .brand-panel > .brand-identity .brand-school-name > span {
    display: block !important;
}

body .brand-panel > .brand-footer {
    position: absolute !important;
    right: clamp(28px, 3.5vw, 64px) !important;
    bottom: clamp(24px, 4vh, 40px) !important;
    left: clamp(28px, 3.5vw, 64px) !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-end !important;
    gap: 12px 24px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    font-size: 11px !important;
    line-height: 1.6 !important;
}

body .brand-panel > .brand-footer span {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: none !important;
    overflow-wrap: anywhere !important;
}

body .brand-panel > .brand-footer span:last-child {
    text-align: right !important;
}

@media (max-width: 760px) {
    body .brand-panel > .brand-identity {
        top: 24px !important;
        left: 22px !important;
        right: 22px !important;
        gap: 12px !important;
    }

    body .brand-panel > .brand-identity img {
        flex-basis: 56px !important;
        width: 56px !important;
        height: 56px !important;
        max-width: 56px !important;
    }

    body .brand-panel > .brand-footer {
        right: 22px !important;
        bottom: 20px !important;
        left: 22px !important;
        gap: 8px 14px !important;
    }
}

</style>

<style id="final-shared-brand-override">

/* Final shared branding dimensions and alignment */
@media (min-width: 761px) {
    body .login-container,
    body .forgot-page {
        grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) !important;
    }
}

body .brand-panel > .brand-identity {
    position: absolute !important;
    top: 40px !important;
    left: 48px !important;
    right: 48px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
}

body .brand-panel > .brand-identity img {
    display: block !important;
    flex: 0 0 72px !important;
    width: 72px !important;
    height: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    min-height: 72px !important;
    max-height: 72px !important;
    margin: 0 !important;
    padding: 0 !important;
    object-fit: contain !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}

body .brand-panel > .brand-identity .brand-school-name {
    display: block !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    font-size: 17px !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    letter-spacing: .025em !important;
}

body .brand-panel > .brand-identity .brand-school-name > span {
    display: block !important;
}

body .brand-panel > .brand-footer {
    position: absolute !important;
    right: 48px !important;
    bottom: 32px !important;
    left: 48px !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-end !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    font-size: 11px !important;
    line-height: 1.6 !important;
}

body .brand-panel > .brand-footer span {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: none !important;
}

body .brand-panel > .brand-footer span:last-child {
    text-align: right !important;
}

@media (max-width: 760px) {
    body .brand-panel > .brand-identity {
        top: 24px !important;
        left: 22px !important;
        right: 22px !important;
        gap: 12px !important;
    }

    body .brand-panel > .brand-identity img {
        flex-basis: 56px !important;
        width: 56px !important;
        height: 56px !important;
        min-width: 56px !important;
        max-width: 56px !important;
        min-height: 56px !important;
        max-height: 56px !important;
    }

    body .brand-panel > .brand-identity .brand-school-name {
        font-size: 14px !important;
    }

    body .brand-panel > .brand-footer {
        right: 22px !important;
        bottom: 20px !important;
        left: 22px !important;
    }
}

</style>


<style id="reuse-login-branding">
@media (min-width: 761px) {
    body .forgot-page.login-container {
        display: grid !important;
        grid-template-columns: minmax(0, 3fr) minmax(400px, 2fr) !important;
        width: 100% !important;
    }
}
body .forgot-page.login-container > .brand-panel {
    position: relative !important;
    box-sizing: border-box !important;
}
body .forgot-page.login-container > .brand-panel > .brand-identity {
    position: absolute !important;
    top: 40px !important;
    left: 48px !important;
    right: 48px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: flex-start !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
}
body .forgot-page.login-container > .brand-panel > .brand-identity img {
    display: block !important;
    flex: 0 0 72px !important;
    width: 72px !important;
    height: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    min-height: 72px !important;
    max-height: 72px !important;
    margin: 0 !important;
    padding: 0 !important;
    object-fit: contain !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}
body .forgot-page.login-container > .brand-panel > .brand-identity .brand-school-name {
    display: block !important;
    flex: 1 1 auto !important;
    min-width: 0 !important;
    margin: 0 !important;
    font-size: 17px !important;
    font-weight: 700 !important;
    line-height: 1.45 !important;
    letter-spacing: .025em !important;
}
body .forgot-page.login-container > .brand-panel > .brand-identity .brand-school-name > span {
    display: block !important;
}
body .forgot-page.login-container > .brand-panel > .brand-footer {
    position: absolute !important;
    right: 48px !important;
    bottom: 32px !important;
    left: 48px !important;
    display: flex !important;
    justify-content: space-between !important;
    align-items: flex-end !important;
    gap: 16px !important;
    width: auto !important;
    max-width: none !important;
    margin: 0 !important;
    padding: 0 !important;
    box-sizing: border-box !important;
    font-size: 11px !important;
    line-height: 1.6 !important;
}
body .forgot-page.login-container > .brand-panel > .brand-footer span {
    flex: 1 1 0 !important;
    min-width: 0 !important;
    max-width: none !important;
}
body .forgot-page.login-container > .brand-panel > .brand-footer span:last-child {
    text-align: right !important;
}
@media (max-width: 760px) {
    body .forgot-page.login-container {
        grid-template-columns: minmax(0, 1fr) !important;
    }
    body .forgot-page.login-container > .brand-panel > .brand-identity {
        top: 24px !important;
        left: 22px !important;
        right: 22px !important;
        gap: 12px !important;
    }
    body .forgot-page.login-container > .brand-panel > .brand-identity img {
        flex-basis: 56px !important;
        width: 56px !important;
        height: 56px !important;
        min-width: 56px !important;
        max-width: 56px !important;
        min-height: 56px !important;
        max-height: 56px !important;
    }
    body .forgot-page.login-container > .brand-panel > .brand-identity .brand-school-name {
        font-size: 14px !important;
    }
    body .forgot-page.login-container > .brand-panel > .brand-footer {
        right: 22px !important;
        bottom: 20px !important;
        left: 22px !important;
    }
}


/* FINAL RECOVERY PAGE 60/40 BRAND PANEL LAYOUT */
@media (min-width: 761px) {
    body .forgot-page.login-container {
        display: grid !important;
        grid-template-columns: minmax(0, 6fr) minmax(0, 4fr) !important;
        align-items: stretch !important;
        justify-content: stretch !important;
        min-height: 100vh !important;
        padding: 0 !important;
    }

    body .forgot-page.login-container > .brand-panel {
        position: relative !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        box-sizing: border-box !important;
        min-width: 0 !important;
        min-height: 100vh !important;
        height: auto !important;
        padding: 48px clamp(32px, 5.5vw, 88px) 32px !important;
        overflow: hidden !important;
    }

    body .forgot-page.login-container > .forgot-card {
        align-self: center !important;
        justify-self: center !important;
        width: calc(100% - 48px) !important;
        max-width: 440px !important;
        margin: 24px !important;
    }
}

/* FORGOT PASSWORD PRIMARY BUTTON COLOR */

body .forgot-page button[type="submit"],
body .forgot-page input[type="submit"],
body .forgot-page .btn-primary {
    background-color: var(--color1) !important;
    border-color: var(--color1) !important;
}

</style>


<!-- FIRST PASSWORD DESIGN MATCH: FORGOT PASSWORD ONLY -->
<style id="forgot-match-first-password">

/* Desktop: same 60/40 layout as Change First Password */
@media (min-width: 901px) {
    body .forgot-page.login-container {
        display: grid !important;
        grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) !important;
        align-items: stretch !important;
        justify-content: stretch !important;
        gap: 0 !important;
        width: 100% !important;
        min-height: 100vh !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    body .forgot-page.login-container > .brand-panel {
        width: 100% !important;
        min-width: 0 !important;
        min-height: 100vh !important;
        box-sizing: border-box !important;
    }

    body .forgot-page.login-container > .forgot-card {
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        width: 100% !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 100vh !important;
        height: auto !important;
        margin: 0 !important;
        padding: clamp(24px, 2.5vw, 40px) !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #f8fafc !important;
        box-sizing: border-box !important;
        overflow: visible !important;
    }
}

/* Centered content width */
body .forgot-page.login-container > .forgot-card > .forgot-header,
body .forgot-page.login-container > .forgot-card > .forgot-body {
    display: block !important;
    width: 100% !important;
    max-width: 340px !important;
    margin-left: auto !important;
    margin-right: auto !important;
    box-sizing: border-box !important;
}

body .forgot-page.login-container > .forgot-card > .forgot-header {
    margin-top: 0 !important;
    margin-bottom: 34px !important;
    padding: 0 !important;
    text-align: center !important;
    background: transparent !important;
}

body .forgot-page.login-container .forgot-header .forgot-logo {
    display: block !important;
    width: 72px !important;
    height: 72px !important;
    min-width: 72px !important;
    max-width: 72px !important;
    margin: 0 auto 27px !important;
    object-fit: contain !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
    background: transparent !important;
}

body .forgot-page.login-container .forgot-header h1 {
    margin: 0 !important;
    color: #183b56 !important;
    font-size: 24px !important;
    font-weight: 650 !important;
    line-height: 1.3 !important;
    letter-spacing: -0.4px !important;
    text-align: center !important;
}

body .forgot-page.login-container > .forgot-card > .forgot-body {
    padding: 0 !important;
}

body .forgot-page.login-container .request-header-content {
    width: 100% !important;
    margin: 0 0 26px !important;
    text-align: center !important;
}

body .forgot-page.login-container .request-header-content h2,
body .forgot-page.login-container .forgot-body > h2 {
    margin: 0 0 12px !important;
    color: #183b56 !important;
    font-size: 19px !important;
    font-weight: 600 !important;
    line-height: 1.4 !important;
}

body .forgot-page.login-container .forgot-description {
    margin: 0 0 22px !important;
    color: #687985 !important;
    font-size: 13px !important;
    line-height: 1.8 !important;
}

body .forgot-page.login-container .field {
    width: 100% !important;
    margin: 0 0 23px !important;
    box-sizing: border-box !important;
}

body .forgot-page.login-container .field label {
    display: block !important;
    margin: 0 0 9px !important;
    color: #344054 !important;
    font-size: 13px !important;
    font-weight: 600 !important;
}

body .forgot-page.login-container .field input:not(.otp-input) {
    display: block !important;
    width: 100% !important;
    height: 49px !important;
    min-height: 49px !important;
    padding: 0 14px !important;
    border: 1px solid #d5dee9 !important;
    border-radius: 5px !important;
    background: #fff !important;
    color: #263238 !important;
    font-size: 14px !important;
    box-sizing: border-box !important;
}

body .forgot-page.login-container .field input:not(.otp-input):focus {
    border-color: #285b91 !important;
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(40, 91, 145, .12) !important;
}

body .forgot-page.login-container .forgot-button {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    width: 100% !important;
    min-height: 49px !important;
    height: auto !important;
    margin: 0 !important;
    padding: 12px 18px !important;
    border: 1px solid #183b56 !important;
    border-radius: 5px !important;
    background: #183b56 !important;
    color: #fff !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    box-sizing: border-box !important;
}

body .forgot-page.login-container .forgot-button:hover:not(:disabled) {
    background: #285b91 !important;
    border-color: #285b91 !important;
}

body .forgot-page.login-container .forgot-button:disabled {
    opacity: .55 !important;
    cursor: not-allowed !important;
}

body .forgot-page.login-container .back-login {
    display: block !important;
    width: 100% !important;
    margin: 26px auto 0 !important;
    color: #285b91 !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    text-align: center !important;
}

/* Tablet and mobile: allow natural page scrolling */
@media (max-width: 900px) {
    body .forgot-page.login-container > .forgot-card {
        height: auto !important;
        min-height: 100vh !important;
        max-height: none !important;
        overflow: visible !important;
        box-sizing: border-box !important;
    }

    body .forgot-page.login-container > .forgot-card > .forgot-header,
    body .forgot-page.login-container > .forgot-card > .forgot-body {
        max-width: 410px !important;
    }
}

@media (max-width: 480px) {
    body .forgot-page.login-container > .forgot-card {
        padding: 23px 20px !important;
    }

    body .forgot-page.login-container > .forgot-card > .forgot-header {
        margin-bottom: 27px !important;
    }

    body .forgot-page.login-container .forgot-header .forgot-logo {
        margin-bottom: 22px !important;
    }
}

</style>

<style id="forgot-brand-image-zoom">
@media (min-width: 761px) {
    body .forgot-page.login-container > .brand-panel {
        background-size: cover !important;
        background-position: center center !important;
        background-repeat: no-repeat !important;
    }
}
</style>


<style id="forgot-brand-panel-expand-fix">
@media (min-width: 761px) {

    /* Let the page and its grid row grow with the form */
    html,
    body {
        min-height: 100%;
    }

    body .forgot-page.login-container {
        display: grid !important;
        grid-template-columns: minmax(0, 3fr) minmax(0, 2fr) !important;
        grid-template-rows: minmax(100vh, auto) !important;
        grid-auto-rows: minmax(100vh, auto) !important;
        align-items: stretch !important;
        height: auto !important;
        min-height: 100vh !important;
        padding: 0 !important;
        overflow: visible !important;
    }

    /* Keep the image panel as tall as the complete grid row */
    body .forgot-page.login-container > .brand-panel {
        align-self: stretch !important;
        height: auto !important;
        min-height: 100vh !important;
        max-height: none !important;
        position: relative !important;
        overflow: hidden !important;

        background-size: cover !important;
        background-position: center center !important;
        background-repeat: no-repeat !important;
        background-color: #183b56 !important;
    }

    /* Let the form determine the full page height */
    body .forgot-page.login-container > .forgot-card {
        align-self: stretch !important;
        min-height: 100vh !important;
        height: auto !important;
        max-height: none !important;
        overflow: visible !important;
    }
}
</style>

