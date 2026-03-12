<?php
/**
 * Dispatch API
 * Handles courier/staff authentication and order status updates
 * Location: /dispatch/api.php
 */

// Start session
session_start();

// Include email functions for dispatch notifications
$emailFunctionsPath = __DIR__ . '/../email-status-notifications.php';
if (file_exists($emailFunctionsPath)) {
    require_once $emailFunctionsPath;
}

// CORS and headers for API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Configuration
define('COURIERS_FILE', __DIR__ . '/couriers.json');
define('ORDERS_DIR', __DIR__ . '/../uploads/orders/');
define('PHOTOS_DIR', __DIR__ . '/../uploads/delivery-photos/');
define('STATUSES_FILE', __DIR__ . '/../data/statuses.json');
define('DISPATCH_LOG_FILE', __DIR__ . '/dispatch-log.json');

// Ensure photos directory exists
if (!is_dir(PHOTOS_DIR)) {
    mkdir(PHOTOS_DIR, 0755, true);
}

/**
 * Log dispatch activity
 */
function logDispatchActivity($referenceCode, $fromStatus, $toStatus, $user, $photoPath = null) {
    $logFile = DISPATCH_LOG_FILE;
    
    // Load existing log
    $logData = ['entries' => []];
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logData = json_decode($content, true) ?: ['entries' => []];
    }
    
    // Add new entry
    $logData['entries'][] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'referenceCode' => $referenceCode,
        'fromStatus' => $fromStatus,
        'toStatus' => $toStatus,
        'userName' => $user['name'],
        'userRole' => $user['role'],
        'roleLabel' => $user['role_label'],
        'photo' => $photoPath
    ];
    
    // Keep only last 500 entries
    if (count($logData['entries']) > 500) {
        $logData['entries'] = array_slice($logData['entries'], -500);
    }
    
    // Save log
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT));
}

/**
 * Load couriers configuration
 */
function loadCouriers() {
    if (!file_exists(COURIERS_FILE)) {
        return null;
    }
    $content = file_get_contents(COURIERS_FILE);
    return json_decode($content, true);
}

/**
 * Save couriers configuration
 */
