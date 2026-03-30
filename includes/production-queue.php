<?php
/**
 * Production Queue - Vendor Push and Status Operations
 * MTCC Print Services
 *
 * Location: /includes/production-queue.php
 * Extracted from: admin/production.php
 */

function updateOrderStatus($referenceCode, $newStatus, $statusesFile) {
    $statuses = [];
    if (file_exists($statusesFile)) {
        $statuses = json_decode(file_get_contents($statusesFile), true) ?: [];
    }

    $oldStatus = $statuses[$referenceCode] ?? 'unknown';
    $statuses[$referenceCode] = $newStatus;

    if (file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX)) {
        // Sync order file to match statuses.json
        $ordersDir = dirname(dirname($statusesFile)) . '/uploads/orders/';
        if (function_exists('findOrderByReference')) {
            $orderInfo = findOrderByReference($referenceCode, $ordersDir);
            if ($orderInfo) {
                $orderInfo['data']['status'] = $newStatus;
                file_put_contents($orderInfo['filepath'], json_encode($orderInfo['data'], JSON_PRETTY_PRINT), LOCK_EX);
            }
        }
        if (function_exists('logOrderHistory')) {
            logOrderHistory($referenceCode, 'status_change', "Status changed from {$oldStatus} to {$newStatus}", getCurrentAdminName() ?? 'Admin');
        }
        return ['success' => true, 'message' => 'Status updated'];
    }

    return ['success' => false, 'error' => 'Failed to update status'];
}

function pushOrderToVendor($postData, $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile = null) {
    $referenceCode = $postData['reference_code'] ?? '';
    $vendorId = $postData['vendor_id'] ?? '';
    $notes = trim($postData['notes'] ?? '');
    $sendEmail = ($postData['send_email'] ?? '1') === '1';
    $packing = $postData['packing'] ?? 'none';
    $packingCustom = trim($postData['packing_custom'] ?? '');
    $printNotes = trim($postData['print_notes'] ?? '');

    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    // Default tokens file if not provided
    if (!$tokensFile) {
        $tokensFile = $basePath . 'data/vendor-tokens.json';
    }

    // Load vendor
    $vendorData = loadVendors($vendorsFile);
    $vendor = null;
    foreach ($vendorData['vendors'] as $v) {
        if ($v['id'] === $vendorId && $v['active']) {
            $vendor = $v;
            break;
        }
    }

    if (!$vendor) {
        return ['success' => false, 'error' => 'Vendor not found or inactive'];
    }

    // Load order
    $order = findOrderByRef($referenceCode, $ordersDir);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }

    // Generate secure token for vendor portal
    $token = generateVendorToken($referenceCode, $vendorId, $tokensFile);

    // Update status to preflight
    $result = updateOrderStatus($referenceCode, 'preflight', $statusesFile);
    if (!$result['success']) {
        return $result;
    }

    // Log preflight push
    $preflightEntry = [
        'id' => 'pf_' . bin2hex(random_bytes(8)),
        'reference_code' => $referenceCode,
        'vendor_id' => $vendorId,
        'vendor_name' => $vendor['business_name'],
        'pushed_at' => date('c'),
        'pushed_by' => getCurrentAdminName() ?? 'Admin',
        'notes' => $notes,
        'print_notes' => $printNotes,
        'packing' => $packing,
        'packing_custom' => $packingCustom,
        'fulfillment_batch' => $postData['fulfillment_batch'] ?? null,
        'token' => $token,
        'email_sent' => false,
        'confirmed_at' => null,
        'status' => 'pending'
    ];

    $preflightLog = [];
    if (file_exists($preflightLogFile)) {
        $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
    }
    if (!isset($preflightLog['entries'])) {
        $preflightLog['entries'] = [];
    }
    $preflightLog['entries'][$referenceCode] = $preflightEntry;
    file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);

    // Send email to vendor with portal link
    $emailSent = false;
    if ($sendEmail) {
        $emailSent = sendVendorOrderEmail($vendor, $order, $notes, $basePath, $token);
        if ($emailSent) {
            $preflightLog['entries'][$referenceCode]['email_sent'] = true;
            $preflightLog['entries'][$referenceCode]['email_sent_at'] = date('c');
            file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }

    // Log activity
    if (function_exists('logAdminActivity')) {
        logAdminActivity('Order Pushed to Vendor', [
            'reference_code' => $referenceCode,
            'vendor' => $vendor['business_name'],
            'email_sent' => $emailSent
        ], $referenceCode);
    }

    return [
        'success' => true,
        'message' => 'Order pushed to ' . $vendor['business_name'],
        'email_sent' => $emailSent
    ];
}

