/**
 * ============================================================================
 * PHASE 10 COMPLETE INTEGRATION PATCH - admin-utilities.js
 * ============================================================================
 * 
 * 4 existing locations to update + 1 new function to add.
 * 
 * =====================================================
 * CHANGE 1 OF 4: updateStatusBadgeInDOM - statusIcons (line ~180)
 * =====================================================
 * 
 * FIND:
 */

    const statusIcons = {
        'submitted': '&#128203;',
        'checking': '&#128065;',
        'unpaid': '&#9203;',
        'paid': '&#128176;',
        'file_issue': '&#128065;',
        'printing': '&#128424;',
        'shipped': '&#128666;',
        'delivered': '&#128230;',
        'pickedup': '&#9989;',
        'unclaimed': '&#128236;',
        'missing': '&#9888;',
        'cancelled': '&#10006;',
        'refunded': '&#128683;'
    };

/*
 * REPLACE WITH:
 */

    const statusIcons = {
        'submitted': '&#128203;',
        'checking': '&#128065;',
        'unpaid': '&#9203;',
        'paid': '&#128176;',
        'preflight': '&#128640;',
        'file_issue': '&#128065;',
        'printing': '&#128424;',
        'ready_to_ship': '&#128230;',
        'shipped': '&#128666;',
        'delivered': '&#128230;',
        'pickedup': '&#9989;',
        'unclaimed': '&#128236;',
        'missing': '&#9888;',
        'cancelled': '&#10006;',
        'refunded': '&#128683;'
    };

/*
 * =====================================================
 * CHANGE 2 OF 4: updateStatusBadgeInDOM - statusLabels (line ~196)
 * =====================================================
 * 
 * FIND:
 */

    const statusLabels = {
        'submitted': 'Submitted',
        'checking': 'File Checking',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'printing': 'Printing',
        'delivered': 'Delivered',
        'pickedup': 'Picked Up',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };

/*
 * REPLACE WITH:
 */

    const statusLabels = {
        'submitted': 'Submitted',
        'checking': 'File Checking',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'preflight': 'Preflight',
        'file_issue': 'File Issue',
        'printing': 'Printing',
        'ready_to_ship': 'Ready to Ship',
        'shipped': 'Shipped',
        'delivered': 'Delivered',
        'pickedup': 'Picked Up',
        'unclaimed': 'Unclaimed',
        'missing': 'Missing',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };

/*
 * =====================================================
 * CHANGE 3 OF 4: showStatusUpdateMessage - statusLabels (line ~252)
 * =====================================================
 * 
 * FIND:
 */

    const statusLabels = {
        'submitted': 'Submitted',
        'checking': 'File Checking',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'printing': 'Printing',
        'delivered': 'Delivered',
        'cancelled': 'Cancelled'
    };

/*
 * REPLACE WITH:
 */

    const statusLabels = {
        'submitted': 'Submitted',
        'checking': 'File Checking',
        'unpaid': 'Unpaid',
        'paid': 'Paid',
        'preflight': 'Preflight',
        'file_issue': 'File Issue',
        'printing': 'Printing',
        'ready_to_ship': 'Ready to Ship',
        'shipped': 'Shipped',
        'delivered': 'Delivered',
        'pickedup': 'Picked Up',
        'unclaimed': 'Unclaimed',
        'missing': 'Missing',
        'cancelled': 'Cancelled',
        'refunded': 'Refunded'
    };

/*
 * =====================================================
 * CHANGE 4 OF 4: quickStatusConfig + statusGroups (line ~775)
 * =====================================================
 * 
 * FIND:
 */

const quickStatusConfig = {
    'unpaid': { label: 'Unpaid', icon: '&#9203;' },      // ⏳
    'paid': { label: 'Paid', icon: '&#128176;' },        // 💰
    'file_issue': { label: 'File Issue', icon: '&#128269;' }, // 🔍
    'printing': { label: 'Printing', icon: '&#128424;&#65039;' },  // 🖨️
    'shipped': { label: 'Shipped', icon: '&#128666;' },   // 🚚
    'delivered': { label: 'Delivered', icon: '&#128230;' }, // 📦
    'pickedup': { label: 'Picked Up', icon: '&#9989;' },  // ✅
    'unclaimed': { label: 'Unclaimed', icon: '&#128236;' }, // 📬
    'missing': { label: 'Missing', icon: '&#9888;' },     // ⚠
    'cancelled': { label: 'Cancelled', icon: '&#10006;' }, // ✖
    'refunded': { label: 'Refunded', icon: '&#128683;' }  // 🚫
};

