<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/member-content-functions.php';
$departments = mc_tip_departments();
$dept = (string)($_GET['dept'] ?? 'health');
if (!mc_valid_department($dept)) $dept = 'health';
$pageTitle = $departments[$dept]['short'];

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.tips.view');

// Members only ever see published tips.
$rows = mp_table($conn, 'department_tips')
 ? mp_rows($conn, "SELECT t.title, t.content, t.published_at, COALESCE(NULLIF(u.full_name,''), u.username, 'Church Leader') AS author_name
                   FROM department_tips t LEFT JOIN users u ON u.id=t.created_by
                   WHERE t.department=? AND t.status='Published'
                   ORDER BY t.published_at DESC, t.id DESC", 's', [$dept])
 : [];

require_once __DIR__ . '/../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= mp_h(BASE_URL . 'assets/css/member-portal.css') ?>">
<div class="main-content">
<?php require_once __DIR__ . '/../includes/topbar.php'; ?>
<div class="content-area member-module-page">
<div class="member-module-header">
  <div><h2><i class="fas <?= mp_h($departments[$dept]['icon']) ?>"></i> <?= mp_h($departments[$dept]['label']) ?> Tips</h2><p>Practical guidance shared by church department leaders.</p></div>
</div>
<div class="member-tabbar">
  <?php foreach ($departments as $key => $d): ?>
  <a class="<?= $key === $dept ? 'active' : '' ?>" href="?dept=<?= mp_h($key) ?>"><i class="fas <?= mp_h($d['icon']) ?>"></i> <?= mp_h($d['short']) ?></a>
  <?php endforeach; ?>
</div>
<div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" data-member-search placeholder="Search tips..." aria-label="Search tips"></div><div class="page-toolbar-actions"><a class="btn btn-outline-primary page-toolbar-refresh" href="tips.php?dept=<?= mp_h($dept) ?>"><i class="fas fa-rotate-right"></i> Refresh</a></div></div>
<div class="member-module-card">
  <div class="member-module-card-head"><h3><i class="fas <?= mp_h($departments[$dept]['icon']) ?>"></i> <?= mp_h($departments[$dept]['short']) ?></h3><span class="member-module-count"><?= count($rows) ?> tips</span></div>
  <div class="member-module-body">
    <?php if (!$rows): ?>
      <div class="member-empty"><i class="fas fa-lightbulb"></i>No <?= mp_h(strtolower($departments[$dept]['short'])) ?> have been published yet.</div>
    <?php else: ?>
      <div class="member-note-list">
        <?php foreach ($rows as $r): ?>
        <article class="member-note-card" data-member-row>
          <h4><?= mp_h($r['title']) ?></h4>
          <div class="member-note-meta"><i class="fas fa-sitemap"></i> <?= mp_h($departments[$dept]['label']) ?> &middot; <i class="fas fa-user"></i> <?= mp_h($r['author_name']) ?> &middot; <i class="fas fa-calendar"></i> <?= mp_h(mp_date($r['published_at'])) ?></div>
          <div class="member-note-text"><?= mp_h($r['content']) ?></div>
        </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</div>
</div>
<script src="<?= mp_h(BASE_URL . 'assets/js/member-portal.js') ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
