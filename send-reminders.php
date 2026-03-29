#!/usr/bin/env php
<?php
/**
 * Automated Reminder System - MTCC Poster System
 * Cron-compatible script to send reminder emails for unconfirmed vendor orders
 * 
 * Usage:
 * php send-reminders.php # Normal run
 * php send-reminders.php --dry-run # Test mode (no emails sent)
 * php send-reminders.php --verbose # Verbose output
 * php send-reminders.php --force # Ignore time restrictions
 * 
 * Cron Example (run every 30 minutes):
 * */30 * * * * cd /path/to/site && php send-reminders.php >> /var/log/reminders.log 2>&1
 * 
 * Location: / (root directory)
 */

// Set timezone
date_default_timezone_set('America/Toronto');

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv ?? []);
$verbose = in_array('--verbose', $argv ?? []);
$force = in_array('--force', $argv ?? []);

// Paths
$basePath = __DIR__ . '/';
$configFile = $basePath . 'data/reminder-config.json';
$logFile = $basePath . 'data/reminder-log.json';
$preflightLogFile = $basePath . 'data/preflight-log.json';
$vendorsFile = $basePath . 'data/vendors.json';
$tokensFile = $basePath . 'data/vendor-tokens.json';
$ordersDir = $basePath . 'uploads/orders/';
$statusesFile = $basePath . 'data/statuses.json';

// ============================================
// LOGGING FUNCTIONS
// ============================================
function logMessage($message, $level = 'INFO') {
 global $verbose;
 $timestamp = date('Y-m-d H:i:s');
 $line = "[{$timestamp}] [{$level}] {$message}";
 
 if ($verbose || $level === 'ERROR') {
 echo $line . PHP_EOL;
 }
 
 // Also log to file
 error_log($line, 3, __DIR__ . '/reminder-debug.log');
}

function loadJson($file) {
 if (!file_exists($file)) {
 return null;
 }
 $content = file_get_contents($file);
 return json_decode($content, true);
}

