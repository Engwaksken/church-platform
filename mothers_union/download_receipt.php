<?php
require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }
require_once __DIR__ . '/../includes/receipt-branding.php';

if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_exists(mysqli $conn, string $table): bool {
    $s=$conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
    $s->bind_param('s',$table); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close();
    return (int)($r['c']??0)>0;
}

$paymentId = (int)($_GET['payment_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
$rec = null;

if ($paymentId > 0 && table_exists($conn, 'mothers_union_payments')) {
    $stmt = $conn->prepare("SELECT p.id AS payment_id, p.receipt_no, p.amount, p.payment_date, p.payment_method, p.notes AS payment_notes,
                                   mu.*, m.full_name AS full_name,
                                   m.member_no, m.phone, m.email
                            FROM mothers_union_payments p
                            JOIN mothers_union mu ON mu.id = p.mothers_union_id
                            JOIN members m ON m.id = p.member_id
                            WHERE p.id = ? LIMIT 1");
    $stmt->bind_param('i', $paymentId);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} elseif ($id > 0) {
    $stmt = $conn->prepare("SELECT mu.*, m.full_name AS full_name, m.member_no, m.phone, m.email
                            FROM mothers_union mu JOIN members m ON m.id = mu.member_id WHERE mu.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($rec) {
        $rec['payment_id'] = $rec['id'];
        $rec['receipt_no'] = 'MU-RCPT-' . str_pad((string)$rec['id'], 5, '0', STR_PAD_LEFT);
        $rec['amount'] = (float)($rec['subscription'] ?? 0);
        $rec['payment_date'] = $rec['date_joined'];
        $rec['payment_method'] = 'Cash';
        $rec['payment_notes'] = $rec['notes'] ?? '';
    }
}

if (!$rec) die('Invalid receipt/payment ID.');

$autoloads = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', dirname(__DIR__).'/vendor/autoload.php'];
$loaded = false;
foreach ($autoloads as $a) { if (file_exists($a)) { require_once $a; $loaded = true; break; } }
if (!$loaded || !class_exists('Dompdf\\Dompdf')) die('Dompdf autoload not found. Run composer require dompdf/dompdf.');

use Dompdf\Dompdf;
use Dompdf\Options;

$logoHtml = receipt_logo_html($conn);
$amount = (float)($rec['amount'] ?? $rec['subscription'] ?? 0);
$receiptNo = (string)($rec['receipt_no'] ?? ('MU-RCPT-' . str_pad((string)($rec['payment_id'] ?? $rec['id']), 5, '0', STR_PAD_LEFT)));
$date = (string)($rec['payment_date'] ?? $rec['date_joined'] ?? date('Y-m-d'));
$method = (string)($rec['payment_method'] ?? 'Cash');

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:13px}.receipt{border:1px solid #e5e7eb;padding:24px;border-radius:12px}.header{text-align:center;border-bottom:2px solid #111827;padding-bottom:12px;margin-bottom:18px}.title{font-size:24px;font-weight:bold}.muted{color:#6b7280}.row{display:flex;justify-content:space-between;margin:8px 0}.box{background:#f9fafb;border:1px solid #e5e7eb;padding:12px;margin-top:12px}.amount{font-size:22px;font-weight:bold}.footer{margin-top:40px;display:flex;justify-content:space-between}.line{border-top:1px solid #111;width:220px;text-align:center;padding-top:6px}'.receipt_brand_css().'</style></head><body>'.receipt_watermark_html($conn).'<div class="receipt"><div class="header">'.$logoHtml.'<div class="title">MOTHERS\' UNION RECEIPT</div><div class="muted">Subscription / Payment Receipt</div></div><div class="box"><div class="row"><strong>Receipt No:</strong><span>'.e($receiptNo).'</span></div><div class="row"><strong>MU No:</strong><span>'.e($rec['mu_no']).'</span></div><div class="row"><strong>Date:</strong><span>'.e($date).'</span></div><div class="row"><strong>Payment Method:</strong><span>'.e($method).'</span></div></div><div class="box"><div class="row"><strong>Received From:</strong><span>'.e($rec['full_name']).'</span></div><div class="row"><strong>Member No:</strong><span>'.e($rec['member_no']).'</span></div><div class="row"><strong>Phone:</strong><span>'.e($rec['phone']).'</span></div><div class="row"><strong>Position:</strong><span>'.e($rec['position']).'</span></div></div><div class="box"><div class="row"><strong>Description:</strong><span>Mothers\' Union Payment</span></div><div class="row amount"><strong>Amount:</strong><span>UGX '.number_format($amount).'</span></div><div class="row"><strong>Status:</strong><span>'.e($rec['status']).'</span></div></div><div class="footer"><div class="line">Received By</div><div class="line">Authorized Signature</div></div></div></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('mothers-union-receipt-'.preg_replace('/[^A-Za-z0-9_-]/','-', $receiptNo).'.pdf', ['Attachment'=>false]);
