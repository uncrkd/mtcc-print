<?php
/**
 * Production Reminders - Vendor Reminder System
 * MTCC Print Services
 *
 * Location: /includes/production-reminders.php
 * Extracted from: admin/production.php
 */

function sendManualReminder($postData, $vendorsFile, $ordersDir, $preflightLogFile, $tokensFile, $reminderLogFile, $basePath) {
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

    $pfEntry = $preflightLog['entries'][$referenceCode];

    // Skip if already confirmed
    if (!empty($pfEntry['confirmed_at'])) {
        return ['success' => false, 'error' => 'Order already confirmed'];
    }

    // Load vendor
    $vendorData = loadVendors($vendorsFile);
    $vendor = null;
    foreach ($vendorData['vendors'] as $v) {
        if ($v['id'] === $pfEntry['vendor_id']) {
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

    // Get reminder count
    $reminderLog = [];
    if (file_exists($reminderLogFile)) {
        $reminderLog = json_decode(file_get_contents($reminderLogFile), true) ?: [];
    }
    $reminderCount = count($reminderLog['reminders'][$referenceCode] ?? []);

    // Get active token
    $token = null;
    $tokens = loadTokens($tokensFile);
    foreach ($tokens['tokens'] ?? [] as $t => $data) {
        if ($data['reference_code'] === $referenceCode && empty($data['revoked'])) {
            $createdAt = strtotime($data['created_at']);
            $expiresAt = $createdAt + (7 * 24 * 60 * 60);
            if (time() < $expiresAt) {
                $token = $t;
                break;
            }
        }
    }

    // Generate and send reminder email
    $html = generateManualReminderEmail($vendor, $order, $pfEntry, $reminderCount + 1, $token);

    $subject = "Reminder: Print Order #{$referenceCode} - Awaiting Confirmation";
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Print Stuff Orders <orders@printstuff.ca>',
        'Reply-To: orders@printstuff.ca'
    ];

    if (!empty($vendor['email_cc'])) {
        $headers[] = 'Cc: ' . $vendor['email_cc'];
    }

    $sent = mail($vendor['email'], $subject, $html, implode("\r\n", $headers));

    if ($sent) {
        // Log the reminder
        if (!isset($reminderLog['reminders'])) {
            $reminderLog['reminders'] = [];
        }
        if (!isset($reminderLog['reminders'][$referenceCode])) {
            $reminderLog['reminders'][$referenceCode] = [];
        }

        $pushedAt = strtotime($pfEntry['pushed_at']);
        $hoursSincePush = (time() - $pushedAt) / 3600;

        $reminderLog['reminders'][$referenceCode][] = [
            'sent_at' => date('c'),
            'reminder_number' => $reminderCount + 1,
            'threshold_hours' => 0, // Manual
            'vendor_email' => $vendor['email'],
            'hours_since_push' => round($hoursSincePush, 1),
            'manual' => true,
            'sent_by' => getCurrentAdminName() ?? 'Admin'
        ];

        $reminderLog['metadata']['updated_at'] = date('c');
        file_put_contents($reminderLogFile, json_encode($reminderLog, JSON_PRETTY_PRINT), LOCK_EX);

        if (function_exists('logOrderHistory')) {
            logOrderHistory($referenceCode, 'manual_reminder', 'Manual reminder sent to ' . $vendor['email'], getCurrentAdminName() ?? 'Admin');
        }

        return [
            'success' => true,
            'message' => 'Reminder sent to ' . $vendor['email'],
            'reminder_count' => $reminderCount + 1
        ];
    }

    return ['success' => false, 'error' => 'Failed to send email'];
}

function generateManualReminderEmail($vendor, $order, $entry, $reminderNumber, $token = null) {
    $refCode = $order['referenceCode'];
    $dueDate = isset($order['selectedDate']) ? date('D, M j', strtotime($order['selectedDate'])) : 'TBD';
    $_etl = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $_edt = $order['deliveryTime'] ?? 'anytime';
    $dueDate .= ($_edt && $_edt !== 'anytime') ? ' at ' . ($_etl[$_edt] ?? $_edt) : ' at anytime';
    $width = $order['dimensions']['width'] ?? 0;
    $height = $order['dimensions']['height'] ?? 0;
    $material = ucfirst($order['material'] ?? 'paper');

    $portalLink = $token
        ? "https://mtcc.print-stuff.ca/vendor-portal.php?token=" . urlencode($token)
        : null;

    $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Reminder: Print Order #{$refCode}</title>
</head>
<body style='font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f3f4f6;'>
    <table cellpadding='0' cellspacing='0' style='max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <tr>
            <td style='background: #f59e0b; padding: 15px; text-align: center;'>
                <span style='color: white; font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 1px;'>
                    ⏰ REMINDER
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
                    We're following up on the print order below. Please confirm you've received it so we can track progress.
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
                        ✓ View Order & Confirm
                    </a>
                </div>
                " : "") . "
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

function getReminderStats($reminderLogFile, $reminderConfigFile) {
    $stats = [
        'enabled' => false,
        'total_reminders_sent' => 0,
        'orders_with_reminders' => 0,
        'last_run' => null,
        'config' => null
    ];

    // Load config
    if (file_exists($reminderConfigFile)) {
        $config = json_decode(file_get_contents($reminderConfigFile), true);
        $stats['enabled'] = $config['settings']['enabled'] ?? false;
        $stats['config'] = [
            'thresholds' => $config['settings']['reminder_thresholds_hours'] ?? [],
            'max_per_order' => $config['settings']['max_reminders_per_order'] ?? 3,
            'min_hours_between' => $config['settings']['min_hours_between_reminders'] ?? 2
        ];
    }

    // Load log
    if (file_exists($reminderLogFile)) {
        $log = json_decode(file_get_contents($reminderLogFile), true);

        $stats['orders_with_reminders'] = count($log['reminders'] ?? []);

        foreach ($log['reminders'] ?? [] as $refCode => $reminders) {
            $stats['total_reminders_sent'] += count($reminders);
        }

        if (!empty($log['runs'])) {
            $lastRun = end($log['runs']);
            $stats['last_run'] = [
                'timestamp' => $lastRun['timestamp'] ?? null,
                'sent' => $lastRun['reminders_sent'] ?? 0,
                'checked' => $lastRun['orders_checked'] ?? 0
            ];
        }
    }

    return ['success' => true, 'stats' => $stats];
}

function formatElapsedTime($seconds) {
    if ($seconds < 60) return 'Just now';
    if ($seconds < 3600) return floor($seconds / 60) . 'm ago';
    if ($seconds < 86400) return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm ago';
    return floor($seconds / 86400) . 'd ' . floor(($seconds % 86400) / 3600) . 'h ago';
}

function markVendorConfirmed($postData, $statusesFile, $preflightLogFile) {
    $referenceCode = $postData['reference_code'] ?? '';

    if (empty($referenceCode)) {
        return ['success' => false, 'error' => 'Reference code required'];
    }

    // Update preflight log
    $preflightLog = [];
    if (file_exists($preflightLogFile)) {
        $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
    }

    if (isset($preflightLog['entries'][$referenceCode])) {
        $preflightLog['entries'][$referenceCode]['confirmed_at'] = date('c');
        $preflightLog['entries'][$referenceCode]['status'] = 'confirmed';
        file_put_contents($preflightLogFile, json_encode($preflightLog, JSON_PRETTY_PRINT), LOCK_EX);
    }

    // Update order status to printing
    $result = updateOrderStatus($referenceCode, 'printing', $statusesFile);

    if ($result['success'] && function_exists('logOrderHistory')) {
        logOrderHistory($referenceCode, 'vendor_confirmed', 'Order approved for printing', getCurrentAdminName() ?? 'Admin');
    }

    return $result;
}
