<?php
/**
 * Batch Order Functions (Shared)
 * MTCC Print Services — Dispatch System
 * 
 * Shared batch logic used by BOTH the Dispatch Hub (admin) and the Courier App.
 * Included by dispatch-functions.php via require_once.
 * 
 * Server path: /dispatch/batch-functions.php
 * 
 * Dependencies:
 *   - dispatch-functions.php (loaded first — provides DISPATCH_* constants and helpers)
 *   - routes-api.php (optional — for Google Routes API geocoding/routing)
 * 
 * Provides:
 *   CRUD:       batch_loadAll, batch_saveAll, batch_find, batch_findByOrderRef
 *   Creation:   batch_create, batch_buildStops, batch_nextId
 *   Routes:     batch_calculateRoute, batch_estimateRoute, batch_haversine, batch_buildNavUrls
 *   Payout:     batch_calculatePayout
 *   Urgency:    batch_getUrgency
 *   Lifecycle:  batch_accept, batch_updateStop, batch_disband, batch_recalculateRoute
 *   Queries:    batch_getPending, batch_getForCourier, batch_getActive
 *   Formatting: batch_formatForApp, batch_formatOrder
 *   Detection:  batch_lightweightCheck, batch_autoDetect, batch_findProximityGroups
 */

// Maximum orders per batch
if (!defined('BATCH_MAX_ORDERS')) {
    define('BATCH_MAX_ORDERS', 99);  // No practical limit
}

// Photos directory
if (!defined('BATCH_PHOTOS_DIR')) {
    define('BATCH_PHOTOS_DIR', dirname(__DIR__) . '/uploads/delivery-photos/');
}

// ============================================
// INTERNAL HELPERS
// Order load/save/status that work without courier API context
// These use DISPATCH_* constants from dispatch-functions.php
// ============================================

/**
 * Get status for a single order ref
 */
function batch_getOrderStatus($ref) {
    $statuses = dispatch_loadStatuses();
    return $statuses[$ref] ?? null;
}

/**
 * Set status for a single order ref.
 * Uses cascadeStatusTo() for forward lifecycle moves (auto-fills skipped steps).
 * Falls back to direct write for reversions (e.g., dispatched → ready on release).
 */
