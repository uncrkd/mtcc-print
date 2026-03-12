<?php
require_once __DIR__ . '/includes/icons.php';
/**
 * One-Time Status Sync Script (Fixed)
 * 
 * This script reads each order JSON file, extracts the referenceCode,
 * looks up the status in statuses.json, and adds the status field to the order file.
 * 
 * Run once, then delete this file.
 */

// Configuration
$statusesFile = __DIR__ . '/data/statuses.json';
$ordersDir = __DIR__ . '/uploads/orders/';

// Track results
$results = [
    'updated' => [],
    'skipped' => [],
    'no_status' => [],
    'errors' => []
];

// Check if statuses.json exists
if (!file_exists($statusesFile)) {
    die("Error: statuses.json not found at: $statusesFile");
}

// Check if orders directory exists
if (!is_dir($ordersDir)) {
    die("Error: Orders directory not found at: $ordersDir");
}

// Load statuses.json
$statuses = json_decode(file_get_contents($statusesFile), true);
if (!$statuses) {
    die("Error: Could not parse statuses.json");
}

echo "<html><head><title>Status Sync</title>";
echo "<style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; }
    h1 { color: #1f2937; }
    .success { color: #059669; }
    .error { color: #dc2626; }
    .warning { color: #d97706; }
    .info { color: #0284c7; }
    pre { background: #f3f4f6; padding: 12px; border-radius: 8px; overflow-x: auto; max-height: 300px; font-size: 12px; }
    .summary { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 16px; border-radius: 8px; margin-top: 20px; }
    .summary.has-errors { background: #fef2f2; border-color: #fecaca; }
</style></head><body>";

echo "<h1>ðŸ”„ Order Status Sync (Fixed)</h1>";
echo "<p>Adding status field to order JSON files from <code>statuses.json</code>...</p>";

// Get all order JSON files
$files = glob($ordersDir . '*.json');

foreach ($files as $file) {
    $filename = basename($file);
    
    // Skip history files
    if (strpos($filename, '_history') !== false || strpos($filename, 'history.json') !== false) {
        continue;
    }
    
    // Load order data
    $orderData = json_decode(file_get_contents($file), true);
    if (!$orderData) {
        $results['errors'][] = "$filename - Could not parse JSON";
        continue;
    }
    
    // Get reference code from file content
    $refCode = $orderData['referenceCode'] ?? null;
    if (!$refCode) {
        $results['errors'][] = "$filename - No referenceCode in file";
        continue;
    }
    
    // Look up status in statuses.json
    $statusFromFile = $statuses[$refCode] ?? null;
    
    if (!$statusFromFile) {
        // No status found - set default to 'unpaid'
        $statusFromFile = 'unpaid';
        $results['no_status'][] = "$refCode - No status in statuses.json, defaulting to 'unpaid'";
    }
    
    // Check if already has correct status
    $currentStatus = $orderData['status'] ?? null;
    if ($currentStatus === $statusFromFile) {
        $results['skipped'][] = "$refCode (already $statusFromFile)";
        continue;
    }
    
    // Update status in order data
    $orderData['status'] = $statusFromFile;
    $orderData['statusSyncedAt'] = date('Y-m-d H:i:s');
    
    // Save order
    $saved = file_put_contents($file, json_encode($orderData, JSON_PRETTY_PRINT));
    
    if ($saved === false) {
        $results['errors'][] = "$refCode - Failed to save $filename";
    } else {
        $oldStatus = $currentStatus ?? 'none';
        $results['updated'][] = "$refCode: $oldStatus <?= SYMBOL_ARROW_RIGHT ?> $statusFromFile";
    }
}

// Display results
$hasErrors = count($results['errors']) > 0;

echo "<div class='summary " . ($hasErrors ? 'has-errors' : '') . "'>";
echo "<h2><?= ICON_CHART_UP ?> Summary</h2>";
echo "<ul>";
echo "<li class='success'><strong>" . count($results['updated']) . "</strong> orders updated</li>";
echo "<li class='info'><strong>" . count($results['skipped']) . "</strong> orders already in sync (skipped)</li>";
echo "<li class='warning'><strong>" . count($results['no_status']) . "</strong> orders not in statuses.json (set to unpaid)</li>";
echo "<li class='error'><strong>" . count($results['errors']) . "</strong> errors</li>";
echo "</ul>";
echo "</div>";

if (count($results['updated']) > 0) {
    echo "<h3 class='success'><?= ICON_CHECK_GREEN ?> Updated Orders (" . count($results['updated']) . ")</h3>";
    echo "<pre>";
    foreach ($results['updated'] as $item) {
        echo htmlspecialchars($item) . "\n";
    }
    echo "</pre>";
}

if (count($results['skipped']) > 0) {
    echo "<h3 class='info'>â­ Skipped - Already Synced (" . count($results['skipped']) . ")</h3>";
    echo "<pre>";
    foreach ($results['skipped'] as $item) {
        echo htmlspecialchars($item) . "\n";
    }
    echo "</pre>";
}

if (count($results['no_status']) > 0) {
    echo "<h3 class='warning'><?= ICON_WARNING ?> No Status Found - Set to Unpaid (" . count($results['no_status']) . ")</h3>";
    echo "<pre>";
    foreach ($results['no_status'] as $item) {
        echo htmlspecialchars($item) . "\n";
    }
    echo "</pre>";
}

if (count($results['errors']) > 0) {
    echo "<h3 class='error'>âŒ Errors (" . count($results['errors']) . ")</h3>";
    echo "<pre>";
    foreach ($results['errors'] as $item) {
        echo htmlspecialchars($item) . "\n";
    }
    echo "</pre>";
}

echo "<hr>";
echo "<p><strong><?= ICON_WARNING ?> Important:</strong> Delete this file after running!</p>";
echo "<p><code>rm sync-order-statuses.php</code></p>";

echo "</body></html>";
?>