function saveCouriers($data) {
    $data['metadata']['lastUpdated'] = date('Y-m-d H:i:s');
    return file_put_contents(COURIERS_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Validate PIN and return user info
 */
function validatePin($pin) {
    $couriers = loadCouriers();
    if (!$couriers || !isset($couriers['users'][$pin])) {
        return null;
    }
    
    $user = $couriers['users'][$pin];
    if (!$user['active']) {
        return null;
    }
    
    // Get role permissions
    $role = $user['role'];
    $permissions = $couriers['roles'][$role] ?? null;
    
    return [
        'pin' => $pin,
        'name' => $user['name'],
        'role' => $role,
        'role_label' => $permissions['label'] ?? ucfirst($role),
        'allowed_statuses' => $permissions['allowed_statuses'] ?? []
    ];
}

/**
 * Check if session is valid (not expired)
 */
function isSessionValid() {
    if (!isset($_SESSION['dispatch_user']) || !isset($_SESSION['dispatch_login_time'])) {
        return false;
    }
    
    // Check if past midnight (session expires at 23:59:59)
    $loginDate = date('Y-m-d', $_SESSION['dispatch_login_time']);
    $today = date('Y-m-d');
    
    if ($loginDate !== $today) {
        // Session from previous day - expired
        unset($_SESSION['dispatch_user']);
        unset($_SESSION['dispatch_login_time']);
        return false;
    }
    
    return true;
}

/**
 * Generate MTCC tracking number for an order (matches tracking-utilities.php logic)
 */
function generateTrackingNumber($order) {
    // Get event acronym from order
    $eventPrefix = '';
    if (isset($order['event']['acronym'])) {
        $eventPrefix = $order['event']['acronym'];
    } elseif (isset($order['eventAcronym'])) {
        $eventPrefix = $order['eventAcronym'];
    }
    
    // Extract order number from reference code
    $orderNumber = '001';
    if (isset($order['referenceCode']) && preg_match('/(\d+)$/', $order['referenceCode'], $matches)) {
        $orderNumber = $matches[1];
    }
    
    // Get date from order
    $dateStr = $order['selectedDate'] ?? $order['orderDate'] ?? date('Y-m-d');
    try {
        $orderDate = new DateTime($dateStr);
    } catch (Exception $e) {
        $orderDate = new DateTime();
    }
    
    if ($eventPrefix) {
        // New format: MTCC + Event + Order + Date
        return 'MTCC' . strtoupper($eventPrefix) . str_pad($orderNumber, 3, '0', STR_PAD_LEFT) . $orderDate->format('ymd');
    } else {
        // Legacy format: MTCC + Date + Order
        return 'MTCC' . $orderDate->format('ymd') . str_pad($orderNumber, 3, '0', STR_PAD_LEFT);
    }
}

/**
 * Find order by tracking number
 */
function findOrderByTracking($trackingNumber) {
    // Clear file stat cache to ensure fresh reads
    clearstatcache();
    
    if (!is_dir(ORDERS_DIR)) {
        error_log("Dispatch API: Orders directory not found: " . ORDERS_DIR);
        return null;
    }
    
    // Tracking numbers are generated on-the-fly, not stored
    // We need to generate tracking for each order and compare
    
    $files = glob(ORDERS_DIR . '*.json');
    if (empty($files)) {
        error_log("Dispatch API: No order files found in " . ORDERS_DIR);
        return null;
    }
    
    foreach ($files as $file) {
        // Clear cache for this specific file
        clearstatcache(true, $file);
        $content = file_get_contents($file);
        $order = json_decode($content, true);
        
        if ($order) {
            // Generate tracking number for this order
            $generatedTracking = generateTrackingNumber($order);
            
            if (strtoupper($generatedTracking) === strtoupper($trackingNumber)) {
                $order['_file'] = basename($file);
                $order['_filepath'] = $file;
                $order['mtccTrackingNumber'] = $generatedTracking;
                
                // Debug log
                error_log("Dispatch API: Found order by tracking " . $trackingNumber . " with status: " . ($order['status'] ?? 'unknown'));
                
                return $order;
            }
        }
    }
    
    return null;
}

/**
 * Find order by reference code
 */
function findOrderByReference($referenceCode) {
    // Clear file stat cache to ensure fresh reads
    clearstatcache();
    
    if (!is_dir(ORDERS_DIR)) {
        error_log("Dispatch API: Orders directory not found: " . ORDERS_DIR);
        return null;
    }
    
    // Search through all order files for matching reference code
    $files = glob(ORDERS_DIR . '*.json');
    foreach ($files as $file) {
        // Skip history files
        if (strpos($file, '_history.json') !== false) continue;
        
        // Clear cache for this specific file and read fresh
        clearstatcache(true, $file);
        $content = file_get_contents($file);
        $order = json_decode($content, true);
        
        if ($order && isset($order['referenceCode'])) {
            if (strtoupper($order['referenceCode']) === strtoupper($referenceCode)) {
                $order['_file'] = basename($file);
                $order['_filepath'] = $file;
                $order['mtccTrackingNumber'] = generateTrackingNumber($order);
                
                // Debug log
                error_log("Dispatch API: Found order " . $referenceCode . " with status: " . ($order['status'] ?? 'unknown'));
                
                return $order;
            }
        }
    }
    
    error_log("Dispatch API: Order not found for reference: " . $referenceCode);
    return null;
}

/**
 * Update order status
 */
function updateOrderStatus($referenceCode, $newStatus, $user, $photoPath = null) {
    // Find the order file by searching
    if (!is_dir(ORDERS_DIR)) {
        return ['success' => false, 'error' => 'Orders directory not found'];
    }
    
    $filename = null;
    $order = null;
    $files = glob(ORDERS_DIR . '*.json');
    
    foreach ($files as $file) {
        if (strpos($file, '_history.json') !== false) continue;
        
        $content = file_get_contents($file);
        $data = json_decode($content, true);
        
        if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $referenceCode) {
            $filename = $file;
            $order = $data;
            break;
        }
    }
    
    if (!$filename || !$order) {
        return ['success' => false, 'error' => 'Order not found'];
    }
    
    $oldStatus = $order['status'] ?? 'unknown';
    
    // Update status
    $order['status'] = $newStatus;
    $order['lastUpdated'] = date('Y-m-d H:i:s');
    
    // Add to order history
    if (!isset($order['history'])) {
        $order['history'] = [];
    }
    
    $historyEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => 'status_change',
        'from' => $oldStatus,
        'to' => $newStatus,
        'by' => $user['name'],
        'role' => $user['role_label'],
        'method' => 'dispatch_scan'
    ];
    
    if ($photoPath) {
        $historyEntry['photo'] = $photoPath;
    }
    
    $order['history'][] = $historyEntry;
    
    // Add delivery photo reference if provided
    if ($photoPath) {
        if (!isset($order['deliveryPhotos'])) {
            $order['deliveryPhotos'] = [];
        }
        $order['deliveryPhotos'][] = [
            'status' => $newStatus,
            'path' => $photoPath,
            'timestamp' => date('Y-m-d H:i:s'),
            'by' => $user['name']
        ];
    }
    
    // Save order
    $saved = file_put_contents($filename, json_encode($order, JSON_PRETTY_PRINT));
    
    if ($saved === false) {
        return ['success' => false, 'error' => 'Failed to save order'];
    }
    
    // Update statuses.json
    updateStatusesFile($referenceCode, $newStatus);
    
    // Log dispatch activity
    logDispatchActivity($referenceCode, $oldStatus, $newStatus, $user, $photoPath);
    
    // Send customer email notification
    $emailSent = false;
    if (function_exists('sendDispatchNotification')) {
        $emailSent = sendDispatchNotification($order, $newStatus, $user['name']);
    }
    
    return [
        'success' => true,
        'order' => $order,
        'message' => "Status updated from {$oldStatus} to {$newStatus}",
        'emailSent' => $emailSent
    ];
}

