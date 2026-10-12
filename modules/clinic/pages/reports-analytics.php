<?php
declare(strict_types=1);

$pageTitle = 'Reports & Analytics';
require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../database/db.php';
require_once __DIR__ . '/../lib/clinic-inventory.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    throw new RuntimeException('Clinic reports database connection is unavailable.');
}

$allowedRoleIds = [];
try {
    $roleStatement = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
    $roleStatement->execute();
    $allowedRoleIds = array_values(array_filter(array_map('intval', explode(',', (string) $roleStatement->fetchColumn())), static fn(int $id): bool => $id > 0));
} catch (Throwable $e) {
    error_log('Clinic report role-setting lookup failed: ' . $e->getMessage());
}
$roleAllowed = $allowedRoleIds !== []
    ? in_array((int) ($_SESSION['role_id'] ?? 0), $allowedRoleIds, true)
    : in_array((string) ($_SESSION['role_name'] ?? ''), ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
if (!$roleAllowed) {
    http_response_code(403);
    echo '<section class="clinic-reports"><h2>Access restricted</h2><p>You are not authorized to view clinic reports.</p></section>';
    return;
}

if (empty($_SESSION['eh_csrf_token']) || !is_string($_SESSION['eh_csrf_token'])) {
    $_SESSION['eh_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['eh_csrf_token'];
$reportTypes = [
    'visits' => 'Clinic Visit Report',
    'consultations' => 'Consultation Report',
    'complaints' => 'Health Complaint Report',
    'medicine' => 'Medicine Usage Report',
    'inventory' => 'Inventory Report',
    'movement' => 'Stock Movement Report',
    'expiration' => 'Expiration Report',
    'health' => 'Employee Health Summary',
    'monthly' => 'Monthly Clinic Report',
    'annual' => 'Annual Clinic Report',
];
$personnelOptions = ['', 'Teaching', 'Non-teaching', 'Administrative'];
$requestData = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' ? $_POST : $_GET;
$requestedType = $requestData['report_type'] ?? 'visits';
$selectedType = is_string($requestedType) && isset($reportTypes[$requestedType]) ? $requestedType : 'visits';
$requestedPersonnel = $requestData['personnel_type'] ?? '';
$personnelType = is_string($requestedPersonnel) && in_array($requestedPersonnel, $personnelOptions, true) ? $requestedPersonnel : '';
$today = new DateTimeImmutable('today');
$parseDate = static function (mixed $value): ?DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $date;
};
$defaultFrom = $today->modify('first day of this month');
$from = $parseDate($requestData['start_date'] ?? null) ?: $defaultFrom;
$to = $parseDate($requestData['end_date'] ?? null) ?: $today;
$reportError = '';
$isGenerateRequest = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['generate_report']);
if ($isGenerateRequest) {
    if (!hash_equals($csrfToken, (string) ($_POST['csrf_token'] ?? ''))) {
        $reportError = 'Your session token is invalid. Refresh the page and try again.';
    } elseif ($parseDate($requestData['start_date'] ?? null) === null || $parseDate($requestData['end_date'] ?? null) === null) {
        $reportError = 'Choose valid start and end dates.';
    } elseif (!is_string($requestedType) || !isset($reportTypes[$requestedType])) {
        $reportError = 'Choose a valid report type.';
    } elseif (!is_string($requestedPersonnel) || !in_array($requestedPersonnel, $personnelOptions, true)) {
        $reportError = 'Choose a valid personnel type.';
    }
}
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$fromDate = $from->format('Y-m-d');
$toDate = $to->format('Y-m-d');
$fromLabel = $from->format('M j, Y');
$toLabel = $to->format('M j, Y');
$e = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rangeRows = static function (string $sql) use ($db, $fromDate, $toDate): array {
    $statement = $db->prepare($sql);
    $statement->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
};

$visits = $rangeRows("
    SELECT v.visit_type, v.visit_at, v.reason, v.details_payload, p.position_name
    FROM clinic_visits v
    LEFT JOIN em_employees e ON e.employee_id = v.employee_id
    LEFT JOIN em_positions p ON p.position_id = e.position_id
    WHERE v.visit_at >= :from_date AND v.visit_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
    ORDER BY v.visit_at DESC
");
$visitTypes = ['consultation' => 'Consultation', 'emergency' => 'Emergency / first aid', 'return_to_work' => 'Return-to-work follow-up'];
$personnelGroup = static function (?string $position): string {
    $position = strtolower((string) $position);
    if (str_contains($position, 'teacher') || str_contains($position, 'faculty')) return 'Teaching';
    if (str_contains($position, 'admin') || str_contains($position, 'director') || str_contains($position, 'manager')) return 'Administrative';
    return 'Non-teaching';
};
$visitCountsByMonth = [];
$annualVisitCounts = [];
$annualConsultationCounts = [];
$annualDispenseCounts = [];
$personnelCounts = ['Teaching' => 0, 'Non-teaching' => 0, 'Administrative' => 0];
$monthlyMedicineCounts = [];
$stockMovementCounts = ['Stock in' => 0, 'Dispensed' => 0, 'Adjustment' => 0];
$visitTypeCounts = ['Consultation' => 0, 'Emergency / first aid' => 0, 'Return-to-work follow-up' => 0];
$consultationCounts = ['General consultation' => 0, 'First aid / emergency' => 0, 'Health assessment' => 0, 'Blood pressure monitoring' => 0, 'Follow-up visit' => 0];
$complaintKeywords = [
    'Headache' => ['headache', 'migraine'],
    'Fever' => ['fever', 'temperature'],
    'Dizziness' => ['dizz', 'vertigo'],
    'Stomachache' => ['stomach', 'abdominal', 'nausea'],
    'Cough and colds' => ['cough', 'cold', 'sore throat', 'flu'],
    'Minor injuries' => ['injury', 'wound', 'cut', 'sprain', 'bruise'],
];
$complaintCounts = [];
$visitReportRows = [];
foreach ($visits as $visit) {
    $date = substr((string) $visit['visit_at'], 0, 10);
    $visitType = $visitTypes[(string) $visit['visit_type']] ?? 'Clinic visit';
    $group = $personnelGroup($visit['position_name'] ?? null);
    $visitCountsByMonth[substr($date, 0, 7)] = ($visitCountsByMonth[substr($date, 0, 7)] ?? 0) + 1;
    $personnelCounts[$group]++;
    $visitTypeCounts[$visitType] = ($visitTypeCounts[$visitType] ?? 0) + 1;
    $year = substr($date, 0, 4);
    $annualVisitCounts[$year] = ($annualVisitCounts[$year] ?? 0) + 1;
    if ($visitType === 'Consultation') $annualConsultationCounts[$year] = ($annualConsultationCounts[$year] ?? 0) + 1;
    $visitReportRows[] = [$date, $visitType, $group, 'Recorded'];

    $details = json_decode((string) $visit['details_payload'], true);
    $details = is_array($details) ? $details : [];
    $combinedText = strtolower(trim((string) $visit['reason']) . ' ' . (string) ($details['employee_report'] ?? '') . ' ' . (string) ($details['first_aid'] ?? ''));
    foreach ($complaintKeywords as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($combinedText, $keyword)) {
                $complaintCounts[$category] = ($complaintCounts[$category] ?? 0) + 1;
                continue 3;
            }
        }
    }
    if (trim((string) $visit['reason']) !== '') {
        $complaintCounts['Other reported concerns'] = ($complaintCounts['Other reported concerns'] ?? 0) + 1;
    }
    if ($visitType === 'Emergency / first aid') {
        $consultationCounts['First aid / emergency']++;
    } elseif ($visitType === 'Return-to-work follow-up') {
        $consultationCounts['Follow-up visit']++;
    } else {
        $consultationCounts['General consultation']++;
        if (!empty($details['blood_pressure'])) $consultationCounts['Blood pressure monitoring']++;
        if (!empty($details['height_cm']) || !empty($details['physician_assessment'])) $consultationCounts['Health assessment']++;
    }
}

