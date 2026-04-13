<?php
/**
 * Social-proof endpoint: returns recent customer orders + computed statistics
 * for the bottom-left toast popup on index.php.
 *
 * Returns JSON: { orders: [...], stats: [...], generatedAt: <unix> }
 *
 * - orders: real recent orders from the LAST 5 DAYS only (anonymized to first
 *   name + last initial, dimensions, event, and relative time). Older orders
 *   are excluded — stale individual orders are less persuasive than statistics.
 * - stats: order-distribution statistics computed from the LAST 90 DAYS of
 *   order history (top tier %, top size %, event variety, total volume,
 *   average savings vs next tier). Always returned. JS uses these as fallback
 *   when there are fewer than 3 recent orders.
 *
 * Result is cached to data/social-proof-cache-v2.json for 5 minutes.
 *
 * Location: /recent-orders.php (root)
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/includes/delivery-validation.php';

$cacheFile = __DIR__ . '/data/social-proof-cache-v2.json';
$cacheMaxAge = 300; // 5 minutes

// Serve cached result if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

// === BUILD FRESH RESULT ===

$ordersDir = __DIR__ . '/uploads/orders';
$recentCutoff = time() - (5 * 24 * 60 * 60);   // 5 days for individual popups
$statsCutoff  = time() - (90 * 24 * 60 * 60);  // 90 days for statistics
$paidStatuses = ['paid', 'preflight', 'printing', 'ready', 'shipped', 'delivered', 'pickedup'];
$tierOrder    = ['early', 'standard', '3days', '2days', 'nextday', 'sameday'];
$tierLabelMap = [
    'early'    => 'Best Value',
    'standard' => 'Standard',
    '3days'    => 'Rush',
    '2days'    => 'Express',
    'nextday'  => 'Priority',
    'sameday'  => 'Same-Day',
];

$recentOrders = []; // last 5 days, for individual popups
$statsOrders  = []; // last 90 days, for stats computation

if (is_dir($ordersDir)) {
    $files = glob($ordersDir . '/*-order.json');
    foreach ($files as $file) {
        if (filemtime($file) < $statsCutoff) continue;

        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data)) continue;

        // Must be a paid status, not refunded
        $status = strtolower($data['status'] ?? '');
        if (!in_array($status, $paidStatuses, true)) continue;
        if (!empty($data['refund'])) continue;

        // Extract name (handle nested + flat field structures)
        $name = '';
        if (!empty($data['customerInfo']['name'])) {
            $name = $data['customerInfo']['name'];
        } elseif (!empty($data['name'])) {
            $name = $data['name'];
        } elseif (!empty($data['customerName'])) {
            $name = $data['customerName'];
        }
        if (empty($name) || !is_string($name)) continue;

        // Parse first name + last initial
        $parts = preg_split('/\s+/', trim($name));
        if (empty($parts) || empty($parts[0])) continue;
        $firstName = ucfirst(strtolower($parts[0]));
        $lastInitial = '';
        if (count($parts) > 1) {
            $lastWord = end($parts);
            $lastInitial = strtoupper(substr($lastWord, 0, 1)) . '.';
        }
        $displayName = $lastInitial ? $firstName . ' ' . $lastInitial : $firstName;

        // Dimensions (handle nested + flat)
        $width = 0;
        $height = 0;
        if (!empty($data['dimensions']['width'])) {
            $width = (int)$data['dimensions']['width'];
            $height = (int)$data['dimensions']['height'];
        } elseif (!empty($data['width'])) {
            $width = (int)$data['width'];
            $height = (int)$data['height'];
        }
        if ($width <= 0 || $height <= 0) continue;
        $dimensions = $width . '" x ' . $height . '"';

        // Event
        $event = '';
        if (is_array($data['event'] ?? null)) {
            $event = $data['event']['acronym'] ?? $data['event']['name'] ?? '';
        } elseif (is_array($data['event_select'] ?? null)) {
            $event = $data['event_select']['acronym'] ?? $data['event_select']['name'] ?? '';
        } elseif (is_string($data['event'] ?? null)) {
            $event = $data['event'];
        }

        // Tier key + base price for stats
        $tierKey = $data['pricing']['tierKey'] ?? '';
        if (!$tierKey && !empty($data['pricing']['tier'])) {
            // Best-effort: map label back to key
            $labelLc = strtolower($data['pricing']['tier']);
            foreach ($tierLabelMap as $k => $lbl) {
                if (strpos($labelLc, strtolower($lbl)) !== false) {
                    $tierKey = $k;
                    break;
                }
            }
        }
        $basePrice = (float)($data['pricing']['basePrice'] ?? 0);
        $material = $data['material'] ?? 'poster';

        // Order time — paidAt > submittedAt > file mtime
        $orderTime = 0;
        if (!empty($data['paidAt'])) {
            $orderTime = strtotime($data['paidAt']);
        } elseif (!empty($data['submittedAt'])) {
            $orderTime = strtotime($data['submittedAt']);
        }
        if (!$orderTime) {
            $orderTime = filemtime($file);
        }

        $record = [
            'name'       => $displayName,
            'dimensions' => $dimensions,
            'event'      => $event,
            'tierKey'    => $tierKey,
            'width'      => $width,
            'height'     => $height,
            'basePrice'  => $basePrice,
            'material'   => $material,
            'time'       => $orderTime,
        ];

        $statsOrders[] = $record;
        if ($orderTime >= $recentCutoff) {
            $recentOrders[] = $record;
        }
    }
}

// Sort recent orders by time desc, cap at 20
usort($recentOrders, function ($a, $b) { return $b['time'] - $a['time']; });
$recentOrders = array_slice($recentOrders, 0, 20);

// Build the public-facing recent orders array (strip private fields)
$publicOrders = [];
$now = time();
foreach ($recentOrders as $o) {
    $diff = $now - $o['time'];
    if ($diff < 60) {
        $rel = 'just now';
    } elseif ($diff < 3600) {
        $mins = (int)round($diff / 60);
        $rel = $mins . ' min ago';
    } elseif ($diff < 86400) {
        $hours = (int)round($diff / 3600);
        $rel = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        $days = (int)round($diff / 86400);
        $rel = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
    $publicOrders[] = [
        'name'         => $o['name'],
        'dimensions'   => $o['dimensions'],
        'event'        => $o['event'],
        'relativeTime' => $rel,
    ];
}

// === COMPUTE STATISTICS ===

$stats = [];
$totalOrders = count($statsOrders);

if ($totalOrders >= 5) {
    // Top tier %
    $tierCounts = [];
    foreach ($statsOrders as $o) {
        $k = $o['tierKey'];
        if ($k) $tierCounts[$k] = ($tierCounts[$k] ?? 0) + 1;
    }
    if (!empty($tierCounts)) {
        arsort($tierCounts);
        $topKey = key($tierCounts);
        $topCount = current($tierCounts);
        $pct = (int)round($topCount / $totalOrders * 100);
        $topLabel = $tierLabelMap[$topKey] ?? $topKey;
        if ($pct >= 15) {
            // Try to compute average savings vs next tier for the top tier
            $topIdx = array_search($topKey, $tierOrder, true);
            $avgSavings = 0;
            if ($topIdx !== false && $topIdx < count($tierOrder) - 1) {
                $nextKey = $tierOrder[$topIdx + 1];
                $totalSav = 0;
                $savCount = 0;
                foreach ($statsOrders as $o) {
                    if ($o['tierKey'] !== $topKey) continue;
                    if (!$o['width'] || !$o['height'] || !$o['basePrice']) continue;
                    $row = findPricingRow($o['width'] * $o['height'], $o['material']);
                    if (!$row) continue;
                    $nextPrice = (float)($row[$nextKey] ?? 0);
                    if ($nextPrice > $o['basePrice']) {
                        $totalSav += ($nextPrice - $o['basePrice']);
                        $savCount++;
                    }
                }
                if ($savCount > 0) {
                    $avgSavings = (int)round($totalSav / $savCount);
                }
            }

            if ($avgSavings > 0) {
                $nextLabel = $tierLabelMap[$tierOrder[$topIdx + 1]] ?? '';
                $stats[] = $pct . '% of customers choose <strong>' . $topLabel
                    . '</strong> turnaround &mdash; saving an average of <strong>$'
                    . $avgSavings . '</strong> per order';
            } else {
                $stats[] = $pct . '% of customers choose <strong>' . $topLabel . '</strong> turnaround';
            }
        }
    }

    // Top size %
    $sizeCounts = [];
    foreach ($statsOrders as $o) {
        $sz = $o['dimensions'];
        if ($sz) $sizeCounts[$sz] = ($sizeCounts[$sz] ?? 0) + 1;
    }
    if (!empty($sizeCounts)) {
        arsort($sizeCounts);
        $topSize = key($sizeCounts);
        $topSizeCount = current($sizeCounts);
        $sizePct = (int)round($topSizeCount / $totalOrders * 100);
        if ($sizePct >= 15) {
            $stats[] = 'Most popular size: <strong>' . $topSize . '</strong> &mdash; chosen by ' . $sizePct . '% of orders';
        }
    }

    // Event variety
    $eventCounts = [];
    foreach ($statsOrders as $o) {
        $e = $o['event'];
        if ($e) $eventCounts[$e] = ($eventCounts[$e] ?? 0) + 1;
    }
    $uniqueEvents = count($eventCounts);
    if ($uniqueEvents >= 5) {
        $stats[] = 'Trusted by customers from <strong>' . $uniqueEvents . '</strong> different events';
    }

    // Volume
    if ($totalOrders >= 50) {
        $stats[] = 'Over <strong>' . $totalOrders . '</strong> posters delivered to MTCC';
    }
}

// Generic nudges as final fallback if no stats computed (e.g. brand-new system)
if (empty($stats)) {
    $stats = [
        'Customers who order ahead save up to 75%',
        'Same delivery date, very different prices &mdash; order early',
        'Plan ahead and pay a fraction of same-day pricing',
    ];
}

$result = json_encode([
    'orders'      => $publicOrders,
    'stats'       => $stats,
    'generatedAt' => $now,
]);

// Write cache (non-fatal if it fails)
@file_put_contents($cacheFile, $result, LOCK_EX);

echo $result;