/**
 * Update status in statuses.json
 */
function updateStatusesFile($referenceCode, $newStatus) {
    if (!file_exists(STATUSES_FILE)) {
        return;
    }
    
    $content = file_get_contents(STATUSES_FILE);
    $statuses = json_decode($content, true);
    
    if (!$statuses) {
        $statuses = [];
    }
    
    // Update the status for this reference code
    $statuses[$referenceCode] = $newStatus;
    
    file_put_contents(STATUSES_FILE, json_encode($statuses, JSON_PRETTY_PRINT));
}

/**
 * Handle photo upload
 */
function handlePhotoUpload($referenceCode, $status) {
    if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $file = $_FILES['photo'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validate file type
    $allowedTypes = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($extension, $allowedTypes)) {
        return null;
    }
    
    // Generate filename: REFCODE_STATUS_TIMESTAMP.jpg
    $timestamp = date('Ymd_His');
    $filename = "{$referenceCode}_{$status}_{$timestamp}.{$extension}";
    $filepath = PHOTOS_DIR . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return "delivery-photos/{$filename}";
    }
    
    return null;
}

/**
 * Get suggested next status based on current status and delivery type
 */
function getSuggestedStatus($order) {
    $currentStatus = $order['status'] ?? 'unknown';
    $deliveryMethod = $order['deliveryMethod'] ?? 'mtcc';
    
    $statusFlow = [
        'paid' => 'preflight',
        'preflight' => 'printing',
        'printing' => 'ready',
        'ready' => 'dispatched',
        'dispatched' => 'shipped',
        'shipped' => 'delivered',
        'delivered' => ($deliveryMethod === 'mtcc') ? 'pickedup' : null,
    ];
    
    return $statusFlow[$currentStatus] ?? null;
}

/**
 * Format order for API response
 */
