<?php
declare(strict_types=1);
$pageTitle='Appointment Requests';
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/../includes/appointment-functions.php';
appointment_ensure_table($conn);
$userId=(int)($_SESSION['user_id']??0);
if($userId<=0){http_response_code(403);exit('Access denied.');}
$memberId=function_exists('current_member_id')?current_member_id():0;
if(empty($_SESSION['appointment_csrf']))$_SESSION['appointment_csrf']=bin2hex(random_bytes(32));
$csrf=(string)$_SESSION['appointment_csrf'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!hash_equals($csrf,(string)($_POST['csrf_token']??'')))throw new RuntimeException('Your form session expired. Refresh and try again.');
        $requestId=(int)($_POST['request_id']??0);
        $request=appointment_rows($conn,'SELECT * FROM appointment_requests WHERE id=? LIMIT 1','i',[$requestId])[0]??null;
        if(!$request||!appointment_can_review($conn,$request))throw new RuntimeException('You are not authorized to review this request.');
        if($request['status']!=='Pending')throw new RuntimeException('This request has already been reviewed.');
        $decision=(string)($_POST['decision']??'');$comment=trim((string)($_POST['review_comment']??''));
        if(!in_array($decision,['Approved','Rejected'],true))throw new RuntimeException('Choose approve or reject.');
        if($comment===''||mb_strlen($comment)>3000)throw new RuntimeException('A review comment is required (up to 3,000 characters).');
        $stmt=$conn->prepare("UPDATE appointment_requests SET status=?,review_comment=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND status='Pending'");
        $stmt->bind_param('ssii',$decision,$comment,$userId,$requestId);$stmt->execute();$changed=$stmt->affected_rows;$stmt->close();
        if(!$changed)throw new RuntimeException('This request has already been reviewed.');
        $member=appointment_rows($conn,'SELECT m.email,m.first_name,m.last_name,u.email AS user_email FROM members m LEFT JOIN users u ON u.id=m.user_id WHERE m.id=? LIMIT 1','i',[(int)$request['member_id']])[0]??[];
        $to=trim((string)($member['email']?:($member['user_email']??'')));
        $notice='Your appointment request "'.$request['subject'].'" was '.strtolower($decision).'. Reviewer comment: '.$comment;
        $emailSent=$to!==''&&appointment_send_email($conn,$to,trim(($member['first_name']??'').' '.($member['last_name']??''))?:'Member','Appointment request '.$decision,$notice);
        $_SESSION['appointment_flash']=['type'=>'success','message'=>$emailSent?'Appointment request '.$decision.'. The member was emailed and can also view the response in My Appointments.':'Appointment request '.$decision.'. The member can view the response in My Appointments; email delivery was unavailable.'];
    }catch(Throwable $e){$_SESSION['appointment_flash']=['type'=>'danger','message'=>$e->getMessage()];}
    header('Location: '.BASE_URL.'appointments/');exit;
}

$isGlobal=appointment_is_global_reviewer();
$rows=$isGlobal
    ? appointment_rows($conn,'SELECT ar.*,m.first_name,m.last_name,m.member_no FROM appointment_requests ar JOIN members m ON m.id=ar.member_id ORDER BY ar.status="Pending" DESC,ar.created_at DESC')
    : appointment_rows($conn,'SELECT ar.*,m.first_name,m.last_name,m.member_no FROM appointment_requests ar JOIN members m ON m.id=ar.member_id WHERE ar.recipient_user_id=? OR ar.recipient_member_id=? ORDER BY ar.status="Pending" DESC,ar.created_at DESC','ii',[$userId,$memberId]);

