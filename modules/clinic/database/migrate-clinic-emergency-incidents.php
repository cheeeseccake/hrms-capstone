<?php
declare(strict_types=1);

/**
 * Adds a source link and structured emergency-visit details to Compliance
 * incident reports. Existing incident data is left unchanged.
 *
 * Run: php modules/clinic/database/migrate-clinic-emergency-incidents.php
 */
require_once __DIR__ . '/../../../database/db.php';
require_once __DIR__ . '/../lib/services/ClinicEmergencyIncidentSync.php';

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    $tableCheck = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'lc_incident_report'
    ");
    $tableCheck->execute();
    if ((int) $tableCheck->fetchColumn() === 0) {
        throw new RuntimeException('Compliance table lc_incident_report does not exist.');
    }

    $columns = $db->query('SHOW COLUMNS FROM lc_incident_report')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('source_clinic_visit_id', $columns, true)) {
        $db->exec('ALTER TABLE lc_incident_report ADD COLUMN source_clinic_visit_id BIGINT UNSIGNED NULL AFTER incident_id');
    }
    if (!in_array('clinic_details_payload', $columns, true)) {
        $db->exec('ALTER TABLE lc_incident_report ADD COLUMN clinic_details_payload MEDIUMTEXT NULL AFTER description');
    }

    $indexCheck = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'lc_incident_report'
          AND index_name = 'uq_lc_incident_report_clinic_visit'
    ");
    $indexCheck->execute();
    if ((int) $indexCheck->fetchColumn() === 0) {
        $db->exec('ALTER TABLE lc_incident_report ADD UNIQUE KEY uq_lc_incident_report_clinic_visit (source_clinic_visit_id)');
    }

    $emergencyVisits = $db->query("
        SELECT v.visit_id, v.visit_at, v.reason, v.details_payload,
               e.employee_code, e.first_name, e.middle_name, e.last_name, e.suffix,
               d.department_name
        FROM clinic_visits v
        INNER JOIN em_employees e ON e.employee_id = v.employee_id
        LEFT JOIN em_departments d ON d.department_id = e.department_id
        WHERE v.visit_type = 'emergency'
        ORDER BY v.visit_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($emergencyVisits as $visit) {
        $nameParts = array_filter([
            trim((string) ($visit['first_name'] ?? '')),
            trim((string) ($visit['middle_name'] ?? '')),
            trim((string) ($visit['last_name'] ?? '')),
        ], static fn(string $part): bool => $part !== '');
        $employeeName = implode(' ', $nameParts);
        $suffix = trim((string) ($visit['suffix'] ?? ''));
        if ($suffix !== '') {
            $employeeName .= ', ' . $suffix;
        }
        $details = json_decode((string) ($visit['details_payload'] ?? ''), true);
        ClinicEmergencyIncidentSync::sync(
            $db,
            (int) $visit['visit_id'],
            (string) $visit['visit_at'],
            (string) $visit['reason'],
            is_array($details) ? $details : [],
            $employeeName,
            (string) ($visit['employee_code'] ?? ''),
            (string) ($visit['department_name'] ?? '')
        );
    }

    echo 'Clinic emergency incident columns and link are ready. Synchronized '
        . count($emergencyVisits) . " emergency visit(s).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Clinic emergency incident migration failed: ' . $error->getMessage() . "\n");
    exit(1);
}
