<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Include utilities for order analytics
if (file_exists('includes/utilities.php')) {
    require_once 'includes/utilities.php';
}

// Function to auto-archive expired events
function autoArchiveExpiredEvents(&$eventsData) {
    $today = new DateTime();
    $expiredEvents = [];
    $activeEvents = [];
    
    foreach ($eventsData['active'] as $event) {
        $endDate = new DateTime($event['endDate']);
        if ($endDate < $today) {
            $event['archivedDate'] = date('Y-m-d');
            $event['archivedReason'] = 'Auto-archived: Event expired';
            $expiredEvents[] = $event;
        } else {
            $activeEvents[] = $event;
        }
    }
    
    if (count($expiredEvents) > 0) {
        $eventsData['active'] = $activeEvents;
        $eventsData['archived'] = array_merge($eventsData['archived'], $expiredEvents);
        
        // Save the updated data
        $eventsFile = 'events.json';
        file_put_contents($eventsFile, json_encode($eventsData, JSON_PRETTY_PRINT));
        
        error_log("Auto-archived " . count($expiredEvents) . " expired events");
    }
    
    return count($expiredEvents);
}

// Function to get real-time order analytics from actual order files
function getEventOrderAnalytics() {
    $analytics = [];
    
    // Try different possible paths for the uploads directory
    $possiblePaths = [
        'uploads/orders/',
        '../uploads/orders/',
        './uploads/orders/',
        __DIR__ . '/uploads/orders/',
        __DIR__ . '/../uploads/orders/'
    ];
    
    $orderDir = null;
    foreach ($possiblePaths as $path) {
        if (is_dir($path)) {
            $orderDir = $path;
            break;
        }
    }
    
    if (!$orderDir) {
        error_log("Could not find uploads/orders directory in any of these locations: " . implode(', ', $possiblePaths));
        return $analytics;
    }
    
    error_log("Using order directory: $orderDir");
    
    try {
        // Look for files ending in -order.json (matching your pattern)
        $orderFiles = glob($orderDir . '*-order.json');
        
        error_log("Found " . count($orderFiles) . " order files in $orderDir");
        if (!empty($orderFiles)) {
            error_log("Sample files: " . implode(', ', array_map('basename', array_slice($orderFiles, 0, 3))));
        }
        
        foreach ($orderFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                error_log("Could not read file: $file");
                continue;
            }
            
            $orderData = json_decode($content, true);
            if (!$orderData) {
                error_log("Invalid JSON in file: " . basename($file));
                continue;
            }
            
            if (!isset($orderData['referenceCode'])) {
                error_log("No referenceCode in file: " . basename($file));
                continue;
            }
            
			// Skip cancelled and refunded orders
    if (isset($orderData['status']) && in_array(strtolower($orderData['status']), ['cancelled', 'refunded'])) {
        error_log("Skipping cancelled/refunded order: " . $orderData['referenceCode']);
        continue;
    }
			
            $referenceCode = $orderData['referenceCode'];
            
            // Extract event prefix from reference code (e.g., "TECH" from "TECH-008")
            $parts = explode('-', $referenceCode);
            $eventPrefix = strtoupper(trim($parts[0]));
            
            error_log("Processing order $referenceCode -> event prefix: $eventPrefix");
            
            // Initialize event analytics if not exists
            if (!isset($analytics[$eventPrefix])) {
                $analytics[$eventPrefix] = [
                    'orderCount' => 0,
                    'totalRevenue' => 0.0,
                    'baseRevenue' => 0.0  // NEW: Track base revenue separately
                ];
            }
            
            // Increment order count
            $analytics[$eventPrefix]['orderCount']++;
            
            // Add to total revenue - handle the floating point precision issue
            if (isset($orderData['pricing']['total'])) {
                $revenue = round((float)$orderData['pricing']['total'], 2);
                $analytics[$eventPrefix]['totalRevenue'] = round($analytics[$eventPrefix]['totalRevenue'] + $revenue, 2);
                error_log("Added $" . number_format($revenue, 2) . " total revenue for $eventPrefix (total now: $" . number_format($analytics[$eventPrefix]['totalRevenue'], 2) . ")");
            } else {
                error_log("No pricing.total found in $referenceCode");
            }
            
            // Add to base revenue (only basePrice, no delivery fee or tax)
            if (isset($orderData['pricing']['basePrice'])) {
                $baseRevenue = round((float)$orderData['pricing']['basePrice'], 2);
                $analytics[$eventPrefix]['baseRevenue'] = round($analytics[$eventPrefix]['baseRevenue'] + $baseRevenue, 2);
                error_log("Added $" . number_format($baseRevenue, 2) . " base revenue for $eventPrefix (base total now: $" . number_format($analytics[$eventPrefix]['baseRevenue'], 2) . ")");
            } else {
                error_log("No pricing.basePrice found in $referenceCode");
            }
        }
        
        error_log("=== FINAL ANALYTICS ===");
        foreach ($analytics as $event => $data) {
            error_log("$event: {$data['orderCount']} orders, $" . number_format($data['totalRevenue'], 2) . " total revenue, $" . number_format($data['baseRevenue'], 2) . " base revenue");
        }
        
    } catch (Exception $e) {
        error_log("Error in getEventOrderAnalytics: " . $e->getMessage());
    }
    
    return $analytics;
}

