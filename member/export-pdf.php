<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/header.php';
require_once __DIR__.'/_helpers.php';
require_permission('member.dashboard.view');
$token=(string)($_GET['token']??'');$report=$_SESSION['mp_pdf'][$token]??null;
if(!$report||($report['created']??0)<time()-1800){http_response_code(404);exit('The PDF report has expired. Return to the page and export again.');}
$autoloads=[__DIR__.'/../vendor/autoload.php',__DIR__.'/../../vendor/autoload.php'];$loaded=false;foreach($autoloads as $a){if(is_file($a)){require_once $a;$loaded=true;break;}}
if(!$loaded||!class_exists(\Dompdf\Dompdf::class)){http_response_code(500);exit('DOMPDF autoload was not found. Install dompdf/dompdf in the project vendor directory.');}
$title=(string)$report['title'];$rows=(array)$report['rows'];$labels=(array)($report['labels']??[]);$keys=$rows?array_keys($rows[0]):[];
$html='<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#222}h1{font-size:18px;margin:0 0 4px}.meta{color:#666;margin-bottom:14px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ccc;padding:6px;text-align:left}th{background:#4b1f91;color:#fff}tr:nth-child(even){background:#f6f6f6}</style></head><body><h1>'.mp_h($title).'</h1><div class="meta">Generated '.date('d M Y H:i').'</div>';
if(!$rows){$html.='<p>No records found.</p>';}else{$html.='<table><thead><tr>';foreach($keys as $k)$html.='<th>'.mp_h($labels[$k]??ucwords(str_replace('_',' ',$k))).'</th>';$html.='</tr></thead><tbody>';foreach($rows as $row){$html.='<tr>';foreach($keys as $k)$html.='<td>'.mp_h($row[$k]??'-').'</td>';$html.='</tr>';}$html.='</tbody></table>';}$html.='</body></html>';
$dompdf=new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);$dompdf->loadHtml($html,'UTF-8');$dompdf->setPaper('A4','landscape');$dompdf->render();$dompdf->stream(preg_replace('/[^A-Za-z0-9_-]+/','_',strtolower($title)).'.pdf',['Attachment'=>true]);exit;
