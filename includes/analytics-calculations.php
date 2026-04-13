<?php
/**
 * Shared Analytics Calculations
 * MTCC Print Services - Revenue & Order Analytics
 * 
 * This file provides consistent calculation functions used across:
 * - admin-orders.php (dashboard analytics)
 * - admin-reports.php (revenue reports)
 * - events-manager.php (event statistics)
 * 
 * All monetary calculations use CAD.
 * HST rate: 13%
 * MTCC Venue Fee: 10% of base revenue
 */

class AnalyticsCalculator {
    
    // Status categories
    const PAID_STATUSES = ['paid', 'preflight', 'printing', 'ready', 'shipped', 'delivered', 'pickedup'];
    const PENDING_STATUSES = ['unpaid', 'file_issue'];
    const EXCLUDED_FROM_REVENUE = ['cancelled', 'refunded'];
    const ALL_STATUSES = ['unpaid', 'paid', 'preflight', 'file_issue', 'printing', 'ready', 'shipped', 'delivered', 'pickedup', 'unclaimed', 'missing', 'cancelled', 'refunded'];
    
    // Tax rate
    const HST_RATE = 0.13;
    
    // MTCC Venue Fee rate (percentage of base revenue)
    const VENUE_FEE_RATE = 0.10;
    
    // Refund reason options
    const REFUND_REASONS = [
        'customer_request' => 'Customer Request',
        'duplicate_order' => 'Duplicate Order',
        'print_quality' => 'Print Quality Issue',
        'file_issue' => 'File Issue',
        'late_delivery' => 'Late Delivery',
        'damaged' => 'Damaged',
        'other' => 'Other'
    ];
    
    // Turnaround tier mapping
    const TURNAROUND_TIERS = [
        'early' => 'Early Bird',
        'standard' => 'Standard',
        '3days' => '3 Days',
        '2days' => '2 Days',
        'nextday' => 'Next Day',
        'sameday' => 'Same Day'
    ];
    
    /**
     * Load all orders from the orders directory
     * @param string $orderDir Path to orders directory
     * @return array Array of order data with status included
     */
    public static function loadOrders($orderDir = 'uploads/orders/', $statusFile = 'data/statuses.json') {
        $orders = [];
        $statuses = self::loadStatuses($statusFile);
        
        if (!is_dir($orderDir)) {
            return $orders;
        }
        
        $files = glob($orderDir . '*-order.json');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $orderData = json_decode($content, true);
            
            if ($orderData && !empty($orderData['referenceCode'])) {
                $refCode = $orderData['referenceCode'];
                $orderData['status'] = $statuses[$refCode] ?? 'unpaid';
                $orderData['filename'] = basename($file);
                $orders[] = $orderData;
            }
        }
        
