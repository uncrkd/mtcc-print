<?php
/**
 * Dispatch Functions
 * Shared business logic for dispatch operations
 * Location: /dispatch/dispatch-functions.php
 * 
 * Used by: dispatch/index.php (Hub), /api/ endpoints, /courier/ app
 */

// ============================================
// Path Configuration
// ============================================
if (!defined('DISPATCH_ORDERS_DIR'))       define('DISPATCH_ORDERS_DIR', __DIR__ . '/../uploads/orders/');
if (!defined('DISPATCH_STATUSES_FILE'))    define('DISPATCH_STATUSES_FILE', __DIR__ . '/../data/statuses.json');
if (!defined('DISPATCH_SETTINGS_FILE'))    define('DISPATCH_SETTINGS_FILE', __DIR__ . '/../data/dispatch-settings.json');
if (!defined('DISPATCH_BATCHES_FILE'))     define('DISPATCH_BATCHES_FILE', __DIR__ . '/../data/batches.json');
if (!defined('DISPATCH_COURIERS_FILE'))    define('DISPATCH_COURIERS_FILE', __DIR__ . '/couriers.json');
if (!defined('DISPATCH_LOCATIONS_FILE'))   define('DISPATCH_LOCATIONS_FILE', __DIR__ . '/../data/mtcc-locations.json');
if (!defined('DISPATCH_PREFLIGHT_LOG'))    define('DISPATCH_PREFLIGHT_LOG', __DIR__ . '/../data/preflight-log.json');
if (!defined('DISPATCH_EARNINGS_FILE'))    define('DISPATCH_EARNINGS_FILE', __DIR__ . '/../data/courier-earnings.json');
if (!defined('DISPATCH_NOTIF_FILE'))       define('DISPATCH_NOTIF_FILE', __DIR__ . '/../data/dispatch-notifications.json');
if (!defined('DISPATCH_WEATHER_CACHE'))    define('DISPATCH_WEATHER_CACHE', __DIR__ . '/weather-cache.json');

// Load centralized data access layer (provides cascadeStatusTo, logOrderHistory, etc.)
require_once __DIR__ . '/../includes/data-access.php';

// ============================================
// Status Helpers
// ============================================

/**
 * Load all statuses from statuses.json
 */
function dispatch_loadStatuses() {
    if (!file_exists(DISPATCH_STATUSES_FILE)) return [];
    $data = json_decode(file_get_contents(DISPATCH_STATUSES_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Get all order reference codes with a given status
 */
function dispatch_getOrderRefsByStatus($status) {
    $statuses = dispatch_loadStatuses();
    $refs = [];
    foreach ($statuses as $ref => $s) {
        if ($s === $status && !empty($ref)) {
            $refs[] = $ref;
        }
    }
    return $refs;
}

/**
 * Get all order reference codes matching any of the given statuses
 */
function dispatch_getOrderRefsByStatuses($statusList) {
    $statuses = dispatch_loadStatuses();
    $refs = [];
    foreach ($statuses as $ref => $s) {
        if (in_array($s, $statusList) && !empty($ref)) {
            $refs[] = $ref;
        }
    }
    return $refs;
}

// ============================================
// Order Loading
// ============================================

/**
 * Load a single order JSON by reference code
 */
function dispatch_loadOrder($refCode) {
    $dir = DISPATCH_ORDERS_DIR;
    
    // Try direct filename first
    $file = $dir . $refCode . '.json';
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) return $data;
    }
    
    // Scan directory for matching file
    if (is_dir($dir)) {
        $files = glob($dir . '*.json');
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $refCode) {
                return $data;
            }
        }
    }
    
    return null;
}

/**
 * Load multiple orders by reference codes
 * Returns array of [refCode => orderData]
 */
function dispatch_loadOrders($refCodes) {
    $orders = [];
    foreach ($refCodes as $ref) {
        $order = dispatch_loadOrder($ref);
        if ($order) {
            $orders[$ref] = $order;
        }
    }
    return $orders;
}

/**
 * Get vendor info for an order from preflight-log.json
 */
function dispatch_getVendorInfo($refCode) {
    if (!file_exists(DISPATCH_PREFLIGHT_LOG)) return null;
    $log = json_decode(file_get_contents(DISPATCH_PREFLIGHT_LOG), true);
    if (isset($log['entries'][$refCode])) {
        $entry = $log['entries'][$refCode];
        return [
            'vendor_name' => $entry['vendor_name'] ?? 'Unknown',
            'vendor_id' => $entry['vendor_id'] ?? '',
            'vendor_order_number' => $entry['vendor_order_number'] ?? '',
            'packing' => $entry['packing'] ?? 'none',
            'packing_details' => $entry['packing_details'] ?? [],
        ];
    }
    return null;
}

/**
 * Resolve destination info from order data
 * Returns: ['type' => 'mtcc'|'office', 'label' => '...', 'address' => '...', 'instructions' => '...']
 */
function dispatch_getDestination($orderData) {
    $deliveryOption = $orderData['deliveryOption'] ?? 'mtcc';
    
    if ($deliveryOption === 'mtcc' || $deliveryOption === 'mtcc_north' || $deliveryOption === 'mtcc_south') {
        // Determine building from event or delivery option
        $building = 'north';
        if ($deliveryOption === 'mtcc_south') {
            $building = 'south';
        } elseif ($deliveryOption === 'mtcc_north') {
            $building = 'north';
        } elseif (isset($orderData['event']['building'])) {
            $building = $orderData['event']['building'];
        }
        
        // Load MTCC locations for full details
        $locations = dispatch_loadLocations();
        $loc = $locations[$building] ?? null;
        
        return [
            'type' => 'mtcc',
            'building' => $building,
            'label' => $loc ? $loc['short_label'] : ('MTCC ' . ucfirst($building)),
            'address' => $loc ? $loc['address'] : '',
            'instructions' => $loc ? $loc['pickup_instructions'] : '',
        ];
    }
    
    // Office/address delivery
    $addr = $orderData['deliveryAddress'] ?? [];
    $parts = [];
    if (!empty($addr['address'])) $parts[] = $addr['address'];
    if (!empty($addr['city'])) $parts[] = $addr['city'];
    
    return [
        'type' => 'office',
        'building' => null,
        'label' => !empty($parts) ? implode(', ', $parts) : 'Address Delivery',
        'address' => implode(', ', array_filter([
            $addr['address'] ?? '', 
            $addr['unit'] ?? '',
            $addr['city'] ?? '', 
            $addr['province'] ?? '', 
            $addr['postal'] ?? ''
        ])),
        'instructions' => $addr['attn'] ?? '',
    ];
}

