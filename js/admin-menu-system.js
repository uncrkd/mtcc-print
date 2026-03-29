/**
 * Admin Menu System Module - Module 2
 * Complete sandwich menu system for order management
 */

// ===== GLOBAL MENU STATE =====
let activeMenu = null;
let activeReferenceCode = null;
let menuDebugMode = false;

// ===== CORE MENU FUNCTIONS =====
function toggleActionMenu(event, referenceCode) {
    event.preventDefault();
    event.stopPropagation();
    
    const trigger = event.target.closest('.action-menu-trigger');
    const menu = document.getElementById(`menu_${referenceCode}`);
    
    if (!menu) {
        console.error('Menu not found for reference code:', referenceCode);
        return;
    }
    
    // If clicking the same menu that's already open, close it
    if (activeMenu === menu && activeReferenceCode === referenceCode) {
        closeAllMenus();
        return;
    }
    
    // Close other menus and open this one
    closeAllMenus();
    openMenu(trigger, menu, referenceCode);
}

function openMenu(trigger, menu, referenceCode) {
    activeMenu = menu;
    activeReferenceCode = referenceCode;
    
    // SOLUTION 1: Move dropdown to document.body to escape table stacking context
    if (menu.parentNode !== document.body) {
        document.body.appendChild(menu);
    }
    
    // Position and show menu with maximum z-index
    menu.style.position = 'fixed';
    menu.style.zIndex = '2147483647'; // Maximum possible z-index
    menu.style.display = 'block';
    
    // Get positions relative to trigger button
    const triggerRect = trigger.getBoundingClientRect();
    let top = triggerRect.bottom + 5;
    let left = triggerRect.left;
    
    // Adjust if too close to viewport edges
    if (left + 250 > window.innerWidth) {
        left = triggerRect.right - 250;
    }
    if (top + 300 > window.innerHeight) {
        top = triggerRect.top - 300;
    }
    
    menu.style.top = `${top}px`;
    menu.style.left = `${left}px`;
    menu.classList.add('show');
    trigger.classList.add('active');
}

function closeAllMenus() {
    document.querySelectorAll('.action-menu-dropdown.show').forEach(menu => {
        menu.classList.remove('show');
        setTimeout(() => {
            // Reset all positioning styles
            menu.style.display = '';
            menu.style.position = '';
            menu.style.top = '';
            menu.style.left = '';
            menu.style.zIndex = '';
            
            // SOLUTION 1: Move menu back to its original container if needed
            // (Optional - menus can stay in document.body)
        }, 150);
    });
    
    document.querySelectorAll('.action-menu-trigger.active').forEach(trigger => {
        trigger.classList.remove('active');
    });
    
    activeMenu = null;
    activeReferenceCode = null;
}

// ===== MENU ACTION FUNCTIONS =====
function printOrderFromMenu(referenceCode) {
    closeAllMenus();
    
    const orderUrl = `admin-orders.php?view=${encodeURIComponent(referenceCode)}`;
    
    // Create hidden iframe for seamless printing
    const iframe = document.createElement('iframe');
    iframe.style.position = 'absolute';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = 'none';
    iframe.style.left = '-9999px';
    iframe.name = 'orderPrintFrame';
    document.body.appendChild(iframe);
    
    iframe.onload = function() {
        // Poll for barcode readiness before printing
        let attempts = 0;
        const maxAttempts = 20; // Max 2 seconds
        
        const checkBarcode = setInterval(() => {
            attempts++;
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const barcodeSvg = iframeDoc.querySelector('#barcode svg, #barcode rect, .barcode-svg svg');
                const barcodeText = iframeDoc.querySelector('.barcode-text, #barcodeText');
                
                const isReady = (barcodeSvg && barcodeSvg.children && barcodeSvg.children.length > 0) ||
                               (barcodeText && !barcodeText.textContent.includes('Generating'));
                
                if (isReady || attempts >= maxAttempts) {
                    clearInterval(checkBarcode);
                    setTimeout(() => {
                        iframe.contentWindow.print();
                        // Remove iframe after print dialog
                        setTimeout(() => {
                            if (iframe.parentNode) {
                                document.body.removeChild(iframe);
                            }
                        }, 1000);
                    }, 200);
                }
            } catch (e) {
                clearInterval(checkBarcode);
                setTimeout(() => {
                    try {
                        iframe.contentWindow.print();
                    } catch (err) {
                        console.error('Print failed:', err);
                    }
                    setTimeout(() => {
                        if (iframe.parentNode) {
                            document.body.removeChild(iframe);
                        }
                    }, 1000);
                }, 500);
            }
        }, 100);
    };
    
    iframe.src = orderUrl;
}

