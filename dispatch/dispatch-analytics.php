<?php
/**
 * Dispatch Analytics — Calculations
 * MTCC Print Services
 * 
 * Scans all orders with dispatch metadata and computes:
 *   - Delivery volume by day/week/month
 *   - Courier performance (deliveries, avg time, on-time %)
 *   - Destination breakdown (MTCC vs office)
 *   - Tier/turnaround distribution
 *   - Cost analysis
 * 
 * Server path: /dispatch/dispatch-analytics.php
 */

require_once __DIR__ . '/dispatch-functions.php';

class DispatchAnalytics {
    
    private $orders = [];
    private $settings = [];
    private $period = 'all'; // 'today', '7days', '30days', 'all'
    
    private $eventFilter = null;
    
    public function __construct($period = 'all', $eventFilter = null) {
        $this->period = $period;
        $this->eventFilter = $eventFilter;
        $this->settings = dispatch_loadSettings();
        $this->loadAllDispatchedOrders();
    }
    
    /**
     * Load all orders that have dispatch metadata.
     */
    private function loadAllDispatchedOrders() {
        $dir = defined('DISPATCH_ORDERS_DIR') ? DISPATCH_ORDERS_DIR : __DIR__ . '/../uploads/orders/';
        if (!is_dir($dir)) return;
        
        $statuses = dispatch_loadStatuses();
        $cutoff = $this->getCutoffDate();
        
        $files = glob($dir . '*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;
            
            $ref = $data['referenceCode'] ?? '';
            if (empty($ref)) continue;
            
            $status = $statuses[$ref] ?? '';
            
            // Include orders that have been through dispatch (have dispatch metadata or are in dispatch-relevant statuses)
            $dispatchStatuses = ['ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];
            $hasDispatch = isset($data['dispatch']) || isset($data['dispatch_summary']);
            
            if (!$hasDispatch && !in_array($status, $dispatchStatuses)) continue;
            
            // Apply date filter
            if ($cutoff) {
                $orderDate = $data['dispatch']['dispatched_at'] ?? $data['submittedAt'] ?? null;
                if ($orderDate && strtotime($orderDate) < $cutoff) continue;
            }
            
            // Event filter
            if ($this->eventFilter) {
                $orderEvent = $data['eventCode'] ?? $data['event_code'] ?? '';
                if ($orderEvent !== $this->eventFilter) continue;
            }
            
            $data['_status'] = $status;
            $data['_ref'] = $ref;
            $this->orders[] = $data;
        }
    }
    
    private function getCutoffDate() {
        switch ($this->period) {
            case 'today': return strtotime('today');
            case '7days': return strtotime('-7 days');
            case '30days': return strtotime('-30 days');
            default: return null;
        }
    }
    
    /**
     * Get all analytics data.
     */
    public function getAll() {
        return [
            'summary' => $this->getSummary(),
            'daily_volume' => $this->getDailyVolume(),
            'courier_performance' => $this->getCourierPerformance(),
            'destination_breakdown' => $this->getDestinationBreakdown(),
            'tier_distribution' => $this->getTierDistribution(),
            'hourly_distribution' => $this->getHourlyDistribution(),
            'timing' => $this->getTimingMetrics(),
            'courier_issues' => $this->getCourierIssueRates(),
            'recent_deliveries' => $this->getRecentDeliveries(),
            'period' => $this->period,
            'event_filter' => $this->eventFilter,
            'total_orders' => count($this->orders),
        ];
    }
    
    /**
     * Summary cards data.
     */
    private function getSummary() {
        $total = count($this->orders);
        $completed = 0;
        $totalDeliveryMinutes = 0;
        $deliveryTimeCount = 0;
        $onTime = 0;
        $totalCost = 0;
        $totalRevenue = 0;
        
        foreach ($this->orders as $order) {
            $status = $order['_status'] ?? '';
            
            if (in_array($status, ['delivered', 'pickedup'])) {
                $completed++;
                
                // Calculate delivery time
                $dispatch = $order['dispatch'] ?? [];
                $dispatchedAt = $dispatch['dispatched_at'] ?? null;
                $completedAt = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null;
                
                if ($dispatchedAt && $completedAt) {
                    $minutes = (strtotime($completedAt) - strtotime($dispatchedAt)) / 60;
                    if ($minutes > 0 && $minutes < 1440) { // Sanity check: under 24 hours
                        $totalDeliveryMinutes += $minutes;
                        $deliveryTimeCount++;
                    }
                }
                
                // On-time check
                $dueInfo = dispatch_getDueInfo($order);
                if (!empty($dueInfo) && isset($dueInfo['hours_remaining'])) {
                    // If completed before due, it's on-time
                    if ($completedAt) {
                        $dueTimestamp = strtotime($dueInfo['date'] . ' ' . ($dueInfo['time'] ?? '23:59'));
                        if ($dueTimestamp && strtotime($completedAt) <= $dueTimestamp) {
                            $onTime++;
                        }
                    }
                }
                
                // Cost
                $payout = $dispatch['payout'] ?? $dispatch['estimated_payout'] ?? 0;
                $totalCost += $payout;
            }
            
            // Revenue
            $price = $order['pricing']['total'] ?? $order['pricing']['finalPrice'] ?? 0;
            $totalRevenue += $price;
        }
        
        return [
            'total_dispatched' => $total,
            'completed' => $completed,
            'pending' => $total - $completed,
            'avg_delivery_minutes' => $deliveryTimeCount > 0 ? round($totalDeliveryMinutes / $deliveryTimeCount) : 0,
            'on_time_rate' => $completed > 0 ? round(($onTime / $completed) * 100) : 0,
            'on_time_count' => $onTime,
            'total_courier_cost' => round($totalCost, 2),
            'total_revenue' => round($totalRevenue, 2),
            'avg_cost_per_delivery' => $completed > 0 ? round($totalCost / $completed, 2) : 0,
        ];
    }
    
    /**
     * Daily delivery volume for chart.
     */
    private function getDailyVolume() {
        $days = [];
        
        foreach ($this->orders as $order) {
            $dispatch = $order['dispatch'] ?? [];
            $date = null;
            
            if (!empty($dispatch['dispatched_at'])) {
                $date = date('Y-m-d', strtotime($dispatch['dispatched_at']));
            } elseif (!empty($order['submittedAt'])) {
                $date = date('Y-m-d', strtotime($order['submittedAt']));
            }
            
            if (!$date) continue;
            
            if (!isset($days[$date])) {
                $days[$date] = ['dispatched' => 0, 'completed' => 0];
            }
            $days[$date]['dispatched']++;
            
            if (in_array($order['_status'] ?? '', ['delivered', 'pickedup'])) {
                $completedDate = date('Y-m-d', strtotime($dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? $date));
                if (!isset($days[$completedDate])) {
                    $days[$completedDate] = ['dispatched' => 0, 'completed' => 0];
                }
                $days[$completedDate]['completed']++;
            }
        }
        
        ksort($days);
        
        // Fill gaps
        if (!empty($days)) {
            $start = new \DateTime(array_key_first($days));
            $end = new \DateTime(array_key_last($days));
            $interval = new \DateInterval('P1D');
            $period = new \DatePeriod($start, $interval, $end->modify('+1 day'));
            
            $filled = [];
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                $filled[$d] = $days[$d] ?? ['dispatched' => 0, 'completed' => 0];
            }
            $days = $filled;
        }
        
        return $days;
    }
    
