<?php
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }
if (!isset($conn) || !($conn instanceof mysqli)) { echo json_encode(['success'=>false,'message'=>'Database connection not found.']); exit; }
$conn->set_charset('utf8mb4');

function out($a){ echo json_encode($a); exit; }
function s($v){ return trim((string)$v); }
function table_exists($table){ global $conn; $st=$conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?"); $st->bind_param('s',$table); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close(); return (int)($r['c']??0)>0; }
function make_zone_code(){ global $conn; $prefix='ZONE-'.date('Y').'-'; $res=$conn->query("SELECT COUNT(*)+1 FROM church_zones"); $n=$res?(int)$res->fetch_row()[0]:1; return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT); }

try{

$action = $_REQUEST['action'] ?? 'list';

if ($action === 'list') {
    $sql = "SELECT z.*, m.full_name AS leader_name, m.member_no AS leader_no
            FROM church_zones z
            LEFT JOIN members m ON m.id=z.leader_member_id
            ORDER BY z.status ASC, z.zone_name ASC";
    $res = $conn->query($sql);
    $items=[]; while($res && $row=$res->fetch_assoc()) $items[]=$row;
    out(['success'=>true,'items'=>$items]);
}

if ($action === 'get') {
    $id=(int)($_GET['id']??0); if($id<=0) out(['success'=>false,'message'=>'Invalid zone.']);
    $st=$conn->prepare("SELECT z.*, m.full_name AS leader_name, m.member_no AS leader_no
                        FROM church_zones z LEFT JOIN members m ON m.id=z.leader_member_id WHERE z.id=? LIMIT 1");
    $st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
    out($row?['success'=>true,'item'=>$row]:['success'=>false,'message'=>'Zone not found.']);
}

if ($action === 'create' || $action === 'update') {
    $id=(int)($_POST['id']??0);
    $zone_name=s($_POST['zone_name']??'');
    $zone_code=s($_POST['zone_code']??'');
    $leader=(int)($_POST['leader_member_id']??0);
    $phone=s($_POST['phone']??'');
    $email=s($_POST['email']??'');
    $location=s($_POST['location']??'');
    $meeting_place=s($_POST['meeting_place']??'');
    $meeting_day=s($_POST['meeting_day']??'');
    $meeting_time=s($_POST['meeting_time']??'');
    $description=s($_POST['description']??'');
    $status=s($_POST['status']??'Active');
    if($zone_name==='') out(['success'=>false,'message'=>'Zone name is required.']);
    if(!in_array($status,['Active','Inactive'],true)) $status='Active';
    if($zone_code==='') $zone_code=make_zone_code();

    $dupSql="SELECT id FROM church_zones WHERE zone_code=?".($action==='update'?' AND id<>?':'')." LIMIT 1";
    $st=$conn->prepare($dupSql);
    if($action==='update'){ $st->bind_param('si',$zone_code,$id); } else { $st->bind_param('s',$zone_code); }
    $st->execute(); $dup=$st->get_result()->fetch_assoc(); $st->close();
    if($dup) out(['success'=>false,'message'=>'Zone code already exists.']);

    if($action==='update' && $id>0){
        $st=$conn->prepare("UPDATE church_zones SET zone_name=?, zone_code=?, leader_member_id=NULLIF(?,0), phone=?, email=?, location=?, meeting_place=?, meeting_day=?, meeting_time=?, description=?, status=? WHERE id=?");
        $st->bind_param('ssissssssssi',$zone_name,$zone_code,$leader,$phone,$email,$location,$meeting_place,$meeting_day,$meeting_time,$description,$status,$id);
        $st->execute(); $st->close();
        out(['success'=>true,'message'=>'Zone updated successfully.','id'=>$id]);
    }

    $createdBy=(int)($_SESSION['user_id']??0);
    $st=$conn->prepare("INSERT INTO church_zones (zone_name, zone_code, leader_member_id, phone, email, location, meeting_place, meeting_day, meeting_time, description, status, created_by) VALUES (?,?,NULLIF(?,0),?,?,?,?,?,?,?,?,?)");
    $st->bind_param('ssissssssssi',$zone_name,$zone_code,$leader,$phone,$email,$location,$meeting_place,$meeting_day,$meeting_time,$description,$status,$createdBy);
    $st->execute(); $newId=$conn->insert_id; $st->close();
    out(['success'=>true,'message'=>'Zone created successfully.','id'=>$newId]);
}

if ($action === 'delete') {
    $id=(int)($_POST['id']??0); if($id<=0) out(['success'=>false,'message'=>'Invalid zone.']);
    $st=$conn->prepare('DELETE FROM church_zones WHERE id=?'); $st->bind_param('i',$id); $st->execute(); $st->close();
    out(['success'=>true,'message'=>'Zone deleted successfully.']);
}

out(['success'=>false,'message'=>'Invalid action.']);
}catch(Throwable $e){ out(['success'=>false,'message'=>$e->getMessage()]); }
