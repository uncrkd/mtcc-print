<?php
/**
 * Sidebar Integration Helper
 * MTCC Print Services
 * 
 * Drop-in include for any admin page to add the sidebar.
 * Place this AFTER admin-auth.php and BEFORE the closing </body>.
 * 
 * Server path: /includes/sidebar-init.php
 * 
 * USAGE (2 steps per page):
 * 
 *   Step 1: In <head>, add the CSS:
 *     <link rel="stylesheet" href="css/admin-sidebar.css">
 *     (or ../css/admin-sidebar.css if in a subfolder)
 * 
 *   Step 2: Right after opening <body>, add:
 *     <?php 
 *       $SIDEBAR_CURRENT_PAGE = 'orders';  // set current page key
 *       require_once __DIR__ . '/includes/sidebar-init.php';
 *     ?>
 * 
 * That's it. The sidebar renders and the JS is included automatically.
 * 
 * Page keys: orders, production, events, dispatch, dispatch_hub,
 *            dispatch_analytics, dispatch_rates, dispatch_couriers,
 *            dispatch_settings, reports, activity_log, users
 */

// Determine paths based on current directory
$_sidebarInSubfolder = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || 
                         strpos($_SERVER['PHP_SELF'], '/reports/') !== false || 
                         strpos($_SERVER['PHP_SELF'], '/logs/') !== false ||
                         strpos($_SERVER['PHP_SELF'], '/dispatch/') !== false ||
                         strpos($_SERVER['PHP_SELF'], '/fulfillment/') !== false);
$_sidebarPrefix = $_sidebarInSubfolder ? '../' : '';

// Include sidebar component
require_once __DIR__ . '/admin-sidebar.php';

// Render sidebar HTML
$_sidebarPage = isset($SIDEBAR_CURRENT_PAGE) ? $SIDEBAR_CURRENT_PAGE : '';
renderSidebar($_sidebarPage);

// Output JS include
echo '<script src="' . $_sidebarPrefix . 'js/admin-sidebar.js"></script>';
