<?php
declare(strict_types=1);

/**
 * Church mobile app API. Call as api/member/?action=<name>.
 * Every active website user can sign in: members with their membership or pledge number (or username/email)
 * and portal password; staff without a member record with their username or email, password and a code
 * emailed to them. Then send "Authorization: Bearer <token>" on every other request.
 * Members only read their own records and published church content; staff tools follow the permissions
 * assigned on the website (Users > Permissions).
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/member-content-functions.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_staff.php';
require_once __DIR__ . '/../../includes/pledge-types.php';
require_once __DIR__ . '/../../includes/member-notify.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset('utf8mb4');
$action = (string)($_GET['action'] ?? '');

try {
    if (!mapi_table($conn, 'member_api_tokens') || !mapi_table($conn, 'member_api_login_attempts')) {
        mapi_fail(503, 'The member app is not set up yet. Please contact the church office.');
    }

    switch ($action) {
        case 'branding':        mapi_action_branding($conn); break;
        case 'login':           mapi_action_login($conn); break;
        case 'verify_login_code': mapi_action_verify_login_code($conn); break;
        case 'resend_login_code': mapi_action_resend_login_code($conn); break;
        case 'logout':          mapi_action_logout($conn); break;
        case 'me':              mapi_action_me($conn); break;
        case 'change_password': mapi_action_change_password($conn); break;
        case 'forgot_password': mapi_action_forgot_password($conn); break;
        case 'reset_password':  mapi_action_reset_password($conn); break;
        case 'notification_settings':        mapi_action_notification_settings($conn); break;
        case 'update_notification_settings': mapi_action_update_notification_settings($conn); break;
        case 'register_device': mapi_action_register_device($conn); break;
        case 'notifications':   mapi_action_notifications($conn); break;
        case 'mark_notifications_read': mapi_action_mark_notifications_read($conn); break;
        case 'zones':           mapi_action_zones($conn); break;
        case 'update_profile':  mapi_action_update_profile($conn); break;
        case 'update_photo':    mapi_action_update_photo($conn); break;
        case 'dashboard':       mapi_action_dashboard($conn); break;
        case 'giving':          mapi_action_giving($conn); break;
        case 'pledges':         mapi_action_pledges($conn); break;
        case 'role_tools':      mapi_action_role_tools($conn); break;
        case 'record_collection': mapi_action_record_collection($conn); break;
        case 'pledge_options':  mapi_action_pledge_options($conn); break;
        case 'staff_pledges':   mapi_action_staff_pledges($conn); break;
        case 'pledge_detail':   mapi_action_pledge_detail($conn); break;
        case 'save_pledge':     mapi_action_save_pledge($conn); break;
        case 'pledge_payments': mapi_action_pledge_payments($conn); break;
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

/** Public branding used before sign-in. */
function mapi_action_branding(mysqli $conn): never
{
    $logo = mapi_setting($conn, 'site_logo', mapi_setting($conn, 'logo', ''));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo) && defined('BASE_URL')) {
        $logo = rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    }
    mapi_ok(['church' => [
        'name' => mapi_setting($conn, 'site_name', defined('APP_NAME') ? APP_NAME : 'Church'),
        'subtitle' => mapi_setting($conn, 'site_subtitle', ''),
        'logo_url' => $logo,
    ]]);
}

/** Token and profile returned after a successful sign-in. */
function mapi_signed_in(mysqli $conn, int $userId, int $memberId, string $device): never
{
    $token = mapi_issue_token($conn, $userId, $memberId, $device);
    $mustChange = false;
    try {
        $flag = mapi_row($conn, 'SELECT must_change_password FROM users WHERE id=? LIMIT 1', 'i', [$userId]);
        $mustChange = (int)($flag['must_change_password'] ?? 0) === 1;
    } catch (Throwable $e) {
        // Older database without the column: nothing to change.
    }
    $auth = ['token_id' => 0, 'user_id' => $userId, 'member_id' => $memberId, 'role' => ''];
    mapi_ok([
        'token' => $token,
        'must_change_password' => $mustChange,
        'expires_in_days' => MAPI_TOKEN_DAYS,
        'member' => mapi_profile($conn, $auth),
        'account' => mapi_account($conn, $auth),
    ]);
}

