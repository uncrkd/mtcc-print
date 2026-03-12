<?php
/**
 * Timer Helper Functions - MTCC Print Services
 * Generates data attributes for timer-utils.js
 * 
 * Location: /includes/timer-helpers.php
 * 
 * Usage in PHP templates:
 * 
 *   Countdown to deadline:
 *     <span class="timer-compact" <?= timerCountdown($dueTimestamp) ?>>--:--:--</span>
 * 
 *   Countdown with label:
 *     <span <?= timerCountdown($dueTimestamp, 'Due in', 'dhms') ?>>--:--:--</span>
 * 
 *   Countup from start:
 *     <span <?= timerCountup($startTimestamp, 'Elapsed') ?>>--:--:--</span>
 * 
 *   Countup with warning threshold (turns amber after 4hrs):
 *     <span <?= timerCountup($startTimestamp, 'In Status', 'hms', 14400) ?>>--:--:--</span>
 */

/**
 * Generate countdown timer attributes
 * 
 * @param int|string $target  Unix timestamp or datetime string of deadline
 * @param string $label       Optional prefix label (e.g., "Due in")
 * @param string $format      "dhms" (default), "hms", or "compact"
 * @param int $window         Total window in seconds for urgency % calc (0 = absolute thresholds)
 * @param string $overdueLabel Custom text for overdue state
 * @return string HTML attributes string
 */
function timerCountdown($target, $label = '', $format = 'dhms', $window = 0, $overdueLabel = '') {
    if (is_string($target) && !is_numeric($target)) {
        $target = strtotime($target);
    }
    if (!$target || $target <= 0) return '';
    
    $attrs = 'data-timer-type="countdown"';
    $attrs .= ' data-timer-target="' . intval($target) . '"';
    if ($format && $format !== 'dhms') $attrs .= ' data-timer-format="' . htmlspecialchars($format) . '"';
    if ($label) $attrs .= ' data-timer-label="' . htmlspecialchars($label) . '"';
    if ($window > 0) $attrs .= ' data-timer-window="' . intval($window) . '"';
    if ($overdueLabel) $attrs .= ' data-timer-overdue-label="' . htmlspecialchars($overdueLabel) . '"';
    
    return $attrs;
}

/**
 * Generate countup timer attributes
 * 
 * @param int|string $start    Unix timestamp or datetime string of start time
 * @param string $label        Optional prefix label (e.g., "Elapsed", "In Status")
 * @param string $format       "dhms" (default), "hms", or "compact"
 * @param int $warnAfter       Seconds after which timer shows caution color (0 = no warning)
 * @return string HTML attributes string
 */
function timerCountup($start, $label = '', $format = 'hms', $warnAfter = 0) {
    if (is_string($start) && !is_numeric($start)) {
        $start = strtotime($start);
    }
    if (!$start || $start <= 0) return '';
    
    $attrs = 'data-timer-type="countup"';
    $attrs .= ' data-timer-start="' . intval($start) . '"';
    if ($format && $format !== 'dhms') $attrs .= ' data-timer-format="' . htmlspecialchars($format) . '"';
    if ($label) $attrs .= ' data-timer-label="' . htmlspecialchars($label) . '"';
    if ($warnAfter > 0) $attrs .= ' data-timer-warn-after="' . intval($warnAfter) . '"';
    
    return $attrs;
}

/**
 * Calculate vendor deadline from customer delivery deadline
 * Uses flat 3-hour buffer (2hr print + 1hr delivery)
 * Vendor hours: 9am-6pm
 * 
 * @param string $dueDate     Customer due date (Y-m-d)
 * @param string $dueTime     Customer due time (9am, 12pm, 3pm, 6pm, anytime)
 * @return int Unix timestamp of vendor deadline
 */
function calculateVendorDeadline($dueDate, $dueTime = 'anytime') {
    $timeHours = [
        '9am'     => 9,
        '12pm'    => 12,
        '3pm'     => 15,
        '6pm'     => 18,
        'anytime' => 18,
    ];
    
    $customerHour = $timeHours[$dueTime] ?? 18;
    $bufferHours = 3; // 2hr print + 1hr delivery — flat for all scenarios
    
    $vendorOpen = 9;   // 9:00 AM
    $vendorClose = 18;  // 6:00 PM
    
    // Customer deadline timestamp
    $customerDeadline = strtotime($dueDate . ' ' . sprintf('%02d:00:00', $customerHour));
    
    // Vendor must be ready: customer deadline minus 1hr (delivery buffer)
    $vendorReadyBy = $customerDeadline - (1 * 3600);
    
    // Vendor must receive order: ready time minus 2hr (print buffer)  
    $vendorReceiveBy = $vendorReadyBy - (2 * 3600);
    
    // Check if vendor ready-by time falls within vendor hours
    $readyHour = (int)date('G', $vendorReadyBy);
    
    if ($readyHour < $vendorOpen) {
        // Before vendor opens → must be done previous business day by close
        $prevDay = strtotime('-1 day', strtotime($dueDate));
        // Skip weekends (if applicable — currently vendors work all week during conventions)
        $vendorReadyBy = strtotime(date('Y-m-d', $prevDay) . ' ' . sprintf('%02d:00:00', $vendorClose));
        $vendorReceiveBy = $vendorReadyBy - (2 * 3600);
    }
    
    // The vendor deadline is when they must have the order ready
    return $vendorReadyBy;
}

/**
 * Get timer urgency class from PHP (for server-side initial render)
 * Matches the JS getUrgencyClass logic
 * 
 * @param int $remainingSeconds Seconds remaining (negative = overdue)
 * @param int $totalWindow      Total window for % calculation (0 = absolute)
 * @return string CSS class name
 */
function getTimerUrgencyClass($remainingSeconds, $totalWindow = 0) {
    if ($remainingSeconds <= 0) return 'timer-overdue';
    
    if (!$totalWindow || $totalWindow <= 0) {
        if ($remainingSeconds <= 3600) return 'timer-critical';
        if ($remainingSeconds <= 7200) return 'timer-warning';
        if ($remainingSeconds <= 14400) return 'timer-caution';
        return 'timer-safe';
    }
    
    $pct = $remainingSeconds / $totalWindow;
    if ($pct <= 0.10) return 'timer-critical';
    if ($pct <= 0.25) return 'timer-warning';
    if ($pct <= 0.50) return 'timer-caution';
    return 'timer-safe';
}

/**
 * Get delivery deadline as unix timestamp from order data
 * 
 * @param array $order Order data array
 * @return int|null Unix timestamp or null if no due date
 */
function getDeliveryDeadlineTimestamp($order) {
    $dueDate = $order['due_date'] ?? $order['dueDate'] ?? null;
    if (!$dueDate) return null;
    
    $timeMap = [
        '9am'     => 9,
        '12pm'    => 12,
        '3pm'     => 15,
        '6pm'     => 18,
        'anytime' => 18,
    ];
    
    $dueTime = $order['due_time'] ?? $order['dueTime'] ?? 'anytime';
    $hour = $timeMap[$dueTime] ?? 18;
    
    return strtotime($dueDate . ' ' . sprintf('%02d:00:00', $hour));
}
