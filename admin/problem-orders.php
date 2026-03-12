<?php
/**
 * Problem Orders - All Problems Combined
 * Shows both production and dispatch problems
 * Location: /admin/problem-orders.php
 */
require_once '../admin-auth.php';
requireAnyPermission(['preflight_view', 'preflight_edit', 'orders_view', 'orders_edit', 'god_mode']);
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/problem-data.php';

// Macro for section rendering with optional ID for click-to-jump
function prodSection($title, $icon, $countClass, $items, $columns, $rowRenderer, $sectionId = '') {
    if (count($items) === 0) return;
    $idAttr = $sectionId ? ' id="' . $sectionId . '"' : '';
    echo '<div class="prob-section"' . $idAttr . '>';
    echo '<div class="prob-section-header" onclick="toggleSection(this)">';
    echo '<div class="prob-section-left"><div class="prob-section-title">' . $icon . ' ' . $title . ' <span class="prob-section-count ' . $countClass . '">' . count($items) . '</span></div></div>';
    echo '<div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest(\'.prob-section\'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>';
    echo '</div><div class="prob-section-body"><table class="prob-table"><thead><tr>' . sectionHeaderCheckbox() . $columns . '</tr></thead><tbody>';
    foreach ($items as $p) { $rowRenderer($p); }
    echo '</tbody></table></div></div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Problem Orders - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="../css/admin-responsive.css">
    <link rel="stylesheet" href="../css/admin-print.css" media="print">
    <link rel="stylesheet" href="../css/admin-sidebar.css">
    <link rel="stylesheet" href="problem-styles.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('problem_orders_all'); ?>
<script src="../admin-sidebar.js"></script>
<div style="margin: 0 auto!important; padding: 0 20px!important;">

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><?= ICON_WARNING ?> Problem Orders</h1>
        <div class="page-welcome">
            <span class="welcome-text">All production &amp; dispatch issues needing attention</span>
            <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
    </div>
    <div class="page-header-right">
        <div class="prob-header-controls">
            <button id="toggleAllBtn" data-state="expanded" onclick="toggleAllClick()" class="prob-ctrl-btn">&#9662; Collapse All</button>
            <button onclick="exportAll()" class="prob-ctrl-btn">&#128229; Export CSV</button>
            <button onclick="location.reload()" class="prob-ctrl-btn" title="Refresh">&#8635; Refresh</button>
            <?php if ($grandCritical > 0): ?>
            <span class="status-badge status-cancelled" style="font-size:13px;padding:6px 12px;"><?= $grandCritical ?> Critical</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= renderFilterBar($availableEvents, $eventFilter) ?>

<?= renderNewProblemsBanner($newProblemCount) ?>
<?= renderResolutionBar($recentResolutions) ?>

<?php if ($grandTotal === 0): ?>
<div class="prob-all-clear">
    <div class="prob-all-clear-icon">&#9989;</div>
    <h2>All Clear</h2>
    <p>No problems detected<?= $eventFilter ? ' for this event' : '' ?>.</p>
</div>
<?php else: ?>

<div class="prob-stats">
    <div class="prob-stat-card info">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128203;</span><span class="prob-stat-label">Total Issues</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= $grandTotal ?></div>
    </div>
    <div class="prob-stat-card critical clickable" data-section="sec-confirm-critical">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128680;</span><span class="prob-stat-label">Critical</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= $grandCritical ?></div>
    </div>
    <div class="prob-stat-card warning clickable" data-section="sec-prod-divider">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128230;</span><span class="prob-stat-label"><a href="problem-production.php<?= $eventFilter ? '?event=' . urlencode($eventFilter) : '' ?>">Production</a></span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= $prodTotal ?></div>
    </div>
    <div class="prob-stat-card dispatch clickable" data-section="sec-disp-divider">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128666;</span><span class="prob-stat-label"><a href="../dispatch/problem-dispatch.php<?= $eventFilter ? '?event=' . urlencode($eventFilter) : '' ?>">Dispatch</a></span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= $dispatchTotal ?></div>
    </div>
    <?php if (count($prodProblems['payment_pending']) > 0): ?>
    <div class="prob-stat-card warning clickable" data-section="sec-payment-pending">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128176;</span><span class="prob-stat-label">Payment Pending</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['payment_pending']) ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- ========== PRODUCTION ========== -->
<?php if ($prodTotal > 0): ?>
<div class="prob-divider" id="sec-prod-divider">&#128230; Production Issues (<?= $prodTotal ?>)</div>

<?php
// Confirmation Critical
prodSection('Confirmation Critical', '&#128680;', 'critical', $prodProblems['confirmation_critical'],
    '<th>Reference</th><th>Customer</th><th>Vendor</th><th>Tier</th><th>Waiting</th><th>Pushed At</th><th>Reminders</th><th>Due Date</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td>' . htmlspecialchars($p['vendor_name'] ?? 'Unknown') . '</td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['pushed_at_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['pushed_at']) ? date('M j, g:ia', strtotime($p['pushed_at'])) : '&mdash;') . '</td>';
        echo '<td>' . ($p['reminder_count'] ?? 0) . '</td>';
        echo '<td>' . (!empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], true, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-confirm-critical');