/*
 * REPLACE WITH:
 */

const quickStatusConfig = {
    'unpaid': { label: 'Unpaid', icon: '&#9203;' },      // ⏳
    'paid': { label: 'Paid', icon: '&#128176;' },        // 💰
    'preflight': { label: 'Preflight', icon: '&#128640;' }, // 🚀
    'file_issue': { label: 'File Issue', icon: '&#128269;' }, // 🔍
    'printing': { label: 'Printing', icon: '&#128424;&#65039;' },  // 🖨️
    'ready_to_ship': { label: 'Ready to Ship', icon: '&#128230;' }, // 📦
    'shipped': { label: 'Shipped', icon: '&#128666;' },   // 🚚
    'delivered': { label: 'Delivered', icon: '&#128230;' }, // 📦
    'pickedup': { label: 'Picked Up', icon: '&#9989;' },  // ✅
    'unclaimed': { label: 'Unclaimed', icon: '&#128236;' }, // 📬
    'missing': { label: 'Missing', icon: '&#9888;' },     // ⚠
    'cancelled': { label: 'Cancelled', icon: '&#10006;' }, // ✖
    'refunded': { label: 'Refunded', icon: '&#128683;' }  // 🚫
};

/*
 * ALSO in the same file, update statusGroups in createQuickStatusDropdown (~line 866).
 * 
 * FIND:
 */

    const statusGroups = [
        ['unpaid', 'file_issue'],
        ['paid', 'printing'],
        ['shipped', 'delivered', 'pickedup'],
        ['unclaimed', 'missing'],
        ['cancelled']
    ];

/*
 * REPLACE WITH:
 */

    const statusGroups = [
        ['unpaid', 'file_issue'],
        ['paid', 'preflight', 'printing', 'ready_to_ship'],
        ['shipped', 'delivered', 'pickedup'],
        ['unclaimed', 'missing'],
        ['cancelled']
    ];

/*
 * =====================================================
 * NEW FUNCTION: pushToPreflightFromMenu
 * =====================================================
 * 
 * Add this function at the END of admin-utilities.js (before the closing),
 * or anywhere after the updateOrderStatus function.
 * 
 * This handles the "Push to Preflight" action from the sandwich menu.
 */

/**
 * Push order to Preflight status from the sandwich menu.
 * Changes status to 'preflight' and shows confirmation toast.
 * Only available for paid/file_issue orders with uploaded files.
 */
function pushToPreflightFromMenu(referenceCode) {
    // Close the action menu
    const menu = document.getElementById('menu_' + referenceCode);
    if (menu) {
        menu.classList.remove('show');
    }
    
    // Confirm the action
    if (!confirm(`Push order ${referenceCode} to Preflight?\n\nThis will mark the order as ready for vendor assignment.`)) {
        return;
    }
    
    // Use existing updateOrderStatus to change to preflight
    updateOrderStatus(referenceCode, 'preflight', 
        function(data) {
            // Success - show a purple-themed toast
            const toast = document.createElement('div');
            toast.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #8b5cf6;
                color: white;
                padding: 12px 20px;
                border-radius: 8px;
                font-weight: 600;
                z-index: 100000;
                font-size: 0.9rem;
                box-shadow: 0 4px 12px rgba(139, 92, 246, 0.4);
                animation: slideIn 0.3s ease;
            `;
            toast.innerHTML = `&#128640; Order ${referenceCode} pushed to Preflight`;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.transition = 'all 0.3s ease';
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
            
            // Hide the "Push to Preflight" button in the menu since status changed
            const menuContainer = document.getElementById('menu_' + referenceCode);
            if (menuContainer) {
                const preflightBtn = menuContainer.querySelector('.preflight-action');
                if (preflightBtn) {
                    const section = preflightBtn.closest('.menu-section');
                    if (section) {
                        // Also hide the divider before it
                        const prevDivider = section.previousElementSibling;
                        if (prevDivider && prevDivider.classList.contains('menu-divider')) {
                            prevDivider.style.display = 'none';
                        }
                        section.style.display = 'none';
                    }
                }
            }
        },
        function(errorMsg) {
            alert(errorMsg);
        }
    );
}
