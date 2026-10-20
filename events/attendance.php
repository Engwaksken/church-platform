<?php
declare(strict_types=1);
$pageTitle = 'Event Attendance and Certificates';
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_permission('events.view');
require_once __DIR__ . '/certificate-functions.php';
if (!event_certificate_is_admin()) { http_response_code(403); exit('Only administrators can manage event attendance and certificates.'); }
event_certificate_ensure_tables($conn);
$eventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$stmt = $conn->prepare('SELECT id,title,event_type,event_date,event_time,venue,organizer FROM events WHERE id=? LIMIT 1');
$stmt->bind_param('i', $eventId); $stmt->execute(); $event = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$event) { http_response_code(404); exit('Event not found.'); }
$brand = event_certificate_brand($conn);
$templatePath = event_certificate_setting($conn,'event_certificate_template','');
$nameTop = (float)event_certificate_setting($conn,'event_certificate_name_top','52');
$detailsTop = (float)event_certificate_setting($conn,'event_certificate_details_top','70');
$csrf = (string)($_SESSION['event_certificate_csrf'] ??= bin2hex(random_bytes(32)));
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Your form session expired. Reload and try again.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upload_template') {
            $file = $_FILES['certificate_template'] ?? null;
            if (!$file || (int)$file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('Choose a PNG or JPG certificate design to upload.');
            if ((int)$file['size'] > 12 * 1024 * 1024) throw new RuntimeException('Certificate design must be 12 MB or smaller.');
            $image = @getimagesize((string)$file['tmp_name']);
            $mime = (string)($image['mime'] ?? '');
            $extensions = ['image/png'=>'png','image/jpeg'=>'jpg'];
            if (!isset($extensions[$mime])) throw new RuntimeException('Only PNG and JPG certificate designs are supported.');
            $directory = __DIR__ . '/../assets/uploads/event-certificates';
            if (!is_dir($directory) && !mkdir($directory,0775,true)) throw new RuntimeException('Could not create the certificate uploads directory.');
            $filename = 'event-certificate-template-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string)$file['tmp_name'],$directory.'/'.$filename)) throw new RuntimeException('Could not save the certificate design.');
            $templatePath = 'assets/uploads/event-certificates/' . $filename;
            event_certificate_save_setting($conn,'event_certificate_template',$templatePath);
            $flash = ['type'=>'success','message'=>'Certificate design uploaded and set as the active event certificate template.'];
        } elseif ($action === 'use_builtin_template') {
            event_certificate_save_setting($conn,'event_certificate_template','');
            $templatePath = '';
            $flash = ['type'=>'success','message'=>'The built-in event certificate design is active again.'];
        } elseif ($action === 'save_template_layout') {
            $nameTop = (float)($_POST['name_top'] ?? 52);
            $detailsTop = (float)($_POST['details_top'] ?? 70);
            if ($nameTop < 10 || $nameTop > 90 || $detailsTop < 10 || $detailsTop > 90) throw new RuntimeException('Template text positions must be between 10% and 90%.');
            $nameTop = round($nameTop,1); $detailsTop = round($detailsTop,1);
            event_certificate_save_setting($conn,'event_certificate_name_top',(string)$nameTop);
            event_certificate_save_setting($conn,'event_certificate_details_top',(string)$detailsTop);
            $flash = ['type'=>'success','message'=>'Certificate template text positions saved.'];
        } elseif ($action === 'save_attendance') {
            $selected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['member_ids'] ?? [])), static fn($id) => $id > 0)));
            $conn->begin_transaction();
            $stmt = $conn->prepare('UPDATE event_attendance SET attended=0,marked_by=? WHERE event_id=?');
            $uid = (int)$_SESSION['user_id']; $stmt->bind_param('ii', $uid, $eventId); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare('INSERT INTO event_attendance(event_id,member_id,attended,marked_by) SELECT ?,id,1,? FROM members WHERE id=? AND COALESCE(status,\'Active\')=\'Active\' ON DUPLICATE KEY UPDATE attended=1,marked_by=VALUES(marked_by),marked_at=CURRENT_TIMESTAMP');
            foreach ($selected as $memberId) { $stmt->bind_param('iii', $eventId, $uid, $memberId); $stmt->execute(); }
            $stmt->close(); $conn->commit();
            $flash = ['type'=>'success','message'=>'Attendance saved. Certificates can now be issued only to members marked present.'];
        } elseif ($action === 'issue_certificate') {
            $memberId = (int)($_POST['member_id'] ?? 0);
            $certificateName = trim((string)($_POST['certificate_name'] ?? $brand['name']));
            if ($memberId <= 0 || $certificateName === '' || mb_strlen($certificateName) > 180) throw new RuntimeException('Enter a valid certificate name.');
            $stmt = $conn->prepare('SELECT m.first_name,m.last_name,m.email,u.email user_email FROM event_attendance ea JOIN members m ON m.id=ea.member_id LEFT JOIN users u ON u.id=m.user_id WHERE ea.event_id=? AND ea.member_id=? AND ea.attended=1 LIMIT 1');
            $stmt->bind_param('ii', $eventId, $memberId); $stmt->execute(); $attendee = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$attendee) throw new RuntimeException('A certificate can only be issued to a member marked present.');
            $exists = false;
            $stmt = $conn->prepare('SELECT id FROM event_certificates WHERE event_id=? AND member_id=? LIMIT 1');
            $stmt->bind_param('ii', $eventId, $memberId); $stmt->execute(); $exists = (bool)$stmt->get_result()->fetch_row(); $stmt->close();
            $certNo = 'EVT-' . str_pad((string)$eventId, 5, '0', STR_PAD_LEFT) . '-' . str_pad((string)$memberId, 5, '0', STR_PAD_LEFT) . '-' . date('Y', strtotime((string)$event['event_date']));
            $uid = (int)$_SESSION['user_id'];
            $stmt = $conn->prepare('INSERT INTO event_certificates(event_id,member_id,certificate_no,certificate_name,issued_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE certificate_name=VALUES(certificate_name),issued_by=VALUES(issued_by),issued_at=CURRENT_TIMESTAMP');
            $stmt->bind_param('iissi', $eventId, $memberId, $certNo, $certificateName, $uid); $stmt->execute(); $certificateId = (int)($stmt->insert_id ?: 0); $stmt->close();
            if ($certificateId <= 0) { $stmt = $conn->prepare('SELECT id FROM event_certificates WHERE event_id=? AND member_id=? LIMIT 1'); $stmt->bind_param('ii',$eventId,$memberId);$stmt->execute();$certificateId=(int)($stmt->get_result()->fetch_assoc()['id']??0);$stmt->close(); }
            $email = trim((string)($attendee['email'] ?: ($attendee['user_email'] ?? '')));
            if (!$exists && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/../config/mail-function.php';
                if (function_exists('sendSystemEmail')) {
                    $memberName = trim((string)$attendee['first_name'] . ' ' . (string)$attendee['last_name']);
                    $url = rtrim(BASE_URL,'/') . '/member/event-certificates.php';
                    $subject = 'Your event participation certificate is ready';
                    $text = 'Your participation certificate for ' . $event['title'] . ' is now available in your member dashboard.';
                    $html = '<p>Hello ' . htmlspecialchars($memberName,ENT_QUOTES,'UTF-8') . ',</p><p>Your participation certificate for <strong>' . htmlspecialchars((string)$event['title'],ENT_QUOTES,'UTF-8') . '</strong> is ready.</p><p><a href="' . htmlspecialchars($url,ENT_QUOTES,'UTF-8') . '">Sign in to view and download your certificate.</a></p>';
                    sendSystemEmail($conn,$email,$memberName,$subject,$html,$text);
                }
            }
            $flash = ['type'=>'success','message'=>($exists?'Certificate updated.':'Certificate issued.') . ' It is available to the member on their dashboard.'];
        } else throw new RuntimeException('Invalid action.');
    } catch (Throwable $e) {
        if ($conn->errno === 0) { /* no-op */ }
        try { $conn->rollback(); } catch (Throwable $ignored) {}
        $flash = ['type'=>'danger','message'=>$e->getMessage()];
    }
}
$members = [];
$stmt = $conn->prepare("SELECT m.id,m.member_no,m.first_name,m.last_name,m.email,COALESCE(ea.attended,0) attended,ec.id certificate_id,ec.certificate_no FROM members m LEFT JOIN event_attendance ea ON ea.member_id=m.id AND ea.event_id=? LEFT JOIN event_certificates ec ON ec.member_id=m.id AND ec.event_id=? WHERE COALESCE(m.status,'Active')='Active' ORDER BY m.first_name,m.last_name");
$stmt->bind_param('ii',$eventId,$eventId);$stmt->execute();$res=$stmt->get_result();while($row=$res->fetch_assoc())$members[]=$row;$stmt->close();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sidebar.php';
?>
<div class="main-content"><?php include __DIR__ . '/../includes/topbar.php'; ?><div class="content-area">
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div><h2>Attendance and Certificates</h2><p class="text-muted mb-0"><?=htmlspecialchars((string)$event['title'],ENT_QUOTES,'UTF-8')?> - <?=htmlspecialchars(date('d M Y',strtotime((string)$event['event_date'])),ENT_QUOTES,'UTF-8')?></p></div><a class="btn btn-outline-secondary" href="index.php">Back to Events</a></div>
<?php if($flash):?><div class="alert alert-<?=htmlspecialchars($flash['type'],ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars($flash['message'],ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<div class="card mb-3"><div class="card-header"><strong>Certificate Design Template</strong></div><div class="card-body"><p>Upload a landscape A4 PNG or JPG design. It becomes the active background for event certificates; the member and event details are overlaid automatically.</p><?php if($templatePath!==''):?><img src="<?=htmlspecialchars(event_certificate_logo_src($templatePath),ENT_QUOTES,'UTF-8')?>" alt="Current certificate template" style="display:block;max-width:480px;max-height:220px;object-fit:contain;margin:0 0 14px;border:1px solid #ddd"><?php else:?><div class="alert alert-info">No custom design is active. Certificates use the built-in design.</div><?php endif;?><form method="post" enctype="multipart/form-data" class="d-flex flex-wrap align-items-end gap-2"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="action" value="upload_template"><label class="form-label">PNG or JPG (up to 12 MB)<input class="form-control" type="file" name="certificate_template" accept="image/png,image/jpeg" required></label><button class="btn btn-primary">Upload and Set Template</button></form><?php if($templatePath!==''):?><form method="post" class="mt-3 d-flex flex-wrap align-items-end gap-2"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="action" value="save_template_layout"><label class="form-label">Member name position, from top (%)<input class="form-control" type="number" min="10" max="90" step=".5" name="name_top" value="<?=htmlspecialchars((string)$nameTop,ENT_QUOTES,'UTF-8')?>"></label><label class="form-label">Event details position, from top (%)<input class="form-control" type="number" min="10" max="90" step=".5" name="details_top" value="<?=htmlspecialchars((string)$detailsTop,ENT_QUOTES,'UTF-8')?>"></label><button class="btn btn-outline-primary">Save Template Layout</button></form><form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="action" value="use_builtin_template"><button class="btn btn-outline-secondary btn-sm">Use Built-in Design</button></form><?php endif;?></div></div>
<form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="action" value="save_attendance"><div class="card"><div class="card-header d-flex justify-content-between align-items-center"><strong>Mark attendees</strong><button class="btn btn-primary btn-sm">Save Attendance</button></div><div class="table-responsive"><table class="table"><thead><tr><th>Present</th><th>Member No.</th><th>Member</th><th>Email</th><th>Certificate</th></tr></thead><tbody><?php foreach($members as $m):?><tr><td><input type="checkbox" name="member_ids[]" value="<?=(int)$m['id']?>" <?=((int)$m['attended']===1?'checked':'')?> aria-label="Mark present"></td><td><?=htmlspecialchars((string)($m['member_no']??''),ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars(trim((string)$m['first_name'].' '.(string)$m['last_name']),ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars((string)($m['email']??''),ENT_QUOTES,'UTF-8')?></td><td><?php if((int)$m['attended']!==1):?><span class="text-muted">Mark present first</span><?php elseif(!empty($m['certificate_id'])):?><span class="badge badge-success">Issued: <?=htmlspecialchars((string)$m['certificate_no'],ENT_QUOTES,'UTF-8')?></span><?php else:?><span class="text-muted">Ready to issue</span><?php endif;?></td></tr><?php endforeach;?><?php if(!$members):?><tr><td colspan="5" class="text-center text-muted">No active members found.</td></tr><?php endif;?></tbody></table></div></div></form>
<div class="card mt-3"><div class="card-header"><strong>Issue Certificates</strong></div><div class="card-body"><p>Certificates are available only for members marked present above. Save attendance before issuing.</p><div class="table-responsive"><table class="table"><thead><tr><th>Attendee</th><th>Issue certificate</th></tr></thead><tbody><?php foreach($members as $m):if((int)$m['attended']!==1)continue;?><tr><td><?=htmlspecialchars(trim((string)$m['first_name'].' '.(string)$m['last_name']),ENT_QUOTES,'UTF-8')?></td><td><form method="post" class="d-flex gap-2 align-items-center"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="event_id" value="<?=$eventId?>"><input type="hidden" name="member_id" value="<?=(int)$m['id']?>"><input type="hidden" name="action" value="issue_certificate"><input class="form-control form-control-sm" name="certificate_name" value="<?=htmlspecialchars($brand['name'],ENT_QUOTES,'UTF-8')?>" maxlength="180" required><button class="btn btn-success btn-sm">Issue / Update</button></form></td></tr><?php endforeach;?></tbody></table></div></div></div>
</div></div><?php require_once __DIR__ . '/../includes/footer.php'; ?>
