<?php
declare(strict_types=1);
$pageTitle = 'Annual Church Theme';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.theme.view');

$rows = mp_table($conn,'church_annual_themes')
 ? mp_rows($conn,"SELECT * FROM church_annual_themes WHERE is_active=1 ORDER BY theme_year DESC")
 : [];
$stats=[
 ['label'=>'Published Themes','value'=>count($rows),'icon'=>'fa-cross'],
 ['label'=>'Current Year','value'=>date('Y'),'icon'=>'fa-calendar'],
 ['label'=>'Languages','value'=>count(array_filter($rows,fn($r)=>!empty($r['theme_local']))),'icon'=>'fa-language'],
 ['label'=>'Active','value'=>count($rows),'icon'=>'fa-circle-check']
];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas fa-cross"></i> Annual Church Theme</h2><p>View the annual church theme in English and the local language.</p></div>
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
  <div class="member-module-card-head"><h3><i class="fas fa-cross"></i> Annual Church Theme</h3><span class="member-module-count"><?= count($rows) ?> records</span></div>
  <div class="member-module-body">
    <div class="member-module-toolbar"><div class="member-module-search"><i class="fas fa-search"></i><input class="form-control" data-member-search placeholder="Search annual themes..."></div>
<select class="form-control" data-member-filter="year"><option value="">All Years</option><?php foreach(array_unique(array_column($rows,'theme_year')) as $y):?><option><?=mp_h($y)?></option><?php endforeach;?></select></div>
    <div class="member-module-table-wrap">
      <table class="member-module-table"><thead><tr><th>#</th><th>Year</th><th>English Theme</th><th>Local Theme</th><th>Language</th><th>Scripture</th><th>Action</th></tr></thead><tbody>
      <?php if(!$rows):?><tr><td colspan="7"><div class="member-empty"><i class="fas fa-cross"></i>No annual church theme has been published.</div></td></tr><?php else:foreach($rows as $i=>$r):
$record=['year'=>$r['theme_year']??'','theme_english'=>$r['theme_english']??$r['theme_title']??'','local_language'=>$r['local_language_name']??'','theme_local'=>$r['theme_local']??'','scripture'=>$r['scripture_reference']??'','explanation'=>$r['explanation']??''];
?><tr data-member-row data-year="<?=mp_h($r['theme_year']??'')?>"><td><?=number_format($i+1)?></td><td><strong><?=mp_h($r['theme_year']??'-')?></strong></td><td><?=mp_h($r['theme_english']??$r['theme_title']??'-')?></td><td><?=mp_h($r['theme_local']??'-')?></td><td><?=mp_h($r['local_language_name']??'-')?></td><td><?=mp_h($r['scripture_reference']??'-')?></td><td><button class="btn btn-info btn-sm btn-icon" data-member-view="<?=mp_h(json_encode($record))?>" data-title="Annual Church Theme"><i class="fas fa-eye"></i></button></td></tr><?php endforeach;endif;?>
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
