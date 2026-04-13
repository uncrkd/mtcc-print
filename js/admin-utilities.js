/**
 * Admin Utilities Module
 * Core helper functions and shared utilities for the order management system
 *
 * NOTE: formatFileSize, escapeHtml, showNotification, hideNotification
 * are now in js/shared/utils.js (loaded before this file)
 */

// ===== DATE AND BUSINESS DAY CALCULATIONS =====
const holidays2025 = [
    '2025-01-01', '2025-04-18', '2025-04-21', '2025-05-19', 
    '2025-07-01', '2025-08-04', '2025-09-01', '2025-10-13', 
    '2025-11-11', '2025-12-25', '2025-12-26'
];

function isBusinessDay(date) {
    const day = date.getDay();
    const dateStr = date.toISOString().split('T')[0];
    return day !== 0 && day !== 6 && !holidays2025.includes(dateStr);
}

function calculateBusinessDays(startDate, endDate) {
    if (startDate >= endDate) return 0;
    
    let businessDays = 0;
    let currentDate = new Date(startDate);
    
    while (currentDate < endDate) {
        if (isBusinessDay(currentDate)) {
            businessDays++;
        }
        currentDate.setDate(currentDate.getDate() + 1);
    }
    
    return businessDays;
}

function formatDateDisplay(dateString) {
    if (!dateString) return 'Select Date';
    const date = new Date(dateString + 'T00:00:00'); // Avoid timezone issues
    return date.toLocaleDateString('en-US', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });
}

function calculatePriorityTier(dueDateString) {
    if (!dueDateString) return 'Standard';
    
    const now = new Date();
    const dueDate = new Date(dueDateString + 'T00:00:00');
    
    // Set due date to 4:00 PM
    dueDate.setHours(16, 0, 0, 0);
    
    const businessDaysRemaining = calculateBusinessDays(now, dueDate);
    
    // Determine tier based on business days remaining
    if (businessDaysRemaining >= 10) {
        return 'Early (10+ Days)';
    } else if (businessDaysRemaining >= 5) {
        return 'Standard (5 Days)';
    } else if (businessDaysRemaining >= 3) {
        return 'Rush (3 Days)';
    } else if (businessDaysRemaining >= 2) {
        return 'Urgent (2 Days)';
    } else if (businessDaysRemaining >= 1) {
        return 'Critical (Next Day)';
    } else {
        return 'LAST MINUTE (Same Day)';
    }
}

// ===== PRICING CALCULATIONS =====
function updatePricing() {
    const basePrice = parseFloat(document.getElementById('base_price').value) || 0;
    const deliveryFee = parseFloat(document.getElementById('delivery_fee').value) || 0;
    
    // Calculate tax (13% of base price + delivery fee)
    const taxableAmount = basePrice + deliveryFee;
    const tax = taxableAmount * 0.13;
    document.getElementById('tax').value = tax.toFixed(2);
    
    // Calculate and update total
    const total = basePrice + deliveryFee + tax;
    document.getElementById('total').value = total.toFixed(2);
}

function updateTotal() {
    const basePrice = parseFloat(document.getElementById('base_price').value) || 0;
    const deliveryFee = parseFloat(document.getElementById('delivery_fee').value) || 0;
    const tax = parseFloat(document.getElementById('tax').value) || 0;
    const total = basePrice + deliveryFee + tax;
    
    document.getElementById('total').value = total.toFixed(2);
}

// ===== SHARED STATUS MANAGEMENT =====
/**
 * Updates order status - used by both sandwich menu and order detail page
 * @param {string} referenceCode - Order reference code
 * @param {string} newStatus - New status to set
 * @param {Function} successCallback - Optional callback on success
 * @param {Function} errorCallback - Optional callback on error
 */
