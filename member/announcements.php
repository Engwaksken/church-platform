<?php
declare(strict_types=1);
$pageTitle = 'Announcements';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.announcements.view');

$rows = mp_table($conn,'church_announcements')
 ? mp_rows($conn,"SELECT * FROM church_announcements WHERE status='Published' AND (publish_from IS NULL OR publish_from<=NOW()) AND (publish_until IS NULL OR publish_until>=NOW()) ORDER BY FIELD(priority,'Urgent','Important','Normal'),publish_from DESC,id DESC")
 : (mp_table($conn,'announcements') ? mp_rows($conn,"SELECT * FROM announcements ORDER BY id DESC") : []);
$stats=[
 ['label'=>'Announcements','value'=>count($rows),'icon'=>'fa-bullhorn'],
 ['label'=>'Urgent','value'=>count(array_filter($rows,fn($r)=>strtolower((string)($r['priority']??''))==='urgent')),'icon'=>'fa-triangle-exclamation'],
 ['label'=>'Important','value'=>count(array_filter($rows,fn($r)=>strtolower((string)($r['priority']??''))==='important')),'icon'=>'fa-circle-info'],
 ['label'=>'Published','value'=>count(array_filter($rows,fn($r)=>strtolower((string)($r['status']??'published'))==='published')),'icon'=>'fa-eye']
];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas fa-bullhorn"></i> Announcements</h2><p>Read current church notices and important updates.</p></div>
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
  <div class="member-module-card-head"><h3><i class="fas fa-bullhorn"></i> Announcements</h3><span class="member-module-count"><?= count($rows) ?> records</span></div>
  <div class="member-module-body">
    <div class="member-module-toolbar"><div class="member-module-search"><i class="fas fa-search"></i><input class="form-control" data-member-search placeholder="Search announcements..."></div>
<select class="form-control" data-member-filter="priority"><option value="">All Priorities</option><option>Normal</option><option>Important</option><option>Urgent</option></select>
<select class="form-control" data-member-filter="audience"><option value="">All Audiences</option><option>All Members</option><option>Selected Departments</option></select></div>
    <div class="member-module-table-wrap">
      <table class="member-module-table"><thead><tr><th>#</th><th>Title</th><th>Message</th><th>Priority</th><th>Audience</th><th>Published</th><th>Action</th></tr></thead><tbody>
      <?php if(!$rows):?><tr><td colspan="7"><div class="member-empty"><i class="fas fa-bell-slash"></i>No announcements are currently available.</div></td></tr><?php else:foreach($rows as $i=>$r):
$title=$r['title']??$r['announcement_title']??'Announcement';$message=$r['message']??$r['description']??'';$priority=$r['priority']??'Normal';$audience=$r['audience_type']??$r['audience']??'All Members';$from=$r['publish_from']??$r['created_at']??'';
$record=['title'=>$title,'message'=>$message,'priority'=>$priority,'audience'=>$audience,'published'=>mp_date($from),'expires'=>mp_date($r['publish_until']??'')];
?><tr data-member-row data-priority="<?=mp_h($priority)?>" data-audience="<?=mp_h($audience)?>"><td><?=number_format($i+1)?></td><td><strong><?=mp_h($title)?></strong></td><td><?=mp_h(mb_strimwidth($message,0,100,'…'))?></td><td><span class="member-status <?=strtolower($priority)==='urgent'?'danger':(strtolower($priority)==='important'?'warning':'')?>"><?=mp_h($priority)?></span></td><td><?=mp_h($audience)?></td><td><?=mp_date($from)?></td><td><button class="btn btn-info btn-sm btn-icon" data-member-view="<?=mp_h(json_encode($record))?>" data-title="Announcement"><i class="fas fa-eye"></i></button></td></tr><?php endforeach;endif;?>
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
