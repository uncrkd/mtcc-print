/**
 * Admin Dashboard Controller Module - Module 4
 * Coordinates all modules and handles dashboard-specific functionality
 */

// ===== DASHBOARD STATE MANAGEMENT =====
class DashboardController {
    constructor() {
        this.modules = {
            utilities: null,
            filtering: null,
            menu: null
        };
        this.isInitialized = false;
        this.debugMode = false;
    }

    // Initialize the dashboard and all modules
    async initialize() {
        console.log('=== Dashboard Controller Initializing ===');
        
        try {
            // Initialize core utilities first
            this.initializeUtilities();
            
            // Initialize filtering system
            this.initializeFiltering();
            
            // Initialize menu system
            this.initializeMenuSystem();
            
            // Setup dashboard-specific features
            this.setupDashboardFeatures();
            
            // Setup module coordination
            this.setupModuleCoordination();
            
            this.isInitialized = true;
            console.log('=== Dashboard Controller Ready ===');
            
        } catch (error) {
            console.error('Dashboard initialization failed:', error);
            this.handleInitializationError(error);
        }
    }

    // Initialize utilities module
    initializeUtilities() {
        console.log('Initializing utilities module...');
        
        // Utilities module auto-initializes, just verify it's available
        if (typeof initializeCommonUtilities === 'function') {
            console.log('✅ Utilities module loaded');
            this.modules.utilities = true;
        } else {
            console.warn('🚫  Utilities module not found');
        }
    }

    // Initialize filtering system
    initializeFiltering() {
    console.log('Initializing filtering system...');
    
    // Only initialize on pages with order tables
    if (document.querySelector('#ordersTableBody')) {
        if (typeof SimpleFilterManager === 'function') {
            this.modules.filtering = new SimpleFilterManager();
            window.simpleFilters = this.modules.filtering;
            console.log('✅ Simple filtering system initialized');
        } else {
            console.warn('🚫  Simple filtering system not found');
        }
    } else {
        console.log('No order table found, skipping filtering system');
    }
}

    // Initialize menu system
    initializeMenuSystem() {
        console.log('Initializing menu system...');
        
        // Only initialize on pages with order tables
        if (document.querySelector('#ordersTableBody')) {
            if (typeof initializeMenuSystem === 'function') {
                // Menu system auto-initializes via DOMContentLoaded
                console.log('✅ Menu system will auto-initialize');
                this.modules.menu = true;
            } else {
                console.warn('🚫  Menu system not found');
            }
        } else {
            console.log('No order table found, skipping menu system');
        }
    }

    // Setup dashboard-specific features
    setupDashboardFeatures() {
        console.log('Setting up dashboard features...');
        
        // Setup analytics updates
        this.setupAnalyticsUpdates();
        
        // Setup real-time features
        this.setupRealTimeFeatures();
        
        // Setup keyboard shortcuts
        this.setupKeyboardShortcuts();
        
        // Setup error handling
        this.setupGlobalErrorHandling();
    }

	
	// Setup module coordination
setupModuleCoordination() {
    console.log('Setting up module coordination...');
    
    // Coordinate status updates between modules
    this.setupStatusUpdateCoordination();
    
    // Coordinate filtering with menu actions
    this.setupFilterMenuCoordination();
    
    // Setup shared event handling
    this.setupSharedEventHandling();
}

// ===== MODULE COORDINATION =====
setupStatusUpdateCoordination() {
    // Listen for status updates and coordinate between modules
    document.addEventListener('statusUpdated', (event) => {
        const { referenceCode, newStatus } = event.detail;
        
        // Update analytics
        this.updateAnalyticsDisplay();
        
        // Update any other displays that depend on status
        this.updateStatusDependentDisplays(referenceCode, newStatus);
    });
}

updateStatusDependentDisplays(referenceCode, newStatus) {
    // Update any dashboard elements that depend on order status
    console.log(`Status updated: ${referenceCode} -> ${newStatus}`);
    
    // This could trigger analytics refresh, badge updates, etc.
    setTimeout(() => {
        this.updateAnalyticsDisplay();
    }, 100);
}

setupFilterMenuCoordination() {
    // Ensure menu actions work properly with filtered views
    document.addEventListener('orderDeleted', (event) => {
        const { referenceCode } = event.detail;
        
        // Update analytics after deletion
        setTimeout(() => {
            this.updateAnalyticsDisplay();
        }, 500);
    });
}

setupSharedEventHandling() {
    // Setup any shared event handlers that coordinate between modules
    this.setupWindowEventHandlers();
    this.setupDocumentEventHandlers();
}

setupWindowEventHandlers() {
    // Handle window events that affect multiple modules
    window.addEventListener('resize', () => {
        // Close any open menus on resize
        if (typeof closeAllMenus === 'function') {
            closeAllMenus();
        }
    });

    window.addEventListener('beforeunload', () => {
        // Cleanup if needed
        this.cleanup();
    });
}

setupDocumentEventHandlers() {
    // Handle document-level events
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            // Close menus when tab becomes hidden
            if (typeof closeAllMenus === 'function') {
                closeAllMenus();
            }
        }
    });
}
	
    // Setup module coordination
    setupAnalyticsUpdates() {
    // Update analytics when orders change
    this.updateAnalyticsDisplay();
    
    // Initialize analytics module if available and Chart.js is loaded
    if (typeof Chart !== 'undefined' && typeof initializeAnalytics === 'function' && window.dashboardData) {
        console.log('Initializing analytics from dashboard controller...');
        setTimeout(() => {
            initializeAnalytics(window.dashboardData);
        }, 500);
    }
    
    // Setup periodic analytics refresh (every 5 minutes)
    setInterval(() => {
        this.updateAnalyticsDisplay();
    }, 5 * 60 * 1000);
}

