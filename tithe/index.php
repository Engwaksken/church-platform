<?php
require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }
$pageTitle = 'Tithe Receipts';

if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$conn->set_charset('utf8mb4');

function th($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function t_scalar(string $sql): float { global $conn; $res = $conn->query($sql); if (!$res) return 0; $row = $res->fetch_row(); return (float)($row[0] ?? 0); }

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int)date('Y');
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
$members = $conn->query("SELECT id, member_no, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS full_name FROM members ORDER BY first_name,last_name");
$members2 = $conn->query("SELECT id, member_no, CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) AS full_name FROM members ORDER BY first_name,last_name");
$totalAll = t_scalar("SELECT COALESCE(SUM(amount),0) FROM tithe");
$totalYear = t_scalar("SELECT COALESCE(SUM(amount),0) FROM tithe WHERE YEAR(tithe_date)=".$year);
$totalMonth = t_scalar("SELECT COALESCE(SUM(amount),0) FROM tithe WHERE YEAR(tithe_date)=".$year." AND MONTH(tithe_date)=".(int)date('n'));
$countYear = t_scalar("SELECT COUNT(*) FROM tithe WHERE YEAR(tithe_date)=".$year);

include '../includes/header.php';
echo '<link rel="stylesheet" href="../assets/css/style.css?v=' . time() . '">';
include '../includes/sidebar.php';
?>
<div class="main-content tithe-module">
  <?php include '../includes/topbar.php'; ?>
  <div class="content-area">
    <?php if (function_exists('showFlash')) { showFlash(); } ?>
    <div class="page-header module-header">
      <div>
        <h2><i class="fas fa-receipt"></i> Tithe Receipts</h2>
        <p class="text-muted">Record tithes, send payment confirmation by email/SMS, and download PDF receipts.</p>
      </div>
      <button type="button" class="btn btn-primary" id="btnOpenTithe"><i class="fas fa-plus"></i> Record Tithe</button>
    </div>

    <div class="stats-grid stats-grid-3">
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-coins"></i></div><div><div class="stat-number" id="statAll">UGX <?= number_format($totalAll) ?></div><div class="stat-label">Total Tithe</div></div></div>
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-alt"></i></div><div><div class="stat-number" id="statYear">UGX <?= number_format($totalYear) ?></div><div class="stat-label">Year <?= th($year) ?></div></div></div>
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="stat-number" id="statMonth">UGX <?= number_format($totalMonth) ?></div><div class="stat-label">This Month</div></div></div>
      <div class="stat-card"><div class="stat-icon"><i class="fas fa-file-invoice"></i></div><div><div class="stat-number" id="statCount"><?= number_format($countYear) ?></div><div class="stat-label">Receipts This Year</div></div></div>
    </div>

    <div class="card mb-3">
      <div class="card-body toolbar-row module-toolbar">
        <div class="search-box"><input type="text" id="titheSearch" class="form-control" placeholder="Search member, receipt, method..."></div>
        <select id="filterMember" class="form-control">
          <option value="0">All Members</option>
          <?php while ($m = $members ? $members->fetch_assoc() : null): ?>
            <option value="<?= (int)$m['id'] ?>"><?= th(trim($m['full_name'])) ?><?= !empty($m['member_no']) ? ' ('.th($m['member_no']).')' : '' ?></option>
          <?php endwhile; ?>
        </select>
        <select id="filterMonth" class="form-control"><option value="0">All Months</option><?php for ($i=1;$i<=12;$i++): ?><option value="<?= $i ?>"><?= date('F', mktime(0,0,0,$i,1)) ?></option><?php endfor; ?></select>
        <input type="number" id="filterYear" class="form-control" value="<?= (int)$year ?>" min="2000" max="2100">
        <button type="button" class="btn btn-secondary" id="btnRefreshTithe"><i class="fas fa-sync-alt"></i> Refresh</button>
      </div>
    </div>

    <div id="titheAlert" class="ajax-alert"></div>
    <div class="card"><div class="table-responsive"><table class="table module-table">
      <thead><tr><th>#</th><th>Receipt No</th><th>Member</th><th>Amount</th><th>Period</th><th>Date Paid</th><th>Method</th><th>Contact</th><th>Actions</th></tr></thead>
      <tbody id="titheTableBody"><tr><td colspan="9" class="text-center text-muted">Loading tithe records...</td></tr></tbody>
    </table></div></div>
  </div>
</div>

<div class="app-modal-backdrop" id="titheBackdrop"></div>