/**
 * Format due date/time from order data
 */
function dispatch_getDueInfo($orderData) {
    $dueDate = $orderData['selectedDate'] ?? null;
    $dueTime = $orderData['deliveryTime'] ?? 'anytime';
    
    $result = [
        'date' => $dueDate,
        'time' => $dueTime,
        'date_formatted' => '',
        'time_formatted' => '',
        'is_today' => false,
        'is_urgent' => false,
        'is_priority' => false,
        'hours_remaining' => null,
    ];
    
    if ($dueDate) {
        $dateObj = new DateTime($dueDate);
        $today = new DateTime('today');
        $result['date_formatted'] = $dateObj->format('l, M j');
        $result['is_today'] = ($dateObj->format('Y-m-d') === $today->format('Y-m-d'));
    }
    
    // Format time display
    $timeMap = [
        'anytime' => 'Anytime',
        'morning' => 'Morning',
        '9am' => '9:00 AM',
        '10am' => '10:00 AM',
        '11am' => '11:00 AM',
        '12pm' => '12:00 PM',
        '1pm' => '1:00 PM',
        '2pm' => '2:00 PM',
        '3pm' => '3:00 PM',
        '4pm' => '4:00 PM',
        '5pm' => '5:00 PM',
    ];
    $result['time_formatted'] = $timeMap[$dueTime] ?? $dueTime;
    
    // Calculate urgency
    if ($dueDate) {
        $settings = dispatch_loadSettings();
        $urgentHours = $settings['urgency']['urgent_hours'] ?? 3;
        $priorityHours = $settings['urgency']['priority_hours'] ?? 5;
        
        // Estimate due datetime
        $dueDateTime = new DateTime($dueDate);
        $timeHour = 17; // default 5pm
        if (preg_match('/(\d+)(am|pm)/', $dueTime, $m)) {
            $timeHour = (int)$m[1];
            if ($m[2] === 'pm' && $timeHour < 12) $timeHour += 12;
            if ($m[2] === 'am' && $timeHour === 12) $timeHour = 0;
        }
        $dueDateTime->setTime($timeHour, 0);
        
        $now = new DateTime();
        $diff = $now->diff($dueDateTime);
        $hoursRemaining = ($diff->invert ? -1 : 1) * ($diff->days * 24 + $diff->h + ($diff->i / 60));
        
        $result['hours_remaining'] = round($hoursRemaining, 1);
        $result['is_urgent'] = ($hoursRemaining > 0 && $hoursRemaining <= $urgentHours);
        $result['is_priority'] = ($hoursRemaining > $urgentHours && $hoursRemaining <= $priorityHours);
    }
    
    return $result;
}

// ============================================
// Ready Queue
// ============================================

/**
 * Get all orders in the Ready Queue (status = 'ready')
 * Returns enriched order data ready for display
 */
function dispatch_getReadyQueue() {
    $refs = dispatch_getOrderRefsByStatus('ready');
    $preflightLog = [];
    if (file_exists(DISPATCH_PREFLIGHT_LOG)) {
        $preflightLog = json_decode(file_get_contents(DISPATCH_PREFLIGHT_LOG), true) ?: [];
    }
    $settings = dispatch_loadSettings();
    $baseRate = $settings['pricing']['base_rate'] ?? 30;
    
    $queue = [];
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if (!$order) continue;
        
        // Check for dispatch_summary first (stamped at ready transition)
        if (isset($order['dispatch_summary'])) {
            $summary = $order['dispatch_summary'];
            $due = dispatch_getDueInfo($order);
            $queue[] = [
                'ref' => $ref,
                'vendor_name' => $summary['vendor_name'] ?? 'Unknown',
                'destination' => $summary['destination_label'] ?? 'Unknown',
                'destination_type' => $summary['destination_type'] ?? 'mtcc',
                'due_date' => $summary['due_date'] ?? '',
                'due_time' => $summary['due_label'] ?? '',
                'due_info' => $due,
                'est_cost' => $baseRate,
                'event' => $order['event']['acronym'] ?? '',
                'customer_name' => $order['customerInfo']['name'] ?? '',
                'vendor_order_number' => $summary['vendor_order_number'] ?? '',
                'packing' => $summary['packing'] ?? 'none',
                'packing_details' => $summary['packing_details'] ?? [],
            ];
            continue;
        }
        
        // Fallback: compute from raw order data
        $vendorInfo = isset($preflightLog['entries'][$ref]) 
            ? $preflightLog['entries'][$ref] 
            : null;
        $dest = dispatch_getDestination($order);
        $due = dispatch_getDueInfo($order);
        
        $queue[] = [
            'ref' => $ref,
            'vendor_name' => $vendorInfo ? ($vendorInfo['vendor_name'] ?? 'Unknown') : 'Unknown',
            'destination' => $dest['label'],
            'destination_type' => $dest['type'],
            'due_date' => $due['date_formatted'],
            'due_time' => $due['time_formatted'],
            'due_info' => $due,
            'est_cost' => $baseRate,
            'event' => $order['event']['acronym'] ?? '',
            'customer_name' => $order['customerInfo']['name'] ?? '',
            'vendor_order_number' => $vendorInfo ? ($vendorInfo['vendor_order_number'] ?? '') : '',
            'packing' => $vendorInfo ? ($vendorInfo['packing'] ?? 'none') : 'none',
            'packing_details' => $vendorInfo ? ($vendorInfo['packing_details'] ?? []) : [],
        ];
    }
    
    // Sort by urgency: urgent first, then priority, then by due date
    usort($queue, function($a, $b) {
        // Urgent orders first
        if ($a['due_info']['is_urgent'] && !$b['due_info']['is_urgent']) return -1;
        if (!$a['due_info']['is_urgent'] && $b['due_info']['is_urgent']) return 1;
        // Priority next
        if ($a['due_info']['is_priority'] && !$b['due_info']['is_priority']) return -1;
        if (!$a['due_info']['is_priority'] && $b['due_info']['is_priority']) return 1;
        // Then by hours remaining
        $aHrs = $a['due_info']['hours_remaining'] ?? 999;
        $bHrs = $b['due_info']['hours_remaining'] ?? 999;
        return $aHrs <=> $bHrs;
    });
    
    return $queue;
}

