<?php
/**
 * Problem Orders - MTCC Poster System
 * Proactive detection and management of orders needing attention
 * 
 * Location: /admin/problem-orders.php
 */

require_once '../admin-auth.php';
requireAnyPermission(['preflight_view', 'preflight_edit', 'orders_view', 'orders_edit', 'god_mode']);

$basePath = '../';
$ordersDir = $basePath . 'uploads/orders/';
$statusesFile = $basePath . 'data/statuses.json';
$preflightLogFile = $basePath . 'data/preflight-log.json';
$vendorsFile = $basePath . 'data/vendors.json';
$tokensFile = $basePath . 'data/vendor-tokens.json';
$reminderLogFile = $basePath . 'data/reminder-log.json';
$eventsFile = $basePath . 'events.json';

require_once $basePath . 'includes/icons.php';

// ============================================
// PROBLEM DETECTION CONFIG
// ============================================
$problemConfig = [
    'confirmation_overdue_hours' => 4,      // Hours before vendor confirmation is overdue
    'confirmation_critical_hours' => 8,     // Hours before it's critical
    'past_due_warning_hours' => 24,         // Hours past due date before flagging
    'stuck_status_days' => 3,               // Days in same status before flagging
    'uncollected_days' => 2,                // Days after event ends to flag uncollected
    'max_reminders_threshold' => 3          // Orders hitting max reminders
];

