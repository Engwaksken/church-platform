<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once '../includes/member-content-functions.php';
requireLogin();

$departments = mc_tip_departments();
$manageable = mc_manageable_departments();
$dept = (string)($_GET['dept'] ?? ($manageable[0] ?? 'health'));
if (!mc_valid_department($dept)) $dept = $manageable[0] ?? 'health';
// Leaders only see and manage their own department's tips.
require_permission($departments[$dept]['permission']);

$pageTitle = $departments[$dept]['short'];
require_once '../includes/header.php';
require_once '../includes/sidebar.php';
require_once '../church_admin/common.php';

$ready = mc_tables_ready($conn, ['department_tips']);
$rows = [];
if ($ready) {
    $stmt = $conn->prepare(
        "SELECT t.*, COALESCE(NULLIF(u.full_name,''), u.username, 'Unknown') AS author_name
         FROM department_tips t LEFT JOIN users u ON u.id=t.created_by
         WHERE t.department=?
         ORDER BY COALESCE(t.published_at, t.updated_at) DESC, t.id DESC"
    );
    $stmt->bind_param('s', $dept);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$publishedCount = count(array_filter($rows, fn($r) => $r['status'] === 'Published'));
$recordFlags = JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP;
?>
<link rel="stylesheet" href="../assets/css/church-admin.css"><link rel="stylesheet" href="../assets/css/church-manager.css">
<div class="main-content"><?php include '../includes/topbar.php'; ?><div class="content-area">
<?php ce_show_flash(); ?>
<div class="church-page-head">
    <div><h2><i class="fas <?= ce_h($departments[$dept]['icon']) ?>"></i> <?= ce_h($departments[$dept]['label']) ?> Tips</h2><p>Write tips for members. Only published tips are visible in the member portal.</p></div>
    <?php if ($ready): ?><button type="button" class="btn btn-primary" id="addBtn"><i class="fas fa-plus"></i> Add Tip</button><?php endif; ?>
</div>

<?php if (count($manageable) > 1): ?>
<div class="mc-tabs">
    <?php foreach ($manageable as $key): ?>
        <a class="<?= $key === $dept ? 'active' : '' ?>" href="?dept=<?= ce_h($key) ?>"><i class="fas <?= ce_h($departments[$key]['icon']) ?>"></i> <?= ce_h($departments[$key]['short']) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$ready): ?>
<div class="alert alert-warning"><strong>Setup required.</strong> Run <code>database/member_content.sql</code> to create the department tips table.</div>
<?php else: ?>
<div class="church-stats">
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-list"></i></div><div><b><?= count($rows) ?></b><span>Total Tips</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-eye"></i></div><div><b><?= $publishedCount ?></b><span>Published</span></div></div>
    <div class="church-stat"><div class="church-stat-icon"><i class="fas fa-pen"></i></div><div><b><?= count($rows) - $publishedCount ?></b><span>Drafts</span></div></div>
