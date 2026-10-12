<?php
/**
 * Insert additional sample medicines without changing existing inventory.
 * Safe to rerun; a medicine name and strength pair is inserted only once.
 * Run: php modules/clinic/database/seed-clinic-medicine-samples.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../../../database/db.php';

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('The database connection is unavailable.');
    }

    $samples = [
        ['Aspirin', 'Acetylsalicylic acid', 'Analgesic', 'Tablet', '81 mg', 120, 20, '2028-04-30', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Available'],
        ['Naproxen', 'Naproxen', 'Analgesic', 'Tablet', '250 mg', 80, 15, '2028-06-30', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Available'],
        ['Loperamide', 'Loperamide hydrochloride', 'Gastrointestinal', 'Capsule', '2 mg', 45, 10, '2027-12-31', 'Sample Clinic Stock', 'Store below 30 C', 'Available'],
        ['Diphenhydramine', 'Diphenhydramine hydrochloride', 'Antihistamine', 'Tablet', '25 mg', 60, 12, '2028-02-29', 'Sample Clinic Stock', 'Protect from light', 'Available'],
        ['Calcium Carbonate', 'Calcium carbonate', 'Antacid', 'Tablet', '500 mg', 90, 15, '2028-08-31', 'Sample Clinic Stock', 'Store in a dry cabinet', 'Available'],
        ['Multivitamin', 'Multivitamins', 'Supplement', 'Tablet', 'Adult', 100, 20, '2027-11-30', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Available'],
        ['Hydrocortisone', 'Hydrocortisone', 'Topical', 'Ointment', '1%', 18, 5, '2027-10-31', 'Sample Clinic Stock', 'Store below 25 C', 'Available'],
        ['Povidone-Iodine', 'Povidone-iodine', 'Antiseptic', 'Liquid', '10%', 24, 6, '2027-09-30', 'Sample Clinic Stock', 'Keep container tightly closed', 'Available'],
        ['Chlorphenamine', 'Chlorpheniramine maleate', 'Antihistamine', 'Tablet', '4 mg', 50, 10, '2027-08-31', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Available'],
        ['Dextromethorphan', 'Dextromethorphan hydrobromide', 'Cough Suppressant', 'Liquid', '15 mg/5 mL', 16, 5, '2027-06-30', 'Sample Clinic Stock', 'Keep container tightly closed', 'Available'],
        ['Guaifenesin', 'Guaifenesin', 'Expectorant', 'Liquid', '100 mg/5 mL', 14, 5, '2027-07-31', 'Sample Clinic Stock', 'Keep container tightly closed', 'Available'],
        ['Zinc Sulfate', 'Zinc sulfate', 'Supplement', 'Tablet', '20 mg', 35, 10, '2027-12-31', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Available'],
        ['Mefenamic Acid', 'Mefenamic acid', 'Analgesic', 'Capsule', '250 mg', 7, 10, '2027-05-31', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Low Stock'],
        ['Sodium Chloride', 'Sodium chloride', 'Medical Solution', 'Liquid', '0.9%', 20, 5, '2027-10-31', 'Sample Clinic Stock', 'Keep sealed until use', 'Available'],
        ['Vitamin C', 'Ascorbic acid', 'Supplement', 'Tablet', '500 mg', 55, 12, '2028-01-31', 'Sample Clinic Stock', 'Store in a cool, dry cabinet', 'Available'],
    ];

    $statement = $db->prepare("
        INSERT INTO cm_medicine_inventory (
            medicine_name, generic_name, category, dosage_form, strength,
            current_stock, reorder_level, unit_cost, selling_price, expiry_date,
            manufacturer, storage_requirements, status
        )
        SELECT :name, :generic, :category, :form, :strength,
               :stock, :reorder, NULL, NULL, :expiry,
               :manufacturer, :storage, :status
        WHERE NOT EXISTS (
            SELECT 1 FROM cm_medicine_inventory
            WHERE medicine_name = :existing_name
              AND COALESCE(strength, '') = :existing_strength
        )
    ");

    $db->beginTransaction();
    $inserted = 0;
    foreach ($samples as $sample) {
        $statement->execute([
            ':name' => $sample[0],
            ':generic' => $sample[1],
            ':category' => $sample[2],
            ':form' => $sample[3],
            ':strength' => $sample[4],
            ':stock' => $sample[5],
            ':reorder' => $sample[6],
            ':expiry' => $sample[7],
            ':manufacturer' => $sample[8],
            ':storage' => $sample[9],
            ':status' => $sample[10],
            ':existing_name' => $sample[0],
            ':existing_strength' => $sample[4],
        ]);
        $inserted += $statement->rowCount();
    }
    $db->commit();

    $total = (int) $db->query('SELECT COUNT(*) FROM cm_medicine_inventory')->fetchColumn();
    echo "Inserted {$inserted} sample medicines. Total medicines in cm_medicine_inventory: {$total}.\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Sample medicine seed failed: " . $e->getMessage() . "\n");
    exit(1);
}
