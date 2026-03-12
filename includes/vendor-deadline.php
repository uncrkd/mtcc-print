<?php
/**
 * Vendor Deadline Utility
 * Calculates vendor production deadlines and urgency levels
 * 
 * Uses delivery-config.php for buffer rules and urgency thresholds
 * Uses vendors.json settings for vendor business hours
 *
 * Location: /includes/vendor-deadline.php
 */

/**
 * Load the delivery configuration (cached)
 */
function getVendorDeliveryConfig() {
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/../delivery-config.php';
        if (file_exists($configPath)) {
            $config = require $configPath;
        }
    }
    return $config;
}

/**
 * Load vendor settings (business hours) from vendors.json
 */
function getVendorSettings() {
    static $settings = null;
    if ($settings === null) {
        $vendorsFile = __DIR__ . '/../data/vendors.json';
        if (!file_exists($vendorsFile)) {
            $vendorsFile = __DIR__ . '/../vendors.json';
        }
        if (file_exists($vendorsFile)) {
            $data = json_decode(file_get_contents($vendorsFile), true);
            $settings = $data['settings'] ?? [];
        } else {
            $settings = [];
        }
    }
    return $settings;
}

/**
 * Calculate the vendor production deadline for an order
 *
 * @param string $deliveryDate   YYYY-MM-DD format
 * @param string $deliveryTime   One of: 9am, 12pm, 3pm, 6pm, anytime
 * @param string|null $orderTime ISO datetime when order was placed/pushed (defaults to now)
 * @return array {
 *   'deadline'           => DateTime object (when vendor must have order ready)
 *   'deadline_formatted' => string (human-readable, e.g. "Tue, Mar 3 by 1:00 PM")
 *   'deadline_iso'       => string (ISO 8601 for storage)
 *   'pickup_time'        => DateTime (when courier picks up from vendor)
 *   'pickup_formatted'   => string
 *   'production_hours'   => float (business hours available for production)
 *   'urgency'            => array { 'level', 'color', 'chime', 'label' }
 *   'is_previous_day'    => bool (9am orders need previous-day completion)
 *   'notes'              => string (human-readable explanation)
 * }
 */