require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/../includes/sidebar.php';
$flash=$_SESSION['appointment_flash']??null;unset($_SESSION['appointment_flash']);
?>
<div class="main-content"><?php include __DIR__.'/../includes/topbar.php';?><div class="content-area appointment-review-page">
<?php if(is_array($flash)):?><div class="alert alert-<?=htmlspecialchars((string)$flash['type'],ENT_QUOTES,'UTF-8')?>"><?=htmlspecialchars((string)$flash['message'],ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<div class="appointment-review-heading"><div><h2><i class="fas fa-calendar-check"></i> Appointment Requests</h2><p><?= $isGlobal?'Review requests across the church.':'Review requests addressed to you.' ?></p></div></div>
<?php if(!$rows):?><div class="appointment-review-empty"><i class="fas fa-inbox"></i><h3>No appointment requests</h3><p>Requests addressed to you will appear here.</p></div><?php else:?>
<div class="table-responsive"><table class="table appointment-review-table"><thead><tr><th>Date</th><th>Member</th><th>Recipient</th><th>Subject and request</th><th>Preferred time</th><th>Status and action</th></tr></thead><tbody>
<?php foreach($rows as $request):?><tr><td><?=htmlspecialchars(date('d M Y',strtotime((string)$request['created_at'])),ENT_QUOTES,'UTF-8')?></td><td><?=htmlspecialchars(trim(($request['first_name']??'').' '.($request['last_name']??'')),ENT_QUOTES,'UTF-8')?><br><small><?=htmlspecialchars((string)($request['member_no']??''),ENT_QUOTES,'UTF-8')?></small></td><td><?=htmlspecialchars(appointment_official_label((string)$request['recipient_label']),ENT_QUOTES,'UTF-8')?></td><td><strong><?=htmlspecialchars((string)$request['subject'],ENT_QUOTES,'UTF-8')?></strong><div class="appointment-review-message"><?=nl2br(htmlspecialchars((string)$request['message'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))?></div></td><td><?=!empty($request['preferred_at'])?htmlspecialchars(date('d M Y, g:i A',strtotime((string)$request['preferred_at'])),ENT_QUOTES,'UTF-8'):'Not specified'?></td><td><span class="appointment-review-status <?=strtolower((string)$request['status'])?>"><?=htmlspecialchars((string)$request['status'],ENT_QUOTES,'UTF-8')?></span><?php if($request['status']==='Pending'):?><form method="post" class="appointment-decision-form"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars($csrf,ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="request_id" value="<?=(int)$request['id']?>"><label>Comment to member <textarea class="form-control" name="review_comment" rows="3" maxlength="3000" required placeholder="Explain your decision or suggest another time."></textarea></label><div><button class="btn btn-success" name="decision" value="Approved">Approve</button><button class="btn btn-danger" name="decision" value="Rejected">Reject</button></div></form><?php elseif(!empty($request['review_comment'])):?><div class="appointment-review-comment"><b>Review comment</b><p><?=nl2br(htmlspecialchars((string)$request['review_comment'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))?></p></div><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</div></div>
<style>
.appointment-review-heading{margin-bottom:18px}.appointment-review-heading h2{margin:0 0 5px}.appointment-review-heading p{margin:0;color:#667085}.appointment-review-table{min-width:950px}.appointment-review-table td{vertical-align:top;overflow-wrap:anywhere}.appointment-review-table td>div{margin-top:7px}.appointment-review-status{display:inline-flex;padding:4px 9px;border-radius:999px;background:#fff7e6;color:#9a6700;font-size:10px;font-weight:800;text-transform:uppercase;white-space:nowrap}.appointment-review-status.approved{background:#e8f7ee;color:#167044}.appointment-review-status.rejected{background:#feeceb;color:#b42318}.appointment-review-message{white-space:pre-wrap;color:#344054;line-height:1.55}.appointment-decision-form{display:grid;gap:8px;min-width:190px}.appointment-decision-form label{display:grid;gap:6px;font-size:12px;font-weight:700}.appointment-decision-form>div{display:flex;gap:8px;flex-wrap:wrap}.appointment-review-comment{padding:9px;border-left:3px solid #4a1e8f;background:#f8f5fc;border-radius:6px}.appointment-review-comment p{margin:5px 0 0;white-space:pre-wrap}.appointment-review-empty{text-align:center;padding:50px 18px;border:1px dashed #cbd2de;border-radius:14px;background:#fff;color:#667085}.appointment-review-empty i{font-size:30px;color:#4a1e8f}
</style>
<?php require_once __DIR__.'/../includes/footer.php';?>
