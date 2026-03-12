/**
 * Problem Orders - Shared JavaScript
 * Live timers, column sorting, collapse/expand, CSV export,
 * event filter, bulk actions
 * Location: /admin/problem-js.js
 */

// ============================================
// LIVE TIMERS
// ============================================
function formatElapsed(seconds) {
    if (seconds < 0) seconds = 0;
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    let parts = [];
    if (d > 0) parts.push(d + 'd');
    parts.push(h + 'h');
    parts.push(String(m).padStart(2, '0') + 'm');
    parts.push(String(s).padStart(2, '0') + 's');
    return parts.join(' ');
}

function updateTimers() {
    const now = Math.floor(Date.now() / 1000);
    document.querySelectorAll('.prob-timer').forEach(el => {
        const ts = parseInt(el.getAttribute('data-ts'), 10);
        if (!ts) return;
        const elapsed = now - ts;
        el.textContent = formatElapsed(elapsed);
        el.classList.remove('timer-ok', 'timer-warn', 'timer-crit');
        const hours = elapsed / 3600;
        if (hours >= 48) el.classList.add('timer-crit');
        else if (hours >= 8) el.classList.add('timer-warn');
        else el.classList.add('timer-ok');
    });
}

setInterval(updateTimers, 1000);
document.addEventListener('DOMContentLoaded', updateTimers);

// ============================================
// COLUMN SORTING
// ============================================
function sortTable(th) {
    const table = th.closest('table');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    const colIdx = Array.from(th.parentNode.children).indexOf(th);
    const isAsc = th.classList.contains('sort-asc');

    th.parentNode.querySelectorAll('th').forEach(h => {
        h.classList.remove('sort-asc', 'sort-desc');
    });
    th.classList.add(isAsc ? 'sort-desc' : 'sort-asc');

    rows.sort((a, b) => {
        const cellA = a.children[colIdx];
        const cellB = b.children[colIdx];
        if (!cellA || !cellB) return 0;

        const timerA = cellA.querySelector('.prob-timer');
        const timerB = cellB.querySelector('.prob-timer');
        if (timerA && timerB) {
            const tsA = parseInt(timerA.getAttribute('data-ts'), 10) || 0;
            const tsB = parseInt(timerB.getAttribute('data-ts'), 10) || 0;
            return isAsc ? tsB - tsA : tsA - tsB;
        }

        let valA = cellA.textContent.trim();
        let valB = cellB.textContent.trim();
        const numA = parseFloat(valA.replace(/[^0-9.\-]/g, ''));
        const numB = parseFloat(valB.replace(/[^0-9.\-]/g, ''));
        if (!isNaN(numA) && !isNaN(numB)) {
            return isAsc ? numB - numA : numA - numB;
        }

        return isAsc ? valB.localeCompare(valA) : valA.localeCompare(valB);
    });

    rows.forEach(row => tbody.appendChild(row));
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.prob-table th').forEach(th => {
        const text = th.textContent.trim();
        if (text === 'Actions' || text === '') return;
        th.classList.add('sortable');
        th.addEventListener('click', () => sortTable(th));
    });
});

// ============================================
// COLLAPSE / EXPAND ALL
// ============================================
function toggleAllSections(expand) {
    document.querySelectorAll('.prob-section-header').forEach(header => {
        const body = header.nextElementSibling;
        if (expand) {
            header.classList.remove('collapsed');
            if (body) body.classList.remove('hidden');
        } else {
            header.classList.add('collapsed');
            if (body) body.classList.add('hidden');
        }
    });
    const btn = document.getElementById('toggleAllBtn');
    if (btn) {
        const allCollapsed = document.querySelectorAll('.prob-section-header.collapsed').length ===
                            document.querySelectorAll('.prob-section-header').length;
        btn.textContent = allCollapsed ? '\u25B8 Expand All' : '\u25BE Collapse All';
        btn.setAttribute('data-state', allCollapsed ? 'collapsed' : 'expanded');
    }
}