$transactionsExist = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'cm_medicine_inventory_transactions'")->fetchColumn() > 0;
$medicineCounts = [];
$movementRows = [];
if ($transactionsExist) {
    $transactions = $rangeRows("
        SELECT t.movement_type, t.quantity_change, t.transaction_date, i.medicine_name, i.strength
        FROM cm_medicine_inventory_transactions t
        INNER JOIN cm_medicine_inventory i ON i.medicine_id = t.medicine_id
        WHERE t.transaction_date >= :from_date AND t.transaction_date <= :to_date
        ORDER BY t.transaction_date DESC, t.transaction_id DESC
    ");
    foreach ($transactions as $transaction) {
        $medicine = trim((string) $transaction['medicine_name'] . ((string) ($transaction['strength'] ?? '') !== '' ? ' ' . $transaction['strength'] : ''));
        $movement = match ((string) $transaction['movement_type']) {
            'stock_in' => 'Stock in',
            'dispense' => 'Dispensed',
            default => 'Adjustment',
        };
        $quantity = abs((int) $transaction['quantity_change']);
        $movementRows[] = [(string) $transaction['transaction_date'], $medicine, $movement, $quantity];
        $stockMovementCounts[$movement] = ($stockMovementCounts[$movement] ?? 0) + $quantity;
        if ($movement === 'Dispensed') {
            $medicineCounts[$medicine] = ($medicineCounts[$medicine] ?? 0) + $quantity;
            $year = substr((string) $transaction['transaction_date'], 0, 4);
            $annualDispenseCounts[$year] = ($annualDispenseCounts[$year] ?? 0) + $quantity;
            $month = substr((string) $transaction['transaction_date'], 0, 7);
            $monthlyMedicineCounts[$month] = ($monthlyMedicineCounts[$month] ?? 0) + $quantity;
        }
    }
}

$inventory = clinic_inventory_fetch($db);
$inventoryRows = [];
$expirationRows = [];
$inventoryStatusCounts = ['Available' => 0, 'Low Stock' => 0, 'Out of Stock' => 0, 'Expiring Soon' => 0, 'Expired' => 0];
foreach ($inventory as $item) {
    $medicine = trim($item['name'] . ($item['strength'] !== '' ? ' ' . $item['strength'] : ''));
    $inventoryRows[] = [$medicine, $item['medicineCategory'] ?: 'Medicine', $item['quantity'] . ' ' . $item['unit'], $item['minimum'] . ' ' . $item['unit'], $item['expires'] ?: '—', $item['status']];
    $inventoryStatusCounts[$item['status']] = ($inventoryStatusCounts[$item['status']] ?? 0) + 1;
    if (in_array($item['status'], ['Expiring Soon', 'Expired'], true)) {
        $expirationRows[] = [$medicine, $item['quantity'] . ' ' . $item['unit'], $item['expires'] ?: '—', $item['status']];
    }
}

$examinations = $rangeRows("
    SELECT status, follow_up_required, follow_up_completed
    FROM clinic_health_examinations
    WHERE COALESCE(exam_date, scheduled_date, created_at) >= :from_date
      AND COALESCE(exam_date, scheduled_date, created_at) < DATE_ADD(:to_date, INTERVAL 1 DAY)
");
$examStatusCounts = ['Scheduled' => 0, 'Completed' => 0, 'Pending' => 0, 'Follow-up Required' => 0, 'Cancelled' => 0];
$profileStatement = $db->prepare("SELECT COUNT(*) FROM clinic_health_profiles WHERE last_assessment_date >= :from_date AND last_assessment_date <= :to_date");
$profileStatement->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
$profileCount = (int) $profileStatement->fetchColumn();
$bpStatement = $db->prepare("SELECT COUNT(*) FROM clinic_health_profiles WHERE last_assessment_date >= :from_date AND last_assessment_date <= :to_date AND blood_pressure IS NOT NULL AND blood_pressure <> ''");
$bpStatement->execute([':from_date' => $fromDate, ':to_date' => $toDate]);
$bpCount = (int) $bpStatement->fetchColumn();
$followUpsRequired = 0;
$followUpsCompleted = 0;
foreach ($examinations as $exam) {
    if (isset($examStatusCounts[$exam['status']])) $examStatusCounts[$exam['status']]++;
    if ((int) $exam['follow_up_required'] === 1) {
        $followUpsRequired++;
        if ((int) $exam['follow_up_completed'] === 1) $followUpsCompleted++;
    }
}
$healthRows = [
    ['Employee health assessments', $profileCount + count($examinations)],
    ['Recorded blood pressure checks', $bpCount],
    ['Follow-ups recommended', $followUpsRequired],
    ['Follow-ups completed', $followUpsCompleted],
];

$monthlyRows = [];
ksort($visitCountsByMonth);
ksort($monthlyMedicineCounts);
foreach ($visitCountsByMonth as $month => $count) $monthlyRows[] = [$month, $count];
$annualYears = array_unique(array_merge(array_keys($annualVisitCounts), array_keys($annualDispenseCounts)));
sort($annualYears);
$annualRows = [];
foreach ($annualYears as $year) {
    $annualRows[] = [(int) $year, $annualVisitCounts[$year] ?? 0, $annualConsultationCounts[$year] ?? 0, $annualDispenseCounts[$year] ?? 0];
}
$filteredVisits = array_values(array_filter($visitReportRows, static fn(array $row): bool => $personnelType === '' || $row[2] === $personnelType));
arsort($complaintCounts);
arsort($medicineCounts);
$reportDefinitions = [
    'visits' => ['columns' => ['Date', 'Visit Type', 'Personnel Type', 'Status'], 'rows' => $filteredVisits],
    'consultations' => ['columns' => ['Consultation Type', 'Total'], 'rows' => array_map(static fn(string $label, int $count): array => [$label, $count], array_keys($consultationCounts), array_values($consultationCounts))],
    'complaints' => ['columns' => ['Aggregated Complaint Category', 'Total Cases'], 'rows' => array_map(static fn(string $label, int $count): array => [$label, $count], array_keys($complaintCounts), array_values($complaintCounts))],
    'medicine' => ['columns' => ['Medicine', 'Units Dispensed'], 'rows' => array_map(static fn(string $label, int $count): array => [$label, $count], array_keys($medicineCounts), array_values($medicineCounts))],
    'inventory' => ['columns' => ['Medicine', 'Category', 'Current Stock', 'Reorder Level', 'Expiration', 'Status'], 'rows' => $inventoryRows],
    'movement' => ['columns' => ['Date', 'Medicine', 'Movement', 'Quantity'], 'rows' => $movementRows],
    'expiration' => ['columns' => ['Medicine', 'Current Stock', 'Expiration Date', 'Status'], 'rows' => $expirationRows],
    'health' => ['columns' => ['Indicator', 'Total'], 'rows' => $healthRows],
    'monthly' => ['columns' => ['Month', 'Total Visits'], 'rows' => $monthlyRows],
    'annual' => ['columns' => ['Year', 'Total Visits', 'Consultations', 'Medicine Units Dispensed'], 'rows' => $annualRows],
];
$definition = $reportDefinitions[$selectedType];
$topMedicineCounts = $medicineCounts;
arsort($topMedicineCounts);
$topMedicineCounts = array_slice($topMedicineCounts, 0, 5, true);
$chartDefinitions = [
    'visits' => ['type' => 'line', 'label' => 'Visits', 'labels' => array_keys($visitCountsByMonth), 'values' => array_values($visitCountsByMonth)],
    'personnel' => ['type' => 'doughnut', 'label' => 'Visits', 'labels' => array_keys($personnelCounts), 'values' => array_values($personnelCounts)],
    'complaints' => ['type' => 'bar', 'label' => 'Reported visits', 'labels' => array_keys($complaintCounts), 'values' => array_values($complaintCounts)],
    'visit-types' => ['type' => 'doughnut', 'label' => 'Visits', 'labels' => array_keys($visitTypeCounts), 'values' => array_values($visitTypeCounts)],
    'consultations' => ['type' => 'bar', 'label' => 'Records', 'labels' => array_keys($consultationCounts), 'values' => array_values($consultationCounts)],
    'medicine-monthly' => ['type' => 'line', 'label' => 'Units dispensed', 'labels' => array_keys($monthlyMedicineCounts), 'values' => array_values($monthlyMedicineCounts)],
    'medicine-top' => ['type' => 'bar', 'label' => 'Units dispensed', 'labels' => array_keys($topMedicineCounts), 'values' => array_values($topMedicineCounts)],
    'examinations' => ['type' => 'doughnut', 'label' => 'Examinations', 'labels' => array_keys($examStatusCounts), 'values' => array_values($examStatusCounts)],
    'inventory-status' => ['type' => 'doughnut', 'label' => 'Medicine types', 'labels' => array_keys($inventoryStatusCounts), 'values' => array_values($inventoryStatusCounts)],
    'stock-movement' => ['type' => 'bar', 'label' => 'Units', 'labels' => array_keys($stockMovementCounts), 'values' => array_values($stockMovementCounts)],
];
$chartJson = json_encode($chartDefinitions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
$generatedReportId = null;
if ($isGenerateRequest && $reportError === '') {
    $reportData = json_encode(['columns' => $definition['columns'], 'rows' => $definition['rows']], JSON_THROW_ON_ERROR);
    $insert = $db->prepare("
        INSERT INTO clinic_report_exports
            (report_key, report_title, period_start, period_end, personnel_type, format, report_data, generated_by)
        VALUES
            (:report_key, :report_title, :period_start, :period_end, :personnel_type, 'Preview', :report_data, :generated_by)
    ");
    $insert->execute([
        ':report_key' => $selectedType,
        ':report_title' => $reportTypes[$selectedType],
        ':period_start' => $fromDate,
        ':period_end' => $toDate,
        ':personnel_type' => $personnelType,
        ':report_data' => $reportData,
        ':generated_by' => (int) $_SESSION['employee_id'],
    ]);
    $generatedReportId = (int) $db->lastInsertId();
}
$historyStatement = $db->query("
    SELECT export_id, report_key, report_title, period_start, period_end, format, generated_at
    FROM clinic_report_exports
    ORDER BY generated_at DESC, export_id DESC
    LIMIT 50
");
$reportHistory = $historyStatement->fetchAll(PDO::FETCH_ASSOC);
?>
<section class="clinic-reports" data-clinic-reports data-chart-data="<?= $e($chartJson) ?>">
    <section class="clinic-reports__section clinic-reports__section--generator" aria-labelledby="clinic-report-generate-heading">
        <div class="clinic-reports__section-heading"><h2 id="clinic-report-generate-heading">Generate Report</h2></div>
        <?php if ($reportError !== ''): ?><p class="clinic-reports__alert" role="alert"><?= $e($reportError) ?></p><?php endif; ?>
        <?php if ($generatedReportId !== null): ?><p class="clinic-reports__success" role="status">Report generated and added to history.</p><?php endif; ?>
        <form class="clinic-reports__form" method="post" action="index.php?page=reports-analytics" data-skip>
            <input type="hidden" name="page" value="reports-analytics">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
            <div class="clinic-reports__form-grid">
                <label><span>Report type</span><select name="report_type" required>
                    <?php foreach ($reportTypes as $key => $title): ?><option value="<?= $e($key) ?>"<?= $selectedType === $key ? ' selected' : '' ?>><?= $e($title) ?></option><?php endforeach; ?>
                </select></label>
                <label><span>Start date</span><input type="date" name="start_date" value="<?= $e($fromDate) ?>" required></label>
                <label><span>End date</span><input type="date" name="end_date" value="<?= $e($toDate) ?>" required></label>
                <label><span>Personnel type</span><select name="personnel_type">
                    <option value="">All personnel</option>
                    <?php foreach (array_slice($personnelOptions, 1) as $option): ?><option value="<?= $e($option) ?>"<?= $personnelType === $option ? ' selected' : '' ?>><?= $e($option) ?></option><?php endforeach; ?>
                </select></label>
            </div>
            <div class="clinic-reports__form-actions">
                <a class="clinic-reports__button" href="index.php?page=reports-analytics">Reset</a>
                <button class="clinic-reports__button clinic-reports__button--primary" type="submit" name="generate_report" value="1">Generate Report</button>
            </div>
        </form>
    </section>

    <section class="clinic-reports__section" aria-labelledby="clinic-report-preview-heading">
        <div class="clinic-reports__section-heading">
            <div><h2 id="clinic-report-preview-heading">Report Preview</h2><p><?= $e($reportTypes[$selectedType]) ?> · <?= $e($fromLabel) ?> – <?= $e($toLabel) ?></p></div>
            <?php if ($generatedReportId !== null): ?>
            <div class="clinic-reports__preview-actions">
                <?php foreach (['pdf' => 'Download PDF', 'csv' => 'Export Excel'] as $format => $label): ?>
                <form method="post" action="/modules/clinic/reports/export.php" data-skip>
                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="export_id" value="<?= $generatedReportId ?>">
                    <input type="hidden" name="format" value="<?= $e($format) ?>">
                    <button class="clinic-reports__button" type="submit"><?= $e($label) ?></button>
                </form>
                <?php endforeach; ?>
                <button class="clinic-reports__button" type="button" data-report-print>Print</button>
            </div>
            <?php endif; ?>
        </div>
        <div class="clinic-reports__preview">
            <div class="clinic-reports__preview-title">
                <strong><?= $e($reportTypes[$selectedType]) ?></strong>
                <span>Reporting period: <?= $e($fromLabel) ?> – <?= $e($toLabel) ?></span>
            </div>
            <div class="clinic-reports__table-wrap">
                <table class="clinic-reports__table" data-report-preview>
                    <thead><tr><?php foreach ($definition['columns'] as $column): ?><th scope="col"><?= $e($column) ?></th><?php endforeach; ?></tr></thead>
                    <tbody>
                    <?php if ($definition['rows'] === []): ?><tr><td class="clinic-reports__empty" colspan="<?= count($definition['columns']) ?>">No records match this report and period.</td></tr>
                    <?php else: foreach ($definition['rows'] as $row): ?><tr><?php foreach ($row as $cell): ?><td><?= $e($cell) ?></td><?php endforeach; ?></tr><?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="clinic-reports__section clinic-reports__section--analytics" aria-labelledby="clinic-report-analytics-heading">
        <div class="clinic-reports__section-heading">
            <div><h2 id="clinic-report-analytics-heading">Clinic Analytics</h2><p>Aggregated clinic activity, medicine use, and inventory trends for this reporting period.</p></div>
        </div>
        <div class="clinic-reports__chart-grid">
            <article class="clinic-reports__chart-panel"><h3>Clinic Visits Over Time</h3><div class="clinic-reports__chart"><canvas data-report-chart="visits" role="img" aria-label="Clinic visits over time"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Visits by Personnel Type</h3><div class="clinic-reports__chart"><canvas data-report-chart="personnel" role="img" aria-label="Visits by personnel type"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Common Health Complaints</h3><div class="clinic-reports__chart"><canvas data-report-chart="complaints" role="img" aria-label="Common health complaints"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Visit Type Distribution</h3><div class="clinic-reports__chart"><canvas data-report-chart="visit-types" role="img" aria-label="Clinic visit types"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Consultation Categories</h3><div class="clinic-reports__chart"><canvas data-report-chart="consultations" role="img" aria-label="Consultation categories"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Medicine Consumption by Month</h3><div class="clinic-reports__chart"><canvas data-report-chart="medicine-monthly" role="img" aria-label="Monthly medicine consumption"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Most Used Medicines</h3><div class="clinic-reports__chart"><canvas data-report-chart="medicine-top" role="img" aria-label="Most used medicines"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Health Examination Status</h3><div class="clinic-reports__chart"><canvas data-report-chart="examinations" role="img" aria-label="Health examination status"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Inventory Status Distribution</h3><div class="clinic-reports__chart"><canvas data-report-chart="inventory-status" role="img" aria-label="Inventory status distribution"></canvas></div></article>
            <article class="clinic-reports__chart-panel"><h3>Stock Movement</h3><div class="clinic-reports__chart"><canvas data-report-chart="stock-movement" role="img" aria-label="Stock movement"></canvas></div></article>
        </div>
    </section>

    <section class="clinic-reports__section clinic-reports__section--history" aria-labelledby="clinic-report-history-heading">
        <div class="clinic-reports__section-heading clinic-reports__history-heading">
            <h2 id="clinic-report-history-heading">Generated Reports</h2>
            <label class="clinic-reports__search"><span class="sr-only">Search generated reports</span><input type="search" placeholder="Search reports" data-report-history-search></label>
        </div>
        <div class="clinic-reports__table-wrap">
            <table class="clinic-reports__table clinic-reports__history-table">
                <thead><tr><th scope="col">Report name</th><th scope="col">Reporting period</th><th scope="col">Generation date</th><th scope="col">Format</th><th scope="col">Download</th></tr></thead>
                <tbody>
                <?php if ($reportHistory === []): ?><tr><td class="clinic-reports__empty" colspan="5">No reports have been generated yet.</td></tr>
                <?php else: foreach ($reportHistory as $history): ?>
                <tr data-report-history-row>
                    <td><?= $e($history['report_title']) ?></td>
                    <td><?= $e(date('M j, Y', strtotime($history['period_start']))) ?> – <?= $e(date('M j, Y', strtotime($history['period_end']))) ?></td>
                    <td><?= $e(date('M j, Y g:i A', strtotime($history['generated_at']))) ?></td>
                    <td><?= $e($history['format']) ?></td>
                    <td><div class="clinic-reports__history-actions">
                        <?php foreach (['pdf' => 'PDF', 'csv' => 'Excel'] as $format => $label): ?>
                <form method="post" action="/modules/clinic/reports/export.php" data-skip>
                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="export_id" value="<?= (int) $history['export_id'] ?>">
                            <input type="hidden" name="format" value="<?= $e($format) ?>">
                            <button class="clinic-reports__text-button" type="submit"><?= $e($label) ?></button>
                        </form>
                        <?php endforeach; ?>
                    </div></td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