function pushMultipleOrders($postData, $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile = null) {
    $referenceCodes = json_decode($postData['reference_codes'] ?? '[]', true);
    $vendorId = $postData['vendor_id'] ?? '';
    $sendEmail = ($postData['send_email'] ?? '1') === '1';
    $packing = $postData['packing'] ?? 'none';
    $packingCustom = trim($postData['packing_custom'] ?? '');
    $printNotes = trim($postData['print_notes'] ?? '');

    if (empty($referenceCodes)) {
        return ['success' => false, 'error' => 'No orders selected'];
    }

    // Default tokens file if not provided
    if (!$tokensFile) {
        $tokensFile = $basePath . 'data/vendor-tokens.json';
    }

    $results = [];
    $successCount = 0;
    $failCount = 0;

    // Load batch data to tag orders
    $fbFile = $basePath . 'data/fulfillment-batches.json';
    $fbData = file_exists($fbFile) ? (json_decode(file_get_contents($fbFile), true) ?: ['batches' => []]) : ['batches' => []];

    foreach ($referenceCodes as $refCode) {
        // Look up batch for this order
        $batchId = null;
        foreach ($fbData['batches'] as $b) {
            if ($b['status'] !== 'cancelled' && in_array($refCode, $b['order_refs'])) {
                $batchId = $b['batch_id'];
                break;
            }
        }

        $result = pushOrderToVendor([
            'reference_code' => $refCode,
            'vendor_id' => $vendorId,
            'notes' => '',
            'print_notes' => $printNotes,
            'packing' => $packing,
            'packing_custom' => $packingCustom,
            'fulfillment_batch' => $batchId,
            'send_email' => $sendEmail ? '1' : '0'
        ], $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile);

        if ($result['success']) {
            $successCount++;
        } else {
            $failCount++;
        }
        $results[$refCode] = $result;
    }

    return [
        'success' => $successCount > 0,
        'message' => "{$successCount} orders pushed" . ($failCount > 0 ? ", {$failCount} failed" : ''),
        'results' => $results
    ];
}

function pushBatchToVendor($postData, $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile = null, $batchesFile = null) {
    $batchId = $postData['batch_id'] ?? '';
    $referenceCodes = json_decode($postData['reference_codes'] ?? '[]', true);
    $vendorId = $postData['vendor_id'] ?? '';
    $sendEmail = ($postData['send_email'] ?? '1') === '1';
    $packing = $postData['packing'] ?? 'none';
    $packingCustom = trim($postData['packing_custom'] ?? '');
    $printNotes = trim($postData['print_notes'] ?? '');

    if (empty($batchId) || empty($referenceCodes)) return ['success' => false, 'error' => 'Batch ID and orders required'];

    // Push each order individually (no email per order)
    $successCount = 0;
    $failCount = 0;
    $pushedOrders = [];

    foreach ($referenceCodes as $refCode) {
        $result = pushOrderToVendor([
            'reference_code' => $refCode,
            'vendor_id' => $vendorId,
            'notes' => '',
            'print_notes' => $printNotes,
            'packing' => $packing,
            'packing_custom' => $packingCustom,
            'fulfillment_batch' => $batchId,
            'send_email' => '0' // Suppress individual emails
        ], $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile);

        if ($result['success']) {
            $successCount++;
            $order = findOrderByRef($refCode, $ordersDir);
            $pushedOrders[] = [
                'ref' => $refCode,
                'customer' => $order['customerInfo']['name'] ?? '-',
                'size' => ($order['dimensions']['width'] ?? 0) . '" × ' . ($order['dimensions']['height'] ?? 0) . '"',
                'material' => ucfirst($order['material'] ?? 'poster'),
                'due_date' => isset($order['selectedDate']) ? date('M j, Y', strtotime($order['selectedDate'])) : 'TBD',
                'delivery_time' => $order['deliveryTime'] ?? 'anytime',
            ];
        } else {
            $failCount++;
        }
    }

    // Update batch record with vendor info
    if ($batchesFile && $successCount > 0) {
        $fbData = loadFulfillmentBatches($batchesFile);
        foreach ($fbData['batches'] as &$fb) {
            if ($fb['batch_id'] === $batchId) {
                $vendorData = loadVendors($vendorsFile);
                foreach ($vendorData['vendors'] as $v) {
                    if ($v['id'] === $vendorId) {
                        $fb['vendor_id'] = $vendorId;
                        $fb['vendor_name'] = $v['business_name'];
                        break;
                    }
                }
                $fb['status'] = 'pushed';
                $fb['pushed_at'] = date('c');
                break;
            }
        }
        unset($fb);
        saveFulfillmentBatches($fbData, $batchesFile);
    }

    // Send ONE consolidated email for the batch
    if ($sendEmail && $successCount > 0) {
        $vendorData = loadVendors($vendorsFile);
        $vendor = null;
        foreach ($vendorData['vendors'] as $v) {
            if ($v['id'] === $vendorId && $v['active']) { $vendor = $v; break; }
        }
        if ($vendor) {
            $batchLabel = '';
            if ($batchesFile) {
                $fbData = loadFulfillmentBatches($batchesFile);
                foreach ($fbData['batches'] as $fb) {
                    if ($fb['batch_id'] === $batchId) { $batchLabel = $fb['label']; break; }
                }
            }
            sendBatchVendorEmail($vendor, $batchId, $batchLabel, $pushedOrders, $basePath);
        }
    }

    return [
        'success' => $successCount > 0,
        'message' => "Batch pushed: {$successCount} orders sent to vendor" . ($failCount > 0 ? ", {$failCount} failed" : ''),
        'email_sent' => $sendEmail && $successCount > 0,
    ];
}