function toggleAllClick() {
    const btn = document.getElementById('toggleAllBtn');
    const state = btn ? btn.getAttribute('data-state') : 'expanded';
    toggleAllSections(state === 'collapsed');
}

function toggleSection(el) {
    el.classList.toggle('collapsed');
    const body = el.nextElementSibling;
    if (body) body.classList.toggle('hidden');
}

// ============================================
// EVENT FILTER
// ============================================
function applyEventFilter() {
    const sel = document.getElementById('eventFilter');
    if (!sel) return;
    const val = sel.value;
    const url = new URL(window.location.href);
    if (val) {
        url.searchParams.set('event', val);
    } else {
        url.searchParams.delete('event');
    }
    window.location.href = url.toString();
}

// ============================================
// BULK SELECTION
// ============================================
let bulkSelected = new Set();

function updateBulkToolbar() {
    const toolbar = document.getElementById('bulkToolbar');
    const count = document.getElementById('bulkCount');
    if (!toolbar) return;
    
    bulkSelected.clear();
    document.querySelectorAll('.prob-row-checkbox:checked').forEach(cb => {
        bulkSelected.add(cb.value);
    });
    
    if (bulkSelected.size > 0) {
        toolbar.classList.add('visible');
        if (count) count.textContent = bulkSelected.size;
    } else {
        toolbar.classList.remove('visible');
    }
}

function toggleSelectAll(masterCheckbox) {
    const section = masterCheckbox.closest('.prob-section');
    if (!section) return;
    const checkboxes = section.querySelectorAll('.prob-row-checkbox');
    checkboxes.forEach(cb => { cb.checked = masterCheckbox.checked; });
    updateBulkToolbar();
}

document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('change', e => {
        if (e.target.classList.contains('prob-row-checkbox')) {
            updateBulkToolbar();
        }
    });
});

function dismissBulkToolbar() {
    document.querySelectorAll('.prob-row-checkbox, .prob-select-all').forEach(cb => { cb.checked = false; });
    bulkSelected.clear();
    const toolbar = document.getElementById('bulkToolbar');
    if (toolbar) toolbar.classList.remove('visible');
}

function bulkAction(action) {
    if (bulkSelected.size === 0) return;
    
    const orders = Array.from(bulkSelected);
    
    if (action === 'export') {
        exportSelectedOrders(orders);
        return;
    }
    
    if (action === 'cancel') {
        showModal({
            type: 'danger',
            title: 'Cancel ' + orders.length + ' Order' + (orders.length !== 1 ? 's' : '') + '?',
            message: 'This will permanently set ' + (orders.length === 1 ? 'this order' : 'these orders') + ' to <strong>cancelled</strong>. This cannot be undone.',
            confirmText: 'Cancel Orders',
            input: true,
            inputPlaceholder: 'Type "cancel" to confirm',
            inputMatch: 'cancel'
        }).then(confirmed => {
            if (confirmed) doBulkAction('bulk_cancel', { orders: orders });
        });
        return;
    }
    
    if (action === 'status') {
        const newStatus = document.getElementById('bulkStatusSelect')?.value;
        if (!newStatus) { alert('Select a status first'); return; }
        showModal({
            type: 'warning',
            title: 'Change Status',
            message: 'Update <strong>' + orders.length + '</strong> order' + (orders.length !== 1 ? 's' : '') + ' to <strong>' + newStatus + '</strong>?',
            confirmText: 'Apply Change'
        }).then(confirmed => {
            if (confirmed) doBulkAction('bulk_status', { orders: orders, new_status: newStatus });
        });
        return;
    }
    
    if (action === 'remind') {
        showModal({
            type: 'info',
            title: 'Send Reminders',
            message: 'Send a reminder for <strong>' + orders.length + '</strong> order' + (orders.length !== 1 ? 's' : '') + '?',
            confirmText: 'Send Reminders'
        }).then(confirmed => {
            if (confirmed) doBulkAction('bulk_remind', { orders: orders });
        });
        return;
    }
}

