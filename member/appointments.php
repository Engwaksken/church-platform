<?php
declare(strict_types=1);
$pageTitle='Appointments';
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/_helpers.php';
require_once __DIR__.'/../includes/appointment-functions.php';
require_permission('member.appointments.view');
appointment_ensure_table($conn);
$memberId=current_member_id();
$userId=(int)($_SESSION['user_id']??0);
if($memberId<=0||!appointment_rows($conn,"SELECT id FROM members WHERE id=? AND user_id=? AND COALESCE(status,'Active')='Active' LIMIT 1",'ii',[$memberId,$userId])){http_response_code(403);exit('An active member profile is required to request an appointment.');}
if(empty($_SESSION['appointment_csrf']))$_SESSION['appointment_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['appointment_csrf'];
$targets=appointment_targets($conn);

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new RuntimeException('Your form session expired. Refresh and try again.');
        $action=(string)($_POST['action']??'');
        if($action==='review'){
            $requestId=(int)($_POST['request_id']??0);
            $request=appointment_rows($conn,'SELECT * FROM appointment_requests WHERE id=? LIMIT 1','i',[$requestId])[0]??null;
            if(!$request||!appointment_can_review($conn,$request))throw new RuntimeException('You are not authorized to review that request.');
            if($request['status']!=='Pending')throw new RuntimeException('This request has already been reviewed.');
            $decision=(string)($_POST['decision']??'');$comment=trim((string)($_POST['review_comment']??''));
            if(!in_array($decision,['Approved','Rejected'],true))throw new RuntimeException('Choose approve or reject.');
            if($comment===''||mb_strlen($comment)>3000)throw new RuntimeException('A review comment is required (up to 3,000 characters).');
            $reviewerId=$userId;
            $stmt=$conn->prepare("UPDATE appointment_requests SET status=?,review_comment=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='Pending'");
            $stmt->bind_param('ssii',$decision,$comment,$reviewerId,$requestId);$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
            if(!$changed)throw new RuntimeException('The request has already been reviewed.');
            $member=appointment_rows($conn,'SELECT m.email,m.first_name,m.last_name,u.email AS user_email FROM members m LEFT JOIN users u ON u.id=m.user_id WHERE m.id=? LIMIT 1','i',[(int)$request['member_id']])[0]??[];
            $to=trim((string)($member['email']?:($member['user_email']??'')));
            $preferred=$request['preferred_at']?date('d M Y, g:i A',strtotime((string)$request['preferred_at'])):'Not specified';
            $notice='Your appointment request "'.$request['subject'].'" was '.strtolower($decision).'. Reviewer comment: '.$comment.' Preferred time: '.$preferred.'.';
            $emailSent=$to!==''&&appointment_send_email($conn,$to,trim(($member['first_name']??'').' '.($member['last_name']??''))?:'Member','Appointment request '.$decision,$notice);
            $_SESSION['appointment_flash']=['type'=>'success','message'=>$emailSent?'Appointment request '.$decision.'. The member was emailed and can also view the response in My Appointments.':'Appointment request '.$decision.'. The member can view the response in My Appointments; email delivery was unavailable.'];
        }elseif($action==='create'){
            $targetKey=(string)($_POST['target']??'');$target=null;foreach($targets as $candidate)if($candidate['key']===$targetKey){$target=$candidate;break;}
            $subject=trim((string)($_POST['subject']??''));$message=trim((string)($_POST['message']??''));$preferred=trim((string)($_POST['preferred_at']??''));
            if(!$target)throw new RuntimeException('Choose a valid church leader or department leader.');
            if($subject===''||mb_strlen($subject)>180)throw new RuntimeException('Enter an appointment subject of up to 180 characters.');
            if(mb_strlen($message)<10||mb_strlen($message)>5000)throw new RuntimeException('Explain your request in 10 to 5,000 characters.');
            $preferredAt=null;
            if($preferred!==''){$date=DateTime::createFromFormat('Y-m-d\TH:i',$preferred);if(!$date)throw new RuntimeException('Enter a valid preferred date and time.');$preferredAt=$date->format('Y-m-d H:i:s');}
            $member=appointment_rows($conn,'SELECT first_name,last_name,member_no FROM members WHERE id=? LIMIT 1','i',[$memberId])[0]??[];
            $memberName=trim(($member['first_name']??'').' '.($member['last_name']??''))?:'Member';
            $recipientUser=(int)$target['recipient_user_id'];$recipientMember=$target['recipient_member_id'];$departmentId=$target['department_id'];
            $stmt=$conn->prepare('INSERT INTO appointment_requests(member_id,member_user_id,recipient_user_id,recipient_member_id,department_id,recipient_label,subject,message,preferred_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $stmt->bind_param('iiiiissss',$memberId,$userId,$recipientUser,$recipientMember,$departmentId,$target['recipient_label'],$subject,$message,$preferredAt);
            $stmt->execute();$stmt->close();
            $when=$preferredAt?date('d M Y, g:i A',strtotime($preferredAt)):'Not specified';
            $notice=$memberName.' ('.($member['member_no']??'Member').') requested an appointment: '.$subject.'. Preferred time: '.$when.'. Request: '.$message;
            $emailSent=appointment_send_email($conn,(string)$target['email'],$target['label'],'New member appointment request',$notice);
            $_SESSION['appointment_flash']=['type'=>'success','message'=>$emailSent?'Your appointment request was sent and emailed to '.$target['label'].'.':'Your appointment request was recorded for '.$target['label'].', but email delivery was unavailable.'];
        }else throw new RuntimeException('Invalid appointment action.');
    }catch(Throwable $e){$_SESSION['appointment_flash']=['type'=>'danger','message'=>$e->getMessage()];}
    header('Location: appointments.php');exit;
}

