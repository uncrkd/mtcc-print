<?php
/**
 * Production Management - MTCC Poster System
 * Manages vendor order pushing and vendor accounts
 * 
 * Phase 4: Full Queue Implementation
 * 
 * Tabs:
 *   - Queue: Orders ready for preflight (paid status)
 *   - In Progress: Orders pushed to vendors (preflight status)
 *   - File Issues: Orders with file problems
 *   - Vendors: Vendor account management
 * 
 * Location: /admin/production.php
 */

require_once '../admin-auth.php';

// Require at least view permission for preflight
requireAnyPermission(['preflight_edit', 'preflight_view', 'orders_edit']);

// Permission helper variables
$canEditPreflight = hasPermission('preflight_edit') || hasPermission('orders_edit');
$canViewPreflight = hasAnyPermission(['preflight_edit', 'preflight_view', 'orders_edit']);

// Paths
$basePath = '../';
$vendorsFile = $basePath . 'data/vendors.json';
$statusesFile = $basePath . 'data/statuses.json';
$ordersDir = $basePath . 'uploads/orders/';
$preflightLogFile = $basePath . 'data/preflight-log.json';
$tokensFile = $basePath . 'data/vendor-tokens.json';
$reminderLogFile = $basePath . 'data/reminder-log.json';
$reminderConfigFile = $basePath . 'data/reminder-config.json';
$fulfillmentBatchesFile = $basePath . 'data/fulfillment-batches.json';

// Configuration
define('MAX_VENDORS', 10);

// Load icons
require_once $basePath . 'includes/icons.php';
if (file_exists($basePath . 'includes/utilities.php')) {
    require_once $basePath . 'includes/utilities.php';
}

// Current tab
$currentTab = $_GET['tab'] ?? 'queue';

