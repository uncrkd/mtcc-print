<?php
/**
 * Activity Log Viewer
 * View system activity and admin actions
 * 
 * Location: /logs/index.php
 */

// Include from parent directory
require_once '../includes/icons.php';
require_once '../admin-auth.php';

// Require activity log permission
requirePermission('activity_log');

// Load activity log - check multiple possible locations
$logFile = __DIR__ . '/../data/activity-log.json';  // First check /logs/activity-log.json
if (!file_exists($logFile)) {
    $logFile = __DIR__ . '/../data/activity-log.json';  // Then check root
}
if (!file_exists($logFile)) {
    $logFile = __DIR__ . '/../data/activity-log.json';  // Also check if path is different
}

$activityLog = [];
if (file_exists($logFile)) {
    $data = json_decode(file_get_contents($logFile), true);
    $activityLog = $data['entries'] ?? [];
}

// Sort by timestamp descending (newest first)
usort($activityLog, function($a, $b) {
    return strtotime($b['timestamp'] ?? '0') - strtotime($a['timestamp'] ?? '0');
});

// Get unique users and actions for filters
$uniqueUsers = array_unique(array_filter(array_column($activityLog, 'username')));
$uniqueActions = array_unique(array_filter(array_column($activityLog, 'action')));
sort($uniqueUsers);
sort($uniqueActions);

// Filter options
$filterUser = $_GET['user'] ?? '';
$filterAction = $_GET['action'] ?? '';
$filterDate = $_GET['date'] ?? '';

// Apply filters
$filteredLog = $activityLog;
if ($filterUser || $filterAction || $filterDate) {
    $filteredLog = array_filter($activityLog, function($entry) use ($filterUser, $filterAction, $filterDate) {
        if ($filterUser && ($entry['username'] ?? '') !== $filterUser) return false;
        if ($filterAction && ($entry['action'] ?? '') !== $filterAction) return false;
        if ($filterDate && substr($entry['timestamp'] ?? '', 0, 10) !== $filterDate) return false;
        return true;
    });
    $filteredLog = array_values($filteredLog);
}

// Pagination
$perPage = 50;
$totalEntries = count($filteredLog);
$totalPages = max(1, ceil($totalEntries / $perPage));
$currentPage = max(1, min($totalPages, intval($_GET['page'] ?? 1)));
$offset = ($currentPage - 1) * $perPage;
$pagedLog = array_slice($filteredLog, $offset, $perPage);

// Format action for display
function formatAction($action) {
    $actions = [
        'login_success' => ['Login', 'success'],
        'login_failed' => ['Login Failed', 'danger'],
        'logout' => ['Logout', 'info'],
        'order_created' => ['Order Created', 'success'],
        'order_updated' => ['Order Updated', 'info'],
        'order_deleted' => ['Order Deleted', 'danger'],
        'status_changed' => ['Status Changed', 'warning'],
        'User Created' => ['User Created', 'success'],
        'User Updated' => ['User Updated', 'info'],
        'User Edited' => ['User Edited', 'info'],
        'User Deleted' => ['User Deleted', 'danger'],
        'password_changed' => ['Password Changed', 'warning'],
        'payment_sent' => ['Payment Link Sent', 'info'],
        'refund_processed' => ['Refund Processed', 'warning'],
    ];
    return $actions[$action] ?? [ucwords(str_replace('_', ' ', $action)), 'default'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #7c3aed;
            --primary-dark: #6d28d9;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --radius: 12px;
            --radius-sm: 8px;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-md: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            line-height: 1.5;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px 0;
        }
        
        /* Page Header */
        .page-header {
            background: linear-gradient(90deg, rgba(64, 0, 128, 1) 0%, rgba(115, 0, 196, 1) 100%);
            color: white;
            padding: 12px 24px;
            border-radius: var(--radius);
            margin-bottom: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-md);
        }
        
        .page-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .page-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .page-subtitle {
            opacity: 0.85;
            font-size: 0.9rem;
            padding-left: 16px;
            border-left: 1px solid rgba(255,255,255,0.3);
        }
        
        .page-welcome {
    display: flex;
    flex-direction: column;
    padding-left: 24px;
    border-left: 1px solid rgba(255, 255, 255, 0.2);
}

.welcome-text {
    font-size: 0.875rem;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
}

