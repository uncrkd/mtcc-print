<?php
/**
 * Vendor Authentication System
 * PIN-based authentication for the vendor portal
 * 
 * Location: /fulfillment/vendor-auth.php
 * 
 * Vendors log in with email + 6-digit PIN (set in vendors.json).
 * Matches the courier PIN pattern from couriers.json / dispatch system.
 * 
 * God Mode admin bypass: If an admin is logged in with god_mode role,
 * they skip PIN auth entirely and can see all vendors' orders.
 * 
 * vendors.json needs a "pin" field per vendor:
 *   { "id": "vendor_xxx", "pin": "847291", "email": "...", ... }
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Data paths (relative to /fulfillment/ directory)
define('VENDORS_FILE', __DIR__ . '/../data/vendors.json');
define('VENDOR_SESSIONS_FILE', __DIR__ . '/../data/vendor-sessions.json');

// Settings
define('VENDOR_SESSION_EXPIRY', 24 * 60 * 60); // 24 hours
define('VENDOR_MAX_ATTEMPTS', 5);
define('VENDOR_LOCKOUT_MINUTES', 15);

// ============================================
// GOD MODE ADMIN BYPASS
// ============================================

/**
 * Check if current session is an admin with god_mode role.
 * Admin sessions use admin_* keys (set by admin-auth.php).
 * This lets god_mode admins access the vendor portal without a PIN.
 */
function isAdminGodMode() {
    return !empty($_SESSION['admin_logged_in']) && 
           ($_SESSION['admin_role'] ?? '') === 'god_mode';
}

/**
 * Check if current session is ANY admin (god_mode or super_admin).
 * Super admins can view the portal but not act on orders.
 */
function isAdminViewer() {
    return !empty($_SESSION['admin_logged_in']) && 
           in_array($_SESSION['admin_role'] ?? '', ['god_mode', 'super_admin']);
}

// ============================================
// DATA LOADING
// ============================================

function loadVendorsData() {
    if (!file_exists(VENDORS_FILE)) return ['vendors' => []];
    $data = json_decode(file_get_contents(VENDORS_FILE), true);
    return $data ?: ['vendors' => []];
}

function loadVendorSessions() {
    if (!file_exists(VENDOR_SESSIONS_FILE)) return [];
    return json_decode(file_get_contents(VENDOR_SESSIONS_FILE), true) ?: [];
}

