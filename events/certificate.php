<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/config.php';
requireLogin();
require_permission('events.view');

$eventId = (int)($_POST['event_id'] ?? $_GET['id'] ?? 0);
$stmt = $conn->prepare('SELECT id,title,event_type,event_date,event_time,venue,organizer FROM events WHERE id=? LIMIT 1');
if (!$stmt) { http_response_code(500); exit('Unable to load the event.'); }
$stmt->bind_param('i', $eventId);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$event) { http_response_code(404); exit('Event not found.'); }

$participant = trim((string)($_POST['participant_name'] ?? ''));
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($participant === '' || mb_strlen($participant) > 180) $errors[] = 'Enter the participant name (up to 180 characters).';
    if (!hash_equals((string)($_SESSION['event_certificate_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) $errors[] = 'The form expired. Reload the page and try again.';
}
if (empty($_SESSION['event_certificate_csrf'])) $_SESSION['event_certificate_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['event_certificate_csrf'];

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

$logoSrc = '';
if ($brand['logo'] !== '') {
    if (preg_match('~^https?://~i', $brand['logo'])) $logoSrc = $brand['logo'];
    else {
        $relative = ltrim($brand['logo'], '/');
        $absolute = __DIR__ . '/../' . $relative;
        if (is_file($absolute)) {
            $data = @file_get_contents($absolute);
            if ($data !== false) $logoSrc = 'data:' . (function_exists('mime_content_type') ? (mime_content_type($absolute) ?: 'image/png') : 'image/png') . ';base64,' . base64_encode($data);
        } else $logoSrc = rtrim(BASE_URL, '/') . '/' . $relative;
    }
}
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$eventDate = date('d F Y', strtotime((string)$event['event_date']));
$certificateNo = 'EVT-' . str_pad((string)$event['id'], 5, '0', STR_PAD_LEFT) . '-' . date('Y', strtotime((string)$event['event_date']));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Event Participation Certificate | <?= $e($brand['name']) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;padding:24px;background:#f1f2f5;color:#172033;font-family:Georgia,'Times New Roman',serif}.toolbar{max-width:1100px;margin:0 auto 18px;padding:16px;background:#fff;border-radius:12px;box-shadow:0 5px 20px #10182812;font:14px Arial,sans-serif}.toolbar form{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.toolbar label{display:grid;gap:6px;font-weight:700;flex:1;min-width:230px}.toolbar input{height:40px;padding:8px 10px;border:1px solid #cbd2de;border-radius:6px;font:inherit}.toolbar button,.toolbar a{display:inline-block;padding:10px 16px;border:0;border-radius:6px;background:#4a1e8f;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.toolbar a{background:#667085}.error{margin:0 0 12px;padding:10px;background:#feeceb;color:#b42318;border-radius:6px}.certificate{position:relative;display:flex;flex-direction:column;justify-content:center;width:min(1100px,100%);min-height:720px;margin:auto;padding:54px 72px;text-align:center;background:#fff;border:10px double #4a1e8f;outline:2px solid #d4a017;outline-offset:-24px;box-shadow:0 15px 42px #10182822}.brand-logo{display:block;max-width:150px;max-height:85px;width:auto;height:auto;object-fit:contain;margin:0 auto 8px}.brand-name{font:700 22px Arial,sans-serif;color:#4a1e8f;letter-spacing:.5px}.brand-subtitle{margin-top:5px;font:14px Arial,sans-serif;color:#667085}.eyebrow{margin:38px 0 10px;color:#a47a0a;font:700 13px Arial,sans-serif;letter-spacing:4px;text-transform:uppercase}h1{margin:0;color:#4a1e8f;font-size:42px;letter-spacing:2px;text-transform:uppercase}.intro{margin:22px 0 8px;font-size:21px}.participant{display:inline-block;margin:4px auto 20px;padding:0 30px 8px;border-bottom:2px solid #d4a017;color:#4a1e8f;font-size:38px;font-weight:700}.statement{max-width:800px;margin:0 auto;font-size:20px;line-height:1.65}.event-name{color:#172033;font-weight:700}.event-meta{margin:25px auto 0;color:#475467;font:14px Arial,sans-serif}.signatures{display:flex;justify-content:space-around;gap:60px;margin:65px auto 0;max-width:780px}.signature{width:270px;padding-top:9px;border-top:1px solid #4a1e8f;font:14px Arial,sans-serif}.cert-number{position:absolute;right:58px;bottom:42px;color:#667085;font:11px Arial,sans-serif}@media(max-width:700px){body{padding:12px}.certificate{min-height:600px;padding:42px 30px}.certificate h1{font-size:30px}.participant{font-size:30px}.statement{font-size:17px}.signatures{gap:28px;margin-top:45px}.signature{width:46%}}@media print{@page{size:A4 landscape;margin:10mm}body{padding:0;background:#fff}.toolbar{display:none}.certificate{width:100%;height:185mm;min-height:0;margin:0;padding:35px 55px;box-shadow:none;break-inside:avoid}.eyebrow{margin-top:22px}.signatures{margin-top:42px}}
</style>
<style>
.certificate{isolation:isolate}
.certificate-watermark{position:absolute;z-index:0;inset:15% 20%;width:60%;height:70%;object-fit:contain;opacity:.06;pointer-events:none}
.certificate>*:not(.certificate-watermark){position:relative;z-index:1}
.certificate>.cert-number{position:absolute;z-index:2}
</style>
</head>
<body>
<?php if ($participant === '' || $errors): ?>
<div class="toolbar">
<?php foreach ($errors as $error): ?><p class="error"><?= $e($error) ?></p><?php endforeach; ?>
<form method="post"><input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><label>Participant full name<input name="participant_name" value="<?= $e($participant) ?>" maxlength="180" required autofocus></label><button type="submit">Prepare Certificate</button><a href="index.php">Back to Events</a></form>
</div>
<?php else: ?>
<div class="toolbar"><form onsubmit="return false"><label>Participant<input value="<?= $e($participant) ?>" readonly></label><button type="button" onclick="window.print()">Print Certificate</button><a href="certificate.php?id=<?= (int)$event['id'] ?>">Create Another</a><a href="index.php">Back to Events</a></form></div>
<main class="certificate">
<?php if ($logoSrc !== ''): ?><img class="certificate-watermark" src="<?= $e($logoSrc) ?>" alt="" aria-hidden="true"><?php endif; ?>
<?php if ($logoSrc !== ''): ?><img class="brand-logo" src="<?= $e($logoSrc) ?>" alt="<?= $e($brand['name']) ?> logo"><?php endif; ?>
<div class="brand-name"><?= $e($brand['name']) ?></div>
<?php if ($brand['subtitle'] !== ''): ?><div class="brand-subtitle"><?= $e($brand['subtitle']) ?></div><?php endif; ?>
<div class="eyebrow">Certificate of Participation</div>
<h1>Event Certificate</h1>
<p class="intro">This certificate is proudly presented to</p>
<div class="participant"><?= $e($participant) ?></div>
<p class="statement">In recognition of participation in <span class="event-name"><?= $e($event['title']) ?></span><?= !empty($event['event_type']) ? ' (' . $e($event['event_type']) . ')' : '' ?>, held on <strong><?= $e($eventDate) ?></strong><?= !empty($event['venue']) ? ' at <strong>' . $e($event['venue']) . '</strong>' : '' ?>.</p>
<div class="signatures"><div class="signature"><?= $e($event['organizer'] ?: 'Event Organizer') ?><br>Organizer</div><div class="signature">Authorized Church Representative</div></div>
<div class="cert-number">Certificate No: <?= $e($certificateNo) ?></div>
</main>
<?php endif; ?>
</body></html>
