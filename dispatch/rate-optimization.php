<?php
/**
 * Rate Optimization — Pricing Analysis & Suggestions
 * MTCC Print Services
 * 
 * Analyzes historical order data to surface pricing insights:
 *   - Tier distribution vs revenue concentration
 *   - Material profitability comparison
 *   - Size-based revenue analysis
 *   - Actionable pricing recommendations
 * 
 * Server path: /dispatch/rate-optimization.php
 */

require_once __DIR__ . '/../admin-auth.php';
requirePermission('dispatch');

// Use the shared analytics calculator
require_once __DIR__ . '/../includes/analytics-calculations.php';

// ============================================
// DATA LOADING
// ============================================

$orderDir = __DIR__ . '/../uploads/orders/';
$statusFile = __DIR__ . '/../data/statuses.json';

$allOrders = AnalyticsCalculator::loadOrders($orderDir, $statusFile);
$totalOrders = count($allOrders);

// Filter to paid/completed orders for pricing analysis
$paidStatuses = AnalyticsCalculator::PAID_STATUSES;
$paidOrders = array_filter($allOrders, function($o) use ($paidStatuses) {
    return in_array($o['status'] ?? '', $paidStatuses);
});
$paidCount = count($paidOrders);

// ============================================
// TIER ANALYSIS
// ============================================

$tierLabels = [
    'early'    => 'Early Bird',
    'standard' => 'Standard',
    '3days'    => '3-Day',
    '2days'    => '2-Day',
    'nextday'  => 'Next Day',
    'sameday'  => 'Same Day',
];

$tierData = [];
foreach ($tierLabels as $key => $label) {
    $tierData[$key] = [
        'label' => $label,
        'count' => 0,
        'revenue' => 0,
        'min_price' => PHP_FLOAT_MAX,
        'max_price' => 0,
        'sizes' => [],
    ];
}

$totalRevenue = 0;
$materialData = ['fabric' => ['count' => 0, 'revenue' => 0], 'poster' => ['count' => 0, 'revenue' => 0]];
$sizeRevenue = [];
$eventRevenue = [];

foreach ($paidOrders as $order) {
    $price = (float)($order['pricing']['total'] ?? 0);
    $totalRevenue += $price;
    
    // Tier classification
    $tierRaw = $order['pricing']['tier'] ?? 'standard';
    $tierClass = AnalyticsCalculator::getTurnaroundClass($tierRaw);
    
    if (isset($tierData[$tierClass])) {
        $tierData[$tierClass]['count']++;
        $tierData[$tierClass]['revenue'] += $price;
        $tierData[$tierClass]['min_price'] = min($tierData[$tierClass]['min_price'], $price);
        $tierData[$tierClass]['max_price'] = max($tierData[$tierClass]['max_price'], $price);
        
        // Track sizes per tier
        $w = $order['dimensions']['width'] ?? 0;
        $h = $order['dimensions']['height'] ?? 0;
        if ($w && $h) {
            $sqin = $w * $h;
            $tierData[$tierClass]['sizes'][] = $sqin;
        }
    }
    
    // Material
    $mat = strtolower($order['material'] ?? 'poster');
    $matKey = (strpos($mat, 'fabric') !== false) ? 'fabric' : 'poster';
    $materialData[$matKey]['count']++;
    $materialData[$matKey]['revenue'] += $price;
    
    // Size buckets
    $w = $order['dimensions']['width'] ?? 0;
    $h = $order['dimensions']['height'] ?? 0;
    if ($w && $h) {
        $sizeKey = $w . '" × ' . $h . '"';
        if (!isset($sizeRevenue[$sizeKey])) {
            $sizeRevenue[$sizeKey] = ['count' => 0, 'revenue' => 0, 'sqin' => $w * $h];
        }
        $sizeRevenue[$sizeKey]['count']++;
        $sizeRevenue[$sizeKey]['revenue'] += $price;
    }
    
    // Event
    $event = $order['event']['acronym'] ?? $order['event']['name'] ?? 'Unknown';
    if (!isset($eventRevenue[$event])) {
        $eventRevenue[$event] = ['count' => 0, 'revenue' => 0];
    }
    $eventRevenue[$event]['count']++;
    $eventRevenue[$event]['revenue'] += $price;
}

