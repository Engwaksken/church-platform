<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/church-book-functions.php';
require_once 'report-data.php';
requireLogin();
cb_require_permission('converts.view');
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');
cb_load_dompdf();

use Dompdf\Dompdf;
use Dompdf\Options;

$rows = cn_report_rows($conn);
$site = cb_site_setting($conn, 'site_name', 'Church Management System');
$filter = cn_report_filter_text($conn);
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:22px 20px}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:8px}h1,h2,p{margin:0;text-align:center}h2{font-size:14px}h1{font-size:19px;margin-top:4px}.meta{margin:9px 0 11px;padding:7px;background:#f3f6f8;border:1px solid #d9e0e5;font-size:7px}.count{float:right}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #aeb7c0;padding:4px 3px;vertical-align:top;word-wrap:break-word}th{background:#e9eff2;font-size:7px;text-transform:uppercase}.small{font-size:6.5px;color:#55606d}.footer{margin-top:9px;font-size:6.5px;text-align:right;color:#667085}</style></head><body>';
$html .= '<h2>'.$e($site).'</h2><h1>Converts &amp; Nurture Register</h1><p class="small">Generated '.date('d M Y, H:i').'</p>';
$html .= '<div class="meta">'.$e($filter).'<span class="count">Records: '.count($rows).'</span></div>';
$html .= '<table><thead><tr><th style="width:3%">#</th><th style="width:8%">Date</th><th style="width:13%">Convert</th><th style="width:6%">Type</th><th style="width:10%">Decision</th><th style="width:13%">Assigned To</th><th style="width:12%">Nurture Period</th><th style="width:10%">Next Follow-up</th><th style="width:8%">Status</th><th style="width:17%">Prayer / Notes</th></tr></thead><tbody>';
if (!$rows) $html .= '<tr><td colspan="10" style="text-align:center">No convert records found.</td></tr>';
foreach ($rows as $i=>$r) {
    $convert = '<strong>'.$e($r['full_name']).'</strong><br><span class="small">'.$e($r['phone'] ?: $r['email'] ?: '-').'</span>';
    if ((int)$r['self_registered']===1) $convert .= '<br><span class="small">QR self-registered</span>';
    $decision = $e($r['decision_type']); if ($r['conversion_context']) $decision .= '<br><span class="small">'.$e($r['conversion_context']).'</span>';
    $assigned = $e($r['assigned_member_name'] ?: 'Pending Assignment'); if ($r['assigned_member_no']) $assigned .= '<br><span class="small">'.$e($r['assigned_member_no']).'</span>';
    $period = $e((string)$r['nurture_period_weeks'].' weeks').'<br><span class="small">'.$e($r['nurture_start_date'] ?: '-').' to '.$e($r['nurture_end_date'] ?: '-').'<br>'.(int)$r['followup_count'].' follow-up(s)</span>';
    $notes = $e($r['prayer_request'] ?: ''); if ($r['notes']) $notes .= ($notes?'<br>':'').'<span class="small">'.$e($r['notes']).'</span>';
    $html .= '<tr><td>'.($i+1).'</td><td>'.$e(date('d M Y',strtotime($r['conversion_date']))).'</td><td>'.$convert.'</td><td>'.$e($r['person_type']).'</td><td>'.$decision.'</td><td>'.$assigned.'</td><td>'.$period.'</td><td>'.$e($r['next_follow_up_date'] ?: '-').'</td><td>'.$e($r['status']).'</td><td>'.$notes.'</td></tr>';
}
$html .= '</tbody></table><div class="footer">'.$e($site).' · Converts &amp; Nurture Register</div></body></html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$pdf = new Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'landscape');
$pdf->render();
$pdf->stream('converts-nurture-' . date('Y-m-d') . '.pdf', ['Attachment'=>true]);
exit;
