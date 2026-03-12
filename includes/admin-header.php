<?php
/**
 * Admin Header Components
 * ======================
 * Provides consistent header rendering across all admin pages.
 * Matches the Order Dashboard header design.
 * 
 * USAGE:
 *   require_once 'includes/admin-header.php';
 *   
 *   // For 1400px container pages:
 *   renderAdminHeader([
 *       'title' => 'User Management',
 *       'subtitle' => 'Manage admin users and permissions',
 *       'currentPage' => 'users',
 *       'containerWidth' => '1400px',
 *       'rightContent' => '<div class="header-tabs">...</div>'
 *   ]);
 *   
 *   // For full-width pages:
 *   renderAdminHeader([
 *       'title' => 'Order Dashboard',
 *       'subtitle' => 'Welcome Zeus! 👋',
 *       'subtitleDate' => true,
 *       'currentPage' => 'orders',
 *       'containerWidth' => 'full',
 *       'rightContent' => '...',
 *       'showOnlineIndicator' => true
 *   ]);
 * 
 * Last Updated: January 2026
 */

// Ensure icons are loaded
if (!defined('ICON_CLIPBOARD')) {
    require_once __DIR__ . '/icons.php';
}

/**
 * Render the complete admin header (logo bar + page header)
 * 
 * @param array $config Configuration options:
 *   - 'title' => Page title (required)
 *   - 'subtitle' => Subtitle text (optional)
 *   - 'subtitleDate' => Show date under subtitle (default: true)
 *   - 'currentPage' => Page key for nav highlighting (orders, events, dispatch, etc.)
 *   - 'containerWidth' => '1200px' or 'full' (default: '1200px')
 *   - 'theme' => Color theme: purple, green, orange, blue, gray, red, teal, edit (default: purple)
 *   - 'rightContent' => HTML string for right side of page header (optional)
 *   - 'backButton' => Array with 'url' and 'text' for back button in title bar (optional)
 *   - 'logo' => Logo image path (default: 'mtcc-ps-logo.png')
 *   - 'showOnlineIndicator' => Show online user count (default: false, only for dashboard)
 */
function renderAdminHeader($config) {
    $title = $config['title'] ?? 'Page Title';
    $subtitle = $config['subtitle'] ?? null;
    $subtitleDate = $config['subtitleDate'] ?? true;
    $currentPage = $config['currentPage'] ?? '';
    $containerWidth = $config['containerWidth'] ?? '1400px';
    $theme = $config['theme'] ?? 'purple';
    $rightContent = $config['rightContent'] ?? '';
    $backButton = $config['backButton'] ?? null;
    $logo = $config['logo'] ?? 'mtcc-ps-logo.png';
    $showOnlineIndicator = $config['showOnlineIndicator'] ?? false;
    
    // Determine container class
    $containerClass = ($containerWidth === 'full') ? 'header-container-full' : 'header-container';
    
    // Start container
    echo '<div class="' . $containerClass . '">';
    
    // Render top logo bar (pass showOnlineIndicator)
    renderTopLogoBar($currentPage, $logo, $showOnlineIndicator);
    
    // Render page header
    renderPageHeader($title, $subtitle, $subtitleDate, $theme, $rightContent, $backButton);
    
    // End container
    echo '</div>';
}

/**
 * Render the top logo bar with navigation
 */
function renderTopLogoBar($currentPage = '', $logo = 'mtcc-ps-logo.png', $showOnlineIndicator = false) {
    echo '<div class="top-logo-bar">';
    echo '  <div class="logo-left">';
    echo '    <a href="admin-orders.php">';
    echo '      <img src="' . htmlspecialchars($logo) . '" alt="MTCC Print Services" class="top-logo" onerror="this.style.display=\'none\'">';
    echo '    </a>';
    // Only show online indicator if explicitly enabled (for Order Dashboard only)
    if ($showOnlineIndicator && function_exists('renderOnlineIndicator')) {
        echo renderOnlineIndicator();
    }
    echo '  </div>';
    echo '  <div class="logo-right">';
    if (function_exists('renderAdminNav')) {
        echo renderAdminNav($currentPage);
    }
    echo '  </div>';
    echo '</div>';
}

