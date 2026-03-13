<?php
/**
 * Vendor Portal API
 * Handles AJAX actions and file downloads for logged-in vendors
 * 
 * Location: /fulfillment/api.php
 * 
 * Actions:
 *   approve       - Approve file for print (preflight → printing)
 *   confirm       - Alias for approve (backward compat)
 *   mark_ready    - Mark order as printed (printing → ready)
 *   revert_to_printing - Revert printed order back to printing (ready → printing)
 *   flag_issue    - Flag file issue with reason
 *   add_note      - Add vendor note to an order
 *   delete_note   - Delete a vendor note
 *   submit_price  - Submit vendor pricing for admin review
 *   accept_price  - Admin accepts submitted price (God Mode only)
 *   reject_price  - Admin rejects submitted price with reason (God Mode only)
 *   update_packing - Admin updates packing method for an order
 *   download      - Secure file download (GET only)
 *   bulk_download - ZIP download for 2+ files (GET only)
 *   check_new     - Poll for new order count
 * 
 * Admin god_mode: Bypasses ownership checks, can act on any order.
 * Admin super_admin: Can download files but cannot perform status actions.
 */

require_once 'vendor-auth.php';

// Email notifications
$emailFulfillmentPath = __DIR__ . '/../email-fulfillment.php';
$emailSmtpPath = __DIR__ . '/../email-status-notifications.php';
if (file_exists($emailSmtpPath)) require_once $emailSmtpPath;
if (file_exists($emailFulfillmentPath)) require_once $emailFulfillmentPath;

// GET requests: download and bulk_download
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    requireVendorLogin();
    if ($_GET['action'] === 'download') { handleDownload(); exit; }
    if ($_GET['action'] === 'bulk_download') { handleBulkDownload(); exit; }
}

// All other actions are POST with JSON body
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isVendorLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$vendorId = getCurrentVendorId();

$basePath = __DIR__ . '/../';
$preflightLogFile = $basePath . 'data/preflight-log.json';
$statusesFile = $basePath . 'data/statuses.json';
$ordersDir = $basePath . 'uploads/orders/';
$activityLogFile = $basePath . 'data/activity-log.json';

