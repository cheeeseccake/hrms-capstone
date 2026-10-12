<?php

/**
 * Clinic visit recording page.
 *
 * Standalone page for the "Record Clinic Visit" form that used to sit inside
 * the Clinic Visits tab of the Employee Health page. Data access mirrors
 * pages/employee-health.php (same settings table, same registry tables), so a
 * session authorized there is authorized here too.
 */

$pageTitle = 'Record Clinic Visit';

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

/* Medical data must never be cached by the browser or an intermediary. */
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
}
require_once __DIR__ . '/../../../auth/session.php';

/* ── Database connection (same bootstrap as the employee health page) ──── */

$db = $db ?? null;
if (!($db instanceof PDO)) {
    try {
        require_once __DIR__ . '/../../../database/db.php';

        $dbClassCandidates = ['Database', 'App\\Database'];
        foreach ($dbClassCandidates as $dbClassName) {
            if (class_exists($dbClassName)) {
                $db = (new $dbClassName())->getConnection();
                if ($db instanceof PDO) {
                    break;
                }
            }
        }

        if (!($db instanceof PDO)) {
            $db = null;
        }
    } catch (Throwable $e) {
        error_log('ClinicVisit database connection error: ' . $e->getMessage());
        $db = null;
    }
}

$pageError = $db instanceof PDO ? '' : 'Database connection is not available. Clinic visit recording is temporarily disabled.';

/* ── Helpers (defined in pages/employee-health.php as well, so guarded) ── */

