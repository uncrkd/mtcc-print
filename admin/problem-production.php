<?php
/**
 * Production Problems - MTCC Print Services
 * Production-only problem orders
 * Location: /admin/problem-production.php
 */
require_once '../admin-auth.php';
requireAnyPermission(['preflight_view', 'preflight_edit', 'orders_view', 'orders_edit', 'god_mode']);
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/problem-data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Problems - MTCC Print Services</title>
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
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('problem_orders'); ?>
<script src="../js/admin-sidebar.js"></script>
<div style="margin: 0 auto!important; padding: 0 20px!important;">

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><?= ICON_WARNING ?> Production Problems</h1>
        <div class="page-welcome">
            <span class="welcome-text">Vendor confirmation, file issues &amp; overdue orders</span>
            <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
    </div>
    <div class="page-header-right">
        <div class="prob-header-controls">
            <a href="problem-orders.php<?= $eventFilter ? '?event=' . urlencode($eventFilter) : '' ?>" class="prob-ctrl-btn"><?= ICON_WARNING ?> All Problems</a>
            <button id="toggleAllBtn" data-state="expanded" onclick="toggleAllClick()" class="prob-ctrl-btn">&#9662; Collapse All</button>
            <button onclick="exportAll()" class="prob-ctrl-btn">&#128229; Export CSV</button>
            <button onclick="location.reload()" class="prob-ctrl-btn" title="Refresh">&#8635; Refresh</button>
            <?php if ($prodCritical > 0): ?>
            <span class="status-badge status-cancelled" style="font-size:13px;padding:6px 12px;"><?= $prodCritical ?> Critical</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= renderFilterBar($availableEvents, $eventFilter) ?>

<?= renderNewProblemsBanner($newProblemCount) ?>
<?= renderResolutionBar($recentResolutions) ?>

<?php if ($prodTotal === 0): ?>
<div class="prob-all-clear">
    <div class="prob-all-clear-icon">&#9989;</div>
    <h2>Production All Clear</h2>
    <p>No production problems detected<?= $eventFilter ? ' for this event' : '' ?>.</p>
</div>
<?php else: ?>

<div class="prob-stats">
    <div class="prob-stat-card info">
        <div class="prob-stat-left"><span class="prob-stat-icon"><?= ICON_PACKAGE ?></span><span class="prob-stat-label">Total Production</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= $prodTotal ?></div>
    </div>
    <div class="prob-stat-card critical clickable" data-section="sec-confirm-critical">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128680;</span><span class="prob-stat-label">Critical Confirm.</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['confirmation_critical']) ?></div>
    </div>
    <div class="prob-stat-card warning clickable" data-section="sec-confirm-overdue">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#9203;</span><span class="prob-stat-label">Overdue Confirm.</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['confirmation_overdue']) ?></div>
    </div>
    <div class="prob-stat-card critical clickable" data-section="sec-file-issues">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128196;</span><span class="prob-stat-label">File Issues</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['file_issues']) ?></div>
    </div>
    <div class="prob-stat-card warning clickable" data-section="sec-past-due">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128197;</span><span class="prob-stat-label">Past Due</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['past_due']) ?></div>
    </div>
    <div class="prob-stat-card info clickable" data-section="sec-uncollected">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128232;</span><span class="prob-stat-label">Uncollected / Stuck</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['uncollected']) + count($prodProblems['stuck_status']) ?></div>
    </div>
    <?php if (count($prodProblems['payment_pending']) > 0): ?>
    <div class="prob-stat-card warning clickable" data-section="sec-payment-pending">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128176;</span><span class="prob-stat-label">Payment Pending</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($prodProblems['payment_pending']) ?></div>
    </div>
    <?php endif; ?>
</div>

