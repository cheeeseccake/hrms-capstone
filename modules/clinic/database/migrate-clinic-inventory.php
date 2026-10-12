<?php
/**
 * Add a transaction ledger for the existing cm_medicine_inventory table.
 * Run: php modules/clinic/database/migrate-clinic-inventory.php
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
        CREATE TABLE IF NOT EXISTS cm_medicine_inventory_transactions (
            transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            medicine_id INT(10) NOT NULL,
            movement_type ENUM('stock_in', 'dispense', 'adjustment') NOT NULL,
            quantity_change INT NOT NULL,
            transaction_date DATE NOT NULL,
            personnel VARCHAR(160) NOT NULL,
            notes VARCHAR(500) NOT NULL DEFAULT '',
            visit_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cm_medicine_transactions_medicine_date (medicine_id, transaction_date),
            CONSTRAINT fk_cm_medicine_transactions_medicine
                FOREIGN KEY (medicine_id) REFERENCES cm_medicine_inventory (medicine_id)
                ON DELETE RESTRICT ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Medicine inventory transaction ledger is ready.\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Clinic inventory migration failed: " . $e->getMessage() . "\n");
    exit(1);
}
