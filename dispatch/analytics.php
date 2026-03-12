<?php
/**
 * Dispatch Analytics — Dashboard Page
 * MTCC Print Services
 * 
 * Delivery performance metrics, courier stats, and trend charts.
 * Server path: /dispatch/analytics.php
 */

require_once __DIR__ . '/../admin-auth.php';
requirePermission('dispatch');

require_once __DIR__ . '/dispatch-analytics.php';

$period = $_GET['period'] ?? '30days';
$analytics = new DispatchAnalytics($period);
$data = $analytics->getAll();

$summary = $data['summary'];
$dailyVolume = $data['daily_volume'];
$courierPerf = $data['courier_performance'];
$destBreakdown = $data['destination_breakdown'];
$tierDist = $data['tier_distribution'];
$hourlyDist = $data['hourly_distribution'];
$recentDeliveries = $data['recent_deliveries'];
$totalOrders = $data['total_orders'];

// Period labels
$periodLabels = [
    'today' => 'Today',
    '7days' => 'Last 7 Days',
    '30days' => 'Last 30 Days',
    'all' => 'All Time',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Analytics - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
    /* ---- Analytics Layout ---- */
    .analytics-container { max-width: 1400px; margin: 0 auto; padding: 0 20px 40px; }
    
    .analytics-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 12px;
    }
    .analytics-header-left h1 {
        font-size: 1.4rem;
        font-weight: 800;
        color: var(--text);
        margin-bottom: 2px;
    }
    .analytics-header-left p {
        font-size: 0.8rem;
        color: var(--subtext);
    }
    .analytics-header-right {
        display: flex;
        gap: 8px;
        align-items: center;
    }
    .analytics-back {
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
    .analytics-back:hover { background: #f9fafb; border-color: #9ca3af; color: var(--text); }

    /* Period Selector */
    .period-selector {
        display: inline-flex;
        background: var(--card);
        border: 1px solid #e5e7eb;
        border-radius: 8px;
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .period-btn {
        padding: 7px 14px;
        font-size: 0.74rem;
        font-weight: 600;
        color: var(--subtext);
        background: transparent;
        border: none;
        border-right: 1px solid #e5e7eb;
        cursor: pointer;
        transition: all 0.15s;
        font-family: 'Montserrat', sans-serif;
    }
    .period-btn:last-child { border-right: none; }
    .period-btn:hover { background: var(--bg); }
    .period-btn.active {
        background: var(--primary);
        color: white;
    }

    /* ---- Summary Cards ---- */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
        margin-bottom: 24px;
    }
    .stat-card {
        background: var(--card);
        border: 1px solid #e5e7eb;
        border-radius: var(--radius);
        padding: 16px 20px;
        box-shadow: var(--shadow-sm);
    }
    .stat-card-label {
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--subtext);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        margin-bottom: 4px;
    }
    .stat-card-value {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--text);
        line-height: 1.1;
    }
    .stat-card-sub {
        font-size: 0.7rem;
        color: var(--subtext);
        margin-top: 4px;
    }
    .stat-accent-green .stat-card-value { color: var(--green); }
    .stat-accent-purple .stat-card-value { color: var(--primary); }
    .stat-accent-blue .stat-card-value { color: var(--blue); }
    .stat-accent-orange .stat-card-value { color: var(--orange); }

    /* ---- Chart Panels ---- */
    .chart-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }
    .chart-panel {
        background: var(--card);
        border: 1px solid #e5e7eb;
        border-radius: var(--radius);
        box-shadow: var(--shadow-sm);
        padding: 16px 20px 20px;
    }
    .chart-panel-wide { grid-column: 1 / -1; }
    .chart-title {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--text);
        margin-bottom: 14px;
    }
    .chart-canvas-wrap { position: relative; height: 260px; }
    .chart-canvas-wrap-sm { position: relative; height: 200px; }

    /* ---- Courier Table ---- */
    .courier-table { width: 100%; border-collapse: collapse; }
    .courier-table th {
        font-size: 0.68rem;
        font-weight: 700;
        color: var(--subtext);
        text-transform: uppercase;
        letter-spacing: 0.04em;
        text-align: left;
        padding: 8px 12px;
        border-bottom: 2px solid #e5e7eb;
    }
    .courier-table td {
        padding: 10px 12px;
        font-size: 0.8rem;
        border-bottom: 1px solid #f3f4f6;
        color: var(--text);
    }
    .courier-table tr:hover td { background: var(--bg); }
    .courier-name { font-weight: 700; }
    .courier-stat { font-weight: 600; }
    .courier-stat-muted { color: var(--subtext); font-weight: 500; }

    /* ---- Recent Deliveries ---- */
    .recent-list { display: flex; flex-direction: column; gap: 0; }
    .recent-item {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid #f3f4f6;
    }
    .recent-item:last-child { border-bottom: none; }
    .recent-ref {
        font-size: 0.76rem;
        font-weight: 700;
        font-family: 'Montserrat', sans-serif;
        color: var(--primary);
        min-width: 80px;
    }
    .recent-status {
        font-size: 0.62rem;
        font-weight: 700;
        padding: 2px 8px;
        border-radius: 4px;
        text-transform: uppercase;
    }
    .recent-status-delivered { background: #ecfdf5; color: var(--green); }
    .recent-status-pickedup { background: #eff6ff; color: var(--blue); }
    .recent-status-dispatched { background: var(--primary-light); color: var(--primary); }
    .recent-status-ready { background: #fff7ed; color: var(--orange); }
    .recent-detail {
        flex: 1;
        font-size: 0.74rem;
        color: var(--subtext);
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .recent-time {
        font-size: 0.68rem;
        color: var(--subtext);
        white-space: nowrap;
    }

    /* ---- Empty State ---- */
    .analytics-empty {
        text-align: center;
        padding: 60px 20px;
        color: var(--subtext);
    }
    .analytics-empty-icon { font-size: 3rem; margin-bottom: 12px; }
    .analytics-empty-title { font-size: 1.1rem; font-weight: 700; color: var(--text); margin-bottom: 6px; }
    .analytics-empty-text { font-size: 0.82rem; max-width: 400px; margin: 0 auto; }

    /* ---- Responsive ---- */
    @media (max-width: 768px) {
        .chart-grid { grid-template-columns: 1fr; }
        .summary-grid { grid-template-columns: repeat(2, 1fr); }
        .analytics-header { flex-direction: column; align-items: flex-start; }
    }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_analytics'); ?>
<script src="../js/admin-sidebar.js"></script>

<div class="analytics-container">
    
    <!-- Header -->
    <div class="analytics-header">
        <div class="analytics-header-left">
            <h1>&#128202; Dispatch Analytics</h1>
            <p>Delivery performance and courier metrics &mdash; <?= htmlspecialchars($periodLabels[$period] ?? 'All Time') ?></p>
        </div>
        <div class="analytics-header-right">
            <div class="period-selector">
                <?php foreach ($periodLabels as $key => $label): ?>
                <button class="period-btn <?= $period === $key ? 'active' : '' ?>" onclick="window.location.href='?period=<?= $key ?>'">
                    <?= $label ?>
                </button>
                <?php endforeach; ?>
            </div>
            <a href="rate-optimization.php" class="analytics-back" style="background:var(--primary);color:white;border-color:var(--primary);">&#128200; Rate Optimization</a>
            <a href="./" class="analytics-back">&larr; Dispatch Hub</a>
        </div>
    </div>

    <?php if ($totalOrders === 0): ?>
    <!-- Empty State -->
    <div class="analytics-empty">
        <div class="analytics-empty-icon">&#128202;</div>
        <div class="analytics-empty-title">No dispatch data yet</div>
        <div class="analytics-empty-text">Analytics will populate once orders move through the dispatch workflow. Dispatched, shipped, delivered, and picked-up orders all contribute to these metrics.</div>
    </div>

    <?php else: ?>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="stat-card stat-accent-purple">
            <div class="stat-card-label">Total Dispatched</div>
            <div class="stat-card-value"><?= $summary['total_dispatched'] ?></div>
            <div class="stat-card-sub"><?= $summary['completed'] ?> completed &middot; <?= $summary['pending'] ?> pending</div>
        </div>
        <div class="stat-card stat-accent-green">
            <div class="stat-card-label">On-Time Rate</div>
            <div class="stat-card-value"><?= $summary['on_time_rate'] ?>%</div>
            <div class="stat-card-sub"><?= $summary['on_time_count'] ?> of <?= $summary['completed'] ?> deliveries</div>
        </div>
        <div class="stat-card stat-accent-blue">
            <div class="stat-card-label">Avg Delivery Time</div>
            <div class="stat-card-value"><?= $summary['avg_delivery_minutes'] > 0 ? $summary['avg_delivery_minutes'] . 'm' : '—' ?></div>
            <div class="stat-card-sub">Dispatched to delivered</div>
        </div>
        <div class="stat-card stat-accent-orange">
            <div class="stat-card-label">Total Courier Cost</div>
            <div class="stat-card-value">$<?= number_format($summary['total_courier_cost'], 0) ?></div>
            <div class="stat-card-sub">Avg $<?= number_format($summary['avg_cost_per_delivery'], 2) ?> per delivery</div>
        </div>
    </div>

    <!-- Charts Row 1: Volume + Destinations -->
    <div class="chart-grid">
        <div class="chart-panel chart-panel-wide">
            <div class="chart-title">Daily Delivery Volume</div>
            <div class="chart-canvas-wrap">
                <canvas id="volumeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 2: Destinations + Tiers -->
    <div class="chart-grid">
        <div class="chart-panel">
            <div class="chart-title">Delivery Destinations</div>
            <div class="chart-canvas-wrap-sm">
                <canvas id="destChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <div class="chart-title">Turnaround Tier Distribution</div>
            <div class="chart-canvas-wrap-sm">
                <canvas id="tierChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Charts Row 3: Hourly + (Courier Table) -->
    <div class="chart-grid">
        <div class="chart-panel">
            <div class="chart-title">Dispatch Time of Day</div>
            <div class="chart-canvas-wrap-sm">
                <canvas id="hourlyChart"></canvas>
            </div>
        </div>
        <div class="chart-panel">
            <div class="chart-title">Recent Deliveries</div>
            <?php if (empty($recentDeliveries)): ?>
            <div style="text-align:center;padding:30px;color:var(--subtext);font-size:0.82rem;">No deliveries recorded yet</div>
            <?php else: ?>
            <div class="recent-list">
                <?php foreach (array_slice($recentDeliveries, 0, 8) as $d): ?>
                <div class="recent-item">
                    <span class="recent-ref"><?= htmlspecialchars($d['ref']) ?></span>
                    <span class="recent-status recent-status-<?= htmlspecialchars($d['status']) ?>"><?= htmlspecialchars($d['status']) ?></span>
                    <span class="recent-detail"><?= htmlspecialchars($d['courier']) ?> &rarr; <?= htmlspecialchars($d['destination'] ?: $d['customer']) ?></span>
                    <span class="recent-time"><?= $d['completed_at'] ? date('M j, g:ia', strtotime($d['completed_at'])) : '' ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Courier Performance Table -->
    <?php if (!empty($courierPerf)): ?>
    <div class="chart-panel" style="margin-bottom: 24px;">
        <div class="chart-title">Courier Performance</div>
        <table class="courier-table">
            <thead>
                <tr>
                    <th>Courier</th>
                    <th>Deliveries</th>
                    <th>Completed</th>
                    <th>Avg Time</th>
                    <th>Total Distance</th>
                    <th>Total Payout</th>
                    <th>Avg Payout</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($courierPerf as $c): ?>
                <tr>
                    <td class="courier-name"><?= htmlspecialchars($c['name']) ?></td>
                    <td class="courier-stat"><?= $c['total'] ?></td>
                    <td class="courier-stat"><?= $c['completed'] ?></td>
                    <td class="courier-stat-muted"><?= $c['avg_minutes'] > 0 ? $c['avg_minutes'] . ' min' : '—' ?></td>
                    <td class="courier-stat-muted"><?= $c['total_distance_km'] > 0 ? round($c['total_distance_km'], 1) . ' km' : '—' ?></td>
                    <td class="courier-stat">$<?= number_format($c['total_payout'], 2) ?></td>
                    <td class="courier-stat-muted">$<?= number_format($c['avg_payout'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; // end totalOrders > 0 ?>
</div>

<script>
<?php if ($totalOrders > 0): ?>
// Chart.js defaults
Chart.defaults.font.family = "'Montserrat', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#6b7280';

// Colors
var purple = '#7c3aed';
var green = '#059669';
var blue = '#0284c7';
var orange = '#ea580c';
var gray = '#9ca3af';

// ---- Volume Chart ----
(function() {
    var ctx = document.getElementById('volumeChart');
    if (!ctx) return;
    var dailyData = <?= json_encode($dailyVolume) ?>;
    var labels = Object.keys(dailyData).map(function(d) {
        var dt = new Date(d + 'T12:00:00');
        return dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    });
    var dispatched = Object.values(dailyData).map(function(v) { return v.dispatched; });
    var completed = Object.values(dailyData).map(function(v) { return v.completed; });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Dispatched',
                    data: dispatched,
                    backgroundColor: purple + '33',
                    borderColor: purple,
                    borderWidth: 1.5,
                    borderRadius: 4,
                },
                {
                    label: 'Completed',
                    data: completed,
                    backgroundColor: green + '33',
                    borderColor: green,
                    borderWidth: 1.5,
                    borderRadius: 4,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { boxWidth: 12, padding: 16 } } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f3f4f6' } },
                x: { grid: { display: false } }
            }
        }
    });
})();

// ---- Destination Chart ----
(function() {
    var ctx = document.getElementById('destChart');
    if (!ctx) return;
    var destData = <?= json_encode($destBreakdown['by_location']) ?>;
    var labels = Object.keys(destData);
    var values = Object.values(destData);
    var colors = [purple, green, blue, orange, '#eab308', '#ec4899', gray, '#8b5cf6', '#14b8a6', '#f43f5e'];

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }
            }
        }
    });
})();

