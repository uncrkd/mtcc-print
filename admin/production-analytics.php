<?php
/**
 * Production Analytics - MTCC Print Services
 * Vendor performance tracking and workflow insights
 * 
 * Location: /admin/production-analytics.php
 * Uses same design system as admin-orders.php (dashboard).
 */

// Include from parent directory
require_once '../includes/icons.php';
require_once '../admin-auth.php';
require_once '../includes/timing-calculations.php';

// Require analytics or reports permission
requireAnyPermission(['dashboard_analytics', 'reports']);

// ============================================
// DATA PATHS (relative to parent directory)
// ============================================
$vendorsFile = '../data/vendors.json';
$preflightLogFile = '../data/preflight-log.json';
$reminderLogFile = '../data/reminder-log.json';
$tokensFile = '../data/vendor-tokens.json';
$statusesFile = '../data/statuses.json';
$ordersDir = '../uploads/orders/';

// ============================================
// DATE RANGE FILTER
// ============================================
$rangePreset = $_GET['range'] ?? '30days';
$eventFilter = $_GET['event'] ?? null;
$customStart = $_GET['start'] ?? null;
$customEnd = $_GET['end'] ?? null;

$now = new DateTime();
switch ($rangePreset) {
    case '7days':
        $startDate = (clone $now)->modify('-7 days');
        $endDate = $now;
        break;
    case '30days':
        $startDate = (clone $now)->modify('-30 days');
        $endDate = $now;
        break;
    case '90days':
        $startDate = (clone $now)->modify('-90 days');
        $endDate = $now;
        break;
    case 'thismonth':
        $startDate = new DateTime('first day of this month');
        $endDate = $now;
        break;
    case 'lastmonth':
        $startDate = new DateTime('first day of last month');
        $endDate = new DateTime('last day of last month');
        break;
    case 'custom':
        $startDate = $customStart ? new DateTime($customStart) : (clone $now)->modify('-30 days');
        $endDate = $customEnd ? new DateTime($customEnd) : $now;
        break;
    default:
        $startDate = (clone $now)->modify('-30 days');
        $endDate = $now;
}

$startTimestamp = $startDate->getTimestamp();
$endTimestamp = (clone $endDate)->setTime(23, 59, 59)->getTimestamp();