// Confirmation Overdue
prodSection('Confirmation Overdue', '&#9203;', 'warning', $prodProblems['confirmation_overdue'],
    '<th>Reference</th><th>Customer</th><th>Vendor</th><th>Tier</th><th>Waiting</th><th>Pushed At</th><th>Reminders</th><th>Due Date</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td>' . htmlspecialchars($p['vendor_name'] ?? 'Unknown') . '</td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['pushed_at_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['pushed_at']) ? date('M j, g:ia', strtotime($p['pushed_at'])) : '&mdash;') . '</td>';
        echo '<td>' . ($p['reminder_count'] ?? 0) . '</td>';
        echo '<td>' . (!empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], true, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-confirm-overdue');

// File Issues
prodSection('File Issues', '&#128196;', 'critical', $prodProblems['file_issues'],
    '<th>Reference</th><th>Customer</th><th>Vendor</th><th>Tier</th><th>Issue Type</th><th>Description</th><th>Open For</th><th>Due Date</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td>' . htmlspecialchars($p['vendor_name'] ?? 'Unknown') . '</td><td>' . tierBadge($p['tier']) . '</td>';
        echo '<td><span class="status-badge status-file_issue">' . htmlspecialchars($p['issue_type'] ?? 'unknown') . '</span></td>';
        echo '<td title="' . htmlspecialchars($p['issue_description'] ?? '') . '">' . htmlspecialchars(substr($p['issue_description'] ?? '', 0, 40)) . '</td>';
        echo timerCell($p['reported_at_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-file-issues');

// Past Due
prodSection('Past Due', '&#128197;', 'critical', $prodProblems['past_due'],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Past Due</th><th>Due Date</th><th>Event</th><th>Material</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td><span class="status-badge status-' . $p['status'] . '">' . htmlspecialchars($p['status']) . '</span></td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['due_date_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td><td>' . htmlspecialchars(ucfirst($p['material'] ?? '')) . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-past-due');

// Payment Pending
prodSection('Payment Pending', '&#128176;', 'warning', $prodProblems['payment_pending'],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Amount</th><th>Unpaid For</th><th>Due Date</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td><span class="status-badge status-' . $p['status'] . '">' . htmlspecialchars($p['status']) . '</span></td><td>' . tierBadge($p['tier']) . '</td>';
        echo '<td><strong>$' . number_format($p['amount_due'] ?? 0, 2) . '</strong></td>';
        echo timerCell($p['created_at_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-payment-pending');

// Uncollected
prodSection('Uncollected Orders', '&#128232;', 'warning', $prodProblems['uncollected'],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Uncollected</th><th>Event Ended</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td><span class="status-badge status-ready">' . htmlspecialchars($p['status']) . '</span></td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['event_end_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['event_end_date']) ? date('M j', strtotime($p['event_end_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-uncollected');

// Stuck
prodSection('Stuck in Status', '&#128256;', '', $prodProblems['stuck_status'],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Stuck For</th><th>Last Change</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td><span class="status-badge status-' . $p['status'] . '">' . htmlspecialchars($p['status']) . '</span></td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['last_change_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['last_change']) ? date('M j, g:ia', strtotime($p['last_change'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-stuck');
?>
<?php endif; ?>

<!-- ========== DISPATCH ========== -->
<?php if ($dispatchTotal > 0): ?>
<div class="prob-divider" id="sec-disp-divider">&#128666; Dispatch Issues (<?= $dispatchTotal ?>)</div>

<?php
// Ready & Stale
prodSection('Ready &amp; Stale', '&#9203;', 'warning', $dispatchProblems['ready_stale'] ?? [],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Waiting</th><th>Due Date</th><th>Event</th><th>Actions</th>',
    function($d) {
        echo '<tr class="' . (($d['severity'] ?? '') === 'critical' ? 'row-critical' : 'row-warning') . ' ' . escalationClass($d) . '">' . checkboxCell($d['ref'] ?? '');
        echo '<td><a href="../admin-orders.php?search=' . urlencode($d['ref'] ?? '') . '" class="ref-link">' . htmlspecialchars($d['ref'] ?? 'Unknown') . '</a>' . newBadge($d) . '</td>';
        echo '<td>' . htmlspecialchars($d['customer'] ?? 'Unknown') . '</td><td><span class="status-badge status-' . ($d['status'] ?? 'ready') . '">' . htmlspecialchars($d['status'] ?? 'ready') . '</span></td><td>' . tierBadge($d['tier'] ?? 'standard') . '</td>';
        echo timerCell($d['ready_since_ts'] ?? 0, $d);
        echo '<td>' . (!empty($d['due_date']) ? date('M j, g:ia', strtotime($d['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($d['event_name'] ?? '') . '</td>' . actionButtons($d['ref'] ?? '', false, $d['note_count'] ?? 0) . '</tr>';
    }
, 'sec-ready-stale');

// Delivery Overdue
prodSection('Delivery Overdue', '&#128680;', 'critical', $dispatchProblems['delivery_overdue'] ?? [],
    '<th>Reference</th><th>Customer</th><th>Tier</th><th>Overdue</th><th>Deadline</th><th>Courier</th><th>Event</th><th>Actions</th>',
    function($d) {
        echo '<tr class="' . (($d['severity'] ?? '') === 'critical' ? 'row-critical' : 'row-warning') . ' ' . escalationClass($d) . '">' . checkboxCell($d['ref'] ?? '');
        echo '<td><a href="../admin-orders.php?search=' . urlencode($d['ref'] ?? '') . '" class="ref-link">' . htmlspecialchars($d['ref'] ?? 'Unknown') . '</a>' . newBadge($d) . '</td>';
        echo '<td>' . htmlspecialchars($d['customer'] ?? 'Unknown') . '</td><td>' . tierBadge($d['tier'] ?? 'standard') . '</td>';
        echo timerCell($d['deadline_ts'] ?? 0, $d);
        echo '<td>' . (!empty($d['due_date']) ? date('M j, g:ia', strtotime($d['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($d['courier'] ?? 'Unassigned') . '</td>';
        echo '<td>' . htmlspecialchars($d['event_name'] ?? '') . '</td>' . actionButtons($d['ref'] ?? '', false, $d['note_count'] ?? 0) . '</tr>';
    }
, 'sec-delivery-overdue');

// Unresolved Issues
prodSection('Unresolved Issues', '&#9888;', 'critical', $dispatchProblems['unresolved_issues'] ?? [],
    '<th>Reference</th><th>Customer</th><th>Tier</th><th>Issue Type</th><th>Severity</th><th>Open For</th><th>Reported By</th><th>Reported At</th><th>Actions</th>',
    function($d) {
        echo '<tr class="' . (($d['severity'] ?? '') === 'critical' ? 'row-critical' : 'row-warning') . ' ' . escalationClass($d) . '">' . checkboxCell($d['ref'] ?? '');
        echo '<td><a href="../admin-orders.php?search=' . urlencode($d['ref'] ?? '') . '" class="ref-link">' . htmlspecialchars($d['ref'] ?? 'Unknown') . '</a>' . newBadge($d) . '</td>';
        echo '<td>' . htmlspecialchars($d['customer'] ?? 'Unknown') . '</td><td>' . tierBadge($d['tier'] ?? 'standard') . '</td>';
        echo '<td><span class="status-badge status-file_issue">' . htmlspecialchars($d['type'] ?? 'unknown') . '</span></td>';
        echo '<td>' . severityBadge($d['severity'] ?? 'warning') . '</td>';
        echo timerCell($d['reported_at_ts'] ?? 0, $d);
        echo '<td>' . htmlspecialchars($d['reported_by'] ?? 'Unknown') . '</td>';
        echo '<td>' . (!empty($d['reported_at']) ? date('M j, g:ia', strtotime($d['reported_at'])) : '&mdash;') . '</td>' . actionButtons($d['ref'] ?? '', false, $d['note_count'] ?? 0) . '</tr>';
    }
, 'sec-unresolved');

// Past Due (Dispatch)
prodSection('Past Due (Dispatch)', '&#128197;', 'critical', $dispatchProblems['past_due'] ?? [],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Past Due</th><th>Due Date</th><th>Event</th><th>Material</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td><span class="status-badge status-' . $p['status'] . '">' . htmlspecialchars($p['status']) . '</span></td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['due_date_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td><td>' . htmlspecialchars(ucfirst($p['material'] ?? '')) . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-dispatch-past-due');

// Stuck (Dispatch)
prodSection('Stuck in Status (Dispatch)', '&#128256;', '', $dispatchProblems['stuck_status'] ?? [],
    '<th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Stuck For</th><th>Last Change</th><th>Event</th><th>Actions</th>',
    function($p) {
        echo '<tr class="' . rowSeverityClass($p) . ' ' . escalationClass($p) . '">' . checkboxCell($p['reference_code']);
        echo '<td><a href="../admin-orders.php?search=' . urlencode($p['reference_code']) . '" class="ref-link">' . htmlspecialchars($p['reference_code']) . '</a>' . newBadge($p) . '</td>';
        echo '<td>' . htmlspecialchars($p['customer_name']) . '</td><td><span class="status-badge status-' . $p['status'] . '">' . htmlspecialchars($p['status']) . '</span></td><td>' . tierBadge($p['tier']) . '</td>';
        echo timerCell($p['last_change_ts'] ?? 0, $p);
        echo '<td>' . (!empty($p['last_change']) ? date('M j, g:ia', strtotime($p['last_change'])) : '&mdash;') . '</td>';
        echo '<td>' . htmlspecialchars($p['event_name'] ?? '') . '</td>' . actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) . '</tr>';
    }
, 'sec-dispatch-stuck');
?>
<?php endif; ?>

<?php endif; /* grandTotal === 0 */ ?>

<?= renderBulkToolbar(true) ?>

</div>
<script src="problem-js.js"></script>
</body>
</html>