function formatOrderResponse($order, $user) {
    $deliveryMethod = $order['deliveryMethod'] ?? 'mtcc';
    
    // Determine delivery location display
    $deliveryLocation = '';
    if ($deliveryMethod === 'mtcc') {
        $building = $order['event']['building'] ?? 'north';
        if ($building === 'south') {
            $deliveryLocation = "MTCC South Building\nLevel 800\n222 Bremner Boulevard";
        } else {
            $deliveryLocation = "MTCC North Building\nLevel 300\n255 Front Street West";
        }
    } else {
        // Address delivery
        $addr = $order['deliveryAddress'] ?? [];
        $deliveryLocation = implode("\n", array_filter([
            $addr['name'] ?? '',
            $addr['address'] ?? '',
            $addr['address2'] ?? '',
            trim(($addr['city'] ?? '') . ', ' . ($addr['province'] ?? '') . ' ' . ($addr['postalCode'] ?? ''))
        ]));
    }
    
    // Get suggested next status
    $suggestedStatus = getSuggestedStatus($order);
    
    // Filter allowed statuses based on user role and order state
    $allowedStatuses = $user['allowed_statuses'];
    
    // For non-admin, filter to only show relevant next statuses
    if ($user['role'] !== 'admin') {
        // MTCC staff can do pickedup/unclaimed, only if order is delivered
        if ($user['role'] === 'mtcc_staff') {
            if ($order['status'] !== 'delivered' || $deliveryMethod !== 'mtcc') {
                $allowedStatuses = [];
            } else {
                // Filter to only pickedup and unclaimed
                $allowedStatuses = array_values(array_intersect($allowedStatuses, ['pickedup', 'unclaimed']));
            }
        }
        // Courier can do shipped/delivered based on current status
        else if ($user['role'] === 'courier') {
            if ($order['status'] === 'delivered') {
                $allowedStatuses = []; // Courier is done
            } else if ($order['status'] === 'shipped') {
                $allowedStatuses = ['delivered'];
            } else if (in_array($order['status'], ['dispatched', 'ready'])) {
                $allowedStatuses = ['shipped'];
            }
        }
    }
    
    // Remove current status from allowed statuses (hide button for current state)
    $currentStatus = $order['status'];
    $allowedStatuses = array_values(array_filter($allowedStatuses, function($s) use ($currentStatus) {
        return $s !== $currentStatus;
    }));
    
    return [
        'referenceCode' => $order['referenceCode'] ?? '',
        'trackingNumber' => $order['mtccTrackingNumber'] ?? '',
        'customerName' => $order['customerName'] ?? $order['name'] ?? '',
        'customerPhone' => $order['phone'] ?? '',
        'customerEmail' => $order['email'] ?? '',
        'currentStatus' => $order['status'] ?? 'unknown',
        'deliveryMethod' => $deliveryMethod,
        'deliveryLocation' => $deliveryLocation,
        'posterSize' => ($order['width'] ?? '?') . '" x ' . ($order['height'] ?? '?') . '"',
        'material' => $order['material'] ?? 'paper',
        'eventName' => $order['event']['name'] ?? '',
        'notes' => $order['notes'] ?? $order['additionalNotes'] ?? '',
        'suggestedStatus' => $suggestedStatus,
        'allowedStatuses' => $allowedStatuses,
        'isFinalStatus' => ($deliveryMethod !== 'mtcc' && $order['status'] === 'delivered') || $order['status'] === 'pickedup',
        'deliveryPhotos' => $order['deliveryPhotos'] ?? []
    ];
}

