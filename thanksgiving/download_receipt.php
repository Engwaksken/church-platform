<?php
/**
 * thanksgiving/download_receipt.php
 * Generates a PDF thanksgiving receipt using Dompdf Composer autoload.
 */

require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }
require_once __DIR__ . '/../includes/receipt-branding.php';

$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];
$autoloadFound = false;
foreach ($autoloadPaths as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        $autoloadFound = true;
        break;
    }
}
if (!$autoloadFound || !class_exists('Dompdf\\Dompdf')) {
    die('Dompdf not found. Run: composer require dompdf/dompdf');
}

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function tr_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function tr_money($v): string { return 'UGX ' . number_format((float)$v); }
function tr_col_exists(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $cache[$key] = ((int)($row['c'] ?? 0) > 0);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) die('Invalid thanksgiving receipt.');

$sql = "SELECT t.*, m.full_name AS member_name,
               m.member_no, m.phone, m.email
        FROM thanksgiving t
        LEFT JOIN members m ON m.id = t.member_id
        WHERE t.id = ? LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$receipt) die('Thanksgiving receipt not found.');
receipt_clip_fields($receipt);

$receiptNo = $receipt['receipt_no'] ?: ('TGV-' . str_pad((string)$receipt['id'], 5, '0', STR_PAD_LEFT));
$giver = trim((string)($receipt['member_name'] ?? ''));
if ($giver === '') $giver = trim((string)($receipt['giver_name'] ?? 'Anonymous'));
$churchName = receipt_brand($conn)['name'];
$amountWords = '';

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:28px 32px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:12px}.receipt{border:2px solid #111827;padding:22px;position:relative}.watermark{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;opacity:.05;font-size:70px;font-weight:900;transform:rotate(-25deg);z-index:0}.content{position:relative;z-index:1}.top{display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #111827;padding-bottom:14px}.logo{width:82px;height:82px;object-fit:contain}.title{text-align:right}.title h1{margin:0;font-size:24px}.title h2{margin:5px 0 0;font-size:16px;color:#92400e}.meta{margin-top:18px;display:grid;grid-template-columns:1fr 1fr;gap:10px}.box{border:1px solid #d1d5db;border-radius:8px;padding:10px;background:#f9fafb}.label{font-size:10px;color:#6b7280;text-transform:uppercase;font-weight:700}.value{font-size:13px;font-weight:700;margin-top:3px}.amount{font-size:24px;color:#166534}.section-title{margin:18px 0 8px;font-weight:900;color:#111827;border-bottom:1px solid #e5e7eb;padding-bottom:5px}.notes{min-height:46px}.signatures{margin-top:38px;display:grid;grid-template-columns:1fr 1fr;gap:50px}.sig{border-top:1px solid #111827;padding-top:6px;text-align:center;font-size:11px}.footer{margin-top:20px;text-align:center;color:#6b7280;font-size:10px}.badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:800}
' . receipt_brand_css() . '</style></head><body>' . receipt_watermark_html($conn) . '<div class="receipt"><div class="content">
<div class="top"><div>' . receipt_logo_html($conn) . '</div><div class="title"><h1>' . tr_h($churchName) . '</h1><h2>THANKSGIVING RECEIPT</h2><div class="badge">' . tr_h($receiptNo) . '</div></div></div>
<div class="meta">
<div class="box"><div class="label">Received From</div><div class="value">' . tr_h($giver) . '</div></div>
<div class="box"><div class="label">Receipt Date</div><div class="value">' . tr_h($receipt['thanksgiving_date'] ?? date('Y-m-d')) . '</div></div>
<div class="box"><div class="label">Member No</div><div class="value">' . tr_h($receipt['member_no'] ?? 'N/A') . '</div></div>
<div class="box"><div class="label">Payment Method</div><div class="value">' . tr_h($receipt['payment_method'] ?? 'Cash') . '</div></div>
<div class="box"><div class="label">Reason</div><div class="value">' . tr_h($receipt['reason'] ?? '') . '</div></div>
<div class="box"><div class="label">Amount</div><div class="value amount">' . tr_money($receipt['amount'] ?? 0) . '</div></div>
</div>
<div class="section-title">Offering Details</div>
<div class="box notes"><strong>Item Description:</strong> ' . tr_h($receipt['item_description'] ?? 'Cash thanksgiving offering') . '<br><strong>Notes:</strong> ' . tr_h($receipt['notes'] ?? '') . '</div>
<div class="signatures"><div class="sig">Received By / Cashier</div><div class="sig">Authorized Signature</div></div>
<div class="footer">Generated on ' . date('Y-m-d H:i:s') . '. This receipt was generated electronically.</div>
</div></div></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = 'thanksgiving-receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $receiptNo) . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
exit;