function updateOrderStatus(referenceCode, newStatus, successCallback = null, errorCallback = null) {
    const formData = new FormData();
    formData.append('update_status', '1');
    formData.append('reference_code', referenceCode);
    formData.append('status', newStatus);
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Default success handling
            updateStatusBadgeInDOM(referenceCode, newStatus);
            showStatusUpdateMessage(referenceCode, newStatus);
            
            // Update the order data in analyticsManager and recalculate
            if (window.analyticsManager && window.analyticsManager.data) {
                // Update the order's status in the cached data
                const orders = window.analyticsManager.data.orders || [];
                const order = orders.find(o => o.referenceCode === referenceCode);
                if (order) {
                    order.status = newStatus;
                }
                
                // Recalculate analytics based on current mode
                const activeBtn = document.querySelector('.events-toggle .toggle-btn.active');
                const mode = activeBtn?.dataset?.mode || 'active';
                window.analyticsManager.recalculateForEventsMode(mode);
            }
            
            // Call custom success callback if provided
            if (successCallback) {
                successCallback(data);
            }
            
        } else {
            console.error('Status update failed:', data.error);
            const errorMsg = 'Failed to update status: ' + (data.error || 'Unknown error');
            
            if (errorCallback) {
                errorCallback(errorMsg);
            } else {
                alert(errorMsg);
            }
        }
    })
    .catch(error => {
        console.error('Error updating status:', error);
        const errorMsg = 'Failed to update status. Please check your connection.';
        
        if (errorCallback) {
            errorCallback(errorMsg);
        } else {
            alert(errorMsg);
        }
    });
}

/**
 * Updates status badge in the DOM (works for both table and detail view)
 */
function updateStatusBadgeInDOM(referenceCode, newStatus) {
        const statusIcons = {
        'submitted': '&#128203;',
        'checking': '&#128065;',
        'unpaid': '&#9203;',
        'paid': '&#128176;',
        'file_issue': '&#128065;',
        'printing': '&#128424;',
        'shipped': '&#128666;',
        'delivered': '<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:inline;vertical-align:-0.125em;"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'pickedup': '&#9989;',
        'unclaimed': '&#128236;',
        'missing': '&#9888;',
        'cancelled': '&#10006;',
        'refunded': '&#128683;'
    };

    const statusLabels = {
        'submitted': 'Submitted',
        'checking': 'File Checking',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'preflight': 'Sent to Vendor',
        'file_issue': 'File Issue',
        'printing': 'Printing',
        'ready': 'Ready to Ship',
        'dispatched': 'Courier Assigned',
        'shipped': 'Shipped',
        'delivered': 'Delivered',
        'pickedup': 'Picked Up',
        'unclaimed': 'Unclaimed',
        'missing': 'Missing',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };

    // Update in table view
    const tableRows = document.querySelectorAll('#ordersTableBody tr');
    tableRows.forEach(row => {
        if (row.dataset.reference === referenceCode.toLowerCase()) {
            row.className = newStatus;
            row.dataset.status = newStatus;
            
            const statusBadge = row.querySelector('.status-badge');
            if (statusBadge) {
                // Preserve clickable class if present
                const isClickable = statusBadge.classList.contains('status-badge-clickable');
                statusBadge.className = `status-badge status-${newStatus}`;
                if (isClickable) {
                    statusBadge.classList.add('status-badge-clickable');
                    // Update the data attribute and onclick handler
                    statusBadge.dataset.currentStatus = newStatus;
                    statusBadge.onclick = function(event) {
                        toggleQuickStatusDropdown(event, referenceCode, newStatus);
                    };
                }
                statusBadge.innerHTML = `${statusIcons[newStatus] || '&#128203;'} ${statusLabels[newStatus] || newStatus}`;
            }
        }
    });
    
    // Update in detail view
    const detailStatusBadge = document.querySelector('.status-badge-large');
    if (detailStatusBadge) {
        detailStatusBadge.className = `status-badge-large status-${newStatus}`;
        detailStatusBadge.innerHTML = `${statusIcons[newStatus] || '&#128203;'} ${statusLabels[newStatus] || newStatus}`;
    }
    
    // Update header border color in detail view
    const header = document.querySelector('.header');
    if (header) {
        header.className = header.className.replace(/\bstatus-\w+/g, '');
        header.classList.add(`status-${newStatus}`);
    }
}

/**
 * Shows status update feedback message
 */
