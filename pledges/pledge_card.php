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

$balance = (float)$pledge['amount_pledged'] - (float)$pledge['amount_paid'];
$purpose = $pledge['project_name'] ?: ($pledge['pledge_reason'] ?: 'General Pledge');

$brand = receipt_brand($conn);
$paidPct = (float)$pledge['amount_pledged'] > 0 ? min(100, round((float)$pledge['amount_paid'] / (float)$pledge['amount_pledged'] * 100)) : 0;
$notes = trim((string)($pledge['notes'] ?? ''));
// Long notes would push the card onto a second page.
if (mb_strlen($notes) > 220) $notes = mb_substr($notes, 0, 217) . '...';

// Laid out for one A5 landscape page: fixed margins, compact two-column tables, no element taller than the page.
$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:22px 26px}
body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:11px;margin:0}
.card{border:2px solid #4a1e8f;border-radius:14px;padding:18px 22px}
.head{width:100%;border-collapse:collapse;border-bottom:2px solid #d4a017;margin-bottom:12px}
.head td{vertical-align:middle;padding:0 0 10px}
.head .receipt-logo{height:62px;width:auto}
.church{font-size:15px;font-weight:bold;color:#4a1e8f}
.title{font-size:19px;font-weight:bold;color:#111827;margin-top:2px}
.sub{font-size:10px;color:#6b7280}
.badge{display:inline-block;padding:5px 12px;border-radius:12px;background:#4a1e8f;color:#fff;font-weight:bold;font-size:11px}
.no{font-size:10px;color:#6b7280;margin-top:6px}
.grid{width:100%;border-collapse:collapse}
.grid td{padding:6px 8px;border-bottom:1px solid #eef0f3;vertical-align:top;width:25%}
.label{font-size:8px;color:#6b7280;text-transform:uppercase;font-weight:bold;letter-spacing:.3px}
.val{font-size:11px;font-weight:bold;margin-top:1px}
.money{width:100%;border-collapse:separate;border-spacing:8px 0;margin:12px -8px 0}
.money td{width:33%;padding:9px 10px;border-radius:9px;background:#f5f3ff}
.money .big{font-size:16px;font-weight:bold;color:#4a1e8f;margin-top:2px}
.money .paid{color:#166534}.money .bal{color:#b91c1c}
.bar{height:6px;background:#e5e7eb;border-radius:3px;margin-top:9px}
.bar div{height:6px;background:#16a34a;border-radius:3px}
.notes{margin-top:9px;font-size:10px;color:#374151}
.sign{width:100%;margin-top:46px;border-collapse:collapse}
.sign td{width:50%;text-align:center;padding:0 24px}
.sign div{border-top:1px solid #9ca3af;padding-top:4px;font-size:9.5px;color:#4b5563}
.foot{text-align:center;margin-top:10px;font-size:8.5px;color:#9ca3af}
'.receipt_brand_css().'
.receipt-watermark{top:20%}.receipt-watermark img{height:240px}
</style></head><body>'.receipt_watermark_html($conn).'<div class="card">
<table class="head"><tr>
  <td style="width:56px">'.receipt_logo_html($conn).'</td>
  <td><div class="church">'.pdf_h($brand['name']).'</div><div class="title">Pledge Commitment Card</div><div class="sub">Official pledge record</div></td>
  <td style="text-align:right;width:140px"><span class="badge">'.pdf_h($pledge['status']).'</span><div class="no">Pledge No <strong>'.pdf_h($pledge['pledge_no']).'</strong></div></td>
</tr></table>
<table class="grid">
<tr><td colspan="2"><div class="label">Member</div><div class="val">'.pdf_h(trim((string)$pledge['full_name'])).'</div></td><td><div class="label">Member No</div><div class="val">'.pdf_h($pledge['member_no'] ?: '—').'</div></td><td><div class="label">Phone</div><div class="val">'.pdf_h($pledge['phone'] ?: '—').'</div></td></tr>
<tr><td colspan="2"><div class="label">Project / Purpose</div><div class="val">'.pdf_h($purpose).'</div></td><td><div class="label">Pledge Date</div><div class="val">'.pdf_h($pledge['pledge_date']).'</div></td><td><div class="label">Due Date</div><div class="val">'.pdf_h($pledge['due_date'] ?: 'Open').'</div></td></tr>
</table>
<table class="money"><tr>
  <td><div class="label">Amount Pledged</div><div class="big">'.money_ugx($pledge['amount_pledged']).'</div></td>
  <td><div class="label">Amount Paid</div><div class="big paid">'.money_ugx($pledge['amount_paid']).'</div></td>
  <td><div class="label">Balance</div><div class="big bal">'.money_ugx($balance).'</div></td>
</tr></table>
<div class="bar"><div style="width:'.$paidPct.'%"></div></div>
'.($notes !== '' ? '<div class="notes"><span class="label">Notes</span> '.pdf_h($notes).'</div>' : '').'
<table class="sign"><tr><td><div>Member Signature</div></td><td><div>Authorized Signature</div></td></tr></table>
<div class="foot">Thank you for supporting the ministry · Generated '.date('Y-m-d H:i').'</div>
</div></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A5', 'landscape');
$dompdf->render();
$dompdf->stream('pledge-card-' . preg_replace('/[^A-Za-z0-9_-]/','-', $pledge['pledge_no']) . '.pdf', ['Attachment' => false]);
exit;
