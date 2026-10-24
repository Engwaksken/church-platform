<?php
require_once '../config/config.php';
requireLogin();

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}
$conn->set_charset('utf8mb4');

function ch($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function c_table_exists(mysqli $conn, $table) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0) > 0;
}
function c_scalar(mysqli $conn, $sql) {
    try {
        $res = $conn->query($sql);
        if (!$res) return 0;
        $row = $res->fetch_row();
        $res->close();
        return (float)($row[0] ?? 0);
    } catch (Throwable $e) { return 0; }
}

$hasContributions = c_table_exists($conn, 'contributions');
$hasMembers = c_table_exists($conn, 'members');
$hasProjects = c_table_exists($conn, 'projects');
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';

$totalContributions = $hasContributions ? c_scalar($conn, "SELECT COUNT(*) FROM contributions") : 0;
$totalAmount = $hasContributions ? c_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM contributions") : 0;
$todayAmount = $hasContributions ? c_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM contributions WHERE contributed_on = CURDATE()") : 0;
$thisMonthAmount = $hasContributions ? c_scalar($conn, "SELECT COALESCE(SUM(amount),0) FROM contributions WHERE YEAR(contributed_on)=YEAR(CURDATE()) AND MONTH(contributed_on)=MONTH(CURDATE())") : 0;
$uniqueMembers = $hasContributions ? c_scalar($conn, "SELECT COUNT(DISTINCT member_id) FROM contributions WHERE member_id IS NOT NULL AND member_id > 0") : 0;
$uniqueProjects = $hasContributions ? c_scalar($conn, "SELECT COUNT(DISTINCT project_id) FROM contributions WHERE project_id IS NOT NULL AND project_id > 0") : 0;