// Compute derived metrics
foreach ($tierData as $key => &$t) {
    $t['pct_orders'] = $paidCount > 0 ? round(($t['count'] / $paidCount) * 100, 1) : 0;
    $t['pct_revenue'] = $totalRevenue > 0 ? round(($t['revenue'] / $totalRevenue) * 100, 1) : 0;
    $t['avg_price'] = $t['count'] > 0 ? round($t['revenue'] / $t['count'], 2) : 0;
    $t['avg_sqin'] = !empty($t['sizes']) ? round(array_sum($t['sizes']) / count($t['sizes'])) : 0;
    $t['price_per_sqin'] = ($t['avg_sqin'] > 0 && $t['count'] > 0) ? round($t['avg_price'] / $t['avg_sqin'], 4) : 0;
    if ($t['min_price'] === PHP_FLOAT_MAX) $t['min_price'] = 0;
}
unset($t);

// Material metrics
foreach ($materialData as &$m) {
    $m['avg_price'] = $m['count'] > 0 ? round($m['revenue'] / $m['count'], 2) : 0;
    $m['pct_orders'] = $paidCount > 0 ? round(($m['count'] / $paidCount) * 100, 1) : 0;
    $m['pct_revenue'] = $totalRevenue > 0 ? round(($m['revenue'] / $totalRevenue) * 100, 1) : 0;
}
unset($m);

// Sort sizes by count
uasort($sizeRevenue, function($a, $b) { return $b['count'] <=> $a['count']; });
$topSizes = array_slice($sizeRevenue, 0, 8, true);

// Sort events by revenue
uasort($eventRevenue, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });

// ============================================
// GENERATE INSIGHTS
// ============================================

$insights = [];

// Insight: Dominant tier
$maxTier = null;
$maxTierCount = 0;
foreach ($tierData as $key => $t) {
    if ($t['count'] > $maxTierCount) {
        $maxTierCount = $t['count'];
        $maxTier = $key;
    }
}
if ($maxTier && $tierData[$maxTier]['pct_orders'] > 40) {
    $t = $tierData[$maxTier];
    $insights[] = [
        'type' => 'info',
        'icon' => '&#128202;',
        'title' => $t['label'] . ' tier dominates at ' . $t['pct_orders'] . '% of orders',
        'detail' => 'This tier generates ' . $t['pct_revenue'] . '% of revenue with an average order of $' . number_format($t['avg_price'], 2) . '. ' .
            ($t['pct_revenue'] < $t['pct_orders'] 
                ? 'Revenue share is lower than order share — consider a price increase for this tier.'
                : 'Revenue share exceeds order share — pricing is effective for this tier.')
    ];
}

// Insight: Revenue concentration gap
foreach ($tierData as $key => $t) {
    if ($t['count'] < 3) continue;
    $gap = $t['pct_orders'] - $t['pct_revenue'];
    if ($gap > 10) {
        $insights[] = [
            'type' => 'warning',
            'icon' => '&#9888;&#65039;',
            'title' => $t['label'] . ' tier: ' . $t['pct_orders'] . '% of orders but only ' . $t['pct_revenue'] . '% of revenue',
            'detail' => 'This ' . round($gap, 1) . 'pp gap suggests the ' . $t['label'] . ' tier may be underpriced relative to demand. A 5–10% price increase could capture $' . 
                number_format($t['avg_price'] * $t['count'] * 0.07, 0) . ' in additional revenue without significantly affecting order volume.'
        ];
    }
}

// Insight: Same-day premium analysis
if (isset($tierData['sameday']) && isset($tierData['standard'])) {
    $sd = $tierData['sameday'];
    $st = $tierData['standard'];
    if ($sd['avg_price'] > 0 && $st['avg_price'] > 0) {
        $premium = round(($sd['avg_price'] / $st['avg_price'] - 1) * 100);
        $insights[] = [
            'type' => $premium < 150 ? 'warning' : 'success',
            'icon' => $premium < 150 ? '&#128161;' : '&#9989;',
            'title' => 'Same-day premium is ' . $premium . '% over Standard',
            'detail' => $premium < 150
                ? 'Industry convention print services typically charge 150–200% premium for same-day. Your current ' . $premium . '% premium may have room for adjustment.'
                : 'Your same-day premium is well-positioned within the 150–200% industry range.'
        ];
    }
}