function sendBatchVendorEmail($vendor, $batchId, $batchLabel, $orders, $basePath) {
    $to = $vendor['email'];
    $subject = 'New Print Batch: ' . ($batchLabel ?: $batchId) . ' (' . count($orders) . ' orders)';
    $year = date('Y');

    $orderRows = '';
    foreach ($orders as $o) {
        $orderRows .= '<tr>
            <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; font-weight: 600; color: #7c3aed; font-size: 14px;">' . htmlspecialchars($o['ref']) . '</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: #374151;">' . htmlspecialchars($o['customer']) . '</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: #374151;">' . htmlspecialchars($o['size']) . '</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: #374151;">' . htmlspecialchars($o['material']) . '</td>
            <td style="padding: 10px 12px; border-bottom: 1px solid #e5e7eb; font-size: 13px; color: #374151;">' . htmlspecialchars($o['due_date']) . ' ' . htmlspecialchars($o['delivery_time']) . '</td>
        </tr>';
    }

    $html = '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">
<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 650px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr><td>
    <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); border-radius: 12px 12px 0 0;">
    <tr><td style="padding: 30px; text-align: center;">
        <div style="color: #ffffff; font-size: 22px; font-weight: 700; margin-bottom: 6px;">New Print Batch</div>
        <div style="color: rgba(255,255,255,0.8); font-size: 14px;">' . htmlspecialchars($batchLabel ?: $batchId) . ' &middot; ' . count($orders) . ' orders</div>
    </td></tr>
    </table>
    <table cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr><td style="padding: 30px;">
        <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;">
            Hi ' . htmlspecialchars($vendor['business_name']) . ',
        </p>
        <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
            A new batch of <strong>' . count($orders) . ' orders</strong> has been assigned to you. Please review and confirm in the fulfillment portal.
        </p>
        <table cellpadding="0" cellspacing="0" style="width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; margin: 20px 0;">
            <thead>
                <tr style="background: #f8fafc;">
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Order</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Customer</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Size</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Material</th>
                    <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 700; color: #6b7280; text-transform: uppercase;">Due</th>
                </tr>
            </thead>
            <tbody>' . $orderRows . '</tbody>
        </table>
        <div style="margin-top: 24px; text-align: center;">
            <a href="https://mtcc.print-stuff.ca/fulfillment/" style="display: inline-block; padding: 14px 32px; background-color: #7c3aed; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px;">
                View in Fulfillment Portal
            </a>
        </div>
    </td></tr>
    </table>
    <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
    <tr><td style="padding: 20px; text-align: center;">
        <div style="color: #6b7280; font-size: 13px;">Questions? Contact <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a></div>
        <div style="color: #9ca3af; font-size: 11px; margin-top: 6px;">&copy; ' . $year . ' Print Stuff - MTCC Print Services</div>
    </td></tr>
    </table>
</td></tr>
</table>
</body></html>';

    // Use SMTP if available
    $emailFile = $basePath . 'email-status-notifications.php';
    if (file_exists($emailFile)) {
        require_once $emailFile;
        if (function_exists('sendEmailSMTP')) {
            return sendEmailSMTP($to, $subject, $html, $batchId, 'orders');
        }
    }
    // Fallback
    $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Print Stuff Orders <orders@printstuff.ca>\r\n";
    return mail($to, $subject, $html, $headers);
}