// =============================================================================
// API ROUTING
// =============================================================================

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    
    // -------------------------------------------------------------------------
    // LOGIN - Validate PIN and create session
    // -------------------------------------------------------------------------
    case 'login':
        $pin = $_POST['pin'] ?? '';
        
        if (empty($pin)) {
            echo json_encode(['success' => false, 'error' => 'PIN required']);
            exit;
        }
        
        $user = validatePin($pin);
        
        if (!$user) {
            echo json_encode(['success' => false, 'error' => 'Invalid PIN']);
            exit;
        }
        
        // Create session
        $_SESSION['dispatch_user'] = $user;
        $_SESSION['dispatch_login_time'] = time();
        
        echo json_encode([
            'success' => true,
            'user' => [
                'name' => $user['name'],
                'role' => $user['role'],
                'role_label' => $user['role_label']
            ]
        ]);
        break;
    
    // -------------------------------------------------------------------------
    // LOGOUT - End session
    // -------------------------------------------------------------------------
    case 'logout':
        unset($_SESSION['dispatch_user']);
        unset($_SESSION['dispatch_login_time']);
        echo json_encode(['success' => true]);
        break;
    
    // -------------------------------------------------------------------------
    // CHECK SESSION - Verify if user is logged in
    // -------------------------------------------------------------------------
    case 'check_session':
        if (isSessionValid()) {
            echo json_encode([
                'success' => true,
                'loggedIn' => true,
                'user' => [
                    'name' => $_SESSION['dispatch_user']['name'],
                    'role' => $_SESSION['dispatch_user']['role'],
                    'role_label' => $_SESSION['dispatch_user']['role_label']
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'loggedIn' => false
            ]);
        }
        break;
    
    // -------------------------------------------------------------------------
    // LOOKUP - Find order by tracking number or reference code
    // -------------------------------------------------------------------------
    case 'lookup':
        if (!isSessionValid()) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        
        $tracking = $_GET['tracking'] ?? $_POST['tracking'] ?? '';
        
        if (empty($tracking)) {
            echo json_encode(['success' => false, 'error' => 'Tracking number required']);
            exit;
        }
        
        // Try tracking number first, then reference code
        $order = findOrderByTracking($tracking);
        if (!$order) {
            $order = findOrderByReference($tracking);
        }
        
        if (!$order) {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
            exit;
        }
        
        $user = $_SESSION['dispatch_user'];
        $formatted = formatOrderResponse($order, $user);
        
        echo json_encode([
            'success' => true,
            'order' => $formatted
        ]);
        break;
    
    // -------------------------------------------------------------------------
    // UPDATE STATUS - Change order status
    // -------------------------------------------------------------------------
    case 'update_status':
        if (!isSessionValid()) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        
        $referenceCode = $_POST['reference_code'] ?? '';
        $newStatus = $_POST['status'] ?? '';
        
        if (empty($referenceCode) || empty($newStatus)) {
            echo json_encode(['success' => false, 'error' => 'Reference code and status required']);
            exit;
        }
        
        $user = $_SESSION['dispatch_user'];
        
        // Verify user has permission for this status
        if (!in_array($newStatus, $user['allowed_statuses'])) {
            echo json_encode(['success' => false, 'error' => 'You do not have permission to set this status']);
            exit;
        }
        
        // Handle photo upload if provided
        $photoPath = handlePhotoUpload($referenceCode, $newStatus);
        
        // Update the order
        $result = updateOrderStatus($referenceCode, $newStatus, $user, $photoPath);
        
        echo json_encode($result);
        break;
    
    // -------------------------------------------------------------------------
    // GET STATUSES - Get list of valid statuses for current user
    // -------------------------------------------------------------------------
    case 'get_statuses':
        if (!isSessionValid()) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            exit;
        }
        
        $user = $_SESSION['dispatch_user'];
        
        $statusLabels = [
            'unpaid' => 'Unpaid',
            'paid' => 'Paid',
            'preflight' => 'Preflight',
            'file_issue' => 'File Issue',
            'printing' => 'Printing',
            'ready' => 'Ready',
            'dispatched' => 'Dispatched',
            'shipped' => 'Shipped',
            'delivered' => 'Delivered',
            'pickedup' => 'Picked Up',
            'unclaimed' => 'Unclaimed',
            'missing' => 'Missing',
            'cancelled' => 'Cancelled',
            'refunded' => 'Refunded'
        ];
        
        $allowedStatuses = [];
        foreach ($user['allowed_statuses'] as $status) {
            $allowedStatuses[] = [
                'value' => $status,
                'label' => $statusLabels[$status] ?? ucfirst($status)
            ];
        }
        
        echo json_encode([
            'success' => true,
            'statuses' => $allowedStatuses
        ]);
        break;
    
    // -------------------------------------------------------------------------
    // DEBUG - Check paths and list orders (remove in production)
    // -------------------------------------------------------------------------
    case 'debug':
        $debug = [
            'orders_dir' => ORDERS_DIR,
            'orders_dir_exists' => is_dir(ORDERS_DIR),
            'photos_dir' => PHOTOS_DIR,
            'photos_dir_exists' => is_dir(PHOTOS_DIR),
            'couriers_file' => COURIERS_FILE,
            'couriers_file_exists' => file_exists(COURIERS_FILE),
            'current_dir' => __DIR__,
            'orders' => []
        ];
        
        if (is_dir(ORDERS_DIR)) {
            $files = glob(ORDERS_DIR . '*.json');
            foreach (array_slice($files, 0, 10) as $file) { // Limit to 10
                $content = file_get_contents($file);
                $order = json_decode($content, true);
                if ($order) {
                    $debug['orders'][] = [
                        'file' => basename($file),
                        'referenceCode' => $order['referenceCode'] ?? 'N/A',
                        'tracking' => generateTrackingNumber($order),
                        'status' => $order['status'] ?? 'N/A'
                    ];
                }
            }
        }
        
        echo json_encode($debug, JSON_PRETTY_PRINT);
        break;
    
    // -------------------------------------------------------------------------
    // DEFAULT - Invalid action
    // -------------------------------------------------------------------------
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
