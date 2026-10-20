<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/church-book-functions.php';
requireLogin();
cb_require_permission('converts.view');
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');
cb_load_dompdf();

use Dompdf\Dompdf;
use Dompdf\Options;

$id = cb_int($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid convert record.'); }

$row = cb_fetch_one(
    $conn,
    "SELECT c.*,
            CONCAT_WS(' ',am.first_name,am.last_name) assigned_member_name,
            am.member_no assigned_member_no,
            am.phone assigned_member_phone,
            CONCAT_WS(' ',lm.first_name,lm.last_name) linked_member_name,
            lm.member_no linked_member_no,
            v.full_name linked_visitor_name
     FROM church_converts c
     LEFT JOIN members am ON am.id=c.assigned_member_id
     LEFT JOIN members lm ON lm.id=c.member_id
     LEFT JOIN church_visitors v ON v.id=c.visitor_id
     WHERE c.id=? LIMIT 1",
    'i',
    [$id]
);
if (!$row) { http_response_code(404); exit('Convert record not found.'); }

$followups = cb_fetch_all(
    $conn,
    "SELECT f.*,u.full_name recorded_by_name
     FROM church_convert_followups f
     LEFT JOIN users u ON u.id=f.recorded_by
     WHERE f.convert_id=?
     ORDER BY f.follow_up_date ASC,f.id ASC",
    'i',
    [$id]
);

$site = cb_site_setting($conn,'site_name','Church Management System');
$e = static fn($v): string => htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$yesNo = static fn($v): string => (int)$v === 1 ? 'Yes' : 'No';

$html = '<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:28px 32px}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:10px}h1,h2,h3,p{margin:0}h2{text-align:center;font-size:15px}h1{text-align:center;font-size:21px;margin:5px 0}.sub{text-align:center;color:#667085;font-size:8px}.section{margin-top:15px;border-bottom:2px solid #05645b;color:#05645b;font-weight:bold;font-size:11px;padding-bottom:4px}.grid{width:100%;border-collapse:collapse;margin-top:7px}.grid td{width:50%;padding:5px 7px;border:1px solid #d8dee5;vertical-align:top}.label{font-size:7px;text-transform:uppercase;color:#667085;font-weight:bold}.value{margin-top:2px}.box{border:1px solid #d8dee5;padding:8px;margin-top:7px;min-height:35px}.timeline{width:100%;border-collapse:collapse;margin-top:7px}.timeline th,.timeline td{border:1px solid #cbd2d9;padding:5px;vertical-align:top}.timeline th{background:#edf4f2;text-align:left;font-size:8px}.signatures{width:100%;margin-top:28px;border-collapse:collapse}.signatures td{width:50%;padding:18px 18px 0}.line{border-top:1px solid #444;padding-top:4px;text-align:center;font-size:8px}.footer{position:fixed;bottom:-12px;left:0;right:0;text-align:center;color:#98a2b3;font-size:7px}</style></head><body>';
$html .= '<h2>'.$e($site).'</h2><h1>Convert &amp; Nurture Record</h1><p class="sub">Record #CN-'.str_pad((string)$row['id'],6,'0',STR_PAD_LEFT).' · Generated '.date('d M Y').'</p>';

$html .= '<div class="section">Convert Details</div><table class="grid">';
$details = [
    ['Full Name',$row['full_name'],'Person Type',$row['person_type']],
    ['Conversion Date',date('d M Y',strtotime($row['conversion_date'])),'Decision Type',$row['decision_type']],
    ['Gender',$row['gender'] ?: '-','Phone',$row['phone'] ?: '-'],
    ['Email',$row['email'] ?: '-','Address',$row['address'] ?: '-'],
    ['Linked Member',$row['linked_member_name'] ? trim((string)$row['linked_member_no'].' '.$row['linked_member_name']) : '-','Linked Visitor',$row['linked_visitor_name'] ?: '-'],
    ['Service / Event / Place',$row['conversion_context'] ?: '-','QR Self-registered',$yesNo($row['self_registered'])],
];
foreach ($details as $d) $html .= '<tr><td><div class="label">'.$e($d[0]).'</div><div class="value">'.$e($d[1]).'</div></td><td><div class="label">'.$e($d[2]).'</div><div class="value">'.$e($d[3]).'</div></td></tr>';
$html .= '</table>';

$html .= '<div class="section">Testimony &amp; Prayer</div>';
$html .= '<div class="box"><div class="label">Testimony / Decision Notes</div><div class="value">'.nl2br($e($row['testimony'] ?: '-')).'</div></div>';
$html .= '<div class="box"><div class="label">Prayer Request / Support Needed</div><div class="value">'.nl2br($e($row['prayer_request'] ?: '-')).'</div></div>';

$html .= '<div class="section">Nurture Plan</div><table class="grid">';
$plan = [
    ['Assigned Nurture Member',$row['assigned_member_name'] ?: 'Pending Assignment','Member No.',$row['assigned_member_no'] ?: '-'],
    ['Nurture Start',$row['nurture_start_date'] ? date('d M Y',strtotime($row['nurture_start_date'])) : '-','Nurture End',$row['nurture_end_date'] ? date('d M Y',strtotime($row['nurture_end_date'])) : '-'],
    ['Period',(int)$row['nurture_period_weeks'].' weeks','Status',$row['status']],
    ['Next Follow-up',$row['next_follow_up_date'] ? date('d M Y',strtotime($row['next_follow_up_date'])) : '-','Baptism Interest',$yesNo($row['baptism_interest'])],
    ['Discipleship Class',$row['discipleship_class'] ?: '-','Assigned Member Phone',$row['assigned_member_phone'] ?: '-'],
];
foreach ($plan as $d) $html .= '<tr><td><div class="label">'.$e($d[0]).'</div><div class="value">'.$e($d[1]).'</div></td><td><div class="label">'.$e($d[2]).'</div><div class="value">'.$e($d[3]).'</div></td></tr>';
$html .= '</table>';
if ($row['notes']) $html .= '<div class="box"><div class="label">Internal Notes</div><div class="value">'.nl2br($e($row['notes'])).'</div></div>';

$html .= '<div class="section">Nurture Follow-up History</div><table class="timeline"><thead><tr><th>Date</th><th>Method</th><th>Progress</th><th>Topics / Activities</th><th>Prayer / Support</th><th>Next</th></tr></thead><tbody>';
if (!$followups) $html .= '<tr><td colspan="6" style="text-align:center">No nurture follow-up has been recorded.</td></tr>';
foreach ($followups as $f) {
    $html .= '<tr><td>'.$e(date('d M Y',strtotime($f['follow_up_date']))).'</td><td>'.$e($f['contact_method']).'</td><td>'.$e($f['progress_status']).'</td><td>'.$e($f['topics_discussed'] ?: '-').'</td><td>'.$e($f['prayer_notes'] ?: '-').'</td><td>'.$e($f['next_follow_up_date'] ? date('d M Y',strtotime($f['next_follow_up_date'])) : '-').'</td></tr>';
}
$html .= '</tbody></table>';

$html .= '<table class="signatures"><tr><td><div class="line">Assigned Nurture Member</div></td><td><div class="line">Pastor / Ministry Leader</div></td></tr><tr><td><div class="line">Convert</div></td><td><div class="line">Date / Church Stamp</div></td></tr></table>';
$html .= '<div class="footer">'.$e($site).' · Converts &amp; Nurture</div></body></html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');
$pdf = new Dompdf($options);
$pdf->loadHtml($html,'UTF-8');
$pdf->setPaper('A4','portrait');
$pdf->render();
$safeName = preg_replace('/[^A-Za-z0-9_-]+/','-',trim((string)$row['full_name'])) ?: 'convert';
$pdf->stream('nurture-record-'.$safeName.'.pdf',['Attachment'=>true]);
exit;