function showStatusUpdateMessage(referenceCode, newStatus) {
    const statusLabels = {
        'submitted': 'Submitted',
        'checking': 'File Checking',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'preflight': 'Sent to Vendor',
        'file_issue': 'File Issue',
        'printing': 'Printing',
        'ready': 'Ready to Ship',
        'dispatched': 'Courier Assigned',
        'shipped': 'Shipped',
        'delivered': 'Delivered',
        'pickedup': 'Picked Up',
        'unclaimed': 'Unclaimed',
        'missing': 'Missing',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };
    
    const tempMessage = document.createElement('div');
    tempMessage.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #059669;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: 600;
        z-index: 100000;
        font-size: 0.9rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        animation: slideIn 0.3s ease;
    `;
    tempMessage.textContent = `Order ${referenceCode} updated to ${statusLabels[newStatus]}`;
    
    document.body.appendChild(tempMessage);
    
    setTimeout(() => {
        tempMessage.style.transition = 'all 0.3s ease';
        tempMessage.style.opacity = '0';
        tempMessage.style.transform = 'translateX(100%)';
        
        setTimeout(() => {
            if (tempMessage.parentNode) {
                tempMessage.remove();
            }
        }, 300);
    }, 3000);
}

// ===== PRINT FUNCTIONALITY - FIXED VERSION =====
/**
 * Enhanced print preparation function - hides interactive elements for clean printing
 * IMPORTANT: Only call this when actually printing, not on page load
 */
function preparePrintView() {
    
    const elementsToHide = [
        '.header-actions',
        '.action-buttons-container', 
        '.add-note-btn',
        '.add-note-form',
        '.note-actions',
        '.edit-note-form',
        '.btn',
        'button',
        'input[type="submit"]',
        'input[type="button"]',
        '.action-btn-table',
        '.action-btn-large',
        '.btn-small',
        '.note-action-btn',
        '.notes-timeline',
        '.note-item', 
        '#notesTimeline',
        '#addNoteForm',
        '#addNoteBtn',
        '.no-notes'
    ];
    
    elementsToHide.forEach(selector => {
        const elements = document.querySelectorAll(selector);
        elements.forEach(element => {
            element.style.display = 'none';
            element.style.visibility = 'hidden';
        });
    });
    
    // Hide entire notes card
    const sectionHeaders = document.querySelectorAll('.section-header');
    sectionHeaders.forEach(header => {
        const headerText = header.textContent.toLowerCase();
        if (headerText.includes('internal') && (headerText.includes('notes') || headerText.includes('communication'))) {
            const card = header.closest('.card');
            if (card) {
                card.style.display = 'none';
                card.style.visibility = 'hidden';
                card.style.height = '0';
                card.style.overflow = 'hidden';
            }
        }
    });
}

/**
 * Print order details with proper preparation - FIXED VERSION
 */
function printOrderDetails() {
    
    // REMOVED: preparePrintView() call here to prevent auto-hiding elements
    
    // Use setTimeout to ensure proper print handling
    setTimeout(() => {
        window.print();
        
        // REMOVED: location.reload() to prevent unwanted page refreshes
    }, 100);
}

/**
 * Print shipping label in new window
 */
function printShippingLabel() {
    const referenceCode = window.orderReferenceCode || document.querySelector('input[name="reference_code"]')?.value;
    if (!referenceCode) {
        alert('Order reference code not found');
        return;
    }
    
    const labelUrl = 'admin-orders.php?label=' + encodeURIComponent(referenceCode);
    window.open(labelUrl, '_blank', 'width=600,height=800,scrollbars=yes,resizable=yes');
}

// ===== PRIORITY TIER MANAGEMENT =====
function updatePriorityTier() {
    const dueDateInput = document.getElementById('due_date_input');
    const priorityTierHidden = document.getElementById('hidden_priority_tier') || document.getElementById('priority_tier_hidden');
    const priorityTierBadge = document.getElementById('priority_tier_badge');
    
    if (dueDateInput && priorityTierHidden && priorityTierBadge) {
        const selectedDate = dueDateInput.value;
        const tier = calculatePriorityTier(selectedDate);
        priorityTierHidden.value = tier;
        priorityTierBadge.textContent = tier;
        
        updatePriorityTierColor(tier);
    }
}

function updatePriorityTierColor(tier) {
    const priorityBadge = document.getElementById('priority_tier_badge');
    if (!priorityBadge) return;
    
    // Remove existing color classes
    priorityBadge.className = 'priority-tier-badge';
    
    // Determine color class based on tier
    const tierLower = tier.toLowerCase();
    if (tierLower.includes('last minute')) {
        priorityBadge.classList.add('priority-tier-lastminute');
    } else if (tierLower.includes('early')) {
        priorityBadge.classList.add('priority-tier-early');
    } else if (tierLower.includes('rush')) {
        priorityBadge.classList.add('priority-tier-rush');
    } else if (tierLower.includes('urgent')) {
        priorityBadge.classList.add('priority-tier-urgent');
    } else if (tierLower.includes('critical')) {
        priorityBadge.classList.add('priority-tier-critical');
    } else {
        priorityBadge.classList.add('priority-tier-standard');
    }
}

// ===== FORM INPUT SYNCHRONIZATION =====
/**
 * Syncs header inputs with hidden form inputs in edit mode
 */
function syncHeaderInputs() {
    // Sync order number
    const orderNumberInput = document.querySelector('input[name="new_reference_code"]:not([type="hidden"])');
    const hiddenOrderNumber = document.getElementById('hidden_new_reference_code');
    if (orderNumberInput && hiddenOrderNumber) {
        orderNumberInput.addEventListener('input', function() {
            hiddenOrderNumber.value = this.value;
        });
    }
    
    // Sync due date
    const dueDateInput = document.getElementById('due_date_input');
    const hiddenDueDate = document.getElementById('hidden_delivery_date');
    if (dueDateInput && hiddenDueDate) {
        dueDateInput.addEventListener('change', function() {
            hiddenDueDate.value = this.value;
        });
    }
    
    // Sync status
    const statusSelect = document.querySelector('select[name="status"]:not([type="hidden"])');
    const hiddenStatus = document.getElementById('hidden_status');
    if (statusSelect && hiddenStatus) {
        statusSelect.addEventListener('change', function() {
            hiddenStatus.value = this.value;
        });
    }
    
    // Update priority tier when date changes
    if (dueDateInput) {
        dueDateInput.addEventListener('change', function() {
            updatePriorityTier();
        });
    }
}

// ===== DELIVERY OPTION HANDLING =====
function toggleDeliveryFields(deliveryOption) {
    const section = document.getElementById('delivery_address_section');
    const deliveryFeeInput = document.getElementById('delivery_fee');
    
    if (deliveryOption === 'office') {
        section.style.display = 'block';
        if (deliveryFeeInput) {
            deliveryFeeInput.value = '10.00';
            updatePricing();
        }
    } else {
        section.style.display = 'none';
        if (deliveryFeeInput) {
            deliveryFeeInput.value = '0.00';
            updatePricing();
        }
    }
}

// ===== STATUS COLOR MANAGEMENT =====
function updateStatusColor() {
    const statusSelect = document.querySelector('select[name="status"]');
    const header = document.querySelector('.header');
    
    if (!statusSelect || !header) return;
    
    // Remove existing status classes
    statusSelect.className = 'header-input btn-medium';
    
    // Remove existing border status classes
    header.className = header.className.replace(/\bborder-status-\w+/g, '');
    
    // Add status-specific color class
    const selectedStatus = statusSelect.value;
    statusSelect.classList.add('status-' + selectedStatus);
    
    // Add border color class to header
    header.classList.add('border-status-' + selectedStatus);
}

function setInitialStatusBorder() {
    const header = document.querySelector('.header');
    if (!header) return;
    
    const statusClass = Array.from(header.classList).find(cls => cls.startsWith('status-'));
    if (statusClass) {
        const status = statusClass.replace('status-', '');
        header.classList.add('border-status-' + status);
    }
}

// ===== DATE INPUT SETUP =====
function setupDateInput() {
    const dateInput = document.getElementById('due_date_input');
    const dateDisplay = document.getElementById('due_date_display');
    const dateText = document.getElementById('due_date_text');
    
    if (!dateInput || !dateDisplay || !dateText) return;
    
    // Handle date changes
    dateInput.addEventListener('change', function() {
        const selectedDate = this.value;
        dateText.textContent = formatDateDisplay(selectedDate);
        updatePriorityTier();
    });
    
    // Handle clicks on display to open date picker
    dateDisplay.addEventListener('click', function() {
        dateInput.focus();
        if (dateInput.showPicker) {
            dateInput.showPicker();
        }
    });
}

// NOTE: escapeHtml, showNotification, hideNotification moved to js/shared/utils.js

function resendConfirmationEmail(referenceCode) {
    if (!confirm('Send order details to customer?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('resend_email', '1');
    formData.append('reference_code', referenceCode);
    
    // Show loading state
    const button = event.target;
    const originalText = button.textContent;
    button.textContent = 'Sending...';
    button.disabled = true;
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(text => {
        // Extract JSON from the response
        const jsonMatch = text.match(/\{.*\}$/);
        if (jsonMatch) {
            const data = JSON.parse(jsonMatch[0]);
            if (data.success) {
                showNotification(data.message, 'success');
            } else {
                showNotification('Failed to send email: ' + data.error, 'error');
            }
        } else {
            showNotification('Unexpected server response', 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to send email', 'error');
    })
    .finally(() => {
        // Restore button state
        button.textContent = originalText;
        button.disabled = false;
    });
}

// ===== PRINT LOADING MESSAGES =====
function showPrintLoadingMessage(message) {
    hidePrintLoadingMessage();
    
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'printLoadingMessage';
    loadingDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: #7c3aed;
        color: white;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: 600;
        z-index: 1000000;
        font-size: 0.9rem;
        box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
        display: flex;
        align-items: center;
        gap: 10px;
    `;
    
    loadingDiv.innerHTML = `
        <div class="spinner" style="
            width: 16px; 
            height: 16px; 
            border: 2px solid rgba(255,255,255,0.3); 
            border-top: 2px solid white; 
            border-radius: 50%; 
            animation: spin 1s linear infinite;
        "></div>
        ${message}
    `;
    
    document.body.appendChild(loadingDiv);
}