$flash=$_SESSION['appointment_flash']??null;unset($_SESSION['appointment_flash']);
$outgoing=appointment_rows($conn,'SELECT * FROM appointment_requests WHERE member_id=? ORDER BY created_at DESC','i',[$memberId]);
$incoming=appointment_rows($conn,'SELECT ar.*,m.first_name,m.last_name,m.member_no FROM appointment_requests ar JOIN members m ON m.id=ar.member_id WHERE ar.recipient_user_id=? OR ar.recipient_member_id=? ORDER BY ar.status="Pending" DESC,ar.created_at DESC','ii',[$userId,$memberId]);
include __DIR__.'/../includes/sidebar.php';
?>
<div class="main-content"><?php include __DIR__.'/../includes/topbar.php';?><div class="content-area appointment-page">
<?php if(is_array($flash)):?><div class="alert alert-<?=mp_h($flash['type'])?>"><?=mp_h($flash['message'])?></div><?php endif;?>
<div class="appointment-heading"><div><h2><i class="fas fa-calendar-check"></i> Appointments</h2><p>Request a private meeting with a church leader and track the response.</p></div></div>
<div class="appointment-columns">
  <section class="card"><div class="card-header"><h3>Request an Appointment</h3></div><div class="card-body">
    <?php if(!$targets):?><div class="alert alert-info">No church leaders with an active email address are currently available. Please contact the church office.</div><?php else:?>
    <form method="post" class="appointment-form"><input type="hidden" name="csrf_token" value="<?=mp_h($csrf)?>"><input type="hidden" name="action" value="create">
      <label>Send request to<select class="form-control" name="target" required><option value="">Select a church leader</option><?php foreach($targets as $target):?><option value="<?=mp_h($target['key'])?>"><?=mp_h($target['label'])?></option><?php endforeach;?></select></label>
      <label>Subject<input class="form-control" name="subject" maxlength="180" required placeholder="Brief reason for meeting"></label>
      <label>Preferred date and time <span class="appointment-optional">(optional)</span><input class="form-control" type="datetime-local" name="preferred_at"></label>
      <label>Message<textarea class="form-control" name="message" rows="5" minlength="10" maxlength="5000" required placeholder="Please share a short explanation for your request."></textarea></label>
      <button class="btn btn-primary"><i class="fas fa-paper-plane"></i> Send Appointment Request</button>
    </form><?php endif;?>
  </div></section>
  <section class="card"><div class="card-header"><h3>My Requests</h3><span class="appointment-count"><?=count($outgoing)?></span></div><div class="card-body"><div class="appointment-request-list">
    <?php if(!$outgoing):?><div class="appointment-empty">Your appointment requests will appear here.</div><?php else:foreach($outgoing as $request):?><article class="appointment-request"><div class="appointment-request-head"><div><span class="appointment-status <?=strtolower((string)$request['status'])?>"><?=mp_h($request['status'])?></span><h4><?=mp_h($request['subject'])?></h4></div><time><?=mp_h(mp_date($request['created_at'],'d M Y'))?></time></div><p class="appointment-recipient">To: <?=mp_h($request['recipient_label'])?></p><p><?=nl2br(mp_h($request['message']))?></p><?php if(!empty($request['preferred_at'])):?><p><strong>Preferred:</strong> <?=mp_h(date('d M Y, g:i A',strtotime((string)$request['preferred_at'])))?></p><?php endif;?><?php if(!empty($request['review_comment'])):?><div class="appointment-comment"><strong>Leader’s comment</strong><p><?=nl2br(mp_h($request['review_comment']))?></p></div><?php endif;?></article><?php endforeach;endif;?>
  </div></div></section>
