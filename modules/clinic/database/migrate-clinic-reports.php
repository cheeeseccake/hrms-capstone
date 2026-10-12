<?php
/**
 * Create the clinic generated-report history table.
 * Run: php modules/clinic/database/migrate-clinic-reports.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../../database/db.php';

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('The database connection is unavailable.');
    }

    $db->exec("
        CREATE TABLE IF NOT EXISTS clinic_report_exports (
            export_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            report_key VARCHAR(40) NOT NULL,
            report_title VARCHAR(120) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            personnel_type VARCHAR(40) NOT NULL DEFAULT '',
            format VARCHAR(20) NOT NULL DEFAULT 'Preview',
            report_data LONGTEXT NOT NULL,
            generated_by INT NULL,
            generated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_clinic_report_exports_generated (generated_at),
            KEY idx_clinic_report_exports_report (report_key, generated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Clinic generated-report history is ready.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Clinic reports migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