function hidePrintLoadingMessage() {
    const loadingDiv = document.getElementById('printLoadingMessage');
    if (loadingDiv) {
        loadingDiv.style.transition = 'all 0.3s ease';
        loadingDiv.style.opacity = '0';
        loadingDiv.style.transform = 'translateX(100%)';
        
        setTimeout(() => {
            if (loadingDiv.parentNode) {
                loadingDiv.parentNode.removeChild(loadingDiv);
            }
        }, 300);
    }
}

// ===== INITIALIZATION UTILITIES - FIXED VERSION =====
/**
 * Initialize common functionality that's used across multiple modules
 * FIXED: Removed problematic global event listeners
 */
function initializeCommonUtilities() {
    
    // FIXED: Only setup print shortcut, not broad overrides
    document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printOrderDetails();
        }
    });
    
    // REMOVED: Problematic print button override that was too broad
    // REMOVED: beforeprint event handler that was causing issues
    
}

// ===== VALIDATION HELPERS =====
function validateRequired(value, fieldName) {
    if (!value || value.trim() === '') {
        throw new Error(`${fieldName} is required`);
    }
    return value.trim();
}

function validateEmail(email) {
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        throw new Error('Please enter a valid email address');
    }
    return email;
}

function validatePositiveNumber(value, fieldName) {
    const num = parseFloat(value);
    if (isNaN(num) || num < 0) {
        throw new Error(`${fieldName} must be a positive number`);
    }
    return num;
}

