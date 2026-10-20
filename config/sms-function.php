<?php
declare(strict_types=1);

/**
 * Generic SMS helper.
 * Expects sms_settings table with: api_url, sender_id, api_key, username, api_password/password, is_active.
 */
function getSmsSettings(mysqli $conn): array
{
    $res = $conn->query("SELECT * FROM sms_settings WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
    if (!$res || $res->num_rows === 0) {
        throw new RuntimeException('SMS API settings not configured.');
    }
    return $res->fetch_assoc();
}

function sendSms(mysqli $conn, string $phone, string $message): bool
{
    try {
        $phone = trim($phone);
        if ($phone === '') return false;

        $settings = getSmsSettings($conn);
        $siteName = defined('APP_NAME') ? APP_NAME : 'Church';
        try {
            $brandResult = $conn->query("SELECT setting_value FROM site_settings WHERE setting_key='site_name' LIMIT 1");
            if ($brandResult && ($brandRow = $brandResult->fetch_assoc()) && trim((string)$brandRow['setting_value']) !== '') {
                $siteName = trim((string)$brandRow['setting_value']);
            }
        } catch (Throwable $e) { /* Keep the application default. */ }
        $siteName = trim(preg_replace('/[\r\n]+/', ' ', $siteName));
        if ($siteName !== '' && stripos($message, '[' . $siteName . ']') !== 0) {
            $message = '[' . $siteName . '] ' . $message;
        }
        $apiUrl = trim((string)($settings['api_url'] ?? ''));
        if ($apiUrl === '') return false;

        $payload = [
            'to' => $phone,
            'phone' => $phone,
            'recipient' => $phone,
            'message' => $message,
            'text' => $message,
            'sender_id' => $settings['sender_id'] ?? '',
            'sender' => $settings['sender_id'] ?? '',
            'api_key' => $settings['api_key'] ?? '',
            'apikey' => $settings['api_key'] ?? '',
            'username' => $settings['username'] ?? '',
            'password' => $settings['api_password'] ?? ($settings['password'] ?? '')
        ];

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log('SMS failed. HTTP '.$httpCode.' Error: '.$error.' Response: '.(string)$response);
            return false;
        }

        return true;
    } catch (Throwable $e) {
        error_log('SMS sending failed: '.$e->getMessage());
        return false;
    }
}