// ============================================
// AJAX HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if (!$canEditPreflight) {
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }
    
    $action = $_POST['ajax_action'];
    
    switch ($action) {
        // Queue actions
        case 'push_to_vendor':
            $result = pushOrderToVendor($_POST, $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile);
            echo json_encode($result);
            break;
            
        case 'push_multiple':
            $result = pushMultipleOrders($_POST, $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile);
            echo json_encode($result);
            break;
            
        case 'push_batch':
            $result = pushBatchToVendor($_POST, $vendorsFile, $statusesFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile, $fulfillmentBatchesFile);
            echo json_encode($result);
            break;
            
        case 'mark_file_issue':
            $result = markFileIssue($_POST, $statusesFile, $ordersDir);
            echo json_encode($result);
            break;
            
        case 'resolve_file_issue':
            $result = resolveFileIssue($_POST, $statusesFile);
            echo json_encode($result);
            break;
            
        case 'mark_confirmed':
            $result = markVendorConfirmed($_POST, $statusesFile, $preflightLogFile);
            echo json_encode($result);
            break;
            
        case 'mark_printing':
            $result = updateOrderStatus($_POST['reference_code'], 'printing', $statusesFile);
            echo json_encode($result);
            break;
            
        case 'mark_ready':
            $result = updateOrderStatus($_POST['reference_code'], 'ready', $statusesFile);
            echo json_encode($result);
            break;
            
        case 'resend_vendor_email':
            $result = resendVendorEmail($_POST, $vendorsFile, $ordersDir, $preflightLogFile, $basePath, $tokensFile);
            echo json_encode($result);
            break;
            
        case 'regenerate_token':
            $result = regenerateOrderToken($_POST, $preflightLogFile, $tokensFile);
            echo json_encode($result);
            break;
            
        case 'get_order_details':
            $result = getOrderPreflightDetails($_POST['reference_code'], $ordersDir, $preflightLogFile, $tokensFile, $vendorsFile, $reminderLogFile);
            echo json_encode($result);
            break;

        case 'add_order_note':
            $refCode = $_POST['reference_code'] ?? '';
            $text = trim($_POST['text'] ?? '');
            $visibleToVendor = !empty($_POST['visible_to_vendor']);
            if (empty($refCode) || empty($text)) {
                echo json_encode(['success' => false, 'error' => 'Reference code and text required']);
                break;
            }
            $order = findOrderByRef($refCode, $ordersDir);
            if (!$order) {
                echo json_encode(['success' => false, 'error' => 'Order not found']);
                break;
            }
            if (!isset($order['internalNotes'])) $order['internalNotes'] = [];
            $order['internalNotes'][] = [
                'id' => uniqid(),
                'username' => $currentUser['display_name'] ?? 'Admin',
                'content' => $text,
                'timestamp' => date('Y-m-d H:i:s'),
                'visible_to_vendor' => $visibleToVendor
            ];
            $orderFile = findOrderFile($refCode, $ordersDir);
            if ($orderFile) {
                file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            echo json_encode(['success' => true]);
            break;

        case 'delete_order_note':
            $refCode = $_POST['reference_code'] ?? '';
            $noteIndex = intval($_POST['note_index'] ?? -1);
            if (empty($refCode) || $noteIndex < 0) {
                echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
                break;
            }
            $order = findOrderByRef($refCode, $ordersDir);
            if (!$order || !isset($order['internalNotes'][$noteIndex])) {
                echo json_encode(['success' => false, 'error' => 'Note not found']);
                break;
            }
            array_splice($order['internalNotes'], $noteIndex, 1);
            $orderFile = findOrderFile($refCode, $ordersDir);
            if ($orderFile) {
                file_put_contents($orderFile, json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
            echo json_encode(['success' => true]);
            break;

        case 'send_manual_reminder':
            $result = sendManualReminder($_POST, $vendorsFile, $ordersDir, $preflightLogFile, $tokensFile, $reminderLogFile, $basePath);
            echo json_encode($result);
            break;
            
        case 'get_reminder_stats':
            $result = getReminderStats($reminderLogFile, $reminderConfigFile);
            echo json_encode($result);
            break;
            
        case 'create_fulfillment_batch':
            $result = createFulfillmentBatch($_POST, $fulfillmentBatchesFile);
            echo json_encode($result);
            break;
            
        case 'remove_from_batch':
            $result = removeFromFulfillmentBatch($_POST, $fulfillmentBatchesFile);
            echo json_encode($result);
            break;
            
        case 'add_to_batch':
            $result = addToFulfillmentBatch($_POST, $fulfillmentBatchesFile);
            echo json_encode($result);
            break;
            
        case 'delete_batch':
            $result = deleteFulfillmentBatch($_POST, $fulfillmentBatchesFile);
            echo json_encode($result);
            break;
            
        case 'edit_batch':
            $result = editFulfillmentBatch($_POST, $fulfillmentBatchesFile);
            echo json_encode($result);
            break;
            
        default:
        case 'bulk_approve':
            $refCodes = json_decode($_POST['reference_codes'] ?? '[]', true);
            $successCount = 0;
            $errors = [];
            foreach ($refCodes as $refCode) {
                $result = markVendorConfirmed(['reference_code' => $refCode], $statusesFile, $preflightLogFile);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errors[] = "$refCode: " . ($result['error'] ?? 'Failed');
                }
            }
            echo json_encode([
                'success' => $successCount > 0,
                'message' => "$successCount order(s) approved" . (count($errors) > 0 ? ', ' . count($errors) . ' failed' : ''),
                'approved' => $successCount,
                'errors' => $errors
            ]);
            break;
            
        case 'bulk_mark_ready':
            $refCodes = json_decode($_POST['reference_codes'] ?? '[]', true);
            $successCount = 0;
            $errors = [];
            foreach ($refCodes as $refCode) {
                $result = updateOrderStatus($refCode, 'ready', $statusesFile);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errors[] = "$refCode: " . ($result['error'] ?? 'Failed');
                }
            }
            echo json_encode([
                'success' => $successCount > 0,
                'message' => "$successCount order(s) marked as ready" . (count($errors) > 0 ? ', ' . count($errors) . ' failed' : ''),
                'ready_count' => $successCount,
                'errors' => $errors
            ]);
            break;
            
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ============================================
// VENDOR FUNCTIONS
// ============================================
require_once __DIR__ . '/../includes/vendor-functions.php';

// ============================================
// FULFILLMENT BATCH FUNCTIONS
// ============================================

function loadFulfillmentBatches($file) {
    if (!file_exists($file)) return ['batches' => [], 'metadata' => ['last_batch_number' => 0, 'last_updated' => null, 'version' => '1.0']];
    $data = json_decode(file_get_contents($file), true);
    return $data ?: ['batches' => [], 'metadata' => ['last_batch_number' => 0, 'last_updated' => null, 'version' => '1.0']];
}

function saveFulfillmentBatches($data, $file) {
    $data['metadata']['last_updated'] = date('c');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function createFulfillmentBatch($postData, $file) {
    $refs = json_decode($postData['order_refs'] ?? '[]', true);
    $label = trim($postData['label'] ?? '');
    $notes = trim($postData['notes'] ?? '');
    
    if (empty($refs) || count($refs) < 1) return ['success' => false, 'error' => 'Select at least one order'];
    if (empty($label)) return ['success' => false, 'error' => 'Batch label is required'];
    
    $data = loadFulfillmentBatches($file);
    
    // Check for orders already in a batch
    $alreadyBatched = [];
    foreach ($data['batches'] as $batch) {
        if ($batch['status'] === 'cancelled') continue;
        foreach ($batch['order_refs'] as $existingRef) {
            if (in_array($existingRef, $refs)) $alreadyBatched[] = $existingRef;
        }
    }
    if (!empty($alreadyBatched)) {
        return ['success' => false, 'error' => 'Orders already in a batch: ' . implode(', ', $alreadyBatched)];
    }
    
    $num = ($data['metadata']['last_batch_number'] ?? 0) + 1;
    $data['metadata']['last_batch_number'] = $num;
    $batchId = 'FB-' . str_pad($num, 3, '0', STR_PAD_LEFT);
    
    $data['batches'][] = [
        'batch_id' => $batchId,
        'label' => $label,
        'notes' => $notes,
        'order_refs' => $refs,
        'order_count' => count($refs),
        'created_at' => date('c'),
        'created_by' => getCurrentAdminName() ?? 'Admin',
        'status' => 'created',
        'vendor_id' => null,
        'vendor_name' => null,
        'pushed_at' => null,
    ];
    
    saveFulfillmentBatches($data, $file);
    
    if (function_exists('logAdminActivity')) {
        logAdminActivity('Batch Created', ['batch_id' => $batchId, 'label' => $label, 'orders' => count($refs)]);
    }
    
    return ['success' => true, 'message' => 'Batch "' . $label . '" created with ' . count($refs) . ' order(s)', 'batch_id' => $batchId];
}

function removeFromFulfillmentBatch($postData, $file) {
    $ref = $postData['reference_code'] ?? '';
    if (empty($ref)) return ['success' => false, 'error' => 'Reference code required'];
    
    $data = loadFulfillmentBatches($file);
    foreach ($data['batches'] as &$batch) {
        if ($batch['status'] === 'cancelled') continue;
        $idx = array_search($ref, $batch['order_refs']);
        if ($idx !== false) {
            array_splice($batch['order_refs'], $idx, 1);
            $batch['order_count'] = count($batch['order_refs']);
            if (count($batch['order_refs']) === 0) $batch['status'] = 'cancelled';
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => 'Removed from batch'];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Order not found in any batch'];
}

function addToFulfillmentBatch($postData, $file) {
    $ref = $postData['reference_code'] ?? '';
    $batchId = $postData['batch_id'] ?? '';
    if (empty($ref) || empty($batchId)) return ['success' => false, 'error' => 'Reference code and batch ID required'];
    
    $data = loadFulfillmentBatches($file);
    
    // Check order isn't already in a batch
    foreach ($data['batches'] as $check) {
        if ($check['status'] === 'cancelled') continue;
        if (in_array($ref, $check['order_refs'])) {
            return ['success' => false, 'error' => $ref . ' is already in batch ' . $check['batch_id']];
        }
    }
    
    // Find target batch and add
    foreach ($data['batches'] as &$batch) {
        if ($batch['batch_id'] === $batchId && $batch['status'] !== 'cancelled') {
            $batch['order_refs'][] = $ref;
            $batch['order_count'] = count($batch['order_refs']);
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => $ref . ' added to ' . $batchId];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Batch not found'];
}

function deleteFulfillmentBatch($postData, $file) {
    $batchId = $postData['batch_id'] ?? '';
    if (empty($batchId)) return ['success' => false, 'error' => 'Batch ID required'];
    
    $data = loadFulfillmentBatches($file);
    foreach ($data['batches'] as &$batch) {
        if ($batch['batch_id'] === $batchId) {
            $batch['status'] = 'cancelled';
            $batch['cancelled_at'] = date('c');
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => 'Batch cancelled'];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Batch not found'];
}

function editFulfillmentBatch($postData, $file) {
    $batchId = $postData['batch_id'] ?? '';
    $label = trim($postData['label'] ?? '');
    $notes = trim($postData['notes'] ?? '');
    if (empty($batchId)) return ['success' => false, 'error' => 'Batch ID required'];
    
    $data = loadFulfillmentBatches($file);
    foreach ($data['batches'] as &$batch) {
        if ($batch['batch_id'] === $batchId && $batch['status'] !== 'cancelled') {
            $batch['label'] = $label;
            $batch['notes'] = $notes;
            $batch['updated_at'] = date('c');
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => 'Batch updated'];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Batch not found'];
}

function getOrderBatch($ref, $batchesData) {
    foreach ($batchesData['batches'] as $batch) {
        if ($batch['status'] === 'cancelled') continue;
        if (in_array($ref, $batch['order_refs'])) return $batch;
    }
    return null;
}

function generateBatchSuggestions($unbatchedOrders) {
    if (count($unbatchedOrders) < 2) return [];
    
    $suggestions = [];
    $usedRefs = [];
    
    // Strategy 1: Same event + same due date (strongest signal)
    $byEventDate = [];
    foreach ($unbatchedOrders as $order) {
        $ref = $order['referenceCode'];
        $event = explode('-', $ref)[0] ?? '';
        $date = $order['selectedDate'] ?? '';
        if ($event && $date) {
            $key = $event . '|' . $date;
            $byEventDate[$key][] = $order;
        }
    }
    foreach ($byEventDate as $key => $orders) {
        if (count($orders) < 2) continue;
        list($event, $date) = explode('|', $key);
        $dateFormatted = date('D M j', strtotime($date));
        $refs = array_column($orders, 'referenceCode');
        $suggestions[] = [
            'type' => 'event_date',
            'icon' => '&#128197;',
            'title' => count($refs) . ' ' . $event . ' orders due ' . $dateFormatted,
            'description' => 'Same event, same deadline \u2014 batch for one vendor push',
            'refs' => $refs,
            'score' => 90 + count($refs) * 3,
            'auto_label' => $event . ' ' . $dateFormatted,
        ];
    }
    
    // Strategy 2: Same material (fabric vs paper)
    $byMaterial = [];
    foreach ($unbatchedOrders as $order) {
        $mat = strtolower($order['material'] ?? 'poster');
        $byMaterial[$mat][] = $order;
    }
    foreach ($byMaterial as $mat => $orders) {
        if (count($orders) < 2) continue;
        if ($mat === 'poster' || $mat === 'paper') continue; // Only suggest for non-default materials
        $refs = array_column($orders, 'referenceCode');
        // Skip if all already in an event_date suggestion
        $newRefs = array_diff($refs, $usedRefs);
        if (count($newRefs) < 2) continue;
        $suggestions[] = [
            'type' => 'material',
            'icon' => '&#127912;',
            'title' => count($refs) . ' ' . ucfirst($mat) . ' orders',
            'description' => 'Same material \u2014 send to specialist vendor for best pricing',
            'refs' => $refs,
            'score' => 75 + count($refs) * 2,
            'auto_label' => ucfirst($mat) . ' batch',
        ];
    }
    
    // Strategy 3: Urgency cluster (sameday + nextday)
    $urgentOrders = array_filter($unbatchedOrders, function($o) {
        return in_array($o['pricing']['tier'] ?? '', ['sameday', 'nextday']);
    });
    if (count($urgentOrders) >= 2) {
        $refs = array_column($urgentOrders, 'referenceCode');
        $suggestions[] = [
            'type' => 'urgent',
            'icon' => '&#9888;',
            'title' => count($refs) . ' urgent orders',
            'description' => 'Same-day and next-day orders \u2014 batch and push immediately',
            'refs' => $refs,
            'score' => 85 + count($refs) * 3,
            'auto_label' => 'Urgent ' . date('M j'),
        ];
    }
    
    // Strategy 4: Same size range (within 10" tolerance on both dimensions)
    $sizeGroups = [];
    foreach ($unbatchedOrders as $order) {
        $w = intval($order['dimensions']['width'] ?? 0);
        $h = intval($order['dimensions']['height'] ?? 0);
        // Normalize: smaller dimension first
        $dim = [min($w, $h), max($w, $h)];
        $placed = false;
        foreach ($sizeGroups as &$group) {
            $gw = $group['dim'][0]; $gh = $group['dim'][1];
            if (abs($dim[0] - $gw) <= 10 && abs($dim[1] - $gh) <= 10) {
                $group['orders'][] = $order;
                $placed = true;
                break;
            }
        }
        unset($group);
        if (!$placed) {
            $sizeGroups[] = ['dim' => $dim, 'orders' => [$order]];
        }
    }
    foreach ($sizeGroups as $group) {
        if (count($group['orders']) < 3) continue; // Only suggest size batches with 3+
        $refs = array_column($group['orders'], 'referenceCode');
        $sizeLabel = $group['dim'][0] . '"\u00d7' . $group['dim'][1] . '" range';
        $suggestions[] = [
            'type' => 'size',
            'icon' => '&#128208;',
            'title' => count($refs) . ' similar-size orders (' . $sizeLabel . ')',
            'description' => 'Same size range \u2014 efficient machine setup, less material waste',
            'refs' => $refs,
            'score' => 60 + count($refs) * 2,
            'auto_label' => 'Size batch ' . $group['dim'][0] . 'x' . $group['dim'][1],
        ];
    }
    
    // Deduplicate: remove orders from lower-scored suggestions if in higher ones
    usort($suggestions, function($a, $b) { return $b['score'] - $a['score']; });
    $deduped = [];
    foreach ($suggestions as $s) {
        $available = array_diff($s['refs'], $usedRefs);
        if (count($available) < 2) continue;
        if (count($available) < count($s['refs'])) {
            $s['refs'] = array_values($available);
            $s['title'] = preg_replace('/^\d+/', count($available), $s['title']);
        }
        $deduped[] = $s;
        $usedRefs = array_merge($usedRefs, $s['refs']);
    }
    
    return array_slice($deduped, 0, 4); // Max 4 suggestions
}






// ============================================
// TOKEN MANAGEMENT FUNCTIONS
// ============================================
function loadTokens($tokensFile) {
    if (!file_exists($tokensFile)) {
        $defaultData = [
            'tokens' => [],
            'metadata' => [
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'version' => '1.0'
            ]
        ];
        file_put_contents($tokensFile, json_encode($defaultData, JSON_PRETTY_PRINT), LOCK_EX);
        return $defaultData;
    }
    return json_decode(file_get_contents($tokensFile), true) ?: ['tokens' => []];
}

function saveTokens($data, $tokensFile) {
    $data['metadata']['updated_at'] = date('c');
    return file_put_contents($tokensFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function generateVendorToken($referenceCode, $vendorId, $tokensFile) {
    $token = bin2hex(random_bytes(32)); // 64 char token
    
    $tokens = loadTokens($tokensFile);
    
    // Revoke any existing tokens for this order
    foreach ($tokens['tokens'] as $existingToken => &$tokenData) {
        if ($tokenData['reference_code'] === $referenceCode && empty($tokenData['revoked'])) {
            $tokenData['revoked'] = true;
            $tokenData['revoked_at'] = date('c');
            $tokenData['revoked_reason'] = 'Replaced by new token';
        }
    }
    
    // Create new token
    $tokens['tokens'][$token] = [
        'reference_code' => $referenceCode,
        'vendor_id' => $vendorId,
        'created_at' => date('c'),
        'created_by' => getCurrentAdminName() ?? 'System',
        'revoked' => false,
        'confirmed_at' => null,
        'downloads' => []
    ];
    
    saveTokens($tokens, $tokensFile);
    
    return $token;
}

function revokeToken($token, $tokensFile, $reason = 'Manual revocation') {
    $tokens = loadTokens($tokensFile);
    
    if (isset($tokens['tokens'][$token])) {
        $tokens['tokens'][$token]['revoked'] = true;
        $tokens['tokens'][$token]['revoked_at'] = date('c');
        $tokens['tokens'][$token]['revoked_reason'] = $reason;
        saveTokens($tokens, $tokensFile);
        return true;
    }
    
    return false;
}

function getActiveTokenForOrder($referenceCode, $tokensFile) {
    $tokens = loadTokens($tokensFile);
    
    foreach ($tokens['tokens'] as $token => $tokenData) {
        if ($tokenData['reference_code'] === $referenceCode && empty($tokenData['revoked'])) {
            // Check if not expired (7 days)
            $createdAt = strtotime($tokenData['created_at']);
            $expiresAt = $createdAt + (7 * 24 * 60 * 60);
            if (time() < $expiresAt) {
                return $token;
            }
        }
    }
    
    return null;
}

// ============================================
// QUEUE FUNCTIONS
// ============================================
function updateOrderStatus($referenceCode, $newStatus, $statusesFile) {
    $statuses = [];
    if (file_exists($statusesFile)) {
        $statuses = json_decode(file_get_contents($statusesFile), true) ?: [];
    }
    
    $oldStatus = $statuses[$referenceCode] ?? 'unknown';
    $statuses[$referenceCode] = $newStatus;
    
    if (file_put_contents($statusesFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX)) {
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

function findOrderByRef($referenceCode, $ordersDir) {
    $files = glob($ordersDir . '*-order.json');
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $referenceCode) {
            return $data;
        }
    }
    return null;
}

function findOrderFile($referenceCode, $ordersDir) {
    $files = glob($ordersDir . '*-order.json');
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $referenceCode) {
            return $file;
        }
    }
    return null;
}

function sendVendorOrderEmail($vendor, $order, $notes, $basePath, $token = null) {
    $to = $vendor['email'];
    $cc = !empty($vendor['email_cc']) ? $vendor['email_cc'] : null;
    
    $refCode = $order['referenceCode'];
    $subject = "Print Order: {$refCode} - Print Stuff";
    
    // Build email body with token for secure access
    $html = generateVendorEmailHTML($vendor, $order, $notes, $basePath, $token);
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Print Stuff Orders <orders@printstuff.ca>',
        'Reply-To: orders@printstuff.ca'
    ];
    
    if ($cc) {
        $headers[] = "Cc: {$cc}";
    }
    
    return mail($to, $subject, $html, implode("\r\n", $headers));
}

function generateVendorEmailHTML($vendor, $order, $notes, $basePath, $token = null) {
    $refCode = $order['referenceCode'];
    $customerName = $order['customerInfo']['name'] ?? 'Customer';
    $width = $order['dimensions']['width'] ?? 0;
    $height = $order['dimensions']['height'] ?? 0;
    $material = ucfirst($order['material'] ?? 'paper');
    $dueDate = isset($order['selectedDate']) ? date('D, M j', strtotime($order['selectedDate'])) : 'TBD';
    $_etl = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $_edt = $order['deliveryTime'] ?? 'anytime';
    $dueDate .= ($_edt && $_edt !== 'anytime') ? ' at ' . ($_etl[$_edt] ?? $_edt) : ' at anytime';
    $tier = $order['pricing']['tier'] ?? 'standard';
    
    // File info
    $origName = $order['uploadedFile']['originalName'] ?? 'No file';
    $fileName = function_exists('getDisplayFileName') ? getDisplayFileName($refCode, $origName) : $origName;
    $fileSize = isset($order['uploadedFile']['size']) ? formatFileSizeEmail($order['uploadedFile']['size']) : '';
    
    // Build secure portal link with token
    $portalLink = $token 
        ? "https://mtcc.print-stuff.ca/vendor-portal.php?token=" . urlencode($token)
        : "https://mtcc.print-stuff.ca/admin-orders.php?download=" . urlencode($refCode);
    
    $downloadLink = $token
        ? "https://mtcc.print-stuff.ca/vendor-download.php?token=" . urlencode($token)
        : "https://mtcc.print-stuff.ca/admin-orders.php?download=" . urlencode($refCode);
    
    $notesSection = '';
    if (!empty($notes)) {
        $notesSection = "
        <tr>
            <td style='padding: 15px 20px; background: #fef3c7; border-left: 4px solid #f59e0b;'>
                <strong style='color: #92400e;'>Special Instructions:</strong><br>
                <span style='color: #78350f;'>" . nl2br(htmlspecialchars($notes)) . "</span>
            </td>
        </tr>";
    }
    
    $urgencyBadge = '';
    if ((strpos(strtolower($tier), 'same') !== false || strpos(strtolower($tier), 'last minute') !== false || strpos(strtolower($tier), 'critical') !== false || strpos(strtolower($tier), 'next') !== false)) {
        $urgencyBadge = "<span style='background: #dc2626; color: white; padding: 4px 12px; border-radius: 4px; font-weight: bold; text-transform: uppercase;'>URGENT - " . strtoupper($tier) . "</span>";
    }
    
    // Portal section for confirmation (only if token provided)
    $portalSection = '';
    if ($token) {
        $portalSection = "
        <tr>
            <td style='padding: 25px; text-align: center; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);'>
                <p style='color: #166534; margin: 0 0 15px 0; font-weight: 600;'>
                    ✅ Please confirm receipt of this order
                </p>
                <a href='{$portalLink}' style='display: inline-block; background: #7c3aed; color: white; padding: 16px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;'>
                    View Order & Confirm
                </a>
                <p style='color: #6b7280; margin: 15px 0 0 0; font-size: 12px;'>
                    This link expires in 7 days
                </p>
            </td>
        </tr>";
    }
    
    $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Print Order: {$refCode}</title>
</head>
<body style='font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f3f4f6;'>
    <table cellpadding='0' cellspacing='0' style='max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <tr>
            <td style='background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0 0 10px 0; font-size: 24px;'>New Print Order</h1>
                <div style='background: rgba(255,255,255,0.2); display: inline-block; padding: 8px 20px; border-radius: 20px;'>
                    <span style='color: white; font-size: 18px; font-weight: bold;'>#{$refCode}</span>
                </div>
                " . ($urgencyBadge ? "<div style='margin-top: 15px;'>{$urgencyBadge}</div>" : "") . "
            </td>
        </tr>
        
        <tr>
            <td style='padding: 25px;'>
                <table cellpadding='0' cellspacing='0' style='width: 100%;'>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Due Date:</strong>
                            <span style='float: right; color: #111827; font-weight: 600;'>{$dueDate}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Dimensions:</strong>
                            <span style='float: right; color: #111827;'>{$width}\" × {$height}\"</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Material:</strong>
                            <span style='float: right; color: #111827;'>{$material}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Priority:</strong>
                            <span style='float: right; color: #111827; text-transform: capitalize;'>{$tier}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        
        {$notesSection}
        
        {$portalSection}
        
        <tr>
            <td style='padding: 25px; background: #f8fafc;'>
                <div style='margin-bottom: 15px;'>
                    <strong style='color: #374151;'>File:</strong> {$fileName} " . ($fileSize ? "({$fileSize})" : "") . "
                </div>
                <a href='{$downloadLink}' style='display: inline-block; background: #10b981; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                    ⬇️ Download Print File
                </a>
            </td>
        </tr>
        
        <tr>
            <td style='padding: 20px; background: #f3f4f6; text-align: center; border-top: 1px solid #e5e7eb;'>
                <p style='color: #6b7280; margin: 0 0 10px 0; font-size: 13px;'>
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

function formatFileSizeEmail($bytes) {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 1) . ' ' . $sizes[$i];
}

// ============================================
// LOAD DATA
// ============================================
$vendorData = loadVendors($vendorsFile);
$vendors = $vendorData['vendors'] ?? [];
$settings = $vendorData['settings'] ?? [];
$defaultVendorId = $settings['default_vendor_id'] ?? null;
$activeVendors = array_filter($vendors, fn($v) => $v['active']);

// Load statuses
$statuses = [];
if (file_exists($statusesFile)) {
    $statuses = json_decode(file_get_contents($statusesFile), true) ?: [];
}

// Load preflight log
$preflightLog = [];
if (file_exists($preflightLogFile)) {
    $preflightLog = json_decode(file_get_contents($preflightLogFile), true) ?: [];
}
$preflightEntries = $preflightLog['entries'] ?? [];

// Load fulfillment batches
$fbData = loadFulfillmentBatches($fulfillmentBatchesFile);
$activeBatches = array_filter($fbData['batches'], fn($b) => $b['status'] !== 'cancelled');

// Load all orders
$orders = [];
if (is_dir($ordersDir)) {
    $files = glob($ordersDir . '*-order.json');
    foreach ($files as $file) {
        $orderData = json_decode(file_get_contents($file), true);
        if ($orderData && isset($orderData['referenceCode'])) {
            $refCode = $orderData['referenceCode'];
            $orderData['status'] = $statuses[$refCode] ?? 'unpaid';
            $orderData['preflight_info'] = $preflightEntries[$refCode] ?? null;
            $orderData['fulfillment_batch'] = getOrderBatch($refCode, $fbData);
            $orders[] = $orderData;
        }
    }
}

// Sort by due date
usort($orders, function($a, $b) {
    $dateA = strtotime($a['selectedDate'] ?? '2099-12-31');
    $dateB = strtotime($b['selectedDate'] ?? '2099-12-31');
    return $dateA - $dateB;
});

// Filter orders by status
$queueOrders = array_filter($orders, fn($o) => ($o['status'] ?? '') === 'paid');
$preflightOrders = array_filter($orders, fn($o) => ($o['status'] ?? '') === 'preflight');
$printingOrders = array_filter($orders, fn($o) => ($o['status'] ?? '') === 'printing');
$readyOrders = array_filter($orders, fn($o) => ($o['status'] ?? '') === 'ready');
$fileIssueOrders = array_filter($orders, fn($o) => ($o['status'] ?? '') === 'file_issue');

// Stats
$stats = [
    'ready_to_push' => count($queueOrders),
    'in_preflight' => count($preflightOrders),
    'printing' => count($printingOrders),
    'ready_to_ship' => count($readyOrders),
    'file_issues' => count($fileIssueOrders),
    'active_vendors' => count($activeVendors),
    'total_vendors' => count($vendors)
];

// Helper function for tier badges
function getTierBadge($tier) {
    $tierLower = strtolower($tier);
    $cls = 'tier-standard';
    if (strpos($tierLower, 'last minute') !== false || strpos($tierLower, 'same') !== false || strpos($tierLower, 'sameday') !== false) $cls = 'tier-sameday';
    elseif (strpos($tierLower, 'critical') !== false || strpos($tierLower, 'nextday') !== false || strpos($tierLower, 'next day') !== false || strpos($tierLower, 'next-day') !== false) $cls = 'tier-critical';
    elseif (strpos($tierLower, 'urgent') !== false || strpos($tierLower, '2day') !== false || strpos($tierLower, '2-day') !== false || strpos($tierLower, '2days') !== false) $cls = 'tier-urgent';
    elseif (strpos($tierLower, 'rush') !== false || strpos($tierLower, '3day') !== false || strpos($tierLower, '3-day') !== false || strpos($tierLower, '3days') !== false) $cls = 'tier-rush';
    elseif (strpos($tierLower, 'early') !== false) $cls = 'tier-early';
    return '<span class="tier-badge ' . $cls . '">' . htmlspecialchars($tier) . '</span>';
}


function isUrgentTier($tier) {
    $t = strtolower($tier);
    return (strpos($t, 'same') !== false || strpos($t, 'last minute') !== false || strpos($t, 'critical') !== false || strpos($t, 'next') !== false);
}

function renderDueCell($order) {
    $date = isset($order['selectedDate']) ? date('D, M j', strtotime($order['selectedDate'])) : '-';
    $tl = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $dt = $order['deliveryTime'] ?? 'anytime';
    $time = $tl[$dt] ?? ucfirst($dt ?: 'Anytime');
    return '<div class="due-cell"><span class="due-date">' . $date . '</span><span class="due-time">' . $time . '</span></div>';
}

function renderPriceCell($vp, $refCode, $canEdit) {
    $vpStatus = $vp['status'] ?? 'none';
    if ($vpStatus === 'accepted') {
        return '<span class="price-approved">$' . number_format($vp['total'] ?? 0, 2) . '</span>';
    } elseif ($vpStatus === 'submitted') {
        $html = '<div class="price-review-box">';
        $html .= '<span class="prb-amount">$' . number_format($vp['total'] ?? 0, 2) . '</span>';
        if ($canEdit) {
            $html .= '<span class="prb-divider"></span>';
            $html .= '<button class="prb-btn prb-approve" onclick="approvePrice(\'' . $refCode . '\')" title="Approve">&#10003;</button>';
            $html .= '<span class="prb-divider-dash"></span>';
            $html .= '<button class="prb-btn prb-reject" onclick="rejectPrice(\'' . $refCode . '\')" title="Reject">&#10007;</button>';
        }
        $html .= '</div>';
        return $html;
    } elseif ($vpStatus === 'rejected') {
        return '<span class="price-rejected">Rejected</span>';
    }
    return '<span class="text-muted">&mdash;</span>';
}

function getTimeSince($datetime) {
    $now = new DateTime();
    $then = new DateTime($datetime);
    $diff = $now->diff($then);
    
    if ($diff->days > 0) return $diff->days . 'd ago';
    if ($diff->h > 0) return $diff->h . 'h ago';
    if ($diff->i > 0) return $diff->i . 'm ago';
    return 'Just now';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Management - MTCC Poster System</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="production-styles.css">
<link rel="stylesheet" href="../css/admin-sidebar.css">
<link rel="stylesheet" href="production-panel.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('production'); ?>
<script src="../js/admin-sidebar.js"></script>
<div class="production-content">


    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title"><?= ICON_CLIPBOARD ?> Production Management</h1>
            <div class="page-welcome">
                <span class="welcome-text">Manage orders from queue through ready to ship</span>
                <span class="welcome-date">Today is <?= date('l, F j Y') ?></span>
            </div>
        </div>

    </div>

    
    <div class="preflight-container">
        
        <!-- Tab Navigation -->
        <div class="tab-navigation">
            <button class="tab-btn <?= $currentTab === 'queue' ? 'active' : '' ?>" onclick="switchTab('queue')">
                <?= ICON_INBOX ?> Queue
                <?php if ($stats['ready_to_push'] > 0): ?>
                <span class="badge"><?= $stats['ready_to_push'] ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn <?= $currentTab === 'progress' ? 'active' : '' ?>" onclick="switchTab('progress')">
                <?= ICON_CLOCK ?> In Progress
                <?php if ($stats['in_preflight'] + $stats['printing'] > 0): ?>
                <span class="badge"><?= $stats['in_preflight'] + $stats['printing'] ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn <?= $currentTab === 'ready' ? 'active' : '' ?>" onclick="switchTab('ready')">
                <?= ICON_PACKAGE ?> Ready to Ship
                <?php if ($stats['ready_to_ship'] > 0): ?>
                <span class="badge success"><?= $stats['ready_to_ship'] ?></span>
                <?php endif; ?>
            </button>
            <button class="tab-btn <?= $currentTab === 'issues' ? 'active' : '' ?>" onclick="switchTab('issues')">
                <?= ICON_WARNING ?> File Issues
                <?php if ($stats['file_issues'] > 0): ?>
                <span class="badge alert"><?= $stats['file_issues'] ?></span>
                <?php endif; ?>
            </button>

        </div>
        
        <!-- Queue Tab -->
        <div id="tab-queue" class="tab-content <?= $currentTab === 'queue' ? 'active' : '' ?>">
            <?php if (count($activeVendors) === 0): ?>
            <div class="alert-box warning">
                <?= ICON_WARNING ?> <strong>No active vendors.</strong> <a href="vendors.php">Add a vendor</a> before pushing orders.
            </div>
            <?php endif; ?>
            
            <?php if (count($queueOrders) > 0): ?>
            <?php
            // Separate batched vs unbatched orders
            $batchedGroups = [];
            $unbatchedOrders = [];
            foreach ($queueOrders as $order) {
                $fb = $order['fulfillment_batch'];
                if ($fb) {
                    $bid = $fb['batch_id'];
                    if (!isset($batchedGroups[$bid])) $batchedGroups[$bid] = ['batch' => $fb, 'orders' => []];
                    $batchedGroups[$bid]['orders'][] = $order;
                } else {
                    $unbatchedOrders[] = $order;
                }
            }
            ?>
            
            <?php
            // Generate smart batch suggestions for unbatched orders
            $batchSuggestions = generateBatchSuggestions($unbatchedOrders);
            ?>
            <?php if (!empty($batchSuggestions) && $canEditPreflight): ?>
            <div class="batch-suggestions">
                <div class="suggestions-header">
                    <div class="suggestions-title">
                        <span class="suggestions-icon">&#9889;</span>
                        Smart Batch Suggestions
                        <span class="suggestions-count"><?= count($batchSuggestions) ?></span>
                    </div>
                    <button class="suggestions-dismiss" onclick="this.closest('.batch-suggestions').style.display='none'" title="Dismiss">&times;</button>
                </div>
                <div class="suggestions-list">
                    <?php foreach ($batchSuggestions as $si => $sug):
                        $scoreClass = $sug['score'] >= 80 ? 'score-high' : ($sug['score'] >= 60 ? 'score-med' : 'score-low');
                        $typeLabels = ['event_date' => 'Same Event + Date', 'material' => 'Same Material', 'urgent' => 'Urgent', 'size' => 'Similar Size'];
                        $typeColors = ['event_date' => 'sg-type-event', 'material' => 'sg-type-material', 'urgent' => 'sg-type-urgent', 'size' => 'sg-type-size'];
                    ?>
                    <div class="suggestion-card" id="sgCard_<?= $si ?>">
                        <div class="sg-header">
                            <span class="sg-type-badge <?= $typeColors[$sug['type']] ?? 'sg-type-event' ?>"><?= $sug['icon'] ?> <?= $typeLabels[$sug['type']] ?? $sug['type'] ?></span>
                            <span class="suggestion-score <?= $scoreClass ?>"><?= $sug['score'] ?></span>
                            <button class="sg-dismiss" onclick="event.stopPropagation();document.getElementById('sgCard_<?= $si ?>').style.display='none'" title="Dismiss">&times;</button>
                        </div>
                        <div class="sg-body">
                            <div class="sg-title"><?= htmlspecialchars($sug['title']) ?></div>
                            <div class="sg-desc"><?= htmlspecialchars($sug['description']) ?></div>
                        </div>
                        <div class="sg-stats">
                            <?php foreach ($sug['refs'] as $ri => $sref): ?>
                            <?php if ($ri > 0): ?><span class="sg-stat-divider"></span><?php endif; ?>
                            <span class="sg-ref-pill"><?= htmlspecialchars($sref) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <button class="suggestion-btn suggestion-btn-batch" onclick="acceptSuggestion(<?= $si ?>)">
                            Create Batch
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($batchedGroups) || !empty($unbatchedOrders)): ?>
            
            <?php if (!empty($batchedGroups)): ?>
            <?php foreach ($batchedGroups as $bid => $group): ?>
            <div class="batch-group" data-batch-id="<?= htmlspecialchars($bid) ?>">
                <div class="batch-group-header" onclick="toggleBatchGroup('<?= htmlspecialchars($bid) ?>')">
                    <div class="batch-group-left">
                        <span class="batch-group-chevron" id="batchChev_<?= htmlspecialchars($bid) ?>">&#9660;</span>
                        <span class="batch-group-id"><?= htmlspecialchars($bid) ?></span>
                        <?php if (!empty($group['batch']['label'])): ?><span class="batch-group-label"><?= htmlspecialchars($group['batch']['label']) ?></span><?php endif; ?>
                        <span class="batch-group-count"><?= count($group['orders']) ?> order<?= count($group['orders']) > 1 ? 's' : '' ?></span>
                    </div>
                    <div class="batch-group-actions" onclick="event.stopPropagation()">
                        <?php if ($canEditPreflight && count($activeVendors) > 0): ?>
                        <button class="btn-confirm" onclick="openBatchPushModal('<?= htmlspecialchars($bid) ?>')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push Batch</button>
                        <span class="batch-header-sep"></span>
                        <button class="btn-edit-batch" onclick="editBatchInfo('<?= htmlspecialchars($bid) ?>', '<?= htmlspecialchars(addslashes($group['batch']['label'])) ?>', '<?= htmlspecialchars(addslashes($group['batch']['notes'] ?? '')) ?>')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg> Edit</button>
                        <button class="btn-delete-batch" onclick="deleteBatch('<?= htmlspecialchars($bid) ?>', '<?= htmlspecialchars(addslashes($group['batch']['label'])) ?>')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Remove</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="batch-group-body" id="batchBody_<?= htmlspecialchars($bid) ?>">
                    <table class="queue-table batch-table prod-table">
                        <thead><tr>
                            <th class="col-check"><input type="checkbox" class="batch-select-all" onchange="toggleBatchSelectAll(this, '<?= htmlspecialchars($bid) ?>')"></th>
                            <th class="col-order">Order</th><th class="col-priority">Priority</th><th class="col-customer">Customer</th><th class="col-size">Size</th><th class="col-material">Material</th><th class="col-due">Due</th><th class="col-packing">Packing</th><th class="col-actions">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($group['orders'] as $order):
                            $refCode = $order['referenceCode'];
                            $isUrgent = isUrgentTier($order['pricing']['tier'] ?? '');
                        ?>
                        <tr class="<?= $isUrgent ? 'urgent' : '' ?>" data-ref="<?= htmlspecialchars($refCode) ?>">
                            <td><input type="checkbox" class="order-checkbox" value="<?= htmlspecialchars($refCode) ?>"></td>
                            <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                            <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                            <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                            <td><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                            <td><?= ucfirst($order['material'] ?? 'paper') ?></td>
                            <td><?= renderDueCell($order) ?></td>
                            <td>
                                <div class="pack-badge-wrapper" data-ref="<?= htmlspecialchars($refCode) ?>">
                                    <span class="pack-badge pack-none" onclick="togglePackDropdown(event, '<?= htmlspecialchars($refCode) ?>')" data-value="none">None / Flat</span>
                                    <div class="pack-dropdown" id="packDrop_<?= htmlspecialchars($refCode) ?>">
                                        <button class="pack-dropdown-item current" data-value="none" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','none',this)"><span class="pack-badge pack-none">None / Flat</span></button>
                                        <button class="pack-dropdown-item" data-value="tube" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','tube',this)"><span class="pack-badge pack-tube">Tube</span></button>
                                        <button class="pack-dropdown-item" data-value="box" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','box',this)"><span class="pack-badge pack-box">Box</span></button>
                                        <button class="pack-dropdown-item" data-value="custom" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','custom',this)"><span class="pack-badge pack-custom">Custom</span></button>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($canEditPreflight): ?>
                                <div class="action-buttons">
                                    <button class="btn-push" onclick="openPushModal('<?= $refCode ?>')"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push</button>
                                    <button onclick="removeBatchOrder('<?= htmlspecialchars($bid) ?>', '<?= htmlspecialchars($refCode) ?>')" class="btn-unbatch"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18.84 12.25l1.72-1.71a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M5.16 11.75l-1.72 1.71a5 5 0 007.07 7.07l1.72-1.71"/></svg> Unbatch</button>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($unbatchedOrders)): ?>
            <div class="queue-table-wrapper" style="margin-top:16px;">
                <?php if (!empty($batchedGroups)): ?><div class="unbatched-divider">Unbatched Orders</div><?php endif; ?>
                <table class="queue-table prod-table">
                    <thead><tr>
                        <th class="col-check"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th class="col-order">Order</th><th class="col-priority">Priority</th><th class="col-customer">Customer</th><th class="col-size">Size</th><th class="col-material">Material</th><th class="col-due">Due</th><th class="col-packing">Packing</th><th class="col-actions">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($unbatchedOrders as $order): 
                        $refCode = $order['referenceCode'];
                        $isUrgent = isUrgentTier($order['pricing']['tier'] ?? '');
                    ?>
                    <tr class="<?= $isUrgent ? 'urgent' : '' ?>" data-ref="<?= htmlspecialchars($refCode) ?>">
                        <td><input type="checkbox" class="order-checkbox" value="<?= htmlspecialchars($refCode) ?>"></td>
                        <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                        <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                        <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                        <td><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                        <td><?= ucfirst($order['material'] ?? 'paper') ?></td>
                        <td><?= renderDueCell($order) ?></td>
                        <td>
                            <div class="pack-badge-wrapper" data-ref="<?= htmlspecialchars($refCode) ?>">
                                <span class="pack-badge pack-none" onclick="togglePackDropdown(event, '<?= htmlspecialchars($refCode) ?>')" data-value="none">None / Flat</span>
                                <div class="pack-dropdown" id="packDrop_<?= htmlspecialchars($refCode) ?>">
                                    <button class="pack-dropdown-item current" data-value="none" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','none',this)"><span class="pack-badge pack-none">None / Flat</span></button>
                                    <button class="pack-dropdown-item" data-value="tube" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','tube',this)"><span class="pack-badge pack-tube">Tube</span></button>
                                    <button class="pack-dropdown-item" data-value="box" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','box',this)"><span class="pack-badge pack-box">Box</span></button>
                                    <button class="pack-dropdown-item" data-value="custom" onclick="setRowPacking('<?= htmlspecialchars($refCode) ?>','custom',this)"><span class="pack-badge pack-custom">Custom</span></button>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($canEditPreflight && count($activeVendors) > 0): ?>
                            <div class="action-buttons">
                                <button class="btn-push" onclick="openPushModal('<?= $refCode ?>')"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push</button>
                                <button class="btn-issue" onclick="openIssueModal('<?= $refCode ?>')"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue</button>
                                <?php if (!empty($batchedGroups)): ?>
                                <div class="batch-assign-wrapper" data-ref="<?= htmlspecialchars($refCode) ?>">
                                    <button onclick="toggleBatchAssign(event, '<?= htmlspecialchars($refCode) ?>')" class="btn-batch-assign"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg> Batch</button>
                                    <div class="batch-assign-dropdown" id="batchAssign_<?= htmlspecialchars($refCode) ?>">
                                        <?php foreach ($batchedGroups as $abid => $agroup): ?>
                                        <button class="batch-assign-item" onclick="assignToBatch('<?= htmlspecialchars($refCode) ?>', '<?= htmlspecialchars($abid) ?>')">
                                            <span class="batch-assign-id"><?= htmlspecialchars($abid) ?></span>
                                            <?php if (!empty($agroup['batch']['label'])): ?><span class="batch-assign-label"><?= htmlspecialchars($agroup['batch']['label']) ?></span><?php endif; ?>
                                            <span class="batch-assign-count"><?= count($agroup['orders']) ?></span>
                                        </button>
                                        <?php endforeach; ?>
                                        <div class="batch-assign-divider"></div>
                                        <button class="batch-assign-item batch-assign-new" onclick="openCreateBatchFromRow('<?= htmlspecialchars($refCode) ?>')">+ New Batch</button>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php endif; ?>
            <?php else: ?>
            <div class="empty-state">
                <div class="icon"><?= ICON_CHECK_GREEN ?></div>
                <div class="title">Queue Empty</div>
                <div class="description">No paid orders waiting to be pushed to vendors.</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- In Progress Tab -->
        <div id="tab-progress" class="tab-content <?= $currentTab === 'progress' ? 'active' : '' ?>">
            <?php 
            $inProgressOrders = array_merge(array_values($preflightOrders), array_values($printingOrders));
            
            $overdueCount = 0;
            foreach ($preflightOrders as $o) {
                $pushedAt = $o['preflight_info']['pushed_at'] ?? null;
                if ($pushedAt && (time() - strtotime($pushedAt)) > (2 * 60 * 60)) $overdueCount++;
            }
            
            if ($overdueCount > 0): 
            ?>
            <div class="alert-box warning">
                <?= ICON_WARNING ?> <strong><?= $overdueCount ?> order(s) awaiting approval for over 2 hours.</strong>
                Consider resending vendor emails.
            </div>
            <?php endif; ?>
            
            <?php if (count($inProgressOrders) > 0): ?>
            <?php
            // Split by batch
            $progressBatched = [];
            $progressUnbatched = [];
            foreach ($inProgressOrders as $order) {
                $fb = $order['fulfillment_batch'];
                if ($fb) {
                    $bid = $fb['batch_id'];
                    if (!isset($progressBatched[$bid])) $progressBatched[$bid] = ['batch' => $fb, 'orders' => []];
                    $progressBatched[$bid]['orders'][] = $order;
                } else {
                    $progressUnbatched[] = $order;
                }
            }
            ?>
            


            <?php if (!empty($progressBatched)): ?>
            <?php foreach ($progressBatched as $bid => $group):
                $issueCount = 0;
                foreach ($group['orders'] as $go) { if (($go['status'] ?? '') === 'file_issue') $issueCount++; }
                // Batch pricing summary
                $bGrand = 0; $bStatus = 'none'; $bAllSub = true; $bAllAcc = true; $bAny = false;
                foreach ($group['orders'] as $bo) {
                    $bvp = $bo['preflight_info']['vendor_pricing'] ?? [];
                    $bs = $bvp['status'] ?? 'none';
                    if ($bs !== 'submitted') $bAllSub = false;
                    if ($bs !== 'accepted') $bAllAcc = false;
                    if ($bs === 'submitted' || $bs === 'accepted') { $bAny = true; $bGrand += floatval($bvp['total'] ?? 0); }
                }
                if ($bAllAcc && $bAny) $bStatus = 'accepted';
                elseif ($bAllSub && $bAny) $bStatus = 'submitted';
                elseif ($bAny) $bStatus = 'partial';
            ?>
            <div class="batch-group" data-batch-id="<?= htmlspecialchars($bid) ?>">
                <div class="batch-group-header <?= $bStatus === 'accepted' ? 'batch-header-approved' : ($bStatus === 'submitted' ? 'batch-header-pending' : '') ?>" onclick="toggleBatchGroup('<?= htmlspecialchars($bid) ?>')">
                    <div class="batch-group-left">
                        <span class="batch-group-chevron" id="batchChev_<?= htmlspecialchars($bid) ?>">&#9660;</span>
                        <span class="batch-group-id"><?= htmlspecialchars($bid) ?></span>
                        <?php if (!empty($group['batch']['label'])): ?><span class="batch-group-label"><?= htmlspecialchars($group['batch']['label']) ?></span><?php endif; ?>
                        <span class="batch-group-count"><?= count($group['orders']) ?> order<?= count($group['orders']) > 1 ? 's' : '' ?></span>
                        <?php if ($issueCount > 0): ?><span class="batch-issue-warning">&#9888; <?= $issueCount ?> issue<?= $issueCount > 1 ? 's' : '' ?></span><?php endif; ?>
                    </div>
                    <div class="batch-group-actions" onclick="event.stopPropagation()">
                        <?php if ($bAny): ?>
                        <span class="batch-header-pricing">
                            <?php if ($bStatus === 'accepted'): ?>
                            <span class="batch-header-status batch-status-approved">&#10003; Approved</span>
                            <?php elseif ($bStatus === 'submitted'): ?>
                            <span class="batch-header-status batch-status-pending">Pending</span>
                            <?php elseif ($bStatus === 'partial'): ?>
                            <span class="batch-header-status batch-status-partial">Partial</span>
                            <?php endif; ?>
                            <span class="batch-header-sep"></span>
                            <span class="batch-header-price"><span class="batch-header-price-label">Batch Total</span> $<?= number_format($bGrand, 2) ?></span>
                        </span>
                        <?php if ($bStatus === 'submitted' && $canEditPreflight): ?>
                        <span class="batch-header-sep"></span>
                        <button class="btn-confirm" onclick="approveBatchPrices('<?= htmlspecialchars($bid) ?>')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Approve All</button>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="batch-group-body" id="batchBody_<?= htmlspecialchars($bid) ?>">
                    <table class="queue-table batch-table prod-table">
                        <thead><tr>
                            <th class="col-check"><input type="checkbox" class="batch-select-all" onchange="toggleBatchSelectAll(this, '<?= htmlspecialchars($bid) ?>')"></th>
                            <th class="col-order">Order</th><th class="col-priority">Priority</th><th class="col-customer">Customer</th><th class="col-size">Size</th><th class="col-material">Material</th><th class="col-vendor">Vendor</th><th class="col-status">Status</th><th class="col-due">Due</th><th class="col-packing">Packing</th><th class="col-price">Price</th><th class="col-actions">Actions</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($group['orders'] as $order):
                            $refCode = $order['referenceCode'];
                            $pfInfo = $order['preflight_info'];
                            $status = $order['status'];
                            $vendorName = $pfInfo['vendor_name'] ?? 'Unknown';
                            $pushedAt = $pfInfo['pushed_at'] ?? null;
                            $elapsedSeconds = $pushedAt ? (time() - strtotime($pushedAt)) : 0;
                            $isOverdue = $elapsedSeconds > 7200; $isCritical = $elapsedSeconds > 14400;
                            $vp = $pfInfo['vendor_pricing'] ?? []; $vpStatus = $vp['status'] ?? 'none';
                            $packType = $pfInfo['packing'] ?? 'none';
                        ?>
                        <tr class="<?= $isCritical ? 'critical-row' : ($isOverdue ? 'overdue-row' : '') ?>" data-ref="<?= htmlspecialchars($refCode) ?>" data-status="<?= $status ?>">
                            <td><input type="checkbox" class="progress-checkbox" value="<?= htmlspecialchars($refCode) ?>" data-status="<?= $status ?>" onchange="updateProgressBulkBar()"></td>
                            <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                            <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                            <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                            <td><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                            <td><?= ucfirst($order['material'] ?? 'paper') ?></td>
                            <td><?= htmlspecialchars($vendorName) ?></td>
                            <td><?php if ($status === 'preflight'): ?><span class="status-badge status-preflight">Pending</span><?php else: ?><span class="status-badge status-printing">Printing</span><?php endif; ?></td>
                            <td><?= renderDueCell($order) ?></td>
                            <td><span class="pack-label pack-<?= $packType ?>"><?= ucfirst($packType === 'none' ? 'Flat' : $packType) ?></span></td>
                            <td><?= renderPriceCell($vp, $refCode, $canEditPreflight) ?></td>
                            <td>
                                <?php if ($canEditPreflight): ?>
                                <div class="action-buttons">
                                    <?php if ($status !== 'printing'): ?><button class="btn-issue" onclick="openIssueModal('<?= $refCode ?>')"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue</button><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($progressUnbatched)): ?>
            <div class="queue-table-wrapper" style="margin-top:16px;">
                <?php if (!empty($progressBatched)): ?><div class="unbatched-divider">Unbatched Orders</div><?php endif; ?>
                <table class="queue-table prod-table">
                    <thead><tr>
                        <th class="col-check"><input type="checkbox" id="progressSelectAll" onchange="toggleProgressSelectAll(this)"></th>
                        <th class="col-order">Order</th><th class="col-priority">Priority</th><th class="col-customer">Customer</th><th class="col-size">Size</th><th class="col-material">Material</th><th class="col-vendor">Vendor</th><th class="col-status">Status</th><th class="col-due">Due</th><th class="col-packing">Packing</th><th class="col-price">Price</th><th class="col-actions">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($progressUnbatched as $order): 
                        $refCode = $order['referenceCode'];
                        $pfInfo = $order['preflight_info'];
                        $status = $order['status'];
                        $vendorName = $pfInfo['vendor_name'] ?? 'Unknown';
                        $pushedAt = $pfInfo['pushed_at'] ?? null;
                        $elapsedSeconds = $pushedAt ? (time() - strtotime($pushedAt)) : 0;
                        $isOverdue = $elapsedSeconds > 7200; $isCritical = $elapsedSeconds > 14400;
                        $vp = $pfInfo['vendor_pricing'] ?? []; $vpStatus = $vp['status'] ?? 'none';
                        $packType = $pfInfo['packing'] ?? 'none';
                        $rowClass = '';
                        if ($status === 'preflight') { if ($isCritical) $rowClass = 'critical-row'; elseif ($isOverdue) $rowClass = 'overdue-row'; }
                    ?>
                    <tr class="<?= $rowClass ?>" data-ref="<?= htmlspecialchars($refCode) ?>" data-status="<?= $status ?>">
                        <td><input type="checkbox" class="progress-checkbox" value="<?= htmlspecialchars($refCode) ?>" data-status="<?= $status ?>" onchange="updateProgressBulkBar()"></td>
                        <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                        <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                        <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                        <td><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                        <td><?= ucfirst($order['material'] ?? 'paper') ?></td>
                        <td><?= htmlspecialchars($vendorName) ?></td>
                        <td>
                            <?php if ($status === 'preflight'): ?>
                                <?php if ($isCritical): ?><span class="status-badge status-critical">Delayed</span>
                                <?php elseif ($isOverdue): ?><span class="status-badge status-overdue">Overdue</span>
                                <?php else: ?><span class="status-badge status-preflight">Pending</span><?php endif; ?>
                            <?php else: ?><span class="status-badge status-printing">Printing</span><?php endif; ?>
                        </td>
                        <td><?= renderDueCell($order) ?></td>
                        <td><span class="pack-label pack-<?= $packType ?>"><?= ucfirst($packType === 'none' ? 'Flat' : $packType) ?></span></td>
                        <td><?= renderPriceCell($vp, $refCode, $canEditPreflight) ?></td>
                        <td>
                            <?php if ($canEditPreflight): ?>
                            <div class="action-buttons">
                                <?php if ($status !== 'printing'): ?><button class="btn-issue" onclick="openIssueModal('<?= $refCode ?>')"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue</button><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="empty-state">
                <div class="icon"><?= ICON_INBOX ?></div>
                <div class="title">No Orders In Progress</div>
                <div class="description">Push orders from the Queue tab to get started.</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Ready to Ship Tab -->
        <div id="tab-ready" class="tab-content <?= $currentTab === 'ready' ? 'active' : '' ?>">
            <?php if (count($readyOrders) > 0): ?>
            <?php
            $readyBatched = [];
            $readyUnbatched = [];
            foreach ($readyOrders as $order) {
                $fb = $order['fulfillment_batch'];
                if ($fb) {
                    $bid = $fb['batch_id'];
                    if (!isset($readyBatched[$bid])) $readyBatched[$bid] = ['batch' => $fb, 'orders' => []];
                    $readyBatched[$bid]['orders'][] = $order;
                } else {
                    $readyUnbatched[] = $order;
                }
            }
            ?>
            
            <?php if (!empty($readyBatched)): ?>
            <?php foreach ($readyBatched as $bid => $group):
                $bGrand = 0; $bAny = false; $bAllPaid = true;
                foreach ($group['orders'] as $bo) {
                    $bvp = $bo['preflight_info']['vendor_pricing'] ?? [];
                    if (($bvp['status'] ?? '') === 'accepted') { $bAny = true; $bGrand += floatval($bvp['total'] ?? 0); }
                    if (empty($bo['preflight_info']['vendor_paid'])) $bAllPaid = false;
                }
            ?>
            <div class="batch-group" data-batch-id="<?= htmlspecialchars($bid) ?>">
                <div class="batch-group-header <?= $bAny ? ($bAllPaid ? 'batch-header-approved' : 'batch-header-pending') : '' ?>" onclick="toggleBatchGroup('<?= htmlspecialchars($bid) ?>')">
                    <div class="batch-group-left">
                        <span class="batch-group-chevron" id="batchChev_<?= htmlspecialchars($bid) ?>">&#9660;</span>
                        <span class="batch-group-id"><?= htmlspecialchars($bid) ?></span>
                        <?php if (!empty($group['batch']['label'])): ?><span class="batch-group-label"><?= htmlspecialchars($group['batch']['label']) ?></span><?php endif; ?>
                        <span class="batch-group-count"><?= count($group['orders']) ?> order<?= count($group['orders']) > 1 ? 's' : '' ?></span>
                        <span class="batch-ready-badge">Ready</span>
                    </div>
                    <div class="batch-group-actions" onclick="event.stopPropagation()">
                        <?php if ($bAny): ?>
                        <span class="batch-header-pricing">
                            <span class="batch-header-status <?= $bAllPaid ? 'batch-status-approved' : 'batch-status-pending' ?>"><?= $bAllPaid ? '&#10003; Paid' : 'Unpaid' ?></span>
                            <span class="batch-header-sep"></span>
                            <span class="batch-header-price"><span class="batch-header-price-label">Batch Total</span> $<?= number_format($bGrand, 2) ?></span>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="batch-group-body" id="batchBody_<?= htmlspecialchars($bid) ?>">
                    <table class="queue-table batch-table prod-table">
                        <thead><tr>
                            <th class="col-order">Order</th><th class="col-priority">Priority</th><th class="col-customer">Customer</th><th class="col-size">Size</th><th class="col-material">Material</th><th class="col-vendor">Vendor</th><th class="col-due">Due</th><th class="col-packing">Packing</th><th class="col-price">Price</th><th class="col-paid">Paid</th><th class="col-delivery">Delivery</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($group['orders'] as $order):
                            $refCode = $order['referenceCode'];
                            $pfInfo = $order['preflight_info'] ?? [];
                            $vendorName = $pfInfo['vendor_name'] ?? '';
                            $deliveryMethod = strtolower($order['deliveryOption'] ?? 'mtcc');
                            $isMTCC = ($deliveryMethod === 'mtcc' || $deliveryMethod === 'pickup' || strpos($deliveryMethod, 'mtcc') !== false);
                            $vp = $pfInfo['vendor_pricing'] ?? []; $vpStatus = $vp['status'] ?? 'none';
                            $packType = $pfInfo['packing'] ?? 'none';
                            $isPaid = !empty($pfInfo['vendor_paid']);
                        ?>
                        <tr data-ref="<?= htmlspecialchars($refCode) ?>">
                            <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                            <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                            <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                            <td><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                            <td><?= ucfirst($order['material'] ?? 'poster') ?></td>
                            <td><?= !empty($vendorName) ? htmlspecialchars($vendorName) : '&mdash;' ?></td>
                            <td><?= renderDueCell($order) ?></td>
                            <td><span class="pack-label pack-<?= $packType ?>"><?= ucfirst($packType === 'none' ? 'Flat' : $packType) ?></span></td>
                            <td><?php if ($vpStatus === 'accepted'): ?><span class="price-approved">$<?= number_format($vp['total'] ?? 0, 2) ?></span><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
                            <td><?= $isPaid ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' ?></td>
                            <td><?php if ($isMTCC): ?><?php $bldg = ucfirst($order['event']['building'] ?? 'north'); ?><span class="delivery-badge delivery-mtcc">MTCC - <?= $bldg ?></span><?php else: ?><span class="delivery-badge delivery-address">Delivery</span><?php endif; ?></td>
                        </tr>
                        <?php endforeach; ?>

                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if (!empty($readyUnbatched)): ?>
            <div class="queue-table-wrapper" style="margin-top:16px;">
                <?php if (!empty($readyBatched)): ?><div class="unbatched-divider">Unbatched Orders</div><?php endif; ?>
                <table class="queue-table prod-table">
                    <thead><tr>
                        <th class="col-order">Order</th><th class="col-priority">Priority</th><th class="col-customer">Customer</th><th class="col-size">Size</th><th class="col-material">Material</th><th class="col-vendor">Vendor</th><th class="col-due">Due</th><th class="col-packing">Packing</th><th class="col-price">Price</th><th class="col-paid">Paid</th><th class="col-delivery">Delivery</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($readyUnbatched as $order): 
                        $refCode = $order['referenceCode'];
                        $pfInfo = $order['preflight_info'] ?? [];
                        $vendorName = $pfInfo['vendor_name'] ?? '';
                        $deliveryMethod = strtolower($order['deliveryOption'] ?? 'mtcc');
                        $isMTCC = ($deliveryMethod === 'mtcc' || $deliveryMethod === 'pickup' || strpos($deliveryMethod, 'mtcc') !== false);
                        $vp = $pfInfo['vendor_pricing'] ?? []; $vpStatus = $vp['status'] ?? 'none';
                        $packType = $pfInfo['packing'] ?? 'none';
                        $isPaid = !empty($pfInfo['vendor_paid']);
                    ?>
                    <tr data-ref="<?= htmlspecialchars($refCode) ?>">
                        <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                        <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                        <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                        <td><?= ($order['dimensions']['width'] ?? 0) ?>" × <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                        <td><?= ucfirst($order['material'] ?? 'poster') ?></td>
                        <td><?= !empty($vendorName) ? htmlspecialchars($vendorName) : '<span class="text-muted">&mdash;</span>' ?></td>
                        <td><?= renderDueCell($order) ?></td>
                        <td><span class="pack-label pack-<?= $packType ?>"><?= ucfirst($packType === 'none' ? 'Flat' : $packType) ?></span></td>
                        <td><?php if ($vpStatus === 'accepted'): ?><span class="price-approved">$<?= number_format($vp['total'] ?? 0, 2) ?></span><?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?></td>
                        <td><?= $isPaid ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>' ?></td>
                        <td><?php if ($isMTCC): ?><?php $bldg = ucfirst($order['event']['building'] ?? 'north'); ?><span class="delivery-badge delivery-mtcc">MTCC - <?= $bldg ?></span><?php else: ?><span class="delivery-badge delivery-address">Delivery</span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <?php else: ?>
            <div class="empty-state success">
                <div class="icon"><?= ICON_PACKAGE ?></div>
                <div class="title">No Orders Ready to Ship</div>
                <div class="description">Orders will appear here once they've been printed and approved.</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- File Issues Tab -->
        <div id="tab-issues" class="tab-content <?= $currentTab === 'issues' ? 'active' : '' ?>">
            <?php if (count($fileIssueOrders) > 0): ?>
            <div class="queue-table-wrapper">
                <table class="queue-table issue-table prod-table">
                    <thead>
                        <tr>
                            <th class="col-order">Order</th>
                            <th class="col-priority">Priority</th>
                            <th class="col-customer">Customer</th>
                            <th class="col-size">Size</th>
                            <th class="col-material">Material</th>
                            <th class="col-vendor">Vendor</th>
                            <th class="col-packing">Packing</th>
                            <th class="col-due">Due</th>
                            <th>Issue Details</th>
                            <th>File</th>
                            <th class="col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fileIssueOrders as $order): 
                            $refCode = $order['referenceCode'];
                            $pfInfo = $order['preflight_info'] ?? [];
                            $issueReason = htmlspecialchars($pfInfo['file_issue_reason'] ?? '');
                            $issueBy = $pfInfo['file_issue_by'] ?? 'admin';
                            $issueAt = !empty($pfInfo['file_issue_at']) ? date('D, M j g:ia', strtotime($pfInfo['file_issue_at'])) : '';
                            $vendorNotes = $pfInfo['vendor_notes'] ?? [];
                            $vendorName = $pfInfo['vendor_name'] ?? '';
                            $preflightNotes = $pfInfo['notes'] ?? '';
                            $customerNotes = $order['customerInfo']['additionalNotes'] ?? '';
                            $packType = $pfInfo['packing'] ?? 'none';
                            $origName = $order['uploadedFile']['originalName'] ?? '';
                            $fileName = function_exists('getDisplayFileName') ? getDisplayFileName($refCode, $origName) : $origName;
                            $fileUrl = '../uploads/' . ($order['uploadedFile']['filename'] ?? '');
                            $issueCols = 11;
                        ?>
                        <tr class="issue-row" data-ref="<?= htmlspecialchars($refCode) ?>">
                            <td><a href="#" class="order-link" onclick="event.preventDefault();openProductionPanel('<?= htmlspecialchars($refCode) ?>')"><?= htmlspecialchars($refCode) ?></a></td>
                            <td><?= getTierBadge($order['pricing']['tier'] ?? 'standard') ?></td>
                            <td><?= htmlspecialchars($order['customerInfo']['name'] ?? '-') ?></td>
                            <td><?= ($order['dimensions']['width'] ?? 0) ?>" &times; <?= ($order['dimensions']['height'] ?? 0) ?>"</td>
                            <td><?= ucfirst($order['material'] ?? 'paper') ?></td>
                            <td><?= !empty($vendorName) ? htmlspecialchars($vendorName) : '<span class="text-muted">&#8212;</span>' ?></td>
                            <td><span class="pack-label pack-<?= $packType ?>"><?= ucfirst($packType === 'none' ? 'Flat' : $packType) ?></span></td>
                            <td class="col-due-cell"><?= renderDueCell($order) ?></td>
                            <td class="issue-details-cell">
                                <?php if ($issueReason): ?>
                                <div class="issue-reason">
                                    <span class="issue-source <?= $issueBy === 'vendor' ? 'source-vendor' : 'source-admin' ?>">
                                        <?= $issueBy === 'vendor' ? 'Vendor' : 'Admin' ?>
                                    </span>
                                    <span class="issue-text"><?= $issueReason ?></span>
                                    <?php if ($issueAt): ?><span class="issue-time"><?= $issueAt ?></span><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td class="issue-file-cell">
                                <div class="fm-row" data-ref="<?= htmlspecialchars($refCode) ?>">
                                    <?php if ($origName): ?>
                                    <?php
                                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                                    $isImage = in_array($ext, ['jpg','jpeg','png','gif','webp']);
                                    $isPdf = ($ext === 'pdf');
                                    $dlUrl = '../fulfillment/api.php?action=download&ref=' . urlencode($refCode) . '&admin=1';
                                    ?>
                                    <div class="fm-file-link" onmouseenter="showFilePreview(event, this)" onmouseleave="hideFilePreview()">
                                        <a href="<?= $dlUrl ?>" class="fm-name-link" title="<?= htmlspecialchars($origName) ?>">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> <?= htmlspecialchars(mb_strimwidth($fileName, 0, 22, '...')) ?>
                                        </a>
                                        <?php if ($isImage): ?>
                                        <img class="fm-preview-src" src="<?= $dlUrl ?>" alt="" style="display:none">
                                        <?php elseif ($isPdf): ?>
                                        <span class="fm-preview-src fm-preview-pdf" data-type="pdf" style="display:none"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="fm-btns">
                                        <a href="<?= $dlUrl ?>" class="fm-btn" title="Download"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></a>
                                        <button class="fm-btn" onclick="deleteIssueFile('<?= htmlspecialchars($refCode) ?>')" title="Delete"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg></button>
                                    </div>
                                    <?php else: ?>
                                    <span class="fm-empty">No file</span>
                                    <?php endif; ?>
                                    <label class="fm-upload-inline" ondragover="event.preventDefault();this.classList.add('dragover')" ondragleave="this.classList.remove('dragover')" ondrop="event.preventDefault();this.classList.remove('dragover');handleIssueFileDrop(event,'<?= htmlspecialchars($refCode) ?>')">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Upload
                                        <input type="file" data-ref="<?= htmlspecialchars($refCode) ?>" onchange="handleIssueFileUpload(this)" accept=".pdf,.jpg,.jpeg,.png,.tif,.tiff,.ai,.psd,.eps,.svg" style="display:none">
                                    </label>
                                </div>
                            </td>
                            <td>
                                <?php if ($canEditPreflight): ?>
                                <div class="action-buttons">
                                    <button class="btn-confirm" onclick="resolveAndRepush('<?= $refCode ?>')" title="Resolve and re-push">
                                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Resolve &amp; Re-push
                                    </button>

                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php foreach ($vendorNotes as $vn): ?>
                        <tr class="issue-notes-row vendor-note-row">
                            <td colspan="<?= $issueCols ?>">
                                <span class="notes-row-label vendor-label">Vendor:</span>
                                <span class="notes-row-text"><?= htmlspecialchars($vn['text'] ?? '') ?></span>
                                <?php if (!empty($vn['timestamp'])): ?><span class="notes-row-time"><?= date('D, M j g:ia', strtotime($vn['timestamp'])) ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!empty($preflightNotes)): ?>
                        <tr class="issue-notes-row preflight-note-row">
                            <td colspan="<?= $issueCols ?>"><span class="notes-row-label admin-label">Production Note:</span> <span class="notes-row-text"><?= htmlspecialchars($preflightNotes) ?></span></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($customerNotes)): ?>
                        <tr class="issue-notes-row customer-note-row">
                            <td colspan="<?= $issueCols ?>"><span class="notes-row-label customer-label">Customer Note:</span> <span class="notes-row-text"><?= htmlspecialchars($customerNotes) ?></span></td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state success">
                <div class="icon"><?= ICON_CHECK_GREEN ?></div>
                <div class="title">No File Issues</div>
                <div class="description">All orders have valid files.</div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Vendors Tab (same as Phase 2) -->

    </div>

    <!-- Slide-Out Panel Overlay -->
    <div class="pp-overlay" id="prodPanelOverlay"></div>

    <!-- Slide-Out Panel -->
    <aside class="pp-panel" id="prodPanel">
        <div class="pp-header">
            <div class="pp-ref" id="prodPanelRef"></div>
            <button class="pp-close" id="prodPanelClose">&#215;</button>
        </div>
        <div class="pp-body" id="prodPanelBody"></div>
        <div class="pp-footer" id="prodPanelFooter"></div>
    </aside>

    <!-- Note Modal -->
    <div class="pp-note-modal" id="ppNoteModal" style="display:none;">
        <div class="pp-note-modal-inner">
            <div class="pp-note-modal-head">
                <h3>Add Note</h3>
                <button class="pp-note-modal-close" onclick="document.getElementById('ppNoteModal').style.display='none'">&#215;</button>
            </div>
            <div class="pp-note-modal-body">
                <textarea id="ppNoteText" rows="3" placeholder="Enter your note..."></textarea>
                <label class="pp-note-visibility"><input type="checkbox" id="ppNoteVisible"> Make visible to vendor</label>
            </div>
            <div class="pp-note-modal-foot">
                <button class="pp-btn pp-btn-ghost" onclick="document.getElementById('ppNoteModal').style.display='none'">Cancel</button>
                <button class="pp-btn pp-btn-primary" id="ppNoteSubmit">Save Note</button>
            </div>
        </div>
    </div>

    <!-- Queue Bulk Action Dock (floating bottom) -->
    <?php if ($canEditPreflight && count($activeVendors) > 0): ?>
    <div class="queue-bulk-dock" id="queueBulkDock">
        <div class="queue-bulk-inner">
            <span class="queue-bulk-count" id="queueBulkCount">0</span>
            <span class="queue-bulk-label">selected</span>
            <div class="pack-badge-wrapper pack-badge-dock" id="dockPackWrapper">
                <span class="pack-badge pack-dock-trigger" onclick="toggleDockPackDropdown(event)">Packing...</span>
                <div class="pack-dropdown pack-dropdown-dock" id="dockPackDrop">
                    <button class="pack-dropdown-item" data-value="none" onclick="bulkSetPacking('none')"><span class="pack-badge pack-none">None / Flat</span></button>
                    <button class="pack-dropdown-item" data-value="tube" onclick="bulkSetPacking('tube')"><span class="pack-badge pack-tube">Tube</span></button>
                    <button class="pack-dropdown-item" data-value="box" onclick="bulkSetPacking('box')"><span class="pack-badge pack-box">Box</span></button>
                    <button class="pack-dropdown-item" data-value="custom" onclick="bulkSetPacking('custom')"><span class="pack-badge pack-custom">Custom</span></button>
                </div>
            </div>
            <button class="queue-bulk-btn queue-bulk-batch" onclick="openCreateBatchModal()">
                <?= ICON_PACKAGE ?> Create Batch
            </button>
            <button class="queue-bulk-btn queue-bulk-push" onclick="openBulkPushModal()">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push to Vendor
            </button>
            <button class="queue-bulk-btn queue-bulk-issue" onclick="bulkMarkQueueIssue()">
                <?= ICON_WARNING ?> Mark Issue
            </button>
            <button class="queue-bulk-clear" onclick="clearQueueSelection()">&times;</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Push to Vendor Modal -->
    <div class="modal-overlay" id="pushModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">Push Order to Vendor</div>
                <button class="modal-close" onclick="closePushModal()">&times;</button>
            </div>
            <form id="pushForm" onsubmit="submitPush(event)">
                <input type="hidden" id="pushRefCode" name="reference_code" value="">
                <input type="hidden" id="pushBatchId" name="fulfillment_batch" value="">
                <div class="modal-body">
                    <div class="push-order-info" id="pushOrderInfo"></div>
                    
                    <div class="form-group">
                        <label>Select Vendor <span class="required">*</span></label>
                        <select id="pushVendor" name="vendor_id" required>
                            <?php foreach ($activeVendors as $v): ?>
                            <option value="<?= $v['id'] ?>" <?= $v['id'] === $defaultVendorId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($v['business_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Packing Method</label>
                        <select id="pushPacking" name="packing">
                            <option value="none">None / Flat</option>
                            <option value="tube">Tube</option>
                            <option value="box">Box</option>
                            <option value="custom">Custom (specify)</option>
                        </select>
                        <input type="text" id="pushPackingCustom" name="packing_custom" placeholder="Describe custom packing..." style="display:none; margin-top:6px;">
                    </div>
                    
                    <div class="form-group">
                        <label>Print Instructions (Optional)</label>
                        <textarea id="pushPrintNotes" name="print_notes" placeholder="DPI requirements, color profile (CMYK/RGB), bleed margins, special instructions..."></textarea>
                        <div style="font-size: 0.72rem; color: #9ca3af; margin-top: 4px;">These instructions will be visible to the vendor in the fulfillment portal.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Notes for Vendor (Optional)</label>
                        <textarea id="pushNotes" name="notes" placeholder="Any additional notes for the vendor..."></textarea>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <label>
                            <input type="checkbox" id="sendEmail" name="send_email" value="1" checked>
                            Send email notification to vendor
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closePushModal()">Cancel</button>
                    <button type="submit" class="btn btn-save" id="pushBtn"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push to Vendor</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Create Batch Modal -->
    <div class="modal-overlay" id="batchModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><?= ICON_PACKAGE ?> Create Fulfillment Batch</div>
                <button class="modal-close" onclick="closeBatchModal()">&times;</button>
            </div>
            <form id="batchForm" onsubmit="submitBatch(event)">
                <div class="modal-body">
                    <div class="batch-order-summary" id="batchOrderSummary"></div>
                    
                    <div class="form-group">
                        <label>Batch Label <span class="required">*</span></label>
                        <input type="text" id="batchLabel" name="label" placeholder="e.g. COMIC Fri Mar 6" required>
                        <div style="font-size: 0.72rem; color: #9ca3af; margin-top: 4px;">Auto-suggested from selected orders. Edit as needed.</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Batch Notes (Optional)</label>
                        <textarea id="batchNotes" name="notes" placeholder="Any notes about this batch..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeBatchModal()">Cancel</button>
                    <button type="submit" class="btn btn-save" id="batchBtn"><?= ICON_PACKAGE ?> Create Batch</button>
                </div>
            </form>
        </div>
    </div>

    <!-- File Issue Modal -->
    <div class="modal-overlay" id="issueModal">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title"><?= ICON_WARNING ?> Mark File Issue</div>
                <button class="modal-close" onclick="closeIssueModal()">&times;</button>
            </div>
            <form id="issueForm" onsubmit="submitIssue(event)">
                <input type="hidden" id="issueRefCode" name="reference_code" value="">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Issue Description</label>
                        <textarea id="issueNote" name="issue_note" placeholder="Describe the file issue..." required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-cancel" onclick="closeIssueModal()">Cancel</button>
                    <button type="submit" class="btn btn-warning"><?= ICON_WARNING ?> Mark Issue</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    
    
    <div id="toast" class="toast"></div>
    
    <script>
        const orderData = <?= json_encode(array_values($queueOrders)) ?>;
        const batchSuggestionsData = <?= json_encode($batchSuggestions ?? []) ?>;
        const defaultVendorId = <?= json_encode($defaultVendorId) ?>;
        
        // Tab switching
        function switchTab(tab) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            
            const buttons = document.querySelectorAll('.tab-btn');
            const tabMap = ['queue', 'progress', 'ready', 'issues'];
            const idx = tabMap.indexOf(tab);
            if (idx >= 0 && buttons[idx]) buttons[idx].classList.add('active');
            document.getElementById(`tab-${tab}`).classList.add('active');
            
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.replaceState({}, '', url);
        }
        
        // Select all checkboxes
        function toggleSelectAll(checkbox) {
            document.querySelectorAll('#tab-queue .order-checkbox').forEach(cb => cb.checked = checkbox.checked);
            updateQueueBulkDock();
        }
        
        // Individual checkbox change → update dock
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('order-checkbox') && e.target.closest('#tab-queue')) {
                updateQueueBulkDock();
            }
        });
        
        function updateQueueBulkDock() {
            const checked = document.querySelectorAll('#tab-queue .order-checkbox:checked');
            const dock = document.getElementById('queueBulkDock');
            if (!dock) return;
            document.getElementById('queueBulkCount').textContent = checked.length;
            if (checked.length > 0) {
                dock.classList.add('visible');
            } else {
                dock.classList.remove('visible');
            }
        }
        
        function clearQueueSelection() {
            document.querySelectorAll('#tab-queue .order-checkbox').forEach(cb => cb.checked = false);
            const sa = document.getElementById('selectAll');
            if (sa) { sa.checked = false; sa.indeterminate = false; }
            updateQueueBulkDock();
        }
        
        function bulkMarkQueueIssue() {
            const selected = Array.from(document.querySelectorAll('#tab-queue .order-checkbox:checked')).map(cb => cb.value);
            if (selected.length === 0) return;
            openIssueModal(selected[0]);
        }
        
        function bulkSetPacking(value) {
            if (value === 'custom') {
                var desc = prompt('Enter custom packing description:');
                if (!desc || !desc.trim()) return;
            }
            closeDockPackDropdown();
            document.querySelectorAll('#tab-queue .order-checkbox:checked').forEach(function(cb) {
                var ref = cb.value;
                var wrapper = document.querySelector('.pack-badge-wrapper[data-ref="' + ref + '"]');
                if (wrapper) setPackBadge(wrapper, value);
            });
            var count = document.querySelectorAll('#tab-queue .order-checkbox:checked').length;
            var label = {none:'None / Flat', tube:'Tube', box:'Box', custom:'Custom'}[value] || value;
            showToast('Packing set to ' + label + ' for ' + count + ' order(s)', 'success');
        }
        
        // Packing badge dropdown logic
        function togglePackDropdown(e, ref) {
            e.stopPropagation();
            closeAllPackDropdowns();
            var dd = document.getElementById('packDrop_' + ref);
            if (dd) {
                var rect = e.target.getBoundingClientRect();
                dd.style.top = (rect.bottom + 4) + 'px';
                dd.style.left = rect.left + 'px';
                dd.classList.add('show');
            }
        }
        
        function setRowPacking(ref, value, btnEl) {
            if (value === 'custom') {
                var desc = prompt('Enter custom packing description:');
                if (!desc || !desc.trim()) { closeAllPackDropdowns(); return; }
            }
            var wrapper = document.querySelector('.pack-badge-wrapper[data-ref="' + ref + '"]');
            if (wrapper) setPackBadge(wrapper, value);
            closeAllPackDropdowns();
        }
        
        function setPackBadge(wrapper, value) {
            var badge = wrapper.querySelector('.pack-badge:first-child');
            var labels = {none:'None / Flat', tube:'Tube', box:'Box', custom:'Custom'};
            badge.textContent = labels[value] || value;
            badge.className = 'pack-badge pack-' + value;
            badge.dataset.value = value;
            // Update current marker in dropdown
            wrapper.querySelectorAll('.pack-dropdown-item').forEach(function(item) {
                item.classList.toggle('current', item.dataset.value === value);
            });
        }
        
        function toggleDockPackDropdown(e) {
            e.stopPropagation();
            var dd = document.getElementById('dockPackDrop');
            if (dd) dd.classList.toggle('show');
        }
        
        function closeDockPackDropdown() {
            var dd = document.getElementById('dockPackDrop');
            if (dd) dd.classList.remove('show');
        }
        
        function closeAllPackDropdowns() {
            document.querySelectorAll('.pack-dropdown').forEach(function(dd) { dd.classList.remove('show'); });
        }
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.pack-badge-wrapper')) closeAllPackDropdowns();
        });
        
        // Push Modal — pre-fill packing from row quick-select
        function openPushModal(refCode) {
            const order = orderData.find(o => o.referenceCode === refCode);
            if (!order) return;
            
            document.getElementById('pushRefCode').value = refCode;
            // Set batch ID if order is in a batch
            var row = document.querySelector('tr[data-ref="' + refCode + '"]');
            document.getElementById('pushBatchId').value = row ? (row.dataset.batch || '') : '';
            document.getElementById('pushOrderInfo').innerHTML = `
                <strong>#${refCode}</strong> • 
                ${order.dimensions?.width || 0}" × ${order.dimensions?.height || 0}" • 
                ${order.material || 'paper'} • 
                Due: ${order.selectedDate ? new Date(order.selectedDate + 'T00:00:00').toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'}) : 'TBD'}${order.deliveryTime ? ' at ' + ({'9am':'9:00am','12pm':'12:00pm','3pm':'3:00pm','6pm':'6:00pm','anytime':'anytime'}[order.deliveryTime] || order.deliveryTime) : ''}
            `;
            document.getElementById('pushNotes').value = '';
            document.getElementById('pushPrintNotes').value = '';
            document.getElementById('sendEmail').checked = true;
            // Pre-fill packing from queue badge if set
            var queuePackBadge = document.querySelector('.pack-badge-wrapper[data-ref="' + refCode + '"] .pack-badge:first-child');
            var packVal = queuePackBadge ? (queuePackBadge.dataset.value || 'none') : 'none';
            document.getElementById('pushPacking').value = packVal;
            document.getElementById('pushPackingCustom').value = '';
            document.getElementById('pushPackingCustom').style.display = packVal === 'custom' ? 'block' : 'none';
            document.getElementById('pushModal').classList.add('show');
        }
        
        // Packing custom field toggle
        document.getElementById('pushPacking').addEventListener('change', function() {
            document.getElementById('pushPackingCustom').style.display = this.value === 'custom' ? 'block' : 'none';
        });
        
        function closePushModal() {
            document.getElementById('pushModal').classList.remove('show');
        }
        
        async function submitPush(event) {
            event.preventDefault();
            const btn = document.getElementById('pushBtn');
            btn.disabled = true;
            btn.textContent = 'Pushing...';
            
            const formData = new FormData(document.getElementById('pushForm'));
            formData.append('ajax_action', 'push_to_vendor');
            formData.append('send_email', document.getElementById('sendEmail').checked ? '1' : '0');
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message + (result.email_sent ? ' (email sent)' : ''), 'success');
                    closePushModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(result.error || 'Failed to push order', 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push to Vendor';
                }
            } catch (error) {
                showToast('An error occurred', 'error');
                btn.disabled = false;
                btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push to Vendor';
            }
        }
        
        // ============================================
        // SMART BATCH SUGGESTIONS
        // ============================================
        async function acceptSuggestion(index) {
            var sug = batchSuggestionsData[index];
            if (!sug) return;
            
            var formData = new FormData();
            formData.append('ajax_action', 'create_fulfillment_batch');
            formData.append('order_refs', JSON.stringify(sug.refs));
            formData.append('label', sug.auto_label || '');
            formData.append('notes', 'Auto-suggested: ' + sug.description);
            
            try {
                var response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                var result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showToast(result.error || 'Failed', 'error');
                }
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        // ============================================
        // FULFILLMENT BATCHING
        // ============================================
        function toggleBatchGroup(batchId) {
            var body = document.getElementById('batchBody_' + batchId);
            var chev = document.getElementById('batchChev_' + batchId);
            if (!body) return;
            if (body.style.display === 'none') {
                body.style.display = '';
                if (chev) chev.innerHTML = '&#9660;';
            } else {
                body.style.display = 'none';
                if (chev) chev.innerHTML = '&#9654;';
            }
        }
        
        function toggleBatchSelectAll(el, batchId) {
            var group = document.querySelector('.batch-group[data-batch-id="' + batchId + '"]');
            if (!group) return;
            group.querySelectorAll('.order-checkbox').forEach(function(cb) { cb.checked = el.checked; });
            updateQueueBulkDock();
        }
        
        function openBatchPushModal(batchId) {
            var group = document.querySelector('.batch-group[data-batch-id="' + batchId + '"]');
            if (!group) return;
            var refs = Array.from(group.querySelectorAll('tr[data-ref]')).map(r => r.dataset.ref);
            var label = group.querySelector('.fb-badge') ? group.querySelector('.fb-badge').textContent : batchId;
            
            document.getElementById('pushRefCode').value = '';
            document.getElementById('pushBatchId').value = batchId;
            document.getElementById('pushOrderInfo').innerHTML = '<strong>Batch: ' + label + '</strong> (' + refs.length + ' orders: ' + refs.join(', ') + ')';
            document.getElementById('pushNotes').value = '';
            document.getElementById('pushPrintNotes').value = '';
            document.getElementById('sendEmail').checked = true;
            document.getElementById('pushPacking').value = 'none';
            document.getElementById('pushPackingCustom').value = '';
            document.getElementById('pushPackingCustom').style.display = 'none';
            document.getElementById('pushModal').querySelector('.modal-title').textContent = 'Push Batch to Vendor';
            document.getElementById('pushModal').classList.add('show');
            
            // Override form submit for batch push
            document.getElementById('pushForm').onsubmit = async (e) => {
                e.preventDefault();
                const btn = document.getElementById('pushBtn');
                btn.disabled = true;
                btn.textContent = 'Pushing...';
                
                const formData = new FormData();
                formData.append('ajax_action', 'push_batch');
                formData.append('batch_id', batchId);
                formData.append('reference_codes', JSON.stringify(refs));
                formData.append('vendor_id', document.getElementById('pushVendor').value);
                formData.append('packing', document.getElementById('pushPacking').value);
                formData.append('packing_custom', document.getElementById('pushPackingCustom').value);
                formData.append('print_notes', document.getElementById('pushPrintNotes').value);
                formData.append('send_email', document.getElementById('sendEmail').checked ? '1' : '0');
                
                try {
                    const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                    const result = await response.json();
                    showToast(result.message, result.success ? 'success' : 'error');
                    closePushModal();
                    if (result.success) setTimeout(() => location.reload(), 800);
                } catch (error) {
                    showToast('An error occurred', 'error');
                }
                
                btn.disabled = false;
                btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push to Vendor';
                document.getElementById('pushForm').onsubmit = submitPush;
            };
        }
        
        async function removeBatchOrder(batchId, refCode) {
            const formData = new FormData();
            formData.append('ajax_action', 'remove_from_batch');
            formData.append('reference_code', refCode);
            try {
                const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                const result = await response.json();
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 500);
            } catch (error) { showToast('Error', 'error'); }
        }
        
        // Batch assignment dropdown on unbatched rows
        function toggleBatchAssign(e, refCode) {
            e.stopPropagation();
            closeAllBatchAssign();
            var dd = document.getElementById('batchAssign_' + refCode);
            if (dd) dd.classList.add('show');
        }
        
        function closeAllBatchAssign() {
            document.querySelectorAll('.batch-assign-dropdown').forEach(function(dd) { dd.classList.remove('show'); });
        }
        
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.batch-assign-wrapper')) closeAllBatchAssign();
        });
        
        async function assignToBatch(refCode, batchId) {
            closeAllBatchAssign();
            const formData = new FormData();
            formData.append('ajax_action', 'add_to_batch');
            formData.append('reference_code', refCode);
            formData.append('batch_id', batchId);
            try {
                const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                const result = await response.json();
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 500);
            } catch (error) { showToast('Error', 'error'); }
        }
        
        function openCreateBatchFromRow(refCode) {
            closeAllBatchAssign();
            // Pre-check the order's checkbox and open batch modal
            var cb = document.querySelector('.order-checkbox[value="' + refCode + '"]');
            if (cb) cb.checked = true;
            updateQueueBulkDock();
            openCreateBatchModal();
        }
        
        async function deleteBatch(batchId, label) {
            if (!confirm('Disband batch "' + label + '"? Orders will move to unbatched.')) return;
            const formData = new FormData();
            formData.append('ajax_action', 'delete_batch');
            formData.append('batch_id', batchId);
            try {
                const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                const result = await response.json();
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 500);
            } catch (error) { showToast('Error', 'error'); }
        }
        
        async function editBatchInfo(batchId, currentLabel, currentNotes) {
            var newLabel = prompt('Batch label:', currentLabel || '');
            if (newLabel === null) return; // cancelled
            var newNotes = prompt('Batch notes:', currentNotes || '');
            if (newNotes === null) newNotes = currentNotes || '';
            const formData = new FormData();
            formData.append('ajax_action', 'edit_batch');
            formData.append('batch_id', batchId);
            formData.append('label', newLabel);
            formData.append('notes', newNotes);
            try {
                const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                const result = await response.json();
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 500);
            } catch (error) { showToast('Error', 'error'); }
        }
        function openCreateBatchModal() {
            const selected = Array.from(document.querySelectorAll('#tab-queue .order-checkbox:checked')).map(cb => cb.value);
            if (selected.length === 0) {
                showToast('Select at least one order', 'error');
                return;
            }
            
            // Check if any selected orders are already batched
            const alreadyBatched = selected.filter(ref => {
                const row = document.querySelector('tr[data-ref="' + ref + '"] .fb-badge');
                return row !== null;
            });
            if (alreadyBatched.length > 0) {
                showToast('Some orders are already batched: ' + alreadyBatched.join(', '), 'error');
                return;
            }
            
            // Auto-suggest label: extract event prefix + common due date
            const orders = selected.map(ref => orderData.find(o => o.referenceCode === ref)).filter(Boolean);
            const events = [...new Set(orders.map(o => (o.referenceCode || '').split('-')[0]).filter(Boolean))];
            const dueDates = [...new Set(orders.map(o => o.selectedDate).filter(Boolean))];
            
            let autoLabel = '';
            if (events.length === 1) {
                autoLabel = events[0];
            } else {
                autoLabel = events.join('+');
            }
            if (dueDates.length === 1) {
                const d = new Date(dueDates[0] + 'T00:00:00');
                autoLabel += ' ' + d.toLocaleDateString('en-US', {weekday:'short', month:'short', day:'numeric'});
            } else if (dueDates.length > 1) {
                const sorted = dueDates.sort();
                const d1 = new Date(sorted[0] + 'T00:00:00');
                const d2 = new Date(sorted[sorted.length-1] + 'T00:00:00');
                autoLabel += ' ' + d1.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + '-' + d2.toLocaleDateString('en-US', {day:'numeric'});
            }
            
            // Show summary
            const summaryHtml = '<strong>' + selected.length + ' order' + (selected.length > 1 ? 's' : '') + '</strong>: ' +
                selected.map(r => '<span class="batch-ref-tag">' + r + '</span>').join(' ');
            document.getElementById('batchOrderSummary').innerHTML = summaryHtml;
            document.getElementById('batchLabel').value = autoLabel.trim();
            document.getElementById('batchNotes').value = '';
            
            // Store selected refs for submit
            document.getElementById('batchForm').dataset.refs = JSON.stringify(selected);
            document.getElementById('batchModal').classList.add('show');
            document.getElementById('batchLabel').focus();
        }
        
        function closeBatchModal() {
            document.getElementById('batchModal').classList.remove('show');
        }
        
        async function submitBatch(event) {
            event.preventDefault();
            const btn = document.getElementById('batchBtn');
            btn.disabled = true;
            btn.textContent = 'Creating...';
            
            const refs = JSON.parse(document.getElementById('batchForm').dataset.refs || '[]');
            const formData = new FormData();
            formData.append('ajax_action', 'create_fulfillment_batch');
            formData.append('order_refs', JSON.stringify(refs));
            formData.append('label', document.getElementById('batchLabel').value.trim());
            formData.append('notes', document.getElementById('batchNotes').value.trim());
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    showToast(result.message, 'success');
                    closeBatchModal();
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(result.error || 'Failed to create batch', 'error');
                }
            } catch (error) {
                showToast('An error occurred', 'error');
            }
            
            btn.disabled = false;
            btn.innerHTML = '<?= ICON_PACKAGE ?> Create Batch';
        }
        
        // Bulk Push
        function openBulkPushModal() {
            const selected = Array.from(document.querySelectorAll('.order-checkbox:checked')).map(cb => cb.value);
            if (selected.length === 0) {
                showToast('Please select at least one order', 'error');
                return;
            }
            
            document.getElementById('pushRefCode').value = '';
            document.getElementById('pushOrderInfo').innerHTML = `<strong>${selected.length} orders selected</strong>`;
            document.getElementById('pushModal').querySelector('.modal-title').textContent = 'Push Multiple Orders';
            document.getElementById('pushModal').classList.add('show');
            
            // Override form submit for bulk
            document.getElementById('pushForm').onsubmit = async (e) => {
                e.preventDefault();
                const btn = document.getElementById('pushBtn');
                btn.disabled = true;
                btn.textContent = 'Pushing...';
                
                const formData = new FormData();
                formData.append('ajax_action', 'push_multiple');
                formData.append('reference_codes', JSON.stringify(selected));
                formData.append('vendor_id', document.getElementById('pushVendor').value);
                formData.append('packing', document.getElementById('pushPacking').value);
                formData.append('packing_custom', document.getElementById('pushPackingCustom').value);
                formData.append('print_notes', document.getElementById('pushPrintNotes').value);
                formData.append('send_email', document.getElementById('sendEmail').checked ? '1' : '0');
                
                try {
                    const response = await fetch(window.location.pathname + window.location.search, {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();
                    
                    showToast(result.message, result.success ? 'success' : 'error');
                    closePushModal();
                    if (result.success) setTimeout(() => location.reload(), 800);
                } catch (error) {
                    showToast('An error occurred', 'error');
                }
                
                btn.disabled = false;
                btn.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Push to Vendor';
                document.getElementById('pushForm').onsubmit = submitPush;
            };
        }
        
        // Issue Modal
        function openIssueModal(refCode) {
            document.getElementById('issueRefCode').value = refCode;
            document.getElementById('issueNote').value = '';
            document.getElementById('issueModal').classList.add('show');
        }
        
        function closeIssueModal() {
            document.getElementById('issueModal').classList.remove('show');
        }
        
        async function submitIssue(event) {
            event.preventDefault();
            
            const formData = new FormData(document.getElementById('issueForm'));
            formData.append('ajax_action', 'mark_file_issue');
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast('Marked as file issue', 'success');
                    closeIssueModal();
                    setTimeout(() => location.reload(), 500);
                } else {
                    showToast(result.error || 'Failed', 'error');
                }
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        // Price review actions (calls fulfillment API)
        async function approvePrice(refCode) {
            if (!confirm('Approve vendor price for ' + refCode + '?')) return;
            try {
                const response = await fetch('../fulfillment/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'accept_price', reference_code: refCode })
                });
                const result = await response.json();
                showToast(result.message || 'Price approved', result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 800);
            } catch(e) { showToast('Error approving price', 'error'); }
        }
        
        async function rejectPrice(refCode) {
            var reason = prompt('Reason for rejecting price:');
            if (reason === null) return;
            try {
                const response = await fetch('../fulfillment/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'reject_price', reference_code: refCode, reason: reason })
                });
                const result = await response.json();
                showToast(result.message || 'Price rejected', result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 800);
            } catch(e) { showToast('Error rejecting price', 'error'); }
        }
        
        // Approve & Ready actions
        async function approveBatchPrices(batchId) {
            if (!confirm('Approve all prices in batch ' + batchId + '?')) return;
            try {
                // Get all refs in this batch with submitted prices
                var group = document.querySelector('.batch-group[data-batch-id="' + batchId + '"]');
                if (!group) return;
                var refs = [];
                group.querySelectorAll('tr[data-ref]').forEach(function(row) {
                    refs.push(row.dataset.ref);
                });
                for (var i = 0; i < refs.length; i++) {
                    var response = await fetch('../fulfillment/api.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'accept_price', reference_code: refs[i] })
                    });
                }
                showToast('Batch prices approved', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } catch(e) { showToast('Error approving batch prices', 'error'); }
        }
        

        
        async function markReady(refCode) {
            if (!confirm('Mark this order as ready?')) return;
            await quickAction('mark_ready', refCode, 'Order marked as ready');
        }
        
        // ============================================
        // In Progress - Bulk Selection
        // ============================================
        function toggleProgressSelectAll(checkbox) {
            document.querySelectorAll('.progress-checkbox').forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateProgressBulkBar();
        }
        
        function updateProgressBulkBar() {
            const selected = document.querySelectorAll('.progress-checkbox:checked');
            const bar = document.getElementById('progressBulkBar');
            const count = document.getElementById('progressBulkCount');
            
            if (selected.length > 0) {
                bar.style.display = 'flex';
                count.textContent = selected.length + ' selected';
            } else {
                bar.style.display = 'none';
            }
        }
        
        function getSelectedProgressRefs(filterStatus) {
            const checkboxes = document.querySelectorAll('.progress-checkbox:checked');
            const refs = [];
            checkboxes.forEach(cb => {
                if (!filterStatus || cb.dataset.status === filterStatus) {
                    refs.push(cb.value);
                }
            });
            return refs;
        }
        
        async function bulkApprove() {
            const refs = getSelectedProgressRefs('preflight');
            if (refs.length === 0) {
                showToast('No awaiting orders selected', 'error');
                return;
            }
            if (!confirm(`Approve ${refs.length} order(s) for printing?`)) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'bulk_approve');
            formData.append('reference_codes', JSON.stringify(refs));
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 800);
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        async function bulkMarkReady() {
            const refs = getSelectedProgressRefs('printing');
            if (refs.length === 0) {
                showToast('No printing orders selected', 'error');
                return;
            }
            if (!confirm(`Mark ${refs.length} order(s) as ready?`)) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'bulk_mark_ready');
            formData.append('reference_codes', JSON.stringify(refs));
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                showToast(result.message, result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 800);
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        async function bulkMarkIssue() {
            const refs = Array.from(document.querySelectorAll('.progress-checkbox:checked')).map(cb => cb.value);
            if (refs.length === 0) {
                showToast('No orders selected', 'error');
                return;
            }
            // For bulk issue, open the issue modal for first order
            // (Issues usually need individual notes, so prompt one at a time)
            if (refs.length === 1) {
                openIssueModal(refs[0]);
            } else {
                showToast('Please mark file issues one at a time (each needs specific notes)', 'error');
            }
        }
        
        async function deleteIssueFile(refCode) {
            if (!confirm('Delete the file for ' + refCode + '?')) return;
            try {
                var resp = await fetch('../fulfillment/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_file', reference_code: refCode })
                });
                var result = await resp.json();
                if (result.success) {
                    showToast('File deleted for ' + refCode, 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showToast(result.error || 'Delete failed', 'error');
                }
            } catch(e) { showToast('Error deleting file', 'error'); }
        }
        
        async function resolveAndRepush(refCode) {
            if (!confirm('Resolve this issue and re-push to the vendor?')) return;
            try {
                // First resolve
                var resp = await fetch('../fulfillment/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'resolve_issue', reference_code: refCode })
                });
                var result = await resp.json();
                if (result.success) {
                    showToast('Issue resolved, opening push...', 'success');
                    setTimeout(function() { openPushModal(refCode); }, 500);
                } else {
                    showToast(result.error || 'Failed to resolve', 'error');
                }
            } catch(e) { showToast('Error resolving issue', 'error'); }
        }
        
        async function handleIssueFileUpload(input) {
            var file = input.files[0];
            if (!file) return;
            var refCode = input.dataset.ref;
            uploadIssueFile(refCode, file);
            input.value = '';
        }
        
        function handleIssueFileDrop(event, refCode) {
            var file = event.dataTransfer.files[0];
            if (file) uploadIssueFile(refCode, file);
        }
        
        async function uploadIssueFile(refCode, file) {
            var formData = new FormData();
            formData.append('file', file);
            formData.append('reference_code', refCode);
            formData.append('action', 'replace_file');
            try {
                var resp = await fetch('../upload-order.php', { method: 'POST', body: formData });
                var result = await resp.json();
                if (result.success) {
                    showToast('File updated for ' + refCode, 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showToast(result.error || 'Upload failed', 'error');
                }
            } catch(e) { showToast('Upload error', 'error'); }
        }
        
        function previewIssueFile(refCode) {
            window.open('../fulfillment/api.php?action=download&ref=' + encodeURIComponent(refCode) + '&admin=1', '_blank');
        }
        
        async function deleteIssueFile(refCode) {
            if (!confirm('Delete the file for ' + refCode + '? This cannot be undone.')) return;
            try {
                var resp = await fetch('../fulfillment/api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete_file', reference_code: refCode })
                });
                var result = await resp.json();
                if (result.success) {
                    showToast('File deleted for ' + refCode, 'success');
                    setTimeout(function() { location.reload(); }, 800);
                } else {
                    showToast(result.error || 'Delete failed', 'error');
                }
            } catch(e) { showToast('Delete error', 'error'); }
        }
        
        async function resolveIssue(refCode) {
            if (!confirm('Mark this file issue as resolved? Order will return to Queue.')) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'resolve_file_issue');
            formData.append('reference_code', refCode);
            formData.append('target_status', 'paid');
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                showToast(result.success ? 'Issue resolved' : (result.error || 'Failed'), result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 500);
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        async function quickAction(action, refCode, successMsg) {
            const formData = new FormData();
            formData.append('ajax_action', action);
            formData.append('reference_code', refCode);
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                showToast(result.success ? successMsg : (result.error || 'Failed'), result.success ? 'success' : 'error');
                if (result.success) setTimeout(() => location.reload(), 500);
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        // Details Modal
        let currentDetailsRefCode = null;
        

        
        function renderDetailsContent(data) {
            const order = data.order;
            const preflight = data.preflight;
            const vendor = data.vendor;
            const token = data.token;
            const time = data.time_metrics;
            
            let html = `
                <div class="details-grid">
                    <div class="details-section">
                        <h4>Order Information</h4>
                        <div class="details-row">
                            <span class="label">Reference:</span>
                            <span class="value"><strong>#${data.reference_code}</strong></span>
                        </div>
                        <div class="details-row">
                            <span class="label">Customer:</span>
                            <span class="value">${order.customer_name || '-'}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Size:</span>
                            <span class="value">${order.dimensions}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Material:</span>
                            <span class="value">${order.material}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Due Date:</span>
                            <span class="value">${order.due_date ? new Date(order.due_date + 'T00:00:00').toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'}) + (order.delivery_time ? ' at ' + ({'9am':'9:00am','12pm':'12:00pm','3pm':'3:00pm','6pm':'6:00pm','anytime':'anytime'}[order.delivery_time] || order.delivery_time) : '') : '-'}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Priority:</span>
                            <span class="value tier-${order.tier}">${order.tier}</span>
                        </div>
                    </div>
                    
                    <div class="details-section">
                        <h4>Vendor Status</h4>
                        ${vendor ? `
                        <div class="details-row">
                            <span class="label">Vendor:</span>
                            <span class="value">${vendor.business_name}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Email:</span>
                            <span class="value">${vendor.email}</span>
                        </div>
                        ` : '<p class="no-data">No vendor assigned</p>'}
                        
                        ${preflight ? `
                        <div class="details-row">
                            <span class="label">Pushed:</span>
                            <span class="value">${time ? time.elapsed_human : '-'}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Pushed By:</span>
                            <span class="value">${preflight.pushed_by || '-'}</span>
                        </div>
                        ${preflight.resend_count > 0 ? `
                        <div class="details-row">
                            <span class="label">Resent:</span>
                            <span class="value">${preflight.resend_count} time(s)</span>
                        </div>
                        ` : ''}
                        ${preflight.notes ? `
                        <div class="details-row notes-row">
                            <span class="label">Notes:</span>
                            <span class="value">${preflight.notes}</span>
                        </div>
                        ` : ''}
                        ` : ''}
                    </div>
                    
                    <div class="details-section">
                        <h4>Portal Access</h4>
                        ${token ? `
                        <div class="details-row">
                            <span class="label">Token:</span>
                            <span class="value token-value">${token.token}</span>
                        </div>
                        <div class="details-row">
                            <span class="label">Status:</span>
                            <span class="value ${token.is_expired ? 'expired' : (token.is_revoked ? 'revoked' : 'active')}">
                                ${token.is_expired ? '⚠️ Expired' : (token.is_revoked ? '❌ Revoked' : '✅ Active')}
                            </span>
                        </div>
                        <div class="details-row">
                            <span class="label">Downloads:</span>
                            <span class="value">${token.download_count} time(s)</span>
                        </div>
                        ${token.confirmed_at ? `
                        <div class="details-row">
                            <span class="label">Confirmed:</span>
                            <span class="value">${new Date(token.confirmed_at).toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'}) + ' at ' + new Date(token.confirmed_at).toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'})}</span>
                        </div>
                        ` : ''}
                        <div class="details-row">
                            <span class="label">Portal Link:</span>
                            <span class="value">
                                <a href="${token.portal_url}" target="_blank" class="portal-link">Open Portal</a>
                                <button class="btn-copy" onclick="copyToClipboard('${token.portal_url}')">&#128203;</button>
                            </span>
                        </div>
                        ` : '<p class="no-data">No token generated</p>'}
                    </div>
                    
                    <div class="details-section">
                        <h4>Reminders</h4>
                        ${data.reminders ? `
                        <div class="details-row">
                            <span class="label">Reminders Sent:</span>
                            <span class="value">${data.reminders.count}</span>
                        </div>
                        ${data.reminders.last_sent_at ? `
                        <div class="details-row">
                            <span class="label">Last Reminder:</span>
                            <span class="value">${data.reminders.last_sent_human}</span>
                        </div>
                        ` : ''}
                        ${data.reminders.history && data.reminders.history.length > 0 ? `
                        <div class="reminder-history">
                            <span class="label">History:</span>
                            <ul class="reminder-list">
                                ${data.reminders.history.map(r => `
                                    <li>#${r.reminder_number} - ${new Date(r.sent_at).toLocaleDateString('en-US', {weekday:'long', year:'numeric', month:'long', day:'numeric'}) + ' at ' + new Date(r.sent_at).toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'})}</li>
                                `).join('')}
                            </ul>
                        </div>
                        ` : ''}
                        <div class="reminder-action">
                            <button class="btn btn-reminder" onclick="sendManualReminder('${data.reference_code}')">
                                ⏰ Send Reminder Now
                            </button>
                        </div>
                        ` : `
                        <p class="no-data">No reminders sent yet</p>
                        <div class="reminder-action">
                            <button class="btn btn-reminder" onclick="sendManualReminder('${data.reference_code}')">
                                ⏰ Send Reminder Now
                            </button>
                        </div>
                        `}
                    </div>
                    
                    ${time && time.is_overdue ? `
                    <div class="details-section alert-section ${time.is_critical ? 'critical' : 'warning'}">
                        <h4>${time.is_critical ? '&#128680; Critical Alert' : '&#9888;&#65039; Overdue Warning'}</h4>
                        <p>This order has been awaiting vendor confirmation for <strong>${time.elapsed_human}</strong>.</p>
                        <p>Consider resending the vendor email or contacting them directly.</p>
                    </div>
                    ` : ''}
                </div>
            `;
            
            document.getElementById('detailsContent').innerHTML = html;
        }
        
        async function sendManualReminder(refCode) {
            if (!confirm('Send a reminder email to the vendor for this order?')) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'send_manual_reminder');
            formData.append('reference_code', refCode);
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    // Refresh details modal
                    if (currentDetailsRefCode === refCode) {
                        openDetailsModal(refCode);
                    }
                } else {
                    showToast(result.error || 'Failed to send reminder', 'error');
                }
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        

        
        async function resendFromDetails() {
            if (!currentDetailsRefCode) return;
            await resendEmail(currentDetailsRefCode, true);
        }
        
        async function approveFromDetails() {
            if (!currentDetailsRefCode) return;
            if (!confirm('Approve this order for printing?')) return;
            await quickAction('mark_confirmed', currentDetailsRefCode, 'Order approved');
        }
        
        // Resend Email
        async function resendEmail(refCode, regenerateToken = false) {
            if (!confirm('Resend vendor email for this order?' + (regenerateToken ? '\n\nThis will generate a new portal link.' : ''))) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'resend_vendor_email');
            formData.append('reference_code', refCode);
            formData.append('regenerate_token', regenerateToken ? '1' : '0');
            
            try {
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    showToast(result.message, 'success');
                    if (currentDetailsRefCode === refCode) {
                        openDetailsModal(refCode); // Refresh details
                    }
                } else {
                    showToast(result.error || 'Failed to resend email', 'error');
                }
            } catch (error) {
                showToast('An error occurred', 'error');
            }
        }
        
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                showToast('Link copied to clipboard', 'success');
            }).catch(() => {
                showToast('Failed to copy', 'error');
            });
        }
        
        // Vendor functions (same as Phase 2)
