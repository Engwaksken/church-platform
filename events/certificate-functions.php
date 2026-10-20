<?php
declare(strict_types=1);

function event_certificate_ensure_tables(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS event_attendance (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        member_id INT NOT NULL,
        attended TINYINT(1) NOT NULL DEFAULT 0,
        marked_by INT NULL,
        marked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_attendee (event_id, member_id),
        KEY idx_event_attendance_member (member_id, attended)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS event_certificates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id INT NOT NULL,
        member_id INT NOT NULL,
        certificate_no VARCHAR(80) NOT NULL,
        certificate_name VARCHAR(180) NOT NULL,
        issued_by INT NULL,
        issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_member_certificate (event_id, member_id),
        UNIQUE KEY uq_event_certificate_no (certificate_no),
        KEY idx_event_cert_member (member_id, issued_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function event_certificate_tables_ready(mysqli $conn): bool
{
    $result = $conn->query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('event_attendance','event_certificates')");
    if (!$result) return false;
    $ready = (int)($result->fetch_row()[0] ?? 0) === 2;
    $result->close();
    return $ready;
}

function event_certificate_brand(mysqli $conn): array
{
    $brand = ['name' => defined('APP_NAME') ? APP_NAME : 'Church', 'subtitle' => '', 'logo' => ''];
    try {
        $result = $conn->query("SELECT setting_key,setting_value FROM site_settings WHERE setting_key IN ('site_name','site_subtitle','site_logo')");
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $value = trim((string)$row['setting_value']);
                if ($row['setting_key'] === 'site_name' && $value !== '') $brand['name'] = $value;
                elseif ($row['setting_key'] === 'site_subtitle') $brand['subtitle'] = $value;
                elseif ($row['setting_key'] === 'site_logo') $brand['logo'] = $value;
            }
            $result->close();
        }
    } catch (Throwable $e) { error_log('[event certificate branding] ' . $e->getMessage()); }
    return $brand;
}

function event_certificate_logo_src(string $path): string
{
    if ($path === '') return '';
    if (preg_match('~^https?://~i', $path)) return $path;
    $relative = ltrim($path, '/');
    $absolute = __DIR__ . '/../' . $relative;
    if (is_file($absolute)) {
        $data = @file_get_contents($absolute);
        if ($data !== false) return 'data:' . (function_exists('mime_content_type') ? (mime_content_type($absolute) ?: 'image/png') : 'image/png') . ';base64,' . base64_encode($data);
    }
    return rtrim(BASE_URL, '/') . '/' . $relative;
}

function event_certificate_is_admin(): bool
{
    return in_array(strtolower(trim((string)($_SESSION['role'] ?? ''))), ['admin', 'administrator', 'super admin', 'super_admin'], true);
}