<div class="app-modal" id="titheFormModal" aria-hidden="true"><div class="app-dialog">
  <form id="titheForm">
    <div class="app-modal-header"><h4 id="titheFormTitle"><i class="fas fa-receipt"></i> Record Tithe</h4><button type="button" class="modal-close" data-close-modal>&times;</button></div>
    <div class="app-modal-body">
      <div id="formAlert" class="ajax-alert"></div>
      <input type="hidden" name="csrf_token" value="<?= th($csrfToken) ?>">
      <input type="hidden" name="action" id="titheAction" value="create">
      <input type="hidden" name="id" id="titheId">
      <div class="form-grid">
        <div class="form-group"><label>Member</label><select name="member_id" id="member_id" class="form-control"><option value="0">Anonymous / Visitor</option><?php while ($m = $members2 ? $members2->fetch_assoc() : null): ?><option value="<?= (int)$m['id'] ?>"><?= th(trim($m['full_name'])) ?><?= !empty($m['member_no']) ? ' ('.th($m['member_no']).')' : '' ?></option><?php endwhile; ?></select></div>
        <div class="form-group"><label>Amount <span class="req">*</span></label><input type="number" step="0.01" min="0" name="amount" id="amount" class="form-control" required></div>
        <div class="form-group"><label>Period Month</label><select name="period_month" id="period_month" class="form-control"><option value="0">N/A</option><?php for ($i=1;$i<=12;$i++): ?><option value="<?= $i ?>"><?= date('F', mktime(0,0,0,$i,1)) ?></option><?php endfor; ?></select></div>
        <div class="form-group"><label>Period Year</label><input type="number" name="period_year" id="period_year" class="form-control" value="<?= date('Y') ?>"></div>
        <div class="form-group"><label>Date Paid</label><input type="date" name="tithe_date" id="tithe_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
        <div class="form-group"><label>Payment Method</label><select name="payment_method" id="payment_method" class="form-control"><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option><option>Other</option></select></div>
        <div class="form-group full-span"><label>Notes</label><textarea name="notes" id="notes" class="form-control" rows="3"></textarea></div>
      </div>
      <small class="text-muted">After saving, confirmation is sent by email/SMS if the selected member has email/phone and settings are configured.</small>
    </div>
    <div class="app-modal-footer"><button type="button" class="btn btn-light" data-close-modal>Cancel</button><button type="submit" class="btn btn-primary" id="saveTitheBtn"><i class="fas fa-save"></i> Save Tithe</button></div>
  </form>
</div></div>

<div class="app-modal" id="viewTitheModal" aria-hidden="true"><div class="app-dialog"><div class="app-modal-header"><h4><i class="fas fa-eye"></i> Tithe Details</h4><button type="button" class="modal-close" data-close-modal>&times;</button></div><div class="app-modal-body"><div id="viewTitheContent" class="detail-grid"></div></div><div class="app-modal-footer"><button class="btn btn-light" data-close-modal>Close</button></div></div></div>
<div class="app-modal" id="deleteTitheModal" aria-hidden="true"><div class="app-dialog app-dialog-sm"><form id="deleteTitheForm"><div class="app-modal-header"><h4><i class="fas fa-trash"></i> Delete Tithe</h4><button type="button" class="modal-close" data-close-modal>&times;</button></div><div class="app-modal-body"><div id="deleteAlert" class="ajax-alert"></div><input type="hidden" name="csrf_token" value="<?= th($csrfToken) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteTitheId"><p>Delete receipt <strong id="deleteReceiptNo"></strong>?</p></div><div class="app-modal-footer"><button type="button" class="btn btn-light" data-close-modal>Cancel</button><button class="btn btn-danger" type="submit"><i class="fas fa-trash"></i> Delete</button></div></form></div></div>

