<?php
declare(strict_types=1);
// Evidence downloads are available only to authenticated HRMS users.
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../database/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['employee_id'])) { http_response_code(403); exit('Forbidden'); }
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) { http_response_code(404); exit('Evidence not found'); }
$database = new Database();
if (method_exists($database, 'hasConnectionError') && $database->hasConnectionError()) { http_response_code(503); exit('Unavailable'); }
$db = $database->getConnection();
$stmt = $db->prepare('SELECT original_name,stored_name,mime_type,file_size FROM lc_audit_evidence WHERE evidence_id=? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); exit('Evidence not found'); }
$base = dirname(__DIR__, 3) . '/private_audit_evidence/';
$path = $base . basename((string)$row['stored_name']);
if (!is_file($path) || !is_readable($path)) { http_response_code(404); exit('Evidence file not found'); }
$allowed = ['application/pdf','image/jpeg','image/png','image/webp'];
$mime = in_array($row['mime_type'], $allowed, true) ? $row['mime_type'] : 'application/octet-stream';
header('X-Content-Type-Options: nosniff');
header('Content-Type: '.$mime);
header('Content-Length: '.filesize($path));
header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'_',basename((string)$row['original_name'])).'"');
readfile($path);
