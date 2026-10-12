<div class="module-content">

    <div class="tab-container">
        <?php $this->renderTabNav([
            'clinic-visits' => 'Clinic Visits',
            'consultation' => 'Consultation / Check-up',
            'sick-leave-return' => 'Sick Leave Return',
            'emergency' => 'Emergency',
            'follow-up' => 'Follow-up',
            'appointments' => 'Appointments',
        ]); ?>

        <div class="tab-content active" data-tab="clinic-visits">
            <div class="content-header">
                <h2>Clinic Visits</h2>
                <div class="content-actions">
                    <button type="button" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Record Visit</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Visit No.</th>
                            <th>Employee</th>
                            <th>Complaint</th>
                            <th>Time In</th>
                            <th>Time Out</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-hospital"></i>
                                    <p>No clinic visits recorded today.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-content" data-tab="consultation">
            <div class="content-header">
                <h2>Consultation / Check-up</h2>
                <div class="content-actions">
                    <button type="button" class="btn btn-outline"><i class="fa-solid fa-filter"></i> Filter</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Vital Signs</th>
                            <th>Diagnosis</th>
                            <th>Medicine Given</th>
                            <th>Nurse</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-user-doctor"></i>
                                    <p>No consultations found.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-content" data-tab="sick-leave-return">
            <div class="content-header">
                <h2>Sick Leave Return</h2>
                <div class="content-actions">
                    <button type="button" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Return</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Leave Reference</th>
                            <th>Return Date</th>
                            <th>Cleared By</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-person-walking-arrow-right"></i>
                                    <p>No sick leave returns found.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-content" data-tab="emergency">
            <div class="content-header">
                <h2>Emergency</h2>
                <div class="content-actions">
                    <button type="button" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Log Emergency</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Case No.</th>
                            <th>Employee</th>
                            <th>Emergency Type</th>
                            <th>Response Time</th>
                            <th>Outcome</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-truck-medical"></i>
                                    <p>No emergency cases found.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-content" data-tab="follow-up">
            <div class="content-header">
                <h2>Follow-up</h2>
                <div class="content-actions">
                    <button type="button" class="btn btn-outline"><i class="fa-solid fa-filter"></i> Filter</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Last Visit</th>
                            <th>Follow-up Date</th>
                            <th>Notes</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                    <p>No pending follow-ups.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="tab-content" data-tab="appointments">
            <div class="content-header">
                <h2>Appointments</h2>
                <div class="content-actions">
                    <button type="button" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New Appointment</button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Employee</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <i class="fa-regular fa-calendar"></i>
                                    <p>No scheduled appointments.</p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>
