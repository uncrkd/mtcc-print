<?php
/**
 * Production Issues - File Issue Handling and Preflight Details
 * MTCC Print Services
 *
 * Location: /includes/production-issues.php
 * Extracted from: admin/production.php
 */

function markFileIssue($postData, $statusesFile, $ordersDir) {
    global $preflightLogFile;
    $referenceCode = $postData['reference_code'] ?? '';
    $issueNote = trim($postData['issue_note'] ?? '');

    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    $result = updateOrderStatus($referenceCode, 'file_issue', $statusesFile);

    if ($result['success']) {
        // Write issue details to preflight-log.json so File Issues tab can display them
        if (file_exists($preflightLogFile)) {
            $log = json_decode(file_get_contents($preflightLogFile), true) ?: [];
        } else {
            $log = ['entries' => []];
        }

        if (!isset($log['entries'][$referenceCode])) {
            $log['entries'][$referenceCode] = [];
        }
        $log['entries'][$referenceCode]['file_issue_at'] = date('c');
        $log['entries'][$referenceCode]['file_issue_reason'] = $issueNote;
        $log['entries'][$referenceCode]['file_issue_by'] = 'admin';
        $log['entries'][$referenceCode]['status'] = 'file_issue';
        file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

        // Also log to order history
        if (!empty($issueNote) && function_exists('logOrderHistory')) {
            logOrderHistory($referenceCode, 'file_issue', "File issue: {$issueNote}", getCurrentAdminName() ?? 'Admin');
        }
    }

    return $result;
}

function resolveFileIssue($postData, $statusesFile) {
    global $preflightLogFile;
    $referenceCode = $postData['reference_code'] ?? '';
    $targetStatus = $postData['target_status'] ?? 'paid';

    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    $result = updateOrderStatus($referenceCode, $targetStatus, $statusesFile);

    if ($result['success']) {
        // Clear issue data in preflight-log.json
        if (file_exists($preflightLogFile)) {
            $log = json_decode(file_get_contents($preflightLogFile), true) ?: [];
            if (isset($log['entries'][$referenceCode])) {
                unset($log['entries'][$referenceCode]['file_issue_at']);
                unset($log['entries'][$referenceCode]['file_issue_reason']);
                unset($log['entries'][$referenceCode]['file_issue_by']);
                $log['entries'][$referenceCode]['status'] = $targetStatus;
                file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
            }
        }

        if (function_exists('logOrderHistory')) {
            logOrderHistory($referenceCode, 'file_issue_resolved', "File issue resolved, status set to {$targetStatus}", getCurrentAdminName() ?? 'Admin');
        }
    }

    return $result;
}

function resendVendorEmail($postData, $vendorsFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile) {
    $referenceCode = $postData['reference_code'] ?? '';
    $regenerateToken = ($postData['regenerate_token'] ?? '0') === '1';

    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    // Load preflight info
    $preflightLog = [];
    if (file_exists($preflightLogFile)) {
        $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
    }

    if (!isset($preflightLog['entries'][$referenceCode])) {
        return ['success' => false, 'error' => 'Order not found in preflight log'];
    }

    $pfEntry = $preflightLog['entries'][$referenceCode];
    $vendorId = $pfEntry['vendor_id'];

    // Load vendor
    $vendorData = loadVendors($vendorsFile);
    $vendor = null;
    foreach ($vendorData['vendors'] as $v) {
        if ($v['id'] === $vendorId) {
            $vendor = $v;
            break;
        }
    }

    if (!$vendor) {
        return ['success' => false, 'error' => 'Vendor not found'];
    }

    // Load order
    $order = findOrderByRef($referenceCode, $ordersDir);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    // Get or regenerate token
    $token = $pfEntry['token'] ?? null;
    if ($regenerateToken || !$token) {
        $token = generateVendorToken($referenceCode, $vendorId, $tokensFile);
        $preflightLog['entries'][$referenceCode]['token'] = $token;
    }

    // Send email
    $notes = $pfEntry['notes'] ?? '';
    $emailSent = sendVendorOrderEmail($vendor, $order, $notes, $basePath, $token);

    if ($emailSent) {
        // Update preflight log
        $preflightLog['entries'][$referenceCode]['email_resent_at'] = date('c');
        $preflightLog['entries'][$referenceCode]['email_resent_by'] = getCurrentAdminName() ?? 'Admin';
        if (!isset($preflightLog['entries'][$referenceCode]['resend_count'])) {
            $preflightLog['entries'][$referenceCode]['resend_count'] = 0;
        }
        $preflightLog['entries'][$referenceCode]['resend_count']++;
        file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);

        if (function_exists('logOrderHistory')) {
            logOrderHistory($referenceCode, 'email_resent', 'Vendor email resent to ' . $vendor['email'], getCurrentAdminName() ?? 'Admin');
        }

        return [
            'success' => true,
            'message' => 'Email resent to ' . $vendor['email'],
            'token_regenerated' => $regenerateToken
        ];
    }

    return ['success' => false, 'error' => 'Failed to send email'];
}