function saveJson($file, $data) {
 $data['metadata']['updated_at'] = date('c');
 return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// ============================================
// LOAD CONFIGURATION
// ============================================
logMessage("Starting reminder check...");

$config = loadJson($configFile);
if (!$config) {
 logMessage("Config file not found, creating default", 'WARN');
 $config = [
 'settings' => [
 'enabled' => true,
 'reminder_thresholds_hours' => [2, 4, 8],
 'max_reminders_per_order' => 3,
 'min_hours_between_reminders' => 2
 ],
 'email_settings' => [
 'from_name' => 'Print Stuff Orders',
 'from_email' => 'orders@printstuff.ca'
 ],
 'metadata' => ['created_at' => date('c')]
 ];
 saveJson($configFile, $config);
}

// Check if enabled
if (!$config['settings']['enabled'] && !$force) {
 logMessage("Reminders are disabled in config. Use --force to override.");
 exit(0);
}

// Check business hours if configured
if (!empty($config['settings']['business_hours_only']) && !$force) {
 $now = new DateTime();
 $start = DateTime::createFromFormat('H:i', $config['settings']['business_hours_start']);
 $end = DateTime::createFromFormat('H:i', $config['settings']['business_hours_end']);
 
 if ($now < $start || $now > $end) {
 logMessage("Outside business hours. Use --force to override.");
 exit(0);
 }
}

// Check weekends if configured
if (!empty($config['settings']['exclude_weekends']) && !$force) {
 $dayOfWeek = date('N');
 if ($dayOfWeek >= 6) { // 6 = Saturday, 7 = Sunday
 logMessage("Weekend excluded. Use --force to override.");
 exit(0);
 }
}

// ============================================
// LOAD DATA
// ============================================
$preflightLog = loadJson($preflightLogFile);
if (!$preflightLog || empty($preflightLog['entries'])) {
 logMessage("No preflight entries found.");
 exit(0);
}

$reminderLog = loadJson($logFile) ?: ['reminders' => [], 'runs' => [], 'metadata' => []];
$vendors = loadJson($vendorsFile) ?: ['vendors' => []];
$statuses = loadJson($statusesFile) ?: [];

// ============================================
// FIND ORDERS NEEDING REMINDERS
// ============================================
$settings = $config['settings'];
$thresholds = $settings['reminder_thresholds_hours'];
$maxReminders = $settings['max_reminders_per_order'];
$minHoursBetween = $settings['min_hours_between_reminders'];

$ordersToRemind = [];
$now = time();

foreach ($preflightLog['entries'] as $refCode => $entry) {
 // Skip if already confirmed
 if (!empty($entry['confirmed_at'])) {
 continue;
 }
 
 // Skip if status is not preflight
 $status = $statuses[$refCode] ?? 'unknown';
 if ($status !== 'preflight') {
 continue;
 }
 
 // Calculate time since push
 $pushedAt = strtotime($entry['pushed_at']);
 $hoursSincePush = ($now - $pushedAt) / 3600;
 
 // Get reminder history for this order
 $reminderHistory = $reminderLog['reminders'][$refCode] ?? [];
 $reminderCount = count($reminderHistory);
 
 // Skip if max reminders reached
 if ($reminderCount >= $maxReminders) {
 logMessage("Order {$refCode}: Max reminders reached ({$reminderCount}/{$maxReminders})", 'DEBUG');
 continue;
 }
 
 // Check if enough time since last reminder
 if (!empty($reminderHistory)) {
 $lastReminder = end($reminderHistory);
 $hoursSinceLastReminder = ($now - strtotime($lastReminder['sent_at'])) / 3600;
 
 if ($hoursSinceLastReminder < $minHoursBetween) {
 logMessage("Order {$refCode}: Too soon since last reminder ({$hoursSinceLastReminder}h < {$minHoursBetween}h)", 'DEBUG');
 continue;
 }
 }
 
 // Determine which threshold applies
 $applicableThreshold = null;
 foreach ($thresholds as $threshold) {
 if ($hoursSincePush >= $threshold) {
 // Check if we've already sent a reminder for this threshold
 $alreadySentForThreshold = false;
 foreach ($reminderHistory as $sent) {
 if (($sent['threshold_hours'] ?? 0) == $threshold) {
 $alreadySentForThreshold = true;
 break;
 }
 }
 
 if (!$alreadySentForThreshold) {
 $applicableThreshold = $threshold;
 }
 }
 }
 
 if ($applicableThreshold !== null) {
 $ordersToRemind[] = [
 'reference_code' => $refCode,
 'entry' => $entry,
 'hours_since_push' => round($hoursSincePush, 1),
 'threshold_hours' => $applicableThreshold,
 'reminder_number' => $reminderCount + 1
 ];
 }
}

logMessage("Found " . count($ordersToRemind) . " orders needing reminders");

if (empty($ordersToRemind)) {
 // Log the run
 $reminderLog['runs'][] = [
 'timestamp' => date('c'),
 'orders_checked' => count($preflightLog['entries']),
 'reminders_sent' => 0,
 'dry_run' => $dryRun
 ];
 saveJson($logFile, $reminderLog);
 exit(0);
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function findVendor($vendorId, $vendors) {
 foreach ($vendors['vendors'] ?? [] as $v) {
 if ($v['id'] === $vendorId) {
 return $v;
 }
 }
 return null;
}

function findOrder($refCode, $ordersDir) {
 $files = glob($ordersDir . '*-order.json');
 foreach ($files as $file) {
 $data = json_decode(file_get_contents($file), true);
 if ($data && ($data['referenceCode'] ?? '') === $refCode) {
 return $data;
 }
 }
 return null;
}

function getActiveToken($refCode, $tokensFile) {
 $tokens = loadJson($tokensFile);
 if (!$tokens) return null;
 
 foreach ($tokens['tokens'] ?? [] as $token => $data) {
 if ($data['reference_code'] === $refCode && empty($data['revoked'])) {
 $createdAt = strtotime($data['created_at']);
 $expiresAt = $createdAt + (7 * 24 * 60 * 60);
 if (time() < $expiresAt) {
 return $token;
 }
 }
 }
 return null;
}

function generateReminderEmail($vendor, $order, $entry, $reminderNumber, $token = null) {
 $refCode = $order['referenceCode'];
 $dueDate = isset($order['selectedDate']) ? date('D, M j, Y', strtotime($order['selectedDate'])) : 'TBD';
 $width = $order['dimensions']['width'] ?? 0;
 $height = $order['dimensions']['height'] ?? 0;
 $material = ucfirst($order['material'] ?? 'paper');
 $tier = $order['pricing']['tier'] ?? 'standard';
 
 $portalLink = $token 
 ? "https://mtcc.print-stuff.ca/vendor-portal.php?token=" . urlencode($token)
 : null;
 
 $urgencyLevel = '';
 $urgencyColor = '#f59e0b';
 if ($reminderNumber >= 3) {
 $urgencyLevel = 'FINAL REMINDER';
 $urgencyColor = '#dc2626';
 } elseif ($reminderNumber >= 2) {
 $urgencyLevel = 'SECOND REMINDER';
 $urgencyColor = '#ea580c';
 } else {
 $urgencyLevel = 'REMINDER';
 }
 
 $html = "<!DOCTYPE html>
<html>
<head>
 <meta charset='UTF-8'>
 <title>Reminder: Print Order #{$refCode}</title>
</head>
<body style='font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f3f4f6;'>
 <table cellpadding='0' cellspacing='0' style='max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
 <tr>
 <td style='background: {$urgencyColor}; padding: 20px; text-align: center;'>
 <span style='color: white; font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>
 ⏰ {$urgencyLevel}
 </span>
 </td>
 </tr>
 
 <tr>
 <td style='background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); padding: 25px; text-align: center;'>
 <h1 style='color: white; margin: 0 0 10px 0; font-size: 20px;'>Order Awaiting Confirmation</h1>
 <div style='background: rgba(255,255,255,0.2); display: inline-block; padding: 8px 20px; border-radius: 20px;'>
 <span style='color: white; font-size: 18px; font-weight: bold;'>#{$refCode}</span>
 </div>
 </td>
 </tr>
 
 <tr>
 <td style='padding: 25px;'>
 <p style='color: #374151; margin: 0 0 20px 0; line-height: 1.6;'>
 Hi " . htmlspecialchars($vendor['contact_name'] ?? $vendor['business_name']) . ",
 </p>
 <p style='color: #374151; margin: 0 0 20px 0; line-height: 1.6;'>
 We haven't received confirmation for the print order below. Please confirm receipt so we can track production progress.
 </p>
 
 <table cellpadding='0' cellspacing='0' style='width: 100%; background: #f8fafc; border-radius: 8px; padding: 15px; margin-bottom: 20px;'>
 <tr>
 <td style='padding: 8px 0; border-bottom: 1px solid #e5e7eb;'>
 <strong style='color: #6b7280;'>Due Date:</strong>
 <span style='float: right; color: #dc2626; font-weight: 600;'>{$dueDate}</span>
 </td>
 </tr>
 <tr>
 <td style='padding: 8px 0; border-bottom: 1px solid #e5e7eb;'>
 <strong style='color: #6b7280;'>Size:</strong>
 <span style='float: right; color: #111827;'>{$width}\" × {$height}\"</span>
 </td>
 </tr>
 <tr>
 <td style='padding: 8px 0;'>
 <strong style='color: #6b7280;'>Material:</strong>
 <span style='float: right; color: #111827;'>{$material}</span>
 </td>
 </tr>
 </table>
 
 " . ($portalLink ? "
 <div style='text-align: center; margin: 25px 0;'>
 <a href='{$portalLink}' style='display: inline-block; background: #7c3aed; color: white; padding: 16px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;'>
 &#10003; View Order & Confirm
 </a>
 </div>
 " : "") . "
 
 <p style='color: #6b7280; margin: 20px 0 0 0; font-size: 13px; line-height: 1.5;'>
 If you've already started printing this order, please click the link above to confirm. If there are any issues with the order, please reply to this email.
 </p>
 </td>
 </tr>
 
 <tr>
 <td style='padding: 20px; background: #f3f4f6; text-align: center; border-top: 1px solid #e5e7eb;'>
 <p style='color: #6b7280; margin: 0 0 5px 0; font-size: 13px;'>
 Questions? Reply to this email or call (437) 882-8822
 </p>
 <p style='color: #9ca3af; margin: 0; font-size: 12px;'>
 Print Stuff • Metro Toronto Convention Centre
 </p>
 </td>
 </tr>
 </table>
</body>
</html>";

 return $html;
}

function sendReminderEmail($vendor, $html, $refCode, $reminderNumber, $config) {
 $to = $vendor['email'];
 $subject = ($reminderNumber >= 3 ? "FINAL REMINDER: " : "Reminder: ") . "Print Order #{$refCode} - Awaiting Confirmation";
 
 $headers = [
 'MIME-Version: 1.0',
 'Content-type: text/html; charset=UTF-8',
 'From: ' . $config['email_settings']['from_name'] . ' <' . $config['email_settings']['from_email'] . '>',
 'Reply-To: ' . ($config['email_settings']['reply_to'] ?? $config['email_settings']['from_email'])
 ];
 
 // CC vendor's secondary email if exists
 if (!empty($vendor['email_cc'])) {
 $headers[] = 'Cc: ' . $vendor['email_cc'];
 }
 
 // CC admin on final reminder if configured
 if ($reminderNumber >= 3 && !empty($config['email_settings']['cc_admin_on_final_reminder'])) {
 $adminEmail = $config['email_settings']['admin_email'] ?? null;
 if ($adminEmail) {
 $headers[] = 'Bcc: ' . $adminEmail;
 }
 }
 
 return mail($to, $subject, $html, implode("\r\n", $headers));
}

// ============================================
// SEND REMINDERS
// ============================================
$sentCount = 0;
$failedCount = 0;

foreach ($ordersToRemind as $orderInfo) {
 $refCode = $orderInfo['reference_code'];
 $entry = $orderInfo['entry'];
 $reminderNumber = $orderInfo['reminder_number'];
 
 logMessage("Processing order {$refCode} (Reminder #{$reminderNumber}, {$orderInfo['hours_since_push']}h since push)");
 
 // Find vendor
 $vendor = findVendor($entry['vendor_id'], $vendors);
 if (!$vendor) {
 logMessage("Vendor not found for order {$refCode}", 'ERROR');
 $failedCount++;
 continue;
 }
 
 // Find order
 $order = findOrder($refCode, $ordersDir);
 if (!$order) {
 logMessage("Order data not found for {$refCode}", 'ERROR');
 $failedCount++;
 continue;
 }
 
 // Get active token
 $token = getActiveToken($refCode, $tokensFile);
 
 // Generate email
 $html = generateReminderEmail($vendor, $order, $entry, $reminderNumber, $token);
 
 if ($dryRun) {
 logMessage("[DRY RUN] Would send reminder #{$reminderNumber} to {$vendor['email']} for order {$refCode}");
 $sentCount++;
 } else {
 // Send email
 $sent = sendReminderEmail($vendor, $html, $refCode, $reminderNumber, $config);
 
 if ($sent) {
 logMessage("Sent reminder #{$reminderNumber} to {$vendor['email']} for order {$refCode}");
 $sentCount++;
 
 // Log the reminder
 if (!isset($reminderLog['reminders'][$refCode])) {
 $reminderLog['reminders'][$refCode] = [];
 }
 $reminderLog['reminders'][$refCode][] = [
 'sent_at' => date('c'),
 'reminder_number' => $reminderNumber,
 'threshold_hours' => $orderInfo['threshold_hours'],
 'vendor_email' => $vendor['email'],
 'hours_since_push' => $orderInfo['hours_since_push']
 ];
 } else {
 logMessage("Failed to send reminder to {$vendor['email']} for order {$refCode}", 'ERROR');
 $failedCount++;
 }
 }
}

// ============================================
// LOG RUN SUMMARY
// ============================================
$reminderLog['runs'][] = [
 'timestamp' => date('c'),
 'orders_checked' => count($preflightLog['entries']),
 'orders_needing_reminder' => count($ordersToRemind),
 'reminders_sent' => $sentCount,
 'reminders_failed' => $failedCount,
 'dry_run' => $dryRun
];

// Keep only last 100 runs
if (count($reminderLog['runs']) > 100) {
 $reminderLog['runs'] = array_slice($reminderLog['runs'], -100);
}

saveJson($logFile, $reminderLog);

logMessage("Reminder run complete: {$sentCount} sent, {$failedCount} failed");
exit($failedCount > 0 ? 1 : 0);
