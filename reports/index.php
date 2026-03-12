<?php
/**
 * Reports - Revenue Reporting System
 * MTCC Print Services
 * 
 * Tax-compliant revenue reporting with breakdowns by event, period, tier, size.
 * Features: Drill-down filtering with clickable tables and filter chips.
 */
require_once '../admin-auth.php';
requirePermission('reports');

// Include shared calculations
require_once __DIR__ . '/../includes/analytics-calculations.php';

// Load events for filter dropdown
$eventsFile = '../events.json';
$eventsData = [];
if (file_exists($eventsFile)) {
    $eventsData = json_decode(file_get_contents($eventsFile), true) ?: [];
}

// Get all unique event prefixes from orders
$allOrders = AnalyticsCalculator::loadOrders('../uploads/orders/', '../data/statuses.json');

// Load preflight log for vendor COGS data
$preflightLogFile = '../data/preflight-log.json';
$preflightLog = file_exists($preflightLogFile) ? (json_decode(file_get_contents($preflightLogFile), true) ?: []) : [];
$preflightEntries = $preflightLog['entries'] ?? [];

// Attach vendor pricing to orders
foreach ($allOrders as &$orderRef) {
    $ref = $orderRef['referenceCode'] ?? '';
    $pfEntry = $preflightEntries[$ref] ?? [];
    $orderRef['vendor_pricing'] = $pfEntry['vendor_pricing'] ?? null;
    $orderRef['vendor_name'] = $pfEntry['vendor_name'] ?? null;
    $orderRef['packing'] = $pfEntry['packing'] ?? null;
}
unset($orderRef);

$eventPrefixes = [];
foreach ($allOrders as $order) {
    $prefix = AnalyticsCalculator::getEventPrefix($order['referenceCode'] ?? '');
    if (!in_array($prefix, $eventPrefixes) && $prefix !== 'UNKNOWN') {
        $eventPrefixes[] = $prefix;
    }
}
sort($eventPrefixes);

// Get date range from request or default to this month
$periodType = $_GET['period'] ?? 'this_month';
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

if (!$startDate || !$endDate) {
    $dates = AnalyticsCalculator::getPeriodDates($periodType);
    $startDate = $dates['start'];
    $endDate = $dates['end'];
}

// Get comparison period
$comparisonDates = AnalyticsCalculator::getPeriodDates('last_month');

// ============================================================
// DRILL-DOWN FILTERS (from URL parameters)
// ============================================================
$drillEvent = $_GET['drill_event'] ?? '';
$drillTier = $_GET['drill_tier'] ?? '';
$drillSize = $_GET['drill_size'] ?? '';
$drillDate = $_GET['drill_date'] ?? '';
$drillMaterial = $_GET['drill_material'] ?? '';

// Also support legacy events[] parameter
$selectedEvents = isset($_GET['events']) ? (array)$_GET['events'] : [];
if (!empty($selectedEvents) && empty($drillEvent)) {
    $drillEvent = $selectedEvents[0];
}

// Build active filters array for display
$activeFilters = [];
if ($drillEvent) $activeFilters['event'] = $drillEvent;
if ($drillTier) $activeFilters['tier'] = $drillTier;
if ($drillSize) $activeFilters['size'] = $drillSize;
if ($drillDate) $activeFilters['date'] = $drillDate;
if ($drillMaterial) $activeFilters['material'] = $drillMaterial;

$hasActiveFilters = !empty($activeFilters);

// ============================================================
// FILTER ORDERS
// ============================================================
$filteredOrders = AnalyticsCalculator::filterByDateRange($allOrders, $startDate, $endDate, 'paidAt');
$comparisonOrders = AnalyticsCalculator::filterByDateRange($allOrders, $comparisonDates['start'], $comparisonDates['end'], 'paidAt');

// Apply drill-down filters
if ($drillEvent) {
    $filteredOrders = array_filter($filteredOrders, function($order) use ($drillEvent) {
        $prefix = AnalyticsCalculator::getEventPrefix($order['referenceCode'] ?? '');
        return $prefix === $drillEvent;
    });
}

if ($drillTier) {
    $filteredOrders = array_filter($filteredOrders, function($order) use ($drillTier) {
        $orderTier = $order['pricing']['tier'] ?? 'standard';
        $normalizedTier = AnalyticsCalculator::getTurnaroundClass($orderTier);
        return $normalizedTier === $drillTier;
    });
}

if ($drillSize) {
    $filteredOrders = array_filter($filteredOrders, function($order) use ($drillSize) {
        $width = $order['dimensions']['width'] ?? 0;
        $height = $order['dimensions']['height'] ?? 0;
        $size = intval($width) . 'x' . intval($height);
        return $size === $drillSize;
    });
}

if ($drillDate) {
    $filteredOrders = array_filter($filteredOrders, function($order) use ($drillDate) {
        $paidAt = $order['paidAt'] ?? $order['submittedAt'] ?? null;
        if (!$paidAt) return false;
        return date('Y-m-d', strtotime($paidAt)) === $drillDate;
    });
}

if ($drillMaterial) {
    $filteredOrders = array_filter($filteredOrders, function($order) use ($drillMaterial) {
        return ($order['material'] ?? 'poster') === $drillMaterial;
    });
}

$filteredOrders = array_values($filteredOrders);

// Get analytics for filtered orders
$analytics = AnalyticsCalculator::getCompleteSummary($filteredOrders);
$comparison = AnalyticsCalculator::comparePeriods($filteredOrders, $comparisonOrders);

// ============================================================
// VENDOR COST (COGS) ANALYTICS
// ============================================================
$vendorCosts = [];  // By vendor
$eventCosts = [];   // By event

