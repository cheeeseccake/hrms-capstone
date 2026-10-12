<?php
/**
 * Clinic visit form — Consultation visit-type panel.
 * Included by views/clinic-visit-dialog.php.
 *
 * @var array $clinicVisitSuggestions Wording suggestions keyed by field name.
 * @var bool  $canRecordPhysicianAssessment True when a physician role may record assessments.
 * @var bool  $editMode True when the health profile is in edit mode.
 */
?>
<fieldset class="eh-visit-panel" data-visit-type="consultation">
    <legend>Consultation</legend>
    <div class="eh-visit-grid">
        <div class="eh-form-field eh-form-field--full"><label for="ehVisitReported">Symptoms or concerns reported by the employee</label><textarea id="ehVisitReported" name="employee_report" rows="3" maxlength="5000"></textarea><div class="eh-visit-suggestions" data-suggestion-target="ehVisitReported" aria-label="Optional symptom wording suggestions" hidden><?php foreach ($clinicVisitSuggestions['employee_report'] ?? [] as $suggestion): ?><button type="button"><?= htmlspecialchars($suggestion) ?></button><?php endforeach; ?><span class="eh-visit-suggestion-empty" hidden>No matches. You can keep typing your own text.</span></div></div>
        <div class="eh-form-field eh-form-field--full"><label for="ehVisitRelevantInfo">Relevant information provided</label><textarea id="ehVisitRelevantInfo" name="relevant_information" rows="2" maxlength="5000"></textarea>
            <?php eh_visit_suggestions('relevant_information', 'ehVisitRelevantInfo', 'Optional relevant information wording suggestions'); ?>
        </div>
        <div class="eh-form-field eh-form-field--full"><label for="ehVisitAssistance">Assistance or basic care provided</label><textarea id="ehVisitAssistance" name="assistance_provided" rows="3" maxlength="5000"></textarea><div class="eh-visit-suggestions" data-suggestion-target="ehVisitAssistance" aria-label="Optional assistance wording suggestions" hidden><?php foreach ($clinicVisitSuggestions['assistance_provided'] ?? [] as $suggestion): ?><button type="button"><?= htmlspecialchars($suggestion) ?></button><?php endforeach; ?><span class="eh-visit-suggestion-empty" hidden>No matches. You can keep typing your own text.</span></div></div>
        <div class="eh-form-field eh-form-field--full" data-clinic-dispense>
            <h4 class="eh-visit-subtitle">Medicine Dispensed (optional)</h4>
            <p class="eh-form-hint">Dispensing records reduce the selected medicine's clinic inventory after this visit is saved.</p>
        </div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehConsultDispenseItem">Medicine</label><select id="ehConsultDispenseItem" name="dispense_item_id" data-dispense-item><option value="">No medicine dispensed</option></select><span class="eh-visit-field-error" data-dispense-error></span></div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehConsultDispenseQuantity">Quantity</label><input id="ehConsultDispenseQuantity" name="dispense_quantity" type="number" min="1" step="1" data-dispense-quantity disabled><small class="eh-form-hint" data-dispense-stock></small></div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehConsultDispenseDate">Date dispensed</label><input id="ehConsultDispenseDate" name="dispense_date" type="date" data-dispense-date disabled></div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehConsultDispensedBy">Dispensed by</label><input id="ehConsultDispensedBy" name="dispense_personnel" maxlength="120" value="<?= htmlspecialchars((string) ($_SESSION['employee_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-dispense-personnel disabled></div>
        <div class="eh-form-field eh-form-field--full"><label for="ehVisitAdvice">Advice or instructions given</label><textarea id="ehVisitAdvice" name="advice_given" rows="2" maxlength="5000"></textarea><div class="eh-visit-suggestions" data-suggestion-target="ehVisitAdvice" aria-label="Optional advice wording suggestions" hidden><?php foreach ($clinicVisitSuggestions['advice_given'] ?? [] as $suggestion): ?><button type="button"><?= htmlspecialchars($suggestion) ?></button><?php endforeach; ?><span class="eh-visit-suggestion-empty" hidden>No matches. You can keep typing your own text.</span></div></div>
        <div class="eh-form-field eh-form-field--full"><h4 class="eh-visit-subtitle">Vital Signs</h4><p class="eh-form-hint">Record only measurements that were actually taken. Measurements are not a diagnosis.</p></div>
        <div class="eh-form-field"><label for="ehConsultBP">Blood pressure (mmHg)</label><input id="ehConsultBP" name="blood_pressure" inputmode="numeric" maxlength="20" pattern="[0-9]{2,3}[ ]*/[ ]*[0-9]{2,3}" title="Enter systolic/diastolic, for example 120/80." placeholder="e.g. 120/80"><span class="eh-visit-field-error" data-field-error="blood_pressure"></span></div>
        <div class="eh-form-field"><label for="ehConsultPulse">Pulse rate (bpm)</label><input id="ehConsultPulse" name="pulse_rate" type="number" min="1" max="300" step="1"><span class="eh-visit-field-error" data-field-error="pulse_rate"></span></div>
        <div class="eh-form-field"><label for="ehConsultResp">Respiratory rate (breaths/min)</label><input id="ehConsultResp" name="respiratory_rate" type="number" min="1" max="80" step="1"><span class="eh-visit-field-error" data-field-error="respiratory_rate"></span></div>
        <div class="eh-form-field"><label for="ehConsultTemp">Body temperature (°C)</label><input id="ehConsultTemp" name="temperature" type="number" min="25" max="45" step="0.1"><span class="eh-visit-field-error" data-field-error="temperature"></span></div>
        <div class="eh-form-field"><label for="ehConsultO2">Oxygen saturation (SpO₂ %)</label><input id="ehConsultO2" name="oxygen_saturation" type="number" min="0" max="100" step="0.1"><span class="eh-visit-field-error" data-field-error="oxygen_saturation"></span></div>
        <div class="eh-form-field"><label for="ehConsultHeight">Height (cm, optional)</label><input id="ehConsultHeight" name="height_cm" type="number" min="30" max="260" step="0.1" data-bmi-height><span class="eh-visit-field-error" data-field-error="height_cm"></span></div>
        <div class="eh-form-field"><label for="ehConsultWeight">Weight (kg, optional)</label><input id="ehConsultWeight" name="weight_kg" type="number" min="1" max="400" step="0.1" data-bmi-weight><span class="eh-visit-field-error" data-field-error="weight_kg"></span></div>
        <div class="eh-form-field"><label for="ehConsultBMI">BMI (calculated)</label><input id="ehConsultBMI" name="bmi" type="text" readonly aria-readonly="true" placeholder="Available when height and weight are valid" data-bmi-output></div>
        <?php if ($canRecordPhysicianAssessment): ?>
        <div class="eh-form-field eh-form-field--full"><h4 class="eh-visit-subtitle">Physician Assessment</h4><p class="eh-form-hint">For physician-completed or physician-documented assessments only. Keep employee reports and staff notes separate.</p></div>
        <div class="eh-form-field"><label for="ehPhysicianAssessment">Assessment performed by a physician?</label><select id="ehPhysicianAssessment" name="physician_assessment"><option value="no">No</option><option value="yes">Yes</option></select></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianName">Physician name <span aria-hidden="true">*</span></label><input id="ehPhysicianName" name="physician_name" maxlength="160" disabled><span class="eh-visit-field-error" data-field-error="physician_name"></span></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianLicense">License or professional identifier (if required by policy)</label><input id="ehPhysicianLicense" name="physician_identifier" maxlength="100" disabled><span class="eh-visit-field-error" data-field-error="physician_identifier"></span></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianDate">Assessment date and time <span aria-hidden="true">*</span></label><input id="ehPhysicianDate" name="physician_assessment_at" type="datetime-local" disabled><span class="eh-visit-field-error" data-field-error="physician_assessment_at"></span></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianFindings">Physician's clinical findings or assessment <span aria-hidden="true">*</span></label><textarea id="ehPhysicianFindings" name="physician_findings" rows="3" maxlength="5000" disabled></textarea><span class="eh-visit-field-error" data-field-error="physician_findings"></span></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianDiagnosis">Physician-documented diagnosis (if documented)</label><textarea id="ehPhysicianDiagnosis" name="physician_diagnosis" rows="2" maxlength="3000" disabled></textarea><span class="eh-visit-field-error" data-field-error="physician_diagnosis"></span></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianPlan">Recommended treatment or management plan</label><textarea id="ehPhysicianPlan" name="physician_plan" rows="2" maxlength="5000" disabled></textarea></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianAdvice">Medical advice or instructions</label><textarea id="ehPhysicianAdvice" name="physician_advice" rows="2" maxlength="5000" disabled></textarea></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="physician_assessment=yes" hidden><label for="ehPhysicianFollowup">Physician-recommended follow-up (if documented)</label><textarea id="ehPhysicianFollowup" name="physician_followup" rows="2" maxlength="3000" disabled></textarea></div>
        <?php else: ?>
        <div class="eh-form-field eh-form-field--full"><h4 class="eh-visit-subtitle">Physician Assessment</h4><p class="eh-form-hint">Physician assessment entry is unavailable because no physician role has been configured for this clinic.</p></div>
    <?php endif; ?>
        <div class="eh-form-field"><label for="ehRestAdvised">Rest advised?</label><select id="ehRestAdvised" name="rest_advised"><option value="not_specified" selected>Not specified</option><option value="no">No</option><option value="yes">Yes</option></select></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="rest_advised=yes" hidden><label for="ehRestDurationPreset">Rest duration</label><select id="ehRestDurationPreset" name="rest_duration_preset" disabled><option value="">Select duration</option><option value="2_hours">2 hours</option><option value="4_hours">4 hours</option><option value="half_day">Half day</option><option value="1_day">1 day</option><option value="2_days">2 days</option><option value="3_days">3 days</option><option value="custom">Other duration</option></select><span class="eh-visit-field-error" data-field-error="rest_duration_preset"></span></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="rest_advised=yes&amp;rest_duration_preset=custom" hidden><label for="ehRestDurationCustom">Custom duration</label><input id="ehRestDurationCustom" name="rest_duration_custom" type="number" min="0.5" max="365" step="0.5" disabled><span class="eh-visit-field-error" data-field-error="rest_duration_custom"></span></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="rest_advised=yes&amp;rest_duration_preset=custom" hidden><label for="ehRestDurationUnit">Duration unit</label><select id="ehRestDurationUnit" name="rest_duration_unit" disabled><option value="hours">Hours</option><option value="days">Days</option></select><span class="eh-visit-field-error" data-field-error="rest_duration_unit"></span></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="rest_advised=yes" hidden><label for="ehRestStart">Rest start date (optional)</label><input id="ehRestStart" name="rest_start_date" type="date" disabled><span class="eh-visit-field-error" data-field-error="rest_start_date"></span></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="rest_advised=yes" hidden><label for="ehRestEnd">Recommended rest end date (optional)</label><input id="ehRestEnd" name="rest_end_date" type="date" disabled><span class="eh-visit-field-error" data-field-error="rest_end_date"></span></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="rest_advised=yes" hidden><label for="ehRestReason">Reason or supporting instructions</label><textarea id="ehRestReason" name="rest_instructions" rows="2" maxlength="3000" disabled></textarea></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="rest_advised=yes" hidden><label for="ehRestFollowup">Recommended follow-up (optional)</label><textarea id="ehRestFollowup" name="rest_followup" rows="2" maxlength="3000" disabled></textarea></div>
        </div>
        <div class="eh-form-field"><label for="ehConsultFollowup">Follow-up required</label><select id="ehConsultFollowup" name="followup_required"><option value="no">No</option><option value="yes">Yes</option></select></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="followup_required=yes" hidden><label for="ehConsultFollowupDate">Recommended follow-up date <span aria-hidden="true">*</span></label><input id="ehConsultFollowupDate" name="followup_date" type="date"><span class="eh-visit-field-error" data-field-error="followup_date"></span></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="followup_required=yes" hidden><label for="ehConsultFollowupNotes">Follow-up notes</label><textarea id="ehConsultFollowupNotes" name="followup_notes" rows="2" maxlength="3000"></textarea></div>
        <div class="eh-form-field eh-form-field--full"><label for="ehConsultNotes">Additional notes</label><textarea id="ehConsultNotes" name="additional_notes" rows="2" maxlength="5000"></textarea></div>
        <div class="eh-visit-panel-actions">
            <button type="submit" class="eh-btn eh-btn--primary" data-eh-visit-submit>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <?= ($visitEditing ?? false) ? 'Update Visit' : 'Save Visit' ?>
            </button>
        </div>
    <?php if ($editMode): ?>
        <div class="eh-form-actions eh-form-actions--section">
            <button type="submit" class="eh-btn eh-btn--success" form="ehProfileForm">
                <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                Save health profile
            </button>
        </div>
    <?php endif; ?>
</fieldset>
