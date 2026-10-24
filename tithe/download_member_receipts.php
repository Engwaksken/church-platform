<?php
require_once '../config/config.php'; requireLogin();
$member_id=(int)($_GET['member_id']??0); $year=(int)($_GET['year']??date('Y')); $month=(int)($_GET['month']??0);
if($member_id<=0) die('Invalid member.');
$where='WHERE t.member_id=? AND YEAR(t.tithe_date)=?'; $types='ii'; $params=[$member_id,$year]; if($month>0){$where.=' AND MONTH(t.tithe_date)=?';$types.='i';$params[]=$month;}
$st=$conn->prepare("SELECT t.*, m.full_name, m.member_no FROM tithe t LEFT JOIN members m ON m.id=t.member_id $where ORDER BY t.tithe_date DESC"); $st->bind_param($types,...$params); $st->execute(); $res=$st->get_result();
header('Content-Type: application/vnd.ms-excel'); header('Content-Disposition: attachment; filename="member-tithe-receipts-'.date('YmdHis').'.xls"');
echo '<table border="1"><tr><th colspan="6">Member Tithe Receipts</th></tr><tr><th>Receipt No</th><th>Member</th><th>Amount</th><th>Period</th><th>Date</th><th>Method</th></tr>'; while($r=$res->fetch_assoc()){ echo '<tr><td>'.htmlspecialchars($r['receipt_no']).'</td><td>'.htmlspecialchars(trim($r['full_name'])).'</td><td>'.number_format((float)$r['amount']).'</td><td>'.htmlspecialchars($r['period_month'].'/'.$r['period_year']).'</td><td>'.htmlspecialchars($r['tithe_date']).'</td><td>'.htmlspecialchars($r['payment_method']).'</td></tr>'; } echo '</table>';
