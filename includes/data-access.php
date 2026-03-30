<?php
require_once __DIR__ . '/status-config.php';

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
        // Use absolute path so this works from any directory (root, fulfillment/, courier/, etc.)
        $baseDir = defined('MTCC_ROOT') ? MTCC_ROOT : dirname(__DIR__);
        $historyFile = $baseDir . '/uploads/orders/' . $referenceCode . '_history.json';

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
        $baseDir = defined('MTCC_ROOT') ? MTCC_ROOT : dirname(__DIR__);
        $historyFile = $baseDir . '/uploads/orders/' . $referenceCode . '_history.json';

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
    $statusLabels = getStatusLabelsForRole('admin');
    $oldLabel = $statusLabels[$oldStatus] ?? $oldStatus;
    $newLabel = $statusLabels[$newStatus] ?? $newStatus;
    logOrderHistory($referenceCode, 'status_change', "Status changed from \"$oldLabel\" to \"$newLabel\"", $user);

    return ['success' => true, 'old_status' => $oldStatus, 'error' => null];
}

/**
 * The expected order lifecycle path (happy path).
 * Used by cascadeStatusTo() to fill in skipped steps.
 */
function getLifecyclePath() {
    return ['unpaid', 'paid', 'preflight', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];
}

/**
 * Auto-cascade an order through skipped lifecycle steps to reach a target status.
 *
 * Example: Order is in "preflight", courier marks "shipped".
 * This will auto-advance: preflight → printing → ready → dispatched → shipped,
 * logging each intermediate step with an "auto-advanced" note.
 *
 * @param string $referenceCode Order reference code
 * @param string $targetStatus  The status we want to reach
 * @param string $user          Who triggered this (e.g. "Mike (Courier)")
 * @param string $reason        Why the cascade happened (e.g. "Courier confirmed pickup")
 * @param string $statusFile    Path to statuses.json
 * @param string $orderDir      Path to orders directory
 * @return array ['success' => bool, 'skipped' => array of auto-advanced statuses, 'old_status' => string]
 */
function cascadeStatusTo($referenceCode, $targetStatus, $user = 'System', $reason = '', $statusFile = 'data/statuses.json', $orderDir = 'uploads/orders/') {
    $statuses = loadStatuses($statusFile);
    $currentStatus = $statuses[$referenceCode] ?? 'unpaid';
    $lifecycle = getLifecyclePath();

    $currentIdx = array_search($currentStatus, $lifecycle);
    $targetIdx = array_search($targetStatus, $lifecycle);

    // If either status isn't on the main lifecycle path, or target is behind/equal, do a direct update
    if ($currentIdx === false || $targetIdx === false || $targetIdx <= $currentIdx) {
        return updateOrderStatusSync($referenceCode, $targetStatus, $user, $statusFile, $orderDir, false);
    }

    // Auto-advance through each intermediate step
    $skipped = [];
    for ($i = $currentIdx + 1; $i < $targetIdx; $i++) {
        $intermediateStatus = $lifecycle[$i];
        $skipped[] = $intermediateStatus;

        // Update statuses.json for intermediate step
        $statuses[$intermediateStatus] = $intermediateStatus; // placeholder, overwritten below
        $statuses[$referenceCode] = $intermediateStatus;
        saveStatuses($statuses, $statusFile);

        // Log the auto-advance to order history
        $statusLabels = [
            'preflight' => 'Preflight', 'printing' => 'Printing', 'ready' => 'Ready to Ship',
            'dispatched' => 'Dispatched', 'shipped' => 'Shipped', 'delivered' => 'Delivered',
        ];
        $label = $statusLabels[$intermediateStatus] ?? $intermediateStatus;
        $autoNote = "Auto-advanced to \"$label\" — $reason";
        logOrderHistory($referenceCode, 'status_auto_advanced', $autoNote, 'System');
    }

    // Now set the actual target status (with full sync)
    $result = updateOrderStatusSync($referenceCode, $targetStatus, $user, $statusFile, $orderDir, false);
    $result['skipped'] = $skipped;
    $result['old_status'] = $currentStatus;

    // Log a notification for admin if steps were skipped
    if (!empty($skipped)) {
        $skippedList = implode(' → ', $skipped);
        logSkippedStepsNotification($referenceCode, $currentStatus, $targetStatus, $skippedList, $user, $reason);
    }

    return $result;
}

/**
 * Log a notification when vendor steps are skipped.
 * Writes to data/dispatch-notifications.json so admin dispatch hub sees it.
 */
function logSkippedStepsNotification($refCode, $fromStatus, $toStatus, $skippedList, $user, $reason) {
    $notifFile = 'data/dispatch-notifications.json';
    $notifs = [];
    if (file_exists($notifFile)) {
        $notifs = json_decode(file_get_contents($notifFile), true) ?: [];
    }
    if (!isset($notifs['notifications'])) {
        $notifs['notifications'] = [];
    }

    $notifs['notifications'][] = [
        'id' => 'skip_' . bin2hex(random_bytes(6)),
        'type' => 'skipped_steps',
        'reference_code' => $refCode,
        'message' => "Order $refCode skipped steps ($skippedList) — $reason by $user",
        'from_status' => $fromStatus,
        'to_status' => $toStatus,
        'skipped' => $skippedList,
        'triggered_by' => $user,
        'created_at' => date('c'),
        'read' => false,
    ];

    // Keep last 200 notifications
    if (count($notifs['notifications']) > 200) {
        $notifs['notifications'] = array_slice($notifs['notifications'], -200);
    }

    file_put_contents($notifFile, json_encode($notifs, JSON_PRETTY_PRINT), LOCK_EX);
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