// Toast
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = `toast ${type} show`;
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
        
        // Modal close handlers
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('show');
                }
            });
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.show').forEach(m => m.classList.remove('show'));
            }
        });
        
        // ============================================
        // TABLE SORT + COLUMN SYNC + RESIZE
        // ============================================
        (function() {
            // ---- SORT ----
            document.querySelectorAll('.prod-table thead th').forEach(function(th) {
                if (th.classList.contains('col-check')) return;
                th.style.cursor = 'pointer';
                th.addEventListener('click', function(e) {
                    // Don't sort if clicking resize handle
                    if (e.target.classList.contains('col-resize-handle')) return;
                    var table = th.closest('table');
                    var tbody = table.querySelector('tbody');
                    if (!tbody) return;
                    var colIdx = Array.from(th.parentNode.children).indexOf(th);
                    var rows = Array.from(tbody.querySelectorAll('tr'));
                    
                    // Determine sort direction
                    var asc = th.dataset.sortDir !== 'asc';
                    // Clear all sort indicators in this table
                    th.parentNode.querySelectorAll('th').forEach(function(h) {
                        h.dataset.sortDir = '';
                        h.classList.remove('sorted-asc', 'sorted-desc');
                    });
                    th.dataset.sortDir = asc ? 'asc' : 'desc';
                    th.classList.add(asc ? 'sorted-asc' : 'sorted-desc');
                    
                    rows.sort(function(a, b) {
                        var aCell = a.children[colIdx];
                        var bCell = b.children[colIdx];
                        if (!aCell || !bCell) return 0;
                        var aVal = (aCell.dataset.sortValue || aCell.textContent).trim().toLowerCase();
                        var bVal = (bCell.dataset.sortValue || bCell.textContent).trim().toLowerCase();
                        // Try numeric
                        var aNum = parseFloat(aVal.replace(/[^0-9.-]/g, ''));
                        var bNum = parseFloat(bVal.replace(/[^0-9.-]/g, ''));
                        if (!isNaN(aNum) && !isNaN(bNum)) {
                            return asc ? aNum - bNum : bNum - aNum;
                        }
                        // Try date
                        var aDate = Date.parse(aVal);
                        var bDate = Date.parse(bVal);
                        if (!isNaN(aDate) && !isNaN(bDate)) {
                            return asc ? aDate - bDate : bDate - aDate;
                        }
                        // String
                        return asc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
                    });
                    
                    rows.forEach(function(row) { tbody.appendChild(row); });
                });
            });
            
            // ---- COLUMN SYNC (sidebar-aware) ----
            function syncTabColumns(tabEl) {
                if (!tabEl) return;
                var tables = tabEl.querySelectorAll('.prod-table');
                if (tables.length < 2) return;
                
                var refThs = tables[0].querySelectorAll('thead th');
                var colCount = refThs.length;
                
                // Verify all tables have same column count
                var allSame = true;
                tables.forEach(function(t) {
                    if (t.querySelectorAll('thead th').length !== colCount) allSame = false;
                });
                if (!allSame) return;
                
                // Clear prior forced widths
                tables.forEach(function(t) {
                    t.querySelectorAll('thead th').forEach(function(th) {
                        th.style.width = '';
                        th.style.minWidth = '';
                    });
                });
                
                // Force reflow
                void tabEl.offsetWidth;
                
                // Measure available width from the content container
                var container = tabEl.closest('.production-content') || tabEl.parentElement;
                var availableWidth = container.clientWidth - 40; // padding
                
                // Measure max natural width per column across all tables
                var maxWidths = new Array(colCount).fill(0);
                tables.forEach(function(t) {
                    var ths = t.querySelectorAll('thead th');
                    for (var i = 0; i < colCount; i++) {
                        var w = ths[i].offsetWidth;
                        if (w > maxWidths[i]) maxWidths[i] = w;
                    }
                });
                
                var totalWidth = maxWidths.reduce(function(a, b) { return a + b; }, 0);
                
                // Identify column types by header text
                var colTypes = [];
                refThs.forEach(function(th) {
                    var txt = th.textContent.trim().toLowerCase();
                    colTypes.push(txt);
                });
                
                // If total exceeds available space, scale down proportionally
                if (totalWidth > availableWidth && availableWidth > 0) {
                    var scale = availableWidth / totalWidth;
                    for (var i = 0; i < colCount; i++) {
                        maxWidths[i] = Math.floor(maxWidths[i] * scale);
                    }
                }
                
                // If there's leftover space, distribute it smartly
                var finalTotal = maxWidths.reduce(function(a, b) { return a + b; }, 0);
                var leftover = availableWidth - finalTotal;
                
                if (leftover > 0) {
                    // Actions gets NO extra — lock it to its measured width
                    // Due gets 30% of leftover
                    // Rest split evenly among other non-fixed columns
                    var dueIdx = -1;
                    var actionsIdx = -1;
                    var checkIdx = -1;
                    var stretchable = [];
                    
                    for (var i = 0; i < colCount; i++) {
                        var t = colTypes[i];
                        if (t === 'actions') actionsIdx = i;
                        else if (t === 'due') dueIdx = i;
                        else if (t === '' || refThs[i].classList.contains('col-check')) checkIdx = i;
                        else stretchable.push(i);
                    }
                    
                    var dueBonus = Math.floor(leftover * 0.3);
                    var remaining = leftover - dueBonus;
                    var perCol = stretchable.length > 0 ? Math.floor(remaining / stretchable.length) : 0;
                    
                    if (dueIdx >= 0) maxWidths[dueIdx] += dueBonus;
                    stretchable.forEach(function(idx) { maxWidths[idx] += perCol; });
                    // Actions stays as-is (no extra)
                }
                
                // Apply widths to all tables
                tables.forEach(function(t) {
                    var ths = t.querySelectorAll('thead th');
                    for (var i = 0; i < colCount; i++) {
                        ths[i].style.minWidth = maxWidths[i] + 'px';
                    }
                });
            }
            
            function syncActiveTab() {
                var active = document.querySelector('.tab-content.active');
                if (active) syncTabColumns(active);
            }
            
            // Propagate col-actions class to td cells
            document.querySelectorAll('.prod-table').forEach(function(table) {
                var ths = table.querySelectorAll('thead th');
                ths.forEach(function(th, idx) {
                    if (th.classList.contains('col-actions')) {
                        table.querySelectorAll('tbody tr').forEach(function(row) {
                            if (row.children[idx]) row.children[idx].classList.add('col-actions');
                        });
                    }
                });
            });
            
            // Run on load
            syncActiveTab();
            
            // Re-sync on tab switch
            var origSwitchTab = window.switchTab;
            window.switchTab = function(tab) {
                if (origSwitchTab) origSwitchTab(tab);
                setTimeout(syncActiveTab, 50);
            };
            
            // Re-sync when sidebar toggles (watch for body class change)
            var sidebarObserver = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    if (m.attributeName === 'class') {
                        setTimeout(syncActiveTab, 300); // wait for sidebar transition
                    }
                });
            });
            sidebarObserver.observe(document.body, { attributes: true });
            
            // Also re-sync on window resize
            var resizeTimer;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(syncActiveTab, 150);
            });
            
            // ---- RESIZE HANDLES (invisible, synced across tables) ----
            document.querySelectorAll('.prod-table').forEach(function(table) {
                var ths = table.querySelectorAll('thead th');
                ths.forEach(function(th, colIdx) {
                    if (th.classList.contains('col-check')) return;
                    var handle = document.createElement('div');
                    handle.className = 'col-resize-handle';
                    th.style.position = 'relative';
                    th.appendChild(handle);
                    
                    var startX, startW;
                    handle.addEventListener('mousedown', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        startX = e.pageX;
                        startW = th.offsetWidth;
                        document.addEventListener('mousemove', onDrag);
                        document.addEventListener('mouseup', onUp);
                        document.body.style.cursor = 'col-resize';
                        document.body.style.userSelect = 'none';
                    });
                    
                    function onDrag(e) {
                        var w = Math.max(40, startW + (e.pageX - startX));
                        // Sync to all tables in this tab at same column index
                        var tabEl = table.closest('.tab-content');
                        if (tabEl) {
                            tabEl.querySelectorAll('.prod-table').forEach(function(t) {
                                var targetTh = t.querySelectorAll('thead th')[colIdx];
                                if (targetTh) {
                                    targetTh.style.width = w + 'px';
                                    targetTh.style.minWidth = w + 'px';
                                }
                            });
                        }
                    }
                    function onUp() {
                        document.removeEventListener('mousemove', onDrag);
                        document.removeEventListener('mouseup', onUp);
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    }
                });
            });
        })();
    </script>