// ============================================
// Active Deliveries
// ============================================

/**
 * Get active deliveries grouped by courier
 */
function dispatch_getActiveDeliveries() {
    $refs = dispatch_getOrderRefsByStatuses(['dispatched', 'shipped']);
    $statuses = dispatch_loadStatuses();
    $couriers = dispatch_loadCouriers();
    $byCourier = [];
    
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if (!$order) continue;
        
        $dispatch = $order['dispatch'] ?? null;
        $courierId = $dispatch ? ($dispatch['courier_id'] ?? 'unassigned') : 'unassigned';
        $courierName = $dispatch ? ($dispatch['courier_name'] ?? 'Unassigned') : 'Unassigned';
        
        if (!isset($byCourier[$courierId])) {
            $byCourier[$courierId] = [
                'courier_id' => $courierId,
                'courier_name' => $courierName,
                'orders' => [],
                'count' => 0,
            ];
        }
        
        $dest = dispatch_getDestination($order);
        $due = dispatch_getDueInfo($order);
        
        $byCourier[$courierId]['orders'][] = [
            'ref' => $ref,
            'status' => $statuses[$ref] ?? 'unknown',
            'destination' => $dest['label'],
            'due_date' => $due['date_formatted'],
            'due_time' => $due['time_formatted'],
            'is_today' => $due['is_today'],
            'is_urgent' => $due['is_urgent'],
            'customer_name' => $order['customerInfo']['name'] ?? '',
            'material' => $order['material'] ?? '',
            'size' => $order['size'] ?? '',
            'dispatched_at' => $dispatch['dispatched_at'] ?? null,
        ];
        $byCourier[$courierId]['count']++;
    }
    
    return array_values($byCourier);
}

// ============================================
// Completed Today
// ============================================

/**
 * Get orders completed (delivered/pickedup) today
 */
function dispatch_getCompletedToday() {
    $refs = dispatch_getOrderRefsByStatuses(['delivered', 'pickedup']);
    $today = date('Y-m-d');
    $completed = [];
    
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if (!$order) continue;
        
        $dispatch = $order['dispatch'] ?? null;
        $completedAt = null;
        
        if ($dispatch) {
            $completedAt = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null;
        }
        
        // If no dispatch metadata, check if order has recent status change
        // For now, include all delivered/pickedup orders (filter by date when metadata is available)
        $isToday = true;
        if ($completedAt) {
            $isToday = (substr($completedAt, 0, 10) === $today);
        }
        
        if (!$isToday) continue;
        
        $dest = dispatch_getDestination($order);
        $statuses = dispatch_loadStatuses();
        
        $completed[] = [
            'ref' => $ref,
            'status' => $statuses[$ref] ?? 'delivered',
            'destination' => $dest['label'],
            'customer_name' => $order['customerInfo']['name'] ?? '',
            'completed_at' => $completedAt,
            'courier_name' => $dispatch['courier_name'] ?? 'N/A',
        ];
    }
    
    return $completed;
}

// ============================================
// Today's Summary
// ============================================

/**
 * Get summary statistics for today
 */
function dispatch_getTodaySummary() {
    $statuses = dispatch_loadStatuses();
    
    $counts = [
        'ready' => 0,
        'active' => 0,       // dispatched + shipped
        'completed' => 0,    // delivered + pickedup (today)
        'in_transit' => 0,   // shipped specifically
    ];
    
    foreach ($statuses as $ref => $status) {
        if (empty($ref)) continue;
        
        switch ($status) {
            case 'ready':
                $counts['ready']++;
                break;
            case 'dispatched':
                $counts['active']++;
                break;
            case 'shipped':
                $counts['active']++;
                $counts['in_transit']++;
                break;
            case 'delivered':
            case 'pickedup':
                $counts['completed']++;
                break;
        }
    }
    
    return $counts;
}

// ============================================
// Couriers
// ============================================

/**
 * Load all couriers
 */
function dispatch_loadCouriers() {
    if (!file_exists(DISPATCH_COURIERS_FILE)) return [];
    $data = json_decode(file_get_contents(DISPATCH_COURIERS_FILE), true);
    return $data['users'] ?? [];
}

/**
 * Get couriers available for assignment (active + courier role)
 */
function dispatch_getAvailableCouriers() {
    $couriers = dispatch_loadCouriers();
    $available = [];
    foreach ($couriers as $pin => $courier) {
        if (($courier['active'] ?? false) && ($courier['role'] ?? '') === 'courier') {
            $available[$pin] = [
                'pin' => $pin,
                'name' => $courier['name'],
                'availability' => $courier['availability'] ?? 'offline',
                'vehicle_type' => $courier['vehicle_type'] ?? 'car',
            ];
        }
    }
    return $available;
}