$members = [];
if ($hasMembers) {
    $res = $conn->query("SELECT id, member_no, full_name FROM members ORDER BY full_name");
    while ($res && $r = $res->fetch_assoc()) $members[] = $r;
}
$projects = [];
if ($hasProjects) {
    $res = $conn->query("SELECT id, name FROM projects ORDER BY name");
    while ($res && $r = $res->fetch_assoc()) $projects[] = $r;
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
        <h2><i class="fas fa-hand-holding-usd"></i> Contributions</h2>
        <p class="text-muted mb-0">Record, update and track member project contributions.</p>
    </div>
    <button type="button" class="btn btn-primary" id="btnOpenContribution">
        <i class="fas fa-plus"></i> Record Contribution
    </button>
</div>

<?php if (!$hasContributions): ?>
<div class="card"><div class="card-body"><strong>Setup required:</strong> contributions table was not found.</div></div>
<?php else: ?>

<div class="contrib-stats-grid">
    <div class="contrib-stat-card"><div class="contrib-stat-icon"><i class="fas fa-receipt"></i></div><div><div class="contrib-stat-value" id="statCount"><?= number_format($totalContributions) ?></div><div class="contrib-stat-label">Total Records</div></div></div>
    <div class="contrib-stat-card"><div class="contrib-stat-icon"><i class="fas fa-coins"></i></div><div><div class="contrib-stat-value" id="statTotal">UGX <?= number_format($totalAmount) ?></div><div class="contrib-stat-label">Total Amount</div></div></div>
    <div class="contrib-stat-card"><div class="contrib-stat-icon"><i class="fas fa-calendar-day"></i></div><div><div class="contrib-stat-value" id="statToday">UGX <?= number_format($todayAmount) ?></div><div class="contrib-stat-label">Today</div></div></div>
    <div class="contrib-stat-card"><div class="contrib-stat-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="contrib-stat-value" id="statMonth">UGX <?= number_format($thisMonthAmount) ?></div><div class="contrib-stat-label">This Month</div></div></div>
    <div class="contrib-stat-card"><div class="contrib-stat-icon"><i class="fas fa-users"></i></div><div><div class="contrib-stat-value" id="statMembers"><?= number_format($uniqueMembers) ?></div><div class="contrib-stat-label">Contributing Members</div></div></div>
    <div class="contrib-stat-card"><div class="contrib-stat-icon"><i class="fas fa-project-diagram"></i></div><div><div class="contrib-stat-value" id="statProjects"><?= number_format($uniqueProjects) ?></div><div class="contrib-stat-label">Supported Projects</div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="contrib-toolbar">
            <div class="contrib-search"><input type="text" id="tableSearch" placeholder="Search receipt, member, project, method..."></div>
            <select id="filterProject" class="form-control">
                <option value="">All Projects</option>
                <?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= ch($p['name']) ?></option><?php endforeach; ?>
            </select>
            <select id="filterMethod" class="form-control">
                <option value="">All Methods</option><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option>
            </select>
            <button type="button" class="btn btn-outline-secondary" id="btnRefresh"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
        <div id="contribAlert" class="contrib-alert"></div>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr><th>#</th><th>Receipt No</th><th>Member</th><th>Project</th><th>Amount (UGX)</th><th>Method</th><th>Date</th><th>Notes</th><th>Actions</th></tr>
                </thead>
                <tbody id="contributionTableBody"><tr><td colspan="9" class="text-muted p-3">Loading contributions...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<div class="contrib-modal-backdrop" id="contribBackdrop"></div>

<div class="contrib-modal" id="contributionModal" aria-hidden="true">
    <div class="contrib-dialog">
        <form id="contributionForm">
            <div class="contrib-modal-header"><div class="contrib-modal-title" id="contributionTitle"><i class="fas fa-plus"></i> Record Contribution</div><button type="button" class="contrib-close" data-close-modal>&times;</button></div>
            <div class="contrib-modal-body">
                <div id="formAlert" class="contrib-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= ch($csrfToken) ?>">
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="id" id="contributionId" value="">
                <div class="contrib-grid">
                    <div class="contrib-field"><label>Member <span class="text-danger">*</span></label><select name="member_id" id="member_id" required><option value="">-- Select Member --</option><?php foreach ($members as $m): ?><option value="<?= (int)$m['id'] ?>"><?= ch(trim($m['full_name'])) ?><?= !empty($m['member_no']) ? ' ('.ch($m['member_no']).')' : '' ?></option><?php endforeach; ?></select></div>
                    <div class="contrib-field"><label>Project <span class="text-danger">*</span></label><select name="project_id" id="project_id" required><option value="">-- Select Project --</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"><?= ch($p['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="contrib-field"><label>Amount (UGX) <span class="text-danger">*</span></label><input type="number" name="amount" id="amount" min="0" step="0.01" required></div>
                    <div class="contrib-field"><label>Payment Method</label><select name="payment_method" id="payment_method"><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option></select></div>
                    <div class="contrib-field"><label>Contribution Date <span class="text-danger">*</span></label><input type="date" name="contributed_on" id="contributed_on" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="contrib-field"><label>Receipt No</label><input type="text" name="receipt_no" id="receipt_no" placeholder="Auto generated if empty"></div>
                    <div class="contrib-field contrib-full"><label>Notes</label><textarea name="notes" id="notes" rows="3"></textarea></div>
                </div>
            </div>
            <div class="contrib-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary" id="saveContributionBtn"><i class="fas fa-save"></i> Save Contribution</button></div>
        </form>
    </div>
</div>

<div class="contrib-modal" id="viewContributionModal" aria-hidden="true">
    <div class="contrib-dialog">
        <div class="contrib-modal-header"><div class="contrib-modal-title"><i class="fas fa-eye"></i> Contribution Details</div><button type="button" class="contrib-close" data-close-modal>&times;</button></div>
        <div class="contrib-modal-body"><div id="viewContributionContent" class="contrib-view-grid"></div></div>
        <div class="contrib-actions"><a href="#" id="viewReceiptLink" target="_blank" class="btn btn-primary"><i class="fas fa-file-pdf"></i> Download Receipt</a><button type="button" class="btn btn-outline-secondary" data-close-modal>Close</button></div>
    </div>
</div>

<div class="contrib-modal" id="deleteContributionModal" aria-hidden="true">
    <div class="contrib-dialog contrib-dialog-sm">
        <form id="deleteContributionForm">
            <div class="contrib-modal-header"><div class="contrib-modal-title"><i class="fas fa-trash"></i> Delete Contribution</div><button type="button" class="contrib-close" data-close-modal>&times;</button></div>
            <div class="contrib-modal-body">
                <div id="deleteAlert" class="contrib-alert"></div>
                <input type="hidden" name="csrf_token" value="<?= ch($csrfToken) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteContributionId">
                <p>Delete contribution receipt <strong id="deleteReceiptNo"></strong>?</p>
                <p class="text-muted">This action removes the contribution record permanently.</p>
            </div>
            <div class="contrib-actions"><button type="button" class="btn btn-outline-secondary" data-close-modal>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
        </form>
    </div>
</div>

<style>
.contrib-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:20px}.contrib-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;box-shadow:0 10px 25px rgba(15,23,42,.08);display:flex;gap:14px;align-items:center}.contrib-stat-icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;background:rgba(22,163,74,.12);font-size:20px;color:#15803d}.contrib-stat-value{font-size:22px;font-weight:800;line-height:1.2}.contrib-stat-label{font-size:13px;color:#64748b;margin-top:4px}.contrib-toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.contrib-search{min-width:260px;flex:1}.contrib-search input,.contrib-toolbar select,.contrib-field input,.contrib-field select,.contrib-field textarea{width:100%;border:1px solid #d1d5db;border-radius:10px;padding:10px 12px}.contrib-toolbar select{max-width:220px}.contrib-alert{display:none;margin-top:10px;border-radius:10px;padding:10px 12px}.contrib-alert.success{display:block;background:#dcfce7;color:#166534}.contrib-alert.error{display:block;background:#fee2e2;color:#991b1b}.contrib-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);display:none;z-index:9998}.contrib-modal{position:fixed;inset:0;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:30px 14px;z-index:9999}.contrib-modal.show{display:flex}.contrib-modal-backdrop.show{display:block}.contrib-dialog{width:min(850px,100%);background:#fff;border-radius:18px;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden}.contrib-dialog-sm{width:min(520px,100%)}.contrib-modal-header{display:flex;align-items:center;justify-content:space-between;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:16px 18px}.contrib-modal-title{font-weight:800;font-size:18px}.contrib-close{border:0;background:transparent;font-size:28px;line-height:1;cursor:pointer}.contrib-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow-y:auto}.contrib-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.contrib-full{grid-column:1/-1}.contrib-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}.contrib-actions{display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 18px}.contrib-view-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.contrib-detail{padding:10px;border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc}.contrib-detail b{display:block;font-size:12px;color:#64748b;margin-bottom:3px}.table-actions{display:flex;gap:6px;flex-wrap:wrap}.badge{display:inline-flex;align-items:center;gap:4px}@media(max-width:900px){.contrib-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.contrib-grid,.contrib-view-grid{grid-template-columns:1fr}}@media(max-width:560px){.contrib-stats-grid{grid-template-columns:1fr}.contrib-toolbar select{max-width:100%}.contrib-search{min-width:100%}}
</style>

