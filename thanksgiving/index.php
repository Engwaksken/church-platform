<?php
require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function tgv_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function tgv_table_exists(mysqli $conn, string $table): bool {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function tgv_scalar(mysqli $conn, string $sql): float {
    try {
        $res = $conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row();
        $res->close();
        return (float)($row[0] ?? 0);
    } catch (Throwable $e) { return 0; }
}

$hasThanksgiving = tgv_table_exists($conn, 'thanksgiving');
$hasMembers = tgv_table_exists($conn, 'members');
$from = tgv_h($_GET['from'] ?? date('Y-m-01'));
$to = tgv_h($_GET['to'] ?? date('Y-m-d'));

$periodTotal = $hasThanksgiving ? tgv_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM thanksgiving WHERE thanksgiving_date BETWEEN '".$conn->real_escape_string($from)."' AND '".$conn->real_escape_string($to)."'") : 0;
$yearTotal = $hasThanksgiving ? tgv_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM thanksgiving WHERE YEAR(thanksgiving_date)=YEAR(CURDATE())") : 0;
$monthTotal = $hasThanksgiving ? tgv_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM thanksgiving WHERE YEAR(thanksgiving_date)=YEAR(CURDATE()) AND MONTH(thanksgiving_date)=MONTH(CURDATE())") : 0;
$recordsCount = $hasThanksgiving ? tgv_scalar($conn, "SELECT COUNT(*) FROM thanksgiving WHERE thanksgiving_date BETWEEN '".$conn->real_escape_string($from)."' AND '".$conn->real_escape_string($to)."'") : 0;
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php if (function_exists('showFlash')) { echo showFlash(); } ?>

<style>
.tgv-page-header{display:flex;justify-content:space-between;gap:1rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap}.tgv-page-header h2{margin:0;font-weight:800}.tgv-muted{color:#6b7280}.tgv-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;margin-bottom:1rem}.tgv-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 10px 25px rgba(15,23,42,.07)}.tgv-stat{padding:1.1rem;display:flex;gap:.9rem;align-items:center}.tgv-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:#f3e8ff;color:#6d28d9;font-size:1.25rem}.tgv-value{font-size:1.45rem;font-weight:900;line-height:1}.tgv-label{font-size:.83rem;color:#6b7280;margin-top:.25rem}.tgv-toolbar{display:flex;justify-content:space-between;gap:.75rem;align-items:end;flex-wrap:wrap;padding:1rem}.tgv-filter{display:flex;gap:.6rem;align-items:end;flex-wrap:wrap}.tgv-field label{font-size:.8rem;font-weight:700;margin-bottom:.25rem;display:block}.tgv-field input,.tgv-field select,.tgv-field textarea{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:.65rem .75rem;background:#fff}.tgv-search{min-width:260px}.tgv-table-wrap{overflow:auto}.tgv-table{width:100%;border-collapse:collapse}.tgv-table th,.tgv-table td{padding:.8rem;border-top:1px solid #edf2f7;text-align:left;vertical-align:middle}.tgv-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.03em;color:#64748b;background:#f8fafc}.tgv-actions{display:flex;gap:.35rem;flex-wrap:wrap}.tgv-badge{display:inline-flex;align-items:center;gap:.25rem;border-radius:999px;padding:.28rem .55rem;font-size:.78rem;font-weight:800;background:#e0f2fe;color:#0369a1}.tgv-badge.gold{background:#fef3c7;color:#92400e}.tgv-badge.green{background:#dcfce7;color:#166534}.tgv-btn-icon{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;padding:0}.tgv-alert{display:none;margin:0 1rem 1rem;border-radius:10px;padding:.75rem 1rem}.tgv-alert.success{display:block;background:#dcfce7;color:#166534}.tgv-alert.error{display:block;background:#fee2e2;color:#991b1b}.tgv-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:9998;display:none}.tgv-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px}.tgv-modal.show{display:flex}.tgv-modal-backdrop.show{display:block}.tgv-dialog{width:min(850px,100%);background:#fff;border-radius:18px;box-shadow:0 25px 70px rgba(0,0,0,.28);overflow:hidden}.tgv-dialog-sm{width:min(520px,100%)}.tgv-modal-header{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:1rem 1.15rem;border-bottom:1px solid #e5e7eb;background:#f8fafc}.tgv-modal-title{font-size:1.1rem;font-weight:900}.tgv-close{border:0;background:transparent;font-size:1.7rem;line-height:1;cursor:pointer}.tgv-modal-body{padding:1.15rem;max-height:calc(100vh - 185px);overflow-y:auto}.tgv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.9rem}.tgv-full{grid-column:1/-1}.tgv-modal-actions{display:flex;justify-content:flex-end;gap:.6rem;padding:1rem 1.15rem;background:#f8fafc;border-top:1px solid #e5e7eb}.tgv-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}.tgv-detail{padding:.75rem;border:1px solid #e5e7eb;border-radius:12px;background:#f8fafc}.tgv-detail b{display:block;color:#64748b;font-size:.78rem;margin-bottom:.2rem}.tgv-empty{text-align:center;color:#64748b;padding:1.5rem}.btn-danger{background:#dc2626;color:#fff}.btn-info{background:#0ea5e9;color:#fff}.btn-warning{background:#f59e0b;color:#fff}.btn-outline{border:1px solid #d1d5db;background:#fff;color:#111827}.btn-secondary{background:#64748b;color:#fff}@media(max-width:900px){.tgv-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.tgv-grid,.tgv-detail-grid{grid-template-columns:1fr}}@media(max-width:560px){.tgv-stats{grid-template-columns:1fr}.tgv-search{min-width:100%}.tgv-filter{width:100%}.tgv-field{width:100%}}
</style>

<div class="tgv-page-header">
  <div>
    <h2><i class="fas fa-praying-hands"></i> Thanksgiving Offerings</h2>
    <div class="tgv-muted">Record, edit, view, and manage thanksgiving offerings without opening separate pages.</div>
  </div>
  <button type="button" class="btn btn-primary" id="btnOpenTgvForm"><i class="fas fa-plus"></i> Record Thanksgiving</button>
</div>

<?php if (!$hasThanksgiving): ?>
<div class="tgv-card"><div class="tgv-empty"><strong>Setup required:</strong> thanksgiving table was not found.</div></div>
<?php else: ?>

<div class="tgv-stats">
  <div class="tgv-card tgv-stat"><div class="tgv-icon"><i class="fas fa-calendar-check"></i></div><div><div class="tgv-value" id="statPeriod">UGX <?= number_format($periodTotal) ?></div><div class="tgv-label">Selected Period</div></div></div>
  <div class="tgv-card tgv-stat"><div class="tgv-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="tgv-value" id="statMonth">UGX <?= number_format($monthTotal) ?></div><div class="tgv-label">This Month</div></div></div>
  <div class="tgv-card tgv-stat"><div class="tgv-icon"><i class="fas fa-chart-line"></i></div><div><div class="tgv-value" id="statYear">UGX <?= number_format($yearTotal) ?></div><div class="tgv-label">This Year</div></div></div>
  <div class="tgv-card tgv-stat"><div class="tgv-icon"><i class="fas fa-receipt"></i></div><div><div class="tgv-value" id="statRecords"><?= number_format($recordsCount) ?></div><div class="tgv-label">Period Records</div></div></div>
</div>

<div class="tgv-card">
  <div class="tgv-toolbar">
    <div class="tgv-filter">
      <div class="tgv-field"><label>From</label><input type="date" id="filterFrom" value="<?= $from ?>"></div>
      <div class="tgv-field"><label>To</label><input type="date" id="filterTo" value="<?= $to ?>"></div>
      <button type="button" class="btn btn-secondary" id="btnFilterTgv"><i class="fas fa-filter"></i> Filter</button>
      <button type="button" class="btn btn-outline" id="btnRefreshTgv"><i class="fas fa-sync-alt"></i> Refresh</button>
    </div>
    <div class="tgv-field tgv-search"><label>Search</label><input type="text" id="tgvSearch" placeholder="Search receipt, giver, reason, method..."></div>
  </div>
  <div id="tgvAlert" class="tgv-alert"></div>
  <div class="tgv-table-wrap">
    <table class="tgv-table">
      <thead><tr><th>#</th><th>Receipt No</th><th>Given By</th><th>Reason</th><th>Amount</th><th>Method</th><th>Date</th><th>Actions</th></tr></thead>
      <tbody id="tgvTableBody"><tr><td colspan="8" class="tgv-empty">Loading records...</td></tr></tbody>
    </table>
  </div>
</div>

<div class="tgv-modal-backdrop" id="tgvBackdrop"></div>

<div class="tgv-modal" id="tgvFormModal" aria-hidden="true">
  <div class="tgv-dialog">
    <form id="tgvForm">
      <div class="tgv-modal-header"><div class="tgv-modal-title" id="tgvFormTitle"><i class="fas fa-praying-hands"></i> Record Thanksgiving</div><button type="button" class="tgv-close" data-tgv-close>&times;</button></div>
      <div class="tgv-modal-body">
        <div id="formAlert" class="tgv-alert" style="margin:0 0 1rem"></div>
        <input type="hidden" name="action" id="tgvAction" value="create">
        <input type="hidden" name="id" id="tgvId" value="">
        <div class="tgv-grid">
          <div class="tgv-field tgv-full">
            <label>Church Member</label>
            <select name="member_id" id="member_id">
              <option value="">-- Visitor / Non-member --</option>
            </select>
          </div>
          <div class="tgv-field tgv-full"><label>Giver's Name <small class="tgv-muted">(for visitor/non-member)</small></label><input type="text" name="giver_name" id="giver_name" placeholder="Enter giver name"></div>
          <div class="tgv-field tgv-full"><label>Reason for Thanksgiving *</label><input type="text" name="reason" id="reason" placeholder="e.g. Recovery, new job, safe journey" required></div>
          <div class="tgv-field"><label>Amount (UGX) *</label><input type="number" name="amount" id="amount" min="0" step="0.01" required></div>
          <div class="tgv-field"><label>Date *</label><input type="date" name="thanksgiving_date" id="thanksgiving_date" value="<?= date('Y-m-d') ?>" required></div>
          <div class="tgv-field"><label>Payment Method</label><select name="payment_method" id="payment_method"><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option><option>Kind</option></select></div>
          <div class="tgv-field"><label>Item Description</label><input type="text" name="item_description" id="item_description" placeholder="For gifts in kind"></div>
          <div class="tgv-field tgv-full"><label>Notes</label><textarea name="notes" id="notes" rows="3" placeholder="Optional notes"></textarea></div>
        </div>
      </div>
      <div class="tgv-modal-actions"><button type="button" class="btn btn-outline" data-tgv-close>Cancel</button><button type="submit" class="btn btn-primary" id="btnSaveTgv"><i class="fas fa-save"></i> Save Thanksgiving</button></div>
    </form>
  </div>
</div>

<div class="tgv-modal" id="tgvViewModal" aria-hidden="true">
  <div class="tgv-dialog">
    <div class="tgv-modal-header"><div class="tgv-modal-title"><i class="fas fa-eye"></i> Thanksgiving Details</div><button type="button" class="tgv-close" data-tgv-close>&times;</button></div>
    <div class="tgv-modal-body"><div id="tgvViewContent" class="tgv-detail-grid"></div></div>
    <div class="tgv-modal-actions"><button type="button" class="btn btn-outline" data-tgv-close>Close</button></div>
  </div>
</div>

<div class="tgv-modal" id="tgvDeleteModal" aria-hidden="true">
  <div class="tgv-dialog tgv-dialog-sm">
    <form id="tgvDeleteForm">
      <div class="tgv-modal-header"><div class="tgv-modal-title"><i class="fas fa-trash"></i> Delete Thanksgiving</div><button type="button" class="tgv-close" data-tgv-close>&times;</button></div>
      <div class="tgv-modal-body">
        <div id="deleteAlert" class="tgv-alert" style="margin:0 0 1rem"></div>
        <input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteTgvId">
        <p>Are you sure you want to delete receipt <strong id="deleteTgvReceipt"></strong>?</p>
        <p class="tgv-muted">This action will remove the thanksgiving record permanently.</p>
      </div>
      <div class="tgv-modal-actions"><button type="button" class="btn btn-outline" data-tgv-close>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
    </form>
  </div>
</div>

<script>
(function(){
  'use strict';
  const endpoint = 'process-thanksgiving.php';
  let rows = [];
  let membersLoaded = false;
  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));
  const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const money = v => 'UGX ' + Number(v || 0).toLocaleString();

  function showAlert(el, msg, type='success') { if (!el) return; el.className = 'tgv-alert ' + (type === 'error' ? 'error' : 'success'); el.textContent = msg; }
  function clearAlert(el) { if (!el) return; el.className = 'tgv-alert'; el.textContent = ''; }
  function openModal(id) { qs('#tgvBackdrop').classList.add('show'); qs(id).classList.add('show'); document.body.style.overflow = 'hidden'; }
  function closeModals() { qsa('.tgv-modal,.tgv-modal-backdrop').forEach(x => x.classList.remove('show')); document.body.style.overflow = ''; }

  async function request(url, opts={}) {
    const res = await fetch(url, Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}}, opts));
    const text = await res.text();
    try { return JSON.parse(text); } catch(e) { throw new Error(text ? 'Invalid server response: ' + text.slice(0, 250) : 'Empty server response from backend.'); }
  }
  async function postForm(form) { return request(endpoint, {method:'POST', body:new FormData(form)}); }

  async function loadMembers(selectedId='') {
    if (membersLoaded && !selectedId) return;
    const select = qs('#member_id');
    const data = await request(endpoint + '?action=members');
    if (!data.success) return;
    select.innerHTML = '<option value="">-- Visitor / Non-member --</option>' + (data.items || []).map(m => {
      const label = `${m.full_name || ''}${m.member_no ? ' (' + m.member_no + ')' : ''}`;
      return `<option value="${Number(m.id)}">${esc(label)}</option>`;
    }).join('');
    if (selectedId) select.value = selectedId;
    membersLoaded = true;
  }

  function renderStats(stats) {
    if (!stats) return;
    qs('#statPeriod').textContent = money(stats.period_total);
    qs('#statMonth').textContent = money(stats.month_total);
    qs('#statYear').textContent = money(stats.year_total);
    qs('#statRecords').textContent = Number(stats.period_records || 0).toLocaleString();
  }

  function renderTable(items) {
    const tbody = qs('#tgvTableBody');
    if (!items.length) { tbody.innerHTML = '<tr><td colspan="8" class="tgv-empty">No thanksgiving records found.</td></tr>'; return; }
    tbody.innerHTML = items.map((r, i) => `<tr>
      <td>${i + 1}</td>
      <td><span class="tgv-badge gold"><i class="fas fa-receipt"></i> ${esc(r.receipt_no || '-')}</span></td>
      <td><strong>${esc(r.given_by || 'Anonymous')}</strong>${r.member_no ? '<br><small class="tgv-muted">' + esc(r.member_no) + '</small>' : ''}</td>
      <td>${esc(r.reason || '—')}</td>
      <td><span class="tgv-badge green">${money(r.amount)}</span></td>
      <td><span class="tgv-badge">${esc(r.payment_method || 'Cash')}</span></td>
      <td>${esc(r.thanksgiving_date || '')}</td>
      <td><div class="tgv-actions">
        <a class="btn btn-success tgv-btn-icon" target="_blank" href="download_receipt.php?id=${Number(r.id)}" title="Generate Receipt PDF"><i class="fas fa-file-pdf"></i></a>
        <button type="button" class="btn btn-info tgv-btn-icon" data-action="view" data-id="${Number(r.id)}" title="View"><i class="fas fa-eye"></i></button>
        <button type="button" class="btn btn-warning tgv-btn-icon" data-action="edit" data-id="${Number(r.id)}" title="Edit"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-danger tgv-btn-icon" data-action="delete" data-id="${Number(r.id)}" data-receipt="${esc(r.receipt_no || '')}" title="Delete"><i class="fas fa-trash"></i></button>
      </div></td>
    </tr>`).join('');
  }

  function applySearch() {
    const term = (qs('#tgvSearch').value || '').toLowerCase();
    const filtered = rows.filter(r => Object.values(r).join(' ').toLowerCase().includes(term));
    renderTable(filtered);
  }

  async function loadRecords() {
    clearAlert(qs('#tgvAlert'));
    const from = encodeURIComponent(qs('#filterFrom').value || '');
    const to = encodeURIComponent(qs('#filterTo').value || '');
    const data = await request(`${endpoint}?action=list&from=${from}&to=${to}`);
    if (!data.success) throw new Error(data.message || 'Failed to load records.');
    rows = data.items || [];
    renderStats(data.stats);
    applySearch();
  }

  function resetForm() {
    qs('#tgvForm').reset();
    qs('#tgvAction').value = 'create';
    qs('#tgvId').value = '';
    qs('#thanksgiving_date').value = new Date().toISOString().slice(0,10);
    qs('#tgvFormTitle').innerHTML = '<i class="fas fa-praying-hands"></i> Record Thanksgiving';
    qs('#btnSaveTgv').innerHTML = '<i class="fas fa-save"></i> Save Thanksgiving';
    clearAlert(qs('#formAlert'));
  }

  async function openAdd() { resetForm(); await loadMembers(); openModal('#tgvFormModal'); }
  async function openEdit(id) {
    resetForm();
    const data = await request(endpoint + '?action=get&id=' + encodeURIComponent(id));
    if (!data.success) return showAlert(qs('#tgvAlert'), data.message || 'Record not found.', 'error');
    const r = data.item;
    await loadMembers(r.member_id || '');
    qs('#tgvAction').value = 'update';
    qs('#tgvId').value = r.id || '';
    qs('#giver_name').value = r.giver_name || '';
    qs('#reason').value = r.reason || '';
    qs('#amount').value = r.amount || 0;
    qs('#thanksgiving_date').value = r.thanksgiving_date || '';
    qs('#payment_method').value = r.payment_method || 'Cash';
    qs('#item_description').value = r.item_description || '';
    qs('#notes').value = r.notes || '';
    qs('#tgvFormTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Thanksgiving';
    qs('#btnSaveTgv').innerHTML = '<i class="fas fa-save"></i> Update Thanksgiving';
    openModal('#tgvFormModal');
  }
  async function openView(id) {
    const data = await request(endpoint + '?action=get&id=' + encodeURIComponent(id));
    if (!data.success) return showAlert(qs('#tgvAlert'), data.message || 'Record not found.', 'error');
    const r = data.item;
    const fields = [['Receipt No', r.receipt_no], ['Given By', r.given_by], ['Member No', r.member_no], ['Phone', r.phone], ['Reason', r.reason], ['Amount', money(r.amount)], ['Payment Method', r.payment_method], ['Date', r.thanksgiving_date], ['Item Description', r.item_description], ['Notes', r.notes]];
    qs('#tgvViewContent').innerHTML = fields.map(([k,v]) => `<div class="tgv-detail"><b>${esc(k)}</b><span>${esc(v || '—')}</span></div>`).join('');
    openModal('#tgvViewModal');
  }

  qs('#btnOpenTgvForm').addEventListener('click', openAdd);
  qs('#btnRefreshTgv').addEventListener('click', () => loadRecords().catch(err => showAlert(qs('#tgvAlert'), err.message, 'error')));
  qs('#btnFilterTgv').addEventListener('click', () => loadRecords().catch(err => showAlert(qs('#tgvAlert'), err.message, 'error')));
  qs('#tgvSearch').addEventListener('input', applySearch);
  qs('#tgvBackdrop').addEventListener('click', closeModals);
  qsa('[data-tgv-close]').forEach(btn => btn.addEventListener('click', closeModals));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });
  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const id = btn.dataset.id;
    if (btn.dataset.action === 'view') openView(id);
    if (btn.dataset.action === 'edit') openEdit(id);
    if (btn.dataset.action === 'delete') { qs('#deleteTgvId').value = id; qs('#deleteTgvReceipt').textContent = btn.dataset.receipt || ''; clearAlert(qs('#deleteAlert')); openModal('#tgvDeleteModal'); }
  });
  qs('#tgvForm').addEventListener('submit', async e => {
    e.preventDefault(); clearAlert(qs('#formAlert'));
    try { const data = await postForm(e.currentTarget); if (!data.success) return showAlert(qs('#formAlert'), data.message || 'Save failed.', 'error'); closeModals(); showAlert(qs('#tgvAlert'), data.message || 'Saved successfully.'); await loadRecords(); }
    catch (err) { showAlert(qs('#formAlert'), err.message, 'error'); }
  });
  qs('#tgvDeleteForm').addEventListener('submit', async e => {
    e.preventDefault(); clearAlert(qs('#deleteAlert'));
    try { const data = await postForm(e.currentTarget); if (!data.success) return showAlert(qs('#deleteAlert'), data.message || 'Delete failed.', 'error'); closeModals(); showAlert(qs('#tgvAlert'), data.message || 'Deleted successfully.'); await loadRecords(); }
    catch (err) { showAlert(qs('#deleteAlert'), err.message, 'error'); }
  });
  loadRecords().catch(err => showAlert(qs('#tgvAlert'), err.message, 'error'));
})();
</script>
<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
