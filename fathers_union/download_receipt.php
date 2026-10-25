<?php
require_once '../config/config.php';
requireLogin();
require_once __DIR__ . '/../includes/receipt-branding.php';
$id=(int)($_GET['id']??0);
$row=fetchOne("SELECT mu.*, m.full_name, m.member_no, m.phone FROM fathers_union mu JOIN members m ON m.id=mu.member_id WHERE mu.id=$id");
if(!$row){ die('Receipt record not found.'); }
$autoload = __DIR__.'/../vendor/autoload.php';
if(!file_exists($autoload)) $autoload = dirname(__DIR__).'/vendor/autoload.php';
if(!file_exists($autoload)) die('Dompdf autoload not found. Run composer require dompdf/dompdf.');
require_once $autoload;
use Dompdf\Dompdf; use Dompdf\Options;
function rh($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$receiptNo='FU-RCT-'.str_pad((string)$row['id'],5,'0',STR_PAD_LEFT);
$html='<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;color:#111}.receipt{border:2px solid #4a1e8f;padding:24px}.head{text-align:center;border-bottom:2px solid #d4a017;padding-bottom:12px}.title{font-size:24px;font-weight:bold;color:#4a1e8f}.meta{width:100%;margin-top:18px;border-collapse:collapse}.meta td{padding:8px;border-bottom:1px solid #ddd}.amount{font-size:22px;color:#0f7a35;font-weight:bold}.foot{margin-top:50px;display:flex;justify-content:space-between}.sig{border-top:1px solid #333;width:220px;text-align:center;padding-top:6px}'.receipt_brand_css().'</style></head><body>'.receipt_watermark_html($conn).'<div class="receipt"><div class="head">'.receipt_logo_html($conn).'<div class="title">FATHERS\' UNION RECEIPT</div><div>Annual Subscription / Membership Payment</div></div><table class="meta"><tr><td><b>Receipt No</b></td><td>'.rh($receiptNo).'</td><td><b>Date</b></td><td>'.date('Y-m-d').'</td></tr><tr><td><b>MU No</b></td><td>'.rh($row['fu_no']).'</td><td><b>Member No</b></td><td>'.rh($row['member_no']).'</td></tr><tr><td><b>Received From</b></td><td colspan="3">'.rh($row['full_name']).' - '.rh($row['phone']).'</td></tr><tr><td><b>Purpose</b></td><td colspan="3">Fathers\' Union Annual Subscription</td></tr><tr><td><b>Amount</b></td><td colspan="3" class="amount">UGX '.number_format((float)$row['subscription']).'</td></tr><tr><td><b>Status</b></td><td>'.rh($row['status']).'</td><td><b>Date Joined</b></td><td>'.rh($row['date_joined']).'</td></tr></table><div class="foot"><div class="sig">Received By</div><div class="sig">Authorized Signature</div></div></div></body></html>';
$options=new Options(); $options->set('isRemoteEnabled',true); $dompdf=new Dompdf($options); $dompdf->loadHtml($html); $dompdf->setPaper('A4','portrait'); $dompdf->render(); $dompdf->stream($receiptNo.'.pdf',['Attachment'=>false]);
