<?php
/**
 * MTCC Commission Statement
 * Generates a formal per-event commission/venue fee report.
 * Printable via print stylesheet, accessible from MTCC dashboard.
 *
 * Usage: admin/mtcc-statement.php?event=COMIC
 * Location: /admin/mtcc-statement.php
 */

require_once __DIR__ . '/../admin-auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/site-settings.php';
require_once __DIR__ . '/../includes/status-config.php';

// Require MTCC analytics permission or god_mode/super_admin
if (!hasPermission('mtcc_analytics') && !hasPermission('dashboard_analytics')) {
    requireAnyPermission(['mtcc_analytics', 'dashboard_analytics']);
}

$eventAcronym = strtoupper(trim($_GET['event'] ?? ''));
if (!$eventAcronym) {
    die('Missing event parameter. Usage: ?event=COMIC');
}

// Load event data from both active and archived
$eventsFile = __DIR__ . '/events.json';
$eventsData = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
$allEvents = array_merge($eventsData['active'] ?? [], $eventsData['archived'] ?? []);

$eventInfo = null;
foreach ($allEvents as $ev) {
    if (strtoupper($ev['acronym'] ?? '') === $eventAcronym) {
        $eventInfo = $ev;
        break;
    }
}

if (!$eventInfo) {
    die('Event not found: ' . htmlspecialchars($eventAcronym));
}

// Load site settings for commission rate
$settings = getSiteSettings();
$commRate = $settings['mtcc_commission_rate'] ?? 0.10;
$commRatePct = round($commRate * 100);

// Load all orders for this event
$orderDir = __DIR__ . '/../uploads/orders/';
$statusFile = __DIR__ . '/../data/statuses.json';
$statuses = file_exists($statusFile) ? (json_decode(file_get_contents($statusFile), true) ?: []) : [];