foreach ($filteredOrders as $order) {
    $vp = $order['vendor_pricing'] ?? null;
    $vpStatus = $vp['status'] ?? 'none';
    $vendorName = $order['vendor_name'] ?? 'Unassigned';
    $eventPrefix = AnalyticsCalculator::getEventPrefix($order['referenceCode'] ?? '');
    $customerRevenue = floatval($order['pricing']['total'] ?? 0);

    // By Vendor
    if (!isset($vendorCosts[$vendorName])) {
        $vendorCosts[$vendorName] = ['orders' => 0, 'revenue' => 0, 'cogs' => 0, 'cogs_base' => 0, 'cogs_packing' => 0, 'cogs_tax' => 0, 'pending' => 0, 'priced' => 0];
    }
    $vendorCosts[$vendorName]['orders']++;
    $vendorCosts[$vendorName]['revenue'] += $customerRevenue;
    if ($vpStatus === 'accepted') {
        $vendorCosts[$vendorName]['cogs'] += floatval($vp['total'] ?? 0);
        $vendorCosts[$vendorName]['cogs_base'] += floatval($vp['base_price'] ?? 0);
        $vendorCosts[$vendorName]['cogs_packing'] += floatval($vp['packing_price'] ?? 0);
        $vendorCosts[$vendorName]['cogs_tax'] += floatval($vp['tax_amount'] ?? 0);
        $vendorCosts[$vendorName]['priced']++;
    } elseif ($vpStatus === 'submitted') {
        $vendorCosts[$vendorName]['pending']++;
    }

    // By Event
    if (!isset($eventCosts[$eventPrefix])) {
        $eventCosts[$eventPrefix] = ['orders' => 0, 'revenue' => 0, 'cogs' => 0, 'pending' => 0, 'priced' => 0];
    }
    $eventCosts[$eventPrefix]['orders']++;
    $eventCosts[$eventPrefix]['revenue'] += $customerRevenue;
    if ($vpStatus === 'accepted') {
        $eventCosts[$eventPrefix]['cogs'] += floatval($vp['total'] ?? 0);
        $eventCosts[$eventPrefix]['priced']++;
    } elseif ($vpStatus === 'submitted') {
        $eventCosts[$eventPrefix]['pending']++;
    }
}

// Sort vendors by COGS descending
uasort($vendorCosts, function($a, $b) { return $b['cogs'] <=> $a['cogs']; });
// Sort events by revenue descending
uasort($eventCosts, function($a, $b) { return $b['revenue'] <=> $a['revenue']; });

// Totals
$totalCogs = array_sum(array_column($vendorCosts, 'cogs'));
$totalRevenue = array_sum(array_column($vendorCosts, 'revenue'));
$totalProfit = $totalRevenue - $totalCogs;
$totalPendingCount = array_sum(array_column($vendorCosts, 'pending'));

// Calculate outstanding (unpaid) orders in the date range
$dateRangeOrders = AnalyticsCalculator::filterByDateRange($allOrders, $startDate, $endDate, 'submittedAt');
$unpaidOrders = array_filter($dateRangeOrders, function($order) {
    return in_array($order['status'] ?? 'unpaid', ['unpaid', 'file_issue']);
});
$unpaidCount = count($unpaidOrders);
$unpaidTotal = array_sum(array_map(function($o) { return (float)($o['pricing']['total'] ?? 0); }, $unpaidOrders));

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function formatMoney($amount) {
    return '$' . number_format((float)$amount, 2);
}

function formatChange($change) {
    if ($change > 0) {
        return ['class' => 'positive', 'text' => '+' . number_format($change, 1) . '%'];
    } elseif ($change < 0) {
        return ['class' => 'negative', 'text' => number_format($change, 1) . '%'];
    }
    return ['class' => 'neutral', 'text' => '0%'];
}

function getTierBadgeClass($tier) {
    $tierLower = strtolower($tier ?? '');
    
    if (strpos($tierLower, 'last minute') !== false || $tierLower === 'sameday' || strpos($tierLower, 'same day') !== false) {
        return 'tier-lastminute';
    } elseif (strpos($tierLower, 'critical') !== false || $tierLower === 'nextday' || strpos($tierLower, 'next day') !== false) {
        return 'tier-critical';
    } elseif (strpos($tierLower, 'urgent') !== false || $tierLower === '2days' || strpos($tierLower, '2 day') !== false) {
        return 'tier-urgent';
    } elseif (strpos($tierLower, 'rush') !== false || $tierLower === '3days' || strpos($tierLower, '3 day') !== false) {
        return 'tier-rush';
    } elseif (strpos($tierLower, 'early') !== false) {
        return 'tier-early';
    }
    return 'tier-standard';
}

function getCleanTierLabel($tier) {
    $tierLower = strtolower($tier ?? '');
    
    // Map any format to clean label
    if (strpos($tierLower, 'last minute') !== false || $tierLower === 'sameday' || strpos($tierLower, 'same day') !== false) {
        return 'Last Minute';
    } elseif (strpos($tierLower, 'critical') !== false || $tierLower === 'nextday' || strpos($tierLower, 'next day') !== false) {
        return 'Critical';
    } elseif (strpos($tierLower, 'urgent') !== false || $tierLower === '2days' || strpos($tierLower, '2 day') !== false) {
        return 'Urgent';
    } elseif (strpos($tierLower, 'rush') !== false || $tierLower === '3days' || strpos($tierLower, '3 day') !== false) {
        return 'Rush';
    } elseif (strpos($tierLower, 'early') !== false) {
        return 'Early';
    }
    return 'Standard';
}

function buildFilterUrl($addFilter = [], $removeFilter = null) {
    $params = [];
    if (isset($_GET['period'])) $params['period'] = $_GET['period'];
    if (isset($_GET['start_date'])) $params['start_date'] = $_GET['start_date'];
    if (isset($_GET['end_date'])) $params['end_date'] = $_GET['end_date'];
    
    $filters = ['drill_event', 'drill_tier', 'drill_size', 'drill_date', 'drill_material'];
    foreach ($filters as $f) {
        if ($removeFilter === $f) continue;
        if (isset($_GET[$f]) && !empty($_GET[$f])) {
            $params[$f] = $_GET[$f];
        }
    }
    
    foreach ($addFilter as $key => $value) {
        $params[$key] = $value;
    }
    
    return '?' . http_build_query($params);
}