function regenerateOrderToken($postData, $preflightLogFile, $tokensFile) {
    $referenceCode = $postData['reference_code'] ?? '';

    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    // Load preflight info
    $preflightLog = [];
    if (file_exists($preflightLogFile)) {
        $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
    }

    if (!isset($preflightLog['entries'][$referenceCode])) {
        return ['success' => false, 'error' => 'Order not found in preflight log'];
    }

    $vendorId = $preflightLog['entries'][$referenceCode]['vendor_id'];

    // Generate new token (this also revokes old ones)
    $newToken = generateVendorToken($referenceCode, $vendorId, $tokensFile);

    // Update preflight log
    $preflightLog['entries'][$referenceCode]['token'] = $newToken;
    $preflightLog['entries'][$referenceCode]['token_regenerated_at'] = date('c');
    file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);

    if (function_exists('logOrderHistory')) {
        logOrderHistory($referenceCode, 'token_regenerated', 'Vendor portal token regenerated', getCurrentAdminName() ?? 'Admin');
    }

    return [
        'success' => true,
        'message' => 'Token regenerated successfully',
        'portal_url' => 'https://mtcc.print-stuff.ca/vendor-portal.php?token=' . urlencode($newToken)
    ];
}

function getOrderPreflightDetails($referenceCode, $ordersDir, $preflightLogFile, $tokensFile, $vendorsFile, $reminderLogFile = null) {
    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    // Load order
    $order = findOrderByRef($referenceCode, $ordersDir);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    // Load preflight info
    $preflightLog = [];
    if (file_exists($preflightLogFile)) {
        $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
    }

    $pfEntry = $preflightLog['entries'][$referenceCode] ?? null;

    // Load token info
    $tokenInfo = null;
    if ($pfEntry && !empty($pfEntry['token'])) {
        $tokens = loadTokens($tokensFile);
        $token = $pfEntry['token'];
        if (isset($tokens['tokens'][$token])) {
            $tokenData = $tokens['tokens'][$token];
            $createdAt = strtotime($tokenData['created_at']);
            $expiresAt = $createdAt + (7 * 24 * 60 * 60);
            $tokenInfo = [
                'token' => substr($token, 0, 8) . '...' . substr($token, -8),
                'created_at' => $tokenData['created_at'],
                'expires_at' => date('c', $expiresAt),
                'is_expired' => time() > $expiresAt,
                'is_revoked' => !empty($tokenData['revoked']),
                'downloads' => $tokenData['downloads'] ?? [],
                'download_count' => count($tokenData['downloads'] ?? []),
                'confirmed_at' => $tokenData['confirmed_at'] ?? null,
                'portal_url' => 'https://mtcc.print-stuff.ca/vendor-portal.php?token=' . urlencode($token)
            ];
        }
    }

    // Load vendor info
    $vendor = null;
    if ($pfEntry && !empty($pfEntry['vendor_id'])) {
        $vendorData = loadVendors($vendorsFile);
        foreach ($vendorData['vendors'] as $v) {
            if ($v['id'] === $pfEntry['vendor_id']) {
                $vendor = [
                    'id' => $v['id'],
                    'business_name' => $v['business_name'],
                    'email' => $v['email'],
                    'contact_name' => $v['contact_name'] ?? ''
                ];
                break;
            }
        }
    }

    // Calculate time metrics
    $timeMetrics = null;
    if ($pfEntry && !empty($pfEntry['pushed_at'])) {
        $pushedAt = strtotime($pfEntry['pushed_at']);
        $now = time();
        $elapsed = $now - $pushedAt;

        $timeMetrics = [
            'pushed_at' => $pfEntry['pushed_at'],
            'elapsed_seconds' => $elapsed,
            'elapsed_human' => formatElapsedTime($elapsed),
            'is_overdue' => $elapsed > (2 * 60 * 60), // 2 hours
            'is_critical' => $elapsed > (4 * 60 * 60)  // 4 hours
        ];
    }

    // Load reminder info
    $reminderInfo = null;
    if ($reminderLogFile && file_exists($reminderLogFile)) {
        $reminderLog = json_decode(file_get_contents($reminderLogFile), true) ?: [];
        $reminders = $reminderLog['reminders'][$referenceCode] ?? [];

        if (!empty($reminders)) {
            $lastReminder = end($reminders);
            $reminderInfo = [
                'count' => count($reminders),
                'last_sent_at' => $lastReminder['sent_at'] ?? null,
                'last_sent_human' => isset($lastReminder['sent_at'])
                    ? formatElapsedTime(time() - strtotime($lastReminder['sent_at']))
                    : null,
                'history' => array_map(function($r) {
                    return [
                        'sent_at' => $r['sent_at'],
                        'reminder_number' => $r['reminder_number'],
                        'threshold_hours' => $r['threshold_hours']
                    ];
                }, $reminders)
            ];
        }
    }

    // File info
    $fileInfo = null;
    $uploadedFile = $order['uploadedFile'] ?? null;
    if ($uploadedFile) {
        $filePath = $uploadedFile['path'] ?? '';
        $fileSize = $uploadedFile['size'] ?? 0;
        $fileInfo = [
            'name' => $uploadedFile['originalName'] ?? basename($filePath),
            'path' => $filePath,
            'size' => $fileSize > 1048576 ? round($fileSize / 1048576, 1) . ' MB' : round($fileSize / 1024, 1) . ' KB'
        ];
    }

    // Vendor pricing
    $vendorPricing = [];
    if ($pfEntry && !empty($pfEntry['vendor_pricing'])) {
        $vendorPricing = $pfEntry['vendor_pricing'];
    }

    // Packing info
    $packingInfo = [
        'type' => $pfEntry['packing'] ?? 'none',
        'qty' => $pfEntry['packing_qty'] ?? 1,
        'custom' => $pfEntry['packing_custom'] ?? ''
    ];

    // Notes — merge internal notes (admin) + vendor notes + customer notes
    $orderNotes = [];
    // Customer notes first
    if (!empty($order['notes'])) {
        $orderNotes[] = ['type' => 'customer', 'text' => $order['notes'], 'by' => 'Customer'];
    }
    // Admin internal notes
    if (!empty($order['internalNotes']) && is_array($order['internalNotes'])) {
        foreach ($order['internalNotes'] as $note) {
            $orderNotes[] = [
                'type' => 'admin',
                'text' => $note['content'] ?? '',
                'by' => $note['username'] ?? 'Admin',
                'time' => !empty($note['timestamp']) ? date('M j, g:ia', strtotime($note['timestamp'])) : '',
                'visible_to_vendor' => !empty($note['visible_to_vendor'])
            ];
        }
    }
    // Vendor notes from preflight log
    if (!empty($pfEntry['vendor_notes']) && is_array($pfEntry['vendor_notes'])) {
        foreach ($pfEntry['vendor_notes'] as $vn) {
            $orderNotes[] = array_merge($vn, ['type' => 'vendor']);
        }
    }

    return [
        'success' => true,
        'reference_code' => $referenceCode,
        'order' => [
            'customer_name' => $order['customerInfo']['name'] ?? '',
            'customer_email' => $order['customerInfo']['email'] ?? '',
            'dimensions' => ($order['dimensions']['width'] ?? 0) . '" x ' . ($order['dimensions']['height'] ?? 0) . '"',
            'material' => $order['material'] ?? 'paper',
            'due_date' => $order['selectedDate'] ?? null,
            'delivery_time' => $order['deliveryTime'] ?? 'anytime',
            'tier' => $order['pricing']['tier'] ?? 'standard'
        ],
        'preflight' => $pfEntry ? [
            'pushed_at' => $pfEntry['pushed_at'] ?? null,
            'pushed_by' => $pfEntry['pushed_by'] ?? null,
            'notes' => $pfEntry['notes'] ?? '',
            'email_sent' => $pfEntry['email_sent'] ?? false,
            'email_resent_at' => $pfEntry['email_resent_at'] ?? null,
            'resend_count' => $pfEntry['resend_count'] ?? 0,
            'confirmed_at' => $pfEntry['confirmed_at'] ?? null,
            'status' => $pfEntry['status'] ?? 'pending'
        ] : null,
        'vendor' => $vendor,
        'token' => $tokenInfo,
        'time_metrics' => $timeMetrics,
        'reminders' => $reminderInfo,
        'file_info' => $fileInfo,
        'vendor_pricing' => $vendorPricing,
        'packing' => $packingInfo,
        'notes' => $orderNotes
    ];
}
