<?php
/**
 * MTCC Revenue Reports page
 * Shows active events with running totals + archived event revenue reports.
 * Accessible from the MTCC dashboard.
 *
 * Location: /admin/mtcc-reports.php
 */

require_once __DIR__ . '/../admin-auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/site-settings.php';
require_once __DIR__ . '/../includes/status-config.php';

// Require MTCC analytics permission or admin+
if (!hasPermission('mtcc_analytics') && !hasPermission('dashboard_analytics')) {
    requireAnyPermission(['mtcc_analytics', 'dashboard_analytics']);
}

$settings = getSiteSettings();
$venueRate = $settings['mtcc_venue_fee_rate'] ?? 0.10;
$venueRatePct = round($venueRate * 100);

// Load events
$eventsFile = __DIR__ . '/events.json';
$eventsData = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
$activeEvents = $eventsData['active'] ?? [];
$archivedEvents = $eventsData['archived'] ?? [];

// Load orders + statuses for active event breakdown
$orderDir = __DIR__ . '/../uploads/orders/';
$statusFile = __DIR__ . '/../data/statuses.json';
$statuses = file_exists($statusFile) ? (json_decode(file_get_contents($statusFile), true) ?: []) : [];

$revenueStatuses = ['paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];

// Build active event breakdown
$activeBreakdown = [];
foreach ($activeEvents as $ev) {
    $prefix = strtoupper($ev['acronym'] ?? '');
    $activeBreakdown[$prefix] = [
        'name' => $ev['name'] ?? $prefix,
        'dates' => $ev['dates'] ?? '',
        'orders' => 0,
        'base_revenue' => 0,
    ];
}

if (is_dir($orderDir)) {
    $files = glob($orderDir . '*.json');
    foreach ($files as $file) {
        if (strpos($file, '_history.json') !== false) continue;
        $order = json_decode(file_get_contents($file), true);
        if (!$order) continue;

        $ref = $order['referenceCode'] ?? '';
        $prefix = strtoupper(explode('-', $ref)[0]);
        if (!isset($activeBreakdown[$prefix])) continue;

        $status = $statuses[$ref] ?? ($order['status'] ?? 'unpaid');
        if (!in_array($status, $revenueStatuses)) continue;

        $activeBreakdown[$prefix]['orders']++;
        $activeBreakdown[$prefix]['base_revenue'] += $order['pricing']['basePrice'] ?? 0;
    }
}

foreach ($activeBreakdown as &$ev) {
    $ev['venue_fee'] = $ev['base_revenue'] * $venueRate;
}
unset($ev);

$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Revenue Reports - MTCC</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-sidebar.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-responsive.css">
    <style>
        .reports-wrap {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .reports-header {
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-color);
        }

        .reports-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .reports-subtitle {
            color: var(--text-secondary);
            font-size: 0.88rem;
        }

        .reports-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--primary);
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 12px;
        }
        .reports-back:hover { text-decoration: underline; }

        .section {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: rgba(0,0,0,0.04) 0 1px 2px;
            margin-bottom: 24px;
            overflow: hidden;
        }

        .section-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border-color);
            background: linear-gradient(135deg, #faf8ff, #ffffff);
        }

        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 2px;
        }

        .section-desc {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .report-table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-table th {
            text-align: left;
            padding: 12px 24px;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border-color);
        }

        .report-table td {
            padding: 14px 24px;
            border-bottom: 1px solid #f3f4f6;
            font-size: 0.88rem;
            color: var(--text-primary);
            vertical-align: middle;
        }

        .report-table tr:last-child td { border-bottom: none; }
        .report-table tr:hover td { background: #fafbfc; }

        .col-fee { color: var(--primary); font-weight: 700; }
        .col-event-name { font-weight: 600; }
        .col-dates { color: var(--text-secondary); font-size: 0.8rem; }
        .col-status-live {
            display: inline-block;
            padding: 3px 10px;
            background: #ecfdf5;
            color: #059669;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .view-btn {
            display: inline-block;
            padding: 6px 14px;
            background: var(--primary);
            color: white;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 600;
            text-decoration: none;
            transition: opacity 0.15s;
        }
        .view-btn:hover { opacity: 0.85; }

        .empty-state {
            padding: 48px 24px;
            text-align: center;
            color: var(--text-secondary);
            font-size: 0.88rem;
        }

        .totals-row {
            background: #faf8ff !important;
            font-weight: 700;
        }
        .totals-row td { border-top: 2px solid var(--border-color); }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('mtcc_reports'); ?>
<script src="../js/admin-sidebar.js"></script>

<div class="reports-wrap">

    <a href="../admin-orders.php" class="reports-back">&larr; Back to Dashboard</a>

    <div class="reports-header">
        <div class="reports-title">Revenue Reports</div>
        <div class="reports-subtitle">Per-event revenue totals and venue fee calculations</div>
    </div>

    <!-- Active Events -->
    <div class="section">
        <div class="section-header">
            <div class="section-title">Active Events</div>
            <div class="section-desc">Running totals for events currently in progress</div>
        </div>
        <?php if (!empty($activeBreakdown)): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Dates</th>
                    <th>Orders</th>
                    <th>Base Revenue</th>
                    <th>Venue Fee (<?= $venueRatePct ?>%)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $activeTotal = ['orders' => 0, 'base' => 0, 'fee' => 0];
                foreach ($activeBreakdown as $prefix => $ev):
                    $activeTotal['orders'] += $ev['orders'];
                    $activeTotal['base'] += $ev['base_revenue'];
                    $activeTotal['fee'] += $ev['venue_fee'];
                ?>
                <tr>
                    <td class="col-event-name"><?= htmlspecialchars($ev['name']) ?></td>
                    <td class="col-dates"><?= htmlspecialchars($ev['dates']) ?></td>
                    <td><?= $ev['orders'] ?></td>
                    <td>$<?= number_format($ev['base_revenue'], 2) ?></td>
                    <td class="col-fee">$<?= number_format($ev['venue_fee'], 2) ?></td>
                    <td><span class="col-status-live">Live</span></td>
                </tr>
                <?php endforeach; ?>
                <tr class="totals-row">
                    <td colspan="2">Total (Active)</td>
                    <td><?= $activeTotal['orders'] ?></td>
                    <td>$<?= number_format($activeTotal['base'], 2) ?></td>
                    <td class="col-fee">$<?= number_format($activeTotal['fee'], 2) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">No active events</div>
        <?php endif; ?>
    </div>

    <!-- Archived Events / Final Reports -->
    <div class="section">
        <div class="section-header">
            <div class="section-title">Past Events</div>
            <div class="section-desc">Final revenue reports for completed events</div>
        </div>
        <?php if (!empty($archivedEvents)): ?>
        <table class="report-table">
            <thead>
                <tr>
                    <th>Event</th>
                    <th>Dates</th>
                    <th>Orders</th>
                    <th>Base Revenue</th>
                    <th>Venue Fee (<?= $venueRatePct ?>%)</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $archivedTotal = ['orders' => 0, 'base' => 0, 'fee' => 0];
                foreach ($archivedEvents as $ev):
                    $evBase = $ev['baseRevenue'] ?? $ev['totalRevenue'] ?? 0;
                    $evFee = $evBase * $venueRate;
                    $archivedTotal['orders'] += $ev['orderCount'] ?? 0;
                    $archivedTotal['base'] += $evBase;
                    $archivedTotal['fee'] += $evFee;
                ?>
                <tr>
                    <td class="col-event-name"><?= htmlspecialchars($ev['name'] ?? $ev['acronym']) ?></td>
                    <td class="col-dates"><?= htmlspecialchars($ev['dates'] ?? '') ?></td>
                    <td><?= $ev['orderCount'] ?? 0 ?></td>
                    <td>$<?= number_format($evBase, 2) ?></td>
                    <td class="col-fee">$<?= number_format($evFee, 2) ?></td>
                    <td><a href="mtcc-statement.php?event=<?= urlencode($ev['acronym'] ?? '') ?>" class="view-btn">View Report</a></td>
                </tr>
                <?php endforeach; ?>
                <tr class="totals-row">
                    <td colspan="2">Total (Archived)</td>
                    <td><?= $archivedTotal['orders'] ?></td>
                    <td>$<?= number_format($archivedTotal['base'], 2) ?></td>
                    <td class="col-fee">$<?= number_format($archivedTotal['fee'], 2) ?></td>
                    <td></td>
                </tr>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state">No archived events yet</div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>