    /**
     * Courier performance breakdown.
     */
    private function getCourierPerformance() {
        $couriers = [];
        
        foreach ($this->orders as $order) {
            $dispatch = $order['dispatch'] ?? [];
            $courierId = $dispatch['courier_id'] ?? '';
            $courierName = $dispatch['courier_name'] ?? '';
            
            if (empty($courierId) && empty($courierName)) continue;
            
            $key = $courierId ?: $courierName;
            if (!isset($couriers[$key])) {
                $couriers[$key] = [
                    'id' => $courierId,
                    'name' => $courierName ?: $courierId,
                    'total' => 0,
                    'completed' => 0,
                    'on_time' => 0,
                    'total_minutes' => 0,
                    'delivery_count' => 0,
                    'total_payout' => 0,
                    'total_distance_km' => 0,
                ];
            }
            
            $couriers[$key]['total']++;
            
            $status = $order['_status'] ?? '';
            if (in_array($status, ['delivered', 'pickedup'])) {
                $couriers[$key]['completed']++;
                
                // On-time check
                $completedAt2 = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null;
                if ($completedAt2) {
                    $dueInfo2 = dispatch_getDueInfo($order);
                    if (!empty($dueInfo2) && !empty($dueInfo2['date'])) {
                        $dueTs2 = strtotime($dueInfo2['date'] . ' ' . ($dueInfo2['time'] ?? '23:59'));
                        if ($dueTs2 && strtotime($completedAt2) <= $dueTs2) {
                            $couriers[$key]['on_time']++;
                        }
                    }
                }
                
                $dispatchedAt = $dispatch['dispatched_at'] ?? null;
                $completedAt = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null;
                
                if ($dispatchedAt && $completedAt) {
                    $minutes = (strtotime($completedAt) - strtotime($dispatchedAt)) / 60;
                    if ($minutes > 0 && $minutes < 1440) {
                        $couriers[$key]['total_minutes'] += $minutes;
                        $couriers[$key]['delivery_count']++;
                    }
                }
                
                $payout = $dispatch['payout'] ?? $dispatch['estimated_payout'] ?? 0;
                $couriers[$key]['total_payout'] += $payout;
                
                $distance = $dispatch['route_info']['distance_km'] ?? 0;
                $couriers[$key]['total_distance_km'] += $distance;
            }
        }
        
        // Compute averages
        foreach ($couriers as &$c) {
            $c['avg_minutes'] = $c['delivery_count'] > 0 ? round($c['total_minutes'] / $c['delivery_count']) : 0;
            $c['on_time_rate'] = $c['completed'] > 0 ? round(($c['on_time'] / max(1, $c['completed'])) * 100) : 0;
            $c['avg_payout'] = $c['completed'] > 0 ? round($c['total_payout'] / $c['completed'], 2) : 0;
        }
        
        // Sort by completed deliveries descending
        usort($couriers, function($a, $b) { return $b['completed'] <=> $a['completed']; });
        
        return array_values($couriers);
    }
    