updateAnalyticsDisplay() {
    const totalOrders = document.querySelectorAll('#ordersTableBody tr').length;
    const visibleOrders = document.querySelectorAll('#ordersTableBody tr:not(.hidden)').length;
    
    // Update any analytics displays if they exist
    const analyticsElements = document.querySelectorAll('[data-analytics]');
    analyticsElements.forEach(element => {
        const type = element.dataset.analytics;
        switch (type) {
            case 'total-orders':
                element.textContent = totalOrders;
                break;
            case 'visible-orders':
                element.textContent = visibleOrders;
                break;
        }
    });
}

refreshAnalyticsData() {
    // This function could be expanded to fetch fresh data from server
    // For now, it updates charts with current page data
    if (window.analyticsManager && window.dashboardData) {
        console.log('Refreshing analytics data...');
        
        // Recalculate analytics from current table data
        const currentOrders = this.extractOrdersFromTable();
        if (currentOrders.length > 0) {
            window.dashboardData.orders = currentOrders;
            window.analyticsManager.updateCharts(window.dashboardData);
        }
    }
}

extractOrdersFromTable() {
    // Extract order data from the current table for real-time updates
    const orders = [];
    const tableRows = document.querySelectorAll('#ordersTableBody tr');
    
    tableRows.forEach(row => {
        // This is a simplified extraction - you could expand this
        // to get more detailed order information if needed
        const orderData = {
            referenceCode: row.dataset.reference || '',
            status: row.dataset.status || '',
            submittedAt: new Date(parseInt(row.dataset.submitted) * 1000).toISOString(),
            pricing: {
                total: parseFloat(row.dataset.value) || 0
            }
        };
        orders.push(orderData);
    });
    
    return orders;
}

    setupRealTimeFeatures() {
        // Setup countdown timers for due dates (if any exist on dashboard)
        this.updateCountdownTimers();
        setInterval(() => this.updateCountdownTimers(), 1000);
        
        // Setup status change animations
        this.setupStatusChangeAnimations();
    }

    updateCountdownTimers() {
        const countdownElements = document.querySelectorAll('.countdown-timer');
        countdownElements.forEach(element => {
            // This would be implemented if countdown timers exist on dashboard
            // For now, just update any existing ones
            if (typeof updateCountdown === 'function') {
                updateCountdown();
            }
        });
    }

    setupStatusChangeAnimations() {
        // Add smooth transitions when status badges change
        const statusBadges = document.querySelectorAll('.status-badge');
        statusBadges.forEach(badge => {
            badge.style.transition = 'all 0.3s ease';
        });
    }

    // ===== KEYBOARD SHORTCUTS =====
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Only handle shortcuts when not typing in inputs
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
                return;
            }

            // Dashboard shortcuts
            switch (e.key) {
                case 'r':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        this.refreshDashboard();
                    }
                    break;
                    
                case 'f':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        this.focusSearch();
                    }
                    break;
                    
                case 'c':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        this.clearAllFilters();
                    }
                    break;
                    
                case 'Escape':
                    this.handleEscapeKey();
                    break;
            }
        });
    }

    focusSearch() {
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            searchBox.focus();
            searchBox.select();
        }
    }

    clearAllFilters() {
    if (window.simpleFilters && typeof window.simpleFilters.clearAll === 'function') {
        window.simpleFilters.clearAll();
    } else {
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (clearBtn && !clearBtn.disabled) {
            clearBtn.click();
        }
    }
}
    handleEscapeKey() {
        // Close any open menus
        if (typeof closeAllMenus === 'function') {
            closeAllMenus();
        }
        
        // Clear search if it has content
        const searchBox = document.getElementById('searchBox');
        if (searchBox && searchBox.value) {
            searchBox.value = '';
            searchBox.dispatchEvent(new Event('input'));
        }
    }

    refreshDashboard() {
        console.log('Refreshing dashboard...');
        location.reload();
    }

    // ===== MODULE COORDINATION =====
    setupStatusUpdateCoordination() {
        // Listen for status updates and coordinate between modules
        document.addEventListener('statusUpdated', (event) => {
            const { referenceCode, newStatus } = event.detail;
            
            // Update analytics
            this.updateAnalyticsDisplay();
            
            // Update any other displays that depend on status
            this.updateStatusDependentDisplays(referenceCode, newStatus);
        });
    }

    updateStatusDependentDisplays(referenceCode, newStatus) {
        // Update any dashboard elements that depend on order status
        console.log(`Status updated: ${referenceCode} -> ${newStatus}`);
        
        // This could trigger analytics refresh, badge updates, etc.
        setTimeout(() => {
            this.updateAnalyticsDisplay();
        }, 100);
    }

    setupFilterMenuCoordination() {
        // Ensure menu actions work properly with filtered views
        document.addEventListener('orderDeleted', (event) => {
            const { referenceCode } = event.detail;
            
            // Update analytics after deletion
            setTimeout(() => {
                this.updateAnalyticsDisplay();
            }, 500);
        });
    }

    setupSharedEventHandling() {
        // Setup any shared event handlers that coordinate between modules
        this.setupWindowEventHandlers();
        this.setupDocumentEventHandlers();
    }

    setupWindowEventHandlers() {
        // Handle window events that affect multiple modules
        window.addEventListener('resize', () => {
            // Close any open menus on resize
            if (typeof closeAllMenus === 'function') {
                closeAllMenus();
            }
        });

        window.addEventListener('beforeunload', () => {
            // Cleanup if needed
            this.cleanup();
        });
    }

    setupDocumentEventHandlers() {
        // Handle document-level events
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                // Close menus when tab becomes hidden
                if (typeof closeAllMenus === 'function') {
                    closeAllMenus();
                }
            }
        });
    }

    // ===== ERROR HANDLING =====
    setupGlobalErrorHandling() {
        // Handle any dashboard-level errors
        window.addEventListener('error', (event) => {
            console.error('Dashboard error:', event.error);
            this.handleGlobalError(event.error);
        });

        // Handle unhandled promise rejections
        window.addEventListener('unhandledrejection', (event) => {
            console.error('Unhandled promise rejection:', event.reason);
            this.handleGlobalError(event.reason);
        });
    }

    handleGlobalError(error) {
        // Log error and potentially show user-friendly message
        console.error('Dashboard error occurred:', error);
        
        // Could show a toast notification or error banner
        // For now, just log it
    }

    handleInitializationError(error) {
        console.error('Failed to initialize dashboard:', error);
        
        // Show user-friendly error message
        this.showInitializationError();
    }

    showInitializationError() {
        // Create error message for user
        const errorDiv = document.createElement('div');
        errorDiv.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #dc2626;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            z-index: 1000000;
            font-size: 0.9rem;
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        `;
        errorDiv.textContent = 'Dashboard initialization failed. Please refresh the page.';
        
        document.body.appendChild(errorDiv);
        
        // Auto-remove after 10 seconds
        setTimeout(() => {
            if (errorDiv.parentNode) {
                errorDiv.remove();
            }
        }, 10000);
    }

    // ===== UTILITY METHODS =====
    getModuleStatus() {
        return {
            utilities: this.modules.utilities,
            filtering: this.modules.filtering,
            menu: this.modules.menu,
            initialized: this.isInitialized
        };
    }

    enableDebugMode() {
        this.debugMode = true;
        console.log('Dashboard debug mode enabled');
        
        // Make debug functions available globally
        window.dashboardDebug = {
            getStatus: () => this.getModuleStatus(),
            refreshAnalytics: () => this.updateAnalyticsDisplay(),
            clearFilters: () => this.clearAllFilters(),
            getController: () => this
        };
    }

    cleanup() {
        // Cleanup any resources if needed
        console.log('Dashboard controller cleanup');
    }
}






function animateCompactCircles() {
    document.querySelectorAll('.compact-circle').forEach(circle => {
        const percentage = parseInt(circle.dataset.percentage);
        const ring = circle.querySelector('.progress-ring');
        const circumference = 157;
        const offset = circumference - (percentage / 100) * circumference;
        
        setTimeout(() => {
            ring.style.strokeDashoffset = offset;
        }, 300);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    setTimeout(animateCompactCircles, 800);
});






// ===== DASHBOARD UTILITIES =====
/**
 * Show dashboard notification
 */
function showDashboardNotification(message, type = 'info', duration = 3000) {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 8px;
        font-weight: 600;
        z-index: 1000000;
        font-size: 0.9rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transition: all 0.3s ease;
    `;
    
    // Set colors based on type
    switch (type) {
        case 'success':
            notification.style.background = '#059669';
            notification.style.color = 'white';
            break;
        case 'error':
            notification.style.background = '#dc2626';
            notification.style.color = 'white';
            break;
        case 'warning':
            notification.style.background = '#d97706';
            notification.style.color = 'white';
            break;
        default:
            notification.style.background = '#0284c7';
            notification.style.color = 'white';
    }
    
    notification.textContent = message;
    document.body.appendChild(notification);
    
    // Auto-remove
    setTimeout(() => {
        notification.style.opacity = '0';
        notification.style.transform = 'translateX(100%)';
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, duration);
}

/**
 * Update page title with order count
 */
function updatePageTitle() {
    const totalOrders = document.querySelectorAll('#ordersTableBody tr').length;
    const visibleOrders = document.querySelectorAll('#ordersTableBody tr:not(.hidden)').length;
    
    if (totalOrders !== visibleOrders) {
        document.title = `Orders (${visibleOrders}/${totalOrders}) - Print Stuff Admin`;
    } else {
        document.title = `Orders (${totalOrders}) - Print Stuff Admin`;
    }
}




/**
 * Check for new orders (could be expanded for real-time updates)
 */
function checkForNewOrders() {
    // This could be expanded to poll for new orders
    // For now, just update the display
    updatePageTitle();
}

// ===== DASHBOARD INITIALIZATION =====
/**
 * Initialize the dashboard controller
 */
function initializeDashboard() {
    // Only initialize on dashboard pages
    if (document.querySelector('.dashboard-header') || document.querySelector('#ordersTableBody')) {
        console.log('Dashboard page detected, initializing controller...');
        
        const controller = new DashboardController();
        
        // Make controller available globally for debugging
        window.dashboardController = controller;
        
        // Initialize the dashboard
        controller.initialize();
        
        // Setup additional dashboard features
        updatePageTitle();
        
        // Check for new orders periodically (every 2 minutes)
        setInterval(checkForNewOrders, 2 * 60 * 1000);
        
        return controller;
    } else {
        console.log('Not a dashboard page, skipping dashboard controller');
        return null;
    }
}

// ===== AUTO-INITIALIZATION =====
document.addEventListener('DOMContentLoaded', function() {
    // Small delay to ensure other modules are loaded
    setTimeout(() => {
        initializeDashboard();
    }, 100);
});

console.log('Dashboard Controller Module loaded');