function getVendorDeadline($deliveryDate, $deliveryTime, $orderTime = null) {
    $config = getVendorDeliveryConfig();
    $vendorSettings = getVendorSettings();
    
    // Defaults from config or hardcoded fallbacks
    $vendorOpen = $vendorSettings['vendor_hours_open'] ?? $config['business_rules']['vendor_weekday_open'] ?? '09:00';
    $vendorClose = $vendorSettings['vendor_hours_close'] ?? $config['business_rules']['vendor_weekday_close'] ?? '18:00';
    
    $openHour = (int)explode(':', $vendorOpen)[0];
    $openMin = (int)(explode(':', $vendorOpen)[1] ?? 0);
    $closeHour = (int)explode(':', $vendorClose)[0];
    $closeMin = (int)(explode(':', $vendorClose)[1] ?? 0);
    $dailyBusinessHours = ($closeHour + $closeMin/60) - ($openHour + $openMin/60);
    
    // Get buffer for this delivery time
    $buffers = $config['vendor_buffers'] ?? [];
    $buffer = $buffers[$deliveryTime] ?? $buffers['anytime'] ?? ['buffer_hours' => 2];
    $bufferHours = $buffer['buffer_hours'] ?? 2;
    $isPreviousDayPickup = !empty($buffer['previous_day_pickup']);
    
    // Parse dates
    $delivery = new DateTime($deliveryDate);
    $deliveryDow = (int)$delivery->format('w');
    $now = $orderTime ? new DateTime($orderTime) : new DateTime();
    
    // Find the delivery time hour
    $deliveryTimeHour = 18; // default (anytime/6pm)
    foreach ($config['delivery_times'] ?? [] as $dt) {
        if ($dt['value'] === $deliveryTime) {
            $deliveryTimeHour = $dt['hour'];
            break;
        }
    }
    
    // Calculate vendor deadline
    $deadline = clone $delivery;
    $notes = '';
    
    if ($isPreviousDayPickup) {
        // 9am delivery: vendor must finish by close of PREVIOUS business day
        // Courier picks up evening before, delivers next morning
        $prevBizDay = getPreviousBusinessDayPHP($delivery);
        $deadline = clone $prevBizDay;
        $deadline->setTime($closeHour, $closeMin, 0);
        $notes = "Previous-day production required. Vendor must complete by close of business " . $prevBizDay->format('l, M j') . ".";
    } elseif ($deliveryDow === 0 || $deliveryDow === 6) {
        // Weekend delivery: vendor must finish by Friday close
        $friday = clone $delivery;
        while ((int)$friday->format('w') !== 5) {
            $friday->modify('-1 day');
        }
        $deadline = clone $friday;
        $deadline->setTime($closeHour, $closeMin, 0);
        $notes = "Weekend delivery. Vendor must complete by Friday " . $friday->format('M j') . " close.";
    } elseif ($deliveryDow === 1 && $deliveryTime === '9am') {
        // Monday 9am: vendor must finish Friday
        $friday = clone $delivery;
        $friday->modify('-3 days');
        $deadline = clone $friday;
        $deadline->setTime($closeHour, $closeMin, 0);
        $notes = "Monday 9 AM delivery. Vendor must complete by Friday " . $friday->format('M j') . " close.";
    } else {
        // Normal weekday: delivery time minus buffer
        $deadlineHour = $deliveryTimeHour - $bufferHours;
        if ($deadlineHour < $openHour) {
            // Buffer pushes deadline to previous business day
            $prevBizDay = getPreviousBusinessDayPHP($delivery);
            $deadline = clone $prevBizDay;
            // Remaining buffer hours from end of previous day
            $remainingBuffer = $bufferHours - $deliveryTimeHour + $openHour;
            $deadline->setTime(max($openHour, $closeHour - $remainingBuffer), 0, 0);
            $notes = "Buffer requires previous-day completion.";
        } else {
            $deadline->setTime($deadlineHour, 0, 0);
            $notes = "Vendor deadline: " . $deadline->format('g:i A') . " on " . $deadline->format('l, M j') . ".";
        }
    }
    
    // Courier pickup time = deadline (vendor must be ready, courier arrives)
    $pickup = clone $deadline;
    
    // Calculate production hours available (business hours between now and deadline)
    $productionHours = calculateBusinessHours($now, $deadline, $openHour, $openMin, $closeHour, $closeMin);
    
    // Determine urgency level
    $urgencyLevels = $config['vendor_urgency'] ?? [
        ['max_hours' => 3,   'level' => 'urgent',   'color' => 'red',    'chime' => 'triple'],
        ['max_hours' => 6,   'level' => 'rush',     'color' => 'amber',  'chime' => 'double'],
        ['max_hours' => 12,  'level' => 'standard', 'color' => 'normal', 'chime' => 'single'],
        ['max_hours' => 999, 'level' => 'normal',   'color' => 'normal', 'chime' => 'soft'],
    ];
    
    $urgencyLabels = [
        'urgent'   => 'URGENT',
        'rush'     => 'RUSH SAME DAY',
        'standard' => 'Next Day',
        'normal'   => 'Standard',
    ];
    
    $urgency = end($urgencyLevels); // default to last (normal)
    foreach ($urgencyLevels as $level) {
        if ($productionHours <= $level['max_hours']) {
            $urgency = $level;
            break;
        }
    }
    $urgency['label'] = $urgencyLabels[$urgency['level']] ?? ucfirst($urgency['level']);
    
    return [
        'deadline'           => $deadline,
        'deadline_formatted' => $deadline->format('D, M j') . ' by ' . $deadline->format('g:i A'),
        'deadline_iso'       => $deadline->format('c'),
        'pickup_time'        => $pickup,
        'pickup_formatted'   => $pickup->format('D, M j') . ' at ' . $pickup->format('g:i A'),
        'production_hours'   => round($productionHours, 1),
        'urgency'            => $urgency,
        'is_previous_day'    => $isPreviousDayPickup,
        'notes'              => $notes,
    ];
}

/**
 * Calculate available business hours between two DateTimes
 * Only counts hours within vendor open-close window, skipping weekends
 */