    /**
     * Destination type breakdown.
     */
    private function getDestinationBreakdown() {
        $types = ['mtcc' => 0, 'office' => 0, 'other' => 0];
        $locations = [];
        
        foreach ($this->orders as $order) {
            $dest = dispatch_getDestination($order);
            $type = $dest['type'] ?? 'other';
            $types[$type] = ($types[$type] ?? 0) + 1;
            
            $label = $dest['label'] ?? 'Unknown';
            $locations[$label] = ($locations[$label] ?? 0) + 1;
        }
        
        arsort($locations);
        
        return [
            'by_type' => $types,
            'by_location' => array_slice($locations, 0, 10, true),
        ];
    }
    
    /**
     * Pricing tier distribution.
     */
    private function getTierDistribution() {
        $tiers = [];
        $tierLabels = [
            'early' => 'Early Bird',
            'standard' => 'Standard',
            '3days' => '3 Days',
            '2days' => '2 Days',
            'nextday' => 'Next Day',
            'sameday' => 'Same Day'
        ];
        
        foreach ($this->orders as $order) {
            $tier = $order['pricing']['tier'] ?? $order['pricing']['tierKey'] ?? 'unknown';
            $label = $tierLabels[$tier] ?? ucfirst($tier);
            $tiers[$label] = ($tiers[$label] ?? 0) + 1;
        }
        
        return $tiers;
    }
    
    /**
     * Hourly distribution (what time of day are deliveries dispatched).
     */
    private function getHourlyDistribution() {
        $hours = array_fill(0, 24, 0);
        
        foreach ($this->orders as $order) {
            $dispatch = $order['dispatch'] ?? [];
            $dispatchedAt = $dispatch['dispatched_at'] ?? null;
            if (!$dispatchedAt) continue;
            
            $hour = (int)date('G', strtotime($dispatchedAt));
            $hours[$hour]++;
        }
        
        return $hours;
    }
    