</div>
<div class="church-card">
    <div class="page-toolbar">
        <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="search" id="recordSearch" class="form-control" placeholder="Search tips..."></div>
        <div class="page-toolbar-actions"><button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="refreshRecords"><i class="fas fa-rotate-right"></i> Refresh</button></div>
    </div>
    <div class="church-table-wrap"><table class="church-table">
        <thead><tr><th>Title</th><th>Author</th><th>Status</th><th>Published</th><th>Last Updated</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $published = $r['status'] === 'Published';
            $record = ['id' => (int)$r['id'], 'title' => $r['title'], 'content' => $r['content'], 'status' => $r['status'], 'author' => $r['author_name'], 'published' => $published ? mc_format_date($r['published_at']) : '-'];
        ?>
            <tr data-search="<?= ce_h(mb_strtolower($r['title'] . ' ' . $r['content'] . ' ' . $r['author_name'])) ?>">
                <td class="wrap"><b><?= ce_h($r['title']) ?></b><br><small><?= ce_h(mb_strimwidth($r['content'], 0, 110, '…')) ?></small></td>
                <td><?= ce_h($r['author_name']) ?></td>
                <td><span class="church-badge <?= $published ? 'church-badge-success' : 'church-badge-warning' ?>"><?= ce_h($r['status']) ?></span></td>
                <td><?= $published ? ce_h(mc_format_date($r['published_at'])) : '-' ?></td>
                <td><?= ce_h(mc_format_date($r['updated_at'])) ?></td>
                <td><div class="church-table-actions">
                    <button type="button" class="btn btn-info btn-sm js-view" title="View" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-eye"></i></button>
                    <button type="button" class="btn btn-primary btn-sm js-edit" title="Edit" data-record='<?= ce_h(json_encode($record, $recordFlags)) ?>'><i class="fas fa-edit"></i></button>
                    <form method="post" action="process.php" class="mc-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="set_tip_status"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <input type="hidden" name="status" value="<?= $published ? 'Draft' : 'Published' ?>">
                        <button type="submit" class="btn btn-sm <?= $published ? 'btn-warning' : 'btn-success' ?>" title="<?= $published ? 'Unpublish' : 'Publish' ?>"><i class="fas <?= $published ? 'fa-eye-slash' : 'fa-upload' ?>"></i></button>
                    </form>
                    <button type="button" class="btn btn-danger btn-sm js-delete" title="Delete" data-id="<?= (int)$r['id'] ?>" data-name="<?= ce_h($r['title']) ?>"><i class="fas fa-trash"></i></button>
                </div></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="church-empty">No tips have been written yet.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="church-modal-backdrop" id="backdrop"></div>
<div class="church-modal" id="formModal"><div class="church-modal-dialog church-modal-dialog-lg"><form method="post" action="process.php" id="recordForm">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="save_tip">
    <input type="hidden" name="department" value="<?= ce_h($dept) ?>"><input type="hidden" name="id" id="recordId">
    <div class="church-modal-header"><h3 id="formTitle">Add Tip</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body"><div class="church-form">
        <label>Department<input value="<?= ce_h($departments[$dept]['label']) ?>" disabled></label>
        <label>Title<input name="title" id="title" maxlength="200" required></label>
        <label>Tip Content<textarea name="content" id="content" rows="10" maxlength="20000" required></textarea></label>
        <label>Status<select name="status" id="status"><option value="Draft">Draft (not visible to members)</option><option value="Published">Published</option></select></label>
    </div></div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Tip</button></div>
</form></div></div>
<div class="church-modal" id="viewModal"><div class="church-modal-dialog"><div class="church-modal-header"><h3>Tip Details</h3><button type="button" class="church-modal-close js-close">&times;</button></div><div class="church-modal-body"><div class="modal-detail-grid" id="details"></div></div><div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Close</button></div></div></div>
<div class="church-modal" id="deleteModal"><div class="church-modal-dialog church-modal-dialog-sm"><form method="post" action="process.php">
    <input type="hidden" name="csrf_token" value="<?= ce_h(ce_csrf()) ?>"><input type="hidden" name="action" value="delete_tip"><input type="hidden" name="id" id="deleteId">
    <div class="church-modal-header"><h3>Delete Tip</h3><button type="button" class="church-modal-close js-close">&times;</button></div>
    <div class="church-modal-body">Are you sure you want to delete <strong id="deleteName"></strong>?</div>
    <div class="church-modal-footer"><button type="button" class="btn btn-outline js-close">Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
</form></div></div>
<?php include '../includes/member-content-staff-assets.php'; ?>
<script>
mcBindTable({
    addTitle: 'Add Tip', editTitle: 'Edit Tip', fields: ['title', 'content', 'status'],
    view: r => ({'Department': <?= json_encode($departments[$dept]['label']) ?>, 'Title': r.title, 'Author': r.author, 'Status': r.status, 'Published': r.published, 'Content': r.content})
});
</script>
</div></div><?php require_once '../includes/footer.php'; ?>