function saveVendorSessions($data) {
    file_put_contents(VENDOR_SESSIONS_FILE, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function findVendorByEmail($email) {
    $data = loadVendorsData();
    foreach ($data['vendors'] as $vendor) {
        if (strtolower(trim($vendor['email'])) === strtolower(trim($email))) {
            return $vendor;
        }
    }
    return null;
}

function findVendorById($vendorId) {
    $data = loadVendorsData();
    foreach ($data['vendors'] as $vendor) {
        if ($vendor['id'] === $vendorId) {
            return $vendor;
        }
    }
    return null;
}

// ============================================
// RATE LIMITING
// ============================================

function checkRateLimit($email) {
    $sessions = loadVendorSessions();
    $key = 'attempts_' . md5(strtolower(trim($email)));
    
    if (!isset($sessions[$key])) return true;
    
    $attempts = $sessions[$key];
    if ($attempts['count'] >= VENDOR_MAX_ATTEMPTS) {
        $lockedUntil = strtotime($attempts['last_attempt']) + (VENDOR_LOCKOUT_MINUTES * 60);
        if (time() < $lockedUntil) {
            $remaining = ceil(($lockedUntil - time()) / 60);
            return "Too many failed attempts. Try again in {$remaining} minute(s).";
        }
        // Lockout expired, reset
        unset($sessions[$key]);
        saveVendorSessions($sessions);
    }
    
    return true;
}

function recordFailedAttempt($email) {
    $sessions = loadVendorSessions();
    $key = 'attempts_' . md5(strtolower(trim($email)));
    
    if (!isset($sessions[$key])) {
        $sessions[$key] = ['count' => 0, 'last_attempt' => null];
    }
    
    $sessions[$key]['count']++;
    $sessions[$key]['last_attempt'] = date('c');
    saveVendorSessions($sessions);
}

function clearFailedAttempts($email) {
    $sessions = loadVendorSessions();
    $key = 'attempts_' . md5(strtolower(trim($email)));
    unset($sessions[$key]);
    saveVendorSessions($sessions);
}

// ============================================
// AUTHENTICATION
// ============================================

function authenticateVendor($email, $pin) {
    // Rate limit check
    $rateCheck = checkRateLimit($email);
    if ($rateCheck !== true) {
        return ['success' => false, 'error' => $rateCheck];
    }
    
    $vendor = findVendorByEmail($email);
    
    if (!$vendor) {
        recordFailedAttempt($email);
        return ['success' => false, 'error' => 'Invalid email or PIN'];
    }
    
    if (!$vendor['active']) {
        return ['success' => false, 'error' => 'Account is inactive. Please contact Print Stuff.'];
    }
    
    if (empty($vendor['pin'])) {
        return ['success' => false, 'error' => 'PIN not configured. Please contact Print Stuff.'];
    }
    
    if ($vendor['pin'] !== trim($pin)) {
        recordFailedAttempt($email);
        return ['success' => false, 'error' => 'Invalid email or PIN'];
    }
    
    // Success — set session
    clearFailedAttempts($email);
    
    $_SESSION['vendor_logged_in'] = true;
    $_SESSION['vendor_id'] = $vendor['id'];
    $_SESSION['vendor_name'] = $vendor['business_name'];
    $_SESSION['vendor_email'] = $vendor['email'];
    $_SESSION['vendor_login_time'] = time();
    
    // Track login
    trackVendorLogin($vendor['id'], $vendor['business_name']);
    
    return ['success' => true, 'vendor' => $vendor];
}

function trackVendorLogin($vendorId, $vendorName) {
    $sessions = loadVendorSessions();
    
    $sessions['logins'][$vendorId] = [
        'vendor_name' => $vendorName,
        'last_login' => date('c'),
        'last_activity' => date('c'),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    saveVendorSessions($sessions);
}

// ============================================
// SESSION MANAGEMENT
// ============================================

function isVendorLoggedIn() {
    // God mode / super_admin bypass — skip vendor session check entirely
    if (isAdminViewer()) {
        return true;
    }
    
    if (empty($_SESSION['vendor_logged_in']) || empty($_SESSION['vendor_id'])) {
        return false;
    }
    
    // Check session expiry
    $loginTime = $_SESSION['vendor_login_time'] ?? 0;
    if ((time() - $loginTime) > VENDOR_SESSION_EXPIRY) {
        logoutVendor();
        return false;
    }
    
    return true;
}

function requireVendorLogin() {
    if (!isVendorLoggedIn()) {
        header('Location: index.php');
        exit;
    }
    
    // Skip activity tracking for admin viewers
    if (isAdminViewer()) {
        return;
    }
    
    // Update vendor activity timestamp
    $sessions = loadVendorSessions();
    $vendorId = $_SESSION['vendor_id'];
    if (isset($sessions['logins'][$vendorId])) {
        $sessions['logins'][$vendorId]['last_activity'] = date('c');
        saveVendorSessions($sessions);
    }
}

/**
 * Get current vendor ID.
 * - Admin god_mode: returns vendor filter from GET param, or 'all' to see everything
 * - Normal vendor: returns their vendor_id from session
 */
function getCurrentVendorId() {
    if (isAdminViewer()) {
        // Admin can filter by vendor or see all
        return $_GET['vendor'] ?? 'all';
    }
    return $_SESSION['vendor_id'] ?? null;
}

/**
 * Get display name for the current user.
 */
function getCurrentVendorName() {
    if (isAdminViewer()) {
        $role = $_SESSION['admin_role_label'] ?? 'Admin';
        return ($_SESSION['admin_name'] ?? 'Admin') . ' (' . $role . ')';
    }
    return $_SESSION['vendor_name'] ?? 'Vendor';
}

/**
 * Check if current user can perform vendor actions (confirm, mark ready, flag).
 * God mode can act. Super admin is view-only.
 */
function canPerformVendorActions() {
    if (isAdminGodMode()) return true;
    if (isAdminViewer()) return false; // super_admin = view only
    return isVendorLoggedIn();
}

function logoutVendor() {
    unset(
        $_SESSION['vendor_logged_in'],
        $_SESSION['vendor_id'],
        $_SESSION['vendor_name'],
        $_SESSION['vendor_email'],
        $_SESSION['vendor_login_time']
    );
}

// ============================================
// AUTO-LOGOUT HANDLER
// ============================================
if (isset($_GET['logout'])) {
    // If admin viewing, just redirect back to admin
    if (isAdminViewer()) {
        header('Location: ../admin-orders.php');
        exit;
    }
    logoutVendor();
    header('Location: index.php?logged_out=1');
    exit;
}
