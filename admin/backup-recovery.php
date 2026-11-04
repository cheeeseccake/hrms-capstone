<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('backup.view');

$canManageBackups = lcAdminHasPermission('backup.manage');
$downloadCsrfToken = $canManageBackups ? lcAdminCsrfToken() : '';

function brEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function brFormatSize(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }

    return $bytes . ' bytes';
}

/*
 * Read-only archive discovery.
 * The backup folders remain outside the public web directory.
 * No archive download, deletion, or restore action is exposed here.
 */
$backupRoot = dirname(__DIR__, 2) . '/private_backups/hrms';

$archiveDirectories = [
    'Daily' => $backupRoot . '/daily',
    'Weekly' => $backupRoot . '/weekly',
];

$archives = [];
$archiveScanAvailable = true;

foreach ($archiveDirectories as $archiveType => $directory) {
    if (!is_dir($directory) || !is_readable($directory)) {
        $archiveScanAvailable = false;
        continue;
    }

    $files = glob($directory . '/hrms-*.sql.gz.gpg');

    if ($files === false) {
        $archiveScanAvailable = false;
        continue;
    }

    foreach ($files as $file) {
        $filename = basename($file);

        if (
            !is_file($file) ||
            !is_readable($file) ||
            !preg_match(
                '/^hrms-[A-Za-z0-9_-]+-[0-9]{8}-[0-9]{6}\.sql\.gz\.gpg$/',
                $filename
            )
        ) {
            continue;
        }

        $checksumFile = $file . '.sha256';
        $checksumStatus = 'Missing';
        $checksumClass = 'neutral';

        if (is_file($checksumFile) && is_readable($checksumFile)) {
            $checksumContents = trim((string) file_get_contents($checksumFile));

            if (
                preg_match(
                    '/^([a-f0-9]{64})\s{2}(.+)$/i',
                    $checksumContents,
                    $matches
                ) &&
                hash_equals($filename, trim($matches[2]))
            ) {
                $actualHash = hash_file('sha256', $file);

                if ($actualHash !== false && hash_equals(
                    strtolower($matches[1]),
                    strtolower($actualHash)
                )) {
                    $checksumStatus = 'Verified';
                    $checksumClass = 'success';
                } else {
                    $checksumStatus = 'Mismatch';
                    $checksumClass = 'inactive';
                }
            } else {
                $checksumStatus = 'Invalid checksum file';
                $checksumClass = 'inactive';
            }
        }

        $modifiedAt = filemtime($file);

        $archives[] = [
            'filename' => $filename,
            'type' => $archiveType,
            'size' => (int) filesize($file),
            'modified' => $modifiedAt !== false ? $modifiedAt : 0,
            'checksum_status' => $checksumStatus,
            'checksum_class' => $checksumClass,
        ];
    }
}

usort(
    $archives,
    static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']
);

$totalArchives = count($archives);
$verifiedArchives = count(array_filter(
    $archives,
    static fn(array $archive): bool =>
        $archive['checksum_status'] === 'Verified'
));

$latestBackup = $totalArchives > 0
    ? $archives[0]['modified']
    : null;

