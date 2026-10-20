<?php
$pageTitle = 'Departments';
require_once '../includes/header.php';
require_once '../includes/sidebar.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function dh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function d_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function d_scalar(mysqli $conn, string $sql): int {
    try {
        $res = $conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row(); $res->close();
        return (int)($row[0] ?? 0);
    } catch (Throwable $e) { return 0; }
}

$hasDepartments  = d_table_exists($conn, 'departments');
$hasMembers      = d_table_exists($conn, 'members');
$hasDeptMembers  = d_table_exists($conn, 'department_members');

$totalDepartments   = $hasDepartments ? d_scalar($conn, "SELECT COUNT(*) FROM departments") : 0;
$activeDepartments  = $hasDepartments ? d_scalar($conn, "SELECT COUNT(*) FROM departments WHERE COALESCE(status,'Active')='Active'") : 0;
$inactiveDepartments = max(0, $totalDepartments - $activeDepartments);
$totalAssignments   = $hasDeptMembers ? d_scalar($conn, "SELECT COUNT(*) FROM department_members") : 0;

$leaders = [];
if ($hasMembers) {
    $res = $conn->query("SELECT id, member_no, first_name, last_name FROM members WHERE COALESCE(status,'Active')='Active' ORDER BY first_name,last_name");
    while ($res && $row = $res->fetch_assoc()) $leaders[] = $row;
}
?>

