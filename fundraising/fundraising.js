(function(){
  'use strict';
  const cfg = window.FundraisingConfig || {endpoint:'process-fundraising.php'};
  let campaigns = [], currentCampaign = null, membersLoaded = false;
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));
  const money = n => 'UGX ' + Number(n || 0).toLocaleString();
  const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));

  /* =========================================================
     ALERTS / MODALS
  ========================================================= */
  function alertBox(el, msg, type='success'){
    if(!el) return;
    el.className = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
    el.textContent = msg || '';
  }
  function clearAlert(el){ if(el){ el.className='ajax-alert'; el.textContent=''; } }

  function openModal(id){
    const b=$('#frBackdrop'), m=$(id);
    if(b) b.classList.add('show');
    if(m) m.classList.add('show');
    document.body.classList.add('modal-open');
  }
  function closeModals(){
    $$('.ajax-modal,.ajax-modal-backdrop').forEach(x=>x.classList.remove('show'));
    document.body.classList.remove('modal-open');
  }

  /* =========================================================
     HTTP
  ========================================================= */
  async function api(url, opts={}){
    const res = await fetch(url, Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}}, opts));
    const text = await res.text();
    try { return JSON.parse(text); }
    catch(e){ throw new Error('Invalid server response: ' + text.slice(0, 250)); }
  }
  async function post(form){ return api(cfg.endpoint, {method:'POST', body:new FormData(form)}); }

  /* =========================================================
     EXPORT HELPERS
  ========================================================= */

  /** Build export URL for campaigns, picking up the status filter from the bar. */
  function campaignExportUrl(action){
    const status = $('#frExportStatus')?.value || '';
    let url = cfg.endpoint + '?action=' + action;
    if(status) url += '&status=' + encodeURIComponent(status);
    return url;
  }

  /** Build export URL for a single campaign's collections. */
  function collectionExportUrl(action, campaignId){
    return cfg.endpoint + '?action=' + action + '&campaign_id=' + encodeURIComponent(campaignId);
  }

  function triggerDownload(url){
    const a = document.createElement('a');
    a.href = url; a.style.display = 'none';
    document.body.appendChild(a); a.click(); a.remove();
  }
  function openTab(url){ window.open(url, '_blank'); }

  function toggleExportBar(show){
    const bar = $('#frExportBar');
    if(bar) bar.style.display = show ? 'flex' : 'none';
  }

  // Track which type is pending so the bar's Download button knows what to fire
  let _exportTarget = null; // 'csv' | 'pdf'

  function activateExportBar(type){
    _exportTarget = type;
    toggleExportBar(true);
    const dlBtn = $('#frBtnExportBarGo');
    if(dlBtn){
      dlBtn.innerHTML = type === 'pdf'
        ? '<i class="fas fa-file-pdf"></i> Open PDF'
        : '<i class="fas fa-file-csv"></i> Download CSV';
    }
  }

  function executeExport(){
    const url = campaignExportUrl(_exportTarget === 'pdf' ? 'export_pdf' : 'export_csv');
    if(_exportTarget === 'pdf') openTab(url);
    else triggerDownload(url);
  }

  /* =========================================================
     RENDER HELPERS
  ========================================================= */
  function statusBadge(s){
    const val = s || 'Planning';
    const cls = val === 'Active' ? 'badge-success' : (val === 'Completed' ? 'badge-info' : (val === 'Cancelled' ? 'badge-danger' : 'badge-warning'));
    return `<span class="badge ${cls}">${esc(val)}</span>`;
  }
  function progressBar(pct){ return `<div class="progress"><div class="progress-bar" style="width:${pct}%">${pct}%</div></div>`; }

  /* =========================================================
     STATS
  ========================================================= */
  function renderStats(){
    const target    = campaigns.reduce((s,x)=>s+Number(x.target_amount||0),0);
    const raised    = campaigns.reduce((s,x)=>s+Number(x.raised||0),0);
    const donations = campaigns.reduce((s,x)=>s+Number(x.donations||0),0);
    const pct = target > 0 ? Math.min(100, Math.round((raised/target)*100)) : 0;
    $('#frStatCampaigns').textContent  = campaigns.length;
    $('#frStatActive').textContent     = campaigns.filter(x=>(x.status||'')==='Active').length;
    $('#frStatCollections').textContent= donations;
    $('#frStatRaised').textContent     = money(raised);
    $('#frStatTarget').textContent     = money(target);
    $('#frStatPercent').textContent    = pct + '%';
  }

  /* =========================================================
     CAMPAIGNS GRID
  ========================================================= */
  function filteredCampaigns(){
    const term   = ($('#frSearch')?.value || '').toLowerCase();
    const status = $('#frStatusFilter')?.value || '';
    return campaigns.filter(c => {
      const hay = Object.values(c).join(' ').toLowerCase();
      return (!term || hay.includes(term)) && (!status || c.status === status);
    });
  }

  function renderCampaigns(){
    const grid  = $('#frCampaignGrid');
    const items = filteredCampaigns();
    if(!items.length){ grid.innerHTML = '<div class="text-muted p-3">No fundraising campaigns found.</div>'; return; }
    grid.innerHTML = items.map(c => {
      const pct = Number(c.target_amount||0) > 0
        ? Math.min(100, Math.round(Number(c.raised||0)/Number(c.target_amount||1)*100))
        : 0;
      return `<div class="fundraising-card">
        <div class="fundraising-card-header"><strong>${esc(c.title)}</strong>${statusBadge(c.status)}</div>
        <div class="fundraising-card-body">
          <p class="text-muted small">${esc(c.description || 'No description')}</p>
          <div class="campaign-money-row">
            <span>Target: <b>${money(c.target_amount)}</b></span>
            <span>Raised: <b>${money(c.raised)}</b></span>
          </div>
          ${progressBar(pct)}
          <div class="mini-stat-row">
            <div><b>${Number(c.donations||0)}</b><small>Donations</small></div>
            <div><b>${esc(c.start_date || '—')}</b><small>Start</small></div>
            <div><b>${esc(c.end_date || 'Ongoing')}</b><small>End</small></div>
          </div>
        </div>
        <div class="fundraising-card-footer">
          <button class="btn btn-sm btn-info"    data-fr-action="view"            data-id="${Number(c.id)}"><i class="fas fa-eye"></i> View</button>
          <button class="btn btn-sm btn-success"  data-fr-action="collect"         data-id="${Number(c.id)}"><i class="fas fa-plus"></i> Collection</button>
          <button class="btn btn-sm btn-warning"  data-fr-action="edit"            data-id="${Number(c.id)}"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-danger"   data-fr-action="delete_campaign" data-id="${Number(c.id)}"><i class="fas fa-trash"></i></button>
        </div>
      </div>`;
    }).join('');
  }

  async function loadCampaigns(){
    clearAlert($('#frAlert'));
    const data = await api(cfg.endpoint + '?action=list');
    if(!data.success) throw new Error(data.message || 'Failed to load campaigns.');
    campaigns = data.items || [];
    renderStats(); renderCampaigns();
  }

  /* =========================================================
     MEMBERS
  ========================================================= */
  async function loadMembers(){
    if(membersLoaded) return;
    const sel = $('#frMemberId'); if(!sel) return;
    const data = await api(cfg.endpoint + '?action=members');
    if(data.success){
      sel.innerHTML = '<option value="">Visitor / Non-member</option>' +
        (data.items||[]).map(m =>
          `<option value="${Number(m.id)}">${esc(m.full_name)}${m.member_no ? ' ('+esc(m.member_no)+')' : ''}</option>`
        ).join('');
      membersLoaded = true;
    }
  }

  /* =========================================================
     CAMPAIGN FORM
  ========================================================= */
  function resetCampaignForm(){
    $('#frCampaignForm').reset();
    $('#frCampaignId').value = '';
    $('#frCampaignTitle').textContent = 'New Campaign';
    $('#frStart').value = cfg.today || '';
    clearAlert($('#frCampaignAlert'));
  }

  async function editCampaign(id){
    resetCampaignForm();
    const data = await api(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
    if(!data.success) return alertBox($('#frAlert'), data.message || 'Campaign not found.', 'error');
    const c = data.item;
    $('#frCampaignTitle').textContent = 'Edit Campaign';
    $('#frCampaignId').value  = c.id || '';
    $('#frTitle').value       = c.title || '';
    $('#frDescription').value = c.description || '';
    $('#frTarget').value      = c.target_amount || 0;
    $('#frStart').value       = c.start_date || cfg.today || '';
    $('#frEnd').value         = c.end_date || '';
    $('#frVenue').value       = c.venue || '';
    $('#frOrganizer').value   = c.organizer || '';
    $('#frStatus').value      = c.status || 'Planning';
    openModal('#frCampaignModal');
  }

  function campaignById(id){ return campaigns.find(c => Number(c.id) === Number(id)); }

  /* =========================================================
     VIEW CAMPAIGN
  ========================================================= */
  async function viewCampaign(id){
    const data = await api(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
    if(!data.success) return alertBox($('#frAlert'), data.message || 'Campaign not found.', 'error');
    currentCampaign = data.item;
    const c   = currentCampaign;
    const pct = Number(c.target_amount||0) > 0
      ? Math.min(100, Math.round(Number(c.raised||0)/Number(c.target_amount||1)*100))
      : 0;
    $('#frViewContent').innerHTML = `<div class="campaign-detail-grid">
      <div class="detail-card"><b>Campaign No</b><span>${esc(c.campaign_no || '—')}</span></div>
      <div class="detail-card"><b>Title</b><span>${esc(c.title || '—')}</span></div>
      <div class="detail-card"><b>Status</b><span>${statusBadge(c.status)}</span></div>
      <div class="detail-card"><b>Organizer</b><span>${esc(c.organizer || '—')}</span></div>
      <div class="detail-card"><b>Venue</b><span>${esc(c.venue || '—')}</span></div>
      <div class="detail-card"><b>Date</b><span>${esc(c.start_date || '—')} - ${esc(c.end_date || 'Ongoing')}</span></div>
      <div class="detail-card"><b>Target</b><span>${money(c.target_amount)}</span></div>
      <div class="detail-card"><b>Raised</b><span>${money(c.raised)}</span></div>
      <div class="detail-card full"><b>Description</b><span>${esc(c.description || '—')}</span>${progressBar(pct)}</div>
    </div>`;
    openModal('#frViewModal');
    await loadCollections(c.id);
  }

  /* =========================================================
     COLLECTIONS TABLE (inside view modal)
  ========================================================= */
  async function loadCollections(campaignId){
    const body = $('#frCollectionsBody');
    body.innerHTML = '<tr><td colspan="7" class="text-muted">Loading...</td></tr>';
    const data = await api(cfg.endpoint + '?action=collections&campaign_id=' + encodeURIComponent(campaignId));
    if(!data.success){
      body.innerHTML = `<tr><td colspan="7" class="text-danger">${esc(data.message || 'Failed to load.')}</td></tr>`;
      return;
    }
    const items = data.items || [];
    if(!items.length){ body.innerHTML = '<tr><td colspan="7" class="text-muted">No collections yet.</td></tr>'; return; }
    body.innerHTML = items.map((x,i) => `<tr>
      <td>${i+1}</td>
      <td>${esc((x.full_name||'').trim() || x.donor_name || 'Anonymous')}</td>
      <td><b>${money(x.amount)}</b></td>
      <td>${esc(x.payment_method || '—')}</td>
      <td>${esc(x.collection_date || '—')}</td>
      <td>${esc(x.item_description || '—')}</td>
      <td class="table-action">
        <a class="btn btn-sm btn-secondary" href="download_receipt.php?id=${Number(x.id)}" target="_blank" title="Receipt"><i class="fas fa-file-pdf"></i></a>
        <button class="btn btn-sm btn-info"    data-fr-action="resend_confirmation" data-id="${Number(x.id)}" title="Resend confirmation"><i class="fas fa-paper-plane"></i></button>
        <button class="btn btn-sm btn-warning" data-fr-action="edit_collection"     data-id="${Number(x.id)}"><i class="fas fa-edit"></i></button>
        <button class="btn btn-sm btn-danger"  data-fr-action="delete_collection"   data-id="${Number(x.id)}"><i class="fas fa-trash"></i></button>
      </td>
    </tr>`).join('');
  }

  /* =========================================================
     COLLECTION FORM
  ========================================================= */
  async function openCollection(campaignId, collectionId){
    await loadMembers();
    $('#frCollectionForm').reset(); clearAlert($('#frCollectionAlert'));
    $('#frCollectionId').value         = '';
    $('#frCollectionCampaignId').value = campaignId;
    $('#frCollectionDate').value       = cfg.today || '';
    const c = campaignById(campaignId) || currentCampaign || {};
    $('#frCollectionCampaignName').value = c.title || 'Selected campaign';
    $('#frCollectionTitle').innerHTML    = '<i class="fas fa-donate"></i> Record Collection';

    if(collectionId){
      const data = await api(cfg.endpoint + '?action=get_collection&id=' + encodeURIComponent(collectionId));
      if(!data.success) return alertBox($('#frAlert'), data.message || 'Collection not found.', 'error');
      const x = data.item;
      $('#frCollectionTitle').innerHTML      = '<i class="fas fa-edit"></i> Edit Collection';
      $('#frCollectionId').value             = x.id || '';
      $('#frCollectionCampaignId').value     = x.campaign_id || campaignId;
      $('#frCollectionCampaignName').value   = x.campaign_title || c.title || 'Selected campaign';
      $('#frMemberId').value                 = x.member_id || '';
      $('#frDonorName').value                = x.donor_name || '';
      $('#frAmount').value                   = x.amount || 0;
      $('#frCollectionDate').value           = x.collection_date || cfg.today || '';
      $('#frPaymentMethod').value            = x.payment_method || 'Cash';
      $('#frItemDescription').value          = x.item_description || '';
      $('#frNotes').value                    = x.notes || '';
    }
    openModal('#frCollectionModal');
  }

  /* =========================================================
     DELETE CONFIRM
  ========================================================= */
  function confirmDelete(action, id, text){
    clearAlert($('#frConfirmAlert'));
    $('#frConfirmAction').value     = action;
    $('#frConfirmId').value         = id;
    $('#frConfirmText').textContent = text;
    openModal('#frConfirmModal');
  }

  /* =========================================================
     GLOBAL CLICK HANDLER
  ========================================================= */
  document.addEventListener('click', e => {
    // Close modals
    if(e.target.closest('[data-fr-close]') || e.target.id === 'frBackdrop') closeModals();

    // New campaign
    const openBtn = e.target.closest('[data-fr-open="campaign"]');
    if(openBtn){ resetCampaignForm(); openModal('#frCampaignModal'); }

    // ── EXPORT: campaigns CSV ───────────────────────────
    if(e.target.closest('#frBtnExportCSV')){
      const barOpen = $('#frExportBar')?.style.display !== 'none';
      if(!barOpen) activateExportBar('csv');
      else         executeExport();
    }

    // ── EXPORT: campaigns PDF ───────────────────────────
    if(e.target.closest('#frBtnExportPDF')){
      const barOpen = $('#frExportBar')?.style.display !== 'none';
      if(!barOpen) activateExportBar('pdf');
      else         executeExport();
    }

    // ── EXPORT: bar Download button ─────────────────────
    if(e.target.closest('#frBtnExportBarGo')) executeExport();

    // ── EXPORT: close filter bar ────────────────────────
    if(e.target.closest('#frBtnExportBarClose')){
      toggleExportBar(false);
      _exportTarget = null;
    }

    // ── EXPORT: collections CSV (inside view modal) ─────
    if(e.target.closest('#frBtnExportCollectionsCSV')){
      if(!currentCampaign) return;
      triggerDownload(collectionExportUrl('export_collections_csv', currentCampaign.id));
    }

    // ── EXPORT: collections PDF (inside view modal) ─────
    if(e.target.closest('#frBtnExportCollectionsPDF')){
      if(!currentCampaign) return;
      openTab(collectionExportUrl('export_collections_pdf', currentCampaign.id));
    }

    // Data-action buttons
    const btn = e.target.closest('[data-fr-action]');
    if(!btn) return;
    const id = btn.dataset.id, action = btn.dataset.frAction;

    if(action === 'view')            viewCampaign(id);
    if(action === 'edit')            editCampaign(id);
    if(action === 'collect')         openCollection(Number(id));
    if(action === 'delete_campaign') confirmDelete('delete_campaign', id, 'Delete this campaign and all its collections?');
    if(action === 'edit_collection') openCollection(currentCampaign ? currentCampaign.id : 0, id);
    if(action === 'delete_collection') confirmDelete('delete_collection', id, 'Delete this collection record?');
    if(action === 'resend_confirmation'){
      const fd = new FormData();
      fd.append('action','resend_confirmation'); fd.append('id', id);
      api(cfg.endpoint, {method:'POST', body:fd}).then(data => {
        if(!data.success) return alertBox($('#frAlert'), data.message || 'Failed to resend confirmation.', 'error');
        const extra = ' Email: ' + (data.email_sent ? 'sent' : 'not sent')
                    + ', SMS: '  + (data.sms_sent   ? 'sent' : 'not sent') + '.';
        alertBox($('#frAlert'), (data.message || 'Confirmation processed.') + extra);
      }).catch(err => alertBox($('#frAlert'), err.message, 'error'));
    }
  });

  document.addEventListener('keydown', e => { if(e.key === 'Escape') closeModals(); });

  /* =========================================================
     PASSIVE LISTENERS
  ========================================================= */
  $('#frRefresh')?.addEventListener('click', () =>
    loadCampaigns().catch(err => alertBox($('#frAlert'), err.message, 'error'))
  );
  $('#frSearch')?.addEventListener('input', renderCampaigns);
  $('#frStatusFilter')?.addEventListener('change', renderCampaigns);
  $('#frViewAddCollection')?.addEventListener('click', () => {
    if(currentCampaign) openCollection(currentCampaign.id);
  });

  /* =========================================================
     FORM SUBMISSIONS
  ========================================================= */
  $('#frCampaignForm')?.addEventListener('submit', async e => {
    e.preventDefault(); clearAlert($('#frCampaignAlert'));
    try {
      const data = await post(e.currentTarget);
      if(!data.success) return alertBox($('#frCampaignAlert'), data.message || 'Save failed.', 'error');
      closeModals(); alertBox($('#frAlert'), data.message || 'Saved.');
      await loadCampaigns();
    } catch(err){ alertBox($('#frCampaignAlert'), err.message, 'error'); }
  });

  $('#frCollectionForm')?.addEventListener('submit', async e => {
    e.preventDefault(); clearAlert($('#frCollectionAlert'));
    try {
      const data = await post(e.currentTarget);
      if(!data.success) return alertBox($('#frCollectionAlert'), data.message || 'Save failed.', 'error');
      closeModals();
      const extra = (typeof data.email_sent !== 'undefined' || typeof data.sms_sent !== 'undefined')
        ? (' Email: ' + (data.email_sent ? 'sent' : 'not sent') + ', SMS: ' + (data.sms_sent ? 'sent' : 'not sent') + '.')
        : '';
      alertBox($('#frAlert'), (data.message || 'Saved.') + extra);
      await loadCampaigns();
      if(currentCampaign) await viewCampaign(currentCampaign.id);
    } catch(err){ alertBox($('#frCollectionAlert'), err.message, 'error'); }
  });

  $('#frConfirmForm')?.addEventListener('submit', async e => {
    e.preventDefault(); clearAlert($('#frConfirmAlert'));
    try {
      const data = await post(e.currentTarget);
      if(!data.success) return alertBox($('#frConfirmAlert'), data.message || 'Delete failed.', 'error');
      closeModals(); alertBox($('#frAlert'), data.message || 'Deleted.');
      await loadCampaigns();
    } catch(err){ alertBox($('#frConfirmAlert'), err.message, 'error'); }
  });

  /* =========================================================
     BOOT
  ========================================================= */
  loadCampaigns().catch(err => alertBox($('#frAlert'), err.message, 'error'));
})();