function mapi_action_login(mysqli $conn): never
{
    mapi_require_method('POST');
    $input = mapi_input();
    $identifier = mapi_str($input, 'identifier', 190);
    $password = (string)($input['password'] ?? '');
    $device = mapi_str($input, 'device_name', 120);
    if ($identifier === '' || $password === '') mapi_fail(422, 'Enter your membership number, pledge number, username or email, and your password.');

    $key = mb_substr(mb_strtolower($identifier), 0, 80);
    if (mapi_is_locked($conn, $key)) mapi_fail(429, 'Too many failed attempts. Please wait ' . MAPI_LOCK_MINUTES . ' minutes and try again.');

    $account = mapi_find_account($conn, $identifier);
    // Verify against a dummy hash when no account matches so response time does not reveal valid numbers.
    $hash = $account ? (string)$account['password'] : password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    if (!password_verify($password, $hash) || !$account) {
        mapi_record_failure($conn, $key);
        mapi_fail(401, 'The sign-in details or password are incorrect.');
    }
    mapi_clear_failures($conn, $key);
    $memberId = (int)$account['member_id'];

    // The old guessable member password (Change@ + member number digits) no longer signs anyone in.
    if ($memberId > 0 && str_starts_with($password, 'Change@')) {
        $memberNo = (string)(mapi_row($conn, 'SELECT member_no FROM members WHERE id=? LIMIT 1', 'i', [$memberId])['member_no'] ?? '');
        $base = 'Change@' . preg_replace('/\D+/', '', $memberNo);
        if (in_array($password, [$base, $base . '@1234', $base . '1234'], true)) {
            mapi_fail(403, 'For your security, your starting password has been retired. Use "Forgot password" to choose a new one, or contact the church office.');
        }
    }

    // Members sign straight in; other accounts confirm with a code emailed to them, as on the website.
    if ($memberId > 0) mapi_signed_in($conn, (int)$account['user_id'], $memberId, $device);
    $pending = mapi_start_login_code($conn, $account, $device);
    mapi_ok([
        'requires_code' => true,
        'challenge' => $pending['challenge'],
        'masked_email' => $pending['masked_email'],
        'expires_in_minutes' => MAPI_LOGIN_CODE_MINUTES,
        'message' => 'We emailed a 6-digit sign-in code to ' . $pending['masked_email'] . '. It expires in ' . MAPI_LOGIN_CODE_MINUTES . ' minutes.',
    ]);
}

