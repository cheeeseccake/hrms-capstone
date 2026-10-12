<?php
/**
 * Clinic visit form — Emergency visit-type panel.
 * Included by views/clinic-visit-dialog.php.
 */
?>
<fieldset class="eh-visit-panel" data-visit-type="emergency" hidden disabled>
    <legend>Emergency</legend>
    <div class="eh-visit-grid">
        <div class="eh-form-field"><label for="ehIncidentAt">Incident date and time</label><input id="ehIncidentAt" name="incident_at" type="datetime-local"><span class="eh-visit-field-error" data-field-error="incident_at"></span></div>
        <div class="eh-form-field"><label for="ehIncidentLocation">Incident location</label><input id="ehIncidentLocation" name="incident_location" maxlength="255">
            <?php eh_visit_suggestions('incident_location', 'ehIncidentLocation', 'Optional incident location suggestions'); ?>
        </div>
        <div class="eh-form-field eh-form-field--full"><label for="ehIncidentCircumstances">Circumstances</label><textarea id="ehIncidentCircumstances" name="circumstances" rows="2" maxlength="5000"></textarea>
            <?php eh_visit_suggestions('circumstances', 'ehIncidentCircumstances', 'Optional circumstances wording suggestions'); ?>
        </div>
        <div class="eh-form-field eh-form-field--full"><label for="ehAccidentType">Type of accident or injury</label><input id="ehAccidentType" name="accident_type" maxlength="255" placeholder="e.g. Slip on a wet or slippery floor">
            <?php eh_visit_suggestions('accident_types', 'ehAccidentType', 'Optional accident type suggestions'); ?>
        </div>
        <div class="eh-form-field eh-form-field--full"><label for="ehEmergencyReported">Symptoms or concerns reported</label><textarea id="ehEmergencyReported" name="employee_report" rows="2" maxlength="5000"></textarea>
            <?php eh_visit_suggestions('employee_report', 'ehEmergencyReported', 'Optional symptom wording suggestions'); ?>
        </div>
        <div class="eh-form-field eh-form-field--full"><label for="ehEmergencyFirstAid">First aid or basic assistance provided</label><textarea id="ehEmergencyFirstAid" name="first_aid" rows="3" maxlength="5000"></textarea>
            <?php eh_visit_suggestions('first_aid', 'ehEmergencyFirstAid', 'Optional first aid wording suggestions'); ?>
        </div>
        <div class="eh-form-field eh-form-field--full" data-clinic-dispense>
            <h4 class="eh-visit-subtitle">Medicine Dispensed (optional)</h4>
            <p class="eh-form-hint">Dispensing records reduce the selected medicine's clinic inventory after this visit is saved.</p>
        </div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehEmergencyDispenseItem">Medicine</label><select id="ehEmergencyDispenseItem" name="dispense_item_id" data-dispense-item><option value="">No medicine dispensed</option></select><span class="eh-visit-field-error" data-dispense-error></span></div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehEmergencyDispenseQuantity">Quantity</label><input id="ehEmergencyDispenseQuantity" name="dispense_quantity" type="number" min="1" step="1" data-dispense-quantity disabled><small class="eh-form-hint" data-dispense-stock></small></div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehEmergencyDispenseDate">Date dispensed</label><input id="ehEmergencyDispenseDate" name="dispense_date" type="date" data-dispense-date disabled></div>
        <div class="eh-form-field" data-clinic-dispense><label for="ehEmergencyDispensedBy">Dispensed by</label><input id="ehEmergencyDispensedBy" name="dispense_personnel" maxlength="120" value="<?= htmlspecialchars((string) ($_SESSION['employee_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-dispense-personnel disabled></div>
        <div class="eh-form-field eh-form-field--full"><label for="ehEmergencyActions">Actions taken by clinic staff</label><textarea id="ehEmergencyActions" name="actions_taken" rows="2" maxlength="5000"></textarea>
            <?php eh_visit_suggestions('actions_taken', 'ehEmergencyActions', 'Optional actions wording suggestions'); ?>
        </div>
        <div class="eh-form-field"><label for="ehAssistanceAt">Time assistance was provided</label><input id="ehAssistanceAt" name="assistance_at" type="datetime-local"></div>
        <div class="eh-form-field"><label for="ehEmergencyContacted">External healthcare or emergency service contacted</label><select id="ehEmergencyContacted" name="external_contacted"><option value="no">No</option><option value="yes">Yes</option></select></div>
        <div class="eh-form-field"><label for="ehEmergencyTransfer">Hospital transfer or emergency transport</label><select id="ehEmergencyTransfer" name="transfer_occurred"><option value="no">No</option><option value="yes">Yes</option></select></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="transfer_occurred=yes" hidden><label for="ehEmergencyTransferDetails">Transport or transfer details</label><input id="ehEmergencyTransferDetails" name="transfer_details" maxlength="3000"></div>
        <div class="eh-form-field eh-form-field--full"><h4 class="eh-visit-subtitle">Optional measured vital signs</h4><p class="eh-form-hint">Record only values actually measured by appropriately trained staff.</p></div>
        <div class="eh-form-field"><label for="ehEmergencyBP">Blood pressure</label><input id="ehEmergencyBP" name="blood_pressure" maxlength="20" pattern="[0-9]{2,3}[ ]*/[ ]*[0-9]{2,3}" title="Enter systolic/diastolic, for example 120/80." placeholder="e.g. 120/80"><span class="eh-visit-field-error" data-field-error="blood_pressure"></span></div>
        <div class="eh-form-field"><label for="ehEmergencyPulse">Pulse rate (bpm)</label><input id="ehEmergencyPulse" name="pulse_rate" type="number" min="1" max="300"></div>
        <div class="eh-form-field"><label for="ehEmergencyResp">Respiratory rate (/min)</label><input id="ehEmergencyResp" name="respiratory_rate" type="number" min="1" max="80"></div>
        <div class="eh-form-field"><label for="ehEmergencyTemp">Temperature (°C)</label><input id="ehEmergencyTemp" name="temperature" type="number" min="25" max="45" step="0.1"></div>
        <div class="eh-form-field"><label for="ehEmergencyO2">Oxygen saturation (%)</label><input id="ehEmergencyO2" name="oxygen_saturation" type="number" min="0" max="100" step="0.1"></div>
        <div class="eh-form-field eh-form-field--full"><label for="ehEmergencyOutcome">Outcome or disposition</label><textarea id="ehEmergencyOutcome" name="outcome" rows="2" maxlength="1000"></textarea>
            <?php eh_visit_suggestions('outcome', 'ehEmergencyOutcome', 'Optional outcome wording suggestions'); ?>
        </div>
        <div class="eh-form-field"><label for="ehEmergencyFollowupRequired">Follow-up required</label><select id="ehEmergencyFollowupRequired" name="followup_required"><option value="no">No</option><option value="yes">Yes</option></select></div>
        <div class="eh-form-field eh-visit-conditional" data-visit-condition="followup_required=yes" hidden><label for="ehEmergencyFollowupDate">Recommended follow-up date <span aria-hidden="true">*</span></label><input id="ehEmergencyFollowupDate" name="followup_date" type="date"><span class="eh-visit-field-error" data-field-error="followup_date"></span></div>
        <div class="eh-form-field eh-form-field--full eh-visit-conditional" data-visit-condition="followup_required=yes" hidden><label for="ehEmergencyFollowup">Follow-up instructions and notes</label><textarea id="ehEmergencyFollowup" name="followup_instructions" rows="2" maxlength="3000"></textarea></div>
        <div class="eh-form-field eh-form-field--full"><label for="ehEmergencyNotes">Additional response notes</label><textarea id="ehEmergencyNotes" name="response_notes" rows="2" maxlength="3000"></textarea></div>
        <div class="eh-visit-panel-actions">
            <button type="submit" class="eh-btn eh-btn--primary" data-eh-visit-submit>
                <i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>
                <?= ($visitEditing ?? false) ? 'Update Visit' : 'Save Visit' ?>
            </button>
        </div>
    </div>
</fieldset>
