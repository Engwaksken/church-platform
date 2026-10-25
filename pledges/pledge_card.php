<?php
require_once '../config/config.php';
requireLogin();
require_once __DIR__ . '/../includes/receipt-branding.php';

function pledge_card_find(mysqli $conn, string $where, string $type, $value): ?array {
    $stmt = $conn->prepare("SELECT p.*, m.full_name AS full_name,
                                   m.member_no, m.phone, m.email, pr.name AS project_name
                            FROM pledges p
                            LEFT JOIN members m ON m.id = p.member_id
                            LEFT JOIN projects pr ON pr.id = p.project_id
                            WHERE $where
                            ORDER BY p.pledge_date DESC, p.id DESC LIMIT 1");
    $stmt->bind_param($type, $value);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ?pledge_id= (used by the pledges list) or ?id= is the pledge's database ID; "2" and "0002" are the same.
// ?pledge_no= is the member's pledge number, shared by all their pledges, so it opens their latest pledge.
$idParam = trim((string)($_GET['pledge_id'] ?? $_GET['id'] ?? ''));
$noParam = trim((string)($_GET['pledge_no'] ?? ''));
if ($idParam !== '') {
    if (!ctype_digit($idParam) || (int)$idParam <= 0) { die('Invalid pledge ID.'); }
    $pledge = pledge_card_find($conn, 'p.id = ?', 'i', (int)$idParam);
} elseif ($noParam !== '' && strlen($noParam) <= 50) {
    $pledge = pledge_card_find($conn, 'p.pledge_no = ?', 's', $noParam);
} else {
    die('Invalid pledge ID.');
}
if (!$pledge) { die('Pledge not found.'); }

$autoloads = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php'
];
$loaded = false;
foreach ($autoloads as $file) {
    if (is_file($file)) { require_once $file; $loaded = true; break; }
}
if (!$loaded || !class_exists('Dompdf\\Dompdf')) {
    die('Dompdf not found. Install it using: composer require dompdf/dompdf');
}

use Dompdf\Dompdf;
use Dompdf\Options;

function pdf_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money_ugx($v) { return 'UGX ' . number_format((float)$v); }

$logoHtml = receipt_logo_html($conn);
$balance = (float)$pledge['amount_pledged'] - (float)$pledge['amount_paid'];
$purpose = $pledge['project_name'] ?: ($pledge['pledge_reason'] ?: 'General Pledge');

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:28px}.body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:12px}.card{border:2px solid #4a1e8f;border-radius:16px;padding:22px;position:relative}.wm{position:absolute;top:38%;left:0;right:0;text-align:center;font-size:56px;color:#4a1e8f;opacity:.06;font-weight:800;transform:rotate(-18deg)}.header{text-align:center;border-bottom:2px solid #d4a017;padding-bottom:12px;margin-bottom:16px}.logo{width:72px;height:72px;object-fit:contain}.title{font-size:24px;font-weight:800;color:#4a1e8f;margin:4px 0}.subtitle{font-size:13px;color:#6b7280}.badge{display:inline-block;padding:7px 12px;border-radius:18px;background:#4a1e8f;color:#fff;font-weight:700}.grid{width:100%;border-collapse:collapse;margin-top:12px}.grid td{padding:9px;border-bottom:1px solid #e5e7eb;vertical-align:top}.label{font-size:10px;color:#6b7280;text-transform:uppercase;font-weight:700}.amount{font-size:20px;font-weight:800;color:#166534}.balance{font-size:18px;font-weight:800;color:#dc2626}.sign{margin-top:38px;width:100%;}.sign td{width:50%;text-align:center;padding-top:35px;border-top:1px solid #9ca3af}.foot{text-align:center;margin-top:18px;font-size:10px;color:#6b7280}.right{text-align:right}
'.receipt_brand_css().'</style></head><body>'.receipt_watermark_html($conn).'<div class="body"><div class="card"><div class="header">'.$logoHtml.'<div class="title">Pledge Commitment Card</div><div class="subtitle">Official pledge record</div></div>
<table class="grid"><tr><td><div class="label">Pledge No</div><strong>'.pdf_h($pledge['pledge_no']).'</strong></td><td class="right"><span class="badge">'.pdf_h($pledge['status']).'</span></td></tr>
<tr><td><div class="label">Member</div>'.pdf_h(trim($pledge['full_name'])).'</td><td><div class="label">Member No</div>'.pdf_h($pledge['member_no'] ?: '—').'</td></tr>
<tr><td><div class="label">Phone</div>'.pdf_h($pledge['phone'] ?: '—').'</td><td><div class="label">Email</div>'.pdf_h($pledge['email'] ?: '—').'</td></tr>
<tr><td colspan="2"><div class="label">Project / Purpose</div>'.pdf_h($purpose).'</td></tr>
<tr><td><div class="label">Pledge Date</div>'.pdf_h($pledge['pledge_date']).'</td><td><div class="label">Due Date</div>'.pdf_h($pledge['due_date'] ?: 'Open').'</td></tr>
<tr><td><div class="label">Amount Pledged</div><div class="amount">'.money_ugx($pledge['amount_pledged']).'</div></td><td><div class="label">Balance</div><div class="balance">'.money_ugx($balance).'</div></td></tr>
<tr><td><div class="label">Amount Paid</div>'.money_ugx($pledge['amount_paid']).'</td><td><div class="label">Generated On</div>'.date('Y-m-d H:i').'</td></tr>
<tr><td colspan="2"><div class="label">Notes</div>'.pdf_h($pledge['notes'] ?: '—').'</td></tr></table>
<table class="sign"><tr><td>Member Signature</td><td>Authorized Signature</td></tr></table><div class="foot">Thank you for supporting the ministry. This card is system generated.</div></div></div></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'landscape');
$dompdf->render();
$dompdf->stream('pledge-card-' . preg_replace('/[^A-Za-z0-9_-]/','-', $pledge['pledge_no']) . '.pdf', ['Attachment' => false]);
exit;
