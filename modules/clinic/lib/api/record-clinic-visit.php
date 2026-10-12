<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Cache-Control: no-store, private');

function eh_visit_json(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

require_once dirname(__DIR__, 4) . '/auth/session.php';
require_once dirname(__DIR__, 4) . '/database/db.php';
require_once __DIR__ . '/../services/ClinicEmergencyIncidentSync.php';

try {
    $db = (new Database())->getConnection();
} catch (Throwable $e) {
    error_log('Clinic visit connection error: ' . $e->getMessage());
    eh_visit_json(503, ['success' => false, 'message' => 'The clinic database is unavailable. Please try again.']);
}

$employeeId = (int) ($_GET['employee_id'] ?? 0);
$actorId = (int) ($_SESSION['employee_id'] ?? 0);
$roleId = (int) ($_SESSION['role_id'] ?? 0);
$roleName = trim((string) ($_SESSION['role_name'] ?? ''));
if ($roleName !== '') {
    try {
        $roleStmt = $db->prepare('SELECT role_id FROM em_roles WHERE role_name = :name LIMIT 1');
        $roleStmt->execute([':name' => $roleName]);
        $resolvedRoleId = (int) $roleStmt->fetchColumn();
        if ($resolvedRoleId > 0) $roleId = $resolvedRoleId;
    } catch (Throwable $e) {
        error_log('Clinic visit role lookup error: ' . $e->getMessage());
    }
}

$allowedRoleIds = [];
try {
    $settingsStmt = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
    $settingsStmt->execute();
    $allowedRoleIds = array_values(array_filter(array_map('intval', explode(',', (string) $settingsStmt->fetchColumn())), static fn(int $id): bool => $id > 0));
} catch (Throwable $e) {
    // Role-name fallback matches the employee health page when settings are unavailable.
}
$roleAllowed = $allowedRoleIds !== []
    ? in_array($roleId, $allowedRoleIds, true)
    : in_array($roleName, ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
if ($actorId <= 0 || !$roleAllowed) {
    eh_visit_json(403, ['success' => false, 'message' => 'You are not authorized to create or access clinic visits.']);
}
$physicianRoleIds = [];
try {
    $physicianRolesStmt = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'physician_assessment_role_ids' LIMIT 1");
    $physicianRolesStmt->execute();
    $physicianRoleIds = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $physicianRolesStmt->fetchColumn())), static fn(int $id): bool => $id > 0)));
} catch (Throwable $e) {
    // Physician-only fields stay unavailable until an administrator configures an explicit role allowlist.
}
$canRecordPhysicianAssessment = $physicianRoleIds !== [] && in_array($roleId, $physicianRoleIds, true);

if ($employeeId <= 0) {
    eh_visit_json(400, ['success' => false, 'message' => 'The employee profile context is missing. Reopen the employee profile and try again.']);
}
$employeeStmt = $db->prepare("
    SELECT e.employee_id, e.employee_code, e.first_name, e.middle_name, e.last_name,
           e.suffix, d.department_name
    FROM em_employees e
    LEFT JOIN em_departments d ON d.department_id = e.department_id
    WHERE e.employee_id = :id
    LIMIT 1
");
$employeeStmt->execute([':id' => $employeeId]);
$employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    eh_visit_json(404, ['success' => false, 'message' => 'The employee record could not be found.']);
}
$employeeName = trim(implode(' ', array_filter([
    (string) ($employee['first_name'] ?? ''),
    (string) ($employee['middle_name'] ?? ''),
    (string) ($employee['last_name'] ?? ''),
], static fn(string $part): bool => $part !== '')));
if (!empty($employee['suffix'])) {
    $employeeName .= ', ' . (string) $employee['suffix'];
}