</div>
    

<script src="production-panel.js"></script>

<!-- File hover preview (shared, position:fixed) -->
<div class="fm-hover-preview" id="fmHoverPreview"><img id="fmHoverImg" src="" alt=""><div id="fmHoverPdf" style="display:none;padding:16px 24px;text-align:center;"><svg width="40" height="48" viewBox="0 0 40 48" fill="none"><rect width="40" height="48" rx="4" fill="#dc2626"/><text x="20" y="30" text-anchor="middle" font-size="12" font-weight="700" fill="white">PDF</text></svg></div></div>
<script>
function showFilePreview(e, el) {
    var src = el.querySelector('.fm-preview-src');
    if (!src) return;
    var preview = document.getElementById('fmHoverPreview');
    var previewImg = document.getElementById('fmHoverImg');
    var previewPdf = document.getElementById('fmHoverPdf');
    if (src.dataset.type === 'pdf') {
        previewImg.style.display = 'none';
        previewPdf.style.display = 'block';
    } else {
        previewImg.src = src.src;
        previewImg.style.display = 'block';
        previewPdf.style.display = 'none';
    }
    var rect = el.getBoundingClientRect();
    preview.style.left = rect.left + 'px';
    preview.style.top = (rect.top - 10) + 'px';
    preview.style.transform = 'translateY(-100%)';
    preview.classList.add('show');
}
function hideFilePreview() {
    document.getElementById('fmHoverPreview').classList.remove('show');
}
</script>
</body>
</html>
