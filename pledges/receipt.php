<?php
declare(strict_types=1);
require_once '../config/db.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$conn->set_charset('utf8mb4');

function r_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money_ugx($v): string { return 'UGX ' . number_format((float)$v); }
function r_one(mysqli $conn, string $sql): ?array { $res=$conn->query($sql); if(!$res) return null; $row=$res->fetch_assoc(); $res->close(); return $row ?: null; }

$payment_id = (int)($_GET['payment_id'] ?? $_GET['id'] ?? $_GET['pledge_payment_id'] ?? 0);
$pledge_id = (int)($_GET['pledge_id'] ?? 0);

if ($payment_id <= 0 && $pledge_id > 0) {
    $last = r_one($conn, "SELECT id FROM pledge_payments WHERE pledge_id=$pledge_id ORDER BY id DESC LIMIT 1");
    $payment_id = (int)($last['id'] ?? 0);
}
if ($payment_id <= 0) { die('Invalid payment ID. Open receipt from a payment row, not the pledge row.'); }

$payment = r_one($conn, "SELECT pp.*, p.pledge_no, p.amount_pledged, p.amount_paid, p.pledge_reason, p.due_date, p.status,
        m.full_name AS full_name,
        m.member_no, m.phone, m.email, pr.name AS project_name
    FROM pledge_payments pp
    JOIN pledges p ON p.id = pp.pledge_id
    LEFT JOIN members m ON m.id = p.member_id
    LEFT JOIN projects pr ON pr.id = p.project_id
    WHERE pp.id = $payment_id LIMIT 1");
if (!$payment) { die('Payment not found.'); }

$autoloads = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', dirname(__DIR__).'/vendor/autoload.php', $_SERVER['DOCUMENT_ROOT'].'/vendor/autoload.php'];
$loaded = false;
foreach ($autoloads as $file) { if (is_file($file)) { require_once $file; $loaded = true; break; } }
if (!$loaded || !class_exists('Dompdf\\Dompdf')) { die('Dompdf not found. Install it using: composer require dompdf/dompdf'); }

$logoPath = realpath(__DIR__ . '/../assets/img/logo.png');
$logoHtml = $logoPath && is_file($logoPath) ? '<img src="file://' . r_h($logoPath) . '" class="logo">' : '';
$balance = max(0, (float)$payment['amount_pledged'] - (float)$payment['amount_paid']);
$purpose = $payment['project_name'] ?: ($payment['pledge_reason'] ?: 'General Pledge');
$receiptNo = $payment['receipt_no'] ?: ('RCP-' . $payment_id);

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:30px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:12px}.receipt{border:1px solid #ddd;padding:24px;position:relative}.wm{position:absolute;top:40%;left:0;right:0;text-align:center;font-size:58px;color:#4a1e8f;opacity:.06;font-weight:800;transform:rotate(-18deg)}.top{text-align:center;border-bottom:3px solid #4a1e8f;padding-bottom:12px}.logo{width:70px;height:70px;object-fit:contain}.title{font-size:23px;font-weight:800;color:#4a1e8f}.receipt-no{font-size:16px;color:#dc2626;font-weight:800}.row{width:100%;border-collapse:collapse;margin-top:16px}.row td,.row th{border:1px solid #e5e7eb;padding:9px;text-align:left}.row th{background:#f8fafc}.amount{font-size:22px;font-weight:800;color:#166534}.sign{margin-top:42px;width:100%;border-collapse:collapse}.sign td{width:50%;text-align:center;padding-top:32px;border-top:1px solid #9ca3af}.foot{text-align:center;font-size:10px;color:#6b7280;margin-top:18px}
</style></head><body><div class="receipt"><div class="wm">RECEIPT</div><div class="top">'.$logoHtml.'<div class="title">Pledge Payment Receipt</div><div class="receipt-no">Receipt No: '.r_h($receiptNo).'</div></div><table class="row"><tr><th>Received From</th><td>'.r_h(trim((string)$payment['full_name'])).'</td><th>Member No</th><td>'.r_h($payment['member_no'] ?: '—').'</td></tr><tr><th>Pledge No</th><td>'.r_h($payment['pledge_no']).'</td><th>Payment Date</th><td>'.r_h($payment['paid_on']).'</td></tr><tr><th>Purpose</th><td colspan="3">'.r_h($purpose).'</td></tr><tr><th>Payment Method</th><td>'.r_h($payment['method']).'</td><th>Phone</th><td>'.r_h($payment['phone'] ?: '—').'</td></tr><tr><th>Amount Paid</th><td colspan="3"><span class="amount">'.money_ugx($payment['amount']).'</span></td></tr><tr><th>Total Pledged</th><td>'.money_ugx($payment['amount_pledged']).'</td><th>Total Paid</th><td>'.money_ugx($payment['amount_paid']).'</td></tr><tr><th>Balance</th><td>'.money_ugx($balance).'</td><th>Status</th><td>'.r_h($payment['status']).'</td></tr><tr><th>Notes</th><td colspan="3">'.r_h($payment['notes'] ?: '—').'</td></tr></table><table class="sign"><tr><td>Received By</td><td>Authorized Signature</td></tr></table><div class="foot">Generated on '.date('Y-m-d H:i').' | Thank you for your pledge payment.</div></div></body></html>';

$options = new Dompdf\Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$dompdf = new Dompdf\Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('pledge-receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '-', (string)$receiptNo) . '.pdf', ['Attachment' => false]);
exit;
