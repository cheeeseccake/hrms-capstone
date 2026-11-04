<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

lcAdminRequirePermission('lawyer_portal.manage');

header('Location: /admin/portal-roles.php#legal-compliance-access', true, 302);
exit;
