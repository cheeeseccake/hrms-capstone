<?php
declare(strict_types=1);

$pageTitle = 'All Clinic Visits';
require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../database/db.php';

$db = (new Database())->getConnection();
if (!$db instanceof PDO) {
    throw new RuntimeException('Clinic visits database connection is unavailable.');
}

$allowedRoleIds = [];
try {
    $roleStatement = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
    $roleStatement->execute();
    $allowedRoleIds = array_values(array_filter(
        array_map('intval', explode(',', (string) $roleStatement->fetchColumn())),
        static fn(int $id): bool => $id > 0
    ));
} catch (Throwable $error) {
    error_log('Clinic visit list role-setting lookup failed: ' . $error->getMessage());
}
$roleAllowed = $allowedRoleIds !== []
    ? in_array((int) ($_SESSION['role_id'] ?? 0), $allowedRoleIds, true)
    : in_array((string) ($_SESSION['role_name'] ?? ''), ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
if (!$roleAllowed) {
    http_response_code(403);
    echo '<section class="clinic-visits"><h2>Access restricted</h2><p>You are not authorized to view clinic visit records.</p></section>';
    return;
}

$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$visitTypes = [
    'consultation' => 'Consultation',
    'emergency' => 'Emergency / first aid',
    'return_to_work' => 'Return-to-work follow-up',
];
$parseDate = static function (mixed $value): ?string {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
        return null;
    }
    return $value;
};

$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$requestedType = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$type = array_key_exists($requestedType, $visitTypes) ? $requestedType : '';
$from = '';
$to = '';
$filterError = '';
if (isset($_GET['from']) && $_GET['from'] !== '') {
    $from = $parseDate($_GET['from']) ?? '';
    if ($from === '') {
        $filterError = 'Enter a valid start date.';
    }
}
if (isset($_GET['to']) && $_GET['to'] !== '') {
    $to = $parseDate($_GET['to']) ?? '';
    if ($to === '') {
        $filterError = 'Enter a valid end date.';
    }
}
if ($filterError === '' && $from !== '' && $to !== '' && $from > $to) {
    $filterError = 'The start date must be on or before the end date.';
}