/**
 * Render the page header (colored title bar)
 */
function renderPageHeader($title, $subtitle = null, $subtitleDate = true, $theme = 'purple', $rightContent = '', $backButton = null) {
    $themeClass = 'theme-' . $theme;
    
    echo '<div class="page-header ' . $themeClass . '">';
    echo '  <div class="page-header-left">';
    echo '    <h1 class="page-title">' . htmlspecialchars($title) . '</h1>';
    
    if ($subtitle || $subtitleDate) {
        echo '    <div class="page-welcome">';
        if ($subtitle) {
            echo '      <span class="welcome-text">' . $subtitle . '</span>';
        }
        if ($subtitleDate) {
            echo '      <span class="welcome-date">Today is ' . date('l, F j Y') . '</span>';
        }
        echo '    </div>';
    }
    
    echo '  </div>';
    
    // Right side content
    if ($rightContent || $backButton) {
        echo '  <div class="page-header-right">';
        
        // Back button goes in the title bar
        if ($backButton) {
            $backUrl = $backButton['url'] ?? 'admin-orders.php';
            $backText = $backButton['text'] ?? '← Back';
            echo '<a href="' . htmlspecialchars($backUrl) . '" class="header-btn header-btn-light">' . $backText . '</a>';
        }
        
        // Additional right content
        if ($rightContent) {
            echo $rightContent;
        }
        
        echo '  </div>';
    }
    
    echo '</div>';
}

/**
 * Helper: Generate header tabs HTML
 * 
 * @param array $tabs Array of tab configs: [['url' => '?tab=users', 'text' => 'Users', 'active' => true], ...]
 */
function renderHeaderTabs($tabs) {
    $html = '<div class="header-tabs">';
    foreach ($tabs as $tab) {
        $activeClass = !empty($tab['active']) ? ' active' : '';
        $url = $tab['url'] ?? '#';
        $text = $tab['text'] ?? 'Tab';
        $html .= '<a href="' . htmlspecialchars($url) . '" class="header-tab' . $activeClass . '">' . $text . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Helper: Generate a header button
 */
function renderHeaderButton($text, $url = '#', $solid = false, $icon = null, $target = null) {
    $class = $solid ? 'header-btn header-btn-solid' : 'header-btn header-btn-light';
    $targetAttr = $target ? ' target="' . htmlspecialchars($target) . '"' : '';
    $iconHtml = $icon ? $icon . ' ' : '';
    return '<a href="' . htmlspecialchars($url) . '" class="' . $class . '"' . $targetAttr . '>' . $iconHtml . htmlspecialchars($text) . '</a>';
}

/**
 * Helper: Generate a header badge
 */
function renderHeaderBadge($icon, $count, $text, $type = 'warning') {
    return '<span class="header-badge header-badge-' . $type . '">' .
           '<span class="badge-icon">' . $icon . '</span>' .
           '<span class="badge-count">' . htmlspecialchars($count) . '</span>' .
           '<span class="badge-text">' . htmlspecialchars($text) . '</span>' .
           '</span>';
}

/**
 * Helper: Generate segmented control (Active Events / All Events)
 */
function renderHeaderSegmentedControl($id, $segments) {
    $html = '<div class="header-segmented-control" id="' . htmlspecialchars($id) . '">';
    foreach ($segments as $segment) {
        $activeClass = !empty($segment['active']) ? ' active' : '';
        $dataMode = isset($segment['mode']) ? ' data-mode="' . htmlspecialchars($segment['mode']) . '"' : '';
        $html .= '<button class="header-segment-btn' . $activeClass . '"' . $dataMode . '>' . htmlspecialchars($segment['text']) . '</button>';
    }
    $html .= '</div>';
    return $html;
}