<script>
(function(){
'use strict';
const endpoint='process-contributions.php';
let rows=[];
const qs=(s,r=document)=>r.querySelector(s); const qsa=(s,r=document)=>Array.from(r.querySelectorAll(s));
const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
const money=v=>'UGX '+Number(v||0).toLocaleString();
function alertBox(el,msg,type='success'){if(!el)return;el.className='contrib-alert '+(type==='error'?'error':'success');el.textContent=msg;}
function clearAlert(el){if(el){el.className='contrib-alert';el.textContent='';}}
function openModal(id){qs('#contribBackdrop').classList.add('show');qs(id).classList.add('show');document.body.style.overflow='hidden';}
function closeModals(){qsa('.contrib-modal,.contrib-modal-backdrop').forEach(m=>m.classList.remove('show'));document.body.style.overflow='';}
async function request(url,opts={}){const res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts));const text=await res.text();try{return JSON.parse(text);}catch(e){throw new Error(text?('Invalid server response: '+text.slice(0,220)):'Empty server response from process-contributions.php. Check PHP error log.');}}
async function postForm(form){return request(endpoint,{method:'POST',body:new FormData(form)});}
function renderStats(items){let total=0,today=0,month=0,mem=new Set(),proj=new Set();const now=new Date(), ym=now.toISOString().slice(0,7), td=now.toISOString().slice(0,10);items.forEach(x=>{const amt=Number(x.amount||0);total+=amt;if(x.contributed_on===td)today+=amt;if(String(x.contributed_on||'').slice(0,7)===ym)month+=amt;if(x.member_id)mem.add(x.member_id);if(x.project_id)proj.add(x.project_id);});qs('#statCount').textContent=items.length.toLocaleString();qs('#statTotal').textContent=money(total);qs('#statToday').textContent=money(today);qs('#statMonth').textContent=money(month);qs('#statMembers').textContent=mem.size.toLocaleString();qs('#statProjects').textContent=proj.size.toLocaleString();}
function filteredRows(){const term=(qs('#tableSearch').value||'').toLowerCase();const project=qs('#filterProject').value;const method=qs('#filterMethod').value;return rows.filter(x=>(!project||String(x.project_id)===project)&&(!method||String(x.payment_method)===method)&&Object.values(x).join(' ').toLowerCase().includes(term));}
function renderTable(){const items=filteredRows();const tbody=qs('#contributionTableBody');renderStats(items);if(!items.length){tbody.innerHTML='<tr><td colspan="9" class="text-muted p-3">No contribution records found.</td></tr>';return;}tbody.innerHTML=items.map((c,i)=>`<tr><td>${i+1}</td><td><span class="badge badge-success">${esc(c.receipt_no||'-')}</span></td><td>${esc(c.full_name||'—')}</td><td>${esc(c.project_name||'—')}</td><td><strong>${Number(c.amount||0).toLocaleString()}</strong></td><td><span class="badge badge-info">${esc(c.payment_method||'—')}</span></td><td>${esc(c.contributed_on||'—')}</td><td>${esc(c.notes||'')}</td><td><div class="table-actions"><button type="button" class="btn btn-sm btn-info" data-action="view" data-id="${Number(c.id)}" title="View"><i class="fas fa-eye"></i></button><a class="btn btn-sm btn-success" href="download_receipt.php?id=${Number(c.id)}" target="_blank" title="Receipt"><i class="fas fa-file-pdf"></i></a><button type="button" class="btn btn-sm btn-warning" data-action="edit" data-id="${Number(c.id)}" title="Edit"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${Number(c.id)}" data-receipt="${esc(c.receipt_no||'')}" title="Delete"><i class="fas fa-trash"></i></button></div></td></tr>`).join('');}
async function load(){clearAlert(qs('#contribAlert'));const data=await request(endpoint+'?action=list');if(!data.success)throw new Error(data.message||'Failed to load contributions.');rows=data.items||[];renderTable();}
function resetForm(){qs('#contributionForm').reset();qs('#formAction').value='create';qs('#contributionId').value='';qs('#contributionTitle').innerHTML='<i class="fas fa-plus"></i> Record Contribution';qs('#saveContributionBtn').innerHTML='<i class="fas fa-save"></i> Save Contribution';qs('#contributed_on').value=new Date().toISOString().slice(0,10);clearAlert(qs('#formAlert'));}
async function openEdit(id){resetForm();const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#contribAlert'),data.message||'Record not found.','error');const c=data.item;qs('#formAction').value='update';qs('#contributionId').value=c.id||'';qs('#contributionTitle').innerHTML='<i class="fas fa-edit"></i> Edit Contribution';qs('#saveContributionBtn').innerHTML='<i class="fas fa-save"></i> Update Contribution';['member_id','project_id','amount','payment_method','contributed_on','receipt_no','notes'].forEach(k=>{if(qs('#'+k))qs('#'+k).value=c[k]||'';});openModal('#contributionModal');}
async function openView(id){const data=await request(endpoint+'?action=get&id='+encodeURIComponent(id));if(!data.success)return alertBox(qs('#contribAlert'),data.message||'Record not found.','error');const c=data.item;const fields=[['Receipt No',c.receipt_no],['Member',c.full_name],['Project',c.project_name],['Amount',money(c.amount)],['Payment Method',c.payment_method],['Date',c.contributed_on],['Notes',c.notes]];qs('#viewContributionContent').innerHTML=fields.map(([k,v])=>`<div class="contrib-detail"><b>${esc(k)}</b><span>${esc(v||'—')}</span></div>`).join('');const receiptLink=qs('#viewReceiptLink');if(receiptLink)receiptLink.href='download_receipt.php?id='+encodeURIComponent(id);openModal('#viewContributionModal');}
qs('#btnOpenContribution').addEventListener('click',()=>{resetForm();openModal('#contributionModal');});qs('#btnRefresh').addEventListener('click',load);['#tableSearch','#filterProject','#filterMethod'].forEach(s=>qs(s).addEventListener('input',renderTable));qsa('[data-close-modal]').forEach(b=>b.addEventListener('click',closeModals));qs('#contribBackdrop').addEventListener('click',closeModals);document.addEventListener('keydown',e=>{if(e.key==='Escape')closeModals();});
document.addEventListener('click',e=>{const b=e.target.closest('[data-action]');if(!b)return;const id=b.dataset.id;if(b.dataset.action==='view')openView(id);if(b.dataset.action==='edit')openEdit(id);if(b.dataset.action==='delete'){qs('#deleteContributionId').value=id;qs('#deleteReceiptNo').textContent=b.dataset.receipt||'';clearAlert(qs('#deleteAlert'));openModal('#deleteContributionModal');}});
qs('#contributionForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#formAlert'),data.message||'Save failed.','error');closeModals();alertBox(qs('#contribAlert'),data.message||'Saved successfully.');await load();}catch(err){alertBox(qs('#formAlert'),err.message,'error');}});
qs('#deleteContributionForm').addEventListener('submit',async e=>{e.preventDefault();try{const data=await postForm(e.currentTarget);if(!data.success)return alertBox(qs('#deleteAlert'),data.message||'Delete failed.','error');closeModals();alertBox(qs('#contribAlert'),data.message||'Deleted successfully.');await load();}catch(err){alertBox(qs('#deleteAlert'),err.message,'error');}});
load().catch(err=>alertBox(qs('#contribAlert'),err.message,'error'));
})();
</script>
<?php endif; ?>
</div>
</div>
<?php include '../includes/footer.php'; ?>