// Insight: Material comparison
if ($materialData['fabric']['count'] > 0 && $materialData['poster']['count'] > 0) {
    $fabricAvg = $materialData['fabric']['avg_price'];
    $posterAvg = $materialData['poster']['avg_price'];
    $diff = round((($fabricAvg / max(1, $posterAvg)) - 1) * 100);
    $insights[] = [
        'type' => 'info',
        'icon' => '&#129525;',
        'title' => 'Fabric orders average $' . number_format($fabricAvg, 2) . ' vs poster paper at $' . number_format($posterAvg, 2),
        'detail' => 'Fabric carries a ' . $diff . '% price premium and represents ' . $materialData['fabric']['pct_orders'] . '% of orders. ' .
            ($materialData['fabric']['pct_orders'] < 20 
                ? 'Low fabric adoption suggests customers may not be aware of the option or find the premium too high.'
                : 'Healthy fabric adoption — the premium is well-received by customers.')
    ];
}

// Insight: Underutilized tiers
foreach ($tierData as $key => $t) {
    if ($t['count'] === 0 && $key !== 'early') {
        $insights[] = [
            'type' => 'info',
            'icon' => '&#128237;',
            'title' => $t['label'] . ' tier has zero orders',
            'detail' => 'No orders have been placed in this tier. This could indicate the lead time window doesn\'t align with typical customer behaviour, or that adjacent tiers are more attractive at their current price points.'
        ];
    }
}

// Insight: Top event revenue
if (!empty($eventRevenue)) {
    $topEvent = array_key_first($eventRevenue);
    $topData = $eventRevenue[$topEvent];
    $insights[] = [
        'type' => 'success',
        'icon' => '&#127919;',
        'title' => $topEvent . ' is the top revenue event at $' . number_format($topData['revenue'], 0),
        'detail' => $topData['count'] . ' orders with an average of $' . number_format($topData['revenue'] / $topData['count'], 2) . ' per order. Consider event-specific pricing or promotions for high-volume events.'
    ];
}