// ---- Tier Chart ----
(function() {
    var ctx = document.getElementById('tierChart');
    if (!ctx) return;
    var tierData = <?= json_encode($tierDist) ?>;
    var labels = Object.keys(tierData);
    var values = Object.values(tierData);
    var colors = ['#059669', '#0284c7', '#7c3aed', '#ea580c', '#dc2626', '#eab308'];

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{ data: values, backgroundColor: colors.slice(0, labels.length), borderWidth: 2, borderColor: '#fff' }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'right', labels: { boxWidth: 10, padding: 8, font: { size: 11 } } }
            }
        }
    });
})();

// ---- Hourly Chart ----
(function() {
    var ctx = document.getElementById('hourlyChart');
    if (!ctx) return;
    var hourlyData = <?= json_encode($hourlyDist) ?>;
    var labels = [];
    for (var i = 0; i < 24; i++) {
        labels.push(i === 0 ? '12a' : i < 12 ? i + 'a' : i === 12 ? '12p' : (i-12) + 'p');
    }

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Dispatches',
                data: hourlyData,
                backgroundColor: purple + '44',
                borderColor: purple,
                borderWidth: 1,
                borderRadius: 3,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f3f4f6' } },
                x: { grid: { display: false }, ticks: { font: { size: 9 } } }
            }
        }
    });
})();
<?php endif; ?>
</script>

</body>
</html>
