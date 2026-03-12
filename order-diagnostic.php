<?php
/**
 * Diagnostic: List order files and their naming pattern
 */

$ordersDir = __DIR__ . '/uploads/orders/';

echo "<html><head><title>Order Files Diagnostic</title>";
echo "<style>
    body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; padding: 20px; max-width: 900px; margin: 0 auto; }
    pre { background: #f3f4f6; padding: 12px; border-radius: 8px; overflow-x: auto; font-size: 12px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #e5e7eb; padding: 8px; text-align: left; }
    th { background: #f9fafb; }
</style></head><body>";

echo "<h1>📂 Order Files Diagnostic</h1>";

if (!is_dir($ordersDir)) {
    echo "<p style='color:red'>Orders directory not found: $ordersDir</p>";
    exit;
}

echo "<p>Directory: <code>$ordersDir</code></p>";

$files = glob($ordersDir . '*.json');
echo "<p>Found <strong>" . count($files) . "</strong> JSON files</p>";

if (count($files) > 0) {
    echo "<h2>Files Found:</h2>";
    echo "<table>";
    echo "<tr><th>#</th><th>Filename</th><th>Reference Code (from content)</th><th>Status</th></tr>";
    
    $count = 0;
    foreach ($files as $file) {
        $count++;
        $filename = basename($file);
        
        // Skip history files
        if (strpos($filename, '_history') !== false) {
            echo "<tr><td>$count</td><td>$filename</td><td colspan='2'><em>History file - skipped</em></td></tr>";
            continue;
        }
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        $refCode = $data['referenceCode'] ?? 'N/A';
        $status = $data['status'] ?? 'N/A';
        
        echo "<tr><td>$count</td><td>$filename</td><td>$refCode</td><td>$status</td></tr>";
        
        if ($count >= 50) {
            echo "<tr><td colspan='4'><em>... showing first 50 files</em></td></tr>";
            break;
        }
    }
    echo "</table>";
    
    // Show sample filename pattern
    echo "<h2>Filename Pattern Analysis:</h2>";
    $sampleFile = basename($files[0]);
    echo "<p>Sample filename: <code>$sampleFile</code></p>";
    
    // Check if files contain reference codes
    echo "<h3>Sample file content structure:</h3>";
    $sampleData = json_decode(file_get_contents($files[0]), true);
    if ($sampleData) {
        echo "<pre>";
        echo "referenceCode: " . ($sampleData['referenceCode'] ?? 'NOT FOUND') . "\n";
        echo "status: " . ($sampleData['status'] ?? 'NOT FOUND') . "\n";
        echo "Keys in file: " . implode(', ', array_keys($sampleData));
        echo "</pre>";
    }
}

echo "<hr><p>Delete this file when done: <code>rm order-diagnostic.php</code></p>";
echo "</body></html>";
?>
