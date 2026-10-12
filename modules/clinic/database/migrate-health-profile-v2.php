<?php
/**
 * Employee Health — Health Profile schema migration (v2)
 *
 * Adds the columns and tables required by the 17-section health profile.
 * The script is IDEMPOTENT and NON-DESTRUCTIVE: it only creates missing
 * tables and adds missing columns. It never drops or rewrites data.
 *
 * Run:  php modules/clinic/database/migrate-health-profile-v2.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../../database/db.php';

try {
    $db = (new Database())->getConnection();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(1);
}

function column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

$addedColumns = 0;
$createdTables = 0;

/* ------------------------------------------------------------------ */
/* 1. Extend clinic_health_profiles with the single-value sections     */
/* ------------------------------------------------------------------ */

$profileColumns = [
    /* Section 1 — General health information */
    'blood_pressure'           => "VARCHAR(20) NULL DEFAULT NULL AFTER blood_type",
    'heart_rate'               => "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER blood_pressure",
    'respiratory_rate'         => "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER heart_rate",
    'body_temperature'         => "DECIMAL(4,1) NULL DEFAULT NULL AFTER respiratory_rate",
    'oxygen_saturation'        => "DECIMAL(4,1) NULL DEFAULT NULL AFTER body_temperature",
    'physical_activity_level'  => "ENUM('not_recorded','sedentary','light','moderate','active','athlete') NOT NULL DEFAULT 'not_recorded' AFTER oxygen_saturation",
    'last_assessment_date'     => "DATE NULL DEFAULT NULL AFTER physical_activity_level",

    /* Relevant medical history (restricted employee health information) */
    'relevant_surgical_history'       => "TEXT NULL DEFAULT NULL AFTER current_medications",
    'relevant_family_medical_history' => "TEXT NULL DEFAULT NULL AFTER relevant_surgical_history",
    'disability_accommodation_info'   => "TEXT NULL DEFAULT NULL AFTER relevant_family_medical_history",
    'primary_physician'               => "VARCHAR(160) NULL DEFAULT NULL AFTER disability_accommodation_info",

    /* Section 8 — Emergency contact */
    'emergency_contact_relationship' => "VARCHAR(80) NULL DEFAULT NULL AFTER emergency_contact_phone",
    'emergency_contact_phone2'       => "VARCHAR(40) NULL DEFAULT NULL AFTER emergency_contact_relationship",
    'emergency_contact_email'        => "VARCHAR(160) NULL DEFAULT NULL AFTER emergency_contact_phone2",
    'emergency_contact_address'      => "VARCHAR(255) NULL DEFAULT NULL AFTER emergency_contact_email",
    'emergency_contact_is_primary'   => "TINYINT(1) NOT NULL DEFAULT 1 AFTER emergency_contact_address",
    'emergency_contact_additional'   => "VARCHAR(255) NULL DEFAULT NULL AFTER emergency_contact_is_primary",

    /* Section 9 — Work-related medical fitness */
    'fitness_assessed_date'    => "DATE NULL DEFAULT NULL AFTER fitness_remarks",
    'fitness_valid_until'      => "DATE NULL DEFAULT NULL AFTER fitness_assessed_date",
    'fitness_assessed_by'      => "VARCHAR(160) NULL DEFAULT NULL AFTER fitness_valid_until",
    'work_restrictions'        => "TEXT NULL AFTER fitness_assessed_by",
    'workplace_adjustments'    => "TEXT NULL AFTER work_restrictions",
    'restricted_duties'        => "TEXT NULL AFTER workplace_adjustments",
    'fitness_follow_up_date'   => "DATE NULL DEFAULT NULL AFTER restricted_duties",
    'return_to_work_status'    => "ENUM('not_applicable','pending_clearance','cleared','cleared_with_restrictions','not_cleared') NOT NULL DEFAULT 'not_applicable' AFTER fitness_follow_up_date",
    'clearance_date'           => "DATE NULL DEFAULT NULL AFTER return_to_work_status",
    'occupational_notes'       => "TEXT NULL AFTER clearance_date",

    /* Section 10 — Occupational health information */
    'job_health_risks'         => "TEXT NULL AFTER occupational_notes",
    'exposure_history'         => "TEXT NULL AFTER job_health_risks",
    'workplace_injury_history' => "TEXT NULL AFTER exposure_history",
    'work_related_conditions'  => "TEXT NULL AFTER workplace_injury_history",
    'ppe_requirements'         => "TEXT NULL AFTER work_related_conditions",
    'workplace_health_measures'=> "TEXT NULL AFTER ppe_requirements",
    'occupational_follow_up'   => "TEXT NULL AFTER workplace_health_measures",

    /* Section 9 — Health assessments */
    'assessment_date'                => "DATE NULL DEFAULT NULL AFTER assessment_type",
    'assessed_by'                    => "VARCHAR(160) NULL DEFAULT NULL AFTER assessment_date",
    'assessment_bp'                  => "VARCHAR(20) NULL DEFAULT NULL AFTER assessed_by",
    'assessment_hr'                  => "SMALLINT UNSIGNED NULL DEFAULT NULL AFTER assessment_bp",
    'assessment_temperature'         => "DECIMAL(4,1) NULL DEFAULT NULL AFTER assessment_hr",
    'assessment_weight_bmi'          => "VARCHAR(40) NULL DEFAULT NULL AFTER assessment_temperature",
    'vision_screening'               => "VARCHAR(120) NULL DEFAULT NULL AFTER assessment_weight_bmi",
    'hearing_screening'              => "VARCHAR(120) NULL DEFAULT NULL AFTER vision_screening",
    'other_screening'                => "TEXT NULL AFTER hearing_screening",
    'assessment_summary'             => "TEXT NULL AFTER other_screening",
    'recommendations'                => "TEXT NULL AFTER assessment_summary",
    'assessment_follow_up_required'  => "TINYINT(1) NOT NULL DEFAULT 0 AFTER recommendations",
    'assessment_follow_up_date'      => "DATE NULL DEFAULT NULL AFTER assessment_follow_up_required",

    /* Section 12 — Lifestyle and wellness */
    'dietary_considerations'   => "TEXT NULL AFTER assessment_follow_up_date",
    'exercise_habits'          => "TEXT NULL AFTER dietary_considerations",
    'sleep_concerns'           => "TEXT NULL AFTER exercise_habits",
    'stress_concerns'          => "TEXT NULL AFTER sleep_concerns",
    'lifestyle_factors'        => "TEXT NULL AFTER stress_concerns",
    'wellness_recommendations' => "TEXT NULL AFTER lifestyle_factors",

    /* Section 13 — Reproductive health (restricted) */
    'reproductive_health_info' => "TEXT NULL AFTER wellness_recommendations",
    'reproductive_care_notes'  => "TEXT NULL AFTER reproductive_health_info",
    'reproductive_follow_up'   => "TEXT NULL AFTER reproductive_care_notes",

    /* Section 16 — Consent, authorization and privacy */
    'exam_authorization'        => "TINYINT(1) NOT NULL DEFAULT 0 AFTER reproductive_follow_up",
    'exam_authorization_date'   => "DATE NULL DEFAULT NULL AFTER exam_authorization",
    'access_restriction_level'  => "ENUM('standard','restricted','confidential') NOT NULL DEFAULT 'standard' AFTER exam_authorization_date",
    'last_privacy_review_date'  => "DATE NULL DEFAULT NULL AFTER access_restriction_level",

    /* Section 17 — Health profile record management */
    'profile_status'        => "ENUM('incomplete','complete','requires_review') NOT NULL DEFAULT 'incomplete' AFTER last_privacy_review_date",
    'last_reviewed_date'    => "DATE NULL DEFAULT NULL AFTER profile_status",
    'reviewed_by'           => "INT NULL DEFAULT NULL AFTER last_reviewed_date",
    'pending_verification'  => "TEXT NULL AFTER reviewed_by",
    'next_review_date'      => "DATE NULL DEFAULT NULL AFTER pending_verification",
];

