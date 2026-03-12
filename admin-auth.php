<?php
/**
 * Admin Authentication System
 * JSON-based multi-user authentication with role-based permissions
 * 
 * Features:
 * - Multi-user authentication with roles
 * - Permission-based access control
 * - Session tracking (who's online)
 * - Login history
 * - Remember me functionality
 * - Activity logging
 * 
 * Include this file at the top of any admin page to require login
 */

// ============================================
// Configuration
// ============================================
define('ADMIN_USERS_FILE', __DIR__ . '/data/admin-users.json');
define('ADMIN_SESSIONS_FILE', __DIR__ . '/data/admin-sessions.json');
define('REMEMBER_ME_COOKIE', 'mtcc_admin_remember');
define('REMEMBER_ME_DAYS', 30);
define('SESSION_ACTIVE_MINUTES', 15); // Consider user "online" if active within this time

// ============================================
// Session Configuration
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', 1);
    }
    session_start();
}

// ============================================
// Load/Save Admin Users Data
// ============================================
function loadAdminUsers() {
    if (!file_exists(ADMIN_USERS_FILE)) {
        return createDefaultAdminUsers();
    }
    $data = json_decode(file_get_contents(ADMIN_USERS_FILE), true);
    if (!$data) {
        return createDefaultAdminUsers();
    }
    
    // Check if password hashes are placeholders and need regeneration
    $needsRegeneration = false;
    foreach ($data['users'] ?? [] as $username => $user) {
        if (strpos($user['password_hash'] ?? '', 'YourHashHere') !== false || 
            strlen($user['password_hash'] ?? '') < 20) {
            $needsRegeneration = true;
            break;
        }
    }
    
    if ($needsRegeneration) {
        return createDefaultAdminUsers();
    }
    
    return $data;
}

function saveAdminUsers($data) {
    $data['metadata']['last_updated'] = date('c');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return file_put_contents(ADMIN_USERS_FILE, $json, LOCK_EX);
}

// ============================================
// Session Tracking (Who's Online)
// ============================================
function loadSessions() {
    if (!file_exists(ADMIN_SESSIONS_FILE)) {
        return ['sessions' => []];
    }
    return json_decode(file_get_contents(ADMIN_SESSIONS_FILE), true) ?? ['sessions' => []];
}

