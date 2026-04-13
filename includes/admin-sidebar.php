<?php
/**
 * Admin Sidebar Navigation + Global Top Bar
 * MTCC Print Services
 * 
 * Collapsible sidebar with grouped navigation, SVG icons,
 * active page highlighting, and persistent collapse state.
 * Plus a global top bar spanning above sidebar + content.
 * 
 * Server path: /includes/admin-sidebar.php
 * 
 * USAGE:
 *   require_once __DIR__ . '/includes/admin-sidebar.php';
 *   renderSidebar('orders');   // pass current page key
 */

/**
 * Get sidebar navigation structure with icons and grouping.
 * Permission-gated: only shows items the current user can access.
 */
function getSidebarNavItems() {
    $groups = [];
    
    // ---- Main ----
    $main = [];
    if (function_exists('hasAnyPermission') && hasAnyPermission(['orders_edit', 'orders_view'])) {
        $main['orders'] = [
            'url' => 'admin-orders.php',
            'label' => 'Orders',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        ];
    }
    if (!empty($main)) {
        $groups['main'] = ['label' => '', 'items' => $main];
    }
    
    // ---- Workflow ----
    $workflow = [];
    $isMtccRole = (($_SESSION['admin_role'] ?? '') === 'mtcc_staff');
    if (!$isMtccRole && function_exists('hasAnyPermission') && hasAnyPermission(['orders_edit', 'orders_view'])) {
        $workflow['production'] = [
            'url' => 'admin/production.php',
            'label' => 'Production',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>',
            'children' => [
                'production' => ['url' => 'admin/production.php', 'label' => 'Queue'],
                'production_analytics' => ['url' => 'admin/production-analytics.php', 'label' => 'Analytics'],
                'production_vendors' => ['url' => 'admin/vendors.php', 'label' => 'Vendors'],
            ],
        ];
    }
    if (!$isMtccRole && function_exists('hasAnyPermission') && hasAnyPermission(['events_edit', 'events_view'])) {
        $workflow['events'] = [
            'url' => 'admin/events-manager.php',
            'label' => 'Events',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
        ];
    }
    if (function_exists('hasPermission') && hasPermission('dispatch')) {
        $workflow['dispatch'] = [
            'url' => 'dispatch/',
            'label' => 'Dispatch',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
            'children' => [
                'dispatch_hub' => ['url' => 'dispatch/', 'label' => 'Hub'],
                'dispatch_analytics' => ['url' => 'dispatch/analytics.php', 'label' => 'Analytics'],
                'dispatch_rates' => ['url' => 'dispatch/rate-optimization.php', 'label' => 'Rate Optimization'],
                'dispatch_couriers' => ['url' => 'dispatch/couriers.php', 'label' => 'Couriers'],
                'dispatch_settings' => ['url' => 'dispatch/settings.php', 'label' => 'Settings'],
            ],
        ];
    }
    // Fulfillment visible to god_mode and super_admin only
    $role = $_SESSION['admin_role'] ?? '';
    if (in_array($role, ['god_mode', 'super_admin'])) {
        $workflow['fulfillment'] = [
            'url' => 'fulfillment/dashboard.php',
            'label' => 'Fulfillment',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        ];
    }
    if (!empty($workflow)) {
        $groups['workflow'] = ['label' => 'Workflow', 'items' => $workflow];
    }
    
    // ---- System ----
    $system = [];
    if (function_exists('hasPermission') && hasPermission('reports')) {
        $system['reports'] = [
            'url' => 'reports/',
            'label' => 'Reports',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        ];
    }
    if (function_exists('hasPermission') && hasPermission('activity_log')) {
        $system['activity_log'] = [
            'url' => 'logs/',
            'label' => 'Activity Log',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        ];
    }
    if (function_exists('hasPermission') && hasPermission('user_management')) {
        $system['users'] = [
            'url' => 'admin-users.php',
            'label' => 'Users',
            'icon' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        ];
    }
    if (!empty($system)) {
        $groups['system'] = ['label' => 'System', 'items' => $system];
    }
    
    return $groups;
}

/**
 * Render the global top bar + sidebar HTML.
 * @param string $currentPage  Active page key
 */
