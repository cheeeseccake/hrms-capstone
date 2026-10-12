<?php
declare(strict_types=1);

final class ClinicEmergencyIncidentSync
{
    public static function sync(
        PDO $db,
        int $visitId,
        string $visitAt,
        string $reason,
        array $details,
        string $employeeName,
        string $employeeCode,
        string $department
    ): void {
        $payload = [
            'employee_name' => $employeeName,
            'employee_code' => $employeeCode,
            'visit_recorded_at' => $visitAt,
            'visit_reason' => $reason,
        ] + $details;
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($encodedPayload)) {
            throw new RuntimeException('Emergency incident details could not be prepared.');
        }

        $labels = [
            'employee_name' => 'Employee involved',
            'employee_code' => 'Employee code',
            'visit_recorded_at' => 'Clinic visit recorded at',
            'visit_reason' => 'Visit reason',
            'incident_at' => 'Incident date and time',
            'incident_location' => 'Incident location',
            'circumstances' => 'Circumstances',
            'accident_type' => 'Type of accident or injury',
            'employee_report' => 'Symptoms or concerns reported',
            'first_aid' => 'First aid or basic assistance provided',
            'actions_taken' => 'Actions taken by clinic staff',
            'assistance_at' => 'Time assistance was provided',
            'external_contacted' => 'External healthcare or emergency service contacted',
            'transfer_occurred' => 'Hospital transfer or emergency transport',
            'transfer_details' => 'Transport or transfer details',
            'blood_pressure' => 'Blood pressure',
            'pulse_rate' => 'Pulse rate (bpm)',
            'respiratory_rate' => 'Respiratory rate (/min)',
            'temperature' => 'Temperature (C)',
            'oxygen_saturation' => 'Oxygen saturation (%)',
            'outcome' => 'Outcome or disposition',
            'followup_required' => 'Follow-up required',
            'followup_date' => 'Recommended follow-up date',
            'followup_instructions' => 'Follow-up instructions and notes',
            'response_notes' => 'Additional response notes',
        ];
        $descriptionLines = ['Clinic emergency visit'];
        foreach ($payload as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
            }
            $descriptionLines[] = ($labels[$field] ?? ucwords(str_replace('_', ' ', $field))) . ': ' . (string) $value;
        }
        $description = implode("\n", $descriptionLines);
        $description = function_exists('mb_substr')
            ? mb_substr($description, 0, 16000, 'UTF-8')
            : substr($description, 0, 16000);

        $incidentAt = trim((string) ($details['incident_at'] ?? ''));
        $incidentTimestamp = $incidentAt !== '' ? $incidentAt : $visitAt;
        $incidentDate = substr($incidentTimestamp, 0, 10);
        $incidentTime = substr($incidentTimestamp, 11, 8);
        $incidentTitle = 'Clinic emergency - ' . (trim($reason) !== '' ? $reason : 'Emergency visit');
        $incidentTitle = function_exists('mb_substr')
            ? mb_substr($incidentTitle, 0, 255, 'UTF-8')
            : substr($incidentTitle, 0, 255);
        $incidentLocation = trim((string) ($details['incident_location'] ?? '')) ?: null;

        $statement = $db->prepare("
            INSERT INTO lc_incident_report
                (incident_id, source_clinic_visit_id, incident_type, type, severity, status,
                 requires_corrective_action, incident_date, incident_time, location, title,
                 description, clinic_details_payload, reporter_name, reporter_department,
                 assigned_to, assigned_name, created_at, updated_at)
            VALUES
                (:incident_id, :source_visit_id, 'Medical Emergency', 'Clinic Emergency', 'medium', 'submitted',
                 0, :incident_date, :incident_time, :location, :title,
                 :description, :clinic_details, :reporter_name, :reporter_department,
                 NULL, NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                incident_type = VALUES(incident_type),
                type = VALUES(type),
                incident_date = VALUES(incident_date),
                incident_time = VALUES(incident_time),
                location = VALUES(location),
                title = VALUES(title),
                description = VALUES(description),
                clinic_details_payload = VALUES(clinic_details_payload),
                reporter_name = VALUES(reporter_name),
                reporter_department = VALUES(reporter_department),
                updated_at = NOW()
        ");
        $statement->execute([
            ':incident_id' => 'CLINIC-' . $visitId,
            ':source_visit_id' => $visitId,
            ':incident_date' => $incidentDate,
            ':incident_time' => $incidentTime,
            ':location' => $incidentLocation,
            ':title' => $incidentTitle,
            ':description' => $description,
            ':clinic_details' => $encodedPayload,
            ':reporter_name' => $employeeName !== '' ? $employeeName : ($employeeCode !== '' ? $employeeCode : 'Clinic employee'),
            ':reporter_department' => $department,
        ]);
    }
}