function saveSessions($data) {
    return file_put_contents(ADMIN_SESSIONS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function updateUserSession() {
    if (!isAdminLoggedIn()) return;
    
    $sessionData = loadSessions();
    $username = getCurrentAdminUsername();
    $sessionId = session_id();
    
    // Clean up old sessions (older than active threshold)
    $threshold = time() - (SESSION_ACTIVE_MINUTES * 60);
    foreach ($sessionData['sessions'] as $key => $session) {
        if (strtotime($session['last_activity']) < $threshold) {
            unset($sessionData['sessions'][$key]);
        }
    }
    $sessionData['sessions'] = array_values($sessionData['sessions']); // Re-index
    
    // Update or add current session
    $found = false;
    foreach ($sessionData['sessions'] as &$session) {
        if ($session['session_id'] === $sessionId) {
            $session['last_activity'] = date('c');
            $session['current_page'] = $_SERVER['REQUEST_URI'] ?? '';
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        $sessionData['sessions'][] = [
            'session_id' => $sessionId,
            'username' => $username,
            'name' => getCurrentAdminName(),
            'role' => getCurrentAdminRoleLabel(),
            'login_time' => date('c'),
            'last_activity' => date('c'),
            'current_page' => $_SERVER['REQUEST_URI'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
    }
    
    saveSessions($sessionData);
}

function removeUserSession() {
    $sessionData = loadSessions();
    $sessionId = session_id();
    
    $sessionData['sessions'] = array_filter($sessionData['sessions'], function($s) use ($sessionId) {
        return $s['session_id'] !== $sessionId;
    });
    $sessionData['sessions'] = array_values($sessionData['sessions']);
    
    saveSessions($sessionData);
}

function getOnlineUsers() {
    $sessionData = loadSessions();
    $threshold = time() - (SESSION_ACTIVE_MINUTES * 60);
    
    $onlineUsers = [];
    foreach ($sessionData['sessions'] as $session) {
        if (strtotime($session['last_activity']) >= $threshold) {
            $onlineUsers[] = $session;
        }
    }
    
    return $onlineUsers;
}

function getOnlineUserCount() {
    return count(getOnlineUsers());
}

// ============================================
// Login History
// ============================================
function logLoginAttempt($username, $success, $reason = '') {
    $logFile = __DIR__ . '/logs/activity-log.json';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logData = [];
    if (file_exists($logFile)) {
        $logData = json_decode(file_get_contents($logFile), true) ?? [];
    }
    
    if (!isset($logData['entries'])) {
        $logData['entries'] = [];
    }
    
    $adminData = loadAdminUsers();
    $user = $adminData['users'][$username] ?? null;
    
    $entry = [
        'timestamp' => date('c'),
        'username' => $username,
        'name' => $user['name'] ?? $username,
        'role' => $user ? ($adminData['roles'][$user['role']]['label'] ?? $user['role']) : 'Unknown',
        'action' => $success ? 'login_success' : 'login_failed',
        'details' => [
            'success' => $success,
            'reason' => $reason,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ],
        'order_ref' => null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logData['entries'][] = $entry;
    
    // Keep only last 5000 entries
    if (count($logData['entries']) > 5000) {
        $logData['entries'] = array_slice($logData['entries'], -5000);
    }
    
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT), LOCK_EX);
}

function logLogout($username) {
    $logFile = __DIR__ . '/logs/activity-log.json';
    
    $logData = [];
    if (file_exists($logFile)) {
        $logData = json_decode(file_get_contents($logFile), true) ?? [];
    }
    
    if (!isset($logData['entries'])) {
        $logData['entries'] = [];
    }
    
    $entry = [
        'timestamp' => date('c'),
        'username' => $username,
        'name' => $_SESSION['admin_name'] ?? $username,
        'role' => $_SESSION['admin_role_label'] ?? 'Unknown',
        'action' => 'logout',
        'details' => [],
        'order_ref' => null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logData['entries'][] = $entry;
    
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT), LOCK_EX);
}

// ============================================
// Remember Me Functions
// ============================================
function generateRememberToken() {
    return bin2hex(random_bytes(32));
}

function setRememberMeCookie($username, $token) {
    $expires = time() + (REMEMBER_ME_DAYS * 24 * 60 * 60);
    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
    setcookie(REMEMBER_ME_COOKIE, $username . ':' . $token, $expires, '/', '', $secure, true);
}

function clearRememberMeCookie() {
    setcookie(REMEMBER_ME_COOKIE, '', time() - 3600, '/', '', false, true);
}

function saveRememberToken($username, $token) {
    $adminData = loadAdminUsers();
    if (isset($adminData['users'][$username])) {
        $adminData['users'][$username]['remember_token'] = password_hash($token, PASSWORD_DEFAULT);
        $adminData['users'][$username]['remember_expires'] = date('c', time() + (REMEMBER_ME_DAYS * 24 * 60 * 60));
        saveAdminUsers($adminData);
    }
}

function validateRememberToken($username, $token) {
    $adminData = loadAdminUsers();
    if (!isset($adminData['users'][$username])) {
        return false;
    }
    
    $user = $adminData['users'][$username];
    
    // Check if token exists and hasn't expired
    if (empty($user['remember_token']) || empty($user['remember_expires'])) {
        return false;
    }
    
    if (strtotime($user['remember_expires']) < time()) {
        return false;
    }
    
    return password_verify($token, $user['remember_token']);
}

function clearRememberToken($username) {
    $adminData = loadAdminUsers();
    if (isset($adminData['users'][$username])) {
        unset($adminData['users'][$username]['remember_token']);
        unset($adminData['users'][$username]['remember_expires']);
        saveAdminUsers($adminData);
    }
}

function attemptAutoLogin() {
    if (isAdminLoggedIn()) return true;
    
    if (!isset($_COOKIE[REMEMBER_ME_COOKIE])) return false;
    
    $parts = explode(':', $_COOKIE[REMEMBER_ME_COOKIE], 2);
    if (count($parts) !== 2) {
        clearRememberMeCookie();
        return false;
    }
    
    list($username, $token) = $parts;
    
    if (!validateRememberToken($username, $token)) {
        clearRememberMeCookie();
        return false;
    }
    
    // Token is valid, log user in
    $adminData = loadAdminUsers();
    $user = $adminData['users'][$username] ?? null;
    
    if (!$user || !$user['active']) {
        clearRememberMeCookie();
        return false;
    }
    
    $role = $user['role'];
    $roleData = $adminData['roles'][$role] ?? null;
    $permissions = $user['custom_permissions'] ?? ($roleData['permissions'] ?? []);
    
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $username;
    $_SESSION['admin_name'] = $user['name'];
    $_SESSION['admin_role'] = $role;
    $_SESSION['admin_role_label'] = $roleData['label'] ?? ucfirst($role);
    $_SESSION['admin_permissions'] = $permissions;
    $_SESSION['admin_tracked'] = $roleData['tracked'] ?? true;
    $_SESSION['admin_login_time'] = time();
    $_SESSION['admin_must_change_password'] = $user['must_change_password'] ?? false;
    $_SESSION['admin_auto_login'] = true;
    
    // Log auto-login
    logLoginAttempt($username, true, 'Auto-login via Remember Me');
    
    // Update last login
    $adminData['users'][$username]['last_login'] = date('c');
    $adminData['users'][$username]['login_count'] = ($user['login_count'] ?? 0) + 1;
    saveAdminUsers($adminData);
    
    // Refresh remember token for security
    $newToken = generateRememberToken();
    saveRememberToken($username, $newToken);
    setRememberMeCookie($username, $newToken);
    
    return true;
}

// ============================================
// Create Default Admin Users
// ============================================
function createDefaultAdminUsers() {
    $data = [
        'users' => [
            'zeus' => [
                'name' => 'Zeus',
                'password_hash' => password_hash('AlphaOmega', PASSWORD_DEFAULT),
                'role' => 'god_mode',
                'custom_permissions' => null,
                'active' => true,
                'must_change_password' => false,
                'created_at' => date('c'),
                'created_by' => 'system',
                'last_login' => null,
                'login_count' => 0
            ],
            'george' => [
                'name' => 'George',
                'password_hash' => password_hash('MTCCPrint', PASSWORD_DEFAULT),
                'role' => 'super_admin',
                'custom_permissions' => null,
                'active' => true,
                'must_change_password' => false,
                'created_at' => date('c'),
                'created_by' => 'system',
                'last_login' => null,
                'login_count' => 0
            ]
        ],
        'roles' => [
            'god_mode' => [
                'label' => 'God Mode',
                'description' => 'Absolute control over everything. Not tracked in activity log.',
                'tracked' => false,
                'is_system_role' => true,
                'permissions' => [
                    'orders_edit', 'orders_view', 'orders_create', 'orders_delete',
                    'dashboard_analytics', 'events_edit', 'events_view', 'events_analytics',
                    'reports', 'dispatch', 'activity_log', 'user_management', 'role_management'
                ]
            ],
            'super_admin' => [
                'label' => 'Super Admin',
                'description' => 'Full access except Activity Log. Tracked in activity log.',
                'tracked' => true,
                'is_system_role' => false,
                'permissions' => [
                    'orders_edit', 'orders_view', 'orders_create', 'orders_delete',
                    'dashboard_analytics', 'events_edit', 'events_view', 'events_analytics',
                    'reports', 'dispatch', 'user_management'
                ]
            ],
            'admin' => [
                'label' => 'Admin',
                'description' => 'Orders, Events, and Analytics management.',
                'tracked' => true,
                'is_system_role' => false,
                'permissions' => [
                    'orders_edit', 'orders_view', 'orders_create',
                    'dashboard_analytics', 'events_edit', 'events_view', 'events_analytics'
                ]
            ],
            'staff' => [
                'label' => 'Staff',
                'description' => 'View orders and events only. Can print and download.',
                'tracked' => true,
                'is_system_role' => false,
                'permissions' => ['orders_view', 'events_view']
            ],
            'reports_only' => [
                'label' => 'Reports Only',
                'description' => 'Access to Reports page only.',
                'tracked' => true,
                'is_system_role' => false,
                'permissions' => ['reports']
            ]
        ],
        'permissions_catalog' => [
            'orders_edit' => ['label' => 'Orders (Full Edit)', 'description' => 'Edit orders, change status, update pricing', 'category' => 'orders'],
            'orders_view' => ['label' => 'Orders (View/Print/Download)', 'description' => 'View order details, print labels, download files', 'category' => 'orders'],
            'orders_create' => ['label' => 'Create Orders', 'description' => 'Create new orders from admin', 'category' => 'orders'],
            'orders_delete' => ['label' => 'Delete Orders', 'description' => 'Permanently delete orders', 'category' => 'orders'],
            'dashboard_analytics' => ['label' => 'Dashboard Analytics', 'description' => 'View analytics cards and charts on dashboard', 'category' => 'orders'],
            'events_edit' => ['label' => 'Events (Full Edit)', 'description' => 'Create, edit, archive, delete events', 'category' => 'events'],
            'events_view' => ['label' => 'Events (View Only)', 'description' => 'View event list without editing', 'category' => 'events'],
            'events_analytics' => ['label' => 'Events Analytics', 'description' => 'View event order counts and statistics', 'category' => 'events'],
            'reports' => ['label' => 'Reports', 'description' => 'Access revenue reports page', 'category' => 'reports'],
            'dispatch' => ['label' => 'Dispatch Manager', 'description' => 'Manage couriers and view scan activity', 'category' => 'dispatch'],
            'activity_log' => ['label' => 'Activity Log', 'description' => 'View system activity log', 'category' => 'system'],
            'user_management' => ['label' => 'User Management', 'description' => 'Add, edit, deactivate users', 'category' => 'system'],
            'role_management' => ['label' => 'Role Management', 'description' => 'Edit role definitions and permissions', 'category' => 'system']
        ],
        'settings' => [
            'password_min_length' => 8,
            'password_require_uppercase' => true,
            'password_require_lowercase' => true,
            'password_require_number' => true,
            'session_timeout_hours' => 8,
            'max_login_attempts' => 5,
            'lockout_minutes' => 15
        ],
        'metadata' => [
            'version' => '1.0',
            'created_at' => date('c'),
            'last_updated' => date('c')
        ]
    ];
    
    saveAdminUsers($data);
    return $data;
}

// ============================================
// Handle Logout
// ============================================
if (isset($_GET['logout'])) {
    $username = $_SESSION['admin_username'] ?? 'unknown';
    
    // Log logout (before clearing session)
    if (isset($_SESSION['admin_tracked']) && $_SESSION['admin_tracked']) {
        logLogout($username);
    }
    
    // Remove session tracking
    removeUserSession();
    
    // Clear remember me
    clearRememberMeCookie();
    clearRememberToken($username);
    
    // Destroy session
    $_SESSION = array();
    session_destroy();
    
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ============================================
// Attempt Auto-Login via Remember Me
// ============================================
attemptAutoLogin();

// ============================================
// Handle Login Attempt
// ============================================
$login_error = '';

if (isset($_POST['admin_username']) && isset($_POST['admin_password'])) {
    $submitted_username = strtolower(trim($_POST['admin_username']));
    $submitted_password = $_POST['admin_password'];
    $remember_me = isset($_POST['remember_me']);
    
    $adminData = loadAdminUsers();
    
    if (isset($adminData['users'][$submitted_username])) {
        $user = $adminData['users'][$submitted_username];
        
        if (!$user['active']) {
            $login_error = 'This account has been deactivated';
            logLoginAttempt($submitted_username, false, 'Account deactivated');
        } elseif (password_verify($submitted_password, $user['password_hash'])) {
            // Successful login
            $role = $user['role'];
            $roleData = $adminData['roles'][$role] ?? null;
            
            // Get permissions (custom or from role)
            $permissions = $user['custom_permissions'] ?? ($roleData['permissions'] ?? []);
            
            // Set session data
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $submitted_username;
            $_SESSION['admin_name'] = $user['name'];
            $_SESSION['admin_role'] = $role;
            $_SESSION['admin_role_label'] = $roleData['label'] ?? ucfirst($role);
            $_SESSION['admin_permissions'] = $permissions;
            $_SESSION['admin_tracked'] = $roleData['tracked'] ?? true;
            $_SESSION['admin_login_time'] = time();
            $_SESSION['admin_must_change_password'] = $user['must_change_password'] ?? false;
            
            // Log successful login
            logLoginAttempt($submitted_username, true, 'Password authentication');
            
            // Update last login
            $adminData['users'][$submitted_username]['last_login'] = date('c');
            $adminData['users'][$submitted_username]['login_count'] = ($user['login_count'] ?? 0) + 1;
            saveAdminUsers($adminData);
            
            // Handle Remember Me
            if ($remember_me) {
                $token = generateRememberToken();
                saveRememberToken($submitted_username, $token);
                setRememberMeCookie($submitted_username, $token);
            }
            
            // Redirect to remove POST data
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } else {
            $login_error = 'Invalid username or password';
            logLoginAttempt($submitted_username, false, 'Invalid password');
        }
    } else {
        $login_error = 'Invalid username or password';
        logLoginAttempt($submitted_username, false, 'User not found');
    }
}

// ============================================
// Permission & Auth Check Functions
// ============================================
function isAdminLoggedIn() {
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return false;
    }
    
    // Check session timeout
    $adminData = loadAdminUsers();
    $timeout_hours = $adminData['settings']['session_timeout_hours'] ?? 8;
    $session_timeout = $timeout_hours * 60 * 60;
    
    if (isset($_SESSION['admin_login_time']) && (time() - $_SESSION['admin_login_time']) > $session_timeout) {
        removeUserSession();
        $_SESSION = array();
        session_destroy();
        return false;
    }
    
    return true;
}

function hasPermission($permission) {
    if (!isAdminLoggedIn()) {
        return false;
    }
    
    $permissions = $_SESSION['admin_permissions'] ?? [];
    
    // God mode has everything
    if ($_SESSION['admin_role'] === 'god_mode') {
        return true;
    }
    
    return in_array($permission, $permissions);
}

function hasAnyPermission($permissionArray) {
    foreach ($permissionArray as $permission) {
        if (hasPermission($permission)) {
            return true;
        }
    }
    return false;
}

function hasAllPermissions($permissionArray) {
    foreach ($permissionArray as $permission) {
        if (!hasPermission($permission)) {
            return false;
        }
    }
    return true;
}

function isTrackedUser() {
    return $_SESSION['admin_tracked'] ?? true;
}

function getCurrentAdminUsername() {
    return $_SESSION['admin_username'] ?? 'unknown';
}

function getCurrentAdminName() {
    return $_SESSION['admin_name'] ?? 'Unknown';
}

function getCurrentAdminRole() {
    return $_SESSION['admin_role'] ?? 'unknown';
}

function getCurrentAdminRoleLabel() {
    return $_SESSION['admin_role_label'] ?? 'Unknown';
}

function isGodMode() {
    return ($_SESSION['admin_role'] ?? '') === 'god_mode';
}

function canManageUsers() {
    return hasPermission('user_management');
}

function canManageRoles() {
    return hasPermission('role_management');
}

// ============================================
// Password Validation
// ============================================
function validatePassword($password) {
    $adminData = loadAdminUsers();
    $settings = $adminData['settings'];
    $errors = [];
    
    if (strlen($password) < ($settings['password_min_length'] ?? 8)) {
        $errors[] = 'Password must be at least ' . ($settings['password_min_length'] ?? 8) . ' characters';
    }
    if (($settings['password_require_uppercase'] ?? true) && !preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    if (($settings['password_require_lowercase'] ?? true) && !preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    if (($settings['password_require_number'] ?? true) && !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    
    return $errors;
}

// ============================================
// Navigation Helper - Returns visible nav items
// ============================================
function getVisibleNavItems() {
    $navItems = [];
    
    if (hasAnyPermission(['orders_edit', 'orders_view'])) {
        $navItems['orders'] = ['url' => 'admin-orders.php', 'label' => 'Orders'];
    }
    if (hasAnyPermission(['orders_edit', 'orders_view'])) {
        $navItems['production'] = ['url' => 'admin/production.php', 'label' => 'Production'];
    }
    if (hasAnyPermission(['events_edit', 'events_view'])) {
        $navItems['events'] = ['url' => 'admin/events-manager.php', 'label' => 'Events'];
    }
    if (hasPermission('dispatch')) {
        $navItems['dispatch'] = ['url' => 'dispatch/', 'label' => 'Dispatch'];
    }
    if (hasPermission('reports')) {
        $navItems['reports'] = ['url' => 'reports/', 'label' => 'Reports'];
    }
    if (hasPermission('activity_log')) {
        $navItems['activity_log'] = ['url' => 'logs/', 'label' => 'Activity Log'];
    }
    if (hasPermission('user_management')) {
        $navItems['users'] = ['url' => 'admin-users.php', 'label' => 'Users'];
    }
    
    return $navItems;
}

// ============================================
// Render Navigation Bar
// ============================================
function renderAdminNav($currentPage = '') {
    $navItems = getVisibleNavItems();
    $html = '';
    
    // Determine if we're in a subfolder
    $inSubfolder = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || 
                    strpos($_SERVER['PHP_SELF'], '/reports/') !== false || 
                    strpos($_SERVER['PHP_SELF'], '/logs/') !== false ||
                    strpos($_SERVER['PHP_SELF'], '/dispatch/') !== false);
    $prefix = $inSubfolder ? '../' : '';
    
    foreach ($navItems as $key => $item) {
        $isActive = ($currentPage === $key) ? ' active' : '';
        $url = $prefix . $item['url'];
        $html .= '<a href="' . $url . '" class="top-nav-btn' . $isActive . '">' . $item['label'] . '</a>';
        $html .= '<span class="nav-divider">|</span>';
    }
    
    // Logout always visible
    $html .= '<a href="?logout=1" class="top-nav-btn">Logout</a>';
    
    return $html;
}

// ============================================
// Render Complete Admin Header (Logo + Navigation)
// ============================================
function renderAdminHeader($currentPage = '') {
    // Determine logo path based on current directory
    $inSubfolder = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false || 
                    strpos($_SERVER['PHP_SELF'], '/reports/') !== false || 
                    strpos($_SERVER['PHP_SELF'], '/logs/') !== false ||
                    strpos($_SERVER['PHP_SELF'], '/dispatch/') !== false ||
                    strpos($_SERVER['PHP_SELF'], '/fulfillment/') !== false);
    $logoPath = $inSubfolder ? '../mtccpslogo.png' : 'mtccpslogo.png';
    $ordersPath = $inSubfolder ? '../admin-orders.php' : 'admin-orders.php';
    
    // Logo bar only (nav handled by sidebar)
    $html = '<div class="top-logo-bar">';
    $html .= '<div class="logo-left">';
    $html .= '<a href="' . $ordersPath . '">';
    $html .= '<img src="' . $logoPath . '" alt="Logo" class="top-logo" onerror="this.style.display=\'none\'">';
    $html .= '</a>';
    $html .= '</div>';
    $html .= '</div>';
    
    return $html;
}

// ============================================
// Render Online Users Indicator
// ============================================
function renderOnlineIndicator() {
    $onlineUsers = getOnlineUsers();
    $count = count($onlineUsers);
    
    if ($count === 0) return '';
    
    $userList = array_map(function($u) {
        return htmlspecialchars($u['name']);
    }, $onlineUsers);
    
    $tooltip = implode(', ', $userList);
    
    return '<span class="online-indicator" title="Online: ' . $tooltip . '">
        <span class="online-dot"></span>
        <span class="online-count">' . $count . ' online</span>
    </span>';
}

// ============================================
// Activity Logging
// ============================================
function logAdminActivity($action, $details = [], $orderRef = null) {
    // Don't log if user is not tracked (God Mode)
    if (!isTrackedUser()) {
        return;
    }
    
    $logFile = __DIR__ . '/logs/activity-log.json';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logData = [];
    if (file_exists($logFile)) {
        $logData = json_decode(file_get_contents($logFile), true) ?? [];
    }
    
    if (!isset($logData['entries'])) {
        $logData['entries'] = [];
    }
    
    $entry = [
        'timestamp' => date('c'),
        'username' => getCurrentAdminUsername(),
        'name' => getCurrentAdminName(),
        'role' => getCurrentAdminRoleLabel(),
        'action' => $action,
        'details' => $details,
        'order_ref' => $orderRef,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logData['entries'][] = $entry;
    
    // Keep only last 5000 entries
    if (count($logData['entries']) > 5000) {
        $logData['entries'] = array_slice($logData['entries'], -5000);
    }
    
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT), LOCK_EX);
}

// ============================================
// Login Form HTML
// ============================================
function showLoginForm($error = '') {
    $error_html = '';
    if (!empty($error)) {
        $error_html = '<div class="login-error">' . htmlspecialchars($error) . '</div>';
    }
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Montserrat", sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px 40px;
            width: 100%;
            max-width: 420px;
        }
        .login-logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-logo img {
            max-width: 200px;
            height: auto;
        }
        .login-title {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .login-subtitle {
            text-align: center;
            color: #6b7280;
            font-size: 0.95rem;
            margin-bottom: 30px;
        }
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            font-family: inherit;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        .remember-me {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #7c3aed;
        }
        .remember-me label {
            font-size: 0.9rem;
            color: #4b5563;
            cursor: pointer;
        }
        .login-btn {
            width: 100%;
            padding: 16px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            color: white;
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.4);
        }
        .login-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }
        .login-footer a {
            color: #7c3aed;
            text-decoration: none;
            font-size: 0.9rem;
        }
        .login-footer a:hover { text-decoration: underline; }
        @media (max-width: 480px) {
            .login-container { padding: 40px 25px; }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-logo">
            <img src="logo.png" alt="MTCC Print Services" onerror="this.style.display=\'none\'">
        </div>
        
        <h1 class="login-title">ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬â„¢ Admin Login</h1>
        <p class="login-subtitle">Enter your credentials to access the admin area</p>
        
        ' . $error_html . '
        
        <form method="POST">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input type="text" name="admin_username" class="form-input" placeholder="Enter username" required autofocus>
            </div>
            
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="admin_password" class="form-input" placeholder="Enter password" required>
            </div>
            
            <div class="remember-me">
                <input type="checkbox" name="remember_me" id="remember_me">
                <label for="remember_me">Remember me for ' . REMEMBER_ME_DAYS . ' days</label>
            </div>
            
            <button type="submit" class="login-btn">Login ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢</button>
        </form>
        
        <div class="login-footer">
            <a href="/">ÃƒÂ¢Ã¢â‚¬Â Ã‚Â Back to Order Form</a>
        </div>
    </div>
</body>
</html>';
    exit;
}

// ============================================
// Password Change Form (for must_change_password)
// ============================================
function showPasswordChangeForm($error = '') {
    $error_html = '';
    if (!empty($error)) {
        $error_html = '<div class="login-error">' . htmlspecialchars($error) . '</div>';
    }
    
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Montserrat", sans-serif;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px 40px;
            width: 100%;
            max-width: 420px;
        }
        .login-title {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
        }
        .login-subtitle {
            text-align: center;
            color: #6b7280;
            font-size: 0.95rem;
            margin-bottom: 30px;
        }
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block;
            font-size: 0.9rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        .form-input {
            width: 100%;
            padding: 14px 16px;
            font-size: 1rem;
            font-family: inherit;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .form-input:focus {
            outline: none;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.1);
        }
        .login-btn {
            width: 100%;
            padding: 16px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            color: white;
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 10px;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.4);
        }
        .login-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            margin-bottom: 20px;
            text-align: center;
        }
        .password-requirements {
            background: #f3f4f6;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 0.85rem;
            color: #6b7280;
        }
        .password-requirements ul {
            margin: 8px 0 0 20px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h1 class="login-title">ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ¢â‚¬Ëœ Change Password</h1>
        <p class="login-subtitle">You must change your password before continuing</p>
        
        ' . $error_html . '
        
        <div class="password-requirements">
            <strong>Password Requirements:</strong>
            <ul>
                <li>At least 8 characters</li>
                <li>At least one uppercase letter</li>
                <li>At least one lowercase letter</li>
                <li>At least one number</li>
            </ul>
        </div>
        
        <form method="POST">
            <input type="hidden" name="change_password" value="1">
            
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-input" placeholder="Enter new password" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="confirm_password" class="form-input" placeholder="Confirm new password" required>
            </div>
            
            <button type="submit" class="login-btn">Change Password ÃƒÂ¢Ã¢â‚¬Â Ã¢â‚¬â„¢</button>
        </form>
    </div>
</body>
</html>';
    exit;
}

