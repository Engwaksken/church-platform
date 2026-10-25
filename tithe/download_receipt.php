<?php
require_once '../config/config.php';
requireLogin();
require_once __DIR__ . '/../includes/receipt-branding.php';
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$id = (int)($_GET['tithe_id'] ?? $_GET['id'] ?? 0);
if ($id <= 0) { die('Invalid tithe receipt.'); }
$row = null;
$st = $conn->prepare("SELECT t.*, m.full_name, m.member_no, m.phone FROM tithe t LEFT JOIN members m ON m.id=t.member_id WHERE t.id=? LIMIT 1");
$st->bind_param('i',$id); $st->execute(); $row=$st->get_result()->fetch_assoc(); $st->close();
if(!$row){ die('Receipt not found.'); }
receipt_clip_fields($row);
$autoloads = [__DIR__.'/../vendor/autoload.php', __DIR__.'/../../vendor/autoload.php', dirname(__DIR__).'/vendor/autoload.php'];
$loaded = false; foreach($autoloads as $a){ if(file_exists($a)){ require_once $a; $loaded=true; break; } }
if(!$loaded || !class_exists('Dompdf\\Dompdf')) { die('Dompdf not found. Run composer require dompdf/dompdf and ensure vendor/autoload.php exists.'); }
use Dompdf\Dompdf; use Dompdf\Options;
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$months=['','January','February','March','April','May','June','July','August','September','October','November','December'];
$receiptNo = $row['receipt_no'] ?: ('TITHE-'.$row['id']);
$period = ((int)$row['period_month']>=1 && (int)$row['period_month']<=12 ? $months[(int)$row['period_month']].' ' : '').($row['period_year'] ?: '');
$member = trim($row['full_name'] ?? '') ?: 'Anonymous / Visitor';
$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:28px}body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937}.receipt{border:2px solid #4a1e8f;padding:24px;border-radius:12px}.header{text-align:center;border-bottom:2px solid #d4a017;padding-bottom:12px}.logo{width:75px;height:auto}.title{font-size:24px;font-weight:800;color:#4a1e8f;margin:8px 0 2px}.sub{font-size:12px;color:#6b7280}.watermark{position:fixed;top:38%;left:12%;right:12%;text-align:center;font-size:58px;color:#4a1e8f;opacity:.06;transform:rotate(-25deg);font-weight:800}.meta{margin-top:20px;width:100%;border-collapse:collapse}.meta td{padding:10px;border-bottom:1px solid #e5e7eb}.meta td:first-child{font-weight:700;width:35%;color:#4b5563}.amount{font-size:24px;font-weight:800;color:#198754}.footer{margin-top:35px;display:flex;justify-content:space-between}.sign{border-top:1px solid #111;padding-top:8px;width:220px;text-align:center;font-size:12px}.note{margin-top:22px;font-size:12px;color:#6b7280;text-align:center}'.receipt_brand_css().'</style></head><body>'.receipt_watermark_html($conn).'<div class="receipt"><div class="header">'.receipt_logo_html($conn).'<div class="title">TITHE RECEIPT</div><div class="sub">Official Church Payment Receipt</div></div><table class="meta"><tr><td>Receipt No</td><td>'.h($receiptNo).'</td></tr><tr><td>Member</td><td>'.h($member).(!empty($row['member_no'])?' ('.h($row['member_no']).')':'').'</td></tr><tr><td>Amount Paid</td><td class="amount">UGX '.number_format((float)$row['amount']).'</td></tr><tr><td>Period</td><td>'.h($period ?: 'N/A').'</td></tr><tr><td>Date Paid</td><td>'.h($row['tithe_date']).'</td></tr><tr><td>Payment Method</td><td>'.h($row['payment_method']).'</td></tr><tr><td>Notes</td><td>'.nl2br(h($row['notes'] ?? '')).'</td></tr></table><div class="footer"><div class="sign">Received By</div><div class="sign">Authorized Signature</div></div><div class="note">Thank you for your faithful giving.</div></div></body></html>';
$options = new Options(); $options->set('isRemoteEnabled', true); $options->set('isHtml5ParserEnabled', true); $dompdf = new Dompdf($options); $dompdf->loadHtml($html); $dompdf->setPaper('A4','portrait'); $dompdf->render(); $dompdf->stream('tithe-receipt-'.preg_replace('/[^A-Za-z0-9_-]/','-',$receiptNo).'.pdf', ['Attachment'=>false]);
