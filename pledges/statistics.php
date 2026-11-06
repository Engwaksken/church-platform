<?php
require_once '../config/config.php';
requireLogin();
require_once '../includes/access.php';
if (is_member_user() || !current_user_can('pledges.view')) { http_response_code(403); exit('Access denied.'); }
$pageTitle = 'Pledge Statistics';
include '../includes/header.php';
include '../includes/sidebar.php';
function ps_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<div class="main-content pledges-module"><div class="content-area">
<div class="page-header"><div><h2><i class="fas fa-chart-bar text-primary"></i> Pledge Statistics</h2><p class="text-muted">Search, filter and review pledge records and payments.</p></div><a class="btn btn-outline" href="index.php"><i class="fas fa-arrow-left"></i> Back to Pledges</a></div>
<div class="stats-grid module-stats-grid ps-stats"><button data-stat="all" class="stat-card"><div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div><div><div class="stat-value" id="psPledged">UGX 0</div><div class="stat-label">Total Pledged</div></div></button><button data-stat="paid" class="stat-card success"><div class="stat-icon"><i class="fas fa-coins"></i></div><div><div class="stat-value" id="psPaid">UGX 0</div><div class="stat-label">Total Paid</div></div></button><button data-stat="month" class="stat-card info"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="stat-value" id="psMonth">UGX 0</div><div class="stat-label">Paid This Month</div></div></button><button data-stat="open" class="stat-card accent"><div class="stat-icon"><i class="fas fa-clock"></i></div><div><div class="stat-value" id="psOpen">0</div><div class="stat-label">Open Pledges</div></div></button></div>
<div class="card"><div class="card-body"><div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input id="psSearch" class="form-control" type="search" placeholder="Search pledge, member, purpose..."></div><div class="page-toolbar-filters"><select id="psYear" class="form-control"><option value="">All years</option><?php for($y=(int)date('Y')+1;$y>=2000;$y--):?><option value="<?=$y?>"><?=$y?></option><?php endfor;?></select><select id="psType" class="form-control"><option value="">All types</option></select><select id="psStatus" class="form-control"><option value="">All statuses</option><option>Pending</option><option>Partial</option><option>Fulfilled</option><option>Cancelled</option></select></div></div><div id="psError" class="ajax-alert"></div><div class="table-responsive"><table class="table module-table"><thead><tr><th>#</th><th>Pledge No</th><th>Member</th><th>Purpose</th><th>Pledged</th><th>Paid</th><th>Balance</th><th>Date</th><th>Status</th></tr></thead><tbody id="psBody"></tbody></table></div><div class="ps-pagination"><span id="psSummary">Showing 0 records</span><div><select id="psSize" class="form-control"><option>10</option><option selected>25</option><option>50</option><option>100</option></select><button class="btn btn-outline" id="psPrev">‹ Previous</button><span id="psPage">1</span><button class="btn btn-outline" id="psNext">Next ›</button></div></div></div></div>
</div></div>
<style>
.ps-stats{grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.ps-stats .stat-card{padding:13px;min-height:78px;border:0;text-align:left;width:100%;cursor:pointer}
.ps-stats .stat-icon{width:36px;height:36px;font-size:15px}
.ps-stats .stat-value{font-size:1.15rem}
.ps-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:14px}
.ps-pagination>div{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ps-pagination select{width:82px}
.ps-pagination button,.ps-stats button{font:inherit}
.pledges-module,.pledges-module>.content-area{width:100%;max-width:100%;min-width:0}
.pledges-module .table-responsive{display:block;width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.pledges-module .module-table{min-width:850px;table-layout:auto}
@media(max-width:720px){.ps-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ps-pagination{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){
 .pledges-module .content-area{padding:12px 10px 24px}
 .ps-stats{gap:8px}
 .ps-stats .stat-card{min-width:0;min-height:70px;padding:9px;gap:7px}
 .ps-stats .stat-icon{flex:0 0 30px;width:30px;height:30px}
 .ps-stats .stat-value{font-size:clamp(.78rem,3.4vw,1rem);overflow-wrap:anywhere}
 .pledges-module .card-body{padding:12px 10px}
 .ps-pagination>div{width:100%;justify-content:space-between}
 .ps-pagination button{padding:7px 9px}
}
</style>
<script>
(function(){'use strict';const endpoint='process-pledges.php';let all=[],filtered=[],page=1,statFilter='all',monthIds=null;
const $=s=>document.querySelector(s), money=n=>'UGX '+Number(n||0).toLocaleString();
async function get(q){const r=await fetch(endpoint+'?'+new URLSearchParams(q),{headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();if(!d.success)throw Error(d.message||'Could not load pledge information.');return d;}
function apply(reset=true){if(reset)page=1;const q=$('#psSearch').value.trim().toLowerCase(),y=$('#psYear').value,t=$('#psType').value,s=$('#psStatus').value;filtered=all.filter(p=>{const purpose=p.purpose||p.project_name||p.pledge_reason||'General Pledge';return(!q||Object.values(p).join(' ').toLowerCase().includes(q))&&(!y||String(p.pledge_date||'').slice(0,4)===y)&&(!t||purpose===t)&&(!s||p.status===s)&&(statFilter!=='paid'||Number(p.amount_paid||0)>0)&&(statFilter!=='month'||(monthIds?.has(Number(p.id))??false))&&(statFilter!=='open'||['Pending','Partial'].includes(p.status))});draw();}
function draw(){const size=Number($('#psSize').value)||25,pages=Math.max(1,Math.ceil(filtered.length/size));page=Math.min(page,pages);const start=(page-1)*size,items=filtered.slice(start,start+size),esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));$('#psBody').innerHTML=items.length?items.map((p,i)=>{const bal=Number(p.amount_pledged||0)-Number(p.amount_paid||0),purpose=p.purpose||p.project_name||p.pledge_reason||'General Pledge';return `<tr><td>${start+i+1}</td><td>${esc(p.pledge_no||'—')}</td><td>${esc(p.full_name||'—')}<br><small>${esc(p.member_no||'')}</small></td><td>${esc(purpose)}</td><td>${money(p.amount_pledged)}</td><td>${money(p.amount_paid)}</td><td>${money(bal)}</td><td>${esc(p.pledge_date||'—')}</td><td>${esc(p.status||'—')}</td></tr>`}).join(''):'<tr><td colspan="9" class="text-center text-muted">No pledges match these filters.</td></tr>';$('#psSummary').textContent=filtered.length?`Showing ${start+1}–${start+items.length} of ${filtered.length}`:'Showing 0 records';$('#psPage').textContent=`${page} / ${pages}`;$('#psPrev').disabled=page<=1;$('#psNext').disabled=page>=pages;}
async function load(){try{const [d,m]=await Promise.all([get({action:'list'}),get({action:'payments',month:new Date().toISOString().slice(0,7)})]);all=d.items||[];const st=d.stats||{};$('#psPledged').textContent=money(st.total);$('#psPaid').textContent=money(st.paid);$('#psMonth').textContent=money((m.items||[]).reduce((a,p)=>a+Number(p.amount||0),0));$('#psOpen').textContent=Number(st.pending||0)+Number(st.partial||0);const types=[...new Set(all.map(p=>p.purpose||p.project_name||p.pledge_reason||'General Pledge'))].sort();$('#psType').innerHTML='<option value="">All types</option>'+types.map(t=>`<option>${t.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</option>`).join('');apply();}catch(e){$('#psError').className='ajax-alert error';$('#psError').textContent=e.message;}}
['#psSearch'].forEach(s=>$(s).addEventListener('input',()=>apply()));['#psYear','#psType','#psStatus','#psSize'].forEach(s=>$(s).addEventListener('change',()=>apply()));$('#psPrev').addEventListener('click',()=>{page--;draw()});$('#psNext').addEventListener('click',()=>{page++;draw()});document.querySelectorAll('[data-stat]').forEach(b=>b.addEventListener('click',async()=>{statFilter=b.dataset.stat;monthIds=null;$('#psStatus').value='';$('#psSearch').value='';if(statFilter==='month'){try{const m=await get({action:'payments',month:new Date().toISOString().slice(0,7)});monthIds=new Set((m.items||[]).map(p=>Number(p.pledge_id)));}catch(e){$('#psError').className='ajax-alert error';$('#psError').textContent=e.message;return;}}apply();}));load();})();
</script>
<?php include '../includes/footer.php'; ?>
