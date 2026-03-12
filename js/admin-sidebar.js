/**
 * Admin Sidebar + Global Top Bar JS
 * MTCC Print Services
 * 
 * Server path: /admin-sidebar.js
 */

(function() {
    'use strict';

    // Add has-sidebar class immediately
    document.body.classList.add('has-sidebar');

    // Restore collapse state (desktop only)
    if (window.innerWidth > 1024) {
        if (localStorage.getItem('mtcc_sidebar_collapsed') === '1') {
            document.body.classList.add('sidebar-collapsed');
        }
    }

    // Toggle collapse (desktop)
    window.toggleSidebarCollapse = function() {
        document.body.classList.toggle('sidebar-collapsed');
        var isCollapsed = document.body.classList.contains('sidebar-collapsed');
        localStorage.setItem('mtcc_sidebar_collapsed', isCollapsed ? '1' : '0');
    };

    // Toggle mobile sidebar
    window.toggleSidebar = function() {
        var sidebar = document.getElementById('adminSidebar');
        var overlay = document.getElementById('sidebarOverlay');
        if (!sidebar) return;

        var isOpen = sidebar.classList.contains('mobile-open');
        sidebar.classList.toggle('mobile-open', !isOpen);
        if (overlay) overlay.classList.toggle('active', !isOpen);
    };

    // Toggle sub-menu
    window.toggleSidebarSub = function(event, el) {
        var wrap = el.closest('.sidebar-item-wrap');
        if (!wrap) return;
        
        // If collapsed on desktop, navigate instead of toggling
        if (document.body.classList.contains('sidebar-collapsed') && window.innerWidth > 1024) {
            return; // Let the link navigate
        }
        
        event.preventDefault();
        wrap.classList.toggle('open');
    };

    // Close mobile sidebar on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var sidebar = document.getElementById('adminSidebar');
            if (sidebar && sidebar.classList.contains('mobile-open')) {
                toggleSidebar();
            }
        }
    });

    // Close mobile sidebar on resize to desktop
    window.addEventListener('resize', function() {
        if (window.innerWidth > 1024) {
            var sidebar = document.getElementById('adminSidebar');
            var overlay = document.getElementById('sidebarOverlay');
            if (sidebar) sidebar.classList.remove('mobile-open');
            if (overlay) overlay.classList.remove('active');
        }
    });
})();
