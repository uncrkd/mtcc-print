<?php
/**
 * Batch Suggestion Engine
 * MTCC Print Services — Dispatch Hub
 * 
 * Analyzes ready queue orders and generates smart batch suggestions based on:
 *   - Same vendor (same pickup location)
 *   - Same destination (same dropoff — MTCC North/South)
 *   - Compatible due times (within 3-hour window)
 *   - Route efficiency (nearby vendors that can be chained)
 * 
 * Each suggestion includes a confidence score and savings estimate.
 * 
 * Server path: /dispatch/batch-suggestions.php
 */

require_once __DIR__ . '/dispatch-functions.php';

class BatchSuggestionEngine {
    
    private $readyQueue = [];
    private $settings = [];
    
    public function __construct() {
        $this->settings = dispatch_loadSettings();
    }
    
    /**
     * Generate batch suggestions from the ready queue.
     * Returns array of suggestions sorted by score (best first).
     */
    public function getSuggestions() {
        $this->readyQueue = dispatch_getReadyQueue();
        
        if (count($this->readyQueue) < 2) {
            return [];
        }
        
        $suggestions = [];
        
        // Strategy 1: Same vendor + same destination (strongest signal)
        $suggestions = array_merge($suggestions, $this->groupByVendorAndDest());
        
        // Strategy 2: Same vendor, mixed destinations
        $suggestions = array_merge($suggestions, $this->groupByVendor());
        
        // Strategy 3: Same destination from different (nearby) vendors
        $suggestions = array_merge($suggestions, $this->groupByDestination());
        
        // Strategy 4: Urgent cluster — group all urgent/priority orders
        $suggestions = array_merge($suggestions, $this->groupUrgent());
        
        // Deduplicate: if an order appears in a higher-scored suggestion, remove from lower ones
        $suggestions = $this->deduplicateSuggestions($suggestions);
        
        // Sort by score descending
        usort($suggestions, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        // Limit to top 5 suggestions
        return array_slice($suggestions, 0, 5);
    }
    
    /**
     * Strategy 1: Same vendor + same destination.
     * Highest efficiency — one pickup, one dropoff, multiple orders.
     */
    private function groupByVendorAndDest() {
        $groups = [];
        
        foreach ($this->readyQueue as $order) {
            $key = ($order['vendor_name'] ?? 'Unknown') . '|' . ($order['destination'] ?? 'Unknown');
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'vendor' => $order['vendor_name'] ?? 'Unknown',
                    'destination' => $order['destination'] ?? 'Unknown',
                    'dest_type' => $order['destination_type'] ?? 'mtcc',
                    'orders' => []
                ];
            }
            $groups[$key]['orders'][] = $order;
        }
        
