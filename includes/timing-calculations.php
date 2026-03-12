<?php
/**
 * Timing Calculations — Shared Order Lifecycle Metrics
 * MTCC Print Services
 *
 * Computes timing metrics across the full order lifecycle:
 *   pushed → confirmed → printing → ready → dispatched → delivered/pickedup
 *
 * Used by: production-analytics, dispatch/analytics, reports
 *
 * Server path: /includes/timing-calculations.php
 */

class TimingCalculator {

    /**
     * Get full timing breakdown for a single order.
     * Returns an associative array of stage durations in seconds.
     */
    public static function getOrderTimings($order, $preflightEntry = null) {
        $timings = [
            'confirmation_time'  => null,  // pushed → confirmed
            'production_time'    => null,  // confirmed → ready
            'dispatch_wait_time' => null,  // ready → dispatched
            'delivery_time'      => null,  // dispatched → delivered/pickedup
            'total_time'         => null,  // pushed → delivered/pickedup
            'preflight_to_ready' => null,  // pushed → ready
        ];

        // Source timestamps
        $pushedAt     = self::ts($preflightEntry['pushed_at'] ?? null);
        $confirmedAt  = self::ts($preflightEntry['confirmed_at'] ?? null);
        $readyAt      = self::ts($preflightEntry['ready_at'] ?? null);

        $dispatch = $order['dispatch'] ?? [];
        $dispatchedAt = self::ts($dispatch['dispatched_at'] ?? null);
        $deliveredAt  = self::ts($dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null);

        // Calculate each stage
        if ($pushedAt && $confirmedAt) {
            $timings['confirmation_time'] = max(0, $confirmedAt - $pushedAt);
        }
        if ($confirmedAt && $readyAt) {
            $timings['production_time'] = max(0, $readyAt - $confirmedAt);
        }
        if ($readyAt && $dispatchedAt) {
            $timings['dispatch_wait_time'] = max(0, $dispatchedAt - $readyAt);
        }
        if ($dispatchedAt && $deliveredAt) {
            $timings['delivery_time'] = max(0, $deliveredAt - $dispatchedAt);
        }
        if ($pushedAt && $deliveredAt) {
            $timings['total_time'] = max(0, $deliveredAt - $pushedAt);
        }
        if ($pushedAt && $readyAt) {
            $timings['preflight_to_ready'] = max(0, $readyAt - $pushedAt);
        }

        return $timings;
    }

    /**
     * Compute averages across multiple orders for a given metric.
     * Returns: ['avg' => seconds, 'min' => seconds, 'max' => seconds, 'count' => int]
     */
    public static function getAverages($allTimings, $metric) {
        $values = [];
        foreach ($allTimings as $t) {
            if ($t[$metric] !== null && $t[$metric] > 0 && $t[$metric] < 604800) { // < 7 days sanity
                $values[] = $t[$metric];
            }
        }

        if (empty($values)) {
            return ['avg' => 0, 'min' => 0, 'max' => 0, 'count' => 0, 'median' => 0];
        }

        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        $median = ($count % 2 === 0) ? ($values[$mid - 1] + $values[$mid]) / 2 : $values[$mid];

        return [
            'avg'    => round(array_sum($values) / $count),
            'min'    => $values[0],
            'max'    => end($values),
            'count'  => $count,
            'median' => round($median),
        ];
    }

    /**
     * Get per-vendor metrics.
     * Returns: vendorId => ['name', 'orders', 'avg_confirmation', 'avg_production', 'avg_total', 'on_time_rate']
     */
    public static function getVendorMetrics($allTimings, $vendorMap) {
        $byVendor = [];
        foreach ($allTimings as $ref => $t) {
            $vendorId = $vendorMap[$ref]['vendor_id'] ?? 'unknown';
            $vendorName = $vendorMap[$ref]['vendor_name'] ?? 'Unknown';
            if (!isset($byVendor[$vendorId])) {
                $byVendor[$vendorId] = [
                    'name' => $vendorName,
                    'orders' => 0,
                    'confirmation_times' => [],
                    'production_times' => [],
                    'total_times' => [],
                    'on_time' => 0,
                    'completed' => 0,
                ];
            }
            $byVendor[$vendorId]['orders']++;
            if ($t['confirmation_time'] !== null) $byVendor[$vendorId]['confirmation_times'][] = $t['confirmation_time'];
            if ($t['production_time'] !== null) $byVendor[$vendorId]['production_times'][] = $t['production_time'];
            if ($t['preflight_to_ready'] !== null) $byVendor[$vendorId]['total_times'][] = $t['preflight_to_ready'];

            // On-time: order was ready before due date
            $isOnTime = $vendorMap[$ref]['is_on_time'] ?? null;
            if ($isOnTime !== null) {
                $byVendor[$vendorId]['completed']++;
                if ($isOnTime) $byVendor[$vendorId]['on_time']++;
            }
        }

        // Compute averages
        foreach ($byVendor as &$v) {
            $v['avg_confirmation'] = !empty($v['confirmation_times']) ? round(array_sum($v['confirmation_times']) / count($v['confirmation_times'])) : 0;
            $v['avg_production']   = !empty($v['production_times']) ? round(array_sum($v['production_times']) / count($v['production_times'])) : 0;
            $v['avg_total']        = !empty($v['total_times']) ? round(array_sum($v['total_times']) / count($v['total_times'])) : 0;
            $v['on_time_rate']     = $v['completed'] > 0 ? round(($v['on_time'] / $v['completed']) * 100) : 0;
            // Clean up temp arrays
            unset($v['confirmation_times'], $v['production_times'], $v['total_times']);
        }

        // Sort by order count desc
        uasort($byVendor, fn($a, $b) => $b['orders'] <=> $a['orders']);

        return $byVendor;
    }

