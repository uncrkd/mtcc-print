/**
 * Admin Actions Menu (Feature #3)
 * Context-aware actions menu - appends to document.body for proper z-index
 */

let activeActionsDropdown = null;
let activeActionsTrigger = null;

// ===== MENU TOGGLE - Creates dropdown and appends to body =====
function toggleActionsMenu(event) {
    event.stopPropagation();
    
    const trigger = event.currentTarget;
    
    // If clicking same trigger that's already open, close it
    if (activeActionsDropdown && activeActionsTrigger === trigger) {
        closeActionsMenu();
        return;
    }
    
    // Close any existing dropdown first
    closeActionsMenu();
    
    // Close any open status dropdowns
    if (typeof closeQuickStatusDropdown === 'function') {
        closeQuickStatusDropdown();
    }
    
    // Create dropdown and append to body
    const dropdown = createActionsDropdown();
    document.body.appendChild(dropdown);
    
    // Position dropdown relative to trigger using fixed positioning
    const triggerRect = trigger.getBoundingClientRect();
    const dropdownWidth = 240;
    
    // Calculate position - align to right edge of trigger
    let top = triggerRect.bottom + 4;
    let left = triggerRect.right - dropdownWidth;
    
    // Adjust if too close to left edge
    if (left < 10) {
        left = 10;
    }
    
    dropdown.style.position = 'fixed';
    dropdown.style.top = `${top}px`;
    dropdown.style.left = `${left}px`;
    dropdown.style.zIndex = '2147483647'; // Maximum z-index
    
    // Show with animation
    requestAnimationFrame(() => {
        dropdown.classList.add('show');
    });
    
    activeActionsDropdown = dropdown;
    activeActionsTrigger = trigger;
    trigger.classList.add('active');
    
    // Add click outside listener
    setTimeout(() => {
        document.addEventListener('click', handleActionsClickOutside);
    }, 10);
}

function closeActionsMenu() {
    if (activeActionsDropdown) {
        activeActionsDropdown.remove();
        activeActionsDropdown = null;
    }
    if (activeActionsTrigger) {
        activeActionsTrigger.classList.remove('active');
        activeActionsTrigger = null;
    }
    document.removeEventListener('click', handleActionsClickOutside);
}

function handleActionsClickOutside(event) {
    if (activeActionsDropdown && !activeActionsDropdown.contains(event.target) && 
        activeActionsTrigger && !activeActionsTrigger.contains(event.target)) {
        closeActionsMenu();
    }
}

