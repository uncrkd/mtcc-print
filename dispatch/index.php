<?php
/**
 * Dispatch Hub - Admin Command Center
 * Main dispatch management page
 * Location: /dispatch/index.php
 * Access: /dispatch/
 * 
 * Features:
 *   - Weather bar with current conditions + 5-day forecast
 *   - Today's Summary dashboard cards
 *   - Ready Queue table with courier assignment dropdowns
 *   - Active Deliveries tab (grouped by courier)
 *   - Completed Today tab
 *   - Bulk selection and batch creation
 */

require_once __DIR__ . '/../admin-auth.php';
requirePermission('dispatch');

// Include dependencies
require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../includes/data-access.php';
require_once __DIR__ . '/dispatch-functions.php';
require_once __DIR__ . '/batch-suggestions.php';
require_once __DIR__ . '/../includes/delivery-issues.php';

// ============================================
// AJAX HANDLERS
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    $action = $_POST['ajax_action'];
    
    switch ($action) {
        case 'assign_courier':
            $ref = $_POST['reference_code'] ?? '';
            $courierId = $_POST['courier_id'] ?? '';
            $courierName = $_POST['courier_name'] ?? '';
            
            if (empty($ref) || empty($courierId)) {
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                break;
            }
            
            // Load order and add dispatch metadata
            $orderDir = DISPATCH_ORDERS_DIR;
            $files = glob($orderDir . '*.json');
            $updated = false;
            
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data && isset($data['referenceCode']) && $data['referenceCode'] === $ref) {
                    $data['status'] = 'dispatched';
                    $data['dispatch'] = [
                        'courier_id' => $courierId,
                        'courier_name' => $courierName,
                        'courier_pin' => $courierId,
                        'dispatched_at' => date('c'),
                        'picked_up_at' => null,
                        'delivered_at' => null,
                        'delivery_photo' => null,
                        'delivery_notes' => null,
                        'failed_attempt' => null,
                    ];
                    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                    $updated = true;
                    break;
                }
            }
            
            if ($updated) {
                // Update status to dispatched
                $statusFile = DISPATCH_STATUSES_FILE;
                $statuses = json_decode(file_get_contents($statusFile), true) ?: [];
                $statuses[$ref] = 'dispatched';
                file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
                
                dispatch_notifyOrderDispatched($ref, $courierName);

                // Log to order history
                if (function_exists('logOrderHistory')) {
                    logOrderHistory($ref, 'status_change', "Dispatched to courier: $courierName", getCurrentAdminName() ?? 'Admin');
                }

                // Log activity
                if (function_exists('logAdminActivity')) {
                    logAdminActivity('Order Dispatched', [
                        'reference_code' => $ref,
                        'courier' => $courierName,
                    ], $ref);
                }
                
                echo json_encode(['success' => true, 'message' => "Assigned to $courierName"]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Order not found']);
            }
            break;
            
        case 'refresh_data':
            $summary = dispatch_getTodaySummary();
            $readyQueue = dispatch_getReadyQueue();
            $activeDeliveries = dispatch_getActiveDeliveries();
            $completedToday = dispatch_getCompletedToday();
            echo json_encode([
                'success' => true,
                'summary' => $summary,
                'ready_count' => count($readyQueue),
                'active_count' => array_sum(array_column($activeDeliveries, 'count')),
                'completed_count' => count($completedToday),
            ]);
            break;

        case 'batch_preview':
            $refs = json_decode($_POST['refs'] ?? '[]', true);
            if (empty($refs)) {
                echo json_encode(['success' => false, 'error' => 'No orders specified']);
                break;
            }
            $preview = dispatch_getBatchPreview($refs);
            echo json_encode(['success' => true, 'data' => $preview]);
            break;

        case 'create_batch':
            $refs = json_decode($_POST['refs'] ?? '[]', true);
            $courierId = $_POST['courier_id'] ?? '';
            $courierName = $_POST['courier_name'] ?? '';
            $notes = $_POST['notes'] ?? '';
            
            if (empty($refs) || count($refs) < 2) {
                echo json_encode(['success' => false, 'error' => 'Need at least 2 orders']);
                break;
            }
            
            $result = dispatch_createBatch($refs, $courierId, $courierName, $notes);
            
            if ($result['success']) {
                dispatch_notifyBatchCreated($result['batch_id'], $result['order_count'], $courierName);
                
                if (function_exists('logAdminActivity')) {
                    logAdminActivity('Batch Created', [
                        'batch_id' => $result['batch_id'],
                        'order_count' => $result['order_count'],
                        'courier' => $courierName ?: 'Unassigned',
                    ], $result['batch_id']);
                }
            }
            
            echo json_encode($result);
            break;

        case 'assign_batch_courier':
            $batchId = $_POST['batch_id'] ?? '';
            $courierId = $_POST['courier_id'] ?? '';
            $courierName = $_POST['courier_name'] ?? '';
            
            if (empty($batchId) || empty($courierId)) {
                echo json_encode(['success' => false, 'error' => 'Missing required fields']);
                break;
            }
            
            $result = dispatch_assignBatchCourier($batchId, $courierId, $courierName);
            if ($result['success']) {
                dispatch_notifyBatchDispatched($batchId, $courierName);
            }
            echo json_encode($result);
            break;
            
        case 'toggle_weather_bonus':
            $active = ($_POST['active'] ?? '0') === '1';
            $settings = dispatch_loadSettings();
            $settings['weather']['bad_weather_active'] = $active;
            dispatch_saveSettings($settings);
            dispatch_notifyWeatherAlert($active ? 'Manual toggle' : 'Manual toggle', $active);
            echo json_encode(['success' => true, 'active' => $active]);
            break;

        case 'get_notifications':
            $sinceId = intval($_POST['since_id'] ?? 0);
            $result = dispatch_getNotifications($sinceId);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        case 'mark_notification_read':
            $notifId = $_POST['notif_id'] ?? 'all';
            $result = dispatch_markNotificationRead($notifId);
            echo json_encode($result);
            break;

        case 'clear_notifications':
            $result = dispatch_clearNotifications();
            echo json_encode($result);
            break;

        case 'get_suggestions':
            $engine = new BatchSuggestionEngine();
            $suggestions = $engine->getSuggestions();
            echo json_encode(['success' => true, 'suggestions' => $suggestions]);
            break;

        case 'unassign_order':
            $ref = trim($_POST['reference_code'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            if (empty($ref)) {
                echo json_encode(['success' => false, 'error' => 'Reference code required']);
                break;
            }
            $result = batch_unassignOrder($ref, 'Admin', $reason, true);
            if ($result['success'] && function_exists('logAdminActivity')) {
                logAdminActivity('Order Unassigned', [
                    'reference_code' => $ref,
                    'previous_courier' => $result['previous_courier'] ?? '',
                    'reason' => $reason,
                ], $ref);
            }
            echo json_encode($result);
            break;

        case 'disband_batch':
            $batchId = trim($_POST['batch_id'] ?? '');
            if (empty($batchId)) {
                echo json_encode(['success' => false, 'error' => 'Batch ID required']);
                break;
            }
            $result = batch_disband($batchId, 'Admin');
            if ($result['success'] && function_exists('logAdminActivity')) {
                logAdminActivity('Batch Disbanded', [
                    'batch_id' => $batchId,
                ], $batchId);
            }
            echo json_encode($result);
            break;

        case 'release_all_courier':
            $courierPin = trim($_POST['courier_id'] ?? '');
            $reason = trim($_POST['reason'] ?? '');
            if (empty($courierPin)) {
                echo json_encode(['success' => false, 'error' => 'Courier ID required']);
                break;
            }
            $result = batch_releaseAllForCourier($courierPin, 'Admin', $reason);
            if ($result['success'] && function_exists('logAdminActivity')) {
                logAdminActivity('Courier Orders Released', [
                    'courier_id' => $courierPin,
                    'released_count' => $result['released_count'],
                    'reason' => $reason,
                ], 'courier-' . $courierPin);
            }
            echo json_encode($result);
            break;

        case 'review_issue':
            $issueId = $_POST['issue_id'] ?? '';
            if (empty($issueId)) { echo json_encode(['success' => false, 'error' => 'Missing issue ID']); exit; }
            echo json_encode(issues_updateStatus($issueId, 'reviewing'));
            break;

        case 'resolve_issue':
            $issueId = $_POST['issue_id'] ?? '';
            $resolution = $_POST['resolution'] ?? '';
            $notes = $_POST['resolution_notes'] ?? '';
            $retryDate = $_POST['retry_date'] ?? null;
            $resolvedBy = $_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin';
            if (empty($issueId) || empty($resolution)) { echo json_encode(['success' => false, 'error' => 'Missing fields']); exit; }
            if ($resolution === 'retry' && !$retryDate) $retryDate = date('Y-m-d', strtotime('+1 day'));
            echo json_encode(issues_resolve($issueId, $resolution, $resolvedBy, $notes, $retryDate));
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Unknown action']);
    }
    exit;
}

// ============================================
// LOAD PAGE DATA
// ============================================
$weather = dispatch_getWeather();
$summary = dispatch_getTodaySummary();
$readyQueue = dispatch_getReadyQueue();
$activeDeliveries = dispatch_getActiveDeliveries();
$completedToday = dispatch_getCompletedToday();
$couriers = dispatch_getAvailableCouriers();
$courierSummary = dispatch_getCourierSummary();
$settings = dispatch_loadSettings();
$badWeatherActive = $settings['weather']['bad_weather_active'] ?? false;

$activeBatches = dispatch_getActiveBatches();
$currentTab = $_GET['tab'] ?? 'ready';
$openIssues = issues_getOpen();
$issueCount = count($openIssues);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Hub - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="../css/admin-tables.css">
    <link rel="stylesheet" href="dispatch-hub.css">
<link rel="stylesheet" href="../css/admin-sidebar.css">
    <link rel="stylesheet" href="../css/timer-styles.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_hub'); ?>
<script src="../js/admin-sidebar.js"></script>
<div style="margin: 0 auto; padding: 0 20px;">

    <!-- Page Header -->
    <div class="dispatch-page-header">
        <div class="dispatch-header-left">
            <h1 class="dispatch-title">&#128666; Dispatch Hub</h1>
            <div class="dispatch-subtitle">
                <span>Manage deliveries and courier assignments</span>
                <span class="dispatch-date"><?= date('l, F j Y') ?></span>
            </div>
        </div>
        <div class="dispatch-header-right">
            <a href="scanner.php" class="dispatch-header-btn" title="Open Scanner">
                &#128247; Scanner
            </a>
            <div class="notif-bell-wrapper" id="notifBell">
                <button class="dispatch-header-btn notif-bell-btn" onclick="toggleNotifPanel()" title="Notifications">
                    &#128276;
                    <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
                </button>
                <div class="notif-panel" id="notifPanel" style="display:none;">
                    <div class="notif-panel-header">
                        <span class="notif-panel-title">Notifications</span>
                        <div class="notif-panel-actions">
                            <button class="notif-panel-btn" onclick="markAllNotifsRead()" title="Mark all read">&#10003; Read all</button>
                            <button class="notif-panel-btn" onclick="clearAllNotifs()" title="Clear all">&#128465; Clear</button>
                        </div>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty">No notifications</div>
                    </div>
                </div>
            </div>
            <a href="settings.php" class="dispatch-header-btn" title="Dispatch Settings">
                &#9881;&#65039; Settings
            </a>
            <a href="analytics.php" class="dispatch-header-btn" title="Dispatch Analytics">
                &#128202; Analytics
            </a>
            <a href="couriers.php" class="dispatch-header-btn" title="Courier Management">
                &#128100; Couriers
            </a>
        </div>
    </div>

    <!-- Weather Bar -->
    <div class="weather-bar <?= $weather['bad_weather'] ? 'weather-alert' : '' ?>">
        <div class="weather-current">
            <span class="weather-icon"><?= $weather['current']['icon'] ?></span>
            <span class="weather-temp"><?= $weather['current']['temp'] ?>°C</span>
            <span class="weather-desc"><?= htmlspecialchars($weather['current']['desc']) ?></span>
            <span class="weather-detail">Wind: <?= $weather['current']['wind'] ?> km/h</span>
            <?php if ($weather['bad_weather']): ?>
            <span class="weather-alert-badge">&#9888;&#65039; <?= implode(', ', $weather['bad_reasons']) ?></span>
            <?php endif; ?>
        </div>
        <div class="weather-forecast">
            <?php foreach ($weather['forecast'] as $day): ?>
            <div class="forecast-day">
                <span class="forecast-label"><?= $day['day'] ?></span>
                <span class="forecast-icon"><?= $day['icon'] ?></span>
                <span class="forecast-temps"><?= $day['high'] ?>° / <?= $day['low'] ?>°</span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="weather-bonus-toggle">
            <label class="toggle-label" for="badWeatherToggle">
                Bad Weather Bonus
            </label>
            <button id="badWeatherToggle" class="toggle-btn <?= $badWeatherActive ? 'active' : '' ?>" onclick="toggleBadWeather()">
                <?= $badWeatherActive ? 'ON' : 'OFF' ?>
            </button>
            <?php if ($weather['bad_weather'] && !$badWeatherActive): ?>
            <span class="weather-suggest">&#128161; Auto-suggest: Enable</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Today's Summary -->
    <div class="summary-cards">
        <div class="summary-card summary-ready">
            <div class="summary-number" id="summaryReady"><?= $summary['ready'] ?></div>
            <div class="summary-label">Ready</div>
        </div>
        <div class="summary-card summary-active">
            <div class="summary-number" id="summaryActive"><?= $summary['active'] ?></div>
            <div class="summary-label">Active</div>
        </div>
        <div class="summary-card summary-transit">
            <div class="summary-number" id="summaryTransit"><?= $summary['in_transit'] ?></div>
            <div class="summary-label">In Transit</div>
        </div>
        <div class="summary-card summary-done">
            <div class="summary-number" id="summaryDone"><?= $summary['completed'] ?></div>
            <div class="summary-label">Completed</div>
        </div>
    </div>

    <!-- Courier Status -->
    <?php if (!empty($courierSummary)): ?>
    <div class="courier-status-bar">
        <span class="courier-status-label">Couriers:</span>
        <?php foreach ($courierSummary as $c): ?>
        <span class="courier-indicator <?= $c['availability'] === 'online' ? 'online' : 'offline' ?>">
            <?= $c['availability'] === 'online' ? '&#128994;' : '&#9899;' ?>
            <?= htmlspecialchars($c['name']) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Tab Navigation -->
    <div class="dispatch-tabs">
        <button class="dispatch-tab <?= $currentTab === 'ready' ? 'active' : '' ?>" onclick="switchDispatchTab('ready')">
            Ready Queue
            <?php if ($summary['ready'] > 0): ?>
            <span class="tab-badge"><?= $summary['ready'] ?></span>
            <?php endif; ?>
        </button>
        <button class="dispatch-tab <?= $currentTab === 'active' ? 'active' : '' ?>" onclick="switchDispatchTab('active')">
            Active Deliveries
            <?php if ($summary['active'] > 0): ?>
            <span class="tab-badge"><?= $summary['active'] ?></span>
            <?php endif; ?>
        </button>
        <button class="dispatch-tab <?= $currentTab === 'completed' ? 'active' : '' ?>" onclick="switchDispatchTab('completed')">
            Completed Today
            <?php if ($summary['completed'] > 0): ?>
            <span class="tab-badge tab-badge-green"><?= $summary['completed'] ?></span>
            <?php endif; ?>
        </button>
        <button class="dispatch-tab <?= $currentTab === 'issues' ? 'active' : '' ?>" onclick="switchDispatchTab('issues')">
            Issues
            <?php if ($issueCount > 0): ?>
            <span class="tab-badge tab-badge-red"><?= $issueCount ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- Bulk Action Bar (hidden until items selected) -->
    <div class="bulk-action-bar" id="bulkActionBar" style="display: none;">
        <span class="bulk-count"><span id="bulkCount">0</span> selected</span>
        <button class="bulk-btn bulk-btn-batch" onclick="batchSelected()"><?= ICON_PACKAGE ?> Batch Together</button>
        <div class="bulk-assign-group">
            <select id="bulkAssignCourier" class="bulk-assign-select">
                <option value="">Assign All &#9660;</option>
                <?php foreach ($couriers as $pin => $c): ?>
                <option value="<?= $pin ?>" data-name="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="bulk-btn bulk-btn-assign" onclick="assignAllSelected()">Assign</button>
        </div>
        <button class="bulk-btn bulk-btn-clear" onclick="clearSelection()">&#10005; Clear</button>
    </div>

    <!-- ============================================ -->
    <!-- TAB: READY QUEUE -->
    <!-- ============================================ -->
    <div class="dispatch-tab-content" id="tabReady" style="<?= $currentTab !== 'ready' ? 'display:none' : '' ?>">
        <!-- Batch Suggestions (loaded via AJAX) -->
        <div id="batchSuggestions" class="batch-suggestions" style="display:none;"></div>

        <?php if (empty($readyQueue)): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= ICON_PACKAGE ?></div>
            <div class="empty-text">No orders ready for dispatch</div>
            <div class="empty-subtext">Orders will appear here when vendors mark them as Ready to Ship</div>
        </div>
        <?php else: ?>
        <div class="dispatch-table-wrapper">
            <table class="dispatch-table">
                <thead>
                    <tr>
                        <th class="col-check"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
                        <th class="col-ref">Ref</th>
                        <th class="col-vendor">Vendor</th>
                        <th class="col-dest">Destination</th>
                        <th class="col-due">Due</th>
                        <th class="col-cost">Est. Cost</th>
                        <th class="col-pkg">Pkg</th>
                        <th class="col-courier">Courier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($readyQueue as $order): ?>
                    <tr class="queue-row <?= $order['due_info']['is_urgent'] ? 'row-urgent' : ($order['due_info']['is_priority'] ? 'row-priority' : '') ?>" data-ref="<?= htmlspecialchars($order['ref']) ?>">
                        <td class="col-check">
                            <input type="checkbox" class="order-check" value="<?= htmlspecialchars($order['ref']) ?>" onchange="updateBulkBar()">
                        </td>
                        <td class="col-ref">
                            <span class="ref-code"><?= htmlspecialchars($order['ref']) ?></span>
                            <?php if ($order['due_info']['is_urgent']): ?>
                            <span class="urgency-badge urgent">&#128308; URGENT</span>
                            <?php elseif ($order['due_info']['is_priority']): ?>
                            <span class="urgency-badge priority">&#128992; PRIORITY</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-vendor"><?= htmlspecialchars($order['vendor_name']) ?></td>
                        <td class="col-dest">
                            <span class="dest-label <?= $order['destination_type'] === 'mtcc' ? 'dest-mtcc' : 'dest-office' ?>">
                                <?= htmlspecialchars($order['destination']) ?>
                            </span>
                        </td>
                        <td class="col-due">
                            <div class="due-info">
                                <?php if ($order['due_info']['is_today']): ?>
                                <span class="due-today">Today</span>
                                <?php else: ?>
                                <span class="due-date"><?= htmlspecialchars($order['due_date']) ?></span>
                                <?php endif; ?>
                                <span class="due-time"><?= htmlspecialchars($order['due_time']) ?></span>
                            </div>
                        </td>
                        <td class="col-cost">
                            <span class="cost-estimate">$<?= number_format($order['est_cost'], 0) ?></span>
                        </td>
                        <td class="col-pkg">
                            <?php
                                $pkgInfo = $order['packaging'] ?? null;
                                if ($pkgInfo):
                                    $pkgIcons = ['tube'=>'&#128207;','flat_box'=>ICON_PACKAGE,'roll'=>'&#128220;','envelope'=>'&#9993;&#65039;'];
                                    $pkgIcon = $pkgIcons[$pkgInfo['type'] ?? ''] ?? ICON_PACKAGE;
                            ?>
                            <span class="pkg-badge"><?= $pkgIcon ?> <?= intval($pkgInfo['qty'] ?? 1) ?>x <?= ucfirst(str_replace('_',' ',$pkgInfo['type'] ?? '')) ?></span>
                            <?php else: ?>
                            <span class="text-muted">&#8212;</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-courier">
                            <select class="courier-select" data-ref="<?= htmlspecialchars($order['ref']) ?>" onchange="quickAssign(this)">
                                <option value="">— Assign —</option>
                                <?php foreach ($couriers as $pin => $c): ?>
                                <option value="<?= $pin ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
                                    <?= htmlspecialchars($c['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================ -->
    <!-- TAB: ACTIVE DELIVERIES -->
    <!-- ============================================ -->
    <div class="dispatch-tab-content" id="tabActive" style="<?= $currentTab !== 'active' ? 'display:none' : '' ?>">
        <?php if (empty($activeDeliveries) && empty($activeBatches)): ?>
        <div class="empty-state">
            <div class="empty-icon">&#128666;</div>
            <div class="empty-text">No active deliveries</div>
            <div class="empty-subtext">Dispatched orders will appear here grouped by courier</div>
        </div>
        <?php else: ?>

        <!-- Active Batches -->
        <?php foreach ($activeBatches as $batch): ?>
        <div class="batch-card" data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>">
            <div class="batch-card-header">
                <div class="batch-card-id">
                    <?= ICON_PACKAGE ?> <?= htmlspecialchars($batch['batch_id']) ?>
                    <span class="batch-order-count"><?= $batch['order_count'] ?> orders</span>
                </div>
                <div class="batch-card-meta">
                    <?php if (!empty($batch['courier_name'])): ?>
                    <span class="batch-courier">&#128100; <?= htmlspecialchars($batch['courier_name']) ?></span>
                    <?php else: ?>
                    <div class="batch-assign-inline">
                        <select class="courier-select batch-courier-select" data-batch-id="<?= htmlspecialchars($batch['batch_id']) ?>" onchange="assignBatchCourier(this)">
                            <option value="">&#8212; Assign Courier &#8212;</option>
                            <?php foreach ($couriers as $pin => $c): ?>
                            <option value="<?= $pin ?>" data-name="<?= htmlspecialchars($c['name']) ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <span class="batch-status batch-status-<?= $batch['status'] ?>">
                        <?= $batch['status'] === 'dispatched' ? 'Courier Assigned' : 'Pending' ?>
                    </span>
                </div>
            </div>
            <div class="batch-card-destinations">
                <?php foreach ($batch['destinations'] as $dest): ?>
                <span class="batch-dest-tag <?= $dest['type'] === 'mtcc' ? 'dest-mtcc' : 'dest-office' ?>">
                    <?= htmlspecialchars($dest['label']) ?>
                    <?php if ($dest['count'] > 1): ?>
                    <span class="batch-dest-count">&times;<?= $dest['count'] ?></span>
                    <?php endif; ?>
                </span>
                <?php endforeach; ?>
            </div>
            <div class="batch-card-orders">
                <?php foreach ($batch['orders'] as $bOrder): ?>
                <div class="batch-order-row">
                    <span class="ref-code"><?= htmlspecialchars($bOrder['ref']) ?></span>
                    <span class="batch-order-customer"><?= htmlspecialchars($bOrder['customer_name']) ?></span>
                    <span class="batch-order-dest"><?= htmlspecialchars($bOrder['destination']) ?></span>
                    <span class="batch-order-due"><?= htmlspecialchars($bOrder['due_date']) ?> <?= htmlspecialchars($bOrder['due_time']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (!empty($batch['notes'])): ?>
            <div class="batch-card-notes">&#128221; <?= htmlspecialchars($batch['notes']) ?></div>
            <?php endif; ?>
            <div class="batch-card-footer">
                <span class="batch-created">Created <?= date('g:i A', strtotime($batch['created_at'])) ?></span>
                <button class="btn-disband-batch" onclick="disbandBatch('<?= htmlspecialchars($batch['batch_id']) ?>')" title="Disband batch">Disband</button>
            </div>
        </div>
        <?php endforeach; ?>
        <?php foreach ($activeDeliveries as $group): ?>
        <div class="courier-group">
            <div class="courier-group-header">
                <span class="courier-group-indicator <?= $group['courier_id'] !== 'unassigned' ? 'online' : '' ?>">
                    <?= $group['courier_id'] !== 'unassigned' ? '&#128994;' : '&#9899;' ?>
                </span>
                <span class="courier-group-name"><?= htmlspecialchars($group['courier_name']) ?></span>
                <span class="courier-group-count"><?= $group['count'] ?> order<?= $group['count'] !== 1 ? 's' : '' ?></span>
                <?php if ($group['courier_id'] !== 'unassigned'): ?>
                <button class="btn-release-all" onclick="releaseAllCourier('<?= htmlspecialchars($group['courier_id']) ?>', '<?= htmlspecialchars($group['courier_name']) ?>')" title="Release all orders from this courier">Release All</button>
                <?php endif; ?>
            </div>
            <div class="courier-group-orders">
                <?php foreach ($group['orders'] as $order): ?>
                <div class="active-order-card<?= ($order['is_urgent'] ?? false) ? ' urgent' : '' ?>">
                    <div class="active-order-ref"><?= htmlspecialchars($order['ref']) ?></div>
                    <div class="active-order-customer"><?= htmlspecialchars($order['customer_name']) ?></div>
                    <div class="active-order-dest"><?= htmlspecialchars($order['destination']) ?></div>
                    <div class="active-order-status">
                        <span class="status-badge status-<?= $order['status'] ?>">
                            <?= $order['status'] === 'shipped' ? 'Shipped' : 'Courier Assigned' ?>
                        </span>
                    </div>
                    <div class="active-order-due-group">
                        <div class="active-order-due-date"><?= htmlspecialchars($order['due_date'] ?: 'No date') ?></div>
                        <div class="active-order-due-time"><?= htmlspecialchars($order['due_time'] ?: 'Anytime') ?></div>
                    </div>
                    <?php if (!empty($order['dispatched_at'])): ?>
                    <div class="active-order-dispatched">Sent <?= date('g:i A', strtotime($order['dispatched_at'])) ?></div>
                    <?php endif; ?>
                    <button class="btn-unassign-order" onclick="event.stopPropagation(); unassignOrder('<?= htmlspecialchars($order['ref']) ?>')" title="Unassign order">Unassign</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ============================================ -->
    <!-- TAB: COMPLETED TODAY -->
    <!-- ============================================ -->
    <div class="dispatch-tab-content" id="tabCompleted" style="<?= $currentTab !== 'completed' ? 'display:none' : '' ?>">
        <?php if (empty($completedToday)): ?>
        <div class="empty-state">
            <div class="empty-icon">&#9989;</div>
            <div class="empty-text">No deliveries completed today</div>
            <div class="empty-subtext">Completed orders will appear here as they are delivered or picked up</div>
        </div>
        <?php else: ?>
        <div class="dispatch-table-wrapper">
            <table class="dispatch-table">
                <thead>
                    <tr>
                        <th class="col-ref">Ref</th>
                        <th class="col-dest">Destination</th>
                        <th class="col-courier">Courier</th>
                        <th class="col-status">Status</th>
                        <th class="col-time">Completed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($completedToday as $order): ?>
                    <tr>
                        <td class="col-ref"><span class="ref-code"><?= htmlspecialchars($order['ref']) ?></span></td>
                        <td class="col-dest"><?= htmlspecialchars($order['destination']) ?></td>
                        <td class="col-courier"><?= htmlspecialchars($order['courier_name']) ?></td>
                        <td class="col-status">
                            <span class="status-badge status-<?= $order['status'] ?>">
                                <?= $order['status'] === 'pickedup' ? 'Picked Up' : 'Delivered' ?>
                            </span>
                        </td>
                        <td class="col-time">
                            <?php if ($order['completed_at']): ?>
                            <?= date('g:i A', strtotime($order['completed_at'])) ?>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>

    <!-- ============================================ -->
    <!-- BATCH BUILDER MODAL -->
    <!-- ============================================ -->
    <div class="batch-modal-overlay" id="batchModal" style="display:none;" onclick="closeBatchModal(event)">
        <div class="batch-modal" onclick="event.stopPropagation()">
            <div class="batch-modal-header">
                <h2 class="batch-modal-title"><?= ICON_PACKAGE ?> Batch Builder</h2>
                <button class="batch-modal-close" onclick="closeBatchModal()">&times;</button>
            </div>
            <div class="batch-modal-body">
                <!-- Batch ID -->
                <div class="batch-modal-row">
                    <label class="batch-modal-label">Batch ID</label>
                    <span class="batch-modal-id" id="batchModalId">B-001</span>
                </div>

                <!-- Route Summary -->
                <div class="batch-modal-section">
                    <h3 class="batch-modal-section-title">Route Summary</h3>
                    <div class="batch-modal-destinations" id="batchModalDests"></div>
                </div>

                <!-- Orders in Batch -->
                <div class="batch-modal-section">
                    <h3 class="batch-modal-section-title">Orders (<span id="batchModalCount">0</span>)</h3>
                    <div class="batch-modal-orders" id="batchModalOrders"></div>
                </div>

                <!-- Courier Assignment -->
                <div class="batch-modal-row">
                    <label class="batch-modal-label">Assign Courier</label>
                    <select id="batchModalCourier" class="batch-modal-select">
                        <option value="">&#8212; Assign Later &#8212;</option>
                        <?php foreach ($couriers as $pin => $c): ?>
                        <option value="<?= $pin ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Notes -->
                <div class="batch-modal-row">
                    <label class="batch-modal-label">Notes (optional)</label>
                    <input type="text" id="batchModalNotes" class="batch-modal-input" placeholder="e.g. Fragile items, use hand cart">
                </div>
            </div>
            <div class="batch-modal-footer">
                <button class="batch-modal-btn batch-modal-cancel" onclick="closeBatchModal()">Cancel</button>
                <button class="batch-modal-btn batch-modal-create" id="batchModalSubmit" onclick="submitBatch()">
                    <?= ICON_PACKAGE ?> Create Batch & Dispatch
                </button>
            </div>
        </div>
    </div>


    <!-- Issues Tab -->
    <div class="dispatch-tab-content" id="tabIssues" style="<?= $currentTab !== 'issues' ? 'display:none' : '' ?>">
        <?php if (empty($openIssues)): ?>
        <div class="empty-state">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            <h3>No Open Issues</h3>
            <p>All delivery issues have been resolved.</p>
        </div>
        <?php else: ?>
        <div class="issues-list">
            <?php foreach ($openIssues as $iss): ?>
            <?php $iss = issues_enrichWithOrderData($iss); ?>
            <div class="issue-card issue-severity-<?= htmlspecialchars(issues_getSeverity($iss['type'])) ?>" data-issue-id="<?= htmlspecialchars($iss['id']) ?>">
                <div class="issue-card-header">
                    <div class="issue-card-type">
                        <span class="issue-icon"><?= issues_getIcon($iss['type']) ?></span>
                        <span class="issue-label"><?= htmlspecialchars($iss['label']) ?></span>
                    </div>
                    <span class="issue-status-badge issue-status-<?= htmlspecialchars($iss['status']) ?>"><?= ucfirst(str_replace('_', ' ', $iss['status'])) ?></span>
                </div>
                <div class="issue-card-body">
                    <div class="issue-card-ref">
                        <strong><?= htmlspecialchars($iss['ref']) ?></strong>
                        <?php if (!empty($iss['customer_name'])): ?>
                        <span class="issue-customer"><?= htmlspecialchars($iss['customer_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="issue-card-meta">
                        <span>Reported by <?= htmlspecialchars($iss['reported_by']) ?></span>
                        <span><?= date('M j, g:ia', strtotime($iss['reported_at'])) ?></span>
                        <?php if (!empty($iss['event'])): ?>
                        <span><?= htmlspecialchars($iss['event']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($iss['notes'])): ?>
                    <div class="issue-card-notes"><?= htmlspecialchars($iss['notes']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($iss['photo'])): ?>
                    <div class="issue-card-photo">
                        <a href="../<?= htmlspecialchars($iss['photo']) ?>" target="_blank">
                            <img src="../<?= htmlspecialchars($iss['photo']) ?>" alt="Issue photo" loading="lazy">
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php if ($iss['retry_date']): ?>
                    <div class="issue-retry-date">Retry scheduled: <?= date('M j, g:ia', strtotime($iss['retry_date'])) ?></div>
                    <?php endif; ?>
                </div>
                <div class="issue-card-actions">
                    <?php if ($iss['status'] === 'open'): ?>
                    <button class="issue-action-btn issue-btn-review" onclick="reviewIssue('<?= htmlspecialchars($iss['id']) ?>')">Start Review</button>
                    <?php endif; ?>
                    <button class="issue-action-btn issue-btn-reprint" onclick="resolveIssue('<?= htmlspecialchars($iss['id']) ?>', 'reprint')">Reprint</button>
                    <button class="issue-action-btn issue-btn-retry" onclick="resolveIssue('<?= htmlspecialchars($iss['id']) ?>', 'retry')">Retry Delivery</button>
                    <button class="issue-action-btn issue-btn-refund" onclick="resolveIssue('<?= htmlspecialchars($iss['id']) ?>', 'refund')">Refund</button>
                    <button class="issue-action-btn issue-btn-dismiss" onclick="resolveIssue('<?= htmlspecialchars($iss['id']) ?>', 'no_action')">Dismiss</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

<!-- Pass data to JavaScript -->
<script>
    const DISPATCH_COURIERS = <?= json_encode($couriers) ?>;
    const DISPATCH_SETTINGS = <?= json_encode($settings) ?>;
</script>
<script src="dispatch-hub.js"></script>
<script src="../js/timer-utils.js"></script>
</body>
</html>