function batch_setOrderStatus($ref, $newStatus) {
    if (function_exists('cascadeStatusTo')) {
        $result = cascadeStatusTo(
            $ref, $newStatus, 'Dispatch (batch)',
            'Batch operation',
            DISPATCH_STATUSES_FILE,
            DISPATCH_ORDERS_DIR
        );
        if ($result['success']) return;
    }
    // Fallback: direct write (for reversions or if data-access.php not loaded)
    $statuses = dispatch_loadStatuses();
    $oldStatus = $statuses[$ref] ?? 'unknown';
    $statuses[$ref] = $newStatus;
    file_put_contents(DISPATCH_STATUSES_FILE, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
    // Also sync order file
    if (function_exists('findOrderByReference')) {
        $orderInfo = findOrderByReference($ref, DISPATCH_ORDERS_DIR);
        if ($orderInfo) {
            $orderInfo['data']['status'] = $newStatus;
            file_put_contents($orderInfo['filepath'], json_encode($orderInfo['data'], JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
    if (function_exists('logOrderHistory')) {
        logOrderHistory($ref, 'status_change', "Batch status: {$oldStatus} to {$newStatus}", 'Dispatch (batch)');
    }
}

/**
 * Save order data back to its JSON file
 */
function batch_saveOrder($ref, $orderData) {
    $dir = DISPATCH_ORDERS_DIR;
    
    // Try direct filename
    $file = $dir . $ref . '.json';
    if (file_exists($file)) {
        file_put_contents($file, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return true;
    }
    
    // Scan for matching file
    if (is_dir($dir)) {
        $files = glob($dir . '*.json');
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $ref) {
                file_put_contents($f, json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                return true;
            }
        }
    }
    return false;
}

/**
 * Log batch activity to dispatch log
 */
function batch_logActivity($ref, $fromStatus, $toStatus, $userName, $photo = null) {
    $logFile = __DIR__ . '/dispatch-log.json';
    $logData = ['entries' => []];
    if (file_exists($logFile)) {
        $content = file_get_contents($logFile);
        $logData = json_decode($content, true) ?: ['entries' => []];
    }
    
    $logData['entries'][] = [
        'timestamp' => date('Y-m-d H:i:s'),
        'referenceCode' => $ref,
        'fromStatus' => $fromStatus,
        'toStatus' => $toStatus,
        'userName' => $userName,
        'source' => 'batch',
        'photo' => $photo,
    ];
    
    // Keep last 500 entries
    if (count($logData['entries']) > 500) {
        $logData['entries'] = array_slice($logData['entries'], -500);
    }
    
    file_put_contents($logFile, json_encode($logData, JSON_PRETTY_PRINT), LOCK_EX);
}


// ============================================
// BATCH DATA CRUD
// ============================================

/**
 * Load all batch data from batches.json
 * Compatible with Hub's existing schema: {active:[], completed:[], metadata:{}}
 */
function batch_loadAll() {
    if (!file_exists(DISPATCH_BATCHES_FILE)) {
        return batch_emptyData();
    }
    $raw = file_get_contents(DISPATCH_BATCHES_FILE);
    $data = json_decode($raw, true);
    if (!$data || !isset($data['active'])) {
        return batch_emptyData();
    }
    return $data;
}

/**
 * Save all batch data with file locking
 */
function batch_saveAll($data) {
    $data['metadata']['last_updated'] = date('c');
    file_put_contents(DISPATCH_BATCHES_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Empty batch data structure
 */
function batch_emptyData() {
    return [
        'active' => [],
        'completed' => [],
        'metadata' => [
            'last_batch_number' => 0,
            'last_updated' => null,
            'version' => '2.0'
        ]
    ];
}

/**
 * Generate next batch ID: B-001, B-002, etc.
 */
function batch_nextId(&$data) {
    $num = ($data['metadata']['last_batch_number'] ?? 0) + 1;
    $data['metadata']['last_batch_number'] = $num;
    return 'B-' . str_pad($num, 3, '0', STR_PAD_LEFT);
}

/**
 * Find a batch by ID in active or completed
 * Returns ['batch' => ..., 'index' => ..., 'list' => 'active'|'completed'] or null
 */
function batch_find($batchId) {
    $data = batch_loadAll();
    foreach ($data['active'] as $i => $b) {
        if ($b['batch_id'] === $batchId) return ['batch' => $b, 'index' => $i, 'list' => 'active'];
    }
    foreach ($data['completed'] as $i => $b) {
        if ($b['batch_id'] === $batchId) return ['batch' => $b, 'index' => $i, 'list' => 'completed'];
    }
    return null;
}

/**
 * Find a batch containing a specific order ref
 */
function batch_findByOrderRef($ref) {
    $data = batch_loadAll();
    foreach ($data['active'] as $i => $b) {
        $refs = $b['order_refs'] ?? $b['orders'] ?? [];
        // Handle both formats: plain refs array or objects array
        if (is_array($refs)) {
            foreach ($refs as $item) {
                $r = is_array($item) ? ($item['ref'] ?? '') : $item;
                if ($r === $ref) return ['batch' => $b, 'index' => $i, 'list' => 'active'];
            }
        }
    }
    return null;
}


// ============================================
// QUERY FUNCTIONS
// ============================================

/**
 * Get all pending/suggested batches (available for courier acceptance)
 */
function batch_getPending() {
    $data = batch_loadAll();
    $pending = [];
    foreach ($data['active'] as $batch) {
        if (in_array($batch['status'], ['pending', 'suggested'])) {
            $pending[] = $batch;
        }
    }
    return $pending;
}

/**
 * Get all active batches (accepted or in_progress)
 */
function batch_getActive() {
    $data = batch_loadAll();
    $active = [];
    foreach ($data['active'] as $batch) {
        if (in_array($batch['status'], ['accepted', 'in_progress', 'dispatched'])) {
            $active[] = $batch;
        }
    }
    return $active;
}

/**
 * Get batches assigned to a specific courier (by PIN)
 */
function batch_getForCourier($courierPin) {
    $data = batch_loadAll();
    $result = [];
    foreach ($data['active'] as $batch) {
        $pin = $batch['courier']['pin'] ?? $batch['courier_id'] ?? '';
        if ($pin === $courierPin) $result[] = $batch;
    }
    // Also check completed (for today's summary)
    foreach ($data['completed'] as $batch) {
        $pin = $batch['courier']['pin'] ?? $batch['courier_id'] ?? '';
        if ($pin === $courierPin) $result[] = $batch;
    }
    return $result;
}


// ============================================
// BATCH CREATION
// ============================================

/**
 * Create a new batch from selected order refs.
 * Builds stops, calculates route and payout, tags orders.
 * 
 * @param array  $orderRefs    Order reference codes
 * @param string $createdBy    Who created ('admin' or courier name)
 * @param bool   $autoSuggested  Whether auto-detected
 * @param string $courierId    Optional courier PIN to assign immediately
 * @param string $courierName  Optional courier display name
 * @param string $notes        Optional batch notes
 * @return array Result with batch_id and batch data
 */
function batch_create($orderRefs, $createdBy = 'admin', $autoSuggested = false, $courierId = '', $courierName = '', $notes = '') {
    if (count($orderRefs) < 2) {
        return ['success' => false, 'error' => 'Batch requires at least 2 orders'];
    }
    if (count($orderRefs) > BATCH_MAX_ORDERS) {
        return ['success' => false, 'error' => 'Maximum ' . BATCH_MAX_ORDERS . ' orders per batch'];
    }
    
    // Verify all orders exist and are in 'ready' status
    $orders = [];
    $formattedOrders = [];
    foreach ($orderRefs as $ref) {
        // Check not already in a batch
        $existing = batch_findByOrderRef($ref);
        if ($existing) {
            return ['success' => false, 'error' => 'Order ' . $ref . ' is already in batch ' . $existing['batch']['batch_id']];
        }
        
        $order = dispatch_loadOrder($ref);
        if (!$order) {
            return ['success' => false, 'error' => 'Order not found: ' . $ref];
        }
        $currentStatus = batch_getOrderStatus($ref);
        if ($currentStatus !== 'ready') {
            return ['success' => false, 'error' => 'Order ' . $ref . ' is not ready (status: ' . ($currentStatus ?: 'unknown') . ')'];
        }
        // Check not already assigned to a courier
        $dispatch = $order['dispatch'] ?? [];
        if (!empty($dispatch['courier_pin']) || !empty($dispatch['courier_id'])) {
            return ['success' => false, 'error' => 'Order ' . $ref . ' is already assigned to a courier'];
        }
        
        $orders[$ref] = $order;
        $formattedOrders[$ref] = batch_formatOrder($order, $ref);
    }
    
    // Build stops array
    $stops = batch_buildStops($formattedOrders);
    
    // Calculate route via Routes API (with fallback)
    $route = batch_calculateRoute($stops);
    
    // Calculate payout
    $payout = batch_calculatePayout($stops);
    
    // Get urgency from the most urgent order
    $urgency = batch_getUrgency($formattedOrders);
    
    // Gather order details for the batch record (Hub-compatible)
    $orderDetails = [];
    $destinations = [];
    foreach ($formattedOrders as $ref => $fmt) {
        $orderDetails[] = [
            'ref' => $ref,
            'customer_name' => $fmt['customer_name'] ?? '',
            'destination' => $fmt['destination'] ?? '',
            'destination_type' => $fmt['destination_type'] ?? '',
            'due_date' => $fmt['due_date_formatted'] ?? '',
            'due_time' => $fmt['due_time_formatted'] ?? '',
        ];
        
        $destKey = $fmt['destination'] ?? '';
        if (!isset($destinations[$destKey])) {
            $destinations[$destKey] = [
                'label' => $fmt['destination'] ?? '',
                'type' => $fmt['destination_type'] ?? '',
                'address' => $fmt['destination_address'] ?? '',
                'count' => 0,
            ];
        }
        $destinations[$destKey]['count']++;
    }
    
    // Determine initial status
    $status = !empty($courierId) ? 'dispatched' : 'pending';
    if ($autoSuggested && empty($courierId)) $status = 'suggested';
    
    // Create batch record
    $data = batch_loadAll();
    $batchId = batch_nextId($data);
    
    $batch = [
        'batch_id' => $batchId,
        'created_at' => date('c'),
        'created_by' => $createdBy,
        'auto_suggested' => $autoSuggested,
        'status' => $status,
        // Order refs (plain array — Hub compatible)
        'order_refs' => $orderRefs,
        // Order details (Hub compatible)
        'orders' => $orderDetails,
        'order_count' => count($orderDetails),
        'destinations' => array_values($destinations),
        // Rich data (new)
        'stops' => $stops,
        'route' => $route,
        'payout' => $payout,
        'urgency' => $urgency,
        'current_stop_index' => 0,
        'event_acronym' => $formattedOrders[array_key_first($formattedOrders)]['event_acronym'] ?? '',
        // Courier assignment
        'courier' => !empty($courierId) ? [
            'pin' => $courierId,
            'name' => $courierName,
            'assigned_at' => date('c'),
        ] : null,
        'courier_id' => $courierId,
        'courier_name' => $courierName,
        'notes' => $notes,
        // Timestamps
        'dispatched_at' => !empty($courierId) ? date('c') : null,
        'completed_at' => null,
    ];
    
    $data['active'][] = $batch;
    batch_saveAll($data);
    
    // Tag each order with batch_id + optional courier
    foreach ($orderRefs as $ref) {
        $order = $orders[$ref];
        $order['dispatch'] = array_merge($order['dispatch'] ?? [], [
            'batch_id' => $batchId,
        ]);
        
        if (!empty($courierId)) {
            $order['dispatch']['courier_type'] = 'internal';
            $order['dispatch']['courier_id'] = $courierId;
            $order['dispatch']['courier_pin'] = $courierId;
            $order['dispatch']['courier_name'] = $courierName;
            $order['dispatch']['dispatched_at'] = date('c');
            $order['dispatch']['dispatched_by'] = $courierName ?: 'admin';
            batch_setOrderStatus($ref, 'dispatched');
        }
        
        batch_saveOrder($ref, $order);
    }
    
    return ['success' => true, 'batch_id' => $batchId, 'order_count' => count($orderDetails), 'batch' => $batch];
}


// ============================================
// STOPS BUILDING
// ============================================

/**
 * Build stops array from formatted orders.
 * Groups pickups by vendor, dropoffs by destination address.
 * Returns ordered array: all pickups first, then all dropoffs.
 */
function batch_buildStops($formattedOrders) {
    $pickups = [];   // keyed by vendor_name
    $dropoffs = [];  // keyed by destination_address
    
    foreach ($formattedOrders as $ref => $order) {
        $vendorKey = $order['vendor_name'] ?? 'Unknown';
        $vendorAddr = $order['vendor_address'] ?? '';
        
        // Group pickups by vendor
        if (!isset($pickups[$vendorKey])) {
            $pickups[$vendorKey] = [
                'type' => 'pickup',
                'name' => $vendorKey,
                'address' => $vendorAddr,
                'vendor_phone' => $order['vendor_phone'] ?? '',
                'order_refs' => [],
                'order_details' => [],
                'coords' => null,
                'status' => 'pending'
            ];
        }
        $pickups[$vendorKey]['order_refs'][] = $ref;
        $pickups[$vendorKey]['order_details'][] = [
            'ref' => $ref,
            'customer_name' => $order['customer_name'] ?? '',
            'material' => $order['material'] ?? '',
            'size' => $order['size'] ?? '',
            'quantity' => $order['quantity'] ?? 1,
        ];
        
        // Group dropoffs by destination address
        $destAddr = $order['destination_address'] ?? '';
        $destName = $order['destination'] ?? '';
        $destKey = !empty($destAddr) ? $destAddr : $destName;
        
        if (!isset($dropoffs[$destKey])) {
            $dropoffs[$destKey] = [
                'type' => 'dropoff',
                'name' => $destName,
                'address' => $destAddr,
                'destination_instructions' => $order['destination_instructions'] ?? '',
                'customer_phone' => $order['customer_phone'] ?? '',
                'order_refs' => [],
                'order_details' => [],
                'coords' => null,
                'status' => 'pending'
            ];
        }
        $dropoffs[$destKey]['order_refs'][] = $ref;
        $dropoffs[$destKey]['order_details'][] = [
            'ref' => $ref,
            'customer_name' => $order['customer_name'] ?? '',
            'material' => $order['material'] ?? '',
            'size' => $order['size'] ?? '',
            'quantity' => $order['quantity'] ?? 1,
        ];
    }
    
    // Geocode all stops (optional — requires routes-api.php)
    $routesApiFile = __DIR__ . '/../courier/routes-api.php';
    if (file_exists($routesApiFile)) {
        if (!class_exists('RoutesAPI')) {
            require_once $routesApiFile;
        }
        if (class_exists('RoutesAPI')) {
            $routes = new RoutesAPI();
            foreach ($pickups as &$stop) {
                if (!empty($stop['address'])) {
                    $stop['coords'] = $routes->geocodeAddress($stop['address']);
                }
            }
            unset($stop);
            foreach ($dropoffs as &$stop) {
                if (!empty($stop['address'])) {
                    $stop['coords'] = $routes->geocodeAddress($stop['address']);
                }
            }
            unset($stop);
        }
    }
    
    // Combine: all pickups first, then all dropoffs
    return array_merge(array_values($pickups), array_values($dropoffs));
}


// ============================================
// ROUTE CALCULATION
// ============================================

/**
 * Calculate optimized route for batch stops using Google Routes API.
 * Falls back to haversine estimation if API unavailable.
 */
function batch_calculateRoute($stops, $courierCoords = null) {
    if (count($stops) < 2) return null;
    
    $coordsList = [];
    foreach ($stops as $stop) {
        if (!empty($stop['coords'])) {
            $coordsList[] = $stop['coords'];
        } else {
            return batch_estimateRoute($coordsList ?: null);
        }
    }
    
    // Use Routes API if available
    $routesApiFile = __DIR__ . '/../courier/routes-api.php';
    if (file_exists($routesApiFile)) {
        if (!class_exists('RoutesAPI')) {
            require_once $routesApiFile;
        }
        if (class_exists('RoutesAPI')) {
            $routes = new RoutesAPI();
            
            if ($courierCoords) {
                $origin = $courierCoords;
                $destination = $coordsList[count($coordsList) - 1];
                $waypoints = array_slice($coordsList, 0, count($coordsList) - 1);
            } else {
                $origin = $coordsList[0];
                $destination = $coordsList[count($coordsList) - 1];
                $waypoints = array_slice($coordsList, 1, count($coordsList) - 2);
            }
            
            $routeResult = $routes->calculateRoute($origin, $destination, $waypoints, true);
            if ($routeResult) {
                $routeResult['calculated_at'] = date('c');
                return $routeResult;
            }
        }
    }
    
    // Fallback: haversine estimate
    return batch_estimateRoute($coordsList);
}

/**
 * Fallback route estimation using haversine distance
 */
function batch_estimateRoute($coordsList) {
    if (!$coordsList || count($coordsList) < 2) {
        return ['distance_km' => 0, 'duration_min' => 0, 'estimated' => true, 'calculated_at' => date('c')];
    }
    
    $totalKm = 0;
    $legs = [];
    for ($i = 0; $i < count($coordsList) - 1; $i++) {
        $dist = batch_haversine(
            $coordsList[$i]['lat'], $coordsList[$i]['lng'],
            $coordsList[$i + 1]['lat'], $coordsList[$i + 1]['lng']
        );
        $totalKm += $dist;
        $legs[] = [
            'distance_km' => round($dist, 1),
            'duration_min' => round($dist / 30 * 60)
        ];
    }
    
    return [
        'distance_km' => round($totalKm, 1),
        'duration_min' => round($totalKm / 30 * 60),
        'polyline' => null,
        'optimized_order' => null,
        'legs' => $legs,
        'calculated_at' => date('c'),
        'estimated' => true
    ];
}

/**
 * Haversine distance between two lat/lng points in km
 */
function batch_haversine($lat1, $lng1, $lat2, $lng2) {
    $R = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLng / 2) * sin($dLng / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}


// ============================================
// PAYOUT CALCULATION
// ============================================

/**
 * Calculate courier payout for a batch delivery.
 * Uses dispatch-settings.json pricing.
 */
function batch_calculatePayout($stops) {
    $settings = dispatch_loadSettings();
    $pricing = $settings['pricing'] ?? [];
    
    $base = $pricing['base_rate'] ?? 30;
    $additionalStopRate = $pricing['modifiers']['additional_stop'] ?? 10;
    $weatherBonus = $pricing['modifiers']['bad_weather'] ?? 5;
    
    $total = $base;
    $breakdown = [['label' => 'Base rate', 'amount' => $base]];
    
    // Additional stops beyond the base 2 (1 pickup + 1 dropoff)
    $totalStops = count($stops);
    $extraStops = max(0, $totalStops - 2);
    if ($extraStops > 0) {
        $extraAmount = $extraStops * $additionalStopRate;
        $total += $extraAmount;
        $breakdown[] = ['label' => $extraStops . ' extra stop' . ($extraStops > 1 ? 's' : ''), 'amount' => $extraAmount];
    }
    
    // Weather bonus
    if (!empty($settings['weather']['bad_weather_active'])) {
        $total += $weatherBonus;
        $breakdown[] = ['label' => 'Weather', 'amount' => $weatherBonus];
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
            $label = ucfirst(str_replace('_', ' ', $name));
            $total += $amt;
            $breakdown[] = ['label' => $label, 'amount' => $amt];
            break;
        }
    }
    
    return [
        'total' => round($total, 2),
        'breakdown' => $breakdown
    ];
}


// ============================================
// URGENCY
// ============================================

/**
 * Get the most urgent urgency level from a set of formatted orders
 */
function batch_getUrgency($formattedOrders) {
    $worst = 'normal';
    $minHours = null;
    $dueFormatted = '';
    $dueTimeFormatted = '';
    
    foreach ($formattedOrders as $order) {
        $hrs = $order['hours_remaining'] ?? null;
        $urg = $order['urgency'] ?? 'normal';
        
        if ($urg === 'red') {
            $worst = 'red';
        } elseif ($urg === 'orange' && $worst !== 'red') {
            $worst = 'orange';
        }
        
        if ($hrs !== null && ($minHours === null || $hrs < $minHours)) {
            $minHours = $hrs;
            $dueFormatted = $order['due_date_formatted'] ?? '';
            $dueTimeFormatted = $order['due_time_formatted'] ?? '';
        }
    }
    
    return [
        'level' => $worst,
        'hours_remaining' => $minHours,
        'due_date_formatted' => $dueFormatted,
        'due_time_formatted' => $dueTimeFormatted
    ];
}


// ============================================
// BATCH ACCEPTANCE
// ============================================

/**
 * Courier accepts an entire batch.
 * Sets all orders to 'dispatched' and assigns courier.
 */
function batch_accept($batchId, $user) {
    $data = batch_loadAll();
    $batchIdx = null;
    $batch = null;
    
    foreach ($data['active'] as $i => $b) {
        if ($b['batch_id'] === $batchId) {
            $batch = $b;
            $batchIdx = $i;
            break;
        }
    }
    
    if (!$batch) {
        return ['success' => false, 'error' => 'Batch not found: ' . $batchId];
    }
    if (!in_array($batch['status'], ['pending', 'suggested'])) {
        return ['success' => false, 'error' => 'Batch is not available (status: ' . $batch['status'] . ')'];
    }
    
    // Get order refs (handle both formats)
    $refs = $batch['order_refs'] ?? [];
    if (empty($refs) && !empty($batch['orders'])) {
        foreach ($batch['orders'] as $o) {
            $refs[] = is_array($o) ? ($o['ref'] ?? '') : $o;
        }
    }
    
    // Verify all orders still in 'ready' status
    foreach ($refs as $ref) {
        $status = batch_getOrderStatus($ref);
        if ($status !== 'ready') {
            return ['success' => false, 'error' => 'Order ' . $ref . ' is no longer available (status: ' . $status . ')'];
        }
    }
    
    // Assign courier to batch
    $batch['status'] = 'accepted';
    $batch['courier'] = [
        'pin' => $user['pin'],
        'name' => $user['name'],
        'accepted_at' => date('c')
    ];
    $batch['courier_id'] = $user['pin'];
    $batch['courier_name'] = $user['name'];
    $batch['dispatched_at'] = date('c');
    
    // Update each order: status → dispatched, assign courier
    foreach ($refs as $ref) {
        batch_setOrderStatus($ref, 'dispatched');
        
        $order = dispatch_loadOrder($ref);
        if ($order) {
            $order['dispatch'] = array_merge($order['dispatch'] ?? [], [
                'batch_id' => $batchId,
                'courier_type' => 'internal',
                'courier_id' => $user['pin'],
                'courier_pin' => $user['pin'],
                'courier_name' => $user['name'],
                'dispatched_at' => date('c'),
                'dispatched_by' => $user['name'] . ' (batch self-assigned)',
            ]);
            batch_saveOrder($ref, $order);
        }
        
        batch_logActivity($ref, 'ready', 'dispatched', $user['name']);
    }
    
    $data['active'][$batchIdx] = $batch;
    batch_saveAll($data);
    
    // Notify
    if (function_exists('dispatch_notifyBatchDispatched')) {
        dispatch_notifyBatchDispatched($batchId, $user['name']);
    }
    
    return ['success' => true, 'batch' => $batch, 'order_count' => count($refs)];
}


// ============================================
// STOP PROGRESSION
// ============================================

/**
 * Update a single stop within a batch.
 * Handles status transitions and order status sync.
 */
function batch_updateStop($batchId, $stopIndex, $newStatus, $user, $photoData = '') {
    $data = batch_loadAll();
    $batchIdx = null;
    $batch = null;
    
    foreach ($data['active'] as $i => $b) {
        if ($b['batch_id'] === $batchId) {
            $batch = $b;
            $batchIdx = $i;
            break;
        }
    }
    
    if (!$batch) {
        return ['success' => false, 'error' => 'Batch not found'];
    }
    if (!isset($batch['stops'][$stopIndex])) {
        return ['success' => false, 'error' => 'Invalid stop index'];
    }
    
    // Verify courier owns this batch
    $batchPin = $batch['courier']['pin'] ?? $batch['courier_id'] ?? '';
    if ($batchPin !== $user['pin']) {
        return ['success' => false, 'error' => 'This batch is not assigned to you'];
    }
    
    $stop = &$batch['stops'][$stopIndex];
    $stop['status'] = $newStatus;
    $stop['completed_at'] = date('c');
    $stop['completed_by'] = $user['name'];
    
    // Save delivery photo if provided
    $photoPath = '';
    if ($stop['type'] === 'dropoff' && !empty($photoData)) {
        $photoPath = batch_saveDeliveryPhoto($batchId, $stopIndex, $photoData);
        if ($photoPath) {
            $stop['delivery_photo'] = $photoPath;
        }
    }
    
    // Sync individual order statuses
    if ($newStatus === 'completed') {
        if ($stop['type'] === 'pickup') {
            // Check if ALL pickups are now completed
            $allPickupsComplete = true;
            foreach ($batch['stops'] as $s) {
                if ($s['type'] === 'pickup' && $s['status'] !== 'completed' && $s['status'] !== 'skipped') {
                    $allPickupsComplete = false;
                    break;
                }
            }
            
            if ($allPickupsComplete) {
                // All pickups done → all picked-up orders move to 'shipped'
                $allPickedRefs = [];
                foreach ($batch['stops'] as $s) {
                    if ($s['type'] === 'pickup' && $s['status'] === 'completed') {
                        $allPickedRefs = array_merge($allPickedRefs, $s['order_refs']);
                    }
                }
                
                foreach (array_unique($allPickedRefs) as $ref) {
                    $currentStatus = batch_getOrderStatus($ref);
                    if ($currentStatus === 'dispatched') {
                        batch_setOrderStatus($ref, 'shipped');
                        
                        $order = dispatch_loadOrder($ref);
                        if ($order) {
                            $order['dispatch']['shipped_at'] = date('c');
                            batch_saveOrder($ref, $order);
                        }
                        
                        batch_logActivity($ref, 'dispatched', 'shipped', $user['name']);
                        
                        if (function_exists('dispatch_notifyStatusChange')) {
                            dispatch_notifyStatusChange($ref, 'dispatched', 'shipped', $user['name']);
                        }
                    }
                }
                
                $batch['status'] = 'in_progress';
            }
        } elseif ($stop['type'] === 'dropoff') {
            // Delivery completed → those specific orders move to 'delivered'
            foreach ($stop['order_refs'] as $ref) {
                $currentStatus = batch_getOrderStatus($ref);
                if (in_array($currentStatus, ['dispatched', 'shipped'])) {
                    batch_setOrderStatus($ref, 'delivered');
                    
                    $order = dispatch_loadOrder($ref);
                    if ($order) {
                        $order['dispatch']['delivered_at'] = date('c');
                        if ($photoPath) {
                            $order['dispatch']['delivery_photo'] = $photoPath;
                        }
                        batch_saveOrder($ref, $order);
                    }
                    
                    batch_logActivity($ref, $currentStatus, 'delivered', $user['name'], $photoPath ?: null);
                    
                    if (function_exists('dispatch_notifyStatusChange')) {
                        dispatch_notifyStatusChange($ref, $currentStatus, 'delivered', $user['name']);
                    }
                }
            }
        }
    } elseif ($newStatus === 'skipped') {
        if ($stop['type'] === 'pickup') {
            foreach ($stop['order_refs'] as $ref) {
                batch_logActivity($ref, 'dispatched', 'skip_pickup', $user['name']);
            }
        }
    }
    
    // Advance current stop index to next pending stop
    $nextPending = null;
    for ($i = 0; $i < count($batch['stops']); $i++) {
        if ($batch['stops'][$i]['status'] === 'pending') {
            $nextPending = $i;
            break;
        }
    }
    $batch['current_stop_index'] = $nextPending !== null ? $nextPending : count($batch['stops']);
    
    // Check if batch is fully completed
    $allDone = true;
    foreach ($batch['stops'] as $s) {
        if ($s['status'] === 'pending') {
            $allDone = false;
            break;
        }
    }
    
    if ($allDone) {
        $batch['status'] = 'completed';
        $batch['completed_at'] = date('c');
        
        // Move to completed array
        $completed = $batch;
        unset($data['active'][$batchIdx]);
        $data['active'] = array_values($data['active']);
        $data['completed'][] = $completed;
    } else {
        $data['active'][$batchIdx] = $batch;
    }
    
    batch_saveAll($data);
    
    return [
        'success' => true,
        'batch' => $batch,
        'all_complete' => $allDone,
        'next_stop_index' => $batch['current_stop_index']
    ];
}

/**
 * Save a delivery photo for a batch stop
 */
function batch_saveDeliveryPhoto($batchId, $stopIndex, $base64Data) {
    $photosDir = BATCH_PHOTOS_DIR;
    if (!is_dir($photosDir)) {
        @mkdir($photosDir, 0755, true);
    }
    
    if (strpos($base64Data, 'base64,') !== false) {
        $base64Data = substr($base64Data, strpos($base64Data, 'base64,') + 7);
    }
    
    $imageData = base64_decode($base64Data);
    if (!$imageData) return '';
    
    $filename = strtolower($batchId) . '-stop' . $stopIndex . '-' . date('His') . '.jpg';
    $filepath = $photosDir . $filename;
    
    if (file_put_contents($filepath, $imageData, LOCK_EX)) {
        return 'uploads/delivery-photos/' . $filename;
    }
    return '';
}


// ============================================
// DISBAND BATCH
// ============================================

/**
 * Disband a batch — return orders to individual status.
 * Only works for pending/suggested/accepted batches.
 */
function batch_disband($batchId, $disbandedBy = 'admin') {
    $data = batch_loadAll();
    $batchIdx = null;
    $batch = null;
    
    foreach ($data['active'] as $i => $b) {
        if ($b['batch_id'] === $batchId) {
            $batch = $b;
            $batchIdx = $i;
            break;
        }
    }
    
    if (!$batch) {
        return ['success' => false, 'error' => 'Batch not found'];
    }
    
    if ($batch['status'] === 'in_progress') {
        return ['success' => false, 'error' => 'Cannot disband an in-progress batch'];
    }
    
    // Get order refs (handle both formats)
    $refs = $batch['order_refs'] ?? [];
    if (empty($refs) && !empty($batch['orders'])) {
        foreach ($batch['orders'] as $o) {
            $refs[] = is_array($o) ? ($o['ref'] ?? '') : $o;
        }
    }
    
    // Remove batch_id from each order
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if ($order && isset($order['dispatch']['batch_id'])) {
            unset($order['dispatch']['batch_id']);
            
            // If batch was accepted (courier assigned), revert to ready
            if (in_array($batch['status'], ['accepted', 'dispatched'])) {
                batch_setOrderStatus($ref, 'ready');
                unset($order['dispatch']['courier_pin']);
                unset($order['dispatch']['courier_id']);
                unset($order['dispatch']['courier_name']);
                unset($order['dispatch']['dispatched_at']);
                unset($order['dispatch']['dispatched_by']);
            }
            
            batch_saveOrder($ref, $order);
        }
    }
    
    // Archive as cancelled
    $batch['status'] = 'cancelled';
    $batch['disbanded_at'] = date('c');
    $batch['disbanded_by'] = $disbandedBy;
    
    unset($data['active'][$batchIdx]);
    $data['active'] = array_values($data['active']);
    $data['completed'][] = $batch;
    
    batch_saveAll($data);
    
    return ['success' => true, 'message' => 'Batch disbanded'];
}


// ============================================
// ROUTE RECALCULATION
// ============================================

/**
 * Recalculate route from courier's current GPS position.
 */
function batch_recalculateRoute($batchId, $courierLat, $courierLng) {
    $found = batch_find($batchId);
    if (!$found) {
        return ['success' => false, 'error' => 'Batch not found'];
    }
    $batch = $found['batch'];
    
    $remainingStops = [];
    foreach ($batch['stops'] as $stop) {
        if ($stop['status'] === 'pending' && !empty($stop['coords'])) {
            $remainingStops[] = $stop;
        }
    }
    
    if (count($remainingStops) < 1) {
        return ['success' => false, 'error' => 'No remaining stops'];
    }
    
    $courierCoords = ['lat' => $courierLat, 'lng' => $courierLng];
    $route = batch_calculateRoute($remainingStops, $courierCoords);
    $navUrls = batch_buildNavUrls($remainingStops, $courierCoords);
    
    return [
        'success' => true,
        'route' => $route,
        'remaining_stops' => count($remainingStops),
        'google_nav_url' => $navUrls['google'],
        'apple_nav_url' => $navUrls['apple']
    ];
}


// ============================================
// NAVIGATION URLS
// ============================================

/**
 * Build multi-stop navigation URLs for Google Maps and Apple Maps.
 */
function batch_buildNavUrls($stops, $courierCoords = null) {
    $coords = [];
    foreach ($stops as $stop) {
        if (!empty($stop['coords'])) {
            $coords[] = $stop['coords']['lat'] . ',' . $stop['coords']['lng'];
        }
    }
    
    if (empty($coords)) return ['google' => '', 'apple' => ''];
    
    $origin = $courierCoords ? ($courierCoords['lat'] . ',' . $courierCoords['lng']) : $coords[0];
    $destination = $coords[count($coords) - 1];
    $waypoints = count($coords) > 1 ? array_slice($coords, 0, count($coords) - 1) : [];
    
    $googleUrl = 'https://www.google.com/maps/dir/?api=1'
        . '&origin=' . urlencode($origin)
        . '&destination=' . urlencode($destination)
        . '&travelmode=driving';
    if (!empty($waypoints)) {
        $googleUrl .= '&waypoints=' . urlencode(implode('|', $waypoints));
    }
    
    $appleUrl = 'https://maps.apple.com/?saddr=' . urlencode($origin) . '&dirflg=d';
    if (count($coords) === 1) {
        $appleUrl .= '&daddr=' . urlencode($coords[0]);
    } else {
        $appleUrl .= '&daddr=' . urlencode(implode('+to:', $coords));
    }
    
    return ['google' => $googleUrl, 'apple' => $appleUrl];
}


// ============================================
// FORMAT FOR APP (courier & hub)
// ============================================

/**
 * Format a single order for batch context using dispatch_* helpers.
 * Used internally — does NOT depend on courier API's formatOrderForApp().
 */
function batch_formatOrder($orderData, $ref) {
    $dest = dispatch_getDestination($orderData);
    $dueInfo = dispatch_getDueInfo($orderData);
    $vendorInfo = dispatch_getVendorInfo($ref);
    $status = batch_getOrderStatus($ref);
    
    $vendorName = 'Unknown Vendor';
    $vendorAddress = '';
    $vendorPhone = '';
    if ($vendorInfo) {
        $vendorName = $vendorInfo['vendor_name'] ?? 'Unknown Vendor';
        // Try to get vendor address from vendors.json
        if (!empty($vendorInfo['vendor_id'])) {
            $vendorsFile = dirname(__DIR__) . '/data/vendors.json';
            if (file_exists($vendorsFile)) {
                $vendorsData = json_decode(file_get_contents($vendorsFile), true);
                $vendorsList = $vendorsData['vendors'] ?? $vendorsData;
                if (is_array($vendorsList)) {
                    foreach ($vendorsList as $v) {
                        if (($v['id'] ?? '') === $vendorInfo['vendor_id']) {
                            $vendorAddress = $v['address'] ?? '';
                            $vendorPhone = $v['phone'] ?? '';
                            break;
                        }
                    }
                }
            }
        }
    }
    // Override from dispatch_summary if available
    if (isset($orderData['dispatch_summary']['vendor_name'])) {
        $vendorName = $orderData['dispatch_summary']['vendor_name'];
    }
    
    $hoursRemaining = $dueInfo['hours_remaining'] ?? null;
    $urgencyLevel = 'normal';
    if ($hoursRemaining !== null && $hoursRemaining > 0) {
        if ($hoursRemaining <= 2) $urgencyLevel = 'red';
        elseif ($hoursRemaining <= 4) $urgencyLevel = 'orange';
    }
    
    $customerInfo = $orderData['customerInfo'] ?? [];
    $dimensions = $orderData['dimensions'] ?? [];
    $event = $orderData['event'] ?? [];
    
    return [
        'ref' => $ref,
        'status' => $status,
        'customer_name' => $customerInfo['name'] ?? '',
        'customer_phone' => $customerInfo['phone'] ?? '',
        'material' => $orderData['material'] ?? '',
        'size' => ($dimensions['width'] ?? '') . '" x ' . ($dimensions['height'] ?? '') . '"',
        'quantity' => $orderData['quantity'] ?? 1,
        'event' => $event['name'] ?? '',
        'event_acronym' => $event['acronym'] ?? '',
        // Destination
        'destination' => $dest['label'] ?? '',
        'destination_type' => $dest['type'] ?? '',
        'destination_address' => $dest['address'] ?? '',
        'destination_instructions' => $dest['instructions'] ?? '',
        // Vendor / Pickup
        'vendor_name' => $vendorName,
        'vendor_address' => $vendorAddress,
        'vendor_phone' => $vendorPhone,
        // Due info
        'due_date' => $orderData['selectedDate'] ?? '',
        'due_date_formatted' => $dueInfo['date_formatted'] ?? '',
        'due_time_formatted' => $dueInfo['time_formatted'] ?? '',
        'hours_remaining' => $hoursRemaining,
        'is_today' => $dueInfo['is_today'] ?? false,
        'urgency' => $urgencyLevel,
    ];
}

/**
 * Format an entire batch for the courier app / Hub display.
 * Returns the complete batch data shape expected by the frontend.
 */
function batch_formatForApp($batch) {
    // Enrich batches that lack stops OR have stops with empty addresses
    $needsEnrich = empty($batch['stops']);
    if (!$needsEnrich && !empty($batch['stops'])) {
        // Check if any stop has an empty address - if so, re-enrich
        foreach ($batch['stops'] as $s) {
            if (empty($s['address'])) { $needsEnrich = true; break; }
        }
    }
    if ($needsEnrich) {
        $refs = $batch['order_refs'] ?? [];
        if (empty($refs) && !empty($batch['orders'])) {
            foreach ($batch['orders'] as $o) {
                $refs[] = is_array($o) ? ($o['ref'] ?? '') : $o;
            }
        }
        if (!empty($refs)) {
            $formattedOrders = [];
            foreach ($refs as $ref) {
                $order = dispatch_loadOrder($ref);
                if ($order) $formattedOrders[$ref] = batch_formatOrder($order, $ref);
            }
            if (!empty($formattedOrders)) {
                $batch['stops'] = batch_buildStops($formattedOrders);
                $batch['route'] = batch_calculateRoute($batch['stops']);
                $batch['payout'] = batch_calculatePayout($batch['stops']);
                $batch['urgency'] = batch_getUrgency($formattedOrders);
                $batch['current_stop_index'] = 0;
            }
        }
    }
    $urgency = $batch['urgency'] ?? ['level' => 'normal'];
    
    // Get order refs (handle both formats)
    $refs = $batch['order_refs'] ?? [];
    if (empty($refs) && !empty($batch['orders'])) {
        foreach ($batch['orders'] as $o) {
            $refs[] = is_array($o) ? ($o['ref'] ?? '') : $o;
        }
    }
    
    // Build order summaries
    $orderSummaries = [];
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if ($order) {
            $fmt = batch_formatOrder($order, $ref);
            $orderSummaries[] = [
                'ref' => $ref,
                'customer_name' => $fmt['customer_name'] ?? '',
                'customer_phone' => $fmt['customer_phone'] ?? '',
                'material' => $fmt['material'] ?? '',
                'size' => $fmt['size'] ?? '',
                'quantity' => $fmt['quantity'] ?? 1,
                'status' => $fmt['status'] ?? '',
            ];
        }
    }
    
    // Build navigation URLs for remaining stops
    $remainingStops = [];
    $stops = $batch['stops'] ?? [];
    foreach ($stops as $stop) {
        if (($stop['status'] ?? 'pending') === 'pending') {
            $remainingStops[] = $stop;
        }
    }
    $navUrls = !empty($remainingStops) ? batch_buildNavUrls($remainingStops) : ['google' => '', 'apple' => ''];
    
    return [
        'type' => 'batch',
        'batch_id' => $batch['batch_id'],
        'status' => $batch['status'],
        'order_count' => count($refs),
        'orders' => $orderSummaries,
        'stops' => $stops,
        'route' => $batch['route'] ?? null,
        'current_stop_index' => $batch['current_stop_index'] ?? 0,
        // Urgency
        'urgency' => $urgency['level'] ?? 'normal',
        'hours_remaining' => $urgency['hours_remaining'] ?? null,
        'due_date_formatted' => $urgency['due_date_formatted'] ?? '',
        'due_time_formatted' => $urgency['due_time_formatted'] ?? '',
        // Payout
        'est_payout' => $batch['payout']['total'] ?? 0,
        'est_payout_breakdown' => $batch['payout']['breakdown'] ?? [],
        // Courier
        'courier_name' => $batch['courier']['name'] ?? $batch['courier_name'] ?? '',
        'courier_pin' => $batch['courier']['pin'] ?? $batch['courier_id'] ?? '',
        // Navigation
        'google_nav_url' => $navUrls['google'],
        'apple_nav_url' => $navUrls['apple'],
        // Meta
        'event_acronym' => $batch['event_acronym'] ?? '',
        'created_at' => $batch['created_at'] ?? '',
        'notes' => $batch['notes'] ?? '',
        // Hub compatibility
        'destinations' => $batch['destinations'] ?? [],
    ];
}



// ============================================
// UNASSIGN / RELEASE ORDER
// ============================================

/**
 * Unassign a single order from its courier.
 * Reverts status to 'ready' and clears dispatch metadata.
 * Handles batch membership: removes from batch, disbands if <2 remain.
 * 
 * @param string $ref           Order reference code
 * @param string $unassignedBy  Who initiated ('admin', courier name, etc.)
 * @param string $reason        Optional reason (car trouble, wrong order, etc.)
 * @param bool   $adminForce    If true, allows unassign from 'shipped' status
 * @return array Result with success flag
 */
function batch_unassignOrder($ref, $unassignedBy = 'admin', $reason = '', $adminForce = false) {
    $currentStatus = batch_getOrderStatus($ref);
    
    // Validate status
    $allowedStatuses = ['dispatched'];
    if ($adminForce) $allowedStatuses[] = 'shipped';
    
    if (!in_array($currentStatus, $allowedStatuses)) {
        return ['success' => false, 'error' => 'Cannot unassign order in status: ' . ($currentStatus ?: 'unknown') . '. Only dispatched' . ($adminForce ? '/shipped' : '') . ' orders can be unassigned.'];
    }
    
    $order = dispatch_loadOrder($ref);
    if (!$order) {
        return ['success' => false, 'error' => 'Order not found: ' . $ref];
    }
    
    // Capture old courier info for logging
    $dispatch = $order['dispatch'] ?? [];
    $oldCourier = $dispatch['courier_name'] ?? $dispatch['courier_id'] ?? 'unknown';
    $batchId = $dispatch['batch_id'] ?? null;
    
    // Clear dispatch metadata
    unset($order['dispatch']['courier_id']);
    unset($order['dispatch']['courier_pin']);
    unset($order['dispatch']['courier_name']);
    unset($order['dispatch']['courier_type']);
    unset($order['dispatch']['dispatched_at']);
    unset($order['dispatch']['dispatched_by']);
    unset($order['dispatch']['shipped_at']);
    unset($order['dispatch']['batch_id']);
    
    // Add unassign record
    $order['dispatch']['unassigned_at'] = date('c');
    $order['dispatch']['unassigned_by'] = $unassignedBy;
    $order['dispatch']['unassign_reason'] = $reason;
    $order['dispatch']['previous_courier'] = $oldCourier;
    
    // Revert status to ready
    batch_setOrderStatus($ref, 'ready');
    batch_saveOrder($ref, $order);
    
    // Log activity
    batch_logActivity($ref, $currentStatus, 'ready', $unassignedBy);
    
    // Handle batch membership
    $batchResult = null;
    if ($batchId) {
        $batchResult = batch_removeOrderFromBatch($ref, $batchId, $unassignedBy);
    }
    
    // Notify
    if (function_exists('dispatch_notifyStatusChange')) {
        dispatch_notifyStatusChange($ref, $currentStatus, 'ready', $unassignedBy . ' (unassigned)');
    }
    
    return [
        'success' => true,
        'message' => 'Order ' . $ref . ' unassigned from ' . $oldCourier . ' and returned to ready queue',
        'previous_courier' => $oldCourier,
        'previous_status' => $currentStatus,
        'batch_affected' => $batchResult,
    ];
}

/**
 * Remove a single order from a batch.
 * If batch drops below 2 orders, auto-disbands the remaining.
 */
function batch_removeOrderFromBatch($ref, $batchId, $removedBy = 'admin') {
    $data = batch_loadAll();
    $batchIdx = null;
    $batch = null;
    
    foreach ($data['active'] as $i => $b) {
        if ($b['batch_id'] === $batchId) {
            $batch = $b;
            $batchIdx = $i;
            break;
        }
    }
    
    if (!$batch) {
        return ['action' => 'none', 'reason' => 'Batch not found or already completed'];
    }
    
    // Remove ref from order_refs
    $refs = $batch['order_refs'] ?? [];
    $refs = array_values(array_filter($refs, function($r) use ($ref) { return $r !== $ref; }));
    $batch['order_refs'] = $refs;
    
    // Remove from orders array
    if (!empty($batch['orders'])) {
        $batch['orders'] = array_values(array_filter($batch['orders'], function($o) use ($ref) {
            return (is_array($o) ? ($o['ref'] ?? '') : $o) !== $ref;
        }));
        $batch['order_count'] = count($batch['orders']);
    }
    
    // Remove from stops (order_refs within each stop)
    if (!empty($batch['stops'])) {
        foreach ($batch['stops'] as &$stop) {
            $stop['order_refs'] = array_values(array_filter($stop['order_refs'] ?? [], function($r) use ($ref) { return $r !== $ref; }));
            if (!empty($stop['order_details'])) {
                $stop['order_details'] = array_values(array_filter($stop['order_details'], function($d) use ($ref) {
                    return ($d['ref'] ?? '') !== $ref;
                }));
            }
        }
        unset($stop);
        
        // Remove any stops that now have 0 orders
        $batch['stops'] = array_values(array_filter($batch['stops'], function($s) {
            return count($s['order_refs'] ?? []) > 0;
        }));
    }
    
    // If batch drops below 2 orders, disband entirely
    if (count($refs) < 2) {
        // Unassign remaining orders from batch (but keep their courier assignment)
        foreach ($refs as $remainingRef) {
            $remainingOrder = dispatch_loadOrder($remainingRef);
            if ($remainingOrder && isset($remainingOrder['dispatch']['batch_id'])) {
                unset($remainingOrder['dispatch']['batch_id']);
                batch_saveOrder($remainingRef, $remainingOrder);
            }
        }
        
        // Archive batch as disbanded
        $batch['status'] = 'cancelled';
        $batch['disbanded_at'] = date('c');
        $batch['disbanded_by'] = $removedBy;
        $batch['disband_reason'] = 'Order ' . $ref . ' removed, below minimum';
        
        unset($data['active'][$batchIdx]);
        $data['active'] = array_values($data['active']);
        $data['completed'][] = $batch;
        batch_saveAll($data);
        
        return ['action' => 'disbanded', 'reason' => 'Batch dropped below 2 orders', 'remaining_released' => $refs];
    }
    
    // Otherwise update the batch with the order removed
    $data['active'][$batchIdx] = $batch;
    batch_saveAll($data);
    
    return ['action' => 'removed', 'remaining_count' => count($refs)];
}

/**
 * Release all orders assigned to a specific courier.
 * Used when courier can't continue (car trouble, etc.)
 */
function batch_releaseAllForCourier($courierPin, $releasedBy = 'admin', $reason = '') {
    $statuses = dispatch_loadStatuses();
    $released = [];
    $errors = [];
    
    foreach ($statuses as $ref => $status) {
        if (!in_array($status, ['dispatched', 'shipped'])) continue;
        
        $order = dispatch_loadOrder($ref);
        if (!$order) continue;
        
        $dispatch = $order['dispatch'] ?? [];
        $pin = $dispatch['courier_pin'] ?? $dispatch['courier_id'] ?? '';
        if ($pin !== $courierPin) continue;
        
        $result = batch_unassignOrder($ref, $releasedBy, $reason, true);
        if ($result['success']) {
            $released[] = $ref;
        } else {
            $errors[] = $ref . ': ' . $result['error'];
        }
    }
    
    return [
        'success' => true,
        'released_count' => count($released),
        'released_refs' => $released,
        'errors' => $errors,
    ];
}


// ============================================
// AUTO-DETECTION
// ============================================

/**
 * Lightweight batch detection — quick check for existing suggestions
 */
function batch_lightweightCheck() {
    $data = batch_loadAll();
    
    $existing = [];
    foreach ($data['active'] as $batch) {
        if (in_array($batch['status'], ['pending', 'suggested'])) {
            $existing[] = $batch;
        }
    }
    
    if (!empty($existing)) return $existing;
    
    // No pending batches — run auto-detect
    return batch_autoDetect();
}

/**
 * Full auto-detection scan.
 * Analyzes ready queue for batch opportunities.
 * Uses 3 strategies: same-dropoff, same-vendor, proximity.
 */
function batch_autoDetect() {
    $queue = dispatch_getReadyQueue();
    if (count($queue) < 2) return [];
    
    // Load and format all ready orders
    $orders = [];
    foreach ($queue as $item) {
        $ref = $item['ref'] ?? ($item['referenceCode'] ?? '');
        if (empty($ref)) continue;
        
        // Skip if already in a batch
        $existing = batch_findByOrderRef($ref);
        if ($existing) continue;
        
        $order = dispatch_loadOrder($ref);
        if (!$order) continue;
        
        $formatted = batch_formatOrder($order, $ref);
        $orders[$ref] = $formatted;
    }
    
    if (count($orders) < 2) return [];
    
    $suggestions = [];
    
    // Strategy 1: Same dropoff location
    $byDest = [];
    foreach ($orders as $ref => $o) {
        $key = strtolower($o['destination'] ?? '');
        if (empty($key)) continue;
        $byDest[$key][] = $ref;
    }
    foreach ($byDest as $dest => $refs) {
        if (count($refs) < 2) continue;
        $refs = array_slice($refs, 0, BATCH_MAX_ORDERS);
        $batchOrders = [];
        foreach ($refs as $r) $batchOrders[$r] = $orders[$r];
        $suggestions[] = [
            'strategy' => 'same_destination',
            'label' => count($refs) . ' orders → ' . $orders[$refs[0]]['destination'],
            'order_refs' => $refs,
            'score' => 90 + count($refs) * 2,
        ];
    }
    
    // Strategy 2: Same vendor (pickup)
    $byVendor = [];
    foreach ($orders as $ref => $o) {
        $key = strtolower($o['vendor_name'] ?? '');
        if (empty($key) || $key === 'unknown vendor') continue;
        $byVendor[$key][] = $ref;
    }
    foreach ($byVendor as $vendor => $refs) {
        if (count($refs) < 2) continue;
        $refs = array_slice($refs, 0, BATCH_MAX_ORDERS);
        
        // Skip if already suggested (all same refs)
        $isDuplicate = false;
        foreach ($suggestions as $s) {
            if (count(array_diff($refs, $s['order_refs'])) === 0) { $isDuplicate = true; break; }
        }
        if ($isDuplicate) continue;
        
        $suggestions[] = [
            'strategy' => 'same_vendor',
            'label' => count($refs) . ' orders from ' . $orders[$refs[0]]['vendor_name'],
            'order_refs' => $refs,
            'score' => 70 + count($refs) * 2,
        ];
    }
    
    // Strategy 3: Proximity (nearby vendors)
    $proxGroups = batch_findProximityGroups($orders);
    foreach ($proxGroups as $group) {
        $refs = $group['refs'];
        
        // Skip if already suggested
        $isDuplicate = false;
        foreach ($suggestions as $s) {
            $overlap = count(array_intersect($refs, $s['order_refs']));
            if ($overlap >= count($refs) * 0.5) { $isDuplicate = true; break; }
        }
        if ($isDuplicate) continue;
        
        $suggestions[] = [
            'strategy' => 'proximity',
            'label' => count($refs) . ' nearby orders',
            'order_refs' => $refs,
            'score' => 50 + count($refs) * 2,
        ];
    }
    
    // Sort by score descending
    usort($suggestions, function($a, $b) { return $b['score'] - $a['score']; });
    
    return array_slice($suggestions, 0, 5);
}

/**
 * Find groups of orders with nearby vendor addresses.
 * Uses haversine if coords available, otherwise groups by address prefix.
 */
function batch_findProximityGroups($orders) {
    $groups = [];
    $refs = array_keys($orders);
    $used = [];
    
    for ($i = 0; $i < count($refs); $i++) {
        if (isset($used[$refs[$i]])) continue;
        $group = [$refs[$i]];
        $addr1 = strtolower($orders[$refs[$i]]['vendor_address'] ?? '');
        
        for ($j = $i + 1; $j < count($refs); $j++) {
            if (isset($used[$refs[$j]])) continue;
            if (count($group) >= BATCH_MAX_ORDERS) break;
            
            $addr2 = strtolower($orders[$refs[$j]]['vendor_address'] ?? '');
            
            // Simple proximity: same street prefix (first 20 chars)
            if (!empty($addr1) && !empty($addr2)) {
                $prefix1 = substr(preg_replace('/[^a-z0-9]/', '', $addr1), 0, 20);
                $prefix2 = substr(preg_replace('/[^a-z0-9]/', '', $addr2), 0, 20);
                $similarity = 0;
                similar_text($prefix1, $prefix2, $similarity);
                if ($similarity > 70) {
                    $group[] = $refs[$j];
                    $used[$refs[$j]] = true;
                }
            }
        }
        
        if (count($group) >= 2) {
            $groups[] = ['refs' => $group];
            foreach ($group as $r) $used[$r] = true;
        }
    }
    
    return $groups;
}
