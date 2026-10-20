(function () {
    'use strict';

    const cfg = window.FathersUnionConfig || {};
    if (!cfg.endpoint) return;

    const $  = (s, r = document) => r.querySelector(s);
    const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
    let rows = [];
    let rowOffset=0,currentPage=1,pagination={page:1,pages:1,total:0,from:0,to:0};

    const esc = v => String(v ?? '').replace(/[&<>'"]/g,
        c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', "'":'&#039;', '"':'&quot;' }[c]));
    const money = v => 'UGX ' + Number(v || 0).toLocaleString();
    const badge = (txt, cls = 'badge-secondary') =>
        `<span class="badge ${cls}">${esc(txt)}</span>`;

    /* =========================================================
       ALERTS
    ========================================================= */
    function alertBox(el, msg, type = 'success') {
        if (!el) return;
        el.className   = 'ajax-alert ' + (type === 'error' ? 'error' : 'success');
        el.textContent = msg || '';
        el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function clearAlert(el) {
        if (!el) return;
        el.className   = 'ajax-alert';
        el.textContent = '';
    }

    /* =========================================================
       MODALS
    ========================================================= */
    function openModal(id) {
        $('#fuBackdrop')?.classList.add('show');
        $(id)?.classList.add('show');
        $(id)?.setAttribute('aria-hidden', 'false');
        document.body.classList.add('cms-modal-open');
    }
    function closeModals() {
        $$('.app-modal, .app-modal-backdrop').forEach(x => x.classList.remove('show'));
        $$('.app-modal').forEach(x => x.setAttribute('aria-hidden', 'true'));
        document.body.classList.remove('cms-modal-open');
    }

    /* =========================================================
       HTTP
    ========================================================= */
    async function req(url, opts = {}) {
        const r = await fetch(url, Object.assign(
            { headers: { 'X-Requested-With': 'XMLHttpRequest' } }, opts));
        const t = await r.text();
        try { return JSON.parse(t); }
        catch (e) { throw new Error('Invalid server response: ' + t.slice(0, 220)); }
    }
    const post = form => req(cfg.endpoint, { method: 'POST', body: new FormData(form) });

    /* =========================================================
       EXPORT HELPERS
    ========================================================= */

    /** Build export URL with optional status filter from the export bar. */
    function exportUrl(action) {
        const status = $('#fuExportStatus')?.value || '';
        let url = cfg.endpoint + '?action=' + action;
        if (status) url += '&status=' + encodeURIComponent(status);
        return url;
    }

    /** Force a CSV file download via a hidden <a>. */
    function triggerDownload(url) {
        const a = document.createElement('a');
        a.href  = url; a.style.display = 'none';
        document.body.appendChild(a); a.click(); a.remove();
    }

    /** Open a URL in a new tab (used for PDFs). */
    function openTab(url) { window.open(url, '_blank'); }

    function toggleExportBar(show) {
        const bar = $('#fuExportBar');
        if (bar) bar.style.display = show ? 'flex' : 'none';
    }

    // Track which type is pending so the bar's Download button knows what to fire
    let _exportTarget = null; // 'csv' | 'pdf'

    function activateExportBar(type) {
        _exportTarget = type;
        toggleExportBar(true);
        const dlBtn = $('#fuBtnExportBarGo');
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
       MEMBER PICKER (checklist in enrol modal)
    ========================================================= */
    const pickerWrap     = $('#fuMemberPickerWrap');
    const searchInput    = $('#fuMemberSearch');
    const selectAllCb    = $('#fuSelectAll');
    const pickCountBadge = $('#fuPickCount');
    const bulkNote       = $('#fuBulkNote');
    const saveBtnLabel   = $('#fuSaveBtnLabel');

    function getMemberRows() {
        return $$('.fu-check-row:not(.fu-check-all)', $('#fuMemberChecklist'));
    }
    function countChecked() {
        return $$('.fu-member-cb:checked', $('#fuMemberChecklist')).length;
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
            const match = !term || (row.dataset.name ?? '').includes(term);
            row.style.display = match ? '' : 'none';
        });
        syncSelectAll();
    });

    selectAllCb?.addEventListener('change', () => {
        getMemberRows().forEach(row => {
            if (row.style.display === 'none') return;
            const cb = row.querySelector('.fu-member-cb');
            if (cb) cb.checked = selectAllCb.checked;
        });
        updatePickerUI();
    });

    document.addEventListener('change', e => {
        if (e.target.classList.contains('fu-member-cb')) {
            syncSelectAll();
            updatePickerUI();
        }
    });

    function syncSelectAll() {
        if (!selectAllCb) return;
        const visible    = getMemberRows().filter(r => r.style.display !== 'none');
        const checkedVis = visible.filter(r => r.querySelector('.fu-member-cb')?.checked);
        selectAllCb.indeterminate = checkedVis.length > 0 && checkedVis.length < visible.length;
        selectAllCb.checked       = visible.length > 0 && checkedVis.length === visible.length;
    }

    document.addEventListener('mouseover', e => {
        const row = e.target.closest('.fu-check-row:not(.fu-check-all)');
        if (row) row.style.background = '#f5f1ff';
    });
    document.addEventListener('mouseout', e => {
        const row = e.target.closest('.fu-check-row:not(.fu-check-all)');
        if (row) row.style.background = '';
    });

    /* =========================================================
       STATS
    ========================================================= */
    function renderStats(items) {
        const active   = items.filter(x => (x.status || 'Active') === 'Active').length;
        const inactive = items.filter(x => (x.status || 'Active') !== 'Active').length;
        const subs     = items.reduce((s, x) => s + Number(x.subscription || 0), 0);
        if ($('#fuStatTotal'))    $('#fuStatTotal').textContent    = items.length;
        if ($('#fuStatActive'))   $('#fuStatActive').textContent   = active;
        if ($('#fuStatInactive')) $('#fuStatInactive').textContent = inactive;
        if ($('#fuStatSubs'))     $('#fuStatSubs').textContent     = money(subs);
    }

    /* =========================================================
       TABLE RENDER
    ========================================================= */
    function statusBadge(status) {
        const s   = status || 'Active';
        const cls = s === 'Active'    ? 'badge-success'
                  : s === 'Suspended' ? 'badge-warning'
                  : 'badge-secondary';
        return badge(s, cls);
    }

    function renderTable(items) {
        const tb = $('#fuTableBody');
        if (!tb) return;
        if (!items.length) {
            tb.innerHTML = `<tr><td colspan="9">
                <p>No Fathers' Union records found.</p>
            </td></tr>`;
            return;
        }
        tb.innerHTML = items.map((r, i) => `
        <tr>
            <td class="text-muted">${rowOffset + i + 1}</td>
            <td><strong>${esc(r.fu_no || '-')}</strong></td>
            <td>
                <div class="record-info">
                    <div class="record-avatar">${esc((r.full_name || '?')[0].toUpperCase())}</div>
                    <div>
                        <div class="record-name">${esc(r.full_name || '-')}</div>
                        <div class="record-sub">${esc(r.member_no || '')}</div>
                    </div>
                </div>
            </td>
            <td>${esc(r.phone || '-')}</td>
            <td>${badge(r.position || 'Member', 'badge-info')}</td>
            <td>${esc(r.date_joined || '-')}</td>
            <td><strong>${money(r.subscription)}</strong></td>
            <td>${statusBadge(r.status)}</td>
            <td>
                <div class="action-buttons">
                    <button type="button" class="btn btn-sm btn-info btn-icon"
                            data-act="view"   data-id="${Number(r.id)}" title="View">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-primary btn-icon"
                            data-act="edit"   data-id="${Number(r.id)}" title="Edit">
                        <i class="fas fa-edit"></i>
                    </button>
                    <a class="btn btn-sm btn-success btn-icon"
                       href="download_receipt.php?id=${Number(r.id)}"
                       target="_blank" title="Receipt">
                        <i class="fas fa-file-pdf"></i>
                    </a>
                    <button type="button" class="btn btn-sm btn-warning btn-icon"
                            data-act="resend" data-id="${Number(r.id)}" title="Resend confirmation">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-danger btn-icon"
                            data-act="delete" data-id="${Number(r.id)}"
                            data-name="${esc(r.full_name || 'this member')}" title="Delete">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`).join('');
    }

    function buildListUrl(){const q=new URLSearchParams({action:'list',page:String(currentPage),per_page:$('#fuPerPage')?.value||'25'});const v={search:$('#fuSearch')?.value||'',position:$('#fuPositionFilter')?.value||'',status:$('#fuStatusFilter')?.value||'',period:$('#fuPeriodFilter')?.value||'',date_from:$('#fuDateFrom')?.value||'',date_to:$('#fuDateTo')?.value||''};Object.entries(v).forEach(([k,x])=>{if(x)q.set(k,x)});return cfg.endpoint+'?'+q.toString()}
    function renderPagination(){const s=$('#fuPaginationSummary'),b=$('#fuPagination');if(s)s.textContent=pagination.total?`Showing ${pagination.from}–${pagination.to} of ${pagination.total} records`:'Showing 0 records';if(!b)return;b.innerHTML='';const add=(l,pg,d=false,a=false)=>{const x=document.createElement('button');x.type='button';x.textContent=l;x.disabled=d;if(a)x.classList.add('active');x.onclick=()=>{currentPage=pg;load().catch(e=>alertBox($('#fuAlert'),e.message,'error'))};b.appendChild(x)};add('‹',Math.max(1,pagination.page-1),pagination.page<=1);let st=Math.max(1,pagination.page-2),en=Math.min(pagination.pages,st+4);st=Math.max(1,en-4);for(let i=st;i<=en;i++)add(String(i),i,false,i===pagination.page);add('›',Math.min(pagination.pages,pagination.page+1),pagination.page>=pagination.pages)}
    async function load(){const data=await req(buildListUrl());if(!data.success)throw new Error(data.message||'Failed to load records.');rows=data.items||[];pagination=data.pagination||{page:1,pages:1,total:rows.length,from:rows.length?1:0,to:rows.length};rowOffset=Math.max(0,Number(pagination.from||1)-1);renderTable(rows);renderPagination()}

    /* =========================================================
       FORM RESET
    ========================================================= */
    function resetForm() {
        $('#fuForm')?.reset();
        if ($('#fuAction'))    $('#fuAction').value    = 'create';
        if ($('#fuId'))        $('#fuId').value         = '';
        if ($('#fuFormTitle')) $('#fuFormTitle').innerHTML =
            '<i class="fas fa-user-plus"></i> Enrol Fathers\' Union Members';
        if (saveBtnLabel)      saveBtnLabel.textContent  = 'Enrol';
        clearAlert($('#fuFormAlert'));

        if (pickerWrap)  pickerWrap.style.display  = '';
        if (searchInput) searchInput.value          = '';
        if (selectAllCb) { selectAllCb.checked = false; selectAllCb.indeterminate = false; }
        if (bulkNote)    bulkNote.style.display     = 'none';
        $('#fuEditMemberInfo').style.display = 'none';

        $$('.fu-member-cb').forEach(cb => cb.checked = false);
        getMemberRows().forEach(row => row.style.display = '');
        updatePickerUI();
    }

    /* =========================================================
       EDIT
    ========================================================= */
    async function openEdit(id) {
        resetForm();
        try {
            const data = await req(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
            if (!data.success) return alertBox($('#fuAlert'), data.message || 'Record not found.', 'error');
            const r = data.item || {};

            $('#fuAction').value = 'update';
            $('#fuId').value     = r.id || '';
            if ($('#fuFormTitle')) $('#fuFormTitle').innerHTML =
                '<i class="fas fa-edit"></i> Edit Fathers\' Union Member';
            if (saveBtnLabel) saveBtnLabel.textContent = 'Update Member';

            if (pickerWrap) pickerWrap.style.display = 'none';
            const editInfo = $('#fuEditMemberInfo');
            editInfo.style.display = '';
            const nameEl = $('#fuEditMemberName');
            if (nameEl) nameEl.textContent = r.full_name || 'Member';

            if ($('#fuPosition'))     $('#fuPosition').value     = r.position     || 'Member';
            if ($('#fuDateJoined'))   $('#fuDateJoined').value   = r.date_joined  || cfg.today;
            if ($('#fuSubscription')) $('#fuSubscription').value = r.subscription || 0;
            if ($('#fuStatus'))       $('#fuStatus').value       = r.status       || 'Active';
            if ($('#fuNotes'))        $('#fuNotes').value        = r.notes        || '';

            openModal('#fuFormModal');
        } catch (err) { alertBox($('#fuAlert'), err.message, 'error'); }
    }

    /* =========================================================
       VIEW
    ========================================================= */
    async function openView(id) {
        clearAlert($('#fuAlert'));
        $('#fuViewContent').innerHTML =
            '<p class="text-muted"><i class="fas fa-spinner fa-spin"></i> Loading�</p>';
        $('#fuPaymentRows').innerHTML =
            '<tr><td colspan="6" class="text-muted">Loading�</td></tr>';
        openModal('#fuViewModal');

        try {
            const data = await req(cfg.endpoint + '?action=get&id=' + encodeURIComponent(id));
            if (!data.success) return alertBox($('#fuAlert'), data.message || 'Not found.', 'error');
            const r = data.item || {};

            const fields = [
                ['FU No.',       r.fu_no],
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
            $('#fuViewContent').innerHTML = fields.map(([k, v]) =>
                `<div class="detail-item"><b>${esc(k)}</b><span>${esc(v || '-')}</span></div>`
            ).join('');

            const payments = data.payments || [];
            $('#fuPaymentRows').innerHTML = payments.length
                ? payments.map((p, i) => `<tr>
                    <td>${i + 1}</td>
                    <td><strong>${esc(p.receipt_no || p.receipt_number || '-')}</strong></td>
                    <td>${esc(p.payment_date || p.transaction_date || '-')}</td>
                    <td>${money(p.amount)}</td>
                    <td>${esc(p.method || p.payment_method || '-')}</td>
                    <td>
                        <a class="btn btn-sm btn-success btn-icon"
                           href="${esc(p.receipt_url || 'download_receipt.php?id=' + Number(r.id))}"
                           target="_blank" title="Download Receipt">
                            <i class="fas fa-file-pdf"></i>
                        </a>
                    </td>
                </tr>`).join('')
                : '<tr><td colspan="6" class="text-muted">No payment history.</td></tr>';

        } catch (err) { alertBox($('#fuAlert'), err.message, 'error'); }
    }

    /* =========================================================
       GLOBAL CLICK HANDLER
    ========================================================= */
    document.addEventListener('click', async e => {

        // -- Export: members CSV -----------------------------
        if (e.target.closest('#fuBtnExportCSV')) {
            const barOpen = $('#fuExportBar')?.style.display !== 'none';
            if (!barOpen) activateExportBar('csv');
            else          executeExport();
            return;
        }

        // -- Export: members PDF -----------------------------
        if (e.target.closest('#fuBtnExportPDF')) {
            const barOpen = $('#fuExportBar')?.style.display !== 'none';
            if (!barOpen) activateExportBar('pdf');
            else          executeExport();
            return;
        }

        // -- Export: bar Download button ---------------------
        if (e.target.closest('#fuBtnExportBarGo')) { executeExport(); return; }

        // -- Export: close filter bar ------------------------
        if (e.target.closest('#fuBtnExportBarClose')) {
            toggleExportBar(false);
            _exportTarget = null;
            return;
        }

        // Close modals
        if (e.target.closest('[data-fu-close]')) { closeModals(); return; }

        // Data-action buttons
        const btn = e.target.closest('[data-act]');
        if (!btn) return;
        const { act, id, name } = btn.dataset;

        if (act === 'view')   { openView(id).catch(err => alertBox($('#fuAlert'), err.message, 'error')); return; }
        if (act === 'edit')   { openEdit(id).catch(err => alertBox($('#fuAlert'), err.message, 'error')); return; }

        if (act === 'resend') {
            const fd = new FormData();
            fd.append('csrf_token', cfg.csrfToken || '');
            fd.append('action',     'resend_confirmation');
            fd.append('id',         id);
            try {
                const data = await req(cfg.endpoint, { method: 'POST', body: fd });
                if (!data.success) throw new Error(data.message || 'Failed.');
                alertBox($('#fuAlert'),
                    'Confirmation processed. Email: ' + (data.email_sent ? 'sent' : 'not sent') +
                    ', SMS: ' + (data.sms_sent ? 'sent' : 'not sent') + '.');
            } catch (err) { alertBox($('#fuAlert'), err.message, 'error'); }
            return;
        }

        if (act === 'delete') {
            if ($('#fuDeleteId'))   $('#fuDeleteId').value        = id;
            if ($('#fuDeleteName')) $('#fuDeleteName').textContent = name || 'this member';
            clearAlert($('#fuDeleteAlert'));
            openModal('#fuDeleteModal');
        }
    });

    /* =========================================================
       PASSIVE LISTENERS
    ========================================================= */
    $('#btnOpenFuModal')?.addEventListener('click', () => { resetForm(); openModal('#fuFormModal'); });
    $('#btnRefreshFu')?.addEventListener('click', () =>
        load().catch(e => alertBox($('#fuAlert'), e.message, 'error'))
    );

    let listSearchTimer;
    $('#fuSearch')?.addEventListener('input',()=>{clearTimeout(listSearchTimer);listSearchTimer=setTimeout(()=>{currentPage=1;load().catch(e=>alertBox($('#fuAlert'),e.message,'error'))},300)});
    ['#fuPositionFilter','#fuStatusFilter','#fuPerPage'].forEach(x=>$(x)?.addEventListener('change',()=>{currentPage=1;load().catch(e=>alertBox($('#fuAlert'),e.message,'error'))}));
    $('#fuPeriodFilter')?.addEventListener('change',()=>{const r=$('#fuPeriodFilter').value==='range';if($('#fuDateRange'))$('#fuDateRange').hidden=!r;currentPage=1;if(!r)load().catch(e=>alertBox($('#fuAlert'),e.message,'error'))});
    $('#fuApplyRange')?.addEventListener('click',()=>{currentPage=1;load().catch(e=>alertBox($('#fuAlert'),e.message,'error'))});
    $('#fuClearFilters')?.addEventListener('click',()=>{['#fuSearch','#fuPositionFilter','#fuStatusFilter','#fuPeriodFilter','#fuDateFrom','#fuDateTo'].forEach(x=>{if($(x))$(x).value=''});if($('#fuPerPage'))$('#fuPerPage').value='25';if($('#fuDateRange'))$('#fuDateRange').hidden=true;currentPage=1;load().catch(e=>alertBox($('#fuAlert'),e.message,'error'))});
    $('#fuBackdrop')?.addEventListener('click', closeModals);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModals(); });

    /* =========================================================
       FORM SUBMISSIONS
    ========================================================= */
    $('#fuForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert($('#fuFormAlert'));

        const isCreate = $('#fuAction').value === 'create';
        if (isCreate && !$$('.fu-member-cb:checked').length) {
            alertBox($('#fuFormAlert'), 'Please select at least one member to enrol.', 'error');
            return;
        }

        const btn     = $('#fuSaveBtn');
        const oldHTML = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving�'; }

        try {
            const data = await post($('#fuForm'));
            if (!data.success) { alertBox($('#fuFormAlert'), data.message || 'Save failed.', 'error'); return; }
            closeModals();
            alertBox($('#fuAlert'), data.message || 'Saved successfully.');
            await load();
        } catch (err) {
            alertBox($('#fuFormAlert'), err.message, 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = oldHTML; }
        }
    });

    $('#fuDeleteForm')?.addEventListener('submit', async e => {
        e.preventDefault();
        clearAlert($('#fuDeleteAlert'));
        try {
            const data = await post($('#fuDeleteForm'));
            if (!data.success) return alertBox($('#fuDeleteAlert'), data.message || 'Remove failed.', 'error');
            closeModals();
            alertBox($('#fuAlert'), data.message || 'Removed successfully.');
            await load();
        } catch (err) { alertBox($('#fuDeleteAlert'), err.message, 'error'); }
    });

    /* =========================================================
       BOOT
    ========================================================= */
    load().catch(err => alertBox($('#fuAlert'), err.message, 'error'));

})();