// ============================================
// HELPERS
// ============================================
function loadJsonSafe($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

function verifyVendorOwnership($refCode, $vendorId, $preflightLogFile) {
    $log = loadJsonSafe($preflightLogFile);
    $entry = $log['entries'][$refCode] ?? null;
    if (!$entry) return ['valid' => false, 'error' => 'Order not found in preflight log'];
    if (isAdminViewer()) return ['valid' => true, 'entry' => $entry, 'log' => $log];
    if (($entry['vendor_id'] ?? '') !== $vendorId) return ['valid' => false, 'error' => 'Not authorized for this order'];
    return ['valid' => true, 'entry' => $entry, 'log' => $log];
}

function findOrderFile($refCode, $ordersDir) {
    // Optimized: try targeted glob first
    foreach (glob($ordersDir . $refCode . '_*-order.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($data['referenceCode'] ?? '') === $refCode) return $data;
    }
    // Fallback: full scan
    foreach (glob($ordersDir . '*-order.json') as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($data['referenceCode'] ?? '') === $refCode) return $data;
    }
    return null;
}

function logActivity($file, $action, $refCode, $details, $user) {
    $log = loadJsonSafe($file);
    if (!isset($log['entries'])) $log['entries'] = [];
    $source = isAdminViewer() ? 'admin_via_vendor_portal' : 'vendor_portal';
    array_unshift($log['entries'], [
        'id' => uniqid('act_'),
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'order_ref' => $refCode,
        'details' => $details,
        'user' => $user,
        'source' => $source
    ]);
    $log['entries'] = array_slice($log['entries'], 0, 500);
    file_put_contents($file, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
}

// ============================================
// FULFILLMENT → DISPATCH BATCH BRIDGE
// ============================================
/**
 * When an order is marked ready, check if it belongs to a fulfillment batch.
 * If ALL orders in that batch are now ready, auto-create a dispatch batch.
 */
function checkBatchReadyForDispatch($refCode, $preflightLog, $statuses, $ordersDir) {
    $basePath = __DIR__ . '/../';
    $fbFile = $basePath . 'data/fulfillment-batches.json';
    $dispatchBatchFile = $basePath . 'data/batches.json';
    $statusesFile = $basePath . 'data/statuses.json';
    
    if (!file_exists($fbFile)) return;
    $fbData = json_decode(file_get_contents($fbFile), true) ?: ['batches' => []];
    
    // Find which fulfillment batch this order belongs to
    $myBatch = null;
    $myBatchIdx = null;
    foreach ($fbData['batches'] as $idx => $fb) {
        if ($fb['status'] === 'cancelled') continue;
        if (in_array($refCode, $fb['order_refs'])) {
            $myBatch = $fb;
            $myBatchIdx = $idx;
            break;
        }
    }
    
    if (!$myBatch) return; // Not in a batch
    if (!empty($myBatch['dispatch_batch_id'])) return; // Already flowed to dispatch
    
    // Load fresh statuses from disk (other orders may have been updated in previous requests)
    $freshStatuses = json_decode(file_get_contents($statusesFile), true) ?: [];
    
    // Check if ALL orders in this batch are now 'ready'
    $allReady = true;
    foreach ($myBatch['order_refs'] as $batchRef) {
        $status = $freshStatuses[$batchRef] ?? 'unknown';
        if ($status !== 'ready') {
            $allReady = false;
            break;
        }
    }
    
    if (!$allReady) return;
    
    // All ready — create dispatch batch
    $orderRefs = $myBatch['order_refs'];
    
    // Load dispatch batches file
    $dbData = ['active' => [], 'completed' => [], 'metadata' => ['last_batch_number' => 0, 'last_updated' => null, 'version' => '2.0']];
    if (file_exists($dispatchBatchFile)) {
        $dbData = json_decode(file_get_contents($dispatchBatchFile), true) ?: $dbData;
    }
    
    // Shared counter: max of both files + 1
    $fbNum = $fbData['metadata']['last_batch_number'] ?? 0;
    $dbNum = $dbData['metadata']['last_batch_number'] ?? 0;
    $nextNum = max($fbNum, $dbNum) + 1;
    $dispatchBatchId = 'B-' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
    
    // Update both counters
    $dbData['metadata']['last_batch_number'] = $nextNum;
    $fbData['batches'][$myBatchIdx]['dispatch_batch_id'] = $dispatchBatchId;
    $fbData['metadata']['last_batch_number'] = $nextNum;
    
    // Build order details from preflight log + order files
    $orderDetails = [];
    $destinations = [];
    $vendorName = '';
    $vendorAddress = '';
    $eventAcronym = '';
    
    foreach ($orderRefs as $ref) {
        $entry = $preflightLog['entries'][$ref] ?? [];
        $orderFile = findOrderFile($ref, $ordersDir);
        $orderData = $orderFile ? json_decode(file_get_contents($orderFile), true) : [];
        
        if (empty($vendorName)) $vendorName = $entry['vendor_name'] ?? 'Unknown';
        if (empty($eventAcronym)) $eventAcronym = explode('-', $ref)[0] ?? '';
        
        $customerName = $orderData['customerInfo']['name'] ?? '';
        $destination = $orderData['deliveryOption'] ?? 'pickup';
        $destLabel = 'MTCC';
        $destType = 'mtcc';
        $destAddress = '';
        
        if ($destination === 'delivery' && !empty($orderData['deliveryAddress'])) {
            $destLabel = $orderData['deliveryAddress']['formatted'] ?? 'Delivery Address';
            $destType = 'address';
            $destAddress = $destLabel;
        } else {
            // MTCC pickup
            $mtccLocation = $orderData['mtccLocation'] ?? 'MTCC North';
            $destLabel = $mtccLocation;
            $destType = 'mtcc';
        }
        
        $dueDate = !empty($orderData['selectedDate']) ? date('M j', strtotime($orderData['selectedDate'])) : 'TBD';
        $dueTime = $orderData['deliveryTime'] ?? 'anytime';
        $timeLabels = ['9am' => '9:00 AM', '12pm' => '12:00 PM', '3pm' => '3:00 PM', '6pm' => '6:00 PM', 'anytime' => 'Anytime'];
        $dueTimeFormatted = $timeLabels[$dueTime] ?? ucfirst($dueTime);
        
        $orderDetails[] = [
            'ref' => $ref,
            'customer_name' => $customerName,
            'destination' => $destLabel,
            'destination_type' => $destType,
            'due_date' => $dueDate,
            'due_time' => $dueTimeFormatted,
        ];
        
        if (!isset($destinations[$destLabel])) {
            $destinations[$destLabel] = [
                'label' => $destLabel,
                'type' => $destType,
                'address' => $destAddress,
                'count' => 0,
            ];
        }
        $destinations[$destLabel]['count']++;
    }
    
    // Look up vendor address
    $vendorsFile = $basePath . 'data/vendors.json';
    if (file_exists($vendorsFile)) {
        $vData = json_decode(file_get_contents($vendorsFile), true);
        $vendorId = $preflightLog['entries'][$orderRefs[0]]['vendor_id'] ?? '';
        foreach ($vData['vendors'] ?? [] as $v) {
            if ($v['id'] === $vendorId) {
                $vendorAddress = $v['address'] ?? '';
                break;
            }
        }
    }
    
    // Build pickup stop
    $pickupStop = [
        'type' => 'pickup',
        'name' => $vendorName,
        'address' => $vendorAddress,
        'vendor_phone' => '',
        'order_refs' => $orderRefs,
        'order_details' => array_map(function($d) {
            return ['ref' => $d['ref'], 'customer_name' => $d['customer_name'], 'material' => 'poster', 'size' => '', 'quantity' => 1];
        }, $orderDetails),
        'coords' => null,
        'status' => 'pending',
    ];
    
    // Build dropoff stops (grouped by destination)
    $dropoffStops = [];
    foreach ($destinations as $dest) {
        $destRefs = array_values(array_filter($orderRefs, function($ref) use ($orderDetails, $dest) {
            foreach ($orderDetails as $d) { if ($d['ref'] === $ref && $d['destination'] === $dest['label']) return true; }
            return false;
        }));
        $destOrderDetails = array_filter($orderDetails, function($d) use ($dest) { return $d['destination'] === $dest['label']; });
        
        $dropoffStops[] = [
            'type' => 'dropoff',
            'name' => $dest['label'],
            'address' => $dest['address'],
            'destination_instructions' => '',
            'customer_phone' => '',
            'order_refs' => $destRefs,
            'order_details' => array_values(array_map(function($d) { return ['ref' => $d['ref'], 'customer_name' => $d['customer_name'], 'material' => 'poster', 'size' => '', 'quantity' => 1]; }, $destOrderDetails)),
            'coords' => null,
            'status' => 'pending',
        ];
    }
    
    $stops = array_merge([$pickupStop], $dropoffStops);
    
    // Create hub-compatible dispatch batch
    $dispatchBatch = [
        'batch_id' => $dispatchBatchId,
        'created_at' => date('c'),
        'created_by' => 'auto_fulfillment',
        'auto_suggested' => true,
        'status' => 'pending',
        'order_refs' => $orderRefs,
        'orders' => $orderDetails,
        'order_count' => count($orderDetails),
        'destinations' => array_values($destinations),
        'stops' => $stops,
        'route' => ['distance_km' => 0, 'duration_min' => 0, 'estimated' => true, 'calculated_at' => date('c')],
        'payout' => ['total' => 0, 'breakdown' => []],
        'urgency' => ['level' => 'normal', 'hours_remaining' => 0, 'due_date_formatted' => '', 'due_time_formatted' => ''],
        'current_stop_index' => 0,
        'event_acronym' => $eventAcronym,
        'courier' => null,
        'courier_id' => '',
        'courier_name' => '',
        'notes' => 'Auto-created from fulfillment batch ' . $myBatch['batch_id'] . ($myBatch['label'] ? ' (' . $myBatch['label'] . ')' : ''),
        'fulfillment_batch_id' => $myBatch['batch_id'],
        'dispatched_at' => null,
        'completed_at' => null,
    ];
    
    $dbData['active'][] = $dispatchBatch;
    $dbData['metadata']['last_updated'] = date('c');
    file_put_contents($dispatchBatchFile, json_encode($dbData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    // Save fulfillment batch with dispatch link
    file_put_contents($fbFile, json_encode($fbData, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Log the auto-flow
    $activityFile = $basePath . 'data/activity-log.json';
    logActivity($activityFile, 'batch_auto_dispatched', $myBatch['batch_id'],
        'Fulfillment batch ' . $myBatch['batch_id'] . ' auto-created dispatch batch ' . $dispatchBatchId . ' (' . count($orderRefs) . ' orders)',
        'system');
}

// ============================================
// ACTION ROUTER
// ============================================
$statusActions = ['approve', 'confirm', 'mark_ready', 'flag_issue', 'revert_to_printing', 'submit_price', 'accept_price', 'reject_price', 'update_packing', 'update_packing_details', 'update_vendor_ref', 'toggle_paid', 'update_print_notes'];

// Vendor profile update — any logged-in vendor can edit their own profile
if ($action === 'update_vendor_profile') {
    handleUpdateVendorProfile($input, $vendorId);
    exit;
}

if (in_array($action, $statusActions) && !canPerformVendorActions()) {
    echo json_encode(['success' => false, 'error' => 'View-only access. Actions require God Mode.']);
    exit;
}

// "confirm" is now an alias for "approve"
if ($action === 'confirm') $action = 'approve';

switch ($action) {
    case 'approve':
        handleApprove($input, $vendorId, $preflightLogFile, $statusesFile, $ordersDir, $activityLogFile);
        break;
    case 'mark_ready':
        handleMarkReady($input, $vendorId, $preflightLogFile, $statusesFile, $ordersDir, $activityLogFile);
        break;
    case 'flag_issue':
        handleFlagIssue($input, $vendorId, $preflightLogFile, $statusesFile, $ordersDir, $activityLogFile);
        break;
    case 'revert_to_printing':
        handleRevertToPrinting($input, $vendorId, $preflightLogFile, $statusesFile, $activityLogFile);
        break;
    case 'add_note':
        handleAddNote($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'delete_note':
        handleDeleteNote($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'submit_price':
        handleSubmitPrice($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'submit_batch_price':
        handleSubmitBatchPrice($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'accept_price':
        handleAcceptPrice($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'reject_price':
        handleRejectPrice($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'update_packing':
        handleUpdatePacking($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'update_packing_details':
        handleUpdatePackingDetails($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'update_vendor_ref':
        handleUpdateVendorRef($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'toggle_paid':
        handleTogglePaid($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'update_print_notes':
        handleUpdatePrintNotes($input, $vendorId, $preflightLogFile, $activityLogFile);
        break;
    case 'check_new':
        handleCheckNew($vendorId, $preflightLogFile, $statusesFile);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

// ============================================
// ACTION: Approve Order (file reviewed & good for print)
// ============================================
function handleApprove($input, $vendorId, $preflightLogFile, $statusesFile, $ordersDir, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $entry = $check['entry'];

    $statuses = loadJsonSafe($statusesFile);
    if (($statuses[$refCode] ?? '') !== 'preflight') {
        echo json_encode(['success' => false, 'error' => 'Order is not awaiting approval']);
        return;
    }
    if (!empty($entry['confirmed_at'])) {
        echo json_encode(['success' => false, 'error' => 'Order already approved']);
        return;
    }

    $log['entries'][$refCode]['confirmed_at'] = date('c');
    $log['entries'][$refCode]['confirmed_via'] = isAdminViewer() ? 'admin_dashboard' : 'vendor_dashboard';
    $log['entries'][$refCode]['status'] = 'confirmed';
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $statuses[$refCode] = 'printing';
    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'vendor_approved', $refCode,
        'Order approved for print via ' . (isAdminViewer() ? 'admin (vendor portal)' : 'vendor dashboard'), getCurrentVendorName());

    // Email admin: vendor confirmed order, printing started
    if (function_exists('sendFulfillmentEmail')) {
        $entryVid = $log['entries'][$refCode]['vendor_id'] ?? '';
        $vendorInfo = function_exists('getVendorEmailById') ? getVendorEmailById($entryVid) : null;
        sendFulfillmentEmail('order_confirmed', $refCode, [
            'vendor_name' => $vendorInfo['name'] ?? $log['entries'][$refCode]['vendor_name'] ?? getCurrentVendorName(),
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Order approved']);
}

// ============================================
// ACTION: Mark as Printed / Ready to Ship
// ============================================
function handleMarkReady($input, $vendorId, $preflightLogFile, $statusesFile, $ordersDir, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $statuses = loadJsonSafe($statusesFile);
    if (($statuses[$refCode] ?? '') !== 'printing') {
        echo json_encode(['success' => false, 'error' => 'Order is not in printing status']);
        return;
    }

    $log = $check['log'];
    
    // Validate packing details are filled for box/tube
    $packing = $log['entries'][$refCode]['packing'] ?? 'none';
    if (in_array($packing, ['box', 'tube'])) {
        $packDetails = $log['entries'][$refCode]['packing_details'] ?? [];
        if ($packing === 'box') {
            $boxes = $packDetails['boxes'] ?? [];
            if (empty($boxes)) {
                echo json_encode(['success' => false, 'error' => 'Packing details required: please add box dimensions before marking ready']);
                return;
            }
        } elseif (empty($packDetails['qty']) || intval($packDetails['qty']) < 1) {
            echo json_encode(['success' => false, 'error' => 'Packing details required: please set quantity for tube packing before marking ready']);
            return;
        }
    }
    
    $log['entries'][$refCode]['ready_at'] = date('c');
    $log['entries'][$refCode]['status'] = 'ready';
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $statuses[$refCode] = 'ready';
    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'vendor_marked_ready', $refCode,
        'Marked as printed/ready via ' . (isAdminViewer() ? 'admin (vendor portal)' : 'vendor dashboard'), getCurrentVendorName());

    // Check if this order is part of a fulfillment batch — if all batch orders are now ready, auto-create dispatch batch
    checkBatchReadyForDispatch($refCode, $log, $statuses, $ordersDir);

    // Email admin: order ready for courier pickup
    if (function_exists('sendFulfillmentEmail')) {
        $entryVid = $log['entries'][$refCode]['vendor_id'] ?? '';
        $vendorInfo = function_exists('getVendorEmailById') ? getVendorEmailById($entryVid) : null;
        sendFulfillmentEmail('order_ready', $refCode, [
            'vendor_name' => $vendorInfo['name'] ?? $log['entries'][$refCode]['vendor_name'] ?? getCurrentVendorName(),
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Order marked as ready']);
}

// ============================================
// ACTION: Flag File Issue
// ============================================
function handleFlagIssue($input, $vendorId, $preflightLogFile, $statusesFile, $ordersDir, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    $reason = trim($input['reason'] ?? '');
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }
    if (empty($reason)) { echo json_encode(['success' => false, 'error' => 'Please describe the issue']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $log['entries'][$refCode]['file_issue_at'] = date('c');
    $log['entries'][$refCode]['file_issue_reason'] = $reason;
    $log['entries'][$refCode]['file_issue_by'] = isAdminViewer() ? 'admin' : 'vendor';
    $log['entries'][$refCode]['status'] = 'file_issue';
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $statuses = loadJsonSafe($statusesFile);
    $statuses[$refCode] = 'file_issue';
    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'vendor_file_issue', $refCode,
        'File issue flagged: ' . substr($reason, 0, 200), getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Issue flagged']);
}

// ============================================
// ACTION: Revert to Printing (ready → printing)
// ============================================
function handleRevertToPrinting($input, $vendorId, $preflightLogFile, $statusesFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $statuses = loadJsonSafe($statusesFile);
    $currentStatus = $statuses[$refCode] ?? '';
    if (!in_array($currentStatus, ['ready', 'ready_to_ship'])) {
        echo json_encode(['success' => false, 'error' => 'Order is not in printed/ready status']);
        return;
    }

    $log = $check['log'];
    $log['entries'][$refCode]['reverted_at'] = date('c');
    $log['entries'][$refCode]['reverted_by'] = getCurrentVendorName();
    $log['entries'][$refCode]['status'] = 'printing';
    unset($log['entries'][$refCode]['ready_at']);
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $statuses[$refCode] = 'printing';
    file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'vendor_reverted_to_printing', $refCode,
        'Order reverted to printing via ' . (isAdminViewer() ? 'admin (vendor portal)' : 'vendor dashboard'), getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Order moved back to printing']);
}

// ============================================
// ACTION: Add Vendor Note
// ============================================
function handleAddNote($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    $text = trim($input['text'] ?? '');
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }
    if (empty($text)) { echo json_encode(['success' => false, 'error' => 'Note text required']); return; }
    if (strlen($text) > 1000) { echo json_encode(['success' => false, 'error' => 'Note too long (max 1000 chars)']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    if (!isset($log['entries'][$refCode]['vendor_notes'])) {
        $log['entries'][$refCode]['vendor_notes'] = [];
    }

    $log['entries'][$refCode]['vendor_notes'][] = [
        'text' => $text,
        'timestamp' => date('c'),
        'by' => getCurrentVendorName()
    ];

    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'vendor_note_added', $refCode,
        'Note: ' . substr($text, 0, 200), getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Note added']);
}

// ============================================
// ACTION: Delete Vendor Note
// ============================================
function handleDeleteNote($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    $noteIndex = $input['note_index'] ?? null;
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }
    if ($noteIndex === null || !is_numeric($noteIndex)) { echo json_encode(['success' => false, 'error' => 'Note index required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $notes = $log['entries'][$refCode]['vendor_notes'] ?? [];
    $noteIndex = (int)$noteIndex;

    if ($noteIndex < 0 || $noteIndex >= count($notes)) {
        echo json_encode(['success' => false, 'error' => 'Note not found']);
        return;
    }

    $deletedText = substr($notes[$noteIndex]['text'] ?? '', 0, 100);
    array_splice($log['entries'][$refCode]['vendor_notes'], $noteIndex, 1);
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'vendor_note_deleted', $refCode,
        'Note deleted: ' . $deletedText, getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Note deleted']);
}

// ============================================
// ACTION: Submit Vendor Price
// ============================================
function handleSubmitPrice($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $basePrice = floatval($input['base_price'] ?? 0);
    $packingPrice = floatval($input['packing_price'] ?? 0);
    $taxRate = 0.13;

    // Parse additional fees
    $additionalFees = [];
    $feesTotal = 0;
    if (!empty($input['additional_fees']) && is_array($input['additional_fees'])) {
        foreach ($input['additional_fees'] as $fee) {
            $label = trim($fee['label'] ?? '');
            $amount = floatval($fee['amount'] ?? 0);
            if (!empty($label) && $amount > 0) {
                $additionalFees[] = ['label' => $label, 'amount' => $amount];
                $feesTotal += $amount;
            }
        }
    }

    if ($basePrice <= 0) { echo json_encode(['success' => false, 'error' => 'Base price must be greater than zero']); return; }
    if ($basePrice > 99999) { echo json_encode(['success' => false, 'error' => 'Price seems unreasonable']); return; }
    if ($packingPrice < 0) { $packingPrice = 0; }

    $subtotal = $basePrice + $packingPrice + $feesTotal;
    $taxAmount = round($subtotal * $taxRate, 2);
    $total = round($subtotal + $taxAmount, 2);

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $existing = $log['entries'][$refCode]['vendor_pricing'] ?? [];
    $existingStatus = $existing['status'] ?? 'none';

    // Can only submit if no price yet, previously rejected, or still under review (editing)
    if (!in_array($existingStatus, ['none', 'rejected', 'submitted', ''])) {
        echo json_encode(['success' => false, 'error' => 'Price already accepted and cannot be changed']);
        return;
    }

    // Build pricing record
    $pricing = [
        'base_price' => $basePrice,
        'packing_price' => $packingPrice,
        'additional_fees' => $additionalFees,
        'tax_rate' => $taxRate,
        'tax_amount' => $taxAmount,
        'total' => $total,
        'status' => 'submitted',
        'submitted_at' => date('c'),
        'reviewed_at' => null,
        'reviewed_by' => null,
        'rejection_reason' => null,
    ];

    // Preserve history if resubmitting after rejection
    $history = $existing['history'] ?? [];
    if ($existingStatus === 'rejected') {
        $history[] = [
            'action' => 'rejected',
            'base_price' => $existing['base_price'] ?? null,
            'total' => $existing['total'] ?? null,
            'reason' => $existing['rejection_reason'] ?? '',
            'at' => $existing['reviewed_at'] ?? null,
        ];
    }
    $pricing['history'] = $history;

    $log['entries'][$refCode]['vendor_pricing'] = $pricing;
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $feeDesc = '';
    if (!empty($additionalFees)) {
        $feeLabels = array_map(function($f) { return $f['label'] . ' $' . number_format($f['amount'], 2); }, $additionalFees);
        $feeDesc = ' + fees: ' . implode(', ', $feeLabels);
    }
    logActivity($activityLogFile, 'vendor_price_submitted', $refCode,
        'Price submitted: $' . number_format($total, 2) . ' (base $' . number_format($basePrice, 2) . ' + packing $' . number_format($packingPrice, 2) . $feeDesc . ' + tax $' . number_format($taxAmount, 2) . ')',
        getCurrentVendorName());

    // Email admin: price needs review
    if (function_exists('sendFulfillmentEmail')) {
        $vendorInfo = function_exists('getVendorEmailById') ? getVendorEmailById($vendorId) : null;
        sendFulfillmentEmail('price_submitted', $refCode, [
            'vendor_name' => $vendorInfo['name'] ?? getCurrentVendorName(),
            'pricing' => $pricing,
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Price submitted for review', 'total' => $total]);
}

// ============================================
// ACTION: Submit Batch Price
// ============================================
function handleSubmitBatchPrice($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $batchId = $input['batch_id'] ?? '';
    $items = $input['items'] ?? [];
    $batchPrintTotal = floatval($input['batch_print_total'] ?? 0);
    $tubePrice = floatval($input['tube_price'] ?? 0);
    $tubeQty = intval($input['tube_qty'] ?? 0);
    $tubeTotal = floatval($input['tube_total'] ?? 0);
    $taxRate = 0.13;
    $taxAmount = floatval($input['tax_amount'] ?? 0);
    $grandTotal = floatval($input['grand_total'] ?? 0);
    
    if (empty($batchId)) { echo json_encode(['success' => false, 'error' => 'Batch ID required']); return; }
    if (empty($items)) { echo json_encode(['success' => false, 'error' => 'No items in batch']); return; }
    if ($batchPrintTotal <= 0) { echo json_encode(['success' => false, 'error' => 'Batch total must be greater than zero']); return; }
    
    $log = loadJsonSafe($preflightLogFile);
    if (empty($log['entries'])) { echo json_encode(['success' => false, 'error' => 'No preflight data found']); return; }
    
    // Distribute tube cost across items by weight
    $tubePerItem = (count($items) > 0 && $tubeTotal > 0) ? $tubeTotal / count($items) : 0;
    
    $successCount = 0;
    foreach ($items as $item) {
        $ref = $item['ref'] ?? '';
        if (empty($ref) || !isset($log['entries'][$ref])) continue;
        
        // Verify vendor owns this order
        $entryVendor = $log['entries'][$ref]['vendor_id'] ?? '';
        if ($entryVendor !== $vendorId && !isAdminViewer()) continue;
        
        $basePrice = floatval($item['base_price'] ?? 0);
        if ($basePrice <= 0) continue;
        
        $packingPrice = round($tubePerItem, 2); // Allocated tube cost as packing
        $subtotal = $basePrice + $packingPrice;
        $itemTax = round($subtotal * $taxRate, 2);
        $itemTotal = round($subtotal + $itemTax, 2);
        
        $existing = $log['entries'][$ref]['vendor_pricing'] ?? [];
        $existingStatus = $existing['status'] ?? 'none';
        
        if (!in_array($existingStatus, ['none', 'rejected', 'submitted', ''])) continue;
        
        $history = $existing['history'] ?? [];
        if ($existingStatus === 'rejected') {
            $history[] = [
                'action' => 'rejected',
                'base_price' => $existing['base_price'] ?? null,
                'total' => $existing['total'] ?? null,
                'reason' => $existing['rejection_reason'] ?? '',
                'at' => $existing['reviewed_at'] ?? null,
            ];
        }
        
        $log['entries'][$ref]['vendor_pricing'] = [
            'base_price' => $basePrice,
            'packing_price' => $packingPrice,
            'additional_fees' => [],
            'tax_rate' => $taxRate,
            'tax_amount' => $itemTax,
            'total' => $itemTotal,
            'status' => 'submitted',
            'submitted_at' => date('c'),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
            'batch_pricing' => [
                'batch_id' => $batchId,
                'batch_print_total' => $batchPrintTotal,
                'allocation_weight' => floatval($item['weight'] ?? 0),
                'allocation_area' => intval($item['area'] ?? 0),
            ],
            'history' => $history,
        ];
        
        $successCount++;
    }
    
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Store batch-level pricing on the fulfillment batch record
    $basePath = __DIR__ . '/../';
    $fbFile = $basePath . 'data/fulfillment-batches.json';
    if (file_exists($fbFile)) {
        $fbData = json_decode(file_get_contents($fbFile), true) ?: ['batches' => []];
        foreach ($fbData['batches'] as &$fb) {
            if ($fb['batch_id'] === $batchId) {
                $fb['batch_pricing'] = [
                    'print_total' => $batchPrintTotal,
                    'tube_price' => $tubePrice,
                    'tube_qty' => $tubeQty,
                    'tube_total' => $tubeTotal,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'grand_total' => $grandTotal,
                    'submitted_at' => date('c'),
                    'submitted_by' => getCurrentVendorName(),
                ];
                break;
            }
        }
        unset($fb);
        file_put_contents($fbFile, json_encode($fbData, JSON_PRETTY_PRINT), LOCK_EX);
    }
    
    logActivity($activityLogFile, 'batch_price_submitted', $batchId,
        'Batch price submitted: $' . number_format($grandTotal, 2) . ' (' . $successCount . ' items, tubes: ' . $tubeQty . ' × $' . number_format($tubePrice, 2) . ')',
        getCurrentVendorName());
    
    // Email admin
    if (function_exists('sendFulfillmentEmail')) {
        $vendorInfo = function_exists('getVendorEmailById') ? getVendorEmailById($vendorId) : null;
        sendFulfillmentEmail('price_submitted', $batchId, [
            'vendor_name' => $vendorInfo['name'] ?? getCurrentVendorName(),
            'pricing' => ['total' => $grandTotal, 'batch_id' => $batchId, 'order_count' => $successCount],
        ]);
    }
    
    echo json_encode(['success' => true, 'message' => 'Batch price submitted for review (' . $successCount . ' items)', 'grand_total' => $grandTotal]);
}

// ============================================
// ACTION: Accept Vendor Price (admin only)
// ============================================
function handleAcceptPrice($input, $vendorId, $preflightLogFile, $activityLogFile) {
    if (!isAdminGodMode()) {
        echo json_encode(['success' => false, 'error' => 'Admin God Mode required']);
        return;
    }

    $refCode = $input['reference_code'] ?? '';
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $pricing = $log['entries'][$refCode]['vendor_pricing'] ?? [];

    if (($pricing['status'] ?? '') !== 'submitted') {
        echo json_encode(['success' => false, 'error' => 'No submitted price to accept']);
        return;
    }

    $log['entries'][$refCode]['vendor_pricing']['status'] = 'accepted';
    $log['entries'][$refCode]['vendor_pricing']['reviewed_at'] = date('c');
    $log['entries'][$refCode]['vendor_pricing']['reviewed_by'] = getCurrentVendorName();
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $total = $pricing['total'] ?? 0;
    logActivity($activityLogFile, 'price_accepted', $refCode,
        'Price accepted: $' . number_format($total, 2), getCurrentVendorName());

    // Email vendor: price approved, please confirm job
    if (function_exists('sendFulfillmentEmail')) {
        $entryVid = $log['entries'][$refCode]['vendor_id'] ?? '';
        $vendorInfo = function_exists('getVendorEmailById') ? getVendorEmailById($entryVid) : null;
        if ($vendorInfo && $vendorInfo['email']) {
            sendFulfillmentEmail('price_approved', $refCode, [
                'vendor_name' => $vendorInfo['name'],
                'vendor_email' => $vendorInfo['email'],
                'pricing' => $pricing,
                'admin_name' => getCurrentVendorName(),
            ]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Price accepted']);
}

// ============================================
// ACTION: Reject Vendor Price (admin only)
// ============================================
function handleRejectPrice($input, $vendorId, $preflightLogFile, $activityLogFile) {
    if (!isAdminGodMode()) {
        echo json_encode(['success' => false, 'error' => 'Admin God Mode required']);
        return;
    }

    $refCode = $input['reference_code'] ?? '';
    $reason = trim($input['reason'] ?? '');
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }
    if (empty($reason)) { echo json_encode(['success' => false, 'error' => 'Rejection reason required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $pricing = $log['entries'][$refCode]['vendor_pricing'] ?? [];

    if (($pricing['status'] ?? '') !== 'submitted') {
        echo json_encode(['success' => false, 'error' => 'No submitted price to reject']);
        return;
    }

    // Archive this attempt in history
    $history = $pricing['history'] ?? [];
    $history[] = [
        'action' => 'rejected',
        'base_price' => $pricing['base_price'] ?? null,
        'packing_price' => $pricing['packing_price'] ?? null,
        'total' => $pricing['total'] ?? null,
        'reason' => $reason,
        'rejected_at' => date('c'),
        'rejected_by' => getCurrentVendorName(),
    ];

    $log['entries'][$refCode]['vendor_pricing']['status'] = 'rejected';
    $log['entries'][$refCode]['vendor_pricing']['reviewed_at'] = date('c');
    $log['entries'][$refCode]['vendor_pricing']['reviewed_by'] = getCurrentVendorName();
    $log['entries'][$refCode]['vendor_pricing']['rejection_reason'] = $reason;
    $log['entries'][$refCode]['vendor_pricing']['history'] = $history;
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $total = $pricing['total'] ?? 0;
    logActivity($activityLogFile, 'price_rejected', $refCode,
        'Price rejected ($' . number_format($total, 2) . '): ' . substr($reason, 0, 200), getCurrentVendorName());

    // Email vendor: price rejected, please resubmit
    if (function_exists('sendFulfillmentEmail')) {
        $entryVid = $log['entries'][$refCode]['vendor_id'] ?? '';
        $vendorInfo = function_exists('getVendorEmailById') ? getVendorEmailById($entryVid) : null;
        if ($vendorInfo && $vendorInfo['email']) {
            sendFulfillmentEmail('price_rejected', $refCode, [
                'vendor_name' => $vendorInfo['name'],
                'vendor_email' => $vendorInfo['email'],
                'pricing' => $pricing,
                'reason' => $reason,
                'admin_name' => getCurrentVendorName(),
            ]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Price rejected']);
}

// ============================================
// ACTION: Update Packing (admin only)
// ============================================
function handleUpdatePacking($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    $packing = $input['packing'] ?? 'none';
    $packingCustom = trim($input['packing_custom'] ?? '');
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $validTypes = ['none', 'tube', 'box', 'flat', 'custom'];
    if (!in_array($packing, $validTypes)) $packing = 'none';

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $log['entries'][$refCode]['packing'] = $packing;
    $log['entries'][$refCode]['packing_custom'] = $packingCustom;
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $label = $packing === 'custom' && $packingCustom ? $packingCustom : ucfirst($packing);
    logActivity($activityLogFile, 'packing_updated', $refCode,
        'Packing set to: ' . $label, getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Packing updated to ' . $label]);
}

// ============================================
// ACTION: Update Packing Details (qty, dimensions, weight)
// ============================================
function handleUpdatePackingDetails($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    $details = $input['packing_details'] ?? [];
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $log['entries'][$refCode]['packing_details'] = [
        'qty' => intval($details['qty'] ?? 0),
        'dimensions' => trim($details['dimensions'] ?? ''),
        'weight' => trim($details['weight'] ?? ''),
    ];
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'packing_details_updated', $refCode,
        'Packing details: qty ' . ($details['qty'] ?? 0), getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Packing details saved']);
}

// ============================================
// ACTION: Update Vendor Order Number
// ============================================
function handleUpdateVendorRef($input, $vendorId, $preflightLogFile, $activityLogFile) {
    $refCode = $input['reference_code'] ?? '';
    $vendorOrderNum = trim($input['vendor_order_number'] ?? '');
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { echo json_encode(['success' => false, 'error' => $check['error']]); return; }

    $log = $check['log'];
    $log['entries'][$refCode]['vendor_order_number'] = $vendorOrderNum;
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    if ($vendorOrderNum) {
        logActivity($activityLogFile, 'vendor_ref_updated', $refCode,
            'Vendor order # set: ' . $vendorOrderNum, getCurrentVendorName());
    }

    echo json_encode(['success' => true, 'message' => 'Vendor reference saved']);
}

// ============================================
// ACTION: Toggle Vendor Payment (admin only)
// ============================================
function handleTogglePaid($input, $vendorId, $preflightLogFile, $activityLogFile) {
    if (!isAdminViewer()) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        return;
    }

    $refCode = $input['reference_code'] ?? '';
    $paid = !empty($input['paid']);
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $log = loadJsonSafe($preflightLogFile);
    if (!isset($log['entries'][$refCode])) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }

    $log['entries'][$refCode]['vendor_paid'] = $paid;
    if ($paid) {
        $log['entries'][$refCode]['vendor_paid_at'] = date('c');
        $log['entries'][$refCode]['vendor_paid_by'] = getCurrentVendorName();
    } else {
        $log['entries'][$refCode]['vendor_paid_at'] = null;
        $log['entries'][$refCode]['vendor_paid_by'] = null;
    }
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    $action = $paid ? 'vendor_marked_paid' : 'vendor_marked_unpaid';
    logActivity($activityLogFile, $action, $refCode,
        $paid ? 'Vendor payment marked as paid' : 'Vendor payment marked as unpaid',
        getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => $paid ? 'Marked as paid' : 'Marked as unpaid']);
}

// ============================================
// ACTION: Update Print Notes (admin only)
// ============================================
function handleUpdatePrintNotes($input, $vendorId, $preflightLogFile, $activityLogFile) {
    if (!isAdminViewer()) {
        echo json_encode(['success' => false, 'error' => 'Admin access required']);
        return;
    }

    $refCode = $input['reference_code'] ?? '';
    $notes = trim($input['print_notes'] ?? '');
    if (empty($refCode)) { echo json_encode(['success' => false, 'error' => 'Reference code required']); return; }

    $log = loadJsonSafe($preflightLogFile);
    if (!isset($log['entries'][$refCode])) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }

    $log['entries'][$refCode]['print_notes'] = $notes;
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    logActivity($activityLogFile, 'print_notes_updated', $refCode,
        'Print instructions updated: ' . substr($notes, 0, 100),
        getCurrentVendorName());

    echo json_encode(['success' => true, 'message' => 'Print instructions saved']);
}

// ============================================
// ACTION: Update Vendor Profile (vendor self-service)
// ============================================
function handleUpdateVendorProfile($input, $vendorId) {
    if ($vendorId === 'all' || empty($vendorId)) {
        echo json_encode(['success' => false, 'error' => 'No vendor context']);
        return;
    }

    $vendorsFile = __DIR__ . '/../data/vendors.json';
    if (!file_exists($vendorsFile)) {
        echo json_encode(['success' => false, 'error' => 'Vendors file not found']);
        return;
    }

    $data = json_decode(file_get_contents($vendorsFile), true) ?: ['vendors' => []];
    $found = false;

    foreach ($data['vendors'] as &$vendor) {
        if ($vendor['id'] === $vendorId) {
            // Vendors can update these fields
            if (isset($input['business_name'])) $vendor['business_name'] = trim($input['business_name']);
            if (isset($input['contact_name'])) $vendor['contact_name'] = trim($input['contact_name']);
            if (isset($input['phone'])) $vendor['phone'] = trim($input['phone']);
            if (isset($input['address'])) $vendor['address'] = trim($input['address']);

            // Business hours
            if (isset($input['business_hours']) && is_array($input['business_hours'])) {
                $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
                $hours = [];
                foreach ($days as $day) {
                    $dh = $input['business_hours'][$day] ?? [];
                    $hours[$day] = [
                        'open' => trim($dh['open'] ?? ''),
                        'close' => trim($dh['close'] ?? ''),
                        'closed' => !empty($dh['closed'])
                    ];
                }
                $vendor['business_hours'] = $hours;
            }

            $vendor['updated_at'] = date('c');

            // Validate
            if (empty($vendor['business_name'])) {
                echo json_encode(['success' => false, 'error' => 'Business name is required']);
                return;
            }

            $found = true;
            break;
        }
    }
    unset($vendor);

    if (!$found) {
        echo json_encode(['success' => false, 'error' => 'Vendor not found']);
        return;
    }

    file_put_contents($vendorsFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    // Update session name if changed
    if (!empty($input['business_name'])) {
        $_SESSION['vendor_name'] = trim($input['business_name']);
    }

    echo json_encode(['success' => true, 'message' => 'Profile updated']);
}

// ============================================
// ACTION: Check for New Orders (polling)
// ============================================
function handleCheckNew($vendorId, $preflightLogFile, $statusesFile) {
    $log = loadJsonSafe($preflightLogFile);
    $statuses = loadJsonSafe($statusesFile);
    $count = 0;
    foreach ($log['entries'] ?? [] as $refCode => $entry) {
        $match = ($vendorId === 'all') || (($entry['vendor_id'] ?? '') === $vendorId);
        if ($match && empty($entry['confirmed_at']) && ($statuses[$refCode] ?? '') === 'preflight') $count++;
    }
    echo json_encode(['success' => true, 'new_count' => $count]);
}

// ============================================
// ACTION: Secure File Download (GET)
// ============================================
function handleDownload() {
    $refCode = $_GET['ref'] ?? '';
    $vendorId = getCurrentVendorId();
    if (empty($refCode)) { http_response_code(400); die('Missing reference code'); }

    $basePath = __DIR__ . '/../';
    $preflightLogFile = $basePath . 'data/preflight-log.json';
    $ordersDir = $basePath . 'uploads/orders/';
    $filesDir = $basePath . 'uploads/files/';

    $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
    if (!$check['valid']) { http_response_code(403); die('Access denied: ' . ($check['error'] ?? 'unknown')); }

    $order = findOrderFile($refCode, $ordersDir);
    if (!$order) { http_response_code(404); die('Order data not found for: ' . htmlspecialchars($refCode)); }

    $originalName = $order['uploadedFile']['originalName'] ?? null;
    $storedPath = $order['uploadedFile']['path'] ?? '';
    $storedName = $order['uploadedFile']['storedName'] ?? '';

    $filePath = null;

    // Strategy 1: storedPath is absolute and exists
    if ($storedPath && substr($storedPath, 0, 1) === '/' && file_exists($storedPath)) {
        $filePath = $storedPath;
    }

    // Strategy 2: storedPath is relative — resolve from basePath
    if (!$filePath && $storedPath) {
        $candidate = $basePath . ltrim($storedPath, '/');
        if (file_exists($candidate)) $filePath = $candidate;
    }

    // Strategy 3: storedName — look in uploads/files/
    if (!$filePath && $storedName && file_exists($filesDir . $storedName)) {
        $filePath = $filesDir . $storedName;
    }

    // Strategy 4: glob by ref code in uploads/files/
    if (!$filePath) {
        $matches = glob($filesDir . $refCode . '_*');
        if (!empty($matches)) {
            $filePath = $matches[0]; // Take the first match
        }
    }

    // Strategy 5: glob by ref code with dash (MST-014-*)
    if (!$filePath) {
        $matches = glob($filesDir . $refCode . '-*');
        if (!empty($matches)) {
            $filePath = $matches[0];
        }
    }

    if (!$filePath) {
        http_response_code(404);
        die('File not found on server for: ' . htmlspecialchars($refCode) . '. Stored path: ' . htmlspecialchars($storedPath ?: 'empty'));
    }

    // Build download name: REF-01-originalname.ext
    if (!$originalName) {
        $originalName = basename($filePath);
    }
    if (strpos($originalName, $refCode) === 0) {
        $downloadName = $originalName; // Already prefixed
    } else {
        $downloadName = $refCode . '-01-' . $originalName;
    }

    // Track download
    $log = $check['log'];
    if (!isset($log['entries'][$refCode]['vendor_downloads'])) $log['entries'][$refCode]['vendor_downloads'] = 0;
    $log['entries'][$refCode]['vendor_downloads']++;
    $log['entries'][$refCode]['last_download_at'] = date('c');
    file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);

    // Preview mode: serve inline with correct MIME type (for thumbnail hover)
    $isPreview = isset($_GET['preview']);
    if ($isPreview) {
        $mimeTypes = [
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
            'tiff' => 'image/tiff', 'tif' => 'image/tiff', 'svg' => 'image/svg+xml',
        ];
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=3600');
    } else {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-store');
    }
    readfile($filePath);
    exit;
}

// ============================================
// ACTION: Bulk ZIP Download (GET, 2+ files)
// ============================================
function handleBulkDownload() {
    $refsStr = $_GET['refs'] ?? '';
    $vendorId = getCurrentVendorId();
    if (empty($refsStr)) { http_response_code(400); die('Missing reference codes'); }

    $refs = array_filter(array_map('trim', explode(',', $refsStr)));
    if (count($refs) < 1) { http_response_code(400); die('No valid references'); }

    $basePath = __DIR__ . '/../';
    $preflightLogFile = $basePath . 'data/preflight-log.json';
    $ordersDir = $basePath . 'uploads/orders/';
    $filesDir = $basePath . 'uploads/files/';

    // Check ZipArchive support
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        die('ZIP support not available on this server');
    }

    // Collect valid files
    $files = [];
    foreach ($refs as $refCode) {
        $check = verifyVendorOwnership($refCode, $vendorId, $preflightLogFile);
        if (!$check['valid']) continue;

        $order = findOrderFile($refCode, $ordersDir);
        if (!$order) continue;

        $originalName = $order['uploadedFile']['originalName'] ?? null;
        $storedPath = $order['uploadedFile']['path'] ?? '';
        $storedName = $order['uploadedFile']['storedName'] ?? '';

        // Same 5-strategy path resolution as single download
        $filePath = null;
        if ($storedPath && substr($storedPath, 0, 1) === '/' && file_exists($storedPath)) {
            $filePath = $storedPath;
        }
        if (!$filePath && $storedPath) {
            $candidate = $basePath . ltrim($storedPath, '/');
            if (file_exists($candidate)) $filePath = $candidate;
        }
        if (!$filePath && $storedName && file_exists($filesDir . $storedName)) {
            $filePath = $filesDir . $storedName;
        }
        if (!$filePath) {
            $matches = glob($filesDir . $refCode . '_*');
            if (!empty($matches)) $filePath = $matches[0];
        }
        if (!$filePath) {
            $matches = glob($filesDir . $refCode . '-*');
            if (!empty($matches)) $filePath = $matches[0];
        }
        if (!$filePath) continue;

        if (!$originalName) $originalName = basename($filePath);
        $dlName = (strpos($originalName, $refCode) === 0) ? $originalName : $refCode . '-01-' . $originalName;

        $files[] = [
            'path' => $filePath,
            'name' => $dlName,
            'ref' => $refCode
        ];

        // Track download
        $log = $check['log'];
        if (!isset($log['entries'][$refCode]['vendor_downloads'])) $log['entries'][$refCode]['vendor_downloads'] = 0;
        $log['entries'][$refCode]['vendor_downloads']++;
        $log['entries'][$refCode]['last_download_at'] = date('c');
        file_put_contents($preflightLogFile, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
    }

    if (empty($files)) { http_response_code(404); die('No downloadable files found'); }

    // Create temp ZIP
    $tmpFile = tempnam(sys_get_temp_dir(), 'vendor_dl_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE) !== true) {
        http_response_code(500);
        die('Could not create ZIP file');
    }

    foreach ($files as $f) {
        $zip->addFile($f['path'], $f['name']);
    }
    $zip->close();

    // Stream ZIP
    $zipName = 'orders_' . date('Y-m-d_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpFile));
    header('Cache-Control: no-store');
    readfile($tmpFile);
    unlink($tmpFile);
    exit;
}