<div class="main-content">
    <?php include '../includes/topbar.php'; ?>

    <div class="content-area">

        <!-- ── Page header ─────────────────────────────────── -->
        <div class="module-page-header">
            <div class="module-title">
                <h2><i class="fa fa-sitemap"></i> Church Departments</h2>
                <p>Manage all church departments and ministry members</p>
            </div>
            <div class="module-actions">
                <button type="button" class="btn btn-primary" id="btnOpenAddDept">
                    <i class="fa fa-plus"></i> Add Department
                </button>
            </div>
        </div>

        <?php if (function_exists('showFlash')) showFlash(); ?>

        <?php if (!$hasDepartments): ?>
            <div class="info-box danger">
                <i class="fa fa-exclamation-triangle"></i>
                <div>
                    <h4>Setup Required</h4>
                    <p>The <code>departments</code> table must exist before using this module.</p>
                </div>
            </div>
        <?php else: ?>

        <!-- ── Stats grid (3 × 2) ──────────────────────────── -->
        <div class="dept-stats-grid">

            <div class="dept-stat-card">
                <div class="dept-stat-icon"><i class="fa fa-sitemap"></i></div>
                <div>
                    <div class="dept-stat-value" id="statTotal"><?= (int)$totalDepartments ?></div>
                    <div class="dept-stat-label">Total Departments</div>
                </div>
            </div>

            <div class="dept-stat-card">
                <div class="dept-stat-icon"><i class="fa fa-check-circle"></i></div>
                <div>
                    <div class="dept-stat-value" id="statActive"><?= (int)$activeDepartments ?></div>
                    <div class="dept-stat-label">Active Departments</div>
                </div>
            </div>

            <div class="dept-stat-card">
                <div class="dept-stat-icon"><i class="fa fa-pause-circle"></i></div>
                <div>
                    <div class="dept-stat-value" id="statInactive"><?= (int)$inactiveDepartments ?></div>
                    <div class="dept-stat-label">Inactive Departments</div>
                </div>
            </div>

            <div class="dept-stat-card">
                <div class="dept-stat-icon"><i class="fa fa-users"></i></div>
                <div>
                    <div class="dept-stat-value" id="statAssignments"><?= (int)$totalAssignments ?></div>
                    <div class="dept-stat-label">Member Assignments</div>
                </div>
            </div>

            <div class="dept-stat-card">
                <div class="dept-stat-icon"><i class="fa fa-user-tie"></i></div>
                <div>
                    <div class="dept-stat-value" id="statWithLeaders">0</div>
                    <div class="dept-stat-label">With Leaders</div>
                </div>
            </div>

            <div class="dept-stat-card">
                <div class="dept-stat-icon"><i class="fa fa-user-plus"></i></div>
                <div>
                    <div class="dept-stat-value" id="statWithoutMembers">0</div>
                    <div class="dept-stat-label">Without Members</div>
                </div>
            </div>

        </div><!-- /.dept-stats-grid -->

        <!-- ── Main card ────────────────────────────────────── -->
        <div class="card module-card">
            <div class="card-body">

                <div class="dept-toolbar">
                    <div class="dept-search">
                        <input type="text" id="departmentSearch" class="form-control"
                               placeholder="Search departments…">
                    </div>
                    <button type="button" class="btn btn-outline" id="btnRefreshDept">
                        <i class="fa fa-sync-alt"></i> Refresh
                    </button>
                </div>

                <div id="departmentAlert" class="dept-alert"></div>

                <div id="departmentsGrid" class="dept-grid">
                    <div class="text-muted">Loading departments…</div>
                </div>

            </div>
        </div><!-- /.card -->


        <!-- ══════════════════════════════════════════════════
             MODALS
        ══════════════════════════════════════════════════ -->

        <div class="dept-modal-backdrop" id="deptBackdrop"></div>

        <!-- Add / Edit Department -->
        <div class="dept-modal" id="departmentFormModal" aria-hidden="true">
            <div class="dept-dialog">
                <form id="departmentForm">
                    <div class="dept-modal-header">
                        <div class="dept-modal-title" id="departmentFormTitle">Add Department</div>
                        <button type="button" class="dept-close" data-close-dept-modal>&times;</button>
                    </div>

                    <div class="dept-modal-body">
                        <div id="formAlert" class="dept-alert"></div>

                        <input type="hidden" name="action" id="departmentAction" value="create">
                        <input type="hidden" name="id"     id="departmentId"     value="">

                        <div class="dept-form-grid">
                            <div class="dept-field">
                                <label>Department Name <span class="req">*</span></label>
                                <input type="text" name="name" id="deptName" required>
                            </div>

                            <div class="dept-field">
                                <label>Status</label>
                                <select name="status" id="deptStatus">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>

                            <div class="dept-field">
                                <label>Leader</label>
                                <select name="leader" id="deptLeader">
                                    <option value="">- Select Leader -</option>
                                    <?php foreach ($leaders as $l): ?>
                                    <option value="<?= (int)$l['id'] ?>">
                                        <?= dh(trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''))) ?>
                                        <?= !empty($l['member_no']) ? ' (' . dh($l['member_no']) . ')' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="dept-field">
                                <label>Department Phone</label>
                                <input type="text" name="phone" id="deptPhone">
                            </div>

                            <div class="dept-field dept-full">
                                <label>Description</label>
                                <textarea name="description" id="deptDescription"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="dept-modal-actions">
                        <button type="button" class="btn btn-outline" data-close-dept-modal>Cancel</button>
                        <button type="submit" class="btn btn-primary" id="saveDeptBtn">
                            <i class="fa fa-save"></i> Save Department
                        </button>
                    </div>
                </form>
            </div>
        </div><!-- /#departmentFormModal -->


        <!-- Manage Members -->
        <div class="dept-modal" id="membersModal" aria-hidden="true">
            <div class="dept-dialog">
                <div class="dept-modal-header">
                    <div class="dept-modal-title">
                        <i class="fa fa-users"></i>
                        <span id="membersModalTitle">Department Members</span>
                    </div>
                    <button type="button" class="dept-close" data-close-dept-modal>&times;</button>
                </div>

                <div class="dept-modal-body">
                    <div id="membersAlert" class="dept-alert"></div>
                    <input type="hidden" id="currentDeptId" value="">

                    <div class="dept-member-layout">

                        <!-- Current members list -->
                        <div>
                            <h4 class="mb-2">Current Members</h4>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Phone</th>
                                            <th>Role</th>
                                            <th>Joined</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="departmentMembersBody">
                                        <tr><td colspan="5" class="text-muted">Loading…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Add member panel -->
                        <div>
                            <h4 class="mb-2">Add Member</h4>
                            <form id="addMemberForm">
                                <input type="hidden" name="action"  value="add_member">
                                <input type="hidden" name="dept_id" id="addMemberDeptId">

                                <div class="dept-field">
                                    <label>Select Member</label>
                                    <select name="member_id" id="memberSelect" required>
                                        <option value="">- Select -</option>
                                    </select>
                                </div>

                                <div class="dept-field mt-2">
                                    <label>Role</label>
                                    <select name="role">
                                        <option>Member</option>
                                        <option>Leader</option>
                                        <option>Assistant Leader</option>
                                        <option>Secretary</option>
                                        <option>Treasurer</option>
                                        <option>Coordinator</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn btn-primary btn-block mt-3">
                                    <i class="fa fa-plus"></i> Add to Department
                                </button>
                            </form>
                        </div>

                    </div><!-- /.dept-member-layout -->
                </div>

                <div class="dept-modal-actions">
                    <button type="button" class="btn btn-outline" data-close-dept-modal>Close</button>
                </div>
            </div>
        </div><!-- /#membersModal -->


        <!-- Confirm Delete -->
        <div class="dept-modal" id="deleteDepartmentModal" aria-hidden="true">
            <div class="dept-dialog dept-dialog-sm">
                <form id="deleteDepartmentForm">
                    <div class="dept-modal-header">
                        <div class="dept-modal-title">
                            <i class="fa fa-trash text-danger"></i> Delete Department
                        </div>
                        <button type="button" class="dept-close" data-close-dept-modal>&times;</button>
                    </div>

                    <div class="dept-modal-body">
                        <div id="deleteAlert" class="dept-alert"></div>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id"     id="deleteDepartmentId">

                        <p>Are you sure you want to delete
                           <strong id="deleteDepartmentName"></strong>?
                        </p>
                        <p class="text-muted">
                            This will also remove all department member assignments.
                        </p>
                    </div>

                    <div class="dept-modal-actions">
                        <button type="button" class="btn btn-outline" data-close-dept-modal>Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fa fa-trash"></i> Confirm Delete
                        </button>
                    </div>
                </form>
            </div>
        </div><!-- /#deleteDepartmentModal -->


        <!-- ══════════════════════════════════════════════════
             JAVASCRIPT
        ══════════════════════════════════════════════════ -->
        <script>
        (function () {
            'use strict';

            const ENDPOINT = 'process-departments.php';
            let rows = [];

            /* ── helpers ── */
            const $  = (sel, root = document) => root.querySelector(sel);
            const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
            const esc = v => String(v ?? '').replace(/[&<>'"]/g,
                c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));
            const badge = (txt, cls = 'badge-secondary') =>
                `<span class="badge ${cls}">${esc(txt)}</span>`;

            function showAlert(el, msg, type = 'success') {
                if (!el) return;
                el.className = 'dept-alert ' + (type === 'error' ? 'error' : 'success');
                el.textContent = msg;
            }
            function clearAlert(el) {
                if (el) { el.className = 'dept-alert'; el.textContent = ''; }
            }

            function openModal(id) {
                $('#deptBackdrop').classList.add('show');
                $(id).classList.add('show');
                document.body.classList.add('cms-modal-open');
            }
            function closeModals() {
                $$('.dept-modal, .dept-modal-backdrop').forEach(m => m.classList.remove('show'));
                document.body.classList.remove('cms-modal-open');
            }

            async function request(url, opts = {}) {
                const res  = await fetch(url, Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' } }, opts));
                const text = await res.text();
                try { return JSON.parse(text); }
                catch (e) { throw new Error('Invalid server response: ' + text.slice(0, 220)); }
            }
            const postForm = form => request(ENDPOINT, { method: 'POST', body: new FormData(form) });

            /* ── stats ── */
            function renderStats(items) {
                $('#statTotal').textContent          = items.length;
                $('#statActive').textContent         = items.filter(x => (x.status || 'Active') === 'Active').length;
                $('#statInactive').textContent       = items.filter(x => (x.status || 'Active') !== 'Active').length;
                $('#statAssignments').textContent    = items.reduce((s, x) => s + Number(x.member_count || 0), 0);
                $('#statWithLeaders').textContent    = items.filter(x => Number(x.leader || 0) > 0).length;
                $('#statWithoutMembers').textContent = items.filter(x => Number(x.member_count || 0) === 0).length;
            }

            /* ── card grid ── */
            function renderGrid(items) {
                const grid = $('#departmentsGrid');
                if (!items.length) {
                    grid.innerHTML = '<p class="text-muted empty-state"><i class="fa fa-sitemap icon"></i><br>No departments found.</p>';
                    return;
                }
                grid.innerHTML = items.map(d => {
                    const status = d.status || 'Active';
                    const code   = 'DEPT-' + String(d.id).padStart(3, '0');
                    const leader = d.leader_name || (d.leader ? 'Member ID: ' + d.leader : 'No leader assigned');
                    return `
                    <div class="card dept-card">
                        <div class="card-body">
                            <div class="dept-card-head">
                                <div>
                                    ${badge(code, 'badge-primary dept-code')}
                                    <h3 class="dept-title">${esc(d.name || '')}</h3>
                                </div>
                                ${badge(status, status === 'Active' ? 'badge-success' : 'badge-secondary')}
                            </div>

                            <p class="dept-desc">${esc(d.description || 'No description provided.')}</p>

                            <div class="dept-meta">
                                <div class="dept-count">
                                    <strong>${Number(d.member_count || 0)}</strong>
                                    <div class="text-muted" style="font-size:11px">Members</div>
                                </div>
                                <div>
                                    <div class="text-muted" style="font-size:12px">Leader</div>
                                    <strong>${esc(leader)}</strong>
                                    ${d.phone ? `<br><small>${esc(d.phone)}</small>` : ''}
                                </div>
                            </div>
                        </div>

                        <div class="card-footer dept-actions">
                            <button type="button" class="btn btn-sm btn-info"
                                    data-action="members"
                                    data-id="${Number(d.id)}"
                                    data-name="${esc(d.name || '')}">
                                <i class="fa fa-users"></i> Members
                            </button>
                            <button type="button" class="btn btn-sm btn-primary"
                                    data-action="edit"
                                    data-id="${Number(d.id)}">
                                <i class="fa fa-edit"></i> Edit
                            </button>
                            <button type="button" class="btn btn-sm btn-danger btn-icon"
                                    data-action="delete"
                                    data-id="${Number(d.id)}"
                                    data-name="${esc(d.name || '')}">
                                <i class="fa fa-trash"></i>
                            </button>
                        </div>
                    </div>`;
                }).join('');
            }

            function applySearch() {
                const term     = ($('#departmentSearch').value || '').toLowerCase();
                const filtered = rows.filter(d => Object.values(d).join(' ').toLowerCase().includes(term));
                renderGrid(filtered);
            }

            /* ── load all ── */
            async function loadDepartments() {
                clearAlert($('#departmentAlert'));
                const data = await request(ENDPOINT + '?action=list');
                if (!data.success) throw new Error(data.message || 'Failed to load departments.');
                rows = data.items || [];
                renderStats(rows);
                applySearch();
            }

            /* ── form reset ── */
            function resetDeptForm() {
                $('#departmentForm').reset();
                $('#departmentAction').value       = 'create';
                $('#departmentId').value           = '';
                $('#departmentFormTitle').textContent = 'Add Department';
                $('#saveDeptBtn').innerHTML        = '<i class="fa fa-save"></i> Save Department';
                clearAlert($('#formAlert'));
            }

            /* ── edit ── */
            async function openEdit(id) {
                resetDeptForm();
                const data = await request(ENDPOINT + '?action=get&id=' + encodeURIComponent(id));
                if (!data.success) return showAlert($('#departmentAlert'), data.message || 'Department not found.', 'error');
                const d = data.item;
                $('#departmentAction').value       = 'update';
                $('#departmentId').value           = d.id || '';
                $('#departmentFormTitle').textContent = 'Edit Department';
                $('#saveDeptBtn').innerHTML        = '<i class="fa fa-save"></i> Update Department';
                $('#deptName').value               = d.name        || '';
                $('#deptDescription').value        = d.description || '';
                $('#deptLeader').value             = d.leader      || '';
                $('#deptPhone').value              = d.phone       || '';
                $('#deptStatus').value             = d.status      || 'Active';
                openModal('#departmentFormModal');
            }

            /* ── members modal ── */
            async function openMembers(id, name) {
                $('#currentDeptId').value     = id;
                $('#addMemberDeptId').value   = id;
                $('#membersModalTitle').textContent = name + ' Members';
                clearAlert($('#membersAlert'));
                $('#departmentMembersBody').innerHTML = '<tr><td colspan="5" class="text-muted">Loading…</td></tr>';
                $('#memberSelect').innerHTML          = '<option value="">Loading…</option>';
                openModal('#membersModal');
                await loadMembers(id);
            }

            async function loadMembers(id) {
                const data = await request(ENDPOINT + '?action=members&id=' + encodeURIComponent(id));
                if (!data.success) return showAlert($('#membersAlert'), data.message || 'Failed to load members.', 'error');

                const body    = $('#departmentMembersBody');
                const members = data.members || [];
                body.innerHTML = members.length
                    ? members.map(m => `
                        <tr>
                            <td>
                                <div class="member-row">
                                    <span class="avatar">${esc((m.first_name || 'M').substring(0, 1).toUpperCase())}</span>
                                    <div>
                                        <strong>${esc(m.full_name || '')}</strong>
                                        ${m.member_no ? `<br><small class="text-muted">${esc(m.member_no)}</small>` : ''}
                                    </div>
                                </div>
                            </td>
                            <td>${esc(m.phone || '-')}</td>
                            <td>${badge(m.role || 'Member', 'badge-primary')}</td>
                            <td>${esc(m.joined_date || '-')}</td>
                            <td>
                                <button type="button" class="btn btn-sm btn-danger btn-icon"
                                        data-action="remove-member"
                                        data-member-id="${Number(m.id)}">
                                    <i class="fa fa-times"></i>
                                </button>
                            </td>
                        </tr>`).join('')
                    : '<tr><td colspan="5" class="text-muted">No members in this department.</td></tr>';

                const av = data.available || [];
                $('#memberSelect').innerHTML = '<option value="">- Select -</option>' +
                    av.map(m => `<option value="${Number(m.id)}">${esc(m.full_name || '')} ${m.member_no ? '(' + esc(m.member_no) + ')' : ''}</option>`).join('');
            }

            /* ── event bindings ── */
            $('#btnOpenAddDept').addEventListener('click', () => { resetDeptForm(); openModal('#departmentFormModal'); });
            $('#btnRefreshDept').addEventListener('click', loadDepartments);
            $('#departmentSearch').addEventListener('input', applySearch);
            $('#deptBackdrop').addEventListener('click', closeModals);
            $$('[data-close-dept-modal]').forEach(b => b.addEventListener('click', closeModals));
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

            /* delegated click on dynamic cards */
            document.addEventListener('click', async e => {
                const btn    = e.target.closest('[data-action]');
                if (!btn) return;
                const action = btn.dataset.action;
                const id     = btn.dataset.id;

                if (action === 'edit')    { openEdit(id); return; }
                if (action === 'members') { openMembers(id, btn.dataset.name || 'Department'); return; }

                if (action === 'delete') {
                    $('#deleteDepartmentId').textContent  = id;
                    /* use .value for the hidden input */
                    $('#deleteDepartmentId').value        = id;
                    $('#deleteDepartmentName').textContent = btn.dataset.name || 'this department';
                    clearAlert($('#deleteAlert'));
                    openModal('#deleteDepartmentModal');
                    return;
                }

                if (action === 'remove-member') {
                    if (!confirm('Remove this member from department?')) return;
                    const fd = new FormData();
                    fd.append('action',    'remove_member');
                    fd.append('dept_id',   $('#currentDeptId').value);
                    fd.append('member_id', btn.dataset.memberId);
                    const data = await request(ENDPOINT, { method: 'POST', body: fd });
                    if (!data.success) return showAlert($('#membersAlert'), data.message || 'Remove failed.', 'error');
                    showAlert($('#membersAlert'), data.message || 'Member removed.');
                    await loadMembers($('#currentDeptId').value);
                    await loadDepartments();
                }
            });

            /* form submissions */
            $('#departmentForm').addEventListener('submit', async e => {
                e.preventDefault();
                clearAlert($('#formAlert'));
                try {
                    const data = await postForm(e.currentTarget);
                    if (!data.success) return showAlert($('#formAlert'), data.message || 'Save failed.', 'error');
                    closeModals();
                    showAlert($('#departmentAlert'), data.message || 'Saved successfully.');
                    await loadDepartments();
                } catch (err) { showAlert($('#formAlert'), err.message, 'error'); }
            });

            $('#deleteDepartmentForm').addEventListener('submit', async e => {
                e.preventDefault();
                try {
                    const data = await postForm(e.currentTarget);
                    if (!data.success) return showAlert($('#deleteAlert'), data.message || 'Delete failed.', 'error');
                    closeModals();
                    showAlert($('#departmentAlert'), data.message || 'Deleted successfully.');
                    await loadDepartments();
                } catch (err) { showAlert($('#deleteAlert'), err.message, 'error'); }
            });

            $('#addMemberForm').addEventListener('submit', async e => {
                e.preventDefault();
                try {
                    const data = await postForm(e.currentTarget);
                    if (!data.success) return showAlert($('#membersAlert'), data.message || 'Add failed.', 'error');
                    showAlert($('#membersAlert'), data.message || 'Member added.');
                    await loadMembers($('#currentDeptId').value);
                    await loadDepartments();
                } catch (err) { showAlert($('#membersAlert'), err.message, 'error'); }
            });

            /* ── init ── */
            loadDepartments().catch(err => showAlert($('#departmentAlert'), err.message, 'error'));

        })();
        </script>

        <?php endif; ?>

    </div><!-- /.content-area -->
</div><!-- /.main-content -->

<?php require_once '../includes/footer.php'; ?>