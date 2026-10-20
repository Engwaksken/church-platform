<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/church-book-functions.php';
require_once 'report-data.php';
requireLogin();
cb_require_permission('converts.view');
if (!isset($conn) || !($conn instanceof mysqli)) die('Database connection not found.');
$conn->set_charset('utf8mb4');
$rows = cn_report_rows($conn);
$site = cb_site_setting($conn, 'site_name', 'Church Management System');
$filter = cn_report_filter_text($conn);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Converts & Nurture Register</title><style>
body{font-family:Arial,sans-serif;color:#172033;margin:24px;font-size:12px}h1,h2,p{text-align:center;margin:0}h1{margin-top:4px}.meta{margin:14px 0;padding:9px;border:1px solid #ccc;background:#f7f7f7}.actions{text-align:right;margin-bottom:12px}button{padding:8px 12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #999;padding:6px;vertical-align:top}th{background:#eee;text-align:left;font-size:10px;text-transform:uppercase}.small{font-size:10px;color:#555}@media print{.actions{display:none}body{margin:8mm}@page{size:A4 landscape;margin:8mm}}
</style></head><body><div class="actions"><button onclick="window.print()">Print</button></div><h2><?= cb_h($site) ?></h2><h1>Converts & Nurture Register</h1><p><?= cb_h($filter) ?> · <?= count($rows) ?> record(s)</p><br>
<table><thead><tr><th>#</th><th>Date</th><th>Convert</th><th>Type</th><th>Decision</th><th>Assigned To</th><th>Nurture Period</th><th>Next Follow-up</th><th>Status</th><th>Follow-ups</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="10" style="text-align:center">No records found.</td></tr><?php endif;?>
<?php foreach($rows as $i=>$r):?><tr><td><?= $i+1 ?></td><td><?= cb_h(date('d M Y',strtotime($r['conversion_date']))) ?></td><td><strong><?= cb_h($r['full_name']) ?></strong><div class="small"><?= cb_h($r['phone']?:$r['email']?:'-') ?></div></td><td><?= cb_h($r['person_type']) ?></td><td><?= cb_h($r['decision_type']) ?><div class="small"><?= cb_h($r['conversion_context']?:'') ?></div></td><td><?= cb_h($r['assigned_member_name']?:'Pending Assignment') ?></td><td><?= (int)$r['nurture_period_weeks'] ?> weeks<div class="small"><?= cb_h($r['nurture_start_date']?:'-') ?> to <?= cb_h($r['nurture_end_date']?:'-') ?></div></td><td><?= cb_h($r['next_follow_up_date']?:'-') ?></td><td><?= cb_h($r['status']) ?></td><td><?= (int)$r['followup_count'] ?></td></tr><?php endforeach;?>
</tbody></table></body></html>
