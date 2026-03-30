<?php
/**
 * Centralized Data Access Layer
 * MTCC Print Services
 *
 * Location: /includes/data-access.php
 *
 * Single source of truth for:
 *   - Order history logging (logOrderHistory / getOrderHistory)
 *   - Status file operations (loadStatuses / saveStatuses)
 *   - Order lookup (findOrderByReference / findOrderFile)
 *   - Generic JSON file I/O (loadJsonFile / saveJsonFile)
 */

// ============================================
// GENERIC JSON I/O
// ============================================

/**
 * Load and decode a JSON file.
 * @param string $path File path
 * @return array Decoded data (empty array if file missing or invalid)
 */
function loadJsonFile($path) {
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Encode and save data to a JSON file with LOCK_EX.
 * @param string $path File path
 * @param mixed  $data Data to encode
 * @return bool Success
 */
function saveJsonFile($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

// ============================================
// ORDER HISTORY
// ============================================

/**
 * Log an event to an order's history file.
 * @param string $referenceCode Order reference code (e.g. COMIC-042)
 * @param string $action        Action type (status_change, edit, note_added, email_sent, etc.)
 * @param string $details       Human-readable description of the change
 * @param string $user          Who performed the action (defaults to 'System')
 * @return bool Success
 */
if (!function_exists('logOrderHistory')) {
    function logOrderHistory($referenceCode, $action, $details = '', $user = 'System') {
        $historyFile = 'uploads/orders/' . $referenceCode . '_history.json';

        $history = [];
        if (file_exists($historyFile)) {
            $history = json_decode(file_get_contents($historyFile), true) ?: [];
        }

        $history[] = [
            'id' => uniqid('hist_'),
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'details' => $details,
            'user' => $user
        ];

        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT), LOCK_EX);
        return true;
    }
}

/**
 * Get order history entries sorted newest-first.
 * @param string $referenceCode Order reference code
 * @return array History entries
 */
if (!function_exists('getOrderHistory')) {
    function getOrderHistory($referenceCode) {
        $historyFile = 'uploads/orders/' . $referenceCode . '_history.json';

        if (!file_exists($historyFile)) {
            return [];
        }

        $history = json_decode(file_get_contents($historyFile), true) ?: [];

        usort($history, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });

        return $history;
    }
}

// ============================================
// STATUS OPERATIONS
// ============================================

/**
 * Load order statuses from the status file.
 * @param string $statusFile Path to statuses.json (default: data/statuses.json)
 * @return array Map of referenceCode => status
 */
if (!function_exists('loadStatuses')) {
    function loadStatuses($statusFile = 'data/statuses.json') {
        if (!file_exists($statusFile)) return [];
        return json_decode(file_get_contents($statusFile), true) ?: [];
    }
}

/**
 * Save order statuses to the status file with LOCK_EX.
 * @param array  $statuses   Map of referenceCode => status
 * @param string $statusFile Path to statuses.json
 * @return bool Success
 */
