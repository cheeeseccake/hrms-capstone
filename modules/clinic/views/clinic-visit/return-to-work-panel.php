<?php
/**
 * Clinic visit form — Return-to-Work visit-type panel.
 * Included by views/clinic-visit-dialog.php.
 *
 * @var array|null $lastVisit    Previous visit data for pre-filling fields.
 */

$prev = $lastVisit ?? null;
$prevDetails = $prev !== null ? ($prev['details'] ?? []) : [];

$prevAbsenceStart = $previousRestStart !== '' ? $previousRestStart : ($prevDetails['absence_start'] ?? '');
$prevAbsenceEnd = $previousRestEnd !== '' ? $previousRestEnd : ($prevDetails['absence_end'] ?? '');
?>
<fieldset class="eh-visit-panel" data-visit-type="return_to_work" hidden disabled data-prev-reason="<?= htmlspecialchars($previousChiefComplaint ?? '') ?>" data-prev-absence-start="<?= htmlspecialchars($prevAbsenceStart) ?>" data-prev-absence-end="<?= htmlspecialchars($prevAbsenceEnd) ?>">
    <legend>Return to Work</legend>
    <div class="eh-visit-grid">
        <div class="eh-form-field"><label for="ehAbsenceStart">Absence start date</label><input id="ehAbsenceStart" name="absence_start" type="date" value="<?= htmlspecialchars(substr((string) $prevAbsenceStart, 0, 10)) ?>" data-prev-value></div>
        <div class="eh-form-field"><label for="ehAbsenceEnd">Absence end date</label><input id="ehAbsenceEnd" name="absence_end" type="date" value="<?= htmlspecialchars(substr((string) $prevAbsenceEnd, 0, 10)) ?>" data-prev-value><span class="eh-visit-field-error" data-field-error="absence_end"></span></div>
        <div class="eh-form-field eh-form-field--upload"><label for="ehMedCert">Medical Certificate (MedCert)</label><input id="ehMedCert" name="medical_certificate" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-eh-visit-file><span class="eh-visit-filename" data-file-name>No file selected</span><button type="button" class="eh-btn eh-btn--ghost eh-visit-remove-file" data-remove-file hidden>Remove file</button><span class="eh-form-hint">Optional · PDF, JPG, or PNG · up to 10 MB</span><span class="eh-visit-field-error" data-field-error="medical_certificate"></span></div>
        <div class="eh-form-field eh-form-field--upload"><label for="ehFitToWork">Fit-to-Work Certificate</label><input id="ehFitToWork" name="fit_to_work" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" data-eh-visit-file><span class="eh-visit-filename" data-file-name>No file selected</span><button type="button" class="eh-btn eh-btn--ghost eh-visit-remove-file" data-remove-file hidden>Remove file</button><span class="eh-form-hint">Optional · separate from the MedCert · up to 10 MB</span><span class="eh-visit-field-error" data-field-error="fit_to_work"></span></div>
    </div>
    <p class="eh-visit-policy-note">Documents and restrictions are recorded for administrative review. Uploading a certificate does not automatically determine fitness for work.</p>
    <div class="eh-visit-panel-actions">
        <button type="submit" class="eh-btn eh-btn--primary" data-eh-visit-submit>
            <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
            <?= ($visitEditing ?? false) ? 'Update Visit' : 'Save Visit' ?>
        </button>
    </div>
</fieldset>
