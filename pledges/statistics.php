<?php
require_once '../config/config.php';
requireLogin();
require_once '../includes/access.php';
if (is_member_user() || !current_user_can('pledges.view')) { http_response_code(403); exit('Access denied.'); }
require_once '../includes/member-fields.php';
$zoneOptions = isset($conn) && $conn instanceof mysqli ? member_fields_zone_options($conn) : [];
$serviceOptions = isset($conn) && $conn instanceof mysqli && member_fields_ensure($conn) ? member_fields_service_options($conn) : [];
$pageTitle = 'Pledge Statistics';
include '../includes/header.php';
include '../includes/sidebar.php';
function ps_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<div class="main-content pledges-module"><div class="content-area">
<div class="page-header"><div><h2><i class="fas fa-chart-bar text-primary"></i> Pledge Statistics</h2><p class="text-muted">Filter pledges by zone, service and period; the cards below follow the filters.</p></div><a class="btn btn-outline" href="index.php"><i class="fas fa-arrow-left"></i> Back to Pledges</a></div>

<div class="card ps-filter-card"><div class="card-body">
  <div class="ps-filters">
    <label><span>Zone</span><select id="psZone" class="form-control"><option value="">All zones</option><?php foreach ($zoneOptions as $z): ?><option value="<?= (int)$z['id'] ?>"><?= ps_h($z['name']) ?></option><?php endforeach; ?></select></label>
    <label><span>Service</span><select id="psService" class="form-control"><option value="">All services</option><?php foreach ($serviceOptions as $so): ?><option value="<?= ps_h($so) ?>"><?= ps_h($so) ?></option><?php endforeach; ?></select></label>
    <label><span>Period</span><select id="psPeriod" class="form-control">
      <option value="">All time</option>
      <option value="this_week">This week</option>
      <option value="this_month">This month</option>
      <option value="last_month">Last month</option>
      <option value="last_30">Last 30 days</option>
      <option value="this_quarter">This quarter</option>
      <option value="this_year">This year</option>
      <option value="last_year">Last year</option>
      <option value="custom">Custom range…</option>
    </select></label>
    <label class="ps-custom" hidden><span>From</span><input type="date" id="psFrom" class="form-control"></label>
    <label class="ps-custom" hidden><span>To</span><input type="date" id="psTo" class="form-control"></label>
    <button type="button" class="btn btn-outline" id="psReset"><i class="fas fa-rotate-left"></i> Reset</button>
  </div>
  <small class="text-muted" id="psFilterNote" role="status"></small>
</div></div>

<div class="stats-grid module-stats-grid ps-stats">
  <button data-stat="all" class="stat-card active"><div class="stat-icon"><i class="fas fa-hand-holding-heart"></i></div><div><div class="stat-value" id="psPledged">UGX 0</div><div class="stat-label" id="psPledgedLabel">Total Pledged</div></div></button>
  <button data-stat="paid" class="stat-card success"><div class="stat-icon"><i class="fas fa-coins"></i></div><div><div class="stat-value" id="psPaid">UGX 0</div><div class="stat-label">Paid on These Pledges</div></div></button>
  <button data-stat="month" class="stat-card info"><div class="stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="stat-value" id="psMonth">UGX 0</div><div class="stat-label" id="psMonthLabel">Paid This Month</div></div></button>
  <button data-stat="open" class="stat-card accent"><div class="stat-icon"><i class="fas fa-clock"></i></div><div><div class="stat-value" id="psOpen">0</div><div class="stat-label">Open Pledges</div></div></button>
  <button data-stat="balance" class="stat-card danger"><div class="stat-icon"><i class="fas fa-scale-unbalanced"></i></div><div><div class="stat-value" id="psBalance">UGX 0</div><div class="stat-label">Outstanding Balance</div></div></button>
  <button data-stat="members" class="stat-card"><div class="stat-icon"><i class="fas fa-users"></i></div><div><div class="stat-value" id="psMembers">0</div><div class="stat-label">Members Pledging</div></div></button>
</div>

