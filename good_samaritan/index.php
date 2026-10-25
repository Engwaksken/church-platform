<?php
require_once '../config/config.php';
requireLogin();
require_once __DIR__ . '/../includes/service-options.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function gsh($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function gs_page_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}

$hasTable = gs_page_table_exists($conn, 'good_samaritan');
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

$members = [];
if (gs_page_table_exists($conn, 'members')) {
    $res = $conn->query("SELECT id, member_no, full_name FROM members ORDER BY full_name");
    while ($res && $r = $res->fetch_assoc()) $members[] = $r;
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
        <h2><i class="fas fa-hands-helping"></i> Good Samaritan</h2>
        <p class="text-muted mb-0">Record and track what members give to the Good Samaritan fund.</p>
    </div>
    <?php if ($hasTable): ?>
    <button type="button" class="btn btn-primary" id="btnOpenRecord"><i class="fas fa-plus"></i> Record Collection</button>
    <?php endif; ?>
</div>

<?php if (!$hasTable): ?>
<div class="card"><div class="card-body"><strong>Setup required:</strong> the good_samaritan table was not found. Run <code>database/good_samaritan.sql</code>.</div></div>
<?php else: ?>

<div class="gs-stats-grid">
    <div class="gs-stat-card"><div class="gs-stat-icon"><i class="fas fa-receipt"></i></div><div><div class="gs-stat-value" id="statCount">0</div><div class="gs-stat-label">Records</div></div></div>
    <div class="gs-stat-card"><div class="gs-stat-icon"><i class="fas fa-coins"></i></div><div><div class="gs-stat-value" id="statTotal">UGX 0</div><div class="gs-stat-label">Total Collected</div></div></div>
    <div class="gs-stat-card"><div class="gs-stat-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="gs-stat-value" id="statMonth">UGX 0</div><div class="gs-stat-label">This Month</div></div></div>
    <div class="gs-stat-card"><div class="gs-stat-icon"><i class="fas fa-users"></i></div><div><div class="gs-stat-value" id="statMembers">0</div><div class="gs-stat-label">Giving Members</div></div></div>
</div>

<div class="page-toolbar">
    <div class="page-toolbar-search"><i class="fas fa-search"></i><input type="text" id="tableSearch" placeholder="Search receipt, member, method, notes..."></div>
    <div class="page-toolbar-filters">
        <select id="filterMethod" class="form-control">
            <option value="">All Methods</option><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option>
        </select>
    </div>
    <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="btnRefresh"><i class="fas fa-rotate-right"></i> Refresh</button>
    </div>
</div>
<div id="pageAlert" class="gs-alert"></div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th>#</th><th>Receipt No</th><th>Member</th><th>Amount (UGX)</th><th>Method</th><th>Date</th><th>Notes</th><th>Actions</th></tr>
                </thead>
                <tbody id="recordTableBody"><tr><td colspan="8" class="text-muted p-3">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="gs-modal-backdrop" id="gsBackdrop"></div>

<div class="gs-modal" id="recordModal" aria-hidden="true">
    <div class="gs-dialog">
        <form id="recordForm">
            <div class="gs-modal-header"><div class="gs-modal-title" id="recordTitle"><i class="fas fa-plus"></i> Record Collection</div><button type="button" class="gs-close" data-close-modal>&times;</button></div>
            <div class="gs-modal-body">
                <div id="formAlert" class="gs-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= gsh($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="recordId" value="">
                <div class="gs-grid">
                    <div class="gs-field gs-full"><label>Member <small class="text-muted">(optional)</small></label><select name="member_id" id="member_id"><option value="">General collection (basket, no single member)</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= gsh(trim($m['full_name'])) ?><?= !empty($m['member_no']) ? ' ('.gsh($m['member_no']).')' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="gs-field"><label>Amount (UGX) <span class="text-danger">*</span></label><input type="number" name="amount" id="amount" min="0" step="0.01" required></div>
                    <div class="gs-field"><label>Payment Method</label><select name="payment_method" id="payment_method"><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option></select></div>
                    <div class="gs-field"><label>Date <span class="text-danger">*</span></label><input type="date" name="given_on" id="given_on" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="gs-field"><label>Service Type</label><select name="service_type" id="service_type"><option value="">Not from a service</option><?php foreach (service_type_options() as $t): ?><option><?= gsh($t) ?></option><?php endforeach; ?></select></div>
                    <div class="gs-field"><label>Service</label><select name="service_session" id="service_session"><option value="">Not specified</option><?php foreach (service_session_options() as $t): ?><option><?= gsh($t) ?></option><?php endforeach; ?></select></div>
                    <div class="gs-field"><label>Receipt No</label><input type="text" name="receipt_no" id="receipt_no" placeholder="Auto generated if empty"></div>
                    <div class="gs-field gs-full"><label>Notes</label><textarea name="notes" id="notes" rows="3"></textarea></div>
                </div>
            </div>
            <div class="gs-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary" id="saveRecordBtn"><i class="fas fa-save"></i> Save</button></div>
        </form>
    </div>
</div>

<div class="gs-modal" id="deleteModal" aria-hidden="true">
    <div class="gs-dialog gs-dialog-sm">
        <form id="deleteForm">
            <div class="gs-modal-header"><div class="gs-modal-title"><i class="fas fa-trash"></i> Delete Collection</div><button type="button" class="gs-close" data-close-modal>&times;</button></div>
            <div class="gs-modal-body">
                <div id="deleteAlert" class="gs-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= gsh($csrfToken) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <p>Delete Good Samaritan receipt <strong id="deleteReceiptNo"></strong>?</p>
                <p class="text-muted">This removes the record permanently.</p>
            </div>
            <div class="gs-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
        </form>
    </div>
</div>

<style>
.gs-stats-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}.gs-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;gap:14px;align-items:center}.gs-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(22,163,74,.12);font-size:20px;color:#15803d}.gs-stat-value{font-size:22px;font-weight:800;line-height:1.2}.gs-stat-label{font-size:13px;color:#64748b;margin-top:4px}.gs-field input,.gs-field select,.gs-field textarea{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px}.gs-alert{display:none;margin-top:10px;border-radius:10px;padding:10px 12px}.gs-alert.success{display:block;background:#dcfce7;color:#166534}.gs-alert.error{display:block;background:#fee2e2;color:#991b1b}.gs-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;z-index:9998}.gs-modal{position:fixed;inset:0;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px;z-index:9999}.gs-modal.show{display:flex}.gs-modal-backdrop.show{display:block}.gs-dialog{width:min(760px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}.gs-dialog-sm{width:min(520px,100%)}.gs-modal-header{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:16px 18px}.gs-modal-title{font-weight:800;font-size:18px}.gs-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.gs-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}.gs-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.gs-full{grid-column:1/-1}.gs-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}.gs-actions{display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 18px}.table-actions{display:flex;gap:6px;flex-wrap:wrap}@media(max-width:900px){.gs-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.gs-grid{grid-template-columns:1fr}}@media(max-width:560px){.gs-stats-grid{grid-template-columns:1fr}}
</style>

