<?php
declare(strict_types=1);
$pageTitle = 'Live & Recorded Services';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.services.view');

$rows = mp_table($conn,'church_services')
    ? mp_rows($conn,"SELECT * FROM church_services WHERE status <> 'Cancelled' ORDER BY service_date DESC,service_time DESC")
    : [];
$stats = [
 ['label'=>'Total Services','value'=>count($rows),'icon'=>'fa-video'],
 ['label'=>'Live','value'=>count(array_filter($rows,fn($r)=>strtolower((string)$r['status'])==='live')),'icon'=>'fa-tower-broadcast'],
 ['label'=>'Upcoming','value'=>count(array_filter($rows,fn($r)=>strtolower((string)$r['status'])==='upcoming')),'icon'=>'fa-clock'],
 ['label'=>'Recorded','value'=>count(array_filter($rows,fn($r)=>!empty($r['recording_link'])||!empty($r['recording_file']))),'icon'=>'fa-circle-play']
];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas fa-video"></i> Live & Recorded Services</h2><p>Join live services and watch available recordings.</p></div>
</div>
<div class="member-module-stats">
  <?php foreach ($stats as $stat): ?>
  <div class="member-module-stat">
    <div class="icon"><i class="fas <?= mp_h($stat['icon']) ?>"></i></div>
    <div><strong><?= !empty($stat['money']) ? 'UGX '.mp_money((float)$stat['value'],0) : number_format((float)$stat['value']) ?></strong><span><?= mp_h($stat['label']) ?></span></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="member-module-card">
  <div class="member-module-card-head"><h3><i class="fas fa-video"></i> Live & Recorded Services</h3><span class="member-module-count"><?= count($rows) ?> records</span></div>
  <div class="member-module-body">
    <div class="member-module-toolbar"><div class="member-module-search"><i class="fas fa-search"></i><input class="form-control" data-member-search placeholder="Search services..."></div>
<select class="form-control" data-member-filter="status"><option value="">All Statuses</option><option>Upcoming</option><option>Live</option><option>Completed</option></select>
<select class="form-control" data-member-filter="platform"><option value="">All Platforms</option><option>Zoom</option><option>Google Meet</option><option>YouTube</option><option>Facebook</option></select></div>
    <div class="member-module-table-wrap">
      <table class="member-module-table"><thead><tr><th>#</th><th>Service</th><th>Date</th><th>Time</th><th>Preacher</th><th>Platform</th><th>Status</th><th>Attend</th><th>Details</th></tr></thead><tbody>
      <?php if(!$rows): ?><tr><td colspan="9"><div class="member-empty"><i class="fas fa-video-slash"></i>No services are available.</div></td></tr><?php else: foreach($rows as $i=>$r):
$record=['service'=>$r['service_title']??'','date'=>mp_date($r['service_date']??''),'time'=>substr((string)($r['service_time']??''),0,5),'preacher'=>$r['preacher']??'','theme'=>$r['theme']??'','readings'=>$r['readings']??'','lessons_learnt'=>$r['lessons_learnt']??'','recommendation_message'=>$r['recommendation_message']??'','platform'=>$r['platform']??'','status'=>$r['status']??''];
?><tr data-member-row data-status="<?=mp_h($r['status']??'')?>" data-platform="<?=mp_h($r['platform']??'')?>">
<td><?=number_format($i+1)?></td><td><strong><?=mp_h($r['service_title']??'-')?></strong></td><td><?=mp_date($r['service_date']??'')?></td><td><?=mp_h(substr((string)($r['service_time']??''),0,5)?:'-')?></td><td><?=mp_h($r['preacher']??'-')?></td><td><?=mp_h($r['platform']??'-')?></td><td><span class="member-status"><?=mp_h($r['status']??'-')?></span></td><td><?php if(!empty($r['live_link'])):?><a class="btn btn-success btn-sm" target="_blank" rel="noopener" href="<?=mp_h($r['live_link'])?>"><i class="fas fa-video"></i> Join</a><?php elseif(!empty($r['recording_link'])):?><a class="btn btn-primary btn-sm" target="_blank" rel="noopener" href="<?=mp_h($r['recording_link'])?>"><i class="fas fa-play"></i> Watch</a><?php else:?>-<?php endif;?></td><td><button class="btn btn-info btn-sm btn-icon" data-member-view="<?=mp_h(json_encode($record))?>" data-title="Service Details"><i class="fas fa-eye"></i></button></td></tr><?php endforeach; endif; ?>
      </tbody></table>
    </div>
  </div>
</div>
</div>
</div>

<div class="member-display-modal" id="memberViewModal" aria-hidden="true">
  <div class="member-display-dialog">
    <div class="member-display-modal-head">
      <h3 id="memberViewTitle">Details</h3>
      <button type="button" class="member-display-close" data-close-member-modal>&times;</button>
    </div>
    <div class="member-display-modal-body">
      <div class="member-detail-grid" id="memberViewContent"></div>
    </div>
    <div class="member-display-modal-foot">
      <button type="button" class="btn btn-outline" data-close-member-modal>Close</button>
    </div>
  </div>
</div>
<div class="member-modal-backdrop" id="memberDisplayBackdrop"></div>
<script src="<?= mp_h(BASE_URL . 'assets/js/member-portal.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