// ===== CREATE DROPDOWN ELEMENT =====
function createActionsDropdown() {
    const selectedCount = getSelectedOrderCount();
    const hasSelection = selectedCount > 0;
    
    const dropdown = document.createElement('div');
    dropdown.className = 'actions-menu-dropdown';
    
    let html = '';
    
    // Selection-specific actions
    if (hasSelection) {
        html += `
        <div class="actions-menu-section">
            <div class="actions-menu-item" onclick="toggleStatusSubmenuInline(event)">
                <span class="menu-icon">&#128681;</span>
                <span class="menu-label">Change Status (${selectedCount})</span>
                <span class="submenu-arrow" id="statusArrowInline">&#9655;</span>
            </div>
            <div id="statusSubmenuInline" class="status-submenu-inline">
                <div class="submenu-item" onclick="bulkChangeStatus('unpaid', event)"><span class="status-badge status-unpaid">&#9203; Unpaid</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('paid', event)"><span class="status-badge status-paid">&#128176; Paid</span></div>
                <div class="submenu-divider"></div>
                <div class="submenu-item" onclick="bulkChangeStatus('preflight', event)"><span class="status-badge status-preflight">&#128640; Sent to Vendor</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('file_issue', event)"><span class="status-badge status-file_issue">&#128065; File Issue</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('printing', event)"><span class="status-badge status-printing">&#128424;&#65039; Printing</span></div>
                <div class="submenu-divider"></div>
                <div class="submenu-item" onclick="bulkChangeStatus('ready', event)"><span class="status-badge status-ready">&#9989; Ready to Ship</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('shipped', event)"><span class="status-badge status-shipped">&#128666; Shipped</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('delivered', event)"><span class="status-badge status-delivered"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:-0.125em;"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> Delivered</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('pickedup', event)"><span class="status-badge status-pickedup">&#9989; Picked Up</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('unclaimed', event)"><span class="status-badge status-unclaimed">&#128236; Unclaimed</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('missing', event)"><span class="status-badge status-missing">&#9888; Missing</span></div>
                <div class="submenu-item" onclick="bulkChangeStatus('cancelled', event)"><span class="status-badge status-cancelled">&#10006; Cancelled</span></div>
            </div>
            <div class="actions-menu-item" onclick="printSelectedOrders()">
                <span class="menu-icon">&#128424;&#65039;</span>
                <span class="menu-label">Print Orders (${selectedCount})</span>
            </div>
            <div class="actions-menu-item" onclick="printSelectedLabels()">
                <span class="menu-icon">&#127991;&#65039;</span>
                <span class="menu-label">Print Labels (${selectedCount})</span>
            </div>
            <div class="actions-menu-item" onclick="exportSelectedOrders()">
                <span class="menu-icon">&#8599;&#65039;]</span>
                <span class="menu-label">Export Selected (${selectedCount})</span>
            </div>
            <div class="actions-menu-item" onclick="downloadSelectedFiles()">
                <span class="menu-icon">&#128229;</span>
                <span class="menu-label">Download Files (${selectedCount})</span>
            </div>
            <div class="actions-menu-divider"></div>
            <div class="actions-menu-item action-danger" onclick="bulkDeleteOrders()">
                <span class="menu-icon">&#128465;</span>
                <span class="menu-label">Delete Orders (${selectedCount})</span>
            </div>
            <div class="actions-menu-divider"></div>
        </div>`;
    }
    
    // Always-visible actions
    html += `
    <div class="actions-menu-section">
        <div class="actions-menu-item" onclick="exportAllOrders()">
            <span class="menu-icon">&#8599;&#65039;</span>
            <span class="menu-label">Export All (CSV)</span>
        </div>
        <div class="actions-menu-item" onclick="exportFilteredOrders()">
            <span class="menu-icon">&#128203;</span>
            <span class="menu-label">Export Filtered (CSV)</span>
        </div>
        <div class="actions-menu-item" onclick="exportAsPDF()">
            <span class="menu-icon">&#128196;</span>
            <span class="menu-label">Export as PDF</span>
        </div>
        <div class="actions-menu-item" onclick="printAllOrders()">
            <span class="menu-icon">&#128424;&#65039;</span>
            <span class="menu-label">Print All Orders</span>
        </div>
        <div class="actions-menu-item" onclick="printAllLabels()">
            <span class="menu-icon">&#127991;&#65039;</span>
            <span class="menu-label">Print All Labels</span>
        </div>
        <div class="actions-menu-divider"></div>
        <a href="admin-bulk-upload.php" class="actions-menu-item" style="text-decoration:none;color:inherit;">
            <span class="menu-icon">&#128229;</span>
            <span class="menu-label">Bulk Upload Orders</span>
        </a>
    </div>`;
    
    // Clear selection (only if items selected)
    if (hasSelection) {
        html += `
        <div class="actions-menu-section">
            <div class="actions-menu-divider"></div>
            <div class="actions-menu-item" onclick="clearAllSelections(); closeActionsMenu();">
                <span class="menu-icon">&#10006;</span>
                <span class="menu-label">Clear Selection</span>
            </div>
        </div>`;
    }
    
    dropdown.innerHTML = html;
    return dropdown;
}

// ===== STATUS SUBMENU TOGGLE =====
function toggleStatusSubmenuInline(event) {
    event.stopPropagation();
    const submenu = document.getElementById('statusSubmenuInline');
    const arrow = document.getElementById('statusArrowInline');
    if (submenu) {
        submenu.classList.toggle('show');
        if (arrow) {
            arrow.textContent = submenu.classList.contains('show') ? '&#9655;' : '&#9655;';
        }
    }
}

// ===== HELPER FUNCTIONS =====
function getSelectedOrderCount() {
    if (typeof selectedOrders !== 'undefined') {
        return selectedOrders.size;
    }
    const checked = document.querySelectorAll('.order-checkbox:checked');
    return checked.length;
}

function getSelectedOrderReferences() {
    if (typeof selectedOrders !== 'undefined') {
        return Array.from(selectedOrders);
    }
    const checked = document.querySelectorAll('.order-checkbox:checked');
    return Array.from(checked).map(cb => cb.dataset.reference);
}

