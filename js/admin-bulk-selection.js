/**
 * Bulk Selection Module
 * Handles checkbox selection for orders table with persistence across view switches
 */

// Store selected order reference codes (persists across Active/All Events toggle)
const selectedOrders = new Set();

/**
 * Initialize bulk selection functionality
 */
function initBulkSelection() {
    
    // Add event listener for select-all checkbox
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', handleSelectAll);
    }
    
    // Add event listeners for individual row checkboxes
    attachRowCheckboxListeners();
    
    // Restore selections after table updates (pagination, filtering)
    restoreSelections();
    
    // Update count display
    updateSelectionCount();
    
}

/**
 * Attach listeners to individual row checkboxes
 */
function attachRowCheckboxListeners() {
    const checkboxes = document.querySelectorAll('.order-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            handleRowCheckboxChange(this);
        });
    });
}

/**
 * Handle select-all checkbox change
 * Only selects/deselects rows visible on the current page
 */
function handleSelectAll(event) {
    const isChecked = event.target.checked;
    
    // Only get checkboxes in visible rows (not filtered out and on current page)
    const visibleCheckboxes = document.querySelectorAll('#ordersTableBody tr:not(.filtered-out):not([style*="display: none"]) .order-checkbox');
    
    visibleCheckboxes.forEach(checkbox => {
        checkbox.checked = isChecked;
        const referenceCode = checkbox.dataset.reference;
        const row = checkbox.closest('tr');
        
        if (isChecked) {
            selectedOrders.add(referenceCode);
            row.classList.add('selected');
        } else {
            selectedOrders.delete(referenceCode);
            row.classList.remove('selected');
        }
    });
    
    updateSelectionCount();
}

/**
 * Handle individual row checkbox change
 */
function handleRowCheckboxChange(checkbox) {
    const referenceCode = checkbox.dataset.reference;
    const row = checkbox.closest('tr');
    
    if (checkbox.checked) {
        selectedOrders.add(referenceCode);
        row.classList.add('selected');
    } else {
        selectedOrders.delete(referenceCode);
        row.classList.remove('selected');
    }
    
    // Update select-all checkbox state
    updateSelectAllState();
    
    // Update count display
    updateSelectionCount();
}

/**
 * Update the select-all checkbox based on current selections
 * Only considers visible rows on current page
 */
function updateSelectAllState() {
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    if (!selectAllCheckbox) return;
    
    // Only get checkboxes in visible rows
    const visibleCheckboxes = document.querySelectorAll('#ordersTableBody tr:not(.filtered-out):not([style*="display: none"]) .order-checkbox');
    const checkedVisibleCount = document.querySelectorAll('#ordersTableBody tr:not(.filtered-out):not([style*="display: none"]) .order-checkbox:checked').length;
    
    if (visibleCheckboxes.length === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedVisibleCount === 0) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    } else if (checkedVisibleCount === visibleCheckboxes.length) {
        selectAllCheckbox.checked = true;
        selectAllCheckbox.indeterminate = false;
    } else {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = true;
    }
}

/**
 * Update the selection count badge
 */
function updateSelectionCount() {
    const badge = document.getElementById('selectionCountBadge');
    const countSpan = document.getElementById('selectionCount');
    
    if (!badge || !countSpan) return;
    
    const count = selectedOrders.size;
    
    if (count > 0) {
        countSpan.textContent = count;
        badge.classList.add('visible');
    } else {
        badge.classList.remove('visible');
    }
    
    // Dispatch custom event for other modules (like actions menu)
    document.dispatchEvent(new CustomEvent('selectionChanged', {
        detail: { count: count, selected: Array.from(selectedOrders) }
    }));
}

/**
 * Clear all selections
 */
function clearAllSelections() {
    selectedOrders.clear();
    
    // Uncheck all checkboxes
    document.querySelectorAll('.order-checkbox').forEach(checkbox => {
        checkbox.checked = false;
    });
    
    // Remove selected class from all rows
    document.querySelectorAll('.orders-table tbody tr.selected').forEach(row => {
        row.classList.remove('selected');
    });
    
    // Update select-all checkbox
    const selectAllCheckbox = document.getElementById('selectAllOrders');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = false;
        selectAllCheckbox.indeterminate = false;
    }
    
    // Update count display
    updateSelectionCount();
}

/**
 * Restore selections after table refresh (pagination, filtering, view switch)
 */
function restoreSelections() {
    const checkboxes = document.querySelectorAll('.order-checkbox');
    
    checkboxes.forEach(checkbox => {
        const referenceCode = checkbox.dataset.reference;
        if (selectedOrders.has(referenceCode)) {
            checkbox.checked = true;
            checkbox.closest('tr').classList.add('selected');
        }
    });
    
    updateSelectAllState();
}

/**
 * Get array of selected order reference codes
 */
function getSelectedOrders() {
    return Array.from(selectedOrders);
}

/**
 * Get count of selected orders
 */
function getSelectedCount() {
    return selectedOrders.size;
}

/**
 * Check if any orders are selected
 */
function hasSelections() {
    return selectedOrders.size > 0;
}

/**
 * Re-initialize after table content changes (called by filters, pagination, etc.)
 */
function refreshBulkSelection() {
    attachRowCheckboxListeners();
    restoreSelections();
    updateSelectionCount();
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure table is rendered
    setTimeout(initBulkSelection, 100);
});

// Export functions for use by other modules
window.bulkSelection = {
    getSelectedOrders,
    getSelectedCount,
    hasSelections,
    clearAllSelections,
    refreshBulkSelection
};