function doBulkAction(action, data) {
    const formData = new FormData();
    formData.append('action', action);
    data.orders.forEach(o => formData.append('orders[]', o));
    if (data.new_status) formData.append('new_status', data.new_status);
    
    // Determine correct path based on page location
    const apiUrl = getApiUrl();
    
    fetch(apiUrl, { method: 'POST', body: formData })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                alert(result.processed + ' order(s) updated successfully.');
                location.reload();
            } else {
                alert('Error: ' + (result.error || 'Unknown error'));
            }
        })
        .catch(err => {
            alert('Request failed: ' + err.message);
        });
}

function exportSelectedOrders(orders) {
    const rows = ['"Reference","Customer","Status","Tier","Event","Due Date"'];
    document.querySelectorAll('.prob-row-checkbox:checked').forEach(cb => {
        const tr = cb.closest('tr');
        if (!tr) return;
        const cells = Array.from(tr.querySelectorAll('td'));
        const ref = cb.value;
        const data = cells.slice(1).map(td => {
            // Skip checkbox and actions columns
            if (td.classList.contains('prob-checkbox-cell') || td.classList.contains('prob-actions')) return null;
            return '"' + td.textContent.trim().replace(/"/g, '""') + '"';
        }).filter(Boolean);
        rows.push('"' + ref + '",' + data.join(','));
    });
    
    const csv = rows.join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'selected_problems_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// ============================================
// CSV EXPORT (per-section and full page)
// ============================================
function exportSection(sectionEl) {
    const table = sectionEl.querySelector('.prob-table');
    if (!table) return;
    const header = sectionEl.querySelector('.prob-section-title');
    const title = header ? header.textContent.replace(/[^\w\s]/g, '').trim().replace(/\s+/g, '_') : 'export';
    const rows = [];

    const ths = Array.from(table.querySelectorAll('thead th'));
    const skipCols = [];
    const headerRow = [];
    ths.forEach((th, i) => {
        const t = th.textContent.trim();
        if (t === 'Actions' || t === '') { skipCols.push(i); return; }
        headerRow.push('"' + t.replace(/"/g, '""') + '"');
    });
    rows.push(headerRow.join(','));

    table.querySelectorAll('tbody tr').forEach(tr => {
        const cells = [];
        Array.from(tr.children).forEach((td, i) => {
            if (skipCols.includes(i)) return;
            if (td.classList.contains('prob-checkbox-cell')) return;
            cells.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
        });
        rows.push(cells.join(','));
    });

    downloadCSV(rows.join('\n'), title);
}

function exportAll() {
    const tables = document.querySelectorAll('.prob-table');
    if (tables.length === 0) return;
    const rows = [];
    let isFirst = true;

    tables.forEach(table => {
        const section = table.closest('.prob-section');
        const header = section ? section.querySelector('.prob-section-title') : null;
        const sectionName = header ? header.textContent.replace(/[^\w\s]/g, '').trim() : '';

        if (!isFirst) rows.push('');
        rows.push('"=== ' + sectionName + ' ==="');

        const ths = Array.from(table.querySelectorAll('thead th'));
        const skipCols = [];
        const headerRow = [];
        ths.forEach((th, i) => {
            const t = th.textContent.trim();
            if (t === 'Actions' || t === '') { skipCols.push(i); return; }
            headerRow.push('"' + t.replace(/"/g, '""') + '"');
        });
        rows.push(headerRow.join(','));

        table.querySelectorAll('tbody tr').forEach(tr => {
            const cells = [];
            Array.from(tr.children).forEach((td, i) => {
                if (skipCols.includes(i)) return;
                if (td.classList.contains('prob-checkbox-cell')) return;
                cells.push('"' + td.textContent.trim().replace(/"/g, '""') + '"');
            });
            rows.push(cells.join(','));
        });
        isFirst = false;
    });

    downloadCSV(rows.join('\n'), 'problem_orders');
}

function downloadCSV(csv, title) {
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = title + '_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
}

// ============================================
// STAT CARD CLICK-TO-JUMP
// ============================================
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.prob-stat-card.clickable').forEach(card => {
        card.addEventListener('click', (e) => {
            // Don't hijack <a> link clicks inside the card
            if (e.target.tagName === 'A') return;
            const sectionId = card.getAttribute('data-section');
            if (!sectionId) return;
            const section = document.getElementById(sectionId);
            if (!section) return;
            // Expand if collapsed
            const header = section.querySelector('.prob-section-header');
            if (header && header.classList.contains('collapsed')) {
                header.classList.remove('collapsed');
                const body = header.nextElementSibling;
                if (body) body.classList.remove('hidden');
            }
            section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            // Brief highlight
            section.classList.add('section-highlight');
            setTimeout(() => section.classList.remove('section-highlight'), 1500);
        });
    });
});

// ============================================
// THEMED MODAL SYSTEM
// ============================================
function showModal(options) {
    return new Promise((resolve) => {
        // Remove any existing modal
        const existing = document.getElementById('probModal');
        if (existing) existing.remove();

        const overlay = document.createElement('div');
        overlay.id = 'probModal';
        overlay.className = 'prob-modal-overlay';

        let inputHtml = '';
        if (options.input) {
            inputHtml = '<input type="text" id="probModalInput" class="prob-modal-input" placeholder="' + (options.inputPlaceholder || '') + '" autocomplete="off">';
        }

        const iconMap = { danger: '&#128465;', warning: '&#9888;', info: '&#128276;' };
        const icon = iconMap[options.type] || iconMap.warning;
        const confirmClass = options.type === 'danger' ? 'prob-modal-btn-danger' : 'prob-modal-btn-primary';

        overlay.innerHTML =
            '<div class="prob-modal">' +
            '<div class="prob-modal-icon ' + (options.type || 'warning') + '">' + icon + '</div>' +
            '<div class="prob-modal-title">' + (options.title || 'Confirm') + '</div>' +
            '<div class="prob-modal-body">' + (options.message || '') + '</div>' +
            inputHtml +
            '<div class="prob-modal-buttons">' +
            '<button class="prob-modal-btn prob-modal-btn-cancel" id="probModalCancel">Cancel</button>' +
            '<button class="prob-modal-btn ' + confirmClass + '" id="probModalConfirm">' + (options.confirmText || 'Confirm') + '</button>' +
            '</div></div>';

        document.body.appendChild(overlay);
        requestAnimationFrame(() => overlay.classList.add('visible'));

        const inputEl = document.getElementById('probModalInput');
        if (inputEl) inputEl.focus();

        function close(result) {
            overlay.classList.remove('visible');
            setTimeout(() => overlay.remove(), 200);
            resolve(result);
        }

        document.getElementById('probModalCancel').addEventListener('click', () => close(false));
        document.getElementById('probModalConfirm').addEventListener('click', () => {
            if (options.input) {
                const val = (inputEl?.value || '').trim().toLowerCase();
                if (options.inputMatch && val !== options.inputMatch) {
                    inputEl.classList.add('prob-modal-input-error');
                    inputEl.focus();
                    return;
                }
            }
            close(true);
        });
        overlay.addEventListener('click', (e) => { if (e.target === overlay) close(false); });
        if (inputEl) {
            inputEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') document.getElementById('probModalConfirm').click();
                if (e.key === 'Escape') close(false);
            });
        }
        document.addEventListener('keydown', function esc(e) {
            if (e.key === 'Escape') { close(false); document.removeEventListener('keydown', esc); }
        });
    });
}

// ============================================
// INLINE NOTES
// ============================================
function toggleNotes(refCode, btn) {
    const row = btn.closest('tr');
    const existing = row.nextElementSibling;
    
    // Close if already open
    if (existing && existing.classList.contains('prob-notes-row')) {
        existing.remove();
        return;
    }
    
    // Close any other open notes
    document.querySelectorAll('.prob-notes-row').forEach(r => r.remove());
    
    const colSpan = row.children.length;
    const noteRow = document.createElement('tr');
    noteRow.className = 'prob-notes-row';
    noteRow.innerHTML = '<td colspan="' + colSpan + '"><div class="prob-notes-panel">' +
        '<div class="prob-notes-list" id="notesList-' + refCode + '"><span class="prob-notes-loading">Loading notes...</span></div>' +
        '<div class="prob-notes-add">' +
        '<input type="text" class="prob-notes-input" id="noteInput-' + refCode + '" placeholder="Add a quick note..." maxlength="500">' +
        '<button class="prob-notes-submit" onclick="addNote(\'' + refCode + '\')">Add</button>' +
        '</div></div></td>';
    
    row.after(noteRow);
    
    // Enter key to submit
    document.getElementById('noteInput-' + refCode).addEventListener('keydown', (e) => {
        if (e.key === 'Enter') addNote(refCode);
    });
    
    // Load notes
    loadNotes(refCode);
}

function getApiUrl() {
    const isDispatch = window.location.pathname.includes('/dispatch/');
    return isDispatch ? '../admin/problem-actions.php' : 'problem-actions.php';
}

function loadNotes(refCode) {
    const listEl = document.getElementById('notesList-' + refCode);
    if (!listEl) return;
    
    fetch(getApiUrl() + '?action=get_notes&ref=' + encodeURIComponent(refCode))
        .then(r => r.json())
        .then(data => {
            if (!data.success || data.notes.length === 0) {
                listEl.innerHTML = '<span class="prob-notes-empty">No notes yet</span>';
                return;
            }
            listEl.innerHTML = data.notes.map((n, i) => {
                const when = new Date(n.at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
                return '<div class="prob-note-item">' +
                    '<span class="prob-note-text">' + escapeHtml(n.text) + '</span>' +
                    '<span class="prob-note-meta">' + escapeHtml(n.by) + ' &middot; ' + when + '</span>' +
                    '<button class="prob-note-delete" onclick="deleteNote(\'' + refCode + '\',' + i + ')" title="Delete">&times;</button>' +
                    '</div>';
            }).join('');
        })
        .catch(() => { listEl.innerHTML = '<span class="prob-notes-empty">Failed to load notes</span>'; });
}

function addNote(refCode) {
    const input = document.getElementById('noteInput-' + refCode);
    const text = (input?.value || '').trim();
    if (!text) return;
    
    input.disabled = true;
    const formData = new FormData();
    formData.append('action', 'add_note');
    formData.append('ref', refCode);
    formData.append('text', text);
    
    fetch(getApiUrl(), { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                input.value = '';
                loadNotes(refCode);
                // Update badge count on the button
                updateNoteBadge(refCode, data.total);
            }
            input.disabled = false;
            input.focus();
        })
        .catch(() => { input.disabled = false; });
}

function deleteNote(refCode, index) {
    const formData = new FormData();
    formData.append('action', 'delete_note');
    formData.append('ref', refCode);
    formData.append('index', index);
    
    fetch(getApiUrl(), { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadNotes(refCode);
                updateNoteBadge(refCode, data.total);
            }
        });
}

function updateNoteBadge(refCode, count) {
    // Find the note button for this ref
    document.querySelectorAll('.prob-note-btn').forEach(btn => {
        if (btn.getAttribute('onclick')?.includes(refCode)) {
            const badge = btn.querySelector('.note-badge');
            if (count > 0) {
                if (badge) {
                    badge.textContent = count;
                } else {
                    const span = document.createElement('span');
                    span.className = 'note-badge';
                    span.textContent = count;
                    btn.appendChild(span);
                }
                btn.classList.add('has-notes');
            } else {
                if (badge) badge.remove();
                btn.classList.remove('has-notes');
            }
        }
    });
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
