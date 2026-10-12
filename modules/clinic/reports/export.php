<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../database/db.php';

function clinic_report_export_fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

try {
    $db = (new Database())->getConnection();
    if (!$db instanceof PDO) {
        throw new RuntimeException('The database connection is unavailable.');
    }

    $roleStatement = $db->prepare("SELECT setting_value FROM clinic_health_settings WHERE setting_key = 'medical_access_role_ids' LIMIT 1");
    $roleStatement->execute();
    $allowedRoleIds = array_values(array_filter(array_map('intval', explode(',', (string) $roleStatement->fetchColumn())), static fn(int $id): bool => $id > 0));
    $roleAllowed = $allowedRoleIds !== []
        ? in_array((int) ($_SESSION['role_id'] ?? 0), $allowedRoleIds, true)
        : in_array((string) ($_SESSION['role_name'] ?? ''), ['Clinic Staff', 'School Clinic Staff', 'System Administrator'], true);
    if (!$roleAllowed) {
        clinic_report_export_fail(403, 'You are not authorized to download clinic reports.');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        clinic_report_export_fail(405, 'Use the report download form to request an export.');
    }
    $sessionToken = (string) ($_SESSION['eh_csrf_token'] ?? '');
    $requestToken = (string) ($_POST['csrf_token'] ?? '');
    if ($sessionToken === '' || !hash_equals($sessionToken, $requestToken)) {
        clinic_report_export_fail(403, 'Your session token is invalid. Refresh the page and try again.');
    }
    $exportId = filter_var($_POST['export_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $format = $_POST['format'] ?? '';
    if ($exportId === false || !in_array($format, ['pdf', 'csv'], true)) {
        clinic_report_export_fail(400, 'Invalid clinic report export request.');
    }

    $statement = $db->prepare('SELECT report_title, period_start, period_end, format, report_data FROM clinic_report_exports WHERE export_id = :export_id LIMIT 1');
    $statement->execute([':export_id' => $exportId]);
    $report = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($report)) {
        clinic_report_export_fail(404, 'The requested report could not be found.');
    }
    $data = json_decode((string) $report['report_data'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || !isset($data['columns'], $data['rows']) || !is_array($data['columns']) || !is_array($data['rows'])) {
        throw new UnexpectedValueException('Stored report data has an invalid structure.');
    }

    $title = (string) $report['report_title'];
    $from = date('M j, Y', strtotime((string) $report['period_start']));
    $to = date('M j, Y', strtotime((string) $report['period_end']));
    $filenameBase = preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)) ?: 'clinic-report';
    if ($format === 'csv') {
        $lines = [array_merge([$title], []), ['Reporting period', $from . ' - ' . $to], [], $data['columns']];
        foreach ($data['rows'] as $row) {
            if (!is_array($row)) continue;
            $lines[] = array_map(static function (mixed $cell): string {
                $value = trim((string) $cell);
                if (preg_match('/^[\s]*[=+\-@]/', $value) === 1) $value = "'" . $value;
                return $value;
            }, $row);
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new RuntimeException('Unable to prepare the CSV export.');
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($lines as $line) fputcsv($stream, $line, ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        if ($csv === false) throw new RuntimeException('Unable to read the CSV export.');
        $update = $db->prepare("UPDATE clinic_report_exports SET format = 'Excel (CSV)' WHERE export_id = :export_id");
        $update->execute([':export_id' => $exportId]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filenameBase . '-' . date('Ymd', strtotime((string) $report['period_start'])) . '.csv"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
        exit;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('The PDF export dependency is not installed.');
    }
    require_once $autoload;
    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new RuntimeException('The PDF export library is unavailable.');
    }
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>'
        . 'body{font:11px DejaVu Sans,sans-serif;color:#172b49}h1{font-size:18px;color:#174b91}'
        . 'p{color:#50627a}table{width:100%;border-collapse:collapse;margin-top:18px}'
        . 'th,td{padding:7px;border:1px solid #dbe4ef;text-align:left}th{background:#edf3fa}'
        . '</style></head><body><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
        . '<p>Reporting period: ' . htmlspecialchars($from . ' - ' . $to, ENT_QUOTES, 'UTF-8') . '</p><table><thead><tr>';
    foreach ($data['columns'] as $column) {
        $html .= '<th>' . htmlspecialchars((string) $column, ENT_QUOTES, 'UTF-8') . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($data['rows'] as $row) {
        if (!is_array($row)) continue;
        $html .= '<tr>';
        foreach ($row as $cell) $html .= '<td>' . htmlspecialchars((string) $cell, ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '</tr>';
    }
    if ($data['rows'] === []) $html .= '<tr><td colspan="' . count($data['columns']) . '">No records match this report and period.</td></tr>';
    $html .= '</tbody></table></body></html>';
    $options = new \Dompdf\Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $pdf = $dompdf->output();
    $update = $db->prepare("UPDATE clinic_report_exports SET format = 'PDF' WHERE export_id = :export_id");
    $update->execute([':export_id' => $exportId]);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filenameBase . '-' . date('Ymd', strtotime((string) $report['period_start'])) . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (Throwable $e) {
    error_log('Clinic report export failed: ' . $e->getMessage());
    clinic_report_export_fail(500, 'The report export could not be completed. Please try again or contact the system administrator.');
}