        return $orders;
    }
    
    /**
     * Load statuses from statuses.json
     * @param string $statusFile Path to status file
     * @return array Associative array of reference codes to statuses
     */
    public static function loadStatuses($statusFile = 'data/statuses.json') {
        if (file_exists($statusFile)) {
            return json_decode(file_get_contents($statusFile), true) ?: [];
        }
        return [];
    }
    
    // =========================================================================
    // REVENUE CALCULATIONS
    // =========================================================================
    
    /**
     * Calculate gross revenue (before refunds)
     * @param array $orders Array of orders
     * @param array $excludeStatuses Statuses to exclude (default: cancelled only)
     * @return float Gross revenue
     */
    public static function getGrossRevenue($orders, $excludeStatuses = ['cancelled']) {
        $total = 0;
        foreach ($orders as $order) {
            if (!in_array($order['status'] ?? '', $excludeStatuses)) {
                $total += (float)($order['pricing']['total'] ?? 0);
            }
        }
        return $total;
    }
    
    /**
     * Calculate net revenue (gross minus refunds)
     * @param array $orders Array of orders
     * @return float Net revenue
     */
    public static function getNetRevenue($orders) {
        $gross = self::getGrossRevenue($orders, ['cancelled']);
        $refunded = self::getRefundedRevenue($orders);
        return $gross - $refunded;
    }
    
    /**
     * Calculate base revenue (poster printing only, excludes fees)
     * @param array $orders Array of orders
     * @param array $includeStatuses Only include these statuses (default: paid+)
     * @return float Base revenue
     */
    public static function getBaseRevenue($orders, $includeStatuses = null) {
        if ($includeStatuses === null) {
            $includeStatuses = self::PAID_STATUSES;
        }
        
        $total = 0;
        foreach ($orders as $order) {
            if (in_array($order['status'] ?? '', $includeStatuses)) {
                $total += (float)($order['pricing']['basePrice'] ?? 0);
            }
        }
        return $total;
    }
    
    /**
     * Calculate MTCC Venue Fee (10% of base revenue)
     * @param array $orders Array of orders
     * @param array $includeStatuses Only include these statuses
     * @return float Venue fee amount
     */
    public static function getMTCCVenueFee($orders, $includeStatuses = null) {
        $baseRevenue = self::getBaseRevenue($orders, $includeStatuses);
        return $baseRevenue * self::VENUE_FEE_RATE;
    }
    
    /**
     * Calculate total HST collected
     * @param array $orders Array of orders
     * @param array $includeStatuses Only include these statuses
     * @return float HST amount
     */
    public static function getHSTCollected($orders, $includeStatuses = null) {
        if ($includeStatuses === null) {
            $includeStatuses = self::PAID_STATUSES;
        }
        
        $total = 0;
        foreach ($orders as $order) {
            if (in_array($order['status'] ?? '', $includeStatuses)) {
                $total += (float)($order['pricing']['tax'] ?? 0);
            }
        }
        return $total;
    }
    
    /**
     * Calculate delivery fee revenue
     * @param array $orders Array of orders
     * @param array $includeStatuses Only include these statuses
     * @return float Delivery fee total
     */
    public static function getDeliveryRevenue($orders, $includeStatuses = null) {
        if ($includeStatuses === null) {
            $includeStatuses = self::PAID_STATUSES;
        }
        
        $total = 0;
        foreach ($orders as $order) {
            if (in_array($order['status'] ?? '', $includeStatuses)) {
                $total += (float)($order['pricing']['deliveryFee'] ?? 0);
            }
        }
        return $total;
    }
    
    /**
     * Get complete revenue breakdown
     * @param array $orders Array of orders
     * @return array Revenue breakdown with all components
     */
    public static function getRevenueBreakdown($orders) {
        $paidOrders = array_filter($orders, function($o) {
            return in_array($o['status'] ?? '', self::PAID_STATUSES);
        });
        
        $refundedOrders = array_filter($orders, function($o) {
            return ($o['status'] ?? '') === 'refunded';
        });
        
        $baseRevenue = self::getBaseRevenue($orders);
        $deliveryRevenue = self::getDeliveryRevenue($orders);
        $hstCollected = self::getHSTCollected($orders);
        $grossRevenue = self::getGrossRevenue($orders, ['cancelled']);
        $refundedRevenue = self::getRefundedRevenue($orders);
        $netRevenue = $grossRevenue - $refundedRevenue;
        $venueFee = self::getMTCCVenueFee($orders);

        return [
            'gross_revenue' => $grossRevenue,
            'refunded_revenue' => $refundedRevenue,
            'net_revenue' => $netRevenue,
            'base_revenue' => $baseRevenue,
            'delivery_revenue' => $deliveryRevenue,
            'hst_collected' => $hstCollected,
            'venue_fee' => $venueFee,
            'subtotal' => $baseRevenue + $deliveryRevenue,
            'paid_order_count' => count($paidOrders),
            'refunded_order_count' => count($refundedOrders)
        ];
    }
    
    // =========================================================================
    // REFUND CALCULATIONS
    // =========================================================================
    
    /**
     * Get refunded orders
     * @param array $orders Array of orders
     * @return array Refunded orders only
     */
    public static function getRefundedOrders($orders) {
        return array_filter($orders, function($o) {
            return ($o['status'] ?? '') === 'refunded';
        });
    }
    
    /**
     * Calculate total refunded revenue
     * @param array $orders Array of orders
     * @return float Total refunded amount
     */
    public static function getRefundedRevenue($orders) {
        $total = 0;
        foreach ($orders as $order) {
            if (($order['status'] ?? '') === 'refunded') {
                // Check for partial refund amount first
                if (isset($order['refund']['refundAmount'])) {
                    $total += (float)$order['refund']['refundAmount'];
                } else {
                    // Full refund - use order total
                    $total += (float)($order['pricing']['total'] ?? 0);
                }
            }
        }
        return $total;
    }
    
    /**
     * Get refund statistics
     * @param array $orders Array of orders
     * @return array Refund statistics
     */
    public static function getRefundStats($orders) {
        $refundedOrders = self::getRefundedOrders($orders);
        $totalOrders = count($orders);
        $refundCount = count($refundedOrders);
        
        // Count by reason
        $byReason = [];
        foreach (self::REFUND_REASONS as $key => $label) {
            $byReason[$key] = [
                'label' => $label,
                'count' => 0,
                'amount' => 0
            ];
        }
        
        foreach ($refundedOrders as $order) {
            $reason = $order['refund']['refundReason'] ?? 'other';
            if (!isset($byReason[$reason])) {
                $reason = 'other';
            }
            $byReason[$reason]['count']++;
            
            $amount = isset($order['refund']['refundAmount']) 
                ? (float)$order['refund']['refundAmount']
                : (float)($order['pricing']['total'] ?? 0);
            $byReason[$reason]['amount'] += $amount;
        }
        
        return [
            'count' => $refundCount,
            'total_amount' => self::getRefundedRevenue($orders),
            'percentage' => $totalOrders > 0 ? ($refundCount / $totalOrders) * 100 : 0,
            'by_reason' => $byReason
        ];
    }
    
    // =========================================================================
    // CANCELLED ORDER CALCULATIONS
    // =========================================================================
    
    /**
     * Get cancelled orders
     * @param array $orders Array of orders
     * @return array Cancelled orders only
     */
    public static function getCancelledOrders($orders) {
        return array_filter($orders, function($o) {
            return ($o['status'] ?? '') === 'cancelled';
        });
    }
    
    /**
     * Get cancelled order statistics
     * @param array $orders Array of orders
     * @return array Cancelled order stats
     */
    public static function getCancelledStats($orders) {
        $cancelledOrders = self::getCancelledOrders($orders);
        $totalOrders = count($orders);
        $cancelledCount = count($cancelledOrders);
        
        // Calculate would-have-been revenue
        $potentialRevenue = 0;
        foreach ($cancelledOrders as $order) {
            $potentialRevenue += (float)($order['pricing']['total'] ?? 0);
        }
        
        return [
            'count' => $cancelledCount,
            'potential_revenue' => $potentialRevenue,
            'percentage' => $totalOrders > 0 ? ($cancelledCount / $totalOrders) * 100 : 0
        ];
    }
    
    // =========================================================================
    // ORDER COUNT & STATUS BREAKDOWN
    // =========================================================================
    
    /**
     * Get order counts by various criteria
     * @param array $orders Array of orders
     * @return array Order count statistics
     */
    public static function getOrderCounts($orders) {
        $total = count($orders);
        $today = date('Y-m-d');
        
        $todayOrders = array_filter($orders, function($o) use ($today) {
            $submittedAt = $o['submittedAt'] ?? null;
            if (!$submittedAt) return false;
            return date('Y-m-d', strtotime($submittedAt)) === $today;
        });
        
        $paidOrders = array_filter($orders, function($o) {
            return in_array($o['status'] ?? '', self::PAID_STATUSES);
        });
        
        $pendingOrders = array_filter($orders, function($o) {
            return in_array($o['status'] ?? '', self::PENDING_STATUSES);
        });
        
        return [
            'total' => $total,
            'today' => count($todayOrders),
            'paid' => count($paidOrders),
            'pending' => count($pendingOrders),
            'cancelled' => count(self::getCancelledOrders($orders)),
            'refunded' => count(self::getRefundedOrders($orders))
        ];
    }
    
    /**
     * Get status breakdown
     * @param array $orders Array of orders
     * @param bool $excludeCancelled Whether to exclude cancelled from main breakdown
     * @return array Status counts
     */
    public static function getStatusBreakdown($orders, $excludeCancelled = true) {
        $breakdown = [];
        
        foreach (self::ALL_STATUSES as $status) {
            if ($excludeCancelled && $status === 'cancelled') {
                continue;
            }
            
            $breakdown[$status] = count(array_filter($orders, function($o) use ($status) {
                return ($o['status'] ?? '') === $status;
            }));
        }
        
        return $breakdown;
    }
    
    // =========================================================================
    // TURNAROUND TIER BREAKDOWN
    // =========================================================================
    
    /**
     * Determine turnaround class from tier name
     * @param string $tier Tier name from pricing
     * @return string Turnaround class key
     */
    public static function getTurnaroundClass($tier) {
        $tierLower = strtolower($tier ?? '');
        
        // Handle both formats: "Standard" and "Standard (5 Days)" and "Last Minute (Same Day)"
        // Also handle key-based values like "sameday", "nextday", etc.
        
        if (strpos($tierLower, 'last minute') !== false || $tierLower === 'sameday' || strpos($tierLower, 'same day') !== false) {
            return 'sameday';
        } elseif (strpos($tierLower, 'critical') !== false || $tierLower === 'nextday' || strpos($tierLower, 'next day') !== false) {
            return 'nextday';
        } elseif (strpos($tierLower, 'urgent') !== false || $tierLower === '2days' || strpos($tierLower, '2 day') !== false) {
            return '2days';
        } elseif (strpos($tierLower, 'rush') !== false || $tierLower === '3days' || strpos($tierLower, '3 day') !== false) {
            return '3days';
        } elseif (strpos($tierLower, 'early') !== false) {
            return 'early';
        }
        // Default to standard for "Standard", "Standard (5 Days)", or unknown
        return 'standard';
    }
    
    /**
     * Get turnaround tier breakdown
     * @param array $orders Array of orders
     * @param bool $excludeCancelledRefunded Whether to exclude cancelled/refunded
     * @return array Turnaround breakdown with counts and revenue
     */
    public static function getTurnaroundBreakdown($orders, $excludeCancelledRefunded = true) {
        $breakdown = [];
        
        foreach (self::TURNAROUND_TIERS as $key => $label) {
            $breakdown[$key] = [
                'label' => $label,
                'count' => 0,
                'revenue' => 0
            ];
        }
        
        foreach ($orders as $order) {
            if ($excludeCancelledRefunded && in_array($order['status'] ?? '', self::EXCLUDED_FROM_REVENUE)) {
                continue;
            }
            
            $tier = $order['pricing']['tier'] ?? 'Standard';
            $class = self::getTurnaroundClass($tier);
            
            $breakdown[$class]['count']++;
            $breakdown[$class]['revenue'] += (float)($order['pricing']['total'] ?? 0);
        }
        
        return $breakdown;
    }
    
    // =========================================================================
    // SIZE BREAKDOWN
    // =========================================================================
    
    /**
     * Get size breakdown
     * @param array $orders Array of orders
     * @param int $limit Number of top sizes to return
     * @param bool $excludeCancelledRefunded Whether to exclude cancelled/refunded
     * @return array Size breakdown sorted by count
     */
    public static function getSizeBreakdown($orders, $limit = 10, $excludeCancelledRefunded = true) {
        $sizes = [];
        
        foreach ($orders as $order) {
            if ($excludeCancelledRefunded && in_array($order['status'] ?? '', self::EXCLUDED_FROM_REVENUE)) {
                continue;
            }
            
            if (!isset($order['dimensions']['width']) || !isset($order['dimensions']['height'])) {
                continue;
            }
            
            $size = $order['dimensions']['width'] . 'x' . $order['dimensions']['height'];
            
            if (!isset($sizes[$size])) {
                $sizes[$size] = [
                    'size' => $size,
                    'width' => $order['dimensions']['width'],
                    'height' => $order['dimensions']['height'],
                    'count' => 0,
                    'revenue' => 0
                ];
            }
            
            $sizes[$size]['count']++;
            $sizes[$size]['revenue'] += (float)($order['pricing']['total'] ?? 0);
        }
        
        // Sort by count descending
        usort($sizes, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return array_slice($sizes, 0, $limit);
    }
    
    // =========================================================================
    // MATERIAL & DELIVERY BREAKDOWN
    // =========================================================================
    
    /**
     * Get material breakdown (poster vs fabric)
     * @param array $orders Array of orders
     * @param bool $excludeCancelledRefunded Whether to exclude cancelled/refunded
     * @return array Material breakdown with counts, percentages, and revenue
     */
    public static function getMaterialBreakdown($orders, $excludeCancelledRefunded = true) {
        $poster = ['count' => 0, 'revenue' => 0];
        $fabric = ['count' => 0, 'revenue' => 0];
        
        foreach ($orders as $order) {
            if ($excludeCancelledRefunded && in_array($order['status'] ?? '', self::EXCLUDED_FROM_REVENUE)) {
                continue;
            }
            
            $material = $order['material'] ?? 'poster';
            $revenue = (float)($order['pricing']['total'] ?? 0);
            
            if ($material === 'fabric') {
                $fabric['count']++;
                $fabric['revenue'] += $revenue;
            } else {
                $poster['count']++;
                $poster['revenue'] += $revenue;
            }
        }
        
        $total = $poster['count'] + $fabric['count'];
        
        return [
            'poster' => [
                'count' => $poster['count'],
                'revenue' => $poster['revenue'],
                'percentage' => $total > 0 ? round(($poster['count'] / $total) * 100, 1) : 0
            ],
            'fabric' => [
                'count' => $fabric['count'],
                'revenue' => $fabric['revenue'],
                'percentage' => $total > 0 ? round(($fabric['count'] / $total) * 100, 1) : 0
            ],
            'total' => $total
        ];
    }
    
    /**
     * Get delivery breakdown (MTCC vs Office)
     * @param array $orders Array of orders
     * @param bool $excludeCancelledRefunded Whether to exclude cancelled/refunded
     * @return array Delivery breakdown
     */
    public static function getDeliveryBreakdown($orders, $excludeCancelledRefunded = true) {
        $mtcc = ['count' => 0, 'revenue' => 0];
        $office = ['count' => 0, 'revenue' => 0];
        
        foreach ($orders as $order) {
            if ($excludeCancelledRefunded && in_array($order['status'] ?? '', self::EXCLUDED_FROM_REVENUE)) {
                continue;
            }
            
            $delivery = $order['deliveryOption'] ?? 'mtcc';
            $revenue = (float)($order['pricing']['total'] ?? 0);
            
            if ($delivery === 'office') {
                $office['count']++;
                $office['revenue'] += $revenue;
            } else {
                $mtcc['count']++;
                $mtcc['revenue'] += $revenue;
            }
        }
        
        $total = $mtcc['count'] + $office['count'];
        
        return [
            'mtcc' => [
                'count' => $mtcc['count'],
                'revenue' => $mtcc['revenue'],
                'percentage' => $total > 0 ? round(($mtcc['count'] / $total) * 100, 1) : 0
            ],
            'office' => [
                'count' => $office['count'],
                'revenue' => $office['revenue'],
                'percentage' => $total > 0 ? round(($office['count'] / $total) * 100, 1) : 0
            ],
            'total' => $total
        ];
    }
    
    // =========================================================================
    // EVENT ANALYTICS
    // =========================================================================
    
    /**
     * Extract event prefix from reference code
     * @param string $referenceCode Order reference code
     * @return string Event prefix
     */
    public static function getEventPrefix($referenceCode) {
        $parts = explode('-', $referenceCode ?? '');
        return strtoupper($parts[0] ?? 'UNKNOWN');
    }
    
    /**
     * Get analytics grouped by event
     * @param array $orders Array of orders
     * @param bool $excludeCancelledRefunded Whether to exclude cancelled/refunded
     * @return array Event analytics
     */
    public static function getEventAnalytics($orders, $excludeCancelledRefunded = true) {
        $events = [];
        
        foreach ($orders as $order) {
            $prefix = self::getEventPrefix($order['referenceCode'] ?? '');
            
            if (!isset($events[$prefix])) {
                $events[$prefix] = [
                    'event' => $prefix,
                    'order_count' => 0,
                    'gross_revenue' => 0,
                    'base_revenue' => 0,
                    'hst_collected' => 0,
                    'delivery_fees' => 0,
                    'refunded_count' => 0,
                    'refunded_amount' => 0,
                    'cancelled_count' => 0,
                    'venue_fee' => 0
                ];
            }

            $status = $order['status'] ?? '';
            $pricing = $order['pricing'] ?? [];
            $total = (float)($pricing['total'] ?? 0);
            $base = (float)($pricing['basePrice'] ?? 0);
            $tax = (float)($pricing['tax'] ?? 0);
            $deliveryFee = (float)($pricing['deliveryFee'] ?? 0);

            if ($status === 'cancelled') {
                $events[$prefix]['cancelled_count']++;
            } elseif ($status === 'refunded') {
                $events[$prefix]['refunded_count']++;
                $refundAmount = isset($order['refund']['refundAmount'])
                    ? (float)$order['refund']['refundAmount']
                    : $total;
                $events[$prefix]['refunded_amount'] += $refundAmount;

                if (!$excludeCancelledRefunded) {
                    $events[$prefix]['order_count']++;
                    $events[$prefix]['gross_revenue'] += $total;
                    $events[$prefix]['base_revenue'] += $base;
                    $events[$prefix]['hst_collected'] += $tax;
                    $events[$prefix]['delivery_fees'] += $deliveryFee;
                }
            } else {
                $events[$prefix]['order_count']++;
                $events[$prefix]['gross_revenue'] += $total;
                $events[$prefix]['base_revenue'] += $base;
                $events[$prefix]['hst_collected'] += $tax;
                $events[$prefix]['delivery_fees'] += $deliveryFee;
            }
        }
        
        // Calculate venue fee and net revenue for each event
        foreach ($events as $prefix => &$event) {
            $event['venue_fee'] = $event['base_revenue'] * self::VENUE_FEE_RATE;
            $event['net_revenue'] = $event['gross_revenue'] - $event['refunded_amount'];
        }
        
        // Sort by gross revenue descending
        uasort($events, function($a, $b) {
            return $b['gross_revenue'] - $a['gross_revenue'];
        });
        
        return $events;
    }
    
    // =========================================================================
    // PERIOD FILTERING & COMPARISON
    // =========================================================================
    
    /**
     * Filter orders by date range
     * @param array $orders Array of orders
     * @param string $startDate Start date (Y-m-d)
     * @param string $endDate End date (Y-m-d)
     * @param string $dateField Which date field to use (submittedAt, paidAt, selectedDate)
     * @return array Filtered orders
     */
    public static function filterByDateRange($orders, $startDate, $endDate, $dateField = 'paidAt') {
        $start = strtotime($startDate . ' 00:00:00');
        $end = strtotime($endDate . ' 23:59:59');
        
        return array_filter($orders, function($order) use ($start, $end, $dateField) {
            // For paidAt, fall back to submittedAt if not set
            $dateValue = $order[$dateField] ?? null;
            if ($dateField === 'paidAt' && !$dateValue) {
                $dateValue = $order['submittedAt'] ?? null;
            }
            
            if (!$dateValue) {
                return false;
            }
            
            $orderDate = strtotime($dateValue);
            return $orderDate >= $start && $orderDate <= $end;
        });
    }
    
    /**
     * Filter orders by event(s)
     * @param array $orders Array of orders
     * @param array|string $events Event prefix(es) to filter by
     * @return array Filtered orders
     */
    public static function filterByEvent($orders, $events) {
        if (is_string($events)) {
            $events = [$events];
        }
        
        $events = array_map('strtoupper', $events);
        
        return array_filter($orders, function($order) use ($events) {
            $prefix = self::getEventPrefix($order['referenceCode'] ?? '');
            return in_array($prefix, $events);
        });
    }
    
    /**
     * Filter orders by status(es)
     * @param array $orders Array of orders
     * @param array|string $statuses Status(es) to filter by
     * @return array Filtered orders
     */
    public static function filterByStatus($orders, $statuses) {
        if (is_string($statuses)) {
            $statuses = [$statuses];
        }
        
        return array_filter($orders, function($order) use ($statuses) {
            return in_array($order['status'] ?? '', $statuses);
        });
    }
    
    /**
     * Get date range for common periods
     * @param string $period Period name (this_month, last_month, this_quarter, etc.)
     * @return array [startDate, endDate] in Y-m-d format
     */
    public static function getPeriodDates($period) {
        $now = new DateTime();
        
        switch ($period) {
            case 'today':
                $start = $now->format('Y-m-d');
                $end = $start;
                break;
                
            case 'yesterday':
                $yesterday = (clone $now)->modify('-1 day');
                $start = $yesterday->format('Y-m-d');
                $end = $start;
                break;
                
            case 'last_7_days':
                $start = (clone $now)->modify('-6 days')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
                
            case 'last_30_days':
                $start = (clone $now)->modify('-29 days')->format('Y-m-d');
                $end = $now->format('Y-m-d');
                break;
                
            case 'this_week':
                $start = (clone $now)->modify('monday this week')->format('Y-m-d');
                $end = (clone $now)->modify('sunday this week')->format('Y-m-d');
                break;
                
            case 'last_week':
                $start = (clone $now)->modify('monday last week')->format('Y-m-d');
                $end = (clone $now)->modify('sunday last week')->format('Y-m-d');
                break;
                
            case 'this_month':
                $start = $now->format('Y-m-01');
                $end = $now->format('Y-m-t');
                break;
                
            case 'last_month':
                $lastMonth = (clone $now)->modify('first day of last month');
                $start = $lastMonth->format('Y-m-01');
                $end = $lastMonth->format('Y-m-t');
                break;
                
            case 'this_quarter':
                $quarter = ceil($now->format('n') / 3);
                $start = $now->format('Y') . '-' . str_pad(($quarter - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01';
                $endMonth = $quarter * 3;
                $end = $now->format('Y') . '-' . str_pad($endMonth, 2, '0', STR_PAD_LEFT) . '-' . date('t', strtotime($start . ' +2 months'));
                break;
                
            case 'last_quarter':
                $quarter = ceil($now->format('n') / 3) - 1;
                $year = $now->format('Y');
                if ($quarter < 1) {
                    $quarter = 4;
                    $year--;
                }
                $start = $year . '-' . str_pad(($quarter - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01';
                $endMonth = $quarter * 3;
                $end = $year . '-' . str_pad($endMonth, 2, '0', STR_PAD_LEFT) . '-' . date('t', strtotime($start . ' +2 months'));
                break;
                
            case 'this_year':
                $start = $now->format('Y-01-01');
                $end = $now->format('Y-12-31');
                break;
                
            case 'last_year':
                $lastYear = $now->format('Y') - 1;
                $start = $lastYear . '-01-01';
                $end = $lastYear . '-12-31';
                break;
                
            case 'ytd': // Year to date
                $start = $now->format('Y-01-01');
                $end = $now->format('Y-m-d');
                break;
                
            default:
                // Default to this month
                $start = $now->format('Y-m-01');
                $end = $now->format('Y-m-t');
        }
        
        return ['start' => $start, 'end' => $end];
    }
    
    /**
     * Compare two periods
     * @param array $currentOrders Orders from current period
     * @param array $previousOrders Orders from previous period
     * @return array Comparison metrics with percentage changes
     */
    public static function comparePeriods($currentOrders, $previousOrders) {
        $current = self::getRevenueBreakdown($currentOrders);
        $previous = self::getRevenueBreakdown($previousOrders);
        
        $currentCounts = self::getOrderCounts($currentOrders);
        $previousCounts = self::getOrderCounts($previousOrders);
        
        // Calculate percentage changes
        $calcChange = function($current, $previous) {
            if ($previous == 0) {
                return $current > 0 ? 100 : 0;
            }
            return round((($current - $previous) / $previous) * 100, 1);
        };
        
        return [
            'current' => [
                'net_revenue' => $current['net_revenue'],
                'gross_revenue' => $current['gross_revenue'],
                'order_count' => $currentCounts['paid'],
                'avg_order_value' => $currentCounts['paid'] > 0 ? $current['net_revenue'] / $currentCounts['paid'] : 0,
                'venue_fee' => $current['venue_fee'],
                'hst_collected' => $current['hst_collected']
            ],
            'previous' => [
                'net_revenue' => $previous['net_revenue'],
                'gross_revenue' => $previous['gross_revenue'],
                'order_count' => $previousCounts['paid'],
                'avg_order_value' => $previousCounts['paid'] > 0 ? $previous['net_revenue'] / $previousCounts['paid'] : 0,
                'venue_fee' => $previous['venue_fee'],
                'hst_collected' => $previous['hst_collected']
            ],
            'change' => [
                'net_revenue' => $calcChange($current['net_revenue'], $previous['net_revenue']),
                'gross_revenue' => $calcChange($current['gross_revenue'], $previous['gross_revenue']),
                'order_count' => $calcChange($currentCounts['paid'], $previousCounts['paid']),
                'avg_order_value' => $calcChange(
                    $currentCounts['paid'] > 0 ? $current['net_revenue'] / $currentCounts['paid'] : 0,
                    $previousCounts['paid'] > 0 ? $previous['net_revenue'] / $previousCounts['paid'] : 0
                ),
                'venue_fee' => $calcChange($current['venue_fee'], $previous['venue_fee']),
                'hst_collected' => $calcChange($current['hst_collected'], $previous['hst_collected'])
            ]
        ];
    }
    
    // =========================================================================
    // COMPLETE ANALYTICS SUMMARY
    // =========================================================================
    
    /**
     * Get complete analytics summary for dashboard or reports
     * @param array $orders Array of orders (optionally pre-filtered)
     * @return array Complete analytics data
     */
    public static function getCompleteSummary($orders) {
        return [
            'revenue' => self::getRevenueBreakdown($orders),
            'counts' => self::getOrderCounts($orders),
            'status_breakdown' => self::getStatusBreakdown($orders),
            'turnaround_breakdown' => self::getTurnaroundBreakdown($orders),
            'size_breakdown' => self::getSizeBreakdown($orders),
            'material_breakdown' => self::getMaterialBreakdown($orders),
            'delivery_breakdown' => self::getDeliveryBreakdown($orders),
            'event_analytics' => self::getEventAnalytics($orders),
            'refund_stats' => self::getRefundStats($orders),
            'cancelled_stats' => self::getCancelledStats($orders)
        ];
    }
    
    // =========================================================================
    // UTILITY FUNCTIONS
    // =========================================================================
    
    /**
     * Format currency for display
     * @param float $amount Amount to format
     * @param bool $includeSymbol Whether to include $ symbol
     * @return string Formatted currency
     */
    public static function formatCurrency($amount, $includeSymbol = true) {
        $formatted = number_format((float)$amount, 2);
        return $includeSymbol ? '$' . $formatted : $formatted;
    }
    
    /**
     * Format percentage for display
     * @param float $percentage Percentage value
     * @param int $decimals Number of decimal places
     * @return string Formatted percentage
     */
    public static function formatPercentage($percentage, $decimals = 1) {
        return number_format((float)$percentage, $decimals) . '%';
    }
    
    /**
     * Get average order value
     * @param array $orders Array of orders
     * @param array $includeStatuses Only include these statuses
     * @return float Average order value
     */
    public static function getAverageOrderValue($orders, $includeStatuses = null) {
        if ($includeStatuses === null) {
            $includeStatuses = self::PAID_STATUSES;
        }
        
        $filteredOrders = array_filter($orders, function($o) use ($includeStatuses) {
            return in_array($o['status'] ?? '', $includeStatuses);
        });
        
        $count = count($filteredOrders);
        if ($count === 0) {
            return 0;
        }
        
        $total = 0;
        foreach ($filteredOrders as $order) {
            $total += (float)($order['pricing']['total'] ?? 0);
        }
        
        return $total / $count;
    }
}

/**
 * Helper function for quick access to calculator
 * @return AnalyticsCalculator
 */
function analytics() {
    return new AnalyticsCalculator();
}
