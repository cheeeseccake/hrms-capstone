<?php
/**
 * Clinic visit form markup.
 * Included from views/employee-health/profile.php when the current role may
 * record visits. The visit-type panels live in views/clinic-visit/, and the
 * behavior is loaded from js/pages/clinic-visit-form.js.
 *
 * @var int    $selectedEmployeeId        Employee being viewed.
 * @var array  $selectedEmployee          Employee registry row.
 * @var string $csrfToken                 Session CSRF token.
 * @var string $clinicVisitRequestToken   One-shot token for this form.
 * @var bool   $canRecordPhysicianAssessment
 * @var bool   $editMode
 * @var array  $clinicVisitSuggestions
 * @var array|null $lastVisit             Last visit data for pre-filling return-to-work.
 * @var array|null $visitEdit             Visit being edited, or null when recording a new one.
 */
$clinicVisitApi = 'lib/api/record-clinic-visit.php?employee_id=' . (int) $selectedEmployeeId;
$clinicVisitSuggestions = require __DIR__ . '/../config/clinic-visit-suggestions.php';
$clinicVisitPanels = __DIR__ . '/clinic-visit/';
$visitEditing = is_array($visitEdit ?? null);
$visitEditJson = $visitEditing
    ? json_encode([
        'visit_id' => (int) $visitEdit['visit_id'],
        'visit_type' => (string) $visitEdit['visit_type'],
        'visit_at' => (string) $visitEdit['visit_at'],
        'reason' => (string) $visitEdit['reason'],
        'details' => $visitEdit['details'],
        'documents' => $visitEdit['documents'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : '';

$lastVisitJson = $lastVisit !== null ? json_encode([
    'visit_id' => $lastVisit['visit_id'],
    'visit_type' => $lastVisit['visit_type'],
    'visit_at' => $lastVisit['visit_at'],
    'reason' => $lastVisit['reason'],
    'details' => $lastVisit['details'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : 'null';
require_once __DIR__ . '/../lib/clinic-inventory.php';
require_once __DIR__ . '/../../../database/db.php';
$visitInventoryDb = (new Database())->getConnection();
$clinicInventorySeedJson = json_encode(['items' => clinic_inventory_fetch($visitInventoryDb)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>
<section class="eh-card eh-visit-inline" data-eh-visit-panel aria-labelledby="ehVisitDialogTitle">
    <div class="eh-card-head"><h3 id="ehVisitDialogTitle"><?= $visitEditing ? 'Edit Clinic Visit' : 'Record Clinic Visit' ?></h3></div>
    <form id="ehVisitForm" class="eh-visit-form" data-eh-visit-form data-inventory-seed="<?= htmlspecialchars((string) $clinicInventorySeedJson, ENT_QUOTES, 'UTF-8') ?>" data-inventory-api="lib/api/clinic-inventory.php" data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>" data-skip action="<?= htmlspecialchars($clinicVisitApi) ?>" method="post" enctype="multipart/form-data" novalidate<?= $visitEditing ? ' data-visit-edit="' . htmlspecialchars($visitEditJson, ENT_QUOTES) . '"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="request_token" value="<?= htmlspecialchars($clinicVisitRequestToken) ?>">
        <?php if ($visitEditing): ?><input type="hidden" name="visit_id" value="<?= (int) $visitEdit['visit_id'] ?>"><?php endif; ?>
        <div class="eh-visit-error" data-eh-visit-error role="alert" aria-live="assertive" hidden></div>
        <div class="eh-visit-grid">
            <div class="eh-form-field">
                <label for="ehVisitType">Visit type <span aria-hidden="true">*</span></label>
                <select id="ehVisitType" name="visit_type" required><option value="consultation"<?= ($defaultVisitType ?? 'consultation') === 'consultation' ? ' selected' : '' ?>>Consultation</option><option value="emergency"<?= ($defaultVisitType ?? '') === 'emergency' ? ' selected' : '' ?>>Emergency</option><option value="return_to_work"<?= ($defaultVisitType ?? '') === 'return_to_work' ? ' selected' : '' ?>>Return to Work</option></select>
                <span class="eh-visit-field-error" data-field-error="visit_type"></span>
            </div>
            <div class="eh-form-field"><label for="ehVisitAt">Visit date and time <span aria-hidden="true">*</span></label><input id="ehVisitAt" name="visit_at" type="datetime-local" required><span class="eh-visit-field-error" data-field-error="visit_at"></span></div>
            <div class="eh-form-field eh-form-field--full"><label for="ehVisitReason" data-reason-label>Reason for visit / chief complaint <span aria-hidden="true">*</span></label><textarea id="ehVisitReason" name="reason" rows="2" maxlength="500" required><?= htmlspecialchars(($defaultVisitType ?? 'consultation') === 'return_to_work' ? ($previousChiefComplaint ?? '') : '') ?></textarea><div class="eh-visit-suggestions" data-suggestion-target="ehVisitReason" aria-label="Optional reason wording suggestions" hidden><?php foreach ($clinicVisitSuggestions['reason'] ?? [] as $suggestion): ?><button type="button"><?= htmlspecialchars($suggestion) ?></button><?php endforeach; ?><span class="eh-visit-suggestion-empty" hidden>No matches. You can keep typing your own text.</span></div><span class="eh-visit-field-error" data-field-error="reason"></span></div>
        </div>

        <?php include $clinicVisitPanels . 'consultation-panel.php'; ?>

        <?php include $clinicVisitPanels . 'emergency-panel.php'; ?>

        <?php include $clinicVisitPanels . 'return-to-work-panel.php'; ?>
    </form>
</section>
