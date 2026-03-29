/**
 * Admin Dashboard Drag-and-Drop with Gridstack
 * Feature #8: Customizable Analytics Dashboard
 */

(function() {
    'use strict';
    
    const STORAGE_KEY = 'mtcc_dashboard_layout_24col';
    let grid = null;
    let isEditMode = false;
    
    // Default layout for 24-column grid
    const DEFAULT_LAYOUT = [
        { id: 'today-revenue', x: 0, y: 0, w: 4, h: 6 },
        { id: 'avg-order', x: 4, y: 0, w: 4, h: 6 },
        { id: 'file-conversions', x: 8, y: 0, w: 4, h: 6 },
        { id: 'total-revenue', x: 12, y: 0, w: 6, h: 6 },
        { id: 'mtcc-venue-fee', x: 18, y: 0, w: 6, h: 6 },
        { id: 'pending-orders', x: 0, y: 6, w: 4, h: 6 },
        { id: 'cancelled-orders', x: 4, y: 6, w: 4, h: 6 },
        { id: 'refunded-orders', x: 8, y: 6, w: 4, h: 6 },
        { id: 'material-type', x: 12, y: 6, w: 6, h: 6 },
        { id: 'delivery-method', x: 18, y: 6, w: 6, h: 6 },
        { id: 'timeline-chart', x: 0, y: 12, w: 12, h: 21 },
        { id: 'top-sizes', x: 12, y: 12, w: 6, h: 9 },
        { id: 'turnaround', x: 18, y: 12, w: 6, h: 12 },
        { id: 'event-dist', x: 12, y: 21, w: 6, h: 9 },
        { id: 'order-statuses', x: 18, y: 24, w: 6, h: 9 }
    ];
    
    /**
     * Initialize Gridstack
     */
    function init() {
        const gridElement = document.getElementById('analyticsGrid');
        if (!gridElement) {
            return;
        }
        
        // Clear ALL old localStorage keys first
        clearAllOldLayouts();
        
        // Check if we have a saved layout BEFORE initializing grid
        const savedLayout = getSavedLayout();
        
        // If we have a saved layout, update HTML attributes BEFORE grid init
        if (savedLayout) {
            savedLayout.forEach(item => {
                const el = gridElement.querySelector(`[gs-id="${item.id}"]`);
                if (el) {
                    el.setAttribute('gs-x', item.x);
                    el.setAttribute('gs-y', item.y);
                    el.setAttribute('gs-w', item.w);
                    el.setAttribute('gs-h', item.h);
                }
            });
        }
        
        // Initialize Gridstack
        grid = GridStack.init({
            column: 24,
            cellHeight: 20,
            margin: 8,
            float: true,
            disableDrag: true,
            disableResize: true,
            animate: true,
            handle: '.drag-handle',
            resizable: {
                handles: 'e, se, s, sw, w'
            }
        }, gridElement);
        
        // Listen for changes
        grid.on('change', function(event, items) {
            if (isEditMode) {
                // Don't auto-save on every change, only on Set Default
            }
        });
        
        // Resize charts when grid changes
        grid.on('resizestop', function(event, el) {
            resizeChartsInElement(el);
        });
        
        if (savedLayout) {
        } else {
        }
    }
    
    /**
     * Clear ALL old localStorage keys
     */
    function clearAllOldLayouts() {
        const keysToRemove = [
            'mtcc_dashboard_layout',
            'mtcc_dashboard_layout_v1',
            'mtcc_dashboard_layout_v2',
            'mtcc_dashboard_layout_v3',
            'mtcc_dashboard_layout_v4',
            'mtcc_dashboard_layout_v5',
            'mtcc_dashboard_layout_v6',
            'mtcc_dashboard_layout_v7'
        ];
        keysToRemove.forEach(key => {
            if (localStorage.getItem(key)) {
                localStorage.removeItem(key);
            }
        });
    }
    
    /**
     * Get saved layout from localStorage
     */
    function getSavedLayout() {
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            if (!saved) return null;
            
            const data = JSON.parse(saved);
            if (data && Array.isArray(data.layout) && data.layout.length > 0) {
                return data.layout;
            }
            return null;
        } catch (e) {
            console.error('[Gridstack] Error reading saved layout:', e);
            return null;
        }
    }
    
    /**
     * Resize Chart.js charts inside an element
     */
    function resizeChartsInElement(el) {
        const canvases = el.querySelectorAll('canvas');
        canvases.forEach(canvas => {
            const chart = Chart.getChart(canvas);
            if (chart) {
                chart.resize();
            }
        });
    }
    
    /**
     * Toggle dashboard edit mode
     */
    window.toggleDashboardEditMode = function() {
        isEditMode = !isEditMode;
        
        const container = document.getElementById('analyticsContainer');
        const settingsBtn = document.getElementById('dashboardSettingsBtn');
        
        if (!grid) {
            console.error('[Gridstack] Grid not initialized');
            return;
        }
        
        if (isEditMode) {
            container.classList.add('edit-mode');
            settingsBtn.classList.add('active');
            grid.enableMove(true);
            grid.enableResize(true);
            addDragHandles();
            addEditModeToolbar();
        } else {
            container.classList.remove('edit-mode');
            settingsBtn.classList.remove('active');
            grid.enableMove(false);
            grid.enableResize(false);
            removeDragHandles();
            removeEditModeToolbar();
            document.querySelectorAll('.grid-stack-item').forEach(resizeChartsInElement);
        }
    };
    
    /**
     * Add drag handles to cards
     */
    function addDragHandles() {
        document.querySelectorAll('.grid-stack-item .card-header').forEach(header => {
            if (header.querySelector('.drag-handle')) return;
            
            const handle = document.createElement('div');
            handle.className = 'drag-handle';
            handle.innerHTML = '&#9881;️';
            handle.title = 'Drag to move';
            header.insertBefore(handle, header.firstChild);
        });
    }
    
    /**
     * Remove drag handles
     */
    function removeDragHandles() {
        document.querySelectorAll('.drag-handle').forEach(h => h.remove());
    }
    
    /**
     * Add floating toolbar
     */
    function addEditModeToolbar() {
        removeEditModeToolbar();
        
        const toolbar = document.createElement('div');
        toolbar.id = 'editModeToolbar';
        toolbar.className = 'edit-mode-toolbar';
        toolbar.innerHTML = `
            <div class="toolbar-message">
                <span class="toolbar-icon">&#9881;️</span>
                <span>Drag to move • Drag edges to resize</span>
            </div>
            <div class="toolbar-actions">
                <button class="toolbar-btn set-default" onclick="setDefaultLayout()" title="Save current layout as your default">
                    ⭐ Set as Default
                </button>
                <button class="toolbar-btn reset" onclick="resetDashboardLayout()" title="Reset to original layout">
                    &#128260; Reset
                </button>
                <button class="toolbar-btn done" onclick="toggleDashboardEditMode()">
                    &#9989; Done
                </button>
            </div>
        `;
        
        document.body.appendChild(toolbar);
        setTimeout(() => toolbar.classList.add('visible'), 10);
    }
    
    /**
     * Remove toolbar
     */
    function removeEditModeToolbar() {
        const toolbar = document.getElementById('editModeToolbar');
        if (toolbar) {
            toolbar.classList.remove('visible');
            setTimeout(() => toolbar.remove(), 300);
        }
    }
    
    /**
     * Get current layout from grid
     */
    function getCurrentLayout() {
        const layout = [];
        document.querySelectorAll('.grid-stack-item').forEach(el => {
            const id = el.getAttribute('gs-id');
            if (id) {
                layout.push({
                    id: id,
                    x: parseInt(el.getAttribute('gs-x')) || 0,
                    y: parseInt(el.getAttribute('gs-y')) || 0,
                    w: parseInt(el.getAttribute('gs-w')) || 4,
                    h: parseInt(el.getAttribute('gs-h')) || 6
                });
            }
        });
        return layout;
    }
    
    /**
     * Set current layout as user's default
     */
    window.setDefaultLayout = function() {
        const layout = getCurrentLayout();
        
        
        try {
            const data = {
                version: '24col',
                layout: layout,
                savedAt: new Date().toISOString()
            };
            localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
            showSaveIndicator('&#9989; Saved as your default layout');
        } catch (e) {
            console.error('[Gridstack] Failed to save:', e);
            showSaveIndicator('&#10060; Failed to save layout');
        }
    };
    
    /**
     * Reset to original default layout
     */
    window.resetDashboardLayout = function() {
        if (!confirm('Reset dashboard layout to original default?')) return;
        
        try {
            localStorage.removeItem(STORAGE_KEY);
            window.location.reload();
        } catch (e) {
            console.error('[Gridstack] Failed to reset:', e);
            window.location.reload();
        }
    };
    
    /**
     * Show save indicator
     */
    function showSaveIndicator(message) {
        const existing = document.querySelector('.layout-saved-indicator');
        if (existing) existing.remove();
        
        const indicator = document.createElement('div');
        indicator.className = 'layout-saved-indicator';
        indicator.innerHTML = message || 'âœ“ Saved';
        document.body.appendChild(indicator);
        
        setTimeout(() => {
            indicator.classList.add('fade-out');
            setTimeout(() => indicator.remove(), 300);
        }, 2000);
    }
    
    // Debug function - can be called from console
    window.debugGridLayout = function() {
    };
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        setTimeout(init, 100);
    }
    
})();