</div>

<?php if($incoming):?><section class="card appointment-inbox"><div class="card-header"><h3>Requests for My Review</h3><span class="appointment-count"><?=count($incoming)?></span></div><div class="card-body"><div class="appointment-request-list"><?php foreach($incoming as $request):?><article class="appointment-request"><div class="appointment-request-head"><div><span class="appointment-status <?=strtolower((string)$request['status'])?>"><?=mp_h($request['status'])?></span><h4><?=mp_h($request['subject'])?></h4></div><time><?=mp_h(mp_date($request['created_at'],'d M Y'))?></time></div><p><strong>From:</strong> <?=mp_h(trim(($request['first_name']??'').' '.($request['last_name']??'')))?> (<?=mp_h($request['member_no']??'') ?>)</p><p><?=nl2br(mp_h($request['message']))?></p><?php if(!empty($request['preferred_at'])):?><p><strong>Preferred:</strong> <?=mp_h(date('d M Y, g:i A',strtotime((string)$request['preferred_at'])))?></p><?php endif;?><?php if($request['status']==='Pending'):?><form method="post" class="appointment-review-form"><input type="hidden" name="csrf_token" value="<?=mp_h($csrf)?>"><input type="hidden" name="action" value="review"><input type="hidden" name="request_id" value="<?=(int)$request['id']?>"><label>Comment (required)<textarea class="form-control" name="review_comment" rows="2" maxlength="3000" required></textarea></label><div><button class="btn btn-success btn-sm" name="decision" value="Approved"><i class="fas fa-check"></i> Approve</button><button class="btn btn-danger btn-sm" name="decision" value="Rejected"><i class="fas fa-xmark"></i> Reject</button></div></form><?php elseif(!empty($request['review_comment'])):?><div class="appointment-comment"><strong>Your comment</strong><p><?=nl2br(mp_h($request['review_comment']))?></p></div><?php endif;?></article><?php endforeach;?></div></div></section><?php endif;?>
</div></div>
<style>
.appointment-heading{margin-bottom:18px}.appointment-heading h2{margin:0 0 5px}.appointment-heading p{margin:0;color:#667085}.appointment-columns{display:grid;grid-template-columns:minmax(300px,.85fr) minmax(0,1.15fr);gap:16px;align-items:start}.appointment-form{display:grid;gap:13px}.appointment-form label,.appointment-review-form label{display:grid;gap:6px;color:#344054;font-size:13px;font-weight:700}.appointment-form textarea{min-height:125px;resize:vertical}.appointment-optional{color:#667085;font-weight:400}.appointment-count{display:inline-grid;min-width:26px;height:26px;place-items:center;border-radius:50%;background:#f1ebfa;color:#4a1e8f;font-weight:800;font-size:12px}.appointment-request-list{display:grid;gap:12px}.appointment-request{padding:14px;border:1px solid #e5eaf1;border-radius:11px;background:#fff;overflow-wrap:anywhere}.appointment-request-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.appointment-request-head h4{margin:7px 0 0;color:#172033}.appointment-request-head time{color:#667085;font-size:11px;white-space:nowrap}.appointment-request p{margin:9px 0;color:#475467;line-height:1.5}.appointment-recipient{font-size:12px}.appointment-status{display:inline-flex;padding:4px 9px;border-radius:999px;background:#fff7e6;color:#9a6700;font-size:10px;font-weight:800;text-transform:uppercase}.appointment-status.approved{background:#e8f7ee;color:#167044}.appointment-status.rejected{background:#feeceb;color:#b42318}.appointment-comment{padding:10px 12px;border-left:3px solid #4a1e8f;border-radius:6px;background:#f8f5fc;color:#344054}.appointment-comment p{margin-bottom:0}.appointment-review-form{display:grid;gap:10px;margin-top:12px}.appointment-review-form textarea{resize:vertical}.appointment-review-form>div{display:flex;gap:8px;flex-wrap:wrap}.appointment-empty{padding:22px;color:#667085;text-align:center}.appointment-inbox{margin-top:18px}@media(max-width:900px){.appointment-columns{grid-template-columns:1fr}}
</style>
<?php require_once __DIR__.'/../includes/footer.php';?>
