<?php


$pageTitle = 'Employee Health';

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
        $db = null;
    }
}

/* ------------------------------------------------------------------ */
/* Authorization — driven by clinic_health_settings where available     */
/* ------------------------------------------------------------------ */

/**
 * Read a comma-separated role-id list from clinic_health_settings.
 */
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
        error_log('EmployeeHealth settings lookup error: ' . $e->getMessage());
        return [];
    }
}

$medicalRoleIds  = eh_setting_role_ids($db, 'medical_access_role_ids');
$registryRoleIds = eh_setting_role_ids($db, 'registry_access_role_ids');
$physicianAssessmentRoleIds = eh_setting_role_ids($db, 'physician_assessment_role_ids');

$currentEmployeeId = (int) ($_SESSION['employee_id'] ?? 0);
$currentRoleId     = (int) ($_SESSION['role_id'] ?? 0);
$currentRoleName   = (string) ($_SESSION['role_name'] ?? '');

if ($currentRoleName !== '' && $db instanceof PDO) {
    try {
        $stmt = $db->prepare("SELECT role_id FROM em_roles WHERE role_name = :name LIMIT 1");
        $stmt->execute([':name' => $currentRoleName]);
        $lookupRoleId = (int) $stmt->fetchColumn();
        if ($lookupRoleId > 0) {
            $currentRoleId = $lookupRoleId;
        }
    } catch (Throwable $e) {
        error_log('EmployeeHealth role lookup error: ' . $e->getMessage());
    }
}

/* Fallback role names used only when the settings table is unavailable. */
$clinicRoleNames = ['Clinic Staff', 'School Clinic Staff', 'System Administrator'];

$canViewMedical   = $medicalRoleIds !== []
    ? in_array($currentRoleId, $medicalRoleIds, true)
    : in_array($currentRoleName, $clinicRoleNames, true);
$canManageHealth  = $canViewMedical;
$canRecordPhysicianAssessment = $physicianAssessmentRoleIds !== []
    && in_array($currentRoleId, $physicianAssessmentRoleIds, true);
/* Fail closed: with no registry roles configured, only medical roles may open the registry. */
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

/* ------------------------------------------------------------------ */
/* CSRF token                                                          */
/* ------------------------------------------------------------------ */

