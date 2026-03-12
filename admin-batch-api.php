<?php
/**
 * Admin Batch API
 * Handles batch management calls from the dispatch-manager admin UI.
 * Uses admin session auth (not courier auth).
 * 
 * Server path: /admin-batch-api.php (root directory, same as dispatch-manager.php)
 * 
 * Actions:
 *   suggest_batches  — Run auto-detection scan
 *   create_batch     — Create batch from selected orders
 *   disband_batch    — Cancel/disband a batch
 */
session_start();
header('Content-Type: application/json');

// Require admin auth
require_once __DIR__ . '/admin-auth.php';
if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Admin authentication required']);
    exit;
}

// Load dispatch functions (provides constants and helpers)
$dispatchFunctions = __DIR__ . '/dispatch/dispatch-functions.php';
if (file_exists($dispatchFunctions)) {
    require_once $dispatchFunctions;
}

// Load Routes API
$routesApi = __DIR__ . '/courier/routes-api.php';
if (file_exists($routesApi)) {
    require_once $routesApi;
}

// Load batch functions
require_once __DIR__ . '/courier/batch-functions.php';

// We need the courier API helpers that batch-functions.php depends on.
// Define stubs for functions that live in courier/api.php if not already loaded.
if (!function_exists('courier_loadStatuses')) {
    define('COURIER_STATUSES_FILE', __DIR__ . '/data/statuses.json');
    
    function courier_loadStatuses() {
        if (!file_exists(COURIER_STATUSES_FILE)) return [];
        return json_decode(file_get_contents(COURIER_STATUSES_FILE), true) ?: [];
    }
}

if (!function_exists('courier_getStatus')) {
    function courier_getStatus($ref) {
        $statuses = courier_loadStatuses();
        return $statuses[$ref] ?? null;
    }
}

