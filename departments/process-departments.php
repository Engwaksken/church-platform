<?php
declare(strict_types=1);

ob_start();
header('Content-Type: application/json; charset=utf-8');

try {
    require_once '../config/config.php';
    require_once '../config/db.php';
    if (function_exists('requireLogin')) { requireLogin(); }
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection not found.');
    }
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Bootstrap error: '.$e->getMessage()]);
    exit;
}

function json_out(array $data): void {
    if (ob_get_length()) ob_clean();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function val(string $key, string $default=''): string { return trim((string)($_POST[$key] ?? $_GET[$key] ?? $default)); }
function intv(string $key): int { return (int)($_POST[$key] ?? $_GET[$key] ?? 0); }
function table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function col_exists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = "$table.$column";
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function scalar(mysqli $conn, string $sql): int {
    $res = $conn->query($sql); if (!$res) return 0;
    $row = $res->fetch_row(); $res->close(); return (int)($row[0] ?? 0);
}
function department_select_sql(mysqli $conn, string $where='1=1'): string {
    $leaderJoin = table_exists($conn, 'members') ? " LEFT JOIN members m ON m.id = d.leader " : "";
    $leaderFields = table_exists($conn, 'members') ? ", TRIM(CONCAT(COALESCE(m.first_name,''),' ',COALESCE(m.last_name,''))) AS leader_name, m.member_no AS leader_no" : ", '' AS leader_name, '' AS leader_no";
    $countSql = table_exists($conn, 'department_members') ? "(SELECT COUNT(*) FROM department_members dm WHERE dm.dept_id=d.id)" : "0";
    return "SELECT d.*, {$countSql} AS member_count {$leaderFields} FROM departments d {$leaderJoin} WHERE {$where}";
}

try {
    $action = val('action', 'list');
    if (!table_exists($conn, 'departments')) json_out(['success'=>false,'message'=>'departments table does not exist.']);

    if ($action === 'stats') {
        $total = scalar($conn, "SELECT COUNT(*) FROM departments");
        $active = col_exists($conn,'departments','status') ? scalar($conn, "SELECT COUNT(*) FROM departments WHERE COALESCE(status,'Active')='Active'") : $total;
        $inactive = col_exists($conn,'departments','status') ? scalar($conn, "SELECT COUNT(*) FROM departments WHERE COALESCE(status,'Active')<>'Active'") : 0;
        $members = table_exists($conn,'department_members') ? scalar($conn, "SELECT COUNT(*) FROM department_members") : 0;
        json_out(['success'=>true,'stats'=>compact('total','active','inactive','members')]);
    }

    if ($action === 'list') {
        $sql = department_select_sql($conn) . " ORDER BY d.name ASC";
        $res = $conn->query($sql);
        if (!$res) throw new Exception($conn->error);
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        json_out(['success'=>true,'items'=>$items]);
    }

    if ($action === 'get') {
        $id = intv('id');
        if ($id <= 0) json_out(['success'=>false,'message'=>'Invalid department ID.']);
        $sql = department_select_sql($conn, 'd.id=?');
        $stmt = $conn->prepare($sql); $stmt->bind_param('i', $id); $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$item) json_out(['success'=>false,'message'=>'Department not found.']);
        json_out(['success'=>true,'item'=>$item]);
    }

    if ($action === 'members') {
        $id = intv('id');
        if ($id <= 0) json_out(['success'=>false,'message'=>'Invalid department ID.']);
        if (!table_exists($conn,'department_members') || !table_exists($conn,'members')) json_out(['success'=>true,'members'=>[], 'available'=>[]]);
        $stmt = $conn->prepare("SELECT m.id,m.member_no,m.first_name,m.last_name,m.gender,m.phone,m.email,dm.role,dm.joined_date FROM department_members dm JOIN members m ON m.id=dm.member_id WHERE dm.dept_id=? ORDER BY m.first_name,m.last_name");
        $stmt->bind_param('i', $id); $stmt->execute();
        $res = $stmt->get_result(); $members=[];
        while($r=$res->fetch_assoc()) { $r['full_name'] = trim(($r['first_name']??'').' '.($r['last_name']??'')); $members[]=$r; }
        $stmt->close();
        $stmt = $conn->prepare("SELECT id,member_no,first_name,last_name,phone FROM members WHERE COALESCE(status,'Active')='Active' AND id NOT IN (SELECT member_id FROM department_members WHERE dept_id=?) ORDER BY first_name,last_name");
        $stmt->bind_param('i', $id); $stmt->execute();
        $res = $stmt->get_result(); $available=[];
        while($r=$res->fetch_assoc()) { $r['full_name'] = trim(($r['first_name']??'').' '.($r['last_name']??'')); $available[]=$r; }
        $stmt->close();
        json_out(['success'=>true,'members'=>$members,'available'=>$available]);
    }

    if ($action === 'create' || $action === 'update') {
        $id = intv('id');
        $name = val('name');
        $description = val('description');
        $leader = intv('leader');
        $phone = val('phone');
        $status = val('status','Active') ?: 'Active';
        if ($name === '') json_out(['success'=>false,'message'=>'Department name is required.']);
        $leaderVal = $leader > 0 ? $leader : null;
        if ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO departments (name, description, leader, phone, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
            if (!$stmt) {
                $stmt = $conn->prepare("INSERT INTO departments (name, description, leader, phone, status) VALUES (?, ?, ?, ?, ?)");
            }
            $stmt->bind_param('ssiss', $name, $description, $leaderVal, $phone, $status);
            $stmt->execute(); $newId = $stmt->insert_id; $stmt->close();
            json_out(['success'=>true,'message'=>'Department created successfully.','id'=>$newId]);
        }
        if ($id <= 0) json_out(['success'=>false,'message'=>'Invalid department ID.']);
        $stmt = $conn->prepare("UPDATE departments SET name=?, description=?, leader=?, phone=?, status=? WHERE id=?");
        $stmt->bind_param('ssissi', $name, $description, $leaderVal, $phone, $status, $id);
        $stmt->execute(); $stmt->close();
        json_out(['success'=>true,'message'=>'Department updated successfully.']);
    }

    if ($action === 'delete') {
        $id = intv('id');
        if ($id <= 0) json_out(['success'=>false,'message'=>'Invalid department ID.']);
        if (table_exists($conn,'department_members')) {
            $stmt = $conn->prepare("DELETE FROM department_members WHERE dept_id=?"); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
        }
        $stmt = $conn->prepare("DELETE FROM departments WHERE id=?"); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
        json_out(['success'=>true,'message'=>'Department deleted successfully.']);
    }

    if ($action === 'add_member') {
        $deptId = intv('dept_id'); $memberId = intv('member_id'); $role = val('role','Member') ?: 'Member';
        if ($deptId <= 0 || $memberId <= 0) json_out(['success'=>false,'message'=>'Select department and member.']);
        if (!table_exists($conn,'department_members')) json_out(['success'=>false,'message'=>'department_members table does not exist.']);
        $stmt = $conn->prepare("SELECT id FROM department_members WHERE dept_id=? AND member_id=? LIMIT 1");
        $stmt->bind_param('ii',$deptId,$memberId); $stmt->execute(); $exists=$stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($exists) json_out(['success'=>false,'message'=>'This member is already in this department.']);
        $stmt = $conn->prepare("INSERT INTO department_members (dept_id, member_id, role, joined_date) VALUES (?, ?, ?, CURDATE())");
        $stmt->bind_param('iis',$deptId,$memberId,$role); $stmt->execute(); $stmt->close();
        json_out(['success'=>true,'message'=>'Member added to department.']);
    }

    if ($action === 'remove_member') {
        $deptId = intv('dept_id'); $memberId = intv('member_id');
        if ($deptId <= 0 || $memberId <= 0) json_out(['success'=>false,'message'=>'Invalid department/member.']);
        $stmt = $conn->prepare("DELETE FROM department_members WHERE dept_id=? AND member_id=?");
        $stmt->bind_param('ii',$deptId,$memberId); $stmt->execute(); $stmt->close();
        json_out(['success'=>true,'message'=>'Member removed from department.']);
    }

    json_out(['success'=>false,'message'=>'Invalid request action.']);
} catch (Throwable $e) {
    json_out(['success'=>false,'message'=>'Server error: '.$e->getMessage()]);
}
