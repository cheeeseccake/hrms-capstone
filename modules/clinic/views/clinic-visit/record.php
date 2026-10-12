<?php
/**
 * Clinic visit recording page markup.
 *
 * Mirrors the Employee Health page layout (views/employee-health/profile.php):
 * a two-column eh-row with the visit log and recording form in the main column
 * and the employee summary in the side column.
 *
 * @var array|null $selectedEmployee       Selected employee row, or null.
 * @var bool       $employeeNotFound       True when the requested employee is unknown.
 * @var bool       $canManageHealth        True when the role may record visits.
 * @var bool       $canViewMedical         True when the role may read medical records.
 * @var array      $clinicVisits           Recorded visits for the employee.
 * @var array      $clinicVisitDocuments   Visit documents grouped by visit id.
 * @var bool       $clinicVisitHistoryError
 * @var string     $searchTerm             Current employee search term.
 * @var array      $searchResults          Employee search results.
 * @var string     $searchError            Search validation message.
 * @var string     $pageError              Page-level error message.
 * @var string     $recordAccessNotice     Configured privacy notice.
 * @var string     $employeeInitials       Avatar initials for the selected employee.
 * @var array      $clinicVisitTypeLabels   Visit-type labels for the summary card.
 */
?>
<?php
/* Same modifier classes the Employee Health views use, so this page inherits
   the identical blue card/table language (profile when an employee is
   selected, search when the employee lookup form is still showing). */
$clinicVisitEhModifier = $selectedEmployee !== null ? 'employee-health--profile' : 'employee-health--search';
?>
<section class="eh-module employee-health <?= $clinicVisitEhModifier ?>" id="employeeHealthRoot" data-employee-id="<?= (int) ($selectedEmployee['employee_id'] ?? 0) ?>">
    <?php if ($pageError !== ''): ?>
        <div class="eh-flash eh-flash--error" role="alert" data-eh-flash="error"><?= htmlspecialchars($pageError) ?></div>
    <?php endif; ?>

    <?php if ($selectedEmployee === null): ?>
        <div class="eh-toolbar">
            <div class="eh-toolbar-info">Select an employee to record a clinic visit</div>
        </div>

        <div class="eh-notice eh-notice--info">
            Recording a clinic visit starts from the employee it belongs to.
        </div>
        <?php include __DIR__ . '/search.php'; ?>
    <?php else: ?>
        <div class="eh-row">
            <!-- ── Main column: clinic visit recording form ── -->
            <div class="eh-col-main">
                <?php if (!$canManageHealth): ?>
                    <div class="eh-notice eh-notice--info">
                        Recording clinic visits is restricted to authorized clinic personnel.
                        Contact the school clinic if you need access to visit recording.
                    </div>
                <?php endif; ?>

                <?php if ($canManageHealth): ?>
                    <?php include dirname(__DIR__) . '/clinic-visit-dialog.php'; ?>
                <?php endif; ?>
            </div>

            <!-- ── Side column ── -->
            <aside class="eh-col-side" aria-label="Employee visit summary">
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
                            <dt class="eh-info-label">Status</dt>
                            <dd class="eh-info-value">
                                <span class="eh-stamp <?= eh_status_stamp_class((string) ($selectedEmployee['employment_status'] ?? '')) ?>">
                                    <?= htmlspecialchars((string) ($selectedEmployee['employment_status'] ?? '—')) ?>
                                </span>
                            </dd>
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
                            <dt class="eh-info-label">Civil Status</dt>
                            <dd class="eh-info-value"><?= eh_view_value($selectedEmployee['civil_status'] ?? null) ?></dd>
                        </div>
                    </dl>
                </div>

                <?php if ($canViewMedical): ?>
                    <div class="eh-side-card">
                        <h3 class="eh-side-title">Visit Log Summary</h3>
                        <p class="eh-side-label">Recorded visits</p>
                        <p class="eh-side-metric">
                            <strong><?= count($clinicVisits) ?></strong>
                            visit<?= count($clinicVisits) === 1 ? '' : 's' ?> on record
                            <?= $clinicVisits !== [] ? '(latest ' . htmlspecialchars(eh_fmt_date($clinicVisits[0]['visit_at'] ?? null, 'M j, Y')) . ')' : '' ?>
                        </p>
                        <?php if ($clinicVisitHistoryError): ?>
                            <p class="eh-side-empty">Visit history is unavailable right now.</p>
                        <?php elseif ($clinicVisits === []): ?>
                            <p class="eh-side-empty">No clinic visits are recorded yet. Use the form to record the first one.</p>
                        <?php else: ?>
                            <ul class="eh-side-list">
                                <?php foreach ($clinicVisitTypeLabels as $typeKey => $typeLabel): ?>
                                    <?php $typeCount = count(array_filter($clinicVisits, static function ($visit) use ($typeKey): bool {
                                        return ($visit['visit_type'] ?? '') === $typeKey;
                                    })); ?>
                                    <li>
                                        <span class="eh-side-item-title"><?= htmlspecialchars($typeLabel) ?></span>
                                        <span class="eh-side-item-meta"><?= (int) $typeCount ?> recorded</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <p class="eh-side-note">Showing the <?= count($clinicVisits) ?> most recent visits.</p>
                    </div>

                    <div class="eh-side-card">
                        <h3 class="eh-side-title">Recording a Visit</h3>
                        <ul class="eh-side-list">
                            <li>Consultation: symptoms, vitals, assistance, and follow-up advice.</li>
                            <li>Emergency: incident details, first aid, and external referrals.</li>
                            <li>Return to Work: absence dates, documentation, and attachments.</li>
                        </ul>
                        <p class="eh-side-note">Record only what was actually observed or reported. Visits can be edited by authorized clinic staff.</p>
                    </div>

                    <?php if ($recordAccessNotice !== ''): ?>
                        <p class="eh-side-privacy"><?= htmlspecialchars($recordAccessNotice) ?></p>
                    <?php endif; ?>
                <?php endif; ?>
            </aside>
        </div>
    <?php endif; ?>
</section>