<script>
window.TitheConfig = {endpoint:'process-tithe.php', receiptUrl:'download_receipt.php?tithe_id='};
(function(){
  'use strict';
  var cfg = window.TitheConfig;
  var rows = [];
  var months = ['', 'January','February','March','April','May','June','July','August','September','October','November','December'];
  function qs(s,r){return (r||document).querySelector(s)}
  function qsa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))}
  function esc(v){return String(v == null ? '' : v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}
  function money(v){return 'UGX ' + Number(v||0).toLocaleString()}
  function alertBox(el,msg,type){ if(!el) return; el.className='ajax-alert '+(type==='error'?'error':'success'); el.textContent=msg; }
  function clearAlert(el){ if(el){el.className='ajax-alert'; el.textContent='';} }
  function openModal(id){ var b=qs('#titheBackdrop'), m=qs(id); if(b)b.classList.add('show'); if(m)m.classList.add('show'); document.body.style.overflow='hidden'; }
  function closeModals(){ qsa('.app-modal,.app-modal-backdrop').forEach(function(x){x.classList.remove('show')}); document.body.style.overflow=''; }
  async function req(url, opts){ var res=await fetch(url,Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}},opts||{})); var text=await res.text(); try{return JSON.parse(text)}catch(e){throw new Error('Invalid server response: '+text.slice(0,250));} }
  async function postForm(form){ return req(cfg.endpoint,{method:'POST',body:new FormData(form)}); }
  function period(r){ var m=Number(r.period_month||0); var y=r.period_year||''; return (m>=1&&m<=12?months[m]+' ':'')+(y||'') || 'N/A'; }
  function render(items){ var tb=qs('#titheTableBody'); if(!tb)return; if(!items.length){tb.innerHTML='<tr><td colspan="9" class="text-center text-muted">No tithe records found.</td></tr>';return;} tb.innerHTML=items.map(function(r,i){ var member=(r.full_name||'').trim()||'Anonymous / Visitor'; var contact=[]; if(r.email) contact.push('<span title="Email"><i class="fas fa-envelope"></i></span>'); if(r.phone) contact.push('<span title="SMS"><i class="fas fa-phone"></i></span>'); return '<tr>'+
    '<td>'+(i+1)+'</td><td><span class="badge badge-gold">'+esc(r.receipt_no||('TITHE-'+r.id))+'</span></td><td><strong>'+esc(member)+'</strong>'+(r.member_no?' <small>('+esc(r.member_no)+')</small>':'')+'</td><td><strong>'+money(r.amount)+'</strong></td><td>'+esc(period(r))+'</td><td>'+esc(r.tithe_date||'')+'</td><td>'+esc(r.payment_method||'')+'</td><td>'+(contact.join(' ')||'<span class="text-muted">No contact</span>')+'</td><td><div class="table-action">'+
    '<button class="btn btn-sm btn-info btn-icon" data-action="view" data-id="'+Number(r.id)+'" title="View"><i class="fas fa-eye"></i></button>'+
    '<button class="btn btn-sm btn-primary btn-icon" data-action="edit" data-id="'+Number(r.id)+'" title="Edit"><i class="fas fa-edit"></i></button>'+
    '<a class="btn btn-sm btn-success btn-icon" href="'+cfg.receiptUrl+Number(r.id)+'" target="_blank" title="Receipt"><i class="fas fa-file-pdf"></i></a>'+
    '<button class="btn btn-sm btn-accent btn-icon" data-action="resend" data-id="'+Number(r.id)+'" title="Resend Confirmation"><i class="fas fa-paper-plane"></i></button>'+
    '<button class="btn btn-sm btn-danger btn-icon" data-action="delete" data-id="'+Number(r.id)+'" data-receipt="'+esc(r.receipt_no||('TITHE-'+r.id))+'" title="Delete"><i class="fas fa-trash"></i></button>'+
    '</div></td></tr>'; }).join(''); }
  function applySearch(){ var t=(qs('#titheSearch')?.value||'').toLowerCase(); render(rows.filter(function(r){return Object.values(r).join(' ').toLowerCase().includes(t)})); }
  async function load(){ clearAlert(qs('#titheAlert')); var y=qs('#filterYear')?.value||new Date().getFullYear(); var m=qs('#filterMonth')?.value||0; var member=qs('#filterMember')?.value||0; var d=await req(cfg.endpoint+'?action=list&year='+encodeURIComponent(y)+'&month='+encodeURIComponent(m)+'&member_id='+encodeURIComponent(member)); if(!d.success) throw new Error(d.message||'Failed to load tithe records.'); rows=d.items||[]; applySearch(); }
  function resetForm(){ var f=qs('#titheForm'); if(f)f.reset(); qs('#titheAction').value='create'; qs('#titheId').value=''; qs('#titheFormTitle').innerHTML='<i class="fas fa-receipt"></i> Record Tithe'; qs('#saveTitheBtn').innerHTML='<i class="fas fa-save"></i> Save Tithe'; clearAlert(qs('#formAlert')); }
  async function openEdit(id){ resetForm(); var d=await req(cfg.endpoint+'?action=get&id='+encodeURIComponent(id)); if(!d.success){alertBox(qs('#titheAlert'),d.message||'Not found','error');return;} var r=d.item; qs('#titheAction').value='update'; qs('#titheId').value=r.id||''; qs('#member_id').value=r.member_id||0; qs('#amount').value=r.amount||''; qs('#period_month').value=r.period_month||0; qs('#period_year').value=r.period_year||new Date().getFullYear(); qs('#tithe_date').value=r.tithe_date||''; qs('#payment_method').value=r.payment_method||'Cash'; qs('#notes').value=r.notes||''; qs('#titheFormTitle').innerHTML='<i class="fas fa-edit"></i> Edit Tithe'; qs('#saveTitheBtn').innerHTML='<i class="fas fa-save"></i> Update Tithe'; openModal('#titheFormModal'); }
  async function openView(id){ var d=await req(cfg.endpoint+'?action=get&id='+encodeURIComponent(id)); if(!d.success){alertBox(qs('#titheAlert'),d.message||'Not found','error');return;} var r=d.item; var member=(r.full_name||'').trim()||'Anonymous / Visitor'; var fields=[['Receipt No',r.receipt_no||('TITHE-'+r.id)],['Member',member],['Member No',r.member_no],['Amount',money(r.amount)],['Period',period(r)],['Date Paid',r.tithe_date],['Method',r.payment_method],['Email',r.email],['Phone',r.phone],['Notes',r.notes]]; qs('#viewTitheContent').innerHTML=fields.map(function(f){return '<div class="detail-item"><b>'+esc(f[0])+'</b><span>'+esc(f[1]||'—')+'</span></div>'}).join(''); openModal('#viewTitheModal'); }
  document.addEventListener('click',function(e){ var close=e.target.closest('[data-close-modal]'); if(close){closeModals();return;} var add=e.target.closest('#btnOpenTithe'); if(add){resetForm();openModal('#titheFormModal');return;} var btn=e.target.closest('[data-action]'); if(!btn)return; var id=btn.dataset.id, action=btn.dataset.action; if(action==='view') openView(id).catch(function(err){alertBox(qs('#titheAlert'),err.message,'error')}); if(action==='edit') openEdit(id).catch(function(err){alertBox(qs('#titheAlert'),err.message,'error')}); if(action==='delete'){ qs('#deleteTitheId').value=id; qs('#deleteReceiptNo').textContent=btn.dataset.receipt||''; clearAlert(qs('#deleteAlert')); openModal('#deleteTitheModal'); } if(action==='resend'){ var fd=new FormData(); fd.append('action','resend_confirmation'); fd.append('id',id); req(cfg.endpoint,{method:'POST',body:fd}).then(function(d){ if(!d.success) throw new Error(d.message||'Failed'); alertBox(qs('#titheAlert'),'Confirmation processed. Email: '+(d.email_sent?'sent':'not sent')+', SMS: '+(d.sms_sent?'sent':'not sent')+'.'); }).catch(function(err){alertBox(qs('#titheAlert'),err.message,'error')}); }});
  qs('#titheBackdrop')?.addEventListener('click',closeModals); document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModals()}); qsa('#filterMember,#filterMonth,#filterYear').forEach(function(el){el.addEventListener('change',function(){load().catch(function(err){alertBox(qs('#titheAlert'),err.message,'error')})})}); qs('#btnRefreshTithe')?.addEventListener('click',function(){load().catch(function(err){alertBox(qs('#titheAlert'),err.message,'error')})}); qs('#titheSearch')?.addEventListener('input',applySearch);
  qs('#titheForm')?.addEventListener('submit',async function(e){ e.preventDefault(); try{ var d=await postForm(e.currentTarget); if(!d.success){alertBox(qs('#formAlert'),d.message||'Save failed','error');return;} closeModals(); alertBox(qs('#titheAlert'),d.message||'Saved successfully.'); await load(); }catch(err){alertBox(qs('#formAlert'),err.message,'error')} });
  qs('#deleteTitheForm')?.addEventListener('submit',async function(e){ e.preventDefault(); try{ var d=await postForm(e.currentTarget); if(!d.success){alertBox(qs('#deleteAlert'),d.message||'Delete failed','error');return;} closeModals(); alertBox(qs('#titheAlert'),d.message||'Deleted successfully.'); await load(); }catch(err){alertBox(qs('#deleteAlert'),err.message,'error')} });
  load().catch(function(err){alertBox(qs('#titheAlert'),err.message,'error')});
})();
</script>
<?php include '../includes/footer.php'; ?>