function printLabelFromMenu(referenceCode) {
    closeAllMenus();
    
    const labelUrl = `admin-orders.php?label=${encodeURIComponent(referenceCode)}`;
    
    // Create hidden iframe for seamless printing
    const iframe = document.createElement('iframe');
    iframe.style.position = 'absolute';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.style.border = 'none';
    iframe.style.left = '-9999px';
    iframe.name = 'labelPrintFrame';
    document.body.appendChild(iframe);
    
    // The label page has auto-print built in
    iframe.onload = function() {
        setTimeout(() => {
            if (iframe.parentNode) {
                document.body.removeChild(iframe);
            }
        }, 2000);
    };
    
    iframe.src = labelUrl;
}

function deleteOrderFromMenu(referenceCode) {
    closeAllMenus();
    
    if (!confirm(`Are you sure you want to permanently delete order #${referenceCode}?\n\nThis action cannot be undone.`)) {
        return;
    }
    
    // Show loading state
    showMenuLoadingMessage('Deleting order...');
    
    const formData = new FormData();
    formData.append('delete_order', '1');
    formData.append('reference_code', referenceCode);
    
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        return response.json();
    })
    .then(data => {
        hideMenuLoadingMessage();
        
        if (data.success) {
            // Find and remove the table row
            const row = document.querySelector(`tr[data-reference="${referenceCode.toLowerCase()}"]`);
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(-100%)';
                
                setTimeout(() => {
                    row.remove();
                    
                    // Check if no orders left
                    const remainingRows = document.querySelectorAll('#ordersTableBody tr');
                    if (remainingRows.length === 0) {
                        location.reload();
                    }
                }, 300);
            }
            
            // Show success message
            showMenuSuccessMessage(`Order #${referenceCode} deleted successfully`);
            
        } else {
            console.error('Delete failed:', data.error);
            alert('Failed to delete order: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Delete request error:', error);
        hideMenuLoadingMessage();
        alert('Failed to delete order. Please check your connection and try again.');
    });
}

function changeStatusFromMenu(referenceCode, newStatus) {
    closeAllMenus();
    
    // Show loading state
    showMenuLoadingMessage('Updating status...');
    
    const formData = new FormData();
    formData.append('update_status', '1');
    formData.append('reference_code', referenceCode);
    formData.append('status', newStatus);
    
    
    fetch('admin-orders.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        return response.json();
    })
    .then(data => {
        hideMenuLoadingMessage();
        
        if (data.success) {
            // Update the status badge in the table
            updateTableStatusBadge(referenceCode, newStatus);
            
            // Show success message
            const statusLabels = {
                'unpaid': 'Unpaid',
                'file_issue': 'File Issue',
                'unpaid': 'Unpaid',
                'paid': 'Paid',
                'printing': 'Printing',
                'delivered': 'Delivered',
                'pickedup': 'Picked Up',
                'cancelled': 'Cancelled',
                'refunded': 'Refunded'
            };
            
            showMenuSuccessMessage(`Order #${referenceCode} updated to ${statusLabels[newStatus]}`);
            
        } else {
            console.error('Status update failed:', data.error);
            alert('Failed to update status: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Status update request error:', error);
        hideMenuLoadingMessage();
        alert('Failed to update status. Please check your connection and try again.');
    });
}

