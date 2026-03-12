<?php
/**
 * Dispatch Problems
 * Dispatch-only problem detection and display
 * 
 * Location: /admin/problem-dispatch.php
 */
require_once '../includes/icons.php';
require_once '../admin-auth.php';
requireAnyPermission(['dispatch', 'orders_edit', 'god_mode']);

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
    <link rel="stylesheet" href="problem-styles.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_problems'); ?>
<script src="../js/admin-sidebar.js"></script>
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
        <a href="problem-orders.php" class="header-btn header-btn-light"><?= ICON_WARNING ?> All Problems</a>
        <?php if ($dispatchCritical > 0): ?>
        <span class="status-badge status-cancelled" style="font-size:13px;padding:6px 12px;"><?= $dispatchCritical ?> Critical</span>
        <?php endif; ?>
        <button onclick="location.reload()" class="header-btn header-btn-primary"><?= ICON_REFRESH ?> Refresh</button>
    </div>
</div>

<?php if ($dispatchTotal === 0): ?>
<div class="prob-all-clear">
    <div class="prob-all-clear-icon">&#9989;</div>
    <h2>Dispatch All Clear</h2>
    <p>No dispatch problems detected. All deliveries are on schedule.</p>
</div>
<?php else: ?>

<div class="prob-stats">
    <div class="prob-stat-card dispatch">
        <div class="prob-stat-icon">&#128666;</div>
        <div class="prob-stat-count"><?= $dispatchTotal ?></div>
        <div class="prob-stat-label">Total Dispatch</div>
    </div>
    <div class="prob-stat-card warning">
        <div class="prob-stat-icon">&#9203;</div>
        <div class="prob-stat-count"><?= count($dispatchProblems['ready_stale'] ?? []) ?></div>
        <div class="prob-stat-label">Ready &amp; Stale</div>
    </div>
    <div class="prob-stat-card critical">
        <div class="prob-stat-icon">&#128680;</div>
        <div class="prob-stat-count"><?= count($dispatchProblems['delivery_overdue'] ?? []) ?></div>
        <div class="prob-stat-label">Delivery Overdue</div>
    </div>
    <div class="prob-stat-card critical">
        <div class="prob-stat-icon">&#9888;</div>
        <div class="prob-stat-count"><?= count($dispatchProblems['unresolved_issues'] ?? []) ?></div>
        <div class="prob-stat-label">Unresolved Issues</div>
    </div>
</div>

<?php include __DIR__ . '/problem-sections-dispatch.php'; ?>

<?php endif; ?>

</div>

<script>
function toggleSection(el) {
    el.classList.toggle('collapsed');
    el.nextElementSibling.classList.toggle('hidden');
}
</script>
</body>
</html>
