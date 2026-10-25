<?php
declare(strict_types=1);

/**
 * Member mobile app API. Call as api/member/?action=<name>.
 * Sign in with a membership number or pledge number and the member's portal password,
 * then send "Authorization: Bearer <token>" on every other request.
 * Members can only read their own records and published church content.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/member-content-functions.php';
require_once __DIR__ . '/_lib.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$action = (string)($_GET['action'] ?? '');

try {
    if (!mapi_table($conn, 'member_api_tokens') || !mapi_table($conn, 'member_api_login_attempts')) {
        mapi_fail(503, 'The member app is not set up yet. Please contact the church office.');
    }

    switch ($action) {
        case 'login':           mapi_action_login($conn); break;
        case 'logout':          mapi_action_logout($conn); break;
        case 'me':              mapi_action_me($conn); break;
        case 'change_password': mapi_action_change_password($conn); break;
        case 'dashboard':       mapi_action_dashboard($conn); break;
        case 'giving':          mapi_action_giving($conn); break;
        case 'pledges':         mapi_action_pledges($conn); break;
        case 'announcements':   mapi_action_announcements($conn); break;
        case 'programs':        mapi_action_programs($conn); break;
        case 'order_of_service': mapi_action_order_of_service($conn); break;
        case 'hymns':           mapi_action_hymns($conn); break;
        case 'hymn':            mapi_action_hymn($conn); break;
        case 'tips':            mapi_action_tips($conn); break;
        default:                mapi_fail(404, 'Unknown action.');
    }
} catch (Throwable $e) {
    error_log('[Member API] ' . $action . ': ' . $e->getMessage());
    mapi_fail(500, 'Something went wrong. Please try again.');
}

/* ------------------------------------------------------------------ account */

function mapi_action_login(mysqli $conn): never
{
    mapi_require_method('POST');
    $input = mapi_input();
    $identifier = mapi_str($input, 'identifier', 80);
    $password = (string)($input['password'] ?? '');
    $device = mapi_str($input, 'device_name', 120);
    if ($identifier === '' || $password === '') mapi_fail(422, 'Enter your membership or pledge number and your password.');

    $key = mb_strtolower($identifier);
    if (mapi_is_locked($conn, $key)) mapi_fail(429, 'Too many failed attempts. Please wait ' . MAPI_LOCK_MINUTES . ' minutes and try again.');

    $account = mapi_find_member_account($conn, $identifier);
    // Verify against a dummy hash when no account matches so response time does not reveal valid numbers.
    $hash = $account ? (string)$account['password'] : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$account) {
        mapi_record_failure($conn, $key);
        mapi_fail(401, 'The membership/pledge number or password is incorrect.');
    }
    mapi_clear_failures($conn, $key);

    $token = mapi_issue_token($conn, (int)$account['user_id'], (int)$account['member_id'], $device);
    mapi_ok([
        'token' => $token,
        'expires_in_days' => MAPI_TOKEN_DAYS,
        'member' => mapi_member_profile($conn, (int)$account['member_id']),
    ]);
}

function mapi_action_logout(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $auth['token_id']);
    $stmt->execute();
    $stmt->close();
    mapi_ok(['message' => 'Signed out.']);
}

function mapi_action_me(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $logo = mapi_setting($conn, 'site_logo', mapi_setting($conn, 'logo', ''));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo) && defined('BASE_URL')) $logo = rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    mapi_ok([
        'member' => mapi_member_profile($conn, $auth['member_id']),
        'church' => ['name' => mapi_setting($conn, 'site_name', 'Rongoro COU'), 'logo_url' => $logo],
    ]);
}

function mapi_action_change_password(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $input = mapi_input();
    $current = (string)($input['current_password'] ?? '');
    $new = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    $user = mapi_row($conn, 'SELECT password FROM users WHERE id=? LIMIT 1', 'i', [$auth['user_id']]);
    $errors = mapi_password_errors($current, $new, $confirm, (string)($user['password'] ?? ''));
    if ($errors) mapi_fail(422, implode(' ', $errors));

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $stmt->bind_param('si', $hash, $auth['user_id']);
    $stmt->execute();
    $stmt->close();
    // Sign out the member's other devices; this one stays signed in.
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE user_id=? AND id<>? AND revoked_at IS NULL');
    $stmt->bind_param('ii', $auth['user_id'], $auth['token_id']);
    $stmt->execute();
    $stmt->close();
    mapi_ok(['message' => 'Your password was changed. Use it for the app and the website.']);
}

/* ------------------------------------------------------------------ home */