// ===== BULK STATUS CHANGE =====
async function bulkChangeStatus(newStatus, event) {
    if (event) event.stopPropagation();
    
    const references = getSelectedOrderReferences();
    if (references.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    
    const statusLabels = {
        'unpaid': 'Unpaid', 'paid': 'Paid', 'preflight': 'Sent to Vendor', 'file_issue': 'File Issue', 'printing': 'Printing', 'ready': 'Ready to Ship', 'dispatched': 'Courier Assigned', 'shipped': 'Shipped', 'delivered': 'Delivered',
        'pickedup': 'Picked Up', 'unclaimed': 'Unclaimed', 'missing': 'Missing', 'cancelled': 'Cancelled', 'refunded': 'Refunded'
    };
    
    if (!confirm(`Change status of ${references.length} order(s) to "${statusLabels[newStatus]}"?`)) {
        return;
    }
    
    closeActionsMenu();
    showNotification(`Updating ${references.length} orders...`, 'info');
    
    let successCount = 0, failCount = 0;
    
    for (const reference of references) {
        try {
            const formData = new FormData();
            formData.append('reference', reference);
            formData.append('status', newStatus);
            
            const response = await fetch('admin-order-handlers.php?action=updateStatus', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                successCount++;
                if (typeof updateStatusBadgeInDOM === 'function') {
                    updateStatusBadgeInDOM(reference, newStatus);
                }
            } else {
                failCount++;
            }
        } catch (error) {
            failCount++;
        }
    }
    
    if (failCount === 0) {
        showNotification(`Successfully updated ${successCount} order(s)`, 'success');
    } else {
        showNotification(`Updated ${successCount}, failed ${failCount}`, 'warning');
    }
    
    if (typeof clearAllSelections === 'function') clearAllSelections();
}

// ===== BULK DELETE =====
async function bulkDeleteOrders() {
    const references = getSelectedOrderReferences();
    if (references.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    
    if (!confirm(`&#9888; PERMANENTLY DELETE ${references.length} order(s)?\n\nThis action cannot be undone!`)) {
        return;
    }
    
    // Double confirmation for safety
    if (!confirm(`Are you absolutely sure? This will delete:\n\n${references.join(', ')}\n\nClick OK to permanently delete.`)) {
        return;
    }
    
    closeActionsMenu();
    showNotification(`Deleting ${references.length} orders...`, 'info');
    
    let successCount = 0, failCount = 0;
    
    for (const reference of references) {
        try {
            const formData = new FormData();
            formData.append('delete_order', '1');
            formData.append('reference_code', reference);
            
            const response = await fetch('admin-orders.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                successCount++;
                // Remove row from DOM
                const row = document.querySelector(`tr[data-reference="${reference.toLowerCase()}"]`);
                if (row) {
                    row.style.transition = 'all 0.3s ease';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 300);
                }
            } else {
                failCount++;
            }
        } catch (error) {
            failCount++;
        }
    }
    
    if (failCount === 0) {
        showNotification(`Successfully deleted ${successCount} order(s)`, 'success');
    } else {
        showNotification(`Deleted ${successCount}, failed ${failCount}`, 'warning');
    }
    
    if (typeof clearAllSelections === 'function') clearAllSelections();
}

// ===== BULK DOWNLOAD FILES =====
function downloadSelectedFiles() {
    const references = getSelectedOrderReferences();
    if (references.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    
    closeActionsMenu();
    
    // Open bulk download URL
    const downloadUrl = `admin-orders.php?bulk_download=${encodeURIComponent(references.join(','))}`;
    window.location.href = downloadUrl;
    
    showNotification(`Preparing download for ${references.length} order(s)...`, 'info');
}

// ===== EXPORT FUNCTIONS =====
function exportSelectedOrders() {
    const references = getSelectedOrderReferences();
    if (references.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    closeActionsMenu();
    exportOrdersToCSV(references);
}

function exportAllOrders() {
    closeActionsMenu();
    const visibleRows = document.querySelectorAll('#ordersTableBody tr:not(.filtered-out):not([style*="display: none"])');
    const references = Array.from(visibleRows).map(row => row.dataset.reference).filter(Boolean);
    if (references.length === 0) {
        showNotification('No orders to export', 'warning');
        return;
    }
    exportOrdersToCSV(references, true);
}

function exportOrdersToCSV(references, isAll = false) {
    const orders = [];
    const headers = ['Reference', 'Status', 'Priority', 'Customer Name', 'Email', 'Due Date', 'Width', 'Height', 'Material', 'Total', 'Submitted'];
    
    references.forEach(ref => {
        const row = document.querySelector(`#ordersTableBody tr[data-reference="${ref.toLowerCase()}"]`);
        if (row) {
            const cells = row.querySelectorAll('td');
            const status = row.dataset.status || '';
            const priority = row.dataset.priority || '';
            const customerName = cells[3]?.querySelector('.cell-main')?.textContent?.trim() || '';
            const email = cells[3]?.querySelector('.cell-micro')?.textContent?.trim() || '';
            const dueDate = row.dataset.duedate || '';
            const value = row.dataset.value || '';
            
            const sizeCell = cells[5];
            const sizeMain = sizeCell?.querySelector('.cell-main')?.textContent?.trim() || '';
            const sizeParts = sizeMain.match(/(\d+(?:\.\d+)?)"?\s*[&#10006;]\s*(\d+(?:\.\d+)?)"?/);
            const width = sizeParts ? sizeParts[1] : '';
            const height = sizeParts ? sizeParts[2] : '';
            const material = sizeCell?.querySelector('.cell-micro')?.textContent?.trim() || '';
            
            const dueDateCell = cells[4];
            const submittedText = dueDateCell?.querySelector('.cell-micro')?.textContent?.trim() || '';
            const submitted = submittedText.replace('Submitted: ', '');
            
            orders.push({
                reference: ref.toUpperCase(),
                status: formatStatusForExport(status),
                priority: formatPriorityForExport(priority),
                customerName, email, dueDate, width, height, material,
                total: '$' + parseFloat(value || 0).toFixed(2),
                submitted
            });
        }
    });
    
    let csvContent = headers.join(',') + '\n';
    orders.forEach(order => {
        csvContent += [
            escapeCSV(order.reference), escapeCSV(order.status), escapeCSV(order.priority),
            escapeCSV(order.customerName), escapeCSV(order.email), escapeCSV(order.dueDate),
            escapeCSV(order.width), escapeCSV(order.height), escapeCSV(order.material),
            escapeCSV(order.total), escapeCSV(order.submitted)
        ].join(',') + '\n';
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = isAll ? `orders-export-all-${new Date().toISOString().slice(0,10)}.csv` : `orders-export-${references.length}-${new Date().toISOString().slice(0,10)}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
    
    showNotification(`Exported ${orders.length} orders to CSV`, 'success');
}

function escapeCSV(value) {
    if (value === null || value === undefined) return '';
    const str = String(value);
    if (str.includes(',') || str.includes('"') || str.includes('\n')) {
        return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
}

function formatStatusForExport(status) {
    const labels = { 'unpaid': 'Unpaid', 'paid': 'Paid', 'preflight': 'Sent to Vendor', 'file_issue': 'File Issue', 'printing': 'Printing', 'ready': 'Ready to Ship', 'dispatched': 'Courier Assigned', 'shipped': 'Shipped', 'delivered': 'Delivered', 'pickedup': 'Picked Up', 'unclaimed': 'Unclaimed', 'missing': 'Missing', 'cancelled': 'Cancelled', 'refunded': 'Refunded' };
    return labels[status] || status;
}

function formatPriorityForExport(priority) {
    const labels = { 'early': 'Early Bird', 'standard': 'Standard', '3day': '3-Day Rush', '2day': '2-Day Rush', 'nextday': 'Next Day', 'sameday': 'Same Day' };
    return labels[priority] || priority;
}

// Export only currently filtered/visible orders
function exportFilteredOrders() {
    closeActionsMenu();
    var visibleRows = document.querySelectorAll('#ordersTableBody tr');
    var references = [];
    visibleRows.forEach(function(row) {
        // Check if row is actually visible (not filtered out, not hidden by pagination)
        if (row.offsetParent !== null && !row.classList.contains('filtered-out')) {
            var ref = row.dataset.reference;
            if (ref) references.push(ref);
        }
    });
    if (references.length === 0) {
        showNotification('No visible orders to export', 'warning');
        return;
    }
    exportOrdersToCSV(references, false);
    showNotification('Exported ' + references.length + ' filtered orders', 'success');
}

// Export current table view as PDF via print dialog
function exportAsPDF() {
    closeActionsMenu();

    // Add a class to body for print-specific styling
    document.body.classList.add('pdf-export-mode');

    // Temporarily show all rows (remove pagination hiding)
    var hiddenRows = document.querySelectorAll('#ordersTableBody tr[style*="display: none"]');
    hiddenRows.forEach(function(row) { row.dataset.wasHidden = 'true'; row.style.display = ''; });

    // Trigger print (user can choose "Save as PDF" in the dialog)
    setTimeout(function() {
        window.print();

        // Restore hidden rows after print dialog closes
        setTimeout(function() {
            document.body.classList.remove('pdf-export-mode');
            hiddenRows.forEach(function(row) {
                if (row.dataset.wasHidden) {
                    row.style.display = 'none';
                    delete row.dataset.wasHidden;
                }
            });
        }, 1000);
    }, 100);
}

// ===== PRINT FUNCTIONS - Use existing pages exactly =====
function printSelectedOrders() {
    const references = getSelectedOrderReferences();
    if (references.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    closeActionsMenu();
    
    // Open bulk print page that uses the EXACT existing order view
    const printUrl = `admin-orders.php?bulk_print=${encodeURIComponent(references.join(','))}`;
    window.open(printUrl, 'bulk_print', 'width=1000,height=800');
}

function printAllOrders() {
    closeActionsMenu();
    const visibleRows = document.querySelectorAll('#ordersTableBody tr:not(.filtered-out):not([style*="display: none"])');
    const references = Array.from(visibleRows).map(row => row.dataset.reference).filter(Boolean);
    if (references.length === 0) {
        showNotification('No orders to print', 'warning');
        return;
    }
    if (references.length > 50 && !confirm(`Print ${references.length} orders? This may take a while.`)) {
        return;
    }
    const printUrl = `admin-orders.php?bulk_print=${encodeURIComponent(references.join(','))}`;
    window.open(printUrl, 'bulk_print', 'width=1000,height=800');
}

function printSelectedLabels() {
    const references = getSelectedOrderReferences();
    if (references.length === 0) {
        showNotification('No orders selected', 'warning');
        return;
    }
    closeActionsMenu();
    
    // Open bulk labels page that uses the EXACT existing label layout
    const labelUrl = `admin-orders.php?bulk_labels=${encodeURIComponent(references.join(','))}`;
    window.open(labelUrl, 'bulk_labels', 'width=500,height=700');
}

function printAllLabels() {
    closeActionsMenu();
    const visibleRows = document.querySelectorAll('#ordersTableBody tr:not(.filtered-out):not([style*="display: none"])');
    const references = Array.from(visibleRows).map(row => row.dataset.reference).filter(Boolean);
    if (references.length === 0) {
        showNotification('No orders to print labels for', 'warning');
        return;
    }
    if (references.length > 50 && !confirm(`Print ${references.length} labels? This may take a while.`)) {
        return;
    }
    const labelUrl = `admin-orders.php?bulk_labels=${encodeURIComponent(references.join(','))}`;
    window.open(labelUrl, 'bulk_labels', 'width=500,height=700');
}

// ===== NOTIFICATION HELPER =====
function showNotification(message, type = 'info') {
    // Remove existing notification
    const existing = document.querySelector('.actions-notification');
    if (existing) existing.remove();
    
    const icons = { 'info': '&#8505;', 'success': '&#9989;', 'warning': '&#9888;', 'error': '&#10060;' };
    const colors = {
        'info': { bg: '#dbeafe', color: '#1e40af', border: '#93c5fd' },
        'success': { bg: '#d1fae5', color: '#065f46', border: '#6ee7b7' },
        'warning': { bg: '#fef3c7', color: '#92400e', border: '#fcd34d' },
        'error': { bg: '#fee2e2', color: '#991b1b', border: '#fca5a5' }
    };
    
    const c = colors[type] || colors.info;
    const notification = document.createElement('div');
    notification.className = 'actions-notification';
    notification.style.cssText = `position:fixed;top:20px;right:20px;padding:12px 20px;border-radius:8px;display:flex;align-items:center;gap:10px;font-size:14px;font-weight:500;z-index:2147483647;animation:slideInRight 0.3s ease;box-shadow:0 4px 12px rgba(0,0,0,0.15);background:${c.bg};color:${c.color};border:1px solid ${c.border};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;`;
    notification.innerHTML = `<span>${icons[type]}</span><span>${message}</span>`;
    
    // Add animation keyframes if not present
    if (!document.getElementById('notification-keyframes')) {
        const style = document.createElement('style');
        style.id = 'notification-keyframes';
        style.textContent = `@keyframes slideInRight{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}`;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.style.animation = 'slideInRight 0.3s ease reverse';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// ===== CLOSE ON ESCAPE =====
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeActionsMenu();
    }
});
