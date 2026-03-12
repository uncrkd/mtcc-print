<?php
/**
 * Tracking Number Generation Utilities
 * Handles MTCC tracking number generation with flexible format support
 */

/**
 * Generate MTCC tracking number with support for both legacy and new formats
 * 
 * @param array $order Order data array
 * @param string|null $eventPrefix Optional event prefix (e.g., 'AAIC')
 * @return string Generated tracking number
 */
function generateMTCCTrackingNumber($order, $eventPrefix = null) {
    // Extract order date and number
    $orderDate = new DateTime($order['selectedDate']);
    $orderNumberMatch = preg_match('/(\d+)$/', $order['referenceCode'], $matches);
    $orderNumber = $orderNumberMatch ? $matches[1] : '001';
    
    if ($eventPrefix) {
        // New format: MTCC + Event + Order + Date
        // Example: MTCCAAIC001250905
        $trackingNumber = 'MTCC' . $eventPrefix . str_pad($orderNumber, 3, '0', STR_PAD_LEFT) . $orderDate->format('ymd');
    } else {
        // Legacy format: MTCC + Date + Order
        // Example: MTCC250905001
        $trackingNumber = 'MTCC' . $orderDate->format('ymd') . str_pad($orderNumber, 3, '0', STR_PAD_LEFT);
    }
    
    return $trackingNumber;
}

/**
 * Parse tracking number to extract components
 * Supports both legacy and new formats
 * 
 * @param string $trackingNumber The tracking number to parse
 * @return array|false Array with components or false if invalid
 */
function parseTrackingNumber($trackingNumber) {
    // Remove MTCC prefix
    $withoutPrefix = substr($trackingNumber, 4);
    
    if (strlen($withoutPrefix) === 9) {
        // Legacy format: YYMMDDNNN
        return [
            'format' => 'legacy',
            'date' => substr($withoutPrefix, 0, 6),
            'order_number' => substr($withoutPrefix, 6, 3),
            'event_prefix' => null
        ];
    } elseif (strlen($withoutPrefix) >= 10) {
        // New format: EVENTNNNYYMMDD (variable event prefix length)
        // Extract last 6 characters as date
        $date = substr($withoutPrefix, -6);
        $remaining = substr($withoutPrefix, 0, -6);
        
        // Extract last 3 characters of remaining as order number
        $orderNumber = substr($remaining, -3);
        $eventPrefix = substr($remaining, 0, -3);
        
        return [
            'format' => 'new',
            'date' => $date,
            'order_number' => $orderNumber,
            'event_prefix' => $eventPrefix
        ];
    }
    
    return false;
}

/**
 * Validate tracking number format
 * 
 * @param string $trackingNumber The tracking number to validate
 * @return bool True if valid format
 */
function validateTrackingNumber($trackingNumber) {
    // Must start with MTCC
    if (substr($trackingNumber, 0, 4) !== 'MTCC') {
        return false;
    }
    
    // Must be between 11-20 characters total (reasonable limits)
    $length = strlen($trackingNumber);
    if ($length < 11 || $length > 20) {
        return false;
    }
    
    // Try to parse - if it parses, it's valid
    return parseTrackingNumber($trackingNumber) !== false;
}

/**
 * Get event prefix from order data
 * This can be expanded to determine event prefix based on order properties
 * 
 * @param array $order Order data array
 * @return string|null Event prefix or null for legacy format
 */
function getEventPrefixForOrder($order) {
    // For now, return null to use legacy format
    // This can be modified to return event prefixes based on:
    // - Order date ranges
    // - Customer information
    // - Special order flags
    // - Database lookups
    
    // Example implementation:
    // if (isset($order['event_code'])) {
    //     return $order['event_code'];
    // }
    
    // Check if this is for a specific event based on date or other criteria
    // $orderDate = new DateTime($order['selectedDate']);
    // if ($orderDate >= new DateTime('2025-03-15') && $orderDate <= new DateTime('2025-03-20')) {
    //     return 'AAIC'; // Example event
    // }
    
    return null; // Use legacy format for now
}
?>