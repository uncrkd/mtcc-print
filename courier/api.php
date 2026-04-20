<?php
/**
 * Courier App API
 * Handles all courier/staff mobile app requests
 * Location: /courier/api.php
 * 
 * Endpoints (via POST 'action' parameter):
 *   Auth:     login, logout, session_check
 *   Orders:   get_available, get_my_deliveries, get_pickup_queue, get_activity
 *   Dispatch: accept_delivery, update_status, scan_order
 *   Earnings: get_earnings
 */
session_start();

header('Content-Type: application/json');

// Include shared dispatch functions (provides all DISPATCH_* constants and helpers)
$dispatchFunctions = __DIR__ . '/../dispatch/dispatch-functions.php';
if (!file_exists($dispatchFunctions)) {
    echo json_encode(['success' => false, 'error' => 'System configuration error']);
    exit;
}
require_once $dispatchFunctions;
require_once __DIR__ . '/../includes/data-access.php';

// Include dispatch email functions
$emailFunctions = __DIR__ . '/../dispatch-email-functions.php';
if (file_exists($emailFunctions)) {
    require_once $emailFunctions;
}

// Include Google Maps Routes API
require_once __DIR__ . '/routes-api.php';

// Include Weather API
require_once __DIR__ . '/weather-api.php';

// Statuses file (same location as admin uses)
if (!defined('COURIER_STATUSES_FILE')) {
    define('COURIER_STATUSES_FILE', __DIR__ . '/../data/statuses.json');
}

// Photos directory
define('COURIER_PHOTOS_DIR', __DIR__ . '/../uploads/delivery-photos/');
if (!is_dir(COURIER_PHOTOS_DIR)) {
    mkdir(COURIER_PHOTOS_DIR, 0755, true);
}

// Get action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!$action) {
    echo json_encode(['success' => false, 'error' => 'No action specified']);
    exit;
}

// Route request
switch ($action) {
    // Auth
    case 'login':          handleLogin(); break;
    case 'logout':         handleLogout(); break;
    case 'session_check':  handleSessionCheck(); break;
    // Order Queries
    case 'get_available':      requireAuth(); handleGetAvailable(); break;
    case 'get_my_deliveries':  requireAuth(); handleGetMyDeliveries(); break;
    case 'get_pickup_queue':   requireAuth(); handleGetPickupQueue(); break;
    case 'get_activity':       requireAuth(); handleGetActivity(); break;
    case 'get_upcoming':       requireAuth(); handleGetUpcoming(); break;
    case 'get_mtcc_dashboard': requireAuth(); handleGetMTCCDashboard(); break;
    case 'get_completed':      requireAuth(); handleGetCompleted(); break;
    case 'get_upcoming_mtcc':  requireAuth(); handleGetUpcomingMTCC(); break;
    // Dispatch Actions
    case 'accept_delivery':    requireAuth(); handleAcceptDelivery(); break;
    case 'update_status':      requireAuth(); handleUpdateStatus(); break;
    case 'scan_order':         requireAuth(); handleScanOrder(); break;
    // Earnings
    case 'get_earnings':       requireAuth(); handleGetEarnings(); break;
    // Routes & Maps
    case 'get_route_info':     requireAuth(); handleGetRouteInfo(); break;
    case 'optimize_route':     requireAuth(); handleOptimizeRoute(); break;
    case 'get_nearby_orders':  requireAuth(); handleGetNearbyOrders(); break;
    case 'geocode_vendors':    requireAuth(); handleGeocodeVendors(); break;
    // Live Tracking
    case 'update_location':    requireAuth(); handleUpdateLocation(); break;
    case 'get_tracking':       handleGetTracking(); break; // public — no auth needed
    // Weather
    case 'get_home_data':      requireAuth(); handleGetHomeData(); break;
    case 'search_orders':      requireAuth(); handleSearchOrders(); break;
    case 'get_weather':        requireAuth(); handleGetWeather(); break;
    case 'get_notifications':  requireAuth(); handleGetNotifications(); break;
    // Batch Orders
    case 'accept_batch':       requireAuth(); handleAcceptBatch(); break;
    case 'update_batch_stop':  requireAuth(); handleUpdateBatchStop(); break;
    case 'get_batch_route':    requireAuth(); handleGetBatchRoute(); break;
    case 'suggest_batches':    requireAuth(); handleSuggestBatches(); break;
    case 'create_batch':       requireAuth(); handleCreateBatch(); break;
    case 'disband_batch':      requireAuth(); handleDisbandBatch(); break;
    case 'release_delivery':   requireAuth(); handleReleaseDelivery(); break;
    case 'release_batch':      requireAuth(); handleReleaseBatch(); break;
    case 'set_availability':   requireAuth(); handleSetAvailability(); break;
    case 'report_issue':       requireAuth(); handleReportIssue(); break;
    case 'send_quick_message': requireAuth(); handleSendQuickMessage(); break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        break;
}

// ============================================
// Status Helpers (statuses.json is the source of truth)
// ============================================