/**
 * Get courier online status summary
 */
function dispatch_getCourierSummary() {
    $couriers = dispatch_loadCouriers();
    $summary = [];
    foreach ($couriers as $pin => $courier) {
        if (($courier['active'] ?? false) && ($courier['role'] ?? '') === 'courier') {
            $summary[] = [
                'name' => $courier['name'],
                'availability' => $courier['availability'] ?? 'offline',
                'active_orders' => 0, // Will be computed from batches in Phase 2B
            ];
        }
    }
    return $summary;
}

// ============================================
// Settings & Locations
// ============================================

/**
 * Load dispatch settings
 */
function dispatch_loadSettings() {
    if (!file_exists(DISPATCH_SETTINGS_FILE)) return [];
    return json_decode(file_get_contents(DISPATCH_SETTINGS_FILE), true) ?: [];
}

/**
 * Save dispatch settings
 */
function dispatch_saveSettings($settings) {
    $settings['metadata']['updated_at'] = date('c');
    file_put_contents(DISPATCH_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Load MTCC locations
 */
function dispatch_loadLocations() {
    if (!file_exists(DISPATCH_LOCATIONS_FILE)) return [];
    return json_decode(file_get_contents(DISPATCH_LOCATIONS_FILE), true) ?: [];
}

// ============================================
// Weather
// ============================================

/**
 * Get weather data (cached for 30 minutes)
 * Uses Open-Meteo API (free, no key required)
 */
function dispatch_getWeather() {
    $cacheFile = DISPATCH_WEATHER_CACHE;
    $cacheDuration = 1800; // 30 minutes
    
    // Check cache
    if (file_exists($cacheFile)) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['fetched_at'])) {
            $age = time() - strtotime($cached['fetched_at']);
            if ($age < $cacheDuration) {
                return $cached['data'];
            }
        }
    }
    
    // Fetch from Open-Meteo API
    $url = 'https://api.open-meteo.com/v1/forecast'
        . '?latitude=43.65&longitude=-79.38'
        . '&current=weather_code,temperature_2m,rain,snowfall,wind_speed_10m,relative_humidity_2m'
        . '&daily=weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,wind_speed_10m_max'
        . '&timezone=America/Toronto'
        . '&forecast_days=5';
    
    $context = stream_context_create([
        'http' => ['timeout' => 5, 'method' => 'GET']
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        // Return fallback/cached data on failure
        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            return $cached['data'] ?? dispatch_getWeatherFallback();
        }
        return dispatch_getWeatherFallback();
    }
    
    $raw = json_decode($response, true);
    if (!$raw) return dispatch_getWeatherFallback();
    
    $data = dispatch_parseWeather($raw);
    
    // Save to cache
    $cache = [
        'fetched_at' => date('c'),
        'data' => $data,
    ];
    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT), LOCK_EX);
    
    return $data;
}

/**
 * Parse Open-Meteo API response into display-friendly format
 */
