<?php
/**
 * thanksgiving/process-thanksgiving.php
 * AJAX backend for Thanksgiving Offerings.
 */

ob_start();
require_once '../config/config.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function tg_json(bool $success, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function tg_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tg_input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function tg_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function tg_col_exists(mysqli $conn, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function tg_gen_receipt(mysqli $conn): string
{
    if (function_exists('genNo')) {
        $no = genNo('TGV');
        if ($no) return (string)$no;
    }
    $prefix = 'TGV-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM thanksgiving WHERE DATE(created_at) = CURDATE()");
    try {
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $num = ((int)($row['c'] ?? 0)) + 1;
    } catch (Throwable $e) {
        $num = random_int(1, 9999);
    }
    $stmt->close();
    return $prefix . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
}

function tg_fetch_one(mysqli $conn, int $id): ?array
{
    $sql = "SELECT t.*, m.full_name AS member_name, m.member_no, m.phone, m.email
            FROM thanksgiving t
            LEFT JOIN members m ON m.id = t.member_id
            WHERE t.id = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['given_by'] = trim((string)($row['member_name'] ?? '')) ?: ((string)($row['giver_name'] ?? 'Anonymous'));
    return $row;
}

try {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        tg_json(false, 'Database connection not found.');
    }
    $conn->set_charset('utf8mb4');

    if (!tg_table_exists($conn, 'thanksgiving')) {
        tg_json(false, 'The thanksgiving table does not exist.');
    }

    $action = strtolower(trim((string)tg_input('action', 'list')));

    if ($action === 'list') {
        $from = trim((string)tg_input('from', date('Y-m-01')));
        $to = trim((string)tg_input('to', date('Y-m-d')));
        if ($from === '') $from = date('Y-m-01');
        if ($to === '') $to = date('Y-m-d');

        $sql = "SELECT t.*, m.full_name AS member_name, m.member_no
                FROM thanksgiving t
                LEFT JOIN members m ON m.id = t.member_id
                WHERE t.thanksgiving_date BETWEEN ? AND ?
                ORDER BY t.thanksgiving_date DESC, t.id DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $row['given_by'] = trim((string)($row['member_name'] ?? '')) ?: ((string)($row['giver_name'] ?? 'Anonymous'));
            $items[] = $row;
        }
        $stmt->close();

        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) total, COUNT(*) records FROM thanksgiving WHERE thanksgiving_date BETWEEN ? AND ?");
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $period = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $year = $conn->query("SELECT COALESCE(SUM(amount),0) total, COUNT(*) records FROM thanksgiving WHERE YEAR(thanksgiving_date)=YEAR(CURDATE())")->fetch_assoc();
        $month = $conn->query("SELECT COALESCE(SUM(amount),0) total, COUNT(*) records FROM thanksgiving WHERE YEAR(thanksgiving_date)=YEAR(CURDATE()) AND MONTH(thanksgiving_date)=MONTH(CURDATE())")->fetch_assoc();

        tg_json(true, '', [
            'items' => $items,
            'stats' => [
                'period_total' => (float)($period['total'] ?? 0),
                'period_records' => (int)($period['records'] ?? 0),
                'year_total' => (float)($year['total'] ?? 0),
                'month_total' => (float)($month['total'] ?? 0),
            ]
        ]);
    }

    if ($action === 'members') {
        if (!tg_table_exists($conn, 'members')) tg_json(true, '', ['items' => []]);
        $res = $conn->query("SELECT id, member_no, full_name, phone FROM members ORDER BY full_name");
        $items = [];
        while ($row = $res->fetch_assoc()) {
            $items[] = $row;
        }
        tg_json(true, '', ['items' => $items]);
    }

    if ($action === 'get') {
        $id = (int)tg_input('id', 0);
        if ($id <= 0) tg_json(false, 'Invalid thanksgiving record ID.');
        $item = tg_fetch_one($conn, $id);
        if (!$item) tg_json(false, 'Thanksgiving record not found.');
        tg_json(true, '', ['item' => $item]);
    }

    if ($action === 'create' || $action === 'update') {
        $id = (int)tg_input('id', 0);
        $memberId = (int)tg_input('member_id', 0);
        $memberSql = $memberId > 0 ? $memberId : null;
        $giver = trim((string)tg_input('giver_name', ''));
        $reason = trim((string)tg_input('reason', ''));
        $amount = (float)tg_input('amount', 0);
        $date = trim((string)tg_input('thanksgiving_date', date('Y-m-d')));
        $method = trim((string)tg_input('payment_method', 'Cash'));
        $itemDesc = trim((string)tg_input('item_description', ''));
        $notes = trim((string)tg_input('notes', ''));

        if ($memberId <= 0 && $giver === '') tg_json(false, 'Select a member or enter giver name.');
        if ($reason === '') tg_json(false, 'Reason for thanksgiving is required.');
        if ($amount < 0) tg_json(false, 'Amount cannot be negative.');
        if ($date === '') tg_json(false, 'Thanksgiving date is required.');

        if ($action === 'update') {
            if ($id <= 0) tg_json(false, 'Invalid thanksgiving record ID.');
            $stmt = $conn->prepare("UPDATE thanksgiving SET member_id=?, giver_name=?, reason=?, amount=?, thanksgiving_date=?, payment_method=?, item_description=?, notes=? WHERE id=?");
            $stmt->bind_param('issdssssi', $memberSql, $giver, $reason, $amount, $date, $method, $itemDesc, $notes, $id);
            $stmt->execute();
            $stmt->close();
            tg_json(true, 'Thanksgiving record updated successfully.');
        }

        $receipt = tg_gen_receipt($conn);
        $stmt = $conn->prepare("INSERT INTO thanksgiving (receipt_no, member_id, giver_name, reason, amount, thanksgiving_date, payment_method, item_description, notes) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sissdssss', $receipt, $memberSql, $giver, $reason, $amount, $date, $method, $itemDesc, $notes);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        tg_json(true, 'Thanksgiving recorded successfully. Receipt: ' . $receipt, ['id' => $newId, 'receipt_no' => $receipt]);
    }

    if ($action === 'delete') {
        $id = (int)tg_input('id', 0);
        if ($id <= 0) tg_json(false, 'Invalid thanksgiving record ID.');
        $stmt = $conn->prepare("DELETE FROM thanksgiving WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        tg_json(true, 'Thanksgiving record deleted successfully.');
    }

    tg_json(false, 'Invalid request action.');
} catch (Throwable $e) {
    error_log('[process-thanksgiving] ' . $e->getMessage());
    tg_json(false, 'Server error: ' . $e->getMessage());
}