    /**
     * Get per-courier metrics.
     * Returns: courierId => ['name', 'deliveries', 'avg_delivery', 'on_time_rate', 'issue_rate', 'streak']
     */
    public static function getCourierMetrics($allTimings, $courierMap) {
        $byCourier = [];
        foreach ($allTimings as $ref => $t) {
            $courierId = $courierMap[$ref]['courier_id'] ?? '';
            $courierName = $courierMap[$ref]['courier_name'] ?? '';
            if (empty($courierId) && empty($courierName)) continue;

            $key = $courierId ?: $courierName;
            if (!isset($byCourier[$key])) {
                $byCourier[$key] = [
                    'name' => $courierName ?: $courierId,
                    'deliveries' => 0,
                    'delivery_times' => [],
                    'on_time' => 0,
                    'issues' => 0,
                    'completed' => 0,
                ];
            }
            $byCourier[$key]['deliveries']++;

            if ($t['delivery_time'] !== null && $t['delivery_time'] > 0) {
                $byCourier[$key]['delivery_times'][] = $t['delivery_time'];
                $byCourier[$key]['completed']++;
            }

            $isOnTime = $courierMap[$ref]['is_on_time'] ?? null;
            if ($isOnTime === true) $byCourier[$key]['on_time']++;

            if ($courierMap[$ref]['has_issue'] ?? false) $byCourier[$key]['issues']++;
        }

        foreach ($byCourier as &$c) {
            $c['avg_delivery'] = !empty($c['delivery_times']) ? round(array_sum($c['delivery_times']) / count($c['delivery_times'])) : 0;
            $c['on_time_rate'] = $c['completed'] > 0 ? round(($c['on_time'] / $c['completed']) * 100) : 0;
            $c['issue_rate']   = $c['deliveries'] > 0 ? round(($c['issues'] / $c['deliveries']) * 100) : 0;
            unset($c['delivery_times']);
        }

        uasort($byCourier, fn($a, $b) => $b['deliveries'] <=> $a['deliveries']);

        return $byCourier;
    }

    /**
     * Compute order lifecycle breakdown for reporting.
     * Returns avg time spent in each status stage.
     */
    public static function getLifecycleBreakdown($allTimings) {
        $stages = [
            ['key' => 'confirmation_time',  'label' => 'Confirmation (Pushed → Confirmed)',   'color' => '#3b82f6'],
            ['key' => 'production_time',    'label' => 'Production (Confirmed → Ready)',       'color' => '#f59e0b'],
            ['key' => 'dispatch_wait_time', 'label' => 'Dispatch Wait (Ready → Dispatched)',   'color' => '#8b5cf6'],
            ['key' => 'delivery_time',      'label' => 'Delivery (Dispatched → Delivered)',     'color' => '#10b981'],
        ];

        $breakdown = [];
        foreach ($stages as $stage) {
            $avgs = self::getAverages($allTimings, $stage['key']);
            $breakdown[] = [
                'label' => $stage['label'],
                'color' => $stage['color'],
                'avg_seconds' => $avgs['avg'],
                'median_seconds' => $avgs['median'],
                'min_seconds' => $avgs['min'],
                'max_seconds' => $avgs['max'],
                'sample_count' => $avgs['count'],
            ];
        }

        // Also compute total
        $totalAvg = self::getAverages($allTimings, 'total_time');
        $breakdown[] = [
            'label' => 'Total (Pushed → Delivered)',
            'color' => '#1f2937',
            'avg_seconds' => $totalAvg['avg'],
            'median_seconds' => $totalAvg['median'],
            'min_seconds' => $totalAvg['min'],
            'max_seconds' => $totalAvg['max'],
            'sample_count' => $totalAvg['count'],
        ];

        return $breakdown;
    }

    /**
     * Format seconds into human-readable string.
     */
    public static function formatDuration($seconds) {
        if ($seconds <= 0) return '—';
        if ($seconds < 60) return $seconds . 's';
        if ($seconds < 3600) return round($seconds / 60) . 'm';
        $hours = floor($seconds / 3600);
        $mins = round(($seconds % 3600) / 60);
        if ($hours >= 24) {
            $days = floor($hours / 24);
            $remainHours = $hours % 24;
            return $days . 'd ' . $remainHours . 'h';
        }
        return $hours . 'h ' . $mins . 'm';
    }