function eh_visit_audit(PDO $db, int $actorId, int $employeeId, string $action, ?int $entityId, string $outcome, string $description): void
{
    try {
        $stmt = $db->prepare("INSERT INTO clinic_health_audit_logs
            (actor_employee_id, actor_role, action, entity_type, entity_id, target_employee_id, access_level, outcome, description, ip_address, user_agent, created_at)
            VALUES (:actor, :role, :action, 'clinic_visits', :entity, :target, 'medical', :outcome, :description, :ip, :agent, NOW())");
        $stmt->execute([
            ':actor' => $actorId, ':role' => (string) ($_SESSION['role_name'] ?? ''), ':action' => $action,
            ':entity' => $entityId, ':target' => $employeeId, ':outcome' => $outcome, ':description' => $description,
            ':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), ':agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    } catch (Throwable $e) {
        error_log('Clinic visit audit error: ' . $e->getMessage());
    }
}

function eh_visit_document_payload(PDO $db, int $visitId): array
{
    $stmt = $db->prepare('SELECT document_id, document_type, original_filename FROM clinic_visit_documents WHERE visit_id = :visit ORDER BY document_id');
    $stmt->execute([':visit' => $visitId]);
    return array_map(static function (array $document): array {
        return ['id' => (int) $document['document_id'], 'type' => (string) $document['document_type'], 'name' => (string) $document['original_filename']];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

$privateStorage = dirname(__DIR__, 6) . DIRECTORY_SEPARATOR . 'clinic-private' . DIRECTORY_SEPARATOR . 'visits';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $documentId = (int) ($_GET['document_id'] ?? 0);
    if ($documentId <= 0) eh_visit_json(400, ['success' => false, 'message' => 'Document not found.']);
    try {
        $documentStmt = $db->prepare('SELECT d.document_id, d.employee_id, d.original_filename, d.storage_key, d.mime_type, d.file_size FROM clinic_visit_documents d INNER JOIN clinic_visits v ON v.visit_id = d.visit_id AND v.employee_id = d.employee_id WHERE d.document_id = :id AND d.employee_id = :employee LIMIT 1');
        $documentStmt->execute([':id' => $documentId, ':employee' => $employeeId]);
        $document = $documentStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Clinic visit document lookup error: ' . $e->getMessage());
        eh_visit_json(503, ['success' => false, 'message' => 'Unable to retrieve this document.']);
    }
    if (!$document || !preg_match('/^[a-f0-9]{64}$/', (string) $document['storage_key'])) {
        eh_visit_json(404, ['success' => false, 'message' => 'Document not found.']);
    }
    $path = $privateStorage . DIRECTORY_SEPARATOR . $document['storage_key'];
    if (!is_file($path) || !is_readable($path)) eh_visit_json(404, ['success' => false, 'message' => 'The stored document is unavailable.']);
    eh_visit_audit($db, $actorId, $employeeId, 'visit_document_download', $documentId, 'allowed', 'Downloaded a protected clinic visit document.');
    $filename = preg_replace('/[^A-Za-z0-9._ -]/', '_', (string) $document['original_filename']) ?: 'clinic-document';
    header('Content-Type: ' . (string) $document['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    readfile($path);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: GET, POST');
    eh_visit_json(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$sessionCsrfToken = (string) ($_SESSION['eh_csrf_token'] ?? '');
$submittedCsrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($sessionCsrfToken === '' || $submittedCsrfToken === '' || !hash_equals($sessionCsrfToken, $submittedCsrfToken)) {
    eh_visit_json(419, ['success' => false, 'message' => 'Your session token has expired. Refresh the page and try again.']);
}

if (($_POST['action'] ?? '') === 'delete_visit') {
    $visitIdToDelete = (int) ($_POST['visit_id'] ?? 0);
    if ($visitIdToDelete <= 0) eh_visit_json(422, ['success' => false, 'message' => 'Select a valid visit to delete.']);
    try {
        $latestStmt = $db->prepare('SELECT visit_id FROM clinic_visits WHERE employee_id = :employee ORDER BY visit_at DESC, visit_id DESC LIMIT 1');
        $latestStmt->execute([':employee' => $employeeId]);
        $latestVisitId = (int) $latestStmt->fetchColumn();
        if ($visitIdToDelete === $latestVisitId) {
            eh_visit_json(409, ['success' => false, 'message' => 'The most recent visit cannot be deleted from this list.']);
        }

        $documentStmt = $db->prepare('SELECT storage_key FROM clinic_visit_documents WHERE employee_id = :employee AND visit_id = :visit');
        $documentStmt->execute([':employee' => $employeeId, ':visit' => $visitIdToDelete]);
        $storageKeys = $documentStmt->fetchAll(PDO::FETCH_COLUMN);

        $db->beginTransaction();
        $deleteStmt = $db->prepare('DELETE FROM clinic_visits WHERE employee_id = :employee AND visit_id = :visit');
        $deleteStmt->execute([':employee' => $employeeId, ':visit' => $visitIdToDelete]);
        if ($deleteStmt->rowCount() !== 1) {
            $db->rollBack();
            eh_visit_json(404, ['success' => false, 'message' => 'The visit could not be found.']);
        }
        $db->commit();

        foreach ($storageKeys as $storageKey) {
            if (!preg_match('/^[a-f0-9]{64}$/', (string) $storageKey)) continue;
            $storedPath = $privateStorage . DIRECTORY_SEPARATOR . $storageKey;
            if (is_file($storedPath) && !@unlink($storedPath)) {
                error_log('Clinic visit document cleanup failed for visit ID ' . $visitIdToDelete . '.');
            }
        }
        eh_visit_audit($db, $actorId, $employeeId, 'visit_delete', $visitIdToDelete, 'allowed', 'Deleted an older clinic visit record.');
        eh_visit_json(200, ['success' => true, 'message' => 'Clinic visit deleted.']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Clinic visit deletion error: ' . $e->getMessage());
        eh_visit_json(500, ['success' => false, 'message' => 'The clinic visit could not be deleted. Please retry.']);
    }
}

$visitType = (string) ($_POST['visit_type'] ?? '');
$validTypes = ['consultation', 'emergency', 'return_to_work'];
if (!in_array($visitType, $validTypes, true)) {
    eh_visit_json(422, ['success' => false, 'message' => 'Select a valid visit type.', 'field_errors' => ['visit_type' => 'Select a visit type.']]);
}
/* Editing an existing visit replaces its stored payload instead of adding a row. */
$editVisitId = (int) ($_POST['visit_id'] ?? 0);
$editVisit = null;
if ($editVisitId > 0) {
    try {
        $editVisitStmt = $db->prepare('SELECT visit_id, employee_id, visit_type FROM clinic_visits WHERE visit_id = :id LIMIT 1');
        $editVisitStmt->execute([':id' => $editVisitId]);
        $editVisit = $editVisitStmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Clinic visit edit lookup error: ' . $e->getMessage());
        eh_visit_json(503, ['success' => false, 'message' => 'The visit could not be loaded for editing. Please try again.']);
    }
    if (!$editVisit || (int) $editVisit['employee_id'] !== $employeeId) {
        eh_visit_json(404, ['success' => false, 'message' => 'The visit you are editing could not be found for this employee.']);
    }
}
$requestToken = (string) ($_POST['request_token'] ?? '');
if ($editVisitId <= 0 && !preg_match('/^[a-f0-9]{64}$/', $requestToken)) {
    eh_visit_json(422, ['success' => false, 'message' => 'Refresh the form before submitting.']);
}

// Return the existing result on a retry after a dropped network response.
// An edit always applies the submitted values, so the token shortcut is skipped.
if ($editVisitId <= 0) {
    try {
    $existingStmt = $db->prepare('SELECT visit_id, employee_id, visit_type, visit_at, reason, details_payload FROM clinic_visits WHERE request_token = :token LIMIT 1');
    $existingStmt->execute([':token' => $requestToken]);
} catch (Throwable $e) {
    error_log('Clinic visit request-token lookup error: ' . $e->getMessage());
    eh_visit_json(503, ['success' => false, 'message' => 'Clinic visit storage is not ready. Apply the clinic visits migration and retry.']);
}
$existingVisit = $existingStmt->fetch(PDO::FETCH_ASSOC);
if ($existingVisit) {
    if ((int) $existingVisit['employee_id'] !== $employeeId) {
        eh_visit_json(409, ['success' => false, 'message' => 'This submission token has already been used. Refresh the form and try again.']);
    }
    $existingDetails = json_decode((string) $existingVisit['details_payload'], true) ?: [];
    if ((string) $existingVisit['visit_type'] === 'emergency') {
        try {
            ClinicEmergencyIncidentSync::sync(
                $db,
                (int) $existingVisit['visit_id'],
                (string) $existingVisit['visit_at'],
                (string) $existingVisit['reason'],
                $existingDetails,
                $employeeName,
                (string) ($employee['employee_code'] ?? ''),
                (string) ($employee['department_name'] ?? '')
            );
        } catch (Throwable $e) {
            error_log('Clinic emergency incident retry sync error: ' . $e->getMessage());
            eh_visit_json(503, ['success' => false, 'message' => 'The clinic visit is saved, but its compliance incident could not be synchronized. Retry the submission or contact the system administrator.']);
        }
    }
    if (!$canRecordPhysicianAssessment) foreach (['physician_assessment','physician_name','physician_identifier','physician_assessment_at','physician_findings','physician_diagnosis','physician_plan','physician_advice','physician_followup','consult_work_restrictions'] as $restrictedField) unset($existingDetails[$restrictedField]);
    eh_visit_json(200, ['success' => true, 'message' => 'The clinic visit was already saved.', 'visit' => [
        'id' => (int) $existingVisit['visit_id'], 'type' => (string) $existingVisit['visit_type'],
        'date' => (string) $existingVisit['visit_at'], 'reason' => (string) $existingVisit['reason'],
        'details' => $existingDetails,
        'documents' => eh_visit_document_payload($db, (int) $existingVisit['visit_id']),
    ]]);
}
}

$errors = [];
$visitTimezone = new DateTimeZone('Asia/Manila');
$length = static function (string $value): int { return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value); };
$text = static function (string $name, int $max, bool $required = false) use (&$errors, $length): string {
    $value = trim((string) ($_POST[$name] ?? ''));
    if ($length($value) > $max) $errors[$name] = 'Use no more than ' . $max . ' characters.';
    elseif ($required && $value === '') $errors[$name] = 'This field is required.';
    return $value;
};
$selectedFields = [];
$visitAtInput = trim((string) ($_POST['visit_at'] ?? ''));
$visitAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $visitAtInput, $visitTimezone);
$dateErrors = DateTimeImmutable::getLastErrors();
if (!$visitAt || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) {
    $errors['visit_at'] = 'Enter a valid visit date and time.';
    $visitAtSql = (new DateTimeImmutable('now', $visitTimezone))->format('Y-m-d H:i:s');
} else {
    $visitAtSql = $visitAt->format('Y-m-d H:i:s');
}
$reason = $text('reason', 500, true);

$allowedByType = [
    'consultation' => ['employee_report','relevant_information','additional_notes','assistance_provided','advice_given','blood_pressure','pulse_rate','respiratory_rate','temperature','oxygen_saturation','height_cm','weight_kg','physician_assessment','physician_name','physician_identifier','physician_assessment_at','physician_findings','physician_diagnosis','physician_plan','physician_advice','physician_followup','rest_advised','rest_duration_preset','rest_duration_custom','rest_duration_unit','rest_start_date','rest_end_date','rest_instructions','rest_followup','work_advice','work_return_at','work_rest_notes','work_referral_destination','work_referral_notes','work_referral_followup','work_documentation_required','work_documentation_due','work_documentation_notes','consult_work_restrictions','work_advice_notes','referral','referral_destination','referral_details','outcome','followup_required','followup_date','followup_notes'],
    'emergency' => ['incident_at','incident_location','circumstances','accident_type','employee_report','first_aid','actions_taken','assistance_at','response_notes','blood_pressure','pulse_rate','respiratory_rate','temperature','oxygen_saturation','external_contacted','transfer_occurred','transfer_details','outcome','followup_required','followup_date','followup_instructions'],
    'return_to_work' => ['absence_start','absence_end'],
];
$limits = [
    'employee_report'=>5000, 'relevant_information'=>5000, 'additional_notes'=>5000, 'assistance_provided'=>5000,
    'advice_given'=>5000, 'referral_destination'=>255, 'referral_details'=>3000, 'outcome'=>1000,
    'followup_notes'=>3000, 'incident_location'=>255, 'circumstances'=>5000, 'first_aid'=>5000,
    'actions_taken'=>5000, 'response_notes'=>3000, 'blood_pressure'=>20, 'contact_person'=>255,
    'transfer_details'=>3000, 'followup_instructions'=>3000, 'documentation_notes'=>3000,
    'work_restrictions'=>5000, 'accommodations'=>5000, 'supporting_notes'=>5000,
    'physician_name'=>160, 'physician_identifier'=>100, 'physician_findings'=>5000, 'physician_diagnosis'=>3000,
    'physician_plan'=>5000, 'physician_advice'=>5000, 'physician_followup'=>3000, 'rest_instructions'=>3000, 'rest_followup'=>3000,
    'work_rest_notes'=>3000, 'work_referral_destination'=>255, 'work_referral_notes'=>3000, 'work_referral_followup'=>3000,
    'work_documentation_required'=>255, 'work_documentation_notes'=>3000, 'consult_work_restrictions'=>3000,
    'accident_type'=>255,
    'work_advice_notes'=>3000,
];
foreach ($allowedByType[$visitType] as $field) {
    if (in_array($field, ['physician_assessment_at','work_return_at'], true) || in_array($field, ['rest_start_date','rest_end_date','work_documentation_due'], true)) {
        $value = trim((string) ($_POST[$field] ?? ''));
        $dateTime = in_array($field, ['physician_assessment_at','work_return_at'], true);
        if ($value === '') $selectedFields[$field] = null;
        else {
            $format = $dateTime ? 'Y-m-d\\TH:i' : 'Y-m-d';
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value, $visitTimezone);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$parsed || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) $errors[$field] = 'Enter a valid date' . ($dateTime ? ' and time.' : '.');
            else $selectedFields[$field] = $parsed->format($dateTime ? 'Y-m-d H:i:s' : 'Y-m-d');
        }
        continue;
    }
    if (in_array($field, ['height_cm','weight_kg','rest_duration_custom'], true)) {
        $raw = trim((string) ($_POST[$field] ?? ''));
        if ($raw === '') $selectedFields[$field] = null;
        elseif (!is_numeric($raw)) $errors[$field] = 'Enter a valid number.';
        else {
            $number = (float) $raw;
            $range = ['height_cm'=>[30,260], 'weight_kg'=>[1,400], 'rest_duration_custom'=>[0.5,365]][$field];
            if ($number < $range[0] || $number > $range[1]) $errors[$field] = 'Enter a value from ' . $range[0] . ' to ' . $range[1] . '.';
            else $selectedFields[$field] = $number;
        }
        continue;
    }
    if (in_array($field, ['incident_at','assistance_at','absence_start','absence_end','expected_return_date','followup_date'], true)) {
        $value = trim((string) ($_POST[$field] ?? ''));
        if ($value !== '') {
            $format = $field === 'incident_at' || $field === 'assistance_at' ? 'Y-m-d\TH:i' : 'Y-m-d';
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $value, $visitTimezone);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$parsed || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count']))) $errors[$field] = 'Enter a valid date' . (str_contains($format, 'H') ? ' and time.' : '.');
            else $selectedFields[$field] = $parsed->format(str_contains($format, 'H') ? 'Y-m-d H:i:s' : 'Y-m-d');
        } else {
            $selectedFields[$field] = null;
        }
        continue;
    }
    if (in_array($field, ['pulse_rate','respiratory_rate','temperature','oxygen_saturation'], true)) {
        $raw = trim((string) ($_POST[$field] ?? ''));
        if ($raw !== '') {
            if (!is_numeric($raw)) $errors[$field] = 'Enter a valid number.';
            else {
                $number = (float) $raw;
                $range = ['pulse_rate'=>[1,300], 'respiratory_rate'=>[1,80], 'temperature'=>[25,45], 'oxygen_saturation'=>[0,100]][$field];
                if ($number < $range[0] || $number > $range[1]) $errors[$field] = 'Enter a value from ' . $range[0] . ' to ' . $range[1] . '.';
                else $selectedFields[$field] = $number;
            }
        } else $selectedFields[$field] = null;
        continue;
    }
    if (in_array($field, ['referral','followup_required','external_contacted','transfer_occurred'], true)) {
        $value = (string) ($_POST[$field] ?? 'no');
        if (!in_array($value, ['yes','no'], true)) $errors[$field] = 'Choose Yes or No.';
        $selectedFields[$field] = $value === 'yes';
        continue;
    }
    if ($field === 'documentation_status') {
        $value = (string) ($_POST[$field] ?? '');
        $options = ['documentation_received','awaiting_documentation','referred_for_assessment','other'];
        if (!in_array($value, $options, true)) $errors[$field] = 'Choose a documentation status.';
        else $selectedFields[$field] = $value;
        continue;
    }
    $enumOptions = [
        'physician_assessment'=>['no','yes'],
        'rest_advised'=>['not_specified','no','yes'],
        'rest_duration_preset'=>['','2_hours','4_hours','half_day','1_day','2_days','3_days','custom'],
        /* Empty is valid because the field only ships when rest is advised with
           a custom duration; the consultation branch below rejects it then. */
        'rest_duration_unit'=>['','hours','days'],
        'work_advice'=>['not_specified','usual','rest','assessment','documentation','restrictions','other'],
    ];
    if (isset($enumOptions[$field])) {
        $default = $field === 'physician_assessment' ? 'no' : (in_array($field, ['rest_advised','work_advice'], true) ? 'not_specified' : '');
        $value = trim((string) ($_POST[$field] ?? $default));
        if (!in_array($value, $enumOptions[$field], true)) $errors[$field] = 'Choose a valid option.';
        else $selectedFields[$field] = $value;
        continue;
    }
    $selectedFields[$field] = $text($field, $limits[$field] ?? 255);
}

if ($visitType === 'consultation') {
    $selectedFields['physician_assessment'] = $selectedFields['physician_assessment'] ?? 'no';
    $selectedFields['rest_advised'] = $selectedFields['rest_advised'] ?? 'not_specified';
    $selectedFields['work_advice'] = $selectedFields['work_advice'] ?? 'not_specified';
    if ($selectedFields['physician_assessment'] === 'yes') {
        if (!$canRecordPhysicianAssessment) $errors['physician_assessment'] = 'Your account is not authorized to record physician assessments.';
        else foreach (['physician_name'=>'Enter the assessing physician name.','physician_assessment_at'=>'Enter the assessment date and time.','physician_findings'=>'Enter the physician-documented assessment.'] as $requiredField => $message) {
            if (empty($selectedFields[$requiredField])) $errors[$requiredField] = $message;
        }
    } else {
        foreach (['physician_name','physician_identifier','physician_assessment_at','physician_findings','physician_diagnosis','physician_plan','physician_advice','physician_followup'] as $field) unset($selectedFields[$field]);
    }
    if ($selectedFields['rest_advised'] !== 'yes') {
        foreach (['rest_duration_preset','rest_duration_custom','rest_duration_unit','rest_start_date','rest_end_date','rest_instructions','rest_followup'] as $field) unset($selectedFields[$field]);
    } else {
        $preset = (string) ($selectedFields['rest_duration_preset'] ?? '');
        if ($preset === '') $errors['rest_duration_preset'] = 'Select a rest duration when rest is advised.';
        if ($preset === 'custom' && empty($selectedFields['rest_duration_custom'])) $errors['rest_duration_custom'] = 'Enter the custom rest duration.';
        if ($preset === 'custom' && empty($selectedFields['rest_duration_unit'])) $errors['rest_duration_unit'] = 'Choose hours or days.';
        if ($preset !== 'custom') unset($selectedFields['rest_duration_custom'], $selectedFields['rest_duration_unit']);
    }
    $workAdvice = $selectedFields['work_advice'];
    $workFieldsByAdvice = [
        'rest'=>['work_return_at','work_rest_notes'], 'assessment'=>['work_referral_destination','work_referral_notes','work_referral_followup'],
        'documentation'=>['work_documentation_required','work_documentation_due','work_documentation_notes'],
        'restrictions'=>['consult_work_restrictions'], 'other'=>['work_advice_notes'],
    ];
    foreach ($workFieldsByAdvice as $advice => $fields) if ($workAdvice !== $advice) foreach ($fields as $field) unset($selectedFields[$field]);
    if ($workAdvice === 'restrictions' && !$canRecordPhysicianAssessment) {
        unset($selectedFields['consult_work_restrictions']);
        $errors['work_advice'] = 'Only an authorized physician role may record documented work restrictions here.';
    }
    if (($selectedFields['rest_duration_preset'] ?? '') === 'custom' && isset($selectedFields['rest_duration_custom'], $selectedFields['rest_duration_unit'])) {
        $selectedFields['rest_duration'] = (float) $selectedFields['rest_duration_custom'];
    } elseif (($selectedFields['rest_duration_preset'] ?? '') !== '') {
        $durationLabels = ['2_hours'=>'2 hours','4_hours'=>'4 hours','half_day'=>'Half day','1_day'=>'1 day','2_days'=>'2 days','3_days'=>'3 days'];
        $selectedFields['rest_duration'] = $durationLabels[$selectedFields['rest_duration_preset']] ?? null;
    }
    unset($selectedFields['bmi']);
    if (!empty($selectedFields['height_cm']) && !empty($selectedFields['weight_kg'])) {
        $heightM = (float) $selectedFields['height_cm'] / 100;
        if ($heightM > 0) $selectedFields['bmi'] = round((float) $selectedFields['weight_kg'] / ($heightM * $heightM), 2);
    }
    if (!empty($selectedFields['rest_start_date']) && !empty($selectedFields['rest_end_date']) && $selectedFields['rest_end_date'] < $selectedFields['rest_start_date']) $errors['rest_end_date'] = 'The rest end date must be on or after the start date.';
    if (!empty($selectedFields['rest_start_date']) && $selectedFields['rest_start_date'] < substr($visitAtSql, 0, 10)) $errors['rest_start_date'] = 'The rest start date must be on or after the visit date.';
    if (!empty($selectedFields['work_documentation_due']) && $selectedFields['work_documentation_due'] < substr($visitAtSql, 0, 10)) $errors['work_documentation_due'] = 'The expected submission date must be on or after the visit date.';
    if (!empty($selectedFields['physician_assessment_at']) && $selectedFields['physician_assessment_at'] > $visitAtSql) $errors['physician_assessment_at'] = 'The physician assessment cannot be after the visit time.';
    if (!empty($selectedFields['work_return_at']) && $selectedFields['work_return_at'] < $visitAtSql) $errors['work_return_at'] = 'The recommended return time must be on or after the visit time.';
}

foreach (['referral' => ['referral_destination','referral_details'], 'external_contacted' => ['referral_destination','contact_person'], 'transfer_occurred' => ['transfer_details']] as $flag => $dependentFields) {
    if (empty($selectedFields[$flag])) foreach ($dependentFields as $dependentField) unset($selectedFields[$dependentField]);
}
if ($visitType === 'emergency' && ($selectedFields['blood_pressure'] ?? '') !== ''
    && !preg_match('/^\s*\d{2,3}\s*\/\s*\d{2,3}\s*$/', (string) $selectedFields['blood_pressure'])) {
    $errors['blood_pressure'] = 'Enter blood pressure as systolic/diastolic, for example 120/80.';
}
if ($visitType === 'consultation' && ($selectedFields['blood_pressure'] ?? '') !== ''
    && !preg_match('/^\s*\d{2,3}\s*\/\s*\d{2,3}\s*$/', (string) $selectedFields['blood_pressure'])) {
    $errors['blood_pressure'] = 'Enter blood pressure as systolic/diastolic, for example 120/80.';
}
if (in_array($visitType, ['consultation','emergency'], true) && preg_match('/^\s*(\d{2,3})\s*\/\s*(\d{2,3})\s*$/', (string) ($selectedFields['blood_pressure'] ?? ''), $bpParts)) {
    if ((int) $bpParts[1] < 30 || (int) $bpParts[1] > 300 || (int) $bpParts[2] < 20 || (int) $bpParts[2] > 200) {
        $errors['blood_pressure'] = 'Enter systolic and diastolic values within the supported input ranges.';
    }
}
if (empty($selectedFields['followup_required'])) {
    unset($selectedFields['followup_date'], $selectedFields['followup_notes']);
    if (isset($selectedFields['followup_instructions'])) unset($selectedFields['followup_instructions']);
}
if ($visitType === 'consultation' && !empty($selectedFields['referral']) && ($selectedFields['referral_destination'] ?? '') === '') {
    $errors['referral_destination'] = 'Add a referral destination when a referral is indicated.';
}
if ($visitType === 'emergency' && !empty($selectedFields['incident_at']) && $selectedFields['incident_at'] > $visitAtSql) {
    $errors['incident_at'] = 'The incident time cannot be after the visit time.';
}
if ($visitType === 'return_to_work') {
    $from = $selectedFields['absence_start'] ?? null;
    $until = $selectedFields['absence_end'] ?? null;
    if ($from && $until && $until < $from) $errors['absence_end'] = 'The absence end date must be on or after the start date.';
    $expectedReturn = $selectedFields['expected_return_date'] ?? null;
    if ($expectedReturn && $from && $expectedReturn < $from) $errors['expected_return_date'] = 'The return-to-work date must be on or after the absence start date.';
    if ($expectedReturn && $until && $expectedReturn < $until) $errors['expected_return_date'] = 'The return-to-work date must be on or after the absence end date.';
}
if (!empty($selectedFields['followup_required']) && empty($selectedFields['followup_date'])) {
    $errors['followup_date'] = 'Enter a follow-up date when follow-up is required.';
}
if (!empty($selectedFields['followup_date']) && $selectedFields['followup_date'] < substr($visitAtSql, 0, 10)) {
    $errors['followup_date'] = 'The follow-up date must be on or after the visit date.';
}

$fileFields = ['medical_certificate' => 'medical_certificate', 'fit_to_work' => 'fit_to_work'];
$filesToSave = [];
$maxFileSize = 10 * 1024 * 1024;
$allowedMime = ['application/pdf'=>'pdf', 'image/jpeg'=>'jpg', 'image/png'=>'png'];
if ($visitType !== 'return_to_work') {
    foreach ($fileFields as $field => $type) {
        if (isset($_FILES[$field]) && ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $errors[$field] = 'Certificate uploads are available only for Return to Work visits.';
        }
    }
} else {
    foreach ($fileFields as $field => $type) {
        $file = $_FILES[$field] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            $errors[$field] = 'The file could not be uploaded. Select it again and retry.';
            continue;
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > $maxFileSize) {
            $errors[$field] = 'Each certificate must be no larger than 10 MB.';
            continue;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']);
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mimeExtensions = ['application/pdf'=>['pdf'], 'image/jpeg'=>['jpg','jpeg'], 'image/png'=>['png']];
        if (!is_string($mime) || !isset($allowedMime[$mime]) || !in_array($extension, $mimeExtensions[$mime] ?? [], true)) {
            $errors[$field] = 'Use a PDF, JPG, JPEG, or PNG file.';
            continue;
        }
        $filesToSave[$field] = [
            'type' => $type, 'tmp' => (string) $file['tmp_name'], 'name' => basename((string) $file['name']),
            'mime' => $mime, 'extension' => $allowedMime[$mime], 'size' => (int) $file['size'],
        ];
    }
}

if ($errors !== []) {
    eh_visit_audit($db, $actorId, $employeeId, $editVisitId > 0 ? 'visit_update_rejected' : 'visit_create_rejected', $editVisitId > 0 ? $editVisitId : null, 'denied', 'Clinic visit validation failed.');
    eh_visit_json(422, ['success' => false, 'message' => 'Please correct the highlighted fields and try again.', 'field_errors' => $errors]);
}

$details = json_encode($selectedFields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
if (!is_string($details)) eh_visit_json(500, ['success' => false, 'message' => 'The visit details could not be prepared.']);
$newFiles = [];
$visitId = 0;
try {
    if ($filesToSave !== [] && !is_dir($privateStorage) && !mkdir($privateStorage, 0700, true) && !is_dir($privateStorage)) {
        throw new RuntimeException('Private document storage could not be created.');
    }
    $db->beginTransaction();
    if ($editVisitId > 0) {
        $update = $db->prepare('UPDATE clinic_visits SET visit_type = :type, visit_at = :visit_at, reason = :reason, details_payload = :details WHERE visit_id = :id AND employee_id = :employee');
        $update->execute([':type'=>$visitType, ':visit_at'=>$visitAtSql, ':reason'=>$reason, ':details'=>$details, ':id'=>$editVisitId, ':employee'=>$employeeId]);
        $visitId = $editVisitId;
    } else {
        $insert = $db->prepare('INSERT INTO clinic_visits (employee_id, visit_type, visit_at, reason, details_payload, request_token, created_by) VALUES (:employee, :type, :visit_at, :reason, :details, :token, :actor)');
        $insert->execute([':employee'=>$employeeId, ':type'=>$visitType, ':visit_at'=>$visitAtSql, ':reason'=>$reason, ':details'=>$details, ':token'=>$requestToken, ':actor'=>$actorId]);
        $visitId = (int) $db->lastInsertId();
    }

    if ($visitType === 'emergency') {
        ClinicEmergencyIncidentSync::sync(
            $db,
            $visitId,
            $visitAtSql,
            $reason,
            $selectedFields,
            $employeeName,
            (string) ($employee['employee_code'] ?? ''),
            (string) ($employee['department_name'] ?? '')
        );
    }

    foreach ($filesToSave as $file) {
        $storageKey = bin2hex(random_bytes(32));
        $path = $privateStorage . DIRECTORY_SEPARATOR . $storageKey;
        if (!move_uploaded_file($file['tmp'], $path)) throw new RuntimeException('A certificate could not be stored securely.');
        @chmod($path, 0600);
        $newFiles[] = $path;
        $docInsert = $db->prepare('INSERT INTO clinic_visit_documents (visit_id, employee_id, document_type, original_filename, storage_key, mime_type, file_size, uploaded_by) VALUES (:visit, :employee, :type, :name, :key, :mime, :size, :actor)');
        $docInsert->execute([':visit'=>$visitId, ':employee'=>$employeeId, ':type'=>$file['type'], ':name'=>substr($file['name'], 0, 255), ':key'=>$storageKey, ':mime'=>$file['mime'], ':size'=>$file['size'], ':actor'=>$actorId]);
    }
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    foreach ($newFiles as $path) if (is_file($path)) @unlink($path);
    error_log('Clinic visit save error: ' . $e->getMessage());
    eh_visit_audit($db, $actorId, $employeeId, $editVisitId > 0 ? 'visit_update_failed' : 'visit_create_failed', $visitId ?: null, 'error', 'Clinic visit could not be saved.');
    eh_visit_json(500, ['success' => false, 'message' => 'The visit could not be saved. Please retry; your form entries are still available.']);
}
if ($editVisitId > 0) {
    eh_visit_audit($db, $actorId, $employeeId, 'visit_update', $visitId, 'allowed', 'Updated a ' . $visitType . ' visit for employee ID ' . $employeeId . '.');
} else {
    eh_visit_audit($db, $actorId, $employeeId, 'visit_create', $visitId, 'allowed', 'Created a ' . $visitType . ' visit for employee ID ' . $employeeId . '.');
}

/* Carry the success message across the redirect to the employee health page. */
$_SESSION['eh_visit_saved_for'] = (int) $employeeId;

eh_visit_json($editVisitId > 0 ? 200 : 201, [
    'success' => true,
    'message' => $editVisitId > 0 ? 'Clinic visit updated successfully.' : 'Clinic visit recorded successfully.',
    'visit' => [
        'id' => $visitId, 'type' => $visitType, 'date' => $visitAtSql, 'reason' => $reason,
        'details' => $selectedFields,
        'documents' => eh_visit_document_payload($db, $visitId),
    ],
    'redirect' => '?page=employee-health&employee_id=' . (int) $employeeId . '&section=visits',
]);
