<?php
/**
 * Vendor Portal - MTCC Poster System
 * Secure portal for vendors to view order details, download files, and confirm receipt
 * 
 * Location: /vendor-portal.php (root directory)
 * 
 * Access Methods:
 * 1. Vendor: ?token=SECURE_TOKEN (from email link)
 * 2. Admin:  ?ref=MTCC-00123 (God Mode users only)
 */

// Paths
$tokensFile = 'data/vendor-tokens.json';
$vendorsFile = 'data/vendors.json';
$preflightLogFile = 'preflight-log.json';
$statusesFile = 'statuses.json';
$ordersDir = 'uploads/orders/';

// Include utilities for shared helper functions
if (file_exists(__DIR__ . '/includes/utilities.php')) {
    require_once __DIR__ . '/includes/utilities.php';
} elseif (file_exists(__DIR__ . '/utilities.php')) {
    require_once __DIR__ . '/utilities.php';
}

// ============================================
// TOKEN VALIDATION FUNCTIONS
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
        return ['valid' => false, 'error' => 'This link has been revoked'];
    }
    
    // Check expiration (7 days)
    $createdAt = strtotime($tokenData['created_at']);
    $expiresAt = $createdAt + (7 * 24 * 60 * 60);
    if (time() > $expiresAt) {
        return ['valid' => false, 'error' => 'This link has expired'];
    }
    
    return [
        'valid' => true,
        'data' => $tokenData
    ];
}

// ============================================
// ADMIN GOD MODE OVERRIDE
// ============================================
$token = $_GET['token'] ?? '';
$refCode = $_GET['ref'] ?? '';
$isAdminView = false;
$adminUser = null;

// Check for admin override via reference code
if (empty($token) && !empty($refCode)) {
    // Try to load admin auth (use __DIR__ for reliable path resolution)
    $authFile = __DIR__ . '/admin-auth.php';
    if (file_exists($authFile)) {
        require_once $authFile;
        
        // Check if logged in with god_mode role
        if (isAdminLoggedIn() && isGodMode()) {
            
            $isAdminView = true;
            $adminUser = getCurrentAdminName() ?? 'Admin';
            
            // Find active token for this reference code
            $tokens = loadTokens($tokensFile);
            foreach ($tokens['tokens'] as $t => $data) {
                if (($data['reference_code'] ?? '') === $refCode && empty($data['revoked'])) {
                    // Check not expired
                    $createdAt = strtotime($data['created_at']);
                    $expiresAt = $createdAt + (7 * 24 * 60 * 60);
                    if (time() < $expiresAt) {
                        $token = $t;
                        break;
                    }
                }
            }
            
            // If no token exists, create a temporary validation bypass
            if (empty($token)) {
                // We'll handle this case - admin can still view without token
                $token = 'ADMIN_BYPASS';
            }
        }
    }
}

// ============================================
// LOAD ORDER DATA
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

function loadVendor($vendorId, $vendorsFile) {
    if (!file_exists($vendorsFile)) return null;
    $data = json_decode(file_get_contents($vendorsFile), true);
    foreach ($data['vendors'] ?? [] as $v) {
        if ($v['id'] === $vendorId) {
            return $v;
        }
    }
    return null;
}

function formatFileSize($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}

