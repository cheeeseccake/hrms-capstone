<?php
/**
 * Employee picker for the standalone clinic visit page.
 *
 * Included by views/clinic-visit/record.php when no employee is selected.
 *
 * @var string $searchTerm    Current search term.
 * @var array  $searchResults Matching employees.
 * @var string $searchError   Search validation message.
 * @var bool   $canAccessRegistry
 */
?>
<div class="eh-card">
    <div class="eh-card-head"><h3>Find Employee</h3></div>
    <div class="eh-card-body">
        <?php if ($searchError !== ''): ?>
            <div class="eh-flash eh-flash--error" role="alert" data-eh-flash="error"><?= htmlspecialchars($searchError) ?></div>
        <?php endif; ?>

        <?php if (!$canAccessRegistry): ?>
            <div class="eh-empty">You are not authorized to look up employees for clinic visits.</div>
        <?php else: ?>
            <form class="eh-search-form" method="get" action="index.php" data-skip>
                <input type="hidden" name="page" value="clinic-visit">
                <div class="eh-search-field eh-search-field--wide">
                    <label for="ehVisitSearchInput">Employee name or employee code</label>
                    <input type="search" id="ehVisitSearchInput" name="q" maxlength="100" autocomplete="off"
                           placeholder="e.g. EMP-000033 or Bautista"
                           value="<?= htmlspecialchars($searchTerm) ?>">
                </div>
                <div class="eh-search-actions">
                    <button type="submit" class="eh-btn eh-btn--primary">
                        <i class="fa-solid fa-magnifying-glass"></i> Search
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($canAccessRegistry): ?>
    <div class="eh-card">
        <div class="eh-card-head"><h3>Employee Results</h3></div>
        <div class="eh-card-body" data-employee-results aria-live="polite">
            <?php if ($searchResults === []): ?>
                <p class="eh-side-empty">No employees found matching &ldquo;<?= htmlspecialchars($searchTerm) ?>&rdquo;.</p>
            <?php else: ?>
                <div class="eh-table-wrap">
                    <table class="eh-table">
                        <thead>
                            <tr>
                                <th>Employee No.</th>
                                <th>Employee</th>
                                <th>Department</th>
                                <th>Position</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($searchResults as $row): ?>
                                <tr>
                                    <td data-label="Employee No." class="eh-mono"><?= htmlspecialchars((string) ($row['employee_code'] ?? '—')) ?></td>
                                    <td data-label="Employee"><?= htmlspecialchars(eh_full_name($row)) ?></td>
                                    <td data-label="Department"><?= htmlspecialchars((string) ($row['department_name'] ?? '—')) ?></td>
                                    <td data-label="Position"><?= htmlspecialchars((string) ($row['position_name'] ?? '—')) ?></td>
                                    <td data-label="Action">
                                        <a class="eh-action-link" href="?page=clinic-visit&employee_id=<?= (int) $row['employee_id'] ?>">
                                            Record Clinic Visit                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
