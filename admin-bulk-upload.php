<?php
/**
 * Bulk Order Upload - MTCC Poster System
 * Upload orders from Excel spreadsheet with file attachment
 * 
 * Features:
 * - Excel file upload with validation
 * - Duplicate detection (file hash + row content)
 * - Inline editing before creation
 * - Payment method tracking
 * - Bulk email sending
 * - File attachment by customer name
 */
require_once 'includes/icons.php';
require_once 'admin-auth.php';

// Require order creation permission
requireAnyPermission(['orders_edit', 'orders_create']);

$canCreateOrders = hasPermission('orders_create') || hasPermission('orders_edit');

// Load events for validation
$eventsFile = __DIR__ . '/admin/events.json';
$events = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
$activeEvents = array_filter($events, fn($e) => ($e['status'] ?? '') === 'active');
$eventPrefixes = array_column($events, 'prefix');

// Bulk upload tracking file
$bulkUploadsFile = __DIR__ . '/bulk-uploads.json';

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    // Check for duplicate spreadsheet
    if ($action === 'check_spreadsheet_hash') {
        $hash = $_POST['hash'] ?? '';
        
        $uploads = file_exists($bulkUploadsFile) ? json_decode(file_get_contents($bulkUploadsFile), true) : [];
        $duplicate = null;
        
        foreach ($uploads as $upload) {
            if (($upload['file_hash'] ?? '') === $hash) {
                $duplicate = [
                    'uploaded_at' => $upload['uploaded_at'],
                    'uploaded_by' => $upload['uploaded_by'],
                    'order_count' => $upload['order_count']
                ];
                break;
            }
        }
        
        echo json_encode(['success' => true, 'duplicate' => $duplicate]);
        exit;
    }
    
    // Check for duplicate row content
    if ($action === 'check_duplicate_rows') {
        $rows = json_decode($_POST['rows'] ?? '[]', true);
        $duplicates = [];
        
        // Load all existing orders
        $ordersDir = __DIR__ . '/orders/';
        $allOrders = [];
        
        if (is_dir($ordersDir)) {
            foreach (glob($ordersDir . '*_orders.json') as $file) {
                $orders = json_decode(file_get_contents($file), true) ?? [];
                foreach ($orders as $order) {
                    $allOrders[] = $order;
                }
            }
        }
        
        // Check each row
        foreach ($rows as $idx => $row) {
            $rowHash = md5(strtolower(
                ($row['customer_name'] ?? '') . '|' .
                ($row['email'] ?? '') . '|' .
                ($row['event_prefix'] ?? '') . '|' .
                ($row['width'] ?? '') . '|' .
                ($row['height'] ?? '') . '|' .
                ($row['total'] ?? '') . '|' .
                ($row['submitted'] ?? '')
            ));
            
            foreach ($allOrders as $existing) {
                $existingHash = md5(strtolower(
                    ($existing['customer']['name'] ?? '') . '|' .
                    ($existing['customer']['email'] ?? '') . '|' .
                    (explode('-', $existing['referenceCode'] ?? '')[0] ?? '') . '|' .
                    ($existing['product']['width'] ?? '') . '|' .
                    ($existing['product']['height'] ?? '') . '|' .
                    ($existing['pricing']['total'] ?? '') . '|' .
                    ($existing['submittedAt'] ?? '')
                ));
                
                if ($rowHash === $existingHash) {
                    $duplicates[] = [
                        'row' => $idx,
                        'existing_order' => $existing['referenceCode']
                    ];
                    break;
                }
            }
        }
        
        echo json_encode(['success' => true, 'duplicates' => $duplicates]);
        exit;
    }
    
    // Create orders from uploaded data
    if ($action === 'create_orders') {
        $ordersData = json_decode($_POST['orders'] ?? '[]', true);
        $fileHash = $_POST['file_hash'] ?? '';
        
        if (empty($ordersData)) {
            echo json_encode(['success' => false, 'error' => 'No orders provided']);
            exit;
        }
        
        $created = [];
        $errors = [];
        $ordersByPrefix = [];
        
        // Group orders by prefix
        foreach ($ordersData as $idx => $order) {
            $prefix = strtoupper(trim($order['event_prefix'] ?? ''));
            if (!isset($ordersByPrefix[$prefix])) {
                $ordersByPrefix[$prefix] = [];
            }
            $ordersByPrefix[$prefix][] = ['index' => $idx, 'data' => $order];
        }
        
        // Track customer order counts for file matching
        $customerOrderCounts = [];
        
        // Process each prefix group
        foreach ($ordersByPrefix as $prefix => $prefixOrders) {
            $ordersFile = __DIR__ . '/orders/' . $prefix . '_orders.json';
            
            // Ensure orders directory exists
            if (!is_dir(__DIR__ . '/orders')) {
                mkdir(__DIR__ . '/orders', 0755, true);
            }
            
            // Load existing orders
            $existingOrders = [];
            if (file_exists($ordersFile)) {
                $existingOrders = json_decode(file_get_contents($ordersFile), true) ?? [];
            }
            
            // Find next order number
            $nextNum = 1;
            foreach ($existingOrders as $order) {
                $refCode = $order['referenceCode'] ?? '';
                if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/i', $refCode, $m)) {
                    $nextNum = max($nextNum, intval($m[1]) + 1);
                }
            }
            
            // Create each order
            foreach ($prefixOrders as $item) {
                $orderData = $item['data'];
                $rowIndex = $item['index'];
                
                try {
                    $referenceCode = $prefix . '-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                    $customerName = trim($orderData['customer_name'] ?? '');
                    
                    // Track customer order sequence for file matching
                    $customerKey = strtolower($customerName);
                    if (!isset($customerOrderCounts[$customerKey])) {
                        $customerOrderCounts[$customerKey] = 0;
                    }
                    $customerOrderCounts[$customerKey]++;
                    $customerSequence = $customerOrderCounts[$customerKey];
                    
                    // Determine payment status
                    $status = strtolower(trim($orderData['status'] ?? 'unpaid'));
                    $paymentMethod = strtolower(trim($orderData['payment_method'] ?? ''));
                    $paymentRef = trim($orderData['payment_reference'] ?? '');
                    
                    // If paid but no method specified, default to 'offline'
                    if ($status === 'paid' && empty($paymentMethod)) {
                        $paymentMethod = 'offline';
                    }
                    
                    // Build order structure
                    $newOrder = [
                        'referenceCode' => $referenceCode,
                        'customer' => [
                            'name' => $customerName,
                            'company' => trim($orderData['company'] ?? ''),
                            'email' => trim($orderData['email'] ?? ''),
                            'phone' => trim($orderData['phone'] ?? '')
                        ],
                        'delivery' => [
                            'method' => strtolower(trim($orderData['delivery_method'] ?? 'pickup')),
                            'location' => trim($orderData['booth_room'] ?? '')
                        ],
                        'product' => [
                            'type' => strtolower(trim($orderData['product_type'] ?? 'poster')),
                            'material' => trim($orderData['material'] ?? 'Paper'),
                            'width' => floatval($orderData['width'] ?? 0),
                            'height' => floatval($orderData['height'] ?? 0),
                            'quantity' => intval($orderData['quantity'] ?? 1)
                        ],
                        'pricing' => [
                            'tier' => strtolower(trim($orderData['priority'] ?? 'standard')),
                            'basePrice' => floatval($orderData['base_price'] ?? 0),
                            'rushFee' => floatval($orderData['rush_fee'] ?? 0),
                            'deliveryFee' => floatval($orderData['delivery_fee'] ?? 0),
                            'subtotal' => floatval($orderData['subtotal'] ?? 0),
                            'tax' => floatval($orderData['tax'] ?? 0),
                            'total' => floatval($orderData['total'] ?? 0)
                        ],
                        'payment' => [
                            'method' => $paymentMethod,
                            'reference' => $paymentRef,
                            'paidAt' => ($status === 'paid') ? date('c') : null
                        ],
                        'status' => $status,
                        'notes' => trim($orderData['notes'] ?? ''),
                        'submittedAt' => $orderData['submitted'] ?: date('Y-m-d H:i:s'),
                        'dueDate' => $orderData['due_date'] ?: null,
                        'uploadedFile' => null,
                        'bulkUpload' => [
                            'batchId' => $fileHash,
                            'uploadedAt' => date('c'),
                            'uploadedBy' => getCurrentAdminUsername(),
                            'customerSequence' => $customerSequence
                        ]
                    ];
                    
                    $existingOrders[] = $newOrder;
                    $created[] = [
                        'row' => $rowIndex + 1,
                        'reference' => $referenceCode,
                        'customer' => $customerName,
                        'email' => $newOrder['customer']['email'],
                        'total' => $newOrder['pricing']['total'],
                        'status' => $status,
                        'customerSequence' => $customerSequence
                    ];
                    
                    $nextNum++;
                    
                } catch (Exception $e) {
                    $errors[] = [
                        'row' => $rowIndex + 1,
                        'error' => $e->getMessage()
                    ];
                }
            }
            
            // Save orders file
            file_put_contents($ordersFile, json_encode($existingOrders, JSON_PRETTY_PRINT), LOCK_EX);
        }
        
        // Log bulk upload
        $uploads = file_exists($bulkUploadsFile) ? json_decode(file_get_contents($bulkUploadsFile), true) : [];
        $uploads[] = [
            'file_hash' => $fileHash,
            'uploaded_at' => date('c'),
            'uploaded_by' => getCurrentAdminUsername(),
            'order_count' => count($created),
            'orders' => array_column($created, 'reference')
        ];
        file_put_contents($bulkUploadsFile, json_encode(array_slice($uploads, -100), JSON_PRETTY_PRINT), LOCK_EX);
        
        // Log activity
        $logEntry = [
            'timestamp' => date('c'),
            'user' => getCurrentAdminUsername(),
            'action' => 'bulk_upload',
            'details' => count($created) . ' orders created via bulk upload',
            'orders' => array_column($created, 'reference')
        ];
        
        $logFile = __DIR__ . '/data/activity-log.json';
        $log = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : [];
        $log[] = $logEntry;
        file_put_contents($logFile, json_encode(array_slice($log, -1000), JSON_PRETTY_PRINT), LOCK_EX);
        
        echo json_encode([
            'success' => true,
            'created' => $created,
            'errors' => $errors,
            'summary' => [
                'total_created' => count($created),
                'total_errors' => count($errors),
                'batch_id' => $fileHash
            ]
        ]);
        exit;
    }
    
    // Send payment emails for unpaid orders
    if ($action === 'send_payment_emails') {
        $orderRefs = json_decode($_POST['orders'] ?? '[]', true);
        $sent = [];
        $failed = [];
        
        require_once __DIR__ . '/email-order-confirmation.php';
        
        foreach ($orderRefs as $ref) {
            $prefix = explode('-', $ref)[0];
            $ordersFile = __DIR__ . '/orders/' . $prefix . '_orders.json';
            
            if (!file_exists($ordersFile)) {
                $failed[] = ['reference' => $ref, 'error' => 'Order file not found'];
                continue;
            }
            
            $orders = json_decode(file_get_contents($ordersFile), true) ?? [];
            $order = null;
            
            foreach ($orders as $o) {
                if (($o['referenceCode'] ?? '') === $ref) {
                    $order = $o;
                    break;
                }
            }
            
            if (!$order) {
                $failed[] = ['reference' => $ref, 'error' => 'Order not found'];
                continue;
            }
            
            try {
                // Use existing payment link function if available
                if (function_exists('sendPaymentLinkEmail')) {
                    sendPaymentLinkEmail($order);
                    $sent[] = $ref;
                } else {
                    $failed[] = ['reference' => $ref, 'error' => 'Payment email function not available'];
                }
            } catch (Exception $e) {
                $failed[] = ['reference' => $ref, 'error' => $e->getMessage()];
            }
        }
        
        echo json_encode([
            'success' => true,
            'sent' => count($sent),
            'failed' => count($failed),
            'details' => ['sent' => $sent, 'failed' => $failed]
        ]);
        exit;
    }
    
    // Send confirmation emails for paid orders
    if ($action === 'send_confirmation_emails') {
        $orderRefs = json_decode($_POST['orders'] ?? '[]', true);
        $sent = [];
        $failed = [];
        
        require_once __DIR__ . '/email-order-confirmation.php';
        
        foreach ($orderRefs as $ref) {
            $prefix = explode('-', $ref)[0];
            $ordersFile = __DIR__ . '/orders/' . $prefix . '_orders.json';
            
            if (!file_exists($ordersFile)) {
                $failed[] = ['reference' => $ref, 'error' => 'Order file not found'];
                continue;
            }
            
            $orders = json_decode(file_get_contents($ordersFile), true) ?? [];
            $order = null;
            
            foreach ($orders as $o) {
                if (($o['referenceCode'] ?? '') === $ref) {
                    $order = $o;
                    break;
                }
            }
            
            if (!$order) {
                $failed[] = ['reference' => $ref, 'error' => 'Order not found'];
                continue;
            }
            
            try {
                if (function_exists('sendOrderConfirmationEmail')) {
                    sendOrderConfirmationEmail($order);
                    $sent[] = $ref;
                } else {
                    $failed[] = ['reference' => $ref, 'error' => 'Email function not available'];
                }
            } catch (Exception $e) {
                $failed[] = ['reference' => $ref, 'error' => $e->getMessage()];
            }
        }
        
        echo json_encode([
            'success' => true,
            'sent' => count($sent),
            'failed' => count($failed),
            'details' => ['sent' => $sent, 'failed' => $failed]
        ]);
        exit;
    }
    
    // Attach files to orders
    if ($action === 'attach_files') {
        $attachments = json_decode($_POST['attachments'] ?? '[]', true);
        $attached = [];
        $failed = [];
        
        foreach ($attachments as $att) {
            $ref = $att['reference'];
            $filename = $att['filename'];
            $fileData = $att['data'];
            
            $prefix = explode('-', $ref)[0];
            $ordersFile = __DIR__ . '/orders/' . $prefix . '_orders.json';
            
            if (!file_exists($ordersFile)) {
                $failed[] = ['reference' => $ref, 'error' => 'Order file not found'];
                continue;
            }
            
            $orders = json_decode(file_get_contents($ordersFile), true) ?? [];
            $found = false;
            
            foreach ($orders as &$order) {
                if (($order['referenceCode'] ?? '') === $ref) {
                    $uploadsDir = __DIR__ . '/uploads/';
                    if (!is_dir($uploadsDir)) {
                        mkdir($uploadsDir, 0755, true);
                    }
                    
                    $ext = pathinfo($filename, PATHINFO_EXTENSION);
                    $savedFilename = $ref . '_' . time() . '.' . $ext;
                    $filepath = $uploadsDir . $savedFilename;
                    
                    $decoded = base64_decode($fileData);
                    if ($decoded && file_put_contents($filepath, $decoded)) {
                        $order['uploadedFile'] = [
                            'originalName' => $filename,
                            'savedName' => $savedFilename,
                            'path' => 'uploads/' . $savedFilename,
                            'size' => strlen($decoded),
                            'uploadedAt' => date('c')
                        ];
                        $attached[] = $ref;
                        $found = true;
                    } else {
                        $failed[] = ['reference' => $ref, 'error' => 'Failed to save file'];
                    }
                    break;
                }
            }
            
            if ($found) {
                file_put_contents($ordersFile, json_encode($orders, JSON_PRETTY_PRINT), LOCK_EX);
            } elseif (!in_array($ref, array_column($failed, 'reference'))) {
                $failed[] = ['reference' => $ref, 'error' => 'Order not found'];
            }
        }
        
        echo json_encode([
            'success' => true,
            'attached' => count($attached),
            'failed' => count($failed),
            'details' => ['attached' => $attached, 'failed' => $failed]
        ]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Order Upload - MTCC Poster System</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-layout.css">
    <link rel="stylesheet" href="css/admin-orders.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Montserrat', sans-serif; background: #f8fafc; margin: 0; padding: 0; }
        .main-container { max-width: 1400px; margin: 0 auto; padding: 12px 0px; }
        .step-indicator { display: flex; justify-content: center; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; }
        .step { display: flex; align-items: center; gap: 8px; padding: 8px 16px; background: #e5e7eb; border-radius: 20px; font-size: 0.875rem; color: #6b7280; transition: all 0.3s; }
        .step.active { background: #7c3aed; color: white; }
        .step.completed { background: #10b981; color: white; }
        .step-number { width: 24px; height: 24px; border-radius: 50%; background: rgba(0,0,0,0.1); display: flex; align-items: center; justify-content: center; font-weight: 600; font-size: 0.75rem; }
        .step.active .step-number, .step.completed .step-number { background: rgba(255,255,255,0.2); }
        .upload-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 32px; }
        .upload-zone { border: 2px dashed #d1d5db; border-radius: 12px; padding: 48px; text-align: center; transition: all 0.3s; cursor: pointer; }
        .upload-zone:hover, .upload-zone.dragover { border-color: #7c3aed; background: #faf5ff; }
        .upload-icon { font-size: 48px; margin-bottom: 16px; }
        .upload-title { font-size: 1.25rem; font-weight: 600; color: #374151; margin-bottom: 8px; }
        .upload-subtitle { color: #6b7280; margin-bottom: 16px; }
        .upload-btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; background: #7c3aed; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        .upload-btn:hover { background: #6d28d9; }
        .upload-formats { margin-top: 16px; font-size: 0.75rem; color: #9ca3af; }
        .template-section { margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; }
        .template-info h3 { margin: 0 0 4px 0; font-size: 0.875rem; color: #374151; }
        .template-info p { margin: 0; font-size: 0.75rem; color: #6b7280; }
        .template-btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; text-decoration: none; }
        .template-btn:hover { background: #e5e7eb; }
        .upload-section, .review-section, .success-section, .files-section { display: none; }
        .upload-section.active, .review-section.active, .success-section.active, .files-section.active { display: block; }
        .summary-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
        .summary-card { background: white; border-radius: 12px; padding: 20px; text-align: center; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .summary-value { font-size: 2rem; font-weight: 700; color: #7c3aed; }
        .summary-card.warning .summary-value { color: #f59e0b; }
        .summary-card.success .summary-value { color: #10b981; }
        .summary-card.error .summary-value { color: #ef4444; }
        .summary-label { font-size: 0.875rem; color: #6b7280; margin-top: 4px; }
        .warnings-panel { background: #fffbeb; border: 1px solid #fcd34d; border-radius: 12px; padding: 16px; margin-bottom: 24px; }
        .warnings-panel.hidden { display: none; }
        .warnings-panel.error { background: #fef2f2; border-color: #fca5a5; }
        .warnings-header { display: flex; align-items: center; gap: 8px; font-weight: 600; color: #92400e; margin-bottom: 12px; }
        .warnings-panel.error .warnings-header { color: #991b1b; }
        .warning-item { display: flex; align-items: flex-start; gap: 8px; padding: 8px 0; font-size: 0.875rem; color: #78350f; border-bottom: 1px solid #fde68a; }
        .warnings-panel.error .warning-item { color: #991b1b; border-bottom-color: #fecaca; }
        .warning-item:last-child { border-bottom: none; }
        .warning-row { background: #fef3c7; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 0.75rem; }
        .warnings-panel.error .warning-row { background: #fee2e2; }
        .warning-actions { margin-top: 12px; }
        .warning-btn { padding: 6px 12px; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; border: none; }
        .warning-btn-override { background: #f59e0b; color: white; }
        .warning-btn-override:hover { background: #d97706; }
        .event-group { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 16px; overflow: hidden; }
        .event-group-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; cursor: pointer; }
        .event-group-header:hover { background: #f1f5f9; }
        .event-group-title { display: flex; align-items: center; gap: 12px; }
        .event-prefix { background: #7c3aed; color: white; padding: 4px 12px; border-radius: 6px; font-weight: 700; font-size: 0.875rem; }
        .event-count { color: #6b7280; font-size: 0.875rem; }
        .event-total { font-weight: 600; color: #059669; }
        .event-group-content { display: none; }
        .event-group.expanded .event-group-content { display: block; }
        .event-group.expanded .expand-icon { transform: rotate(180deg); }
        .expand-icon { transition: transform 0.2s; }
        .orders-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; }
        .orders-table th { text-align: left; padding: 10px 12px; background: #f8fafc; font-weight: 600; color: #374151; border-bottom: 1px solid #e5e7eb; }
        .orders-table td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .orders-table tr:hover { background: #faf5ff; }
        .orders-table tr.duplicate { background: #fef3c7; }
        .orders-table tr.deleted { background: #fee2e2; opacity: 0.5; text-decoration: line-through; }
        .orders-table .row-num { color: #9ca3af; font-size: 0.75rem; }
        .editable-cell input, .editable-cell select { width: 100%; padding: 4px 6px; border: 1px solid #d1d5db; border-radius: 4px; font-size: 0.8rem; font-family: inherit; }
        .editable-cell input:focus, .editable-cell select:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.2); }
        .editable-cell input[type="number"] { width: 60px; }
        .editable-cell.price input { width: 80px; text-align: right; }
        .delete-row-btn { background: none; border: none; color: #ef4444; cursor: pointer; padding: 4px; border-radius: 4px; opacity: 0.6; }
        .delete-row-btn:hover { opacity: 1; background: #fee2e2; }
        .restore-row-btn { background: #10b981; border: none; color: white; cursor: pointer; padding: 4px 8px; border-radius: 4px; font-size: 0.7rem; font-weight: 600; }
        .action-buttons { display: flex; justify-content: space-between; align-items: center; margin-top: 24px; padding-top: 24px; border-top: 1px solid #e5e7eb; flex-wrap: wrap; gap: 12px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size: 0.875rem; cursor: pointer; transition: all 0.2s; border: none; font-family: inherit; }
        .btn-primary { background: #7c3aed; color: white; }
        .btn-primary:hover { background: #6d28d9; }
        .btn-primary:disabled { background: #d1d5db; cursor: not-allowed; }
        .btn-secondary { background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; }
        .btn-secondary:hover { background: #e5e7eb; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-warning:hover { background: #d97706; }
        .success-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 48px; text-align: center; }
        .success-icon { font-size: 64px; margin-bottom: 16px; }
        .success-title { font-size: 1.5rem; font-weight: 700; color: #059669; margin-bottom: 8px; }
        .success-subtitle { color: #6b7280; margin-bottom: 24px; }
        .created-summary { background: #f0fdf4; border-radius: 8px; padding: 20px; margin: 24px auto; max-width: 500px; text-align: left; }
        .created-summary-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #bbf7d0; }
        .created-summary-item:last-child { border-bottom: none; }
        .created-prefix { font-weight: 600; color: #166534; }
        .created-range { color: #15803d; }
        .email-actions { display: flex; gap: 16px; justify-content: center; margin: 24px 0; padding: 20px; background: #f8fafc; border-radius: 8px; flex-wrap: wrap; }
        .email-action-card { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px; text-align: center; min-width: 200px; }
        .email-action-count { font-size: 1.5rem; font-weight: 700; margin-bottom: 4px; }
        .email-action-count.unpaid { color: #f59e0b; }
        .email-action-count.paid { color: #10b981; }
        .email-action-label { font-size: 0.875rem; color: #6b7280; margin-bottom: 12px; }
        .success-actions { display: flex; justify-content: center; gap: 12px; margin-top: 24px; flex-wrap: wrap; }
        .files-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); padding: 32px; }
        .files-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .files-title { font-size: 1.25rem; font-weight: 700; color: #374151; }
        .files-subtitle { font-size: 0.875rem; color: #6b7280; }
        .files-stats { display: flex; gap: 16px; }
        .files-stat { text-align: center; }
        .files-stat-value { font-size: 1.5rem; font-weight: 700; }
        .files-stat-value.pending { color: #f59e0b; }
        .files-stat-value.attached { color: #10b981; }
        .files-stat-label { font-size: 0.75rem; color: #6b7280; }
        .files-upload-zone { border: 2px dashed #d1d5db; border-radius: 12px; padding: 32px; text-align: center; margin-bottom: 24px; cursor: pointer; }
        .files-upload-zone:hover, .files-upload-zone.dragover { border-color: #7c3aed; background: #faf5ff; }
        .files-table { width: 100%; border-collapse: collapse; }
        .files-table th { text-align: left; padding: 12px; background: #f8fafc; font-weight: 600; font-size: 0.875rem; }
        .files-table td { padding: 12px; border-bottom: 1px solid #f1f5f9; }
        .file-status { display: inline-flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: 600; }
        .file-status.pending { background: #fef3c7; color: #92400e; }
        .file-status.attached { background: #d1fae5; color: #065f46; }
        .file-status.matched { background: #dbeafe; color: #1e40af; }
        @media (max-width: 768px) {
            .summary-cards { grid-template-columns: repeat(2, 1fr); }
            .email-actions { flex-direction: column; }
        }
    </style>
    </style>
<link rel="stylesheet" href="css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/admin-sidebar.php'; renderSidebar('orders'); ?>
<script src="js/admin-sidebar.js"></script>

<div class="admin-container" style="max-width: 1400px; margin: 0 auto; padding: 20px 0;">
    <!-- Page Header -->
        <div class="page-header" style= "margin-bottom:0px;">
            <div class="page-header-left">
                <h1 class="page-title">Bulk Order Upload</h1>
                <div class="page-welcome">
                    <span class="welcome-text">Import orders from Excel spreadsheet</span>
                    <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
                </div>
                </div>
                <div class="page-header-right">
                <a href="bulk-order-template.xlsx" download style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; color: white; text-decoration: none; font-weight: 600; font-size: 0.875rem;"><?= ICON_DOWNLOAD ?> Download Template</a>
                </div>
            
        </div>
    
</div>

<div class="main-container">
    <div class="step-indicator">
        <div class="step active" id="step1Indicator"><span class="step-number">1</span><span>Upload</span></div>
        <div class="step" id="step2Indicator"><span class="step-number">2</span><span>Review & Edit</span></div>
        <div class="step" id="step3Indicator"><span class="step-number">3</span><span>Complete</span></div>
        <div class="step" id="step4Indicator"><span class="step-number">4</span><span>Attach Files</span></div>
    </div>
    
    <div class="upload-section active" id="uploadSection">
        <div class="upload-card">
            <div class="upload-zone" id="uploadZone">
                <div class="upload-icon"><?= ICON_DOWNLOAD ?></div>
                <div class="upload-title">Drag & drop your Excel file here</div>
                <div class="upload-subtitle">or click to browse</div>
                <button class="upload-btn" onclick="document.getElementById('fileInput').click()"><?= ICON_FOLDER ?> Choose File</button>
                <div class="upload-formats">Supported formats: .xlsx, .xls</div>
                <input type="file" id="fileInput" accept=".xlsx,.xls" style="display:none">
            </div>
            <div class="template-section">
                <div class="template-info">
                    <h3><?= ICON_CLIPBOARD ?> Need a template?</h3>
                    <p>Download our Excel template with all required columns pre-formatted</p>
                </div>
                <a href="bulk-order-template.xlsx" download class="template-btn"><?= ICON_DOWNLOAD ?> Download Template</a>
            </div>
        </div>
    </div>
    
    <div class="review-section" id="reviewSection">
        <div class="summary-cards">
            <div class="summary-card"><div class="summary-value" id="totalOrders">0</div><div class="summary-label">Orders</div></div>
            <div class="summary-card"><div class="summary-value" id="totalEvents">0</div><div class="summary-label">Events</div></div>
            <div class="summary-card"><div class="summary-value" id="totalValue">$0</div><div class="summary-label">Total Value</div></div>
            <div class="summary-card" id="warningsCard"><div class="summary-value" id="totalWarnings">0</div><div class="summary-label">Warnings</div></div>
        </div>
        <div class="warnings-panel error hidden" id="duplicateFilePanel">
            <div class="warnings-header"><?= ICON_WARNING ?> Duplicate Spreadsheet Detected</div>
            <div id="duplicateFileMessage"></div>
            <div class="warning-actions"><button class="warning-btn warning-btn-override" onclick="overrideDuplicateFile()">Continue Anyway</button></div>
        </div>
        <div class="warnings-panel hidden" id="warningsPanel">
            <div class="warnings-header"><?= ICON_WARNING ?> Warnings Found</div>
            <div id="warningsList"></div>
        </div>
        <div class="warnings-panel error hidden" id="duplicateRowsPanel">
            <div class="warnings-header"><?= ICON_WARNING ?> Possible Duplicate Orders</div>
            <div id="duplicateRowsList"></div>
            <div class="warning-actions"><button class="warning-btn warning-btn-override" onclick="overrideDuplicateRows()">Create Anyway</button></div>
        </div>
        <div id="eventGroups"></div>
        <div class="action-buttons">
            <button class="btn btn-secondary" onclick="resetUpload()"><?= SYMBOL_ARROW_LEFT ?> Start Over</button>
            <div><span id="deletedCount" style="color:#6b7280;margin-right:16px;"></span><button class="btn btn-primary" id="createOrdersBtn" onclick="createOrders()"><?= ICON_CHECK_GREEN ?> Create <span id="createCount">0</span> Orders</button></div>
        </div>
    </div>
    
    <div class="success-section" id="successSection">
        <div class="success-card">
            <div class="success-icon"><?= ICON_CHECK_GREEN ?></div>
            <div class="success-title" id="successTitle">Orders Created!</div>
            <div class="success-subtitle">Your orders have been successfully imported</div>
            <div class="created-summary" id="createdSummary"></div>
            <div class="email-actions">
                <div class="email-action-card">
                    <div class="email-action-count unpaid" id="unpaidCount">0</div>
                    <div class="email-action-label">Unpaid Orders</div>
                    <button class="btn btn-warning" id="sendPaymentEmailsBtn" onclick="sendPaymentEmails()"><?= ICON_ENVELOPE ?> Send Payment Links</button>
                </div>
                <div class="email-action-card">
                    <div class="email-action-count paid" id="paidCount">0</div>
                    <div class="email-action-label">Paid Orders</div>
                    <button class="btn btn-success" id="sendConfirmationEmailsBtn" onclick="sendConfirmationEmails()"><?= ICON_ENVELOPE ?> Send Confirmations</button>
                </div>
            </div>
            <div class="success-actions">
                <button class="btn btn-secondary" onclick="resetUpload()"><?= ICON_DOWNLOAD ?> Upload More</button>
                <button class="btn btn-primary" onclick="showFilesSection()"><?= ICON_FOLDER ?> Attach Files</button>
                <a href="admin-orders.php" class="btn btn-secondary"><?= ICON_EYE ?> View Orders</a>
            </div>
        </div>
    </div>
    
    <div class="files-section" id="filesSection">
        <div class="files-card">
            <div class="files-header">
                <div>
                    <div class="files-title"><?= ICON_FOLDER ?> Attach Files to Orders</div>
                    <div class="files-subtitle">Upload files named by customer (e.g., "John Smith.pdf")</div>
                </div>
                <div class="files-stats">
                    <div class="files-stat"><div class="files-stat-value pending" id="filesPending">0</div><div class="files-stat-label">Pending</div></div>
                    <div class="files-stat"><div class="files-stat-value attached" id="filesAttached">0</div><div class="files-stat-label">Attached</div></div>
                </div>
            </div>
            <div class="files-upload-zone" id="filesUploadZone">
                <div style="font-size:32px;margin-bottom:12px;"><?= ICON_FOLDER ?></div>
                <div style="font-weight:600;margin-bottom:8px;">Drag & drop PDF files here</div>
                <div style="color:#6b7280;font-size:0.875rem;">Files will be matched by customer name</div>
                <input type="file" id="filesInput" accept=".pdf,.jpg,.jpeg,.png" multiple style="display:none">
            </div>
            <table class="files-table"><thead><tr><th>Order #</th><th>Customer</th><th>Expected Filename</th><th>Status</th><th>Action</th></tr></thead><tbody id="filesTableBody"></tbody></table>
            <div class="action-buttons">
                <button class="btn btn-secondary" onclick="showSuccessSection()"><?= SYMBOL_ARROW_LEFT ?> Back</button>
                <button class="btn btn-primary" id="attachFilesBtn" onclick="attachMatchedFiles()" disabled><?= ICON_CHECK_GREEN ?> Attach <span id="matchedCount">0</span> Files</button>
            </div>
        </div>
    </div>
</div>

<!-- PHP-injected config for external JS -->
<script>const BULK_UPLOAD_CONFIG = { eventPrefixes: <?= json_encode($eventPrefixes) ?> };</script>
<script src="js/shared/utils.js"></script>
<script src="js/admin-bulk-upload.js"></script>
</body>
</html>
