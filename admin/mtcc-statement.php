<?php
/**
 * MTCC Revenue Report
 * Formal per-event report showing base revenue and venue fee calculation.
 * MTCC uses this to issue their invoice to Print Stuff.
 *
 * Usage: admin/mtcc-statement.php?event=COMIC
 * Location: /admin/mtcc-statement.php
 */

require_once __DIR__ . '/../admin-auth.php';
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/site-settings.php';
require_once __DIR__ . '/../includes/status-config.php';

if (!hasPermission('mtcc_analytics') && !hasPermission('dashboard_analytics')) {
    requireAnyPermission(['mtcc_analytics', 'dashboard_analytics']);
}

$eventAcronym = strtoupper(trim($_GET['event'] ?? ''));
if (!$eventAcronym) {
    die('Missing event parameter. Usage: ?event=COMIC');
}

// Load event data
$eventsFile = __DIR__ . '/events.json';
$eventsData = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
$allEvents = array_merge($eventsData['active'] ?? [], $eventsData['archived'] ?? []);

$eventInfo = null;
foreach ($allEvents as $ev) {
    if (strtoupper($ev['acronym'] ?? '') === $eventAcronym) { $eventInfo = $ev; break; }
}
if (!$eventInfo) die('Event not found: ' . htmlspecialchars($eventAcronym));

$settings = getSiteSettings();
$feeRate = $settings['mtcc_venue_fee_rate'] ?? 0.10;
$feeRatePct = round($feeRate * 100);

// Load orders for this event
$orderDir = __DIR__ . '/../uploads/orders/';
$statusFile = __DIR__ . '/../data/statuses.json';
$statuses = file_exists($statusFile) ? (json_decode(file_get_contents($statusFile), true) ?: []) : [];

$revenueStatuses = ['paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup'];

$orders = [];
$totalBase = 0; $totalDelivery = 0; $totalTax = 0; $grossTotal = 0;

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

        $base = $order['pricing']['basePrice'] ?? 0;
        $delivery = $order['pricing']['deliveryFee'] ?? 0;
        $tax = $order['pricing']['tax'] ?? 0;
        $total = $order['pricing']['total'] ?? 0;

        $orders[] = [
            'ref' => $ref,
            'customer' => $order['customerInfo']['name'] ?? 'Unknown',
            'date' => $order['selectedDate'] ?? '',
            'base' => $base,
            'total' => $total,
        ];

        $totalBase += $base;
        $totalDelivery += $delivery;
        $totalTax += $tax;
        $grossTotal += $total;
    }
}

usort($orders, function($a, $b) { return strcmp($a['ref'], $b['ref']); });