function calculateBusinessHours($start, $end, $openHour, $openMin, $closeHour, $closeMin) {
    if ($end <= $start) return 0;
    
    $openTime = $openHour + $openMin / 60;
    $closeTime = $closeHour + $closeMin / 60;
    $dailyHours = $closeTime - $openTime;
    
    $totalHours = 0;
    $current = clone $start;
    
    // Cap to reasonable iteration (30 days max)
    $maxDays = 30;
    $dayCount = 0;
    
    while ($current < $end && $dayCount < $maxDays) {
        $dow = (int)$current->format('w');
        
        // Skip weekends
        if ($dow === 0 || $dow === 6) {
            $current->modify('+1 day');
            $current->setTime($openHour, $openMin, 0);
            $dayCount++;
            continue;
        }
        
        // Get start of work for this day
        $dayStart = clone $current;
        $currentTimeDecimal = (int)$current->format('G') + (int)$current->format('i') / 60;
        
        // If before open, advance to open
        if ($currentTimeDecimal < $openTime) {
            $dayStart->setTime($openHour, $openMin, 0);
            $currentTimeDecimal = $openTime;
        }
        
        // If after close, skip to next day
        if ($currentTimeDecimal >= $closeTime) {
            $current->modify('+1 day');
            $current->setTime($openHour, $openMin, 0);
            $dayCount++;
            continue;
        }
        
        // Get end of work for this day
        $dayEnd = clone $current;
        $dayEnd->setTime($closeHour, $closeMin, 0);
        
        // If deadline is today, cap at deadline
        if ($end->format('Y-m-d') === $current->format('Y-m-d')) {
            $endTimeDecimal = (int)$end->format('G') + (int)$end->format('i') / 60;
            $effectiveEnd = min($endTimeDecimal, $closeTime);
            $effectiveStart = max($currentTimeDecimal, $openTime);
            
            if ($effectiveEnd > $effectiveStart) {
                $totalHours += ($effectiveEnd - $effectiveStart);
            }
            break; // We've reached the deadline day
        } else {
            // Full remaining hours for this day
            $effectiveStart = max($currentTimeDecimal, $openTime);
            if ($closeTime > $effectiveStart) {
                $totalHours += ($closeTime - $effectiveStart);
            }
        }
        
        // Move to next day at open
        $current->modify('+1 day');
        $current->setTime($openHour, $openMin, 0);
        $dayCount++;
    }
    
    return max(0, $totalHours);
}

/**
 * Get the previous business day (skipping weekends)
 */
if (!function_exists('getPreviousBusinessDayPHP')) {
    function getPreviousBusinessDayPHP($date) {
        $prev = clone $date;
        $prev->modify('-1 day');
        while ((int)$prev->format('w') === 0 || (int)$prev->format('w') === 6) {
            $prev->modify('-1 day');
        }
        return $prev;
    }
}

/**
 * Get vendor deadline info for an order data array
 * Convenience wrapper that extracts fields from order format
 *
 * @param array $orderData  Standard order data array
 * @return array|null  Vendor deadline info, or null if insufficient data
 */
function getVendorDeadlineForOrder($orderData) {
    $deliveryDate = $orderData['selectedDate'] ?? $orderData['dueDate'] ?? $orderData['due_date'] ?? null;
    $deliveryTime = $orderData['deliveryTime'] ?? $orderData['delivery_time'] ?? 'anytime';
    $orderTime = $orderData['submittedAt'] ?? $orderData['submitted_at'] ?? $orderData['created_at'] ?? null;
    
    if (!$deliveryDate) return null;
    
    return getVendorDeadline($deliveryDate, $deliveryTime, $orderTime);
}

/**
 * Format vendor deadline for display in notifications/emails
 *
 * @param array $deadlineInfo  Return value from getVendorDeadline()
 * @return string  Formatted string like "⚡ RUSH SAME DAY — Due by Tue, Mar 3 at 1:00 PM (6.0 hrs production)"
 */
function formatVendorDeadlineNotification($deadlineInfo) {
    $icons = [
        'urgent'   => '🚨',
        'rush'     => '⚡',
        'standard' => '📋',
        'normal'   => '📦',
    ];
    
    $urgency = $deadlineInfo['urgency'];
    $icon = $icons[$urgency['level']] ?? '📦';
    $label = $urgency['label'];
    $deadline = $deadlineInfo['deadline_formatted'];
    $hours = $deadlineInfo['production_hours'];
    
    return "{$icon} {$label} — Due {$deadline} ({$hours} hrs production)";
}

/**
 * Get urgency badge HTML for admin display
 *
 * @param array $deadlineInfo  Return value from getVendorDeadline()
 * @return string  HTML badge
 */
function getUrgencyBadgeHTML($deadlineInfo) {
    $urgency = $deadlineInfo['urgency'];
    $hours = $deadlineInfo['production_hours'];
    
    $colors = [
        'urgent'   => ['bg' => '#FEE2E2', 'text' => '#DC2626', 'border' => '#FECACA'],
        'rush'     => ['bg' => '#FEF3C7', 'text' => '#D97706', 'border' => '#FDE68A'],
        'standard' => ['bg' => '#DBEAFE', 'text' => '#2563EB', 'border' => '#BFDBFE'],
        'normal'   => ['bg' => '#F0FDF4', 'text' => '#16A34A', 'border' => '#BBF7D0'],
    ];
    
    $c = $colors[$urgency['level']] ?? $colors['normal'];
    $label = $urgency['label'];
    
    return "<span style=\"display:inline-block;padding:3px 10px;border-radius:12px;font-size:0.78rem;font-weight:600;background:{$c['bg']};color:{$c['text']};border:1px solid {$c['border']}\">{$label} ({$hours}h)</span>";
}
