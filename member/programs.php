<?php
declare(strict_types=1);
$pageTitle = 'Church Programs';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.programs.view');

$rows = mp_table($conn,'church_programs')
    ? mp_rows($conn,"SELECT * FROM church_programs WHERE status <> 'Cancelled' ORDER BY program_date ASC,start_time ASC")
    : [];
$today = date('Y-m-d');
$thisMonth = date('Y-m');
$stats = [
 ['label'=>'Total Programs','value'=>count($rows),'icon'=>'fa-calendar-days'],
 ['label'=>'Today','value'=>count(array_filter($rows,fn($r)=>(string)$r['program_date']===$today)),'icon'=>'fa-calendar-day'],
 ['label'=>'This Month','value'=>count(array_filter($rows,fn($r)=>str_starts_with((string)$r['program_date'],$thisMonth))),'icon'=>'fa-calendar'],
 ['label'=>'Scheduled','value'=>count(array_filter($rows,fn($r)=>strtolower((string)$r['status'])==='scheduled')),'icon'=>'fa-clock']
];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas fa-calendar-days"></i> Church Programs</h2><p>View upcoming and completed church programs.</p></div>
</div>
<div class="stats-grid module-stats-grid">
  <?php foreach ($stats as $stat): ?>
  <div class="stat-card">
    <div class="stat-icon"><i class="fas <?= mp_h($stat['icon']) ?>"></i></div>
    <div><strong class="stat-value"><?= !empty($stat['money']) ? 'UGX '.mp_money((float)$stat['value'],0) : number_format((float)$stat['value']) ?></strong><span class="stat-label"><?= mp_h($stat['label']) ?></span></div>
  </div>
  <?php endforeach; ?>
</div>
<div class="member-module-card">
  <div class="member-module-card-head"><h3><i class="fas fa-calendar-days"></i> Church Programs</h3><span class="member-module-count"><?= count($rows) ?> records</span></div>
  <div class="member-module-body">
    <div class="member-module-toolbar"><div class="member-module-search"><i class="fas fa-search"></i><input class="form-control" data-member-search placeholder="Search programs..."></div>
<select class="form-control" data-member-filter="status"><option value="">All Statuses</option><option>Scheduled</option><option>Completed</option></select>
<select class="form-control" data-member-filter="audience"><option value="">All Audiences</option><option>All Members</option><option>Selected Departments</option></select></div>
    <div class="member-module-table-wrap">
      <table class="member-module-table"><thead><tr><th>#</th><th>Program</th><th>Date</th><th>Time</th><th>Venue</th><th>Audience</th><th>Status</th><th>Action</th></tr></thead><tbody>
      <?php if(!$rows): ?><tr><td colspan="8"><div class="member-empty"><i class="fas fa-calendar-xmark"></i>No church programs are available.</div></td></tr><?php else: foreach($rows as $i=>$r):
$record=['title'=>$r['title']??'','date'=>mp_date($r['program_date']??''),'start_time'=>substr((string)($r['start_time']??''),0,5),'end_time'=>substr((string)($r['end_time']??''),0,5),'venue'=>$r['venue']??'','audience'=>$r['audience']??'All Members','status'=>$r['status']??'','description'=>$r['description']??''];
?><tr data-member-row data-status="<?=mp_h($r['status']??'')?>" data-audience="<?=mp_h($r['audience']??'All Members')?>">
<td><?=number_format($i+1)?></td><td><strong><?=mp_h($r['title']??'-')?></strong></td><td><?=mp_date($r['program_date']??'')?></td><td><?=mp_h(substr((string)($r['start_time']??''),0,5)?:'-')?></td><td><?=mp_h($r['venue']??'-')?></td><td><?=mp_h($r['audience']??'All Members')?></td><td><span class="member-status"><?=mp_h($r['status']??'-')?></span></td><td><button class="btn btn-info btn-sm btn-icon" data-member-view="<?=mp_h(json_encode($record))?>" data-title="Program Details"><i class="fas fa-eye"></i></button></td></tr><?php endforeach; endif; ?>
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
