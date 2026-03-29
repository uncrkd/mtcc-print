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