// ============================================
// HANDLE CONFIRMATION ACTION
// ============================================
$confirmationMessage = '';
$confirmationError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Don't allow confirmations from admin view
    if ($isAdminView) {
        $confirmationError = 'Admin view is read-only. Actions must be performed from the vendor portal.';
    } else {
        if ($_POST['action'] === 'confirm' && !empty($token) && $token !== 'ADMIN_BYPASS') {
            $validation = validateToken($token, $tokensFile);
            
            if ($validation['valid']) {
                $tokenData = $validation['data'];
                $refCode = $tokenData['reference_code'];
                
                // Update token with confirmation
                $tokens = loadTokens($tokensFile);
                $tokens['tokens'][$token]['confirmed_at'] = date('c');
                $tokens['tokens'][$token]['confirmed_ip'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                saveTokens($tokensFile, $tokens);
                
                // Update preflight log
                if (file_exists($preflightLogFile)) {
                    $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
                    if (isset($preflightLog['entries'][$refCode])) {
                        $preflightLog['entries'][$refCode]['confirmed_at'] = date('c');
                        $preflightLog['entries'][$refCode]['confirmed_via'] = 'portal';
                        file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);
                    }
                }
                
                // Update order status to 'printing'
                if (file_exists($statusesFile)) {
                    $statuses = json_decode(file_get_contents($statusesFile), true) ?: [];
                    $statuses[$refCode] = 'printing';
                    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
                }
                
                $confirmationMessage = 'Order confirmed! Thank you for your prompt response.';
            } else {
                $confirmationError = $validation['error'];
            }
        }
        
        if ($_POST['action'] === 'mark_printed' && !empty($token) && $token !== 'ADMIN_BYPASS') {
            $validation = validateToken($token, $tokensFile);
            
            if ($validation['valid']) {
                $tokenData = $validation['data'];
                $refCode = $tokenData['reference_code'];
                
                // Update token with printed timestamp
                $tokens = loadTokens($tokensFile);
                $tokens['tokens'][$token]['printed_at'] = date('c');
                saveTokens($tokensFile, $tokens);
                
                // Update preflight log
                if (file_exists($preflightLogFile)) {
                    $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
                    if (isset($preflightLog['entries'][$refCode])) {
                        $preflightLog['entries'][$refCode]['printed_at'] = date('c');
                        $preflightLog['entries'][$refCode]['printed_via'] = 'portal';
                        $preflightLog['entries'][$refCode]['status'] = 'printed';
                        file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);
                    }
                }
                
                // Update order status to 'ready'
                if (file_exists($statusesFile)) {
                    $statuses = json_decode(file_get_contents($statusesFile), true) ?: [];
                    $statuses[$refCode] = 'ready';
                    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
                }
                
                $confirmationMessage = 'Order marked as printed! Thank you.';
            } else {
                $confirmationError = $validation['error'];
            }
        }
        
        if ($_POST['action'] === 'report_issue' && !empty($token) && $token !== 'ADMIN_BYPASS') {
            $validation = validateToken($token, $tokensFile);
            
            if ($validation['valid']) {
                $tokenData = $validation['data'];
                $refCode = $tokenData['reference_code'];
                $issueType = $_POST['issue_type'] ?? 'other';
                $issueDescription = trim($_POST['issue_description'] ?? '');
                
                // Update preflight log with issue
                if (file_exists($preflightLogFile)) {
                    $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
                    if (isset($preflightLog['entries'][$refCode])) {
                        $preflightLog['entries'][$refCode]['file_issue'] = [
                            'reported_at' => date('c'),
                            'type' => $issueType,
                            'description' => $issueDescription,
                            'reported_via' => 'portal'
                        ];
                        file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);
                    }
                }
                
                // Update order status
                if (file_exists($statusesFile)) {
                    $statuses = json_decode(file_get_contents($statusesFile), true) ?: [];
                    $statuses[$refCode] = 'file_issue';
                    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
                }
                
                $confirmationMessage = 'Issue reported. Our team will contact you shortly.';
            } else {
                $confirmationError = $validation['error'];
            }
        }
    }
}

// ============================================
// VALIDATE TOKEN AND LOAD DATA
// ============================================
$order = null;
$vendor = null;
$isConfirmed = false;
$hasIssue = false;
$isPrinted = false;
$tokenData = null;

