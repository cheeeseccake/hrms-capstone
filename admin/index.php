<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('dashboard.view');

$admin = $_SESSION['lc_admin'];
$permissions = $_SESSION['lc_admin_permissions'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Administrator Dashboard | Bestlink HRMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #f3f6fb;
            color: #1d2b42;
            font-family: Montserrat, Arial, sans-serif;
        }
        .topbar {
            min-height: 70px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 30px;
            background: #fff;
            border-bottom: 1px solid #e4eaf2;
        }
        .brand { font-size: 14px; font-weight: 800; }
        .brand span {
            display: block;
            margin-top: 4px;
            color: #718096;
            font-size: 10px;
            font-weight: 500;
        }
        .content {
            width: calc(100% - 40px);
            max-width: none;
            margin: 0 20px;
            padding: 32px 0 45px;
        }
        .welcome {
            width: 100%;
            margin: 0 0 25px;
            padding: 23px 25px;
            background: #fff;
            border: 1px solid #e4eaf2;
            border-radius: 7px;
        }
        .welcome h2 { margin: 0 0 9px; font-size: 16px; }
        .welcome p {
            margin: 0;
            color: #718096;
            font-size: 12px;
            line-height: 1.8;
        }
        .menu-grid {
            display: grid;
            width: 100%;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
        }
        .menu-tile {
            min-height: 178px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 15px;
            padding: 22px 14px;
            background: #fff;
            border: 1px solid #e1e8f1;
            border-radius: 7px;
            color: #244d83;
            text-align: center;
            text-decoration: none;
            transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }
        .menu-tile:hover {
            border-color: #9bb5d5;
            box-shadow: 0 5px 16px rgba(31, 65, 108, .08);
            transform: translateY(-2px);
        }
        .tile-icon {
            width: 54px;
            height: 54px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #dce6f2;
            border-radius: 6px;
            background: #f3f7fc;
            color: #244d83;
        }
        .tile-icon svg {
            width: 27px;
            height: 27px;
            fill: none;
            stroke: currentColor;
            stroke-width: 1.7;
            stroke-linecap: round;
            stroke-linejoin: round;
        }
        .tile-label { font-size: 12px; font-weight: 700; }
        .tile-description {
            margin-top: -9px;
            color: #718096;
            font-size: 10px;
            line-height: 1.5;
        }
        @media (max-width: 800px) {
            .menu-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 480px) {
            .topbar { padding: 15px 18px; }
            .content {
                width: calc(100% - 40px);
                margin: 0 20px;
                padding: 22px 0 35px;
            }
            .menu-grid { gap: 12px; }
            .menu-tile { min-height: 155px; }
        }
    </style>
<link rel="stylesheet" href="/admin/admin-content-layout.css?v=20261102-030252">
    <link rel="stylesheet" href="/modules/compliance/css/pages/admin-topbar.css?v=20261102-030955">
</head>
<body>
    <?php require __DIR__ . '/includes/admin-topbar.php'; ?>

    <main class="content">
        <section class="welcome">
            <h2>Welcome, <?= htmlspecialchars($admin['display_name'], ENT_QUOTES, 'UTF-8') ?>.</h2>
            <p>
                Manage employee accounts and access to the Legal and Compliance module,
                including account creation, password resets, and account unlocking.
            </p>
        </section>

        <section class="menu-grid" aria-label="Administration menu">
            <?php if (lcAdminHasPermission('accounts.manage')): ?>
            <a class="menu-tile" href="/admin/new-hire.php">
                <span class="tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <circle cx="9" cy="8" r="3.5"/>
                        <path d="M3 20v-1.5a6 6 0 0 1 12 0V20"/>
                        <path d="M19 8v6M16 11h6"/>
                    </svg>
                </span>
                <span class="tile-label">New Hire</span>
                <span class="tile-description">Employees without HRMS login accounts</span>
            </a>
            <?php endif; ?>

            <?php if (lcAdminHasPermission('accounts.view')): ?>
            <a class="menu-tile" href="/admin/accounts.php">
                <span class="tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="M3 10h18M7 15h4"/>
                    </svg>
                </span>
                <span class="tile-label">Accounts</span>
                <span class="tile-description">Administrator account management</span>
            </a>
            <?php endif; ?>

            <?php if (lcAdminHasPermission('roles.view')): ?>
            <a class="menu-tile" href="/admin/roles.php">
                <span class="tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 3 20 7v5c0 5-3.5 8-8 9-4.5-1-8-4-8-9V7z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                </span>
                <span class="tile-label">Employee Role Management</span>
                <span class="tile-description">Change HRMS roles for promoted employees</span>
            </a>
            <?php endif; ?>

            <?php if (lcAdminHasPermission('accounts.view')): ?>
            <?php endif; ?>

            <?php if (lcAdminHasPermission('backup.view')): ?>
            <a class="menu-tile" href="/admin/backup-recovery.php">
                <span class="tile-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 3v12"/>
                        <path d="m7 10 5 5 5-5"/>
                        <path d="M4 17v3h16v-3"/>
                        <path d="M5 7a8 8 0 0 1 14 0"/>
                    </svg>
                </span>
                <span class="tile-label">Backup &amp; Recovery</span>
                <span class="tile-description">Encrypted Legal &amp; Compliance backups</span>
            </a>
            <?php endif; ?>
        </section>
    </main>
<script src="/modules/compliance/js/admin-topbar.js?v=20261101-230304"></script>
</body>
</html>