$venueFeeTotal = $totalBase * $feeRate;
$reportId = 'RPT-' . $eventAcronym . '-' . date('Y');
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
    <title>Revenue Report - <?= htmlspecialchars($eventAcronym) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f3f4f6;
            color: #374151;
            padding: 20px;
        }
        .report {
            max-width: 800px;
            margin: 0 auto;
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(124,58,237,0.12);
            overflow: hidden;
        }

        /* Header */
        .rpt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 32px 40px;
            border-bottom: 3px solid #7c3aed;
        }
        .rpt-logo img { max-width: 200px; height: auto; }
        .rpt-title-block { text-align: right; }
        .rpt-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #7c3aed;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .rpt-id { font-size: 0.8rem; color: #6b7280; margin-top: 4px; }
        .rpt-date { font-size: 0.78rem; color: #9ca3af; margin-top: 2px; }

        /* Event details */
        .rpt-event {
            padding: 24px 40px;
            background: #faf8ff;
            border-bottom: 1px solid #e5e7eb;
        }
        .rpt-event-grid {
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 6px 16px;
            font-size: 0.88rem;
        }
        .rpt-label { color: #6b7280; font-weight: 500; }
        .rpt-value { color: #1e1b2e; font-weight: 600; }

        /* Summary cards */
        .rpt-summary { padding: 28px 40px; border-bottom: 1px solid #e5e7eb; }
        .rpt-section-title {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.5px; color: #6b7280; margin-bottom: 16px;
        }
        .rpt-summary-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
        }
        .rpt-summary-card {
            text-align: center; padding: 16px; border-radius: 10px;
            background: #f8fafc; border: 1px solid #e5e7eb;
        }
        .rpt-summary-card.highlight {
            background: linear-gradient(135deg, #faf5ff, #ffffff);
            border-color: #7c3aed;
        }
        .rpt-summary-val { font-size: 1.4rem; font-weight: 700; color: #1e1b2e; }
        .rpt-summary-card.highlight .rpt-summary-val { color: #7c3aed; }
        .rpt-summary-lbl {
            font-size: 0.7rem; color: #6b7280; text-transform: uppercase;
            letter-spacing: 0.3px; margin-top: 4px;
        }

        /* Revenue breakdown */
        .rpt-breakdown { padding: 24px 40px; border-bottom: 1px solid #e5e7eb; }
        .rpt-breakdown-grid {
            display: grid; grid-template-columns: 1fr auto; gap: 0;
            font-size: 0.88rem; max-width: 400px;
        }
        .rpt-bk-label { padding: 6px 0; color: #6b7280; }
        .rpt-bk-value { padding: 6px 0; text-align: right; color: #1e1b2e; font-weight: 600; }
        .rpt-bk-total { border-top: 2px solid #e5e7eb; padding-top: 10px; margin-top: 4px; }
        .rpt-bk-fee {
            border-top: 2px solid #7c3aed; padding-top: 10px; margin-top: 10px;
        }
        .rpt-bk-fee .rpt-bk-label { color: #7c3aed; font-weight: 700; }
        .rpt-bk-fee .rpt-bk-value { color: #7c3aed; font-weight: 700; font-size: 1.1rem; }

        /* Order table */
        .rpt-orders { padding: 28px 40px; }
        .rpt-table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
        .rpt-table th {
            text-align: left; padding: 8px 12px; font-size: 0.7rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.3px; color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
        }
        .rpt-table th:nth-child(3), .rpt-table td:nth-child(3),
        .rpt-table th:nth-child(4), .rpt-table td:nth-child(4) { text-align: right; }
        .rpt-table td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; color: #374151; }
        .rpt-table tr:last-child td { border-bottom: none; }
        .rpt-table .ref-cell { font-weight: 600; color: #7c3aed; }

        /* Footer */
        .rpt-footer {
            padding: 20px 40px; background: #fafbfc; border-top: 1px solid #f3f4f6;
            font-size: 0.75rem; color: #9ca3af;
        }
        .rpt-footer-note {
            font-size: 0.78rem; color: #6b7280; margin-bottom: 12px;
            padding: 12px 16px; background: #faf8ff; border-radius: 8px;
            border-left: 3px solid #7c3aed;
        }

        /* Actions bar */
        .rpt-actions {
            max-width: 800px; margin: 16px auto; display: flex; gap: 8px; justify-content: flex-end;
        }
        .rpt-btn {
            padding: 10px 20px; border: none; border-radius: 10px; font-family: inherit;
            font-size: 0.85rem; font-weight: 600; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .rpt-btn-primary { background: #7c3aed; color: white; box-shadow: 0 2px 8px rgba(124,58,237,0.25); }
        .rpt-btn-secondary { background: #f3f4f6; color: #374151; }
        .rpt-btn:hover { opacity: 0.9; }

        @media print {
            body { background: white; padding: 0; }
            .report { box-shadow: none; border-radius: 0; }
            .rpt-actions { display: none; }
        }
        @media (max-width: 600px) {
            .rpt-header { flex-direction: column; gap: 16px; padding: 24px 20px; }
            .rpt-title-block { text-align: left; }
            .rpt-event, .rpt-summary, .rpt-breakdown, .rpt-orders, .rpt-footer { padding-left: 20px; padding-right: 20px; }
            .rpt-summary-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="rpt-actions">
    <a href="mtcc-reports.php" class="rpt-btn rpt-btn-secondary">&larr; Back to Reports</a>
    <button class="rpt-btn rpt-btn-secondary" onclick="window.print()" title="Print this report">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print
    </button>
    <button class="rpt-btn rpt-btn-primary" onclick="downloadPDF()" title="Save as PDF">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Download PDF
    </button>
</div>

<script>
function downloadPDF() {
    // Most browsers let users save the print output as PDF via the print dialog's destination selector.
    // We trigger print and briefly set a helper hint for first-time users.
    window.print();
}
</script>

<div class="report">

    <div class="rpt-header">
        <div class="rpt-logo"><img src="../mtcc-ps-logo.png" alt="MTCC + Print Stuff"></div>
        <div class="rpt-title-block">
            <div class="rpt-title">Revenue Report</div>
            <div class="rpt-id"><?= htmlspecialchars($reportId) ?></div>
            <div class="rpt-date">Generated: <?= $generatedAt ?></div>
        </div>
    </div>

    <div class="rpt-event">
        <div class="rpt-event-grid">
            <span class="rpt-label">Event</span>
            <span class="rpt-value"><?= htmlspecialchars($eventInfo['name'] ?? $eventAcronym) ?></span>
            <span class="rpt-label">Dates</span>
            <span class="rpt-value"><?= htmlspecialchars($eventInfo['dates'] ?? 'N/A') ?></span>
            <span class="rpt-label">Building</span>
            <span class="rpt-value"><?= htmlspecialchars($buildingName) ?></span>
            <span class="rpt-label">Fee Rate</span>
            <span class="rpt-value"><?= $feeRatePct ?>% of base revenue</span>
        </div>
    </div>

    <!-- Summary -->
    <div class="rpt-summary">
        <div class="rpt-section-title">Summary</div>
        <div class="rpt-summary-grid">
            <div class="rpt-summary-card">
                <div class="rpt-summary-val"><?= count($orders) ?></div>
                <div class="rpt-summary-lbl">Total Orders</div>
            </div>
            <div class="rpt-summary-card">
                <div class="rpt-summary-val">$<?= number_format($totalBase, 2) ?></div>
                <div class="rpt-summary-lbl">Base Revenue</div>
            </div>
            <div class="rpt-summary-card highlight">
                <div class="rpt-summary-val">$<?= number_format($venueFeeTotal, 2) ?></div>
                <div class="rpt-summary-lbl">Venue Fee Due</div>
            </div>
        </div>
    </div>

    <!-- Revenue Breakdown -->
    <div class="rpt-breakdown">
        <div class="rpt-section-title">Revenue Breakdown</div>
        <div class="rpt-breakdown-grid">
            <span class="rpt-bk-label">Base Revenue (print services)</span>
            <span class="rpt-bk-value">$<?= number_format($totalBase, 2) ?></span>
            <span class="rpt-bk-label">Delivery Fees</span>
            <span class="rpt-bk-value">$<?= number_format($totalDelivery, 2) ?></span>
            <span class="rpt-bk-label">HST (13%)</span>
            <span class="rpt-bk-value">$<?= number_format($totalTax, 2) ?></span>
            <span class="rpt-bk-label rpt-bk-total">Gross Collected</span>
            <span class="rpt-bk-value rpt-bk-total">$<?= number_format($grossTotal, 2) ?></span>
            <span class="rpt-bk-label rpt-bk-fee">Venue Fee (<?= $feeRatePct ?>% of base)</span>
            <span class="rpt-bk-value rpt-bk-fee">$<?= number_format($venueFeeTotal, 2) ?></span>
        </div>
    </div>

    <!-- Order Detail -->
    <div class="rpt-orders">
        <div class="rpt-section-title">Order Detail</div>
        <table class="rpt-table">
            <thead>
                <tr><th>Ref</th><th>Customer</th><th>Base Price</th><th>Venue Fee</th></tr>
            </thead>
            <tbody>
                <?php foreach ($orders as $o): ?>
                <tr>
                    <td class="ref-cell"><?= htmlspecialchars($o['ref']) ?></td>
                    <td><?= htmlspecialchars($o['customer']) ?></td>
                    <td>$<?= number_format($o['base'], 2) ?></td>
                    <td>$<?= number_format($o['base'] * $feeRate, 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($orders)): ?>
                <tr><td colspan="4" style="text-align: center; color: #9ca3af; padding: 24px;">No orders found for this event.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Footer -->
    <div class="rpt-footer">
        <div class="rpt-footer-note">
            This report is provided for invoicing purposes. Please issue your invoice referencing report <strong><?= htmlspecialchars($reportId) ?></strong>.
        </div>
        <div style="text-align: center;">
            &copy; <?= $currentYear ?> Print Stuff &middot; Metro Toronto Convention Centre &middot; Big or small, we print it all.
        </div>
    </div>

</div>

</body>
</html>
