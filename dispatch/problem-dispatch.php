<?php
/**
 * Dispatch Problems - MTCC Print Services
 * Dispatch-only problem orders
 * Location: /dispatch/problem-dispatch.php
 */
require_once __DIR__ . '/../admin-auth.php';
requireAnyPermission(['dispatch', 'orders_edit', 'god_mode']);
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/problem-data.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Problems - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="../css/admin-responsive.css">
    <link rel="stylesheet" href="../css/admin-print.css" media="print">
    <link rel="stylesheet" href="../css/admin-sidebar.css">
    <link rel="stylesheet" href="../admin/problem-styles.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_problems'); ?>
<script src="../admin-sidebar.js"></script>
<div style="margin: 0 auto!important; padding: 0 20px!important;">

<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><?= ICON_TRUCK ?> Dispatch Problems</h1>
        <div class="page-welcome">
            <span class="welcome-text">Delivery delays, stale orders &amp; unresolved issues</span>
            <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
    </div>
    <div class="page-header-right">
        <div class="prob-header-controls">
            <a href="../admin/problem-orders.php<?= $eventFilter ? '?event=' . urlencode($eventFilter) : '' ?>" class="prob-ctrl-btn"><?= ICON_WARNING ?> All Problems</a>
            <button id="toggleAllBtn" data-state="expanded" onclick="toggleAllClick()" class="prob-ctrl-btn">&#9662; Collapse All</button>
            <button onclick="exportAll()" class="prob-ctrl-btn">&#128229; Export CSV</button>
            <button onclick="location.reload()" class="prob-ctrl-btn" title="Refresh">&#8635; Refresh</button>
            <?php if ($dispatchCritical > 0): ?>
            <span class="status-badge status-cancelled" style="font-size:13px;padding:6px 12px;"><?= $dispatchCritical ?> Critical</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<?= renderFilterBar($availableEvents, $eventFilter) ?>

<?= renderNewProblemsBanner($newProblemCount) ?>
<?= renderResolutionBar($recentResolutions) ?>

<?php if ($dispatchTotal === 0): ?>
<div class="prob-all-clear">
    <div class="prob-all-clear-icon">&#9989;</div>
    <h2>Dispatch All Clear</h2>
    <p>No dispatch problems detected<?= $eventFilter ? ' for this event' : '' ?>.</p>
</div>
<?php else: ?>

<div class="prob-stats">
    <div class="prob-stat-card dispatch">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128666;</span><span class="prob-stat-label">Total Dispatch</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= $dispatchTotal ?></div>
    </div>
    <div class="prob-stat-card warning clickable" data-section="sec-ready-stale">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#9203;</span><span class="prob-stat-label">Ready &amp; Stale</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($dispatchProblems['ready_stale'] ?? []) ?></div>
    </div>
    <div class="prob-stat-card critical clickable" data-section="sec-delivery-overdue">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128680;</span><span class="prob-stat-label">Delivery Overdue</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($dispatchProblems['delivery_overdue'] ?? []) ?></div>
    </div>
    <div class="prob-stat-card critical clickable" data-section="sec-unresolved">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#9888;</span><span class="prob-stat-label">Unresolved Issues</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($dispatchProblems['unresolved_issues'] ?? []) ?></div>
    </div>
    <div class="prob-stat-card warning clickable" data-section="sec-dispatch-past-due">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128197;</span><span class="prob-stat-label">Past Due</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($dispatchProblems['past_due'] ?? []) ?></div>
    </div>
    <div class="prob-stat-card info clickable" data-section="sec-dispatch-stuck">
        <div class="prob-stat-left"><span class="prob-stat-icon">&#128256;</span><span class="prob-stat-label">Stuck in Status</span></div>
        <div class="prob-stat-divider"></div><div class="prob-stat-count"><?= count($dispatchProblems['stuck_status'] ?? []) ?></div>
    </div>
</div>