// ============================================
// LOAD DATA
// ============================================
function loadJsonFile($file) {
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

$vendors = loadJsonFile($vendorsFile) ?: ['vendors' => []];
$preflightLog = loadJsonFile($preflightLogFile) ?: ['entries' => []];
$reminderLog = loadJsonFile($reminderLogFile) ?: ['reminders' => []];
$tokens = loadJsonFile($tokensFile) ?: ['tokens' => []];
$statuses = loadJsonFile($statusesFile) ?: [];

// ============================================
// CALCULATE ANALYTICS
// ============================================

// Filter entries by date range
$filteredEntries = [];
foreach ($preflightLog['entries'] ?? [] as $refCode => $entry) {
    $pushedAt = strtotime($entry['pushed_at'] ?? '');
    if ($pushedAt >= $startTimestamp && $pushedAt <= $endTimestamp) {
        $entry['reference_code'] = $refCode;
        $entry['current_status'] = $statuses[$refCode] ?? 'unknown';
        $filteredEntries[$refCode] = $entry;
    }
}

// Overall Stats
$stats = [
    'total_pushed' => count($filteredEntries),
    'confirmed' => 0,
    'pending' => 0,
    'file_issues' => 0,
    'total_confirmation_time' => 0,
    'confirmed_with_time' => 0,
    'fastest_confirmation' => null,
    'slowest_confirmation' => null,
    'avg_confirmation_hours' => 0,
    'confirmed_within_1h' => 0,
    'confirmed_within_2h' => 0,
    'confirmed_within_4h' => 0,
    'confirmed_after_4h' => 0,
    'total_reminders' => 0,
    'orders_with_reminders' => 0
];

$vendorStats = [];
$dailyStats = [];
$hourlyDistribution = array_fill(0, 24, 0);

foreach ($filteredEntries as $refCode => $entry) {
    $vendorId = $entry['vendor_id'] ?? 'unknown';
    $pushedAt = strtotime($entry['pushed_at']);
    $confirmedAt = !empty($entry['confirmed_at']) ? strtotime($entry['confirmed_at']) : null;
    
    // Initialize vendor stats
    if (!isset($vendorStats[$vendorId])) {
        $vendorData = null;
        foreach ($vendors['vendors'] as $v) {
            if ($v['id'] === $vendorId) {
                $vendorData = $v;
                break;
            }
        }
        $vendorStats[$vendorId] = [
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorData['business_name'] ?? 'Unknown Vendor',
            'total_orders' => 0,
            'confirmed' => 0,
            'pending' => 0,
            'file_issues' => 0,
            'total_confirmation_time' => 0,
            'avg_confirmation_hours' => 0,
            'reminders_sent' => 0
        ];
    }
    
    $vendorStats[$vendorId]['total_orders']++;
    
    // Track confirmation status
    if ($confirmedAt) {
        $stats['confirmed']++;
        $vendorStats[$vendorId]['confirmed']++;
        
        $confirmationTime = $confirmedAt - $pushedAt;
        $confirmationHours = $confirmationTime / 3600;
        
        $stats['total_confirmation_time'] += $confirmationTime;
        $stats['confirmed_with_time']++;
        $vendorStats[$vendorId]['total_confirmation_time'] += $confirmationTime;
        
        if ($stats['fastest_confirmation'] === null || $confirmationTime < $stats['fastest_confirmation']) {
            $stats['fastest_confirmation'] = $confirmationTime;
        }
        if ($stats['slowest_confirmation'] === null || $confirmationTime > $stats['slowest_confirmation']) {
            $stats['slowest_confirmation'] = $confirmationTime;
        }
        
        if ($confirmationHours <= 1) {
            $stats['confirmed_within_1h']++;
        } elseif ($confirmationHours <= 2) {
            $stats['confirmed_within_2h']++;
        } elseif ($confirmationHours <= 4) {
            $stats['confirmed_within_4h']++;
        } else {
            $stats['confirmed_after_4h']++;
        }
    } else {
        $currentStatus = $entry['current_status'] ?? '';
        if ($currentStatus === 'file_issue') {
            $stats['file_issues']++;
            $vendorStats[$vendorId]['file_issues']++;
        } else {
            $stats['pending']++;
            $vendorStats[$vendorId]['pending']++;
        }
    }
    
    // Daily stats
    $day = date('Y-m-d', $pushedAt);
    if (!isset($dailyStats[$day])) {
        $dailyStats[$day] = ['pushed' => 0, 'confirmed' => 0];
    }
    $dailyStats[$day]['pushed']++;
    if ($confirmedAt) {
        $confirmDay = date('Y-m-d', $confirmedAt);
        if (!isset($dailyStats[$confirmDay])) {
            $dailyStats[$confirmDay] = ['pushed' => 0, 'confirmed' => 0];
        }
        $dailyStats[$confirmDay]['confirmed']++;
    }
    
    // Hourly distribution
    $hour = (int)date('G', $pushedAt);
    $hourlyDistribution[$hour]++;
}

// Count reminders
foreach ($reminderLog['reminders'] ?? [] as $refCode => $reminders) {
    if (isset($filteredEntries[$refCode])) {
        $count = is_array($reminders) ? count($reminders) : 0;
        $stats['total_reminders'] += $count;
        $stats['orders_with_reminders']++;
        
        $vendorId = $filteredEntries[$refCode]['vendor_id'] ?? 'unknown';
        if (isset($vendorStats[$vendorId])) {
            $vendorStats[$vendorId]['reminders_sent'] += $count;
        }
    }
}

// Calculate averages
if ($stats['confirmed_with_time'] > 0) {
    $stats['avg_confirmation_hours'] = round(($stats['total_confirmation_time'] / $stats['confirmed_with_time']) / 3600, 1);
}

foreach ($vendorStats as $vendorId => &$vs) {
    if ($vs['confirmed'] > 0) {
        $vs['avg_confirmation_hours'] = round(($vs['total_confirmation_time'] / $vs['confirmed']) / 3600, 1);
    }
    $vs['confirmation_rate'] = $vs['total_orders'] > 0 
        ? round(($vs['confirmed'] / $vs['total_orders']) * 100) 
        : 0;
}
unset($vs);

uasort($vendorStats, fn($a, $b) => $b['total_orders'] <=> $a['total_orders']);
ksort($dailyStats);

$stats['confirmation_rate'] = $stats['total_pushed'] > 0 
    ? round(($stats['confirmed'] / $stats['total_pushed']) * 100) 
    : 0;

function formatDuration($seconds) {
    if ($seconds === null) return '—';
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    if ($hours > 24) {
        $days = floor($hours / 24);
        $hours = $hours % 24;
        return "{$days}d {$hours}h";
    }
    return "{$hours}h {$minutes}m";
}

$stats['fastest_display'] = formatDuration($stats['fastest_confirmation']);
$stats['slowest_display'] = formatDuration($stats['slowest_confirmation']);

// Prepare chart data
$chartLabels = array_keys($dailyStats);
$chartPushed = array_column($dailyStats, 'pushed');
$chartConfirmed = array_column($dailyStats, 'confirmed');

// ============================================
// TIMING METRICS (via TimingCalculator)
// ============================================
$timingData = TimingCalculator::loadAllTimings($startDate, $endDate, $eventFilter);
$allTimings = $timingData['timings'];
$vendorMapTiming = $timingData['vendor_map'];

$avgConfirmation = TimingCalculator::getAverages($allTimings, 'confirmation_time');
$avgProduction   = TimingCalculator::getAverages($allTimings, 'production_time');
$avgTotal        = TimingCalculator::getAverages($allTimings, 'preflight_to_ready');
$vendorTimingMetrics = TimingCalculator::getVendorMetrics($allTimings, $vendorMapTiming);

// Load events for filter dropdown
$eventsFile = __DIR__ . '/events.json';
$events = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
$eventNames = [];
$allEvents = array_merge($events['active'] ?? [], $events['archived'] ?? []);
foreach ($allEvents as $e) { if (!empty($e['acronym'])) $eventNames[$e['acronym']] = $e['name'] ?? $e['acronym']; }

// Quick period buttons

$periodButtons = [
    '7days' => 'Last 7 Days',
    '30days' => 'Last 30 Days',
    '90days' => 'Last 90 Days',
    'thismonth' => 'This Month',
    'lastmonth' => 'Last Month'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Analytics - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Shared Admin CSS (parent css/ directory) -->
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="../css/admin-responsive.css">
    <link rel="stylesheet" href="../css/admin-print.css" media="print">
    <!-- Page-specific (same directory) -->
    <link rel="stylesheet" href="production-analytics.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('production_analytics'); ?>
<script src="../js/admin-sidebar.js"></script>
<div style="margin: 0 auto!important; padding: 0 20px!important;">

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><?= ICON_CHART_UP ?> Production Analytics</h1>
        <div class="page-welcome">
            <span class="welcome-text">Vendor performance &amp; workflow insights</span>
            <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
    </div>
    <div class="page-header-right">
        <a href="../admin-orders.php" class="header-btn header-btn-light"><?= ICON_CLIPBOARD ?> Dashboard</a>
    </div>
</div>

<!-- Filter Bar -->
<div class="pf-filter-bar">
    <div class="pf-quick-periods">
        <span class="pf-periods-label">Period:</span>
        <?php foreach ($periodButtons as $key => $label): ?>
        <a href="?range=<?= $key ?>" class="pf-period-btn <?= $rangePreset === $key ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
        <a href="javascript:void(0)" onclick="document.getElementById('customRangeRow').style.display='flex'" class="pf-period-btn <?= $rangePreset === 'custom' ? 'active' : '' ?>">Custom</a>
    </div>
    <div class="pf-custom-range" id="customRangeRow" style="<?= $rangePreset === 'custom' ? '' : 'display:none' ?>">
        <div class="pf-filter-group">
            <label>From</label>
            <input type="date" id="startDate" value="<?= $startDate->format('Y-m-d') ?>">
        </div>
        <div class="pf-filter-group">
            <label>To</label>
            <input type="date" id="endDate" value="<?= $endDate->format('Y-m-d') ?>">
        </div>
        <button class="pf-apply-btn" onclick="applyCustomRange()">Apply</button>
    </div>
    <?php if (!empty($eventNames)): ?>
    <div class="pf-event-filter">
        <label>Event:</label>
        <select onchange="filterByEvent(this.value)">
            <option value="">All Events</option>
            <?php foreach ($eventNames as $acr => $name): ?>
            <option value="<?= htmlspecialchars($acr) ?>" <?= $eventFilter === $acr ? 'selected' : '' ?>><?= htmlspecialchars($acr) ?> — <?= htmlspecialchars($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="pf-date-range-info">
        <?= ICON_CALENDAR ?> <?= $startDate->format('M j, Y') ?> &mdash; <?= $endDate->format('M j, Y') ?>
    </div>
</div>

<!-- Stats Row (matches dashboard analytics-card pattern) -->
<div class="analytics-dashboard">
    <div class="analytics-row row-5">
        <!-- Orders Pushed -->
        <div class="analytics-card compact" style="background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); color: white;">
            <div class="card-header" style="border-bottom-color: rgba(255,255,255,0.15);">
                <span class="card-icon"><?= ICON_ROCKET ?></span>
                <span class="card-title" style="color: rgba(255,255,255,0.8);">Orders Pushed</span>
            </div>
            <div class="card-content">
                <div class="primary-metric" style="color: white;"><?= $stats['total_pushed'] ?></div>
            </div>
        </div>

        <!-- Confirmed -->
        <div class="analytics-card compact" style="background: linear-gradient(135deg, #059669 0%, #047857 100%); color: white;">
            <div class="card-header" style="border-bottom-color: rgba(255,255,255,0.15);">
                <span class="card-icon"><?= ICON_CHECK_GREEN ?></span>
                <span class="card-title" style="color: rgba(255,255,255,0.8);">Confirmed</span>
            </div>
            <div class="card-content">
                <div class="primary-metric" style="color: white;"><?= $stats['confirmed'] ?></div>
                <div class="secondary-metric" style="color: rgba(255,255,255,0.8);"><?= $stats['confirmation_rate'] ?>% rate</div>
            </div>
        </div>

        <!-- Pending -->
        <div class="analytics-card compact">
            <div class="card-header">
                <span class="card-icon"><?= ICON_CLOCK ?></span>
                <span class="card-title">Pending</span>
            </div>
            <div class="card-content">
                <div class="primary-metric"><?= $stats['pending'] ?></div>
                <div class="secondary-metric">awaiting confirmation</div>
            </div>
        </div>

        <!-- File Issues -->
        <div class="analytics-card compact" <?php if ($stats['file_issues'] > 0): ?>style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); color: white;"<?php endif; ?>>
            <div class="card-header" <?php if ($stats['file_issues'] > 0): ?>style="border-bottom-color: rgba(255,255,255,0.15);"<?php endif; ?>>
                <span class="card-icon"><?= ICON_SIREN ?></span>
                <span class="card-title" <?php if ($stats['file_issues'] > 0): ?>style="color: rgba(255,255,255,0.8);"<?php endif; ?>>File Issues</span>
            </div>
            <div class="card-content">
                <div class="primary-metric" <?php if ($stats['file_issues'] > 0): ?>style="color: white;"<?php endif; ?>><?= $stats['file_issues'] ?></div>
            </div>
        </div>

        <!-- Avg Confirmation -->
        <div class="analytics-card compact">
            <div class="card-header">
                <span class="card-icon"><?= ICON_HOURGLASS ?></span>
                <span class="card-title">Avg Confirmation</span>
            </div>
            <div class="card-content">
                <div class="primary-metric"><?= $stats['avg_confirmation_hours'] ?>h</div>
                <div class="secondary-metric">fastest: <?= $stats['fastest_display'] ?></div>
            </div>
        </div>
    </div>

    <!-- Row 1b: Production Timing Metrics -->
    <div class="analytics-row row-3">
        <div class="analytics-card compact" style="border-top: 4px solid #3b82f6;">
            <div class="card-header">
                <span class="card-icon"><?= ICON_CLOCK ?></span>
                <span class="card-title">Avg Confirmation</span>
            </div>
            <div class="card-content">
                <div class="primary-metric"><?= TimingCalculator::formatHours($avgConfirmation['avg']) ?></div>
                <div class="secondary-metric">median: <?= TimingCalculator::formatHours($avgConfirmation['median']) ?> &bull; <?= $avgConfirmation['count'] ?> orders</div>
            </div>
        </div>
        <div class="analytics-card compact" style="border-top: 4px solid #f59e0b;">
            <div class="card-header">
                <span class="card-icon">&#9881;&#65039;</span>
                <span class="card-title">Avg Production</span>
            </div>
            <div class="card-content">
                <div class="primary-metric"><?= TimingCalculator::formatHours($avgProduction['avg']) ?></div>
                <div class="secondary-metric">median: <?= TimingCalculator::formatHours($avgProduction['median']) ?> &bull; <?= $avgProduction['count'] ?> orders</div>
            </div>
        </div>
        <div class="analytics-card compact" style="border-top: 4px solid #10b981;">
            <div class="card-header">
                <span class="card-icon"><?= ICON_ROCKET ?></span>
                <span class="card-title">Avg Total (Push &#8594; Ready)</span>
            </div>
            <div class="card-content">
                <div class="primary-metric"><?= TimingCalculator::formatHours($avgTotal['avg']) ?></div>
                <div class="secondary-metric">median: <?= TimingCalculator::formatHours($avgTotal['median']) ?> &bull; <?= $avgTotal['count'] ?> orders</div>
            </div>
        </div>
    </div>

    <!-- Row 2: Daily Chart + Confirmation Speed -->
    <div class="analytics-row charts-row-2col">

        <!-- Daily Activity Chart -->
        <div class="analytics-card chart-large">
            <div class="card-header">
                <span class="card-icon"><?= ICON_CHART_UP ?></span>
                <span class="card-title">Daily Activity</span>
            </div>
            <div class="card-content" style="padding: 15px;">
                <div class="chart-container" style="height: 280px;">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Confirmation Speed Breakdown -->
        <div class="analytics-card compact" style="border-top: 4px solid #7c3aed;">
            <div class="card-header">
                <span class="card-icon"><?= ICON_CLOCK ?></span>
                <span class="card-title">Confirmation Speed</span>
            </div>
            <div class="card-content">
                <?php
                $speedBrackets = [
                    ['label' => '< 1 hour', 'count' => $stats['confirmed_within_1h'], 'class' => 'priority-early'],
                    ['label' => '1-2 hrs', 'count' => $stats['confirmed_within_2h'], 'class' => 'priority-standard'],
                    ['label' => '2-4 hrs', 'count' => $stats['confirmed_within_4h'], 'class' => 'priority-rush'],
                    ['label' => '4+ hrs', 'count' => $stats['confirmed_after_4h'], 'class' => 'priority-critical'],
                ];
                $maxBracket = max(1, $stats['confirmed_with_time']);
                ?>
                <div id="turnaroundList">
                    <?php foreach ($speedBrackets as $bracket): ?>
                    <div class="turnaround-item">
                        <span class="turnaround-label"><?= $bracket['label'] ?></span>
                        <div class="turnaround-progress-container">
                            <div class="turnaround-progress-bar <?= $bracket['class'] ?>" style="width: <?= round(($bracket['count'] / $maxBracket) * 100) ?>%"></div>
                        </div>
                        <span class="turnaround-value"><?= $bracket['count'] ?> order<?= $bracket['count'] !== 1 ? 's' : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="pf-speed-summary">
                    <span>Fastest: <strong><?= $stats['fastest_display'] ?></strong></span>
                    <span>Slowest: <strong><?= $stats['slowest_display'] ?></strong></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Row 3: Vendor Performance Table (full width) -->
    <div class="analytics-card" style="border-top: 4px solid #7c3aed;">
        <div class="card-header">
            <span class="card-icon"><?= ICON_USERS ?></span>
            <span class="card-title">Vendor Performance</span>
            <span class="pf-card-badge"><?= count($vendorStats) ?> vendor<?= count($vendorStats) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="card-content" style="padding: 0;">
            <?php if (count($vendorStats) > 0): ?>
            <table class="orders-table pf-vendor-table">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Orders</th>
                        <th>Confirmed</th>
                        <th>Rate</th>
                        <th>Avg Time</th>
                        <th>Pending</th>
                        <th>Issues</th>
                        <th>Reminders</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vendorStats as $vs): ?>
                    <?php
                    $perfScore = 'neutral';
                    if ($vs['confirmation_rate'] >= 90 && $vs['avg_confirmation_hours'] <= 2) {
                        $perfScore = 'excellent';
                    } elseif ($vs['confirmation_rate'] >= 70 && $vs['avg_confirmation_hours'] <= 4) {
                        $perfScore = 'good';
                    } elseif ($vs['confirmation_rate'] < 50 || $vs['avg_confirmation_hours'] > 8) {
                        $perfScore = 'poor';
                    }
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($vs['vendor_name']) ?></strong></td>
                        <td><?= $vs['total_orders'] ?></td>
                        <td><?= $vs['confirmed'] ?></td>
                        <td>
                            <span style="background: <?= $vs['confirmation_rate'] >= 90 ? '#dcfce7; color: #166534' : ($vs['confirmation_rate'] >= 70 ? '#dbeafe; color: #1e40af' : '#fef3c7; color: #92400e') ?>; display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;">
                                <?= $vs['confirmation_rate'] ?>%
                            </span>
                        </td>
                        <td><?= $vs['avg_confirmation_hours'] > 0 ? $vs['avg_confirmation_hours'] . 'h' : '&mdash;' ?></td>
                        <td><?= $vs['pending'] ?></td>
                        <td>
                            <?php if ($vs['file_issues'] > 0): ?>
                            <span style="background: #fee2e2; color: #991b1b; display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 0.75rem; font-weight: 600;"><?= $vs['file_issues'] ?></span>
                            <?php else: ?>
                            0
                            <?php endif; ?>
                        </td>
                        <td><?= $vs['reminders_sent'] ?></td>
                        <td>
                            <span class="pf-perf-indicator pf-perf-<?= $perfScore ?>">
                                <?php if ($perfScore === 'excellent'): ?>
                                    <?= ICON_STAR ?> Excellent
                                <?php elseif ($perfScore === 'good'): ?>
                                    <?= ICON_THUMBS_UP ?> Good
                                <?php elseif ($perfScore === 'poor'): ?>
                                    <?= ICON_WARNING ?> Needs Attention
                                <?php else: ?>
                                    &mdash; Average
                                <?php endif; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #6b7280;">
                <p><?= ICON_INBOX ?> No vendor data available for this period.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Row 3b: Vendor Production Timing -->
    <?php if (!empty($vendorTimingMetrics)): ?>
    <div class="analytics-card" style="border-top: 4px solid #f59e0b;">
        <div class="card-header">
            <span class="card-icon">&#9881;&#65039;</span>
            <span class="card-title">Vendor Production Timing</span>
            <span class="pf-card-badge"><?= count($vendorTimingMetrics) ?> vendor<?= count($vendorTimingMetrics) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="card-content" style="padding: 0;">
            <table class="orders-table pf-vendor-table">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th class="text-right">Orders</th>
                        <th class="text-right">Avg Confirm</th>
                        <th class="text-right">Avg Production</th>
                        <th class="text-right">Avg Total</th>
                        <th class="text-right">On-Time %</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vendorTimingMetrics as $vId => $vt): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($vt['name']) ?></strong></td>
                        <td class="text-right"><?= $vt['orders'] ?></td>
                        <td class="text-right"><?= TimingCalculator::formatDuration($vt['avg_confirmation']) ?></td>
                        <td class="text-right"><?= TimingCalculator::formatDuration($vt['avg_production']) ?></td>
                        <td class="text-right"><?= TimingCalculator::formatDuration($vt['avg_total']) ?></td>
                        <td class="text-right">
                            <?php if ($vt['completed'] > 0): ?>
                            <span class="pf-perf-badge <?= $vt['on_time_rate'] >= 90 ? 'pf-perf-good' : ($vt['on_time_rate'] >= 70 ? 'pf-perf-ok' : 'pf-perf-poor') ?>">
                                <?= $vt['on_time_rate'] ?>%
                            </span>
                            <?php else: ?>&mdash;<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Row 4: Reminders + Peak Hours -->
    <div class="analytics-row charts-row-2col">

        <!-- Reminder Activity -->
        <div class="analytics-card compact" style="border-top: 4px solid #7c3aed;">
            <div class="card-header">
                <span class="card-icon"><?= ICON_BELL ?></span>
                <span class="card-title">Reminder Activity</span>
            </div>
            <div class="card-content">
                <div class="pf-reminder-grid">
                    <div class="pf-reminder-metric">
                        <div class="primary-metric"><?= $stats['total_reminders'] ?></div>
                        <div class="secondary-metric">Total Sent</div>
                    </div>
                    <div class="pf-reminder-metric">
                        <div class="primary-metric"><?= $stats['orders_with_reminders'] ?></div>
                        <div class="secondary-metric">Orders Reminded</div>
                    </div>
                    <div class="pf-reminder-metric">
                        <div class="primary-metric">
                            <?= $stats['orders_with_reminders'] > 0 
                                ? round($stats['total_reminders'] / $stats['orders_with_reminders'], 1) 
                                : 0 ?>
                        </div>
                        <div class="secondary-metric">Avg per Order</div>
                    </div>
                </div>
                
                <?php if ($stats['total_pushed'] > 0): ?>
                <div class="pf-reminder-rate">
                    <span class="pf-rate-label">Needed reminders:</span>
                    <div class="turnaround-progress-container" style="flex: 1;">
                        <div class="turnaround-progress-bar priority-rush" style="width: <?= round(($stats['orders_with_reminders'] / $stats['total_pushed']) * 100) ?>%"></div>
                    </div>
                    <span class="pf-rate-value"><?= round(($stats['orders_with_reminders'] / $stats['total_pushed']) * 100) ?>%</span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Peak Hours Chart -->
        <div class="analytics-card chart-large" style="border-top: 4px solid #7c3aed;">
            <div class="card-header">
                <span class="card-icon"><?= ICON_CLOCK ?></span>
                <span class="card-title">Push Time Distribution</span>
            </div>
            <div class="card-content" style="padding: 15px;">
                <div class="chart-container" style="height: 220px;">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.analytics-dashboard -->

</div><!-- /main container -->

<script>
    // Custom range handler
    function applyCustomRange() {
        var start = document.getElementById('startDate').value;
        var end = document.getElementById('endDate').value;
        window.location.href = '?range=custom&start=' + start + '&end=' + end;
    }

    // ============================================
    // CHART: Daily Activity (Line)
    // ============================================
    var dailyCtx = document.getElementById('dailyChart').getContext('2d');
    new Chart(dailyCtx, {
        type: 'line',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [
                {
                    label: 'Pushed',
                    data: <?= json_encode($chartPushed) ?>,
                    borderColor: '#7c3aed',
                    backgroundColor: 'rgba(124, 58, 237, 0.08)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#7c3aed'
                },
                {
                    label: 'Confirmed',
                    data: <?= json_encode($chartConfirmed) ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.08)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#10b981'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { family: 'Montserrat', size: 11, weight: '500' },
                        usePointStyle: true,
                        padding: 20
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { 
                        stepSize: 1,
                        font: { family: 'Montserrat', size: 11 }
                    },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                },
                x: {
                    ticks: { 
                        font: { family: 'Montserrat', size: 10 },
                        maxRotation: 45,
                        minRotation: 0
                    },
                    grid: { display: false }
                }
            }
        }
    });

    // ============================================
    // CHART: Hourly Distribution (Bar)
    // ============================================
    var hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
    var hourLabels = [];
    for (var i = 0; i < 24; i++) {
        hourLabels.push(i.toString().padStart(2, '0') + ':00');
    }
    
    new Chart(hourlyCtx, {
        type: 'bar',
        data: {
            labels: hourLabels,
            datasets: [{
                label: 'Orders Pushed',
                data: <?= json_encode(array_values($hourlyDistribution)) ?>,
                backgroundColor: 'rgba(124, 58, 237, 0.6)',
                borderColor: '#7c3aed',
                borderWidth: 1,
                borderRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { 
                        stepSize: 1,
                        font: { family: 'Montserrat', size: 10 }
                    },
                    grid: { color: 'rgba(0,0,0,0.04)' }
                },
                x: {
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45,
                        font: { family: 'Montserrat', size: 9 }
                    },
                    grid: { display: false }
                }
            }
        }
    });
</script>

</body>
</html>