// Function to load order counts from real order data
function loadOrderCounts(&$eventsData) {
    try {
        // Get real-time analytics from actual orders
        $orderAnalytics = getEventOrderAnalytics();
        
        // Update order counts, total revenue, and base revenue in events data
        foreach ($eventsData['active'] as &$event) {
            $acronym = strtoupper($event['acronym']);
            if (isset($orderAnalytics[$acronym])) {
                $event['orderCount'] = $orderAnalytics[$acronym]['orderCount'];
                $event['totalRevenue'] = $orderAnalytics[$acronym]['totalRevenue'];
                $event['baseRevenue'] = $orderAnalytics[$acronym]['baseRevenue'];  // NEW
            } else {
                $event['orderCount'] = 0;
                $event['totalRevenue'] = 0.0;
                $event['baseRevenue'] = 0.0;  // NEW
            }
        }
        
        foreach ($eventsData['archived'] as &$event) {
            $acronym = strtoupper($event['acronym']);
            if (isset($orderAnalytics[$acronym])) {
                $event['orderCount'] = $orderAnalytics[$acronym]['orderCount'];
                $event['totalRevenue'] = $orderAnalytics[$acronym]['totalRevenue'];
                $event['baseRevenue'] = $orderAnalytics[$acronym]['baseRevenue'];  // NEW
            } else {
                $event['orderCount'] = 0;
                $event['totalRevenue'] = 0.0;
                $event['baseRevenue'] = 0.0;  // NEW
            }
        }
        
    } catch (Exception $e) {
        error_log('Error in loadOrderCounts: ' . $e->getMessage());
        // Set defaults if there's an error
        foreach ($eventsData['active'] as &$event) {
            $event['orderCount'] = 0;
            $event['totalRevenue'] = 0.0;
            $event['baseRevenue'] = 0.0;  // NEW
        }
        foreach ($eventsData['archived'] as &$event) {
            $event['orderCount'] = 0;
            $event['totalRevenue'] = 0.0;
            $event['baseRevenue'] = 0.0;  // NEW
        }
    }
}

// Function to create fallback events if file doesn't exist
function createFallbackEvents() {
    return [
        'active' => [
            [
                'id' => 'fanexpo',
                'acronym' => 'FANEXPO',
                'name' => 'Fan Expo Canada',
                'dates' => 'Aug 21-24, 2025',
                'startDate' => '2025-08-21',
                'endDate' => '2025-08-24',
                'fullName' => 'FANEXPO - Fan Expo Canada (Aug 21-24)',
                'orderCount' => 0,
                'totalRevenue' => 0.0,
                'baseRevenue' => 0.0,  // NEW
                'priority' => 'high'
            ],
            [
                'id' => 'comic',
                'acronym' => 'COMIC',
                'name' => 'Toronto Comic Con',
                'dates' => 'Oct 15-17, 2025',
                'startDate' => '2025-10-15',
                'endDate' => '2025-10-17',
                'fullName' => 'COMIC - Toronto Comic Con (Oct 15-17)',
                'orderCount' => 0,
                'totalRevenue' => 0.0,
                'baseRevenue' => 0.0,  // NEW
                'priority' => 'standard'
            ],
            [
                'id' => 'tech',
                'acronym' => 'TECH',
                'name' => 'Toronto Tech Conference',
                'dates' => 'Nov 5-7, 2025',
                'startDate' => '2025-11-05',
                'endDate' => '2025-11-07',
                'fullName' => 'TECH - Toronto Tech Conference (Nov 5-7)',
                'orderCount' => 0,
                'totalRevenue' => 0.0,
                'baseRevenue' => 0.0,  // NEW
                'priority' => 'standard'
            ]
        ],
        'archived' => [],
        'metadata' => [
            'lastUpdated' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'created' => date('Y-m-d H:i:s'),
            'description' => 'Fallback event data for MTCC Poster System'
        ]
    ];
}

