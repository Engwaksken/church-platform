<?php
require_once '../config/config.php';
requireLogin();
require_once __DIR__ . '/../includes/receipt-branding.php';
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { die('Invalid offertory ID.'); }
$rec = fetchOne("SELECT * FROM offertory WHERE id = $id");
if (!$rec) { die('Offertory record not found.'); }
$autoloads = [__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php', dirname(__DIR__) . '/vendor/autoload.php', $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php'];
$loaded = false;
foreach ($autoloads as $file) { if (is_file($file)) { require_once $file; $loaded = true; break; } }
if (!$loaded || !class_exists('Dompdf\\Dompdf')) { die('Dompdf not found. Install it using: composer require dompdf/dompdf'); }
use Dompdf\Dompdf;
use Dompdf\Options;
function pdf_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money_ugx($v){ return 'UGX ' . number_format((float)$v); }
$logoHtml = receipt_logo_html($conn);
$receiptNo = 'OFF-' . str_pad((string)$rec['id'], 6, '0', STR_PAD_LEFT);
$html='<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:30px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#111827;font-size:12px}.receipt{border:1px solid #ddd;padding:24px;position:relative}.wm{position:absolute;top:40%;left:0;right:0;text-align:center;font-size:58px;color:#4a1e8f;opacity:.06;font-weight:800;transform:rotate(-18deg)}.top{text-align:center;border-bottom:3px solid #4a1e8f;padding-bottom:12px}.logo{width:70px;height:70px;object-fit:contain}.title{font-size:23px;font-weight:800;color:#4a1e8f}.receipt-no{font-size:16px;color:#dc2626;font-weight:800}.tbl{width:100%;border-collapse:collapse;margin-top:16px}.tbl td,.tbl th{border:1px solid #e5e7eb;padding:10px;text-align:left}.tbl th{background:#f8fafc}.amount{font-size:24px;font-weight:800;color:#166534}.sign{margin-top:42px;width:100%}.sign td{width:50%;text-align:center;padding-top:32px;border-top:1px solid #9ca3af}.foot{text-align:center;font-size:10px;color:#6b7280;margin-top:18px}'.receipt_brand_css().'</style></head><body>'.receipt_watermark_html($conn).'<div class="receipt"><div class="top">'.$logoHtml.'<div class="title">Offertory Receipt</div><div class="receipt-no">Receipt No: '.pdf_h($receiptNo).'</div></div><table class="tbl"><tr><th>Service Date</th><td>'.pdf_h($rec['service_date']).'</td><th>Service Type</th><td>'.pdf_h($rec['service_type']).'</td></tr><tr><th>Envelope Collection</th><td>'.money_ugx($rec['envelope_total']).'</td><th>Loose Offering</th><td>'.money_ugx($rec['loose_total']).'</td></tr><tr><th>Total Amount</th><td colspan="3"><span class="amount">'.money_ugx($rec['total_amount']).'</span></td></tr><tr><th>Counted By</th><td>'.pdf_h($rec['counted_by'] ?: '—').'</td><th>Witnessed By</th><td>'.pdf_h($rec['witnessed_by'] ?: '—').'</td></tr><tr><th>Notes</th><td colspan="3">'.pdf_h($rec['notes'] ?: '—').'</td></tr></table><table class="sign"><tr><td>Counted By Signature</td><td>Authorized Signature</td></tr></table><div class="foot">Generated on '.date('Y-m-d H:i').' | Official offertory receipt.</div></div></body></html>';
$options=new Options();$options->set('isRemoteEnabled',true);$options->set('isHtml5ParserEnabled',true);$dompdf=new Dompdf($options);$dompdf->loadHtml($html);$dompdf->setPaper('A4','portrait');$dompdf->render();$dompdf->stream('offertory-receipt-'.$receiptNo.'.pdf',['Attachment'=>false]);exit;
