<?php
/**
 * Order Status Sync Verification
 * MTCC Print Services
 *
 * Location: /sync-order-statuses.php
 *
 * Compares statuses.json against individual order JSON files.
 * Fixes any drift by treating statuses.json as the source of truth.
 * Logs all corrections to order history.
 *
 * Usage:
 *   - Cron: Run nightly via cPanel cron job
 *     php /home1/stuffprint/mtcc.print-stuff.ca/sync-order-statuses.php
 *   - Browser: https://mtcc.print-stuff.ca/sync-order-statuses.php
 *     (requires admin login)
 *   - CLI: php sync-order-statuses.php
 *
 * Output: JSON report of drift found and corrections applied.
 */

// Timezone
date_default_timezone_set('America/Toronto');

// Detect CLI vs web
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    // Web access requires admin login
    session_start();
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin login required']);
        exit;
    }
    header('Content-Type: application/json');
}

// Load data access layer
require_once __DIR__ . '/includes/data-access.php';

// Configuration
$statusFile = __DIR__ . '/data/statuses.json';
$ordersDir = __DIR__ . '/uploads/orders/';

// Load statuses.json (source of truth)
$statuses = loadStatuses($statusFile);

// Scan all order files
$orderFiles = glob($ordersDir . '*-order.json');

$report = [
    'timestamp' => date('c'),
    'total_orders_scanned' => 0,
    'total_in_statuses_json' => count($statuses),
    'drift_found' => 0,
    'corrections_applied' => 0,
    'missing_from_statuses' => 0,
    'details' => [],
];

foreach ($orderFiles as $file) {
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['referenceCode'])) continue;

    $ref = $data['referenceCode'];
    $report['total_orders_scanned']++;

    $fileStatus = $data['status'] ?? null;
    $jsonStatus = $statuses[$ref] ?? null;

    // Case 1: Order exists in file but not in statuses.json
    if ($jsonStatus === null) {
        $report['missing_from_statuses']++;
        $defaultStatus = $fileStatus ?: 'unpaid';
        $statuses[$ref] = $defaultStatus;
        $report['details'][] = [
            'ref' => $ref,
            'issue' => 'missing_from_statuses_json',
            'file_status' => $fileStatus,
            'action' => "Added to statuses.json as '$defaultStatus'",
        ];
        logOrderHistory($ref, 'sync_correction', "Added to statuses.json (was missing). Status: $defaultStatus", 'System (sync)');
        continue;
    }

    // Case 2: Statuses differ between statuses.json and order file
    if ($fileStatus !== null && $fileStatus !== $jsonStatus) {
        $report['drift_found']++;
        // Trust statuses.json — update order file
        $data['status'] = $jsonStatus;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        $report['corrections_applied']++;
        $report['details'][] = [
            'ref' => $ref,
            'issue' => 'status_drift',
            'statuses_json' => $jsonStatus,
            'order_file' => $fileStatus,
            'action' => "Order file corrected: '$fileStatus' -> '$jsonStatus'",
        ];
        logOrderHistory($ref, 'sync_correction', "Status drift corrected: order file had '$fileStatus', statuses.json had '$jsonStatus'. File updated to match.", 'System (sync)');
    }
}

// Save any additions to statuses.json
if ($report['missing_from_statuses'] > 0) {
    saveStatuses($statuses, $statusFile);
}

// Output
$output = json_encode($report, JSON_PRETTY_PRINT);

if ($isCLI) {
    echo $output . "\n";
    if ($report['drift_found'] > 0 || $report['missing_from_statuses'] > 0) {
        echo "\n** ISSUES FOUND: {$report['drift_found']} drift, {$report['missing_from_statuses']} missing **\n";
    } else {
        echo "\nAll {$report['total_orders_scanned']} orders in sync.\n";
    }
} else {
    echo $output;
}