// ============================================
// Handle Password Change
// ============================================
if (isset($_POST['change_password']) && isAdminLoggedIn()) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $password_error = '';
    
    if ($new_password !== $confirm_password) {
        $password_error = 'Passwords do not match';
    } else {
        $validation_errors = validatePassword($new_password);
        if (!empty($validation_errors)) {
            $password_error = implode('. ', $validation_errors);
        } else {
            // Update password
            $adminData = loadAdminUsers();
            $username = getCurrentAdminUsername();
            $adminData['users'][$username]['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
            $adminData['users'][$username]['must_change_password'] = false;
            saveAdminUsers($adminData);
            
            $_SESSION['admin_must_change_password'] = false;
            
            // Log the action
            logAdminActivity('password_changed', ['user' => $username]);
            
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        }
    }
    
    if (!empty($password_error)) {
        showPasswordChangeForm($password_error);
    }
}

// ============================================
// Access Denied Page
// ============================================
function showAccessDenied($requiredPermission = '') {
    http_response_code(403);
    echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Montserrat", sans-serif;
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            padding: 50px 40px;
            width: 100%;
            max-width: 500px;
            text-align: center;
        }
        .icon { font-size: 4rem; margin-bottom: 20px; }
        .title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 12px;
        }
        .message {
            color: #6b7280;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .back-btn {
            display: inline-block;
            padding: 14px 28px;
            font-size: 1rem;
            font-weight: 600;
            color: white;
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            border: none;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.4);
        }
        .user-info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            font-size: 0.85rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">ÃƒÂ°Ã…Â¸Ã…Â¡Ã‚Â«</div>
        <h1 class="title">Access Denied</h1>
        <p class="message">You don\'t have permission to access this page.<br>Contact your administrator if you believe this is an error.</p>
        <a href="admin-orders.php" class="back-btn">ÃƒÂ¢Ã¢â‚¬Â Ã‚Â Go to Dashboard</a>
        <div class="user-info">
            Logged in as: ' . htmlspecialchars(getCurrentAdminName()) . ' (' . htmlspecialchars(getCurrentAdminRoleLabel()) . ')
        </div>
    </div>
