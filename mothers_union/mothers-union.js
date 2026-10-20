(function () {
    'use strict';

    const cfg = window.MothersUnionConfig || {};
    if (!cfg.endpoint) return;

    const $  = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    let rows = [];
    let rowOffset=0,currentPage=1,pagination={page:1,pages:1,total:0,from:0,to:0};

    /* ── helpers ──────────────────────────────────────────── */
    const esc   = v => String(v ?? '').replace(/[&<>"']/g, c =>
        ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#039;' }[c]));
    const money = v => 'UGX ' + Number(v || 0).toLocaleString();
    const badge = (v, cls = 'badge-secondary') =>
        `<span class="badge ${cls}">${esc(v || '-')}</span>`;

    /* ── alerts ───────────────────────────────────────────── */
    function alertBox(el, msg, type = 'success') {
        if (!el) return;
        el.className   = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        el.textContent = msg;
    }
    function clearAlert(el) {
        if (el) { el.className = 'ajax-alert'; el.textContent = ''; }
    }

    /* ── modals ───────────────────────────────────────────── */
    function openModal(id) {
        $('#muBackdrop')?.classList.add('show');
        $(id)?.classList.add('show');
        $(id)?.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cms-modal-open');
    }
    function closeModal() {
        $$('.app-modal, .app-modal-backdrop').forEach(x => x.classList.remove('show'));
        $$('.app-modal').forEach(x => x.setAttribute('aria-hidden', 'true'));
        document.body.classList.remove('cms-modal-open');
    }

    /* ── HTTP ─────────────────────────────────────────────── */
    async function req(url, opt = {}) {
        const r = await fetch(url, Object.assign({ headers: { 'X-Requested-With': 'XMLHttpRequest' } }, opt));
        const t = await r.text();
        try { return JSON.parse(t); }
        catch (e) { throw new Error('Invalid server response: ' + t.slice(0, 220)); }
    }
    const post = form => req(cfg.endpoint, { method: 'POST', body: new FormData(form) });

    /* =========================================================
       EXPORT HELPERS
    ========================================================= */

    /** Build export URL with optional status filter from the bar. */
    function exportUrl(action) {
        const status = $('#muExportStatus')?.value || '';
        let url = cfg.endpoint + '?action=' + action;
        if (status) url += '&status=' + encodeURIComponent(status);
        return url;
    }

    function triggerDownload(url) {
        const a = document.createElement('a');
        a.href = url; a.style.display = 'none';
        document.body.appendChild(a); a.click(); a.remove();
    }

    function openTab(url) { window.open(url, '_blank'); }

    function toggleExportBar(show) {
        const bar = $('#muExportBar');
        if (bar) bar.style.display = show ? 'flex' : 'none';
    }

    let _exportTarget = null; // 'csv' | 'pdf'

    function activateExportBar(type) {
        _exportTarget = type;
        toggleExportBar(true);
        const dlBtn = $('#muBtnExportBarGo');
        if (dlBtn) {
            dlBtn.innerHTML = type === 'pdf'
                ? '<i class="fas fa-file-pdf"></i> Open PDF'
                : '<i class="fas fa-file-csv"></i> Download CSV';
        }
    }

    function executeExport() {
        const url = exportUrl(_exportTarget === 'pdf' ? 'export_pdf' : 'export_csv');
        if (_exportTarget === 'pdf') openTab(url);
        else triggerDownload(url);
    }

    /* =========================================================
       MULTI-SELECT MEMBER PICKER
    ========================================================= */
    const pickerWrap     = $('#muMemberPickerWrap');
    const searchInput    = $('#muMemberSearch');
    const selectAllCb    = $('#muSelectAll');
    const pickCountBadge = $('#muPickCount');
    const bulkNote       = $('#muBulkNote');
    const saveBtnLabel   = $('#muSaveBtnLabel');

    function getMemberRows() {
        return $$('.mu-check-row:not(.mu-check-all)', $('#muMemberChecklist'));
    }
    function countChecked() {
        return $$('.mu-member-cb:checked', $('#muMemberChecklist')).length;
    }
    function updatePickerUI() {
        const n = countChecked();
        if (pickCountBadge) {
            pickCountBadge.textContent = n === 0 ? '0 selected'
                                       : n === 1 ? '1 selected'
                                       : n + ' selected';
            pickCountBadge.className = 'badge ml-2 ' + (n > 0 ? 'badge-primary' : 'badge-secondary');
        }
        if (bulkNote)     bulkNote.style.display     = n > 1 ? 'flex' : 'none';
        if (saveBtnLabel) saveBtnLabel.textContent    = n > 1 ? `Enrol ${n} Members` : 'Enrol';
    }

    searchInput?.addEventListener('input', () => {
        const term = searchInput.value.toLowerCase().trim();
        getMemberRows().forEach(row => {
            row.style.display = (!term || (row.dataset.name ?? '').includes(term)) ? '' : 'none';
        });
        syncSelectAll();
    });

    selectAllCb?.addEventListener('change', () => {
        getMemberRows().forEach(row => {
            if (row.style.display === 'none') return;
            const cb = row.querySelector('.mu-member-cb');
            if (cb) cb.checked = selectAllCb.checked;
        });
        updatePickerUI();
    });

    document.addEventListener('change', e => {
        if (e.target.classList.contains('mu-member-cb')) { syncSelectAll(); updatePickerUI(); }
    });

    function syncSelectAll() {
        if (!selectAllCb) return;
        const visible    = getMemberRows().filter(r => r.style.display !== 'none');
        const checkedVis = visible.filter(r => r.querySelector('.mu-member-cb')?.checked);
        selectAllCb.indeterminate = checkedVis.length > 0 && checkedVis.length < visible.length;
        selectAllCb.checked       = visible.length > 0 && checkedVis.length === visible.length;
    }

    document.addEventListener('mouseover', e => {
        const row = e.target.closest('.mu-check-row:not(.mu-check-all)');
        if (row) row.style.background = '#f5f1ff';
    });
    document.addEventListener('mouseout', e => {
        const row = e.target.closest('.mu-check-row:not(.mu-check-all)');
        if (row) row.style.background = '';
    });

    /* =========================================================
       STATS
    ========================================================= */
    function renderStats(items) {
        const el = $('#muTotal');  if (el) el.textContent = items.length;
        const ea = $('#muActive'); if (ea) ea.textContent = items.filter(x => (x.status || 'Active') === 'Active').length;
        const es = $('#muSubs');   if (es) es.textContent = money(items.reduce((a, x) => a + Number(x.paid_total || x.subscription || 0), 0));
    }

    /* =========================================================
       TABLE RENDER
    ========================================================= */
    function render(items) {
        const tb = $('#muTableBody');
        if (!tb) return;
        if (!items.length) {
            tb.innerHTML = `<tr><td colspan="9">
                <div class="empty-state"><p>No records found.</p></div>
            </td></tr>`;
            return;
        }
        tb.innerHTML = items.map((r, i) => {
            const paid      = Number(r.paid_total || r.subscription || 0);
            const statusCls = (r.status || 'Active') === 'Active' ? 'badge-success'
                            : r.status === 'Suspended'             ? 'badge-danger'
                            : 'badge-secondary';
            return `<tr>
                <td class="text-muted">${rowOffset + i + 1}</td>
                <td><strong>${esc(r.mu_no)}</strong></td>
                <td>
                    <div class="record-info">
                        <div class="record-avatar">${esc((r.full_name || '?')[0].toUpperCase())}</div>
                        <div>
                            <div class="record-name">${esc(r.full_name)}</div>
                            <div class="record-sub">${esc(r.member_no || '')}</div>
                        </div>
                    </div>
                </td>
                <td>${esc(r.phone || '-')}</td>
                <td>${badge(r.position || 'Member', 'badge-info')}</td>
                <td>${esc(r.date_joined || '-')}</td>
                <td><strong>${money(paid)}</strong></td>
                <td>${badge(r.status || 'Active', statusCls)}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn btn-sm btn-info btn-icon"    data-mu-act="view"   data-id="${Number(r.id)}" title="View"><i class="fas fa-eye"></i></button>
                        <button class="btn btn-sm btn-primary btn-icon" data-mu-act="edit"   data-id="${Number(r.id)}" title="Edit"><i class="fas fa-edit"></i></button>
                        <a      class="btn btn-sm btn-success btn-icon" href="download_receipt.php?id=${Number(r.id)}" target="_blank" title="Receipt"><i class="fas fa-file-pdf"></i></a>
                        <button class="btn btn-sm btn-danger btn-icon"  data-mu-act="delete" data-id="${Number(r.id)}" data-name="${esc(r.full_name)}" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>`;
        }).join('');
    }

    function buildListUrl(){const q=new URLSearchParams({action:'list',page:String(currentPage),per_page:$('#muPerPage')?.value||'25'});const v={search:$('#muSearch')?.value||'',position:$('#muPositionFilter')?.value||'',status:$('#muStatusFilter')?.value||'',period:$('#muPeriodFilter')?.value||'',date_from:$('#muDateFrom')?.value||'',date_to:$('#muDateTo')?.value||''};Object.entries(v).forEach(([k,x])=>{if(x)q.set(k,x)});return cfg.endpoint+'?'+q.toString()}
    function renderPagination(){const s=$('#muPaginationSummary'),b=$('#muPagination');if(s)s.textContent=pagination.total?`Showing ${pagination.from}–${pagination.to} of ${pagination.total} records`:'Showing 0 records';if(!b)return;b.innerHTML='';const add=(l,pg,d=false,a=false)=>{const x=document.createElement('button');x.type='button';x.textContent=l;x.disabled=d;if(a)x.classList.add('active');x.onclick=()=>{currentPage=pg;load().catch(e=>alertBox($('#muAlert'),e.message,'error'))};b.appendChild(x)};add('‹',Math.max(1,pagination.page-1),pagination.page<=1);let st=Math.max(1,pagination.page-2),en=Math.min(pagination.pages,st+4);st=Math.max(1,en-4);for(let i=st;i<=en;i++)add(String(i),i,false,i===pagination.page);add('›',Math.min(pagination.pages,pagination.page+1),pagination.page>=pagination.pages)}
    async function load(){const data=await req(buildListUrl());if(!data.success)throw new Error(data.message||'Failed to load records.');rows=data.items||[];pagination=data.pagination||{page:1,pages:1,total:rows.length,from:rows.length?1:0,to:rows.length};rowOffset=Math.max(0,Number(pagination.from||1)-1);render(rows);renderPagination()}

    /* =========================================================
       FORM RESET
    ========================================================= */
    function reset() {
        $('#muForm')?.reset();
        if ($('#muAction'))    $('#muAction').value    = 'create';
        if ($('#muId'))        $('#muId').value         = '';
        if ($('#muFormTitle')) $('#muFormTitle').innerHTML = '<i class="fas fa-user-plus"></i> Enrol Members';
        if (saveBtnLabel)      saveBtnLabel.textContent    = 'Enrol';
        clearAlert($('#muFormAlert'));
        if (pickerWrap)  pickerWrap.style.display  = '';
        if (searchInput) searchInput.value          = '';
        if (selectAllCb) { selectAllCb.checked = false; selectAllCb.indeterminate = false; }
        if (bulkNote)    bulkNote.style.display     = 'none';
        $('#muEditMemberInfo').style.display = 'none';
        $$('.mu-member-cb').forEach(cb => cb.checked = false);
        getMemberRows().forEach(row => row.style.display = '');
        updatePickerUI();
    }

    /* =========================================================
       EDIT
    ========================================================= */
    async function openEdit(id) {
        reset();
        const d = await req(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
        if (!d.success) return alertBox($('#muAlert'), d.message || 'Not found', 'error');
        const r = d.item;

        $('#muAction').value = 'update';
        $('#muId').value     = r.id;
        if ($('#muFormTitle')) $('#muFormTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Member';
        if (saveBtnLabel)      saveBtnLabel.textContent    = 'Update';

        if (pickerWrap) pickerWrap.style.display = 'none';
        const editInfo = $('#muEditMemberInfo');
        editInfo.style.display = '';
        const nameEl = $('#muEditMemberName');
        if (nameEl) nameEl.textContent = r.full_name || 'Member';

        if ($('#muPosition'))      $('#muPosition').value      = r.position     || 'Member';
        if ($('#muDateJoined'))    $('#muDateJoined').value     = r.date_joined  || cfg.today;
        if ($('#muSubscription'))  $('#muSubscription').value   = r.subscription || 0;
        if ($('#muPaymentMethod')) $('#muPaymentMethod').value  = 'Cash';
        if ($('#muStatus'))        $('#muStatus').value         = r.status       || 'Active';
        if ($('#muNotes'))         $('#muNotes').value          = r.notes        || '';

        openModal('#muFormModal');
    }

    /* =========================================================
       VIEW
    ========================================================= */
    function renderPaymentHistory(payments) {
        const tb = $('#muPaymentHistory');
        if (!tb) return;
        if (!payments || !payments.length) {
            tb.innerHTML = '<tr><td colspan="8" class="text-muted">No payment history found.</td></tr>';
            return;
        }
        tb.innerHTML = payments.map((p, i) => `<tr>
            <td>${i + 1}</td>
            <td><strong>${esc(p.receipt_no)}</strong></td>
            <td>${money(p.amount)}</td>
            <td>${esc(p.payment_date || '-')}</td>
            <td>${esc(p.payment_method || '-')}</td>
            <td>${Number(p.email_sent || 0) ? badge('Sent', 'badge-success') : badge('Pending', 'badge-secondary')}</td>
            <td>${Number(p.sms_sent  || 0) ? badge('Sent', 'badge-success') : badge('Pending', 'badge-secondary')}</td>
            <td>
                <div class="action-buttons">
                    <a class="btn btn-sm btn-success btn-icon" href="download_receipt.php?payment_id=${Number(p.id)}" target="_blank" title="Download Receipt"><i class="fas fa-file-pdf"></i></a>
                    <button class="btn btn-sm btn-primary btn-icon" data-mu-act="resend" data-payment-id="${Number(p.id)}" title="Resend Confirmation"><i class="fas fa-paper-plane"></i></button>
                </div>
            </td>
        </tr>`).join('');
    }

    async function openView(id) {
        clearAlert($('#muAlert'));
        $('#muViewContent').innerHTML = '<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading…</p>';
        $('#muPaymentHistory').innerHTML = '<tr><td colspan="8" class="text-muted">Loading…</td></tr>';
        openModal('#muViewModal');

        const d = await req(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
        if (!d.success) return alertBox($('#muAlert'), d.message || 'Not found', 'error');
        const r = d.item;

        const fields = [
            ['MU No.',       r.mu_no],
            ['Full Name',    r.full_name],
            ['Member No.',   r.member_no],
            ['Phone',        r.phone],
            ['Email',        r.email],
            ['Position',     r.position],
            ['Date Joined',  r.date_joined],
            ['Subscription', money(r.subscription)],
            ['Status',       r.status],
            ['Notes',        r.notes],
        ];
        $('#muViewContent').innerHTML = fields.map(([k, v]) =>
            `<div class="detail-item"><b>${esc(k)}</b><span>${esc(v || '-')}</span></div>`
        ).join('');
        renderPaymentHistory(d.payments || []);
    }

    /* =========================================================
       RESEND
    ========================================================= */
    async function resendConfirmation(paymentId) {
        const fd = new FormData();
        fd.append('action',      'resend_confirmation');
        fd.append('payment_id',  paymentId);
        fd.append('csrf_token',  document.querySelector('input[name="csrf_token"]')?.value || '');
        const d = await req(cfg.endpoint, { method: 'POST', body: fd });
        if (!d.success) throw new Error(d.message || 'Failed to resend confirmation.');
        alertBox($('#muAlert'), d.message || 'Confirmation sent.');
    }

    /* =========================================================
       GLOBAL CLICK HANDLER
    ========================================================= */
    document.addEventListener('click', e => {

        // ── Export: members CSV ─────────────────────────────
        if (e.target.closest('#muBtnExportCSV')) {
            const barOpen = $('#muExportBar')?.style.display !== 'none';
            if (!barOpen) activateExportBar('csv');
            else          executeExport();
            return;
        }

        // ── Export: members PDF ─────────────────────────────
        if (e.target.closest('#muBtnExportPDF')) {
            const barOpen = $('#muExportBar')?.style.display !== 'none';
            if (!barOpen) activateExportBar('pdf');
            else          executeExport();
            return;
        }

        // ── Export: bar Download button ─────────────────────
        if (e.target.closest('#muBtnExportBarGo')) { executeExport(); return; }

        // ── Export: close filter bar ────────────────────────
        if (e.target.closest('#muBtnExportBarClose')) {
            toggleExportBar(false);
            _exportTarget = null;
            return;
        }

        // Close modals
        if (e.target.closest('[data-mu-close]')) { closeModal(); return; }

        // Data-action buttons
        const b = e.target.closest('[data-mu-act]');
        if (!b) return;
        const { muAct: act, id, name, paymentId } = b.dataset;

        if (act === 'view')   openView(id).catch(err => alertBox($('#muAlert'), err.message, 'error'));
        if (act === 'edit')   openEdit(id).catch(err => alertBox($('#muAlert'), err.message, 'error'));
        if (act === 'resend') resendConfirmation(paymentId).catch(err => alertBox($('#muAlert'), err.message, 'error'));
        if (act === 'delete') {
            if ($('#muDeleteId'))   $('#muDeleteId').value        = id;
            if ($('#muDeleteName')) $('#muDeleteName').textContent = name || 'this member';
            clearAlert($('#muDeleteAlert'));
            openModal('#muDeleteModal');
        }
    });

    /* =========================================================
       PASSIVE LISTENERS
    ========================================================= */
    $('#btnOpenMU')?.addEventListener('click', () => { reset(); openModal('#muFormModal'); });
    $('#btnRefreshMU')?.addEventListener('click', () => load().catch(e => alertBox($('#muAlert'), e.message, 'error')));

    let listSearchTimer;
    $('#muSearch')?.addEventListener('input',()=>{clearTimeout(listSearchTimer);listSearchTimer=setTimeout(()=>{currentPage=1;load().catch(e=>alertBox($('#muAlert'),e.message,'error'))},300)});
    ['#muPositionFilter','#muStatusFilter','#muPerPage'].forEach(x=>$(x)?.addEventListener('change',()=>{currentPage=1;load().catch(e=>alertBox($('#muAlert'),e.message,'error'))}));
    $('#muPeriodFilter')?.addEventListener('change',()=>{const r=$('#muPeriodFilter').value==='range';if($('#muDateRange'))$('#muDateRange').hidden=!r;currentPage=1;if(!r)load().catch(e=>alertBox($('#muAlert'),e.message,'error'))});
    $('#muApplyRange')?.addEventListener('click',()=>{currentPage=1;load().catch(e=>alertBox($('#muAlert'),e.message,'error'))});
    $('#muClearFilters')?.addEventListener('click',()=>{['#muSearch','#muPositionFilter','#muStatusFilter','#muPeriodFilter','#muDateFrom','#muDateTo'].forEach(x=>{if($(x))$(x).value=''});if($('#muPerPage'))$('#muPerPage').value='25';if($('#muDateRange'))$('#muDateRange').hidden=true;currentPage=1;load().catch(e=>alertBox($('#muAlert'),e.message,'error'))});
    $('#muBackdrop')?.addEventListener('click', closeModal);
    document.addEventListener('keydown',  e => { if (e.key === 'Escape') closeModal(); });

    /* =========================================================
       FORM SUBMISSIONS
    ========================================================= */
    $('#muForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert($('#muFormAlert'));

        const isCreate = $('#muAction').value === 'create';
        if (isCreate && !$$('.mu-member-cb:checked').length) {
            alertBox($('#muFormAlert'), 'Please select at least one member to enrol.', 'error');
            return;
        }

        const btn     = $('#muSaveBtn');
        const oldHTML = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

        try {
            const d = await post($('#muForm'));
            if (!d.success) { alertBox($('#muFormAlert'), d.message || 'Save failed', 'error'); return; }
            closeModal();
            const c = d.confirmation
                ? ` Email: ${d.confirmation.email_sent ? 'sent' : 'not sent'}, SMS: ${d.confirmation.sms_sent ? 'sent' : 'not sent'}.`
                : '';
            alertBox($('#muAlert'), (d.message || 'Saved') + c);
            await load();
        } catch (err) {
            alertBox($('#muFormAlert'), err.message, 'error');
        } finally {
            btn.disabled  = false;
            btn.innerHTML = oldHTML;
        }
    });

    $('#muDeleteForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        try {
            const d = await post($('#muDeleteForm'));
            if (!d.success) return alertBox($('#muDeleteAlert'), d.message || 'Delete failed', 'error');
            closeModal();
            alertBox($('#muAlert'), d.message || 'Deleted.');
            await load();
        } catch (err) { alertBox($('#muDeleteAlert'), err.message, 'error'); }
    });

    /* =========================================================
       BOOT
    ========================================================= */
    const init = () => load().catch(e => alertBox($('#muAlert'), e.message, 'error'));
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

})();