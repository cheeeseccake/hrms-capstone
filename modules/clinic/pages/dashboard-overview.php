<?php
declare(strict_types=1);

$pageTitle = 'Clinic Dashboard';
require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../database/db.php';
require_once __DIR__ . '/../lib/clinic-inventory.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    throw new RuntimeException('Clinic dashboard database connection is unavailable.');
}

$allowedRoleIds = [];
$roleStatement = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
$roleStatement->execute();
$allowedRoleIds = array_values(array_filter(array_map('intval', explode(',', (string) $roleStatement->fetchColumn())), static fn(int $id): bool => $id > 0));
$roleAllowed = $allowedRoleIds !== []
    ? in_array((int) ($_SESSION['role_id'] ?? 0), $allowedRoleIds, true)
    : in_array((string) ($_SESSION['role_name'] ?? ''), ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
if (!$roleAllowed) {
    http_response_code(403);
    echo '<section class="clinic-dashboard"><h2>Access restricted</h2><p>You are not authorized to view clinic dashboard data.</p></section>';
    return;
}

$today = new DateTimeImmutable('today');
$todayDate = $today->format('Y-m-d');
$trendStart = $today->modify('first day of -5 months')->format('Y-m-01');
$visitStatement = $db->prepare("
    SELECT DATE_FORMAT(visit_at, '%Y-%m') AS visit_month, visit_type, COUNT(*) AS visit_count
    FROM clinic_visits
    WHERE visit_at >= :from_date AND visit_at < DATE_ADD(:today, INTERVAL 1 DAY)
    GROUP BY DATE_FORMAT(visit_at, '%Y-%m'), visit_type
    ORDER BY visit_month
");
$visitStatement->execute([':from_date' => $trendStart, ':today' => $todayDate]);
$visitRows = $visitStatement->fetchAll(PDO::FETCH_ASSOC);

$monthlyVisits = [];
$visitTypes = ['consultation' => 0, 'emergency' => 0, 'return_to_work' => 0];
$visitTypeLabels = [
    'consultation' => 'Consultation',
    'emergency' => 'Emergency / first aid',
    'return_to_work' => 'Return-to-work follow-up',
];
for ($offset = 5; $offset >= 0; $offset--) {
    $month = $today->modify("first day of -{$offset} months")->format('Y-m');
    $monthlyVisits[$month] = 0;
}
foreach ($visitRows as $row) {
    $month = (string) $row['visit_month'];
    $count = (int) $row['visit_count'];
    $monthlyVisits[$month] = ($monthlyVisits[$month] ?? 0) + $count;
    $type = (string) $row['visit_type'];
    if ($month === $today->format('Y-m')) {
        $visitTypes[$type] = ($visitTypes[$type] ?? 0) + $count;
    }
}

$scalar = static function (string $sql, array $params = []) use ($db): int {
    $statement = $db->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
};
$tomorrowDate = $today->modify('+1 day')->format('Y-m-d');
$todayVisits = $scalar('SELECT COUNT(*) FROM clinic_visits WHERE visit_at >= :today_start AND visit_at < :tomorrow', [':today_start' => $todayDate, ':tomorrow' => $tomorrowDate]);
$pendingFollowUps = $scalar("
    SELECT COUNT(*) FROM clinic_health_examinations
    WHERE follow_up_required = 1 AND follow_up_completed = 0
      AND (follow_up_date IS NULL OR follow_up_date <= :today)
", [':today' => $todayDate]);

$inventoryItems = clinic_inventory_fetch($db);
$inventoryCounts = ['Available' => 0, 'Low Stock' => 0, 'Out of Stock' => 0, 'Expiring Soon' => 0, 'Expired' => 0];
foreach ($inventoryItems as $item) {
    $inventoryCounts[$item['status']] = ($inventoryCounts[$item['status']] ?? 0) + 1;
}
$lowStockItems = $inventoryCounts['Low Stock'] + $inventoryCounts['Out of Stock'];

$examStatement = $db->prepare("
    SELECT status, COUNT(*) AS total
    FROM clinic_health_examinations
    WHERE COALESCE(exam_date, scheduled_date, created_at) >= :from_date
      AND COALESCE(exam_date, scheduled_date, created_at) < DATE_ADD(:today, INTERVAL 1 DAY)
    GROUP BY status
");
$examStatement->execute([':from_date' => $trendStart, ':today' => $todayDate]);
$examCounts = ['Scheduled' => 0, 'Pending' => 0, 'Completed' => 0, 'Follow-up Required' => 0, 'Cancelled' => 0];
foreach ($examStatement->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $examCounts[(string) $row['status']] = (int) $row['total'];
}

$weekAgoDate = $today->modify('-6 days')->format('Y-m-d');
$recentStatement = $db->prepare("
    SELECT DATE(visit_at) AS visit_date, visit_type, COUNT(*) AS total
    FROM clinic_visits
    WHERE visit_at >= :week_start
      AND visit_at < :tomorrow
    GROUP BY DATE(visit_at), visit_type
    ORDER BY visit_date DESC
");
$recentStatement->execute([':week_start' => $weekAgoDate, ':tomorrow' => $tomorrowDate]);
$recentActivity = $recentStatement->fetchAll(PDO::FETCH_ASSOC);

$monthLabels = array_map(
    static fn(string $month): string => DateTimeImmutable::createFromFormat('!Y-m', $month)->format('M Y'),
    array_keys($monthlyVisits)
);
$chartData = [
    'months' => $monthLabels,
    'visits' => array_values($monthlyVisits),
    'visitTypes' => array_values($visitTypes),
    'visitTypeLabels' => array_values($visitTypeLabels),
    'inventoryLabels' => array_keys($inventoryCounts),
    'inventoryCounts' => array_values($inventoryCounts),
    'examLabels' => array_keys($examCounts),
    'examCounts' => array_values($examCounts),
];
$chartJson = json_encode($chartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$initialMonth = $today->format('Y-m');
$greeting = (int) $today->format('G') < 12 ? 'morning' : ((int) $today->format('G') < 17 ? 'afternoon' : 'evening');
$moduleHeaderClass = 'module-header--clinic-dashboard';
$moduleHeaderHtml = '<div class="clinic-dashboard__welcome">'
    . '<div><h2>Good ' . $greeting . ', ' . $escape($_SESSION['employee_name'] ?? 'Clinic team') . '</h2>'
    . '<p>Clinic activity and operational overview for ' . $escape($today->format('l, F j, Y')) . '.</p></div>'
    . '</div>';
?>
<section class="clinic-dashboard" data-clinic-dashboard data-chart-data="<?= $escape($chartJson) ?>" data-calendar-api="lib/api/dashboard-calendar.php" data-initial-month="<?= $escape($initialMonth) ?>">
    <div class="clinic-dashboard__metrics" aria-label="Clinic key indicators">
        <a class="clinic-dashboard__metric" href="index.php?page=all-clinic-visits">
            <span class="clinic-dashboard__metric-label">Visits today</span>
            <strong><?= $todayVisits ?></strong>
            <span class="clinic-dashboard__metric-foot">View all clinic visits <span aria-hidden="true">→</span></span>
        </a>
        <a class="clinic-dashboard__metric" href="index.php?page=employee-health">
            <span class="clinic-dashboard__metric-label">Follow-ups due</span>
            <strong><?= $pendingFollowUps ?></strong>
            <span class="clinic-dashboard__metric-foot">Review follow-up records <span aria-hidden="true">→</span></span>
        </a>
        <a class="clinic-dashboard__metric" href="index.php?page=inventory-management">
            <span class="clinic-dashboard__metric-label">Low or out of stock</span>
            <strong><?= $lowStockItems ?></strong>
            <span class="clinic-dashboard__metric-foot">Open medicine inventory <span aria-hidden="true">→</span></span>
        </a>
        <a class="clinic-dashboard__metric" href="index.php?page=inventory-management">
            <span class="clinic-dashboard__metric-label">Expired medicines</span>
            <strong><?= $inventoryCounts['Expired'] ?></strong>
            <span class="clinic-dashboard__metric-foot">Review expiry status <span aria-hidden="true">→</span></span>
        </a>
    </div>

    <div class="clinic-dashboard__primary-grid">
        <section class="clinic-dashboard__panel clinic-dashboard__calendar-panel" aria-labelledby="clinic-calendar-heading">
            <div class="clinic-dashboard__panel-heading">
                <div><h3 id="clinic-calendar-heading">Clinic calendar</h3><p>Visits and scheduled examinations. Employee details are not shown.</p></div>
                <a class="clinic-dashboard__text-link" href="index.php?page=all-clinic-visits">View clinic visits</a>
            </div>
            <div class="clinic-dashboard__calendar-toolbar">
                <button type="button" class="clinic-dashboard__calendar-nav" data-calendar-prev aria-label="Previous month">‹</button>
                <strong data-calendar-title><?= $escape($today->format('F Y')) ?></strong>
                <button type="button" class="clinic-dashboard__calendar-nav" data-calendar-next aria-label="Next month">›</button>
            </div>
            <div class="clinic-dashboard__calendar-grid" data-calendar-grid aria-live="polite"></div>
            <div class="clinic-dashboard__calendar-selection" data-calendar-selection aria-live="polite">
                <strong><?= $escape($today->format('l, F j')) ?></strong>
                <span>Select a date to view its clinic activity.</span>
            </div>
        </section>

        <section class="clinic-dashboard__panel clinic-dashboard__activity-panel" aria-labelledby="clinic-activity-heading">
            <div class="clinic-dashboard__panel-heading">
                <div><h3 id="clinic-activity-heading">Recent clinic activity</h3><p>Aggregated visits from the last seven days</p></div>
                <a class="clinic-dashboard__text-link" href="index.php?page=all-clinic-visits">View visits</a>
            </div>
            <div class="clinic-dashboard__table-wrap">
                <table class="clinic-dashboard__table">
                    <thead><tr><th>Date</th><th>Visit type</th><th>Records</th></tr></thead>
                    <tbody>
                    <?php if ($recentActivity === []): ?>
                        <tr><td colspan="3" class="clinic-dashboard__empty">No visits recorded in the last seven days.</td></tr>
                    <?php else: foreach ($recentActivity as $activity): ?>
                        <tr>
                            <td><?= $escape(date('M j', strtotime((string) $activity['visit_date']))) ?></td>
                            <td><?= $escape($visitTypeLabels[(string) $activity['visit_type']] ?? 'Clinic visit') ?></td>
                            <td><?= (int) $activity['total'] ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="clinic-dashboard__quick-links">
                <a href="index.php?page=clinic-visit">Record a visit</a>
                <a href="index.php?page=inventory-management">Manage inventory</a>
                <a href="index.php?page=reports-analytics">View reports</a>
            </div>
        </section>
    </div>

    <section class="clinic-dashboard__charts-section" aria-labelledby="clinic-trends-heading">
        <div class="clinic-dashboard__panel-heading">
            <div><h3 id="clinic-trends-heading">Clinic trends</h3><p>Live totals from clinic visits, examinations, and medicine inventory.</p></div>
            <a class="clinic-dashboard__text-link" href="index.php?page=reports-analytics">Open reports and analytics</a>
        </div>
        <div class="clinic-dashboard__charts">
            <a class="clinic-dashboard__chart-panel" href="index.php?page=reports-analytics" aria-label="Open reports for clinic visit trend">
                <span>Clinic visits · last six months</span><div><canvas data-dashboard-chart="visits" role="img" aria-label="Monthly clinic visits over the past six months"></canvas></div>
            </a>
            <a class="clinic-dashboard__chart-panel" href="index.php?page=reports-analytics" aria-label="Open reports for visit types">
                <span>Visit types · this month</span><div><canvas data-dashboard-chart="visit-types" role="img" aria-label="Clinic visit types this month"></canvas></div>
            </a>
            <a class="clinic-dashboard__chart-panel" href="index.php?page=inventory-management" aria-label="Open medicine inventory status">
                <span>Medicine inventory status</span><div><canvas data-dashboard-chart="inventory" role="img" aria-label="Medicine inventory status"></canvas></div>
            </a>
            <a class="clinic-dashboard__chart-panel" href="index.php?page=medical-records" aria-label="Open examination records">
                <span>Examination status · last six months</span><div><canvas data-dashboard-chart="examinations" role="img" aria-label="Health examination status"></canvas></div>
            </a>
        </div>
    </section>
</section>