function courier_loadStatuses() {
    $file = COURIER_STATUSES_FILE;
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

function courier_saveStatuses($statuses) {
    return file_put_contents(COURIER_STATUSES_FILE, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function courier_getStatus($ref) {
    $statuses = courier_loadStatuses();
    return $statuses[$ref] ?? '';
}

function courier_setStatus($ref, $newStatus) {
    $statuses = courier_loadStatuses();
    $statuses[$ref] = $newStatus;
    return courier_saveStatuses($statuses);
}

// ============================================
// Order File Helpers (find/update by scanning directory)
// ============================================

/**
 * Find the actual file path for an order by reference code.
 * Orders are NOT always named {ref}.json — we must scan.
 */
function courier_findOrderFile($ref) {
    $dir = DISPATCH_ORDERS_DIR;
    
    // Try direct filename first
    $direct = $dir . $ref . '.json';
    if (file_exists($direct)) {
        return $direct;
    }
    
    // Scan directory
    if (!is_dir($dir)) return null;
    $files = glob($dir . '*.json');
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $ref) {
            return $file;
        }
    }
    return null;
}

/**
 * Load order data using dispatch-functions.php's proven loader
 */
function courier_loadOrder($ref) {
    return dispatch_loadOrder($ref);
}

/**
 * Save order data back to its file (finds file by ref, preserves filename)
 */
function courier_saveOrder($ref, $orderData) {
    $file = courier_findOrderFile($ref);
    if (!$file) return false;
    return file_put_contents($file, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ============================================
// Auth Functions
// ============================================

function requireAuth() {
    if (!isSessionValid()) {
        echo json_encode(['success' => false, 'error' => 'Not authenticated', 'auth_required' => true]);
        exit;
    }
}

function isSessionValid() {
    if (!isset($_SESSION['courier_user']) || !isset($_SESSION['courier_login_time'])) {
        return false;
    }
    $loginDate = date('Y-m-d', $_SESSION['courier_login_time']);
    if ($loginDate !== date('Y-m-d')) {
        unset($_SESSION['courier_user']);
        unset($_SESSION['courier_login_time']);
        return false;
    }
    return true;
}

function getCurrentUser() {
    return $_SESSION['courier_user'] ?? null;
}

function handleLogin() {
    $pin = trim($_POST['pin'] ?? '');
    if (!$pin) {
        echo json_encode(['success' => false, 'error' => 'PIN required']);
        return;
    }
    
    // Load couriers data using dispatch-functions constant
    $couriersFile = DISPATCH_COURIERS_FILE;
    if (!file_exists($couriersFile)) {
        echo json_encode(['success' => false, 'error' => 'System error: couriers file not found']);
        return;
    }
    
    $data = json_decode(file_get_contents($couriersFile), true);
    if (!$data || !isset($data['users'][$pin])) {
        echo json_encode(['success' => false, 'error' => 'Invalid PIN']);
        return;
    }
    
    $user = $data['users'][$pin];
    if (!$user['active']) {
        echo json_encode(['success' => false, 'error' => 'Account deactivated']);
        return;
    }
    
    $role = $user['role'];
    $permissions = $data['roles'][$role] ?? [];
    $tabs = getTabsForRole($role);
    
    $_SESSION['courier_user'] = [
        'pin' => $pin,
        'name' => $user['name'],
        'role' => $role,
        'role_label' => $permissions['label'] ?? ucfirst($role),
        'allowed_statuses' => $permissions['allowed_statuses'] ?? [],
        'tabs' => $tabs,
    ];
    $_SESSION['courier_login_time'] = time();
    
    // Update last_seen
    $data['users'][$pin]['last_seen'] = date('Y-m-d H:i:s');
    $data['users'][$pin]['availability'] = 'online';
    file_put_contents($couriersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    
    echo json_encode(['success' => true, 'user' => $_SESSION['courier_user']]);
}

function getTabsForRole($role) {
    switch ($role) {
        case 'courier':
            return [
                ['id' => 'home', 'label' => 'Home', 'icon' => 'home'],
                ['id' => 'deliveries', 'label' => 'Deliveries', 'icon' => 'deliveries'],
                ['id' => 'scan', 'label' => 'Scan', 'icon' => 'scan'],
                ['id' => 'available', 'label' => 'Available', 'icon' => 'available'],
                ['id' => 'account', 'label' => 'Account', 'icon' => 'account'],
            ];
        case 'mtcc_staff':
            return [
                ['id' => 'mtcc_dashboard', 'label' => 'Dashboard', 'icon' => 'dashboard'],
                ['id' => 'pickup', 'label' => 'Pickup', 'icon' => 'pickup'],
                ['id' => 'scan', 'label' => 'Scan', 'icon' => 'scan'],
                ['id' => 'upcoming_mtcc', 'label' => 'Upcoming', 'icon' => 'upcoming'],
                ['id' => 'complete', 'label' => 'Complete', 'icon' => 'complete'],
            ];
        case 'admin':
            return [
                ['id' => 'pickup', 'label' => 'Pickup', 'icon' => 'pickup'],
                ['id' => 'scan', 'label' => 'Scan', 'icon' => 'scan'],
                ['id' => 'activity', 'label' => 'Activity', 'icon' => 'activity'],
            ];
        default:
            return [['id' => 'scan', 'label' => 'Scan', 'icon' => 'scan']];
    }
}

function handleLogout() {
    $user = getCurrentUser();
    if ($user) {
        $couriersFile = DISPATCH_COURIERS_FILE;
        if (file_exists($couriersFile)) {
            $data = json_decode(file_get_contents($couriersFile), true);
            if ($data && isset($data['users'][$user['pin']])) {
                $data['users'][$user['pin']]['availability'] = 'offline';
                file_put_contents($couriersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
    }
    unset($_SESSION['courier_user']);
    unset($_SESSION['courier_login_time']);
    echo json_encode(['success' => true]);
}

function handleSetAvailability() {
    $user = getCurrentUser();
    $status = trim($_POST['status'] ?? '');
    if (!in_array($status, ['online', 'offline'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid status']);
        return;
    }
    $couriersFile = DISPATCH_COURIERS_FILE;
    if (file_exists($couriersFile)) {
        $data = json_decode(file_get_contents($couriersFile), true);
        if ($data && isset($data['users'][$user['pin']])) {
            $data['users'][$user['pin']]['availability'] = $status;
            $data['users'][$user['pin']]['last_seen'] = date('Y-m-d H:i:s');
            file_put_contents($couriersFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }
    echo json_encode(['success' => true, 'status' => $status]);
}

function handleSessionCheck() {
    if (isSessionValid()) {
        echo json_encode(['success' => true, 'authenticated' => true, 'user' => getCurrentUser()]);
    } else {
        echo json_encode(['success' => true, 'authenticated' => false]);
    }
}

// ============================================
// Order Query Handlers
// ============================================

function handleGetHomeData() {
    $user = getCurrentUser();
    $pin = $user['pin'];
    $today = date('Y-m-d');
    $statuses = courier_loadStatuses();

    // Active deliveries for this courier (dispatched or shipped)
    $activeOrders = [];
    $issueOrders = [];
    $urgentAvailable = [];
    $completedToday = 0;
    $earnedToday = 0;
    $onTimeCount = 0;
    $totalDelivered = 0;

    // Scan courier's assigned orders
    foreach ($statuses as $ref => $status) {
        if (empty($ref)) continue;
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        $dispatch = $order['dispatch'] ?? [];
        $courierPin = $dispatch['courier_pin'] ?? $dispatch['courier_id'] ?? '';

        // Active orders for this courier
        if ($courierPin === $pin && in_array($status, ['dispatched', 'shipped'])) {
            $formatted = formatOrderForApp($order, $ref, $status);
            $formatted['type'] = 'single';
            $activeOrders[] = $formatted;

            // Check for open issues
            if (orderHasOpenIssue($ref)) {
                $issueOrders[] = ['ref' => $ref, 'destination' => $formatted['destination'] ?? ''];
            }
        }

        // Completed today count
        if ($courierPin === $pin && in_array($status, ['delivered', 'pickedup'])) {
            $ts = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? '';
            if ($ts && substr($ts, 0, 10) === $today) {
                $completedToday++;
                $totalDelivered++;
            }
        }
    }

    // Sort active: in-transit (shipped) first, then by urgency
    usort($activeOrders, function($a, $b) {
        $aT = ($a['status'] === 'shipped') ? 0 : 1;
        $bT = ($b['status'] === 'shipped') ? 0 : 1;
        if ($aT !== $bT) return $aT - $bT;
        return ($a['hours_remaining'] ?? 9999) - ($b['hours_remaining'] ?? 9999);
    });

    // Count available orders + find urgent ones
    $availableCount = 0;
    $readyNowCount = 0;
    $queueItems = dispatch_getReadyQueue();
    foreach ($queueItems as $item) {
        $ref = $item['ref'];
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        $dispatch = $order['dispatch'] ?? [];
        if (!empty($dispatch['courier_id']) || !empty($dispatch['courier_pin'])) continue;
        if (!empty($dispatch['batch_id'])) continue;

        $availableCount++;
        $formatted = formatOrderForApp($order, $ref);
        if (($formatted['status'] ?? '') === 'ready') $readyNowCount++;

        // Urgent: <=4 hours remaining
        $hr = $formatted['hours_remaining'] ?? null;
        if ($hr !== null && $hr <= 4) {
            $urgentAvailable[] = [
                'ref' => $ref,
                'hours_remaining' => round($hr, 1),
                'urgency' => $hr <= 2 ? 'red' : 'orange',
                'destination' => $formatted['destination'] ?? '',
                'due_date_formatted' => $formatted['due_date_formatted'] ?? '',
                'due_time_formatted' => $formatted['due_time_formatted'] ?? '',
            ];
        }
    }

    // Today's earnings
    $earningsFile = DISPATCH_EARNINGS_FILE;
    if (file_exists($earningsFile)) {
        $eData = json_decode(file_get_contents($earningsFile), true) ?: [];
        foreach ($eData['earnings'][$pin] ?? [] as $entry) {
            $d = substr($entry['date'] ?? '', 0, 10);
            if ($d === $today) {
                $earnedToday += floatval($entry['amount'] ?? 0);
            }
        }
    }

    // On-time rate (from performance calculator)
    $perf = buildCourierPerformance($pin, $eData['earnings'][$pin] ?? []);

    // Weather
    $weather = null;
    try {
        if (class_exists('WeatherAPI')) {
            $weatherApi = new WeatherAPI();
            $wData = $weatherApi->getWeather();
            if ($wData) {
                $badWeather = $weatherApi->checkBadWeather($wData);
                $weather = [
                    'temp' => $wData['current']['temperature'] ?? $wData['temp'] ?? null,
                    'description' => $wData['current']['description'] ?? $wData['description'] ?? '',
                    'icon' => $wData['current']['icon'] ?? $wData['icon'] ?? '',
                    'wind_kmh' => $wData['current']['wind_speed'] ?? $wData['wind_kmh'] ?? null,
                    'bad_weather_active' => $badWeather['is_bad'] ?? false,
                ];
            }
        }
    } catch (Exception $e) { /* weather optional */ }

    // Latest dispatch notification for this courier
    $latestNotif = null;
    if (function_exists('dispatch_getNotifications')) {
        $notifs = dispatch_getNotifications();
        foreach ($notifs as $n) {
            $ctx = $n['context'] ?? [];
            $nPin = $ctx['courier_pin'] ?? null;
            if ($nPin && $nPin !== $pin) continue;
            $latestNotif = [
                'title' => $n['title'] ?? '',
                'message' => $n['message'] ?? '',
                'created_at' => $n['created_at'] ?? '',
            ];
            break; // Most recent only
        }
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'completed_today' => $completedToday,
            'earned_today' => round($earnedToday, 2),
            'on_time_rate' => $perf['on_time_rate'],
            'total_completed' => $perf['total_completed'],
        ],
        'active_orders' => array_slice($activeOrders, 0, 3), // Top 3 for preview
        'active_count' => count($activeOrders),
        'available_count' => $availableCount,
        'ready_now_count' => $readyNowCount,
        'urgent_orders' => array_slice($urgentAvailable, 0, 5),
        'issue_orders' => $issueOrders,
        'weather' => $weather,
        'latest_notification' => $latestNotif,
    ]);
}

function handleGetAvailable() {
    $queueItems = dispatch_getReadyQueue();
    $available = [];
    
    // Track which orders are in batches (so we don't double-show them)
    $batchedRefs = [];
    
    // Include pending/suggested batches as single items
    $pendingBatches = batch_getPending();
    foreach ($pendingBatches as $batch) {
        $available[] = batch_formatForApp($batch);
        $refs = $batch['order_refs'] ?? [];
        if (empty($refs) && !empty($batch['orders'])) {
            foreach ($batch['orders'] as $o) {
                $refs[] = is_array($o) ? ($o['ref'] ?? '') : $o;
            }
        }
        foreach ($refs as $r) {
            $batchedRefs[$r] = true;
        }
    }
    
    // Add individual (non-batched) orders
    foreach ($queueItems as $item) {
        $ref = $item['ref'];
        if (isset($batchedRefs[$ref])) continue;
        
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        
        // Skip if already in a batch or assigned
        $dispatch = $order['dispatch'] ?? [];
        if (!empty($dispatch['batch_id'])) continue;
        if (!empty($dispatch['courier_id']) || !empty($dispatch['courier_pin'])) continue;
        
        $formatted = formatOrderForApp($order, $ref);
        $formatted['type'] = 'single';
        $available[] = $formatted;
    }
    
    echo json_encode(['success' => true, 'orders' => $available, 'count' => count($available)]);
}

function handleGetMyDeliveries() {
    $user = getCurrentUser();
    $pin = $user['pin'];
    
    $statuses = courier_loadStatuses();
    $active = [];
    $completedToday = [];
    $today = date('Y-m-d');
    
    // Track which orders are in batches
    $batchedRefs = [];
    
    // Include courier's batches
    $courierBatches = batch_getForCourier($pin);
    foreach ($courierBatches as $batch) {
        $formatted = batch_formatForApp($batch);
        
        // Track all refs in this batch
        $refs = $batch['order_refs'] ?? [];
        if (empty($refs) && !empty($batch['orders'])) {
            foreach ($batch['orders'] as $o) {
                $refs[] = is_array($o) ? ($o['ref'] ?? '') : $o;
            }
        }
        foreach ($refs as $r) {
            $batchedRefs[$r] = true;
        }
        
        if (in_array($batch['status'], ['accepted', 'dispatched', 'in_progress'])) {
            $active[] = $formatted;
        } elseif ($batch['status'] === 'completed') {
            $ts = $batch['completed_at'] ?? '';
            if ($ts && substr($ts, 0, 10) === $today) {
                $completedToday[] = $formatted;
            }
        }
    }
    
    // Add individual (non-batched) orders
    foreach ($statuses as $ref => $status) {
        if (empty($ref)) continue;
        if (isset($batchedRefs[$ref])) continue;
        if (!in_array($status, ['dispatched', 'shipped', 'delivered', 'pickedup'])) continue;
        
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        
        $dispatch = $order['dispatch'] ?? [];
        $courierPin = $dispatch['courier_pin'] ?? $dispatch['courier_id'] ?? '';
        if ($courierPin !== $pin) continue;
        
        $formatted = formatOrderForApp($order, $ref, $status);
        $formatted['type'] = 'single';
        
        if (in_array($status, ['dispatched', 'shipped'])) {
            $active[] = $formatted;
        } elseif (in_array($status, ['delivered', 'pickedup'])) {
            $ts = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? $dispatch['shipped_at'] ?? '';
            if ($ts && substr($ts, 0, 10) === $today) {
                $completedToday[] = $formatted;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'active' => $active,
        'completed_today' => $completedToday,
        'active_count' => count($active),
        'completed_count' => count($completedToday),
    ]);
}

function handleGetPickupQueue() {
    $statuses = courier_loadStatuses();
    $queue = [];
    
    foreach ($statuses as $ref => $status) {
        if (empty($ref) || $status !== 'delivered') continue;
        
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        
        // Only show orders delivered to MTCC
        $deliveryOption = $order['deliveryOption'] ?? '';
        $dest = dispatch_getDestination($order);
        $destLabel = strtolower($dest['label'] ?? '');
        $destType = $dest['type'] ?? '';
        
        if ($destType !== 'mtcc' && strpos($destLabel, 'mtcc') === false && $deliveryOption !== 'mtcc') {
            continue;
        }
        
        $queue[] = formatOrderForApp($order, $ref, $status);
    }
    
    // Sort by delivered time (newest first)
    usort($queue, function($a, $b) {
        return strcmp($b['delivered_at'] ?? '', $a['delivered_at'] ?? '');
    });
    
    echo json_encode(['success' => true, 'orders' => $queue, 'count' => count($queue)]);
}

function handleGetActivity() {
    $logFile = __DIR__ . '/../dispatch/dispatch-log.json';
    if (!file_exists($logFile)) {
        $logFile = __DIR__ . '/../data/dispatch-log.json';
    }
    
    $entries = [];
    if (file_exists($logFile)) {
        $data = json_decode(file_get_contents($logFile), true);
        $entries = $data['entries'] ?? [];
    }
    
    $today = date('Y-m-d');
    $todayEntries = array_filter($entries, function($e) use ($today) {
        return substr($e['timestamp'] ?? '', 0, 10) === $today;
    });
    usort($todayEntries, function($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });
    
    echo json_encode(['success' => true, 'entries' => array_slice(array_values($todayEntries), 0, 50)]);
}


// ============================================
// MTCC Staff Dashboard
// ============================================

function handleSearchOrders() {
    $query = strtolower(trim($_POST['query'] ?? ''));
    if (strlen($query) < 2) {
        echo json_encode(['success' => true, 'results' => []]);
        return;
    }

    $statuses = courier_loadStatuses();
    $results = [];

    foreach ($statuses as $ref => $status) {
        if (empty($ref)) continue;
        // Skip cancelled/refunded
        if (in_array($status, ['cancelled', 'refunded'])) continue;

        $order = courier_loadOrder($ref);
        if (!$order) continue;

        $customerName = strtolower($order['customerInfo']['name'] ?? $order['name'] ?? '');
        $customerEmail = strtolower($order['customerInfo']['email'] ?? $order['email'] ?? '');
        $tracking = strtolower($order['dispatch']['tracking'] ?? '');
        $refLower = strtolower($ref);

        // Match against ref, customer name, email, tracking
        if (strpos($refLower, $query) !== false ||
            strpos($customerName, $query) !== false ||
            strpos($customerEmail, $query) !== false ||
            strpos($tracking, $query) !== false) {

            $formatted = formatOrderForApp($order, $ref, $status);
            $results[] = [
                'ref' => $ref,
                'status' => $status,
                'customer_name' => $order['customerInfo']['name'] ?? $order['name'] ?? '',
                'event' => $order['event']['acronym'] ?? $order['event_select']['acronym'] ?? '',
                'event_name' => $order['event']['name'] ?? $order['event_select']['name'] ?? '',
                'building' => $formatted['building'] ?? '',
                'due_date_formatted' => $formatted['due_date_formatted'] ?? '',
                'due_time_formatted' => $formatted['due_time_formatted'] ?? '',
            ];

            // Cap at 20 results
            if (count($results) >= 20) break;
        }
    }

    echo json_encode(['success' => true, 'results' => $results, 'query' => $query]);
}

function handleGetMTCCDashboard() {
    $statuses = courier_loadStatuses();
    $today = date('Y-m-d');

    // Load active events to filter
    $activeEvents = loadActiveEvents();
    // Event filtering handled client-side

    $waitingForPickup = 0;
    $pickedUpToday = 0;
    $expectedToday = 0;
    $openIssues = 0;
    $issueOrdersList = [];
    $inProduction = 0;
    $inTransit = 0;
    $upcomingDeliveries = [];
    // Per-event totals: track total orders, waiting, picked up
    $eventTotals = [];

    foreach ($statuses as $ref => $status) {
        if (empty($ref)) continue;
        // Skip cancelled/refunded
        if (in_array($status, ['cancelled', 'refunded'])) continue;

        $eventPrefix = explode('-', $ref)[0] ?? '';
        // Initialize event bucket
        if ($eventPrefix && !isset($eventTotals[$eventPrefix])) {
            $eventTotals[$eventPrefix] = ['acronym' => $eventPrefix, 'total' => 0, 'waiting' => 0, 'picked_up' => 0];
        }
        if ($eventPrefix) {
            $eventTotals[$eventPrefix]['total']++;
        }

        $order = courier_loadOrder($ref);
        if (!$order) continue;

        if ($status === 'delivered') {
            $waitingForPickup++;
            if ($eventPrefix) $eventTotals[$eventPrefix]['waiting']++;
        } elseif ($status === 'pickedup') {
            $pickupTime = $order['dispatch']['picked_up_at'] ?? '';
            if ($pickupTime && substr($pickupTime, 0, 10) === $today) {
                $pickedUpToday++;
            }
            if ($eventPrefix) $eventTotals[$eventPrefix]['picked_up']++;
        } elseif (in_array($status, ['dispatched', 'shipped'])) {
            $inTransit++;
            $dueDate = $order['selectedDate'] ?? '';
            if ($dueDate === $today) $expectedToday++;
            $formatted = formatOrderForApp($order, $ref, $status);
            $upcomingDeliveries[] = $formatted;
        } elseif ($status === 'ready') {
            $dueDate = $order['selectedDate'] ?? '';
            if ($dueDate === $today) $expectedToday++;
            $formatted = formatOrderForApp($order, $ref, $status);
            $upcomingDeliveries[] = $formatted;
        } elseif (in_array($status, ['preflight', 'printing'])) {
            $inProduction++;
        } elseif (in_array($status, ['missing', 'file_issue', 'unclaimed'])) {
            $openIssues++;
            // Return FULL formatted order so detail panel has everything it needs
            $issueOrdersList[] = formatOrderForApp($order, $ref, $status);
        }
    }

    // Sort upcoming by hours_remaining
    usort($upcomingDeliveries, function($a, $b) {
        $aH = $a['hours_remaining'] ?? 9999;
        $bH = $b['hours_remaining'] ?? 9999;
        return $aH <=> $bH;
    });

    // Recent pickups (last 10 today) — now with pickedup_by
    $recentPickups = [];
    foreach ($statuses as $ref => $status) {
        if ($status !== 'pickedup') continue;
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        $pickupTime = $order['dispatch']['picked_up_at'] ?? '';
        if ($pickupTime && substr($pickupTime, 0, 10) === $today) {
            $recentPickups[] = [
                'ref' => $ref,
                'customer_name' => $order['customerInfo']['name'] ?? $order['name'] ?? '',
                'event' => $order['event']['name'] ?? $order['event_select']['name'] ?? '',
                'event_acronym' => $order['event']['acronym'] ?? $order['event_select']['acronym'] ?? '',
                'picked_up_at' => $pickupTime,
                'pickedup_by' => $order['dispatch']['pickedup_by'] ?? '',
            ];
        }
    }
    usort($recentPickups, function($a, $b) {
        return strcmp($b['picked_up_at'], $a['picked_up_at']);
    });

    // Active and upcoming events ONLY (excludes past events)
    $activeAndUpcoming = loadActiveAndUpcomingEvents();
    $today = date('Y-m-d');

    // Current event = first event whose dates contain today, otherwise first upcoming
    $currentEvent = null;
    foreach ($activeAndUpcoming as $ev) {
        $start = $ev['startDate'] ?? null;
        $end = $ev['endDate'] ?? null;
        if ($start && $end && $start <= $today && $end >= $today) {
            $currentEvent = ['acronym' => $ev['acronym'], 'name' => $ev['name'] ?? $ev['acronym']];
            break;
        }
    }
    // If no event happening today, use the next upcoming
    if (!$currentEvent && !empty($activeAndUpcoming)) {
        $currentEvent = ['acronym' => $activeAndUpcoming[0]['acronym'], 'name' => $activeAndUpcoming[0]['name'] ?? $activeAndUpcoming[0]['acronym']];
    }

    // Build per-event progress data (active and upcoming only)
    $eventProgress = [];
    foreach ($activeAndUpcoming as $ev) {
        $acr = $ev['acronym'] ?? '';
        if (!$acr) continue;
        $totals = $eventTotals[$acr] ?? ['total' => 0, 'waiting' => 0, 'picked_up' => 0];
        $isHappening = false;
        $start = $ev['startDate'] ?? null;
        $end = $ev['endDate'] ?? null;
        if ($start && $end && $start <= $today && $end >= $today) {
            $isHappening = true;
        }
        $eventProgress[] = [
            'acronym' => $acr,
            'name' => $ev['name'] ?? $acr,
            'dates' => $ev['dates'] ?? '',
            'startDate' => $start,
            'endDate' => $end,
            'is_happening' => $isHappening,
            'total' => $totals['total'],
            'waiting' => $totals['waiting'],
            'picked_up' => $totals['picked_up'],
        ];
    }

    echo json_encode([
        'success' => true,
        'stats' => [
            'waiting_for_pickup' => $waitingForPickup,
            'picked_up_today' => $pickedUpToday,
            'expected_today' => $expectedToday,
            'open_issues' => $openIssues,
            'in_production' => $inProduction,
            'in_transit' => $inTransit,
        ],
        'upcoming_deliveries' => array_slice($upcomingDeliveries, 0, 8),
        'recent_pickups' => array_slice($recentPickups, 0, 10),
        'event_progress' => $eventProgress,
        'current_event' => $currentEvent,
        'active_events' => $activeEvents,
        'issue_orders' => $issueOrdersList,
    ]);
}

function handleGetCompleted() {
    $statuses = courier_loadStatuses();
    $completed = [];
    // Track totals per event for past events list
    $eventTotals = [];

    foreach ($statuses as $ref => $status) {
        if ($status !== 'pickedup') continue;

        $eventPrefix = explode('-', $ref)[0] ?? '';
        $order = courier_loadOrder($ref);
        if (!$order) continue;

        $completed[] = formatOrderForApp($order, $ref, $status);

        // Tally per event
        if ($eventPrefix) {
            if (!isset($eventTotals[$eventPrefix])) {
                $eventTotals[$eventPrefix] = ['acronym' => $eventPrefix, 'picked_up' => 0];
            }
            $eventTotals[$eventPrefix]['picked_up']++;
        }
    }

    // Sort by pickup time (newest first)
    usort($completed, function($a, $b) {
        $aT = $a['picked_up_at'] ?? $a['delivered_at'] ?? '';
        $bT = $b['picked_up_at'] ?? $b['delivered_at'] ?? '';
        return strcmp($bT, $aT);
    });

    // Build past events archive list
    $pastEvents = loadPastEvents();
    $pastEventsList = [];
    foreach ($pastEvents as $ev) {
        $acr = $ev['acronym'] ?? '';
        if (!$acr) continue;
        $pickedUp = $eventTotals[$acr]['picked_up'] ?? 0;
        // Only include events that had any pickups
        if ($pickedUp > 0) {
            $pastEventsList[] = [
                'acronym' => $acr,
                'name' => $ev['name'] ?? $acr,
                'dates' => $ev['dates'] ?? '',
                'endDate' => $ev['endDate'] ?? '',
                'picked_up' => $pickedUp,
                'total_orders' => $ev['orderCount'] ?? 0,
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'orders' => $completed,
        'count' => count($completed),
        'past_events' => $pastEventsList,
    ]);
}

function handleGetUpcomingMTCC() {
    $statuses = courier_loadStatuses();
    $activeEvents = loadActiveEvents();
    // Event filtering handled client-side
    $orders = [];

    $pipelineStatuses = ['preflight', 'printing', 'ready', 'dispatched', 'shipped'];

    foreach ($statuses as $ref => $status) {
        if (empty($ref) || !in_array($status, $pipelineStatuses)) continue;

        $eventPrefix = explode('-', $ref)[0] ?? '';
        // Event filtering handled client-side via filter pills

        $order = courier_loadOrder($ref);
        if (!$order) continue;

        $orders[] = formatOrderForApp($order, $ref, $status);
    }

    // Sort: in transit first (dispatched/shipped), then ready, then production
    $statusPriority = ['shipped' => 1, 'dispatched' => 2, 'ready' => 3, 'printing' => 4, 'preflight' => 5];
    usort($orders, function($a, $b) use ($statusPriority) {
        $aP = $statusPriority[$a['status']] ?? 9;
        $bP = $statusPriority[$b['status']] ?? 9;
        if ($aP !== $bP) return $aP - $bP;
        $aH = $a['hours_remaining'] ?? 9999;
        $bH = $b['hours_remaining'] ?? 9999;
        return $aH <=> $bH;
    });

    echo json_encode(['success' => true, 'orders' => $orders, 'count' => count($orders)]);
}

/**
 * Load active events from admin/events.json
 */
/**
 * Load active events from admin/events.json.
 * Returns both active events AND any event acronyms found in current orders
 * so the filter pills show all relevant events.
 */
function loadActiveEvents() {
    $eventsFile = __DIR__ . '/../admin/events.json';
    $events = [];
    $eventMap = []; // acronym => event data

    if (file_exists($eventsFile)) {
        $data = json_decode(file_get_contents($eventsFile), true);
        if ($data) {
            // Active events
            foreach ($data['active'] ?? [] as $e) {
                $acr = $e['acronym'] ?? '';
                if ($acr) $eventMap[$acr] = $e;
            }
            // Archived events (needed for orders that reference them)
            foreach ($data['archived'] ?? [] as $e) {
                $acr = $e['acronym'] ?? '';
                if ($acr && !isset($eventMap[$acr])) $eventMap[$acr] = $e;
            }
        }
    }

    // Also scan orders for event prefixes not in events.json
    $statuses = courier_loadStatuses();
    foreach ($statuses as $ref => $status) {
        $prefix = explode('-', $ref)[0] ?? '';
        if ($prefix && !isset($eventMap[$prefix])) {
            $eventMap[$prefix] = ['acronym' => $prefix, 'name' => $prefix];
        }
    }

    return array_values($eventMap);
}

/**
 * Load only events that are currently active or upcoming (endDate >= today).
 * Past events are excluded.
 */
function loadActiveAndUpcomingEvents() {
    $eventsFile = __DIR__ . '/../admin/events.json';
    $today = date('Y-m-d');
    $eventMap = [];

    if (file_exists($eventsFile)) {
        $data = json_decode(file_get_contents($eventsFile), true);
        if ($data) {
            // Active events list (already filtered by admin)
            foreach ($data['active'] ?? [] as $e) {
                $acr = $e['acronym'] ?? '';
                if (!$acr) continue;
                $endDate = $e['endDate'] ?? null;
                // Include if no endDate (always active) OR endDate >= today
                if (!$endDate || $endDate >= $today) {
                    $eventMap[$acr] = $e;
                }
            }
            // Also check archived for events that were archived but haven't ended yet
            foreach ($data['archived'] ?? [] as $e) {
                $acr = $e['acronym'] ?? '';
                if (!$acr || isset($eventMap[$acr])) continue;
                $endDate = $e['endDate'] ?? null;
                if ($endDate && $endDate >= $today) {
                    $eventMap[$acr] = $e;
                }
            }
        }
    }

    // Sort by startDate ascending (earliest upcoming first)
    $events = array_values($eventMap);
    usort($events, function($a, $b) {
        $aStart = $a['startDate'] ?? '9999-12-31';
        $bStart = $b['startDate'] ?? '9999-12-31';
        return strcmp($aStart, $bStart);
    });

    return $events;
}

/**
 * Load past events (endDate < today). Used for the Complete tab archive.
 */
function loadPastEvents() {
    $eventsFile = __DIR__ . '/../admin/events.json';
    $today = date('Y-m-d');
    $eventMap = [];

    if (file_exists($eventsFile)) {
        $data = json_decode(file_get_contents($eventsFile), true);
        if ($data) {
            // Both active and archived can have past events
            foreach (array_merge($data['active'] ?? [], $data['archived'] ?? []) as $e) {
                $acr = $e['acronym'] ?? '';
                if (!$acr || isset($eventMap[$acr])) continue;
                $endDate = $e['endDate'] ?? null;
                if ($endDate && $endDate < $today) {
                    $eventMap[$acr] = $e;
                }
            }
        }
    }

    // Sort by endDate descending (most recent first)
    $events = array_values($eventMap);
    usort($events, function($a, $b) {
        $aEnd = $a['endDate'] ?? '0000-00-00';
        $bEnd = $b['endDate'] ?? '0000-00-00';
        return strcmp($bEnd, $aEnd);
    });

    return $events;
}

/**
 * Get just the acronyms from active events.
 * Returns empty array (meaning: don't filter) so all orders show.
 */
function getActiveAcronyms() {
    // Return empty = show all orders regardless of event
    return [];
}

// ============================================
// Upcoming Orders (printing / ready_to_ship)
// ============================================

function handleGetUpcoming() {
    $statuses = courier_loadStatuses();
    $upcoming = [];
    
    foreach ($statuses as $ref => $status) {
        if (empty($ref)) continue;
        if (!in_array($status, ['printing', 'ready_to_ship'])) continue;
        
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        
        $formatted = formatOrderForApp($order, $ref, $status);
        
        // Calculate pipeline emphasis tier
        $hr = $formatted['hours_remaining'];
        if ($hr !== null && $hr > 0) {
            if ($hr <= 24) $formatted['pipeline_tier'] = '24h';
            elseif ($hr <= 48) $formatted['pipeline_tier'] = '48h';
            elseif ($hr <= 72) $formatted['pipeline_tier'] = '72h';
            else $formatted['pipeline_tier'] = 'later';
        } else {
            $formatted['pipeline_tier'] = 'later';
        }
        
        $upcoming[] = $formatted;
    }
    
    // Sort by hours_remaining ascending (soonest first)
    usort($upcoming, function($a, $b) {
        $aH = $a['hours_remaining'] ?? 9999;
        $bH = $b['hours_remaining'] ?? 9999;
        return $aH <=> $bH;
    });
    
    echo json_encode([
        'success' => true,
        'orders' => $upcoming,
        'count' => count($upcoming),
    ]);
}

// ============================================
// Dispatch Action Handlers
// ============================================

function handleAcceptDelivery() {
    $user = getCurrentUser();
    $ref = trim($_POST['ref'] ?? '');
    if (!$ref) {
        echo json_encode(['success' => false, 'error' => 'Reference code required']);
        return;
    }
    if ($user['role'] !== 'courier') {
        echo json_encode(['success' => false, 'error' => 'Only couriers can accept deliveries']);
        return;
    }
    
    // Load order using dispatch-functions proven loader
    $order = courier_loadOrder($ref);
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found: ' . $ref]);
        return;
    }
    
    // Check status from statuses.json (source of truth)
    $currentStatus = courier_getStatus($ref);
    $acceptableStatuses = ['ready', 'preflight', 'printing']; // Allow vendor-behind orders
    if (!in_array($currentStatus, $acceptableStatuses)) {
        echo json_encode(['success' => false, 'error' => 'Order not available (status: ' . ($currentStatus ?: 'unknown') . ')']);
        return;
    }

    // Check not already assigned
    $dispatch = $order['dispatch'] ?? [];
    if (!empty($dispatch['courier_pin']) || !empty($dispatch['courier_id'])) {
        echo json_encode(['success' => false, 'error' => 'Already assigned to another courier']);
        return;
    }

    // Auto-cascade to dispatched (fills in skipped vendor steps if needed)
    $cascadeResult = cascadeStatusTo(
        $ref, 'dispatched', $user['name'],
        'Courier accepted delivery',
        dirname(__DIR__) . '/data/statuses.json',
        dirname(__DIR__) . '/uploads/orders/'
    );

    // Reload order after cascade
    $order = courier_loadOrder($ref);

    // Update dispatch metadata in order file
    $order['dispatch'] = array_merge($order['dispatch'] ?? [], [
        'courier_type' => 'internal',
        'courier_id' => $user['pin'],
        'courier_pin' => $user['pin'],
        'courier_name' => $user['name'],
        'dispatched_at' => date('c'),
        'dispatched_by' => $user['name'] . ' (self-assigned)',
    ]);
    courier_saveOrder($ref, $order);
    
    // Log activity
    logCourierActivity($ref, 'ready', 'dispatched', $user);
    
    // Dispatch notification
    if (function_exists('dispatch_notifyOrderDispatched')) {
        dispatch_notifyOrderDispatched($ref, $user['name']);
    }
    
    echo json_encode(['success' => true, 'message' => 'Delivery accepted', 'order' => formatOrderForApp($order, $ref, 'dispatched')]);
}

function handleUpdateStatus() {
    $user = getCurrentUser();
    $ref = trim($_POST['ref'] ?? '');
    $newStatus = trim($_POST['status'] ?? '');
    $photoData = $_POST['photo'] ?? '';
    
    if (!$ref || !$newStatus) {
        echo json_encode(['success' => false, 'error' => 'Reference and status required']);
        return;
    }
    if (!in_array($newStatus, $user['allowed_statuses'])) {
        echo json_encode(['success' => false, 'error' => 'Permission denied for status: ' . $newStatus]);
        return;
    }
    
    // Load order
    $order = courier_loadOrder($ref);
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found: ' . $ref]);
        return;
    }
    
    // Get current status from statuses.json (source of truth)
    $oldStatus = courier_getStatus($ref);

    // Auto-cascade through skipped lifecycle steps if needed
    // e.g. order still in "preflight" but courier is marking "shipped"
    $cascadeResult = cascadeStatusTo(
        $ref, $newStatus, $user['name'],
        'Courier confirmed via app',
        dirname(__DIR__) . '/data/statuses.json',
        dirname(__DIR__) . '/uploads/orders/'
    );

    if (!$cascadeResult['success']) {
        echo json_encode(['success' => false, 'error' => $cascadeResult['error'] ?? 'Status update failed']);
        return;
    }

    $skippedSteps = $cascadeResult['skipped'] ?? [];

    // Reload order after cascade may have updated it
    $order = courier_loadOrder($ref);

    // Update dispatch metadata in order file
    $order['dispatch'] = $order['dispatch'] ?? [];
    
    // Photo
    $photoPath = null;
    if ($photoData && strpos($photoData, 'data:image') === 0) {
        $photoPath = saveDeliveryPhoto($ref, $photoData);
        if ($photoPath) {
            $order['dispatch']['delivery_photo'] = $photoPath;
            $order['dispatch']['photo_taken_at'] = date('c');
        }
    }
    
    // Timestamps
    if ($newStatus === 'shipped') {
        $order['dispatch']['shipped_at'] = date('c');
        $order['dispatch']['shipped_by'] = $user['name'];
    } elseif ($newStatus === 'delivered') {
        $order['dispatch']['delivered_at'] = date('c');
        $order['dispatch']['delivered_by'] = $user['name'];
        if ($user['role'] === 'courier') {
            recordCourierEarning($user['pin'], $ref, $order);
        }
    } elseif ($newStatus === 'pickedup') {
        $order['dispatch']['picked_up_at'] = date('c');
        $order['dispatch']['pickedup_by'] = $user['name'];
    } elseif ($newStatus === 'unclaimed') {
        $order['dispatch']['unclaimed_at'] = date('c');
        $order['dispatch']['unclaimed_by'] = $user['name'];
    }
    
    courier_saveOrder($ref, $order);
    
    // Log
    logCourierActivity($ref, $oldStatus, $newStatus, $user, $photoPath);
    
    // Email notification
    if (function_exists('sendDispatchEmail')) {
        sendDispatchEmail($order, $oldStatus, $newStatus);
    }
    // Dispatch notification
    if (function_exists('dispatch_notifyStatusChange')) {
        dispatch_notifyStatusChange($ref, $oldStatus, $newStatus, $user['name']);
    }
    
    $message = 'Status updated to ' . $newStatus;
    if (!empty($skippedSteps)) {
        $message .= ' (auto-advanced through: ' . implode(', ', $skippedSteps) . ')';
    }

    echo json_encode(['success' => true, 'message' => $message, 'skipped_steps' => $skippedSteps, 'order' => formatOrderForApp($order, $ref, $newStatus)]);
}

function orderHasOpenIssue($ref) {
    static $issuesCache = null;
    if ($issuesCache === null) {
        $issuesFile = dirname(__DIR__) . '/data/delivery-issues.json';
        $issuesCache = [];
        if (file_exists($issuesFile)) {
            $data = json_decode(file_get_contents($issuesFile), true) ?: [];
            foreach ($data['issues'] ?? [] as $issue) {
                if (($issue['status'] ?? '') === 'open') {
                    $issueRef = $issue['reference_code'] ?? '';
                    if ($issueRef) $issuesCache[$issueRef] = true;
                }
            }
        }
    }
    return isset($issuesCache[$ref]);
}

function handleReportIssue() {
    $user = getCurrentUser();
    $ref = trim($_POST['ref'] ?? '');
    $issueType = trim($_POST['issue_type'] ?? '');
    $issueLabel = trim($_POST['issue_label'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $photoData = $_POST['photo'] ?? '';

    if (!$ref || !$issueType) {
        echo json_encode(['success' => false, 'error' => 'Reference and issue type required']);
        return;
    }

    // Save photo if provided
    $photoPath = null;
    if ($photoData && strpos($photoData, 'data:image') === 0) {
        $photoPath = saveDeliveryPhoto($ref . '_issue', $photoData);
    }

    // Build issue record
    $issue = [
        'id' => 'issue_' . bin2hex(random_bytes(6)),
        'reference_code' => $ref,
        'issue_type' => $issueType,
        'issue_label' => $issueLabel,
        'notes' => $notes,
        'photo' => $photoPath,
        'reported_by' => $user['name'],
        'reported_by_pin' => $user['pin'],
        'reported_by_role' => $user['role'],
        'reported_at' => date('c'),
        'status' => 'open',
        'resolved_at' => null,
        'resolved_by' => null,
    ];

    // Append to delivery-issues.json
    $issuesFile = dirname(__DIR__) . '/data/delivery-issues.json';
    $issues = [];
    if (file_exists($issuesFile)) {
        $issues = json_decode(file_get_contents($issuesFile), true) ?: [];
    }
    if (!isset($issues['issues'])) {
        $issues['issues'] = [];
    }
    $issues['issues'][] = $issue;
    $issues['metadata']['last_updated'] = date('c');
    file_put_contents($issuesFile, json_encode($issues, JSON_PRETTY_PRINT), LOCK_EX);

    // Log activity
    logCourierActivity($ref, 'issue_reported', $issueType . ': ' . ($notes ?: $issueLabel), $user);

    // Dispatch notification if available
    if (function_exists('dispatch_notifyStatusChange')) {
        dispatch_notifyStatusChange($ref, 'issue', $issueType, $user['name']);
    }

    echo json_encode(['success' => true, 'message' => 'Issue reported successfully']);
}

function handleSendQuickMessage() {
    $user = getCurrentUser();
    $messageType = trim($_POST['message_type'] ?? '');
    $messageText = trim($_POST['message_text'] ?? '');
    $ref = trim($_POST['ref'] ?? '');
    $customNote = trim($_POST['custom_note'] ?? '');

    if (!$messageType || !$messageText) {
        echo json_encode(['success' => false, 'error' => 'Message type required']);
        return;
    }

    $title = $user['name'] . ' (' . ($user['role_label'] ?? 'Courier') . ')';
    $msg = $messageText;
    if ($ref) $msg .= ' — ' . $ref;
    if ($customNote) $msg .= ' | Note: ' . $customNote;

    // Store as dispatch notification
    if (function_exists('dispatch_createNotification')) {
        dispatch_createNotification('courier_message', $title, $msg, [
            'ref' => $ref,
            'courier_pin' => $user['pin'],
            'courier_name' => $user['name'],
            'message_type' => $messageType,
            'action' => $ref ? 'view_order' : null,
        ]);
    }

    // Also log to activity
    logCourierActivity($ref ?: 'SYSTEM', 'quick_message', $messageType . ': ' . $msg, $user);

    echo json_encode(['success' => true, 'message' => 'Message sent to dispatch']);
}

function handleGetNotifications() {
    $user = getCurrentUser();
    $pin = $user['pin'];
    $since = $_POST['since'] ?? null; // ISO timestamp — only return newer notifications

    if (!function_exists('dispatch_getNotifications')) {
        echo json_encode(['success' => true, 'notifications' => []]);
        return;
    }

    $notifications = dispatch_getNotifications();
    $filtered = [];

    foreach ($notifications as $n) {
        // Filter to courier-relevant notification types
        $relevantTypes = ['order_dispatched', 'batch_dispatched', 'batch_created', 'courier_message', 'weather_alert', 'status_change'];
        if (!in_array($n['type'] ?? '', $relevantTypes)) continue;

        // Only show notifications for this courier's orders or general broadcasts
        $ctx = $n['context'] ?? [];
        $courierPin = $ctx['courier_pin'] ?? null;
        if ($courierPin && $courierPin !== $pin) continue;

        // Time filter
        if ($since && isset($n['created_at'])) {
            if (strtotime($n['created_at']) <= strtotime($since)) continue;
        }

        $filtered[] = [
            'id' => $n['id'] ?? '',
            'type' => $n['type'] ?? '',
            'title' => $n['title'] ?? '',
            'message' => $n['message'] ?? '',
            'created_at' => $n['created_at'] ?? '',
            'context' => $ctx,
        ];
    }

    // Return most recent 20
    $filtered = array_slice($filtered, 0, 20);

    echo json_encode(['success' => true, 'notifications' => $filtered]);
}

function handleScanOrder() {
    $user = getCurrentUser();
    $tracking = trim($_POST['tracking'] ?? '');
    if (!$tracking) {
        echo json_encode(['success' => false, 'error' => 'Tracking number required']);
        return;
    }
    
    // Try to find order by tracking number or reference code
    $result = findOrderByTrackingOrRef($tracking);
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Order not found for: ' . $tracking]);
        return;
    }
    
    $order = $result['order'];
    $ref = $result['ref'];
    
    // Get status from statuses.json (source of truth)
    $currentStatus = courier_getStatus($ref);
    $nextStatuses = getAvailableStatusTransitions($currentStatus, $user['role'], $user['allowed_statuses']);
    $receiveMode = in_array($user['role'], ['mtcc_staff', 'admin']) && $currentStatus === 'shipped';

    // Detect if order is behind in lifecycle (vendor skipped steps)
    $vendorBehind = false;
    $vendorWarning = null;
    $behindStatuses = ['preflight', 'printing'];
    if ($user['role'] === 'courier' && in_array($currentStatus, $behindStatuses)) {
        $vendorBehind = true;
        $statusLabels = ['preflight' => 'Preflight (awaiting vendor)', 'printing' => 'Printing'];
        $vendorWarning = 'This order is still in "' . ($statusLabels[$currentStatus] ?? $currentStatus) . '". If you have the item, confirm pickup and the system will auto-advance the status.';
        // Allow courier to mark shipped even though order isn't "ready" yet — cascade handles it
        if (!in_array('shipped', $nextStatuses)) {
            $nextStatuses[] = 'shipped';
        }
    }

    echo json_encode([
        'success' => true,
        'order' => formatOrderForApp($order, $ref, $currentStatus),
        'current_status' => $currentStatus,
        'available_statuses' => $nextStatuses,
        'receive_mode' => $receiveMode,
        'vendor_behind' => $vendorBehind,
        'vendor_warning' => $vendorWarning,
    ]);
}

// ============================================
// Earnings
// ============================================

function handleGetEarnings() {
    $user = getCurrentUser();
    $pin = $user['pin'];
    
    $earningsFile = DISPATCH_EARNINGS_FILE;
    $data = [];
    if (file_exists($earningsFile)) {
        $data = json_decode(file_get_contents($earningsFile), true) ?: [];
    }
    
    $myEarnings = $data['earnings'][$pin] ?? [];
    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $monthStart = date('Y-m-01');
    $todayTotal = $weekTotal = $monthTotal = $allTimeTotal = $deliveriesToday = 0;
    
    foreach ($myEarnings as $entry) {
        $d = substr($entry['date'] ?? '', 0, 10);
        $amt = floatval($entry['amount'] ?? 0);
        $allTimeTotal += $amt;
        if ($d === $today) { $todayTotal += $amt; $deliveriesToday++; }
        if ($d >= $weekStart) $weekTotal += $amt;
        if ($d >= $monthStart) $monthTotal += $amt;
    }
    
    // Build 7-day chart
    $dailyChart = [];
    for ($i = 6; $i >= 0; $i--) {
        $dayDate = date('Y-m-d', strtotime("-{$i} days"));
        $dayLabel = ($i === 0) ? 'Today' : date('D', strtotime($dayDate));
        $dayAmt = 0;
        $dayCount = 0;
        foreach ($myEarnings as $entry) {
            if (substr($entry['date'] ?? '', 0, 10) === $dayDate) {
                $dayAmt += floatval($entry['amount'] ?? 0);
                $dayCount++;
            }
        }
        $dailyChart[] = ['date' => $dayDate, 'label' => $dayLabel, 'amount' => round($dayAmt, 2), 'count' => $dayCount];
    }

    // Performance metrics — scan this courier's completed deliveries
    $performance = buildCourierPerformance($pin, $myEarnings);

    echo json_encode([
        'success' => true,
        'summary' => [
            'today' => round($todayTotal, 2),
            'week' => round($weekTotal, 2),
            'month' => round($monthTotal, 2),
            'all_time' => round($allTimeTotal, 2),
            'deliveries_today' => $deliveriesToday,
        ],
        'daily_chart' => $dailyChart,
        'performance' => $performance,
        'recent' => array_slice(array_reverse($myEarnings), 0, 20),
    ]);
}

function buildCourierPerformance($pin, $earnings) {
    $totalCompleted = count($earnings);
    $onTimeCount = 0;
    $totalDurationMin = 0;
    $durCount = 0;

    // Scan order files for delivery timing data
    $ordersDir = __DIR__ . '/../uploads/orders/';
    if (is_dir($ordersDir)) {
        $files = glob($ordersDir . '*-order.json');
        foreach ($files as $file) {
            $order = @json_decode(file_get_contents($file), true);
            if (!$order) continue;
            $dispatch = $order['dispatch'] ?? [];
            // Only orders assigned to this courier
            if (($dispatch['courier_pin'] ?? '') != $pin) continue;
            if (!in_array($order['status'] ?? '', ['delivered', 'pickedup'])) continue;

            // On-time check: compare delivery time to due date/time
            $deliveredAt = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null;
            if ($deliveredAt) {
                $dueDate = $order['selectedDate'] ?? $order['due_date'] ?? null;
                $dueTime = $order['deliveryTime'] ?? $order['due_time'] ?? 'anytime';
                if ($dueDate) {
                    $timeMap = ['anytime' => '18:00', '9am' => '09:00', '12pm' => '12:00', '3pm' => '15:00', '6pm' => '18:00'];
                    $dueHour = $timeMap[$dueTime] ?? '18:00';
                    $dueTimestamp = strtotime($dueDate . ' ' . $dueHour);
                    $deliveredTimestamp = strtotime($deliveredAt);
                    if ($deliveredTimestamp && $dueTimestamp && $deliveredTimestamp <= $dueTimestamp) {
                        $onTimeCount++;
                    }
                }
            }

            // Delivery duration: dispatched_at → delivered_at
            $dispatchedAt = $dispatch['dispatched_at'] ?? null;
            if ($dispatchedAt && $deliveredAt) {
                $dur = (strtotime($deliveredAt) - strtotime($dispatchedAt)) / 60;
                if ($dur > 0 && $dur < 480) { // sanity check: under 8 hours
                    $totalDurationMin += $dur;
                    $durCount++;
                }
            }
        }
    }

    return [
        'total_completed' => $totalCompleted,
        'on_time_count' => $onTimeCount,
        'on_time_rate' => $totalCompleted > 0 ? round(($onTimeCount / $totalCompleted) * 100) : 0,
        'avg_delivery_min' => $durCount > 0 ? round($totalDurationMin / $durCount) : 0,
    ];
}

function recordCourierEarning($pin, $ref, $order) {
    $earningsFile = DISPATCH_EARNINGS_FILE;
    $data = ['earnings' => [], 'metadata' => ['last_updated' => null, 'version' => '1.0']];
    if (file_exists($earningsFile)) {
        $data = json_decode(file_get_contents($earningsFile), true) ?: $data;
    }
    
    $amount = calculateDeliveryEarning($order);
    if (!isset($data['earnings'][$pin])) $data['earnings'][$pin] = [];
    $data['earnings'][$pin][] = [
        'ref' => $ref,
        'amount' => $amount,
        'date' => date('c'),
        'breakdown' => getEarningBreakdown($order),
    ];
    $data['metadata']['last_updated'] = date('c');
    
    // Update courier totals
    $couriersFile = DISPATCH_COURIERS_FILE;
    if (file_exists($couriersFile)) {
        $couriers = json_decode(file_get_contents($couriersFile), true);
        if ($couriers && isset($couriers['users'][$pin])) {
            $couriers['users'][$pin]['total_deliveries'] = ($couriers['users'][$pin]['total_deliveries'] ?? 0) + 1;
            $couriers['users'][$pin]['total_earned'] = ($couriers['users'][$pin]['total_earned'] ?? 0) + $amount;
            file_put_contents($couriersFile, json_encode($couriers, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
    }
    file_put_contents($earningsFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function calculateDeliveryEarning($order) {
    $settings = dispatch_loadSettings();
    $pricing = $settings['pricing'] ?? [];
    $amount = $pricing['base_rate'] ?? 30;
    
    if (!empty($settings['weather']['bad_weather_active'])) {
        $amount += $pricing['modifiers']['bad_weather'] ?? 5;
    }
    
    $hour = (int)date('H');
    foreach ($pricing['time_windows'] ?? [] as $window) {
        $amt = $window['amount'] ?? 0;
        if ($amt <= 0) continue;
        $match = false;
        if (isset($window['before']) && $hour < (int)substr($window['before'], 0, 2)) $match = true;
        if (isset($window['after']) && $hour >= (int)substr($window['after'], 0, 2)) $match = true;
        if (isset($window['start']) && isset($window['end'])) {
            if ($hour >= (int)substr($window['start'], 0, 2) && $hour < (int)substr($window['end'], 0, 2)) $match = true;
        }
        if ($match) { $amount += $amt; break; }
    }
    
    // Distance modifier (if route info available on order)
    if (!empty($order['route_distance_km'])) {
        $routes = new RoutesAPI();
        $distMod = $routes->calculateDistanceModifier($order['route_distance_km']);
        $amount += $distMod;
    }
    
    return round($amount, 2);
}

function getEarningBreakdown($order) {
    $settings = dispatch_loadSettings();
    $pricing = $settings['pricing'] ?? [];
    $breakdown = [['label' => 'Base rate', 'amount' => $pricing['base_rate'] ?? 30]];
    if (!empty($settings['weather']['bad_weather_active'])) {
        $breakdown[] = ['label' => 'Weather bonus', 'amount' => $pricing['modifiers']['bad_weather'] ?? 5];
    }
    $hour = (int)date('H');
    foreach ($pricing['time_windows'] ?? [] as $name => $window) {
        $amt = $window['amount'] ?? 0;
        if ($amt <= 0) continue;
        $match = false;
        if (isset($window['before']) && $hour < (int)substr($window['before'], 0, 2)) $match = true;
        if (isset($window['after']) && $hour >= (int)substr($window['after'], 0, 2)) $match = true;
        if (isset($window['start']) && isset($window['end'])) {
            if ($hour >= (int)substr($window['start'], 0, 2) && $hour < (int)substr($window['end'], 0, 2)) $match = true;
        }
        if ($match) {
            $breakdown[] = ['label' => ucfirst(str_replace('_', ' ', $name)) . ' bonus', 'amount' => $amt];
            break;
        }
    }
    
    // Distance modifier
    if (!empty($order['route_distance_km'])) {
        $routes = new RoutesAPI();
        $distMod = $routes->calculateDistanceModifier($order['route_distance_km']);
        if ($distMod > 0) {
            $breakdown[] = ['label' => 'Distance (' . $order['route_distance_km'] . ' km)', 'amount' => $distMod];
        }
    }
    
    return $breakdown;
}

// ============================================
// Helpers
// ============================================

/**
 * Format order data for the mobile app.
 * Uses correct field names from the actual order JSON structure:
 *   customerInfo.name, dimensions.width/height, pricing.total/tier, event.name/acronym
 * Status comes from statuses.json, NOT from the order file.
 */
function formatOrderForApp($order, $ref = null, $statusOverride = null) {
    if (!$ref) $ref = $order['referenceCode'] ?? '';
    
    // Status from statuses.json (passed in or looked up)
    $status = $statusOverride ?: courier_getStatus($ref);
    
    $dispatch = $order['dispatch'] ?? [];
    $customerInfo = $order['customerInfo'] ?? [];
    $dimensions = $order['dimensions'] ?? [];
    $pricing = $order['pricing'] ?? [];
    $event = $order['event'] ?? $order['event_select'] ?? [];

    // Build tracking number
    $eventPrefix = $event['acronym'] ?? '';
    $orderNum = '001';
    if (preg_match('/(\d+)$/', $ref, $m)) $orderNum = $m[1];
    $dateStr = $order['selectedDate'] ?? date('Y-m-d');
    try { $d = new DateTime($dateStr); } catch (Exception $e) { $d = new DateTime(); }
    $tracking = $eventPrefix
        ? 'MTCC' . strtoupper($eventPrefix) . str_pad($orderNum, 3, '0', STR_PAD_LEFT) . $d->format('ymd')
        : 'MTCC' . $d->format('ymd') . str_pad($orderNum, 3, '0', STR_PAD_LEFT);
    
    // Destination from dispatch-functions helper (includes address, instructions, building)
    $dest = dispatch_getDestination($order);
    
    // Vendor info from preflight-log + vendors.json
    $vendorInfo = dispatch_getVendorInfo($ref);
    $vendorName = $vendorInfo ? ($vendorInfo['vendor_name'] ?? 'Unknown Vendor') : 'Unknown Vendor';
    $vendorAddress = '';
    if ($vendorInfo && !empty($vendorInfo['vendor_id'])) {
        $vendorAddress = courier_getVendorAddress($vendorInfo['vendor_id']);
    }
    // If dispatch_summary has vendor info cached, use that
    if (isset($order['dispatch_summary']['vendor_name'])) {
        $vendorName = $order['dispatch_summary']['vendor_name'];
    }
    
    // Estimated courier payout from dispatch-settings
    $payoutInfo = courier_calculatePayout();
    
    // Urgency / due info from dispatch-functions
    $dueInfo = courier_getDueInfo($order);
    $hoursRemaining = $dueInfo['hours_remaining'];
    $urgencyLevel = 'normal'; // normal, orange (<4hr), red (<2hr)
    if ($hoursRemaining !== null && $hoursRemaining > 0) {
        if ($hoursRemaining <= 2) $urgencyLevel = 'red';
        elseif ($hoursRemaining <= 4) $urgencyLevel = 'orange';
    }
    
    // Vendor phone from vendors.json
    $vendorPhone = '';
    if ($vendorInfo && !empty($vendorInfo['vendor_id'])) {
        $vendorPhone = courier_getVendorPhone($vendorInfo['vendor_id']);
    }
    
    return [
        'ref' => $ref,
        'tracking' => $tracking,
        'status' => $status,
        'customer_name' => $customerInfo['name'] ?? '',
        'customer_email' => $customerInfo['email'] ?? '',
        'customer_phone' => $customerInfo['phone'] ?? '',
        'material' => $order['material'] ?? '',
        'size' => ($dimensions['width'] ?? '') . '" x ' . ($dimensions['height'] ?? '') . '"',
        'width' => floatval($dimensions['width'] ?? 0),
        'height' => floatval($dimensions['height'] ?? 0),
        'quantity' => $order['quantity'] ?? 1,
        'event' => $event['name'] ?? '',
        'event_acronym' => $event['acronym'] ?? '',
        'building' => $event['building'] ?? '',
        // Destination details
        'destination' => $dest['label'] ?? '',
        'destination_type' => $dest['type'] ?? '',
        'destination_address' => $dest['address'] ?? '',
        'destination_instructions' => $dest['instructions'] ?? '',
        'destination_building' => $dest['building'] ?? '',
        // Vendor / Pickup details
        'vendor_name' => $vendorName,
        'vendor_address' => $vendorAddress,
        'vendor_phone' => $vendorPhone,
        // Due date / urgency
        'due_date' => $order['selectedDate'] ?? '',
        'due_time' => $dueInfo['time'] ?? 'anytime',
        'due_time_formatted' => $dueInfo['time_formatted'] ?? 'Anytime',
        'due_date_formatted' => $dueInfo['date_formatted'] ?? '',
        'hours_remaining' => $hoursRemaining,
        'urgency' => $urgencyLevel,
        'is_today' => $dueInfo['is_today'] ?? false,
        // Pricing
        'delivery_tier' => $pricing['tier'] ?? '',
        'total' => $pricing['total'] ?? 0,
        'base_price' => $pricing['basePrice'] ?? 0,
        'tax' => $pricing['tax'] ?? 0,
        'delivery_fee' => $pricing['deliveryFee'] ?? 0,
        // Courier payout
        'est_payout' => $payoutInfo['total'],
        'est_payout_breakdown' => $payoutInfo['breakdown'],
        // Dispatch details
        'courier_name' => $dispatch['courier_name'] ?? '',
        'courier_pin' => $dispatch['courier_pin'] ?? $dispatch['courier_id'] ?? '',
        'dispatched_at' => $dispatch['dispatched_at'] ?? '',
        'shipped_at' => $dispatch['shipped_at'] ?? '',
        'delivered_at' => $dispatch['delivered_at'] ?? '',
        'picked_up_at' => $dispatch['picked_up_at'] ?? '',
        'pickedup_by' => $dispatch['pickedup_by'] ?? '',
        'delivered_by' => $dispatch['delivered_by'] ?? '',
        'shipped_by' => $dispatch['shipped_by'] ?? '',
        'delivery_photo' => $dispatch['delivery_photo'] ?? '',
        'notes' => $order['specialInstructions'] ?? '',
        // Issue tracking
        'has_issue' => orderHasOpenIssue($ref),
        // Vendor fulfillment details
        'vendor_order_number' => $vendorInfo ? ($vendorInfo['vendor_order_number'] ?? '') : '',
        'packing' => $vendorInfo ? ($vendorInfo['packing'] ?? 'none') : 'none',
        'packing_details' => $vendorInfo ? ($vendorInfo['packing_details'] ?? []) : [],
        // Route info (saved by get_route_info endpoint)
        'route_distance_km' => $order['route_info']['distance_km'] ?? $order['route_distance_km'] ?? null,
        'route_duration_min' => $order['route_info']['duration_min'] ?? $order['route_duration_min'] ?? null,
        'route_polyline' => $order['route_info']['polyline'] ?? null,
    ];
}


/**
 * Get vendor address from vendors.json by vendor_id
 */
function courier_getVendorAddress($vendorId) {
    $vendorsFile = __DIR__ . '/../data/vendors.json';
    if (!file_exists($vendorsFile)) return '';
    $data = json_decode(file_get_contents($vendorsFile), true);
    if (!$data || !isset($data['vendors'])) return '';
    foreach ($data['vendors'] as $v) {
        if (($v['id'] ?? '') === $vendorId) {
            return $v['address'] ?? '';
        }
    }
    return '';
}

/**
 * Get vendor phone from vendors.json by vendor_id
 */
function courier_getVendorPhone($vendorId) {
    $vendorsFile = __DIR__ . '/../data/vendors.json';
    if (!file_exists($vendorsFile)) return '';
    $data = json_decode(file_get_contents($vendorsFile), true);
    if (!$data || !isset($data['vendors'])) return '';
    foreach ($data['vendors'] as $v) {
        if (($v['id'] ?? '') === $vendorId) {
            return $v['phone'] ?? '';
        }
    }
    return '';
}


/**
 * Calculate due date/time info and urgency for an order.
 */
function courier_getDueInfo($order) {
    $selectedDate = $order['selectedDate'] ?? '';
    $deliveryTime = $order['deliveryTime'] ?? 'anytime';
    
    $timeLabels = [
        'anytime' => 'Anytime', '9am' => '9:00 AM',
        '12pm' => '12:00 PM', '3pm' => '3:00 PM', '6pm' => '6:00 PM',
    ];
    $timeHours = [
        'anytime' => 18, '9am' => 9, '12pm' => 12, '3pm' => 15, '6pm' => 18,
    ];
    
    $timeFormatted = $timeLabels[$deliveryTime] ?? 'Anytime';
    $dateFormatted = '';
    $hoursRemaining = null;
    $isToday = false;
    
    if ($selectedDate) {
        try {
            $dueDate = new DateTime($selectedDate);
            $dateFormatted = $dueDate->format('l, M j, Y');
            $today = new DateTime();
            $isToday = ($dueDate->format('Y-m-d') === $today->format('Y-m-d'));
            
            $dueHour = $timeHours[$deliveryTime] ?? 18;
            $dueDateTime = clone $dueDate;
            $dueDateTime->setTime($dueHour, 0, 0);
            $now = new DateTime();
            if ($dueDateTime > $now) {
                $diff = $now->diff($dueDateTime);
                $hoursRemaining = ($diff->days * 24) + $diff->h + ($diff->i / 60);
            } else {
                $diff = $dueDateTime->diff($now);
                $hoursRemaining = -(($diff->days * 24) + $diff->h + ($diff->i / 60));
            }
            $hoursRemaining = round($hoursRemaining, 2);
        } catch (Exception $e) {}
    }
    
    return [
        'time' => $deliveryTime,
        'time_formatted' => $timeFormatted,
        'date_formatted' => $dateFormatted,
        'hours_remaining' => $hoursRemaining,
        'is_today' => $isToday,
    ];
}
/**
 * Calculate estimated courier payout from current dispatch-settings pricing
 */
function courier_calculatePayout() {
    $settings = dispatch_loadSettings();
    $pricing = $settings['pricing'] ?? [];
    $base = $pricing['base_rate'] ?? 30;
    $breakdown = [['label' => 'Base rate', 'amount' => $base]];
    $total = $base;
    
    // Weather bonus
    if (!empty($settings['weather']['bad_weather_active'])) {
        $bonus = $pricing['modifiers']['bad_weather'] ?? 5;
        $breakdown[] = ['label' => 'Weather bonus', 'amount' => $bonus];
        $total += $bonus;
    }
    
    // Time-of-day bonus
    $hour = (int)date('H');
    foreach ($pricing['time_windows'] ?? [] as $name => $window) {
        $amt = $window['amount'] ?? 0;
        if ($amt <= 0) continue;
        $match = false;
        if (isset($window['before']) && $hour < (int)substr($window['before'], 0, 2)) $match = true;
        if (isset($window['after']) && $hour >= (int)substr($window['after'], 0, 2)) $match = true;
        if (isset($window['start']) && isset($window['end'])) {
            if ($hour >= (int)substr($window['start'], 0, 2) && $hour < (int)substr($window['end'], 0, 2)) $match = true;
        }
        if ($match) {
            $label = ucfirst(str_replace('_', ' ', $name)) . ' bonus';
            $breakdown[] = ['label' => $label, 'amount' => $amt];
            $total += $amt;
            break;
        }
    }
    
    return ['total' => round($total, 2), 'breakdown' => $breakdown];
}

/**
 * Find an order by tracking number or reference code.
 * Returns ['order' => ..., 'ref' => ...] or null.
 */
function findOrderByTrackingOrRef($tracking) {
    $tracking = strtoupper(trim($tracking));
    $dir = DISPATCH_ORDERS_DIR;
    if (!is_dir($dir)) return null;
    
    $files = glob($dir . '*.json');
    if (empty($files)) return null;
    
    foreach ($files as $file) {
        $order = json_decode(file_get_contents($file), true);
        if (!$order) continue;
        
        $ref = $order['referenceCode'] ?? '';
        
        // Check reference code directly
        if (strtoupper($ref) === $tracking) {
            return ['order' => $order, 'ref' => $ref];
        }
        
        // Generate tracking number and compare
        $ep = $order['event']['acronym'] ?? $order['event_select']['acronym'] ?? '';
        $num = '001';
        if (preg_match('/(\d+)$/', $ref, $m)) $num = $m[1];
        $ds = $order['selectedDate'] ?? date('Y-m-d');
        try { $dt = new DateTime($ds); } catch (Exception $e) { $dt = new DateTime(); }
        $gen = $ep
            ? 'MTCC' . strtoupper($ep) . str_pad($num, 3, '0', STR_PAD_LEFT) . $dt->format('ymd')
            : 'MTCC' . $dt->format('ymd') . str_pad($num, 3, '0', STR_PAD_LEFT);
        
        if (strtoupper($gen) === $tracking) {
            return ['order' => $order, 'ref' => $ref];
        }
    }
    return null;
}

function getAvailableStatusTransitions($currentStatus, $role, $allowedStatuses) {
    // Couriers follow strict flow: accept -> pickup -> deliver
    // Admin/Staff can skip dispatched for 3rd-party courier scenarios
    if ($role === 'courier') {
        $transitions = [
            'ready' => ['dispatched'],
            'dispatched' => ['shipped'],
            'shipped' => ['delivered'],
        ];
    } else {
        $transitions = [
            'ready' => ['dispatched', 'shipped'],
            'dispatched' => ['shipped'],
            'shipped' => ['delivered'],
            'delivered' => ['pickedup'],
        ];
    }
    return array_values(array_intersect($transitions[$currentStatus] ?? [], $allowedStatuses));
}

function saveDeliveryPhoto($ref, $base64Data) {
    if (!preg_match('/^data:image\/(jpeg|png|jpg);base64,(.+)$/', $base64Data, $m)) return null;
    $ext = $m[1] === 'png' ? 'png' : 'jpg';
    $decoded = base64_decode($m[2]);
    if (!$decoded) return null;
    $filename = $ref . '_' . date('Ymd_His') . '.' . $ext;
    if (file_put_contents(COURIER_PHOTOS_DIR . $filename, $decoded, LOCK_EX)) {
        return 'delivery-photos/' . $filename;
    }
    return null;
}

function logCourierActivity($ref, $from, $to, $user, $photo = null) {
    $logFile = __DIR__ . '/../dispatch/dispatch-log.json';
    if (!file_exists($logFile)) {
        $logFile = __DIR__ . '/../data/dispatch-log.json';
    }
    $logData = ['entries' => []];
    if (file_exists($logFile)) {
        $logData = json_decode(file_get_contents($logFile), true) ?: ['entries' => []];
    }
    $logData['entries'][] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'referenceCode' => $ref,
        'fromStatus' => $from,
        'toStatus' => $to,
        'userName' => $user['name'],
        'userRole' => $user['role'],
        'roleLabel' => $user['role_label'],
        'photo' => $photo,
        'source' => 'courier_app',
    ];
    if (count($logData['entries']) > 500) {
        $logData['entries'] = array_slice($logData['entries'], -500);
    }
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}


// ============================================
// Weather Handler
// ============================================

function handleGetWeather() {
    $weather = new WeatherAPI();
    $data = $weather->getWeather();
    
    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'Weather data unavailable']);
        return;
    }
    
    // Check bad weather thresholds and auto-toggle
    $badWeather = $weather->checkBadWeather($data);
    
    echo json_encode([
        'success' => true,
        'weather' => $data,
        'bad_weather' => $badWeather
    ]);
}

// ============================================
// Routes & Maps Handlers
// ============================================

/**
 * Get route info between pickup and dropoff for a specific order.
 * Returns distance, duration, and static map URL.
 */
function handleGetRouteInfo() {
    $ref = $_POST['ref'] ?? '';
    if (empty($ref)) {
        echo json_encode(['success' => false, 'error' => 'Missing ref']);
        return;
    }
    
    $routes = new RoutesAPI();
    
    // Load order using dispatch system's file lookup
    $order = courier_loadOrder($ref);
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }
    
    $formatted = formatOrderForApp($order, $ref);
    
    // Get pickup coordinates
    $pickupCoords = $routes->getOrderPickupCoords($formatted);
    if (!$pickupCoords) {
        echo json_encode(['success' => false, 'error' => 'Could not geocode pickup address']);
        return;
    }
    
    // Get dropoff coordinates  
    $dropoffCoords = $routes->getOrderDropoffCoords($formatted);
    if (!$dropoffCoords) {
        echo json_encode(['success' => false, 'error' => 'Could not geocode dropoff address']);
        return;
    }
    
    // Calculate route
    $routeInfo = $routes->getDistance($pickupCoords, $dropoffCoords);
    if (!$routeInfo) {
        echo json_encode(['success' => false, 'error' => 'Route calculation failed']);
        return;
    }
    
    // Generate static map URL
    $stops = [$pickupCoords, $dropoffCoords];
    $mapUrl = $routes->getStaticMapUrl($stops, 400, 200, $routeInfo['polyline'] ?? null);
    
    // Generate directions link
    $directionsUrl = $routes->getDirectionsUrl($pickupCoords, $dropoffCoords);
    
    // Save route info to order (including polyline for map rendering)
    $order['route_info'] = [
        'distance_km' => $routeInfo['distance_km'],
        'duration_min' => $routeInfo['duration_min'],
        'polyline' => $routeInfo['polyline'] ?? null,
        'calculated_at' => date('c'),
        'pickup_coords' => $pickupCoords,
        'dropoff_coords' => $dropoffCoords
    ];
    courier_saveOrder($ref, $order);
    
    echo json_encode([
        'success' => true,
        'route' => [
            'distance_km' => $routeInfo['distance_km'],
            'duration_min' => $routeInfo['duration_min'],
            'polyline' => $routeInfo['polyline'],
            'static_map_url' => $mapUrl,
            'directions_url' => $directionsUrl,
            'pickup' => $pickupCoords,
            'dropoff' => $dropoffCoords
        ]
    ]);
}

// ============================================
// ACTION: Optimize Route (from courier GPS to all stops)
// ============================================
function handleOptimizeRoute() {
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    $stops = json_decode($_POST['stops'] ?? '[]', true);

    if (!$lat || !$lng || empty($stops)) {
        echo json_encode(['success' => false, 'error' => 'Missing location or stops']);
        return;
    }

    $routes = new RoutesAPI();
    $origin = ['lat' => $lat, 'lng' => $lng];

    // Last stop is the final destination, rest are intermediates
    $destination = end($stops);
    $waypoints = array_slice($stops, 0, -1);

    // If only one stop, just get simple route
    if (count($stops) === 1) {
        $result = $routes->getDistance($origin, $stops[0]);
        if (!$result) {
            echo json_encode(['success' => false, 'error' => 'Route calculation failed']);
            return;
        }
        echo json_encode([
            'success' => true,
            'optimized_order' => [0],
            'distance_km' => $result['distance_km'],
            'duration_min' => $result['duration_min'],
            'polyline' => $result['polyline']
        ]);
        return;
    }

    // Multiple stops — optimize waypoint order
    $result = $routes->calculateRoute($origin, $destination, $waypoints, true);
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Route optimization failed']);
        return;
    }

    echo json_encode([
        'success' => true,
        'optimized_order' => $result['optimized_order'],
        'distance_km' => $result['distance_km'],
        'duration_min' => $result['duration_min'],
        'polyline' => $result['polyline'],
        'legs' => $result['legs']
    ]);
}

/**
 * Get nearby available orders with distances from courier's location.
 * Courier sends their lat/lng, we return orders sorted by distance.
 */
function handleGetNearbyOrders() {
    $courierLat = (float)($_POST['lat'] ?? 0);
    $courierLng = (float)($_POST['lng'] ?? 0);
    $radiusKm = (float)($_POST['radius'] ?? 15);
    
    if ((!$courierLat && !$courierLng) || $courierLat < -90 || $courierLat > 90 || $courierLng < -180 || $courierLng > 180) {
        echo json_encode(['success' => false, 'error' => 'Location required']);
        return;
    }
    
    $routes = new RoutesAPI();
    
    // Get available orders only (ready = unassigned, available for pickup)
    $statusesFile = defined('COURIER_STATUSES_FILE') ? COURIER_STATUSES_FILE : __DIR__ . '/../data/statuses.json';
    $statuses = file_exists($statusesFile) ? json_decode(file_get_contents($statusesFile), true) : [];

    $nearbyOrders = [];

    foreach ($statuses as $ref => $status) {
        if (!is_string($status)) continue;
        if ($status !== 'ready') continue;
        
        $order = courier_loadOrder($ref);
        if (!$order) continue;
        
        $formatted = formatOrderForApp($order, $ref, $status);
        
        // Get pickup coords
        $pickupCoords = $routes->getOrderPickupCoords($formatted);
        if (!$pickupCoords) continue;
        
        // Calculate straight-line distance
        $straightLine = sqrt(
            pow(($pickupCoords['lat'] - $courierLat) * 111, 2) +
            pow(($pickupCoords['lng'] - $courierLng) * 111 * cos(deg2rad($courierLat)), 2)
        );
        
        if ($straightLine > $radiusKm) continue;
        
        // Get dropoff coords
        $dropoffCoords = $routes->getOrderDropoffCoords($formatted);
        
        $formatted['pickup_coords'] = $pickupCoords;
        $formatted['dropoff_coords'] = $dropoffCoords;
        $formatted['distance_from_courier_km'] = round($straightLine * 1.3, 1); // ~driving estimate
        $formatted['est_pickup_min'] = max(5, round($straightLine * 1.3 * 2.5, 0));
        
        $nearbyOrders[] = $formatted;
    }
    
    // Sort by distance from courier
    usort($nearbyOrders, function($a, $b) {
        return ($a['distance_from_courier_km'] ?? 999) <=> ($b['distance_from_courier_km'] ?? 999);
    });
    
    echo json_encode([
        'success' => true,
        'orders' => $nearbyOrders,
        'courier_location' => ['lat' => $courierLat, 'lng' => $courierLng],
        'radius_km' => $radiusKm
    ]);
}

/**
 * Geocode all vendors that don't have coordinates yet.
 * Returns array of vendor coords.
 */
function handleGeocodeVendors() {
    $routes = new RoutesAPI();
    
    $vendorsFile = __DIR__ . '/../data/vendors.json';
    if (!file_exists($vendorsFile)) {
        echo json_encode(['success' => false, 'error' => 'Vendors file not found']);
        return;
    }
    
    $vendorData = json_decode(file_get_contents($vendorsFile), true);
    $results = [];
    
    foreach ($vendorData['vendors'] as $vendor) {
        if (!$vendor['active']) continue;
        
        $coords = $routes->geocodeVendor($vendor['id']);
        $results[] = [
            'id' => $vendor['id'],
            'name' => $vendor['business_name'],
            'coords' => $coords
        ];
    }
    
    echo json_encode(['success' => true, 'vendors' => $results]);
}

// ============================================
// Batch Order Handlers
// ============================================

/**
 * Courier accepts a batch
 * POST: action=accept_batch, batch_id
 */
function handleAcceptBatch() {
    $user = getCurrentUser();
    $batchId = trim($_POST['batch_id'] ?? '');
    
    if (!$batchId) {
        echo json_encode(['success' => false, 'error' => 'Batch ID required']);
        return;
    }
    if ($user['role'] !== 'courier') {
        echo json_encode(['success' => false, 'error' => 'Only couriers can accept batches']);
        return;
    }
    
    $result = batch_accept($batchId, $user);
    echo json_encode($result);
}

/**
 * Update a single stop within a batch
 * POST: action=update_batch_stop, batch_id, stop_index, status (completed|skipped), photo (optional)
 */
function handleUpdateBatchStop() {
    $user = getCurrentUser();
    $batchId = trim($_POST['batch_id'] ?? '');
    $stopIndex = intval($_POST['stop_index'] ?? -1);
    $newStatus = trim($_POST['status'] ?? '');
    $photoData = $_POST['photo'] ?? '';
    
    if (!$batchId || $stopIndex < 0 || !$newStatus) {
        echo json_encode(['success' => false, 'error' => 'batch_id, stop_index, and status are required']);
        return;
    }
    if (!in_array($newStatus, ['completed', 'skipped'])) {
        echo json_encode(['success' => false, 'error' => 'Status must be completed or skipped']);
        return;
    }
    
    $result = batch_updateStop($batchId, $stopIndex, $newStatus, $user, $photoData);
    echo json_encode($result);
}

/**
 * Get optimized route from courier's current GPS position
 * POST: action=get_batch_route, batch_id, lat, lng
 */
function handleGetBatchRoute() {
    $batchId = trim($_POST['batch_id'] ?? '');
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    
    if (!$batchId || !$lat || !$lng) {
        echo json_encode(['success' => false, 'error' => 'batch_id, lat, and lng are required']);
        return;
    }
    
    $result = batch_recalculateRoute($batchId, $lat, $lng);
    echo json_encode($result);
}

/**
 * Run full batch auto-detection scan
 * POST: action=suggest_batches
 */
function handleSuggestBatches() {
    $suggestions = batch_autoDetect();
    
    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions,
        'count' => count($suggestions)
    ]);
}

/**
 * Create a batch from selected orders
 * POST: action=create_batch, order_refs (comma-separated or JSON array)
 */
function handleCreateBatch() {
    $user = getCurrentUser();
    
    $refsInput = $_POST['order_refs'] ?? '';
    
    if (is_string($refsInput)) {
        $decoded = json_decode($refsInput, true);
        if (is_array($decoded)) {
            $refs = $decoded;
        } else {
            $refs = array_filter(array_map('trim', explode(',', $refsInput)));
        }
    } elseif (is_array($refsInput)) {
        $refs = $refsInput;
    } else {
        echo json_encode(['success' => false, 'error' => 'order_refs required']);
        return;
    }
    
    if (count($refs) < 2) {
        echo json_encode(['success' => false, 'error' => 'At least 2 order refs required']);
        return;
    }
    
    $createdBy = $user['name'] ?? 'courier';
    $result = batch_create($refs, $createdBy, false);
    echo json_encode($result);
}

/**
 * Disband/cancel a batch
 * POST: action=disband_batch, batch_id
 */
function handleDisbandBatch() {
    $user = getCurrentUser();
    $batchId = trim($_POST['batch_id'] ?? '');
    
    if (!$batchId) {
        echo json_encode(['success' => false, 'error' => 'Batch ID required']);
        return;
    }
    
    $result = batch_disband($batchId, $user['name'] ?? 'admin');
    echo json_encode($result);
}

// ============================================
// Release / Unassign Handlers
// ============================================

/**
 * Courier releases a single delivery back to the available queue
 * POST: action=release_delivery, ref, reason (optional)
 */
function handleReleaseDelivery() {
    $user = getCurrentUser();
    $ref = trim($_POST['ref'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    
    if (!$ref) {
        echo json_encode(['success' => false, 'error' => 'Reference code required']);
        return;
    }
    
    // Load order and verify this courier owns it
    $order = courier_loadOrder($ref);
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        return;
    }
    
    $dispatch = $order['dispatch'] ?? [];
    $courierPin = $dispatch['courier_pin'] ?? $dispatch['courier_id'] ?? '';
    
    // Couriers can only release their own orders; admins can release any
    if ($user['role'] === 'courier' && $courierPin !== $user['pin']) {
        echo json_encode(['success' => false, 'error' => 'This order is not assigned to you']);
        return;
    }
    
    // Couriers cannot release shipped orders (already picked up)
    $adminForce = in_array($user['role'], ['admin', 'mtcc_staff']);
    
    $result = batch_unassignOrder($ref, $user['name'], $reason, $adminForce);
    echo json_encode($result);
}

/**
 * Courier releases an entire batch back to available
 * POST: action=release_batch, batch_id, reason (optional)
 */
function handleReleaseBatch() {
    $user = getCurrentUser();
    $batchId = trim($_POST['batch_id'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    
    if (!$batchId) {
        echo json_encode(['success' => false, 'error' => 'Batch ID required']);
        return;
    }
    
    $found = batch_find($batchId);
    if (!$found || $found['list'] !== 'active') {
        echo json_encode(['success' => false, 'error' => 'Batch not found or already completed']);
        return;
    }
    $batch = $found['batch'];
    
    // Verify courier owns this batch (admins can release any)
    $batchPin = $batch['courier']['pin'] ?? $batch['courier_id'] ?? '';
    if ($user['role'] === 'courier' && $batchPin !== $user['pin']) {
        echo json_encode(['success' => false, 'error' => 'This batch is not assigned to you']);
        return;
    }
    
    // Cannot release in_progress batches from courier side
    if ($user['role'] === 'courier' && $batch['status'] === 'in_progress') {
        echo json_encode(['success' => false, 'error' => 'Cannot release a batch that is in progress. Contact admin.']);
        return;
    }
    
    // Disband the batch (returns all orders to ready)
    $result = batch_disband($batchId, $user['name'] . ' (' . ($reason ?: 'released') . ')');
    echo json_encode($result);
}

// ============================================
// ACTION: Update Courier Location (for live tracking)
// ============================================
function handleUpdateLocation() {
    $user = getCurrentUser();
    $lat = floatval($_POST['lat'] ?? 0);
    $lng = floatval($_POST['lng'] ?? 0);
    if (!$lat || !$lng) {
        echo json_encode(['success' => false, 'error' => 'Missing coordinates']);
        return;
    }
    $file = dirname(__DIR__) . '/data/courier-locations.json';
    $locations = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $locations[$user['pin'] ?? 'unknown'] = [
        'lat' => $lat,
        'lng' => $lng,
        'name' => $user['name'] ?? '',
        'updated_at' => date('c')
    ];
    file_put_contents($file, json_encode($locations, JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['success' => true]);
}

// ============================================
// ACTION: Get Tracking (public — for customer tracking page)
// ============================================
function handleGetTracking() {
    $ref = $_POST['ref'] ?? '';
    if (empty($ref)) { echo json_encode(['success' => false]); return; }

    // Find the order to get the courier assignment
    $order = courier_loadOrder($ref);
    if (!$order) { echo json_encode(['success' => false]); return; }

    $dispatch = $order['dispatch'] ?? [];
    $courierPin = $dispatch['courier_pin'] ?? $dispatch['courier_id'] ?? '';
    $status = $dispatch['status'] ?? ($order['status'] ?? 'unknown');

    // Look up courier's last known location
    $file = dirname(__DIR__) . '/data/courier-locations.json';
    $locations = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $loc = $locations[$courierPin] ?? null;

    if (!$loc) { echo json_encode(['success' => true, 'status' => $status, 'lat' => null, 'lng' => null]); return; }

    // Check if location is stale (>5 min)
    $updatedAt = strtotime($loc['updated_at'] ?? '');
    $stale = $updatedAt && (time() - $updatedAt > 300);

    // Calculate ETA from courier's position to destination
    $etaMin = null;
    $etaDistKm = null;
    if (!$stale && $loc['lat'] && $loc['lng']) {
        // Get destination coordinates
        $dest = dispatch_getDestination($order);
        $destAddr = $dest['address'] ?? '';
        if ($destAddr) {
            // Check for cached ETA (avoid excessive API calls — cache for 60s)
            $etaCacheFile = dirname(__DIR__) . '/data/eta-cache.json';
            $etaCache = file_exists($etaCacheFile) ? json_decode(file_get_contents($etaCacheFile), true) : [];
            $etaCacheKey = $ref . '_' . round($loc['lat'], 3) . '_' . round($loc['lng'], 3);
            $cached = $etaCache[$etaCacheKey] ?? null;

            if ($cached && (time() - ($cached['ts'] ?? 0)) < 60) {
                $etaMin = $cached['eta_min'];
                $etaDistKm = $cached['distance_km'];
            } else {
                require_once __DIR__ . '/routes-api.php';
                $routes = new RoutesAPI();
                $origin = ['lat' => $loc['lat'], 'lng' => $loc['lng']];
                $destCoords = $routes->geocodeAddress($destAddr);
                if ($destCoords) {
                    $result = $routes->getDistance($origin, $destCoords);
                    if ($result) {
                        $etaMin = $result['duration_min'];
                        $etaDistKm = $result['distance_km'];
                        // Cache result
                        $etaCache[$etaCacheKey] = [
                            'eta_min' => $etaMin,
                            'distance_km' => $etaDistKm,
                            'ts' => time()
                        ];
                        // Keep cache small — only last 50 entries
                        if (count($etaCache) > 50) {
                            $etaCache = array_slice($etaCache, -50, null, true);
                        }
                        file_put_contents($etaCacheFile, json_encode($etaCache, JSON_PRETTY_PRINT), LOCK_EX);
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'lat' => $loc['lat'],
        'lng' => $loc['lng'],
        'courier_name' => $loc['name'] ?? '',
        'updated_at' => $loc['updated_at'] ?? '',
        'stale' => $stale,
        'eta_min' => $etaMin,
        'distance_km' => $etaDistKm
    ]);
}
