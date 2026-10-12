<?php
$pageTitle = 'Inventory Management';
require_once __DIR__ . '/../lib/clinic-inventory.php';
require_once __DIR__ . '/../../../database/db.php';

$inventoryDb = (new Database())->getConnection();
if (!$inventoryDb instanceof PDO) {
    throw new RuntimeException('Clinic inventory database connection is unavailable.');
}
if (empty($_SESSION['eh_csrf_token'])) {
    $_SESSION['eh_csrf_token'] = bin2hex(random_bytes(32));
}
$inventoryItems = clinic_inventory_fetch($inventoryDb);
$inventoryStatusClass = static function (string $status): string {
    return match ($status) {
        'Available' => 'is-available',
        'Low Stock' => 'is-low',
        'Out of Stock' => 'is-out',
        'Expiring Soon' => 'is-expiring',
        'Expired' => 'is-expired',
        default => '',
    };
};

$inventoryTransactions = [];
try {
    $transactionsTableExists = $inventoryDb->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table');
    $transactionsTableExists->execute([':table' => 'cm_medicine_inventory_transactions']);
    if ((int) $transactionsTableExists->fetchColumn() > 0) {
        $inventoryTransactions = clinic_inventory_transactions($inventoryDb);
    }
} catch (Throwable $e) {
    error_log('Clinic inventory transaction history lookup failed: ' . $e->getMessage());
}

