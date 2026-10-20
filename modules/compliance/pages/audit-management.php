<?php
// Audit Management — dedicated scheduler, external checklist tracker, review and corrective actions.
$pageTitle = 'Audit Management';
require_once __DIR__ . '/../../../auth/session.php';
require_once __DIR__ . '/../../../database/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$database = new Database();
if (method_exists($database, 'hasConnectionError') && $database->hasConnectionError()) throw new RuntimeException('Database connection unavailable.');
$db = $database->getConnection();
if (!$db instanceof PDO) throw new RuntimeException('Database connection unavailable.');
if (empty($_SESSION['lc_audit_csrf'])) $_SESSION['lc_audit_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['lc_audit_csrf'];
$actor = (string)($_SESSION['employee_name'] ?? $_SESSION['username'] ?? 'Compliance Personnel');
$flash = ''; $error = '';
function am_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function am_event(PDO $db,int $id,string $type,string $message,string $actor): void {
    $s=$db->prepare('INSERT INTO lc_audit_events(audit_id,event_type,event_message,actor) VALUES(?,?,?,?)');
    $s->execute([$id,$type,mb_substr($message,0,500),mb_substr($actor,0,180)]);
}
function am_mail(string $to,string $name,string $subject,string $body): bool {
    if (!filter_var($to,FILTER_VALIDATE_EMAIL)) return false;
    $from = getenv('HRMS_AUDIT_FROM_EMAIL') ?: 'no-reply@legal.bcp-hrms.com';
    if (!filter_var($from,FILTER_VALIDATE_EMAIL)) return false;
    $headers = "From: Bestlink HRMS Audit <{$from}>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, $headers);
}
function am_template(string $type): array {
    $sets = [
      'Building & Facilities' => [
        'Confirm applicable building permit and occupancy documents are current.',
        'Inspect visible building defects, leaks, damaged fixtures, and hazards.',
        'Check that corridors and access routes are clear and usable.',
        'Confirm facility inspection and maintenance records are available.',
        'Record any corrective action needed and supporting evidence.'
      ],
      'Fire & Emergency Safety' => [
        'Check fire extinguishers are present, accessible, and within inspection date.',
        'Check emergency exits and evacuation routes are clear.',
        'Verify emergency exit signage and emergency lighting.',
        'Confirm evacuation plans and emergency contact information are displayed.',
        'Check that the latest drill or emergency readiness record is available.'
      ],
      'Occupational Safety' => [
        'Inspect work areas for visible hazards and unsafe conditions.',
        'Confirm relevant safety procedures and emergency instructions are available.',
        'Check required protective equipment is available where applicable.',
        'Verify safety training or inspection records where applicable.',
        'Document hazards and recommended corrective actions.'
      ],
      'Government & Permits' => [
        'Confirm applicable permits and registrations are available.',
        'Check document validity and expiration dates.',
        'Verify the latest inspection or renewal evidence is filed.',
        'Identify missing, expired, or unverified documents.',
        'Record responsible person and target date for each deficiency.'
      ],
      'HR & Labor Compliance' => [
        'Verify applicable employment and labor compliance records are available.',
        'Check required policies and acknowledgements are documented.',
        'Confirm applicable training or certification records are current.',
        'Identify overdue requirements or records needing verification.',
        'Record corrective actions and supporting evidence.'
      ],
      'Document Compliance' => [
        'Confirm required documents are present in the designated records.',
        'Check expiry dates and document status.',
        'Verify supporting evidence is legible and matches the requirement.',
        'Identify missing, expired, or unverified documents.',
        'Record the corrective action owner and due date.'
      ],
      'Custom Audit' => [
        'Verify the first applicable requirement and record evidence.',
        'Verify the second applicable requirement and record evidence.',
        'Inspect for deficiencies or items needing follow-up.',
        'Record supporting evidence and remarks.',
        'Identify corrective actions, owners, and due dates.'
      ]
    ];
    $list = $sets[$type] ?? $sets['Custom Audit'];
    $out=[]; foreach($list as $i=>$text) $out[]=['id'=>'item_'.($i+1),'text'=>$text,'required'=>true];
    return $out;
}
function am_status_label(string $s): string { return ucwords(str_replace('_',' ',$s)); }
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) {
        $error='Security token expired. Refresh the page and try again.';
    } else {
      $action=(string)($_POST['action']??'');
      try {
        if ($action==='create') {
          $title=trim((string)($_POST['title']??'')); $campus=trim((string)($_POST['campus']??''));
          $type=trim((string)($_POST['audit_type']??'Custom Audit')); $desc=trim((string)($_POST['description']??''));
          $name=trim((string)($_POST['responsible_name']??'')); $email=trim((string)($_POST['responsible_email']??''));
          $auditor=trim((string)($_POST['auditor_name']??$actor)); $scheduled=(string)($_POST['scheduled_date']??''); $due=(string)($_POST['due_date']??'');
          $allowedTypes=['Building & Facilities','Fire & Emergency Safety','Occupational Safety','Government & Permits','HR & Labor Compliance','Document Compliance','Custom Audit'];
          if ($title===''||$campus===''||$name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($type,$allowedTypes,true)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$scheduled)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||$due<$scheduled) {
            throw new RuntimeException('Complete all required fields and make sure the due date is on or after the scheduled date.');
          }
          $token=bin2hex(random_bytes(32)); $code='AUD-'.date('Y').'-'.strtoupper(bin2hex(random_bytes(3)));
          $checklist=json_encode(am_template($type),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
          $expires=(new DateTimeImmutable($due.' 23:59:59'))->modify('+30 days')->format('Y-m-d H:i:s');
          $st=$db->prepare("INSERT INTO lc_audits(audit_code,title,campus,audit_type,description,responsible_name,responsible_email,auditor_name,scheduled_date,due_date,status,checklist_json,public_token_hash,token_expires_at,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,'scheduled',?,?,?,?)");
          $st->execute([$code,$title,$campus,$type,$desc,$name,$email,$auditor,$scheduled,$due,$checklist,hash('sha256',$token),$expires,(int)($_SESSION['employee_id']??0)]);
          $id=(int)$db->lastInsertId(); am_event($db,$id,'scheduled','Audit scheduled and external checklist created.',$actor);
          $url='https://legal.bcp-hrms.com/modules/compliance/public-audit-checklist.php?token='.$token;
          $body="Hello {$name},\n\nYou have been assigned an audit checklist.\n\nAudit: {$title}\nCampus: {$campus}\nCategory: {$type}\nScheduled: {$scheduled}\nDue: {$due}\n\nOpen your secure checklist (no HRMS login required):\n{$url}\n\nPlease do not forward this private link. It expires after the audit deadline plus 30 days.\n\nBestlink HRMS Legal & Compliance";
          $sent=am_mail($email,$name,'Audit checklist assigned: '.$title,$body);
          am_event($db,$id,$sent?'email_sent':'email_failed',$sent?'Checklist link email sent.':'Email delivery failed; use Resend link from the audit record after mail configuration is checked.',$actor);
          $_SESSION['lc_audit_flash']=$sent?'Audit scheduled and checklist link emailed successfully.':'Audit scheduled, but email could not be sent. Verify server mail settings, then use Resend checklist link.';
          header('Location: ?page=audit-management'); exit;
        } elseif ($action==='resend') {
          $id=(int)($_POST['audit_id']??0); $st=$db->prepare('SELECT * FROM lc_audits WHERE audit_id=?'); $st->execute([$id]); $a=$st->fetch(PDO::FETCH_ASSOC);
          if(!$a) throw new RuntimeException('Audit not found.');
          $token=bin2hex(random_bytes(32)); $expires=(new DateTimeImmutable($a['due_date'].' 23:59:59'))->modify('+30 days')->format('Y-m-d H:i:s');
          $db->prepare('UPDATE lc_audits SET public_token_hash=?,token_expires_at=? WHERE audit_id=?')->execute([hash('sha256',$token),$expires,$id]);
          $url='https://legal.bcp-hrms.com/modules/compliance/public-audit-checklist.php?token='.$token;
          $body="Hello {$a['responsible_name']},\n\nHere is your renewed secure audit checklist link.\nAudit: {$a['title']}\nCampus: {$a['campus']}\nDue: {$a['due_date']}\n\n{$url}\n\nBestlink HRMS Legal & Compliance";
          $sent=am_mail($a['responsible_email'],$a['responsible_name'],'Renewed audit checklist: '.$a['title'],$body);
          am_event($db,$id,$sent?'email_sent':'email_failed',$sent?'Checklist link resent; previous link revoked.':'Resend attempted; mail delivery failed.',$actor);
          $_SESSION['lc_audit_flash']=$sent?'A new link was emailed; the previous link is now invalid.':'Link renewed but email sending failed. Check server mail settings.';
          header('Location: ?page=audit-management'); exit;
        } elseif ($action==='review') {
          $id=(int)($_POST['audit_id']??0); $decision=(string)($_POST['decision']??''); $notes=trim((string)($_POST['review_notes']??''));
          if(!in_array($decision,['approved','returned'],true)) throw new RuntimeException('Choose approve or return for correction.');
          $st=$db->prepare('SELECT * FROM lc_audits WHERE audit_id=?');$st->execute([$id]);$a=$st->fetch(PDO::FETCH_ASSOC);
          if(!$a||$a['status']!=='submitted') throw new RuntimeException('Only submitted audits can be reviewed.');
          $db->prepare('UPDATE lc_audits SET status=?,reviewed_at=NOW(),review_notes=? WHERE audit_id=?')->execute([$decision,$notes,$id]);
          am_event($db,$id,$decision,$decision==='approved'?'Audit submission approved.':'Audit returned for correction: '.$notes,$actor);
          if(filter_var($a['responsible_email'],FILTER_VALIDATE_EMAIL)) am_mail($a['responsible_email'],$a['responsible_name'],'Audit checklist '.$decision.': '.$a['title'],"Hello {$a['responsible_name']},\n\nYour audit checklist '{$a['title']}' was {$decision}.\nReviewer notes: {$notes}\n\nBestlink HRMS Legal & Compliance");
          $_SESSION['lc_audit_flash']='Audit review saved.'; header('Location: ?page=audit-management&view='.$id); exit;
        } elseif ($action==='finding') {
          $id=(int)($_POST['audit_id']??0); $title=trim((string)($_POST['finding_title']??'')); $details=trim((string)($_POST['finding_details']??'')); $severity=(string)($_POST['severity']??'medium'); $assignee=trim((string)($_POST['assigned_to']??'')); $email=trim((string)($_POST['assigned_email']??'')); $due=trim((string)($_POST['finding_due']??''));
          if($title===''||!in_array($severity,['low','medium','high','critical'],true)||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))) throw new RuntimeException('Enter a finding title, valid severity, and valid assignee email if supplied.');
          $db->prepare('INSERT INTO lc_audit_findings(audit_id,finding_title,finding_details,severity,assigned_to,assigned_email,due_date,created_by) VALUES(?,?,?,?,?,?,NULLIF(?,\'\'),?)')->execute([$id,$title,$details,$severity,$assignee,$email,$due,(int)($_SESSION['employee_id']??0)]);
          am_event($db,$id,'finding_created','Corrective action created: '.$title,$actor);
          if($email!=='') am_mail($email,$assignee,'Audit corrective action assigned: '.$title,"Hello {$assignee},\n\nA corrective action has been assigned to you.\nFinding: {$title}\nDetails: {$details}\nDue: {$due}\n\nPlease coordinate with Legal & Compliance for resolution evidence.");
          $_SESSION['lc_audit_flash']='Finding and corrective action saved.'; header('Location: ?page=audit-management&view='.$id); exit;
        } elseif ($action==='finding_status') {
          $fid=(int)($_POST['finding_id']??0); $id=(int)($_POST['audit_id']??0); $status=(string)($_POST['finding_status']??'open'); $notes=trim((string)($_POST['resolution_notes']??''));
          if(!in_array($status,['open','in_progress','resolved','verified','closed'],true)) throw new RuntimeException('Invalid finding status.');
          $db->prepare('UPDATE lc_audit_findings SET status=?,resolution_notes=? WHERE finding_id=? AND audit_id=?')->execute([$status,$notes,$fid,$id]);
          am_event($db,$id,'finding_updated','Corrective action status changed to '.$status,$actor);
          $_SESSION['lc_audit_flash']='Corrective action updated.'; header('Location: ?page=audit-management&view='.$id); exit;
        } else { throw new RuntimeException('Unknown action.'); }
      } catch(Throwable $e) { $error=$e instanceof RuntimeException?$e->getMessage():'Unable to complete the requested action. Check the database schema and server logs.'; }
    }
}
if(isset($_SESSION['lc_audit_flash'])) { $flash=(string)$_SESSION['lc_audit_flash']; unset($_SESSION['lc_audit_flash']); }
if (($_GET['export']??'')==='csv') {
  header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="audit-report-'.date('Ymd').'.csv"');
  $out=fopen('php://output','w'); fputcsv($out,['Audit Code','Title','Campus','Category','Responsible Person','Email','Scheduled Date','Due Date','Status','Submitted At','Reviewed At']);
  foreach($db->query('SELECT audit_code,title,campus,audit_type,responsible_name,responsible_email,scheduled_date,due_date,status,submitted_at,reviewed_at FROM lc_audits ORDER BY scheduled_date DESC') as $row) fputcsv($out,$row); fclose($out); exit;
}
$search=trim((string)($_GET['q']??'')); $filter=(string)($_GET['status']??'');
$where=[];$params=[];
if($search!==''){$where[]='(title LIKE ? OR campus LIKE ? OR audit_code LIKE ? OR responsible_name LIKE ?)';$params=array_merge($params,array_fill(0,4,'%'.$search.'%'));}
$validFilters=['scheduled','in_progress','submitted','returned','approved','cancelled','overdue'];
if(in_array($filter,$validFilters,true)){$where[]='status=?';$params[]=$filter;}
$whereSql=$where?'WHERE '.implode(' AND ',$where):'';
$st=$db->prepare("SELECT * FROM lc_audits {$whereSql} ORDER BY scheduled_date DESC,audit_id DESC LIMIT 200");$st->execute($params);$audits=$st->fetchAll(PDO::FETCH_ASSOC);
$counts=[]; foreach(['scheduled','in_progress','submitted','returned','approved','overdue'] as $s){$x=$db->prepare("SELECT COUNT(*) FROM lc_audits WHERE status=?".($s==='overdue'?' OR (status IN (\'scheduled\',\'in_progress\',\'returned\') AND due_date<CURDATE())':''));$x->execute([$s]);$counts[$s]=(int)$x->fetchColumn();}
$viewId=(int)($_GET['view']??0);$selected=null;$findings=[];$events=[];$evidence=[];
if($viewId){$x=$db->prepare('SELECT * FROM lc_audits WHERE audit_id=?');$x->execute([$viewId]);$selected=$x->fetch(PDO::FETCH_ASSOC)?:null;if($selected){$x=$db->prepare('SELECT * FROM lc_audit_findings WHERE audit_id=? ORDER BY created_at DESC');$x->execute([$viewId]);$findings=$x->fetchAll(PDO::FETCH_ASSOC);$x=$db->prepare('SELECT * FROM lc_audit_events WHERE audit_id=? ORDER BY created_at DESC LIMIT 30');$x->execute([$viewId]);$events=$x->fetchAll(PDO::FETCH_ASSOC);$x=$db->prepare('SELECT * FROM lc_audit_evidence WHERE audit_id=? ORDER BY uploaded_at DESC');$x->execute([$viewId]);$evidence=$x->fetchAll(PDO::FETCH_ASSOC);}}
?>
<style>
.am{--am-blue:#245b9e;--am-ink:#182230;--am-muted:#64748b;color:var(--am-ink);font-family:Arial,sans-serif}.am *{box-sizing:border-box}.am-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:18px}.am-head h2{font-size:22px;margin:0 0 5px}.am-muted{color:var(--am-muted);font-size:13px}.am-btn{border:0;border-radius:7px;background:var(--am-blue);color:white;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:13px}.am-btn.alt{background:#eaf0f7;color:#173b67}.am-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:18px}.am-stat,.am-card{background:#fff;border:1px solid #e1e7ef;border-radius:10px;padding:16px;min-width:0}.am-stat span{display:block;color:var(--am-muted);font-size:12px}.am-stat strong{font-size:26px;display:block;margin:7px 0}.am-formgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}.am-field label{display:block;font-size:12px;font-weight:700;margin-bottom:6px}.am-field input,.am-field select,.am-field textarea,.am-filter input,.am-filter select{width:100%;border:1px solid #ccd5e1;border-radius:6px;padding:10px;background:#fff;font:inherit;font-size:13px}.am-field textarea{min-height:72px}.am-wide{grid-column:1/-1}.am-card h3{font-size:16px;margin:0 0 14px}.am-tablewrap{overflow:auto}.am-table{border-collapse:collapse;width:100%;font-size:13px}.am-table th,.am-table td{text-align:left;border-bottom:1px solid #e8edf3;padding:12px 10px;vertical-align:top}.am-table th{color:#526174;font-size:11px;text-transform:uppercase;white-space:nowrap}.am-pill{display:inline-block;padding:5px 8px;border-radius:20px;background:#edf2f7;font-size:11px;font-weight:700;white-space:nowrap}.am-pill.submitted,.am-pill.approved,.am-pill.verified,.am-pill.closed{background:#dcfce7;color:#166534}.am-pill.returned,.am-pill.overdue,.am-pill.critical{background:#fee2e2;color:#991b1b}.am-pill.in_progress,.am-pill.scheduled,.am-pill.open{background:#dbeafe;color:#1d4ed8}.am-pill.medium{background:#fef3c7;color:#92400e}.am-actions{display:flex;gap:7px;flex-wrap:wrap}.am-flash{padding:12px;border-radius:7px;background:#ecfdf5;color:#166534;margin-bottom:12px}.am-error{padding:12px;border-radius:7px;background:#fef2f2;color:#991b1b;margin-bottom:12px}.am-section{margin-top:18px}.am-item{padding:12px 0;border-bottom:1px solid #e8edf3}.am-item:last-child{border-bottom:0}.am-item p{margin:6px 0;color:#475569;font-size:13px;white-space:pre-wrap}.am-details{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.am-detail{background:#f7f9fc;border-radius:7px;padding:10px}.am-detail span{display:block;color:#64748b;font-size:11px;margin-bottom:5px}.am-filter{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.am-filter input{max-width:300px}.am-filter select{max-width:200px}.am small{color:#64748b}@media(max-width:800px){.am-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.am-formgrid,.am-details{grid-template-columns:1fr}.am-wide{grid-column:auto}}@media(max-width:520px){.am-grid{grid-template-columns:1fr}.am-head{align-items:flex-start}}
</style>
<div class="am">
  <div class="am-head"><div><h2>Audit Management</h2><div class="am-muted">Schedule campus inspections, send standalone checklists, review findings, and track corrective actions.</div></div><div class="am-actions"><a class="am-btn alt" href="?page=audit-management&export=csv">Export CSV report</a><a class="am-btn" href="#am-schedule">+ Schedule Audit</a></div></div>
  <?php if($flash): ?><div class="am-flash"><?=am_h($flash)?></div><?php endif; ?><?php if($error): ?><div class="am-error"><?=am_h($error)?></div><?php endif; ?>
  <div class="am-grid"><?php foreach(['scheduled'=>'Scheduled','in_progress'=>'In Progress','submitted'=>'For Review','returned'=>'Returned','approved'=>'Approved','overdue'=>'Overdue'] as $k=>$label): ?><div class="am-stat"><span><?=am_h($label)?></span><strong><?=number_format($counts[$k]??0)?></strong><span>Audit records</span></div><?php endforeach; ?></div>
  <?php if($selected): $responses=json_decode((string)($selected['responses_json']??''),true);$responses=is_array($responses)?$responses:[];$checklist=json_decode((string)$selected['checklist_json'],true);$checklist=is_array($checklist)?$checklist:[]; ?>
  <section class="am-card"><div class="am-head"><div><h2><?=am_h($selected['title'])?></h2><div class="am-muted"><?=am_h($selected['audit_code'])?> · <?=am_h($selected['campus'])?></div></div><a class="am-btn alt" href="?page=audit-management">← Back to audits</a></div>
  <div class="am-details"><?php foreach(['Audit type'=>'audit_type','Responsible person'=>'responsible_name','Responsible email'=>'responsible_email','Auditor'=>'auditor_name','Scheduled'=>'scheduled_date','Deadline'=>'due_date'] as $label=>$key): ?><div class="am-detail"><span><?=am_h($label)?></span><strong><?=am_h($selected[$key])?></strong></div><?php endforeach; ?></div>
  <div class="am-section"><h3>Checklist submission</h3><?php if(!$responses): ?><p class="am-muted">No checklist responses have been saved yet.</p><?php else: foreach($checklist as $item):$rid=(string)($item['id']??'');$ans=$responses[$rid]??[]; ?><div class="am-item"><strong><?=am_h($item['text']??'Checklist item')?></strong><p>Status: <?=am_h(am_status_label((string)($ans['status']??'Not answered')))?></p><?php if(!empty($ans['remarks'])):?><p><?=am_h($ans['remarks'])?></p><?php endif;?></div><?php endforeach;endif; ?></div>
  <?php if($selected['status']==='submitted'): ?><form method="post" class="am-card am-section"><h3>Review submission</h3><input type="hidden" name="csrf" value="<?=am_h($csrf)?>"><input type="hidden" name="action" value="review"><input type="hidden" name="audit_id" value="<?=$viewId?>"><div class="am-field"><label>Reviewer notes</label><textarea name="review_notes" placeholder="Explain approval or required corrections"></textarea></div><div class="am-actions" style="margin-top:10px"><button class="am-btn" name="decision" value="approved">Approve Audit</button><button class="am-btn alt" name="decision" value="returned">Return for Corrections</button></div></form><?php elseif($selected['status']==='returned'): ?><div class="am-section"><strong>Review notes</strong><p><?=nl2br(am_h($selected['review_notes']??''))?></p></div><?php endif;?>
  <form method="post" class="am-card am-section"><h3>Add finding / corrective action</h3><input type="hidden" name="csrf" value="<?=am_h($csrf)?>"><input type="hidden" name="action" value="finding"><input type="hidden" name="audit_id" value="<?=$viewId?>"><div class="am-formgrid"><div class="am-field"><label>Finding title *</label><input name="finding_title" required></div><div class="am-field"><label>Severity</label><select name="severity"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></div><div class="am-field am-wide"><label>Details / required correction</label><textarea name="finding_details"></textarea></div><div class="am-field"><label>Assigned person</label><input name="assigned_to"></div><div class="am-field"><label>Assignee email (optional)</label><input type="email" name="assigned_email"></div><div class="am-field"><label>Corrective action due date</label><input type="date" name="finding_due"></div><div class="am-field" style="align-self:end"><button class="am-btn">Save Corrective Action</button></div></div></form>
  <section class="am-card am-section"><h3>Findings & corrective actions</h3><?php if(!$findings): ?><p class="am-muted">No findings recorded.</p><?php else: ?><div class="am-tablewrap"><table class="am-table"><thead><tr><th>Finding</th><th>Severity</th><th>Assignee</th><th>Due</th><th>Status / update</th></tr></thead><tbody><?php foreach($findings as $f): ?><tr><td><strong><?=am_h($f['finding_title'])?></strong><div class="am-muted"><?=am_h($f['finding_details'])?></div></td><td><span class="am-pill <?=am_h($f['severity'])?>"><?=am_h(ucfirst($f['severity']))?></span></td><td><?=am_h($f['assigned_to'])?></td><td><?=am_h($f['due_date'])?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=am_h($csrf)?>"><input type="hidden" name="action" value="finding_status"><input type="hidden" name="audit_id" value="<?=$viewId?>"><input type="hidden" name="finding_id" value="<?=(int)$f['finding_id']?>"><select name="finding_status"><?php foreach(['open','in_progress','resolved','verified','closed'] as $s):?><option value="<?=$s?>" <?=$f['status']===$s?'selected':''?>><?=am_h(am_status_label($s))?></option><?php endforeach;?></select><input name="resolution_notes" placeholder="Resolution / verification note" value="<?=am_h($f['resolution_notes'])?>" style="margin:5px 0;padding:7px;width:100%;border:1px solid #ccd5e1;border-radius:5px"><button class="am-btn alt">Update</button></form></td></tr><?php endforeach;?></tbody></table></div><?php endif;?></section>
  <section class="am-card am-section"><h3>Supporting evidence</h3><?php if(!$evidence): ?><p class="am-muted">No files uploaded for this audit yet.</p><?php else: ?><div class="am-tablewrap"><table class="am-table"><thead><tr><th>File</th><th>Checklist item</th><th>Type / size</th><th>Uploaded</th><th>Access</th></tr></thead><tbody><?php foreach($evidence as $ev): ?><tr><td><?=am_h($ev['original_name'])?></td><td><?=am_h($ev['checklist_item_id']??'—')?></td><td><?=am_h($ev['mime_type'])?> · <?=number_format(((int)$ev['file_size'])/1024,1)?> KB</td><td><?=am_h($ev['uploaded_at'])?></td><td><a class="am-btn alt" href="/modules/compliance/audit-evidence.php?id=<?=(int)$ev['evidence_id']?>">Download</a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
  <section class="am-card am-section"><h3>Audit activity history</h3><?php foreach($events as $ev):?><div class="am-item"><strong><?=am_h(am_status_label($ev['event_type']))?></strong><p><?=am_h($ev['event_message'])?></p><small><?=am_h($ev['actor'])?> · <?=am_h($ev['created_at'])?></small></div><?php endforeach;?></section>
  </section>
  <?php endif; ?>
  <section class="am-card am-section" id="am-schedule"><h3>Schedule a new audit</h3><p class="am-muted" style="margin-bottom:14px">The responsible person receives a one-audit-only link by email. They do not need an HRMS account.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?=am_h($csrf)?>"><input type="hidden" name="action" value="create"><div class="am-formgrid">
    <div class="am-field"><label>Audit title *</label><input name="title" required maxlength="180" placeholder="e.g. Campus Building & Safety Audit"></div>
    <div class="am-field"><label>Campus / location *</label><input name="campus" required maxlength="180" placeholder="e.g. Bulacan Campus"></div>
    <div class="am-field"><label>Audit category *</label><select name="audit_type"><?php foreach(['Building & Facilities','Fire & Emergency Safety','Occupational Safety','Government & Permits','HR & Labor Compliance','Document Compliance','Custom Audit'] as $type):?><option><?=am_h($type)?></option><?php endforeach;?></select></div>
    <div class="am-field"><label>Auditor / coordinator *</label><input name="auditor_name" required value="<?=am_h($actor)?>" maxlength="150"></div>
    <div class="am-field"><label>Responsible person's name *</label><input name="responsible_name" required maxlength="150"></div>
    <div class="am-field"><label>Responsible person's email *</label><input type="email" name="responsible_email" required maxlength="254" placeholder="person@school.edu.ph"></div>
    <div class="am-field"><label>Scheduled date *</label><input type="date" name="scheduled_date" required min="<?=date('Y-m-d')?>" value="<?=date('Y-m-d',strtotime('+7 days'))?>"></div>
    <div class="am-field"><label>Checklist deadline *</label><input type="date" name="due_date" required value="<?=date('Y-m-d',strtotime('+14 days'))?>"></div>
    <div class="am-field am-wide"><label>Instructions / scope</label><textarea name="description" maxlength="5000" placeholder="State areas to inspect, evidence expected, and any relevant requirements."></textarea></div>
    <div class="am-wide"><button class="am-btn" type="submit">Schedule Audit & Send Checklist</button></div></div></form>
  </section>
  <section class="am-card am-section"><div class="am-head"><div><h3>Audit register</h3><div class="am-muted">Up to 200 most recent records</div></div></div><form class="am-filter" method="get"><input type="hidden" name="page" value="audit-management"><input name="q" value="<?=am_h($search)?>" placeholder="Search audit, campus, person..."><select name="status"><option value="">All statuses</option><?php foreach($validFilters as $s):?><option value="<?=$s?>" <?=$filter===$s?'selected':''?>><?=am_h(am_status_label($s))?></option><?php endforeach;?></select><button class="am-btn">Filter</button><a class="am-btn alt" href="?page=audit-management">Reset</a></form>
  <div class="am-tablewrap"><table class="am-table"><thead><tr><th>Audit</th><th>Campus / Category</th><th>Responsible person</th><th>Schedule / Due</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php if(!$audits):?><tr><td colspan="6">No audit records found. Schedule your first audit above.</td></tr><?php else: foreach($audits as $a):$stt=$a['status'];if(in_array($stt,['scheduled','in_progress','returned'],true)&&$a['due_date']<date('Y-m-d'))$stt='overdue';?><tr><td><strong><?=am_h($a['title'])?></strong><div class="am-muted"><?=am_h($a['audit_code'])?></div></td><td><?=am_h($a['campus'])?><div class="am-muted"><?=am_h($a['audit_type'])?></div></td><td><?=am_h($a['responsible_name'])?><div class="am-muted"><?=am_h($a['responsible_email'])?></div></td><td><?=am_h($a['scheduled_date'])?><div class="am-muted">Due <?=am_h($a['due_date'])?></div></td><td><span class="am-pill <?=am_h($stt)?>"><?=am_h(am_status_label($stt))?></span></td><td><div class="am-actions"><a class="am-btn alt" href="?page=audit-management&view=<?=(int)$a['audit_id']?>">Open / Review</a><?php if(!in_array($a['status'],['approved','cancelled'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=am_h($csrf)?>"><input type="hidden" name="action" value="resend"><input type="hidden" name="audit_id" value="<?=(int)$a['audit_id']?>"><button class="am-btn" type="submit" onclick="return confirm('Create a new checklist link and invalidate the previous one?')">Resend link</button></form><?php endif;?></div></td></tr><?php endforeach;endif;?></tbody></table></div></section>
</div>