// ===== HELPER FUNCTIONS =====
function updateTableStatusBadge(referenceCode, newStatus) {
    const statusIcons = {
    'submitted': '&#128203;',   // &#128203;
    'checking': '&#128065;',    // &#128065;
    'unpaid': '&#9203;',        // ⏳
    'paid': '&#128176;',        // &#128176;
    'file_issue': '&#128065;',  // &#128065;
    'printing': '&#128424;',    // &#128424;
    'shipped': '&#128666;',     // &#128666;
    'delivered': '&#128230;',   // &#128230;
    'pickedup': '&#9989;',      // &#9989;
    'unclaimed': '&#128236;',   // &#128236;
    'missing': '&#9888;',       // &#9888;
    'cancelled': '&#10006;',    // &#10006;
    'refunded': '&#128683;'     // &#128683;
    }
    
    const statusLabels = {
        'unpaid': 'Unpaid',
        'file_issue': 'File Issue',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'printing': 'Printing',
        'delivered': 'Delivered',
        'pickedup': 'Picked Up',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };
    
    // Find and update the row
    const tableRows = document.querySelectorAll('#ordersTableBody tr');
    tableRows.forEach(row => {
        if (row.dataset.reference === referenceCode.toLowerCase()) {
            // Update row class and data
            row.className = newStatus;
            row.dataset.status = newStatus;
            
            // Update status badge
            const statusBadge = row.querySelector('.status-badge');
            if (statusBadge) {
                statusBadge.className = `status-badge status-${newStatus}`;
                statusBadge.innerHTML = `${statusIcons[newStatus]} ${statusLabels[newStatus]}`;
            }
            
            // Handle NEW badge visibility based on status
            const orderNumberCell = row.querySelector('td:first-child');
            if (orderNumberCell) {
                const orderLink = orderNumberCell.querySelector('a.order-number');
                const existingBadge = orderNumberCell.querySelector('.new-badge');
                
                if (newStatus === 'unpaid') {
                    // Add NEW badge if not present
                    if (!existingBadge && orderLink) {
                        const newBadgeWrapper = document.createElement('div');
                        newBadgeWrapper.className = 'new-badge';
                        newBadgeWrapper.appendChild(orderLink.cloneNode(true));
                        orderLink.replaceWith(newBadgeWrapper);
                    }
                } else {
                    // Remove NEW badge if present
                    if (existingBadge) {
                        const link = existingBadge.querySelector('a.order-number');
                        if (link) {
                            existingBadge.replaceWith(link);
                        }
                    }
                }
            }
        }
    });
}

// ===== FEEDBACK MESSAGE SYSTEM =====
function showMenuLoadingMessage(message) {
    hideMenuLoadingMessage(); // Remove any existing message
    
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'menuLoadingMessage';
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
        font-family: 'Montserrat', sans-serif;
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

function hideMenuLoadingMessage() {
    const loadingDiv = document.getElementById('menuLoadingMessage');
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

function showMenuSuccessMessage(message) {
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
        z-index: 1000000;
        font-size: 0.9rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        font-family: 'Montserrat', sans-serif;
    `;
    tempMessage.textContent = message;
    
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

// ===== EVENT HANDLERS =====
function initializeMenuEventHandlers() {
    document.addEventListener('click', function(event) {
        // Close menus when clicking outside
        if (!activeMenu) return;
        
        const clickedMenu = event.target.closest('.action-menu-dropdown');
        const clickedTrigger = event.target.closest('.action-menu-trigger');
        
        if (clickedMenu === activeMenu || clickedTrigger) return;
        
        closeAllMenus();
    });

    document.addEventListener('keydown', function(event) {
        // Close menu on Escape key
        if (event.key === 'Escape' && activeMenu) {
            closeAllMenus();
        }
    });

    window.addEventListener('resize', closeAllMenus);

    window.addEventListener('scroll', closeAllMenus);
}

// ===== DEBUG FUNCTIONS =====
function debugMenuSystem() {
    
    const allMenus = document.querySelectorAll('.action-menu-dropdown');
    
    const allTriggers = document.querySelectorAll('.action-menu-trigger');
    
    // Test if functions are available in global scope
}

// ===== INITIALIZATION =====
function initializeMenuSystem() {
    
    // Setup event handlers
    initializeMenuEventHandlers();
    
    // Make debug function available globally
    window.debugMenuSystem = debugMenuSystem;
    
    // Test the first menu after page loads
    setTimeout(() => {
        const firstTrigger = document.querySelector('.action-menu-trigger');
        if (firstTrigger) {
            const onclick = firstTrigger.getAttribute('onclick');
        } else {
        }
    }, 1000);
    
}

// ===== CSS UTILITIES =====
function addMenuSystemStyles() {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .spinner {
            animation: spin 1s linear infinite;
        }
    `;
    
    if (!document.querySelector('style[data-menu-styles]')) {
        style.setAttribute('data-menu-styles', 'true');
        document.head.appendChild(style);
    }
}

// ===== AUTO-INITIALIZATION =====
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize on pages with order tables (main dashboard)
    if (document.querySelector('#ordersTableBody')) {
        addMenuSystemStyles();
        initializeMenuSystem();
    } else {
    }
});

