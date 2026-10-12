<section class="eh-module employee-health employee-health--search" id="employeeHealthRoot">
    <?php if ($employeeNotFound): ?>
        <div class="eh-flash eh-flash--error" role="alert" data-eh-flash="error">
            The requested employee could not be found.
        </div>
    <?php endif; ?>
    <?php if ($searchError !== ''): ?>
        <div class="eh-flash eh-flash--error" role="alert" data-eh-flash="error"><?= htmlspecialchars($searchError) ?></div>
    <?php endif; ?>

    <div class="eh-row">
        <!-- ── Main column ── -->
        <div class="eh-col-main">
            

            <div class="eh-card">
                <div class="eh-card-head">
                    <h3>Find Employee</h3>
                </div>
                <div class="eh-card-body">
                    <form class="eh-search-form" method="get" action="index.php" data-skip>
                        <input type="hidden" name="page" value="employee-health">
                        <div class="eh-search-field eh-search-field--wide">
                            <label for="ehSearchInput">Employee number, code, or name</label>
                            <input type="search" id="ehSearchInput" name="q" maxlength="100" autocomplete="off"
                                   placeholder="e.g. EMP-000029 or Garcia"
                                   value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        <div class="eh-search-field">
                            <label for="ehSearchStatus">Employment status</label>
                            <select id="ehSearchStatus" name="employment_status">
                                <option value="">All statuses</option>
                                <?php foreach ($allowedStatuses as $statusOption): ?>
                                    <option value="<?= $statusOption ?>"<?= eh_selected($statusFilter, $statusOption) ?>><?= $statusOption ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="eh-search-actions">
                            <button type="submit" class="eh-btn eh-btn--primary">
                                <i class="fa-solid fa-magnifying-glass"></i> Search
                            </button>
                            <a class="eh-btn eh-btn--ghost" href="?page=employee-health">Reset</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="eh-card">
                <div class="eh-card-head">
                    <h3>Employee Results</h3>
                    <span class="eh-result-count" data-employee-result-count<?= $totalRows > 0 ? '' : ' hidden' ?>>
                            <?= number_format($totalRows) ?> record<?= $totalRows === 1 ? '' : 's' ?> found
                    </span>
                </div>
                <div class="eh-card-body" data-employee-results aria-live="polite">
                    <?php if (!$canAccessRegistry): ?>
                        <div class="eh-empty">You are not authorized to search the employee health registry.</div>
                    <?php elseif (!$searchSubmitted): ?>
                        <div class="eh-empty">Enter an employee number, employee code, or name to search the registry.</div>
                    <?php elseif ($results === []): ?>
                        <div class="eh-empty">
                            No employees found matching &ldquo;<?= htmlspecialchars($searchTerm) ?>&rdquo;.
                        </div>
                    <?php else: ?>
                        <div class="eh-table-wrap">
                            <table class="eh-table">
                                <thead>
                                    <tr>
                                        <th>Employee No.</th>
                                        <th>Employee</th>
                                        <th>Department</th>
                                        <th>Position</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results as $row): ?>
                                        <tr>
                                            <td data-label="Employee No." class="eh-mono"><?= htmlspecialchars((string) ($row['employee_code'] ?? '—')) ?></td>
                                            <td data-label="Employee"><?= htmlspecialchars(eh_full_name($row)) ?></td>
                                            <td data-label="Department"><?= htmlspecialchars((string) ($row['department_name'] ?? '—')) ?></td>
                                            <td data-label="Position"><?= htmlspecialchars((string) ($row['position_name'] ?? '—')) ?></td>
                                            <td data-label="Status">
                                                <span class="eh-stamp <?= eh_status_stamp_class((string) ($row['employment_status'] ?? '')) ?>">
                                                    <?= htmlspecialchars((string) ($row['employment_status'] ?? '—')) ?>
                                                </span>
                                            </td>
                                            <td data-label="Action">
                                                <a class="eh-action-link" href="?page=employee-health&employee_id=<?= (int) $row['employee_id'] ?>&q=<?= urlencode($searchTerm) ?><?= $statusFilter !== '' ? '&employment_status=' . urlencode($statusFilter) : '' ?>">
                                                    View Health Profile
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <div class="eh-pagination">
                                <span class="eh-pagination-info">
                                    Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?>
                                    of <?= number_format($totalRows) ?> records
                                </span>
                                <nav class="eh-pagination-nav" role="navigation" aria-label="Employee search pagination">
                                    <a href="<?= $currentPage > 1 ? $paginationBase . '&emp_page=' . ($currentPage - 1) : '#' ?>"
                                       class="eh-page-btn" <?= $currentPage > 1 ? '' : 'aria-disabled="true"' ?>>
                                        <i class="fa-solid fa-angle-left"></i>
                                    </a>
                                    <?php
                                    $range = 2;
                                    $start = max(1, $currentPage - $range);
                                    $end = min($totalPages, $currentPage + $range);
                                    for ($i = $start; $i <= $end; $i++):
                                    ?>
                                        <a href="<?= $paginationBase . '&emp_page=' . $i ?>"
                                           class="eh-page-btn <?= $i === $currentPage ? 'eh-page-btn--active' : '' ?>"><?= $i ?></a>
                                    <?php endfor; ?>
                                    <a href="<?= $currentPage < $totalPages ? $paginationBase . '&emp_page=' . ($currentPage + 1) : '#' ?>"
                                       class="eh-page-btn" <?= $currentPage < $totalPages ? '' : 'aria-disabled="true"' ?>>
                                        <i class="fa-solid fa-angle-right"></i>
                                    </a>
                                </nav>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── Side column: search guidance and real registry figures ── -->
        <aside class="eh-col-side" aria-label="Search guidance">
            <div class="eh-side-card">
                <h3 class="eh-side-title">How to Search</h3>
                <ul class="eh-side-list">
                    <li>Search by employee number (EMP-000029) or the numeric employee ID.</li>
                    <li>Partial names work — try a surname such as &ldquo;Garcia&rdquo;.</li>
                    <li>Filter by employment status to narrow results.</li>
                    <li>Select an employee to open their clinic health profile.</li>
                </ul>
            </div>

            <div class="eh-side-card">
                <h3 class="eh-side-title">Registry</h3>
                <p class="eh-side-metric">
                    <strong><?= number_format($activeEmployeeCount) ?></strong>
                    active employee<?= $activeEmployeeCount === 1 ? '' : 's' ?> in the HRMS registry
                </p>
                <p class="eh-side-note">Results are limited to 10 per page and exclude archived employees.</p>
            </div>

            <?php if ($recordAccessNotice !== ''): ?>
                <p class="eh-side-privacy"><?= htmlspecialchars($recordAccessNotice) ?></p>
            <?php endif; ?>
        </aside>
    </div>
</section>