try {
    $eventsFile = 'events.json';
    $eventsData = null;
    
    // Try to load existing events data
    if (file_exists($eventsFile)) {
        $content = file_get_contents($eventsFile);
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded) && isset($decoded['active']) && is_array($decoded['active'])) {
                $eventsData = $decoded;
            }
        }
    }
    
    // If no valid data loaded, create fallback
    if (!$eventsData) {
        error_log('Events file not found or invalid, creating fallback data');
        
        // Create directory if it doesn't exist
        $adminDir = 'admin';
        if (!is_dir($adminDir)) {
            mkdir($adminDir, 0755, true);
        }
        
        // Create fallback events
        $eventsData = createFallbackEvents();
        
        // Save fallback data
        file_put_contents($eventsFile, json_encode($eventsData, JSON_PRETTY_PRINT));
        error_log('Created fallback events file');
    }
    
    // Ensure required structure exists
    if (!isset($eventsData['active']) || !is_array($eventsData['active'])) {
        $eventsData['active'] = [];
    }
    if (!isset($eventsData['archived']) || !is_array($eventsData['archived'])) {
        $eventsData['archived'] = [];
    }
    if (!isset($eventsData['metadata']) || !is_array($eventsData['metadata'])) {
        $eventsData['metadata'] = [
            'lastUpdated' => date('Y-m-d H:i:s'),
            'version' => '1.0'
        ];
    }
    
    // Auto-archive expired events
    $archivedCount = autoArchiveExpiredEvents($eventsData);
    
    // Load current order counts and revenue from real order files
    loadOrderCounts($eventsData);
    
    // Update metadata
    $eventsData['metadata']['lastAccessed'] = date('Y-m-d H:i:s');
    $eventsData['metadata']['autoArchivedThisLoad'] = $archivedCount;
    
    // Filter out events that have already ended (extra safety check)
    $today = new DateTime();
    $validActiveEvents = [];
    foreach ($eventsData['active'] as $event) {
        $endDate = new DateTime($event['endDate']);
        if ($endDate >= $today) {
            $validActiveEvents[] = $event;
        }
    }
    $eventsData['active'] = $validActiveEvents;
    
    // Return response based on query parameter
    if (isset($_GET['active_only']) && $_GET['active_only'] === 'true') {
        // Return only active events for the main form
        echo json_encode([
            'success' => true,
            'data' => $eventsData['active'],
            'message' => 'Active events loaded successfully',
            'count' => count($eventsData['active']),
            'autoArchived' => $archivedCount
        ]);
    } else {
        // Return full data for admin interface
        echo json_encode([
            'success' => true,
            'data' => $eventsData,
            'message' => 'Events loaded successfully',
            'autoArchived' => $archivedCount
        ]);
    }
    
} catch (Exception $e) {
    error_log('Error in get-events.php: ' . $e->getMessage());
    
    // Return fallback data even on error to prevent form from breaking
    $fallbackEvents = createFallbackEvents();
    
    if (isset($_GET['active_only']) && $_GET['active_only'] === 'true') {
        echo json_encode([
            'success' => true,
            'data' => $fallbackEvents['active'],
            'message' => 'Using fallback events due to error: ' . $e->getMessage(),
            'fallback' => true,
            'count' => count($fallbackEvents['active'])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Error loading events: ' . $e->getMessage(),
            'data' => $fallbackEvents,
            'fallback' => true
        ]);
    }
}
?>