    /**
     * Format seconds as compact hours (e.g., "2.5h").
     */
    public static function formatHours($seconds) {
        if ($seconds <= 0) return '—';
        $hours = round($seconds / 3600, 1);
        return $hours . 'h';
    }

    /** Safe strtotime */
    private static function ts($val) {
        if (empty($val)) return null;
        $t = strtotime($val);
        return ($t && $t > 0) ? $t : null;
    }

    /**
     * Load and compute timings for all orders in a date range.
     * Returns: ['timings' => [ref => timings], 'vendor_map' => [...], 'courier_map' => [...]]
     */
    public static function loadAllTimings($startDate = null, $endDate = null, $eventFilter = null) {
        $basePath = defined('BASE_PATH') ? BASE_PATH : __DIR__ . '/../';

        $preflightLogFile = $basePath . 'data/preflight-log.json';
        $statusesFile = $basePath . 'data/statuses.json';
        $ordersDir = $basePath . 'uploads/orders/';
        $issuesFile = $basePath . 'data/delivery-issues.json';

        $log = file_exists($preflightLogFile) ? json_decode(file_get_contents($preflightLogFile), true) : [];
        $entries = $log['entries'] ?? [];

        $allTimings = [];
        $vendorMap = [];
        $courierMap = [];

        foreach ($entries as $ref => $entry) {
            // Date filter
            $pushedAt = strtotime($entry['pushed_at'] ?? '');
            if (!$pushedAt) continue;
            if ($startDate && $pushedAt < $startDate->getTimestamp()) continue;
            if ($endDate && $pushedAt > $endDate->getTimestamp()) continue;

            // Event filter
            if ($eventFilter) {
                $orderFile = $ordersDir . $ref . '.json';
                if (!file_exists($orderFile)) continue;
                $order = json_decode(file_get_contents($orderFile), true);
                if (!$order) continue;
                $orderEvent = $order['event']['acronym'] ?? '';
                if ($orderEvent !== $eventFilter) continue;
            } else {
                $orderFile = $ordersDir . $ref . '.json';
                $order = file_exists($orderFile) ? json_decode(file_get_contents($orderFile), true) : [];
            }

            if (!$order) $order = [];

            $timings = self::getOrderTimings($order, $entry);
            $allTimings[$ref] = $timings;

            // Build vendor map
            $vendorMap[$ref] = [
                'vendor_id' => $entry['vendor_id'] ?? 'unknown',
                'vendor_name' => $entry['vendor_name'] ?? 'Unknown',
                'is_on_time' => self::checkOnTime($order, $entry),
            ];

            // Build courier map
            $dispatch = $order['dispatch'] ?? [];
            $courierMap[$ref] = [
                'courier_id' => $dispatch['courier_id'] ?? '',
                'courier_name' => $dispatch['courier_name'] ?? '',
                'is_on_time' => self::checkDeliveryOnTime($order),
                'has_issue' => !empty($order['dispatch']['has_issue']),
            ];
        }

        return [
            'timings' => $allTimings,
            'vendor_map' => $vendorMap,
            'courier_map' => $courierMap,
        ];
    }

    /** Check if vendor completed on time (ready before due date) */
    private static function checkOnTime($order, $entry) {
        $readyAt = self::ts($entry['ready_at'] ?? null);
        if (!$readyAt) return null;

        $dueDate = $order['selectedDate'] ?? null;
        $dueTime = $order['deliveryTime'] ?? 'anytime';
        if (!$dueDate) return null;

        // Vendor deadline = due - 3h buffer
        $dueTs = strtotime($dueDate . ' 23:59:59');
        if ($dueTime && $dueTime !== 'anytime') {
            $timeMap = ['9am' => '09:00', '12pm' => '12:00', '3pm' => '15:00', '6pm' => '18:00'];
            $mapped = $timeMap[$dueTime] ?? null;
            if ($mapped) $dueTs = strtotime($dueDate . ' ' . $mapped);
        }
        $vendorDeadline = $dueTs - (3 * 3600); // 3h buffer

        return $readyAt <= $vendorDeadline;
    }

    /** Check if delivery was on time */
    private static function checkDeliveryOnTime($order) {
        $dispatch = $order['dispatch'] ?? [];
        $deliveredAt = self::ts($dispatch['delivered_at'] ?? $dispatch['picked_up_at'] ?? null);
        if (!$deliveredAt) return null;

        $dueDate = $order['selectedDate'] ?? null;
        $dueTime = $order['deliveryTime'] ?? 'anytime';
        if (!$dueDate) return null;

        $dueTs = strtotime($dueDate . ' 23:59:59');
        if ($dueTime && $dueTime !== 'anytime') {
            $timeMap = ['9am' => '09:00', '12pm' => '12:00', '3pm' => '15:00', '6pm' => '18:00'];
            $mapped = $timeMap[$dueTime] ?? null;
            if ($mapped) $dueTs = strtotime($dueDate . ' ' . $mapped);
        }

        return $deliveredAt <= $dueTs;
    }
}