$inventorySeedJson = json_encode(['items' => $inventoryItems, 'transactions' => $inventoryTransactions], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

$expiringItems = array_values(array_filter($inventoryItems, static function (array $item): bool {
    return $item['expires'] !== '' && in_array($item['status'], ['Expiring Soon', 'Expired'], true);
}));

$inventoryCounts = [
    'total' => count($inventoryItems),
    'available' => count(array_filter($inventoryItems, static fn(array $item): bool => $item['status'] === 'Available')),
    'low' => count(array_filter($inventoryItems, static fn(array $item): bool => $item['status'] === 'Low Stock')),
    'expiring' => count(array_filter($inventoryItems, static fn(array $item): bool => $item['status'] === 'Expiring Soon')),
    'expired' => count(array_filter($inventoryItems, static fn(array $item): bool => $item['status'] === 'Expired')),
    'equipment' => 0,
];
$inventoryNotifications = [];
foreach ($inventoryItems as $item) {
    if ($item['status'] === 'Out of Stock') {
        $inventoryNotifications[] = ['out', 'Out of stock', $item['name'] . ' is out of stock. Please request replenishment.'];
    } elseif ($item['status'] === 'Low Stock') {
        $inventoryNotifications[] = ['low', 'Low stock warning', $item['name'] . ' has reached its minimum stock level. Restocking is recommended.'];
    } elseif ($item['status'] === 'Expiring Soon') {
        $inventoryNotifications[] = ['expiring', 'Expiration warning', $item['name'] . ' will expire within 30 days. Please review the remaining stock.'];
    }
}

?>
<section class="clinic-inventory" id="clinicInventoryRoot" data-inventory-seed="<?= htmlspecialchars((string) $inventorySeedJson, ENT_QUOTES, 'UTF-8') ?>" data-inventory-api="lib/api/clinic-inventory.php" data-csrf-token="<?= htmlspecialchars((string) $_SESSION['eh_csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <!-- ── 1. Inventory Dashboard ── -->
    <section class="clinic-inventory__section" aria-label="Inventory metrics">
        <div class="clinic-inventory__metrics">
            <button class="clinic-inventory__metric" type="button" data-inventory-metric data-category-filter="" data-status-filter="" aria-pressed="true" aria-controls="inventory-items"><span class="clinic-inventory__metric-icon"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></span><span class="clinic-inventory__metric-label">Total Medicines</span><strong><?= $inventoryCounts['total'] ?></strong><small>Registered clinic medicines</small></button>
            <button class="clinic-inventory__metric" type="button" data-inventory-metric data-category-filter="Medicine" data-status-filter="Available" aria-pressed="false" aria-controls="inventory-items"><span class="clinic-inventory__metric-icon"><i class="fa-solid fa-capsules" aria-hidden="true"></i></span><span class="clinic-inventory__metric-label">Available Medicines</span><strong><?= $inventoryCounts['available'] ?></strong><small>Medicines currently in stock</small></button>
            <button class="clinic-inventory__metric clinic-inventory__metric--amber" type="button" data-inventory-metric data-status-filter="Low Stock" aria-pressed="false" aria-controls="inventory-items"><span class="clinic-inventory__metric-icon"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span><span class="clinic-inventory__metric-label">Low Stock Items</span><strong><?= $inventoryCounts['low'] ?></strong><small>Reached the minimum stock level</small></button>
            <button class="clinic-inventory__metric clinic-inventory__metric--orange" type="button" data-inventory-metric data-status-filter="Expiring Soon" aria-pressed="false" aria-controls="inventory-items"><span class="clinic-inventory__metric-icon"><i class="fa-regular fa-clock" aria-hidden="true"></i></span><span class="clinic-inventory__metric-label">Expiring Soon</span><strong><?= $inventoryCounts['expiring'] ?></strong><small>Approaching their expiration dates</small></button>
            <button class="clinic-inventory__metric clinic-inventory__metric--red" type="button" data-inventory-metric data-status-filter="Expired" aria-pressed="false" aria-controls="inventory-items"><span class="clinic-inventory__metric-icon"><i class="fa-solid fa-ban" aria-hidden="true"></i></span><span class="clinic-inventory__metric-label">Expired Items</span><strong><?= $inventoryCounts['expired'] ?></strong><small>Already past expiration</small></button>
            <button class="clinic-inventory__metric" type="button" data-inventory-metric data-category-filter="Equipment" data-status-filter="" aria-pressed="false" aria-controls="inventory-items"><span class="clinic-inventory__metric-icon"><i class="fa-solid fa-stethoscope" aria-hidden="true"></i></span><span class="clinic-inventory__metric-label">Medical Equipment</span><strong><?= $inventoryCounts['equipment'] ?></strong><small>Tracked separately from medicine inventory</small></button>
        </div>
    </section>

    <div class="clinic-inventory__columns">
        <div class="clinic-inventory__main-column">
            <!-- ── Medical Supplies Inventory ── -->
            <section class="clinic-inventory__section" id="inventory-items" aria-labelledby="inventory-items-title">
        <div class="clinic-inventory__section-head">
            <div><h2 id="inventory-items-title">Medicine Inventory</h2><p>Live stock from clinic medicine records</p></div>
            <div class="clinic-inventory__section-actions">
                <button class="clinic-inventory__button clinic-inventory__button--primary" type="button" data-inventory-add><i class="fa-solid fa-plus" aria-hidden="true"></i> Add New Item</button>
                <button class="clinic-inventory__button" type="button" data-inventory-stock-in-open><i class="fa-solid fa-arrow-down-to-line" aria-hidden="true"></i> Stock In</button>
            </div>
        </div>
        <div class="clinic-inventory__filters"></div>
        <div class="clinic-inventory__table-wrap"><table class="clinic-inventory__table" data-inventory-table>
            <thead><tr><th>Item ID</th><th>Item Name</th><th>Category</th><th>Quantity</th><th>Expiration Date</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($inventoryItems as $item): ?>
                        <tr data-id="<?= htmlspecialchars($item['id']) ?>" data-medicine-id="<?= (int) $item['medicineId'] ?>" data-name="<?= htmlspecialchars($item['name']) ?>" data-category="<?= htmlspecialchars($item['category']) ?>" data-quantity="<?= (int) $item['quantity'] ?>" data-unit="<?= htmlspecialchars($item['unit']) ?>" data-minimum="<?= (int) $item['minimum'] ?>" data-expires="<?= htmlspecialchars($item['expires']) ?>" data-description="<?= htmlspecialchars($item['description']) ?>" data-supplier="<?= htmlspecialchars($item['supplier']) ?>" data-batch="<?= htmlspecialchars($item['batch']) ?>" data-storage="<?= htmlspecialchars($item['storage']) ?>" data-received="<?= htmlspecialchars($item['received']) ?>" data-status="<?= htmlspecialchars($item['status']) ?>" data-dosage-form="<?= htmlspecialchars($item['dosageForm']) ?>" data-strength="<?= htmlspecialchars($item['strength']) ?>" data-search="<?= htmlspecialchars(strtolower($item['id'] . ' ' . $item['name'] . ' ' . $item['category'])) ?>">
                            <td class="clinic-inventory__code"><?= htmlspecialchars($item['id']) ?></td>
                            <td><strong><?= htmlspecialchars($item['name'] . ($item['strength'] !== '' ? ' ' . $item['strength'] : '')) ?></strong><small>Minimum stock: <?= (int) $item['minimum'] ?> <?= htmlspecialchars($item['unit']) ?></small></td>
                            <td><?= htmlspecialchars($item['category']) ?></td>
                            <td><?= (int) $item['quantity'] ?> <span class="clinic-inventory__unit"><?= htmlspecialchars($item['unit']) ?></span></td>
                            <td><?= $item['expires'] !== '' ? htmlspecialchars(date('M j, Y', strtotime($item['expires']))) : '<span class="clinic-inventory__muted">Not applicable</span>' ?></td>
                            <td><span class="clinic-inventory__status <?= $inventoryStatusClass($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                        <td>
                            <div class="clinic-inventory__row-actions">
                                <button class="clinic-inventory__icon-btn" type="button" title="View" aria-label="View <?= htmlspecialchars($item['name']) ?>" data-inventory-view><i class="fa-solid fa-eye" aria-hidden="true"></i></button>
                                <button class="clinic-inventory__icon-btn" type="button" title="Edit" aria-label="Edit <?= htmlspecialchars($item['name']) ?>" data-inventory-edit><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr data-inventory-no-results hidden><td colspan="7">No inventory items match these filters.</td></tr>
            </tbody>
        </table></div>
        <nav class="clinic-inventory__pagination" data-inventory-pagination aria-label="Inventory list pages" hidden>
            <span class="clinic-inventory__pagination-info" data-inventory-page-info aria-live="polite"></span>
            <div class="clinic-inventory__pagination-controls">
                <button class="clinic-inventory__page-button" type="button" data-inventory-page-previous aria-label="Previous page">Previous</button>
                <div class="clinic-inventory__page-numbers" data-inventory-page-numbers></div>
                <button class="clinic-inventory__page-button" type="button" data-inventory-page-next aria-label="Next page">Next</button>
            </div>
        </nav>
            </section>

            <!-- ── Add New Inventory Item ── -->
            <section class="clinic-inventory__section" id="inventory-form" aria-labelledby="inventory-form-title" hidden>
        <div class="clinic-inventory__section-head"><div><h2 id="inventory-form-title" data-inventory-form-title>Add Medicine</h2><p>Register a medicine in the clinic inventory</p></div></div>
        <div class="clinic-inventory__flash clinic-inventory__flash--success" data-inventory-form-success role="status" hidden><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Inventory item has been successfully added.</div>
        <form class="clinic-inventory__form" data-inventory-item-form data-skip novalidate>
            <input type="hidden" name="mode" value="add" data-inventory-form-mode>
            <input type="hidden" name="editing_id" value="" data-inventory-editing-id>
            <div class="clinic-inventory__form-grid">
                <div class="clinic-inventory__field">
                    <label for="itemCode">Item ID</label>
                    <input type="text" id="itemCode" name="item_code" readonly placeholder="Automatically generated">
                    <small class="clinic-inventory__hint">Automatically generated inventory identification number.</small>
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemName">Item Name <span class="clinic-inventory__required">*</span></label>
                    <input type="text" id="itemName" name="item_name" maxlength="120" required placeholder="e.g. Paracetamol 500 mg">
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemCategory">Category <span class="clinic-inventory__required">*</span></label>
                    <select id="itemCategory" name="item_category" required>
                        <option value="">Select a category</option>
                        <option>Medicine</option>
                    </select>
                </div>
                <div class="clinic-inventory__field clinic-inventory__field--full">
                    <label for="itemDescription">Generic Name</label>
                    <textarea id="itemDescription" name="item_description" rows="2" maxlength="200" placeholder="Generic or active ingredient name"></textarea>
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemQuantity">Quantity <span class="clinic-inventory__required">*</span></label>
                    <input type="number" id="itemQuantity" name="item_quantity" min="0" step="1" required placeholder="0">
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemUnit">Unit</label>
                    <select id="itemUnit" name="item_unit">
                        <option>Tablets</option>
                        <option>Capsules</option>
                        <option>Bottles</option>
                        <option>Vials</option>
                        <option>Tubes</option>
                        <option>Units</option>
                    </select>
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemMinimum">Minimum Stock Level</label>
                    <input type="number" id="itemMinimum" name="item_minimum" min="0" step="1" placeholder="0">
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemExpires">Expiration Date</label>
                    <input type="date" id="itemExpires" name="item_expires">
                    <small class="clinic-inventory__hint">Leave blank if the item does not expire.</small>
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemSupplier">Supplier</label>
                    <input type="text" id="itemSupplier" name="item_supplier" maxlength="120" placeholder="Name of the supplier">
                </div>
                <div class="clinic-inventory__field">
                    <label for="itemStorage">Storage Location</label>
                    <input type="text" id="itemStorage" name="item_storage" maxlength="60" placeholder="Cabinet, shelf, or storage area">
                </div>
            </div>
            <div class="clinic-inventory__form-actions">
                <button class="clinic-inventory__button clinic-inventory__button--primary" type="submit" data-inventory-form-submit><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save Item</button>
                <button class="clinic-inventory__button" type="button" data-inventory-form-clear><i class="fa-solid fa-eraser" aria-hidden="true"></i> Clear Form</button>
                <button class="clinic-inventory__button clinic-inventory__button--ghost" type="button" data-inventory-form-cancel><i class="fa-solid fa-xmark" aria-hidden="true"></i> Cancel Form</button>
            </div>
        </form>
            </section>
        </div>

        <aside class="clinic-inventory__side-column" aria-label="Inventory activity and expiration">
            <section class="clinic-inventory__section" id="inventory-transactions" aria-labelledby="inventory-transactions-title">
                <div class="clinic-inventory__section-head"><div><h2 id="inventory-transactions-title">Transaction History</h2><p>Review incoming and outgoing medical supplies</p></div></div>
                <div class="clinic-inventory__table-wrap"><table class="clinic-inventory__table clinic-inventory__table--compact" data-inventory-transactions>
                    <thead><tr><th>Transaction ID</th><th>Item Name</th><th>Quantity</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($inventoryTransactions as $transaction): ?>
                            <tr>
                                <td class="clinic-inventory__code"><?= htmlspecialchars($transaction['id']) ?></td>
                                <td><?= htmlspecialchars($transaction['name']) ?></td>
                                <td><?= (int) $transaction['quantity'] > 0 ? '+' : '' ?><?= (int) $transaction['quantity'] ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($transaction['date']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </section>

            <section class="clinic-inventory__section" id="inventory-expiration" aria-labelledby="inventory-expiration-title">
                <div class="clinic-inventory__section-head"><div><h2 id="inventory-expiration-title">Medicine Expiration Tracking</h2><p>Prevent the use of expired medicines and medical supplies</p></div></div>
                <ul class="clinic-inventory__feature-list">
                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Automatic expiration date monitoring</li>
                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Notifications for medicines expiring within 30 days</li>
                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Identification of expired items</li>
                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Expiration status indicators</li>
                    <li><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Records of expired and disposed items</li>
                </ul>
                <div class="clinic-inventory__table-wrap"><table class="clinic-inventory__table clinic-inventory__table--compact" data-inventory-expiration-table>
                    <thead><tr><th>Medicine</th><th>Quantity</th><th>Expiration Date</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($expiringItems as $item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['name']) ?></strong></td>
                                <td><?= (int) $item['quantity'] ?></td>
                                <td><?= htmlspecialchars(date('M j, Y', strtotime($item['expires']))) ?></td>
                                <td><span class="clinic-inventory__status <?= $inventoryStatusClass($item['status']) ?>"><?= htmlspecialchars($item['status']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
                <p class="clinic-inventory__notice clinic-inventory__notice--warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Expired medicines must not be issued or administered. They should be separated from usable stock and disposed of according to applicable medical waste disposal guidelines.</p>
            </section>
        </aside>
    </div>

    <!-- ── Inventory Notifications ── -->
    <section class="clinic-inventory__section clinic-inventory__alert-stack" id="inventory-notifications" aria-labelledby="inventory-notifications-title" aria-live="polite">
        <h2 id="inventory-notifications-title" class="sr-only">Inventory Alerts and Notifications</h2>
        <ul class="clinic-inventory__notifications">
            <?php foreach ($inventoryNotifications as [$notificationType, $notificationTitle, $notificationMessage]): ?>
                <li class="clinic-inventory__notification clinic-inventory__notification--<?= htmlspecialchars($notificationType) ?>">
                    <span class="clinic-inventory__notification-icon" aria-hidden="true"><i class="fa-solid <?= $notificationType === 'out' ? 'fa-circle-xmark' : ($notificationType === 'low' ? 'fa-triangle-exclamation' : ($notificationType === 'expiring' ? 'fa-clock' : 'fa-circle-check')) ?>"></i></span>
                    <div>
                        <strong><?= htmlspecialchars($notificationTitle) ?></strong>
                        <p><?= htmlspecialchars($notificationMessage) ?></p>
                    </div>
                    <button class="clinic-inventory__icon-btn" type="button" title="Dismiss notification" aria-label="Dismiss notification" data-inventory-dismiss><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- ── Stock-in dialog ── -->
    <dialog class="clinic-inventory__dialog clinic-inventory__stock-dialog" data-inventory-stock-in-dialog aria-labelledby="stock-in-dialog-title">
        <div class="clinic-inventory__dialog-head">
            <div>
                <h3 id="stock-in-dialog-title"><i class="fa-solid fa-arrow-down-to-line" aria-hidden="true"></i> Stock In</h3>
                <p class="clinic-inventory__panel-note">Record newly received medicines and supplies.</p>
            </div>
            <button class="clinic-inventory__icon-btn" type="button" title="Close" aria-label="Close stock-in form" data-inventory-stock-in-close><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <form class="clinic-inventory__panel" data-inventory-stock-in data-skip novalidate>
            <div class="clinic-inventory__form-grid">
                <div class="clinic-inventory__field">
                    <label for="stockInItem">Item Name <span class="clinic-inventory__required">*</span></label>
                    <select id="stockInItem" name="item_name" required></select>
                </div>
                <div class="clinic-inventory__field">
                    <label for="stockInQuantity">Quantity Received <span class="clinic-inventory__required">*</span></label>
                    <input type="number" id="stockInQuantity" name="quantity" min="1" step="1" required placeholder="0">
                </div>
                <div class="clinic-inventory__field">
                    <label for="stockInDate">Date Received</label>
                    <input type="date" id="stockInDate" name="date">
                </div>
                <div class="clinic-inventory__field">
                    <label for="stockInSupplier">Supplier</label>
                    <input type="text" id="stockInSupplier" name="supplier" maxlength="120" placeholder="Name of the supplier">
                </div>
                <div class="clinic-inventory__field">
                    <label for="stockInBatch">Batch Number</label>
                    <input type="text" id="stockInBatch" name="batch" maxlength="60" placeholder="Product batch or lot number">
                </div>
                <div class="clinic-inventory__field">
                    <label for="stockInExpires">Expiration Date</label>
                    <input type="date" id="stockInExpires" name="expires">
                </div>
                <div class="clinic-inventory__field">
                    <label for="stockInBy">Received By</label>
                    <input type="text" id="stockInBy" name="personnel" maxlength="120" value="<?= htmlspecialchars((string) ($_SESSION['employee_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Name of the receiving personnel">
                </div>
                <div class="clinic-inventory__field clinic-inventory__field--full">
                    <label for="stockInRemarks">Remarks</label>
                    <textarea id="stockInRemarks" name="remarks" rows="2" maxlength="500" placeholder="Optional remarks about this delivery"></textarea>
                </div>
            </div>
            <div class="clinic-inventory__form-actions">
                <button class="clinic-inventory__button clinic-inventory__button--primary" type="submit"><i class="fa-solid fa-plus" aria-hidden="true"></i> Record Stock In</button>
            </div>
        </form>
    </dialog>

    <!-- ── Item details dialog ── -->
    <dialog class="clinic-inventory__dialog" data-inventory-dialog>
        <div class="clinic-inventory__dialog-head">
            <h3 data-inventory-dialog-title>Item Details</h3>
            <button class="clinic-inventory__icon-btn" type="button" title="Close" aria-label="Close item details" data-inventory-dialog-close><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <dl class="clinic-inventory__dialog-body" data-inventory-dialog-body></dl>
    </dialog>
</section>
