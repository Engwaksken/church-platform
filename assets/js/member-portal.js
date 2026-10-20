document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const backdrop = document.getElementById('memberDisplayBackdrop');

    function openModal(modal) {
        if (!modal) return;
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        backdrop?.classList.add('show');
        document.body.classList.add('cms-modal-open');
    }

    function closeModals() {
        document.querySelectorAll('.member-display-modal').forEach(function (modal) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
        });
        backdrop?.classList.remove('show');
        document.body.classList.remove('cms-modal-open');
    }

    document.querySelectorAll('[data-member-view]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById('memberViewModal');
            const content = document.getElementById('memberViewContent');
            const title = document.getElementById('memberViewTitle');

            let record = {};
            try {
                record = JSON.parse(button.dataset.memberView || '{}');
            } catch (error) {
                record = {};
            }

            if (title) {
                title.textContent = button.dataset.title || 'Details';
            }

            if (content) {
                content.innerHTML = '';

                Object.entries(record).forEach(function ([label, value]) {
                    if (label.startsWith('_')) return;

                    const item = document.createElement('div');
                    item.className = 'member-detail-item';

                    if (String(value ?? '').length > 120) {
                        item.classList.add('full');
                    }

                    const heading = document.createElement('b');
                    heading.textContent = label.replaceAll('_', ' ');

                    const text = document.createElement('span');
                    text.textContent = String(value === null || value === '' ? '-' : value);

                    item.appendChild(heading);
                    item.appendChild(text);
                    content.appendChild(item);
                });
            }

            openModal(modal);
        });
    });

    document.querySelectorAll('[data-close-member-modal]').forEach(function (button) {
        button.addEventListener('click', closeModals);
    });

    backdrop?.addEventListener('click', closeModals);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModals();
    });

    const search = document.querySelector('[data-member-search]');
    const filters = document.querySelectorAll('[data-member-filter]');
    const rows = Array.from(document.querySelectorAll('[data-member-row]'));

    function applyClientFilters() {
        const term = (search?.value || '').toLowerCase().trim();

        rows.forEach(function (row) {
            const matchesTerm = term === '' || row.textContent.toLowerCase().includes(term);
            let matchesFilters = true;

            filters.forEach(function (filter) {
                const key = filter.dataset.memberFilter || '';
                const value = filter.value || '';
                if (value !== '' && (row.dataset[key] || '') !== value) {
                    matchesFilters = false;
                }
            });

            row.hidden = !(matchesTerm && matchesFilters);
        });
    }

    search?.addEventListener('input', applyClientFilters);
    filters.forEach(function (filter) {
        filter.addEventListener('change', applyClientFilters);
    });
});