function saveStatuses($statuses, $statusFile = 'data/statuses.json') {
    return file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

// ============================================
// SYNCHRONIZED STATUS UPDATE
// ============================================

/**
 * Valid status transitions. Each status maps to an array of statuses it can transition to.
 * This is the single source of truth for the order lifecycle.
 */
function getValidTransitions() {
    return [
        'unpaid'     => ['paid', 'cancelled'],
        'paid'       => ['preflight', 'cancelled', 'refunded'],
        'preflight'  => ['printing', 'file_issue', 'cancelled', 'refunded'],
        'file_issue' => ['preflight', 'paid', 'printing', 'cancelled', 'refunded'],
        'printing'   => ['ready', 'file_issue', 'cancelled', 'refunded'],
        'ready'      => ['dispatched', 'shipped', 'cancelled', 'refunded'],
        'dispatched' => ['shipped', 'ready', 'cancelled'],
        'shipped'    => ['delivered', 'dispatched', 'cancelled'],
        'delivered'  => ['pickedup', 'unclaimed', 'missing'],
        'pickedup'   => [],
        'unclaimed'  => ['delivered'],
        'missing'    => ['delivered'],
        'cancelled'  => ['paid'],
        'refunded'   => [],
    ];
}

/**
 * Check if a status transition is valid.
 * @param string $from Current status
 * @param string $to   Target status
 * @return bool True if transition is allowed
 */
function isValidTransition($from, $to) {
    $transitions = getValidTransitions();
    $allowed = $transitions[$from] ?? [];
    return in_array($to, $allowed);
}

/**
 * Update order status in BOTH statuses.json AND the order JSON file.
 * Also logs to order history. This is the canonical way to change status.
 *
 * @param string $referenceCode Order reference code
 * @param string $newStatus     Target status
 * @param string $user          Who is making the change (defaults to 'System')
 * @param string $statusFile    Path to statuses.json
 * @param string $orderDir      Path to orders directory
 * @param bool   $validate      Whether to enforce transition rules (default true)
 * @return array ['success' => bool, 'error' => string|null, 'old_status' => string]
 */
function updateOrderStatusSync($referenceCode, $newStatus, $user = 'System', $statusFile = 'data/statuses.json', $orderDir = 'uploads/orders/', $validate = true) {
    // 1. Load current status from statuses.json (source of truth)
    $statuses = loadStatuses($statusFile);
    $oldStatus = $statuses[$referenceCode] ?? 'unpaid';

    // 2. Validate transition
    if ($validate && !isValidTransition($oldStatus, $newStatus)) {
        return [
            'success' => false,
            'error' => "Invalid transition: $oldStatus to $newStatus",
            'old_status' => $oldStatus
        ];
    }

    // 3. Update statuses.json
    $statuses[$referenceCode] = $newStatus;
    if (!saveStatuses($statuses, $statusFile)) {
        return ['success' => false, 'error' => 'Failed to save status file', 'old_status' => $oldStatus];
    }

    // 4. Update order JSON file (keep in sync)
    $orderInfo = findOrderByReference($referenceCode, $orderDir);
    if ($orderInfo) {
        $orderData = $orderInfo['data'];
        $orderData['status'] = $newStatus;
        file_put_contents($orderInfo['filepath'], json_encode($orderData, JSON_PRETTY_PRINT), LOCK_EX);
    }

    // 5. Log to order history
    $statusLabels = [
        'unpaid' => 'Unpaid', 'paid' => 'Paid', 'preflight' => 'Preflight',
        'file_issue' => 'File Issue', 'printing' => 'Printing',
        'ready' => 'Ready to Ship', 'dispatched' => 'Dispatched',
        'shipped' => 'Shipped', 'delivered' => 'Delivered',
        'pickedup' => 'Picked Up', 'unclaimed' => 'Unclaimed',
        'missing' => 'Missing', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'
    ];
    $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
    $newLabel = $statusLabels[$newStatus] ?? $newStatus;
    logOrderHistory($referenceCode, 'status_change', "Status changed from \"$oldLabel\" to \"$newLabel\"", $user);

    return ['success' => true, 'old_status' => $oldStatus, 'error' => null];
}

// ============================================
// ORDER LOOKUP
// ============================================

/**
 * Find an order by reference code.
 * Returns both the decoded data and the file path.
 * @param string $referenceCode Order reference code
 * @param string $orderDir      Directory containing order JSON files
 * @return array|null ['data' => array, 'filepath' => string] or null
 */
if (!function_exists('findOrderByReference')) {
    function findOrderByReference($referenceCode, $orderDir = 'uploads/orders/') {
        $orderFiles = glob($orderDir . '*-order.json');

        foreach ($orderFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($data['referenceCode'] ?? '') === $referenceCode) {
                return ['data' => $data, 'filepath' => $file];
            }
        }
        return null;
    }
}

/**
 * Find an order by reference code (returns data only).
 * Thin wrapper around findOrderByReference for production.php compatibility.
 * @param string $referenceCode Order reference code
 * @param string $ordersDir     Directory containing order JSON files
 * @return array|null Order data or null
 */
if (!function_exists('findOrderByRef')) {
    function findOrderByRef($referenceCode, $ordersDir) {
        $result = findOrderByReference($referenceCode, $ordersDir);
        return $result ? $result['data'] : null;
    }
}

/**
 * Find the file path for an order by reference code.
 * @param string $referenceCode Order reference code
 * @param string $ordersDir     Directory containing order JSON files
 * @return string|null File path or null
 */
if (!function_exists('findOrderFile')) {
    function findOrderFile($referenceCode, $ordersDir) {
        $result = findOrderByReference($referenceCode, $ordersDir);
        return $result ? $result['filepath'] : null;
    }
}

/**
 * Load all orders from disk, optionally merging with statuses.
 * @param string      $orderDir   Directory containing order JSON files
 * @param string|null $statusFile Path to statuses.json (null = don't merge)
 * @return array List of order arrays
 */
function loadAllOrders($orderDir = 'uploads/orders/', $statusFile = null) {
    $orders = [];
    $statuses = $statusFile ? loadStatuses($statusFile) : [];

    $files = glob($orderDir . '*-order.json');
    if (!$files) return [];

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!$data) continue;

        $ref = $data['referenceCode'] ?? '';
        if ($statusFile && $ref && isset($statuses[$ref])) {
            $data['status'] = $statuses[$ref];
        }

        $orders[] = $data;
    }

    return $orders;
}