<div class="card"><div class="card-body"><div class="page-toolbar"><div class="page-toolbar-search"><i class="fas fa-search"></i><input id="psSearch" class="form-control" type="search" placeholder="Search pledge, member, purpose..."></div><div class="page-toolbar-filters"><select id="psYear" class="form-control"><option value="">All years</option><?php for($y=(int)date('Y')+1;$y>=2000;$y--):?><option value="<?=$y?>"><?=$y?></option><?php endfor;?></select><select id="psType" class="form-control"><option value="">All types</option></select><select id="psStatus" class="form-control"><option value="">All statuses</option><option>Pending</option><option>Partial</option><option>Fulfilled</option><option>Cancelled</option></select></div></div><div id="psError" class="ajax-alert"></div><div class="table-responsive"><table class="table module-table"><thead><tr><th>#</th><th>Pledge No</th><th>Member</th><th>Zone</th><th>Service</th><th>Purpose</th><th>Pledged</th><th>Paid</th><th>Balance</th><th>Date</th><th>Status</th></tr></thead><tbody id="psBody"></tbody></table></div><div class="ps-pagination"><span id="psSummary">Showing 0 records</span><div><select id="psSize" class="form-control"><option>10</option><option selected>25</option><option>50</option><option>100</option></select><button class="btn btn-outline" id="psPrev">‹ Previous</button><span id="psPage">1</span><button class="btn btn-outline" id="psNext">Next ›</button></div></div></div></div>
</div></div>
<style>
.ps-filter-card{margin-bottom:14px}
.ps-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}
.ps-filters label{display:flex;flex-direction:column;gap:4px;margin:0;min-width:150px;flex:1 1 150px}
.ps-filters label[hidden]{display:none}
.ps-filters label span{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted)}
.ps-filters .btn{flex:0 0 auto}
#psFilterNote{display:block;margin-top:8px}
.ps-stats{grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.ps-stats .stat-card{padding:13px;min-height:78px;border:0;text-align:left;width:100%;cursor:pointer}
.ps-stats .stat-card.active{outline:3px solid #f0b429;outline-offset:2px}
.ps-stats .stat-icon{width:36px;height:36px;font-size:15px}
.ps-stats .stat-value{font-size:1.15rem}
.ps-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-top:14px}
.ps-pagination>div{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.ps-pagination select{width:82px}
.ps-pagination button,.ps-stats button{font:inherit}
.pledges-module,.pledges-module>.content-area{width:100%;max-width:100%;min-width:0}
.pledges-module .table-responsive{display:block;width:100%;max-width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.pledges-module .module-table{min-width:1000px;table-layout:auto}
@media(max-width:720px){.ps-stats{grid-template-columns:repeat(2,minmax(0,1fr))!important}.ps-pagination{align-items:flex-start;flex-direction:column}}
@media(max-width:600px){
 .pledges-module .content-area{padding:12px 10px 24px}
 .ps-filters label{flex:1 1 calc(50% - 10px);min-width:0}
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
(function(){'use strict';
const endpoint='process-pledges.php';
let all=[],payments=[],filtered=[],page=1,statFilter='all',loadSeq=0;
const $=s=>document.querySelector(s), money=n=>'UGX '+Number(n||0).toLocaleString();
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const purposeOf=p=>p.purpose||p.project_name||p.pledge_reason||'General Pledge';
const pad=n=>String(n).padStart(2,'0'), ymd=d=>`${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
const fmtDate=s=>new Date(s+'T00:00:00').toLocaleDateString(undefined,{day:'numeric',month:'short',year:'numeric'});

/* Selected period as {from,to,label} (local dates, inclusive), or null for all time. */
function period(){
  const v=$('#psPeriod').value, now=new Date(), y=now.getFullYear(), m=now.getMonth();
  const d=(yy,mm,dd)=>new Date(yy,mm,dd);
  let from,to=now,label;
  switch(v){
    case 'this_week': { const off=(now.getDay()+6)%7; from=d(y,m,now.getDate()-off); label='This Week'; break; }
    case 'this_month': from=d(y,m,1); label='This Month'; break;
    case 'last_month': from=d(y,m-1,1); to=d(y,m,0); label='Last Month'; break;
    case 'last_30': from=d(y,m,now.getDate()-29); label='Last 30 Days'; break;
    case 'this_quarter': from=d(y,Math.floor(m/3)*3,1); label='This Quarter'; break;
    case 'this_year': from=d(y,0,1); label='This Year'; break;
    case 'last_year': from=d(y-1,0,1); to=d(y-1,11,31); label='Last Year'; break;
    case 'custom': {
      const f=$('#psFrom').value, t=$('#psTo').value;
      if(!f&&!t) return null;
      return {from:f||'1900-01-01',to:t||ymd(now),label:(f?fmtDate(f):'Start')+' – '+(t?fmtDate(t):'Today')};
    }
    default: return null;
  }
  return {from:ymd(from),to:ymd(to),label};
}

async function get(q){const r=await fetch(endpoint+'?'+new URLSearchParams(q),{headers:{'X-Requested-With':'XMLHttpRequest'}});const d=await r.json();if(!d.success)throw Error(d.message||'Could not load pledge information.');return d;}

/* Zone, service and the payment date range are filtered on the server; everything else here. */
async function load(){
  const seq=++loadSeq, member={zone_id:$('#psZone').value,service:$('#psService').value}, per=period();
  const payQ=Object.assign({action:'payments'},member,per?{date_from:per.from,date_to:per.to}:{month:ymd(new Date()).slice(0,7)});
  $('#psError').className='ajax-alert';$('#psError').textContent='';
  try{
    const [d,p]=await Promise.all([get(Object.assign({action:'list'},member)),get(payQ)]);
    if(seq!==loadSeq) return;
    all=d.items||[]; payments=p.items||[];
    const keep=$('#psType').value, types=[...new Set(all.map(purposeOf))].sort();
    $('#psType').innerHTML='<option value="">All types</option>'+types.map(t=>`<option${t===keep?' selected':''}>${esc(t)}</option>`).join('');
    apply();
  }catch(e){ if(seq===loadSeq){$('#psError').className='ajax-alert error';$('#psError').textContent=e.message;} }
}

function matchesTable(p){
  const q=$('#psSearch').value.trim().toLowerCase(), y=$('#psYear').value, t=$('#psType').value, s=$('#psStatus').value;
  return (!q||Object.values(p).join(' ').toLowerCase().includes(q))
    &&(!y||String(p.pledge_year||p.pledge_date||'').slice(0,4)===y)
    &&(!t||purposeOf(p)===t)&&(!s||p.status===s);
}

function apply(reset=true){
  if(reset) page=1;
  const per=period(), s=$('#psStatus').value;
  const matching=all.filter(matchesTable);
  const base=per?matching.filter(p=>p.pledge_date&&p.pledge_date>=per.from&&p.pledge_date<=per.to):matching;
  const counted=s==='Cancelled'?base:base.filter(p=>p.status!=='Cancelled');
  const sum=(list,k)=>list.reduce((a,p)=>a+Number(p[k]||0),0);
  // Payments in the period on pledges that match the search / year / type / status filters.
  const matchIds=new Set(matching.map(p=>Number(p.id)));
  const periodPays=payments.filter(x=>matchIds.has(Number(x.pledge_id)));
  const paidIds=new Set(periodPays.map(x=>Number(x.pledge_id)));

  $('#psPledged').textContent=money(sum(counted,'amount_pledged'));
  $('#psPledgedLabel').textContent=per?'Pledged — '+per.label:'Total Pledged';
  $('#psPaid').textContent=money(sum(counted,'amount_paid'));
  $('#psMonth').textContent=money(sum(periodPays,'amount'));
  $('#psMonthLabel').textContent=per?'Received — '+per.label:'Paid This Month';
  $('#psOpen').textContent=counted.filter(p=>['Pending','Partial'].includes(p.status)).length.toLocaleString();
  $('#psBalance').textContent=money(counted.reduce((a,p)=>a+Math.max(0,Number(p.amount_pledged||0)-Number(p.amount_paid||0)),0));
  $('#psMembers').textContent=new Set(counted.map(p=>p.member_id).filter(Boolean)).size.toLocaleString();

  const notes=[];
  if($('#psZone').value) notes.push('Zone: '+$('#psZone').selectedOptions[0].textContent);
  if($('#psService').value) notes.push('Service: '+$('#psService').value);
  if(per) notes.push(`Period: ${per.label} (${fmtDate(per.from)} – ${fmtDate(per.to)})`);
  $('#psFilterNote').textContent=notes.length?notes.join(' · '):'Showing all zones, services and dates.';

  if(statFilter==='month') filtered=matching.filter(p=>paidIds.has(Number(p.id)));
  else filtered=base.filter(p=>(statFilter!=='paid'||Number(p.amount_paid||0)>0)
    &&(statFilter!=='open'||['Pending','Partial'].includes(p.status))
    &&(statFilter!=='balance'||(p.status!=='Cancelled'&&Number(p.amount_pledged||0)>Number(p.amount_paid||0))));
  draw();
}

function draw(){
  const size=Number($('#psSize').value)||25,pages=Math.max(1,Math.ceil(filtered.length/size));page=Math.min(page,pages);
  const start=(page-1)*size,items=filtered.slice(start,start+size);
  $('#psBody').innerHTML=items.length?items.map((p,i)=>{const bal=Number(p.amount_pledged||0)-Number(p.amount_paid||0);return `<tr><td>${start+i+1}</td><td>${esc(p.pledge_no||'—')}</td><td>${esc(p.full_name||'—')}<br><small>${esc(p.member_no||'')}</small></td><td>${esc(p.zone_name||'—')}</td><td>${esc(p.service_attended||'—')}</td><td>${esc(purposeOf(p))}</td><td>${money(p.amount_pledged)}</td><td>${money(p.amount_paid)}</td><td>${money(bal)}</td><td>${esc(p.pledge_date||'—')}</td><td>${esc(p.status||'—')}</td></tr>`}).join(''):'<tr><td colspan="11" class="text-center text-muted">No pledges match these filters.</td></tr>';
  $('#psSummary').textContent=filtered.length?`Showing ${start+1}–${start+items.length} of ${filtered.length}`:'Showing 0 records';
  $('#psPage').textContent=`${page} / ${pages}`;$('#psPrev').disabled=page<=1;$('#psNext').disabled=page>=pages;
}

$('#psSearch').addEventListener('input',()=>apply());
['#psYear','#psType','#psStatus'].forEach(s=>$(s).addEventListener('change',()=>apply()));
$('#psSize').addEventListener('change',()=>apply());
['#psZone','#psService'].forEach(s=>$(s).addEventListener('change',load));
$('#psPeriod').addEventListener('change',()=>{const c=$('#psPeriod').value==='custom';document.querySelectorAll('.ps-custom').forEach(l=>l.hidden=!c);if(c&&!$('#psFrom').value&&!$('#psTo').value){$('#psFrom').focus();return;}load();});
['#psFrom','#psTo'].forEach(s=>$(s).addEventListener('change',load));
$('#psReset').addEventListener('click',()=>{['#psZone','#psService','#psPeriod','#psFrom','#psTo','#psSearch','#psYear','#psType','#psStatus'].forEach(s=>$(s).value='');document.querySelectorAll('.ps-custom').forEach(l=>l.hidden=true);statFilter='all';document.querySelectorAll('[data-stat]').forEach(b=>b.classList.toggle('active',b.dataset.stat==='all'));load();});
$('#psPrev').addEventListener('click',()=>{page--;draw()});$('#psNext').addEventListener('click',()=>{page++;draw()});
document.querySelectorAll('[data-stat]').forEach(b=>b.addEventListener('click',()=>{statFilter=b.dataset.stat==='members'?'all':b.dataset.stat;document.querySelectorAll('[data-stat]').forEach(x=>x.classList.toggle('active',x===b));apply();}));
load();
})();
</script>
<?php include '../includes/footer.php'; ?>
