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
 * Load statutory holidays map from data/holidays.json.
 * Returns ['YYYY-MM-DD' => 'Holiday name', ...].
 */
function getHolidaysMap() {
    static $holidays = null;
    if ($holidays === null) {
        $path = __DIR__ . '/../data/holidays.json';
        if (!file_exists($path)) {
            $holidays = [];
            return $holidays;
        }
        $data = json_decode(file_get_contents($path), true);
        $holidays = isset($data['holidays']) && is_array($data['holidays']) ? $data['holidays'] : [];
    }
    return $holidays;
}

function isHolidayDatePHP(DateTime $date) {
    $key = $date->format('Y-m-d');
    $holidays = getHolidaysMap();
    return isset($holidays[$key]);
}

function isBusinessDayPHP(DateTime $date) {
    $dow = (int)$date->format('w');
    if ($dow === 0 || $dow === 6) return false;
    return !isHolidayDatePHP($date);
}

function walkBackBusinessDaysPHP(DateTime $fromDate, $numDays) {
    $d = clone $fromDate;
    $remaining = (int)$numDays;
    while ($remaining > 0) {
        $d->modify('-1 day');
        if (isBusinessDayPHP($d)) $remaining--;
    }
    return $d;
}

function getPreviousBusinessDayPHP(DateTime $date) {
    $prev = clone $date;
    do {
        $prev->modify('-1 day');
    } while (!isBusinessDayPHP($prev));
    return $prev;
}

/**
 * Production deadline for a given delivery date + time.
 * Mirrors getProductionDeadline() in script.js.
 *
 * Rules:
 *   - Sat/Sun delivery        → Friday before @ 2 PM
 *   - Monday delivery @ 9am   → Friday before @ 2 PM
 *   - Weekday delivery @ 9am  → previous business day @ 2 PM
 *   - Weekday delivery @ Xpm  → same day @ (X - 3h)
 */
function getProductionDeadlinePHP($deliveryDate, $deliveryTimeValue) {
    $delivery = $deliveryDate instanceof DateTime ? clone $deliveryDate : new DateTime($deliveryDate);
    $delivery->setTime(0, 0, 0);
    $deliveryDow = (int)$delivery->format('w');

    // Sat/Sun → preceding business day (Friday unless Fri is a holiday) @ 2 PM
    if ($deliveryDow === 0 || $deliveryDow === 6) {
        $fri = clone $delivery;
        while ((int)$fri->format('w') !== 5) {
            $fri->modify('-1 day');
        }
        while (!isBusinessDayPHP($fri)) {
            $fri->modify('-1 day');
        }
        $fri->setTime(14, 0, 0);
        return $fri;
    }

    // Monday delivery @ 9am → previous business day (typically Fri) @ 2 PM
    if ($deliveryDow === 1 && $deliveryTimeValue === '9am') {
        $prev = getPreviousBusinessDayPHP($delivery);
        $prev->setTime(14, 0, 0);
        return $prev;
    }

    // Weekday @ 9am → previous business day @ 2 PM
    if ($deliveryTimeValue === '9am') {
        $prev = getPreviousBusinessDayPHP($delivery);
        $prev->setTime(14, 0, 0);
        return $prev;
    }

    // Weekday @ 12pm/3pm/6pm/anytime → delivery hour minus 3h
    $deliveryHourMap = ['12pm' => 12, '3pm' => 15, '6pm' => 18, 'anytime' => 18];
    $deliveryHour = isset($deliveryHourMap[$deliveryTimeValue]) ? $deliveryHourMap[$deliveryTimeValue] : 18;
    $deadline = clone $delivery;
    $deadline->setTime($deliveryHour - 3, 0, 0);
    return $deadline;
}

/**
 * Tier availability is now purely a function of "is the cutoff still in the future".
 * Retained for API compatibility — always returns false (never blocks structurally).
 * The calculateBestTier() loop still checks cutoff-vs-now expiry, which is the real gate.
 */
function isTierBlocked($tierKey, $deliveryDate, $deliveryTimeValue) {
    return false;
}

/**
 * Tier order cutoff = walk back leadDays business days from production deadline,
 * set tier cutoff hour. Same-day tier (lead=0) uses production deadline directly.
 * Mirrors getCutoffDate() in script.js.
 */
function getCutoffDateTime($deliveryDate, $leadDays, $cutoffHour, $deliveryTimeValue = 'anytime') {
    $productionDeadline = getProductionDeadlinePHP($deliveryDate, $deliveryTimeValue);

    if ((int)$leadDays === 0) {
        return $productionDeadline;
    }

    $cutoff = walkBackBusinessDaysPHP($productionDeadline, (int)$leadDays);
    $cutoff->setTime((int)$cutoffHour, 0, 0);
    return $cutoff;
}

/**
 * A delivery time is available iff its production deadline is still in the future.
 * Mirrors isDeliveryTimeAvailable() in script.js.
 */
function isDeliveryTimeAvailable($timeValue, $deliveryDate) {
    $now = new DateTime();
    $delivery = new DateTime($deliveryDate);

    $today = new DateTime();
    $today->setTime(0, 0, 0);
    $deliveryMidnight = clone $delivery;
    $deliveryMidnight->setTime(0, 0, 0);

    if ($deliveryMidnight < $today) return false;

    $productionDeadline = getProductionDeadlinePHP($delivery, $timeValue);
    return $productionDeadline > $now;
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
    $serverSubtotal = $serverTier['price'] + $deliveryFee;
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