function renderSidebar($currentPage = '') {
    $groups = getSidebarNavItems();
    
    // Determine URL prefix (are we in a subfolder?)
    $inSubfolder = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || 
                    strpos($_SERVER['PHP_SELF'], '/reports/') !== false || 
                    strpos($_SERVER['PHP_SELF'], '/logs/') !== false ||
                    strpos($_SERVER['PHP_SELF'], '/dispatch/') !== false ||
                    strpos($_SERVER['PHP_SELF'], '/fulfillment/') !== false ||
                    strpos($_SERVER['PHP_SELF'], '/includes/') !== false);
    $prefix = $inSubfolder ? '../' : '';
    
    // User info
    $userName = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
    $userRole = $_SESSION['admin_role'] ?? '';
    $roleLabels = [
        'god_mode' => 'God Mode',
        'super_admin' => 'Super Admin',
        'admin' => 'Admin',
        'staff' => 'Staff',
        'reports_only' => 'Reports',
    ];
    $roleLabel = $roleLabels[$userRole] ?? ucfirst($userRole);
    $userInitials = strtoupper(substr($userName, 0, 1));
    
    ?>
    <!-- Global Top Bar -->
    <header class="global-topbar" id="globalTopbar">
        <div class="topbar-left">
            <button class="topbar-hamburger" id="sidebarHamburger" onclick="toggleSidebar()" title="Menu">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <a href="<?= $prefix ?>admin-orders.php" class="topbar-logo-link">
                <img src="<?= $prefix ?>mtccpslogo.png" alt="MTCC Print Services" class="topbar-logo-img" onerror="this.style.display='none'">
                <span class="topbar-logo-text">MTCC Print Services</span>
            </a>
        </div>
        <div class="topbar-right">
            <span class="topbar-date"><?= date('l, M j') ?></span>
            <div class="topbar-divider"></div>
            <div class="topbar-user-pill">
                <span class="topbar-user-avatar"><?= $userInitials ?></span>
                <span class="topbar-user-name"><?= htmlspecialchars($userName) ?></span>
                <span class="topbar-user-role"><?= htmlspecialchars($roleLabel) ?></span>
            </div>
            <a href="?logout=1" class="topbar-logout" title="Sign out">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </header>
    
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
    
    <!-- Sidebar -->
    <nav class="admin-sidebar" id="adminSidebar">
        
        <!-- Sidebar Header (collapse toggle) -->
        <div class="sidebar-header">
            <button class="sidebar-collapse-btn" onclick="toggleSidebarCollapse()" title="Toggle sidebar">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
            </button>
        </div>
        
        <!-- Sidebar Navigation -->
        <div class="sidebar-nav">
            <?php foreach ($groups as $groupKey => $group): ?>
            <?php if (!empty($group['label'])): ?>
            <div class="sidebar-group-label"><?= htmlspecialchars($group['label']) ?></div>
            <?php endif; ?>
            
            <?php foreach ($group['items'] as $key => $item): ?>
            <?php 
                $isActive = ($currentPage === $key);
                $hasChildren = !empty($item['children']);
                $isChildActive = false;
                if ($hasChildren) {
                    foreach ($item['children'] as $childKey => $child) {
                        if ($currentPage === $childKey) { $isChildActive = true; break; }
                    }
                }
                $isOpen = $isActive || $isChildActive;
                $url = $prefix . $item['url'];
            ?>
            <div class="sidebar-item-wrap<?= $hasChildren ? ' has-children' : '' ?><?= $isOpen ? ' open' : '' ?>">
                <a href="<?= htmlspecialchars($url) ?>" 
                   class="sidebar-item<?= $isActive ? ' active' : '' ?><?= $isChildActive ? ' child-active' : '' ?>"
                   <?= $hasChildren ? 'onclick="toggleSidebarSub(event, this)"' : '' ?>
                   title="<?= htmlspecialchars($item['label']) ?>">
                    <span class="sidebar-item-icon"><?= $item['icon'] ?></span>
                    <span class="sidebar-item-label"><?= htmlspecialchars($item['label']) ?></span>
                    <?php if ($hasChildren): ?>
                    <svg class="sidebar-item-arrow" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                    <?php endif; ?>
                </a>
                <?php if ($hasChildren): ?>
                <div class="sidebar-sub" <?= $isOpen ? 'style="display:block"' : '' ?>>
                    <?php foreach ($item['children'] as $childKey => $child): ?>
                    <a href="<?= htmlspecialchars($prefix . $child['url']) ?>" 
                       class="sidebar-sub-item<?= $currentPage === $childKey ? ' active' : '' ?>"
                       title="<?= htmlspecialchars($child['label']) ?>">
                        <span class="sidebar-sub-dot"></span>
                        <span class="sidebar-item-label"><?= htmlspecialchars($child['label']) ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            
            <?php endforeach; ?>
        </div>
        
        <!-- Sidebar Footer -->
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-avatar"><?= $userInitials ?></div>
                <div class="sidebar-user-info">
                    <div class="sidebar-user-name"><?= htmlspecialchars($userName) ?></div>
                    <div class="sidebar-user-role"><?= htmlspecialchars($roleLabel) ?></div>
                </div>
            </div>
            <a href="?logout=1" class="sidebar-logout" title="Logout">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </nav>
    <?php
}