if (!function_exists('eh_setting_role_ids')) {
    function eh_setting_role_ids(?PDO $db, string $key): array
    {
        if (!$db instanceof PDO) {
            return [];
        }
        try {
            $stmt = $db->prepare(
                "SELECT setting_value FROM clinic_health_settings WHERE setting_key = :key LIMIT 1"
            );
            $stmt->execute([':key' => $key]);
            $raw = (string) $stmt->fetchColumn();
            $ids = array_filter(array_map('intval', explode(',', $raw)), static function ($id) {
                return $id > 0;
            });
            return array_values(array_unique($ids));
        } catch (Throwable $e) {
            error_log('ClinicVisit settings lookup error: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('eh_full_name')) {
    function eh_full_name(array $employee): string
    {
        $middle = trim((string) ($employee['middle_name'] ?? ''));
        $name = trim((string) ($employee['first_name'] ?? ''));
        if ($middle !== '') {
            $name .= ' ' . $middle;
        }
        $name .= ' ' . trim((string) ($employee['last_name'] ?? ''));
        $suffix = trim((string) ($employee['suffix'] ?? ''));
        if ($suffix !== '') {
            $name .= ', ' . $suffix;
        }
        return trim($name);
    }
}

if (!function_exists('eh_fetch_employee')) {
    function eh_fetch_employee(?PDO $db, int $employeeId): ?array
    {
        if (!$db instanceof PDO || $employeeId <= 0) {
            return null;
        }
        try {
            $stmt = $db->prepare("
                SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name,
                       e.last_name, e.suffix, e.email, e.mobile_no, e.gender,
                       e.birth_date, e.civil_status,
                       e.employment_status,
                       d.department_name, p.position_name
                FROM em_employees e
                LEFT JOIN em_departments d ON d.department_id = e.department_id
                LEFT JOIN em_positions p ON p.position_id = e.position_id
                WHERE e.employee_id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $employeeId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('ClinicVisit employee lookup error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('eh_fmt_date')) {
    function eh_fmt_date($value, string $format = 'M j, Y'): string
    {
        if (empty($value)) {
            return '';
        }
        $ts = strtotime((string) $value);
        return $ts ? date($format, $ts) : '';
    }
}

if (!function_exists('eh_view_value')) {
    function eh_view_value($value, string $suffix = ''): string
    {
        if ($value === null || $value === '') {
            return '<span class="eh-view-missing">Not recorded</span>';
        }
        return htmlspecialchars((string) $value) . ($suffix !== '' ? ' ' . htmlspecialchars($suffix) : '');
    }
}

if (!function_exists('eh_status_stamp_class')) {
    function eh_status_stamp_class(string $status): string
    {
        switch (strtolower($status)) {
            case 'active':
                return 'eh-stamp--success';
            case 'probationary':
                return 'eh-stamp--warning';
            case 'terminated':
                return 'eh-stamp--danger';
            default:
                return 'eh-stamp--neutral';
        }
    }
}

/* ── Authorization: clinic_health_settings drives every role check ─────── */

$medicalRoleIds = eh_setting_role_ids($db, 'medical_access_role_ids');
$registryRoleIds = eh_setting_role_ids($db, 'registry_access_role_ids');
$physicianAssessmentRoleIds = eh_setting_role_ids($db, 'physician_assessment_role_ids');

$currentRoleId = (int) ($_SESSION['role_id'] ?? 0);
$currentRoleName = (string) ($_SESSION['role_name'] ?? '');

if ($currentRoleName !== '' && $db instanceof PDO) {
    try {
        $stmt = $db->prepare("SELECT role_id FROM em_roles WHERE role_name = :name LIMIT 1");
        $stmt->execute([':name' => $currentRoleName]);
        $lookupRoleId = (int) $stmt->fetchColumn();
        if ($lookupRoleId > 0) {
            $currentRoleId = $lookupRoleId;
        }
    } catch (Throwable $e) {
        error_log('ClinicVisit role lookup error: ' . $e->getMessage());
    }
}

/* Fallback role names, used only when the settings table is unavailable. */
$clinicRoleNames = ['Clinic Staff', 'School Clinic Staff', 'System Administrator'];

$canViewMedical = $medicalRoleIds !== []
    ? in_array($currentRoleId, $medicalRoleIds, true)
    : in_array($currentRoleName, $clinicRoleNames, true);
$canManageHealth = $canViewMedical;
$canRecordPhysicianAssessment = $physicianAssessmentRoleIds !== []
    && in_array($currentRoleId, $physicianAssessmentRoleIds, true);
/* Fail closed: with no registry roles configured, only medical roles may look up employees. */
$canAccessRegistry = $registryRoleIds !== []
    ? in_array($currentRoleId, $registryRoleIds, true)
    : $canViewMedical;

/* Privacy notice rendered in the side column (real configured value). */
$recordAccessNotice = '';
if ($db instanceof PDO) {
    try {
        $stmt = $db->prepare(
            "SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'record_access_notice' LIMIT 1"
        );
        $stmt->execute();
        $recordAccessNotice = (string) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $recordAccessNotice = '';
    }
}

/* ── CSRF token (same session key the visit API validates) ─────────────── */

if (empty($_SESSION['eh_csrf_token']) || !is_string($_SESSION['eh_csrf_token'])) {
    $_SESSION['eh_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['eh_csrf_token'];
$clinicVisitRequestToken = bin2hex(random_bytes(32));
$editMode = false;
$visitEditId = filter_var($_GET['edit_visit_id'] ?? null, FILTER_VALIDATE_INT);
$visitEdit = null;

/* ── Selected employee ─────────────────────────────────────────────────── */

$selectedEmployeeId = (int) ($_GET['employee_id'] ?? 0);
$selectedEmployee = eh_fetch_employee($db, $selectedEmployeeId);
$employeeNotFound = $selectedEmployeeId > 0 && $selectedEmployee === null;
$defaultVisitType = ($_GET['visit_type'] ?? '') === 'return_to_work' ? 'return_to_work' : 'consultation';
$requestedRestVisitId = filter_var($_GET['rest_visit_id'] ?? null, FILTER_VALIDATE_INT);

/* Avatar initials, matching the health profile side column. */
$employeeNameParts = $selectedEmployee !== null
    ? (preg_split('/\s+/', trim(eh_full_name($selectedEmployee))) ?: [])
    : [];
$employeeInitials = strtoupper(
    mb_substr($employeeNameParts[0] ?? '', 0, 1)
    . (count($employeeNameParts) > 1 ? mb_substr(end($employeeNameParts), 0, 1) : '')
);

/* ── Employee search, used until an employee is selected ───────────────── */

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$searchSubmitted = isset($_GET['q']);
$searchError = '';
$searchResults = [];

if (!$canAccessRegistry) {
    $searchError = 'You are not authorized to look up employees for clinic visits.';
} elseif ($selectedEmployee !== null) {
    /* An employee is selected — the query is preserved for the return link. */
} elseif (!$searchSubmitted) {
    $searchError = 'Enter an employee number, employee code, or name to find the employee for this visit.';
} elseif ($searchTerm === '') {
    $searchError = 'Enter an employee number, employee code, or name to search.';
} elseif (mb_strlen($searchTerm) > 100) {
    $searchError = 'Search term is too long. Use 100 characters or fewer.';
} elseif ($db instanceof PDO) {
    $like = '%' . addcslashes($searchTerm, '%_\\') . '%';
    $where = "WHERE (e.employee_code LIKE :q_code
                     OR e.first_name LIKE :q_first
                     OR e.middle_name LIKE :q_middle
                     OR e.last_name LIKE :q_last
                     OR CONCAT(e.first_name, ' ', e.last_name) LIKE :q_full
                     OR CONCAT(e.first_name, ' ', IFNULL(e.middle_name, ''), ' ', e.last_name) LIKE :q_full2";
    $params = [
        ':q_code'   => $like,
        ':q_first'  => $like,
        ':q_middle' => $like,
        ':q_last'   => $like,
        ':q_full'   => $like,
        ':q_full2'  => $like,
    ];
    if (ctype_digit($searchTerm)) {
        $where .= ' OR e.employee_id = :exact_id';
        $params[':exact_id'] = (int) $searchTerm;
    }
    $where .= ') AND e.is_archived = 0';

    try {
        $listStmt = $db->prepare("
            SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name, e.last_name,
                   e.employment_status, d.department_name, p.position_name
            FROM em_employees e
            LEFT JOIN em_departments d ON d.department_id = e.department_id
            LEFT JOIN em_positions p ON p.position_id = e.position_id
            $where
            ORDER BY e.employee_code ASC, e.employee_id ASC
            LIMIT 20
        ");
        foreach ($params as $key => $value) {
            $listStmt->bindValue($key, $value);
        }
        $listStmt->execute();
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
        $searchResults = is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('ClinicVisit search error: ' . $e->getMessage());
        $searchError = 'Unable to search the employee registry. Please try again.';
        $searchResults = [];
    }
} else {
    $searchError = 'Database connection is not available. Please try again later.';
}

/* ── Recorded visit history for the selected employee ──────────────────── */

$clinicVisits = [];
$clinicVisitDocuments = [];
$clinicVisitHistoryError = false;
$lastVisit = null;
$previousChiefComplaint = '';
$previousRestStart = '';
$previousRestEnd = '';

if ($selectedEmployee !== null && $canViewMedical && $db instanceof PDO) {
    try {
        $visitStmt = $db->prepare('SELECT visit_id, visit_type, visit_at, reason, details_payload, created_at
                                   FROM clinic_visits
                                   WHERE employee_id = :id
                                   ORDER BY visit_at DESC, visit_id DESC
                                   LIMIT 100');
        $visitStmt->execute([':id' => $selectedEmployeeId]);
        $clinicVisits = $visitStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$canRecordPhysicianAssessment) {
            $physicianOnlyVisitFields = ['physician_assessment','physician_name','physician_identifier','physician_assessment_at','physician_findings','physician_diagnosis','physician_plan','physician_advice','physician_followup','consult_work_restrictions'];
            foreach ($clinicVisits as &$clinicVisit) {
                $visitDetailsForAccess = json_decode((string) ($clinicVisit['details_payload'] ?? ''), true) ?: [];
                foreach ($physicianOnlyVisitFields as $restrictedField) {
                    unset($visitDetailsForAccess[$restrictedField]);
                }
                $clinicVisit['details_payload'] = json_encode($visitDetailsForAccess, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
            }
            unset($clinicVisit);
        }

        /* The Return to Work reason starts with the latest prior case's chief
         * complaint. A supplied source visit ID narrows this to that exact case. */
        foreach ($clinicVisits as $visitRow) {
            if ($requestedRestVisitId !== false && $requestedRestVisitId !== null
                && (int) ($visitRow['visit_id'] ?? 0) !== (int) $requestedRestVisitId) {
                continue;
            }
            $visitDetails = json_decode((string) ($visitRow['details_payload'] ?? ''), true) ?: [];
            $previousChiefComplaint = trim((string) ($visitRow['reason'] ?? ''));
            foreach (['chief_complaint', 'employee_report', 'physician_diagnosis'] as $complaintField) {
                if ($previousChiefComplaint !== '') {
                    break;
                }
                $previousChiefComplaint = trim((string) ($visitDetails[$complaintField] ?? ''));
            }
            if ($previousChiefComplaint !== '') {
                break;
            }
        }

        /* Carry the advised rest period into the Return to Work absence dates.
         * Explicit dates take priority; otherwise use the visit date and its
         * recorded duration so these fields still have useful defaults. */
        foreach ($clinicVisits as $visitRow) {
            if ($requestedRestVisitId !== false && $requestedRestVisitId !== null
                && (int) ($visitRow['visit_id'] ?? 0) !== (int) $requestedRestVisitId) {
                continue;
            }
            $visitDetails = json_decode((string) ($visitRow['details_payload'] ?? ''), true) ?: [];
            if (($visitDetails['rest_advised'] ?? '') !== 'yes') continue;

            $previousRestStart = trim((string) ($visitDetails['rest_start_date'] ?? ''));
            if ($previousRestStart === '') {
                $previousRestStart = substr((string) ($visitRow['visit_at'] ?? ''), 0, 10);
            }
            $previousRestEnd = trim((string) ($visitDetails['rest_end_date'] ?? ''));
            if ($previousRestEnd === '' && $previousRestStart !== '') {
                $durationDays = 0;
                $preset = (string) ($visitDetails['rest_duration_preset'] ?? '');
                if (in_array($preset, ['1_day', '2_days', '3_days'], true)) {
                    $durationDays = (int) substr($preset, 0, 1);
                } elseif ($preset === 'custom' && ($visitDetails['rest_duration_unit'] ?? '') === 'days') {
                    $durationDays = max(1, (int) ceil((float) ($visitDetails['rest_duration_custom'] ?? $visitDetails['rest_duration'] ?? 1)));
                } else {
                    $durationDays = 1;
                }
                try {
                    $previousRestEnd = (new DateTimeImmutable($previousRestStart, new DateTimeZone('Asia/Manila')))
                        ->modify('+' . max(0, $durationDays - 1) . ' days')
                        ->format('Y-m-d');
                } catch (Throwable $e) {
                    $previousRestEnd = $previousRestStart;
                }
            }
            break;
        }

        /* Use the newest saved return-to-work case for prefilling. A newer
         * unrelated visit may not contain any case details to retrieve. */
        $rtwCaseFields = [
            'absence_start', 'absence_end',
        ];
        foreach ($clinicVisits as $visitRow) {
            $visitDetails = json_decode((string) ($visitRow['details_payload'] ?? ''), true);
            if (!is_array($visitDetails)) {
                continue;
            }
            $hasCaseData = false;
            foreach ($rtwCaseFields as $field) {
                if (array_key_exists($field, $visitDetails) && $visitDetails[$field] !== '' && $visitDetails[$field] !== null) {
                    $hasCaseData = true;
                    break;
                }
            }
            if ($hasCaseData) {
                $lastVisit = [
                    'visit_id' => (int) ($visitRow['visit_id'] ?? 0),
                    'visit_type' => (string) ($visitRow['visit_type'] ?? ''),
                    'visit_at' => (string) ($visitRow['visit_at'] ?? ''),
                    'reason' => (string) ($visitRow['reason'] ?? ''),
                    'details' => $visitDetails,
                ];
                break;
            }
        }

        $visitDocumentStmt = $db->prepare('SELECT document_id, visit_id, document_type, original_filename
                                           FROM clinic_visit_documents
                                           WHERE employee_id = :id
                                           ORDER BY document_id');
        $visitDocumentStmt->execute([':id' => $selectedEmployeeId]);
        foreach ($visitDocumentStmt->fetchAll(PDO::FETCH_ASSOC) as $visitDocument) {
            $clinicVisitDocuments[(int) $visitDocument['visit_id']][] = $visitDocument;
        }
    } catch (Throwable $e) {
        error_log('ClinicVisit history lookup error: ' . $e->getMessage());
        $clinicVisitHistoryError = true;
    }
}

/* ── Visit being edited ─────────────────────────────────────────────────── */

if ($visitEditId !== null && $visitEditId > 0 && $selectedEmployee !== null
    && $canManageHealth && $db instanceof PDO && !$clinicVisitHistoryError) {
    try {
        $editStmt = $db->prepare('SELECT visit_id, visit_type, visit_at, reason, details_payload FROM clinic_visits WHERE visit_id = :visit AND employee_id = :employee LIMIT 1');
        $editStmt->execute([':visit' => (int) $visitEditId, ':employee' => $selectedEmployeeId]);
        $visitRow = $editStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('ClinicVisit edit lookup error: ' . $e->getMessage());
        $visitRow = false;
    }
    if (is_array($visitRow)) {
        $visitEditDetails = json_decode((string) ($visitRow['details_payload'] ?? ''), true);
        $visitEdit = [
            'visit_id' => (int) $visitRow['visit_id'],
            'visit_type' => (string) ($visitRow['visit_type'] ?? ''),
            'visit_at' => (string) ($visitRow['visit_at'] ?? ''),
            'reason' => (string) ($visitRow['reason'] ?? ''),
            'details' => is_array($visitEditDetails) ? $visitEditDetails : [],
            'documents' => array_map(static function (array $document): array {
                return [
                    'id' => (int) ($document['document_id'] ?? 0),
                    'type' => (string) ($document['document_type'] ?? ''),
                    'name' => (string) ($document['original_filename'] ?? ''),
                ];
            }, $clinicVisitDocuments[(int) $visitRow['visit_id']] ?? []),
        ];
        $defaultVisitType = $visitEdit['visit_type'];
        $editMode = true;
    }
}

/**
 * Renders the suggestion row that fills the field $targetId as it is typed in.
 * Options come from config/clinic-visit-suggestions.php, which this loads
 * itself: the page runs inside Page::render(), so page-level variables are
 * not visible here.
 */
if (!function_exists('eh_visit_suggestions')) {
    function eh_visit_suggestions(string $optionKey, string $targetId, string $ariaLabel): void
    {
        static $quickOptions = null;
        if ($quickOptions === null) {
            $quickOptions = require dirname(__DIR__) . '/config/clinic-visit-suggestions.php';
        }

        $clinicVisitQuickOptions = $quickOptions;
        $optionKey = $optionKey;
        $targetId = $targetId;
        $ariaLabel = $ariaLabel;
        include dirname(__DIR__) . '/views/clinic-visit/suggestions.php';
    }
}

/* ── Breadcrumb: back to the registry, then the profile, then this form ── */

/* Visit-type labels used by the side-column summary. */
$clinicVisitTypeLabels = [
    'consultation' => 'Consultation',
    'emergency' => 'Emergency',
    'return_to_work' => 'Return to Work',
];

$searchReturnUrl = '?page=employee-health';
if ($searchTerm !== '') {
    $searchReturnUrl .= '&q=' . urlencode($searchTerm);
}

$pageTitle = $visitEdit !== null ? 'Edit Clinic Visit' : 'Record Clinic Visit';
$pageBreadcrumbHtml = '<nav class="eh-breadcrumb" aria-label="Breadcrumb"><ol>'
    . '<li><a href="' . htmlspecialchars($searchReturnUrl) . '">Employees</a></li>'
    . ($selectedEmployee !== null
        ? '<li><a href="?page=employee-health&amp;employee_id=' . (int) $selectedEmployee['employee_id'] . '">Employee Profile</a></li>'
          . '<li aria-current="page">' . htmlspecialchars($pageTitle) . '</li>'
        : '<li aria-current="page">' . htmlspecialchars($pageTitle) . '</li>')
    . '</ol></nav>';

include __DIR__ . '/../views/clinic-visit/record.php';
