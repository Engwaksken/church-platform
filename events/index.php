<?php
require_once '../config/config.php';
requireLogin();

function eh($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
$eventTypes = ['Sunday Service','Revival','Youth Event','Fundraising','AGM','Burial','Wedding','Baptism','Other'];
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area events-page">
  <?php echo showFlash(); ?>

  <div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div>
      <h2><i class="fas fa-calendar-alt"></i> Church Events</h2>
      <p class="text-muted mb-0">Manage church services, fundraisings, meetings, weddings, baptisms and other events.</p>
    </div>
    <button type="button" class="btn btn-primary" id="btnOpenAddEvent"><i class="fas fa-plus"></i> Add Event</button>
  </div>

  <div class="ev-stats-grid">
    <div class="ev-stat-card"><div class="ev-stat-icon"><i class="fas fa-calendar"></i></div><div><div class="ev-stat-value" id="statTotal">0</div><div class="ev-stat-label">Total Events</div></div></div>
    <div class="ev-stat-card"><div class="ev-stat-icon"><i class="fas fa-calendar-check"></i></div><div><div class="ev-stat-value" id="statUpcoming">0</div><div class="ev-stat-label">Upcoming</div></div></div>
    <div class="ev-stat-card"><div class="ev-stat-icon"><i class="fas fa-history"></i></div><div><div class="ev-stat-value" id="statPast">0</div><div class="ev-stat-label">Past Events</div></div></div>
    <div class="ev-stat-card"><div class="ev-stat-icon"><i class="fas fa-star"></i></div><div><div class="ev-stat-value" id="statToday">0</div><div class="ev-stat-label">Today</div></div></div>
    <div class="ev-stat-card"><div class="ev-stat-icon"><i class="fas fa-tags"></i></div><div><div class="ev-stat-value" id="statTypes">0</div><div class="ev-stat-label">Event Types</div></div></div>
    <div class="ev-stat-card"><div class="ev-stat-icon"><i class="fas fa-coins"></i></div><div><div class="ev-stat-value" id="statBudget">0</div><div class="ev-stat-label">Budget UGX</div></div></div>
  </div>

  <div class="card">
    <div class="card-body">
      <div class="ev-toolbar">
        <div class="ev-tabs" role="tablist">
          <button type="button" class="ev-tab active" data-tab="upcoming"><i class="fas fa-calendar-check"></i> Upcoming</button>
          <button type="button" class="ev-tab" data-tab="past"><i class="fas fa-history"></i> Past</button>
          <button type="button" class="ev-tab" data-tab="all"><i class="fas fa-list"></i> All</button>
        </div>
        <div class="ev-filters">
          <input type="text" id="eventSearch" class="form-control" placeholder="Search events...">
          <select id="eventTypeFilter" class="form-control">
            <option value="">All Types</option>
            <?php foreach ($eventTypes as $type): ?><option value="<?= eh($type) ?>"><?= eh($type) ?></option><?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-outline" id="btnRefreshEvents"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
      </div>
      <div id="eventsAlert" class="ev-alert"></div>
      <div id="eventsGrid" class="ev-grid"><div class="text-muted p-3">Loading events...</div></div>
    </div>
  </div>
</div>
</div>

<div class="ev-modal-backdrop" id="eventBackdrop"></div>

<div class="ev-modal" id="eventFormModal" aria-hidden="true">
  <div class="ev-dialog">
    <form id="eventForm">
      <div class="ev-modal-header">
        <div class="ev-modal-title" id="eventFormTitle"><i class="fas fa-plus"></i> Add Event</div>
        <button type="button" class="ev-close" data-close-event-modal>&times;</button>
      </div>
      <div class="ev-modal-body">
        <div id="formAlert" class="ev-alert"></div>
        <input type="hidden" name="csrf_token" value="<?= eh($csrfToken) ?>">
        <input type="hidden" name="action" id="eventAction" value="create">
        <input type="hidden" name="id" id="eventId" value="">
        <div class="ev-form-grid">
          <div class="ev-field ev-full"><label>Event Title <span class="text-danger">*</span></label><input type="text" name="title" id="title" class="form-control" required></div>
          <div class="ev-field"><label>Event Type</label><select name="event_type" id="event_type" class="form-control"><?php foreach ($eventTypes as $type): ?><option value="<?= eh($type) ?>"><?= eh($type) ?></option><?php endforeach; ?></select></div>
          <div class="ev-field"><label>Event Date <span class="text-danger">*</span></label><input type="date" name="event_date" id="event_date" class="form-control" required></div>
          <div class="ev-field"><label>Event Time</label><input type="time" name="event_time" id="event_time" class="form-control"></div>
          <div class="ev-field"><label>Budget (UGX)</label><input type="number" name="budget" id="budget" class="form-control" value="0" step="0.01" min="0"></div>
          <div class="ev-field"><label>Venue</label><input type="text" name="venue" id="venue" class="form-control" placeholder="e.g. Church Hall"></div>
          <div class="ev-field"><label>Organizer</label><input type="text" name="organizer" id="organizer" class="form-control"></div>
          <div class="ev-field ev-full"><label>Description</label><textarea name="description" id="description" class="form-control" rows="4"></textarea></div>
        </div>
      </div>
      <div class="ev-actions"><button type="button" class="btn btn-outline" data-close-event-modal>Cancel</button><button type="submit" class="btn btn-primary" id="saveEventBtn"><i class="fas fa-save"></i> Save Event</button></div>
    </form>
  </div>
</div>

<div class="ev-modal" id="viewEventModal" aria-hidden="true">
  <div class="ev-dialog">
    <div class="ev-modal-header"><div class="ev-modal-title"><i class="fas fa-eye"></i> Event Details</div><button type="button" class="ev-close" data-close-event-modal>&times;</button></div>
    <div class="ev-modal-body"><div id="viewEventContent" class="ev-view-grid"></div></div>
    <div class="ev-actions"><button type="button" class="btn btn-outline" data-close-event-modal>Close</button></div>
  </div>
</div>

<div class="ev-modal" id="deleteEventModal" aria-hidden="true">
  <div class="ev-dialog ev-dialog-sm">
    <form id="deleteEventForm">
      <div class="ev-modal-header"><div class="ev-modal-title"><i class="fas fa-trash"></i> Delete Event</div><button type="button" class="ev-close" data-close-event-modal>&times;</button></div>
      <div class="ev-modal-body">
        <div id="deleteAlert" class="ev-alert"></div>
        <input type="hidden" name="csrf_token" value="<?= eh($csrfToken) ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" id="deleteEventId">
        <p>Are you sure you want to delete <strong id="deleteEventTitle"></strong>?</p>
        <p class="text-muted">This action cannot be undone.</p>
      </div>
      <div class="ev-actions"><button type="button" class="btn btn-outline" data-close-event-modal>Cancel</button><button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button></div>
    </form>
  </div>
</div>

<style>
.ev-stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin:18px 0}.ev-stat-card{background:#fff;border:1px solid rgba(148,163,184,.25);border-radius:16px;padding:18px;display:flex;align-items:center;gap:14px;box-shadow:0 10px 25px rgba(15,23,42,.08)}.ev-stat-icon{width:48px;height:48px;border-radius:14px;background:rgba(37,99,235,.12);display:flex;align-items:center;justify-content:center;font-size:20px;color:var(--primary,#2563eb)}.ev-stat-value{font-size:25px;font-weight:800;line-height:1}.ev-stat-label{font-size:13px;color:#64748b;margin-top:5px}.ev-toolbar{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px}.ev-tabs{display:flex;gap:8px;flex-wrap:wrap}.ev-tab{border:1px solid #d1d5db;background:#fff;border-radius:10px;padding:9px 12px;cursor:pointer;font-weight:700}.ev-tab.active{background:var(--primary,#2563eb);color:#fff;border-color:var(--primary,#2563eb)}.ev-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:center}.ev-filters input{min-width:240px}.ev-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.ev-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;box-shadow:0 8px 20px rgba(15,23,42,.06);display:flex;flex-direction:column}.ev-card-head{background:var(--primary,#2563eb);color:#fff;padding:13px 15px;display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.ev-card-title{font-weight:800}.ev-card-body{padding:15px;flex:1}.ev-card-body p{margin-bottom:7px}.ev-card-actions{border-top:1px solid #e5e7eb;padding:12px;display:flex;justify-content:flex-end;gap:7px;background:#f8fafc}.ev-badge{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:800;background:#eef2ff;color:#3730a3}.ev-badge-light{background:#fff;color:#1f2937}.ev-alert{display:none;margin-bottom:12px;border-radius:10px;padding:10px 12px}.ev-alert.success{display:block;background:#dcfce7;color:#166534}.ev-alert.error{display:block;background:#fee2e2;color:#991b1b}.ev-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.62);z-index:9998;display:none}.ev-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;overflow:auto;padding:32px 14px}.ev-modal.show{display:flex}.ev-modal-backdrop.show{display:block}.ev-dialog{width:min(860px,100%);background:#fff;border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25)}.ev-dialog-sm{width:min(520px,100%)}.ev-modal-header{display:flex;align-items:center;justify-content:space-between;gap:12px;background:#f8fafc;border-bottom:1px solid #e5e7eb;padding:16px 18px}.ev-modal-title{font-weight:800;font-size:18px}.ev-close{border:0;background:transparent;font-size:26px;cursor:pointer;line-height:1}.ev-modal-body{padding:18px;max-height:calc(100vh - 180px);overflow:auto}.ev-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.ev-field label{font-weight:700;font-size:13px;margin-bottom:6px;display:block}.ev-full{grid-column:1/-1}.ev-actions{display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;border-top:1px solid #e5e7eb;padding:14px 18px}.ev-view-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.ev-detail{border:1px solid #e5e7eb;border-radius:10px;background:#f8fafc;padding:10px}.ev-detail b{display:block;font-size:12px;color:#64748b;margin-bottom:3px}.btn-danger{background:#dc2626;color:#fff;border-color:#dc2626}.btn-outline{background:#fff;border:1px solid #d1d5db;color:#334155}@media(max-width:1100px){.ev-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:900px){.ev-stats-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ev-form-grid,.ev-view-grid{grid-template-columns:1fr}.ev-toolbar{align-items:stretch}.ev-filters{width:100%}.ev-filters input,.ev-filters select{min-width:100%;width:100%}}@media(max-width:580px){.ev-stats-grid,.ev-grid{grid-template-columns:1fr}}
</style>

<script>
(function(){
  'use strict';
  const endpoint = 'process-events.php';
  let currentTab = 'upcoming';
  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => Array.from(r.querySelectorAll(s));
  const esc = v => String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]));
  const money = v => Number(v || 0).toLocaleString();

  function showAlert(el, msg, type='success') { if (!el) return; el.className = 'ev-alert ' + (type === 'error' ? 'error' : 'success'); el.textContent = msg; }
  function clearAlert(el) { if (el) { el.className = 'ev-alert'; el.textContent = ''; } }
  function openModal(id) { qs('#eventBackdrop').classList.add('show'); qs(id).classList.add('show'); document.body.style.overflow='hidden'; }
  function closeModals() { qsa('.ev-modal,.ev-modal-backdrop').forEach(m => m.classList.remove('show')); document.body.style.overflow=''; }

  async function request(url, opts={}) {
    const res = await fetch(url, Object.assign({headers:{'X-Requested-With':'XMLHttpRequest'}}, opts));
    const text = await res.text();
    try { return JSON.parse(text); } catch(e) { throw new Error('Invalid server response: ' + text.slice(0, 250)); }
  }
  async function postForm(form) { return request(endpoint, {method:'POST', body:new FormData(form)}); }

  function renderStats(stats) {
    stats = stats || {};
    qs('#statTotal').textContent = stats.total || 0;
    qs('#statUpcoming').textContent = stats.upcoming || 0;
    qs('#statPast').textContent = stats.past || 0;
    qs('#statToday').textContent = stats.today || 0;
    qs('#statTypes').textContent = stats.types || 0;
    qs('#statBudget').textContent = money(stats.budget || 0);
  }

  function eventCard(e) {
    const time = e.event_time ? `<p><i class="fas fa-clock mr-1"></i>${esc(e.event_time)}</p>` : '';
    const venue = e.venue ? `<p><i class="fas fa-map-marker-alt mr-1"></i>${esc(e.venue)}</p>` : '';
    const org = e.organizer ? `<p><i class="fas fa-user mr-1"></i>${esc(e.organizer)}</p>` : '';
    const budget = Number(e.budget || 0) > 0 ? `<p><i class="fas fa-coins mr-1"></i>UGX ${money(e.budget)}</p>` : '';
    const desc = e.description ? `<p class="text-muted small">${esc(e.description).slice(0, 160)}</p>` : '';
    return `<div class="ev-card">
      <div class="ev-card-head"><div class="ev-card-title">${esc(e.title)}</div><span class="ev-badge ev-badge-light">${esc(e.event_type || 'Other')}</span></div>
      <div class="ev-card-body">
        <p><i class="fas fa-calendar mr-1"></i><strong>${esc(e.event_date || '')}</strong></p>${time}${venue}${org}${budget}${desc}
      </div>
      <div class="ev-card-actions">
        <button type="button" class="btn btn-sm btn-info" data-action="view" data-id="${Number(e.id)}"><i class="fas fa-eye"></i></button>
        <a class="btn btn-sm btn-success" target="_blank" href="certificate.php?id=${Number(e.id)}" title="Create participation certificate"><i class="fas fa-certificate"></i></a>
        <button type="button" class="btn btn-sm btn-warning" data-action="edit" data-id="${Number(e.id)}"><i class="fas fa-edit"></i></button>
        <button type="button" class="btn btn-sm btn-danger" data-action="delete" data-id="${Number(e.id)}" data-title="${esc(e.title)}"><i class="fas fa-trash"></i></button>
      </div>
    </div>`;
  }

  async function loadEvents() {
    clearAlert(qs('#eventsAlert'));
    const search = encodeURIComponent(qs('#eventSearch').value || '');
    const type = encodeURIComponent(qs('#eventTypeFilter').value || '');
    const data = await request(`${endpoint}?action=list&tab=${encodeURIComponent(currentTab)}&search=${search}&event_type=${type}`);
    if (!data.success) throw new Error(data.message || 'Failed to load events.');
    renderStats(data.stats);
    const items = data.items || [];
    qs('#eventsGrid').innerHTML = items.length ? items.map(eventCard).join('') : '<div class="text-muted p-3">No events found.</div>';
  }

  function resetForm() {
    qs('#eventForm').reset();
    qs('#eventAction').value = 'create';
    qs('#eventId').value = '';
    qs('#eventFormTitle').innerHTML = '<i class="fas fa-plus"></i> Add Event';
    qs('#saveEventBtn').innerHTML = '<i class="fas fa-save"></i> Save Event';
    qs('#budget').value = '0';
    clearAlert(qs('#formAlert'));
  }

  async function openEdit(id) {
    resetForm();
    const data = await request(endpoint + '?action=get&id=' + encodeURIComponent(id));
    if (!data.success) return showAlert(qs('#eventsAlert'), data.message || 'Event not found.', 'error');
    const e = data.item || {};
    qs('#eventAction').value = 'update';
    qs('#eventId').value = e.id || '';
    qs('#title').value = e.title || '';
    qs('#event_type').value = e.event_type || 'Other';
    qs('#event_date').value = e.event_date || '';
    qs('#event_time').value = e.event_time || '';
    qs('#venue').value = e.venue || '';
    qs('#organizer').value = e.organizer || '';
    qs('#budget').value = e.budget || 0;
    qs('#description').value = e.description || '';
    qs('#eventFormTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Event';
    qs('#saveEventBtn').innerHTML = '<i class="fas fa-save"></i> Update Event';
    openModal('#eventFormModal');
  }

  async function openView(id) {
    const data = await request(endpoint + '?action=get&id=' + encodeURIComponent(id));
    if (!data.success) return showAlert(qs('#eventsAlert'), data.message || 'Event not found.', 'error');
    const e = data.item || {};
    const fields = [['Title', e.title], ['Type', e.event_type], ['Date', e.event_date], ['Time', e.event_time], ['Venue', e.venue], ['Organizer', e.organizer], ['Budget', 'UGX ' + money(e.budget || 0)], ['Description', e.description]];
    qs('#viewEventContent').innerHTML = fields.map(([k,v]) => `<div class="ev-detail"><b>${esc(k)}</b><span>${esc(v || '—')}</span></div>`).join('');
    openModal('#viewEventModal');
  }

  qs('#btnOpenAddEvent').addEventListener('click', () => { resetForm(); openModal('#eventFormModal'); });
  qs('#btnRefreshEvents').addEventListener('click', loadEvents);
  qs('#eventSearch').addEventListener('input', () => loadEvents().catch(err => showAlert(qs('#eventsAlert'), err.message, 'error')));
  qs('#eventTypeFilter').addEventListener('change', () => loadEvents().catch(err => showAlert(qs('#eventsAlert'), err.message, 'error')));
  qs('#eventBackdrop').addEventListener('click', closeModals);
  qsa('[data-close-event-modal]').forEach(btn => btn.addEventListener('click', closeModals));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

  qsa('.ev-tab').forEach(tab => tab.addEventListener('click', () => {
    qsa('.ev-tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    currentTab = tab.dataset.tab || 'upcoming';
    loadEvents().catch(err => showAlert(qs('#eventsAlert'), err.message, 'error'));
  }));

  document.addEventListener('click', e => {
    const btn = e.target.closest('[data-action]');
    if (!btn) return;
    const action = btn.dataset.action;
    const id = btn.dataset.id;
    if (action === 'view') openView(id);
    if (action === 'edit') openEdit(id);
    if (action === 'delete') {
      qs('#deleteEventId').value = id;
      qs('#deleteEventTitle').textContent = btn.dataset.title || 'this event';
      clearAlert(qs('#deleteAlert'));
      openModal('#deleteEventModal');
    }
  });

  qs('#eventForm').addEventListener('submit', async e => {
    e.preventDefault();
    clearAlert(qs('#formAlert'));
    try {
      const data = await postForm(e.currentTarget);
      if (!data.success) return showAlert(qs('#formAlert'), data.message || 'Save failed.', 'error');
      closeModals(); showAlert(qs('#eventsAlert'), data.message || 'Saved successfully.'); await loadEvents();
    } catch (err) { showAlert(qs('#formAlert'), err.message, 'error'); }
  });

  qs('#deleteEventForm').addEventListener('submit', async e => {
    e.preventDefault();
    try {
      const data = await postForm(e.currentTarget);
      if (!data.success) return showAlert(qs('#deleteAlert'), data.message || 'Delete failed.', 'error');
      closeModals(); showAlert(qs('#eventsAlert'), data.message || 'Deleted successfully.'); await loadEvents();
    } catch (err) { showAlert(qs('#deleteAlert'), err.message, 'error'); }
  });

  loadEvents().catch(err => showAlert(qs('#eventsAlert'), err.message, 'error'));
})();
</script>

<?php include '../includes/footer.php'; ?>