// ============================================
// LOAD DATA
// ============================================
function loadJsonFile($file) {
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

$statuses = loadJsonFile($statusesFile) ?: [];
$preflightLog = loadJsonFile($preflightLogFile) ?: ['entries' => []];
$vendors = loadJsonFile($vendorsFile) ?: ['vendors' => []];
$tokens = loadJsonFile($tokensFile) ?: ['tokens' => []];
$reminderLog = loadJsonFile($reminderLogFile) ?: ['reminders' => []];
$eventsData = loadJsonFile($eventsFile) ?: ['events' => []];

// Build vendor lookup
$vendorLookup = [];
foreach ($vendors['vendors'] ?? [] as $v) {
    $vendorLookup[$v['id']] = $v;
}

// Build event lookup with end dates
$eventLookup = [];
foreach ($eventsData['events'] ?? [] as $e) {
    $eventLookup[$e['code']] = $e;
}

// ============================================
// DETECT PROBLEMS
// ============================================
$problems = [
    'confirmation_overdue' => [],
    'confirmation_critical' => [],
    'file_issues' => [],
    'past_due' => [],
    'stuck_status' => [],
    'uncollected' => [],
    'max_reminders' => [],
    'payment_pending' => []
];

$now = time();

// Load all orders
$orderFiles = glob($ordersDir . '*.json');
$allOrders = [];

foreach ($orderFiles as $file) {
    $order = json_decode(file_get_contents($file), true);
    if ($order && isset($order['referenceCode'])) {
        $allOrders[$order['referenceCode']] = $order;
    }
}

foreach ($allOrders as $refCode => $order) {
    $status = $statuses[$refCode] ?? 'new';
    $pfEntry = $preflightLog['entries'][$refCode] ?? null;
    $reminders = $reminderLog['reminders'][$refCode] ?? [];
    
    // Get event info
    $eventCode = $order['eventCode'] ?? null;
    $event = $eventCode ? ($eventLookup[$eventCode] ?? null) : null;
    
    // Build problem entry base
    $problemEntry = [
        'reference_code' => $refCode,
        'customer_name' => $order['customerInfo']['name'] ?? 'Unknown',
        'customer_email' => $order['customerInfo']['email'] ?? '',
        'dimensions' => ($order['dimensions']['width'] ?? 0) . '" × ' . ($order['dimensions']['height'] ?? 0) . '"',
        'material' => $order['material'] ?? 'paper',
        'due_date' => $order['selectedDate'] ?? null,
        'event_code' => $eventCode,
        'event_name' => $event['name'] ?? $eventCode,
        'status' => $status,
        'created_at' => $order['submittedAt'] ?? null,
        'tier' => $order['pricing']['tier'] ?? 'standard'
    ];
    
    // ----------------------------------------
    // Check: Confirmation Overdue/Critical
    // ----------------------------------------
    if ($status === 'preflight' && $pfEntry && empty($pfEntry['confirmed_at'])) {
        $pushedAt = strtotime($pfEntry['pushed_at']);
        $hoursSincePush = ($now - $pushedAt) / 3600;
        
        if ($hoursSincePush >= $problemConfig['confirmation_critical_hours']) {
            $problemEntry['hours_waiting'] = round($hoursSincePush, 1);
            $problemEntry['vendor_id'] = $pfEntry['vendor_id'] ?? null;
            $problemEntry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
            $problemEntry['pushed_at'] = $pfEntry['pushed_at'];
            $problemEntry['reminder_count'] = count($reminders);
            $problems['confirmation_critical'][] = $problemEntry;
        } elseif ($hoursSincePush >= $problemConfig['confirmation_overdue_hours']) {
            $problemEntry['hours_waiting'] = round($hoursSincePush, 1);
            $problemEntry['vendor_id'] = $pfEntry['vendor_id'] ?? null;
            $problemEntry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
            $problemEntry['pushed_at'] = $pfEntry['pushed_at'];
            $problemEntry['reminder_count'] = count($reminders);
            $problems['confirmation_overdue'][] = $problemEntry;
        }
    }
    
    // ----------------------------------------
    // Check: File Issues
    // ----------------------------------------
    if ($status === 'file_issue' || !empty($pfEntry['file_issue'])) {
        $issue = $pfEntry['file_issue'] ?? [];
        $problemEntry['issue_type'] = $issue['type'] ?? 'unknown';
        $problemEntry['issue_description'] = $issue['description'] ?? '';
        $problemEntry['reported_at'] = $issue['reported_at'] ?? null;
        $problemEntry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
        $problems['file_issues'][] = $problemEntry;
    }
    
    // ----------------------------------------
    // Check: Past Due Date
    // ----------------------------------------
    if (!empty($order['selectedDate']) && !in_array($status, ['complete', 'dispatched', 'picked_up', 'cancelled'])) {
        $dueDate = strtotime($order['selectedDate']);
        $hoursPastDue = ($now - $dueDate) / 3600;
        
        if ($hoursPastDue >= $problemConfig['past_due_warning_hours']) {
            $problemEntry['hours_past_due'] = round($hoursPastDue, 1);
            $problemEntry['days_past_due'] = round($hoursPastDue / 24, 1);
            $problems['past_due'][] = $problemEntry;
        }
    }
    
    // ----------------------------------------
    // Check: Stuck in Status
    // ----------------------------------------
    $statusHistory = $order['statusHistory'] ?? [];
    if (!empty($statusHistory) && !in_array($status, ['complete', 'dispatched', 'picked_up', 'cancelled'])) {
        $lastChange = end($statusHistory);
        $lastChangeTime = strtotime($lastChange['timestamp'] ?? $order['submittedAt']);
        $daysSinceChange = ($now - $lastChangeTime) / 86400;
        
        if ($daysSinceChange >= $problemConfig['stuck_status_days']) {
            $problemEntry['days_in_status'] = round($daysSinceChange, 1);
            $problemEntry['last_change'] = $lastChange['timestamp'] ?? null;
            $problems['stuck_status'][] = $problemEntry;
        }
    }
    
    // ----------------------------------------
    // Check: Uncollected (Event Ended)
    // ----------------------------------------
    if ($event && !empty($event['end_date']) && in_array($status, ['ready', 'ready_for_pickup'])) {
        $eventEnd = strtotime($event['end_date']);
        $daysSinceEventEnd = ($now - $eventEnd) / 86400;
        
        if ($daysSinceEventEnd >= $problemConfig['uncollected_days']) {
            $problemEntry['days_uncollected'] = round($daysSinceEventEnd, 1);
            $problemEntry['event_end_date'] = $event['end_date'];
            $problems['uncollected'][] = $problemEntry;
        }
    }
    
    // ----------------------------------------
    // Check: Max Reminders Hit
    // ----------------------------------------
    if (count($reminders) >= $problemConfig['max_reminders_threshold'] && $status === 'preflight' && empty($pfEntry['confirmed_at'])) {
        $problemEntry['reminder_count'] = count($reminders);
        $lastReminder = end($reminders);
        $problemEntry['last_reminder'] = $lastReminder['sent_at'] ?? null;
        $problemEntry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
        $problems['max_reminders'][] = $problemEntry;
    }
    
    // ----------------------------------------
    // Check: Payment Pending (Not Paid)
    // ----------------------------------------
    if (!in_array($status, ['cancelled']) && empty($order['paymentInfo']['paid'])) {
        $tier = $order['pricing']['tier'] ?? 'standard';
        // Only flag if it's a rush order without payment
        if (in_array($tier, ['sameday', 'nextday', '2days'])) {
            $problemEntry['amount_due'] = $order['pricing']['total'] ?? 0;
            $problems['payment_pending'][] = $problemEntry;
        }
    }
}

// Sort problems by severity/age
usort($problems['confirmation_critical'], function($a, $b) {
    return $b['hours_waiting'] <=> $a['hours_waiting'];
});
usort($problems['confirmation_overdue'], function($a, $b) {
    return $b['hours_waiting'] <=> $a['hours_waiting'];
});
usort($problems['past_due'], function($a, $b) {
    return $b['hours_past_due'] <=> $a['hours_past_due'];
});
usort($problems['uncollected'], function($a, $b) {
    return $b['days_uncollected'] <=> $a['days_uncollected'];
});

// Calculate totals
$totalProblems = 0;
$criticalCount = 0;
foreach ($problems as $type => $items) {
    $totalProblems += count($items);
    if (in_array($type, ['confirmation_critical', 'file_issues', 'past_due'])) {
        $criticalCount += count($items);
    }
}

// Dispatch-level problems
$issuesFile = $basePath . 'data/delivery-issues.json';
require_once $basePath . 'includes/problem-detection.php';
$dispatchProblems = function_exists('getDispatchProblems')
    ? getDispatchProblems($ordersDir, $statusesFile, $issuesFile)
    : ['ready_stale' => [], 'delivery_overdue' => [], 'unresolved_issues' => [], 'counts' => ['total' => 0]];
$totalProblems += $dispatchProblems['counts']['total'];
$criticalCount += count(array_filter($dispatchProblems['delivery_overdue'] ?? [], fn($d) => $d['severity'] === 'critical'));
$criticalCount += count(array_filter($dispatchProblems['unresolved_issues'] ?? [], fn($i) => $i['severity'] === 'critical'));

// Active filter
$activeFilter = $_GET['filter'] ?? 'all';

?>
<?php
// Determine sidebar active key based on filter
$sidebarKey = 'problem_orders_all';
if ($activeFilter === 'production') $sidebarKey = 'problem_orders';
if ($activeFilter === 'dispatch') $sidebarKey = 'dispatch_problems';

// Page title based on filter
$pageTitle = 'Problem Orders';
$pageSubtitle = 'Proactive detection of orders needing attention';
if ($activeFilter === 'production') {
    $pageTitle = 'Production Problems';
    $pageSubtitle = 'Vendor confirmation, file issues &amp; overdue orders';
} elseif ($activeFilter === 'dispatch') {
    $pageTitle = 'Dispatch Problems';
    $pageSubtitle = 'Delivery delays, stale orders &amp; unresolved issues';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Shared Admin CSS -->
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="../css/admin-responsive.css">
    <link rel="stylesheet" href="../css/admin-print.css" media="print">
    <link rel="stylesheet" href="../css/admin-sidebar.css">
    <style>
        
        /* Summary Cards */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .summary-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid transparent;
        }
        
        .summary-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .summary-card.active {
            border-color: #7c3aed;
        }
        
        .summary-card.critical {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        }
        
        .summary-card.warning {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }
        
        .summary-card.info {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }
        
        .card-icon {
            font-size: 28px;
            margin-bottom: 8px;
        }
        
        .card-count {
            font-size: 32px;
            font-weight: 700;
            color: #1e293b;
        }
        
        .card-label {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        /* Problem Sections */
        .problem-section {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            cursor: pointer;
        }
        
        .section-header:hover {
            background: #f1f5f9;
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 15px;
            color: #1e293b;
        }
        
        .section-badge {
            background: #7c3aed;
            color: white;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .section-badge.critical {
            background: #dc2626;
        }
        
        .section-badge.warning {
            background: #f59e0b;
        }
        
        .section-toggle {
            font-size: 18px;
            transition: transform 0.2s;
        }
        
        .section-content {
            display: none;
        }
        
        .section-content.show {
            display: block;
        }
        
        .section-header.collapsed .section-toggle {
            transform: rotate(-90deg);
        }
        
        /* Problem Table */
        .problem-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .problem-table th {
            text-align: left;
            padding: 12px 15px;
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .problem-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
            color: #374151;
        }
        
        .problem-table tr:hover {
            background: #f8fafc;
        }
        
        .ref-link {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }
        
        .ref-link:hover {
            text-decoration: underline;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-badge.critical {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .status-badge.warning {
            background: #fef3c7;
            color: #92400e;
        }
        
        .status-badge.info {
            background: #dbeafe;
            color: #1d4ed8;
        }
        
        .time-badge {
            font-weight: 600;
        }
        
        .time-badge.critical {
            color: #dc2626;
        }
        
        .time-badge.warning {
            color: #f59e0b;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn.primary {
            background: #7c3aed;
            color: white;
        }
        
        .action-btn.primary:hover {
            background: #6d28d9;
        }
        
        .action-btn.secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .action-btn.secondary:hover {
            background: #d1d5db;
        }
        
        .empty-section {
            padding: 40px 20px;
            text-align: center;
            color: #6b7280;
        }
        
        .empty-section svg {
            width: 48px;
            height: 48px;
            margin-bottom: 10px;
            opacity: 0.5;
        }
        
        /* All Clear State */
        .all-clear {
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border-radius: 16px;
            padding: 60px 40px;
            text-align: center;
            margin-top: 20px;
        }
        
        .all-clear-icon {
            font-size: 64px;
            margin-bottom: 15px;
        }
        
        .all-clear h2 {
            color: #059669;
            margin-bottom: 10px;
        }
        
        .all-clear p {
            color: #047857;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .summary-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .problem-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar($sidebarKey); ?>
<script src="../admin-sidebar.js"></script>
<div style="margin: 0 auto!important; padding: 0 20px!important;">

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1 class="page-title"><?= ICON_WARNING ?> <?= $pageTitle ?></h1>
        <div class="page-welcome">
            <span class="welcome-text"><?= $pageSubtitle ?></span>
            <span class="welcome-date">Today is <?= date('l, F j, Y') ?></span>
        </div>
    </div>
    <div class="page-header-right">
        <?php if ($activeFilter !== 'all'): ?>
        <a href="problem-orders.php" class="header-btn header-btn-light"><?= ICON_WARNING ?> All Problems</a>
        <?php endif; ?>
        <?php if ($criticalCount > 0): ?>
        <span class="status-badge critical" style="font-size:13px;padding:6px 12px;"><?= $criticalCount ?> Critical</span>
        <?php endif; ?>
        <button onclick="location.reload()" class="header-btn header-btn-primary"><?= ICON_REFRESH ?> Refresh</button>
    </div>
</div>

<div class="admin-container" style="padding:0!important;">
        
        <!-- Summary Cards -->
        <?php $productionTotal = count($problems['confirmation_critical']) + count($problems['confirmation_overdue']) + count($problems['file_issues']) + count($problems['past_due']) + count($problems['uncollected']) + count($problems['stuck_status']); ?>
        <div class="summary-grid">
            <div class="summary-card <?= $activeFilter === 'all' ? 'active' : '' ?>" onclick="filterProblems('all')">
                <div class="card-icon">📋</div>
                <div class="card-count"><?= $totalProblems ?></div>
                <div class="card-label">Total Issues</div>
            </div>
            
            <div class="summary-card critical <?= $activeFilter === 'critical' ? 'active' : '' ?>" onclick="filterProblems('critical')">
                <div class="card-icon">🚨</div>
                <div class="card-count"><?= count($problems['confirmation_critical']) ?></div>
                <div class="card-label">Critical (8h+)</div>
            </div>
            
            <div class="summary-card warning <?= $activeFilter === 'overdue' ? 'active' : '' ?>" onclick="filterProblems('overdue')">
                <div class="card-icon">⏰</div>
                <div class="card-count"><?= count($problems['confirmation_overdue']) ?></div>
                <div class="card-label">Overdue (4h+)</div>
            </div>
            
            <div class="summary-card critical <?= $activeFilter === 'file_issues' ? 'active' : '' ?>" onclick="filterProblems('file_issues')">
                <div class="card-icon">⚠️</div>
                <div class="card-count"><?= count($problems['file_issues']) ?></div>
                <div class="card-label">File Issues</div>
            </div>
            
            <div class="summary-card critical <?= $activeFilter === 'past_due' ? 'active' : '' ?>" onclick="filterProblems('past_due')">
                <div class="card-icon">📅</div>
                <div class="card-count"><?= count($problems['past_due']) ?></div>
                <div class="card-label">Past Due</div>
            </div>
            
            <div class="summary-card warning <?= $activeFilter === 'uncollected' ? 'active' : '' ?>" onclick="filterProblems('uncollected')">
                <div class="card-icon">📦</div>
                <div class="card-count"><?= count($problems['uncollected']) ?></div>
                <div class="card-label">Uncollected</div>
            </div>
            
            <div class="summary-card info <?= $activeFilter === 'stuck' ? 'active' : '' ?>" onclick="filterProblems('stuck')">
                <div class="card-icon">🔄</div>
                <div class="card-count"><?= count($problems['stuck_status']) ?></div>
                <div class="card-label">Stuck Status</div>
            </div>
        </div>
            
            <?php if ($dispatchProblems['counts']['total'] > 0): ?>
            <div class="summary-card warning <?= $activeFilter === 'dispatch' ? 'active' : '' ?>" onclick="filterProblems('dispatch')" style="border-left:3px solid #7c3aed;">
                <div class="card-icon">&#128666;</div>
                <div class="card-count"><?= $dispatchProblems['counts']['total'] ?></div>
                <div class="card-label">Dispatch Issues</div>
            </div>
            <?php endif; ?>
        
        <?php if ($totalProblems === 0): ?>
        <div class="all-clear">
            <div class="all-clear-icon">✅</div>
            <h2>All Clear!</h2>
            <p>No problem orders detected. Everything is running smoothly.</p>
        </div>
        <?php else: ?>
        
        <!-- Critical Confirmation -->
        <?php if (count($problems['confirmation_critical']) > 0 && in_array($activeFilter, ['all', 'production', 'critical'])): ?>
        <div class="problem-section" data-type="critical">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    🚨 Critical - Awaiting Confirmation 8+ Hours
                    <span class="section-badge critical"><?= count($problems['confirmation_critical']) ?></span>
                </div>
                <span class="section-toggle">▼</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Vendor</th>
                            <th>Waiting</th>
                            <th>Reminders</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems['confirmation_critical'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link">#<?= htmlspecialchars($p['reference_code']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer_name']) ?></td>
                            <td><?= htmlspecialchars($p['vendor_name']) ?></td>
                            <td><span class="time-badge critical"><?= $p['hours_waiting'] ?>h</span></td>
                            <td><?= $p['reminder_count'] ?> sent</td>
                            <td><?= $p['due_date'] ? date('M j', strtotime($p['due_date'])) : '-' ?></td>
                            <td>
                                <button class="action-btn primary" onclick="sendReminder('<?= $p['reference_code'] ?>')">Send Reminder</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Overdue Confirmation -->
        <?php if (count($problems['confirmation_overdue']) > 0 && in_array($activeFilter, ['all', 'production', 'overdue'])): ?>
        <div class="problem-section" data-type="overdue">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    ⏰ Overdue - Awaiting Confirmation 4+ Hours
                    <span class="section-badge warning"><?= count($problems['confirmation_overdue']) ?></span>
                </div>
                <span class="section-toggle">▼</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Vendor</th>
                            <th>Waiting</th>
                            <th>Reminders</th>
                            <th>Due Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems['confirmation_overdue'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link">#<?= htmlspecialchars($p['reference_code']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer_name']) ?></td>
                            <td><?= htmlspecialchars($p['vendor_name']) ?></td>
                            <td><span class="time-badge warning"><?= $p['hours_waiting'] ?>h</span></td>
                            <td><?= $p['reminder_count'] ?> sent</td>
                            <td><?= $p['due_date'] ? date('M j', strtotime($p['due_date'])) : '-' ?></td>
                            <td>
                                <button class="action-btn primary" onclick="sendReminder('<?= $p['reference_code'] ?>')">Send Reminder</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- File Issues -->
        <?php if (count($problems['file_issues']) > 0 && in_array($activeFilter, ['all', 'production', 'file_issues'])): ?>
        <div class="problem-section" data-type="file_issues">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    ⚠️ File Issues Reported
                    <span class="section-badge critical"><?= count($problems['file_issues']) ?></span>
                </div>
                <span class="section-toggle">▼</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Vendor</th>
                            <th>Issue Type</th>
                            <th>Reported</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems['file_issues'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link">#<?= htmlspecialchars($p['reference_code']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer_name']) ?></td>
                            <td><?= htmlspecialchars($p['vendor_name']) ?></td>
                            <td><span class="status-badge warning"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['issue_type']))) ?></span></td>
                            <td><?= $p['reported_at'] ? date('M j, g:ia', strtotime($p['reported_at'])) : '-' ?></td>
                            <td>
                                <a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="action-btn secondary">View Order</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Past Due -->
        <?php if (count($problems['past_due']) > 0 && in_array($activeFilter, ['all', 'production', 'past_due'])): ?>
        <div class="problem-section" data-type="past_due">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    📅 Past Due Date
                    <span class="section-badge critical"><?= count($problems['past_due']) ?></span>
                </div>
                <span class="section-toggle">▼</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Days Past</th>
                            <th>Event</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems['past_due'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link">#<?= htmlspecialchars($p['reference_code']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer_name']) ?></td>
                            <td><span class="status-badge info"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['status']))) ?></span></td>
                            <td><?= date('M j', strtotime($p['due_date'])) ?></td>
                            <td><span class="time-badge critical"><?= $p['days_past_due'] ?> days</span></td>
                            <td><?= htmlspecialchars($p['event_name'] ?? '-') ?></td>
                            <td>
                                <a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="action-btn secondary">View Order</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Uncollected -->
        <?php if (count($problems['uncollected']) > 0 && in_array($activeFilter, ['all', 'production', 'uncollected'])): ?>
        <div class="problem-section" data-type="uncollected">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    📦 Uncollected (Event Ended)
                    <span class="section-badge warning"><?= count($problems['uncollected']) ?></span>
                </div>
                <span class="section-toggle">▼</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Event</th>
                            <th>Event Ended</th>
                            <th>Days Uncollected</th>
                            <th>Contact</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems['uncollected'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link">#<?= htmlspecialchars($p['reference_code']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer_name']) ?></td>
                            <td><?= htmlspecialchars($p['event_name']) ?></td>
                            <td><?= date('M j', strtotime($p['event_end_date'])) ?></td>
                            <td><span class="time-badge warning"><?= $p['days_uncollected'] ?> days</span></td>
                            <td><a href="mailto:<?= htmlspecialchars($p['customer_email']) ?>"><?= htmlspecialchars($p['customer_email']) ?></a></td>
                            <td>
                                <button class="action-btn primary" onclick="sendPickupReminder('<?= $p['reference_code'] ?>')">Send Reminder</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Stuck Status -->
        <?php if (count($problems['stuck_status']) > 0 && in_array($activeFilter, ['all', 'production', 'stuck'])): ?>
        <div class="problem-section" data-type="stuck">
            <div class="section-header collapsed" onclick="toggleSection(this)">
                <div class="section-title">
                    🔄 Stuck in Status (<?= $problemConfig['stuck_status_days'] ?>+ days)
                    <span class="section-badge"><?= count($problems['stuck_status']) ?></span>
                </div>
                <span class="section-toggle">▼</span>
            </div>
            <div class="section-content">
                <table class="problem-table">
                    <thead>
                        <tr>
                            <th>Order</th>
                            <th>Customer</th>
                            <th>Status</th>
                            <th>Days in Status</th>
                            <th>Last Change</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems['stuck_status'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="ref-link">#<?= htmlspecialchars($p['reference_code']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer_name']) ?></td>
                            <td><span class="status-badge info"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $p['status']))) ?></span></td>
                            <td><?= $p['days_in_status'] ?> days</td>
                            <td><?= $p['last_change'] ? date('M j, g:ia', strtotime($p['last_change'])) : '-' ?></td>
                            <td>
                                <a href="../admin-orders.php?search=<?= urlencode($p['reference_code']) ?>" class="action-btn secondary">View Order</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    
        <!-- DISPATCH PROBLEMS -->
        <?php if ($dispatchProblems['counts']['total'] > 0 && ($activeFilter === 'all' || $activeFilter === 'dispatch')): ?>
        
        <?php if (count($dispatchProblems['ready_stale']) > 0): ?>
        <div class="problem-section" data-type="ready_stale">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    &#128666; Ready &mdash; No Courier Assigned (4h+)
                    <span class="section-badge warning"><?= count($dispatchProblems['ready_stale']) ?></span>
                </div>
                <span class="section-toggle">&#9660;</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead><tr><th>Order</th><th>Customer</th><th>Due Date</th><th>Waiting</th><th>Severity</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($dispatchProblems['ready_stale'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['ref']) ?>" class="order-ref"><?= htmlspecialchars($p['ref']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer']) ?></td>
                            <td><?= $p['due_date'] ? date('M j', strtotime($p['due_date'])) : '-' ?></td>
                            <td><strong><?= $p['hours_waiting'] ?>h</strong></td>
                            <td><span class="status-badge <?= $p['severity'] ?>"><?= ucfirst($p['severity']) ?></span></td>
                            <td><a href="../dispatch/" class="action-btn secondary">Open Dispatch Hub</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (count($dispatchProblems['delivery_overdue']) > 0): ?>
        <div class="problem-section" data-type="delivery_overdue">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    &#9200; Delivery Overdue (2h+ Past Deadline)
                    <span class="section-badge critical"><?= count($dispatchProblems['delivery_overdue']) ?></span>
                </div>
                <span class="section-toggle">&#9660;</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead><tr><th>Order</th><th>Customer</th><th>Courier</th><th>Due Date</th><th>Overdue</th><th>Severity</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($dispatchProblems['delivery_overdue'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['ref']) ?>" class="order-ref"><?= htmlspecialchars($p['ref']) ?></a></td>
                            <td><?= htmlspecialchars($p['customer']) ?></td>
                            <td><?= htmlspecialchars($p['courier']) ?></td>
                            <td><?= $p['due_date'] ? date('M j', strtotime($p['due_date'])) : '-' ?></td>
                            <td><strong style="color:#dc2626"><?= $p['hours_overdue'] ?>h</strong></td>
                            <td><span class="status-badge <?= $p['severity'] ?>"><?= ucfirst($p['severity']) ?></span></td>
                            <td><a href="../dispatch/" class="action-btn secondary">Open Dispatch Hub</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (count($dispatchProblems['unresolved_issues']) > 0): ?>
        <div class="problem-section" data-type="unresolved_issues">
            <div class="section-header" onclick="toggleSection(this)">
                <div class="section-title">
                    &#9888;&#65039; Unresolved Delivery Issues
                    <span class="section-badge warning"><?= count($dispatchProblems['unresolved_issues']) ?></span>
                </div>
                <span class="section-toggle">&#9660;</span>
            </div>
            <div class="section-content show">
                <table class="problem-table">
                    <thead><tr><th>Order</th><th>Issue Type</th><th>Reported By</th><th>Hours Open</th><th>Severity</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($dispatchProblems['unresolved_issues'] as $p): ?>
                        <tr>
                            <td><a href="../admin-orders.php?search=<?= urlencode($p['ref']) ?>" class="order-ref"><?= htmlspecialchars($p['ref']) ?></a></td>
                            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $p['type']))) ?></td>
                            <td><?= htmlspecialchars($p['reported_by']) ?></td>
                            <td><strong><?= $p['hours_open'] ?>h</strong></td>
                            <td><span class="status-badge <?= $p['severity'] ?>"><?= ucfirst($p['severity']) ?></span></td>
                            <td><a href="../dispatch/#issues" class="action-btn secondary">Resolve in Hub</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <?php endif; ?>
    </div>
    
    <script>
        function toggleSection(header) {
            header.classList.toggle('collapsed');
            const content = header.nextElementSibling;
            content.classList.toggle('show');
        }
        
        function filterProblems(filter) {
            window.location.href = '?filter=' + filter;
        }
        
        async function sendReminder(refCode) {
            if (!confirm('Send reminder email to vendor for order #' + refCode + '?')) return;
            
            try {
                const formData = new FormData();
                formData.append('ajax_action', 'send_manual_reminder');
                formData.append('reference_code', refCode);
                
                const response = await fetch('production.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                alert(result.success ? result.message : (result.error || 'Failed to send reminder'));
                if (result.success) location.reload();
            } catch (error) {
                alert('An error occurred');
            }
        }
        
        async function sendPickupReminder(refCode) {
            alert('Pickup reminder feature coming soon. Order: #' + refCode);
            // TODO: Implement pickup reminder email
        }
    </script>
</div><!-- /admin-container -->
</div><!-- /page wrapper -->
</body>
</html>