$pageSize = 25;
$requestedPage = filter_var($_GET['visit_page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$currentPage = $requestedPage === false ? 1 : $requestedPage;
$totalVisits = 0;
$totalPages = 1;
$visits = [];
$queryError = '';

if ($filterError === '') {
    $conditions = [];
    $parameters = [];
    if ($search !== '') {
        $conditions[] = '(e.employee_code LIKE :employee_code OR e.first_name LIKE :first_name OR e.middle_name LIKE :middle_name OR e.last_name LIKE :last_name OR v.reason LIKE :reason)';
        $searchLike = '%' . addcslashes($search, '\\%_') . '%';
        $parameters[':employee_code'] = $searchLike;
        $parameters[':first_name'] = $searchLike;
        $parameters[':middle_name'] = $searchLike;
        $parameters[':last_name'] = $searchLike;
        $parameters[':reason'] = $searchLike;
    }
    if ($type !== '') {
        $conditions[] = 'v.visit_type = :visit_type';
        $parameters[':visit_type'] = $type;
    }
    if ($from !== '') {
        $conditions[] = 'v.visit_at >= :from_date';
        $parameters[':from_date'] = $from;
    }
    if ($to !== '') {
        $conditions[] = 'v.visit_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
        $parameters[':to_date'] = $to;
    }
    $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

    try {
        $countStatement = $db->prepare("
            SELECT COUNT(*)
            FROM clinic_visits v
            LEFT JOIN em_employees e ON e.employee_id = v.employee_id
            $where
        ");
        $countStatement->execute($parameters);
        $totalVisits = (int) $countStatement->fetchColumn();
        $totalPages = max(1, (int) ceil($totalVisits / $pageSize));
        $currentPage = min($currentPage, $totalPages);
        $offset = ($currentPage - 1) * $pageSize;

        $listStatement = $db->prepare("
            SELECT v.visit_id, v.employee_id, v.visit_type, v.visit_at, v.reason,
                   e.employee_code, e.first_name, e.middle_name, e.last_name, e.suffix,
                   d.department_name
            FROM clinic_visits v
            LEFT JOIN em_employees e ON e.employee_id = v.employee_id
            LEFT JOIN em_departments d ON d.department_id = e.department_id
            $where
            ORDER BY v.visit_at DESC, v.visit_id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($parameters as $key => $value) {
            $listStatement->bindValue($key, $value, PDO::PARAM_STR);
        }
        $listStatement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $listStatement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStatement->execute();
        $visits = $listStatement->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $error) {
        error_log('Clinic visit list query failed: ' . $error->getMessage());
        $queryError = 'Clinic visits could not be loaded. Please try again.';
    }
}

$formatVisitDate = static function (mixed $value): string {
    try {
        return (new DateTimeImmutable((string) $value))->format('M j, Y · g:i A');
    } catch (Throwable) {
        return (string) $value;
    }
};
$paginationUrl = static function (int $page) use ($search, $type, $from, $to): string {
    $query = ['page' => 'all-clinic-visits', 'visit_page' => $page];
    foreach (['q' => $search, 'type' => $type, 'from' => $from, 'to' => $to] as $key => $value) {
        if ($value !== '') {
            $query[$key] = $value;
        }
    }
    return 'index.php?' . http_build_query($query);
};
?>
<section class="clinic-visits">
    <form class="clinic-visits__filters" method="get" action="index.php">
        <input type="hidden" name="page" value="all-clinic-visits">
        <label class="clinic-visits__search">
            <span>Search</span>
            <input type="search" name="q" value="<?= $escape($search) ?>" placeholder="Employee, ID, or reason">
        </label>
        <label>
            <span>Visit type</span>
            <select name="type">
                <option value="">All visit types</option>
                <?php foreach ($visitTypes as $value => $label): ?>
                    <option value="<?= $escape($value) ?>" <?= $type === $value ? 'selected' : '' ?>><?= $escape($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>From</span>
            <input type="date" name="from" value="<?= $escape($from) ?>">
        </label>
        <label>
            <span>To</span>
            <input type="date" name="to" value="<?= $escape($to) ?>">
        </label>
        <button type="submit">Apply filters</button>
        <a class="clinic-visits__reset" href="index.php?page=all-clinic-visits">Reset</a>
    </form>

    <?php if ($filterError !== ''): ?>
        <p class="clinic-visits__notice clinic-visits__notice--error" role="alert"><?= $escape($filterError) ?></p>
    <?php elseif ($queryError !== ''): ?>
        <p class="clinic-visits__notice clinic-visits__notice--error" role="alert"><?= $escape($queryError) ?></p>
    <?php else: ?>
        <div class="clinic-visits__table-wrap">
            <table class="clinic-visits__table">
                <thead>
                    <tr><th>Date and time</th><th>Employee</th><th>Department</th><th>Visit type</th><th>Reason</th><th><span class="clinic-visits__sr-only">Actions</span></th></tr>
                </thead>
                <tbody>
                    <?php if ($visits === []): ?>
                        <tr><td colspan="6" class="clinic-visits__empty">No clinic visits match these filters.</td></tr>
                    <?php else: foreach ($visits as $visit):
                        $employeeName = trim(implode(' ', array_filter([
                            (string) ($visit['first_name'] ?? ''),
                            (string) ($visit['middle_name'] ?? ''),
                            (string) ($visit['last_name'] ?? ''),
                        ], static fn(string $part): bool => $part !== '')));
                        if (!empty($visit['suffix'])) {
                            $employeeName .= ', ' . (string) $visit['suffix'];
                        }
                    ?>
                        <tr>
                            <td><?= $escape($formatVisitDate($visit['visit_at'] ?? '')) ?></td>
                            <td>
                                <strong><?= $escape($employeeName !== '' ? $employeeName : 'Employee record unavailable') ?></strong>
                                <?php if (!empty($visit['employee_code'])): ?><span class="clinic-visits__employee-code"><?= $escape($visit['employee_code']) ?></span><?php endif; ?>
                            </td>
                            <td><?= $escape($visit['department_name'] ?? '—') ?></td>
                            <td><span class="clinic-visits__type"><?= $escape($visitTypes[(string) ($visit['visit_type'] ?? '')] ?? 'Clinic visit') ?></span></td>
                            <td class="clinic-visits__reason" title="<?= $escape($visit['reason'] ?? '') ?>"><?= $escape($visit['reason'] ?? '—') ?></td>
                            <td><a class="clinic-visits__row-link" href="index.php?page=employee-health&amp;employee_id=<?= (int) ($visit['employee_id'] ?? 0) ?>">Medical history</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($totalPages > 1): ?>
            <nav class="clinic-visits__pagination" aria-label="Clinic visit pages">
                <?php if ($currentPage > 1): ?>
                    <a href="<?= $escape($paginationUrl($currentPage - 1)) ?>" rel="prev">Previous</a>
                <?php else: ?>
                    <span class="is-disabled">Previous</span>
                <?php endif; ?>
                <span>Page <?= number_format($currentPage) ?> of <?= number_format($totalPages) ?></span>
                <?php if ($currentPage < $totalPages): ?>
                    <a href="<?= $escape($paginationUrl($currentPage + 1)) ?>" rel="next">Next</a>
                <?php else: ?>
                    <span class="is-disabled">Next</span>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
