<?php
/**
 * Shared styles and modal/search behaviour for the member-content staff screens
 * (department_tips/ and worship_resources/). Expects the church-admin modal markup:
 * #backdrop, #formModal with #recordForm/#recordId/#formTitle, #viewModal with #details,
 * #deleteModal with #deleteId/#deleteName, and #recordSearch/#refreshRecords.
 */
?>
<style>
.mc-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}.mc-tabs a{padding:8px 14px;border:1px solid var(--border,#ddd);border-radius:8px;background:#fff;color:#333;text-decoration:none;font-weight:600}.mc-tabs a.active,.mc-tabs a:hover{background:var(--primary,#4a1e8f);border-color:var(--primary,#4a1e8f);color:#fff}
.mc-inline-form{display:inline;margin:0}.modal-detail span{white-space:pre-wrap;word-break:break-word}.church-form-help{display:block;color:var(--text-muted,#6b7280);font-size:12px;font-weight:400;margin-top:4px}
</style>
<script>
const $ = s => document.querySelector(s), $$ = s => Array.from(document.querySelectorAll(s));
function openModal(id) { $('#backdrop').classList.add('show'); $(id).classList.add('show'); document.body.classList.add('cms-modal-open'); }
function closeModal() { $$('.church-modal,.church-modal-backdrop').forEach(x => x.classList.remove('show')); document.body.classList.remove('cms-modal-open'); }
$$('.js-close').forEach(x => x.addEventListener('click', closeModal));
$('#backdrop').addEventListener('click', closeModal);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

/* Renders label/value pairs with textContent so staff-entered content is never parsed as HTML. */
function mcRenderDetails(fields) {
    const box = $('#details');
    box.replaceChildren();
    Object.entries(fields).forEach(([label, value]) => {
        const text = String(value ?? '').trim();
        const item = document.createElement('div');
        item.className = 'modal-detail' + (text.length > 60 || text.includes('\n') ? ' full' : '');
        const b = document.createElement('b'); b.textContent = label;
        const span = document.createElement('span'); span.textContent = text || '-';
        item.append(b, span);
        box.appendChild(item);
    });
}

/**
 * options: addTitle, editTitle, fields (form ids filled from the record), view(record) => {label: value},
 * onReset(), onEdit(record) for extra form state.
 */
function mcBindTable(options) {
    const fill = r => options.fields.forEach(k => { const el = document.getElementById(k); if (el) el.value = r[k] ?? ''; });
    $('#addBtn')?.addEventListener('click', () => {
        $('#recordForm').reset(); $('#recordId').value = ''; $('#formTitle').textContent = options.addTitle;
        options.onReset?.();
        openModal('#formModal');
    });
    $$('.js-edit').forEach(btn => btn.addEventListener('click', () => {
        const r = JSON.parse(btn.dataset.record || '{}');
        $('#recordForm').reset(); options.onReset?.();
        $('#recordId').value = r.id || ''; $('#formTitle').textContent = options.editTitle;
        fill(r); options.onEdit?.(r);
        openModal('#formModal');
    }));
    $$('.js-view').forEach(btn => btn.addEventListener('click', () => {
        mcRenderDetails(options.view(JSON.parse(btn.dataset.record || '{}')));
        openModal('#viewModal');
    }));
    $$('.js-delete').forEach(btn => btn.addEventListener('click', () => {
        $('#deleteId').value = btn.dataset.id || ''; $('#deleteName').textContent = btn.dataset.name || 'this record';
        openModal('#deleteModal');
    }));
    $('#recordSearch')?.addEventListener('input', e => {
        const q = e.target.value.toLowerCase().trim();
        $$('.church-table tbody tr[data-search]').forEach(row => row.hidden = q !== '' && !row.dataset.search.includes(q));
    });
    $('#refreshRecords')?.addEventListener('click', () => { const s = $('#recordSearch'); s.value = ''; s.dispatchEvent(new Event('input')); });
}
</script>
