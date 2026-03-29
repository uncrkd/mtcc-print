<?php
/**
 * MTCC Print Services - Data Migration Tool
 * ==========================================
 * Moves JSON data files to /data/ folder and renames email files.
 * 
 * HOW TO USE:
 *   1. Upload this file to your web root via FTP (same folder as admin-orders.php)
 *   2. Open in browser: https://mtcc.print-stuff.ca/migrate.php
 *   3. Click "Run Dry Run" first to preview changes
 *   4. Click "Run Migration" to execute
 *   5. Test your site
 *   6. DELETE this file when done (security risk if left on server)
 */

// Simple password protection so nobody stumbles on this
$MIGRATION_PASSWORD = 'mtcc2026migrate';

session_start();

// Handle password
if (isset($_POST['password'])) {
    if ($_POST['password'] === $MIGRATION_PASSWORD) {
        $_SESSION['migrate_auth'] = true;
    }
}

if (empty($_SESSION['migrate_auth'])) {
    ?>
    <!DOCTYPE html>
    <html><head><title>Migration Tool</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 500px; margin: 100px auto; background: #f8fafc; }
        .login-box { background: white; padding: 40px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); text-align: center; }
        input[type=password] { padding: 12px 20px; font-size: 16px; border: 2px solid #e5e7eb; border-radius: 8px; width: 80%; margin: 16px 0; }
        button { padding: 12px 32px; font-size: 16px; background: #7c3aed; color: white; border: none; border-radius: 8px; cursor: pointer; }
        button:hover { background: #6d28d9; }
        h2 { color: #374151; }
    </style>
    </head><body>
    <div class="login-box">
        <h2>&#128274; Migration Tool</h2>
        <p>Enter the migration password to continue.</p>
        <form method="POST">
            <input type="password" name="password" placeholder="Password" autofocus><br>
            <button type="submit">Unlock</button>
        </form>
    </div>
    </body></html>
    <?php
    exit;
}

// ============================================================================
// CONFIGURATION
// ============================================================================

$webroot = __DIR__;
$mode = $_GET['mode'] ?? 'preview'; // 'preview' or 'execute'

$log = [];
$errors = [];
$warnings = [];

function logMsg($msg, $type = 'info') {
    global $log, $errors, $warnings;
    $log[] = ['msg' => $msg, 'type' => $type];
    if ($type === 'error') $errors[] = $msg;
    if ($type === 'warning') $warnings[] = $msg;
}

function safeMove($from, $to, $dryRun) {
    if (!file_exists($from)) {
        logMsg("SKIP: $from not found", 'warning');
        return false;
    }
    if ($dryRun) {
        logMsg("Would move: $from → $to");
        return true;
    }
    // Ensure target directory exists
    $dir = dirname($to);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (copy($from, $to)) {
        unlink($from);
        logMsg("Moved: $from → $to", 'success');
        return true;
    } else {
        logMsg("FAILED to move: $from → $to", 'error');
        return false;
    }
}

function safeCopy($from, $to, $dryRun) {
    if (!file_exists($from)) {
        logMsg("SKIP: $from not found", 'warning');
        return false;
    }
    if ($dryRun) {
        logMsg("Would copy: $from → $to");
        return true;
    }
    if (copy($from, $to)) {
        logMsg("Copied: $from → $to", 'success');
        return true;
    } else {
        logMsg("FAILED to copy: $from → $to", 'error');
        return false;
    }
}

function safeDelete($file, $dryRun) {
    if (!file_exists($file)) {
        logMsg("SKIP delete: $file not found (already gone)", 'warning');
        return true;
    }
    if ($dryRun) {
        logMsg("Would delete: $file");
        return true;
    }
    if (unlink($file)) {
        logMsg("Deleted: $file", 'success');
        return true;
    } else {
        logMsg("FAILED to delete: $file", 'error');
        return false;
    }
}

/**
 * Replace text in a file. Handles both Unix and Windows line endings.
 */
function safeReplace($file, $old, $new, $description, $dryRun) {
    if (!file_exists($file)) {
        logMsg("SKIP: " . basename($file) . " not found", 'warning');
        return false;
    }
    
    $content = file_get_contents($file);
    
    if (strpos($content, $old) === false) {
        // Already updated or pattern not found
        if (strpos($content, $new) !== false) {
            logMsg("Already done: " . basename($file) . " — $description", 'skip');
        } else {
            logMsg("Pattern not found in " . basename($file) . ": \"$old\"", 'warning');
        }
        return false;
    }
    
    if ($dryRun) {
        $count = substr_count($content, $old);
        logMsg("Would update " . basename($file) . " ($count occurrence" . ($count > 1 ? 's' : '') . ") — $description");
        return true;
    }
    
    $newContent = str_replace($old, $new, $content);
    if (file_put_contents($file, $newContent) !== false) {
        $count = substr_count($content, $old);
        logMsg("Updated: " . basename($file) . " ($count change" . ($count > 1 ? 's' : '') . ") — $description", 'success');
        return true;
    } else {
        logMsg("FAILED to write: " . basename($file), 'error');
        return false;
    }
}

// ============================================================================
// RUN MIGRATION
// ============================================================================

$dryRun = ($mode !== 'execute');

logMsg("=== MTCC Data Migration " . ($dryRun ? "(DRY RUN — no changes)" : "(EXECUTING)") . " ===", 'header');
logMsg("Web root: $webroot");
logMsg("");

// ---- PRE-FLIGHT CHECKS ----
logMsg("--- Pre-Flight Checks ---", 'header');

$canWrite = is_writable($webroot);
logMsg("Root directory writable: " . ($canWrite ? "YES &#10003;" : "NO &#10007;"), $canWrite ? 'success' : 'error');

if (!$canWrite && !$dryRun) {
    logMsg("Cannot proceed — web root is not writable!", 'error');
    goto render;
}

// Check data/ directory
if (!is_dir("$webroot/data")) {
    if (!$dryRun) {
        if (mkdir("$webroot/data", 0755, true)) {
            logMsg("Created: /data/ directory", 'success');
        } else {
            logMsg("FAILED to create /data/ directory!", 'error');
            goto render;
        }
    } else {
        logMsg("Would create: /data/ directory");
    }
} else {
    logMsg("/data/ directory already exists", 'skip');
}

// Check backup directory
if (!is_dir("$webroot/backup-pre-migration")) {
    if (!$dryRun) {
        mkdir("$webroot/backup-pre-migration", 0755, true);
        logMsg("Created: /backup-pre-migration/ directory", 'success');
    } else {
        logMsg("Would create: /backup-pre-migration/ directory");
    }
}

logMsg("");

// ============================================================================
// STEP 1: BACKUP
// ============================================================================
logMsg("--- Step 1: Backup Current Files ---", 'header');

$backupFiles = [
    'email-functions.php', 'dispatch-email-functions.php',
    'statuses.json', 'vendors.json', 'vendor-tokens.json', 'vendor-sessions.json',
    'admin-users.json', 'admin-sessions.json', 'preflight-log.json',
    'reminder-config.json', 'reminder-log.json', 'order_counter.txt',
    'batches.json', 'courier-earnings.json', 'dispatch-settings.json',
    'dispatch-notifications.json', 'mtcc-locations.json'
];

foreach ($backupFiles as $f) {
    if (file_exists("$webroot/$f")) {
        safeCopy("$webroot/$f", "$webroot/backup-pre-migration/$f", $dryRun);
    }
}

// Backup files from subdirectories
if (file_exists("$webroot/uploads/payment-sessions.json")) {
    safeCopy("$webroot/uploads/payment-sessions.json", "$webroot/backup-pre-migration/uploads-payment-sessions.json", $dryRun);
}
if (file_exists("$webroot/logs/activity-log.json")) {
    safeCopy("$webroot/logs/activity-log.json", "$webroot/backup-pre-migration/logs-activity-log.json", $dryRun);
}
if (file_exists("$webroot/activity-log.json")) {
    safeCopy("$webroot/activity-log.json", "$webroot/backup-pre-migration/root-activity-log.json", $dryRun);
}
if (file_exists("$webroot/includes/email-functions.php")) {
    safeCopy("$webroot/includes/email-functions.php", "$webroot/backup-pre-migration/includes-email-functions.php", $dryRun);
}

logMsg("");

// ============================================================================
// STEP 2: EMAIL FILE RENAMES
// ============================================================================
logMsg("--- Step 2: Rename Email Files ---", 'header');

// Copy (not move) first, then we'll delete originals after references are updated
safeCopy("$webroot/email-functions.php", "$webroot/email-order-confirmation.php", $dryRun);
safeCopy("$webroot/dispatch-email-functions.php", "$webroot/email-status-notifications.php", $dryRun);

// Update header comment in email-status-notifications.php
if (!$dryRun && file_exists("$webroot/email-status-notifications.php")) {
    $content = file_get_contents("$webroot/email-status-notifications.php");
    $content = str_replace(
        'Location: /dispatch-email-functions.php (root directory)',
        "Location: /email-status-notifications.php (root directory)\n * Renamed from: dispatch-email-functions.php",
        $content
    );
    file_put_contents("$webroot/email-status-notifications.php", $content);
}

// Update header comment in email-order-confirmation.php
if (!$dryRun && file_exists("$webroot/email-order-confirmation.php")) {
    $content = file_get_contents("$webroot/email-order-confirmation.php");
    $content = str_replace(
        "Email Functions for Print Stuff\n * Handles order confirmation and notification emails",
        "Email Order Confirmation Functions for Print Stuff\n * Handles order confirmation and resend notification emails\n * Location: /email-order-confirmation.php (root directory)\n * Renamed from: email-functions.php",
        $content
    );
    file_put_contents("$webroot/email-order-confirmation.php", $content);
}

logMsg("");

// ============================================================================
// STEP 3: UPDATE EMAIL REFERENCES
// ============================================================================
logMsg("--- Step 3: Update Email File References ---", 'header');

// email-functions.php → email-order-confirmation.php
safeReplace("$webroot/admin-create-order.php",
    "require_once 'includes/email-functions.php'",
    "require_once 'email-order-confirmation.php'",
    "email path update", $dryRun);

safeReplace("$webroot/admin-orders.php",
    "require_once 'includes/email-functions.php'",
    "require_once 'email-order-confirmation.php'",
    "email path update", $dryRun);

safeReplace("$webroot/admin-bulk-upload.php",
    "require_once __DIR__ . '/email-functions.php'",
    "require_once __DIR__ . '/email-order-confirmation.php'",
    "email path update", $dryRun);

// dispatch-email-functions.php → email-status-notifications.php
safeReplace("$webroot/includes/utilities.php",
    "dispatch-email-functions.php",
    "email-status-notifications.php",
    "dispatch email path update", $dryRun);

safeReplace("$webroot/send-payment-link.php",
    "dispatch-email-functions.php",
    "email-status-notifications.php",
    "dispatch email path update", $dryRun);

// dispatch/api.php (deployed as api.php in dispatch/)
safeReplace("$webroot/dispatch/api.php",
    "dispatch-email-functions.php",
    "email-status-notifications.php",
    "dispatch email path update", $dryRun);

// Delete old email files and includes copy
safeDelete("$webroot/email-functions.php", $dryRun);
safeDelete("$webroot/dispatch-email-functions.php", $dryRun);
safeDelete("$webroot/includes/email-functions.php", $dryRun);

logMsg("");

// ============================================================================
// STEP 4: MOVE JSON/DATA FILES TO /data/
// ============================================================================
logMsg("--- Step 4: Move Data Files to /data/ ---", 'header');

$rootDataFiles = [
    'statuses.json', 'vendors.json', 'vendor-tokens.json', 'vendor-sessions.json',
    'admin-users.json', 'admin-sessions.json', 'preflight-log.json',
    'reminder-config.json', 'reminder-log.json', 'order_counter.txt',
    'batches.json', 'courier-earnings.json', 'dispatch-settings.json',
    'dispatch-notifications.json', 'mtcc-locations.json'
];

foreach ($rootDataFiles as $f) {
    safeMove("$webroot/$f", "$webroot/data/$f", $dryRun);
}

// payment-sessions.json from uploads/
safeMove("$webroot/uploads/payment-sessions.json", "$webroot/data/payment-sessions.json", $dryRun);

// activity-log.json - check logs/ first, then root
if (file_exists("$webroot/logs/activity-log.json")) {
    safeMove("$webroot/logs/activity-log.json", "$webroot/data/activity-log.json", $dryRun);
} elseif (file_exists("$webroot/activity-log.json")) {
    safeMove("$webroot/activity-log.json", "$webroot/data/activity-log.json", $dryRun);
} else {
    logMsg("activity-log.json not found in logs/ or root", 'warning');
}

logMsg("");

// ============================================================================
// STEP 5: UPDATE ALL JSON PATH REFERENCES
// ============================================================================
logMsg("--- Step 5: Update JSON Paths in PHP Files ---", 'header');

// ---- ROOT DIRECTORY FILES ----
logMsg("Root directory files:", 'subheader');

// admin-orders.php — bare relative path 'statuses.json'
safeReplace("$webroot/admin-orders.php",
    "'statuses.json'", "'data/statuses.json'",
    "statuses.json → data/", $dryRun);

// payment-actions.php
safeReplace("$webroot/payment-actions.php",
    "'statuses.json'", "'data/statuses.json'",
    "statuses.json → data/", $dryRun);

// status.php
safeReplace("$webroot/status.php",
    "'statuses.json'", "'data/statuses.json'",
    "statuses.json → data/", $dryRun);

// stripe-webhook.php
safeReplace("$webroot/stripe-webhook.php",
    "__DIR__ . '/statuses.json'", "__DIR__ . '/data/statuses.json'",
    "statuses.json → data/", $dryRun);
safeReplace("$webroot/stripe-webhook.php",
    "__DIR__ . '/order_counter.txt'", "__DIR__ . '/data/order_counter.txt'",
    "order_counter → data/", $dryRun);

// upload-order.php
safeReplace("$webroot/upload-order.php",
    "__DIR__ . '/statuses.json'", "__DIR__ . '/data/statuses.json'",
    "statuses.json → data/", $dryRun);
safeReplace("$webroot/upload-order.php",
    "__DIR__ . '/order_counter.txt'", "__DIR__ . '/data/order_counter.txt'",
    "order_counter → data/", $dryRun);

// payment-success.php
safeReplace("$webroot/payment-success.php",
    "__DIR__ . '/statuses.json'", "__DIR__ . '/data/statuses.json'",
    "statuses.json → data/", $dryRun);
safeReplace("$webroot/payment-success.php",
    "__DIR__ . '/order_counter.txt'", "__DIR__ . '/data/order_counter.txt'",
    "order_counter → data/", $dryRun);
safeReplace("$webroot/payment-success.php",
    "__DIR__ . '/uploads/payment-sessions.json'", "__DIR__ . '/data/payment-sessions.json'",
    "payment-sessions → data/", $dryRun);

// send-payment-link.php
safeReplace("$webroot/send-payment-link.php",
    "__DIR__ . '/statuses.json'", "__DIR__ . '/data/statuses.json'",
    "statuses.json → data/", $dryRun);
safeReplace("$webroot/send-payment-link.php",
    "__DIR__ . '/uploads/payment-sessions.json'", "__DIR__ . '/data/payment-sessions.json'",
    "payment-sessions → data/", $dryRun);

// sync-order-statuses.php
safeReplace("$webroot/sync-order-statuses.php",
    "__DIR__ . '/statuses.json'", "__DIR__ . '/data/statuses.json'",
    "statuses.json → data/", $dryRun);

// admin-auth.php
safeReplace("$webroot/admin-auth.php",
    "__DIR__ . '/admin-users.json'", "__DIR__ . '/data/admin-users.json'",
    "admin-users → data/", $dryRun);
safeReplace("$webroot/admin-auth.php",
    "__DIR__ . '/admin-sessions.json'", "__DIR__ . '/data/admin-sessions.json'",
    "admin-sessions → data/", $dryRun);
safeReplace("$webroot/admin-auth.php",
    "__DIR__ . '/logs/activity-log.json'", "__DIR__ . '/data/activity-log.json'",
    "activity-log → data/", $dryRun);

// vendor-portal.php
safeReplace("$webroot/vendor-portal.php",
    "'vendor-tokens.json'", "'data/vendor-tokens.json'",
    "vendor-tokens → data/", $dryRun);
safeReplace("$webroot/vendor-portal.php",
    "'vendors.json'", "'data/vendors.json'",
    "vendors → data/", $dryRun);

// vendor-download.php
safeReplace("$webroot/vendor-download.php",
    "'vendor-tokens.json'", "'data/vendor-tokens.json'",
    "vendor-tokens → data/", $dryRun);

// admin-bulk-upload.php — activity-log
safeReplace("$webroot/admin-bulk-upload.php",
    "__DIR__ . '/activity-log.json'", "__DIR__ . '/data/activity-log.json'",
    "activity-log → data/", $dryRun);

// admin-create-order.php — JS fetch for order_counter
safeReplace("$webroot/admin-create-order.php",
    "fetch('order_counter.txt')", "fetch('data/order_counter.txt')",
    "order_counter JS fetch → data/", $dryRun);

// send-reminders.php — uses $basePath . 'filename'
safeReplace("$webroot/send-reminders.php",
    "\$basePath . 'reminder-config.json'", "\$basePath . 'data/reminder-config.json'",
    "reminder-config → data/", $dryRun);
safeReplace("$webroot/send-reminders.php",
    "\$basePath . 'reminder-log.json'", "\$basePath . 'data/reminder-log.json'",
    "reminder-log → data/", $dryRun);
safeReplace("$webroot/send-reminders.php",
    "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'",
    "preflight-log → data/", $dryRun);
safeReplace("$webroot/send-reminders.php",
    "\$basePath . 'statuses.json'", "\$basePath . 'data/statuses.json'",
    "statuses → data/", $dryRun);
safeReplace("$webroot/send-reminders.php",
    "\$basePath . 'vendors.json'", "\$basePath . 'data/vendors.json'",
    "vendors → data/", $dryRun);
safeReplace("$webroot/send-reminders.php",
    "\$basePath . 'vendor-tokens.json'", "\$basePath . 'data/vendor-tokens.json'",
    "vendor-tokens → data/", $dryRun);

logMsg("");

// ---- INCLUDES DIRECTORY ----
logMsg("includes/ directory files:", 'subheader');

safeReplace("$webroot/includes/utilities.php",
    "'statuses.json'", "'data/statuses.json'",
    "statuses → data/", $dryRun);

safeReplace("$webroot/includes/refund-utilities.php",
    "'statuses.json'", "'data/statuses.json'",
    "statuses → data/", $dryRun);

safeReplace("$webroot/includes/analytics-calculations.php",
    "'statuses.json'", "'data/statuses.json'",
    "statuses → data/", $dryRun);

safeReplace("$webroot/includes/admin-order-handlers.php",
    "__DIR__ . '/../statuses.json'", "__DIR__ . '/../data/statuses.json'",
    "statuses → data/", $dryRun);
safeReplace("$webroot/includes/admin-order-handlers.php",
    "__DIR__ . '/../order_counter.txt'", "__DIR__ . '/../data/order_counter.txt'",
    "order_counter → data/", $dryRun);

// production-order-card.php — uses $basePath
safeReplace("$webroot/admin/production-order-card.php",
    "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'",
    "preflight-log → data/", $dryRun);
safeReplace("$webroot/admin/production-order-card.php",
    "\$basePath . 'reminder-log.json'", "\$basePath . 'data/reminder-log.json'",
    "reminder-log → data/", $dryRun);
safeReplace("$webroot/admin/production-order-card.php",
    "\$basePath . 'vendors.json'", "\$basePath . 'data/vendors.json'",
    "vendors → data/", $dryRun);
safeReplace("$webroot/admin/production-order-card.php",
    "\$basePath . 'vendor-tokens.json'", "\$basePath . 'data/vendor-tokens.json'",
    "vendor-tokens → data/", $dryRun);

// preflight-order-card.php (if still exists)
safeReplace("$webroot/includes/preflight-order-card.php",
    "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'",
    "preflight-log → data/", $dryRun);
safeReplace("$webroot/includes/preflight-order-card.php",
    "\$basePath . 'reminder-log.json'", "\$basePath . 'data/reminder-log.json'",
    "reminder-log → data/", $dryRun);
safeReplace("$webroot/includes/preflight-order-card.php",
    "\$basePath . 'vendors.json'", "\$basePath . 'data/vendors.json'",
    "vendors → data/", $dryRun);
safeReplace("$webroot/includes/preflight-order-card.php",
    "\$basePath . 'vendor-tokens.json'", "\$basePath . 'data/vendor-tokens.json'",
    "vendor-tokens → data/", $dryRun);

logMsg("");

// ---- ADMIN DIRECTORY ----
logMsg("admin/ directory files:", 'subheader');

$adminFiles = ['production.php', 'preflight.php'];
foreach ($adminFiles as $af) {
    $path = "$webroot/admin/$af";
    safeReplace($path, "\$basePath . 'vendors.json'", "\$basePath . 'data/vendors.json'", "vendors → data/", $dryRun);
    safeReplace($path, "\$basePath . 'statuses.json'", "\$basePath . 'data/statuses.json'", "statuses → data/", $dryRun);
    safeReplace($path, "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'", "preflight-log → data/", $dryRun);
    safeReplace($path, "\$basePath . 'reminder-log.json'", "\$basePath . 'data/reminder-log.json'", "reminder-log → data/", $dryRun);
    safeReplace($path, "\$basePath . 'reminder-config.json'", "\$basePath . 'data/reminder-config.json'", "reminder-config → data/", $dryRun);
    safeReplace($path, "\$basePath . 'vendor-tokens.json'", "\$basePath . 'data/vendor-tokens.json'", "vendor-tokens → data/", $dryRun);
}

// problem-orders.php
$path = "$webroot/admin/problem-orders.php";
safeReplace($path, "\$basePath . 'statuses.json'", "\$basePath . 'data/statuses.json'", "statuses → data/", $dryRun);
safeReplace($path, "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'", "preflight-log → data/", $dryRun);
safeReplace($path, "\$basePath . 'reminder-log.json'", "\$basePath . 'data/reminder-log.json'", "reminder-log → data/", $dryRun);
safeReplace($path, "\$basePath . 'vendors.json'", "\$basePath . 'data/vendors.json'", "vendors → data/", $dryRun);
safeReplace($path, "\$basePath . 'vendor-tokens.json'", "\$basePath . 'data/vendor-tokens.json'", "vendor-tokens → data/", $dryRun);

// production-analytics.php — uses ../ relative paths
$path = "$webroot/admin/production-analytics.php";
safeReplace($path, "'../preflight-log.json'", "'../data/preflight-log.json'", "preflight-log → data/", $dryRun);
safeReplace($path, "'../reminder-log.json'", "'../data/reminder-log.json'", "reminder-log → data/", $dryRun);
safeReplace($path, "'../statuses.json'", "'../data/statuses.json'", "statuses → data/", $dryRun);
safeReplace($path, "'../vendors.json'", "'../data/vendors.json'", "vendors → data/", $dryRun);
safeReplace($path, "'../vendor-tokens.json'", "'../data/vendor-tokens.json'", "vendor-tokens → data/", $dryRun);

logMsg("");

// ---- FULFILLMENT DIRECTORY ----
logMsg("fulfillment/ directory files:", 'subheader');

safeReplace("$webroot/fulfillment/dashboard.php",
    "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'",
    "preflight-log → data/", $dryRun);
safeReplace("$webroot/fulfillment/dashboard.php",
    "\$basePath . 'statuses.json'", "\$basePath . 'data/statuses.json'",
    "statuses → data/", $dryRun);

safeReplace("$webroot/fulfillment/vendor-auth.php",
    "__DIR__ . '/../vendors.json'", "__DIR__ . '/../data/vendors.json'",
    "vendors → data/", $dryRun);
safeReplace("$webroot/fulfillment/vendor-auth.php",
    "__DIR__ . '/../vendor-sessions.json'", "__DIR__ . '/../data/vendor-sessions.json'",
    "vendor-sessions → data/", $dryRun);

safeReplace("$webroot/fulfillment/api.php",
    "\$basePath . 'preflight-log.json'", "\$basePath . 'data/preflight-log.json'",
    "preflight-log → data/", $dryRun);
safeReplace("$webroot/fulfillment/api.php",
    "\$basePath . 'statuses.json'", "\$basePath . 'data/statuses.json'",
    "statuses → data/", $dryRun);
safeReplace("$webroot/fulfillment/api.php",
    "\$basePath . 'activity-log.json'", "\$basePath . 'data/activity-log.json'",
    "activity-log → data/", $dryRun);

logMsg("");

// ---- DISPATCH DIRECTORY ----
logMsg("dispatch/ directory files:", 'subheader');

safeReplace("$webroot/dispatch/api.php",
    "__DIR__ . '/../statuses.json'", "__DIR__ . '/../data/statuses.json'",
    "statuses → data/", $dryRun);


logMsg("");

// ---- LOGS DIRECTORY ----
logMsg("logs/ directory files:", 'subheader');

safeReplace("$webroot/logs/index.php",
    "__DIR__ . '/activity-log.json'", "__DIR__ . '/../data/activity-log.json'",
    "activity-log primary path → data/", $dryRun);
safeReplace("$webroot/logs/index.php",
    "__DIR__ . '/../activity-log.json'", "__DIR__ . '/../data/activity-log.json'",
    "activity-log fallback 1 → data/", $dryRun);
safeReplace("$webroot/logs/index.php",
    "__DIR__ . '/../logs/activity-log.json'", "__DIR__ . '/../data/activity-log.json'",
    "activity-log fallback 2 → data/", $dryRun);

logMsg("");

// ---- REPORTS DIRECTORY ----
logMsg("reports/ directory files:", 'subheader');

safeReplace("$webroot/reports/export.php",
    "'../statuses.json'", "'../data/statuses.json'",
    "statuses → data/", $dryRun);
safeReplace("$webroot/reports/index.php",
    "'../statuses.json'", "'../data/statuses.json'",
    "statuses → data/", $dryRun);

logMsg("");

// ============================================================================
// STEP 6: CREATE .htaccess FOR /data/ SECURITY
// ============================================================================
logMsg("--- Step 6: Secure /data/ Directory ---", 'header');

$htaccessContent = '# Deny all direct web access to data files
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Exception: allow order_counter.txt for admin JS fetch
<Files "order_counter.txt">
    <IfModule mod_authz_core.c>
        Require all granted
    </IfModule>
    <IfModule !mod_authz_core.c>
        Allow from all
    </IfModule>
</Files>
';

if ($dryRun) {
    logMsg("Would create: data/.htaccess (blocks direct web access, allows order_counter.txt)");
} else {
    if (file_put_contents("$webroot/data/.htaccess", $htaccessContent)) {
        logMsg("Created: data/.htaccess", 'success');
    } else {
        logMsg("FAILED to create data/.htaccess", 'error');
    }
}

logMsg("");

// ============================================================================
// STEP 7: VERIFICATION
// ============================================================================
logMsg("--- Step 7: Verification ---", 'header');

if (!$dryRun) {
    // Check data directory contents
    if (is_dir("$webroot/data")) {
        $dataFiles = scandir("$webroot/data");
        $dataFiles = array_filter($dataFiles, fn($f) => $f !== '.' && $f !== '..');
        logMsg("Files in /data/: " . count($dataFiles));
        foreach ($dataFiles as $f) {
            $size = filesize("$webroot/data/$f");
            logMsg("  &#10003; $f (" . number_format($size) . " bytes)", 'success');
        }
    }
    
    logMsg("");
    
    // Check for any remaining old references
    $phpFiles = array_merge(
        glob("$webroot/*.php"),
        glob("$webroot/includes/*.php"),
        glob("$webroot/admin/*.php"),
        glob("$webroot/fulfillment/*.php"),
        glob("$webroot/dispatch/*.php"),
        glob("$webroot/logs/*.php"),
        glob("$webroot/reports/*.php")
    );
    
    $leftover = [];
    foreach ($phpFiles as $file) {
        if (strpos($file, 'backup-pre-migration') !== false) continue;
        if (strpos($file, 'migrate.php') !== false) continue;
        
        $content = file_get_contents($file);
        
        // Check for old email references
        if (preg_match('/[\'"].*email-functions\.php[\'"]/', $content) && 
            strpos($file, 'email-order-confirmation') === false) {
            $leftover[] = basename($file) . " → still references email-functions.php";
        }
        if (preg_match('/dispatch-email-functions\.php/', $content) &&
            strpos($file, 'email-status-notifications') === false) {
            $leftover[] = basename($file) . " → still references dispatch-email-functions.php";
        }
    }
    
    if (empty($leftover)) {
        logMsg("&#10003; No leftover old references found!", 'success');
    } else {
        foreach ($leftover as $l) {
            logMsg("&#9888; $l", 'warning');
        }
    }
} else {
    logMsg("Verification skipped in dry run mode");
}

// ============================================================================
// RENDER OUTPUT
// ============================================================================
render:
?>
<!DOCTYPE html>
<html>
<head>
    <title>MTCC Migration Tool</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 20px; }
        .container { max-width: 900px; margin: 0 auto; }
        
        h1 { color: #1e293b; font-size: 24px; margin-bottom: 8px; }
        .subtitle { color: #64748b; font-size: 14px; margin-bottom: 24px; }
        
        .controls { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        
        .btn { padding: 12px 24px; font-size: 14px; font-weight: 600; border: none; border-radius: 8px; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn-preview { background: #e0e7ff; color: #4338ca; }
        .btn-preview:hover { background: #c7d2fe; }
        .btn-execute { background: #7c3aed; color: white; }
        .btn-execute:hover { background: #6d28d9; }
        .btn-danger { background: #fecaca; color: #991b1b; font-size: 12px; padding: 8px 16px; }
        .btn-danger:hover { background: #fca5a5; }
        
        .status-bar { padding: 12px 20px; border-radius: 8px; margin-bottom: 16px; font-weight: 600; font-size: 14px; }
        .status-dryrun { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .status-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .status-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        
        .log-section { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; margin-bottom: 24px; }
        .log-header { padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #334155; }
        .log-body { padding: 8px 0; max-height: 600px; overflow-y: auto; }
        
        .log-line { padding: 6px 20px; font-size: 13px; font-family: 'SF Mono', Monaco, Consolas, monospace; border-bottom: 1px solid #f8fafc; line-height: 1.5; }
        .log-line:hover { background: #f8fafc; }
        
        .log-info { color: #475569; }
        .log-success { color: #059669; background: #f0fdf4; }
        .log-warning { color: #d97706; background: #fffbeb; }
        .log-error { color: #dc2626; background: #fef2f2; font-weight: 600; }
        .log-header-line { color: #7c3aed; font-weight: 700; font-size: 14px; padding: 12px 20px; background: #faf5ff; border-bottom: 1px solid #ede9fe; }
        .log-subheader { color: #6366f1; font-weight: 600; padding-left: 30px; }
        .log-skip { color: #94a3b8; font-style: italic; }
        
        .summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .summary-card { background: white; padding: 20px; border-radius: 12px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .summary-number { font-size: 32px; font-weight: 700; }
        .summary-label { font-size: 12px; color: #64748b; margin-top: 4px; }
        .num-success { color: #059669; }
        .num-warning { color: #d97706; }
        .num-error { color: #dc2626; }
        
        .warning-box { background: #fef3c7; border: 1px solid #fde68a; border-radius: 8px; padding: 16px; margin-bottom: 24px; }
        .warning-box h3 { color: #92400e; font-size: 14px; margin-bottom: 8px; }
        .warning-box p { color: #78350f; font-size: 13px; line-height: 1.5; }
    </style>
</head>
<body>

<div class="container">
    <h1>&#128295; MTCC Data Migration Tool</h1>
    <div class="subtitle">Email renames + JSON consolidation to /data/ folder</div>
    
    <!-- Summary Cards -->
    <div class="summary">
        <div class="summary-card">
            <div class="summary-number num-success"><?= count(array_filter($log, fn($l) => $l['type'] === 'success')) ?></div>
            <div class="summary-label">Successful</div>
        </div>
        <div class="summary-card">
            <div class="summary-number num-warning"><?= count($warnings) ?></div>
            <div class="summary-label">Warnings</div>
        </div>
        <div class="summary-card">
            <div class="summary-number num-error"><?= count($errors) ?></div>
            <div class="summary-label">Errors</div>
        </div>
    </div>
    
    <!-- Status -->
    <?php if ($dryRun): ?>
        <div class="status-bar status-dryrun">
            &#128065;️ DRY RUN — Preview mode. No files were changed.
        </div>
    <?php elseif (count($errors) === 0): ?>
        <div class="status-bar status-success">
            &#9989; Migration completed successfully!
        </div>
    <?php else: ?>
        <div class="status-bar status-error">
            &#10060; Migration completed with <?= count($errors) ?> error(s). Check the log below.
        </div>
    <?php endif; ?>
    
    <!-- Controls -->
    <div class="controls">
        <a href="?mode=preview" class="btn btn-preview">&#128065;️ Run Dry Run</a>
        
        <?php if ($dryRun): ?>
            <a href="?mode=execute" class="btn btn-execute" 
               onclick="return confirm('This will move files and update paths. A backup will be created first. Continue?')">
                &#128640; Run Migration For Real
            </a>
        <?php endif; ?>
        
        <div style="flex-grow: 1;"></div>
        <span style="color: #94a3b8; font-size: 12px;">
            After migration is done, delete this file!
        </span>
    </div>
    
    <?php if (!$dryRun && count($errors) === 0): ?>
    <div class="warning-box">
        <h3>&#9888;️ Important Next Steps</h3>
        <p>
            1. <strong>Test these pages now:</strong> Order form, Admin dashboard, Production page, Dispatch scanner, Vendor portal, Payment flow, Status check<br>
            2. <strong>Delete this file</strong> (migrate.php) — it's a security risk if left on the server<br>
            3. Backup folder saved at: <code>/backup-pre-migration/</code>
        </p>
    </div>
    <?php endif; ?>
    
    <!-- Log Output -->
    <div class="log-section">
        <div class="log-header">Migration Log (<?= count($log) ?> entries)</div>
        <div class="log-body">
            <?php foreach ($log as $entry): ?>
                <?php
                $class = 'log-info';
                switch ($entry['type']) {
                    case 'success': $class = 'log-success'; break;
                    case 'warning': $class = 'log-warning'; break;
                    case 'error': $class = 'log-error'; break;
                    case 'header': $class = 'log-header-line'; break;
                    case 'subheader': $class = 'log-subheader'; break;
                    case 'skip': $class = 'log-skip'; break;
                }
                ?>
                <div class="log-line <?= $class ?>"><?= htmlspecialchars($entry['msg']) ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

</body>
</html>
<?php
