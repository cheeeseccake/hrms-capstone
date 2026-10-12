<?php
/**
 * Add employee-linked clinic visits and private visit document metadata.
 * Additive and idempotent; existing health profile data is untouched.
 * Run: php modules/clinic/database/migrate-clinic-visits.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../../database/db.php';

try {
    $db = (new Database())->getConnection();
    $db->exec("
        CREATE TABLE IF NOT EXISTS clinic_visits (
            visit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            visit_type ENUM('consultation','emergency','return_to_work') NOT NULL,
            visit_at DATETIME NOT NULL,
            reason VARCHAR(500) NOT NULL,
            details_payload MEDIUMTEXT NOT NULL,
            request_token CHAR(64) NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_clinic_visits_request_token (request_token),
            KEY idx_clinic_visits_employee_date (employee_id, visit_at),
            KEY idx_clinic_visits_type (visit_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS clinic_visit_documents (
            document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            visit_id BIGINT UNSIGNED NOT NULL,
            employee_id INT NOT NULL,
            document_type ENUM('medical_certificate','fit_to_work') NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            storage_key CHAR(64) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT UNSIGNED NOT NULL,
            uploaded_by INT NULL,
            uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_clinic_visit_document_type (visit_id, document_type),
            UNIQUE KEY uq_clinic_visit_document_storage_key (storage_key),
            KEY idx_clinic_visit_documents_employee (employee_id),
            CONSTRAINT fk_clinic_visit_documents_visit
                FOREIGN KEY (visit_id) REFERENCES clinic_visits (visit_id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Clinic visit tables are ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Clinic visit migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
