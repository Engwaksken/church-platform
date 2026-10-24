<?php
ob_start();
header('Content-Type: application/json; charset=utf-8');

require_once '../config/config.php';
if (function_exists('requireLogin')) requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    json_out(false, 'Database connection not found.');
}
$conn->set_charset('utf8mb4');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function json_out($success, $message = '', $extra = []) {
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra));
    exit;
}
function post($key, $default = '') { return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default; }
function getv($key, $default = '') { return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default; }
function table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function col_exists(mysqli $conn, $table, $column) {
    static $cache = [];
    $key = $table.'.'.$column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}
function receipt_no(mysqli $conn) {
    if (function_exists('genNo')) return genNo('RCP');
    $prefix = 'RCP-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM contributions WHERE receipt_no LIKE CONCAT(?, '%')");
    $stmt->bind_param('s', $prefix); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $prefix . str_pad(((int)($row['c'] ?? 0)) + 1, 4, '0', STR_PAD_LEFT);
}
function contribution_select_sql() {
    return "SELECT c.*, m.full_name AS full_name, m.member_no, p.name AS project_name
            FROM contributions c
            LEFT JOIN members m ON m.id = c.member_id
            LEFT JOIN projects p ON p.id = c.project_id";
}
function read_one(mysqli $conn, $id) {
    $sql = contribution_select_sql() . " WHERE c.id = ? LIMIT 1";
    $stmt = $conn->prepare($sql); $stmt->bind_param('i', $id); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row;
}

try {
    if (!table_exists($conn, 'contributions')) json_out(false, 'contributions table not found.');
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' ? post('action') : getv('action', 'list');

    if ($action === 'list') {
        $sql = contribution_select_sql() . " ORDER BY c.contributed_on DESC, c.id DESC";
        $res = $conn->query($sql);
        $items = [];
        while ($row = $res->fetch_assoc()) $items[] = $row;
        json_out(true, '', ['items' => $items]);
    }

    if ($action === 'get') {
        $id = (int)getv('id', 0);
        if ($id <= 0) json_out(false, 'Invalid contribution ID.');
        $row = read_one($conn, $id);
        if (!$row) json_out(false, 'Contribution not found.');
        json_out(true, '', ['item' => $row]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(false, 'Invalid request method.');

    if ($action === 'create' || $action === 'update') {
        $id = (int)post('id', 0);
        $member_id = (int)post('member_id', 0);
        $project_id = (int)post('project_id', 0);
        $amount = (float)post('amount', 0);
        $method = post('payment_method', 'Cash');
        $date = post('contributed_on', date('Y-m-d')) ?: date('Y-m-d');
        $receipt = post('receipt_no', '');
        $notes = post('notes', '');

        if ($member_id <= 0) json_out(false, 'Please select a member.');
        if ($project_id <= 0) json_out(false, 'Please select a project.');
        if ($amount <= 0) json_out(false, 'Please enter a valid amount.');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) json_out(false, 'Invalid contribution date.');
        if ($receipt === '') $receipt = receipt_no($conn);

        if ($action === 'create') {
            $stmt = $conn->prepare("INSERT INTO contributions (receipt_no, member_id, project_id, amount, payment_method, contributed_on, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('siidsss', $receipt, $member_id, $project_id, $amount, $method, $date, $notes);
            $stmt->execute(); $newId = $stmt->insert_id; $stmt->close();
            json_out(true, 'Contribution recorded. Receipt: ' . $receipt, ['id' => $newId]);
        }

        if ($id <= 0) json_out(false, 'Invalid contribution ID.');
        $stmt = $conn->prepare("UPDATE contributions SET receipt_no=?, member_id=?, project_id=?, amount=?, payment_method=?, contributed_on=?, notes=? WHERE id=?");
        $stmt->bind_param('siidsssi', $receipt, $member_id, $project_id, $amount, $method, $date, $notes, $id);
        $stmt->execute(); $stmt->close();
        json_out(true, 'Contribution updated successfully.');
    }

    if ($action === 'delete') {
        $id = (int)post('id', 0);
        if ($id <= 0) json_out(false, 'Invalid contribution ID.');
        $stmt = $conn->prepare("DELETE FROM contributions WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        json_out(true, 'Contribution deleted successfully.');
    }

    json_out(false, 'Invalid request action.');
} catch (Throwable $e) {
    error_log('[process-contributions] ' . $e->getMessage());
    json_out(false, 'Server error: ' . $e->getMessage());
}