foreach ($profileColumns as $column => $definition) {
    if (!column_exists($db, 'clinic_health_profiles', $column)) {
        $db->exec("ALTER TABLE clinic_health_profiles ADD COLUMN `$column` $definition");
        $addedColumns++;
        echo "added column: clinic_health_profiles.$column\n";
    }
}

/* ------------------------------------------------------------------ */
/* 2. Repeating-record tables                                          */
/* ------------------------------------------------------------------ */

$tables = [

    /* Section 2 — Allergies */
    "clinic_health_allergies" => "
        CREATE TABLE clinic_health_allergies (
            allergy_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            allergen_name VARCHAR(160) NOT NULL,
            allergy_type VARCHAR(80) NULL DEFAULT NULL,
            reaction TEXT NULL,
            severity ENUM('Mild','Moderate','Severe') NULL DEFAULT NULL,
            date_identified DATE NULL DEFAULT NULL,
            current_treatment VARCHAR(255) NULL DEFAULT NULL,
            additional_notes TEXT NULL,
            verified_by VARCHAR(160) NULL DEFAULT NULL,
            verification_date DATE NULL DEFAULT NULL,
            status ENUM('Active','Resolved','Suspected') NOT NULL DEFAULT 'Active',
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 3 — Medical conditions */
    "clinic_health_conditions" => "
        CREATE TABLE clinic_health_conditions (
            condition_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            condition_name VARCHAR(180) NOT NULL,
            condition_category VARCHAR(80) NULL DEFAULT NULL,
            current_status ENUM('Active','Resolved','Under Treatment') NOT NULL DEFAULT 'Active',
            date_diagnosed DATE NULL DEFAULT NULL,
            treating_physician VARCHAR(160) NULL DEFAULT NULL,
            current_treatment TEXT NULL,
            relevant_notes TEXT NULL,
            last_reviewed_date DATE NULL DEFAULT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id),
            INDEX idx_status (current_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 4 — Current medications */
    "clinic_health_medications" => "
        CREATE TABLE clinic_health_medications (
            medication_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            medication_name VARCHAR(180) NOT NULL,
            dosage VARCHAR(80) NULL DEFAULT NULL,
            frequency VARCHAR(80) NULL DEFAULT NULL,
            route VARCHAR(80) NULL DEFAULT NULL,
            purpose VARCHAR(255) NULL DEFAULT NULL,
            start_date DATE NULL DEFAULT NULL,
            end_date DATE NULL DEFAULT NULL,
            prescribing_physician VARCHAR(160) NULL DEFAULT NULL,
            medication_status ENUM('Active','Discontinued') NOT NULL DEFAULT 'Active',
            side_effects TEXT NULL,
            additional_notes TEXT NULL,
            last_reviewed_date DATE NULL DEFAULT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id),
            INDEX idx_status (medication_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 5 — Surgical and hospitalization history */
    "clinic_health_surgeries" => "
        CREATE TABLE clinic_health_surgeries (
            surgery_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            record_type ENUM('Surgery','Hospitalization') NOT NULL DEFAULT 'Surgery',
            procedure_name VARCHAR(180) NULL DEFAULT NULL,
            procedure_date DATE NULL DEFAULT NULL,
            hospital_facility VARCHAR(180) NULL DEFAULT NULL,
            reason TEXT NULL,
            relevant_notes TEXT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id),
            INDEX idx_type (record_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 6 — Family medical history */
    "clinic_health_family_history" => "
        CREATE TABLE clinic_health_family_history (
            family_history_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            family_member VARCHAR(120) NULL DEFAULT NULL,
            relationship VARCHAR(80) NULL DEFAULT NULL,
            medical_condition VARCHAR(180) NOT NULL,
            age_at_diagnosis SMALLINT UNSIGNED NULL DEFAULT NULL,
            relevant_notes TEXT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 7 — Immunization history reuses the existing clinic_immunizations
       table (vaccine_name, dose_number, administration_date, next_dose_due,
       status, administering_provider, notes already cover the required fields). */

    /* Section 14 — Medical alerts and special precautions */
    "clinic_health_alerts" => "
        CREATE TABLE clinic_health_alerts (
            alert_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            alert_status ENUM('Active','Resolved') NOT NULL DEFAULT 'Active',
            alert_type VARCHAR(80) NOT NULL,
            alert_description TEXT NULL,
            required_precautions TEXT NULL,
            emergency_instructions TEXT NULL,
            date_verified DATE NULL DEFAULT NULL,
            verified_by VARCHAR(160) NULL DEFAULT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id),
            INDEX idx_status (alert_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 15 — Clinical notes */
    "clinic_health_clinical_notes" => "
        CREATE TABLE clinic_health_clinical_notes (
            note_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            note_date DATE NOT NULL,
            note_category VARCHAR(80) NULL DEFAULT NULL,
            clinical_note TEXT NOT NULL,
            recorded_by VARCHAR(160) NULL DEFAULT NULL,
            follow_up_instructions TEXT NULL,
            next_review_date DATE NULL DEFAULT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id),
            INDEX idx_date (note_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",

    /* Section 16 — Authorized disclosure records */
    "clinic_health_disclosures" => "
        CREATE TABLE clinic_health_disclosures (
            disclosure_id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            disclosure_purpose VARCHAR(255) NOT NULL,
            information_disclosed TEXT NULL,
            recipient VARCHAR(180) NULL DEFAULT NULL,
            disclosure_date DATE NULL DEFAULT NULL,
            authorized_by VARCHAR(160) NULL DEFAULT NULL,
            authorization_date DATE NULL DEFAULT NULL,
            relevant_notes TEXT NULL,
            created_by INT NULL DEFAULT NULL,
            updated_by INT NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_employee (employee_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ",
];

foreach ($tables as $name => $ddl) {
    if (!table_exists($db, $name)) {
        $db->exec($ddl);
        $createdTables++;
        echo "created table: $name\n";
    }
}

/* Columns added after the initial table creation (idempotent). */
$recordColumns = [
    'clinic_health_medications' => [
        'additional_notes' => "TEXT NULL AFTER side_effects",
    ],
];

foreach ($recordColumns as $table => $columns) {
    foreach ($columns as $column => $definition) {
        if (!column_exists($db, $table, $column)) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            $addedColumns++;
            echo "added column: $table.$column\n";
        }
    }
}

echo "\nMigration complete: $createdTables table(s) created, $addedColumns column(s) added.\n";
