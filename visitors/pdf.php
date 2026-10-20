<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/church-book-functions.php';
requireLogin();
cb_require_permission('visitors.view');
$conn->set_charset('utf8mb4');
cb_load_dompdf();

use Dompdf\Dompdf;
use Dompdf\Options;

$q=cb_clean($_GET['q']??'');$type=cb_clean($_GET['type']??'');$follow=cb_clean($_GET['follow']??'');$from=cb_clean($_GET['from']??'');$to=cb_clean($_GET['to']??'');
$where=['1=1'];$types='';$params=[];
if($q!==''){$where[]="(v.full_name LIKE ? OR v.phone LIKE ? OR v.email LIKE ? OR v.address LIKE ? OR v.service_attended LIKE ? OR v.invited_by LIKE ?)";$like="%{$q}%";for($i=0;$i<6;$i++){$params[]=$like;$types.='s';}}
if(in_array($type,['First Time','Returning'],true)){$where[]='v.visitor_type=?';$params[]=$type;$types.='s';}
if(in_array($follow,['Not Required','Pending','Contacted','Completed'],true)){$where[]='v.follow_up_status=?';$params[]=$follow;$types.='s';}
if($from!==''&&cb_valid_date($from)){$where[]='v.visit_date>=?';$params[]=$from;$types.='s';}
if($to!==''&&cb_valid_date($to)){$where[]='v.visit_date<=?';$params[]=$to;$types.='s';}
$rows=cb_fetch_all($conn,"SELECT v.*,m.member_no,CONCAT_WS(' ',m.first_name,m.last_name) member_name FROM church_visitors v LEFT JOIN members m ON m.id=v.member_id WHERE ".implode(' AND ',$where)." ORDER BY v.visit_date ASC,v.id ASC",$types,$params);
$site=cb_site_setting($conn,'site_name','Church Management System');
$filter=[];if($q!=='')$filter[]='Search: '.$q;if($type!=='')$filter[]='Type: '.$type;if($follow!=='')$filter[]='Follow-up: '.$follow;if($from!=='')$filter[]='From: '.$from;if($to!=='')$filter[]='To: '.$to;
$e=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$html='<!doctype html><html><head><meta charset="utf-8"><style>
@page{margin:24px 22px}body{font-family:DejaVu Sans,sans-serif;color:#172033;font-size:9px}h1,h2,p{margin:0;text-align:center}h2{font-size:15px}h1{font-size:20px;margin-top:4px}.meta{margin:10px 0 12px;padding:7px 9px;background:#f3f6f8;border:1px solid #d9e0e5;font-size:8px}.count{float:right}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #aeb7c0;padding:5px 4px;vertical-align:top;word-wrap:break-word}th{background:#e9eff2;font-size:8px;text-transform:uppercase}.small{font-size:7px;color:#55606d}.footer{margin-top:10px;font-size:7px;text-align:right;color:#667085}</style></head><body>';
$html.='<h2>'.$e($site).'</h2><h1>Church Visitors Book</h1><p class="small">Generated '.date('d M Y, H:i').'</p>';
$html.='<div class="meta">'.($filter?$e(implode(' | ',$filter)):'All visitor records').'<span class="count">Records: '.count($rows).'</span></div>';
$html.='<table><thead><tr><th style="width:3%">#</th><th style="width:8%">Date</th><th style="width:14%">Visitor</th><th style="width:15%">Contact / Address</th><th style="width:8%">Type</th><th style="width:10%">Service</th><th style="width:10%">Invited By</th><th style="width:10%">Follow-up</th><th style="width:22%">Notes / Prayer Request</th></tr></thead><tbody>';
if(!$rows){$html.='<tr><td colspan="9" style="text-align:center">No visitor records found.</td></tr>';}
foreach($rows as $i=>$r){
 $visitor='<strong>'.$e($r['full_name']).'</strong>';if(!empty($r['gender']))$visitor.='<br><span class="small">'.$e($r['gender']).'</span>';if(!empty($r['member_no']))$visitor.='<br><span class="small">Member: '.$e($r['member_no']).'</span>';if((int)($r['self_registered']??0)===1)$visitor.='<br><span class="small">Self-registered</span>';
 $contact=$e($r['phone']?:'-');if(!empty($r['email']))$contact.='<br>'.$e($r['email']);if(!empty($r['address']))$contact.='<br>'.$e($r['address']);
 $followup=$e($r['follow_up_status']?:'-');if(!empty($r['assigned_to']))$followup.='<br><span class="small">Assigned: '.$e($r['assigned_to']).'</span>';
 $notes=$e($r['notes']?:'');if(!empty($r['prayer_request']))$notes.=($notes?'<br>':'').'<span class="small"><strong>Prayer:</strong> '.$e($r['prayer_request']).'</span>';
 $html.='<tr><td>'.($i+1).'</td><td>'.$e(date('d M Y',strtotime($r['visit_date']))).'</td><td>'.$visitor.'</td><td>'.$contact.'</td><td>'.$e($r['visitor_type']).'</td><td>'.$e($r['service_attended']?:'-').'</td><td>'.$e($r['invited_by']?:'-').'</td><td>'.$followup.'</td><td>'.$notes.'</td></tr>';
}
$html.='</tbody></table><div class="footer">'.$e($site).' · Church Visitors Book</div></body></html>';
$options=new Options();$options->set('isHtml5ParserEnabled',true);$options->set('defaultFont','DejaVu Sans');
$pdf=new Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4','landscape');$pdf->render();$pdf->stream('church-visitors-'.date('Y-m-d').'.pdf',['Attachment'=>true]);exit;