function getFilterLabel($type, $value) {
    $tierLabels = [
        'early' => 'Early Bird', 'standard' => 'Standard', '3days' => '3 Days',
        '2days' => '2 Days', 'nextday' => 'Next Day', 'sameday' => 'Same Day'
    ];
    
    switch ($type) {
        case 'event': return $value;
        case 'tier': return $tierLabels[$value] ?? ucfirst($value);
        case 'size': return $value . '"';
        case 'date': return date('l, F j, Y', strtotime($value));
        case 'material': return ucfirst($value);
        default: return $value;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="reports-styles.css">
    <style>
        .filter-chips-bar {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .filter-chips-label {
            font-weight: 600;
            color: #6b7280;
            font-size: 0.9rem;
        }
        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .filter-chip-remove {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            line-height: 1;
            transition: background 0.2s;
            text-decoration: none;
        }
        .filter-chip-remove:hover {
            background: rgba(255,255,255,0.4);
        }
        .clear-all-btn {
            background: #f3f4f6;
            color: #6b7280;
            border: none;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .clear-all-btn:hover {
            background: #e5e7eb;
            color: #374151;
        }
        .filter-count {
            background: #f3f4f6;
            color: #6b7280;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 0.8rem;
            margin-left: auto;
        }
        .clickable-cell {
            cursor: pointer;
            transition: all 0.2s;
        }
        .clickable-cell:hover {
            background: #f5f3ff !important;
            color: #7c3aed;
        }
        .clickable-cell:hover strong {
            color: #7c3aed;
        }
        .orders-list-section {
            margin-top: 30px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .orders-list-header {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: white;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .orders-list-header h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        .orders-list-count {
            background: rgba(255,255,255,0.2);
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
        }
        .orders-table {
            width: 100%;
            border-collapse: collapse;
        }
        .orders-table th {
            background: #f9fafb;
            padding: 12px 16px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            border-bottom: 1px solid #e5e7eb;
        }
        .orders-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.9rem;
        }
        .orders-table tr:hover {
            background: #f9fafb;
        }
        .order-ref-link {
            color: #7c3aed;
            font-weight: 600;
            text-decoration: none;
        }
        .order-ref-link:hover {
            text-decoration: underline;
        }
        .status-badge-mini {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-paid { background: #dcfce7; color: #166534; }
        .status-refunded { background: #fee2e2; color: #991b1b; }
        .status-unpaid { background: #fef3c7; color: #92400e; }
        .status-preflight { background: #f5f3ff; color: #7c3aed; }
        .status-file_issue { background: #fff7ed; color: #c2410c; }
        .status-printing { background: #dbeafe; color: #1e40af; }
        .status-ready_to_ship { background: #ecfeff; color: #0891b2; }
        .status-shipped { background: #d1fae5; color: #065f46; }
        .status-delivered { background: #fef3c7; color: #92400e; }
        .status-pickedup { background: #dcfce7; color: #166534; }
        .status-unclaimed { background: #fdf2f8; color: #9d174d; }
        .status-missing { background: #fee2e2; color: #991b1b; }
        .status-cancelled { background: #f3f4f6; color: #4b5563; }
        
        /* Tier Badges - Color coded */
        .tier-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid;
        }
        .tier-early { background: #f0fdf4; color: #059669; border-color: #059669; }
        .tier-standard { background: #f0f9ff; color: #0284c7; border-color: #0284c7; }
        .tier-rush { background: #fefce8; color: #eab308; border-color: #eab308; }
        .tier-urgent { background: #fff7ed; color: #ea580c; border-color: #ea580c; }
        .tier-critical { background: #fef2f2; color: #dc2626; border-color: #dc2626; }
        .tier-lastminute { background: #faf5ff; color: #7c3aed; border-color: #7c3aed; }
        
        .no-orders-msg {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        .pagination-controls {
            padding: 16px 20px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .page-btn {
            padding: 8px 16px;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            text-decoration: none;
            color: #374151;
        }
        .page-btn:hover {
            background: #f3f4f6;
        }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('reports'); ?>
<script src="../js/admin-sidebar.js"></script>
    <div class="container">
        
        <!-- Page Header -->
        <div class="page-header" style="margin-bottom:0px!important;">
            <div class="page-header-left">
                <h1 class="page-title">Reports</h1>
                <div class="page-welcome">
                    <span class="welcome-text">Revenue Reporting</span>
                    <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <form class="filter-bar" method="GET" id="filterForm">
            <?php if ($drillEvent): ?><input type="hidden" name="drill_event" value="<?= htmlspecialchars($drillEvent) ?>"><?php endif; ?>
            <?php if ($drillTier): ?><input type="hidden" name="drill_tier" value="<?= htmlspecialchars($drillTier) ?>"><?php endif; ?>
            <?php if ($drillSize): ?><input type="hidden" name="drill_size" value="<?= htmlspecialchars($drillSize) ?>"><?php endif; ?>
            <?php if ($drillDate): ?><input type="hidden" name="drill_date" value="<?= htmlspecialchars($drillDate) ?>"><?php endif; ?>
            <?php if ($drillMaterial): ?><input type="hidden" name="drill_material" value="<?= htmlspecialchars($drillMaterial) ?>"><?php endif; ?>
            
            <div class="filter-group">
                <label>Period</label>
                <select name="period" id="periodSelect">
                    <option value="today" <?= $periodType === 'today' ? 'selected' : '' ?>>Today</option>
                    <option value="yesterday" <?= $periodType === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                    <option value="last_7_days" <?= $periodType === 'last_7_days' ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="last_30_days" <?= $periodType === 'last_30_days' ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="this_week" <?= $periodType === 'this_week' ? 'selected' : '' ?>>This Week</option>
                    <option value="last_week" <?= $periodType === 'last_week' ? 'selected' : '' ?>>Last Week</option>
                    <option value="this_month" <?= $periodType === 'this_month' ? 'selected' : '' ?>>This Month</option>
                    <option value="last_month" <?= $periodType === 'last_month' ? 'selected' : '' ?>>Last Month</option>
                    <option value="this_quarter" <?= $periodType === 'this_quarter' ? 'selected' : '' ?>>This Quarter</option>
                    <option value="last_quarter" <?= $periodType === 'last_quarter' ? 'selected' : '' ?>>Last Quarter</option>
                    <option value="this_year" <?= $periodType === 'this_year' ? 'selected' : '' ?>>This Year</option>
                    <option value="last_year" <?= $periodType === 'last_year' ? 'selected' : '' ?>>Last Year</option>
                    <option value="ytd" <?= $periodType === 'ytd' ? 'selected' : '' ?>>Year to Date</option>
                    <option value="custom" <?= $periodType === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label>Start Date</label>
                <input type="date" name="start_date" id="startDate" value="<?= htmlspecialchars($startDate) ?>">
            </div>
            
            <div class="filter-group">
                <label>End Date</label>
                <input type="date" name="end_date" id="endDate" value="<?= htmlspecialchars($endDate) ?>">
            </div>
            
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="index.php" class="btn btn-secondary">Reset All</a>
                <button type="button" class="btn btn-outline" onclick="window.print()">
                    ðŸ–¨ï¸ Print
                </button>
                <div class="export-dropdown">
                    <button type="button" class="btn btn-success" onclick="toggleExportMenu()">
                        ðŸ“¥ Export
                    </button>
                    <div class="export-menu" id="exportMenu">
                        <button type="button" class="export-menu-item" onclick="exportReport('csv')">ðŸ“„ Export as CSV</button>
                        <button type="button" class="export-menu-item" onclick="exportReport('xlsx')">ðŸ“Š Export as Excel</button>
                        <button type="button" class="export-menu-item" onclick="exportReport('pdf')">ðŸ“‘ Export as PDF</button>
                    </div>
                </div>
            </div>
        </form>

        <!-- Quick Period Buttons -->
        <div class="quick-periods">
            <span class="quick-periods-label">Quick:</span>
            <a href="?period=today" class="quick-period-btn <?= $periodType === 'today' ? 'active' : '' ?>">Today</a>
            <a href="?period=yesterday" class="quick-period-btn <?= $periodType === 'yesterday' ? 'active' : '' ?>">Yesterday</a>
            <a href="?period=last_7_days" class="quick-period-btn <?= $periodType === 'last_7_days' ? 'active' : '' ?>">Last 7 Days</a>
            <a href="?period=this_week" class="quick-period-btn <?= $periodType === 'this_week' ? 'active' : '' ?>">This Week</a>
            <a href="?period=this_month" class="quick-period-btn <?= $periodType === 'this_month' ? 'active' : '' ?>">This Month</a>
            <a href="?period=last_month" class="quick-period-btn <?= $periodType === 'last_month' ? 'active' : '' ?>">Last Month</a>
            <a href="?period=this_quarter" class="quick-period-btn <?= $periodType === 'this_quarter' ? 'active' : '' ?>">This Quarter</a>
            <a href="?period=ytd" class="quick-period-btn <?= $periodType === 'ytd' ? 'active' : '' ?>">YTD</a>
            <a href="?period=this_year" class="quick-period-btn <?= $periodType === 'this_year' ? 'active' : '' ?>">This Year</a>
        </div>

        <!-- Active Filter Chips -->
        <?php if ($hasActiveFilters): ?>
        <div class="filter-chips-bar">
            <span class="filter-chips-label">Active Filters:</span>
            <?php foreach ($activeFilters as $type => $value): ?>
            <span class="filter-chip">
                <?= getFilterLabel($type, $value) ?>
                <a href="<?= buildFilterUrl([], 'drill_' . $type) ?>" class="filter-chip-remove" title="Remove filter">Ã—</a>
            </span>
            <?php endforeach; ?>
            <a href="?period=<?= $periodType ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" class="clear-all-btn">Clear All Filters</a>
            <span class="filter-count"><?= count($filteredOrders) ?> orders matching</span>
        </div>
        <?php endif; ?>

        <!-- Period Info -->
        <div class="period-info">
            Showing data from <strong><?= date('l, F j, Y', strtotime($startDate)) ?></strong> to <strong><?= date('l, F j, Y', strtotime($endDate)) ?></strong>
            <?php if ($hasActiveFilters): ?>
                &nbsp;â€¢&nbsp; <strong><?= count($activeFilters) ?> filter<?= count($activeFilters) > 1 ? 's' : '' ?> active</strong>
            <?php endif; ?>
            &nbsp;â€¢&nbsp; Comparing to: <strong><?= date('l, F j', strtotime($comparisonDates['start'])) ?> - <?= date('l, F j, Y', strtotime($comparisonDates['end'])) ?></strong>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card highlight">
                <div class="stat-label">Gross Revenue</div>
                <div class="stat-number"><?= formatMoney($analytics['revenue']['gross_revenue']) ?></div>
                <?php $c = formatChange($comparison['change']['gross_revenue']); ?>
                <div class="stat-change <?= $c['class'] ?>"><?= $c['text'] ?> vs last period</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-label">Refunds</div>
                <div class="stat-number">-<?= formatMoney($analytics['revenue']['refunded_revenue']) ?></div>
                <div class="stat-change neutral"><?= $analytics['revenue']['refunded_count'] ?? 0 ?> orders</div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Net Revenue</div>
                <div class="stat-number"><?= formatMoney($analytics['revenue']['net_revenue']) ?></div>
                <?php $c = formatChange($comparison['change']['net_revenue']); ?>
                <div class="stat-change <?= $c['class'] ?>"><?= $c['text'] ?> vs last period</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Orders</div>
                <div class="stat-number"><?= number_format($analytics['revenue']['total_orders']) ?></div>
                <?php $c = formatChange($comparison['change']['total_orders']); ?>
                <div class="stat-change <?= $c['class'] ?>"><?= $c['text'] ?> vs last period</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">HST Collected</div>
                <div class="stat-number"><?= formatMoney($analytics['revenue']['hst_collected']) ?></div>
                <div class="stat-change neutral">13% on sales</div>
            </div>
            <div class="stat-card venue-fee">
                <div class="stat-label">Venue Fees</div>
                <div class="stat-number"><?= formatMoney($analytics['revenue']['total_venue_fee'] ?? 0) ?></div>
                <div class="stat-change neutral">MTCC portion</div>
            </div>
        </div>

        <!-- Outstanding Orders Info Bar -->
        <?php if ($unpaidCount > 0): ?>
        <div class="outstanding-bar">
            <div class="outstanding-icon">â³</div>
            <div class="outstanding-info">
                <strong>Outstanding:</strong> <?= $unpaidCount ?> unpaid order<?= $unpaidCount !== 1 ? 's' : '' ?> 
                (<?= formatMoney($unpaidTotal) ?>) not included in revenue totals
            </div>
            <a href="../admin-orders.php?status=unpaid" class="outstanding-link">View in Orders â†’</a>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('event')">By Event</button>
            <button class="tab-btn" onclick="switchTab('period')">By Period</button>
            <button class="tab-btn" onclick="switchTab('tier')">By Tier</button>
            <button class="tab-btn" onclick="switchTab('size')">By Size</button>
            <button class="tab-btn" onclick="switchTab('costs')">Vendor Costs</button>
        </div>

        <!-- Tab: By Event -->
        <div class="tab-content active" id="tab-event">
            <h3 class="section-title">Revenue by Event <?php if ($drillEvent): ?><small style="color: #7c3aed;">(Filtered: <?= $drillEvent ?>)</small><?php endif; ?></h3>
            <?php $eventAnalytics = AnalyticsCalculator::getEventAnalytics($filteredOrders); ?>
            <?php if (empty($eventAnalytics)): ?>
            <div class="empty-state"><div class="empty-state-icon">ðŸ“Š</div><p>No event data available for this period</p></div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Base</th>
                            <th class="text-right">Delivery</th>
                            <th class="text-right">File Fees</th>
                            <th class="text-right">HST</th>
                            <th class="text-right col-gross">Gross</th>
                            <th class="text-right col-refund">Refunds</th>
                            <th class="text-right col-net">Net</th>
                            <th class="text-right col-venue-fee">Venue Fee</th>
                            <th>Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalGross = array_sum(array_column($eventAnalytics, 'gross_revenue'));
                        foreach ($eventAnalytics as $event): 
                            $share = $totalGross > 0 ? ($event['gross_revenue'] / $totalGross) * 100 : 0;
                            $isCurrentFilter = ($drillEvent === $event['event']);
                        ?>
                        <tr>
                            <td class="clickable-cell" onclick="addFilter('drill_event', '<?= htmlspecialchars($event['event']) ?>')" <?= $isCurrentFilter ? 'style="background: #f5f3ff;"' : '' ?>>
                                <strong><?= htmlspecialchars($event['event']) ?></strong><?= $isCurrentFilter ? ' âœ“' : '' ?>
                            </td>
                            <td class="text-right"><?= number_format($event['order_count']) ?></td>
                            <td class="text-right"><?= formatMoney($event['base_revenue']) ?></td>
                            <td class="text-right"><?= formatMoney($event['delivery_fees']) ?></td>
                            <td class="text-right"><?= formatMoney($event['conversion_fees']) ?></td>
                            <td class="text-right"><?= formatMoney($event['hst_collected']) ?></td>
                            <td class="text-right col-gross"><?= formatMoney($event['gross_revenue']) ?></td>
                            <td class="text-right col-refund"><?= $event['refunded_amount'] > 0 ? '-' . formatMoney($event['refunded_amount']) : '-' ?></td>
                            <td class="text-right col-net"><?= formatMoney($event['net_revenue']) ?></td>
                            <td class="text-right col-venue-fee"><?= formatMoney($event['venue_fee']) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="min-width: 40px; font-size: 0.85rem;"><?= number_format($share, 1) ?>%</span>
                                    <div class="progress-bar" style="flex: 1; max-width: 80px;"><div class="progress-fill" style="width: <?= $share ?>%;"></div></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><?= number_format(array_sum(array_column($eventAnalytics, 'order_count'))) ?></td>
                            <td class="text-right"><?= formatMoney(array_sum(array_column($eventAnalytics, 'base_revenue'))) ?></td>
                            <td class="text-right"><?= formatMoney(array_sum(array_column($eventAnalytics, 'delivery_fees'))) ?></td>
                            <td class="text-right"><?= formatMoney(array_sum(array_column($eventAnalytics, 'conversion_fees'))) ?></td>
                            <td class="text-right"><?= formatMoney(array_sum(array_column($eventAnalytics, 'hst_collected'))) ?></td>
                            <td class="text-right col-gross"><strong><?= formatMoney($totalGross) ?></strong></td>
                            <td class="text-right col-refund">-<?= formatMoney(array_sum(array_column($eventAnalytics, 'refunded_amount'))) ?></td>
                            <td class="text-right col-net"><strong><?= formatMoney(array_sum(array_column($eventAnalytics, 'net_revenue'))) ?></strong></td>
                            <td class="text-right col-venue-fee"><?= formatMoney(array_sum(array_column($eventAnalytics, 'venue_fee'))) ?></td>
                            <td>100%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab: By Period -->
        <div class="tab-content" id="tab-period">
            <h3 class="section-title">Revenue by Time Period <?php if ($drillDate): ?><small style="color: #7c3aed;">(Filtered: <?= date('l, F j, Y', strtotime($drillDate)) ?>)</small><?php endif; ?></h3>
            <?php
            $dailyData = [];
            foreach ($filteredOrders as $order) {
                if (in_array($order['status'] ?? '', ['cancelled'])) continue;
                $paidAt = $order['paidAt'] ?? $order['submittedAt'] ?? null;
                if (!$paidAt) continue;
                $day = date('Y-m-d', strtotime($paidAt));
                if (!isset($dailyData[$day])) $dailyData[$day] = ['orders' => 0, 'revenue' => 0, 'refunds' => 0];
                $dailyData[$day]['orders']++;
                if (($order['status'] ?? '') === 'refunded') {
                    $dailyData[$day]['refunds'] += (float)($order['refund']['refundAmount'] ?? $order['pricing']['total'] ?? 0);
                } else {
                    $dailyData[$day]['revenue'] += (float)($order['pricing']['total'] ?? 0);
                }
            }
            ksort($dailyData);
            ?>
            <?php if (empty($dailyData)): ?>
            <div class="empty-state"><div class="empty-state-icon">ðŸ“…</div><p>No data available for this period</p></div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead><tr><th>Date</th><th class="text-right">Orders</th><th class="text-right col-gross">Revenue</th><th class="text-right col-refund">Refunds</th><th class="text-right col-net">Net</th></tr></thead>
                    <tbody>
                        <?php 
                        $totalOrders = 0; $totalRevenue = 0; $totalRefunds = 0;
                        foreach ($dailyData as $day => $data): 
                            $totalOrders += $data['orders']; $totalRevenue += $data['revenue']; $totalRefunds += $data['refunds'];
                            $isCurrentFilter = ($drillDate === $day);
                        ?>
                        <tr>
                            <td class="clickable-cell" onclick="addFilter('drill_date', '<?= $day ?>')" <?= $isCurrentFilter ? 'style="background: #f5f3ff;"' : '' ?>>
                                <strong><?= date('l, F j, Y', strtotime($day)) ?></strong><?= $isCurrentFilter ? ' âœ“' : '' ?>
                            </td>
                            <td class="text-right"><?= $data['orders'] ?></td>
                            <td class="text-right col-gross"><?= formatMoney($data['revenue']) ?></td>
                            <td class="text-right col-refund"><?= $data['refunds'] > 0 ? '-' . formatMoney($data['refunds']) : '-' ?></td>
                            <td class="text-right col-net"><?= formatMoney($data['revenue'] - $data['refunds']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><?= $totalOrders ?></td>
                            <td class="text-right col-gross"><strong><?= formatMoney($totalRevenue) ?></strong></td>
                            <td class="text-right col-refund"><?= $totalRefunds > 0 ? '-' . formatMoney($totalRefunds) : '-' ?></td>
                            <td class="text-right col-net"><strong><?= formatMoney($totalRevenue - $totalRefunds) ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab: By Tier -->
        <div class="tab-content" id="tab-tier">
            <h3 class="section-title">Revenue by Turnaround Tier <?php if ($drillTier): ?><small style="color: #7c3aed;">(Filtered: <?= getFilterLabel('tier', $drillTier) ?>)</small><?php endif; ?></h3>
            <?php $tierBreakdown = $analytics['turnaround_breakdown']; $tierTotal = array_sum(array_column($tierBreakdown, 'count')); ?>
            <?php if ($tierTotal === 0): ?>
            <div class="empty-state"><div class="empty-state-icon">â±ï¸</div><p>No tier data available for this period</p></div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead><tr><th>Turnaround Tier</th><th class="text-right">Orders</th><th class="text-right">Revenue</th><th>Share</th></tr></thead>
                    <tbody>
                        <?php 
                        $totalTierRevenue = array_sum(array_column($tierBreakdown, 'revenue'));
                        // Map tier keys to badge classes
                        $tierBadgeMap = [
                            'early' => 'tier-early',
                            'standard' => 'tier-standard', 
                            '3days' => 'tier-rush',
                            '2days' => 'tier-urgent',
                            'nextday' => 'tier-critical',
                            'sameday' => 'tier-lastminute'
                        ];
                        foreach ($tierBreakdown as $key => $tier): 
                            if ($tier['count'] === 0) continue;
                            $share = $tierTotal > 0 ? ($tier['count'] / $tierTotal) * 100 : 0;
                            $isCurrentFilter = ($drillTier === $key);
                            $badgeClass = $tierBadgeMap[$key] ?? 'tier-standard';
                        ?>
                        <tr>
                            <td class="clickable-cell" onclick="addFilter('drill_tier', '<?= $key ?>')" <?= $isCurrentFilter ? 'style="background: #f5f3ff;"' : '' ?>>
                                <span class="tier-badge <?= $badgeClass ?>"><?= htmlspecialchars($tier['label']) ?></span><?= $isCurrentFilter ? ' âœ“' : '' ?>
                            </td>
                            <td class="text-right"><?= number_format($tier['count']) ?></td>
                            <td class="text-right"><?= formatMoney($tier['revenue']) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="min-width: 40px; font-size: 0.85rem;"><?= number_format($share, 1) ?>%</span>
                                    <div class="progress-bar" style="flex: 1; max-width: 80px;"><div class="progress-fill" style="width: <?= $share ?>%;"></div></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL</strong></td>
                            <td class="text-right"><?= number_format($tierTotal) ?></td>
                            <td class="text-right"><?= formatMoney($totalTierRevenue) ?></td>
                            <td>100%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab: By Size -->
        <div class="tab-content" id="tab-size">
            <h3 class="section-title">Revenue by Poster Size <?php if ($drillSize): ?><small style="color: #7c3aed;">(Filtered: <?= $drillSize ?>")</small><?php endif; ?></h3>
            <?php $sizeBreakdown = $analytics['size_breakdown']; ?>
            <?php if (empty($sizeBreakdown)): ?>
            <div class="empty-state"><div class="empty-state-icon">ðŸ“</div><p>No size data available for this period</p></div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead><tr><th>Size (inches)</th><th class="text-right">Orders</th><th class="text-right">Revenue</th><th>Share</th></tr></thead>
                    <tbody>
                        <?php 
                        $totalSizeOrders = array_sum(array_column($sizeBreakdown, 'count'));
                        $totalSizeRevenue = array_sum(array_column($sizeBreakdown, 'revenue'));
                        foreach ($sizeBreakdown as $size): 
                            $share = $totalSizeOrders > 0 ? ($size['count'] / $totalSizeOrders) * 100 : 0;
                            $sizeKey = intval($size['width']) . 'x' . intval($size['height']);
                            $isCurrentFilter = ($drillSize === $sizeKey);
                        ?>
                        <tr>
                            <td class="clickable-cell" onclick="addFilter('drill_size', '<?= htmlspecialchars($sizeKey) ?>')" <?= $isCurrentFilter ? 'style="background: #f5f3ff;"' : '' ?>>
                                <strong><?= htmlspecialchars($size['size']) ?>"</strong><?= $isCurrentFilter ? ' âœ“' : '' ?>
                            </td>
                            <td class="text-right"><?= number_format($size['count']) ?></td>
                            <td class="text-right"><?= formatMoney($size['revenue']) ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="min-width: 40px; font-size: 0.85rem;"><?= number_format($share, 1) ?>%</span>
                                    <div class="progress-bar" style="flex: 1; max-width: 80px;"><div class="progress-fill" style="width: <?= $share ?>%;"></div></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td><strong>TOTAL (Top <?= count($sizeBreakdown) ?>)</strong></td>
                            <td class="text-right"><?= number_format($totalSizeOrders) ?></td>
                            <td class="text-right"><?= formatMoney($totalSizeRevenue) ?></td>
                            <td>100%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- Material & Delivery -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px;">
                <div>
                    <h4 class="section-title">By Material</h4>
                    <?php $materialBreakdown = $analytics['material_breakdown']; ?>
                    <table class="data-table">
                        <thead><tr><th>Material</th><th class="text-right">Orders</th><th class="text-right">Revenue</th><th class="text-right">Share</th></tr></thead>
                        <tbody>
                            <tr>
                                <td class="clickable-cell" onclick="addFilter('drill_material', 'poster')" <?= $drillMaterial === 'poster' ? 'style="background: #f5f3ff;"' : '' ?>>
                                    <strong>Poster Paper</strong><?= $drillMaterial === 'poster' ? ' âœ“' : '' ?>
                                </td>
                                <td class="text-right"><?= $materialBreakdown['poster']['count'] ?></td>
                                <td class="text-right"><?= formatMoney($materialBreakdown['poster']['revenue']) ?></td>
                                <td class="text-right"><?= $materialBreakdown['poster']['percentage'] ?>%</td>
                            </tr>
                            <tr>
                                <td class="clickable-cell" onclick="addFilter('drill_material', 'fabric')" <?= $drillMaterial === 'fabric' ? 'style="background: #f5f3ff;"' : '' ?>>
                                    <strong>Fabric</strong><?= $drillMaterial === 'fabric' ? ' âœ“' : '' ?>
                                </td>
                                <td class="text-right"><?= $materialBreakdown['fabric']['count'] ?></td>
                                <td class="text-right"><?= formatMoney($materialBreakdown['fabric']['revenue']) ?></td>
                                <td class="text-right"><?= $materialBreakdown['fabric']['percentage'] ?>%</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h4 class="section-title">By Delivery Method</h4>
                    <?php $deliveryBreakdown = $analytics['delivery_breakdown']; ?>
                    <table class="data-table">
                        <thead><tr><th>Delivery</th><th class="text-right">Orders</th><th class="text-right">Revenue</th><th class="text-right">Share</th></tr></thead>
                        <tbody>
                            <tr><td>MTCC Delivery</td><td class="text-right"><?= $deliveryBreakdown['mtcc']['count'] ?></td><td class="text-right"><?= formatMoney($deliveryBreakdown['mtcc']['revenue']) ?></td><td class="text-right"><?= $deliveryBreakdown['mtcc']['percentage'] ?>%</td></tr>
                            <tr><td>Pickup</td><td class="text-right"><?= $deliveryBreakdown['pickup']['count'] ?></td><td class="text-right"><?= formatMoney($deliveryBreakdown['pickup']['revenue']) ?></td><td class="text-right"><?= $deliveryBreakdown['pickup']['percentage'] ?>%</td></tr>
                            <tr><td>Shipping</td><td class="text-right"><?= $deliveryBreakdown['shipping']['count'] ?></td><td class="text-right"><?= formatMoney($deliveryBreakdown['shipping']['revenue']) ?></td><td class="text-right"><?= $deliveryBreakdown['shipping']['percentage'] ?>%</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Vendor Costs -->
        <div class="tab-content" id="tab-costs">
            <h3 class="section-title">Vendor Costs (COGS)</h3>

            <!-- COGS Summary Cards -->
            <div class="costs-summary">
                <div class="cost-card">
                    <div class="cost-card-label">Revenue</div>
                    <div class="cost-card-value"><?= formatMoney($totalRevenue) ?></div>
                </div>
                <div class="cost-card cost-card-cogs">
                    <div class="cost-card-label">Total COGS</div>
                    <div class="cost-card-value"><?= formatMoney($totalCogs) ?></div>
                </div>
                <div class="cost-card <?= $totalProfit >= 0 ? 'cost-card-profit' : 'cost-card-loss' ?>">
                    <div class="cost-card-label">Gross Profit</div>
                    <div class="cost-card-value"><?= formatMoney($totalProfit) ?></div>
                    <?php if ($totalRevenue > 0): ?>
                    <div class="cost-card-sub"><?= round(($totalProfit / $totalRevenue) * 100) ?>% margin</div>
                    <?php endif; ?>
                </div>
                <?php if ($totalPendingCount > 0): ?>
                <div class="cost-card cost-card-pending">
                    <div class="cost-card-label">Pending Review</div>
                    <div class="cost-card-value"><?= $totalPendingCount ?></div>
                    <div class="cost-card-sub">orders awaiting pricing</div>
                </div>
                <?php endif; ?>
            </div>

            <!-- By Vendor Table -->
            <h4 class="section-title" style="margin-top: 24px;">By Vendor</h4>
            <?php if (empty($vendorCosts)): ?>
            <div class="empty-state"><p>No vendor cost data for this period.</p></div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Vendor</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">COGS</th>
                            <th class="text-right">Profit</th>
                            <th class="text-right">Margin</th>
                            <th class="text-right">Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($vendorCosts as $vendor => $vc): 
                            $profit = $vc['revenue'] - $vc['cogs'];
                            $margin = $vc['revenue'] > 0 ? round(($profit / $vc['revenue']) * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($vendor) ?></strong></td>
                            <td class="text-right"><?= $vc['orders'] ?></td>
                            <td class="text-right"><?= formatMoney($vc['revenue']) ?></td>
                            <td class="text-right"><?= $vc['cogs'] > 0 ? formatMoney($vc['cogs']) : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td class="text-right <?= $profit >= 0 ? 'text-profit' : 'text-loss' ?>"><?= $vc['cogs'] > 0 ? formatMoney($profit) : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td class="text-right <?= $profit >= 0 ? 'text-profit' : 'text-loss' ?>"><?= $vc['cogs'] > 0 ? $margin . '%' : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td class="text-right"><?= $vc['pending'] > 0 ? '<span class="pending-badge">' . $vc['pending'] . '</span>' : '0' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td><strong>Total</strong></td>
                            <td class="text-right"><strong><?= array_sum(array_column($vendorCosts, 'orders')) ?></strong></td>
                            <td class="text-right"><strong><?= formatMoney($totalRevenue) ?></strong></td>
                            <td class="text-right"><strong><?= formatMoney($totalCogs) ?></strong></td>
                            <td class="text-right <?= $totalProfit >= 0 ? 'text-profit' : 'text-loss' ?>"><strong><?= formatMoney($totalProfit) ?></strong></td>
                            <td class="text-right"><strong><?= $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100) : 0 ?>%</strong></td>
                            <td class="text-right"><strong><?= $totalPendingCount ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- COGS Breakdown (for vendors with accepted pricing) -->
            <?php 
            $hasBreakdown = false;
            foreach ($vendorCosts as $vc) { if ($vc['cogs_base'] > 0) { $hasBreakdown = true; break; } }
            if ($hasBreakdown): 
                $totalBase = array_sum(array_column($vendorCosts, 'cogs_base'));
                $totalPacking = array_sum(array_column($vendorCosts, 'cogs_packing'));
                $totalTax = array_sum(array_column($vendorCosts, 'cogs_tax'));
            ?>
            <div class="cogs-breakdown">
                <h4 class="section-title">COGS Breakdown</h4>
                <div class="cogs-breakdown-grid">
                    <div class="cogs-breakdown-item">
                        <span class="cogs-breakdown-label">Base Print Cost</span>
                        <span class="cogs-breakdown-value"><?= formatMoney($totalBase) ?></span>
                    </div>
                    <div class="cogs-breakdown-item">
                        <span class="cogs-breakdown-label">Packing Cost</span>
                        <span class="cogs-breakdown-value"><?= formatMoney($totalPacking) ?></span>
                    </div>
                    <div class="cogs-breakdown-item">
                        <span class="cogs-breakdown-label">Vendor Tax (HST 13%)</span>
                        <span class="cogs-breakdown-value"><?= formatMoney($totalTax) ?></span>
                    </div>
                    <div class="cogs-breakdown-item cogs-breakdown-total">
                        <span class="cogs-breakdown-label">Total COGS</span>
                        <span class="cogs-breakdown-value"><?= formatMoney($totalCogs) ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php endif; ?>

            <!-- By Event Table -->
            <h4 class="section-title" style="margin-top: 24px;">By Event</h4>
            <?php if (empty($eventCosts)): ?>
            <div class="empty-state"><p>No event cost data for this period.</p></div>
            <?php else: ?>
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Event</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">COGS</th>
                            <th class="text-right">Profit</th>
                            <th class="text-right">Margin</th>
                            <th class="text-right">Pending</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($eventCosts as $event => $ec): 
                            $profit = $ec['revenue'] - $ec['cogs'];
                            $margin = $ec['revenue'] > 0 ? round(($profit / $ec['revenue']) * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($event) ?></strong></td>
                            <td class="text-right"><?= $ec['orders'] ?></td>
                            <td class="text-right"><?= formatMoney($ec['revenue']) ?></td>
                            <td class="text-right"><?= $ec['cogs'] > 0 ? formatMoney($ec['cogs']) : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td class="text-right <?= $profit >= 0 ? 'text-profit' : 'text-loss' ?>"><?= $ec['cogs'] > 0 ? formatMoney($profit) : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td class="text-right <?= $profit >= 0 ? 'text-profit' : 'text-loss' ?>"><?= $ec['cogs'] > 0 ? $margin . '%' : '<span class="text-muted">&mdash;</span>' ?></td>
                            <td class="text-right"><?= $ec['pending'] > 0 ? '<span class="pending-badge">' . $ec['pending'] . '</span>' : '0' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="totals-row">
                            <td><strong>Total</strong></td>
                            <td class="text-right"><strong><?= array_sum(array_column($eventCosts, 'orders')) ?></strong></td>
                            <td class="text-right"><strong><?= formatMoney($totalRevenue) ?></strong></td>
                            <td class="text-right"><strong><?= formatMoney($totalCogs) ?></strong></td>
                            <td class="text-right <?= $totalProfit >= 0 ? 'text-profit' : 'text-loss' ?>"><strong><?= formatMoney($totalProfit) ?></strong></td>
                            <td class="text-right"><strong><?= $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100) : 0 ?>%</strong></td>
                            <td class="text-right"><strong><?= $totalPendingCount ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Orders List (when filters active) -->
        <?php if ($hasActiveFilters): ?>
        <div class="orders-list-section">
            <div class="orders-list-header">
                <h3>ðŸ“‹ Filtered Orders</h3>
                <span class="orders-list-count"><?= count($filteredOrders) ?> orders</span>
            </div>
            <?php if (empty($filteredOrders)): ?>
            <div class="no-orders-msg"><p>No orders match the current filters.</p></div>
            <?php else: ?>
            <table class="orders-table">
                <thead><tr><th>Reference</th><th>Date</th><th>Customer</th><th>Event</th><th>Size</th><th>Tier</th><th>Total</th><th>Status</th></tr></thead>
                <tbody>
                    <?php 
                    $displayOrders = array_slice($filteredOrders, 0, 50);
                    foreach ($displayOrders as $order): 
                        $ref = $order['referenceCode'] ?? 'N/A';
                        $date = $order['paidAt'] ?? $order['submittedAt'] ?? '';
                        $customer = $order['customerInfo']['name'] ?? 'N/A';
                        $event = AnalyticsCalculator::getEventPrefix($ref);
                        $size = ($order['dimensions']['width'] ?? 0) . 'x' . ($order['dimensions']['height'] ?? 0);
                        $tierRaw = $order['pricing']['tier'] ?? 'Standard';
                        $tierBadgeClass = getTierBadgeClass($tierRaw);
                        $tierLabel = getCleanTierLabel($tierRaw);
                        $total = $order['pricing']['total'] ?? 0;
                        $status = $order['status'] ?? 'unpaid';
                    ?>
                    <tr>
                        <td><a href="../admin-orders.php?view=<?= urlencode($ref) ?>" class="order-ref-link"><?= htmlspecialchars($ref) ?></a></td>
                        <td><?= $date ? date('l, F j, Y g:ia', strtotime($date)) : '-' ?></td>
                        <td><?= htmlspecialchars($customer) ?></td>
                        <td><?= htmlspecialchars($event) ?></td>
                        <td><?= $size ?>"</td>
                        <td><span class="tier-badge <?= $tierBadgeClass ?>"><?= htmlspecialchars($tierLabel) ?></span></td>
                        <td><?= formatMoney($total) ?></td>
                        <td><span class="status-badge-mini status-<?= $status ?>"><?= ucfirst($status) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (count($filteredOrders) > 50): ?>
            <div class="pagination-controls">
                <span>Showing 50 of <?= count($filteredOrders) ?> orders</span>
                <a href="../admin-orders.php" class="page-btn">View All in Orders â†’</a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- HST Summary -->
        <div class="hst-summary">
            <div class="hst-summary-title">Tax Summary (HST 13%)</div>
            <div class="hst-row"><span class="label">Gross Revenue (incl. HST)</span><span class="value col-gross"><?= formatMoney($analytics['revenue']['gross_revenue']) ?></span></div>
            <div class="hst-row"><span class="label">HST Collected</span><span class="value"><?= formatMoney($analytics['revenue']['hst_collected']) ?></span></div>
            <div class="hst-row subtotal"><span class="label">Pre-Tax Revenue</span><span class="value"><?= formatMoney($analytics['revenue']['gross_revenue'] - $analytics['revenue']['hst_collected']) ?></span></div>
            <div class="hst-row"><span class="label">Less: Refunds</span><span class="value col-refund">-<?= formatMoney($analytics['revenue']['refunded_revenue']) ?></span></div>
            <div class="hst-row total"><span class="label">Net Taxable Revenue</span><span class="value col-net"><?= formatMoney($analytics['revenue']['net_revenue']) ?></span></div>
        </div>

    </div>


<script>
function switchTab(tabName) {
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + tabName).classList.add('active');
    history.replaceState(null, null, '#' + tabName);
}

function addFilter(filterName, filterValue) {
    const url = new URL(window.location.href);
    url.searchParams.set(filterName, filterValue);
    window.location.href = url.toString();
}

function toggleExportMenu() {
    document.getElementById('exportMenu').classList.toggle('show');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.export-dropdown')) {
        document.getElementById('exportMenu').classList.remove('show');
    }
});

function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    const startDate = params.get('start_date') || '<?= $startDate ?>';
    const endDate = params.get('end_date') || '<?= $endDate ?>';
    let parts = ['MTCC-Revenue'];
    <?php if ($drillEvent): ?>parts.push('<?= $drillEvent ?>');<?php endif; ?>
    <?php if ($drillTier): ?>parts.push('<?= $drillTier ?>');<?php endif; ?>
    <?php if ($drillSize): ?>parts.push('<?= $drillSize ?>');<?php endif; ?>
    const start = new Date(startDate);
    const end = new Date(endDate);
    let periodPart = start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear()
        ? start.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }).replace(' ', '')
        : start.toLocaleDateString('en-US', { month: 'short' }).replace(' ', '') + '-' + end.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }).replace(' ', '');
    parts.push(periodPart);
    params.set('filename', parts.join('-'));
    window.location.href = 'export.php?' + params.toString();
    document.getElementById('exportMenu').classList.remove('show');
}

document.addEventListener('DOMContentLoaded', function() {
    const hash = window.location.hash.replace('#', '');
    if (hash && document.getElementById('tab-' + hash)) {
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.querySelector(`[onclick="switchTab('${hash}')"]`).classList.add('active');
        document.getElementById('tab-' + hash).classList.add('active');
    }
});
</script>
</body>
</html>
