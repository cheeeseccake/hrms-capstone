<?php
session_start();

$employeeId = (int) ($_SESSION['employee_id'] ?? 0);

if ($employeeId > 0) {
    try {
        require_once __DIR__ . '/../database/db.php';

        $presenceDb = (new Database())->getConnection();

        if ($presenceDb instanceof PDO) {
            $presenceLogout = $presenceDb->prepare("
                INSERT INTO lc_employee_messaging_presence
                    (employee_id, status, last_seen, login_status)
                VALUES (:eid, 'online', CURRENT_TIMESTAMP, 'logged_out')
                ON DUPLICATE KEY UPDATE
                    login_status = 'logged_out'
            ");
            $presenceLogout->execute([':eid' => $employeeId]);
        }
    } catch (Throwable $e) {
        error_log('Messaging logout presence update failed: ' . $e->getMessage());
    }
}

session_destroy();
header('Location: /');
exit();
