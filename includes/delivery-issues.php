<?php
/**
 * Delivery Issue Functions - MTCC Print Services
 * Manages delivery issue reporting, storage, and resolution
 * 
 * Location: /includes/delivery-issues.php
 * 
 * Used by:
 *   - /courier/api.php (report_issue endpoint)
 *   - /dispatch/index.php (Issues tab)
 *   - /dispatch/api-dispatch.php (resolve actions)
 *   - /includes/problem-detection.php (counts)
 */

// Issue data file
if (!defined('DELIVERY_ISSUES_FILE')) {
    define('DELIVERY_ISSUES_FILE', __DIR__ . '/../data/delivery-issues.json');
}
// Issue photo directory
if (!defined('ISSUE_PHOTOS_DIR')) {
    define('ISSUE_PHOTOS_DIR', __DIR__ . '/../uploads/issue-photos/');
}

// Ensure directories exist
if (!is_dir(dirname(DELIVERY_ISSUES_FILE))) {
    @mkdir(dirname(DELIVERY_ISSUES_FILE), 0755, true);
}
if (!is_dir(ISSUE_PHOTOS_DIR)) {
    @mkdir(ISSUE_PHOTOS_DIR, 0755, true);
}

// ============================================
// LOAD / SAVE
// ============================================

function issues_loadAll() {
    if (!file_exists(DELIVERY_ISSUES_FILE)) {
        return ['issues' => [], 'metadata' => ['next_id' => 1, 'updated_at' => null]];
    }
    $json = file_get_contents(DELIVERY_ISSUES_FILE);
    $data = json_decode($json, true);
    if (!$data || !isset($data['issues'])) {
        return ['issues' => [], 'metadata' => ['next_id' => 1, 'updated_at' => null]];
    }
    return $data;
}