if (empty($_SESSION['eh_csrf_token']) || !is_string($_SESSION['eh_csrf_token'])) {
    $_SESSION['eh_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['eh_csrf_token'];

/* ------------------------------------------------------------------ */
/* Internal sections of the Employee Health page                       */
/* ------------------------------------------------------------------ */

$healthSections = [
    'visits'        => 'Clinic Visits',
    'profile'       => 'Health Profile',
    'medical'       => 'Medical History',
    'documents'     => 'Medical Documents',
];

/* Active health-record tab. Links that return here (such as the clinic visit
   save redirect) pass ?section=<tab> so the user lands on the same tab. */
$requestedSection = (string) ($_GET['section'] ?? '');
$activeHealthSection = array_key_exists($requestedSection, $healthSections) ? $requestedSection : 'visits';

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Fetch one employee from the registry with department and position.
 */
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
                   e.employment_status, e.hire_date,
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
        error_log('EmployeeHealth employee lookup error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Append an entry to the health audit trail.
 */
function eh_write_audit(?PDO $db, array $entry): void
{
    if (!$db instanceof PDO) {
        return;
    }
    try {
        $stmt = $db->prepare("
            INSERT INTO clinic_health_audit_logs
                (actor_employee_id, actor_role, action, entity_type, entity_id,
                 target_employee_id, access_level, outcome, description, metadata,
                 ip_address, user_agent, created_at)
            VALUES
                (:actor_employee_id, :actor_role, :action, :entity_type, :entity_id,
                 :target_employee_id, :access_level, :outcome, :description, :metadata,
                 :ip_address, :user_agent, NOW())
        ");
        $stmt->execute([
            ':actor_employee_id' => $entry['actor_employee_id'] ?? null,
            ':actor_role'        => $entry['actor_role'] ?? null,
            ':action'            => $entry['action'] ?? '',
            ':entity_type'       => $entry['entity_type'] ?? '',
            ':entity_id'         => $entry['entity_id'] ?? null,
            ':target_employee_id'=> $entry['target_employee_id'] ?? null,
            ':access_level'      => $entry['access_level'] ?? 'medical',
            ':outcome'           => $entry['outcome'] ?? 'allowed',
            ':description'       => $entry['description'] ?? '',
            ':metadata'          => $entry['metadata'] ?? null,
            ':ip_address'        => $entry['ip_address'] ?? null,
            ':user_agent'        => $entry['user_agent'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('EmployeeHealth audit write error: ' . $e->getMessage());
    }
}

function eh_audit_context(): array
{
    return [
        'actor_employee_id' => (int) ($_SESSION['employee_id'] ?? 0) ?: null,
        'actor_role'        => (string) ($_SESSION['role_name'] ?? '') ?: null,
        'ip_address'        => (string) ($_SERVER['REMOTE_ADDR'] ?? '') ?: null,
        'user_agent'        => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
    ];
}

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

/**
 * Human label for a stored enum value.
 */
function eh_label(array $map, $value, string $fallback = 'Not recorded'): string
{
    $key = (string) $value;
    return $map[$key] ?? ($key !== '' ? ucfirst($key) : $fallback);
}

/**
 * Parse the profile's CSV-compatible multi-value fields. New values are
 * quoted CSV; legacy unquoted values are split only when they look like a
 * list of known terms or short title-cased entries, preserving free text.
 */
function eh_multi_values($value): array
{
    $value = trim((string) $value);
    if ($value === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (is_array($decoded) && array_values($decoded) === $decoded) {
        $parts = $decoded;
    } elseif (strpos($value, '"') !== false) {
        $parts = str_getcsv($value, ',', '"', '\\');
    } elseif (strpos($value, ',') === false) {
        $parts = [$value];
    } else {
        $legacyParts = array_map('trim', explode(',', $value));
        $knownTerms = array_map('mb_strtolower', [
            'Penicillin','Amoxicillin','Sulfonamide antibiotics','Aspirin','Ibuprofen','Peanuts','Tree nuts','Shellfish','Fish','Milk','Eggs','Soy','Wheat','Sesame','Latex','Dust mites','Pollen','Mold','Bee venom','Nickel',
            'Hypertension','Type 1 Diabetes Mellitus','Type 2 Diabetes Mellitus','Asthma','COPD','Coronary Artery Disease','Chronic Kidney Disease','Heart Failure','Hyperlipidemia','Hypothyroidism','Hyperthyroidism','Arthritis','Gout','Anemia','Epilepsy','GERD',
            'Paracetamol','Metformin','Losartan','Amlodipine','Atorvastatin','Omeprazole','Salbutamol','Cetirizine','Metoprolol','Levothyroxine',
        ]);
        $allKnown = count($legacyParts) > 1;
        $allTitleCased = count($legacyParts) > 1;
        foreach ($legacyParts as $part) {
            $allKnown = $allKnown && in_array(mb_strtolower($part), $knownTerms, true);
            $allTitleCased = $allTitleCased
                && mb_strlen($part) <= 80
                && (bool) preg_match('/^\p{Lu}/u', $part)
                && !preg_match('/[.!?;]$/u', $part);
        }
        $parts = ($allKnown || $allTitleCased) ? $legacyParts : [$value];
    }

    $values = [];
    $seen = [];
    foreach ($parts as $part) {
        if (!is_scalar($part)) {
            continue;
        }
        $part = trim((string) $part);
        if ($part === '') {
            continue;
        }
        $key = mb_strtolower($part);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $values[] = $part;
    }
    return $values;
}

/** Serialize values as quoted CSV so commas inside custom entries round-trip safely. */
function eh_multi_serialize(array $values): string
{
    $values = array_map(static function ($value): string {
        return '"' . str_replace('"', '""', trim((string) $value)) . '"';
    }, $values);
    return implode(', ', $values);
}

function eh_multi_display($value): string
{
    return implode(', ', eh_multi_values($value));
}

/** Keep legacy plain text intact; only parse values written in our marked format. */
function eh_history_data($value): array
{
    $raw = (string) ($value ?? '');
    $prefix = 'EH_HISTORY_V1:';
    if (strpos($raw, $prefix) === 0) {
        $decoded = json_decode(substr($raw, strlen($prefix)), true);
        if (is_array($decoded) && isset($decoded['entries']) && is_array($decoded['entries'])) {
            $entries = [];
            foreach ($decoded['entries'] as $entry) {
                if (is_scalar($entry) && trim((string) $entry) !== '') {
                    $entries[] = (string) $entry;
                }
            }
            return [
                'entries' => $entries,
                'notes' => is_scalar($decoded['notes'] ?? '') ? (string) ($decoded['notes'] ?? '') : '',
            ];
        }
    }

    return ['entries' => $raw === '' ? [] : [$raw], 'notes' => ''];
}

function eh_history_serialize(array $entries, string $notes): string
{
    $values = [];
    $seen = [];
    foreach ($entries as $entry) {
        $entry = trim((string) $entry);
        $key = mb_strtolower($entry);
        if ($entry === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $values[] = $entry;
    }

    $notes = trim($notes);
    if ($values === [] && $notes === '') {
        return '';
    }

    $json = json_encode(['entries' => $values, 'notes' => $notes], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json === false ? '' : 'EH_HISTORY_V1:' . $json;
}

function eh_history_normalize_post($value): string
{
    $raw = is_scalar($value) ? (string) $value : '';
    $prefix = 'EH_HISTORY_V1:';
    if (strpos($raw, $prefix) !== 0) {
        return $raw;
    }

    $decoded = json_decode(substr($raw, strlen($prefix)), true);
    if (!is_array($decoded) || !isset($decoded['entries']) || !is_array($decoded['entries'])) {
        return $raw;
    }
    $data = eh_history_data($raw);
    return eh_history_serialize($data['entries'], $data['notes']);
}

function eh_history_text($value): string
{
    $data = eh_history_data($value);
    $lines = $data['entries'];
    if ($data['notes'] !== '') {
        $lines[] = 'Notes: ' . $data['notes'];
    }
    return implode("\n", $lines);
}

function eh_history_view($value): string
{
    $text = eh_history_text($value);
    return $text === ''
        ? '<span class="eh-view-missing">Not recorded</span>'
        : nl2br(htmlspecialchars($text));
}

/**
 * Compact numeric display (165.00 -> 165, 165.50 -> 165.5).
 */
function eh_num($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $num = number_format((float) $value, 2, '.', '');
    $num = rtrim(rtrim($num, '0'), '.');
    return $num === '' || $num === '-' ? '0' : $num;
}

function eh_fmt_date($value, string $format = 'M j, Y'): string
{
    if (empty($value)) {
        return '';
    }
    $ts = strtotime((string) $value);
    return $ts ? date($format, $ts) : '';
}

/**
 * Profile completion measured against the fields the clinic requires.
 * Blank values are "missing information", never a clinical finding.
 */
function eh_profile_completion(array $profile): array
{
    $items = [
        'blood_type'             => 'Blood type',
        'height_cm'              => 'Height',
        'weight_kg'              => 'Weight',
        'allergies_status'       => 'Allergy status',
        'conditions_status'      => 'Condition status',
        'emergency_contact_name' => 'Emergency contact name',
        'emergency_contact_phone'=> 'Emergency contact phone',
        'privacy_consent'        => 'Data privacy consent',
    ];

    $missing = [];
    $filled = 0;
    foreach ($items as $key => $label) {
        $isFilled = false;
        if ($key === 'privacy_consent') {
            $isFilled = (int) ($profile['privacy_consent'] ?? 0) === 1;
        } elseif (in_array($key, ['allergies_status', 'conditions_status'], true)) {
            $isFilled = (string) ($profile[$key] ?? 'not_recorded') !== 'not_recorded';
        } else {
            $isFilled = trim((string) ($profile[$key] ?? '')) !== '';
        }
        if ($isFilled) {
            $filled++;
        } else {
            $missing[] = $label;
        }
    }

    $total = count($items);
    return [
        'filled'  => $filled,
        'total'   => $total,
        'percent' => $total > 0 ? (int) round(($filled / $total) * 100) : 0,
        'missing' => $missing,
    ];
}

/**
 * Collect the real records displayed in the side column for one employee.
 */
function eh_side_data(PDO $db, int $employeeId): array
{
    $data = [
        'documents'      => [],
        'document_count' => 0,
        'exam_followups' => [],
        'next_doses'     => [],
        'activity'       => [],
    ];

    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM clinic_health_documents WHERE employee_id = :id");
        $stmt->execute([':id' => $employeeId]);
        $data['document_count'] = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('EmployeeHealth document count error: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT document_id, document_type, original_name, retention_review_date, uploaded_at
            FROM clinic_health_documents
            WHERE employee_id = :id
            ORDER BY uploaded_at DESC
            LIMIT 3
        ");
        $stmt->execute([':id' => $employeeId]);
        $data['documents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EmployeeHealth document list error: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT examination_id, exam_type, follow_up_date
            FROM clinic_health_examinations
            WHERE employee_id = :id
              AND follow_up_required = 1
              AND follow_up_completed = 0
              AND follow_up_date IS NOT NULL
            ORDER BY follow_up_date ASC
            LIMIT 3
        ");
        $stmt->execute([':id' => $employeeId]);
        $data['exam_followups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EmployeeHealth follow-up query error: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT immunization_id, vaccine_name, dose_number, next_dose_due
            FROM clinic_immunizations
            WHERE employee_id = :id
              AND next_dose_due IS NOT NULL
              AND status <> 'Cancelled'
            ORDER BY next_dose_due ASC
            LIMIT 3
        ");
        $stmt->execute([':id' => $employeeId]);
        $data['next_doses'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EmployeeHealth immunization query error: ' . $e->getMessage());
    }

    try {
        $stmt = $db->prepare("
            SELECT action, actor_role, outcome, description, created_at
            FROM clinic_health_audit_logs
            WHERE target_employee_id = :id
              AND action <> 'profile_view'
            ORDER BY created_at DESC
            LIMIT 4
        ");
        $stmt->execute([':id' => $employeeId]);
        $data['activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EmployeeHealth activity query error: ' . $e->getMessage());
    }

    return $data;
}

/**
 * Load the structured health records shown on the Medical History tab.
 * Returns [records, errors]; a failing query is reported, never silently empty.
 */
function eh_load_health_records(PDO $db, int $employeeId): array
{
    $queries = [
        'allergies' => "SELECT allergen_name, allergy_type, reaction, severity, date_identified, current_treatment, status FROM clinic_health_allergies WHERE employee_id = :id ORDER BY date_identified DESC, allergy_id DESC",
        'conditions' => "SELECT condition_name, condition_category, current_status, date_diagnosed, treating_physician, current_treatment, last_reviewed_date FROM clinic_health_conditions WHERE employee_id = :id ORDER BY date_diagnosed DESC, condition_id DESC",
        'medications' => "SELECT medication_name, dosage, frequency, route, purpose, start_date, end_date, prescribing_physician, medication_status, last_reviewed_date FROM clinic_health_medications WHERE employee_id = :id ORDER BY start_date DESC, medication_id DESC",
        'surgeries' => "SELECT record_type, procedure_name, procedure_date, hospital_facility, reason FROM clinic_health_surgeries WHERE employee_id = :id ORDER BY procedure_date DESC, surgery_id DESC",
        'family_history' => "SELECT family_member, relationship, medical_condition, age_at_diagnosis FROM clinic_health_family_history WHERE employee_id = :id ORDER BY family_history_id DESC",
        'examinations' => "SELECT examination_id, exam_type, follow_up_date, follow_up_required, follow_up_completed, status FROM clinic_health_examinations WHERE employee_id = :id ORDER BY follow_up_date DESC, examination_id DESC",
        'immunizations' => "SELECT immunization_id, vaccine_name, dose_number, next_dose_due, status FROM clinic_immunizations WHERE employee_id = :id ORDER BY next_dose_due DESC, immunization_id DESC",
        'documents' => "SELECT document_id, document_type, original_name, retention_review_date, uploaded_at FROM clinic_health_documents WHERE employee_id = :id ORDER BY uploaded_at DESC",
    ];
    $records = [];
    $errors = [];
    foreach ($queries as $key => $sql) {
        $records[$key] = [];
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute([':id' => $employeeId]);
            $records[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('EmployeeHealth ' . $key . ' records lookup error: ' . $e->getMessage());
            $errors[$key] = true;
        }
    }
    return [$records, $errors];
}

/**
 * Names of the profile fields whose stored value differs from the new input.
 * Field names only — values are never written to the audit trail.
 */
function eh_changed_fields(array $before, array $after): array
{
    $changed = [];
    foreach ($after as $key => $value) {
        $old = $before[$key] ?? '';
        if (in_array($key, ['height_cm', 'weight_kg'], true)) {
            $old = eh_num($old);
            $value = eh_num($value);
        }
        if ((string) $old !== (string) $value) {
            $changed[] = $key;
        }
    }
    return $changed;
}

/**
 * Mandatory clinic exam requirements with no completed examination on file.
 */
function eh_pending_exam_requirements(PDO $db, int $employeeId): array
{
    try {
        $stmt = $db->prepare("
            SELECT r.requirement_id, r.exam_type, r.description
            FROM clinic_exam_requirements r
            WHERE r.is_active = 1
              AND r.is_mandatory = 1
              AND NOT EXISTS (
                  SELECT 1 FROM clinic_health_examinations e
                  WHERE e.employee_id = :employee_id
                    AND e.exam_type = r.exam_type
                    AND e.status = 'Completed'
              )
            ORDER BY r.exam_type ASC
            LIMIT 4
        ");
        $stmt->execute([':employee_id' => $employeeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EmployeeHealth exam requirement query error: ' . $e->getMessage());
        return [];
    }
}

/* ------------------------------------------------------------------ */
/* Profile field options                                               */
/* ------------------------------------------------------------------ */

$bloodTypeOptions    = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-', 'Unknown'];
$allergyStatuses     = ['not_recorded', 'none_known', 'reported'];
$conditionStatuses   = ['not_recorded', 'none_known', 'reported'];
$fitnessOptions      = ['not_assessed', 'fit', 'fit_with_restrictions', 'temporarily_unfit', 'unfit'];
$smokingOptions      = ['not_recorded', 'never', 'former', 'current'];
$alcoholOptions      = ['not_recorded', 'none', 'occasional', 'regular'];

$enumLabels = [
    'allergies_status'  => ['not_recorded' => 'Not recorded', 'none_known' => 'None known (confirmed)', 'reported' => 'Reported'],
    'conditions_status' => ['not_recorded' => 'Not recorded', 'none_known' => 'None known (confirmed)', 'reported' => 'Reported'],
    'smoking_status'    => ['not_recorded' => 'Not recorded', 'never' => 'Never', 'former' => 'Former', 'current' => 'Current'],
    'alcohol_use'       => ['not_recorded' => 'Not recorded', 'none' => 'None', 'occasional' => 'Occasional', 'regular' => 'Regular'],
    'fitness_for_duty'  => ['not_assessed' => 'Not assessed', 'fit' => 'Fit', 'fit_with_restrictions' => 'Fit with restrictions', 'temporarily_unfit' => 'Temporarily unfit', 'unfit' => 'Unfit'],
];

$activityLabels = [
    'profile_view'           => 'Health profile viewed',
    'profile_create'         => 'Health profile created',
    'profile_update'         => 'Health profile updated',
    'profile_save_rejected'  => 'Save rejected (validation)',
    'profile_update_denied'  => 'Update denied (unauthorized)',
    'profile_save_failed'    => 'Save failed (database error)',
];

$profileDefaults = [
    'profile_id'             => null,
    'blood_type'             => '',
    'allergies_status'       => 'not_recorded',
    'allergies'              => '',
    'conditions_status'      => 'not_recorded',
    'medical_conditions'     => '',
    'current_medications'    => '',
    'relevant_surgical_history' => '',
    'relevant_family_medical_history' => '',
    'disability_accommodation_info' => '',
    'primary_physician'      => '',
    'emergency_contact_name' => '',
    'emergency_contact_phone'=> '',
    'height_cm'              => '',
    'weight_kg'              => '',
    'bmi'                    => null,
    'smoking_status'         => 'not_recorded',
    'alcohol_use'            => 'not_recorded',
    'fitness_for_duty'       => 'not_assessed',
    'fitness_remarks'        => '',
    'privacy_consent'        => 0,
    'consent_date'           => '',
    'notes'                  => '',
    'created_at'             => null,
    'updated_at'             => null,
];

/* ------------------------------------------------------------------ */
/* Selected employee (profile view)                                    */
/* ------------------------------------------------------------------ */

$selectedEmployeeId = isset($_GET['employee_id']) ? (int) $_GET['employee_id'] : 0;
$selectedEmployee = null;
$employeeNotFound = false;

if ($selectedEmployeeId > 0 && $canAccessRegistry) {
    $selectedEmployee = eh_fetch_employee($db, $selectedEmployeeId);
    if (!$selectedEmployee) {
        $employeeNotFound = true;
    }
}

/* ------------------------------------------------------------------ */
/* Employee search                                                     */
/* ------------------------------------------------------------------ */

$searchTerm = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['employment_status'] ?? ''));
$allowedStatuses = ['Active', 'Probationary', 'Resigned', 'Terminated'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$searchSubmitted = isset($_GET['q']) || $statusFilter !== '';
$perPage = 10;
$currentPage = max(1, (int) ($_GET['emp_page'] ?? 1));

$searchError = '';
$results = [];
$totalRows = 0;
$totalPages = 1;
$offset = 0;

if (!$canAccessRegistry) {
    $searchError = 'You are not authorized to open the employee health registry.';
} elseif ($selectedEmployee !== null) {
    /* A profile is open — the query is preserved for the return link only. */
} elseif ($searchSubmitted && $searchTerm === '' && $statusFilter === '') {
    $searchError = 'Enter an employee number, employee code, or name to search.';
} elseif ($searchTerm !== '' && mb_strlen($searchTerm) > 100) {
    $searchError = 'Search term is too long.';
} elseif (($searchTerm !== '' || $statusFilter !== '') && $db instanceof PDO) {
    $where = 'WHERE e.is_archived = 0';
    $params = [];
    if ($searchTerm !== '') {
        $like = '%' . addcslashes($searchTerm, '%_\\') . '%';
        $where .= " AND (e.employee_code LIKE :q_code
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
        $where .= ')';
    }
    if ($statusFilter !== '') {
        $where .= ' AND e.employment_status = :status';
        $params[':status'] = $statusFilter;
    }

    try {
        $countStmt = $db->prepare("
            SELECT COUNT(*) AS total
            FROM em_employees e
            LEFT JOIN em_departments d ON d.department_id = e.department_id
            LEFT JOIN em_positions p ON p.position_id = e.position_id
            $where
        ");
        $countStmt->execute($params);
        $totalRows = (int) ($countStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;
        if ($currentPage > $totalPages) {
            $currentPage = $totalPages;
        }
        $offset = ($currentPage - 1) * $perPage;

        $listStmt = $db->prepare("
            SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name, e.last_name,
                   e.employment_status, d.department_name, p.position_name
            FROM em_employees e
            LEFT JOIN em_departments d ON d.department_id = e.department_id
            LEFT JOIN em_positions p ON p.position_id = e.position_id
            $where
            ORDER BY e.employee_code ASC, e.employee_id ASC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $listStmt->bindValue($key, $value);
        }
        $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStmt->execute();
        $results = $listStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('EmployeeHealth search error: ' . $e->getMessage());
        $searchError = 'Unable to search the employee registry. Please try again.';
        $results = [];
        $totalRows = 0;
    }
} elseif (($searchTerm !== '' || $statusFilter !== '') && !$db instanceof PDO) {
    $searchError = 'Database connection is not available.';
}

/* Registry size shown in the search-page side column (real count). */
$activeEmployeeCount = 0;
if ($canAccessRegistry && $selectedEmployee === null && $db instanceof PDO) {
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM em_employees
            WHERE is_archived = 0 AND employment_status IN ('Active','Probationary')
        ");
        $stmt->execute();
        $activeEmployeeCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        $activeEmployeeCount = 0;
    }
}

/* ------------------------------------------------------------------ */
/* Selected employee (profile view)                                    */
/* ------------------------------------------------------------------ */

$profile = $profileDefaults;
$philhealthNo = '';
$profileError = '';
$profileSaved = false;
$clinicVisits = [];
$clinicVisitDocuments = [];
$allClinicVisitDocuments = [];
$clinicVisitHistoryError = false;
$clinicVisitRequestToken = bin2hex(random_bytes(32));
$fieldErrors = [];
$noKnownAllergies = false;
$noKnownConditions = false;
$sideData = [
    'documents'      => [],
    'document_count' => 0,
    'exam_followups' => [],
    'next_doses'     => [],
    'activity'       => [],
];
$pendingRequirements = [];
$healthRecordErrors = [];
$healthRecords = [
    'allergies' => [], 'conditions' => [], 'medications' => [],
    'surgeries' => [], 'family_history' => [], 'examinations' => [],
    'immunizations' => [], 'documents' => [],
];

/* True when the stored profile could not be read; saving is blocked so blanks never overwrite real data. */
$profileLoadFailed = false;

if ($selectedEmployee && $db instanceof PDO) {
    /* Medical data is only read for roles allowed to see it (data minimisation). */
    if ($canViewMedical) {
        try {
            $stmt = $db->prepare('SELECT * FROM clinic_health_profiles WHERE employee_id = :id LIMIT 1');
            $stmt->execute([':id' => $selectedEmployeeId]);
            $existingProfile = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingProfile) {
                $profile = array_merge($profileDefaults, $existingProfile);
            }
        } catch (Throwable $e) {
            error_log('EmployeeHealth profile fetch error: ' . $e->getMessage());
            $profileLoadFailed = true;
            $profileError = 'The health profile could not be loaded. Editing is disabled until it loads correctly; please refresh.';
        }
    }

    /* Show the "saved" confirmation after a Post/Redirect/Get round trip. */
    if (($_SESSION['eh_profile_saved_for'] ?? 0) === $selectedEmployeeId) {
        $profileSaved = true;
    }
    unset($_SESSION['eh_profile_saved_for']);

    /* Flash set by the clinic visit API after a successful save, so the
       confirmation survives the redirect back to this page. */
    if (($profileSaved === false) && (($_SESSION['eh_visit_saved_for'] ?? 0) === $selectedEmployeeId)) {
        $profileSaved = true;
        $profileSavedMessage = 'Clinic visit recorded successfully.';
    }
    unset($_SESSION['eh_visit_saved_for']);
    $allergyEntries = eh_multi_values($profile['allergies']);
    $conditionEntries = eh_multi_values($profile['medical_conditions']);
    $profile['allergies_status'] = $allergyEntries !== []
        ? 'reported'
        : ((string) $profile['allergies_status'] === 'none_known' ? 'none_known' : 'not_recorded');
    $profile['conditions_status'] = $conditionEntries !== []
        ? 'reported'
        : ((string) $profile['conditions_status'] === 'none_known' ? 'none_known' : 'not_recorded');
    $noKnownAllergies = (string) $profile['allergies_status'] === 'none_known'
        && $allergyEntries === [];
    $noKnownConditions = (string) $profile['conditions_status'] === 'none_known'
        && $conditionEntries === [];

    if ($canViewMedical) {
        try {
            $philhealthStmt = $db->prepare('SELECT philhealth_no FROM payr_bcp.em_government_ids WHERE employee_id = :id ORDER BY gov_id DESC LIMIT 1');
            $philhealthStmt->execute([':id' => $selectedEmployeeId]);
            $philhealthValue = $philhealthStmt->fetchColumn();
            $philhealthNo = is_string($philhealthValue) ? trim($philhealthValue) : '';
        } catch (Throwable $e) {
            error_log('EmployeeHealth PhilHealth lookup error: ' . $e->getMessage());
        }

        $sideData = eh_side_data($db, $selectedEmployeeId);
        $pendingRequirements = eh_pending_exam_requirements($db, $selectedEmployeeId);
        [$healthRecords, $healthRecordErrors] = eh_load_health_records($db, $selectedEmployeeId);

        /* Every read of an employee's medical record is audited (RA 10173 accountability). */
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            eh_write_audit($db, eh_audit_context() + [
                'action'             => 'profile_view',
                'entity_type'        => 'clinic_health_profiles',
                'entity_id'          => $profile['profile_id'] ?? null,
                'target_employee_id' => $selectedEmployeeId,
                'outcome'            => 'allowed',
                'description'        => 'Opened employee health record for employee ID ' . $selectedEmployeeId . '.',
            ]);
        }

        try {
            $visitStmt = $db->prepare('SELECT visit_id, visit_type, visit_at, reason, details_payload, created_at FROM clinic_visits WHERE employee_id = :id ORDER BY visit_at DESC, visit_id DESC');
            $visitStmt->execute([':id' => $selectedEmployeeId]);
            $clinicVisits = $visitStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$canRecordPhysicianAssessment) {
                $physicianOnlyVisitFields = ['physician_assessment','physician_name','physician_identifier','physician_assessment_at','physician_findings','physician_diagnosis','physician_plan','physician_advice','physician_followup','consult_work_restrictions'];
                foreach ($clinicVisits as &$clinicVisit) {
                    $visitDetailsForAccess = json_decode((string) ($clinicVisit['details_payload'] ?? ''), true) ?: [];
                    foreach ($physicianOnlyVisitFields as $restrictedField) unset($visitDetailsForAccess[$restrictedField]);
                    $clinicVisit['details_payload'] = json_encode($visitDetailsForAccess, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
                }
                unset($clinicVisit);
            }
            $visitDocumentStmt = $db->prepare('SELECT d.document_id, d.visit_id, d.document_type, d.original_filename, v.visit_type, v.visit_at, v.reason FROM clinic_visit_documents d INNER JOIN clinic_visits v ON v.visit_id = d.visit_id AND v.employee_id = d.employee_id WHERE d.employee_id = :id ORDER BY v.visit_at DESC, d.document_id DESC');
            $visitDocumentStmt->execute([':id' => $selectedEmployeeId]);
            foreach ($visitDocumentStmt->fetchAll(PDO::FETCH_ASSOC) as $visitDocument) {
                $clinicVisitDocuments[(int) $visitDocument['visit_id']][] = $visitDocument;
                $allClinicVisitDocuments[] = $visitDocument;
            }
        } catch (Throwable $e) {
            error_log('EmployeeHealth clinic visit history lookup error: ' . $e->getMessage());
            $clinicVisitHistoryError = true;
        }
    }
}

/* Edit mode is explicit: view-first, edit only on request or on errors. */
$editMode = $selectedEmployee !== null
    && $canManageHealth
    && (isset($_GET['edit']) && $_GET['edit'] === '1');

/* URL that returns to the search results, preserving the last query. */
$searchReturnUrl = '?page=employee-health';
if ($searchTerm !== '') {
    $searchReturnUrl .= '&q=' . urlencode($searchTerm);
}
if ($statusFilter !== '') {
    $searchReturnUrl .= '&employment_status=' . urlencode($statusFilter);
}

$pageBreadcrumbHtml = '';
if ($selectedEmployee !== null) {
    $pageBreadcrumbHtml = '<nav class="eh-breadcrumb" aria-label="Breadcrumb"><ol>'
        . '<li><a href="' . htmlspecialchars($searchReturnUrl) . '">Employees</a></li>'
        . '<li>Employee Profile</li>'
        . '<li aria-current="page">' . htmlspecialchars(eh_full_name($selectedEmployee)) . '</li>'
        . '</ol></nav>';
}

/* ------------------------------------------------------------------ */
/* Save health profile                                                 */
/* ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'save_health_profile'
    && $selectedEmployee
) {
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['eh_csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
        $profileError = 'Invalid session. Please refresh and try again.';
    } elseif (!$canManageHealth) {
        eh_write_audit($db, eh_audit_context() + [
            'action'             => 'profile_update_denied',
            'entity_type'        => 'clinic_health_profiles',
            'target_employee_id' => $selectedEmployeeId,
            'outcome'            => 'denied',
            'description'        => 'Unauthorized attempt to modify an employee health profile.',
        ]);
        $profileError = 'You are not authorized to modify health records.';
    } elseif (!$db instanceof PDO) {
        $profileError = 'Database connection is not available.';
    } elseif ($profileLoadFailed) {
        $profileError = 'The stored health profile could not be loaded, so saving was blocked to protect existing data. Please refresh and try again.';
    } else {
        $previousProfile = $profile;
        /* -------- gather and validate input -------- */
        $allergyValues = eh_multi_values($_POST['allergies'] ?? '');
        $conditionValues = eh_multi_values($_POST['medical_conditions'] ?? '');
        $medicationValues = eh_multi_values($_POST['current_medications'] ?? '');
        $noKnownAllergies = isset($_POST['no_known_allergies']);
        $noKnownConditions = isset($_POST['no_known_conditions']);
        $fieldErrors = [];

        if ($noKnownAllergies && $allergyValues !== []) {
            $fieldErrors['allergies'] = 'Clear the allergy entries before confirming no known allergies.';
            $noKnownAllergies = false;
        }
        if ($noKnownConditions && $conditionValues !== []) {
            $fieldErrors['medical_conditions'] = 'Clear the condition entries before confirming no known medical conditions.';
            $noKnownConditions = false;
        }

        $input = [
            'blood_type'             => trim((string) ($_POST['blood_type'] ?? '')),
            'allergies_status'       => $noKnownAllergies ? 'none_known' : ($allergyValues !== [] ? 'reported' : 'not_recorded'),
            'allergies'              => eh_multi_serialize($allergyValues),
            'conditions_status'      => $noKnownConditions ? 'none_known' : ($conditionValues !== [] ? 'reported' : 'not_recorded'),
            'medical_conditions'     => eh_multi_serialize($conditionValues),
            'current_medications'    => eh_multi_serialize($medicationValues),
            'relevant_surgical_history' => eh_history_normalize_post($_POST['relevant_surgical_history'] ?? ''),
            'relevant_family_medical_history' => eh_history_normalize_post($_POST['relevant_family_medical_history'] ?? ''),
            'disability_accommodation_info' => eh_history_normalize_post($_POST['disability_accommodation_info'] ?? ''),
            'primary_physician'      => trim((string) ($_POST['primary_physician'] ?? '')),
            'emergency_contact_name' => trim((string) ($_POST['emergency_contact_name'] ?? '')),
            'emergency_contact_phone'=> trim((string) ($_POST['emergency_contact_phone'] ?? '')),
            'height_cm'              => trim((string) ($_POST['height_cm'] ?? '')),
            'weight_kg'              => trim((string) ($_POST['weight_kg'] ?? '')),
            'smoking_status'         => trim((string) ($_POST['smoking_status'] ?? 'not_recorded')),
            'alcohol_use'            => trim((string) ($_POST['alcohol_use'] ?? 'not_recorded')),
            'fitness_for_duty'       => trim((string) ($_POST['fitness_for_duty'] ?? $profile['fitness_for_duty'] ?? 'not_assessed')),
            'fitness_remarks'        => trim((string) ($_POST['fitness_remarks'] ?? $profile['fitness_remarks'] ?? '')),
            'notes'                  => trim((string) ($_POST['notes'] ?? $profile['notes'] ?? '')),
            'consent_date'           => trim((string) ($_POST['consent_date'] ?? $profile['consent_date'] ?? '')),
            'privacy_consent'        => array_key_exists('privacy_consent', $_POST)
                ? (isset($_POST['privacy_consent']) ? 1 : 0)
                : (int) ($profile['privacy_consent'] ?? 0),
        ];

        if (!in_array($input['blood_type'], $bloodTypeOptions, true)) {
            $input['blood_type'] = '';
        }
        if (!in_array($input['fitness_for_duty'], $fitnessOptions, true)) {
            $input['fitness_for_duty'] = 'not_assessed';
        }
        /* A fitness-for-duty determination is a clinical decision: only physician roles may change it. */
        if (!$canRecordPhysicianAssessment) {
            $input['fitness_for_duty'] = in_array((string) ($previousProfile['fitness_for_duty'] ?? ''), $fitnessOptions, true)
                ? (string) $previousProfile['fitness_for_duty']
                : 'not_assessed';
            $input['fitness_remarks'] = (string) ($previousProfile['fitness_remarks'] ?? '');
        }
        if (!in_array($input['smoking_status'], $smokingOptions, true)) {
            $input['smoking_status'] = 'not_recorded';
        }
        if (!in_array($input['alcohol_use'], $alcoholOptions, true)) {
            $input['alcohol_use'] = 'not_recorded';
        }

        foreach (['allergies' => $input['allergies'], 'medical_conditions' => $input['medical_conditions'], 'current_medications' => $input['current_medications']] as $multiField => $multiValue) {
            if (mb_strlen($multiValue) > 2000) {
                $fieldErrors[$multiField] = 'This field cannot exceed 2000 characters.';
            }
        }
        foreach (['relevant_surgical_history', 'relevant_family_medical_history', 'disability_accommodation_info'] as $historyField) {
            if (mb_strlen($input[$historyField]) > 5000) {
                $fieldErrors[$historyField] = 'This field cannot exceed 5000 characters.';
            }
        }
        if (mb_strlen($input['primary_physician']) > 160) {
            $fieldErrors['primary_physician'] = 'Primary physician name cannot exceed 160 characters.';
        }

        $height = null;
        if ($input['height_cm'] !== '') {
            if (!is_numeric($input['height_cm']) || $input['height_cm'] < 30 || $input['height_cm'] > 260) {
                $fieldErrors['height_cm'] = 'Height must be between 30 and 260 cm.';
            } else {
                $height = round((float) $input['height_cm'], 2);
            }
        }
        $weight = null;
        if ($input['weight_kg'] !== '') {
            if (!is_numeric($input['weight_kg']) || $input['weight_kg'] < 1 || $input['weight_kg'] > 400) {
                $fieldErrors['weight_kg'] = 'Weight must be between 1 and 400 kg.';
            } else {
                $weight = round((float) $input['weight_kg'], 2);
            }
        }

        if (mb_strlen($input['emergency_contact_name']) > 150) {
            $fieldErrors['emergency_contact_name'] = 'Emergency contact name is too long.';
        }
        $emergencyPhoneNormalized = preg_replace('/[\s().-]/', '', $input['emergency_contact_phone']);
        if (mb_strlen($input['emergency_contact_phone']) > 40) {
            $fieldErrors['emergency_contact_phone'] = 'Emergency contact phone is too long.';
        } elseif ($input['emergency_contact_phone'] !== ''
            && (preg_match('/\A[0-9+().\s-]+\z/', $input['emergency_contact_phone']) !== 1
                || preg_match('/\A(?:09\d{9}|0[2-9]\d{8,9}|\+?639\d{9}|\+?63[2-9]\d{8,9})\z/', (string) $emergencyPhoneNormalized) !== 1)) {
            $fieldErrors['emergency_contact_phone'] = 'Enter a valid Philippine mobile or landline number, such as 0917-555-1234 or +63 917 555 1234.';
        }
        if (mb_strlen($input['fitness_remarks']) > 255) {
            $fieldErrors['fitness_remarks'] = 'Fitness remarks are too long.';
        }

        if ($input['consent_date'] !== '') {
            $consentDate = DateTime::createFromFormat('Y-m-d', $input['consent_date']);
            if (!$consentDate || $consentDate->format('Y-m-d') !== $input['consent_date']) {
                $fieldErrors['consent_date'] = 'Consent date is not a valid date.';
            } elseif ($consentDate > new DateTime('today')) {
                $fieldErrors['consent_date'] = 'Consent date cannot be in the future.';
            }
        } elseif ($input['privacy_consent'] === 1) {
            $input['consent_date'] = date('Y-m-d');
        }

        $bmi = null;
        if ($height !== null && $weight !== null && $height > 0) {
            $meters = $height / 100;
            $bmi = round($weight / ($meters * $meters), 2);
        }

        if ($fieldErrors !== []) {
            $profileError = 'Please correct the highlighted fields and try again.';
            $profile = array_merge($profileDefaults, $input);
            $profile['height_cm'] = $input['height_cm'];
            $profile['weight_kg'] = $input['weight_kg'];
            $profile['bmi'] = $bmi;
            $editMode = true;
            eh_write_audit($db, eh_audit_context() + [
                'action'             => 'profile_save_rejected',
                'entity_type'        => 'clinic_health_profiles',
                'target_employee_id' => $selectedEmployeeId,
                'outcome'            => 'denied',
                'description'        => 'Health profile save rejected by validation.',
                'metadata'           => json_encode(['fields' => array_keys($fieldErrors)]),
            ]);
        } else {
            try {
                $db->beginTransaction();

                $existingStmt = $db->prepare(
                    'SELECT profile_id FROM clinic_health_profiles WHERE employee_id = :id LIMIT 1'
                );
                $existingStmt->execute([':id' => $selectedEmployeeId]);
                $existingProfileId = $existingStmt->fetchColumn();
                $isNewProfile = $existingProfileId ? false : true;

                if ($isNewProfile) {
                    $insertStmt = $db->prepare("
                        INSERT INTO clinic_health_profiles
                            (employee_id, blood_type, allergies_status, allergies,
                             conditions_status, medical_conditions, current_medications,
                             relevant_surgical_history, relevant_family_medical_history,
                             disability_accommodation_info, primary_physician,
                             emergency_contact_name, emergency_contact_phone,
                             height_cm, weight_kg, bmi, smoking_status, alcohol_use,
                             fitness_for_duty, fitness_remarks, privacy_consent,
                             consent_date, notes, created_by, updated_by)
                        VALUES
                            (:employee_id, :blood_type, :allergies_status, :allergies,
                             :conditions_status, :medical_conditions, :current_medications,
                             :relevant_surgical_history, :relevant_family_medical_history,
                             :disability_accommodation_info, :primary_physician,
                             :emergency_contact_name, :emergency_contact_phone,
                             :height_cm, :weight_kg, :bmi, :smoking_status, :alcohol_use,
                             :fitness_for_duty, :fitness_remarks, :privacy_consent,
                             :consent_date, :notes, :created_by, :updated_by)
                    ");
                    $insertStmt->execute([
                        ':employee_id'             => $selectedEmployeeId,
                        ':blood_type'              => $input['blood_type'] !== '' ? $input['blood_type'] : null,
                        ':allergies_status'        => $input['allergies_status'],
                        ':allergies'               => $input['allergies'] !== '' ? $input['allergies'] : null,
                        ':conditions_status'       => $input['conditions_status'],
                        ':medical_conditions'      => $input['medical_conditions'] !== '' ? $input['medical_conditions'] : null,
                        ':current_medications'     => $input['current_medications'] !== '' ? $input['current_medications'] : null,
                        ':relevant_surgical_history' => $input['relevant_surgical_history'] !== '' ? $input['relevant_surgical_history'] : null,
                        ':relevant_family_medical_history' => $input['relevant_family_medical_history'] !== '' ? $input['relevant_family_medical_history'] : null,
                        ':disability_accommodation_info' => $input['disability_accommodation_info'] !== '' ? $input['disability_accommodation_info'] : null,
                        ':primary_physician'       => $input['primary_physician'] !== '' ? $input['primary_physician'] : null,
                        ':emergency_contact_name'  => $input['emergency_contact_name'] !== '' ? $input['emergency_contact_name'] : null,
                        ':emergency_contact_phone' => $input['emergency_contact_phone'] !== '' ? $input['emergency_contact_phone'] : null,
                        ':height_cm'               => $height,
                        ':weight_kg'               => $weight,
                        ':bmi'                     => $bmi,
                        ':smoking_status'          => $input['smoking_status'],
                        ':alcohol_use'             => $input['alcohol_use'],
                        ':fitness_for_duty'        => $input['fitness_for_duty'],
                        ':fitness_remarks'         => $input['fitness_remarks'] !== '' ? $input['fitness_remarks'] : null,
                        ':privacy_consent'         => $input['privacy_consent'],
                        ':consent_date'            => $input['consent_date'] !== '' ? $input['consent_date'] : null,
                        ':notes'                   => $input['notes'] !== '' ? $input['notes'] : null,
                        ':created_by'              => $currentEmployeeId ?: null,
                        ':updated_by'              => $currentEmployeeId ?: null,
                    ]);
                    $profileId = (int) $db->lastInsertId();
                } else {
                    $updateStmt = $db->prepare("
                        UPDATE clinic_health_profiles
                        SET blood_type = :blood_type,
                            allergies_status = :allergies_status,
                            allergies = :allergies,
                            conditions_status = :conditions_status,
                            medical_conditions = :medical_conditions,
                            current_medications = :current_medications,
                            relevant_surgical_history = :relevant_surgical_history,
                            relevant_family_medical_history = :relevant_family_medical_history,
                            disability_accommodation_info = :disability_accommodation_info,
                            primary_physician = :primary_physician,
                            emergency_contact_name = :emergency_contact_name,
                            emergency_contact_phone = :emergency_contact_phone,
                            height_cm = :height_cm,
                            weight_kg = :weight_kg,
                            bmi = :bmi,
                            smoking_status = :smoking_status,
                            alcohol_use = :alcohol_use,
                            fitness_for_duty = :fitness_for_duty,
                            fitness_remarks = :fitness_remarks,
                            privacy_consent = :privacy_consent,
                            consent_date = :consent_date,
                            notes = :notes,
                            updated_by = :updated_by
                        WHERE profile_id = :profile_id
                    ");
                    $updateStmt->execute([
                        ':blood_type'              => $input['blood_type'] !== '' ? $input['blood_type'] : null,
                        ':allergies_status'        => $input['allergies_status'],
                        ':allergies'               => $input['allergies'] !== '' ? $input['allergies'] : null,
                        ':conditions_status'       => $input['conditions_status'],
                        ':medical_conditions'      => $input['medical_conditions'] !== '' ? $input['medical_conditions'] : null,
                        ':current_medications'     => $input['current_medications'] !== '' ? $input['current_medications'] : null,
                        ':relevant_surgical_history' => $input['relevant_surgical_history'] !== '' ? $input['relevant_surgical_history'] : null,
                        ':relevant_family_medical_history' => $input['relevant_family_medical_history'] !== '' ? $input['relevant_family_medical_history'] : null,
                        ':disability_accommodation_info' => $input['disability_accommodation_info'] !== '' ? $input['disability_accommodation_info'] : null,
                        ':primary_physician'       => $input['primary_physician'] !== '' ? $input['primary_physician'] : null,
                        ':emergency_contact_name'  => $input['emergency_contact_name'] !== '' ? $input['emergency_contact_name'] : null,
                        ':emergency_contact_phone' => $input['emergency_contact_phone'] !== '' ? $input['emergency_contact_phone'] : null,
                        ':height_cm'               => $height,
                        ':weight_kg'               => $weight,
                        ':bmi'                     => $bmi,
                        ':smoking_status'          => $input['smoking_status'],
                        ':alcohol_use'             => $input['alcohol_use'],
                        ':fitness_for_duty'        => $input['fitness_for_duty'],
                        ':fitness_remarks'         => $input['fitness_remarks'] !== '' ? $input['fitness_remarks'] : null,
                        ':privacy_consent'         => $input['privacy_consent'],
                        ':consent_date'            => $input['consent_date'] !== '' ? $input['consent_date'] : null,
                        ':notes'                   => $input['notes'] !== '' ? $input['notes'] : null,
                        ':updated_by'              => $currentEmployeeId ?: null,
                        ':profile_id'              => $existingProfileId,
                    ]);
                    $profileId = (int) $existingProfileId;
                }

                $db->commit();

                eh_write_audit($db, eh_audit_context() + [
                    'action'             => $isNewProfile ? 'profile_create' : 'profile_update',
                    'entity_type'        => 'clinic_health_profiles',
                    'entity_id'          => $profileId,
                    'target_employee_id' => $selectedEmployeeId,
                    'outcome'            => 'allowed',
                    'description'        => ($isNewProfile ? 'Created' : 'Updated')
                        . ' employee health profile for employee ID ' . $selectedEmployeeId . '.',
                    'metadata'           => json_encode([
                        'changed_fields' => $isNewProfile ? array_keys($input) : eh_changed_fields($previousProfile, $input),
                    ]),
                ]);

                /* Post/Redirect/Get: a browser refresh must not resubmit the form. */
                if (!headers_sent()) {
                    $_SESSION['eh_profile_saved_for'] = $selectedEmployeeId;
                    $savedRedirect = '?page=employee-health&employee_id=' . $selectedEmployeeId
                        . ($searchTerm !== '' ? '&q=' . urlencode($searchTerm) : '')
                        . ($statusFilter !== '' ? '&employment_status=' . urlencode($statusFilter) : '')
                        . '&section=profile';
                    header('Location: ' . $savedRedirect, true, 303);
                    exit;
                }

                $profileSaved = true;
                $editMode = false;
                $profile = array_merge($profileDefaults, $input);
                $profile['profile_id'] = $profileId;
                $profile['height_cm'] = $input['height_cm'];
                $profile['weight_kg'] = $input['weight_kg'];
                $profile['bmi'] = $bmi;

                /* Refresh side-column data so it reflects the saved record. */
                if ($canViewMedical) {
                    $sideData = eh_side_data($db, $selectedEmployeeId);
                    $pendingRequirements = eh_pending_exam_requirements($db, $selectedEmployeeId);
                    [$healthRecords, $healthRecordErrors] = eh_load_health_records($db, $selectedEmployeeId);
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('EmployeeHealth profile save error: ' . $e->getMessage());
                $profileError = 'Unable to save the health profile. Please try again.';
                $editMode = true;
                eh_write_audit($db, eh_audit_context() + [
                    'action'             => 'profile_save_failed',
                    'entity_type'        => 'clinic_health_profiles',
                    'target_employee_id' => $selectedEmployeeId,
                    'outcome'            => 'denied',
                    'description'        => 'Health profile save failed due to a database error.',
                ]);
            }
        }
    }
}

/* ------------------------------------------------------------------ */
/* Presentation helpers                                                */
/* ------------------------------------------------------------------ */

function eh_selected(string $current, string $value): string
{
    return $current === $value ? ' selected' : '';
}

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

/**
 * Field-level error markup used beside form inputs.
 */
function eh_field_error(array $fieldErrors, string $key): string
{
    if (!isset($fieldErrors[$key])) {
        return '';
    }
    return '<p class="eh-field-error">' . htmlspecialchars((string) $fieldErrors[$key]) . '</p>';
}

/**
 * Read-only value cell: shows the saved value or an honest "not recorded".
 */
function eh_view_value($value, string $suffix = ''): string
{
    if ($value === null || $value === '') {
        return '<span class="eh-view-missing">Not recorded</span>';
    }
    return htmlspecialchars((string) $value) . ($suffix !== '' ? ' ' . htmlspecialchars($suffix) : '');
}

$completion = eh_profile_completion($profile);
$paginationBase = '?page=employee-health&q=' . urlencode($searchTerm)
    . ($statusFilter !== '' ? '&employment_status=' . urlencode($statusFilter) : '');
$profileBaseUrl = '?page=employee-health&employee_id=' . $selectedEmployeeId
    . ($searchTerm !== '' ? '&q=' . urlencode($searchTerm) : '')
    . ($statusFilter !== '' ? '&employment_status=' . urlencode($statusFilter) : '');
$allergySuggestions = [
    ['label'=>'Penicillin','category'=>'Medications'], ['label'=>'Amoxicillin','category'=>'Medications'], ['label'=>'Sulfonamide antibiotics','category'=>'Medications'], ['label'=>'Aspirin','category'=>'Medications'], ['label'=>'Ibuprofen','category'=>'Medications'],
    ['label'=>'Peanuts','category'=>'Food'], ['label'=>'Tree nuts','category'=>'Food'], ['label'=>'Shellfish','category'=>'Food'], ['label'=>'Fish','category'=>'Food'], ['label'=>'Milk','category'=>'Food'], ['label'=>'Eggs','category'=>'Food'], ['label'=>'Soy','category'=>'Food'], ['label'=>'Wheat','category'=>'Food'], ['label'=>'Sesame','category'=>'Food'],
    ['label'=>'Dust mites','category'=>'Environmental'], ['label'=>'Pollen','category'=>'Environmental'], ['label'=>'Mold','category'=>'Environmental'], ['label'=>'Bee venom','category'=>'Insects'], ['label'=>'Latex','category'=>'Materials'], ['label'=>'Nickel','category'=>'Materials'], ['label'=>'Other','category'=>'Other'],
];
$conditionSuggestions = array_map(static function (string $label): array { return ['label'=>$label]; }, [
    'Hypertension','Type 1 Diabetes Mellitus','Type 2 Diabetes Mellitus','Asthma','COPD','Coronary Artery Disease','Chronic Kidney Disease','Heart Failure','Hyperlipidemia','Hypothyroidism','Hyperthyroidism','Arthritis','Gout','Anemia','Epilepsy','GERD',
]);
$medicationSuggestions = array_map(static function (string $label): array { return ['label'=>$label]; }, [
    'Paracetamol','Ibuprofen','Amoxicillin','Metformin','Losartan','Amlodipine','Atorvastatin','Omeprazole','Salbutamol','Cetirizine','Metoprolol','Aspirin','Levothyroxine',
]);
$autocompleteFields = [
    [
        'key'=>'allergies','label'=>'Allergies','search_id'=>'ehAllergiesSearch','value_id'=>'ehAllergies','status_id'=>'ehAllergiesStatus','status_name'=>'allergies_status','status_value'=>$profile['allergies_status'] ?? 'not_recorded',
        'checkbox_id'=>'ehNoKnownAllergies','checkbox_name'=>'no_known_allergies','checkbox_label'=>'No known allergies (confirmed)','checked'=>$noKnownAllergies,
        'placeholder'=>'Search or add allergy...','initial'=>eh_multi_values($profile['allergies'] ?? ''),'suggestions'=>$allergySuggestions,
    ],
    [
        'key'=>'conditions','label'=>'Medical conditions','search_id'=>'ehConditionsSearch','value_id'=>'ehConditions','status_id'=>'ehConditionsStatus','status_name'=>'conditions_status','status_value'=>$profile['conditions_status'] ?? 'not_recorded',
        'checkbox_id'=>'ehNoKnownConditions','checkbox_name'=>'no_known_conditions','checkbox_label'=>'No known medical conditions (confirmed)','checked'=>$noKnownConditions,
        'placeholder'=>'Search or add condition...','initial'=>eh_multi_values($profile['medical_conditions'] ?? ''),'suggestions'=>$conditionSuggestions,
    ],
    [
        'key'=>'medications','label'=>'Current medications','search_id'=>'ehMedicationsSearch','value_id'=>'ehMedications','status_id'=>null,'status_name'=>null,'status_value'=>null,
        'checkbox_id'=>null,'checkbox_name'=>null,'checkbox_label'=>null,'checked'=>false,
        'placeholder'=>'Search or add medication...','initial'=>eh_multi_values($profile['current_medications'] ?? ''),'suggestions'=>$medicationSuggestions,
    ],
];
$surgicalHistorySuggestions = array_map(static function (string $label): array {
    return ['label' => $label, 'category' => 'Surgical history'];
}, [
    'Appendectomy', 'Cholecystectomy', 'Cesarean section', 'Hysterectomy', 'Hernia repair',
    'Tonsillectomy', 'Thyroidectomy', 'Coronary artery bypass graft', 'Joint replacement',
    'Fracture repair', 'Other surgery',
]);
$familyRelationships = ['Mother', 'Father', 'Sibling', 'Child', 'Maternal grandparent', 'Paternal grandparent', 'Other relative'];
$familyConditions = ['Hypertension', 'Type 2 Diabetes Mellitus', 'Heart disease', 'Stroke', 'Cancer', 'Asthma', 'Chronic kidney disease', 'Thyroid disease', 'Other condition'];
$familyHistorySuggestions = [];
foreach ($familyRelationships as $relationship) {
    foreach ($familyConditions as $condition) {
        $familyHistorySuggestions[] = ['label' => $relationship . ' — ' . $condition, 'category' => 'Family history'];
    }
}
$accommodationSuggestions = [
    ['label'=>'Wheelchair access','category'=>'Mobility and physical access'],
    ['label'=>'Mobility assistance','category'=>'Mobility and physical access'],
    ['label'=>'Accessible examination space','category'=>'Mobility and physical access'],
    ['label'=>'Assistance transferring to an examination table','category'=>'Mobility and physical access'],
    ['label'=>'Sign-language interpreter','category'=>'Communication'],
    ['label'=>'Written communication','category'=>'Communication'],
    ['label'=>'Hearing assistance','category'=>'Communication'],
    ['label'=>'Communication assistance','category'=>'Communication'],
    ['label'=>'Large-print materials','category'=>'Vision'],
    ['label'=>'Visual assistance','category'=>'Vision'],
    ['label'=>'Additional processing time','category'=>'Cognitive and other accommodations'],
    ['label'=>'Support with instructions','category'=>'Cognitive and other accommodations'],
    ['label'=>'Other accommodation','category'=>'Cognitive and other accommodations'],
];
$historyAutocompleteFields = [];
foreach ([
    ['key'=>'surgical-history','label'=>'Relevant surgical history','search_id'=>'ehSurgicalHistorySearch','value_id'=>'ehSurgicalHistory','notes_id'=>'ehSurgicalHistoryNotes','field_name'=>'relevant_surgical_history','placeholder'=>'Search or add a procedure or event...','notes_label'=>'Additional surgical-history notes (optional)','initial_value'=>$profile['relevant_surgical_history'] ?? '','suggestions'=>$surgicalHistorySuggestions],
    ['key'=>'family-history','label'=>'Relevant family medical history','search_id'=>'ehFamilyHistorySearch','value_id'=>'ehFamilyHistory','notes_id'=>'ehFamilyHistoryNotes','field_name'=>'relevant_family_medical_history','placeholder'=>'Search relationship and condition, or add a custom entry...','notes_label'=>'Additional family-history notes (optional)','initial_value'=>$profile['relevant_family_medical_history'] ?? '','suggestions'=>$familyHistorySuggestions],
    ['key'=>'accommodation','label'=>'Disability or accommodation information (if relevant)','search_id'=>'ehAccommodationSearch','value_id'=>'ehAccommodation','notes_id'=>'ehAccommodationNotes','field_name'=>'disability_accommodation_info','placeholder'=>'Search or add an accommodation...','notes_label'=>'Specific accommodation instructions (optional)','initial_value'=>$profile['disability_accommodation_info'] ?? '','suggestions'=>$accommodationSuggestions],
] as $field) {
    $field['history_data'] = eh_history_data($field['initial_value']);
    $historyAutocompleteFields[] = $field;
}
?>

<?php
$ehViewFile = __DIR__ . '/../views/employee-health/' . ($selectedEmployee ? 'profile.php' : 'search.php');
if (is_file($ehViewFile)) {
    include $ehViewFile;
} else {
    error_log('EmployeeHealth view missing: ' . $ehViewFile);
    echo '<div class="eh-notice eh-notice--muted" role="alert">This page is temporarily unavailable. Please contact the system administrator.</div>';
}
?>
