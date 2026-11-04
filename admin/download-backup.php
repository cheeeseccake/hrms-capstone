<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('backup.manage');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method not allowed.');
}

$csrfToken = $_POST['csrf_token'] ?? null;

if (!lcAdminVerifyCsrf(is_string($csrfToken) ? $csrfToken : null)) {
    http_response_code(403);
    exit('Invalid or expired security token. Refresh the page and try again.');
}

$storage = $_POST['storage'] ?? '';
$filename = $_POST['filename'] ?? '';
$action = $_POST['action'] ?? '';

if (
    !is_string($storage) ||
    !in_array($storage, ['daily', 'weekly'], true) ||
    !is_string($filename) ||
    !preg_match(
        '/^hrms-[A-Za-z0-9_-]+-[0-9]{8}-[0-9]{6}\.sql\.gz\.gpg$/',
        $filename
    ) ||
    !is_string($action) ||
    !in_array($action, ['archive', 'checksum'], true)
) {
    http_response_code(400);
    exit('Invalid backup download request.');
}

$backupRoot = dirname(__DIR__, 2) . '/private_backups/hrms';
$resolvedRoot = realpath($backupRoot);
$storagePath = $backupRoot . DIRECTORY_SEPARATOR . $storage;
$resolvedDirectory = realpath($storagePath);

if (
    $resolvedRoot === false ||
    $resolvedDirectory === false ||
    !is_dir($resolvedDirectory) ||
    dirname($resolvedDirectory) !== $resolvedRoot ||
    !is_readable($resolvedDirectory)
) {
    http_response_code(404);
    exit('Backup storage is unavailable.');
}

$archivePath = $resolvedDirectory . DIRECTORY_SEPARATOR . $filename;
$checksumPath = $archivePath . '.sha256';

if (
    is_link($archivePath) ||
    !is_file($archivePath) ||
    !is_readable($archivePath) ||
    realpath($archivePath) === false ||
    dirname((string) realpath($archivePath)) !== $resolvedDirectory
) {
    http_response_code(404);
    exit('Backup archive not found.');
}

if (
    is_link($checksumPath) ||
    !is_file($checksumPath) ||
    !is_readable($checksumPath) ||
    realpath($checksumPath) === false ||
    dirname((string) realpath($checksumPath)) !== $resolvedDirectory
) {
    http_response_code(409);
    exit('A valid checksum file is required before downloading this archive.');
}

$checksumContents = trim((string) file_get_contents($checksumPath));

if (
    !preg_match(
        '/^([a-f0-9]{64})\s{2}(.+)$/i',
        $checksumContents,
        $matches
    ) ||
    !hash_equals($filename, trim($matches[2]))
) {
    http_response_code(409);
    exit('The backup checksum file is invalid.');
}

$actualHash = hash_file('sha256', $archivePath);

if (
    $actualHash === false ||
    !hash_equals(strtolower($matches[1]), strtolower($actualHash))
) {
    http_response_code(409);
    exit('Checksum verification failed. Download blocked.');
}

$downloadPath = $action === 'checksum'
    ? $checksumPath
    : $archivePath;

$downloadName = $action === 'checksum'
    ? $filename . '.sha256'
    : $filename;

$fileSize = filesize($downloadPath);

if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to determine download size.');
}

/*
 * Log the download before sending the file.
 * If logging fails, the download is blocked.
 */
try {
    $pdo = lcAdminDatabase();

    lcAdminLogActivity(
        $pdo,
        (int) $_SESSION['lc_admin']['id'],
        'backup_download',
        'Downloaded ' . ($action === 'checksum' ? 'checksum' : 'encrypted backup archive')
            . ': ' . $filename . ' (' . $storage . ').'
    );
} catch (Throwable $e) {
    error_log('Backup download audit logging failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Download is temporarily unavailable because the audit log could not be saved.');
}

session_write_close();

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header(
    'Content-Disposition: attachment; filename="' . $downloadName . '"'
);
header('Content-Length: ' . $fileSize);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

readfile($downloadPath);
exit;