<?php
// --- CONFIRMATION CRITICAL ---
if (count($prodProblems['confirmation_critical']) > 0): ?>
<div class="prob-section" id="sec-confirm-critical">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128680; Confirmation Critical <span class="prob-section-count critical"><?= count($prodProblems['confirmation_critical']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Vendor</th><th>Tier</th><th>Waiting</th><th>Pushed At</th><th>Reminders</th><th>Due Date</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['confirmation_critical'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><?= htmlspecialchars($p['vendor_name'] ?? 'Unknown') ?></td><td><?= tierBadge($p['tier']) ?></td>
            <?= timerCell($p['pushed_at_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['pushed_at']) ? date('M j, g:ia', strtotime($p['pushed_at'])) : '&mdash;' ?></td>
            <td><?= $p['reminder_count'] ?? 0 ?></td>
            <td><?= !empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><?= actionButtons($p['reference_code'], true, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- CONFIRMATION OVERDUE ---
if (count($prodProblems['confirmation_overdue']) > 0): ?>
<div class="prob-section" id="sec-confirm-overdue">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#9203; Confirmation Overdue <span class="prob-section-count warning"><?= count($prodProblems['confirmation_overdue']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Vendor</th><th>Tier</th><th>Waiting</th><th>Pushed At</th><th>Reminders</th><th>Due Date</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['confirmation_overdue'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><?= htmlspecialchars($p['vendor_name'] ?? 'Unknown') ?></td><td><?= tierBadge($p['tier']) ?></td>
            <?= timerCell($p['pushed_at_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['pushed_at']) ? date('M j, g:ia', strtotime($p['pushed_at'])) : '&mdash;' ?></td>
            <td><?= $p['reminder_count'] ?? 0 ?></td>
            <td><?= !empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><?= actionButtons($p['reference_code'], true, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- FILE ISSUES ---
if (count($prodProblems['file_issues']) > 0): ?>
<div class="prob-section" id="sec-file-issues">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128196; File Issues <span class="prob-section-count critical"><?= count($prodProblems['file_issues']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Vendor</th><th>Tier</th><th>Issue Type</th><th>Description</th><th>Open For</th><th>Due Date</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['file_issues'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><?= htmlspecialchars($p['vendor_name'] ?? 'Unknown') ?></td><td><?= tierBadge($p['tier']) ?></td>
            <td><span class="status-badge status-file_issue"><?= htmlspecialchars($p['issue_type'] ?? 'unknown') ?></span></td>
            <td title="<?= htmlspecialchars($p['issue_description'] ?? '') ?>"><?= htmlspecialchars(substr($p['issue_description'] ?? '', 0, 40)) ?></td>
            <?= timerCell($p['reported_at_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><?= actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- PAST DUE ---
if (count($prodProblems['past_due']) > 0): ?>
<div class="prob-section" id="sec-past-due">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128197; Past Due <span class="prob-section-count critical"><?= count($prodProblems['past_due']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Past Due</th><th>Due Date</th><th>Event</th><th>Material</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['past_due'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><span class="status-badge status-<?= $p['status'] ?>"><?= htmlspecialchars($p['status']) ?></span></td><td><?= tierBadge($p['tier']) ?></td>
            <?= timerCell($p['due_date_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><td><?= htmlspecialchars(ucfirst($p['material'] ?? '')) ?></td><?= actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- PAYMENT PENDING ---
if (count($prodProblems['payment_pending']) > 0): ?>
<div class="prob-section" id="sec-payment-pending">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128176; Payment Pending <span class="prob-section-count warning"><?= count($prodProblems['payment_pending']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Amount</th><th>Unpaid For</th><th>Due Date</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['payment_pending'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><span class="status-badge status-<?= $p['status'] ?>"><?= htmlspecialchars($p['status']) ?></span></td><td><?= tierBadge($p['tier']) ?></td>
            <td><strong>$<?= number_format($p['amount_due'] ?? 0, 2) ?></strong></td>
            <?= timerCell($p['created_at_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['due_date']) ? date('M j, g:ia', strtotime($p['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><?= actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- UNCOLLECTED ---
if (count($prodProblems['uncollected']) > 0): ?>
<div class="prob-section" id="sec-uncollected">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128232; Uncollected Orders <span class="prob-section-count warning"><?= count($prodProblems['uncollected']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Uncollected</th><th>Event Ended</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['uncollected'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><span class="status-badge status-ready"><?= htmlspecialchars($p['status']) ?></span></td><td><?= tierBadge($p['tier']) ?></td>
            <?= timerCell($p['event_end_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['event_end_date']) ? date('M j', strtotime($p['event_end_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><?= actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- STUCK IN STATUS ---
if (count($prodProblems['stuck_status']) > 0): ?>
<div class="prob-section" id="sec-stuck">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128256; Stuck in Status <span class="prob-section-count"><?= count($prodProblems['stuck_status']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Stuck For</th><th>Last Change</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($prodProblems['stuck_status'] as $p): ?>
        <tr class="<?= rowSeverityClass($p) . ' ' . escalationClass($p) ?>"><?= checkboxCell($p['reference_code']) ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link"><?= htmlspecialchars($p['reference_code']) ?></a><?= newBadge($p) ?></td>
            <td><?= htmlspecialchars($p['customer_name']) ?></td><td><span class="status-badge status-<?= $p['status'] ?>"><?= htmlspecialchars($p['status']) ?></span></td><td><?= tierBadge($p['tier']) ?></td>
            <?= timerCell($p['last_change_ts'] ?? 0, $p) ?>
            <td><?= !empty($p['last_change']) ? date('M j, g:ia', strtotime($p['last_change'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($p['event_name'] ?? '') ?></td><?= actionButtons($p['reference_code'], false, $p['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif; ?>

<?php endif; /* prodTotal === 0 */ ?>

<?= renderBulkToolbar(true) ?>

</div>
<script src="problem-js.js"></script>
</body>
</html>