</body>
</html>';
    exit;
}

// ============================================
// Require Login (call this to protect a page)
// ============================================
function requireAdminLogin() {
    global $login_error;
    
    if (!isAdminLoggedIn()) {
        showLoginForm($login_error);
    }
    
    // Update session tracking
    updateUserSession();
    
    // Check if must change password
    if ($_SESSION['admin_must_change_password'] ?? false) {
        showPasswordChangeForm();
    }
}

// ============================================
// Require Specific Permission
// ============================================
function requirePermission($permission) {
    requireAdminLogin();
    
    if (!hasPermission($permission)) {
        showAccessDenied($permission);
    }
}

// ============================================
// Require Any of Multiple Permissions
// ============================================
function requireAnyPermission($permissions) {
    requireAdminLogin();
    
    if (!hasAnyPermission($permissions)) {
        showAccessDenied(implode(' or ', $permissions));
    }
}

// ============================================
// Get Default Redirect for Role
// ============================================
function getDefaultPageForRole() {
    if (hasAnyPermission(['orders_edit', 'orders_view'])) {
        return 'admin-orders.php';
    }
    if (hasPermission('reports')) {
        return 'reports/';
    }
    if (hasAnyPermission(['events_edit', 'events_view'])) {
        return 'events-manager.php';
    }
    if (hasPermission('dispatch')) {
        return 'dispatch/';
    }
    return 'admin-orders.php';
}

// ============================================
// CSS for Online Indicator (include in pages)
// ============================================
function getOnlineIndicatorCSS() {
    return '
    .online-indicator {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        border-radius: 20px;
        font-size: 0.8rem;
        color: #065f46;
        font-weight: 500;
    }
    .online-dot {
        width: 8px;
        height: 8px;
        background: #10b981;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.5; }
    }
    ';
}
?>