// ===== GLOBAL VARIABLE SETUP =====
// These can be set by PHP to pass data to JavaScript modules
window.adminUtilities = {
    orderReferenceCode: null,
    orderDueDate: null,
    currentStatus: null,
    debugMode: false
};

// ===== CSS ANIMATION KEYFRAMES (if needed inline) =====
// Add common animations if not in CSS file
function addUtilityStyles() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
        
        .hidden {
            display: none !important;
        }
    `;
    
    if (!document.querySelector('style[data-utility-styles]')) {
        style.setAttribute('data-utility-styles', 'true');
        document.head.appendChild(style);
    }
}


// ===== QUICK STATUS DROPDOWN =====
// Status configuration for dropdown
// Status configuration for dropdown
const quickStatusConfig = {
    'unpaid': { label: 'Unpaid', icon: '&#9203;' },      // â³
    'paid': { label: 'Paid', icon: '&#128176;' },        // ðŸ’°
    'preflight': { label: 'Sent to Vendor', icon: '&#128640;' },  // &#128640;
    'file_issue': { label: 'File Issue', icon: '&#128269;' }, // &#128269;
    'printing': { label: 'Printing', icon: '&#128424;&#65039;' },  // ðŸ–¨ï¸
    'ready': { label: 'Ready to Ship', icon: '&#9989;' },  // &#9989;
    'shipped': { label: 'Shipped', icon: '&#128666;' },   // ðŸšš
    'delivered': { label: 'Delivered', icon: '<svg width=”1em” height=”1em” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2” style=”display:inline;vertical-align:-0.125em;”><path d=”M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z”/><polyline points=”3.27 6.96 12 12.01 20.73 6.96”/><line x1=”12” y1=”22.08” x2=”12” y2=”12”/></svg>' }, // ðŸ”¦
    'pickedup': { label: 'Picked Up', icon: '&#9989;' },  // âœ…
    'unclaimed': { label: 'Unclaimed', icon: '&#128236;' }, // ðŸ“¬
    'missing': { label: 'Missing', icon: '&#9888;' },     // âš 
    'cancelled': { label: 'Cancelled', icon: '&#10006;' }, // âœ–
    'refunded': { label: 'Refunded', icon: '&#128683;' }  // ðŸš«
};

// Currently open dropdown reference and its trigger element
let activeQuickStatusDropdown = null;
let activeQuickStatusTrigger = null;

/**
 * Toggle the quick status dropdown - moves to document.body for proper z-index
 */
function toggleQuickStatusDropdown(event, referenceCode, currentStatus) {
    event.stopPropagation();
    
    const trigger = event.currentTarget;
    
    // If clicking the same trigger that's already open, close it
    if (activeQuickStatusDropdown && activeQuickStatusTrigger === trigger) {
        closeQuickStatusDropdown();
        return;
    }
    
    // Close any existing dropdown first
    closeQuickStatusDropdown();
    
    // Create dropdown and append to body
    const dropdown = createQuickStatusDropdown(referenceCode, currentStatus);
    document.body.appendChild(dropdown);
    
    // Position dropdown relative to trigger using fixed positioning
    const triggerRect = trigger.getBoundingClientRect();
    const dropdownHeight = 380; // Approximate height
    const dropdownWidth = 180;
    
    // Calculate position
    let top = triggerRect.bottom + 4;
    let left = triggerRect.left + (triggerRect.width / 2) - (dropdownWidth / 2);
    
    // Adjust if too close to right edge
    if (left + dropdownWidth > window.innerWidth - 10) {
        left = window.innerWidth - dropdownWidth - 10;
    }
    // Adjust if too close to left edge
    if (left < 10) {
        left = 10;
    }
    
    // Position above if not enough space below
    if (top + dropdownHeight > window.innerHeight - 10 && triggerRect.top > dropdownHeight) {
        top = triggerRect.top - dropdownHeight - 4;
        dropdown.classList.add('position-above');
    }
    
    dropdown.style.position = 'fixed';
    dropdown.style.top = `${top}px`;
    dropdown.style.left = `${left}px`;
    dropdown.style.zIndex = '2147483647'; // Maximum z-index
    
    // Show with animation
    requestAnimationFrame(() => {
        dropdown.classList.add('show');
    });
    
    activeQuickStatusDropdown = dropdown;
    activeQuickStatusTrigger = trigger;
    
    // Add click outside listener
    setTimeout(() => {
        document.addEventListener('click', handleQuickStatusClickOutside);
    }, 10);
}

/**
 * Create the dropdown element with badge-style status options
 */
function createQuickStatusDropdown(referenceCode, currentStatus) {
    const dropdown = document.createElement('div');
    dropdown.className = 'quick-status-dropdown';
    dropdown.dataset.referenceCode = referenceCode;
    
    // Status order - grouped logically
    // MTCC staff can only set: pickedup, unclaimed, missing
    const perms = window.PERMS || {};
    const labels = window.STATUS_LABELS || {};
    let statusGroups;

    if (perms.isMtccStaff && perms.allowedStatuses) {
        statusGroups = [perms.allowedStatuses];
    } else {
        statusGroups = [
            ['unpaid', 'paid'],
            ['preflight', 'file_issue', 'printing'],
            ['ready', 'shipped', 'delivered', 'pickedup', 'unclaimed', 'missing', 'cancelled']
        ];
    }

    statusGroups.forEach((group, groupIndex) => {
        group.forEach(status => {
            const config = quickStatusConfig[status];
            if (!config) return;
            const item = document.createElement('button');
            item.className = 'quick-status-dropdown-item';
            if (status === currentStatus) {
                item.classList.add('current');
            }
            item.dataset.status = status;
            // Use role-appropriate label from window.STATUS_LABELS if available
            const displayLabel = labels[status] || config.label;
            item.innerHTML = `<span class="status-badge status-${status}">${config.icon} ${displayLabel}</span>`;
            item.addEventListener('click', (e) => {
                e.stopPropagation();
                handleQuickStatusSelect(referenceCode, status, currentStatus);
            });
            dropdown.appendChild(item);
        });

        // Add divider between groups (except after last group)
        if (groupIndex < statusGroups.length - 1) {
            const divider = document.createElement('div');
            divider.className = 'quick-status-dropdown-divider';
            dropdown.appendChild(divider);
        }
    });
    
    return dropdown;
}

/**
 * Handle status selection from dropdown
 */
function handleQuickStatusSelect(referenceCode, newStatus, currentStatus) {
    // Close dropdown immediately
    closeQuickStatusDropdown();
    
    // If same status selected, do nothing
    if (newStatus === currentStatus) {
        return;
    }
    
    // Update status via existing function
    updateOrderStatus(referenceCode, newStatus, 
        // Success callback
        (data) => {
            // Update the badge's data-status attribute
            updateQuickStatusBadgeData(referenceCode, newStatus);
        },
        // Error callback
        (error) => {
            console.error('Quick status update failed:', error);
        }
    );
}

/**
 * Update the quick status badge data attribute after successful change
 */
function updateQuickStatusBadgeData(referenceCode, newStatus) {
    const rows = document.querySelectorAll('#ordersTableBody tr');
    rows.forEach(row => {
        if (row.dataset.reference === referenceCode.toLowerCase()) {
            const badge = row.querySelector('.status-badge-clickable');
            if (badge) {
                badge.dataset.currentStatus = newStatus;
            }
        }
    });
}

/**
 * Close the quick status dropdown
 */
function closeQuickStatusDropdown() {
    if (activeQuickStatusDropdown) {
        activeQuickStatusDropdown.classList.remove('show');
        // Remove from DOM after animation
        const dropdownToRemove = activeQuickStatusDropdown;
        setTimeout(() => {
            if (dropdownToRemove.parentNode) {
                dropdownToRemove.parentNode.removeChild(dropdownToRemove);
            }
        }, 150);
        activeQuickStatusDropdown = null;
        activeQuickStatusTrigger = null;
    }
    document.removeEventListener('click', handleQuickStatusClickOutside);
}

/**
 * Handle clicks outside the dropdown
 */
function handleQuickStatusClickOutside(event) {
    if (activeQuickStatusDropdown && !activeQuickStatusDropdown.contains(event.target) && 
        activeQuickStatusTrigger && !activeQuickStatusTrigger.contains(event.target)) {
        closeQuickStatusDropdown();
    }
}

// Close dropdown on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && activeQuickStatusDropdown) {
        closeQuickStatusDropdown();
    }
});


// ===== AUTO-INITIALIZATION - FIXED VERSION =====
// Initialize utilities when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    addUtilityStyles();
    initializeCommonUtilities();
});