    /**
     * Timing metrics: avg dispatch wait, avg delivery, avg total.
     */
    public function getTimingMetrics() {
        $dispatchWait = [];  // ready -> dispatched
        $deliveryTime = [];  // dispatched -> delivered
        $totalTime = [];     // ready -> delivered
        $issueResolution = [];
        
        foreach ($this->orders as $order) {
            $dispatch = $order['dispatch'] ?? [];
            $status = $order['_status'] ?? '';
            if (!in_array($status, ['delivered', 'pickedup'])) continue;
            
            $readyAt = null;
            foreach ($order['statusHistory'] ?? [] as $sh) {
                if (($sh['status'] ?? $sh['newStatus'] ?? '') === 'ready') {
                    $readyAt = strtotime($sh['timestamp'] ?? '');
                }
            }
            if (!$readyAt && !empty($dispatch['ready_at'])) $readyAt = strtotime($dispatch['ready_at']);
            
            $dispatchedAt = !empty($dispatch['dispatched_at']) ? strtotime($dispatch['dispatched_at']) : null;
            $completedAt = !empty($dispatch['delivered_at']) ? strtotime($dispatch['delivered_at']) : 
                           (!empty($dispatch['picked_up_at']) ? strtotime($dispatch['picked_up_at']) : null);
            
            if ($readyAt && $dispatchedAt && $dispatchedAt > $readyAt) {
                $mins = ($dispatchedAt - $readyAt) / 60;
                if ($mins > 0 && $mins < 2880) $dispatchWait[] = $mins;
            }
            if ($dispatchedAt && $completedAt && $completedAt > $dispatchedAt) {
                $mins = ($completedAt - $dispatchedAt) / 60;
                if ($mins > 0 && $mins < 2880) $deliveryTime[] = $mins;
            }
            if ($readyAt && $completedAt && $completedAt > $readyAt) {
                $mins = ($completedAt - $readyAt) / 60;
                if ($mins > 0 && $mins < 2880) $totalTime[] = $mins;
            }
        }
        
        // Issue resolution times
        $issuesFile = defined('DELIVERY_ISSUES_FILE') ? DELIVERY_ISSUES_FILE : __DIR__ . '/../data/delivery-issues.json';
        if (file_exists($issuesFile)) {
            $issueData = json_decode(file_get_contents($issuesFile), true) ?: [];
            foreach ($issueData['issues'] ?? [] as $issue) {
                if (($issue['status'] ?? '') === 'resolved' && !empty($issue['reported_at']) && !empty($issue['resolved_at'])) {
                    $mins = (strtotime($issue['resolved_at']) - strtotime($issue['reported_at'])) / 60;
                    if ($mins > 0 && $mins < 10080) $issueResolution[] = $mins;
                }
            }
        }
        
        $avg = function($arr) { return count($arr) > 0 ? round(array_sum($arr) / count($arr)) : 0; };
        $med = function($arr) {
            if (empty($arr)) return 0;
            sort($arr); $n = count($arr); $mid = intdiv($n, 2);
            return $n % 2 === 0 ? round(($arr[$mid - 1] + $arr[$mid]) / 2) : round($arr[$mid]);
        };
        
        return [
            'dispatch_wait' => ['avg' => $avg($dispatchWait), 'median' => $med($dispatchWait), 'count' => count($dispatchWait)],
            'delivery_time' => ['avg' => $avg($deliveryTime), 'median' => $med($deliveryTime), 'count' => count($deliveryTime)],
            'total_time' => ['avg' => $avg($totalTime), 'median' => $med($totalTime), 'count' => count($totalTime)],
            'issue_resolution' => ['avg' => $avg($issueResolution), 'median' => $med($issueResolution), 'count' => count($issueResolution)],
        ];
    }
    
    /**
     * Get issue stats per courier.
     */
    public function getCourierIssueRates() {
        $issuesFile = defined('DELIVERY_ISSUES_FILE') ? DELIVERY_ISSUES_FILE : __DIR__ . '/../data/delivery-issues.json';
        $issuesByCourier = [];
        if (file_exists($issuesFile)) {
            $issueData = json_decode(file_get_contents($issuesFile), true) ?: [];
            foreach ($issueData['issues'] ?? [] as $issue) {
                $pin = $issue['courier_id'] ?? $issue['courier_pin'] ?? '';
                if ($pin) $issuesByCourier[$pin] = ($issuesByCourier[$pin] ?? 0) + 1;
            }
        }
        return $issuesByCourier;
    }

    /**
     * Recent deliveries feed.
     */
    private function getRecentDeliveries() {
        $recent = [];
        
        foreach ($this->orders as $order) {
            $dispatch = $order['dispatch'] ?? [];
            $status = $order['_status'] ?? '';
            
            $completedAt = $dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? $dispatch['dispatched_at'] ?? null;
            
            $recent[] = [
                'ref' => $order['_ref'],
                'status' => $status,
                'courier' => $dispatch['courier_name'] ?? 'N/A',
                'destination' => ($order['dispatch_summary']['destination_label'] ?? ''),
                'completed_at' => $completedAt,
                'customer' => $order['customerInfo']['name'] ?? '',
            ];
        }
        
        // Sort by most recent
        usort($recent, function($a, $b) {
            return strtotime($b['completed_at'] ?? '1970-01-01') <=> strtotime($a['completed_at'] ?? '1970-01-01');
        });
        
        return array_slice($recent, 0, 20);
    }
}
