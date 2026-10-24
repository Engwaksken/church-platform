<?php
require_once '../config/config.php';
requireLogin();

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function pj_json($ok, $message='', $extra=[]) {
    echo json_encode(array_merge(['success'=>$ok,'message'=>$message], $extra));
    exit;
}
function pj_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pj_table_exists($table){ global $conn; $t = mysqli_real_escape_string($conn,$table); $r=mysqli_query($conn,"SHOW TABLES LIKE '$t'"); return $r && mysqli_num_rows($r)>0; }
function pj_col_exists($table,$col){ global $conn; static $c=[]; $k="$table.$col"; if(isset($c[$k])) return $c[$k]; $t=mysqli_real_escape_string($conn,$table); $x=mysqli_real_escape_string($conn,$col); $r=mysqli_query($conn,"SHOW COLUMNS FROM `$t` LIKE '$x'"); return $c[$k]=($r && mysqli_num_rows($r)>0); }
function pj_post($k,$d=''){ return trim((string)($_POST[$k] ?? $d)); }
function pj_bind_dynamic($stmt, $types, &$params){ $refs=[]; $refs[]=$types; foreach($params as $k=>&$v){ $refs[]=&$v; } return call_user_func_array([$stmt,'bind_param'],$refs); }
function pj_generate_no(){
    if (function_exists('genNo')) return genNo('PRJ');
    return 'PRJ-' . date('Ymd') . '-' . str_pad((string)random_int(1,9999),4,'0',STR_PAD_LEFT);
}

try {
    if (!isset($conn) || !($conn instanceof mysqli)) pj_json(false,'Database connection not found.');
    mysqli_set_charset($conn,'utf8mb4');
    if (!pj_table_exists('projects')) pj_json(false,'projects table does not exist.');

    $action = $_REQUEST['action'] ?? 'list';

    if ($action === 'list') {
        $hasContrib = pj_table_exists('contributions') && pj_col_exists('contributions','project_id') && pj_col_exists('contributions','amount');
        $sql = "SELECT p.*";
        if ($hasContrib) $sql .= ", COUNT(DISTINCT c.id) contribution_count, COALESCE(SUM(c.amount),0) total_raised";
        else $sql .= ", 0 contribution_count, 0 total_raised";
        $sql .= " FROM projects p";
        if ($hasContrib) $sql .= " LEFT JOIN contributions c ON c.project_id=p.id";
        $sql .= " GROUP BY p.id ORDER BY " . (pj_col_exists('projects','created_at') ? 'p.created_at DESC,' : '') . " p.id DESC";
        $res = mysqli_query($conn,$sql);
        if (!$res) pj_json(false, mysqli_error($conn));
        $items=[];
        while($row=mysqli_fetch_assoc($res)) $items[]=$row;
        pj_json(true,'Loaded',['items'=>$items]);
    }

    if ($action === 'get') {
        $id=(int)($_GET['id'] ?? $_POST['id'] ?? 0);
        if($id<=0) pj_json(false,'Invalid project ID.');
        $stmt=mysqli_prepare($conn,"SELECT * FROM projects WHERE id=? LIMIT 1");
        mysqli_stmt_bind_param($stmt,'i',$id);
        mysqli_stmt_execute($stmt);
        $item=mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if(!$item) pj_json(false,'Project not found.');

        $contribs=[];
        if (pj_table_exists('contributions') && pj_col_exists('contributions','project_id')) {
          $nameSql = pj_table_exists('members')
    ? "COALESCE(NULLIF(TRIM(m.full_name), ''), 'Member')"
    : "'Member'";

$join = pj_table_exists('members') && pj_col_exists('contributions','member_id')
    ? " LEFT JOIN members m ON m.id = c.member_id"
    : "";

$orderCol = pj_col_exists('contributions','contributed_on') ? 'c.contributed_on' : 'c.id';

$sql = "SELECT c.*, $nameSql AS member_name
        FROM contributions c
        $join
        WHERE c.project_id = ?
        ORDER BY $orderCol DESC
        LIMIT 20";
            if($stmt=mysqli_prepare($conn,$sql)){
                mysqli_stmt_bind_param($stmt,'i',$id); mysqli_stmt_execute($stmt); $rs=mysqli_stmt_get_result($stmt);
                while($r=mysqli_fetch_assoc($rs)) $contribs[]=$r;
                mysqli_stmt_close($stmt);
            }
        }
        pj_json(true,'Loaded',['item'=>$item,'contributions'=>$contribs]);
    }

    if ($action === 'create' || $action === 'update') {
        $id=(int)pj_post('id',0);
        $name=pj_post('name');
        $type=pj_post('type','Other');
        $desc=pj_post('description');
        $target=(float)pj_post('target_amount',0);
        $start=pj_post('start_date', date('Y-m-d'));
        $end=pj_post('end_date');
        $status=pj_post('status','Active');
        if($name==='') pj_json(false,'Project name is required.');
        if($start==='') $start=date('Y-m-d');
        if(!in_array($status,['Active','Paused','Completed','Inactive'],true)) $status='Active';

        if ($action === 'create') {
            $no = pj_generate_no();
            $cols=['project_no','name','type','description','target_amount','start_date','end_date','status'];
            $vals=[$no,$name,$type,$desc,$target,$start,($end!==''?$end:null),$status];
            $types='ssssssss';
            $vals[4]=(string)$target;
            if (!pj_col_exists('projects','project_no')) { array_shift($cols); array_shift($vals); $types=substr($types,1); }
            $ph=implode(',',array_fill(0,count($cols),'?'));
            $sql="INSERT INTO projects (`".implode('`,`',$cols)."`) VALUES ($ph)";
            $stmt=mysqli_prepare($conn,$sql);
            if(!$stmt) pj_json(false,mysqli_error($conn));
            pj_bind_dynamic($stmt,$types,$vals);
            if(!mysqli_stmt_execute($stmt)) pj_json(false,mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            pj_json(true,'Project created successfully.');
        } else {
            if($id<=0) pj_json(false,'Invalid project ID.');
            $sql="UPDATE projects SET name=?, type=?, description=?, target_amount=?, start_date=?, end_date=?, status=? WHERE id=?";
            $stmt=mysqli_prepare($conn,$sql);
            if(!$stmt) pj_json(false,mysqli_error($conn));
            $endVal = $end!=='' ? $end : null;
            mysqli_stmt_bind_param($stmt,'sssdsssi',$name,$type,$desc,$target,$start,$endVal,$status,$id);
            if(!mysqli_stmt_execute($stmt)) pj_json(false,mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            pj_json(true,'Project updated successfully.');
        }
    }

    if ($action === 'delete') {
        $id=(int)pj_post('id',0);
        if($id<=0) pj_json(false,'Invalid project ID.');
        if (pj_col_exists('projects','status')) {
            $status='Inactive';
            $stmt=mysqli_prepare($conn,"UPDATE projects SET status=? WHERE id=?");
            mysqli_stmt_bind_param($stmt,'si',$status,$id);
            if(!mysqli_stmt_execute($stmt)) pj_json(false,mysqli_stmt_error($stmt));
            mysqli_stmt_close($stmt);
            pj_json(true,'Project deactivated successfully.');
        }
        $stmt=mysqli_prepare($conn,"DELETE FROM projects WHERE id=?");
        mysqli_stmt_bind_param($stmt,'i',$id);
        if(!mysqli_stmt_execute($stmt)) pj_json(false,mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        pj_json(true,'Project deleted successfully.');
    }

    pj_json(false,'Invalid request action.');
} catch (Throwable $e) {
    pj_json(false,'Server error: '.$e->getMessage());
}