<script>
(function(){
'use strict';
const endpoint='process-good-samaritan.php';
let rows=[];
const qs=(s,r=document)=>r.querySelector(s); const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const money=v=>'UGX '+Number(v||0).toLocaleString();
function alertBox(el,msg,type='success'){if(!el)return;el.className='gs-alert '+(type==='error'?'error':'success');el.textContent=msg;}
function clearAlert(el){if(el){el.className='gs-alert';el.textContent='';}}
function openModal(id){qs('#gsBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
function closeModals(){qsa('.gs-modal,.gs-modal-backdrop').forEach(m=>m.classList.remove('show'));document.body.style.overflow='';}
async function request(url,opts={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts));const text=await res.text();try{return JSON.parse(text);}catch(e){throw new Error(text?('Invalid server response: '+text.slice(0,220)):'Empty server response. Check the PHP error log.');}}
async function postForm(form){return request(endpoint,{method:'POST',body:new FormData(form)});}
function renderStats(items){let total=0,month=0,mem=new Set();const ym=new Date().toISOString().slice(0,7);items.forEach(x=>{const amt=Number(x.amount||0);total+=amt;if(String(x.given_on||'').slice(0,7)===ym)month+=amt;if(x.member_id)mem.add(x.member_id);});qs('#statCount').textContent=items.length.toLocaleString();qs('#statTotal').textContent=money(total);qs('#statMonth').textContent=money(month);qs('#statMembers').textContent=mem.size.toLocaleString();}
function filteredRows(){const term=(qs('#tableSearch').value||'').toLowerCase();const method=qs('#filterMethod').value;return rows.filter(x=>(!method||String(x.payment_method)===method)&&Object.values(x).join(' ').toLowerCase().includes(term));}
function renderTable(){const items=filteredRows();const tbody=qs('#recordTableBody');renderStats(items);if(!items.length){tbody.innerHTML='<tr><td colspan="8" class="text-muted p-3">No Good Samaritan collections found.</td></tr>';return;}tbody.innerHTML=items.map((g,i)=>`<tr><td>${i+1}</td><td><span class="badge badge-success">${esc(g.receipt_no||'-')}</span></td><td>${g.member_id?esc(g.full_name||'—'):'<em class="text-muted">General collection</em>'}${g.member_no?' <small class="text-muted">('+esc(g.member_no)+')</small>':''}</td><td><strong>${Number(g.amount||0).toLocaleString()}</strong></td><td><span class="badge badge-info">${esc(g.payment_method||'—')}</span></td><td>${esc(g.given_on||'—')}${(g.service_type||g.service_session)?`<br><small class="text-muted">${esc([g.service_type,g.service_session].filter(Boolean).join(' · '))}</small>`:''}</td><td>${esc(g.notes||'')}</td><td><div class="table-actions"><button type="button" class="btn btn-sm btn-warning" data-action="edit" data-id="${Number(g.id)}" title="Edit"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${Number(g.id)}" data-receipt="${esc(g.receipt_no||'')}" title="Delete"><i class="fas fa-trash"></i></button></div></td></tr>`).join('');}
async function load(){clearAlert(qs('#pageAlert'));const data=await request(endpoint+'?action=list');if(!data.success)throw new Error(data.message||'Failed to load records.');rows=data.items||[];renderTable();}
function resetForm(){qs('#recordForm').reset();qs('#formAction').value='create';qs('#recordId').value='';qs('#recordTitle').innerHTML='<i class="fas fa-plus"></i> Record Collection';qs('#given_on').value=new Date().toISOString().slice(0,10);clearAlert(qs('#formAlert'));}
async function openEdit(id){resetForm();const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#pageAlert'),data.message||'Record not found.','error');const g=data.item;qs('#formAction').value='update';qs('#recordId').value=g.id||'';qs('#recordTitle').innerHTML='<i class="fas fa-edit"></i> Edit Collection';['member_id','amount','payment_method','given_on','service_type','service_session','receipt_no','notes'].forEach(k=>{if(qs('#'+k))qs('#'+k).value=g[k]||'';});openModal('#recordModal');}
qs('#btnOpenRecord').addEventListener('click',()=>{resetForm();openModal('#recordModal');});qs('#btnRefresh').addEventListener('click',()=>{['#tableSearch','#filterMethod'].forEach(s=>qs(s).value='');load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));});['#tableSearch','#filterMethod'].forEach(s=>qs(s).addEventListener('input',renderTable));qsa('[data-close-modal]').forEach(b=>b.addEventListener('click',closeModals));qs('#gsBackdrop').addEventListener('click',closeModals);document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
document.addEventListener('click',e=>{const b=e.target.closest('[data-action]');if(!b)return;const id=b.dataset.id;if(b.dataset.action==='edit')openEdit(id);if(b.dataset.action==='delete'){qs('#deleteId').value=id;qs('#deleteReceiptNo').textContent=b.dataset.receipt||'';clearAlert(qs('#deleteAlert'));openModal('#deleteModal');}});
qs('#recordForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#formAlert'),data.message||'Save failed.','error');closeModals();alertBox(qs('#pageAlert'),data.message||'Saved.');await load();}catch(err){alertBox(qs('#formAlert'),err.message,'error');}});
qs('#deleteForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#deleteAlert'),data.message||'Delete failed.','error');closeModals();alertBox(qs('#pageAlert'),data.message||'Deleted.');await load();}catch(err){alertBox(qs('#deleteAlert'),err.message,'error');}});
load().catch(err=>alertBox(qs('#pageAlert'),err.message,'error'));
})();
</script>
<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