function dispatch_parseWeather($raw) {
    $current = $raw['current'] ?? [];
    $daily = $raw['daily'] ?? [];
    
    // Map weather codes to icons and descriptions
    $weatherMap = [
        0 => ['icon' => '&#9728;&#65039;', 'desc' => 'Clear'],
        1 => ['icon' => '&#127780;&#65039;', 'desc' => 'Mainly Clear'],
        2 => ['icon' => '&#9925;', 'desc' => 'Partly Cloudy'],
        3 => ['icon' => '&#9729;&#65039;', 'desc' => 'Overcast'],
        45 => ['icon' => '&#127787;&#65039;', 'desc' => 'Fog'],
        48 => ['icon' => '&#127787;&#65039;', 'desc' => 'Freezing Fog'],
        51 => ['icon' => '&#127782;&#65039;', 'desc' => 'Light Drizzle'],
        53 => ['icon' => '&#127782;&#65039;', 'desc' => 'Drizzle'],
        55 => ['icon' => '&#127783;&#65039;', 'desc' => 'Heavy Drizzle'],
        61 => ['icon' => '&#127783;&#65039;', 'desc' => 'Light Rain'],
        63 => ['icon' => '&#127783;&#65039;', 'desc' => 'Rain'],
        65 => ['icon' => '&#127783;&#65039;', 'desc' => 'Heavy Rain'],
        66 => ['icon' => '&#127783;&#65039;', 'desc' => 'Freezing Rain'],
        67 => ['icon' => '&#127783;&#65039;', 'desc' => 'Heavy Freezing Rain'],
        71 => ['icon' => '&#10052;&#65039;', 'desc' => 'Light Snow'],
        73 => ['icon' => '&#10052;&#65039;', 'desc' => 'Snow'],
        75 => ['icon' => '&#10052;&#65039;', 'desc' => 'Heavy Snow'],
        77 => ['icon' => '&#10052;&#65039;', 'desc' => 'Snow Grains'],
        80 => ['icon' => '&#127782;&#65039;', 'desc' => 'Light Showers'],
        81 => ['icon' => '&#127783;&#65039;', 'desc' => 'Showers'],
        82 => ['icon' => '&#127783;&#65039;', 'desc' => 'Heavy Showers'],
        85 => ['icon' => '&#127784;&#65039;', 'desc' => 'Light Snow Showers'],
        86 => ['icon' => '&#127784;&#65039;', 'desc' => 'Heavy Snow Showers'],
        95 => ['icon' => '&#9928;️', 'desc' => 'Thunderstorm'],
        96 => ['icon' => '&#9928;️', 'desc' => 'Thunderstorm + Hail'],
        99 => ['icon' => '&#9928;️', 'desc' => 'Thunderstorm + Heavy Hail'],
    ];
    
    $code = $current['weather_code'] ?? 0;
    $weather = $weatherMap[$code] ?? ['icon' => '&#127777;️', 'desc' => 'Unknown'];
    
    // Check bad weather conditions
    $settings = dispatch_loadSettings();
    $rainThreshold = $settings['weather']['rain_threshold_mm'] ?? 0.5;
    $snowThreshold = $settings['weather']['snow_threshold_mm'] ?? 0.5;
    $windThreshold = $settings['weather']['wind_threshold_kmh'] ?? 40;
    $tempThreshold = $settings['weather']['temp_threshold_c'] ?? -10;
    
    $isBadWeather = false;
    $badReasons = [];
    
    if (($current['rain'] ?? 0) >= $rainThreshold) {
        $isBadWeather = true;
        $badReasons[] = 'Rain';
    }
    if (($current['snowfall'] ?? 0) >= $snowThreshold) {
        $isBadWeather = true;
        $badReasons[] = 'Snow';
    }
    if (($current['wind_speed_10m'] ?? 0) >= $windThreshold) {
        $isBadWeather = true;
        $badReasons[] = 'High Wind';
    }
    if (($current['temperature_2m'] ?? 20) <= $tempThreshold) {
        $isBadWeather = true;
        $badReasons[] = 'Extreme Cold';
    }
    
    // Build forecast
    $forecast = [];
    $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $dailyDates = $daily['time'] ?? [];
    $dailyMaxTemps = $daily['temperature_2m_max'] ?? [];
    $dailyMinTemps = $daily['temperature_2m_min'] ?? [];
    $dailyCodes = $daily['weather_code'] ?? [];
    
    for ($i = 0; $i < min(5, count($dailyDates)); $i++) {
        $dayCode = $dailyCodes[$i] ?? 0;
        $dayWeather = $weatherMap[$dayCode] ?? ['icon' => '&#127777;️', 'desc' => 'Unknown'];
        $dayDate = new DateTime($dailyDates[$i]);
        
        $forecast[] = [
            'day' => ($i === 0) ? 'Today' : $dayNames[(int)$dayDate->format('w')],
            'date' => $dayDate->format('M j'),
            'icon' => $dayWeather['icon'],
            'desc' => $dayWeather['desc'],
            'high' => round($dailyMaxTemps[$i] ?? 0),
            'low' => round($dailyMinTemps[$i] ?? 0),
        ];
    }
    
    return [
        'current' => [
            'temp' => round($current['temperature_2m'] ?? 0),
            'icon' => $weather['icon'],
            'desc' => $weather['desc'],
            'wind' => round($current['wind_speed_10m'] ?? 0),
            'rain' => $current['rain'] ?? 0,
            'snow' => $current['snowfall'] ?? 0,
            'humidity' => $current['relative_humidity_2m'] ?? 0,
        ],
        'forecast' => $forecast,
        'bad_weather' => $isBadWeather,
        'bad_reasons' => $badReasons,
        'updated_at' => date('g:i A'),
    ];
}

/**
 * Fallback weather data when API is unavailable
 */
function dispatch_getWeatherFallback() {
    return [
        'current' => [
            'temp' => '--',
            'icon' => '&#127777;️',
            'desc' => 'Weather unavailable',
            'wind' => '--',
            'rain' => 0,
            'snow' => 0,
            'humidity' => '--',
        ],
        'forecast' => [],
        'bad_weather' => false,
        'bad_reasons' => [],
        'updated_at' => 'N/A',
    ];
}

// ============================================
// Dispatch Summary Stamping
// ============================================

/**
 * Stamp dispatch_summary onto an order when it transitions to 'ready'
 * Call this wherever status is set to 'ready' (fulfillment API, production page, etc.)
 */
function dispatch_stampReadySummary($refCode) {
    $order = dispatch_loadOrder($refCode);
    if (!$order) return false;
    
    $vendorInfo = dispatch_getVendorInfo($refCode);
    $dest = dispatch_getDestination($order);
    $due = dispatch_getDueInfo($order);
    
    $summary = [
        'destination_type' => $dest['type'],
        'destination_label' => $dest['label'],
        'destination_address' => $dest['address'],
        'destination_instructions' => $dest['instructions'],
        'vendor_name' => $vendorInfo ? $vendorInfo['vendor_name'] : 'Unknown',
        'vendor_id' => $vendorInfo ? $vendorInfo['vendor_id'] : '',
        'due_date' => $due['date'],
        'due_time' => $due['time'],
        'due_label' => $due['time_formatted'],
        'stamped_at' => date('c'),
    ];
    
    // Find and update the order file
    $dir = DISPATCH_ORDERS_DIR;
    $files = glob($dir . '*.json');
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $refCode) {
            $data['dispatch_summary'] = $summary;
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            return true;
        }
    }
    
    return false;
}

// ============================================
// Batch Management (shared)
// ============================================
require_once __DIR__ . '/batch-functions.php';

// Thin wrappers for backward compatibility with Hub AJAX handlers
// The real logic is in batch-functions.php

function dispatch_loadBatches() { return batch_loadAll(); }
function dispatch_saveBatches($batches) { batch_saveAll($batches); }
function dispatch_nextBatchId() { $d = batch_loadAll(); return batch_nextId($d); }
function dispatch_getActiveBatches() {
    // Return ALL non-completed batches (pending + dispatched + accepted + in_progress)
    $data = batch_loadAll();
    return $data['active'] ?? [];
}
function dispatch_getBatch($batchId) { $f = batch_find($batchId); return $f ? $f['batch'] : null; }

function dispatch_createBatch($refs, $courierId = '', $courierName = '', $notes = '') {
    return batch_create($refs, 'admin', false, $courierId, $courierName, $notes);
}