$revenueStatuses = ['paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];

$orders = [];
$totalRevenue = 0;
$totalCommission = 0;

if (is_dir($orderDir)) {
    $files = glob($orderDir . '*.json');
    foreach ($files as $file) {
        if (strpos($file, '_history.json') !== false) continue;
        $order = json_decode(file_get_contents($file), true);
        if (!$order) continue;

        $ref = $order['referenceCode'] ?? '';
        $prefix = strtoupper(explode('-', $ref)[0]);
        if ($prefix !== $eventAcronym) continue;

        $status = $statuses[$ref] ?? ($order['status'] ?? 'unpaid');
        if (!in_array($status, $revenueStatuses)) continue;

        $total = $order['pricing']['total'] ?? 0;
        $commission = $total * $commRate;

        $orders[] = [
            'ref' => $ref,
            'customer' => $order['customerInfo']['name'] ?? 'Unknown',
            'date' => $order['selectedDate'] ?? '',
            'submitted' => $order['submittedAt'] ?? '',
            'total' => $total,
            'commission' => $commission,
            'status' => $status,
        ];

        $totalRevenue += $total;
        $totalCommission += $commission;
    }
}

// Sort by reference code
usort($orders, function($a, $b) { return strcmp($a['ref'], $b['ref']); });

$statementId = 'STMT-' . $eventAcronym . '-' . date('Y');
$generatedAt = date('F j, Y \a\t g:i A');
$building = $eventInfo['building'] ?? 'north';
$buildingName = ($building === 'south') ? 'MTCC South Building' : 'MTCC North Building';
$currentYear = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commission Statement - <?= htmlspecialchars($eventAcronym) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f3f4f6;
            color: #374151;
            padding: 20px;
        }

        .statement {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(124,58,237,0.12);
            overflow: hidden;
        }

        /* Header */
        .stmt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 32px 40px;
            border-bottom: 3px solid #7c3aed;
        }
        .stmt-logo img {
            max-width: 200px;
            height: auto;
        }
        .stmt-title-block { text-align: right; }
        .stmt-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #7c3aed;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .stmt-id {
            font-size: 0.8rem;
            color: #6b7280;
            margin-top: 4px;
        }
        .stmt-date {
            font-size: 0.78rem;
            color: #9ca3af;
            margin-top: 2px;
        }

        /* Event details */
        .stmt-event {
            padding: 24px 40px;
            background: #faf8ff;
            border-bottom: 1px solid #e5e7eb;
        }
        .stmt-event-grid {
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 6px 16px;
            font-size: 0.88rem;
        }
        .stmt-event-label { color: #6b7280; font-weight: 500; }
        .stmt-event-value { color: #1e1b2e; font-weight: 600; }

        /* Summary box */
        .stmt-summary {
            padding: 28px 40px;
            border-bottom: 1px solid #e5e7eb;
        }
        .stmt-section-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 16px;
        }
        .stmt-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        .stmt-summary-card {
            text-align: center;
            padding: 16px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }
        .stmt-summary-card.highlight {
            background: linear-gradient(135deg, #faf5ff, #ffffff);
            border-color: #7c3aed;
        }
        .stmt-summary-value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e1b2e;
        }
        .stmt-summary-card.highlight .stmt-summary-value {
            color: #7c3aed;
        }
        .stmt-summary-label {
            font-size: 0.7rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 4px;
        }

        /* Order table */
        .stmt-orders {
            padding: 28px 40px;
        }
        .stmt-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        .stmt-table th {
            text-align: left;
            padding: 8px 12px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
        }
        .stmt-table th:last-child,
        .stmt-table td:last-child,
        .stmt-table th:nth-child(3),
        .stmt-table td:nth-child(3) { text-align: right; }
        .stmt-table td {
            padding: 8px 12px;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
        }
        .stmt-table tr:last-child td { border-bottom: none; }
        .stmt-table .ref-cell { font-weight: 600; color: #7c3aed; }

        /* Totals row */
        .stmt-totals {
            padding: 0 40px 28px;
        }
        .stmt-totals-row {
            display: flex;
            justify-content: flex-end;
            gap: 40px;
            padding: 12px 12px;
            border-top: 2px solid #e5e7eb;
            font-size: 0.88rem;
        }
        .stmt-totals-label { color: #6b7280; font-weight: 600; }
        .stmt-totals-value { font-weight: 700; color: #1e1b2e; min-width: 100px; text-align: right; }
        .stmt-totals-value.commission { color: #7c3aed; }

        /* Footer */
        .stmt-footer {
            padding: 20px 40px;
            background: #fafbfc;
            border-top: 1px solid #f3f4f6;
            text-align: center;
            color: #9ca3af;
            font-size: 0.75rem;
        }

        /* Actions bar (screen only) */
        .stmt-actions {
            max-width: 800px;
            margin: 16px auto;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .stmt-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .stmt-btn-primary {
            background: #7c3aed;
            color: white;
            box-shadow: 0 2px 8px rgba(124,58,237,0.25);
        }
        .stmt-btn-secondary {
            background: #f3f4f6;
            color: #374151;
        }
        .stmt-btn:hover { opacity: 0.9; }

        /* Print styles */
        @media print {
            body { background: white; padding: 0; }
            .statement { box-shadow: none; border-radius: 0; }
            .stmt-actions { display: none; }
            .stmt-header { border-bottom-width: 2px; }
        }

        /* Responsive */
        @media (max-width: 600px) {
            .stmt-header { flex-direction: column; gap: 16px; padding: 24px 20px; }
            .stmt-title-block { text-align: left; }
            .stmt-event, .stmt-summary, .stmt-orders, .stmt-totals { padding-left: 20px; padding-right: 20px; }
            .stmt-summary-grid { grid-template-columns: repeat(2, 1fr); }
            .stmt-table { font-size: 0.75rem; }
            .stmt-table th, .stmt-table td { padding: 6px 8px; }
        }
    </style>
</head>
<body>

<!-- Actions bar (not printed) -->
<div class="stmt-actions">
    <a href="admin-orders.php" class="stmt-btn stmt-secondary"><?= ICON_ARROW_LEFT ?? '&larr;' ?> Back to Dashboard</a>
    <button class="stmt-btn stmt-btn-primary" onclick="window.print()"><?= ICON_PRINTER ?? '' ?> Print Statement</button>
</div>

<div class="statement">

    <!-- Header -->
    <div class="stmt-header">
        <div class="stmt-logo">
            <img src="../mtcc-ps-logo.png" alt="MTCC + Print Stuff">
        </div>
        <div class="stmt-title-block">
            <div class="stmt-title">Commission Statement</div>
            <div class="stmt-id"><?= htmlspecialchars($statementId) ?></div>
            <div class="stmt-date">Generated: <?= $generatedAt ?></div>
        </div>
    </div>

    <!-- Event Details -->
    <div class="stmt-event">
        <div class="stmt-event-grid">
            <span class="stmt-event-label">Event</span>
            <span class="stmt-event-value"><?= htmlspecialchars($eventInfo['name'] ?? $eventAcronym) ?></span>
            <span class="stmt-event-label">Dates</span>
            <span class="stmt-event-value"><?= htmlspecialchars($eventInfo['dates'] ?? 'N/A') ?></span>
            <span class="stmt-event-label">Building</span>
            <span class="stmt-event-value"><?= htmlspecialchars($buildingName) ?></span>
            <span class="stmt-event-label">Rate</span>
            <span class="stmt-event-value"><?= $commRatePct ?>% of gross revenue</span>
        </div>
    </div>

    <!-- Summary -->
    <div class="stmt-summary">
        <div class="stmt-section-title">Summary</div>
        <div class="stmt-summary-grid">
            <div class="stmt-summary-card">
                <div class="stmt-summary-value"><?= count($orders) ?></div>
                <div class="stmt-summary-label">Total Orders</div>
            </div>
            <div class="stmt-summary-card">
                <div class="stmt-summary-value">$<?= number_format($totalRevenue, 2) ?></div>
                <div class="stmt-summary-label">Gross Revenue</div>
            </div>
            <div class="stmt-summary-card">
                <div class="stmt-summary-value"><?= $commRatePct ?>%</div>
                <div class="stmt-summary-label">Commission Rate</div>
            </div>
            <div class="stmt-summary-card highlight">
                <div class="stmt-summary-value">$<?= number_format($totalCommission, 2) ?></div>
                <div class="stmt-summary-label">Commission Due</div>
            </div>
        </div>
    </div>

    <!-- Order Breakdown -->
    <div class="stmt-orders">
        <div class="stmt-section-title">Order Breakdown</div>
        <table class="stmt-table">
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Customer</th>
                    <th>Order Total</th>
                    <th>Commission</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="ref-cell"><?= htmlspecialchars($o['ref']) ?></td>
                    <td><?= htmlspecialchars($o['customer']) ?></td>
                    <td>$<?= number_format($o['total'], 2) ?></td>
                    <td>$<?= number_format($o['commission'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="4" style="text-align: center; color: #9ca3af; padding: 24px;">No orders found for this event.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Totals -->
    <?php if (!empty($orders)): ?>
    <div class="stmt-totals">
        <div class="stmt-totals-row">
            <span class="stmt-totals-label">Gross Revenue</span>
            <span class="stmt-totals-value">$<?= number_format($totalRevenue, 2) ?></span>
        </div>
        <div class="stmt-totals-row">
            <span class="stmt-totals-label">Commission (<?= $commRatePct ?>%)</span>
            <span class="stmt-totals-value commission">$<?= number_format($totalCommission, 2) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Footer -->
    <div class="stmt-footer">
        &copy; <?= $currentYear ?> Print Stuff &middot; Metro Toronto Convention Centre &middot; Big or small, we print it all.
    </div>

</div>

</body>
</html>
