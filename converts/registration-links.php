<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once '../includes/church-book-functions.php';

requireLogin();
cb_require_permission('converts.manage');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./');
    exit;
}

cb_verify_csrf();
$action = cb_clean($_POST['action'] ?? '');

if ($action === 'create') {
    $label = cb_clean($_POST['label'] ?? '');
    $name = cb_clean($_POST['recipient_name'] ?? '');
    $phone = cb_clean($_POST['recipient_phone'] ?? '');
    $personType = cb_clean($_POST['person_type'] ?? '');
    $linkedMemberId = cb_nullable_int($_POST['linked_member_id'] ?? null);
    $linkedVisitorId = cb_nullable_int($_POST['linked_visitor_id'] ?? null);
    $assignedMemberId = cb_nullable_int($_POST['assigned_member_id'] ?? null);
    $weeks = max(1, min(104, cb_int($_POST['nurture_period_weeks'] ?? 12, 12)));
    $maxUses = max(1, min(500, cb_int($_POST['max_uses'] ?? 1, 1)));
    $expiresAt = cb_datetime_local_to_sql(cb_clean($_POST['expires_at'] ?? ''));

    if (!in_array($personType, ['', 'Member', 'Visitor'], true)) {
        $personType = '';
    }

    if ($linkedMemberId) {
        $personType = 'Member';
        $linkedVisitorId = null;
        $member = cb_fetch_one($conn, "SELECT id, CONCAT_WS(' ',first_name,last_name) full_name, phone FROM members WHERE id=? LIMIT 1", 'i', [$linkedMemberId]);
        if ($member) {
            if ($name === '') $name = trim((string)$member['full_name']);
            if ($phone === '') $phone = trim((string)($member['phone'] ?? ''));
        }
    } elseif ($linkedVisitorId) {
        $personType = 'Visitor';
        $linkedMemberId = null;
        $visitor = cb_fetch_one($conn, "SELECT id, full_name, phone FROM church_visitors WHERE id=? LIMIT 1", 'i', [$linkedVisitorId]);
        if ($visitor) {
            if ($name === '') $name = trim((string)$visitor['full_name']);
            if ($phone === '') $phone = trim((string)($visitor['phone'] ?? ''));
        }
    }

    $token = bin2hex(random_bytes(32));
    $uid = cb_current_user_id();
    $personTypeDb = $personType !== '' ? $personType : null;

    $stmt = $conn->prepare(
        "INSERT INTO convert_registration_links
            (token,label,recipient_name,recipient_phone,person_type,linked_member_id,linked_visitor_id,assigned_member_id,nurture_period_weeks,max_uses,expires_at,is_active,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?)"
    );

    if (!$stmt) {
        cb_flash('Could not prepare convert registration link.', 'error');
        header('Location: ./');
        exit;
    }

    $stmt->bind_param(
        'sssssiiiiisi',
        $token,
        $label,
        $name,
        $phone,
        $personTypeDb,
        $linkedMemberId,
        $linkedVisitorId,
        $assignedMemberId,
        $weeks,
        $maxUses,
        $expiresAt,
        $uid
    );

    $ok = $stmt->execute();
    $id = (int)$conn->insert_id;
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        cb_flash('Could not create convert registration link: ' . $error, 'error');
        header('Location: ./');
        exit;
    }

    $_SESSION['cb_share_link'] = [
        'kind' => 'convert',
        'id' => $id,
        'url' => cb_public_url('public_register/convert.php', ['token' => $token]),
        'title' => $label !== '' ? $label : ($name !== '' ? 'Convert Registration: ' . $name : 'Convert Registration'),
    ];

    cb_flash('Convert registration link and QR code generated.');
    header('Location: ./');
    exit;
}

if ($action === 'revoke') {
    $id = cb_int($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare('UPDATE convert_registration_links SET is_active=0 WHERE id=?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            cb_flash('Convert registration link revoked.');
        }
    }
    header('Location: ./');
    exit;
}

cb_flash('Invalid registration-link action.', 'error');
header('Location: ./');
exit;