$latestBackupLabel = $latestBackup !== null
    ? date('M d, Y h:i A', $latestBackup)
    : 'No archive found';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">

    <title>Backup &amp; Recovery | Bestlink HRMS</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="/admin/admin-content-layout.css?v=20261102-backup-ui">
    <link rel="stylesheet" href="/modules/compliance/css/pages/admin-topbar.css?v=20261102-backup-ui">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f3f6fb;
            color: #1d2b42;
            font-family: Montserrat, Arial, sans-serif;
            font-size: 12px;
        }

        .page-head h1 {
            margin: 0;
            font-size: 22px;
            font-weight: 800;
        }

        .subtitle {
            margin: 8px 0 0;
            color: #718096;
            font-size: 11px;
            line-height: 1.7;
        }

        .admin-breadcrumb {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 9px;
            margin-left: auto;
            white-space: nowrap;
            font-size: 13px;
            line-height: 1.5;
            flex-shrink: 0;
        }

        .admin-breadcrumb a {
            color: #2563eb;
            text-decoration: none;
            font-weight: 600;
        }

        .admin-breadcrumb a:hover {
            text-decoration: underline;
        }

        .admin-breadcrumb span[aria-hidden="true"] {
            color: #94a3b8;
        }

        .admin-breadcrumb span[aria-current="page"] {
            color: #64748b;
            font-weight: 500;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .summary-card {
            min-width: 0;
            padding: 18px 20px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 8px;
        }

        .summary-label {
            color: #718096;
            font-size: 10px;
            font-weight: 600;
        }

        .summary-value {
            display: block;
            margin-top: 8px;
            color: #1d2b42;
            font-size: 21px;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .summary-meta {
            display: block;
            margin-top: 5px;
            color: #8290a2;
            font-size: 9px;
            line-height: 1.6;
        }

        .panel {
            min-width: 0;
            margin-bottom: 18px;
            padding: 22px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 8px;
        }

        .panel h2 {
            margin: 0 0 7px;
            font-size: 14px;
            font-weight: 700;
        }

        .panel-description {
            margin: 0 0 17px;
            color: #718096;
            font-size: 10px;
            line-height: 1.7;
        }

        .notice {
            margin-bottom: 16px;
            padding: 12px 14px;
            border: 1px solid #dce5f0;
            border-radius: 5px;
            background: #f8fafd;
            color: #52627a;
            font-size: 10px;
            line-height: 1.7;
        }

        .notice.warning {
            border-color: #f0dfbd;
            background: #fffaf0;
            color: #805b22;
        }

        .notice.success {
            border-color: #d6e9dc;
            background: #f3faf5;
            color: #326746;
        }

        .backup-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            min-width: 150px;
        }

        .backup-download-form {
            display: inline-flex;
            margin: 0;
        }

        .backup-download-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 9px;
            border: 1px solid #244d83;
            border-radius: 5px;
            background: #244d83;
            color: #fff;
            font-family: inherit;
            font-size: 9px;
            font-weight: 700;
            cursor: pointer;
        }

        .backup-download-btn.secondary {
            border-color: #dce5f0;
            background: #fff;
            color: #244d83;
        }

        .backup-download-btn:hover {
            opacity: .88;
        }

        .backup-download-btn:focus-visible {
            outline: 2px solid #79a9e8;
            outline-offset: 2px;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 760px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #edf0f5;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #f8fafd;
            color: #62728a;
            font-size: 9px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        td {
            color: #34445c;
            font-size: 10px;
        }

        td strong {
            display: block;
            color: #1d2b42;
            font-size: 10px;
            overflow-wrap: anywhere;
        }

        td small {
            display: block;
            margin-top: 4px;
            color: #78869a;
            font-size: 9px;
        }

        .status {
            display: inline-block;
            padding: 5px 8px;
            border-radius: 4px;
            background: #edf7f0;
            color: #27623b;
            font-size: 9px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status.inactive {
            background: #fff0f0;
            color: #a33a3a;
        }

        .status.neutral {
            background: #f0f2f5;
            color: #596579;
        }

        .muted {
            color: #8290a2;
        }

        .empty-state {
            padding: 28px 15px;
            color: #8290a2;
            text-align: center;
            line-height: 1.8;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .info-card {
            min-width: 0;
            padding: 19px 20px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 8px;
        }

        .info-card h3 {
            margin: 0 0 8px;
            color: #1d2b42;
            font-size: 12px;
            font-weight: 700;
        }

        .info-card p {
            margin: 0;
            color: #718096;
            font-size: 10px;
            line-height: 1.8;
        }

        .info-card .info-status {
            display: inline-block;
            margin-top: 13px;
        }

        .panel-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-top: 16px;
            padding-top: 13px;
            border-top: 1px solid #edf0f5;
            color: #8290a2;
            font-size: 9px;
            line-height: 1.7;
        }

        .back-link {
            display: inline-block;
            margin-top: 2px;
            color: #244d83;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
        }

        .back-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 700px) {
            main.content {
                padding: 60px 15px 24px;
            }

            main.content > .page-head {
                display: block;
            }

            .admin-breadcrumb {
                justify-content: flex-start;
                margin: 13px 0 0;
                white-space: normal;
                flex-wrap: wrap;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 16px;
            }

            .panel-footer {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<?php require __DIR__ . '/includes/admin-topbar.php'; ?>

<main class="content">

    <div class="page-head">
        <div>
            <h1>Backup &amp; Recovery</h1>
            <p class="subtitle">
                Monitor encrypted Legal and Compliance database backups and recovery readiness.
            </p>
        </div>

        <nav class="admin-breadcrumb" aria-label="Breadcrumb">
            <a href="/admin/">Dashboard</a>
            <span aria-hidden="true">›</span>
            <span aria-current="page">Backup &amp; Recovery</span>
        </nav>
    </div>

    <div class="summary-grid">

        <section class="summary-card">
            <span class="summary-label">Available Backup Archives</span>
            <strong class="summary-value"><?= $totalArchives ?></strong>
            <span class="summary-meta">Encrypted archives discovered in private storage</span>
        </section>

        <section class="summary-card">
            <span class="summary-label">Checksum Verified</span>
            <strong class="summary-value"><?= $verifiedArchives ?></strong>
            <span class="summary-meta">Archives matching their SHA-256 sidecar files</span>
        </section>

        <section class="summary-card">
            <span class="summary-label">Latest Backup</span>
            <strong class="summary-value" style="font-size:16px;">
                <?= brEscape($latestBackupLabel) ?>
            </strong>
            <span class="summary-meta">Based on the archive file modification time</span>
        </section>

    </div>

    <section class="panel">
        <h2>Backup Archive History</h2>
        <p class="panel-description">
            Encrypted backup files currently detected in the private daily and weekly storage directories.
        </p>

        <?php if (!$archiveScanAvailable): ?>
            <div class="notice warning">
                One or more backup storage directories could not be read.
                The archive list may be incomplete. Check the server-side directory permissions.
            </div>
        <?php endif; ?>

        <?php if ($totalArchives > 0 && $verifiedArchives === $totalArchives): ?>
            <div class="notice success">
                All <?= $totalArchives ?> detected archive(s) currently match their SHA-256 checksum files.
                This confirms file integrity only; it does not establish successful database restoration.
            </div>
        <?php elseif ($totalArchives > 0): ?>
            <div class="notice warning">
                Some archives are missing valid checksum verification.
                Review the integrity status shown in the archive table.
            </div>
        <?php endif; ?>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Backup Archive</th>
                        <th>Storage</th>
                        <th>File Size</th>
                        <th>Date Created / Modified</th>
                        <th>Checksum</th>
                        <?php if ($canManageBackups): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$archives): ?>
                        <tr>
                            <td colspan="<?= $canManageBackups ? 6 : 5 ?>">
                                <div class="empty-state">
                                    <?= $archiveScanAvailable
                                        ? 'No encrypted backup archives were found.'
                                        : 'Archive information is currently unavailable.' ?>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($archives as $archive): ?>
                        <tr>
                            <td>
                                <strong><?= brEscape($archive['filename']) ?></strong>
                                <small>Encrypted GPG archive</small>
                            </td>

                            <td><?= brEscape($archive['type']) ?></td>

                            <td><?= brEscape(brFormatSize($archive['size'])) ?></td>

                            <td>
                                <?= $archive['modified'] > 0
                                    ? brEscape(date('M d, Y h:i A', $archive['modified']))
                                    : '<span class="muted">Unavailable</span>' ?>
                            </td>

                            <td>
                                <span class="status <?= brEscape($archive['checksum_class']) ?>">
                                    <?= brEscape($archive['checksum_status']) ?>
                                </span>
                            </td>

                            <?php if ($canManageBackups): ?>
                                <td>
                                    <?php if ($archive['checksum_status'] === 'Verified'): ?>
                                        <div class="backup-actions">
                                            <form
                                                class="backup-download-form"
                                                method="post"
                                                action="/admin/download-backup.php"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= brEscape($downloadCsrfToken) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="storage"
                                                    value="<?= brEscape(strtolower($archive['type'])) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="filename"
                                                    value="<?= brEscape($archive['filename']) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="archive"
                                                >
                                                <button
                                                    class="backup-download-btn"
                                                    type="submit"
                                                >
                                                    Download Archive
                                                </button>
                                            </form>

                                            <form
                                                class="backup-download-form"
                                                method="post"
                                                action="/admin/download-backup.php"
                                            >
                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= brEscape($downloadCsrfToken) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="storage"
                                                    value="<?= brEscape(strtolower($archive['type'])) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="filename"
                                                    value="<?= brEscape($archive['filename']) ?>"
                                                >
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="checksum"
                                                >
                                                <button
                                                    class="backup-download-btn secondary"
                                                    type="submit"
                                                >
                                                    Download SHA-256
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span class="muted">Download blocked: verify checksum</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="panel-footer">
            <span>Archive files are delivered through the permission-protected download endpoint.</span>
            <span><?= $totalArchives ?> archive(s) detected</span>
        </div>
    </section>

    <div class="info-grid">

        <section class="info-card">
            <h3>Backup Configuration</h3>
            <p>
                Scope: 67 Legal and Compliance database tables using the
                <code>lc_</code> prefix. Archives are compressed, encrypted,
                checksum-verified, and stored outside the public web directory.
            </p>
            <span class="status success info-status">Manual backup tested</span>
            <p style="margin-top:10px;">
                Automated scheduling has not been configured.
            </p>
            <span class="status neutral info-status">Schedule not configured</span>
        </section>

        <section class="info-card">
            <h3>Recovery Readiness</h3>
            <p>
                Archive decryption and compressed SQL integrity checks have passed.
                A complete isolated database restoration test is still pending.
            </p>
            <span class="status neutral info-status">Restore test pending</span>
            <p style="margin-top:10px;">
                Production data will not be used for recovery testing.
            </p>
        </section>

    </div>

    <section class="panel">
        <h2>Access &amp; Management</h2>
        <p class="panel-description">
            Backup management access is controlled by the System Administration role permissions.
        </p>

        <?php if ($canManageBackups): ?>
            <div class="notice">
                Your account has <code>backup.manage</code> permission.
                You can download checksum-verified encrypted archives and their SHA-256 files.
                Archive generation, deletion, and database restoration are intentionally not exposed here.
            </div>
        <?php else: ?>
            <div class="notice">
                Your account has view-only access to this page.
                Contact a Portal Administrator if you require backup management permissions.
            </div>
        <?php endif; ?>
    </section>


</main>

<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>

</body>
</html>