// Load current pricing tables for reference
$fabricPricing = [];
$posterPricing = [];
$fabricFile = __DIR__ . '/../Fabric_Pricing.csv';
$posterFile = __DIR__ . '/../Poster_Paper_Pricing.csv';
if (file_exists($fabricFile)) {
    $rows = array_map('str_getcsv', file($fabricFile));
    $header = array_shift($rows);
    foreach ($rows as $row) {
        if (count($row) >= 8) {
            $fabricPricing[] = array_combine($header, $row);
        }
    }
}
if (file_exists($posterFile)) {
    $rows = array_map('str_getcsv', file($posterFile));
    $header = array_map('trim', array_shift($rows));
    foreach ($rows as $row) {
        if (count($row) >= 8) {
            $posterPricing[] = array_combine($header, $row);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rate Optimization - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
    .ro-container { max-width: 1400px; margin: 0 auto; padding: 0 20px 40px; }

    .ro-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .ro-header-left h1 {
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--text);
        margin-bottom: 2px;
    }
    .ro-header-left p { font-size: 0.8rem; color: var(--subtext); }
    .ro-back {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 14px;
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--subtext);
        border: 1px solid #d1d5db;
        border-radius: 6px;
        text-decoration: none;
        transition: all 0.15s;
    }
    .ro-back:hover { background: #f9fafb; border-color: #9ca3af; color: var(--text); }

    /* Summary strip */
    .ro-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .ro-stat {
        background: var(--card);
        border: 1px solid #e5e7eb;
        border-radius: var(--radius);
        padding: 14px 18px;
        box-shadow: var(--shadow-sm);
    }
    .ro-stat-label {
        font-size: 0.66rem;
        font-weight: 700;
        color: var(--subtext);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 4px;
    }
    .ro-stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1;
    }
    .ro-stat-sub { font-size: 0.68rem; color: var(--subtext); margin-top: 3px; }
    .ro-stat-purple .ro-stat-value { color: var(--primary); }
    .ro-stat-green .ro-stat-value { color: var(--green); }

    /* Insights */
    .ro-insights {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-bottom: 24px;
    }
    .ro-insights-title {
        font-size: 0.9rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 4px;
    }
    .ro-insight {
        display: flex;
        gap: 12px;
        padding: 14px 18px;
        background: var(--card);
        border: 1px solid #e5e7eb;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
    }
    .ro-insight-icon { font-size: 1.2rem; flex-shrink: 0; line-height: 1.4; }
    .ro-insight-body { flex: 1; min-width: 0; }
    .ro-insight-title { font-size: 0.82rem; font-weight: 700; color: var(--text); margin-bottom: 3px; }
    .ro-insight-detail { font-size: 0.76rem; color: var(--subtext); line-height: 1.5; }
    .ro-insight.type-warning { border-left: 3px solid var(--orange); }
    .ro-insight.type-success { border-left: 3px solid var(--green); }
    .ro-insight.type-info    { border-left: 3px solid var(--blue); }

    /* Tier table */
    .ro-panel {
        background: var(--card);
        border: 1px solid #e5e7eb;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        margin-bottom: 16px;
        overflow: hidden;
    }
    .ro-panel-header {
        padding: 14px 18px;
        border-bottom: 1px solid #f3f4f6;
    }
    .ro-panel-title {
        font-size: 0.86rem;
        font-weight: 700;
        color: var(--text);
    }
    .ro-panel-sub { font-size: 0.7rem; color: var(--subtext); margin-top: 2px; }

    .ro-table { width: 100%; border-collapse: collapse; }
    .ro-table th {
        font-size: 0.66rem;
        font-weight: 700;
        color: var(--subtext);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        text-align: left;
        padding: 8px 14px;
        border-bottom: 2px solid #e5e7eb;
        white-space: nowrap;
    }
    .ro-table th.right, .ro-table td.right { text-align: right; }
    .ro-table td {
        padding: 10px 14px;
        font-size: 0.8rem;
        border-bottom: 1px solid #f3f4f6;
        color: var(--text);
    }
    .ro-table tr:last-child td { border-bottom: none; }
    .ro-table tr:hover td { background: var(--bg); }

    .ro-tier-label { font-weight: 700; }
    .ro-bar-cell { width: 120px; }
    .ro-bar-wrap {
        height: 6px;
        background: #f3f4f6;
        border-radius: 3px;
        overflow: hidden;
    }
    .ro-bar-fill {
        height: 100%;
        border-radius: 3px;
        transition: width 0.6s ease;
    }
    .ro-bar-orders { background: var(--primary); }
    .ro-bar-revenue { background: var(--green); }
    .ro-gap-positive { color: var(--green); font-weight: 600; }
    .ro-gap-negative { color: var(--orange); font-weight: 600; }
    .ro-gap-neutral  { color: var(--subtext); }

    .ro-grid-2 {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 16px;
    }
    .chart-wrap { position: relative; height: 220px; padding: 0 18px 18px; }

    /* Pricing reference tables */
    .ro-pricing-table { width: 100%; border-collapse: collapse; font-size: 0.72rem; }
    .ro-pricing-table th {
        padding: 6px 8px;
        font-size: 0.62rem;
        font-weight: 700;
        color: var(--subtext);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        text-align: right;
        border-bottom: 2px solid #e5e7eb;
        white-space: nowrap;
    }
    .ro-pricing-table th:first-child, .ro-pricing-table td:first-child { text-align: left; }
    .ro-pricing-table td {
        padding: 5px 8px;
        border-bottom: 1px solid #f3f4f6;
        text-align: right;
        color: var(--text);
    }
    .ro-pricing-table tr:hover td { background: var(--bg); }

    /* Empty */
    .ro-empty {
        text-align: center;
        padding: 60px 20px;
        color: var(--subtext);
    }
    .ro-empty-icon { font-size: 3rem; margin-bottom: 12px; }
    .ro-empty-title { font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 6px; }

    @media (max-width: 768px) {
        .ro-grid-2 { grid-template-columns: 1fr; }
        .ro-summary { grid-template-columns: repeat(2, 1fr); }
        .ro-header { flex-direction: column; align-items: flex-start; }
    }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_rates'); ?>
<script src="../js/admin-sidebar.js"></script>

<div class="ro-container">

    <!-- Header -->
    <div class="ro-header">
        <div class="ro-header-left">
            <h1>&#128200; Rate Optimization</h1>
            <p>Pricing analysis and recommendations based on <?= $paidCount ?> paid orders</p>
        </div>
        <div>
            <a href="analytics.php" class="ro-back">&larr; Analytics</a>
        </div>
    </div>

    <?php if ($paidCount === 0): ?>
    <div class="ro-empty">
        <div class="ro-empty-icon">&#128200;</div>
        <div class="ro-empty-title">Not enough data yet</div>
        <p style="font-size:0.82rem;max-width:400px;margin:0 auto;">Rate optimization requires paid orders to analyse. Insights will appear once order history is available.</p>
    </div>
    <?php else: ?>

    <!-- Summary Strip -->
    <div class="ro-summary">
        <div class="ro-stat ro-stat-purple">
            <div class="ro-stat-label">Total Revenue</div>
            <div class="ro-stat-value">$<?= number_format($totalRevenue, 0) ?></div>
            <div class="ro-stat-sub"><?= $paidCount ?> paid orders</div>
        </div>
        <div class="ro-stat">
            <div class="ro-stat-label">Avg Order Value</div>
            <div class="ro-stat-value">$<?= number_format($paidCount > 0 ? $totalRevenue / $paidCount : 0, 2) ?></div>
            <div class="ro-stat-sub">Across all tiers</div>
        </div>
        <div class="ro-stat">
            <div class="ro-stat-label">Fabric Share</div>
            <div class="ro-stat-value"><?= $materialData['fabric']['pct_orders'] ?>%</div>
            <div class="ro-stat-sub"><?= $materialData['fabric']['count'] ?> orders</div>
        </div>
        <div class="ro-stat ro-stat-green">
            <div class="ro-stat-label">Active Events</div>
            <div class="ro-stat-value"><?= count($eventRevenue) ?></div>
            <div class="ro-stat-sub">Contributing revenue</div>
        </div>
    </div>

    <!-- Insights -->
    <?php if (!empty($insights)): ?>
    <div class="ro-insights">
        <div class="ro-insights-title">&#9889; Pricing Insights</div>
        <?php foreach ($insights as $ins): ?>
        <div class="ro-insight type-<?= $ins['type'] ?>">
            <div class="ro-insight-icon"><?= $ins['icon'] ?></div>
            <div class="ro-insight-body">
                <div class="ro-insight-title"><?= htmlspecialchars($ins['title']) ?></div>
                <div class="ro-insight-detail"><?= htmlspecialchars($ins['detail']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tier Breakdown Table -->
    <div class="ro-panel">
        <div class="ro-panel-header">
            <div class="ro-panel-title">Tier Performance Breakdown</div>
            <div class="ro-panel-sub">Orders vs revenue share per pricing tier. Negative gaps indicate underpricing opportunities.</div>
        </div>
        <table class="ro-table">
            <thead>
                <tr>
                    <th>Tier</th>
                    <th class="right">Orders</th>
                    <th class="right">% Orders</th>
                    <th>Order Share</th>
                    <th class="right">Revenue</th>
                    <th class="right">% Revenue</th>
                    <th>Revenue Share</th>
                    <th class="right">Avg Price</th>
                    <th class="right">Gap</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tierData as $key => $t): ?>
                <tr>
                    <td class="ro-tier-label"><?= $t['label'] ?></td>
                    <td class="right"><?= $t['count'] ?></td>
                    <td class="right"><?= $t['pct_orders'] ?>%</td>
                    <td class="ro-bar-cell">
                        <div class="ro-bar-wrap"><div class="ro-bar-fill ro-bar-orders" style="width: <?= min($t['pct_orders'], 100) ?>%"></div></div>
                    </td>
                    <td class="right">$<?= number_format($t['revenue'], 0) ?></td>
                    <td class="right"><?= $t['pct_revenue'] ?>%</td>
                    <td class="ro-bar-cell">
                        <div class="ro-bar-wrap"><div class="ro-bar-fill ro-bar-revenue" style="width: <?= min($t['pct_revenue'], 100) ?>%"></div></div>
                    </td>
                    <td class="right">$<?= number_format($t['avg_price'], 2) ?></td>
                    <?php
                        $gap = $t['pct_revenue'] - $t['pct_orders'];
                        $gapClass = $gap > 2 ? 'ro-gap-positive' : ($gap < -2 ? 'ro-gap-negative' : 'ro-gap-neutral');
                    ?>
                    <td class="right <?= $gapClass ?>"><?= ($gap > 0 ? '+' : '') . round($gap, 1) ?>pp</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Charts Row -->
    <div class="ro-grid-2">
        <!-- Tier Distribution Chart -->
        <div class="ro-panel">
            <div class="ro-panel-header">
                <div class="ro-panel-title">Order Volume by Tier</div>
            </div>
            <div class="chart-wrap">
                <canvas id="tierOrdersChart"></canvas>
            </div>
        </div>
        <!-- Revenue by Tier Chart -->
        <div class="ro-panel">
            <div class="ro-panel-header">
                <div class="ro-panel-title">Revenue by Tier</div>
            </div>
            <div class="chart-wrap">
                <canvas id="tierRevenueChart"></canvas>
            </div>
        </div>
    </div>

    <div class="ro-grid-2">
        <!-- Material Split -->
        <div class="ro-panel">
            <div class="ro-panel-header">
                <div class="ro-panel-title">Material Comparison</div>
            </div>
            <table class="ro-table">
                <thead>
                    <tr>
                        <th>Material</th>
                        <th class="right">Orders</th>
                        <th class="right">Revenue</th>
                        <th class="right">Avg Price</th>
                        <th class="right">% Orders</th>
                        <th class="right">% Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td class="ro-tier-label">Poster Paper</td>
                        <td class="right"><?= $materialData['poster']['count'] ?></td>
                        <td class="right">$<?= number_format($materialData['poster']['revenue'], 0) ?></td>
                        <td class="right">$<?= number_format($materialData['poster']['avg_price'], 2) ?></td>
                        <td class="right"><?= $materialData['poster']['pct_orders'] ?>%</td>
                        <td class="right"><?= $materialData['poster']['pct_revenue'] ?>%</td>
                    </tr>
                    <tr>
                        <td class="ro-tier-label">Fabric</td>
                        <td class="right"><?= $materialData['fabric']['count'] ?></td>
                        <td class="right">$<?= number_format($materialData['fabric']['revenue'], 0) ?></td>
                        <td class="right">$<?= number_format($materialData['fabric']['avg_price'], 2) ?></td>
                        <td class="right"><?= $materialData['fabric']['pct_orders'] ?>%</td>
                        <td class="right"><?= $materialData['fabric']['pct_revenue'] ?>%</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Top Sizes -->
        <div class="ro-panel">
            <div class="ro-panel-header">
                <div class="ro-panel-title">Top Sizes by Volume</div>
            </div>
            <table class="ro-table">
                <thead>
                    <tr>
                        <th>Size</th>
                        <th class="right">Sq In</th>
                        <th class="right">Orders</th>
                        <th class="right">Revenue</th>
                        <th class="right">Avg Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topSizes as $size => $s): ?>
                    <tr>
                        <td class="ro-tier-label"><?= htmlspecialchars($size) ?></td>
                        <td class="right"><?= number_format($s['sqin']) ?></td>
                        <td class="right"><?= $s['count'] ?></td>
                        <td class="right">$<?= number_format($s['revenue'], 0) ?></td>
                        <td class="right">$<?= number_format($s['count'] > 0 ? $s['revenue'] / $s['count'] : 0, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Event Revenue -->
    <div class="ro-panel">
        <div class="ro-panel-header">
            <div class="ro-panel-title">Revenue by Event</div>
            <div class="ro-panel-sub">Average order value varies by event type — higher-value events may support premium pricing.</div>
        </div>
        <table class="ro-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th class="right">Orders</th>
                    <th class="right">Revenue</th>
                    <th class="right">Avg Order</th>
                    <th class="right">% of Total Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventRevenue as $event => $e): ?>
                <tr>
                    <td class="ro-tier-label"><?= htmlspecialchars($event) ?></td>
                    <td class="right"><?= $e['count'] ?></td>
                    <td class="right">$<?= number_format($e['revenue'], 0) ?></td>
                    <td class="right">$<?= number_format($e['count'] > 0 ? $e['revenue'] / $e['count'] : 0, 2) ?></td>
                    <td class="right"><?= $totalRevenue > 0 ? round(($e['revenue'] / $totalRevenue) * 100, 1) : 0 ?>%</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Current Pricing Reference -->
    <div class="ro-grid-2">
        <div class="ro-panel">
            <div class="ro-panel-header">
                <div class="ro-panel-title">Current Pricing: Poster Paper</div>
                <div class="ro-panel-sub">Size range (sq in) → price per tier</div>
            </div>
            <div style="overflow-x:auto;padding:0 4px 12px;">
                <table class="ro-pricing-table">
                    <thead>
                        <tr>
                            <th>Size Range</th>
                            <th>Early</th>
                            <th>Standard</th>
                            <th>3-Day</th>
                            <th>2-Day</th>
                            <th>Next Day</th>
                            <th>Same Day</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($posterPricing, 0, 12) as $row): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= $row['min'] ?>–<?= $row['max'] ?></td>
                            <td>$<?= $row['early'] ?></td>
                            <td>$<?= $row['standard'] ?></td>
                            <td>$<?= $row['3days'] ?></td>
                            <td>$<?= $row['2days'] ?></td>
                            <td>$<?= $row['nextday'] ?></td>
                            <td>$<?= $row['sameday'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="ro-panel">
            <div class="ro-panel-header">
                <div class="ro-panel-title">Current Pricing: Fabric</div>
                <div class="ro-panel-sub">Size range (sq in) → price per tier</div>
            </div>
            <div style="overflow-x:auto;padding:0 4px 12px;">
                <table class="ro-pricing-table">
                    <thead>
                        <tr>
                            <th>Size Range</th>
                            <th>Early</th>
                            <th>Standard</th>
                            <th>3-Day</th>
                            <th>2-Day</th>
                            <th>Next Day</th>
                            <th>Same Day</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($fabricPricing, 0, 12) as $row): ?>
                        <tr>
                            <td style="white-space:nowrap"><?= $row['min'] ?>–<?= $row['max'] ?></td>
                            <td>$<?= $row['early'] ?></td>
                            <td>$<?= $row['standard'] ?></td>
                            <td>$<?= $row['3days'] ?></td>
                            <td>$<?= $row['2days'] ?></td>
                            <td>$<?= $row['nextday'] ?></td>
                            <td>$<?= $row['sameday'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; // end paidCount > 0 ?>
</div>

<script>
<?php if ($paidCount > 0): ?>
Chart.defaults.font.family = "'Montserrat', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#6b7280';

var tierLabels = <?= json_encode(array_column($tierData, 'label')) ?>;
var tierOrders = <?= json_encode(array_column($tierData, 'count')) ?>;
var tierRevenue = <?= json_encode(array_map(function($t){ return round($t['revenue'], 2); }, $tierData)) ?>;

var tierColors = ['#059669', '#0284c7', '#7c3aed', '#ea580c', '#dc2626', '#eab308'];

// Tier Orders Doughnut
new Chart(document.getElementById('tierOrdersChart'), {
    type: 'doughnut',
    data: {
        labels: tierLabels,
        datasets: [{ data: tierOrders, backgroundColor: tierColors, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { boxWidth: 10, padding: 8 } } }
    }
});

// Tier Revenue Doughnut
new Chart(document.getElementById('tierRevenueChart'), {
    type: 'doughnut',
    data: {
        labels: tierLabels,
        datasets: [{ data: tierRevenue, backgroundColor: tierColors, borderWidth: 2, borderColor: '#fff' }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { boxWidth: 10, padding: 8 } } }
    }
});
<?php endif; ?>
</script>

</body>
</html>
