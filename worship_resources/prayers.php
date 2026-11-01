<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/member-content-functions.php';
requireLogin();
require_permission('prayers.manage');

$pageTitle = 'Church Prayers';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';

$ready = mc_tables_ready($conn, ['church_prayers']);
$rows = $ready ? ce_query_all($conn, 'SELECT * FROM church_prayers ORDER BY FIELD(status,"Published","Draft"), prayer_category, title') : [];
$publishedCount = count(array_filter($rows, fn($r) => $r['status'] === 'Published'));
$recordFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<link rel="stylesheet" href="../assets/css/church-admin.css"><link rel="stylesheet" href="../assets/css/church-manager.css">
<div class="main-content"><div class="content-area">
<?php ce_show_flash(); ?>
<div class="church-page-head">
    <div><h2><i class="fas fa-praying-hands"></i> Church Prayers</h2><p>Prepare prayer guides for members to use in daily devotion and church life.</p></div>
    <?php if ($ready): ?><button type="button" class="btn btn-primary" id="addBtn"><i class="fas fa-plus"></i> Add Prayer</button><?php endif; ?>
</div>
<?php mc_worship_tabs('prayers'); ?>
<?php if (!$ready): ?>
<div class="alert alert-warning"><strong>Setup required.</strong> Run <code>database/member_content.sql</code> to create the Church Prayers table.</div>
<?php else: ?>
<div class="church-stats">
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-praying-hands"></i></div><div><b><?= count($rows) ?></b><span>Total Prayers</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-eye"></i></div><div><b><?= $publishedCount ?></b><span>Published</span></div></div>
</div>
<div class="church-card"><div class="page-toolbar">
    <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="recordSearch" class="form-control" placeholder="Search prayers..."></div>
    <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="refreshRecords"><i class="fas fa-rotate-right"></i> Refresh</button></div>
</div><div class="church-table-wrap"><table class="church-table">
    <thead><tr><th>Prayer</th><th>Category</th><th>Scripture</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($rows as $r): $published = $r['status'] === 'Published'; $record = ['id'=>(int)$r['id'],'title'=>(string)$r['title'],'prayer_category'=>(string)($r['prayer_category']??''),'prayer_text'=>(string)$r['prayer_text'],'scripture_reference'=>(string)($r['scripture_reference']??''),'status'=>(string)$r['status']]; ?>
    <tr data-search="<?= ce_h(mb_strtolower(implode(' ', [$record['title'],$record['prayer_category'],$record['prayer_text'],$record['scripture_reference']]))) ?>">
        <td class="wrap"><b><?= ce_h($r['title']) ?></b><br><small><?= ce_h(mb_strimwidth((string)$r['prayer_text'],0,110,'…')) ?></small></td>
        <td><?= ce_h($r['prayer_category'] ?: 'General') ?></td><td><?= ce_h($r['scripture_reference'] ?: '—') ?></td>
        <td><span class="church-badge <?= $published?'church-badge-success':'church-badge-warning' ?>"><?= ce_h($r['status']) ?></span></td>
        <td><div class="church-table-actions">
            <button type="button" class="btn btn-info btn-sm js-view" title="View" data-record='<?= ce_h(json_encode($record,$recordFlags)) ?>'><i class="fas fa-eye"></i></button>
            <button type="button" class="btn btn-primary btn-sm js-edit" title="Edit" data-record='<?= ce_h(json_encode($record,$recordFlags)) ?>'><i class="fas fa-edit"></i></button>
            <form method="post" action="process.php" class="mc-inline-form"><input type="hidden" name="csrf_token" value="<?=ce_h(ce_csrf())?>"><input type="hidden" name="action" value="set_status"><input type="hidden" name="resource" value="prayers"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input type="hidden" name="status" value="<?=$published?'Draft':'Published'?>"><button type="submit" class="btn btn-sm <?=$published?'btn-warning':'btn-success'?>" title="<?=$published?'Unpublish':'Publish'?>"><i class="fas <?=$published?'fa-eye-slash':'fa-upload'?>"></i></button></form>
            <button type="button" class="btn btn-danger btn-sm js-delete" title="Delete" data-id="<?=(int)$r['id']?>" data-name="<?=ce_h($r['title'])?>"><i class="fas fa-trash"></i></button>
        </div></td>
    </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="5" class="church-empty">No prayers have been added yet.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<div class="church-modal-backdrop" id="backdrop"></div>
<div class="church-modal" id="formModal"><div class="church-modal-dialog church-modal-dialog-lg"><form method="post" action="process.php" id="recordForm">
    <input type="hidden" name="csrf_token" value="<?=ce_h(ce_csrf())?>"><input type="hidden" name="action" value="save"><input type="hidden" name="resource" value="prayers"><input type="hidden" name="id" id="recordId">
    <div class="church-modal-header"><h3 id="formTitle">Add Prayer</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body"><div class="church-form">
        <div class="church-form-row"><label>Prayer title<input name="title" id="title" maxlength="180" required></label><label>Category<input name="prayer_category" id="prayer_category" maxlength="80" placeholder="Morning, Evening, Church, Family..."></label></div>
        <label>Prayer text<textarea name="prayer_text" id="prayer_text" rows="8" maxlength="12000" required></textarea></label>
        <div class="church-form-row"><label>Scripture reference<input name="scripture_reference" id="scripture_reference" maxlength="180" placeholder="e.g. Psalm 23"></label><label>Status<select name="status" id="status"><option value="Draft">Draft</option><option value="Published">Published</option></select></label></div>
    </div></div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Prayer</button></div>
</form></div></div>
<div class="church-modal" id="viewModal"><div class="church-modal-dialog church-modal-dialog-lg"><div class="church-modal-header"><h3>Prayer Details</h3><button type="button" class="church-modal-close js-close">&times;</button></div><div class="church-modal-body"><div class="modal-detail-grid" id="details"></div></div><div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Close</button></div></div></div>
<div class="church-modal" id="deleteModal"><div class="church-modal-dialog church-modal-dialog-sm"><form method="post" action="process.php"><input type="hidden" name="csrf_token" value="<?=ce_h(ce_csrf())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="resource" value="prayers"><input type="hidden" name="id" id="deleteId"><div class="church-modal-header"><h3>Delete Prayer</h3><button type="button" class="church-modal-close js-close">&times;</button></div><div class="church-modal-body">Delete <strong id="deleteName"></strong>?</div><div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div></form></div></div>
<?php include '../includes/member-content-staff-assets.php'; ?>
<script>mcBindTable({addTitle:'Add Prayer',editTitle:'Edit Prayer',fields:['title','prayer_category','prayer_text','scripture_reference','status'],view:r=>({'Title':r.title,'Category':r.prayer_category||'General','Scripture':r.scripture_reference||'—','Status':r.status,'Prayer':r.prayer_text})});</script>
</div></div><?php require_once '../includes/footer.php'; ?>
