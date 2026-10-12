<!-- ==================== SELECTED EMPLOYEE PROFILE ==================== -->
<?php
/**
 * Clinic visit recording page markup. (kept minimal: helpers below)
 */
$activeHealthSection = $activeHealthSection ?? 'visits';
$profileSavedMessage = $profileSavedMessage ?? 'Health profile saved successfully.';
$healthTabClass = static function (string $tab) use ($activeHealthSection): string {
    return 'tab-content' . ($activeHealthSection === $tab ? ' active' : '');
};
?>
<section class="eh-module employee-health employee-health--profile" id="employeeHealthRoot" data-employee-id="<?= (int) $selectedEmployeeId ?>">
    <?php if ($profileSaved): ?>
        <div class="eh-flash eh-flash--success" role="status" data-eh-flash="success"><?= htmlspecialchars($profileSavedMessage) ?></div>
    <?php endif; ?>
    <?php if ($profileError !== ''): ?>
        <div class="eh-flash eh-flash--error" role="alert" data-eh-flash="error"><?= htmlspecialchars($profileError) ?></div>
    <?php endif; ?>

    <div class="eh-row">
        <!-- ── Main column ── -->
        <div class="eh-col-main">
            <?php
            $employeeNameParts = preg_split('/\s+/', trim(eh_full_name($selectedEmployee))) ?: [];
            $employeeInitials = strtoupper(mb_substr($employeeNameParts[0] ?? '', 0, 1) . (count($employeeNameParts) > 1 ? mb_substr(end($employeeNameParts), 0, 1) : ''));
            ?>
            <?php if (!$canViewMedical): ?>
                <div class="eh-notice eh-notice--info">
                    Medical details for this employee are restricted to authorized clinic personnel.
                    Contact the school clinic if you need access to health records.
                </div>
            <?php else: ?>
            <div class="tab-container eh-tab-container">
                <?php $this->renderTabNav($healthSections, $activeHealthSection); ?>

                <!-- ---------------- Health Profile (implemented) ---------------- -->
                <div class="<?= $healthTabClass('profile') ?>" data-tab="profile">
                    <?php if ($editMode): ?>
                        <!-- ── Edit mode ── -->
                        <form class="eh-form" id="ehProfileForm" method="post" action="<?= htmlspecialchars($profileBaseUrl) ?>" novalidate data-skip>
                            <input type="hidden" name="action" value="save_health_profile">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                            <div class="eh-form-section">
                                <h3 class="eh-form-legend">General health information</h3>
                                <div class="eh-form-grid">
                                    <div class="eh-form-field">
                                        <label for="ehBloodType">Blood type</label>
                                        <select id="ehBloodType" name="blood_type">
                                            <option value="">Not recorded</option>
                                            <?php foreach ($bloodTypeOptions as $option): ?>
                                                <option value="<?= $option ?>"<?= eh_selected((string) $profile['blood_type'], $option) ?>><?= $option ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="eh-form-field">
                                        <label for="ehHeight">Height (cm)</label>
                                        <input type="number" id="ehHeight" name="height_cm" step="0.01" min="30" max="260"
                                               value="<?= htmlspecialchars((string) $profile['height_cm']) ?>" placeholder="Not recorded">
                                        <?= eh_field_error($fieldErrors, 'height_cm') ?>
                                    </div>
                                    <div class="eh-form-field">
                                        <label for="ehWeight">Weight (kg)</label>
                                        <input type="number" id="ehWeight" name="weight_kg" step="0.01" min="1" max="400"
                                               value="<?= htmlspecialchars((string) $profile['weight_kg']) ?>" placeholder="Not recorded">
                                        <?= eh_field_error($fieldErrors, 'weight_kg') ?>
                                    </div>
                                    <div class="eh-form-field">
                                        <label for="ehBmiPreview">Body mass index</label>
                                        <input type="text" id="ehBmiPreview" value="<?= !empty($profile['bmi']) ? htmlspecialchars(number_format((float) $profile['bmi'], 2)) : '—' ?>" readonly>
                                    </div>
                                    <div class="eh-form-field">
                                        <label for="ehSmoking">Smoking status</label>
                                        <select id="ehSmoking" name="smoking_status">
                                            <?php foreach ($smokingOptions as $option): ?>
                                                <option value="<?= $option ?>"<?= eh_selected((string) $profile['smoking_status'], $option) ?>><?= ucfirst(str_replace('_', ' ', $option)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="eh-form-field">
                                        <label for="ehAlcohol">Alcohol use</label>
                                        <select id="ehAlcohol" name="alcohol_use">
                                            <?php foreach ($alcoholOptions as $option): ?>
                                                <option value="<?= $option ?>"<?= eh_selected((string) $profile['alcohol_use'], $option) ?>><?= ucfirst($option) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="eh-form-section">
                                <h3 class="eh-form-legend">Allergies and conditions</h3>
                                <div class="eh-form-grid">
                                    <?php foreach ($autocompleteFields as $field): ?>
                                        <?php
                                        $suggestionsJson = json_encode($field['suggestions'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        $initialJson = json_encode($field['initial'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        $serializedValue = eh_multi_serialize($field['initial']);
                                        ?>
                                        <div class="eh-form-field eh-form-field--full">
                                            <label for="<?= htmlspecialchars($field['search_id']) ?>"><?= htmlspecialchars($field['label']) ?></label>
                                            <div class="eh-autocomplete" data-eh-autocomplete data-eh-autocomplete-key="<?= htmlspecialchars($field['key']) ?>"
                                                 data-suggestions="<?= htmlspecialchars($suggestionsJson ?: '[]', ENT_QUOTES, 'UTF-8') ?>"
                                                 data-initial="<?= htmlspecialchars($initialJson ?: '[]', ENT_QUOTES, 'UTF-8') ?>">
                                                <div class="eh-autocomplete-tags" data-eh-tags aria-live="polite"></div>
                                                <div class="eh-autocomplete-entry">
                                                    <i class="fa-solid fa-magnifying-glass eh-autocomplete-icon" aria-hidden="true"></i>
                                                    <input type="search" class="eh-autocomplete-input" id="<?= htmlspecialchars($field['search_id']) ?>" maxlength="2000"
                                                           placeholder="<?= htmlspecialchars($field['placeholder']) ?>" autocomplete="off" role="combobox"
                                                           aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false"
                                                           aria-controls="ehList<?= htmlspecialchars(ucfirst($field['key'])) ?>">
                                                </div>
                                                <div class="eh-autocomplete-menu" data-eh-menu hidden>
                                                    <ul class="eh-autocomplete-list" id="ehList<?= htmlspecialchars(ucfirst($field['key'])) ?>" role="listbox"></ul>
                                                    <p class="eh-autocomplete-empty" data-eh-empty hidden>No matching suggestions. Press Enter to add a custom entry.</p>
                                                </div>
                                                <p class="eh-autocomplete-limit" data-eh-limit hidden>This field cannot exceed 2000 characters.</p>
                                                <input type="hidden" id="<?= htmlspecialchars($field['value_id']) ?>" name="<?= htmlspecialchars($field['key'] === 'conditions' ? 'medical_conditions' : ($field['key'] === 'medications' ? 'current_medications' : 'allergies')) ?>"
                                                       maxlength="2000" value="<?= htmlspecialchars($serializedValue, ENT_QUOTES, 'UTF-8') ?>" data-eh-autocomplete-value>
                                                <?php if ($field['status_name'] !== null): ?>
                                                    <input type="hidden" id="<?= htmlspecialchars($field['status_id']) ?>" name="<?= htmlspecialchars($field['status_name']) ?>" value="<?= htmlspecialchars((string) $field['status_value']) ?>" data-eh-autocomplete-status>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ($field['checkbox_name'] !== null): ?>
                                                <label class="eh-autocomplete-confirm" for="<?= htmlspecialchars($field['checkbox_id']) ?>">
                                                    <input type="checkbox" id="<?= htmlspecialchars($field['checkbox_id']) ?>" name="<?= htmlspecialchars($field['checkbox_name']) ?>" value="1"<?= $field['checked'] ? ' checked' : '' ?> data-eh-no-known>
                                                    <span><?= htmlspecialchars($field['checkbox_label']) ?></span>
                                                </label>
                                            <?php endif; ?>
                                            <?= eh_field_error($fieldErrors, $field['key'] === 'conditions' ? 'medical_conditions' : $field['key']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="eh-form-section">
                                <h3 class="eh-form-legend">Relevant medical history</h3>
                                <p class="eh-side-note">Medical information is sensitive. Record only what is necessary for healthcare; access is limited to authorized clinic personnel. Accommodation requests do not imply a diagnosis.</p>
                                <div class="eh-form-grid">
                                    <?php foreach ($historyAutocompleteFields as $field): ?>
                                        <?php
                                        $suggestionsJson = json_encode($field['suggestions'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        $initialJson = json_encode($field['history_data']['entries'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                        ?>
                                        <div class="eh-form-field eh-form-field--full eh-history-entry-field">
                                            <label for="<?= htmlspecialchars($field['search_id']) ?>"><?= htmlspecialchars($field['label']) ?></label>
                                            <div class="eh-autocomplete eh-history-autocomplete" data-eh-autocomplete data-eh-autocomplete-key="<?= htmlspecialchars($field['key']) ?>" data-eh-history-format data-history-prefix="EH_HISTORY_V1:" data-max-length="5000" data-notes-input-id="<?= htmlspecialchars($field['notes_id']) ?>"
                                                 data-suggestions="<?= htmlspecialchars($suggestionsJson ?: '[]', ENT_QUOTES, 'UTF-8') ?>"
                                                 data-initial="<?= htmlspecialchars($initialJson ?: '[]', ENT_QUOTES, 'UTF-8') ?>">
                                                <div class="eh-autocomplete-tags" data-eh-tags aria-live="polite"></div>
                                                <div class="eh-autocomplete-entry">
                                                    <i class="fa-solid fa-magnifying-glass eh-autocomplete-icon" aria-hidden="true"></i>
                                                    <input type="search" class="eh-autocomplete-input" id="<?= htmlspecialchars($field['search_id']) ?>" maxlength="5000"
                                                           placeholder="<?= htmlspecialchars($field['placeholder']) ?>" autocomplete="off" role="combobox"
                                                           aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false"
                                                           aria-controls="ehList<?= htmlspecialchars($field['key']) ?>">
                                                </div>
                                                <div class="eh-autocomplete-menu" data-eh-menu hidden>
                                                    <ul class="eh-autocomplete-list" id="ehList<?= htmlspecialchars($field['key']) ?>" role="listbox"></ul>
                                                    <p class="eh-autocomplete-empty" data-eh-empty hidden>No matching suggestions. Press Enter to add a custom entry.</p>
                                                </div>
                                                <p class="eh-autocomplete-limit" data-eh-limit hidden>This field cannot exceed 5000 characters.</p>
                                                <input type="hidden" id="<?= htmlspecialchars($field['value_id']) ?>" name="<?= htmlspecialchars($field['field_name']) ?>" maxlength="5000"
                                                       value="<?= htmlspecialchars((string) $field['initial_value'], ENT_QUOTES, 'UTF-8') ?>" data-eh-autocomplete-value>
                                            </div>
                                            <?php if ($field['key'] === 'family-history'): ?>
                                                <p class="eh-form-hint">Suggestions pair a relationship and condition. Use a custom entry when either detail is unknown.</p>
                                            <?php elseif ($field['key'] === 'accommodation'): ?>
                                                <p class="eh-form-hint">Choose practical accommodations without recording a disability diagnosis.</p>
                                            <?php endif; ?>
                                            <label class="eh-history-notes-label" for="<?= htmlspecialchars($field['notes_id']) ?>">Optional notes</label>
                                            <textarea id="<?= htmlspecialchars($field['notes_id']) ?>" data-eh-autocomplete-notes rows="2" maxlength="5000" placeholder="Add details that do not fit a selection..."><?= htmlspecialchars((string) $field['history_data']['notes']) ?></textarea>
                                            <?= eh_field_error($fieldErrors, $field['field_name']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="eh-form-field">
                                        <label for="ehPhilHealth">PhilHealth number</label>
                                        <input type="text" id="ehPhilHealth" maxlength="50" value="<?= htmlspecialchars($philhealthNo) ?>" readonly aria-readonly="true" placeholder="Not recorded">
                                        <p class="eh-form-hint">Retrieved from the employee government ID record.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="eh-form-section">
                                <h3 class="eh-form-legend">Emergency contact</h3>
                                <div class="eh-form-grid">
                                    <div class="eh-form-field">
                                        <label for="ehEmergencyName">Contact name</label>
                                        <input type="text" id="ehEmergencyName" name="emergency_contact_name" maxlength="150"
                                               value="<?= htmlspecialchars((string) $profile['emergency_contact_name']) ?>">
                                        <?= eh_field_error($fieldErrors, 'emergency_contact_name') ?>
                                    </div>
<div class="eh-form-field eh-form-field--phone">
                                        <label for="ehEmergencyPhone">Contact phone</label>
                                        <div class="eh-phone-control">
                                            <span class="eh-phone-country" aria-hidden="true">PH</span>
                                            <input type="tel" id="ehEmergencyPhone" name="emergency_contact_phone" maxlength="40" inputmode="tel" autocomplete="tel" pattern="[0-9+\x28\x29.\s\x2d]{7,40}" title="Enter a mobile or landline number, for example 0917-555-1234 or +63 917 555 1234."<?= isset($fieldErrors['emergency_contact_phone']) ? ' aria-invalid="true"' : '' ?>
                                                                                               value="<?= htmlspecialchars((string) $profile['emergency_contact_phone']) ?>" placeholder="09XX-XXX-XXXX">
                                        </div>
                                        <?= eh_field_error($fieldErrors, 'emergency_contact_phone') ?>
                                    </div>
                                </div>
                            </div>

                            <div class="eh-form-actions">
                                <button type="submit" class="eh-btn eh-btn--success">
                                    <i class="fa-solid fa-save"></i>
                                    Save Health Profile
                                </button>
                                <a href="<?= htmlspecialchars($searchReturnUrl) ?>" class="eh-btn eh-btn--ghost">
                                    <i class="fa-solid fa-xmark"></i>
                                    Cancel
                                </a>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- ── View mode (default) ── -->
                        <div class="eh-toolbar">
                            <div class="eh-toolbar-info">
                                <?php if (empty($profile['profile_id'])): ?>
                                    No health profile has been recorded for this employee yet.
                                <?php else: ?>
                                    Profile last updated
                                    <?= eh_fmt_date($profile['updated_at'], 'F j, Y g:i A') !== ''
                                        ? htmlspecialchars(eh_fmt_date($profile['updated_at'], 'F j, Y g:i A'))
                                        : '—' ?>
                                    &middot; Completion <?= (int) $completion['percent'] ?>%
                                <?php endif; ?>
                            </div>
                            <?php if ($canManageHealth): ?>
                                <a class="eh-btn eh-btn--primary" href="<?= htmlspecialchars($profileBaseUrl . '&edit=1') ?>">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                    <?= empty($profile['profile_id']) ? 'Create Health Profile' : 'Edit Health Profile' ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <?php if (empty($profile['profile_id'])): ?>
                            <div class="eh-notice eh-notice--muted">
                                A blank profile means nothing has been recorded — it does not mean the employee
                                has no allergies, conditions, or medications. Record what the clinic has confirmed.
                            </div>
                        <?php else: ?>
                            <div class="eh-view">
                                <div class="eh-view-section">
                                    <h3 class="eh-form-legend">General health information</h3>
                                    <dl class="eh-view-grid">
                                        <div class="eh-view-row"><dt>Blood type</dt><dd><?= eh_view_value($profile['blood_type']) ?></dd></div>
                                        <div class="eh-view-row"><dt>Height</dt><dd><?= $profile['height_cm'] !== '' && $profile['height_cm'] !== null ? htmlspecialchars(eh_num($profile['height_cm']) . ' cm') : '<span class="eh-view-missing">Not recorded</span>' ?></dd></div>
                                        <div class="eh-view-row"><dt>Weight</dt><dd><?= $profile['weight_kg'] !== '' && $profile['weight_kg'] !== null ? htmlspecialchars(eh_num($profile['weight_kg']) . ' kg') : '<span class="eh-view-missing">Not recorded</span>' ?></dd></div>
                                        <div class="eh-view-row"><dt>Body mass index</dt><dd><?= !empty($profile['bmi']) ? htmlspecialchars(number_format((float) $profile['bmi'], 2)) : '<span class="eh-view-missing">Not recorded</span>' ?></dd></div>
                                        <div class="eh-view-row"><dt>Smoking status</dt><dd><?= htmlspecialchars(eh_label($enumLabels['smoking_status'], $profile['smoking_status'])) ?></dd></div>
                                        <div class="eh-view-row"><dt>Alcohol use</dt><dd><?= htmlspecialchars(eh_label($enumLabels['alcohol_use'], $profile['alcohol_use'])) ?></dd></div>
                                    </dl>
                                </div>

                                <div class="eh-view-section">
                                    <h3 class="eh-form-legend">Allergies and conditions</h3>
                                    <dl class="eh-view-grid">
                                        <div class="eh-view-row"><dt>Allergies</dt><dd><?= htmlspecialchars(eh_label($enumLabels['allergies_status'], $profile['allergies_status'])) ?></dd></div>
                                        <div class="eh-view-row eh-view-row--full"><dt>Allergy details</dt><dd><?= eh_view_value(eh_multi_display($profile['allergies'])) ?></dd></div>
                                        <div class="eh-view-row"><dt>Medical conditions</dt><dd><?= htmlspecialchars(eh_label($enumLabels['conditions_status'], $profile['conditions_status'])) ?></dd></div>
                                        <div class="eh-view-row eh-view-row--full"><dt>Condition details</dt><dd><?= eh_view_value(eh_multi_display($profile['medical_conditions'])) ?></dd></div>
                                        <div class="eh-view-row eh-view-row--full"><dt>Current medications</dt><dd><?= eh_view_value(eh_multi_display($profile['current_medications'])) ?></dd></div>
                                    </dl>
                                </div>

                                <div class="eh-view-section">
                                    <h3 class="eh-form-legend">Emergency contact</h3>
                                    <dl class="eh-view-grid">
                                        <div class="eh-view-row"><dt>Contact name</dt><dd><?= eh_view_value($profile['emergency_contact_name']) ?></dd></div>
                                        <div class="eh-view-row"><dt>Contact phone</dt><dd><?= eh_view_value($profile['emergency_contact_phone']) ?></dd></div>
                                    </dl>
                                </div>

                                <div class="eh-view-section">
                                    <h3 class="eh-form-legend">Relevant medical history</h3>
                                    <dl class="eh-view-grid">
                                        <div class="eh-view-row eh-view-row--full"><dt>Relevant surgical history</dt><dd><?= eh_history_view($profile['relevant_surgical_history'] ?? null) ?></dd></div>
                                        <div class="eh-view-row eh-view-row--full"><dt>Relevant family medical history</dt><dd><?= eh_history_view($profile['relevant_family_medical_history'] ?? null) ?></dd></div>
                                        <div class="eh-view-row eh-view-row--full"><dt>Disability or accommodation information</dt><dd><?= eh_history_view($profile['disability_accommodation_info'] ?? null) ?></dd></div>
                                        <div class="eh-view-row"><dt>Primary physician</dt><dd><?= eh_view_value($profile['primary_physician'] ?? null) ?></dd></div>
                                        <div class="eh-view-row"><dt>PhilHealth membership</dt><dd><?= eh_view_value($philhealthNo !== '' ? $philhealthNo : null) ?></dd></div>
                                    </dl>
                                </div>

                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
                                <?php
                $recordSections = [
                    'medical' => ['Medical History', [
                        ['Surgical and hospitalization history', 'surgeries', ['record_type'=>'Type','procedure_name'=>'Procedure','procedure_date'=>'Date','hospital_facility'=>'Facility','reason'=>'Reason']],
                        ['Family medical history', 'family_history', ['relationship'=>'Relationship','medical_condition'=>'Condition','age_at_diagnosis'=>'Age at diagnosis']],
                    ]],
                    'examinations' => ['Health Examinations', [
                        ['Examination records', 'examinations', ['exam_type'=>'Examination','follow_up_date'=>'Follow-up date','follow_up_required'=>'Follow-up required','follow_up_completed'=>'Follow-up completed','status'=>'Status']],
                    ]],
                    'immunizations' => ['Immunization Records', [
                        ['Vaccinations', 'immunizations', ['vaccine_name'=>'Vaccine','dose_number'=>'Dose','next_dose_due'=>'Next dose due','status'=>'Status']],
                    ]],
                ];
                foreach ($recordSections as $sectionKey => [$sectionTitle, $sectionGroups]):
                ?>
                <div class="<?= $healthTabClass($sectionKey) ?>" data-tab="<?= htmlspecialchars($sectionKey) ?>">
                    <div class="eh-card"><div class="eh-card-head"><h3><?= htmlspecialchars($sectionTitle) ?></h3></div><div class="eh-card-body">
                        <?php foreach ($sectionGroups as [$groupTitle, $recordKey, $columns]): ?>
                            <?php
                            $profileSummaryField = $recordKey === 'surgeries'
                                ? 'relevant_surgical_history'
                                : ($recordKey === 'family_history' ? 'relevant_family_medical_history' : null);
                            $profileSummary = $profileSummaryField !== null
                                ? eh_history_text($profile[$profileSummaryField] ?? '')
                                : '';
                            ?>
                            <section class="eh-view-section"><h4 class="eh-form-legend"><?= htmlspecialchars($groupTitle) ?></h4>
                            <?php if ($profileSummary !== ''): ?>
                                <div class="eh-medical-summary">
                                    <p class="eh-side-label">Relevant history from health profile</p>
                                    <p class="eh-medical-summary-text"><?= nl2br(htmlspecialchars($profileSummary)) ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($healthRecordErrors[$recordKey])): ?>
                                <p class="eh-flash eh-flash--error" role="status">These records are temporarily unavailable.</p>
                            <?php elseif ($healthRecords[$recordKey] === [] && $profileSummary === ''): ?>
                                <p class="eh-side-empty">No <?= htmlspecialchars(strtolower($groupTitle)) ?> are recorded for this employee.</p>
                            <?php elseif ($healthRecords[$recordKey] !== []): ?>
                                <div class="eh-table-wrap"><table class="eh-table"><thead><tr><?php foreach ($columns as $label): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr></thead><tbody>
                                <?php foreach ($healthRecords[$recordKey] as $record): ?><tr><?php foreach ($columns as $field => $label): ?>
                                    <?php $value = $record[$field] ?? ''; if (in_array($field, ['date_identified','date_diagnosed','procedure_date','start_date','end_date','follow_up_date','next_dose_due','uploaded_at','retention_review_date'], true) && $value !== '') { $value = eh_fmt_date($value); } if (in_array($field, ['follow_up_required','follow_up_completed'], true)) { $value = (int) $value === 1 ? 'Yes' : 'No'; } ?>
                                    <td><?= $value !== '' && $value !== null ? nl2br(htmlspecialchars((string) $value)) : '<span class="eh-view-missing">Not recorded</span>' ?></td>
                                <?php endforeach; ?></tr><?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?></section>
                        <?php endforeach; ?>
                        <?php if ($sectionKey === 'medical'): ?>
                            <section class="eh-view-section">
                                <h4 class="eh-form-legend">Clinic visit summaries</h4>
                                <?php if ($clinicVisitHistoryError): ?>
                                    <p class="eh-flash eh-flash--error" role="status">Clinic visit summaries are temporarily unavailable.</p>
                                <?php elseif ($clinicVisits === []): ?>
                                    <p class="eh-side-empty">No clinic visits are recorded for this employee.</p>
                                <?php else: ?>
                                    <?php $summaryVisitTypes = ['consultation'=>'Consultation','emergency'=>'Emergency','return_to_work'=>'Return to Work']; ?>
                                    <?php foreach ($clinicVisits as $summaryVisit): $summaryDetails = json_decode((string) ($summaryVisit['details_payload'] ?? ''), true); $summaryNarrative = []; ?>
                                        <?php if (is_array($summaryDetails)) foreach ($summaryDetails as $summaryKey => $summaryValue):
                                            if ($summaryValue === null || $summaryValue === '' || !is_scalar($summaryValue)) continue;
                                            if (is_bool($summaryValue)) $summaryValue = $summaryValue ? 'yes' : 'no';
                                            $summaryValue = trim((string) $summaryValue);
                                            if ($summaryValue === '') continue;
                                            $summaryLabels = ['employee_report'=>'The employee reported','relevant_information'=>'Relevant information included','additional_notes'=>'Additional notes','assistance_provided'=>'Assistance provided','advice_given'=>'Advice given','referral_destination'=>'Referral destination','referral_details'=>'Referral details','outcome'=>'Outcome','followup_required'=>'Follow-up required','followup_date'=>'Follow-up date','followup_notes'=>'Follow-up instructions','incident_at'=>'Incident occurred','incident_location'=>'Location','circumstances'=>'Circumstances','accident_type'=>'Type of incident','first_aid'=>'First aid provided','actions_taken'=>'Actions taken','assistance_at'=>'Assistance time','response_notes'=>'Response notes','blood_pressure'=>'Blood pressure','pulse_rate'=>'Pulse rate','respiratory_rate'=>'Respiratory rate','temperature'=>'Temperature','oxygen_saturation'=>'Oxygen saturation','height_cm'=>'Height','weight_kg'=>'Weight','bmi'=>'BMI','physician_findings'=>'Clinical findings','physician_diagnosis'=>'Diagnosis','physician_plan'=>'Treatment plan','physician_advice'=>'Medical advice','physician_followup'=>'Recommended follow-up','rest_advised'=>'Rest advised','rest_duration'=>'Rest duration','rest_duration_unit'=>'Rest duration unit','rest_start_date'=>'Rest starts','rest_end_date'=>'Rest ends','rest_instructions'=>'Rest instructions','rest_followup'=>'Rest follow-up','work_advice'=>'Work advice','absence_start'=>'Absence starts','absence_end'=>'Absence ends','work_restrictions'=>'Work restrictions','accommodations'=>'Workplace accommodations'];
                                            $summaryLabel = $summaryLabels[$summaryKey] ?? ucwords(str_replace('_', ' ', (string) $summaryKey));
                                            $summaryNarrative[] = $summaryLabel . ': ' . $summaryValue;
                                        endforeach; ?>
                                        <article class="eh-medical-summary">
                                            <p class="eh-side-label"><?= htmlspecialchars((new DateTimeImmutable((string) $summaryVisit['visit_at'], new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A')) ?> · <?= htmlspecialchars($summaryVisitTypes[$summaryVisit['visit_type']] ?? 'Clinic visit') ?></p>
                                            <p class="eh-medical-summary-text">The employee visited the clinic for <?= nl2br(htmlspecialchars((string) ($summaryVisit['reason'] ?? 'a health concern'))) ?>.<?php if ($summaryNarrative !== []): ?> <?= nl2br(htmlspecialchars(implode('. ', $summaryNarrative))) ?>.<?php endif; ?></p>
                                        </article>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>
                    </div></div>
                </div>
                <?php endforeach; ?>
                <div class="<?= $healthTabClass('documents') ?>" data-tab="documents">
                    <div class="eh-card"><div class="eh-card-head"><h3>Medical Documents</h3></div><div class="eh-card-body">
                        <?php if ($allClinicVisitDocuments === []): ?>
                            <p class="eh-side-empty">No documents are recorded for this employee.</p>
                        <?php else: ?>
                            <div class="eh-table-wrap"><table class="eh-table"><thead><tr><th>Document</th><th>Date</th><th>Chief complaint</th></tr></thead><tbody>
                                <?php foreach ($allClinicVisitDocuments as $document): ?>
                                    <tr>
                                        <td><a href="lib/api/record-clinic-visit.php?employee_id=<?= (int) $selectedEmployeeId ?>&amp;document_id=<?= (int) $document['document_id'] ?>" rel="nofollow"><?= htmlspecialchars($document['document_type'] === 'medical_certificate' ? 'MedCert' : 'Fit-to-Work') ?></a></td>
                                        <td><?= htmlspecialchars(eh_fmt_date($document['visit_at'] ?? '', 'M j, Y')) ?></td>
                                        <td><?= htmlspecialchars((string) ($document['reason'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    </div></div>
                </div>
                <div class="<?= $healthTabClass('visits') ?>" data-tab="visits">
                    <?php if ($clinicVisitHistoryError): ?>
                        <div class="eh-card eh-clinic-visits-card">
                            <div class="eh-card-head"><h3>Clinic Visits</h3></div>
                            <div class="eh-card-body">
                                <p class="eh-side-empty">Visit history is unavailable. Apply the clinic visits migration and try again.</p>
                            </div>
                        </div>
                    <?php elseif ($clinicVisits === []): ?>
                        <div class="eh-visit-welcome" role="region" aria-label="Clinic visits">
                            <dotlottie-wc
                                class="eh-visit-welcome__animation"
                                src="https://lottie.host/0fc2f9c0-15e2-402f-9af1-60481ffd8c2f/wFhICjPOfF.lottie"
                                mode="forward"
                                speed="1"
                                loop
                                autoplay></dotlottie-wc>
                            <h3 class="eh-visit-welcome__title">No clinic visits recorded yet</h3>
                            <p class="eh-visit-welcome__message">
                                Start the visit log for <strong><?= htmlspecialchars(eh_full_name($selectedEmployee)) ?></strong>
                                by recording their first clinic visit.
                            </p>
                            <?php if ($canManageHealth): ?>
                                <a class="eh-btn eh-btn--primary eh-visit-welcome__action"
                                   href="?page=clinic-visit&amp;employee_id=<?= (int) $selectedEmployeeId ?>">
                                    <i class="fa-solid fa-notes-medical" aria-hidden="true"></i>
                                    Record Visit
                                </a>
                            <?php else: ?>
                                <p class="eh-visit-welcome__note">Visit recording is restricted to authorized clinic personnel.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="eh-card eh-clinic-visits-card">
                            <div class="eh-card-head">
                                <h3>Clinic Visits</h3>
                                <?php if ($canManageHealth): ?>
                                    <a class="eh-btn eh-btn--primary" href="?page=clinic-visit&employee_id=<?= (int) $selectedEmployeeId ?>">
                                        <i class="fa-solid fa-notes-medical" aria-hidden="true"></i>
                                        Record Clinic Visit
                                    </a>
                                <?php endif; ?>
                            </div>
                            <div class="eh-card-body">
                                <div class="eh-table-wrap"><table class="eh-table eh-visit-table"><thead><tr><th>Date and time</th><th>Visit type</th><th>Reason</th><th>Actions</th></tr></thead><tbody>
                                <?php foreach ($clinicVisits as $visitIndex => $visit): $visitTypeLabels = ['consultation'=>'Consultation','emergency'=>'Emergency','return_to_work'=>'Return to Work']; $visitDetailLabels = ['employee_report'=>'Employee-reported information','relevant_information'=>'Relevant information provided','additional_notes'=>'Additional notes','assistance_provided'=>'Assistance provided','advice_given'=>'Advice or instructions','referral'=>'External referral','referral_destination'=>'Referral destination','referral_details'=>'Referral details','outcome'=>'Outcome or disposition','followup_required'=>'Follow-up required','followup_date'=>'Follow-up date','followup_notes'=>'Follow-up notes','incident_at'=>'Incident date and time','incident_location'=>'Incident location','circumstances'=>'Circumstances','accident_type'=>'Type of accident or injury','first_aid'=>'First aid or basic assistance','actions_taken'=>'Actions taken','assistance_at'=>'Time assistance was provided','response_notes'=>'Response notes','blood_pressure'=>'Blood pressure','pulse_rate'=>'Pulse rate','respiratory_rate'=>'Respiratory rate','temperature'=>'Temperature','oxygen_saturation'=>'Oxygen saturation','height_cm'=>'Height (cm)','weight_kg'=>'Weight (kg)','bmi'=>'BMI (calculated)','physician_assessment'=>'Physician assessment performed','physician_name'=>'Physician name','physician_identifier'=>'Physician identifier','physician_assessment_at'=>'Physician assessment date and time','physician_findings'=>'Physician clinical findings','physician_diagnosis'=>'Physician-documented diagnosis','physician_plan'=>'Physician treatment or management plan','physician_advice'=>'Physician medical advice','physician_followup'=>'Physician-recommended follow-up','rest_advised'=>'Rest advised','rest_duration'=>'Rest duration','rest_duration_unit'=>'Rest duration unit','rest_start_date'=>'Rest start date','rest_end_date'=>'Rest end date','rest_instructions'=>'Rest instructions','rest_followup'=>'Rest recommendation follow-up','work_advice'=>'Work advice','work_return_at'=>'Recommended return-to-work date and time','work_rest_notes'=>'Work rest instructions or notes','work_referral_destination'=>'Work advice referral destination','work_referral_notes'=>'Work advice referral notes','work_referral_followup'=>'Work advice follow-up instructions','work_documentation_required'=>'Work documentation required','work_documentation_due'=>'Expected documentation date','work_documentation_notes'=>'Work documentation notes','consult_work_restrictions'=>'Consultation work restrictions','work_advice_notes'=>'Other work advice','external_contacted'=>'External service contacted','transfer_occurred'=>'Transfer or transport','transfer_details'=>'Transfer details','contact_person'=>'Person or service contacted','followup_instructions'=>'Follow-up instructions','absence_start'=>'Absence start date','absence_end'=>'Absence end date','expected_return_date'=>'Expected or documented return date','documentation_status'=>'Documentation status','documentation_notes'=>'Documentation notes','work_restrictions'=>'Documented work restrictions','accommodations'=>'Recommended accommodations','supporting_notes'=>'Supporting documentation or notes']; $visitDetails = json_decode((string) ($visit['details_payload'] ?? ''), true); ?>
                                    <tr>
                                        <td><?= htmlspecialchars((new DateTimeImmutable((string) $visit['visit_at'], new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A')) ?></td>
                                        <td><?= htmlspecialchars($visitTypeLabels[$visit['visit_type']] ?? 'Clinic visit') ?></td>
                                        <td><?= nl2br(htmlspecialchars((string) $visit['reason'])) ?><?php if (is_array($visitDetails) && $visitDetails !== []): ?><details class="eh-visit-details"><summary>View visit details</summary><dl><?php foreach ($visitDetails as $detailKey => $detailValue): if (!isset($visitDetailLabels[$detailKey]) || $detailValue === null || $detailValue === '') continue; if (is_bool($detailValue)) $detailValue = $detailValue ? 'Yes' : 'No'; elseif (!is_scalar($detailValue)) continue; if ($detailKey === 'documentation_status') $detailValue = ['documentation_received'=>'Documentation received','awaiting_documentation'=>'Awaiting documentation','referred_for_assessment'=>'Referred for assessment','other'=>'Other'][$detailValue] ?? $detailValue; if ($detailKey === 'physician_assessment') $detailValue = ['yes'=>'Yes','no'=>'No'][$detailValue] ?? $detailValue; if ($detailKey === 'rest_advised') $detailValue = ['not_specified'=>'Not specified','yes'=>'Yes','no'=>'No'][$detailValue] ?? $detailValue; if ($detailKey === 'work_advice') $detailValue = ['not_specified'=>'Not specified','usual'=>'May return to work as usual','rest'=>'Advised to rest before returning to work','assessment'=>'Referred for medical assessment','documentation'=>'Return-to-work documentation required','restrictions'=>'Documented work restrictions','other'=>'Other'][$detailValue] ?? $detailValue; if ($detailKey === 'rest_duration_preset') $detailValue = ['2_hours'=>'2 hours','4_hours'=>'4 hours','half_day'=>'Half day','1_day'=>'1 day','2_days'=>'2 days','3_days'=>'3 days','custom'=>'Custom'][$detailValue] ?? $detailValue; ?><div><dt><?= htmlspecialchars($visitDetailLabels[$detailKey]) ?></dt><dd><?= nl2br(htmlspecialchars((string) $detailValue)) ?></dd></div><?php if ($detailKey === 'followup_notes'): ?><div class="eh-view-row"><dd><button type="button" class="eh-btn eh-btn--primary eh-set-followup-btn" data-visit-id="<?= (int) $visit['visit_id'] ?>">Set Follow-up</button></dd></div><?php endif; ?><?php endforeach; ?></dl></details><?php endif; ?></td>
                                        <?php if ($visitIndex === 0): $actionsCell = ''; if (is_array($visitDetails) && !empty($visitDetails['rest_advised'] ?? '') && (string) $visitDetails['rest_advised'] === 'yes'): $actionsCell .= '<button type="button" class="eh-btn eh-btn--primary eh-return-to-work-btn" data-visit-id="' . (int) $visit['visit_id'] . '" aria-label="Return to Work" title="Return to Work"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i></button>'; endif; if ($actionsCell !== '') $actionsCell .= ' '; $actionsCell .= '<button type="button" class="eh-btn eh-edit-visit-btn" data-visit-id="' . (int) $visit['visit_id'] . '" data-visit-type="' . htmlspecialchars((string) $visit['visit_type'], ENT_QUOTES, 'UTF-8') . '" aria-label="Edit visit" title="Edit visit"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i></button>'; else: $actionsCell = '<button type="button" class="eh-btn eh-delete-visit-btn" data-visit-id="' . (int) $visit['visit_id'] . '" data-csrf-token="' . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '" aria-label="Delete visit" title="Delete visit"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>'; endif; ?>
                                        <td><?= $actionsCell ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody></table></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
            <?php endif; ?>
        </div>
        <!-- ── Side column ── -->
        <aside class="eh-col-side" aria-label="Employee health summary">
            <div class="eh-profile-head eh-profile-head--colside">
                                <div class="eh-profile-identity eh-profile-identity--stacked">
            <div class="eh-avatar" aria-hidden="true"><?= htmlspecialchars($employeeInitials !== '' ? $employeeInitials : 'E') ?></div>
            <div class="eh-profile-heading">
                <h2 class="eh-profile-name"><?= htmlspecialchars(eh_full_name($selectedEmployee)) ?></h2>
                <p class="eh-profile-employee-id">Employee ID <?= (int) $selectedEmployee['employee_id'] ?></p>
                <p class="eh-profile-position"><?= htmlspecialchars((string) ($selectedEmployee['position_name'] ?? '—')) ?></p>
            </div>
        </div>

        <div class="eh-profile-divider" role="presentation"></div>

        <dl class="eh-profile-info">
            <div class="eh-info-item">
                <dt class="eh-info-label">Employee No.</dt>
                <dd class="eh-info-value eh-mono"><?= htmlspecialchars((string) ($selectedEmployee['employee_code'] ?? '—')) ?></dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Department</dt>
                <dd class="eh-info-value"><?= htmlspecialchars((string) ($selectedEmployee['department_name'] ?? '—')) ?></dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Gender</dt>
                <dd class="eh-info-value"><?= eh_view_value($selectedEmployee['gender'] ?? null) ?></dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Birth date</dt>
                <dd class="eh-info-value">
                    <?= eh_fmt_date($selectedEmployee['birth_date'] ?? null) !== ''
                        ? htmlspecialchars(eh_fmt_date($selectedEmployee['birth_date']))
                        : '<span class="eh-view-missing">Not recorded</span>' ?>
                </dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Email Address</dt>
                <dd class="eh-info-value"><?= eh_view_value($selectedEmployee['email'] ?? null) ?></dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Contact No.</dt>
                <dd class="eh-info-value"><?= eh_view_value($selectedEmployee['mobile_no'] ?? null) ?></dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Emergency Contact No.</dt>
                <dd class="eh-info-value"><?= eh_view_value($profile['emergency_contact_phone'] ?? null) ?></dd>
            </div>
            <div class="eh-info-item">
                <dt class="eh-info-label">Civil Status</dt>
                <dd class="eh-info-value"><?= eh_view_value($selectedEmployee['civil_status'] ?? null) ?></dd>
            </div>
        </dl>
            </div>
            <?php if ($canViewMedical): ?>
            <div class="eh-side-card" data-eh-intro-card>
                <h3 class="eh-side-title">Profile Completion</h3>
                <?php if (empty($profile['profile_id'])): ?>
                    <p class="eh-side-empty">No health profile recorded yet. Completion is 0% until the clinic creates one.</p>
                <?php else: ?>
                    <div class="eh-meter" role="img" aria-label="<?= (int) $completion['percent'] ?>% complete" style="--eh-fill: <?= (int) $completion['percent'] ?>%">
                        <div class="eh-meter-fill"></div>
                    </div>
                    <p class="eh-side-metric">
                        <strong><?= (int) $completion['filled'] ?>/<?= (int) $completion['total'] ?></strong>
                        required fields recorded (<?= (int) $completion['percent'] ?>%)
                    </p>
                    <?php if ($completion['missing'] !== []): ?>
                        <p class="eh-side-label">Missing information</p>
                        <ul class="eh-side-list">
                            <?php foreach (array_slice($completion['missing'], 0, 5) as $missingItem): ?>
                                <li><?= htmlspecialchars($missingItem) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="eh-side-empty">All required profile fields are recorded.</p>
                    <?php endif; ?>
                    <p class="eh-side-note">Last updated <?= eh_fmt_date($profile['updated_at']) !== '' ? htmlspecialchars(eh_fmt_date($profile['updated_at'])) : '—' ?></p>
                <?php endif; ?>
            </div>

            <div class="eh-side-card">
                <h3 class="eh-side-title">Follow-ups</h3>
                <?php if ($sideData['exam_followups'] === [] && $sideData['next_doses'] === []): ?>
                    <p class="eh-side-empty">No upcoming follow-up or next dose is scheduled for this employee.</p>
                <?php else: ?>
                    <?php if ($sideData['exam_followups'] !== []): ?>
                        <p class="eh-side-label">Examination follow-ups</p>
                        <ul class="eh-side-list">
                            <?php foreach ($sideData['exam_followups'] as $followUp): ?>
                                <?php $isOverdue = strtotime((string) $followUp['follow_up_date']) < strtotime('today'); ?>
                                <li>
                                    <span class="eh-side-item-title"><?= htmlspecialchars((string) $followUp['exam_type']) ?></span>
                                    <span class="eh-stamp <?= $isOverdue ? 'eh-stamp--danger' : 'eh-stamp--warning' ?>">
                                        <?= $isOverdue ? 'Overdue' : 'Upcoming' ?> <?= htmlspecialchars(eh_fmt_date($followUp['follow_up_date'])) ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <?php if ($sideData['next_doses'] !== []): ?>
                        <p class="eh-side-label">Immunization next doses</p>
                        <ul class="eh-side-list">
                            <?php foreach ($sideData['next_doses'] as $dose): ?>
                                <li>
                                    <span class="eh-side-item-title"><?= htmlspecialchars((string) $dose['vaccine_name']) ?> (dose <?= (int) $dose['dose_number'] ?>)</span>
                                    <span class="eh-side-item-meta"><?= htmlspecialchars(eh_fmt_date($dose['next_dose_due'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($pendingRequirements !== []): ?>
                    <p class="eh-side-label">Mandatory examinations not on record</p>
                    <ul class="eh-side-list">
                        <?php foreach ($pendingRequirements as $requirement): ?>
                            <li><?= htmlspecialchars((string) $requirement['exam_type']) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
        </div>

            <div class="eh-side-card">
                <h3 class="eh-side-title">Recent Record Activity</h3>
                <?php if ($sideData['activity'] === []): ?>
                    <p class="eh-side-empty">No health-record activity has been logged for this employee yet.</p>
                <?php else: ?>
                    <ul class="eh-side-list eh-side-list--activity">
                        <?php foreach ($sideData['activity'] as $activity): ?>
                            <li>
                                <span class="eh-side-item-title"><?= htmlspecialchars($activityLabels[(string) $activity['action']] ?? (string) $activity['action']) ?></span>
                                <span class="eh-side-item-meta">
                                    <?= htmlspecialchars(eh_fmt_date($activity['created_at'], 'M j, Y g:i A')) ?>
                                    <?php if (!empty($activity['actor_role'])): ?>
                                        &middot; <?= htmlspecialchars((string) $activity['actor_role']) ?>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <?php if ($recordAccessNotice !== ''): ?>
                <p class="eh-side-privacy"><?= htmlspecialchars($recordAccessNotice) ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </aside>
    </div>
</section>
