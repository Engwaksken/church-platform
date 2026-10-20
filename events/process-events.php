<?php
/**
 * AJAX backend for Church Events.
 * Supports: list, stats, get, create, update, delete
 */

ob_start();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

require_once '../config/config.php';

if (function_exists('requireLogin')) {
    requireLogin();
}

while (ob_get_level() > 0) {
    $buf = ob_get_clean();
    if (trim($buf) !== '') {
        // Ignore accidental output from includes so AJAX still receives JSON.
    }
}

function ev_json($success, $message = '', $extra = []) {
    echo json_encode(array_merge(['success' => (bool)$success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function ev_post($key, $default = '') {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function ev_get($key, $default = '') {
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function ev_clean($value) {
    return trim((string)$value);
}

function ev_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

function ev_col_exists(mysqli $conn, $table, $col) {
    static $cache = [];
    $key = $table . '.' . $col;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    if (!$stmt) return $cache[$key] = false;
    $stmt->bind_param('ss', $table, $col);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

function ev_fetch_one(mysqli $conn, $id) {
    $stmt = $conn->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
    if (!$stmt) ev_json(false, 'Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function ev_scalar(mysqli $conn, $sql) {
    $res = $conn->query($sql);
    if (!$res) return 0;
    $row = $res->fetch_row();
    $res->close();
    return (float)($row[0] ?? 0);
}

set_exception_handler(function($e) {
    ev_json(false, 'Server error: ' . $e->getMessage());
});
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    ev_json(false, 'PHP error: ' . $message . ' on line ' . $line);
});

if (!isset($conn) || !($conn instanceof mysqli)) {
    ev_json(false, 'Database connection not found.');
}
$conn->set_charset('utf8mb4');

if (!ev_table_exists($conn, 'events')) {
    ev_json(false, 'Events table does not exist.');
}

$action = ev_post('action', ev_get('action', 'list'));
$today = date('Y-m-d');

if ($action === 'list') {
    $tab = ev_get('tab', 'upcoming');
    $search = ev_get('search', '');
    $type = ev_get('event_type', '');
    $where = [];
    $params = [];
    $types = '';

    if ($tab === 'past') {
        $where[] = 'event_date < ?';
        $params[] = $today;
        $types .= 's';
    } elseif ($tab === 'all') {
        // no date filter
    } else {
        $where[] = 'event_date >= ?';
        $params[] = $today;
        $types .= 's';
    }

    if ($type !== '') {
        $where[] = 'event_type = ?';
        $params[] = $type;
        $types .= 's';
    }

    if ($search !== '') {
        $where[] = '(title LIKE ? OR event_type LIKE ? OR venue LIKE ? OR organizer LIKE ? OR description LIKE ?)';
        $like = '%' . $search . '%';
        for ($i = 0; $i < 5; $i++) $params[] = $like;
        $types .= 'sssss';
    }

    $sql = 'SELECT * FROM events' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY event_date ' . ($tab === 'past' ? 'DESC' : 'ASC') . ', event_time ASC, id DESC';
    $stmt = $conn->prepare($sql);
    if (!$stmt) ev_json(false, 'Prepare failed: ' . $conn->error);
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $items = [];
    while ($row = $res->fetch_assoc()) {
        $row['budget'] = isset($row['budget']) ? (float)$row['budget'] : 0;
        $items[] = $row;
    }
    $stmt->close();

    $stats = [
        'total' => (int)ev_scalar($conn, "SELECT COUNT(*) FROM events"),
        'upcoming' => (int)ev_scalar($conn, "SELECT COUNT(*) FROM events WHERE event_date >= '" . $conn->real_escape_string($today) . "'"),
        'past' => (int)ev_scalar($conn, "SELECT COUNT(*) FROM events WHERE event_date < '" . $conn->real_escape_string($today) . "'"),
        'today' => (int)ev_scalar($conn, "SELECT COUNT(*) FROM events WHERE event_date = '" . $conn->real_escape_string($today) . "'"),
        'budget' => (float)ev_scalar($conn, "SELECT COALESCE(SUM(budget),0) FROM events"),
        'types' => (int)ev_scalar($conn, "SELECT COUNT(DISTINCT event_type) FROM events WHERE COALESCE(event_type,'') <> ''")
    ];

    ev_json(true, 'Loaded successfully.', ['items' => $items, 'stats' => $stats]);
}

if ($action === 'get') {
    $id = (int)ev_get('id', 0);
    if ($id <= 0) ev_json(false, 'Invalid event ID.');
    $row = ev_fetch_one($conn, $id);
    if (!$row) ev_json(false, 'Event not found.');
    ev_json(true, 'Loaded successfully.', ['item' => $row]);
}

if ($action === 'create' || $action === 'update') {
    $id = (int)ev_post('id', 0);
    $title = ev_clean(ev_post('title'));
    $eventType = ev_clean(ev_post('event_type'));
    $eventDate = ev_clean(ev_post('event_date'));
    $eventTime = ev_clean(ev_post('event_time'));
    $venue = ev_clean(ev_post('venue'));
    $organizer = ev_clean(ev_post('organizer'));
    $description = ev_clean(ev_post('description'));
    $budget = (float)ev_post('budget', 0);

    if ($title === '') ev_json(false, 'Event title is required.');
    if ($eventDate === '') ev_json(false, 'Event date is required.');
    if ($eventType === '') $eventType = 'Other';
    if ($eventTime === '') $eventTime = null;

    if ($action === 'create') {
        $stmt = $conn->prepare("INSERT INTO events (title, event_type, event_date, event_time, venue, organizer, description, budget) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) ev_json(false, 'Prepare failed: ' . $conn->error);
        $stmt->bind_param('sssssssd', $title, $eventType, $eventDate, $eventTime, $venue, $organizer, $description, $budget);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        ev_json(true, 'Event added successfully.', ['id' => $newId]);
    }

    if ($id <= 0) ev_json(false, 'Invalid event ID.');
    $stmt = $conn->prepare("UPDATE events SET title = ?, event_type = ?, event_date = ?, event_time = ?, venue = ?, organizer = ?, description = ?, budget = ? WHERE id = ?");
    if (!$stmt) ev_json(false, 'Prepare failed: ' . $conn->error);
    $stmt->bind_param('sssssssdi', $title, $eventType, $eventDate, $eventTime, $venue, $organizer, $description, $budget, $id);
    $stmt->execute();
    $stmt->close();
    ev_json(true, 'Event updated successfully.');
}

if ($action === 'delete') {
    $id = (int)ev_post('id', 0);
    if ($id <= 0) ev_json(false, 'Invalid event ID.');
    $stmt = $conn->prepare("DELETE FROM events WHERE id = ?");
    if (!$stmt) ev_json(false, 'Prepare failed: ' . $conn->error);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    ev_json(true, 'Event deleted successfully.');
}

ev_json(false, 'Invalid request action.');
