<?php
declare(strict_types=1);
require_once '../config/config.php'; require_once '../includes/church-book-functions.php'; requireLogin(); cb_require_permission('wedding_vows.manage');
if(!isset($conn)||!($conn instanceof mysqli))die('Database connection not found.'); $conn->set_charset('utf8mb4');
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: ./');exit;} cb_verify_csrf();
$action=cb_clean($_POST['action']??'');
if($action==='create'){
    $weddingId=cb_nullable_int($_POST['wedding_id']??''); $label=cb_clean($_POST['label']??''); $name=cb_clean($_POST['recipient_name']??''); $phone=cb_clean($_POST['recipient_phone']??'');
    if($weddingId!==null){$w=cb_fetch_one($conn,"SELECT id,groom_name,groom_phone FROM wedding_vows WHERE id=?",'i',[$weddingId]); if(!$w)$weddingId=null; else{if($name==='')$name=(string)$w['groom_name'];if($phone==='')$phone=(string)$w['groom_phone'];}}
    $max=max(1,min(50,cb_int($_POST['max_uses']??1,1))); $expires=cb_datetime_local_to_sql(cb_clean($_POST['expires_at']??'')); $token=bin2hex(random_bytes(32)); $uid=cb_current_user_id();
    $stmt=$conn->prepare("INSERT INTO wedding_registration_links(wedding_id,token,label,recipient_name,recipient_phone,max_uses,expires_at,is_active,created_by) VALUES(?,?,?,?,?,?,?,1,?)");
    if(!$stmt){cb_flash('Could not prepare groom registration link.','error');header('Location: ./');exit;}
    $stmt->bind_param('issssisi',$weddingId,$token,$label,$name,$phone,$max,$expires,$uid); $ok=$stmt->execute(); $id=(int)$conn->insert_id; $err=$stmt->error; $stmt->close();
    if(!$ok){cb_flash('Could not create groom registration link: '.$err,'error');header('Location: ./');exit;}
    $_SESSION['cb_share_link']=['kind'=>'wedding','id'=>$id,'url'=>cb_public_url('public_register/wedding.php',['token'=>$token]),'title'=>$label!==''?$label:($name!==''?'Groom: '.$name:'Groom Registration')];
    cb_flash('Groom registration link and QR code generated.'); header('Location: ./'); exit;
}
if($action==='revoke'){$id=cb_int($_POST['id']??0);if($id>0){$stmt=$conn->prepare("UPDATE wedding_registration_links SET is_active=0 WHERE id=?");$stmt->bind_param('i',$id);$stmt->execute();$stmt->close();cb_flash('Groom registration link revoked.');}header('Location: ./');exit;}
cb_flash('Invalid registration-link action.','error');header('Location: ./');exit;
