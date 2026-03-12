<?php
/**
 * Delivery Validation Utility
 * Server-side validation of delivery dates, times, tiers, and pricing
 * 
 * Uses delivery-config.php as single source of truth
 * Mirrors the logic in script.js for consistency
 *
 * Location: /includes/delivery-validation.php (server: /includes/)
 */

/**
 * Load the shared delivery configuration
 */
function getDeliveryConfig() {
    static $config = null;
    if ($config === null) {
        $configPath = __DIR__ . '/../delivery-config.php';
        if (!file_exists($configPath)) {
            error_log('Delivery config not found at: ' . $configPath);
            return null;
        }
        $config = require $configPath;
    }
    return $config;
}

/**
 * Load pricing data from CSV
 */
function loadPricingData($material = 'poster') {
    $filename = ($material === 'fabric') ? 'Fabric Pricing.csv' : 'Poster Paper Pricing.csv';
    $filepath = __DIR__ . '/../' . $filename;
    
    if (!file_exists($filepath)) {
        error_log('Pricing file not found: ' . $filepath);
        return null;
    }
    
    $handle = fopen($filepath, 'r');
    if (!$handle) return null;
    
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return null;
    }
    
    // Clean headers (remove BOM, trim)
    $headers = array_map(function($h) {
        return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h)));
    }, $headers);
    
    $data = [];
    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) !== count($headers)) continue;
        $rowData = [];
        for ($i = 0; $i < count($headers); $i++) {
            $col = $headers[$i];
            $val = trim($row[$i]);
            $rowData[$col] = in_array($col, ['min', 'max']) ? (int)$val : (is_numeric($val) ? (float)$val : $val);
        }
        if (isset($rowData['min']) && isset($rowData['max'])) {
            $data[] = $rowData;
        }
    }
    fclose($handle);
    return $data;
}

/**
 * Find the pricing row for a given poster area
 */
function findPricingRow($area, $material = 'poster') {
    $data = loadPricingData($material);
    if (!$data) return null;
    
    foreach ($data as $row) {
        if ($area >= $row['min'] && $area <= $row['max']) {
            return $row;
        }
    }
    return null;
}

/**
 * Check if a tier is blocked by delivery time rules
 * Mirrors isTierBlockedByDeliveryTime() in script.js
 */
function isTierBlocked($tierKey, $deliveryDate, $deliveryTimeValue) {
    $now = new DateTime();
    $delivery = new DateTime($deliveryDate);
    $currentHour = (int)$now->format('G');
    $deliveryDow = (int)$delivery->format('w'); // 0=Sun, 6=Sat
    $nowDow = (int)$now->format('w');
    
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $deliveryMidnight = clone $delivery;
    $deliveryMidnight->setTime(0, 0, 0);
    $daysDiff = (int)$today->diff($deliveryMidnight)->format('%r%a');
    
    // Same-day tier is never blocked by delivery time rules
    if ($tierKey === 'sameday') return false;
    
    // Next-day tier: only block when delivery is genuinely the next business day
    if ($tierKey === 'nextday') {
        // 9am on literal next day: overnight turnaround not realistic
        if ($deliveryTimeValue === '9am') {
            if ($daysDiff <= 1) return true;
            // Friday ordering for Monday 9am next-day
            if ($nowDow === 5 && $deliveryDow === 1 && $daysDiff <= 3) return true;
        }
        // After 3 PM, only blocked for literal next-day delivery
        if ($currentHour >= 15 && $daysDiff <= 1) return true;
        return false;
    }
    
    // Weekend delivery blocks all non-sameday tiers
    if ($deliveryDow === 0 || $deliveryDow === 6) return true;
    
    // Monday 9am from preceding Fri/Sat/Sun: only sameday realistic
    if ($deliveryDow === 1 && $deliveryTimeValue === '9am' && $daysDiff > 0 && $daysDiff <= 3) return true;
    
    // Ordering from Sat/Sun for the immediately next Monday only
    if ($deliveryDow === 1 && ($nowDow === 0 || $nowDow === 6) && $daysDiff > 0 && $daysDiff <= 2) return true;
    
    // Monday before 9am for same-day Monday delivery
    if ($deliveryDow === 1 && $nowDow === 1 && $currentHour < 9 && $daysDiff === 0) return true;
    
    return false;
}

/**
 * Calculate the cutoff date for a given tier, delivery date, and delivery time
 * Mirrors getCutoffDate() in script.js
 */