/** Second step of a staff sign-in: the emailed code. */
function mapi_action_verify_login_code(mysqli $conn): never
{
    mapi_require_method('POST');
    $input = mapi_input();
    $challenge = strtolower(mapi_str($input, 'challenge', 64));
    $code = preg_replace('/\D+/', '', mapi_str($input, 'code', 12)) ?? '';
    $pending = mapi_find_login_code($conn, $challenge);
    if (!$pending) mapi_fail(410, 'This sign-in has expired. Please sign in again.');
    if (strlen($code) !== 6) mapi_fail(422, 'Enter the 6-digit code from the email.');
    if (!password_verify($code, (string)$pending['code_hash'])) {
        $id = (int)$pending['id'];
        $stmt = $conn->prepare('UPDATE member_api_login_codes SET attempts=attempts+1 WHERE id=?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $left = MAPI_LOGIN_CODE_MAX_TRIES - (int)$pending['attempts'] - 1;
        if ($left <= 0) mapi_fail(410, 'Too many incorrect codes. Please sign in again.');
        mapi_fail(422, "Incorrect code. $left attempt(s) remaining.");
    }
    $id = (int)$pending['id'];
    $stmt = $conn->prepare('UPDATE member_api_login_codes SET used_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
    mapi_signed_in($conn, (int)$pending['user_id'], 0, (string)($pending['device_name'] ?? ''));
}

/** Emails a new code for a pending staff sign-in (at most once a minute). */
function mapi_action_resend_login_code(mysqli $conn): never
{
    mapi_require_method('POST');
    $challenge = strtolower(mapi_str(mapi_input(), 'challenge', 64));
    $pending = mapi_find_login_code($conn, $challenge);
    if (!$pending) mapi_fail(410, 'This sign-in has expired. Please sign in again.');
    $wait = 60 - (time() - strtotime((string)$pending['last_sent_at']));
    if ($wait > 0) mapi_fail(429, "Please wait $wait seconds before asking for another code.");
    $code = (string)random_int(100000, 999999);
    $account = ['user_id' => (int)$pending['user_id'], 'full_name' => (string)$pending['full_name'], 'email' => (string)$pending['email']];
    if (!mapi_send_login_code($conn, $account, (string)$pending['email'], $code)) mapi_fail(502, 'The sign-in code could not be emailed. Please try again later.');
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expires = date('Y-m-d H:i:s', time() + MAPI_LOGIN_CODE_MINUTES * 60);
    $id = (int)$pending['id'];
    $stmt = $conn->prepare('UPDATE member_api_login_codes SET code_hash=?, attempts=0, last_sent_at=NOW(), expires_at=? WHERE id=?');
    $stmt->bind_param('ssi', $hash, $expires, $id);
    $stmt->execute();
    $stmt->close();
    mapi_ok(['message' => 'A new code was sent to ' . mapi_mask_email((string)$pending['email']) . '.']);
}

function mapi_action_logout(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE id=?');
    $stmt->bind_param('i', $auth['token_id']);
    $stmt->execute();
    $stmt->close();
    // This phone stops getting push notifications.
    if (mapi_table($conn, 'member_push_devices')) mn_exec($conn, 'DELETE FROM member_push_devices WHERE api_token_id=?', 'i', [$auth['token_id']]);
    mapi_ok(['message' => 'Signed out.']);
}

function mapi_action_me(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    $logo = mapi_setting($conn, 'site_logo', mapi_setting($conn, 'logo', ''));
    if ($logo !== '' && !preg_match('#^https?://#i', $logo) && defined('BASE_URL')) $logo = rtrim(BASE_URL, '/') . '/' . ltrim($logo, '/');
    mapi_ok([
        'member' => mapi_profile($conn, $auth),
        'account' => mapi_account($conn, $auth),
        'church' => [
            'name' => mapi_setting($conn, 'site_name', defined('APP_NAME') ? APP_NAME : 'Church'),
            'subtitle' => mapi_setting($conn, 'site_subtitle', ''),
            'logo_url' => $logo,
        ],
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

/**
 * Sends a 6-digit reset code to the phone and email on file for a membership or pledge number, username or email.
 * The reply is the same whether or not it matched, so it cannot be used to find valid accounts.
 */
function mapi_action_forgot_password(mysqli $conn): never
{
    mapi_require_method('POST');
    if (!mapi_table($conn, 'member_api_password_resets')) mapi_fail(503, 'Password reset is not set up yet. Please contact the church office.');
    $identifier = mapi_str(mapi_input(), 'identifier', 190);
    if ($identifier === '') mapi_fail(422, 'Enter your membership number, pledge number, username or email.');

    $key = mb_substr(mb_strtolower($identifier), 0, 80);
    $ip = mapi_client_ip();
    $since = date('Y-m-d H:i:s', time() - 3600);
    $byIdentifier = mapi_row($conn, 'SELECT COUNT(*) c FROM member_api_password_resets WHERE identifier=? AND created_at>=?', 'ss', [$key, $since]);
    $byIp = mapi_row($conn, 'SELECT COUNT(*) c FROM member_api_password_resets WHERE ip_address=? AND created_at>=?', 'ss', [$ip, $since]);
    if ((int)$byIdentifier['c'] >= MAPI_RESET_MAX_REQUESTS || (int)$byIp['c'] >= MAPI_RESET_MAX_REQUESTS_PER_IP) {
        mapi_fail(429, 'Too many reset requests. Please wait an hour and try again, or contact the church office.');
    }

    $account = mapi_find_account($conn, $identifier);
    $userId = $account ? (int)$account['user_id'] : null;
    $code = (string)random_int(100000, 999999);
    $codeHash = $account ? password_hash($code, PASSWORD_DEFAULT) : null;
    $now = date('Y-m-d H:i:s');
    $expires = date('Y-m-d H:i:s', time() + MAPI_RESET_CODE_MINUTES * 60);
    if ($account) {
        // Only the newest code works.
        $stmt = $conn->prepare('UPDATE member_api_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare('INSERT INTO member_api_password_resets (identifier, ip_address, user_id, code_hash, created_at, expires_at) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('ssisss', $key, $ip, $userId, $codeHash, $now, $expires);
    $stmt->execute();
    $stmt->close();
    // Keep the table small: requests older than a day no longer matter.
    $conn->query('DELETE FROM member_api_password_resets WHERE created_at < NOW() - INTERVAL 1 DAY');

    if ($account) {
        $contacts = mapi_reset_contacts($conn, (int)$account['member_id'], (int)$userId);
        if (!mapi_send_reset_code($conn, $contacts, $code)) {
            error_log('[Member API] forgot_password: no reset code could be sent for user ' . $userId);
        }
    }
    mapi_ok([
        'message' => 'If these details belong to an active account with a phone number or email on file, a 6-digit code has been sent. '
            . 'It expires in ' . MAPI_RESET_CODE_MINUTES . ' minutes. If nothing arrives, contact the church office.',
        'expires_in_minutes' => MAPI_RESET_CODE_MINUTES,
    ]);
}

/** Sets a new password with a code from forgot_password, and signs the account out of the app everywhere. */
function mapi_action_reset_password(mysqli $conn): never
{
    mapi_require_method('POST');
    if (!mapi_table($conn, 'member_api_password_resets')) mapi_fail(503, 'Password reset is not set up yet. Please contact the church office.');
    $input = mapi_input();
    $identifier = mapi_str($input, 'identifier', 190);
    $code = mapi_str($input, 'code', 10);
    $new = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');
    if ($identifier === '' || $code === '') mapi_fail(422, 'Enter your sign-in details and the code you received.');

    $key = mb_substr(mb_strtolower($identifier), 0, 80);
    if (mapi_is_locked($conn, $key)) mapi_fail(429, 'Too many failed attempts. Please wait ' . MAPI_LOCK_MINUTES . ' minutes and try again.');

    $account = mapi_find_account($conn, $identifier);
    $reset = $account ? mapi_row(
        $conn,
        'SELECT id, code_hash FROM member_api_password_resets
         WHERE user_id=? AND used_at IS NULL AND expires_at>NOW() AND attempts<? AND code_hash IS NOT NULL
         ORDER BY id DESC LIMIT 1',
        'ii',
        [(int)$account['user_id'], MAPI_RESET_MAX_TRIES]
    ) : null;
    if (!$reset || !preg_match('/^\d{6}$/', $code) || !password_verify($code, (string)$reset['code_hash'])) {
        if ($reset) {
            $stmt = $conn->prepare('UPDATE member_api_password_resets SET attempts=attempts+1 WHERE id=?');
            $stmt->bind_param('i', $reset['id']);
            $stmt->execute();
            $stmt->close();
        }
        mapi_record_failure($conn, $key);
        mapi_fail(422, 'The code is incorrect or has expired. Check the code or request a new one.');
    }

    $userId = (int)$account['user_id'];
    $errors = mapi_new_password_errors($new, $confirm, (string)$account['password']);
    if ($errors) mapi_fail(422, implode(' ', $errors));

    $hash = password_hash($new, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password=? WHERE id=?');
    $stmt->bind_param('si', $hash, $userId);
    $stmt->execute();
    $stmt->close();
    try {
        $stmt = $conn->prepare('UPDATE users SET must_change_password=0 WHERE id=?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        // Older database without the column.
    }
    $stmt = $conn->prepare('UPDATE member_api_password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    // Anyone who knew the old password is signed out of the app.
    $stmt = $conn->prepare('UPDATE member_api_tokens SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    mapi_clear_failures($conn, $key);
    mapi_ok(['message' => 'Your password was reset. Use it for the app and the website.']);
}

/* ------------------------------------------------------------------ notifications */

function mapi_require_notifications(mysqli $conn): void
{
    if (!mn_ready($conn)) mapi_fail(503, 'Notifications are not set up yet. Please contact the church office.');
}

/** The member's choices per category, and which channels can reach them. */
function mapi_action_notification_settings(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    mapi_require_member($auth, 'Notification settings are for member accounts.');
    mapi_require_notifications($conn);
    $prefs = mn_prefs($conn, $auth['member_id']);
    $categories = [];
    foreach (mn_categories() as $key => $c) {
        $categories[] = ['key' => $key, 'label' => $c['label'], 'description' => $c['description']] + $prefs[$key];
    }
    $contact = mn_contact($conn, $auth['member_id']);
    mapi_ok([
        'categories' => $categories,
        'channels' => [
            'push' => ['available' => mn_push_configured(), 'note' => mn_push_configured() ? null : 'Push notifications are not switched on by the church yet.'],
            'email' => ['available' => $contact['email'] !== '', 'note' => $contact['email'] !== '' ? $contact['email'] : 'Add an email address to your profile to get emails.'],
            'sms' => ['available' => $contact['phone'] !== '' && mn_sms_configured($conn), 'note' => $contact['phone'] === '' ? 'Add a phone number to your profile to get SMS.' : (mn_sms_configured($conn) ? $contact['phone'] : 'SMS is not switched on by the church yet.')],
        ],
    ]);
}

function mapi_action_update_notification_settings(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    mapi_require_member($auth, 'Notification settings are for member accounts.');
    mapi_require_notifications($conn);
    $settings = mapi_input()['settings'] ?? null;
    if (!is_array($settings)) mapi_fail(422, 'No settings were sent.');
    mn_save_prefs($conn, $auth['member_id'], $settings);
    mapi_ok(['message' => 'Your notification settings were saved.']);
}

/** Saves this phone's push token so it can receive notifications while signed in (members only). */
function mapi_action_register_device(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    if ($auth['member_id'] <= 0) mapi_ok(['message' => 'Push notifications are sent to member accounts.']);
    mapi_require_notifications($conn);
    $input = mapi_input();
    $token = mapi_str($input, 'fcm_token', 255);
    $platform = mapi_str($input, 'platform', 20);
    if ($token === '' || !preg_match('/^[A-Za-z0-9_:\-]+$/', $token)) mapi_fail(422, 'Invalid push token.');
    mn_exec($conn, 'INSERT INTO member_push_devices (member_id, user_id, api_token_id, fcm_token, platform, last_seen_at) VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE member_id=VALUES(member_id), user_id=VALUES(user_id), api_token_id=VALUES(api_token_id), platform=VALUES(platform), last_seen_at=NOW()',
        'iiiss', [$auth['member_id'], $auth['user_id'], $auth['token_id'], $token, $platform]);
    mapi_ok(['message' => 'This phone will get push notifications.']);
}

/** The member's latest notifications, newest first (empty for staff accounts). */
function mapi_action_notifications(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    if ($auth['member_id'] <= 0) mapi_ok(['items' => [], 'unread' => 0]);
    mapi_require_notifications($conn);
    $items = mapi_rows($conn, 'SELECT id, category, title, body, created_at, read_at FROM member_notifications
        WHERE member_id=? ORDER BY id DESC LIMIT 100', 'i', [$auth['member_id']]);
    $unread = 0;
    foreach ($items as &$n) {
        $n['id'] = (int)$n['id'];
        if ($n['read_at'] === null) $unread++;
    }
    unset($n);
    mapi_ok(['items' => $items, 'unread' => $unread]);
}

function mapi_action_mark_notifications_read(mysqli $conn): never
{
    mapi_require_method('POST');
    $auth = mapi_auth($conn);
    if ($auth['member_id'] <= 0) mapi_ok(['message' => 'Marked as read.']);
    mapi_require_notifications($conn);
    mn_exec($conn, 'UPDATE member_notifications SET read_at=NOW() WHERE member_id=? AND read_at IS NULL', 'i', [$auth['member_id']]);
    mapi_ok(['message' => 'Marked as read.']);
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
    mapi_require_member($auth, 'Staff accounts change their details on the church website.');
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
    mapi_require_member($auth, 'Profile photos are for member accounts.');
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
    if ($memberId > 0 && mapi_table($conn, 'pledges')) {
        $row = mapi_row($conn, "SELECT COALESCE(SUM(amount_pledged),0) pledged, COALESCE(SUM(amount_paid),0) paid,
                SUM(status IN ('Pending','Partial')) open_count
            FROM pledges WHERE member_id=? AND status<>'Cancelled'", 'i', [$memberId]);
        $pledges = ['total_pledged' => (float)$row['pledged'], 'total_paid' => (float)$row['paid'],
            'balance' => max(0, (float)$row['pledged'] - (float)$row['paid']), 'open' => (int)$row['open_count']];
    }
    mapi_ok([
        'member' => mapi_profile($conn, $auth),
        'account' => mapi_account($conn, $auth),
        'verses' => mc_dashboard_verses($conn, 3),
        'pledges' => $pledges,
        'work' => mapi_work_summary($conn, $auth),
        'announcements' => array_slice(mapi_published_announcements($conn), 0, 3),
        'programs' => array_slice(mapi_upcoming_programs($conn), 0, 3),
    ]);
}

/* ------------------------------------------------------------------ giving */

function mapi_action_giving(mysqli $conn): never
{
    $auth = mapi_auth($conn);
    mapi_require_member($auth, 'Giving history is for member accounts.');
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
    mapi_require_member($auth, 'My pledges is for member accounts.');
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