.welcome-date {
    font-size: 0.75rem;
    color: rgba(255, 255, 255, 0.6);
}
        /* Filters Card - Attached to header */
        .filters-card {
            background: white;
            border-radius: var(--radius) var(--radius) 0 0;
            padding: 16px 24px;
            box-shadow: var(--shadow);
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
            border-bottom: 1px solid var(--gray-200);
            
        }
        
        .filter-group { display: flex; flex-direction: column; gap: 4px; }
        
        .filter-label {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.875rem;
            background: white;
            min-width: 140px;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        
        .filter-btn {
            padding: 8px 16px;
            border: none;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        
        .filter-btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .filter-btn-primary:hover { background: var(--primary-dark); }
        
        .filter-btn-clear {
            background: var(--gray-200);
            color: var(--gray-700);
        }
        
        .filter-btn-clear:hover { background: var(--gray-300); }
        
        /* Stats Row */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
            background: white;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .stat-card {
            padding: 16px 24px;
            border-right: 1px solid var(--gray-200);
        }
        
        .stat-card:last-child { border-right: none; }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .stat-label {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 2px;
        }
        
        /* Log Table Card */
        .log-card {
            background: white;
            border-radius: 0 0 var(--radius) var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .log-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .log-table th {
            background: var(--gray-50);
            padding: 12px 16px;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            border-bottom: 1px solid var(--gray-200);
        }
        
        .log-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--gray-100);
            vertical-align: top;
        }
        
        .log-table tr:hover td { background: var(--gray-50); }
        .log-table tr:last-child td { border-bottom: none; }
        
        /* Action Badge */
        .action-badge {
            display: inline-flex;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .action-badge.success { background: #d1fae5; color: #065f46; }
        .action-badge.danger { background: #fee2e2; color: #991b1b; }
        .action-badge.warning { background: #fef3c7; color: #92400e; }
        .action-badge.info { background: #dbeafe; color: #1e40af; }
        .action-badge.default { background: var(--gray-100); color: var(--gray-600); }
        
        /* User Info */
        .user-info { display: flex; flex-direction: column; }
        .user-name { font-weight: 600; color: var(--gray-800); }
        .user-role { font-size: 0.75rem; color: var(--gray-500); }
        
        /* Timestamp */
        .timestamp { color: var(--gray-500); font-size: 0.8rem; }
        .timestamp-date { font-weight: 600; color: var(--gray-700); }
        
        /* Details */
        .details-cell { max-width: 300px; }
        .details-text { font-size: 0.8rem; color: var(--gray-600); word-break: break-word; }
        
        .order-ref {
            display: inline-flex;
            padding: 2px 8px;
            background: #ede9fe;
            color: var(--primary);
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-decoration: none;
        }
        .order-ref:hover { background: var(--primary); color: white; }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: var(--gray-50);
            border-top: 1px solid var(--gray-200);
        }
        
        .pagination-info { font-size: 0.875rem; color: var(--gray-500); }
        .pagination-buttons { display: flex; gap: 8px; }
        
        .page-btn {
            padding: 8px 14px;
            border: 1px solid var(--gray-300);
            background: white;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: 0.875rem;
            color: var(--gray-600);
            cursor: pointer;
            text-decoration: none;
        }
        
        .page-btn:hover { background: var(--gray-100); }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        
        /* Empty State */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--gray-500); }
        .empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.5; }
        .empty-title { font-size: 1.25rem; font-weight: 600; color: var(--gray-700); margin-bottom: 8px; }
        
        /* Debug info */
        .debug-info {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 16px;
            font-size: 0.8rem;
            color: #92400e;
        }
        
        @media (max-width: 768px) {
            .page-header { flex-direction: column; gap: 12px; align-items: flex-start; }
            .page-header-left { flex-direction: column; align-items: flex-start; gap: 8px; }
            .page-subtitle { padding-left: 0; border-left: none; }
            .filters-card { flex-direction: column; align-items: stretch; }
            .stats-row { grid-template-columns: 1fr; }
            .stat-card { border-right: none; border-bottom: 1px solid var(--gray-200); }
            .stat-card:last-child { border-bottom: none; }
            .log-table { font-size: 0.8rem; }
        }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('activity_log'); ?>
<script src="../js/admin-sidebar.js"></script>

<div class="container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1 class="page-title">Activity Log</h1>
            <div class="page-welcome">
                    <span class="welcome-text">Welcome Admin! &#128075;</span>
                    <span class="welcome-date">Today is <?= date('l, M j Y') ?></span>
                </div>
        </div>
    </div>
    
    <!-- Filters -->
    <form class="filters-card" method="GET">
        <div class="filter-group">
            <label class="filter-label">User</label>
            <select name="user" class="filter-select">
                <option value="">All Users</option>
                <?php foreach ($uniqueUsers as $user): ?>
                <option value="<?= htmlspecialchars($user) ?>" <?= $filterUser === $user ? 'selected' : '' ?>><?= htmlspecialchars($user) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Action</label>
            <select name="action" class="filter-select">
                <option value="">All Actions</option>
                <?php foreach ($uniqueActions as $action): ?>
                <option value="<?= htmlspecialchars($action) ?>" <?= $filterAction === $action ? 'selected' : '' ?>><?= htmlspecialchars(ucwords(str_replace('_', ' ', $action))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label class="filter-label">Date</label>
            <input type="date" name="date" class="filter-input" value="<?= htmlspecialchars($filterDate) ?>">
        </div>
        <button type="submit" class="filter-btn filter-btn-primary"><?= ICON_SEARCH ?> Apply</button>
        <a href="?" class="filter-btn filter-btn-clear">Clear</a>
    </form>
    
    <!-- Stats -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($totalEntries) ?></div>
            <div class="stat-label">Total Entries<?= ($filterUser || $filterAction || $filterDate) ? ' (filtered)' : '' ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count($uniqueUsers) ?></div>
            <div class="stat-label">Active Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count($uniqueActions) ?></div>
            <div class="stat-label">Action Types</div>
        </div>
    </div>
    
    <!-- Log Table -->
    <div class="log-card">
        <?php if (empty($pagedLog)): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= ICON_CLIPBOARD ?></div>
            <div class="empty-title">No Activity Found</div>
            <p>No activity log entries match your filters.</p>
            <?php if (empty($activityLog)): ?>
            <p style="margin-top: 12px; font-size: 0.8rem;">
                Activity log file location: <?= htmlspecialchars($logFile) ?><br>
                File exists: <?= file_exists($logFile) ? 'Yes' : 'No' ?>
            </p>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <table class="log-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Details</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagedLog as $entry): ?>
                <?php 
                    $actionInfo = formatAction($entry['action'] ?? '');
                    $timestamp = strtotime($entry['timestamp'] ?? 'now');
                ?>
                <tr>
                    <td>
                        <div class="timestamp">
                            <div class="timestamp-date"><?= date('M j, Y', $timestamp) ?></div>
                            <?= date('g:i:s A', $timestamp) ?>
                        </div>
                    </td>
                    <td>
                        <div class="user-info">
                            <span class="user-name"><?= htmlspecialchars($entry['name'] ?? $entry['username'] ?? 'Unknown') ?></span>
                            <span class="user-role"><?= htmlspecialchars($entry['role'] ?? 'Unknown') ?></span>
                        </div>
                    </td>
                    <td>
                        <span class="action-badge <?= $actionInfo[1] ?>"><?= htmlspecialchars($actionInfo[0]) ?></span>
                    </td>
                    <td class="details-cell">
                        <?php if (!empty($entry['order_ref'])): ?>
                        <a href="../admin-orders.php?view=<?= urlencode($entry['order_ref']) ?>" class="order-ref">
                            <?= htmlspecialchars($entry['order_ref']) ?>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($entry['details'])): ?>
                        <div class="details-text">
                            <?php 
                            if (is_array($entry['details'])) {
                                $parts = [];
                                foreach ($entry['details'] as $key => $val) {
                                    if (!is_array($val) && $val !== null && $val !== '') {
                                        $parts[] = ucwords(str_replace('_', ' ', $key)) . ': ' . $val;
                                    }
                                }
                                echo htmlspecialchars(implode(' | ', array_slice($parts, 0, 3)));
                            } else {
                                echo htmlspecialchars($entry['details']);
                            }
                            ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="timestamp"><?= htmlspecialchars($entry['ip'] ?? '-') ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <div class="pagination-info">
                Showing <?= $offset + 1 ?> - <?= min($offset + $perPage, $totalEntries) ?> of <?= number_format($totalEntries) ?> entries
            </div>
            <div class="pagination-buttons">
                <?php if ($currentPage > 1): ?>
                <a href="?page=<?= $currentPage - 1 ?>&user=<?= urlencode($filterUser) ?>&action=<?= urlencode($filterAction) ?>&date=<?= urlencode($filterDate) ?>" class="page-btn">&larr; Prev</a>
                <?php endif; ?>
                
                <?php 
                $startPage = max(1, $currentPage - 2);
                $endPage = min($totalPages, $currentPage + 2);
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                <a href="?page=<?= $i ?>&user=<?= urlencode($filterUser) ?>&action=<?= urlencode($filterAction) ?>&date=<?= urlencode($filterDate) ?>" class="page-btn <?= $i === $currentPage ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($currentPage < $totalPages): ?>
                <a href="?page=<?= $currentPage + 1 ?>&user=<?= urlencode($filterUser) ?>&action=<?= urlencode($filterAction) ?>&date=<?= urlencode($filterDate) ?>" class="page-btn">Next &rarr;</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