function getCutoffDateTime($deliveryDate, $leadDays, $cutoffHour, $deliveryTimeValue = 'anytime') {
    $delivery = new DateTime($deliveryDate);
    $deliveryDow = (int)$delivery->format('w');
    
    // Same-day (lead=0)
    if ($leadDays === 0) {
        // Weekend delivery: cutoff is Friday 3 PM
        if ($deliveryDow === 0 || $deliveryDow === 6) {
            $cutoff = clone $delivery;
            while ((int)$cutoff->format('w') !== 5) {
                $cutoff->modify('-1 day');
            }
            $cutoff->setTime(15, 0, 0);
            return $cutoff;
        }
        // Monday 9am: cutoff is Friday 3 PM
        if ($deliveryDow === 1 && $deliveryTimeValue === '9am') {
            $cutoff = clone $delivery;
            $cutoff->modify('-3 days'); // Monday - 3 = Friday
            $cutoff->setTime(15, 0, 0);
            return $cutoff;
        }
        // Normal weekday same-day
        $cutoff = clone $delivery;
        $cutoff->setTime($cutoffHour, 0, 0);
        return $cutoff;
    }
    
    // Next-day (lead=1)
    if ($leadDays === 1) {
        // Monday delivery: cutoff is Friday
        if ($deliveryDow === 1) {
            $cutoff = clone $delivery;
            $cutoff->modify('-3 days');
            $cutoff->setTime($cutoffHour, 0, 0);
            return $cutoff;
        }
        // Weekend delivery
        if ($deliveryDow === 0 || $deliveryDow === 6) {
            $cutoff = clone $delivery;
            $cutoff->modify('-1 day');
            while ((int)$cutoff->format('w') === 0 || (int)$cutoff->format('w') === 6) {
                $cutoff->modify('-1 day');
            }
            $cutoff->setTime($cutoffHour, 0, 0);
            return $cutoff;
        }
        // Normal weekday
        $cutoff = clone $delivery;
        $cutoff->modify('-1 day');
        while ((int)$cutoff->format('w') === 0 || (int)$cutoff->format('w') === 6) {
            $cutoff->modify('-1 day');
        }
        $cutoff->setTime($cutoffHour, 0, 0);
        return $cutoff;
    }
    
    // 2+ day tiers
    $cutoff = clone $delivery;
    $remaining = $leadDays;
    while ($remaining > 0) {
        $cutoff->modify('-1 day');
        $dow = (int)$cutoff->format('w');
        if ($dow !== 0 && $dow !== 6) {
            $remaining--;
        }
    }
    $cutoff->setTime($cutoffHour, 0, 0);
    return $cutoff;
}

/**
 * Check if a delivery time is available for a given date
 * Mirrors isDeliveryTimeAvailable() in script.js
 */
function isDeliveryTimeAvailable($timeValue, $deliveryDate) {
    $config = getDeliveryConfig();
    if (!$config) return true; // Fail open if config missing
    
    $gates = $config['delivery_time_gates'] ?? [];
    if (!isset($gates[$timeValue])) return true;
    
    $gate = $gates[$timeValue];
    $now = new DateTime();
    $delivery = new DateTime($deliveryDate);
    
    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $deliveryMidnight = clone $delivery;
    $deliveryMidnight->setTime(0, 0, 0);
    $daysDiff = (int)$today->diff($deliveryMidnight)->format('%r%a');
    
    $currentHour = (int)$now->format('G');
    $nowDow = (int)$now->format('w');
    $deliveryDow = (int)$delivery->format('w');
    
    // 2+ days: generally available except weekend rules
    if ($daysDiff >= 2) {
        if ($deliveryDow === 0 || $deliveryDow === 6) {
            return isBeforeFridayCutoffPHP($now);
        }
        if ($deliveryDow === 1 && $timeValue === '9am') {
            return isBeforeFridayCutoffPHP($now);
        }
        return true;
    }
    
    // Same-day
    if ($daysDiff === 0) {
        if ($nowDow === 0 || $nowDow === 6) return false;
        if ($gate['gate_context'] === 'previous_day') return false;
        return $currentHour < $gate['gate_hour'];
    }
    
    // Next-day
    if ($daysDiff === 1) {
        if ($deliveryDow === 0 || $deliveryDow === 6) {
            return isBeforeFridayCutoffPHP($now);
        }
        if ($deliveryDow === 1 && $nowDow === 0) {
            return ($timeValue !== '9am');
        }
        if ($gate['gate_context'] === 'previous_day') {
            return $currentHour < $gate['gate_hour'];
        }
        return true;
    }
    
    return false;
}

/**
 * Helper: is current time before Friday 3 PM?
 */
function isBeforeFridayCutoffPHP($now) {
    $dow = (int)$now->format('w');
    $hour = (int)$now->format('G');
    
    if ($dow === 5 && $hour < 15) return true;
    if ($dow >= 1 && $dow <= 4) return true;
    return false;
}

/**
 * Determine the best available tier for a given order
 * Returns: ['tier_key' => string, 'price' => float, 'label' => string] or null
 */