function dispatch_assignBatchCourier($batchId, $courierId, $courierName) {
    return batch_accept($batchId, ['pin' => $courierId, 'name' => $courierName]);
}

function dispatch_completeBatch($batchId) {
    // Find batch and check if all stops done
    $found = batch_find($batchId);
    if (!$found) return ['success' => false, 'error' => 'Batch not found'];
    $data = batch_loadAll();
    foreach ($data['active'] as $i => $b) {
        if ($b['batch_id'] === $batchId) {
            $b['status'] = 'completed';
            $b['completed_at'] = date('c');
            $data['completed'][] = $b;
            array_splice($data['active'], $i, 1);
            batch_saveAll($data);
            return ['success' => true];
        }
    }
    return ['success' => false, 'error' => 'Batch not found in active'];
}

function dispatch_stampBatchInfo($refCode, $batchId, $courierId = '', $courierName = '') {
    $order = dispatch_loadOrder($refCode);
    if (!$order) return false;
    $order['batch_id'] = $batchId;
    if (!empty($courierId)) {
        $order['dispatch'] = [
            'courier_id' => $courierId,
            'courier_name' => $courierName,
            'batch_id' => $batchId,
            'dispatched_at' => date('c'),
            'picked_up_at' => null,
            'delivered_at' => null,
            'delivery_photo' => null,
            'delivery_notes' => null,
            'failed_attempt' => null,
        ];
    }
    return batch_saveOrder($refCode, $order);
}

function dispatch_getBatchPreview($refs) {
    $orders = [];
    $destinations = [];
    $settings = dispatch_loadSettings();
    $baseRate = $settings['pricing']['base_rate'] ?? 30;
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if (!$order) continue;
        $dest = dispatch_getDestination($order);
        $due = dispatch_getDueInfo($order);
        $orders[] = [
            'ref' => $ref,
            'customer_name' => $order['customerInfo']['name'] ?? '',
            'destination' => $dest['label'],
            'destination_type' => $dest['type'],
            'due_date' => $due['date_formatted'],
            'due_time' => $due['time_formatted'],
            'is_urgent' => $due['is_urgent'],
            'is_priority' => $due['is_priority'],
            'event' => $order['event']['acronym'] ?? '',
        ];
        $destKey = $dest['label'];
        if (!isset($destinations[$destKey])) {
            $destinations[$destKey] = ['label' => $dest['label'], 'type' => $dest['type'], 'count' => 0];
        }
        $destinations[$destKey]['count']++;
    }
    // Enrich preview with stops and payout from batch engine
    $formattedOrders = [];
    foreach ($refs as $ref) {
        $order = dispatch_loadOrder($ref);
        if ($order) $formattedOrders[$ref] = batch_formatOrder($order, $ref);
    }
    $stops = !empty($formattedOrders) ? batch_buildStops($formattedOrders) : [];
    $payout = !empty($stops) ? batch_calculatePayout($stops) : ['total' => $baseRate, 'breakdown' => []];
    $route = !empty($stops) ? batch_calculateRoute($stops) : null;
    return [
        'orders' => $orders,
        'destinations' => array_values($destinations),
        'order_count' => count($orders),
        'est_total' => $payout['total'],
        'payout' => $payout,
        'route' => $route,
        'stops' => $stops,
        'next_batch_id' => dispatch_nextBatchId(),
    ];
}

// ============================================
// Courier Management (Phase 2D)
// ============================================

/**
 * Save couriers data back to file
 */
