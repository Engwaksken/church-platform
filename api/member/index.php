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
require_once __DIR__ . '/../../includes/pledge-types.php';

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
        case 'zones':           mapi_action_zones($conn); break;
        case 'update_profile':  mapi_action_update_profile($conn); break;
        case 'update_photo':    mapi_action_update_photo($conn); break;
        case 'dashboard':       mapi_action_dashboard($conn); break;
        case 'giving':          mapi_action_giving($conn); break;
        case 'pledges':         mapi_action_pledges($conn); break;
        case 'role_tools':       mapi_action_role_tools($conn); break;
        case 'record_collection': mapi_action_record_collection($conn); break;
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

    // The old guessable member password (Change@ + member number digits) no longer signs anyone in.
    if (str_starts_with($password, 'Change@')) {
        $memberNo = (string)(mapi_row($conn, 'SELECT member_no FROM members WHERE id=? LIMIT 1', 'i', [(int)$account['member_id']])['member_no'] ?? '');
        $base = 'Change@' . preg_replace('/\D+/', '', $memberNo);
        if (in_array($password, [$base, $base . '@1234', $base . '1234'], true)) {
            mapi_fail(403, 'For your security, your starting password has been retired. Use "Forgot password" on the church website to choose a new one, or contact the church office.');
        }
    }

    $token = mapi_issue_token($conn, (int)$account['user_id'], (int)$account['member_id'], $device);
    $mustChange = false;
    try {
        $flag = mapi_row($conn, 'SELECT must_change_password FROM users WHERE id=? LIMIT 1', 'i', [(int)$account['user_id']]);
        $mustChange = (int)($flag['must_change_password'] ?? 0) === 1;
    } catch (Throwable $e) {
        // Older database without the column: nothing to change.
    }
    mapi_ok([
        'token' => $token,
        'must_change_password' => $mustChange,
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
    try {
        $stmt = $conn->prepare('UPDATE users SET must_change_password=0 WHERE id=?');
        $stmt->bind_param('i', $auth['user_id']);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Older database without the column.
    }
    // Sign out the member's other devices; this one stays signed in.
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE user_id=? AND id<>? AND revoked_at IS NULL');
    $stmt->bind_param('ii', $auth['user_id'], $auth['token_id']);
    $stmt->execute();
    $stmt->close();
    mapi_ok(['message' => 'Your password was changed. Use it for the app and the website.']);
}

/** Church zones a member can choose on their profile. */
function mapi_action_zones(mysqli $conn): never
{
    mapi_auth($conn);
    $source = mapi_zone_source($conn);
    if (!$source) mapi_ok(['items' => []]);
    [$table, $nameCol] = $source;
    $items = mapi_rows($conn, "SELECT id, `$nameCol` AS name FROM `$table` ORDER BY `$nameCol`");
    foreach ($items as &$item) $item['id'] = (int)$item['id'];
    unset($item);
    mapi_ok(['items' => $items]);
}

/** Contact details and church zone, with the same rules as the web portal (member/profile.php). */
function mapi_action_update_profile(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $input = mapi_input();
    $email = mb_strtolower(mapi_str($input, 'email', 120));
    $phone = mapi_str($input, 'phone', 25);
    $service = mapi_str($input, 'service_attended', 120);
    $zoneId = (int)($input['church_zone_id'] ?? 0);
    $zoneId = $zoneId > 0 ? $zoneId : null;

    $errors = [];
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($phone === '') $errors[] = 'Phone number is required.';
    elseif (!preg_match('/^[0-9+\-\s()]{7,25}$/', $phone)) $errors[] = 'Enter a valid phone number.';
    $source = mapi_zone_source($conn);
    if ($zoneId !== null && $source) {
        [$table] = $source;
        if (!mapi_row($conn, "SELECT id FROM `$table` WHERE id=? LIMIT 1", 'i', [$zoneId])) $errors[] = 'The selected church zone is invalid.';
    }
    if ($email !== '' && mapi_row($conn, 'SELECT id FROM users WHERE email=? AND id<>? LIMIT 1', 'si', [$email, $auth['user_id']])) {
        $errors[] = 'That email address is already being used by another account.';
    }
    if ($errors) mapi_fail(422, implode(' ', $errors));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE users SET email=?, phone=? WHERE id=?');
        $stmt->bind_param('ssi', $email, $phone, $auth['user_id']);
        $stmt->execute();
        $stmt->close();

        $sets = [];
        $types = '';
        $values = [];
        foreach (['email' => ['s', $email], 'phone' => ['s', $phone], 'church_zone_id' => ['i', $zoneId], 'service_attended' => ['s', $service !== '' ? $service : null]] as $col => [$type, $value]) {
            if (!mapi_column($conn, 'members', $col)) continue;
            $sets[] = "`$col`=?";
            $types .= $type;
            $values[] = $value;
        }
        if ($sets) {
            $types .= 'i';
            $values[] = $auth['member_id'];
            $stmt = $conn->prepare('UPDATE members SET ' . implode(', ', $sets) . ' WHERE id=?');
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    mapi_ok(['message' => 'Your profile was updated.', 'member' => mapi_member_profile($conn, $auth['member_id'])]);
}

/** Profile photo upload (multipart field "photo"), with the same rules as the web portal. */
function mapi_action_update_photo(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    if (!mapi_column($conn, 'members', 'photo')) mapi_fail(422, 'Profile photos are not available.');
    $photo = $_FILES['photo'] ?? null;
    if (!is_array($photo) || ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $tooLarge = in_array($photo['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        mapi_fail(422, $tooLarge ? 'The selected photo is too large.' : 'Select a profile photo to upload.');
    }
    if ((int)($photo['size'] ?? 0) > 5 * 1024 * 1024) mapi_fail(422, 'The profile photo must not exceed 5 MB.');
    $tmp = (string)($photo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) mapi_fail(422, 'The uploaded photo is invalid.');

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset($allowed[$mime])) mapi_fail(422, 'Upload a JPG, PNG, or WEBP profile photo.');
    if (@getimagesize($tmp) === false) mapi_fail(422, 'The selected file is not a valid image.');

    $dir = __DIR__ . '/../../assets/uploads/members';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Cannot create ' . $dir);
    $file = sprintf('member_%d_%s.%s', $auth['member_id'], bin2hex(random_bytes(12)), $allowed[$mime]);
    $relative = 'assets/uploads/members/' . $file;
    if (!move_uploaded_file($tmp, $dir . '/' . $file)) throw new RuntimeException('Cannot save the uploaded photo.');

    $old = trim((string)(mapi_row($conn, 'SELECT photo FROM members WHERE id=?', 'i', [$auth['member_id']])['photo'] ?? ''));
    $stmt = $conn->prepare('UPDATE members SET photo=? WHERE id=?');
    $stmt->bind_param('si', $relative, $auth['member_id']);
    $stmt->execute();
    $stmt->close();

    // Remove the previous member-uploaded photo, never anything outside the members upload folder.
    if ($old !== '' && str_starts_with($old, 'assets/uploads/members/')) {
        $oldPath = realpath(__DIR__ . '/../../' . $old);
        $root = realpath($dir);
        if ($oldPath !== false && $root !== false && str_starts_with($oldPath, $root) && is_file($oldPath)) @unlink($oldPath);
    }
    mapi_ok(['message' => 'Your profile photo was updated.', 'member' => mapi_member_profile($conn, $auth['member_id'])]);
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
        'tithe'         => ['tithe', 'tithe_date', 'amount', 'receipt_no', "CONCAT_WS(' ', 'Tithe', period_month, period_year)"],
        'thanksgiving'  => ['thanksgiving', 'thanksgiving_date', 'amount', 'receipt_no', 'COALESCE(NULLIF(reason,\'\'), item_description)'],
        'contributions' => ['contributions', 'contributed_on', 'amount', 'receipt_no', 'notes'],
    ];
    $type = (string)($_GET['type'] ?? 'tithe');
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
    [$typeSelect, $typeJoin] = pledge_purpose_sql($conn);
    $items = mapi_rows($conn, "SELECT p.id, p.pledge_no, p.pledge_reason, $projectName AS project_name $typeSelect,
            p.amount_pledged, p.amount_paid, p.pledge_date, p.due_date, p.status
        FROM pledges p $projectJoin $typeJoin WHERE p.member_id=? ORDER BY p.pledge_date DESC, p.id DESC", 'i', [$auth['member_id']]);

    $payments = [];
    if ($items && mapi_table($conn, 'pledge_payments')) {
        $ids = array_map(fn($p) => (int)$p['id'], $items);
        $rows = mapi_rows($conn, 'SELECT pledge_id, amount, paid_on, method, receipt_no FROM pledge_payments WHERE pledge_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY paid_on DESC, id DESC', str_repeat('i', count($ids)), $ids);
        foreach ($rows as $r) $payments[(int)$r['pledge_id']][] = ['amount' => (float)$r['amount'], 'paid_on' => $r['paid_on'], 'method' => $r['method'], 'receipt_no' => $r['receipt_no']];
    }
    foreach ($items as &$p) {
        $p['purpose'] = pledge_purpose($p);
        $p['id'] = (int)$p['id'];
        $p['amount_pledged'] = (float)$p['amount_pledged'];
        $p['amount_paid'] = (float)$p['amount_paid'];
        $p['balance'] = max(0, $p['amount_pledged'] - $p['amount_paid']);
        $p['payments'] = $payments[$p['id']] ?? [];
    }
    unset($p);
    mapi_ok(['items' => $items]);
}

/** Tools only appear for permissions explicitly granted to this account or its role. */
function mapi_role_tool_permissions(): array
{
    return [
        'offertory' => 'offertory.view',
        'tithe' => 'tithe.view',
        'thanksgiving' => 'thanksgiving.view',
        'pledge_payment' => 'pledges.view',
        'contribution' => 'contributions.view',
        'good_samaritan' => 'good_samaritan.view',
    ];
}

function mapi_user_can_tool(mysqli $conn, int $userId, string $permission): bool
{
    $role = strtolower(trim((string)(mapi_row($conn, 'SELECT role FROM users WHERE id=? LIMIT 1', 'i', [$userId])['role'] ?? '')));
    if ($role === 'admin') return true;
    if (!mapi_table($conn, 'permissions')) return false;
    $hasRoles = mapi_table($conn, 'role_permissions');
    $hasUsers = mapi_table($conn, 'user_permissions');
    if (!$hasRoles && !$hasUsers) return false;
    $joins = ($hasRoles ? ' LEFT JOIN role_permissions rp ON rp.permission_id=p.id AND LOWER(rp.role)=?' : '')
        . ($hasUsers ? ' LEFT JOIN user_permissions up ON up.permission_id=p.id AND up.user_id=?' : '');
    $allowed = $hasRoles && $hasUsers ? 'COALESCE(up.allowed,rp.allowed,0)' : ($hasUsers ? 'COALESCE(up.allowed,0)' : 'COALESCE(rp.allowed,0)');
    $types = ($hasRoles ? 's' : '') . ($hasUsers ? 'i' : '') . 's';
    $params = array_merge($hasRoles ? [$role] : [], $hasUsers ? [$userId] : [], [$permission]);
    return (bool)mapi_row($conn, "SELECT 1 AS allowed FROM permissions p $joins WHERE p.permission_key=? AND $allowed=1 LIMIT 1", $types, $params);
}

function mapi_action_role_tools(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $tools = [];
    foreach (mapi_role_tool_permissions() as $tool => $permission) {
        $tools[$tool] = mapi_user_can_tool($conn, $auth['user_id'], $permission);
    }
    $enabled = array_keys(array_filter($tools));
    $members = [];
    $projects = [];
    $pledges = [];
    if (array_intersect($enabled, ['tithe', 'thanksgiving', 'contribution', 'good_samaritan'])) {
        $members = mapi_rows($conn, "SELECT id, member_no, full_name FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY full_name LIMIT 2000");
        foreach ($members as &$member) $member['id'] = (int)$member['id'];
        unset($member);
    }
    if (in_array('contribution', $enabled, true) && mapi_table($conn, 'projects')) {
        $projects = mapi_rows($conn, "SELECT id,name FROM projects WHERE COALESCE(status,'Active')='Active' ORDER BY name");
        foreach ($projects as &$project) $project['id'] = (int)$project['id'];
        unset($project);
    }
    if (in_array('pledge_payment', $enabled, true)) {
        $pledges = mapi_rows($conn, "SELECT id,pledge_no,pledge_reason,amount_pledged,amount_paid FROM pledges WHERE status IN ('Pending','Partial') ORDER BY pledge_date DESC,id DESC LIMIT 1000");
        foreach ($pledges as &$pledge) { $pledge['id'] = (int)$pledge['id']; $pledge['amount_pledged'] = (float)$pledge['amount_pledged']; $pledge['amount_paid'] = (float)$pledge['amount_paid']; }
        unset($pledge);
    }
    mapi_ok(['items' => $enabled, 'tools' => $tools, 'members' => $members, 'projects' => $projects, 'pledges' => $pledges]);
}

function mapi_action_record_collection(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $input = mapi_input();
    $type = mapi_str($input, 'type', 40);
    $permission = mapi_role_tool_permissions()[$type] ?? '';
    if ($permission === '' || !mapi_user_can_tool($conn, $auth['user_id'], $permission)) mapi_fail(403, 'Your account is not assigned to record this collection.');
    $amount = (float)($input['amount'] ?? 0);
    $date = mapi_str($input, 'date', 10) ?: date('Y-m-d');
    $method = mapi_str($input, 'method', 40) ?: 'Cash';
    $notes = mapi_str($input, 'notes', 2000);
    if ($amount <= 0 || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $dateParts)
        || !checkdate((int)$dateParts[2], (int)$dateParts[3], (int)$dateParts[1])) mapi_fail(422, 'Enter a valid amount and date.');
    if (!in_array($method, ['Cash', 'Mobile Money', 'Bank Transfer', 'Cheque', 'Other'], true)) mapi_fail(422, 'Select a valid payment method.');
    $receipt = 'RCOU-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));

    if ($type === 'offertory') {
        $envelope = max(0, (float)($input['envelope_total'] ?? 0));
        $loose = max(0, (float)($input['loose_total'] ?? 0));
        $extra = max(0, (float)($input['extra'] ?? 0));
        if ($envelope + $loose + $extra <= 0) mapi_fail(422, 'Enter an envelope, loose or additional amount.');
        $service = mapi_str($input, 'service_type', 80) ?: 'Sunday Service';
        $counted = mapi_str($input, 'counted_by', 120) ?: (string)(mapi_member_profile($conn, $auth['member_id'])['full_name'] ?? '');
        $witnessed = mapi_str($input, 'witnessed_by', 120);
        $total = $envelope + $loose + $extra;
        $st = $conn->prepare('INSERT INTO offertory (service_date,service_type,envelope_total,loose_total,total_amount,counted_by,witnessed_by,notes) VALUES (?,?,?,?,?,?,?,?)');
        $st->bind_param('ssdddsss', $date, $service, $envelope, $loose, $total, $counted, $witnessed, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'tithe') {
        $memberId = (int)($input['member_id'] ?? 0); $month = (int)($input['period_month'] ?? (int)date('n')); $year = (int)($input['period_year'] ?? (int)date('Y'));
        if ($memberId > 0 && !mapi_row($conn, "SELECT id FROM members WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$memberId])) mapi_fail(422, 'Select an active member.');
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) mapi_fail(422, 'Select a valid tithe period.');
        $st = $conn->prepare('INSERT INTO tithe (receipt_no,member_id,amount,period_month,period_year,tithe_date,payment_method,notes) VALUES (?,NULLIF(?,0),?,?,?,?,?,?)');
        $st->bind_param('sidiisss', $receipt, $memberId, $amount, $month, $year, $date, $method, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'thanksgiving') {
        $memberId = (int)($input['member_id'] ?? 0); $giver = mapi_str($input, 'giver_name', 120); $reason = mapi_str($input, 'reason', 250); $item = mapi_str($input, 'item_description', 250);
        if ($memberId > 0 && !mapi_row($conn, "SELECT id FROM members WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$memberId])) mapi_fail(422, 'Select an active member.');
        if ($memberId <= 0 && $giver === '') $giver = (string)(mapi_member_profile($conn, $auth['member_id'])['full_name'] ?? '');
        if ($reason === '') mapi_fail(422, 'Enter the thanksgiving reason.');
        $st = $conn->prepare('INSERT INTO thanksgiving (receipt_no,member_id,giver_name,reason,amount,thanksgiving_date,payment_method,item_description,notes) VALUES (?,NULLIF(?,0),?,?,?,?,?,?,?)');
        $st->bind_param('sissdssss', $receipt, $memberId, $giver, $reason, $amount, $date, $method, $item, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'good_samaritan') {
        $memberId = (int)($input['member_id'] ?? 0);
        if ($memberId > 0 && !mapi_row($conn, "SELECT id FROM members WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$memberId])) mapi_fail(422, 'Select an active member.');
        $st = $conn->prepare('INSERT INTO good_samaritan (receipt_no,member_id,amount,payment_method,given_on,notes,recorded_by) VALUES (?,NULLIF(?,0),?,?,?,?,?)');
        $st->bind_param('sidsssi', $receipt, $memberId, $amount, $method, $date, $notes, $auth['user_id']); $st->execute(); $id = $st->insert_id; $st->close();
    } elseif ($type === 'contribution') {
        $memberId = (int)($input['member_id'] ?? 0); $projectId = (int)($input['project_id'] ?? 0);
        if ($memberId <= 0 || $projectId <= 0) mapi_fail(422, 'Select a member and contribution project.');
        if (!mapi_row($conn, "SELECT id FROM members WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$memberId])) mapi_fail(422, 'Select an active member.');
        if (!mapi_row($conn, "SELECT id FROM projects WHERE id=? AND COALESCE(status,'Active')='Active'", 'i', [$projectId])) mapi_fail(422, 'Select an active project.');
        $st = $conn->prepare('INSERT INTO contributions (receipt_no,member_id,project_id,amount,payment_method,contributed_on,notes) VALUES (?,?,?,?,?,?,?)');
        $st->bind_param('siidsss', $receipt, $memberId, $projectId, $amount, $method, $date, $notes); $st->execute(); $id = $st->insert_id; $st->close();
    } else {
        $pledgeId = (int)($input['pledge_id'] ?? 0);
        $pledge = mapi_row($conn, "SELECT id,amount_pledged,amount_paid,status FROM pledges WHERE id=? AND status<>'Cancelled' LIMIT 1", 'i', [$pledgeId]);
        if (!$pledge) mapi_fail(422, 'Select an active pledge.');
        $balance = max(0, (float)$pledge['amount_pledged'] - (float)$pledge['amount_paid']);
        if ($amount > $balance) mapi_fail(422, 'Payment exceeds the remaining pledge balance of ' . number_format($balance) . '.');
        $receipt = 'PLG-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $st = $conn->prepare('INSERT INTO pledge_payments (pledge_id,amount,paid_on,method,receipt_no,notes) VALUES (?,?,?,?,?,?)');
        $st->bind_param('idssss', $pledgeId, $amount, $date, $method, $receipt, $notes); $st->execute(); $id = $st->insert_id; $st->close();
        $paid = (float)$pledge['amount_paid'] + $amount; $status = $paid >= (float)$pledge['amount_pledged'] ? 'Fulfilled' : 'Partial';
        $st = $conn->prepare('UPDATE pledges SET amount_paid=?,status=? WHERE id=?'); $st->bind_param('dsi', $paid, $status, $pledgeId); $st->execute(); $st->close();
    }
    mapi_ok(['message' => 'Collection recorded.', 'id' => (int)$id, 'receipt_no' => $receipt]);
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
