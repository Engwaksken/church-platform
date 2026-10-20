<?php

ob_start();
require_once '../config/config.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo json_encode(['success' => false, 'message' => 'Database connection not found.']);
    exit;
}
$conn->set_charset('utf8mb4');

function aj_out($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit;
}
function aj_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function aj_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function aj_col_exists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return $cache[$key] = false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function aj_scalar(mysqli $conn, string $sql): int {
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_row();
    $res->close();
    return (int)($row[0] ?? 0);
}
function aj_session_key($date, $type): string { return $date . '|' . $type; }

try {
    if (!aj_table_exists($conn, 'attendance')) aj_out(false, 'Attendance table does not exist.');
    if (!aj_table_exists($conn, 'members')) aj_out(false, 'Members table does not exist.');

    $action = $_REQUEST['action'] ?? 'list_sessions';

    if ($action === 'list_sessions') {
        $period = strtolower(trim((string)($_GET['period'] ?? 'all')));
        $search = trim((string)($_GET['search'] ?? ''));
        $dateFrom = trim((string)($_GET['date_from'] ?? ''));
        $dateTo = trim((string)($_GET['date_to'] ?? ''));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = (int)($_GET['per_page'] ?? 10);
        $allowedPerPage = [10, 25, 50, 100];

        if (!in_array($perPage, $allowedPerPage, true)) {
            $perPage = 10;
        }

        $today = date('Y-m-d');

        if ($period === 'daily') {
            $dateFrom = $today;
            $dateTo = $today;
        } elseif ($period === 'weekly') {
            $dateFrom = date('Y-m-d', strtotime('monday this week'));
            $dateTo = date('Y-m-d', strtotime('sunday this week'));
        } elseif ($period !== 'range') {
            $dateFrom = '';
            $dateTo = '';
        }

        if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            aj_out(false, 'Invalid start date.');
        }

        if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            aj_out(false, 'Invalid end date.');
        }

        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            aj_out(false, 'The start date cannot be after the end date.');
        }

        $conditions = [];
        $params = [];
        $types = '';

        if ($dateFrom !== '') {
            $conditions[] = 'service_date >= ?';
            $params[] = $dateFrom;
            $types .= 's';
        }

        if ($dateTo !== '') {
            $conditions[] = 'service_date <= ?';
            $params[] = $dateTo;
            $types .= 's';
        }

        if ($search !== '') {
            $conditions[] = '(service_type LIKE ? OR COALESCE(notes, "") LIKE ? OR DATE_FORMAT(service_date, "%d %b %Y") LIKE ?)';
            $term = '%' . $search . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $types .= 'sss';
        }

        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

        $countSql = "SELECT COUNT(*) AS total
                     FROM (
                         SELECT service_date, service_type
                         FROM attendance
                         {$where}
                         GROUP BY service_date, service_type
                     ) AS grouped_sessions";

        $countStmt = $conn->prepare($countSql);

        if (!$countStmt) {
            aj_out(false, 'Unable to prepare attendance count: ' . $conn->error);
        }

        if ($types !== '') {
            $countStmt->bind_param($types, ...$params);
        }

        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $countStmt->close();

        $totalPages = max(1, (int)ceil($total / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $offset = ($page - 1) * $perPage;
        $totalActive = aj_scalar(
            $conn,
            "SELECT COUNT(*) FROM members WHERE COALESCE(status,'Active')='Active'"
        );

        $sql = "SELECT service_date,
                       service_type,
                       SUM(CASE WHEN status='Present' THEN 1 ELSE 0 END) AS present,
                       COUNT(*) AS marked,
                       MAX(notes) AS notes
                FROM attendance
                {$where}
                GROUP BY service_date, service_type
                ORDER BY service_date DESC, service_type ASC
                LIMIT ? OFFSET ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            aj_out(false, 'Unable to prepare attendance query: ' . $conn->error);
        }

        $queryTypes = $types . 'ii';
        $queryParams = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($queryTypes, ...$queryParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];

        while ($row = $result->fetch_assoc()) {
            $present = (int)($row['present'] ?? 0);
            $totalMembers = max($totalActive, (int)($row['marked'] ?? 0));

            $row['total_members'] = $totalMembers;
            $row['present'] = $present;
            $row['absent'] = max(0, $totalMembers - $present);
            $row['percentage'] = $totalMembers > 0
                ? round(($present / $totalMembers) * 100)
                : 0;
            $row['session_key'] = aj_session_key(
                (string)$row['service_date'],
                (string)$row['service_type']
            );

            $items[] = $row;
        }

        $stmt->close();

        $fromRecord = $total > 0 ? $offset + 1 : 0;
        $toRecord = $total > 0 ? min($offset + $perPage, $total) : 0;

        aj_out(true, 'Loaded.', [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'from' => $fromRecord,
                'to' => $toRecord,
            ],
            'filters' => [
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'search' => $search,
            ],
        ]);
    }

    if ($action === 'members') {
        $items = [];
        $date = trim($_GET['service_date'] ?? '');
        $type = trim($_GET['service_type'] ?? '');
        $existing = [];
        if ($date !== '' && $type !== '') {
            $stmt = $conn->prepare("SELECT member_id, status FROM attendance WHERE service_date=? AND service_type=?");
            $stmt->bind_param('ss', $date, $type);
            $stmt->execute();
            $rs = $stmt->get_result();
            while ($r = $rs->fetch_assoc()) $existing[(int)$r['member_id']] = $r['status'];
            $stmt->close();
        }
        $sql = "SELECT id, member_no, first_name, last_name, gender, phone, department
                FROM members
                WHERE COALESCE(status,'Active')='Active'
                ORDER BY first_name ASC, last_name ASC";
        $res = $conn->query($sql);
        while ($res && $m = $res->fetch_assoc()) {
            $mid = (int)$m['id'];
            $m['full_name'] = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
            $m['attendance_status'] = $existing[$mid] ?? 'Present';
            $items[] = $m;
        }
        aj_out(true, 'Loaded members.', ['items' => $items]);
    }

    if ($action === 'get_session') {
        $date = trim($_GET['date'] ?? '');
        $type = trim($_GET['type'] ?? '');
        if ($date === '' || $type === '') aj_out(false, 'Missing attendance session.');

        $stmt = $conn->prepare("SELECT a.*, CONCAT(m.first_name,' ',m.last_name) member_name, m.member_no, m.department, m.gender, m.phone
                               FROM attendance a
                               JOIN members m ON m.id=a.member_id
                               WHERE a.service_date=? AND a.service_type=?
                               ORDER BY a.status DESC, m.first_name ASC, m.last_name ASC");
        $stmt->bind_param('ss', $date, $type);
        $stmt->execute();
        $rs = $stmt->get_result();
        $records = [];
        $present = 0;
        $notes = '';
        while ($r = $rs->fetch_assoc()) {
            if ($r['status'] === 'Present') $present++;
            if ($notes === '' && !empty($r['notes'])) $notes = $r['notes'];
            $records[] = $r;
        }
        $stmt->close();
        $total = count($records);
        aj_out(true, 'Loaded session.', [
            'session' => [
                'service_date' => $date,
                'service_type' => $type,
                'notes' => $notes,
                'total' => $total,
                'present' => $present,
                'absent' => max(0, $total - $present),
                'percentage' => $total > 0 ? round(($present / $total) * 100) : 0,
            ],
            'records' => $records
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') aj_out(false, 'Invalid request method.');

    if ($action === 'save_session') {
        $date = trim($_POST['service_date'] ?? '');
        $type = trim($_POST['service_type'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $present = $_POST['present'] ?? [];
        if (!is_array($present)) $present = [];
        $presentIds = array_flip(array_map('intval', $present));
        if ($date === '' || $type === '') aj_out(false, 'Service date and type are required.');

        $conn->begin_transaction();
        $del = $conn->prepare("DELETE FROM attendance WHERE service_date=? AND service_type=?");
        $del->bind_param('ss', $date, $type);
        if (!$del->execute()) throw new Exception($del->error);
        $del->close();

        $res = $conn->query("SELECT id FROM members WHERE COALESCE(status,'Active')='Active'");
        $ins = $conn->prepare("INSERT INTO attendance (member_id, service_date, service_type, status, notes) VALUES (?, ?, ?, ?, ?)");
        if (!$ins) throw new Exception($conn->error);
        $marked = 0; $presentCount = 0;
        while ($res && $m = $res->fetch_assoc()) {
            $mid = (int)$m['id'];
            $status = isset($presentIds[$mid]) ? 'Present' : 'Absent';
            if ($status === 'Present') $presentCount++;
            $ins->bind_param('issss', $mid, $date, $type, $status, $notes);
            if (!$ins->execute()) throw new Exception($ins->error);
            $marked++;
        }
        $ins->close();
        $conn->commit();
        aj_out(true, "Attendance saved. Present: {$presentCount} of {$marked}.");
    }

    if ($action === 'delete_session') {
        $date = trim($_POST['service_date'] ?? '');
        $type = trim($_POST['service_type'] ?? '');
        if ($date === '' || $type === '') aj_out(false, 'Missing attendance session.');
        $stmt = $conn->prepare("DELETE FROM attendance WHERE service_date=? AND service_type=?");
        $stmt->bind_param('ss', $date, $type);
        if (!$stmt->execute()) aj_out(false, 'Delete failed: ' . $stmt->error);
        $affected = $stmt->affected_rows;
        $stmt->close();
        aj_out(true, "Attendance session deleted ({$affected} records removed).");
    }

    aj_out(false, 'Invalid request action.');
} catch (Throwable $e) {
    if ($conn instanceof mysqli) { @mysqli_rollback($conn); }
    aj_out(false, 'Server error: ' . $e->getMessage());
}
