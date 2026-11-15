<?php
declare(strict_types=1);

/**
 * Password reset codes and staff sign-in codes for the mobile app API.
 * Tables: database/member_mobile_api.sql (member_api_password_resets) and
 * database/mobile_app_staff.sql (member_api_login_codes).
 */

/** Loads the website's email and SMS helpers once. */
function mapi_load_messaging(): void
{
    foreach (['/../../config/mail-function.php', '/../../config/sms-function.php'] as $helper) {
        if (is_file(__DIR__ . $helper)) require_once __DIR__ . $helper;
    }
}

/** "jo****@example.com" */
function mapi_mask_email(string $email): string
{
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    [$name, $domain] = $parts;
    $masked = strlen($name) <= 2 ? substr($name, 0, 1) . '*' : substr($name, 0, 2) . str_repeat('*', max(2, strlen($name) - 2));
    return $masked . '@' . $domain;
}

/* ------------------------------------------------------------------ password reset */

/** Phone and email to send a reset code to: the member's own details first, then the login's. */
function mapi_reset_contacts(mysqli $conn, int $memberId, int $userId): array
{
    $member = [];
    if ($memberId > 0) {
        $cols = [];
        foreach (['full_name', 'phone', 'email'] as $col) $cols[] = mapi_column($conn, 'members', $col) ? "`$col`" : "NULL AS `$col`";
        $member = mapi_row($conn, 'SELECT ' . implode(', ', $cols) . ' FROM members WHERE id=? LIMIT 1', 'i', [$memberId]) ?? [];
    }
    $userCols = ['full_name', 'email'];
    if (mapi_column($conn, 'users', 'phone')) $userCols[] = 'phone';
    $user = mapi_row($conn, 'SELECT `' . implode('`,`', $userCols) . '` FROM users WHERE id=? LIMIT 1', 'i', [$userId]) ?? [];
    $email = trim((string)($member['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $email = trim((string)($user['email'] ?? ''));
    $phone = trim((string)($member['phone'] ?? '')) ?: trim((string)($user['phone'] ?? ''));
    return [
        'name' => trim((string)($member['full_name'] ?? '')) ?: (trim((string)($user['full_name'] ?? '')) ?: 'Member'),
        'phone' => $phone,
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
    ];
}

/** Sends the reset code by SMS and email; true when at least one was sent. */
function mapi_send_reset_code(mysqli $conn, array $contacts, string $code): bool
{
    mapi_load_messaging();
    $site = mapi_setting($conn, 'site_name', defined('APP_NAME') ? APP_NAME : 'Church');
    $minutes = MAPI_RESET_CODE_MINUTES;
    $sent = false;

    if ($contacts['phone'] !== '' && function_exists('sendSms')) {
        $sent = sendSms($conn, $contacts['phone'], "Your church app password reset code is $code. It expires in $minutes minutes. Do not share it with anyone.") || $sent;
    }
    if ($contacts['email'] !== '' && function_exists('sendSystemEmail')) {
        $name = htmlspecialchars($contacts['name'], ENT_QUOTES, 'UTF-8');
        $html = "<p>Hello $name,</p><p>A password reset was requested for your " . htmlspecialchars($site, ENT_QUOTES, 'UTF-8') . " account.</p>"
            . "<p>Your reset code is:</p><p style=\"font-size:28px;font-weight:700;letter-spacing:6px;\">$code</p>"
            . "<p>The code expires in $minutes minutes. Do not share it with anyone.</p>"
            . "<p>If you did not ask to reset your password, you can ignore this email; your password has not changed.</p>";
        $text = "Hello {$contacts['name']},\n\nYour $site password reset code is $code. It expires in $minutes minutes. Do not share it with anyone.\n\n"
            . "If you did not ask to reset your password, you can ignore this email.";
        $sent = sendSystemEmail($conn, $contacts['email'], $contacts['name'], "$site password reset code", $html, $text) || $sent;
    }
    return $sent;
}

/* ------------------------------------------------------------------ staff sign-in codes */

/**
 * Staff without a member record confirm each sign-in with a 6-digit code emailed to them, as on the
 * website. Returns the challenge the app sends back with the code, and the masked email.
 */
function mapi_start_login_code(mysqli $conn, array $account, string $device): array
{
    if (!mapi_table($conn, 'member_api_login_codes')) mapi_fail(503, 'Staff sign-in is not set up yet. Please contact the church office.');
    $email = trim((string)($account['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        mapi_fail(422, 'This account has no valid email address for the sign-in code. Ask the administrator to add one.');
    }
    $userId = (int)$account['user_id'];
    $code = (string)random_int(100000, 999999);
    if (!mapi_send_login_code($conn, $account, $email, $code)) {
        mapi_fail(502, 'The sign-in code could not be emailed. Please try again later or contact the administrator.');
    }
    $challenge = bin2hex(random_bytes(32));
    $challengeHash = hash('sha256', $challenge);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $deviceValue = $device !== '' ? $device : null;
    $expires = date('Y-m-d H:i:s', time() + MAPI_LOGIN_CODE_MINUTES * 60);
    // Only the newest code works.
    $stmt = $conn->prepare('UPDATE member_api_login_codes SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare('INSERT INTO member_api_login_codes (user_id, challenge_hash, code_hash, device_name, created_at, last_sent_at, expires_at) VALUES (?,?,?,?,NOW(),NOW(),?)');
    $stmt->bind_param('issss', $userId, $challengeHash, $codeHash, $deviceValue, $expires);
    $stmt->execute();
    $stmt->close();
    $conn->query('DELETE FROM member_api_login_codes WHERE created_at < NOW() - INTERVAL 1 DAY');
    return ['challenge' => $challenge, 'masked_email' => mapi_mask_email($email)];
}

function mapi_send_login_code(mysqli $conn, array $account, string $email, string $code): bool
{
    mapi_load_messaging();
    if (!function_exists('sendSystemEmail')) return false;
    $site = mapi_setting($conn, 'site_name', defined('APP_NAME') ? APP_NAME : 'Church');
    $name = trim((string)($account['full_name'] ?? '')) ?: 'there';
    $minutes = MAPI_LOGIN_CODE_MINUTES;
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeSite = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;color:#222;line-height:1.6;">'
        . "<h2 style=\"margin-bottom:8px;\">App sign-in code</h2><p>Hello $safeName,</p>"
        . "<p>A sign-in to the $safeSite app was requested for your account.</p><p>Your code is:</p>"
        . "<div style=\"font-size:30px;font-weight:700;letter-spacing:8px;padding:16px 20px;background:#f4f4f4;border-radius:8px;text-align:center;\">$code</div>"
        . "<p>This code expires in <strong>$minutes minutes</strong>. Do not share it with anyone.</p>"
        . '<p>If you did not try to sign in, change your password.</p></div>';
    $text = "Hello $name,\n\nYour $site app sign-in code is: $code\nThe code expires in $minutes minutes. Do not share it with anyone.";
    try {
        return (bool)sendSystemEmail($conn, $email, $name, "$site app sign-in code", $html, $text);
    } catch (Throwable $e) {
        error_log('[Member API] login code email: ' . $e->getMessage());
        return false;
    }
}

/** The pending sign-in for a challenge, or null when it is unknown, used, expired or out of tries. */
function mapi_find_login_code(mysqli $conn, string $challenge): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $challenge) || !mapi_table($conn, 'member_api_login_codes')) return null;
    return mapi_row(
        $conn,
        "SELECT c.id, c.user_id, c.code_hash, c.device_name, c.attempts, c.last_sent_at, u.full_name, u.email, u.role
         FROM member_api_login_codes c JOIN users u ON u.id=c.user_id
         WHERE c.challenge_hash=? AND c.used_at IS NULL AND c.expires_at>NOW() AND c.attempts<?
           AND LOWER(TRIM(COALESCE(u.status,'Active')))='active'
         LIMIT 1",
        'si',
        [hash('sha256', $challenge), MAPI_LOGIN_CODE_MAX_TRIES]
    );
}