function calculateBestTier($deliveryDate, $deliveryTimeValue, $area, $material = 'poster') {
    $config = getDeliveryConfig();
    if (!$config) return null;
    
    $row = findPricingRow($area, $material);
    if (!$row) return null;
    
    $now = new DateTime();
    $bestTier = null;
    $closestTime = PHP_INT_MAX;
    
    foreach ($config['tiers'] as $tier) {
        $cutoff = getCutoffDateTime($deliveryDate, $tier['lead'], $tier['cutoffHour'], $deliveryTimeValue);
        $isExpired = $cutoff <= $now;
        $isBlocked = isTierBlocked($tier['key'], $deliveryDate, $deliveryTimeValue);
        $priceValue = $row[$tier['key']] ?? 0;
        
        if (!$isExpired && !$isBlocked && $cutoff->getTimestamp() < $closestTime && $priceValue > 0) {
            $closestTime = $cutoff->getTimestamp();
            $bestTier = [
                'tier_key' => $tier['key'],
                'price' => (float)$priceValue,
                'label' => $tier['label'],
                'days' => $tier['days'],
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            ];
        }
    }
    
    return $bestTier;
}

/**
 * Validate a submitted order's pricing
 * 
 * Returns an array with:
 *   'valid' => bool
 *   'server_tier' => array (the server-calculated best tier)
 *   'server_price' => float (the correct base price)
 *   'server_total' => float (recalculated total)
 *   'submitted_price' => float
 *   'submitted_total' => float
 *   'message' => string (error message if invalid)
 *   'corrected' => bool (true if server used a different price than submitted)
 */
function validateOrderPricing($orderData) {
    $width = (float)($orderData['width'] ?? 0);
    $height = (float)($orderData['height'] ?? 0);
    $material = $orderData['material'] ?? 'poster';
    $deliveryDate = $orderData['selectedDate'] ?? '';
    $deliveryTime = $orderData['deliveryTime'] ?? 'anytime';
    $submittedBasePrice = (float)($orderData['basePrice'] ?? 0);
    $submittedTotal = (float)($orderData['total'] ?? 0);
    $submittedTier = $orderData['tier'] ?? '';
    $deliveryFee = (float)($orderData['deliveryFee'] ?? 0);
    $conversionFee = (float)($orderData['conversionFee'] ?? 0);
    $taxRate = 0.13;
    
    $result = [
        'valid' => false,
        'server_tier' => null,
        'server_price' => 0,
        'server_total' => 0,
        'submitted_price' => $submittedBasePrice,
        'submitted_total' => $submittedTotal,
        'message' => '',
        'corrected' => false,
    ];
    
    // Basic validation
    if ($width <= 0 || $height <= 0) {
        $result['message'] = 'Invalid poster dimensions';
        return $result;
    }
    if (empty($deliveryDate)) {
        $result['message'] = 'Delivery date is required';
        return $result;
    }
    if (!in_array($material, ['poster', 'fabric', 'paper'])) {
        $result['message'] = 'Invalid material type';
        return $result;
    }
    // Normalize material name
    if ($material === 'paper') $material = 'poster';
    
    // Validate delivery time is available
    if (!isDeliveryTimeAvailable($deliveryTime, $deliveryDate)) {
        $result['message'] = 'The selected delivery time is no longer available for this date. Please refresh and try again.';
        return $result;
    }
    
    // Calculate the server-side best tier and price
    $area = $width * $height;
    $serverTier = calculateBestTier($deliveryDate, $deliveryTime, $area, $material);
    
    if (!$serverTier) {
        $result['message'] = 'No pricing tiers are currently available for the selected date and delivery time. Please select a different date.';
        return $result;
    }
    
    $result['server_tier'] = $serverTier;
    $result['server_price'] = $serverTier['price'];
    
    // Calculate server total
    $serverSubtotal = $serverTier['price'] + $deliveryFee + $conversionFee;
    $serverTax = round($serverSubtotal * $taxRate, 2);
    $result['server_total'] = round($serverSubtotal + $serverTax, 2);
    
    // Compare submitted price to server price
    // Allow a small tolerance for floating point rounding (1 cent)
    $priceDiff = abs($submittedBasePrice - $serverTier['price']);
    
    if ($priceDiff > 0.01) {
        // Price mismatch - the submitted price doesn't match what the server calculates
        // This could be form manipulation OR a timing issue where the tier expired between
        // when the user saw the price and when they submitted
        
        // Use the SERVER-calculated price (always charge the correct amount)
        $result['corrected'] = true;
        $result['message'] = 'Pricing has been updated since you started your order. The current price has been applied.';
        
        // Log the discrepancy for monitoring
        error_log(sprintf(
            'Pricing mismatch: submitted=$%.2f (%s), server=$%.2f (%s) | date=%s time=%s area=%d material=%s',
            $submittedBasePrice, $submittedTier,
            $serverTier['price'], $serverTier['label'],
            $deliveryDate, $deliveryTime, $area, $material
        ));
    }
    
    $result['valid'] = true;
    return $result;
}