function dispatch_saveCouriers($data) {
    $data['metadata']['lastUpdated'] = date('Y-m-d');
    file_put_contents(DISPATCH_COURIERS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Add a new courier
 */
function dispatch_addCourier($name, $role, $phone = '', $email = '', $vehicleType = 'car', $notes = '') {
    $data = json_decode(file_get_contents(DISPATCH_COURIERS_FILE), true);
    if (!$data) return ['success' => false, 'error' => 'Cannot load courier data'];
    
    $pinLength = $data['settings']['pin_length'] ?? 6;
    
    // Generate unique PIN
    $maxAttempts = 100;
    $pin = '';
    for ($i = 0; $i < $maxAttempts; $i++) {
        $pin = str_pad(mt_rand(0, pow(10, $pinLength) - 1), $pinLength, '0', STR_PAD_LEFT);
        if (!isset($data['users'][$pin])) break;
    }
    
    if (isset($data['users'][$pin])) {
        return ['success' => false, 'error' => 'Could not generate unique PIN'];
    }
    
    $data['users'][$pin] = [
        'name' => $name,
        'role' => $role,
        'active' => true,
        'created' => date('Y-m-d H:i:s'),
        'phone' => $phone,
        'email' => $email,
        'vehicle_type' => $vehicleType,
        'availability' => 'offline',
        'last_seen' => null,
        'total_deliveries' => 0,
        'total_earned' => 0,
        'notes' => $notes,
    ];
    
    dispatch_saveCouriers($data);
    return ['success' => true, 'pin' => $pin];
}

/**
 * Update an existing courier
 */
function dispatch_updateCourier($pin, $fields) {
    $data = json_decode(file_get_contents(DISPATCH_COURIERS_FILE), true);
    if (!$data || !isset($data['users'][$pin])) {
        return ['success' => false, 'error' => 'Courier not found'];
    }
    
    $allowed = ['name', 'role', 'phone', 'email', 'vehicle_type', 'notes', 'active'];
    foreach ($fields as $key => $value) {
        if (in_array($key, $allowed)) {
            $data['users'][$pin][$key] = $value;
        }
    }
    
    dispatch_saveCouriers($data);
    return ['success' => true];
}

/**
 * Toggle courier active status
 */
function dispatch_toggleCourier($pin) {
    $data = json_decode(file_get_contents(DISPATCH_COURIERS_FILE), true);
    if (!$data || !isset($data['users'][$pin])) {
        return ['success' => false, 'error' => 'Courier not found'];
    }
    
    $data['users'][$pin]['active'] = !$data['users'][$pin]['active'];
    dispatch_saveCouriers($data);
    return ['success' => true, 'active' => $data['users'][$pin]['active']];
}

/**
 * Delete a courier
 */
function dispatch_deleteCourier($pin) {
    $data = json_decode(file_get_contents(DISPATCH_COURIERS_FILE), true);
    if (!$data || !isset($data['users'][$pin])) {
        return ['success' => false, 'error' => 'Courier not found'];
    }
    
    $name = $data['users'][$pin]['name'];
    unset($data['users'][$pin]);
    dispatch_saveCouriers($data);
    return ['success' => true, 'name' => $name];
}

/**
 * Get courier earnings summary
 */
function dispatch_getCourierEarnings($pin = null) {
    if (!file_exists(DISPATCH_EARNINGS_FILE)) return [];
    $data = json_decode(file_get_contents(DISPATCH_EARNINGS_FILE), true);
    $earnings = $data['earnings'] ?? [];
    
    if ($pin) return $earnings[$pin] ?? [];
    return $earnings;
}

// ============================================
// Notifications (Phase 2E)
// ============================================

/**
 * Load all notifications
 */
function dispatch_loadNotifications() {
    if (!file_exists(DISPATCH_NOTIF_FILE)) {
        return ['notifications' => [], 'metadata' => ['last_id' => 0]];
    }
    $data = json_decode(file_get_contents(DISPATCH_NOTIF_FILE), true);
    return $data ?: ['notifications' => [], 'metadata' => ['last_id' => 0]];
}

/**
 * Save notifications data
 */
function dispatch_saveNotifications($data) {
    $data['metadata']['last_updated'] = date('c');
    file_put_contents(DISPATCH_NOTIF_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/**
 * Create a notification
 * @param string $type - order_ready|batch_created|batch_dispatched|courier_assigned|status_change|weather_alert|system
 * @param string $title - short summary
 * @param string $message - detail text
 * @param array $context - extra data (ref, batch_id, courier, etc.)
 * @return int notification ID
 */
function dispatch_createNotification($type, $title, $message = '', $context = []) {
    $data = dispatch_loadNotifications();
    $settings = dispatch_loadSettings();
    $maxNotifs = $settings['notifications']['max_notifications'] ?? 50;
    
    $id = ($data['metadata']['last_id'] ?? 0) + 1;
    
    $notification = [
        'id' => $id,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'context' => $context,
        'read' => false,
        'created_at' => date('c'),
    ];
    
    // Prepend (newest first)
    array_unshift($data['notifications'], $notification);
    
    // Trim to max
    if (count($data['notifications']) > $maxNotifs) {
        $data['notifications'] = array_slice($data['notifications'], 0, $maxNotifs);
    }
    
    $data['metadata']['last_id'] = $id;
    dispatch_saveNotifications($data);
    
    return $id;
}

/**
 * Get notifications for display
 * @param int $since_id - only return notifications newer than this ID
 * @param int $limit
 * @return array
 */
function dispatch_getNotifications($since_id = 0, $limit = 20) {
    $data = dispatch_loadNotifications();
    $all = $data['notifications'] ?? [];
    
    if ($since_id > 0) {
        $all = array_filter($all, function($n) use ($since_id) {
            return $n['id'] > $since_id;
        });
        $all = array_values($all);
    }
    
    $unread = count(array_filter($data['notifications'], function($n) {
        return !$n['read'];
    }));
    
    return [
        'notifications' => array_slice($all, 0, $limit),
        'unread_count' => $unread,
        'last_id' => $data['metadata']['last_id'] ?? 0,
    ];
}

/**
 * Mark notification(s) as read
 * @param int|string $id - notification ID or 'all'
 */
function dispatch_markNotificationRead($id = 'all') {
    $data = dispatch_loadNotifications();
    
    foreach ($data['notifications'] as &$n) {
        if ($id === 'all' || $n['id'] == $id) {
            $n['read'] = true;
        }
    }
    
    dispatch_saveNotifications($data);
    return ['success' => true];
}

/**
 * Clear all notifications
 */
function dispatch_clearNotifications() {
    $data = dispatch_loadNotifications();
    $data['notifications'] = [];
    dispatch_saveNotifications($data);
    return ['success' => true];
}

// ============================================
// Notification Triggers (auto-create)
// ============================================

/**
 * Notify: new order is ready for dispatch
 */
function dispatch_notifyOrderReady($ref, $customerName = '', $destination = '') {
    $title = 'Order ready: ' . $ref;
    $msg = '';
    if ($customerName) $msg .= $customerName;
    if ($destination) $msg .= ($msg ? ' \u2192 ' : '') . $destination;
    
    return dispatch_createNotification('order_ready', $title, $msg, [
        'ref' => $ref,
        'action' => 'view_order',
    ]);
}

/**
 * Notify: batch created
 */
function dispatch_notifyBatchCreated($batchId, $orderCount, $courierName = '') {
    $title = 'Batch ' . $batchId . ' created (' . $orderCount . ' orders)';
    $msg = $courierName ? 'Assigned to ' . $courierName : 'Pending courier assignment';
    
    return dispatch_createNotification('batch_created', $title, $msg, [
        'batch_id' => $batchId,
        'action' => 'view_batch',
    ]);
}

/**
 * Notify: courier assigned to batch
 */
function dispatch_notifyBatchDispatched($batchId, $courierName) {
    $title = 'Batch ' . $batchId . ' dispatched';
    $msg = 'Assigned to ' . $courierName;
    
    return dispatch_createNotification('batch_dispatched', $title, $msg, [
        'batch_id' => $batchId,
        'courier_name' => $courierName,
        'action' => 'view_active',
    ]);
}

/**
 * Notify: individual order dispatched to courier
 */
function dispatch_notifyOrderDispatched($ref, $courierName) {
    $title = 'Order ' . $ref . ' dispatched';
    $msg = 'Assigned to ' . $courierName;
    
    return dispatch_createNotification('courier_assigned', $title, $msg, [
        'ref' => $ref,
        'courier_name' => $courierName,
        'action' => 'view_active',
    ]);
}

/**
 * Notify: order status changed
 */
function dispatch_notifyStatusChange($ref, $oldStatus, $newStatus, $changedBy = '') {
    $title = $ref . ': ' . $oldStatus . ' \u2192 ' . $newStatus;
    $msg = $changedBy ? 'Changed by ' . $changedBy : '';
    
    return dispatch_createNotification('status_change', $title, $msg, [
        'ref' => $ref,
        'old_status' => $oldStatus,
        'new_status' => $newStatus,
    ]);
}

/**
 * Notify: weather alert triggered
 */
function dispatch_notifyWeatherAlert($condition, $active = true) {
    $title = $active ? 'Bad weather bonus activated' : 'Bad weather bonus deactivated';
    $msg = $condition;

    return dispatch_createNotification('weather_alert', $title, $msg, [
        'active' => $active,
    ]);
}

// ============================================
// Earnings Summary & Payment
// ============================================

/**
 * Get earnings summary for all couriers, optionally filtered by period
 * @param string $period - 'today', '7days', '30days', 'all'
 * @return array keyed by courier PIN
 */
function dispatch_getEarningsSummary($period = 'all') {
    if (!file_exists(DISPATCH_EARNINGS_FILE)) return [];
    $data = json_decode(file_get_contents(DISPATCH_EARNINGS_FILE), true);
    $allEarnings = $data['earnings'] ?? [];
    $couriers = dispatch_loadCouriers();

    $now = new DateTime('now', new DateTimeZone('America/Toronto'));
    $summary = [];

    foreach ($couriers as $pin => $courier) {
        if (($courier['role'] ?? '') !== 'courier') continue;
        if (!($courier['active'] ?? false)) continue;

        $courierEarnings = $allEarnings[$pin] ?? [];
        $deliveries = $courierEarnings['deliveries'] ?? [];

        // Filter deliveries by period
        $filtered = [];
        foreach ($deliveries as $d) {
            if ($period === 'all') {
                $filtered[] = $d;
                continue;
            }
            $deliveryDate = new DateTime($d['date'] ?? 'now', new DateTimeZone('America/Toronto'));
            $diff = $now->diff($deliveryDate)->days;
            if ($period === 'today' && $diff === 0) $filtered[] = $d;
            elseif ($period === '7days' && $diff <= 7) $filtered[] = $d;
            elseif ($period === '30days' && $diff <= 30) $filtered[] = $d;
        }

        $earned = 0;
        $bonuses = 0;
        foreach ($filtered as $d) {
            $earned += floatval($d['amount'] ?? 0);
            $bonuses += floatval($d['bonus'] ?? 0);
        }

        $paid = floatval($courierEarnings['total_paid'] ?? 0);
        $pending = ($earned + $bonuses) - $paid;
        if ($pending < 0) $pending = 0;

        $summary[$pin] = [
            'name' => $courier['name'] ?? 'Unknown',
            'deliveries' => count($filtered),
            'earned' => $earned,
            'bonuses' => $bonuses,
            'pending' => $pending,
            'paid' => $paid,
            'recent_deliveries' => array_slice($filtered, 0, 10),
        ];
    }

    return $summary;
}

/**
 * Mark courier earnings as paid
 * @param string $pin - courier PIN
 * @param array $indices - specific delivery indices to mark paid (empty = all pending)
 * @param string $method - 'cash', 'etransfer', 'cheque'
 * @return array ['success' => bool, 'error' => string]
 */
function dispatch_markPaid($pin, $indices = [], $method = 'cash') {
    if (!file_exists(DISPATCH_EARNINGS_FILE)) {
        return ['success' => false, 'error' => 'No earnings data found'];
    }

    $data = json_decode(file_get_contents(DISPATCH_EARNINGS_FILE), true);
    $allEarnings = $data['earnings'] ?? [];

    if (!isset($allEarnings[$pin])) {
        return ['success' => false, 'error' => 'No earnings for this courier'];
    }

    $deliveries = $allEarnings[$pin]['deliveries'] ?? [];
    $paidAmount = 0;

    if (empty($indices)) {
        // Mark all unpaid as paid
        foreach ($deliveries as &$d) {
            if (!($d['paid'] ?? false)) {
                $d['paid'] = true;
                $d['paid_method'] = $method;
                $d['paid_date'] = date('c');
                $paidAmount += floatval($d['amount'] ?? 0) + floatval($d['bonus'] ?? 0);
            }
        }
        unset($d);
    } else {
        foreach ($indices as $idx) {
            if (isset($deliveries[$idx]) && !($deliveries[$idx]['paid'] ?? false)) {
                $deliveries[$idx]['paid'] = true;
                $deliveries[$idx]['paid_method'] = $method;
                $deliveries[$idx]['paid_date'] = date('c');
                $paidAmount += floatval($deliveries[$idx]['amount'] ?? 0) + floatval($deliveries[$idx]['bonus'] ?? 0);
            }
        }
    }

    $allEarnings[$pin]['deliveries'] = $deliveries;
    $allEarnings[$pin]['total_paid'] = floatval($allEarnings[$pin]['total_paid'] ?? 0) + $paidAmount;

    $data['earnings'] = $allEarnings;
    $data['metadata']['last_updated'] = date('c');
    file_put_contents(DISPATCH_EARNINGS_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    return ['success' => true, 'paid_amount' => $paidAmount, 'method' => $method];
}
