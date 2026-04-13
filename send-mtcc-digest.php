<?php
/**
 * MTCC Daily Digest Email
 * Sends a daily summary to MTCC staff with order counts, revenue, and commission.
 *
 * Cron: 0 18 * * * php /path/to/send-mtcc-digest.php
 * (Daily at 6 PM ET, after business hours)
 *
 * Location: /send-mtcc-digest.php (root directory)
 */

date_default_timezone_set('America/Toronto');

require_once __DIR__ . '/includes/email-template.php';
require_once __DIR__ . '/includes/site-settings.php';
require_once __DIR__ . '/includes/status-config.php';
require_once __DIR__ . '/email-status-notifications.php'; // for sendEmailSMTP

// Load settings
$settings = getSiteSettings();
$recipientEmail = $settings['mtcc_contact_email'] ?? '';
$digestEnabled = $settings['mtcc_digest_enabled'] ?? false;
$feeRate = $settings['mtcc_venue_fee_rate'] ?? 0.10;
$feeRatePct = round($feeRate * 100);

if (!$digestEnabled || !$recipientEmail) {
    error_log('MTCC Digest: Disabled or no recipient email configured');
    exit;
}

// Load statuses
$statusFile = __DIR__ . '/data/statuses.json';
$statuses = file_exists($statusFile) ? (json_decode(file_get_contents($statusFile), true) ?: []) : [];

// Load events
$eventsFile = __DIR__ . '/admin/events.json';
$eventsData = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
$activeEvents = $eventsData['active'] ?? [];
$activeAcronyms = array_map(function($e) { return strtoupper($e['acronym'] ?? ''); }, $activeEvents);

// Load orders
$revenueStatuses = ['paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];
$orderDir = __DIR__ . '/uploads/orders/';
$orders = [];

if (is_dir($orderDir)) {
    $files = glob($orderDir . '*.json');
    foreach ($files as $file) {
        if (strpos($file, '_history.json') !== false) continue;
        $order = json_decode(file_get_contents($file), true);
        if (!$order) continue;
        $ref = $order['referenceCode'] ?? '';
        $order['status'] = $statuses[$ref] ?? ($order['status'] ?? 'unpaid');
        $orders[] = $order;
    }
}

// Calculate today's metrics
$todayStr = date('Y-m-d');
$monthStart = date('Y-m-01');

$todayOrders = 0; $todayBase = 0;
$monthOrders = 0; $monthBase = 0;
$readyForPickup = 0; $pickedUpToday = 0;
$eventBreakdown = [];
$feeRatePct = round($feeRate * 100);

foreach ($orders as $o) {
    $status = $o['status'] ?? 'unpaid';
    if (!in_array($status, $revenueStatuses)) continue;

    $submitted = isset($o['submittedAt']) ? date('Y-m-d', strtotime($o['submittedAt'])) : '';
    $base = $o['pricing']['basePrice'] ?? 0;
    $prefix = strtoupper(explode('-', $o['referenceCode'] ?? '')[0]);

    // Only count active events in digest
    if (!in_array($prefix, $activeAcronyms)) continue;

    if ($submitted === $todayStr) { $todayOrders++; $todayBase += $base; }
    if ($submitted >= $monthStart) { $monthOrders++; $monthBase += $base; }

    if ($status === 'delivered') $readyForPickup++;
    if ($status === 'pickedup') $pickedUpToday++;

    // Per-event breakdown
    if (!isset($eventBreakdown[$prefix])) {
        $evName = $prefix;
        foreach ($activeEvents as $ev) {
            if (strtoupper($ev['acronym'] ?? '') === $prefix) { $evName = $ev['name'] ?? $prefix; break; }
        }
        $eventBreakdown[$prefix] = ['name' => $evName, 'today_orders' => 0, 'today_base' => 0];
    }
    if ($submitted === $todayStr) {
        $eventBreakdown[$prefix]['today_orders']++;
        $eventBreakdown[$prefix]['today_base'] += $base;
    }
}

$todayFee = $todayBase * $feeRate;
$monthFee = $monthBase * $feeRate;

// Skip if no activity today
if ($todayOrders === 0 && $readyForPickup === 0 && $pickedUpToday === 0) {
    error_log('MTCC Digest: No activity today, skipping');
    exit;
}

// Build email body
$body = '';

// Today's summary
$body .= emailSummaryBox(
    emailDetailRow('New Orders', $todayOrders)
    . emailDetailRow('Base Revenue', '$' . number_format($todayBase, 2))
    . emailDetailRow('Venue Fee (' . $feeRatePct . '%)', '$' . number_format($todayFee, 2), true),
    "Today's Activity"
);

// Operational snapshot
$body .= emailSummaryBox(
    emailDetailRow('Ready for Pickup', $readyForPickup)
    . emailDetailRow('Picked Up Today', $pickedUpToday),
    'Pickup Status'
);

// Per-event breakdown (if multiple events active)
if (count($eventBreakdown) > 1) {
    $eventRows = '';
    foreach ($eventBreakdown as $prefix => $ev) {
        if ($ev['today_orders'] === 0) continue;
        $eventRows .= emailDetailRow($ev['name'], $ev['today_orders'] . ' orders · $' . number_format($ev['today_base'], 2));
    }
    if ($eventRows) {
        $body .= emailSummaryBox($eventRows, 'By Event');
    }
}

// Month-to-date
$body .= emailCalloutPurple(
    'Month-to-Date Venue Fee',
    '<strong style="font-size: 18px;">$' . number_format($monthFee, 2) . '</strong><br>'
    . $monthOrders . ' orders &middot; $' . number_format($monthBase, 2) . ' base revenue'
);

// CTA
$body .= emailButton('View Dashboard', 'https://mtcc.print-stuff.ca/admin-orders.php', EMAIL_BTN_PRIMARY);

// Build the email
$html = emailTemplate(
    EMAIL_BAND_INFO, 'Daily Summary',
    'Today at MTCC', EMAIL_HEADING_INFO,
    date('l, F j, Y'),
    $body
);

// Send
$subject = 'MTCC Daily Summary - ' . date('M j') . ': ' . $todayOrders . ' orders, $' . number_format($todayFee, 2) . ' venue fee';

$sent = false;
if (function_exists('sendEmailSMTP')) {
    $sent = sendEmailSMTP($recipientEmail, $subject, $html, 'MTCC-DIGEST-' . date('Y-m-d'), 'orders');
} else {
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Print Stuff Orders <orders@printstuff.ca>\r\n";
    $sent = mail($recipientEmail, $subject, $html, $headers);
}

if ($sent) {
    error_log('MTCC Digest: Sent to ' . $recipientEmail . ' (' . $todayOrders . ' orders, $' . number_format($todayCommission, 2) . ' commission)');
} else {
    error_log('MTCC Digest: FAILED to send to ' . $recipientEmail);
}