<?php // --- READY & STALE ---
if (count($dispatchProblems['ready_stale'] ?? []) > 0): ?>
<div class="prob-section" id="sec-ready-stale">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#9203; Ready &amp; Stale (No Courier) <span class="prob-section-count warning"><?= count($dispatchProblems['ready_stale']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Waiting</th><th>Due Date</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($dispatchProblems['ready_stale'] as $d): ?>
        <tr class="<?= ($d['severity'] ?? '') === 'critical' ? 'row-critical' : 'row-warning' ?> <?= escalationClass($d) ?>"><?= checkboxCell($d['ref'] ?? '') ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($d['ref'] ?? '') ?>" class="ref-link"><?= htmlspecialchars($d['ref'] ?? 'Unknown') ?></a><?= newBadge($d) ?></td>
            <td><?= htmlspecialchars($d['customer'] ?? 'Unknown') ?></td><td><span class="status-badge status-<?= $d['status'] ?? 'ready' ?>"><?= htmlspecialchars($d['status'] ?? 'ready') ?></span></td><td><?= tierBadge($d['tier'] ?? 'standard') ?></td>
            <?= timerCell($d['ready_since_ts'] ?? 0, $d) ?>
            <td><?= !empty($d['due_date']) ? date('M j, g:ia', strtotime($d['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($d['event_name'] ?? '') ?></td><?= actionButtons($d['ref'] ?? '', false, $d['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- DELIVERY OVERDUE ---
if (count($dispatchProblems['delivery_overdue'] ?? []) > 0): ?>
<div class="prob-section" id="sec-delivery-overdue">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128680; Delivery Overdue <span class="prob-section-count critical"><?= count($dispatchProblems['delivery_overdue']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Tier</th><th>Overdue</th><th>Deadline</th><th>Courier</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($dispatchProblems['delivery_overdue'] as $d): ?>
        <tr class="<?= ($d['severity'] ?? '') === 'critical' ? 'row-critical' : 'row-warning' ?> <?= escalationClass($d) ?>"><?= checkboxCell($d['ref'] ?? '') ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($d['ref'] ?? '') ?>" class="ref-link"><?= htmlspecialchars($d['ref'] ?? 'Unknown') ?></a><?= newBadge($d) ?></td>
            <td><?= htmlspecialchars($d['customer'] ?? 'Unknown') ?></td><td><?= tierBadge($d['tier'] ?? 'standard') ?></td>
            <?= timerCell($d['deadline_ts'] ?? 0, $d) ?>
            <td><?= !empty($d['due_date']) ? date('M j, g:ia', strtotime($d['due_date'])) : '&mdash;' ?></td>
            <td><?= htmlspecialchars($d['courier'] ?? 'Unassigned') ?></td>
            <td><?= htmlspecialchars($d['event_name'] ?? '') ?></td><?= actionButtons($d['ref'] ?? '', false, $d['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- UNRESOLVED ISSUES ---
if (count($dispatchProblems['unresolved_issues'] ?? []) > 0): ?>
<div class="prob-section" id="sec-unresolved">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#9888; Unresolved Delivery Issues <span class="prob-section-count critical"><?= count($dispatchProblems['unresolved_issues']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Tier</th><th>Issue Type</th><th>Severity</th><th>Open For</th><th>Reported By</th><th>Reported At</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($dispatchProblems['unresolved_issues'] as $d): ?>
        <tr class="<?= ($d['severity'] ?? '') === 'critical' ? 'row-critical' : 'row-warning' ?> <?= escalationClass($d) ?>"><?= checkboxCell($d['ref'] ?? '') ?>
            <td><a href="../admin-orders.php?search=<?= urlencode($d['ref'] ?? '') ?>" class="ref-link"><?= htmlspecialchars($d['ref'] ?? 'Unknown') ?></a><?= newBadge($d) ?></td>
            <td><?= htmlspecialchars($d['customer'] ?? 'Unknown') ?></td><td><?= tierBadge($d['tier'] ?? 'standard') ?></td>
            <td><span class="status-badge status-file_issue"><?= htmlspecialchars($d['type'] ?? 'unknown') ?></span></td>
            <td><?= severityBadge($d['severity'] ?? 'warning') ?></td>
            <?= timerCell($d['reported_at_ts'] ?? 0, $d) ?>
            <td><?= htmlspecialchars($d['reported_by'] ?? 'Unknown') ?></td>
            <td><?= !empty($d['reported_at']) ? date('M j, g:ia', strtotime($d['reported_at'])) : '&mdash;' ?></td><?= actionButtons($d['ref'] ?? '', false, $d['note_count'] ?? 0) ?>
        </tr>
        <?php endforeach; ?></tbody></table>
    </div>
</div>
<?php endif;

// --- PAST DUE (Dispatch) ---
if (count($dispatchProblems['past_due'] ?? []) > 0): ?>
<div class="prob-section" id="sec-dispatch-past-due">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128197; Past Due (Dispatch Stage) <span class="prob-section-count critical"><?= count($dispatchProblems['past_due']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Past Due</th><th>Due Date</th><th>Event</th><th>Material</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($dispatchProblems['past_due'] as $p): ?>
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

// --- STUCK IN STATUS (Dispatch) ---
if (count($dispatchProblems['stuck_status'] ?? []) > 0): ?>
<div class="prob-section" id="sec-dispatch-stuck">
    <div class="prob-section-header" onclick="toggleSection(this)">
        <div class="prob-section-left"><div class="prob-section-title">&#128256; Stuck in Status (Dispatch Stage) <span class="prob-section-count"><?= count($dispatchProblems['stuck_status']) ?></span></div></div>
        <div class="prob-section-actions"><button class="prob-section-export" onclick="event.stopPropagation(); exportSection(this.closest('.prob-section'))">CSV</button><span class="prob-section-toggle">&#9660;</span></div>
    </div>
    <div class="prob-section-body">
        <table class="prob-table"><thead><tr><?= sectionHeaderCheckbox() ?><th>Reference</th><th>Customer</th><th>Status</th><th>Tier</th><th>Stuck For</th><th>Last Change</th><th>Event</th><th>Actions</th></tr></thead><tbody>
        <?php foreach ($dispatchProblems['stuck_status'] as $p): ?>
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

<?php endif; /* dispatchTotal === 0 */ ?>

<?= renderBulkToolbar(false) ?>

</div>
<script src="../admin/problem-js.js"></script>
</body>
</html>