// Admin bypass - load directly by ref code
if ($isAdminView && $token === 'ADMIN_BYPASS') {
    $order = findOrderByRef($refCode, $ordersDir);
    
    if ($order) {
        // Load preflight info for vendor
        if (file_exists($preflightLogFile)) {
            $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
            $pfEntry = $preflightLog['entries'][$refCode] ?? null;
            
            if ($pfEntry) {
                $vendor = loadVendor($pfEntry['vendor_id'] ?? '', $vendorsFile);
                $isConfirmed = !empty($pfEntry['confirmed_at']);
                $isPrinted = !empty($pfEntry['printed_at']);
                $hasIssue = !empty($pfEntry['file_issue']);
            }
        }
    }
    
    $validation = ['valid' => true, 'data' => ['reference_code' => $refCode]];
} else {
    // Normal token validation
    $validation = validateToken($token, $tokensFile);
    
    if ($validation['valid']) {
        $tokenData = $validation['data'];
        $refCode = $tokenData['reference_code'];
        $vendorId = $tokenData['vendor_id'];
        
        // Load order
        $order = findOrderByRef($refCode, $ordersDir);
        
        // Load vendor
        $vendor = loadVendor($vendorId, $vendorsFile);
        
        // Check if already confirmed
        $isConfirmed = !empty($tokenData['confirmed_at']);
        
        // Check if marked as printed
        $isPrinted = !empty($tokenData['printed_at']);
        
        // Check for file issues
        if (file_exists($preflightLogFile)) {
            $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
            $hasIssue = !empty($preflightLog['entries'][$refCode]['file_issue']);
        }
        
        // Track portal access (not for admin views)
        if (!$isAdminView) {
            $tokens = loadTokens($tokensFile);
            if (!isset($tokens['tokens'][$token]['portal_views'])) {
                $tokens['tokens'][$token]['portal_views'] = [];
            }
            $tokens['tokens'][$token]['portal_views'][] = [
                'timestamp' => date('c'),
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];
            $tokens['tokens'][$token]['last_viewed'] = date('c');
            saveTokens($tokensFile, $tokens);
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Order Portal - Print Stuff</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 700px;
            margin: 0 auto;
        }
        
        /* Admin Banner */
        .admin-banner {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
        }
        
        .admin-banner strong {
            color: #fbbf24;
        }
        
        .admin-banner a {
            color: #93c5fd;
            text-decoration: none;
        }
        
        .admin-banner a:hover {
            text-decoration: underline;
        }
        
        .header {
            text-align: center;
            padding: 30px 20px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            border-radius: 16px 16px 0 0;
            color: white;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .logo span {
            color: #c4b5fd;
        }
        
        .order-badge {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 20px;
            border-radius: 20px;
            font-size: 18px;
            font-weight: 600;
        }
        
        .card {
            background: white;
            border-radius: 0 0 16px 16px;
            box-shadow: 0 10px 40px rgba(124, 58, 237, 0.15);
            overflow: hidden;
        }
        
        .error-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(220, 38, 38, 0.15);
            padding: 60px 40px;
            text-align: center;
        }
        
        .error-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }
        
        .error-title {
            font-size: 24px;
            color: #dc2626;
            margin-bottom: 10px;
        }
        
        .error-message {
            color: #6b7280;
            margin-bottom: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .alert-warning {
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }
        
        .alert-info {
            background: #e0e7ff;
            color: #3730a3;
            border: 1px solid #a5b4fc;
        }
        
        .confirmed-banner {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .confirmed-banner h3 {
            font-size: 18px;
            margin-bottom: 5px;
        }
        
        .confirmed-banner p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .section {
            padding: 25px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .section:last-child {
            border-bottom: none;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 15px;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .info-item {
            padding: 12px 15px;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .info-item.full-width {
            grid-column: 1 / -1;
        }
        
        .info-label {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
        }
        
        .urgent-badge {
            display: inline-block;
            background: #dc2626;
            color: white;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            margin-left: 10px;
        }
        
        .file-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .file-details {
            flex: 1;
            min-width: 200px;
        }
        
        .file-name {
            font-weight: 600;
            color: #1e293b;
            word-break: break-all;
        }
        
        .file-size {
            font-size: 14px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(124, 58, 237, 0.4);
        }
        
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }
        
        .btn-outline {
            background: white;
            color: #7c3aed;
            border: 2px solid #7c3aed;
        }
        
        .btn-outline:hover {
            background: #f5f3ff;
        }
        
        .btn-danger-outline {
            background: white;
            color: #dc2626;
            border: 2px solid #dc2626;
        }
        
        .btn-danger-outline:hover {
            background: #fef2f2;
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .actions {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            justify-content: center;
            padding: 30px;
            background: #f8fafc;
        }
        
        .notes-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px 20px;
            border-radius: 0 8px 8px 0;
        }
        
        .notes-box strong {
            color: #92400e;
            display: block;
            margin-bottom: 5px;
        }
        
        .notes-box p {
            color: #78350f;
            white-space: pre-wrap;
        }
        
        .issue-form {
            display: none;
            padding: 20px;
            background: #fef2f2;
            border-radius: 8px;
            margin-top: 15px;
        }
        
        .issue-form.active {
            display: block;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-family: inherit;
            font-size: 14px;
        }
        
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .footer {
            text-align: center;
            padding: 30px;
            color: #6b7280;
            font-size: 14px;
        }
        
        .footer a {
            color: #7c3aed;
            text-decoration: none;
        }
        
        /* Token Info for Admin */
        .token-info {
            background: #f1f5f9;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            font-size: 13px;
        }
        
        .token-info-row {
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .token-info-row:last-child {
            border-bottom: none;
        }
        
        .token-info-label {
            color: #64748b;
        }
        
        .token-info-value {
            font-weight: 500;
            color: #334155;
        }
        
        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
            }
            
            .file-info {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .actions {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($isAdminView): ?>
        <!-- Admin Banner -->
        <div class="admin-banner">
            <div>
                👁️ <strong>Admin View</strong> — Viewing as vendor (read-only)
                <?php if ($adminUser): ?>
                    | Logged in as: <?= htmlspecialchars($adminUser) ?>
                <?php endif; ?>
            </div>
            <div>
                <a href="admin-orders.php?search=<?= urlencode($refCode) ?>">← Back to Orders</a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!$validation['valid']): ?>
        <!-- Invalid Token -->
        <div class="error-card">
            <div class="error-icon">🔒</div>
            <h1 class="error-title">Access Denied</h1>
            <p class="error-message"><?= htmlspecialchars($validation['error']) ?></p>
            <p style="color: #9ca3af; font-size: 14px;">
                If you believe this is an error, please contact us at<br>
                <a href="mailto:orders@printstuff.ca" style="color: #7c3aed;">orders@printstuff.ca</a> or call (437) 882-8822
            </p>
        </div>
        
        <?php elseif (!$order): ?>
        <!-- Order Not Found -->
        <div class="error-card">
            <div class="error-icon">📋</div>
            <h1 class="error-title">Order Not Found</h1>
            <p class="error-message">The order associated with this link could not be found.</p>
            <p style="color: #9ca3af; font-size: 14px;">
                Please contact us at<br>
                <a href="mailto:orders@printstuff.ca" style="color: #7c3aed;">orders@printstuff.ca</a>
            </p>
        </div>
        
        <?php else: ?>
        <!-- Valid Order View -->
        <div class="header">
            <div class="logo">Print <span>Stuff</span></div>
            <div class="order-badge">#<?= htmlspecialchars($order['referenceCode']) ?></div>
        </div>
        
        <div class="card">
            <?php if ($isAdminView): ?>
            <div class="alert alert-info" style="margin: 0; border-radius: 0;">
                ℹ️ This is a <strong>read-only preview</strong>. Confirmation and issue reporting are disabled in admin view.
            </div>
            <?php endif; ?>
            
            <?php if ($confirmationMessage): ?>
            <div class="alert alert-success">
                ✅ <?= htmlspecialchars($confirmationMessage) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($confirmationError): ?>
            <div class="alert alert-error">
                ❌ <?= htmlspecialchars($confirmationError) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($isPrinted): ?>
            <div class="confirmed-banner" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%); border-color: #10b981;">
                <h3>✅ Printing Complete</h3>
                <p>Marked as printed on <?= isset($tokenData['printed_at']) ? date('l, F j, Y \a\t g:i A', strtotime($tokenData['printed_at'])) : 'Previously marked' ?></p>
            </div>
            <?php elseif ($isConfirmed): ?>
            <div class="confirmed-banner">
                <h3>🖨️ Printing In Progress</h3>
                <p>Confirmed on <?= isset($tokenData['confirmed_at']) ? date('l, F j, Y \a\t g:i A', strtotime($tokenData['confirmed_at'])) : 'Previously confirmed' ?></p>
            </div>
            <?php endif; ?>
            
            <?php if ($hasIssue): ?>
            <div class="alert alert-warning" style="margin: 0; border-radius: 0;">
                ⚠️ <strong>File Issue Reported</strong> - Our team will contact you shortly.
            </div>
            <?php endif; ?>
            
            <!-- Order Details -->
            <div class="section">
                <div class="section-title">Order Details</div>
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Due Date</div>
                        <div class="info-value">
                            <?php
                            if (isset($order['selectedDate'])) {
                                $_vptl = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
                                $_vpdt = $order['deliveryTime'] ?? 'anytime';
                                $_vptimeStr = ($_vpdt && $_vpdt !== 'anytime') ? ' at ' . ($_vptl[$_vpdt] ?? $_vpdt) : ' at anytime';
                                echo date('l, F j, Y', strtotime($order['selectedDate'])) . $_vptimeStr;
                            } else {
                                echo 'TBD';
                            }
                            ?>
                            <?php 
                            $tier = $order['pricing']['tier'] ?? 'standard';
                            if (in_array($tier, ['sameday', 'nextday'])): 
                            ?>
                            <span class="urgent-badge"><?= strtoupper($tier) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Dimensions</div>
                        <div class="info-value"><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Material</div>
                        <div class="info-value"><?= ucfirst($order['material'] ?? 'Paper') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Priority</div>
                        <div class="info-value"><?= ucfirst($tier) ?></div>
                    </div>
                </div>
                
                <?php if ($isAdminView): ?>
                <!-- Additional Info for Admin -->
                <div class="info-grid" style="margin-top: 15px;">
                    <div class="info-item">
                        <div class="info-label">Customer</div>
                        <div class="info-value"><?= htmlspecialchars($order['customerInfo']['name'] ?? 'Unknown') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= htmlspecialchars($order['customerInfo']['email'] ?? '-') ?></div>
                    </div>
                    <?php if ($vendor): ?>
                    <div class="info-item">
                        <div class="info-label">Assigned Vendor</div>
                        <div class="info-value"><?= htmlspecialchars($vendor['business_name'] ?? 'Unknown') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Vendor Email</div>
                        <div class="info-value"><?= htmlspecialchars($vendor['email'] ?? '-') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <?php 
            // Check for notes in preflight log
            $notes = '';
            if (file_exists($preflightLogFile)) {
                $pfLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
                $notes = $pfLog['entries'][$order['referenceCode']]['notes'] ?? '';
            }
            if (!empty($notes)): 
            ?>
            <div class="section">
                <div class="notes-box">
                    <strong>📝 Special Instructions</strong>
                    <p><?= nl2br(htmlspecialchars($notes)) ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- File Download -->
            <?php if (!$isPrinted): ?>
            <div class="section">
                <div class="section-title">Print File</div>
                <div class="file-info">
                    <div class="file-details">
                        <div class="file-name"><?= htmlspecialchars(function_exists('getDisplayFileName') ? getDisplayFileName($order['referenceCode'] ?? $refCode, $order['uploadedFile']['originalName'] ?? 'Print File') : ($order['uploadedFile']['originalName'] ?? 'Print File')) ?></div>
                        <?php if (isset($order['uploadedFile']['size'])): ?>
                        <div class="file-size"><?= formatFileSize($order['uploadedFile']['size']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if ($token && $token !== 'ADMIN_BYPASS'): ?>
                    <a href="vendor-download.php?token=<?= urlencode($token) ?>" class="btn btn-primary">
                        ⬇️ Download File
                    </a>
                    <?php elseif ($isAdminView): ?>
                    <a href="uploads/orders/<?= htmlspecialchars($order['uploadedFile']['storedName'] ?? '') ?>" class="btn btn-primary" download>
                        ⬇️ Download File
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if ($isAdminView && $tokenData): ?>
            <!-- Token Info for Admin -->
            <div class="section">
                <div class="section-title">Token Information (Admin Only)</div>
                <div class="token-info">
                    <div class="token-info-row">
                        <span class="token-info-label">Token</span>
                        <span class="token-info-value"><?= htmlspecialchars(substr($token, 0, 8) . '...' . substr($token, -8)) ?></span>
                    </div>
                    <div class="token-info-row">
                        <span class="token-info-label">Created</span>
                        <span class="token-info-value"><?= isset($tokenData['created_at']) ? date('l, F j, Y g:i A', strtotime($tokenData['created_at'])) : '-' ?></span>
                    </div>
                    <div class="token-info-row">
                        <span class="token-info-label">Downloads</span>
                        <span class="token-info-value"><?= count($tokenData['downloads'] ?? []) ?></span>
                    </div>
                    <div class="token-info-row">
                        <span class="token-info-label">Portal Views</span>
                        <span class="token-info-value"><?= count($tokenData['portal_views'] ?? []) ?></span>
                    </div>
                    <?php if (!empty($tokenData['confirmed_at'])): ?>
                    <div class="token-info-row">
                        <span class="token-info-label">Confirmed At</span>
                        <span class="token-info-value"><?= date('l, F j, Y g:i A', strtotime($tokenData['confirmed_at'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Actions -->
            <?php if (!$isAdminView && !$isConfirmed && !$hasIssue): ?>
            <div class="actions">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="confirm">
                    <button type="submit" class="btn btn-success">
                        ✅ Confirm Order Received
                    </button>
                </form>
                
                <button type="button" class="btn btn-danger-outline" onclick="toggleIssueForm()">
                    ⚠️ Report File Issue
                </button>
            </div>
            
            <!-- Issue Report Form -->
            <div class="issue-form" id="issueForm">
                <form method="POST">
                    <input type="hidden" name="action" value="report_issue">
                    
                    <div class="form-group">
                        <label>Issue Type</label>
                        <select name="issue_type" required>
                            <option value="">Select issue type...</option>
                            <option value="corrupt">File is corrupted / won't open</option>
                            <option value="wrong_size">Wrong dimensions / size</option>
                            <option value="low_quality">Low resolution / quality</option>
                            <option value="wrong_file">Wrong file / content</option>
                            <option value="color_issue">Color profile issue</option>
                            <option value="other">Other issue</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Description (optional)</label>
                        <textarea name="issue_description" placeholder="Please describe the issue..."></textarea>
                    </div>
                    
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-danger-outline">Submit Issue Report</button>
                        <button type="button" class="btn btn-outline" onclick="toggleIssueForm()">Cancel</button>
                    </div>
                </form>
            </div>
            <?php elseif (!$isAdminView && $isConfirmed && !$isPrinted): ?>
            <div class="actions">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="mark_printed">
                    <button type="submit" class="btn btn-success" onclick="return confirm('Mark this order as printed? This will notify the customer that their order is ready.')">
                        ✅ Mark as Printed
                    </button>
                </form>
                <a href="vendor-download.php?token=<?= urlencode($token) ?>" class="btn btn-primary">
                    ⬇️ Download File Again
                </a>
            </div>
            <?php elseif (!$isAdminView && $isPrinted): ?>
            <div class="actions" style="background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);">
                <p style="color: #059669; font-weight: 600; text-align: center; width: 100%;">🎉 This order is complete. Thank you!</p>
            </div>
            <?php elseif ($isAdminView): ?>
            <div class="actions" style="background: #f1f5f9;">
                <a href="admin/preflight.php" class="btn btn-outline">
                    ← Back to Preflight
                </a>
                <a href="admin-orders.php?search=<?= urlencode($refCode) ?>" class="btn btn-primary">
                    View Full Order
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <p>Questions? Contact us at <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a> or (437) 882-8822</p>
            <p style="margin-top: 10px; color: #9ca3af;">Print Stuff • Metro Toronto Convention Centre</p>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        function toggleIssueForm() {
            const form = document.getElementById('issueForm');
            form.classList.toggle('active');
        }
    </script>
</body>
</html>
