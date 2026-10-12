<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/../../../../auth/session.php';
require_once __DIR__ . '/../../../../database/db.php';

function clinic_dashboard_calendar_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_THROW_ON_ERROR);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        clinic_dashboard_calendar_response(405, ['success' => false, 'message' => 'Only GET requests are supported.']);
    }
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Clinic calendar database connection is unavailable.');
    }

    $roleStatement = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
    $roleStatement->execute();
    $allowedRoleIds = array_values(array_filter(array_map('intval', explode(',', (string) $roleStatement->fetchColumn())), static fn(int $id): bool => $id > 0));
    $roleAllowed = $allowedRoleIds !== []
        ? in_array((int) ($_SESSION['role_id'] ?? 0), $allowedRoleIds, true)
        : in_array((string) ($_SESSION['role_name'] ?? ''), ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
    if (!$roleAllowed) {
        clinic_dashboard_calendar_response(403, ['success' => false, 'message' => 'You are not authorized to view clinic calendar data.']);
    }

    $requestedMonth = $_GET['month'] ?? '';
    if (!is_string($requestedMonth) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $requestedMonth) !== 1) {
        clinic_dashboard_calendar_response(400, ['success' => false, 'message' => 'A valid calendar month is required.']);
    }
    $monthStart = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedMonth . '-01');
    if (!$monthStart instanceof DateTimeImmutable || $monthStart->format('Y-m') !== $requestedMonth) {
        clinic_dashboard_calendar_response(400, ['success' => false, 'message' => 'A valid calendar month is required.']);
    }
    $monthEnd = $monthStart->modify('first day of next month');
    $events = [];

    $visitStatement = $db->prepare("
        SELECT DATE(visit_at) AS event_date, COUNT(*) AS total
        FROM clinic_visits
        WHERE visit_at >= :month_start AND visit_at < :month_end
        GROUP BY DATE(visit_at)
    ");
    $visitStatement->execute([
        ':month_start' => $monthStart->format('Y-m-d'),
        ':month_end' => $monthEnd->format('Y-m-d'),
    ]);
    foreach ($visitStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string) $row['event_date'];
        $events[$date] = ['date' => $date, 'visits' => (int) $row['total'], 'examinations' => 0];
    }

    $examStatement = $db->prepare("
        SELECT COALESCE(scheduled_date, exam_date) AS event_date, COUNT(*) AS total
        FROM clinic_health_examinations
        WHERE COALESCE(scheduled_date, exam_date) >= :month_start
          AND COALESCE(scheduled_date, exam_date) < :month_end
          AND status IN ('Scheduled', 'Pending')
        GROUP BY COALESCE(scheduled_date, exam_date)
    ");
    $examStatement->execute([
        ':month_start' => $monthStart->format('Y-m-d'),
        ':month_end' => $monthEnd->format('Y-m-d'),
    ]);
    foreach ($examStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $date = (string) $row['event_date'];
        $events[$date] ??= ['date' => $date, 'visits' => 0, 'examinations' => 0];
        $events[$date]['examinations'] = (int) $row['total'];
    }

    ksort($events);
    clinic_dashboard_calendar_response(200, [
        'success' => true,
        'month' => $requestedMonth,
        'events' => array_values($events),
    ]);
} catch (Throwable $e) {
    error_log('Clinic dashboard calendar failed: ' . $e->getMessage());
    clinic_dashboard_calendar_response(500, ['success' => false, 'message' => 'Clinic calendar data could not be loaded.']);
}
