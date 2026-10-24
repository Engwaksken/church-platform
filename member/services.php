<?php
declare(strict_types=1);
$pageTitle = 'Live & Recorded Services';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.services.view');

$rows = [];
if (mp_table($conn, 'church_services')) {
    $serviceColumns = mp_cols($conn, 'church_services');
    $serviceWhere = in_array('status', $serviceColumns, true) ? " WHERE status <> 'Cancelled'" : '';
    $serviceOrder = [];
    foreach (['service_date', 'service_time', 'id'] as $column) if (in_array($column, $serviceColumns, true)) $serviceOrder[] = '`' . $column . '` DESC';
    $rows = mp_rows($conn, 'SELECT * FROM church_services' . $serviceWhere . ($serviceOrder ? ' ORDER BY ' . implode(', ', $serviceOrder) : ''));
}
$stats = [
 ['label'=>'Total Services','value'=>count($rows),'icon'=>'fa-video'],
 ['label'=>'Live','value'=>count(array_filter($rows,fn($r)=>strtolower((string)($r['status']??''))==='live')),'icon'=>'fa-tower-broadcast'],
 ['label'=>'Upcoming','value'=>count(array_filter($rows,fn($r)=>strtolower((string)($r['status']??''))==='upcoming')),'icon'=>'fa-clock'],
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
<div class="stats-grid module-stats-grid">
  <?php foreach ($stats as $stat): ?>
  <div class="stat-card">
    <div class="stat-icon"><i class="fas <?= mp_h($stat['icon']) ?>"></i></div>
    <div><strong class="stat-value"><?= !empty($stat['money']) ? 'UGX '.mp_money((float)$stat['value'],0) : number_format((float)$stat['value']) ?></strong><span class="stat-label"><?= mp_h($stat['label']) ?></span></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" data-member-search placeholder="Search services..." aria-label="Search services"></div><div class="page-toolbar-filters"><select data-member-filter="status" aria-label="Status"><option value="">All Statuses</option><option>Upcoming</option><option>Live</option><option>Completed</option></select><select data-member-filter="platform" aria-label="Platform"><option value="">All Platforms</option><option>Zoom</option><option>Google Meet</option><option>YouTube</option><option>Facebook</option></select></div><div class="page-toolbar-actions"><a class="btn btn-outline-primary page-toolbar-refresh" href="services.php"><i class="fas fa-rotate-right"></i> Refresh</a></div></div>
<div class="member-module-card">
  <div class="member-module-card-head"><h3><i class="fas fa-video"></i> Live & Recorded Services</h3><span class="member-module-count"><?= count($rows) ?> records</span></div>
  <div class="member-module-body">
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
