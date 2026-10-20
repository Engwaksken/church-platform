<?php
require_once '../config/config.php';
require_once __DIR__ . '/checkin_token_helper.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, array $data = [], string $message = ''): never {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || ($_GET['action'] ?? '') !== 'generate') {
    respond(false, [], 'Invalid request.');
}

// CSRF check
$sessionCsrf = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
$givenCsrf = $_GET['csrf_token'] ?? '';
if ($sessionCsrf === '' || !hash_equals((string)$sessionCsrf, (string)$givenCsrf)) {
    respond(false, [], 'Invalid session token. Please refresh the page and try again.');
}

$date = trim((string)($_GET['service_date'] ?? ''));
$type = trim((string)($_GET['service_type'] ?? ''));
$hours = (int)($_GET['hours'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    respond(false, [], 'Please choose a valid service date.');
}

if ($type === '' || mb_strlen($type) > 100) {
    respond(false, [], 'Please choose a service type.');
}

$allowedHours = [0, 2, 6, 24, 168]; // 0 = no expiry
if (!in_array($hours, $allowedHours, true)) {
    $hours = 0;
}

$expTs = $hours > 0 ? (time() + ($hours * 3600)) : 0;
$token = checkin_generate_token($date, $type, $expTs);
$url = checkin_base_url() . '/public-checkin.php?t=' . $token;

respond(true, [
    'url' => $url,
    'service_date' => $date,
    'service_type' => $type,
    'expires_at' => $expTs > 0 ? date('D, d M Y H:i', $expTs) : null,
], 'Link generated.');