function issues_saveAll($data) {
    $data['metadata']['updated_at'] = date('c');
    return file_put_contents(
        DELIVERY_ISSUES_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

// ============================================
// CREATE ISSUE
// ============================================

/**
 * Report a new delivery issue
 * 
 * @param string $ref         Order reference code
 * @param string $issueType   Issue type ID (e.g., 'damaged_in_transit')
 * @param string $issueLabel  Human-readable label
 * @param string $notes       Additional notes from courier
 * @param string $reportedBy  Courier/staff name
 * @param string $reportedByPin  Courier/staff PIN
 * @param string|null $photoData  Base64 photo data (or null)
 * @return array ['success' => bool, 'issue' => array, 'error' => string]
 */
function issues_create($ref, $issueType, $issueLabel, $notes, $reportedBy, $reportedByPin, $photoData = null) {
    $data = issues_loadAll();
    
    // Generate ID
    $nextId = $data['metadata']['next_id'] ?? (count($data['issues']) + 1);
    $issueId = 'ISS-' . date('Ymd') . '-' . str_pad($nextId, 3, '0', STR_PAD_LEFT);
    $data['metadata']['next_id'] = $nextId + 1;
    
    // Save photo if provided
    $photoPath = null;
    if ($photoData && strpos($photoData, 'data:image') === 0) {
        $photoPath = issues_savePhoto($issueId, $photoData);
    }
    
    $issue = [
        'id' => $issueId,
        'ref' => $ref,
        'type' => $issueType,
        'label' => $issueLabel,
        'notes' => $notes ?: '',
        'reported_by' => $reportedBy,
        'reported_by_pin' => $reportedByPin,
        'reported_at' => date('c'),
        'photo' => $photoPath,
        'status' => 'open',
        'resolution' => null,
        'resolved_by' => null,
        'resolved_at' => null,
        'resolution_notes' => null,
        'retry_date' => null,
    ];
    
    // For customer_unavailable and address_issue, auto-set retry for next morning
    if (in_array($issueType, ['customer_unavailable', 'address_issue'])) {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $issue['retry_date'] = $tomorrow . 'T09:00:00';
    }
    
    $data['issues'][] = $issue;
    issues_saveAll($data);
    
    return ['success' => true, 'issue' => $issue];
}

// ============================================
// QUERY ISSUES
// ============================================

/**
 * Get all open/reviewing issues (for Dispatch Hub Issues tab)
 */
function issues_getOpen() {
    $data = issues_loadAll();
    $open = [];
    foreach ($data['issues'] as $issue) {
        if (in_array($issue['status'], ['open', 'reviewing'])) {
            $open[] = $issue;
        }
    }
    // Sort: newest first
    usort($open, function($a, $b) {
        return strcmp($b['reported_at'], $a['reported_at']);
    });
    return $open;
}

/**
 * Get issues for a specific order
 */
function issues_getForOrder($ref) {
    $data = issues_loadAll();
    $orderIssues = [];
    foreach ($data['issues'] as $issue) {
        if ($issue['ref'] === $ref) {
            $orderIssues[] = $issue;
        }
    }
    return $orderIssues;
}

/**
 * Get issue by ID
 */
function issues_getById($issueId) {
    $data = issues_loadAll();
    foreach ($data['issues'] as $issue) {
        if ($issue['id'] === $issueId) {
            return $issue;
        }
    }
    return null;
}

/**
 * Count open issues (for sidebar badge and dashboard)
 */
function issues_countOpen() {
    $data = issues_loadAll();
    $count = 0;
    foreach ($data['issues'] as $issue) {
        if (in_array($issue['status'], ['open', 'reviewing'])) {
            $count++;
        }
    }
    return $count;
}

/**
 * Get issues needing retry today
 */
function issues_getRetryToday() {
    $data = issues_loadAll();
    $today = date('Y-m-d');
    $retries = [];
    foreach ($data['issues'] as $issue) {
        if ($issue['retry_date'] && substr($issue['retry_date'], 0, 10) === $today && $issue['status'] !== 'resolved') {
            $retries[] = $issue;
        }
    }
    return $retries;
}

// ============================================
// RESOLVE ISSUE
// ============================================

/**
 * Resolve an issue
 * 
 * @param string $issueId       Issue ID
 * @param string $resolution    Resolution type: reprint, refund, retry, no_action
 * @param string $resolvedBy    Admin/staff name
 * @param string $notes         Resolution notes
 * @param string|null $retryDate  For retry: date to attempt re-delivery (Y-m-d format)
 * @return array ['success' => bool, 'error' => string]
 */
function issues_resolve($issueId, $resolution, $resolvedBy, $notes = '', $retryDate = null) {
    $data = issues_loadAll();
    
    $found = false;
    foreach ($data['issues'] as &$issue) {
        if ($issue['id'] === $issueId) {
            $issue['status'] = ($resolution === 'retry') ? 'retry_scheduled' : 'resolved';
            $issue['resolution'] = $resolution;
            $issue['resolved_by'] = $resolvedBy;
            $issue['resolved_at'] = date('c');
            $issue['resolution_notes'] = $notes;
            if ($retryDate) {
                $issue['retry_date'] = $retryDate . 'T09:00:00';
            }
            $found = true;
            break;
        }
    }
    unset($issue);
    
    if (!$found) {
        return ['success' => false, 'error' => 'Issue not found: ' . $issueId];
    }
    
    issues_saveAll($data);
    return ['success' => true];
}

/**
 * Update issue status (e.g., open → reviewing)
 */
function issues_updateStatus($issueId, $newStatus) {
    $data = issues_loadAll();
    
    foreach ($data['issues'] as &$issue) {
        if ($issue['id'] === $issueId) {
            $issue['status'] = $newStatus;
            issues_saveAll($data);
            return ['success' => true];
        }
    }
    unset($issue);
    
    return ['success' => false, 'error' => 'Issue not found'];
}

// ============================================
// PHOTO HANDLING
// ============================================

function issues_savePhoto($issueId, $photoData) {
    // Extract base64 data
    $parts = explode(',', $photoData, 2);
    if (count($parts) !== 2) return null;
    
    // Determine extension from mime type
    $ext = 'jpg';
    if (strpos($parts[0], 'png') !== false) $ext = 'png';
    
    $binary = base64_decode($parts[1]);
    if (!$binary) return null;
    
    $filename = $issueId . '.' . $ext;
    $filepath = ISSUE_PHOTOS_DIR . $filename;
    
    if (file_put_contents($filepath, $binary, LOCK_EX)) {
        return 'uploads/issue-photos/' . $filename;
    }
    return null;
}

// ============================================
// NOTIFICATION HELPERS
// ============================================

/**
 * Get issue severity level (for notification priority)
 */
function issues_getSeverity($issueType) {
    $highSeverity = ['damaged_in_transit', 'quality_concern'];
    $medSeverity = ['wrong_order', 'customer_unavailable', 'address_issue', 'vendor_not_ready'];
    
    if (in_array($issueType, $highSeverity)) return 'high';
    if (in_array($issueType, $medSeverity)) return 'medium';
    return 'low';
}

/**
 * Get issue type icon emoji
 */
function issues_getIcon($issueType) {
    $icons = [
        'damaged_in_transit' => "\xF0\x9F\x93\x8C",
        'wrong_order' => "\xE2\x9D\x8C",
        'customer_unavailable' => "\xF0\x9F\x9A\xAB",
        'address_issue' => "\xF0\x9F\x93\x8D",
        'vendor_not_ready' => "\xE2\x8F\xB3",
        'quality_concern' => "\xF0\x9F\x94\x8D",
        'other' => "\xE2\x9D\x93",
    ];
    return $icons[$issueType] ?? "\xE2\x9A\xA0";
}

/**
 * Enrich issue with order data for display
 */
function issues_enrichWithOrderData($issue, $ordersDir = null) {
    if (!$ordersDir) {
        $ordersDir = __DIR__ . '/../uploads/orders/';
    }
    
    $ref = $issue['ref'];
    $orderDir = $ordersDir . $ref . '/';
    $orderFile = $orderDir . 'order.json';
    
    if (file_exists($orderFile)) {
        $orderData = json_decode(file_get_contents($orderFile), true);
        if ($orderData) {
            $issue['customer_name'] = $orderData['name'] ?? '';
            $issue['customer_email'] = $orderData['email'] ?? '';
            $issue['event'] = $orderData['event'] ?? $orderData['event_acronym'] ?? '';
            $issue['material'] = $orderData['material'] ?? '';
            $issue['size'] = ($orderData['width'] ?? '') . '" x ' . ($orderData['height'] ?? '') . '"';
            $issue['due_date'] = $orderData['due_date'] ?? '';
            $issue['due_time'] = $orderData['due_time'] ?? '';
            $issue['courier_name'] = $orderData['dispatch']['courier_name'] ?? '';
        }
    }
    
    return $issue;
}
