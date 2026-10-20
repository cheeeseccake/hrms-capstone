<?php
declare(strict_types=1);
/**
 * Standalone external audit checklist. The URL token grants access to ONE audit only.
 * Keep evidence outside the public web root where possible; this endpoint stores only metadata.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/../../database/db.php';

function ac_out(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ac_db(): PDO {
    $database = new Database();
    if (method_exists($database, 'hasConnectionError') && $database->hasConnectionError()) {
        throw new RuntimeException('Database unavailable');
    }
    $pdo = $database->getConnection();
    if (!$pdo instanceof PDO) throw new RuntimeException('Database unavailable');
    return $pdo;
}
function ac_event(PDO $db, int $id, string $type, string $message, string $actor = 'External checklist'): void {
    $st = $db->prepare('INSERT INTO lc_audit_events (audit_id,event_type,event_message,actor) VALUES (?,?,?,?)');
    $st->execute([$id,$type,mb_substr($message,0,500),$actor]);
}
$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(404); $fatal = 'This checklist link is invalid or has expired.';
} else {
    try {
        $db = ac_db();
        $stmt = $db->prepare('SELECT * FROM lc_audits WHERE public_token_hash = ? LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $audit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$audit || strtotime((string)$audit['token_expires_at']) < time() || in_array($audit['status'], ['approved','cancelled'], true)) {
            http_response_code(404); $fatal = 'This checklist link is invalid, expired, or no longer accepting responses.';
        }
    } catch (Throwable $e) {
        http_response_code(500); $fatal = 'The checklist is temporarily unavailable. Please contact the audit coordinator.';
    }
}
$notice = ''; $error = '';
if (isset($audit) && $audit && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($fatal)) {
    $action = (string)($_POST['action'] ?? '');
    $csrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals((string)($_SESSION['audit_public_csrf'] ?? ''), $csrf)) {
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!in_array($action, ['save','submit'], true)) {
        $error = 'Invalid request.';
    } elseif ($audit['status'] === 'submitted') {
        $error = 'This checklist has already been submitted. Contact the audit coordinator if changes are needed.';
    } else {
        $items = json_decode((string)$audit['checklist_json'], true);
        $items = is_array($items) ? $items : [];
        $answers = [];
        $pendingEvidence = [];
        foreach ($items as $item) {
            if (!is_array($item) || empty($item['id'])) continue;
            $id = (string)$item['id'];
            $status = (string)($_POST['status'][$id] ?? '');
            if (!in_array($status, ['compliant','non_compliant','not_applicable','needs_verification'], true)) $status = '';
            $remarks = trim((string)($_POST['remarks'][$id] ?? ''));
            $answers[$id] = ['status'=>$status,'remarks'=>mb_substr($remarks,0,3000)];
            if (isset($_FILES['evidence']['name'][$id]) && (string)$_FILES['evidence']['name'][$id] !== '') {
                $file = ['name'=>$_FILES['evidence']['name'][$id], 'tmp_name'=>$_FILES['evidence']['tmp_name'][$id], 'error'=>$_FILES['evidence']['error'][$id], 'size'=>$_FILES['evidence']['size'][$id]];
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 8 * 1024 * 1024 || !is_uploaded_file($file['tmp_name'])) { $error = 'An evidence file could not be accepted. Use a PDF or image up to 8 MB.'; break; }
                $finfo = new finfo(FILEINFO_MIME_TYPE); $mime = $finfo->file($file['tmp_name']);
                $allowed = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                if (!isset($allowed[$mime])) { $error = 'Evidence files must be PDF, JPG, PNG, or WEBP and no larger than 8 MB.'; break; }
                $pendingEvidence[] = ['item_id'=>$id,'original'=>mb_substr(basename((string)$file['name']),0,255),'tmp'=>$file['tmp_name'],'mime'=>$mime,'size'=>(int)$file['size'],'ext'=>$allowed[$mime]];
            }
        }
        $allAnswered = true;
        foreach ($answers as $answer) if ($answer['status'] === '') $allAnswered = false;
        if ($error !== '') {
            // Reject this save if any uploaded evidence fails validation.
        } elseif ($action === 'submit' && !$allAnswered) {
            $error = 'Please select a status for every checklist item before submitting.';
        } else {
            try {
                $db->beginTransaction();
                $evidenceDir = dirname(__DIR__, 3) . '/private_audit_evidence';
                if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0750, true) && !is_dir($evidenceDir)) throw new RuntimeException('Evidence storage is unavailable.');
                $evidenceStmt = $db->prepare('INSERT INTO lc_audit_evidence(audit_id,checklist_item_id,original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?,?,?)');
                foreach ($pendingEvidence as $ev) {
                    $stored = bin2hex(random_bytes(24)) . '.' . $ev['ext'];
                    if (!move_uploaded_file($ev['tmp'], $evidenceDir . '/' . $stored)) throw new RuntimeException('Evidence upload failed. Please retry.');
                    $evidenceStmt->execute([(int)$audit['audit_id'],$ev['item_id'],$ev['original'],$stored,$ev['mime'],$ev['size'],(string)$audit['responsible_name']]);
                }
                $next = $action === 'submit' ? 'submitted' : 'in_progress';
                $up = $db->prepare('UPDATE lc_audits SET responses_json=?, status=?, last_saved_at=NOW(), submitted_at=IF(?=\'submitted\',NOW(),submitted_at) WHERE audit_id=? AND status <> \'submitted\'');
                $json = json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                $up->execute([$json,$next,$next,(int)$audit['audit_id']]);
                if ($up->rowCount() === 0 && $audit['status'] !== $next) throw new RuntimeException('Checklist could not be saved.');
                ac_event($db,(int)$audit['audit_id'],$action === 'submit' ? 'submitted' : 'saved',$action === 'submit' ? 'External checklist submitted.' : 'External checklist progress saved.');
                $db->commit();
                $audit['responses_json'] = $json; $audit['status'] = $next;
                if ($action === 'submit') { $notice = 'Checklist submitted successfully. Thank you.'; }
                else { $notice = 'Progress saved. You can return using this same secure link until the deadline.'; }
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                $error = 'Unable to save your checklist right now. Please try again.';
            }
        }
    }
}
if (isset($audit) && $audit && empty($_SESSION['audit_public_csrf'])) {
    $_SESSION['audit_public_csrf'] = bin2hex(random_bytes(32));
}
$items = isset($audit) && $audit ? json_decode((string)$audit['checklist_json'], true) : [];
$items = is_array($items) ? $items : [];
$responses = isset($audit) && $audit ? json_decode((string)($audit['responses_json'] ?? ''), true) : [];
$responses = is_array($responses) ? $responses : [];
$readOnly = isset($audit) && $audit && in_array($audit['status'], ['submitted','approved','cancelled'], true);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Audit Checklist</title>
<style>
:root{font-family:Arial,sans-serif;color:#182230;background:#f3f6fa}*{box-sizing:border-box}body{margin:0}.top{background:#173b67;color:white;padding:22px max(18px,calc((100% - 900px)/2))}.top small{color:#d6e5f5}.wrap{max-width:900px;margin:24px auto;padding:0 16px}.card{background:white;border:1px solid #e0e7ef;border-radius:12px;padding:22px;margin-bottom:16px;box-shadow:0 2px 10px #122b4408}.muted{color:#64748b;font-size:14px}.item{padding:18px 0;border-bottom:1px solid #e8edf3}.item:last-child{border-bottom:0}.item h3{font-size:16px;margin:0 0 12px}.fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:12px}.fields label{display:block;font-size:13px;font-weight:700;margin-bottom:6px}select,textarea{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:7px;background:#fff;font:inherit}textarea{min-height:72px;resize:vertical}.btn{border:0;border-radius:7px;padding:12px 17px;background:#245b9e;color:#fff;font-weight:700;cursor:pointer}.btn.secondary{background:#e8eef6;color:#173b67}.actions{display:flex;gap:10px;flex-wrap:wrap}.notice{padding:12px;border-radius:7px;background:#ecfdf5;color:#166534}.error{padding:12px;border-radius:7px;background:#fef2f2;color:#991b1b}.pill{display:inline-block;padding:5px 9px;border-radius:20px;background:#eaf2fc;color:#245b9e;font-size:12px;font-weight:700}@media(max-width:620px){.fields{grid-template-columns:1fr}.card{padding:16px}.top{padding:20px}}
</style></head><body>
<header class="top"><strong>Legal &amp; Compliance · Audit Checklist</strong><br><small>Secure external checklist — no HRMS account required</small></header><main class="wrap">
<?php if (!empty($fatal)): ?><section class="card"><h2>Checklist unavailable</h2><p><?=ac_out($fatal)?></p></section>
<?php elseif (isset($audit) && $audit): ?>
<section class="card"><span class="pill"><?=ac_out(ucwords(str_replace('_',' ',$audit['status'])))?></span><h1><?=ac_out($audit['title'])?></h1><p class="muted"><?=ac_out($audit['campus'])?> · <?=ac_out($audit['audit_type'])?></p><div class="fields"><div><span class="muted">Scheduled date</span><p><?=ac_out($audit['scheduled_date'])?></p></div><div><span class="muted">Submission deadline</span><p><?=ac_out($audit['due_date'])?></p></div></div><?php if (!empty($audit['description'])): ?><p><?=nl2br(ac_out($audit['description']))?></p><?php endif; ?><p class="muted">Complete each item based on what you have verified. “Needs Verification” is not the same as compliant.</p></section>
<?php if ($notice): ?><div class="notice"><?=ac_out($notice)?></div><?php endif; ?><?php if ($error): ?><div class="error"><?=ac_out($error)?></div><?php endif; ?>
<?php if ($readOnly): ?><section class="card"><h2>Submission received</h2><p>This checklist is read-only now. Contact the audit coordinator if you need to correct a response.</p></section>
<?php else: ?><form method="post" enctype="multipart/form-data" class="card"><input type="hidden" name="token" value="<?=ac_out($token)?>"><input type="hidden" name="csrf" value="<?=ac_out((string)$_SESSION['audit_public_csrf'])?>">
<?php foreach ($items as $i=>$item): if (!is_array($item)) continue; $id=(string)($item['id']??$i); $answer=$responses[$id]??[]; ?>
<div class="item"><h3><?=($i+1)?>. <?=ac_out((string)($item['text']??'Checklist item'))?> <?php if (!empty($item['required'])): ?><span class="muted">(Required)</span><?php endif; ?></h3><div class="fields"><div><label for="s<?=ac_out($id)?>">Inspection status</label><select id="s<?=ac_out($id)?>" name="status[<?=ac_out($id)?>]" required><option value="">Select status</option><?php foreach (['compliant'=>'Compliant','non_compliant'=>'Non-Compliant','not_applicable'=>'Not Applicable','needs_verification'=>'Needs Verification'] as $v=>$label): ?><option value="<?=ac_out($v)?>" <?=($answer['status']??'')===$v?'selected':''?>><?=ac_out($label)?></option><?php endforeach; ?></select></div><div><label for="r<?=ac_out($id)?>">Remarks / evidence notes</label><textarea id="r<?=ac_out($id)?>" name="remarks[<?=ac_out($id)?>]" maxlength="3000" placeholder="Describe what you checked or what needs attention..."><?=ac_out((string)($answer['remarks']??''))?></textarea><label for="e<?=ac_out($id)?>" style="margin-top:9px">Attach evidence (PDF/JPG/PNG/WEBP, max 8 MB)</label><input id="e<?=ac_out($id)?>" type="file" name="evidence[<?=ac_out($id)?>]" accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"></div></div></div>
<?php endforeach; ?>
<div class="actions"><button class="btn secondary" type="submit" name="action" value="save">Save Progress</button><button class="btn" type="submit" name="action" value="submit" onclick="return confirm('Submit this checklist for Compliance review? You cannot edit it afterward.')">Submit Checklist</button></div><p class="muted">Save progress to return later using the same link. Uploaded evidence is stored privately and can only be reviewed by authorized system personnel.</p></form><?php endif; ?>
<?php endif; ?></main></body></html>
