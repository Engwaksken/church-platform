<?php
declare(strict_types=1);

/**
 * Church name and logo for PDF receipts and cards (Dompdf).
 *
 * The logo is the one set in Settings (site_settings.site_logo, or the older `logo` key), falling back to
 * assets/img/logo.png. It is embedded as a data URI, so Dompdf never has to read a file path or fetch a URL.
 *
 * Usage inside a receipt's HTML:
 *   <style> ... receipt_brand_css() ... </style>
 *   <body> receipt_watermark_html($conn) ... receipt_logo_html($conn) ...
 */

function receipt_brand(mysqli $conn): array
{
    static $brand = null;
    if ($brand !== null) return $brand;

    $settings = [];
    try {
        $res = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('site_name','site_subtitle','site_logo','logo')");
        while ($res && $row = $res->fetch_assoc()) $settings[$row['setting_key']] = trim((string)$row['setting_value']);
        if ($res) $res->close();
    } catch (Throwable $e) {
        // No settings table: fall back to the defaults below.
    }

    $root = dirname(__DIR__);
    $candidates = [];
    foreach ([$settings['site_logo'] ?? '', $settings['logo'] ?? ''] as $logo) {
        if ($logo === '') continue;
        // A full URL to this site maps back to the local file.
        if (preg_match('~^https?://~i', $logo)) {
            if (!defined('BASE_URL') || stripos($logo, rtrim(BASE_URL, '/') . '/') !== 0) continue;
            $logo = substr($logo, strlen(rtrim(BASE_URL, '/') . '/'));
        }
        $candidates[] = $root . '/' . ltrim(parse_url($logo, PHP_URL_PATH) ?: $logo, '/');
    }
    $candidates[] = $root . '/assets/img/logo.png';

    $logoUri = '';
    foreach ($candidates as $file) {
        $real = realpath($file);
        // Only files inside the site folder.
        if ($real === false || !is_file($real) || !str_starts_with($real, realpath($root) . DIRECTORY_SEPARATOR)) continue;
        $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
        $mime = $types[strtolower(pathinfo($real, PATHINFO_EXTENSION))] ?? null;
        $data = $mime ? @file_get_contents($real) : false;
        if ($data === false || $data === '') continue;
        $logoUri = 'data:' . $mime . ';base64,' . base64_encode($data);
        break;
    }

    return $brand = [
        'name' => ($settings['site_name'] ?? '') !== '' ? $settings['site_name'] : (defined('APP_NAME') ? APP_NAME : 'Church'),
        'subtitle' => $settings['site_subtitle'] ?? '',
        'logo' => $logoUri,
    ];
}

/** CSS for the logo and the watermark; add it inside the receipt's <style>. */
function receipt_brand_css(): string
{
    // Only the height is set so logos of any shape keep their proportions (Dompdf has no object-fit).
    return '.receipt-logo{height:72px;width:auto}'
        // position:fixed repeats the watermark on every page in Dompdf.
        . '.receipt-watermark{position:fixed;top:24%;left:0;right:0;text-align:center;z-index:-1}'
        . '.receipt-watermark img{height:280px;width:auto;opacity:.07}';
}

/** The church logo as an <img>, or '' when there is none. */
function receipt_logo_html(mysqli $conn, string $class = 'receipt-logo'): string
{
    $logo = receipt_brand($conn)['logo'];
    return $logo === '' ? '' : '<img src="' . $logo . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" alt="">';
}

/** A faint logo behind the receipt content; place it straight after <body>. */
function receipt_watermark_html(mysqli $conn): string
{
    $logo = receipt_brand($conn)['logo'];
    return $logo === '' ? '' : '<div class="receipt-watermark"><img src="' . $logo . '" alt=""></div>';
}