function mapi_action_dashboard(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $memberId = $auth['member_id'];
    $pledges = ['total_pledged' => 0.0, 'total_paid' => 0.0, 'balance' => 0.0, 'open' => 0];
    if (mapi_table($conn, 'pledges')) {
        $row = mapi_row($conn, "SELECT COALESCE(SUM(amount_pledged),0) pledged, COALESCE(SUM(amount_paid),0) paid,
                SUM(status IN ('Pending','Partial')) open_count
            FROM pledges WHERE member_id=? AND status<>'Cancelled'", 'i', [$memberId]);
        $pledges = ['total_pledged' => (float)$row['pledged'], 'total_paid' => (float)$row['paid'],
            'balance' => max(0, (float)$row['pledged'] - (float)$row['paid']), 'open' => (int)$row['open_count']];
    }
    mapi_ok([
        'member' => mapi_member_profile($conn, $memberId),
        'verses' => mc_dashboard_verses($conn, 3),
        'pledges' => $pledges,
        'announcements' => array_slice(mapi_published_announcements($conn), 0, 3),
        'programs' => array_slice(mapi_upcoming_programs($conn), 0, 3),
    ]);
}

/* ------------------------------------------------------------------ giving */

function mapi_action_giving(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    // Each giving type mapped to: table, date column, amount column, reference and description columns.
    $types = [
        'offertory'     => ['offertory', 'service_date', 'total_amount', 'NULL', 'service_type'],
        'tithe'         => ['tithe', 'tithe_date', 'amount', 'receipt_no', "CONCAT_WS(' ', 'Tithe', period_month, period_year)"],
        'thanksgiving'  => ['thanksgiving', 'thanksgiving_date', 'amount', 'receipt_no', 'COALESCE(NULLIF(reason,\'\'), item_description)'],
        'contributions' => ['contributions', 'contributed_on', 'amount', 'receipt_no', 'notes'],
    ];
    $type = (string)($_GET['type'] ?? 'offertory');
    if (!isset($types[$type])) mapi_fail(422, 'Unknown giving type.');
    [$table, $date, $amount, $reference, $description] = $types[$type];
    if (!mapi_table($conn, $table)) mapi_ok(['type' => $type, 'total' => 0, 'items' => []]);

    $items = mapi_rows($conn, "SELECT id, `$date` AS date, `$amount` AS amount, $reference AS reference,
            $description AS description, payment_method
        FROM `$table` WHERE member_id=? ORDER BY `$date` DESC, id DESC LIMIT 500", 'i', [$auth['member_id']]);
    $total = 0.0;
    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['amount'] = (float)$item['amount'];
        $total += $item['amount'];
    }
    unset($item);
    mapi_ok(['type' => $type, 'total' => $total, 'items' => $items]);
}

function mapi_action_pledges(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    if (!mapi_table($conn, 'pledges')) mapi_ok(['items' => []]);
    $projectJoin = mapi_table($conn, 'projects') ? 'LEFT JOIN projects pr ON pr.id=p.project_id' : '';
    $projectName = $projectJoin ? 'pr.name' : 'NULL';
    $items = mapi_rows($conn, "SELECT p.id, p.pledge_no, COALESCE($projectName, NULLIF(p.pledge_reason,''), 'General Pledge') AS purpose,
            p.amount_pledged, p.amount_paid, p.pledge_date, p.due_date, p.status
        FROM pledges p $projectJoin WHERE p.member_id=? ORDER BY p.pledge_date DESC, p.id DESC", 'i', [$auth['member_id']]);

    $payments = [];
    if ($items && mapi_table($conn, 'pledge_payments')) {
        $ids = array_map(fn($p) => (int)$p['id'], $items);
        $rows = mapi_rows($conn, 'SELECT pledge_id, amount, paid_on, method, receipt_no FROM pledge_payments WHERE pledge_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY paid_on DESC, id DESC', str_repeat('i', count($ids)), $ids);
        foreach ($rows as $r) $payments[(int)$r['pledge_id']][] = ['amount' => (float)$r['amount'], 'paid_on' => $r['paid_on'], 'method' => $r['method'], 'receipt_no' => $r['receipt_no']];
    }
    foreach ($items as &$p) {
        $p['id'] = (int)$p['id'];
        $p['amount_pledged'] = (float)$p['amount_pledged'];
        $p['amount_paid'] = (float)$p['amount_paid'];
        $p['balance'] = max(0, $p['amount_pledged'] - $p['amount_paid']);
        $p['payments'] = $payments[$p['id']] ?? [];
    }
    unset($p);
    mapi_ok(['items' => $items]);
}

/* ------------------------------------------------------------------ church news */

function mapi_published_announcements(mysqli $conn): array
{
    if (!mapi_table($conn, 'church_announcements')) return [];
    return mapi_rows($conn, "SELECT id, title, message, priority, COALESCE(publish_from, created_at) AS published_at
        FROM church_announcements
        WHERE status='Published' AND (publish_from IS NULL OR publish_from<=NOW()) AND (publish_until IS NULL OR publish_until>=NOW())
        ORDER BY FIELD(priority,'Urgent','Important','Normal'), publish_from DESC, id DESC LIMIT 100");
}

function mapi_upcoming_programs(mysqli $conn): array
{
    if (!mapi_table($conn, 'church_programs')) return [];
    return mapi_rows($conn, "SELECT id, title, description, program_date, start_time, end_time, venue, program_type, meeting_link, status
        FROM church_programs
        WHERE program_date>=CURDATE() AND COALESCE(status,'Scheduled')<>'Cancelled'
        ORDER BY program_date, start_time, id LIMIT 100");
}

function mapi_action_announcements(mysqli $conn): never
{
    mapi_auth($conn);
    mapi_ok(['items' => mapi_published_announcements($conn)]);
}

function mapi_action_programs(mysqli $conn): never
{
    mapi_auth($conn);
    mapi_ok(['items' => mapi_upcoming_programs($conn)]);
}

/* ------------------------------------------------------------------ worship */

function mapi_action_order_of_service(mysqli $conn): never
{
    mapi_auth($conn);
    if (!mapi_table($conn, 'service_orders')) mapi_ok(['orders' => [], 'order' => null]);
    $orders = mapi_rows($conn, "SELECT id, title, service_type, service_date, service_time, notes FROM service_orders
        WHERE status='Published' ORDER BY service_date DESC, service_time DESC, id DESC");
    $selectedId = (int)($_GET['id'] ?? 0);
    $order = null;
    foreach ($orders as $o) if ((int)$o['id'] === $selectedId) $order = $o;
    if (!$order && $orders) {
        // Default to the next service (today or later); otherwise the most recent one.
        $upcoming = array_filter($orders, fn($o) => $o['service_date'] >= date('Y-m-d'));
        $order = $upcoming ? end($upcoming) : $orders[0];
    }
    if ($order) {
        $order['id'] = (int)$order['id'];
        $order['items'] = mapi_rows($conn, "SELECT i.item_title, i.led_by, i.details, h.id AS hymn_id, h.hymn_number, h.title AS hymn_title
            FROM service_order_items i LEFT JOIN church_hymns h ON h.id=i.hymn_id AND h.status='Published'
            WHERE i.service_order_id=? ORDER BY i.sort_order, i.id", 'i', [$order['id']]);
    }
    $list = array_map(fn($o) => ['id' => (int)$o['id'], 'title' => $o['title'], 'service_date' => $o['service_date']], $orders);
    mapi_ok(['orders' => $list, 'order' => $order]);
}

function mapi_action_hymns(mysqli $conn): never
{
    mapi_auth($conn);
    if (!mapi_table($conn, 'church_hymns')) mapi_ok(['items' => []]);
    $items = mapi_rows($conn, "SELECT id, hymn_number, title, hymn_book, language, author, theme FROM church_hymns
        WHERE status='Published' ORDER BY hymn_book, CAST(hymn_number AS UNSIGNED), hymn_number, title");
    foreach ($items as &$h) $h['id'] = (int)$h['id'];
    unset($h);
    mapi_ok(['items' => $items]);
}

function mapi_action_hymn(mysqli $conn): never
{
    mapi_auth($conn);
    $id = (int)($_GET['id'] ?? 0);
    $hymn = mapi_table($conn, 'church_hymns')
        ? mapi_row($conn, "SELECT id, hymn_number, title, hymn_book, language, author, tune, theme, scripture_reference, study_notes,
                lyrics, copyright_status, copyright_notice
            FROM church_hymns WHERE id=? AND status='Published' LIMIT 1", 'i', [$id])
        : null;
    if (!$hymn) mapi_fail(404, 'That hymn is not available.');
    $hymn['id'] = (int)$hymn['id'];
    // Lyrics are only shown when a right to reproduce them was recorded.
    if ($hymn['copyright_status'] === 'Not Entered' || trim((string)$hymn['lyrics']) === '') {
        $hymn['lyrics'] = null;
        $hymn['copyright_notice'] = null;
    }
    mapi_ok(['hymn' => $hymn]);
}

function mapi_action_tips(mysqli $conn): never
{
    mapi_auth($conn);
    $dept = (string)($_GET['dept'] ?? 'health');
    if (!mc_valid_department($dept)) mapi_fail(422, 'Unknown department.');
    $items = mapi_table($conn, 'department_tips')
        ? mapi_rows($conn, "SELECT t.id, t.title, t.content, t.published_at, COALESCE(NULLIF(u.full_name,''), u.username, 'Church Leader') AS author
            FROM department_tips t LEFT JOIN users u ON u.id=t.created_by
            WHERE t.department=? AND t.status='Published' ORDER BY t.published_at DESC, t.id DESC", 's', [$dept])
        : [];
    foreach ($items as &$t) $t['id'] = (int)$t['id'];
    unset($t);
    mapi_ok(['department' => mc_tip_departments()[$dept]['label'], 'items' => $items]);
}