if (!function_exists('courier_setStatus')) {
    function courier_setStatus($ref, $newStatus) {
        $statuses = courier_loadStatuses();
        $statuses[$ref] = $newStatus;
        return file_put_contents(COURIER_STATUSES_FILE, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if (!function_exists('courier_loadOrder')) {
    function courier_loadOrder($ref) {
        $dir = defined('DISPATCH_ORDERS_DIR') ? DISPATCH_ORDERS_DIR : __DIR__ . '/uploads/orders/';
        if (!is_dir($dir)) return null;
        $files = glob($dir . '*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($data['referenceCode'] ?? '') === $ref) {
                $data['_file'] = $file;
                return $data;
            }
        }
        return null;
    }
}

if (!function_exists('courier_saveOrder')) {
    function courier_saveOrder($ref, $orderData) {
        $file = $orderData['_file'] ?? null;
        if (!$file) {
            $dir = defined('DISPATCH_ORDERS_DIR') ? DISPATCH_ORDERS_DIR : __DIR__ . '/uploads/orders/';
            $files = glob($dir . '*.json');
            foreach ($files as $f) {
                $d = json_decode(file_get_contents($f), true);
                if ($d && ($d['referenceCode'] ?? '') === $ref) {
                    $file = $f;
                    break;
                }
            }
        }
        if (!$file) return false;
        unset($orderData['_file']);
        return file_put_contents($file, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

if (!function_exists('courier_getVendorAddress')) {
    function courier_getVendorAddress($vendorId) {
        $vendorsFile = __DIR__ . '/data/vendors.json';
        if (!file_exists($vendorsFile)) return '';
        $data = json_decode(file_get_contents($vendorsFile), true);
        foreach ($data['vendors'] ?? [] as $v) {
            if ($v['id'] === $vendorId) return $v['address'] ?? '';
        }
        return '';
    }
}

if (!function_exists('courier_getVendorPhone')) {
    function courier_getVendorPhone($vendorId) {
        $vendorsFile = __DIR__ . '/data/vendors.json';
        if (!file_exists($vendorsFile)) return '';
        $data = json_decode(file_get_contents($vendorsFile), true);
        foreach ($data['vendors'] ?? [] as $v) {
            if ($v['id'] === $vendorId) return $v['phone'] ?? '';
        }
        return '';
    }
}

if (!function_exists('courier_getDueInfo')) {
    function courier_getDueInfo($order) {
        $dueDate = $order['selectedDate'] ?? '';
        $dueTime = $order['deliveryDetails']['dueTime'] ?? 'anytime';
        $today = date('Y-m-d');
        $isToday = ($dueDate === $today);
        $hoursRemaining = null;
        
        if ($dueDate) {
            try {
                $dueDateTime = new DateTime($dueDate . ' 23:59:59');
                if ($dueTime !== 'anytime') {
                    $dueDateTime = new DateTime($dueDate . ' ' . $dueTime);
                }
                $now = new DateTime();
                $diff = $now->diff($dueDateTime);
                $hoursRemaining = ($diff->invert ? -1 : 1) * ($diff->h + $diff->days * 24);
            } catch (Exception $e) {}
        }
        
        return [
            'time' => $dueTime,
            'time_formatted' => $dueTime === 'anytime' ? 'Anytime' : date('g:i A', strtotime($dueTime)),
            'date_formatted' => $isToday ? 'Today' : ($dueDate ? date('M j', strtotime($dueDate)) : ''),
            'hours_remaining' => $hoursRemaining,
            'is_today' => $isToday,
        ];
    }
}

if (!function_exists('courier_calculatePayout')) {
    function courier_calculatePayout() {
        $settings = function_exists('dispatch_loadSettings') ? dispatch_loadSettings() : [];
        $pricing = $settings['pricing'] ?? [];
        $base = $pricing['base_rate'] ?? 30;
        return ['total' => $base, 'breakdown' => [['label' => 'Base rate', 'amount' => $base]]];
    }
}

if (!function_exists('formatOrderForApp')) {
    function formatOrderForApp($order, $ref = null, $statusOverride = null) {
        if (!$ref) $ref = $order['referenceCode'] ?? '';
        $status = $statusOverride ?: courier_getStatus($ref);
        $dispatch = $order['dispatch'] ?? [];
        $customer = $order['customerInfo'] ?? [];
        $dims = $order['dimensions'] ?? [];
        $event = $order['event'] ?? [];
        $pricing = $order['pricing'] ?? [];
        
        // Destination
        $dest = $order['deliveryDetails'] ?? [];
        $destLabel = $dest['destination'] ?? $order['deliveryOption'] ?? '';
        $destAddr = $dest['address'] ?? '';
        $destInstr = $dest['instructions'] ?? '';
        
        // Vendor info
        $vendorName = $order['dispatch_summary']['vendor_name'] ?? 'Unknown Vendor';
        $vendorAddr = '';
        $vendorPhone = '';
        
        // Try preflight log for vendor info
        if (function_exists('dispatch_getVendorInfo')) {
            $vi = dispatch_getVendorInfo($ref);
            if ($vi) {
                $vendorName = $vi['vendor_name'] ?? $vendorName;
                if (!empty($vi['vendor_id'])) {
                    $vendorAddr = courier_getVendorAddress($vi['vendor_id']);
                    $vendorPhone = courier_getVendorPhone($vi['vendor_id']);
                }
            }
        }
        
        $dueInfo = courier_getDueInfo($order);
        $hrs = $dueInfo['hours_remaining'];
        $urgency = 'normal';
        if ($hrs !== null && $hrs > 0) {
            if ($hrs <= 2) $urgency = 'red';
            elseif ($hrs <= 4) $urgency = 'orange';
        }
        
        $payoutInfo = courier_calculatePayout();
        
        return [
            'ref' => $ref,
            'status' => $status,
            'customer_name' => $customer['name'] ?? '',
            'customer_phone' => $customer['phone'] ?? '',
            'material' => $order['material'] ?? '',
            'size' => ($dims['width'] ?? '') . '" x ' . ($dims['height'] ?? '') . '"',
            'quantity' => $order['quantity'] ?? 1,
            'event' => $event['name'] ?? '',
            'event_acronym' => $event['acronym'] ?? '',
            'destination' => $destLabel,
            'destination_type' => '',
            'destination_address' => $destAddr,
            'destination_instructions' => $destInstr,
            'vendor_name' => $vendorName,
            'vendor_address' => $vendorAddr,
            'vendor_phone' => $vendorPhone,
            'due_date' => $order['selectedDate'] ?? '',
            'due_time_formatted' => $dueInfo['time_formatted'] ?? 'Anytime',
            'due_date_formatted' => $dueInfo['date_formatted'] ?? '',
            'hours_remaining' => $hrs,
            'urgency' => $urgency,
            'is_today' => $dueInfo['is_today'] ?? false,
            'delivery_tier' => $pricing['tier'] ?? '',
            'total' => $pricing['total'] ?? 0,
            'est_payout' => $payoutInfo['total'],
            'est_payout_breakdown' => $payoutInfo['breakdown'],
            'notes' => $order['specialInstructions'] ?? '',
            'type' => !empty($dispatch['batch_id']) ? 'batched' : 'single',
            'batch_id' => $dispatch['batch_id'] ?? null,
        ];
    }
}

if (!function_exists('logCourierActivity')) {
    function logCourierActivity($ref, $from, $to, $user, $photo = null) {
        // Minimal logging for admin-initiated batch operations
        $logFile = __DIR__ . '/dispatch/dispatch-log.json';
        $logData = ['entries' => []];
        if (file_exists($logFile)) {
            $logData = json_decode(file_get_contents($logFile), true) ?: $logData;
        }
        $logData['entries'][] = [
            'ref' => $ref,
            'from_status' => $from,
            'to_status' => $to,
            'user' => $user['name'] ?? 'admin',
            'pin' => $user['pin'] ?? 'admin',
            'timestamp' => date('c'),
            'source' => 'admin-batch-api',
            'photo' => $photo,
        ];
        file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
}

// ============================================
// Handle Action
// ============================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'suggest_batches':
        $suggestions = batch_autoDetect();
        echo json_encode([
            'success' => true,
            'suggestions' => $suggestions,
            'count' => count($suggestions)
        ]);
        break;
    
    case 'create_batch':
        $refsInput = $_POST['order_refs'] ?? '';
        if (is_string($refsInput)) {
            $decoded = json_decode($refsInput, true);
            $refs = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $refsInput)));
        } else {
            $refs = is_array($refsInput) ? $refsInput : [];
        }
        
        if (count($refs) < 2) {
            echo json_encode(['success' => false, 'error' => 'At least 2 order refs required']);
            break;
        }
        
        $adminUser = $_SESSION['admin_user'] ?? 'admin';
        $result = batch_create($refs, $adminUser, false, 'pending');
        echo json_encode($result);
        break;
    
    case 'disband_batch':
        $batchId = trim($_POST['batch_id'] ?? '');
        if (!$batchId) {
            echo json_encode(['success' => false, 'error' => 'Batch ID required']);
            break;
        }
        $adminUser = $_SESSION['admin_user'] ?? 'admin';
        $result = batch_disband($batchId, $adminUser);
        echo json_encode($result);
        break;
    
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        break;
}
