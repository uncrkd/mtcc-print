<?php
/**
 * Vendor Download - MTCC Poster System
 * Secure file download handler for vendor portal
 * 
 * Location: /vendor-download.php (root directory)
 * Access: Via token (from vendor-portal.php or email link)
 */

$token = $_GET['token'] ?? '';

// Paths
$tokensFile = 'data/vendor-tokens.json';
$ordersDir = 'uploads/orders/';
$filesDir = 'uploads/files/';

// Include utilities for shared helper functions
if (file_exists(__DIR__ . '/includes/utilities.php')) {
    require_once __DIR__ . '/includes/utilities.php';
} elseif (file_exists(__DIR__ . '/utilities.php')) {
    require_once __DIR__ . '/utilities.php';
}

// ============================================
// TOKEN VALIDATION
// ============================================
function loadTokens($file) {
    if (!file_exists($file)) return ['tokens' => []];
    return json_decode(file_get_contents($file), true) ?: ['tokens' => []];
}

function saveTokens($file, $data) {
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function validateToken($token, $tokensFile) {
    if (empty($token) || strlen($token) < 32) {
        return ['valid' => false, 'error' => 'Invalid token format'];
    }
    
    $tokens = loadTokens($tokensFile);
    
    if (!isset($tokens['tokens'][$token])) {
        return ['valid' => false, 'error' => 'Token not found'];
    }
    
    $tokenData = $tokens['tokens'][$token];
    
    // Check if revoked
    if (!empty($tokenData['revoked'])) {
        return ['valid' => false, 'error' => 'This download link has been revoked'];
    }
    
    // Check expiration (7 days)
    $createdAt = strtotime($tokenData['created_at']);
    $expiresAt = $createdAt + (7 * 24 * 60 * 60);
    if (time() > $expiresAt) {
        return ['valid' => false, 'error' => 'This download link has expired'];
    }
    
    return [
        'valid' => true,
        'data' => $tokenData
    ];
}

// ============================================
// FIND ORDER
// ============================================
function findOrderByRef($refCode, $ordersDir) {
    $files = glob($ordersDir . '*.json');
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $refCode) {
            return $data;
        }
    }
    return null;
}

// ============================================
// ERROR PAGE
// ============================================
function showError($title, $message) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Download Error - Print Stuff</title>
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Montserrat', sans-serif;
                background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .error-card {
                background: white;
                border-radius: 16px;
                box-shadow: 0 10px 40px rgba(220, 38, 38, 0.15);
                padding: 60px 40px;
                text-align: center;
                max-width: 500px;
            }
            .error-icon { font-size: 64px; margin-bottom: 20px; }
            .error-title { font-size: 24px; color: #dc2626; margin-bottom: 10px; }
            .error-message { color: #6b7280; margin-bottom: 30px; }
            a { color: #7c3aed; text-decoration: none; }
        </style>
    </head>
    <body>
        <div class="error-card">
            <div class="error-icon">⚠️</div>
            <h1 class="error-title"><?= htmlspecialchars($title) ?></h1>
            <p class="error-message"><?= htmlspecialchars($message) ?></p>
            <p style="color: #9ca3af; font-size: 14px;">
                If you need assistance, please contact<br>
                <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a>
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// ============================================
// VALIDATE TOKEN
// ============================================
$validation = validateToken($token, $tokensFile);

if (!$validation['valid']) {
    showError('Download Failed', $validation['error']);
}

$tokenData = $validation['data'];
$refCode = $tokenData['reference_code'];

// ============================================
// FIND ORDER AND FILE
// ============================================
$order = findOrderByRef($refCode, $ordersDir);

if (!$order) {
    showError('Order Not Found', 'The order associated with this download could not be found.');
}

// Get file info
$uploadedFile = $order['uploadedFile'] ?? null;

if (!$uploadedFile) {
    showError('File Not Found', 'No file is associated with this order.');
}

// Robust path resolution (same strategy as all other download handlers)
$storedPath = $uploadedFile['path'] ?? '';
$storedName = $uploadedFile['storedName'] ?? '';
$filePath = null;

// Strategy 1: absolute path
if ($storedPath && substr($storedPath, 0, 1) === '/' && file_exists($storedPath)) {
    $filePath = $storedPath;
}
// Strategy 2: relative path
if (!$filePath && $storedPath && file_exists($storedPath)) {
    $filePath = $storedPath;
}
// Strategy 3: storedName in files dir
if (!$filePath && $storedName && file_exists($filesDir . $storedName)) {
    $filePath = $filesDir . $storedName;
}
// Strategy 4: glob by refCode_ in files dir
if (!$filePath) {
    $matches = glob($filesDir . $refCode . '_*');
    if (!empty($matches)) $filePath = $matches[0];
}
// Strategy 5: glob by refCode- in files dir
if (!$filePath) {
    $matches = glob($filesDir . $refCode . '-*');
    if (!empty($matches)) $filePath = $matches[0];
}

if (!$filePath) {
    showError('File Missing', 'The requested file could not be found on the server.');
}

// ============================================
// TRACK DOWNLOAD
// ============================================
$tokens = loadTokens($tokensFile);

if (!isset($tokens['tokens'][$token]['downloads'])) {
    $tokens['tokens'][$token]['downloads'] = [];
}

$tokens['tokens'][$token]['downloads'][] = [
    'timestamp' => date('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
];

$tokens['tokens'][$token]['last_download'] = date('c');
$tokens['tokens'][$token]['download_count'] = count($tokens['tokens'][$token]['downloads']);

saveTokens($tokensFile, $tokens);

// ============================================
// SERVE FILE
// ============================================
$originalName = $uploadedFile['originalName'] ?? 'print-file';
$downloadName = function_exists('getDisplayFileName') ? getDisplayFileName($refCode, $originalName) : $refCode . '-01-' . $originalName;
$fileSize = filesize($filePath);
$mimeType = $uploadedFile['mimeType'] ?? 'application/octet-stream';

// Clear any output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers for download
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
header('Content-Length: ' . $fileSize);
header('Content-Transfer-Encoding: binary');
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Prevent timeout for large files
set_time_limit(0);

// Read and output file in chunks
$handle = fopen($filePath, 'rb');
if ($handle) {
    while (!feof($handle)) {
        echo fread($handle, 8192);
        flush();
    }
    fclose($handle);
}

exit;