        $suggestions = [];
        foreach ($groups as $group) {
            if (count($group['orders']) < 2) continue;
            
            // Check due time compatibility
            if (!$this->areDueTimesCompatible($group['orders'])) continue;
            
            $refs = array_column($group['orders'], 'ref');
            $baseRate = $this->settings['pricing']['base_rate'] ?? 30;
            $savings = ($baseRate * count($refs)) - ($baseRate + (($this->settings['pricing']['modifiers']['additional_stop'] ?? 10) * (count($refs) - 1)));
            
            $urgentCount = count(array_filter($group['orders'], function($o) {
                return !empty($o['due_info']['is_urgent']);
            }));
            
            $suggestions[] = [
                'id' => 'sameVD_' . md5(implode(',', $refs)),
                'type' => 'same_vendor_dest',
                'title' => count($refs) . ' orders → same route',
                'description' => $group['vendor'] . ' → ' . $group['destination'],
                'reason' => 'Same pickup and dropoff — single trip covers all ' . count($refs) . ' orders',
                'refs' => $refs,
                'order_count' => count($refs),
                'vendor' => $group['vendor'],
                'destination' => $group['destination'],
                'dest_type' => $group['dest_type'],
                'savings_est' => max(0, round($savings, 2)),
                'has_urgent' => $urgentCount > 0,
                'urgent_count' => $urgentCount,
                'score' => $this->scoreGroup($group['orders'], 'same_vendor_dest')
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Strategy 2: Same vendor, different destinations.
     * One pickup, multiple dropoffs — still saves a trip back.
     */
    private function groupByVendor() {
        $groups = [];
        
        foreach ($this->readyQueue as $order) {
            $vendor = $order['vendor_name'] ?? 'Unknown';
            if (!isset($groups[$vendor])) {
                $groups[$vendor] = [];
            }
            $groups[$vendor][] = $order;
        }
        
        $suggestions = [];
        foreach ($groups as $vendor => $orders) {
            if (count($orders) < 2) continue;
            
            // Check if all go to same destination (already covered by Strategy 1)
            $dests = array_unique(array_column($orders, 'destination'));
            if (count($dests) === 1) continue;
            
            if (!$this->areDueTimesCompatible($orders)) continue;
            
            $refs = array_column($orders, 'ref');
            $destList = array_count_values(array_column($orders, 'destination'));
            $destSummary = [];
            foreach ($destList as $d => $cnt) {
                $destSummary[] = $cnt . '× ' . $d;
            }
            
            $suggestions[] = [
                'id' => 'sameV_' . md5(implode(',', $refs)),
                'type' => 'same_vendor',
                'title' => count($refs) . ' orders from ' . $vendor,
                'description' => implode(', ', $destSummary),
                'reason' => 'Single pickup at ' . $vendor . ', then deliver to ' . count($dests) . ' locations',
                'refs' => $refs,
                'order_count' => count($refs),
                'vendor' => $vendor,
                'destination' => implode(' + ', $dests),
                'dest_type' => 'mixed',
                'savings_est' => 0,
                'has_urgent' => count(array_filter($orders, function($o) { return !empty($o['due_info']['is_urgent']); })) > 0,
                'urgent_count' => count(array_filter($orders, function($o) { return !empty($o['due_info']['is_urgent']); })),
                'score' => $this->scoreGroup($orders, 'same_vendor')
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Strategy 3: Same destination from different vendors.
     * Multiple pickups, one dropoff — can chain pickups then deliver.
     */
    private function groupByDestination() {
        $groups = [];
        
        foreach ($this->readyQueue as $order) {
            $dest = $order['destination'] ?? 'Unknown';
            if (!isset($groups[$dest])) {
                $groups[$dest] = [];
            }
            $groups[$dest][] = $order;
        }
        
        $suggestions = [];
        foreach ($groups as $dest => $orders) {
            if (count($orders) < 2) continue;
            
            // Check if all from same vendor (already covered by Strategy 1)
            $vendors = array_unique(array_column($orders, 'vendor_name'));
            if (count($vendors) === 1) continue;
            
            if (!$this->areDueTimesCompatible($orders)) continue;
            
            $refs = array_column($orders, 'ref');
            $vendorList = array_count_values(array_column($orders, 'vendor_name'));
            $vendorSummary = [];
            foreach ($vendorList as $v => $cnt) {
                $vendorSummary[] = $cnt . '× ' . $v;
            }
            
            $suggestions[] = [
                'id' => 'sameD_' . md5(implode(',', $refs)),
                'type' => 'same_dest',
                'title' => count($refs) . ' orders → ' . $dest,
                'description' => 'From: ' . implode(', ', $vendorSummary),
                'reason' => 'Chain pickups from ' . count($vendors) . ' vendors, single dropoff at ' . $dest,
                'refs' => $refs,
                'order_count' => count($refs),
                'vendor' => implode(' + ', $vendors),
                'destination' => $dest,
                'dest_type' => $orders[0]['destination_type'] ?? 'mtcc',
                'savings_est' => 0,
                'has_urgent' => count(array_filter($orders, function($o) { return !empty($o['due_info']['is_urgent']); })) > 0,
                'urgent_count' => count(array_filter($orders, function($o) { return !empty($o['due_info']['is_urgent']); })),
                'score' => $this->scoreGroup($orders, 'same_dest')
            ];
        }
        
        return $suggestions;
    }
    
    /**
     * Strategy 4: Urgent cluster.
     * Group all urgent/priority orders regardless of vendor/dest.
     */
    private function groupUrgent() {
        $urgent = array_filter($this->readyQueue, function($o) {
            return !empty($o['due_info']['is_urgent']) || !empty($o['due_info']['is_priority']);
        });
        
        if (count($urgent) < 2) return [];
        
        $refs = array_column($urgent, 'ref');
        $vendors = array_unique(array_column($urgent, 'vendor_name'));
        $dests = array_unique(array_column($urgent, 'destination'));
        
        return [[
            'id' => 'urgent_' . md5(implode(',', $refs)),
            'type' => 'urgent_cluster',
            'title' => count($refs) . ' urgent/priority orders',
            'description' => count($vendors) . ' vendor' . (count($vendors) > 1 ? 's' : '') . ' → ' . count($dests) . ' destination' . (count($dests) > 1 ? 's' : ''),
            'reason' => 'These orders are time-sensitive — dispatch together to ensure on-time delivery',
            'refs' => $refs,
            'order_count' => count($refs),
            'vendor' => implode(' + ', $vendors),
            'destination' => implode(' + ', $dests),
            'dest_type' => 'mixed',
            'savings_est' => 0,
            'has_urgent' => true,
            'urgent_count' => count(array_filter($urgent, function($o) { return !empty($o['due_info']['is_urgent']); })),
            'score' => $this->scoreGroup($urgent, 'urgent_cluster')
        ]];
    }
    
    /**
     * Score a group of orders for suggestion ranking.
     * Higher = better suggestion.
     */
    private function scoreGroup($orders, $type) {
        $score = 0;
        
        // Base score by type
        switch ($type) {
            case 'same_vendor_dest': $score = 90; break;  // Best: identical route
            case 'same_vendor':     $score = 70; break;  // Good: one pickup
            case 'same_dest':       $score = 60; break;  // OK: chain pickups
            case 'urgent_cluster':  $score = 80; break;  // High: time-sensitive
        }
        
        // Bonus for more orders
        $score += min(20, count($orders) * 4);
        
        // Bonus for urgency
        foreach ($orders as $o) {
            if (!empty($o['due_info']['is_urgent'])) $score += 8;
            if (!empty($o['due_info']['is_priority'])) $score += 4;
        }
        
        // Penalty for mixed destinations (more stops = less efficient)
        $uniqueDests = count(array_unique(array_column($orders, 'destination')));
        if ($uniqueDests > 2) $score -= ($uniqueDests - 2) * 5;
        
        // Penalty for many different vendors (more pickups)
        $uniqueVendors = count(array_unique(array_column($orders, 'vendor_name')));
        if ($uniqueVendors > 2) $score -= ($uniqueVendors - 2) * 3;
        
        return max(0, min(100, $score));
    }
    
    /**
     * Check if orders have compatible due times (within 3-hour window).
     */
    private function areDueTimesCompatible($orders) {
        $hours = [];
        foreach ($orders as $o) {
            $hr = $o['due_info']['hours_remaining'] ?? 999;
            $hours[] = $hr;
        }
        
        if (empty($hours)) return true;
        
        $min = min($hours);
        $max = max($hours);
        
        // If the most urgent order is already urgent (< 3 hrs),
        // all others must also be due within 6 hours
        if ($min < 3 && $max > 6) return false;
        
        // General rule: don't batch orders due today with orders due in 3+ days
        if ($min < 24 && $max > 72) return false;
        
        return true;
    }
    
    /**
     * Remove orders from lower-scored suggestions if they appear in higher-scored ones.
     */
    private function deduplicateSuggestions($suggestions) {
        // Sort by score descending
        usort($suggestions, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        $usedRefs = [];
        $filtered = [];
        
        foreach ($suggestions as $s) {
            // Check how many refs are already used
            $available = array_diff($s['refs'], $usedRefs);
            
            // If less than 2 unique orders remain, skip this suggestion
            if (count($available) < 2) continue;
            
            // If more than half the refs are already used, skip
            if (count($available) < count($s['refs']) / 2) continue;
            
            // Keep suggestion (potentially with reduced refs)
            if (count($available) < count($s['refs'])) {
                $s['refs'] = array_values($available);
                $s['order_count'] = count($available);
                $s['title'] = count($available) . ' orders' . substr($s['title'], strpos($s['title'], ' orders') + 7);
            }
            
            $filtered[] = $s;
            $usedRefs = array_merge($usedRefs, $s['refs']);
        }
        
        return $filtered;
    }
}
