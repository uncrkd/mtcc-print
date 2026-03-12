<?php
/**
 * Problem Detection Utilities - MTCC Poster System
 * Reusable functions for detecting order problems
 * 
 * Location: /includes/problem-detection.php
 */

// Default configuration
$PROBLEM_CONFIG = [
    'confirmation_overdue_hours' => 4,
    'confirmation_critical_hours' => 8,
    'past_due_warning_hours' => 24,
    'stuck_status_days' => 3,
    'uncollected_days' => 2,
    'max_reminders_threshold' => 3
];

/**
 * Get quick problem counts for dashboard display
 */
function getProblemCounts($ordersDir, $statusesFile, $preflightLogFile, $reminderLogFile = null, $eventsFile = null) {
    global $PROBLEM_CONFIG;
    
    $counts = [
        'critical' => 0,
        'overdue' => 0,
        'file_issues' => 0,
        'past_due' => 0,
        'uncollected' => 0,
        'stuck' => 0,
        'total' => 0
    ];
    
    $statuses = file_exists($statusesFile) ? json_decode(file_get_contents($statusesFile), true) : [];
    $preflightLog = file_exists($preflightLogFile) ? json_decode(file_get_contents($preflightLogFile), true) : ['entries' => []];
    $reminderLog = $reminderLogFile && file_exists($reminderLogFile) ? json_decode(file_get_contents($reminderLogFile), true) : ['reminders' => []];
    $events = $eventsFile && file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : ['events' => []];
    
    $eventLookup = [];
    foreach ($events['events'] ?? [] as $e) {
        $eventLookup[$e['code']] = $e;
    }
    
    $now = time();
    $orderFiles = glob($ordersDir . '*.json');
    
    foreach ($orderFiles as $file) {
        $order = json_decode(file_get_contents($file), true);
        if (!$order || !isset($order['referenceCode'])) continue;
        
        $refCode = $order['referenceCode'];
        $status = $statuses[$refCode] ?? 'new';
        $pfEntry = $preflightLog['entries'][$refCode] ?? null;
        
        if (in_array($status, ['complete', 'dispatched', 'picked_up', 'cancelled'])) continue;
        
        if ($status === 'preflight' && $pfEntry && empty($pfEntry['confirmed_at'])) {
            $pushedAt = strtotime($pfEntry['pushed_at']);
            $hoursSincePush = ($now - $pushedAt) / 3600;
            
            if ($hoursSincePush >= $PROBLEM_CONFIG['confirmation_critical_hours']) {
                $counts['critical']++;
            } elseif ($hoursSincePush >= $PROBLEM_CONFIG['confirmation_overdue_hours']) {
                $counts['overdue']++;
            }
        }
        
        if ($status === 'file_issue' || !empty($pfEntry['file_issue'])) {
            $counts['file_issues']++;
        }
        
        if (!empty($order['selectedDate'])) {
            $dueDate = strtotime($order['selectedDate']);
            $hoursPastDue = ($now - $dueDate) / 3600;
            if ($hoursPastDue >= $PROBLEM_CONFIG['past_due_warning_hours']) {
                $counts['past_due']++;
            }
        }
        
        $eventCode = $order['eventCode'] ?? null;
        $event = $eventCode ? ($eventLookup[$eventCode] ?? null) : null;
        if ($event && !empty($event['end_date']) && in_array($status, ['ready', 'ready_for_pickup'])) {
            $eventEnd = strtotime($event['end_date']);
            $daysSinceEventEnd = ($now - $eventEnd) / 86400;
            if ($daysSinceEventEnd >= $PROBLEM_CONFIG['uncollected_days']) {
                $counts['uncollected']++;
            }
        }
        
        $statusHistory = $order['statusHistory'] ?? [];
        if (!empty($statusHistory)) {
            $lastChange = end($statusHistory);
            $lastChangeTime = strtotime($lastChange['timestamp'] ?? $order['submittedAt']);
            $daysSinceChange = ($now - $lastChangeTime) / 86400;
            if ($daysSinceChange >= $PROBLEM_CONFIG['stuck_status_days']) {
                $counts['stuck']++;
            }
        }
    }
    
    $counts['total'] = $counts['critical'] + $counts['overdue'] + $counts['file_issues'] + 
                       $counts['past_due'] + $counts['uncollected'] + $counts['stuck'];
    
    return $counts;
}

/**
 * Render a compact problem summary widget
 */
function renderProblemWidget($counts, $linkUrl = 'problem-orders.php') {
    if ($counts['total'] === 0) {
        return '<div class="problem-widget all-clear">
            <span class="widget-icon">✅</span>
            <span class="widget-text">No issues detected</span>
        </div>';
    }
    
    $criticalTotal = $counts['critical'] + $counts['file_issues'] + $counts['past_due'];
    $warningTotal = $counts['overdue'] + $counts['uncollected'];
    
    $html = '<a href="' . htmlspecialchars($linkUrl) . '" class="problem-widget has-problems">';
    
    if ($criticalTotal > 0) {
        $html .= '<span class="widget-badge critical">🚨 ' . $criticalTotal . ' Critical</span>';
    }
    
    if ($warningTotal > 0) {
        $html .= '<span class="widget-badge warning">⚠️ ' . $warningTotal . ' Warning</span>';
    }
    
    if ($counts['stuck'] > 0) {
        $html .= '<span class="widget-badge info">🔄 ' . $counts['stuck'] . ' Stuck</span>';
    }
    
    $html .= '</a>';
    
    return $html;
}

/**
 * Get CSS for problem widget
 */
function getProblemWidgetCSS() {
    return '
    <style>
    .problem-widget {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        transition: all 0.2s;
    }
    .problem-widget.all-clear { background: #d1fae5; color: #065f46; }
    .problem-widget.has-problems { background: #fef3c7; border: 1px solid #fcd34d; }
    .problem-widget.has-problems:hover { background: #fde68a; transform: translateY(-1px); }
    .widget-icon { font-size: 16px; }
    .widget-badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 8px;
        border-radius: 4px;
        font-size: 12px;
        font-weight: 600;
    }
    .widget-badge.critical { background: #fee2e2; color: #dc2626; }
    .widget-badge.warning { background: #fef3c7; color: #92400e; }
    .widget-badge.info { background: #dbeafe; color: #1d4ed8; }
    </style>';
}

/**
 * Get dispatch problems with full data for problem pages
 * Returns enriched entries with event_code, timestamps, and tier data
 * 
 * @return array ['ready_stale' => [], 'delivery_overdue' => [], 'unresolved_issues' => [], 'counts' => [...]]
 */
function getDispatchProblems($ordersDir, $statusesFile, $issuesFile = null) {
    $statuses = file_exists($statusesFile) ? json_decode(file_get_contents($statusesFile), true) : [];
    $issues = [];
    if ($issuesFile && file_exists($issuesFile)) {
        $issueData = json_decode(file_get_contents($issuesFile), true) ?: [];
        $issues = $issueData['issues'] ?? [];
    }
    
    $now = time();
    $readyStale = [];
    $deliveryOverdue = [];
    $unresolvedIssues = [];
    
    // Unresolved delivery issues
    foreach ($issues as $issue) {
        $status = $issue['status'] ?? 'open';
        if ($status === 'open' || $status === 'escalated') {
            $reportedTs = !empty($issue['reported_at']) ? strtotime($issue['reported_at']) : 0;
            $unresolvedIssues[] = [
                'ref' => $issue['reference_code'] ?? 'Unknown',
                'type' => $issue['issue_type'] ?? 'unknown',
                'reported_by' => $issue['reported_by_name'] ?? 'Unknown',
                'reported_at' => $issue['reported_at'] ?? null,
                'reported_at_ts' => $reportedTs,
                'hours_open' => $reportedTs ? round(($now - $reportedTs) / 3600, 1) : 0,
                'severity' => $status === 'escalated' ? 'critical' : 'warning',
            ];
        }
    }
    
    // Scan orders for ready-stale and delivery-overdue
    $orderFiles = glob($ordersDir . '*.json');
    foreach ($orderFiles as $file) {
        $order = json_decode(file_get_contents($file), true);
        if (!$order || !isset($order['referenceCode'])) continue;
        
        $ref = $order['referenceCode'];
        $status = $statuses[$ref] ?? 'new';
        $dispatch = $order['dispatch'] ?? [];
        $eventCode = $order['eventCode'] ?? null;
        
        // Common fields for enrichment
        $baseFields = [
            'event_code' => $eventCode,
            'tier' => $order['pricing']['tier'] ?? 'standard',
            'material' => $order['material'] ?? 'paper',
            'status' => $status,
            'created_at' => $order['submittedAt'] ?? null,
        ];
        
        // Ready but not dispatched for 4+ hours
        if ($status === 'ready') {
            $readyAt = null;
            foreach ($order['statusHistory'] ?? [] as $sh) {
                if (($sh['status'] ?? '') === 'ready' || ($sh['newStatus'] ?? '') === 'ready') {
                    $readyAt = strtotime($sh['timestamp'] ?? '');
                }
            }
            if (!$readyAt && !empty($dispatch['ready_at'])) {
                $readyAt = strtotime($dispatch['ready_at']);
            }
            if ($readyAt) {
                $hoursSinceReady = ($now - $readyAt) / 3600;
                if ($hoursSinceReady >= 4) {
                    $readyStale[] = array_merge($baseFields, [
                        'ref' => $ref,
                        'customer' => $order['customerInfo']['name'] ?? '',
                        'due_date' => $order['selectedDate'] ?? null,
                        'hours_waiting' => round($hoursSinceReady, 1),
                        'ready_since_ts' => $readyAt,
                        'severity' => $hoursSinceReady >= 8 ? 'critical' : 'warning',
                    ]);
                }
            }
        }
        
        // Shipped but past customer deadline by 2+ hours
        if ($status === 'shipped' || $status === 'dispatched') {
            $dueDate = $order['selectedDate'] ?? null;
            $dueTime = $order['deliveryTime'] ?? 'anytime';
            if ($dueDate) {
                $timeMap = ['9am' => 9, '12pm' => 12, '3pm' => 15, '6pm' => 18, 'anytime' => 18];
                $dueHour = $timeMap[$dueTime] ?? 18;
                $deadlineTs = strtotime($dueDate . ' ' . sprintf('%02d:00:00', $dueHour));
                $hoursPastDue = ($now - $deadlineTs) / 3600;
                
                if ($hoursPastDue >= 2) {
                    $deliveryOverdue[] = array_merge($baseFields, [
                        'ref' => $ref,
                        'customer' => $order['customerInfo']['name'] ?? '',
                        'courier' => $dispatch['courier_name'] ?? 'Unknown',
                        'due_date' => $dueDate,
                        'deadline_ts' => $deadlineTs,
                        'hours_overdue' => round($hoursPastDue, 1),
                        'severity' => $hoursPastDue >= 6 ? 'critical' : 'warning',
                    ]);
                }
            }
        }
        
        // Enrich unresolved issues with order data
        foreach ($unresolvedIssues as &$ui) {
            if (($ui['ref'] ?? '') === $ref) {
                $ui['customer'] = $order['customerInfo']['name'] ?? '';
                $ui['tier'] = $order['pricing']['tier'] ?? 'standard';
                $ui['event_code'] = $eventCode;
            }
        }
        unset($ui);
    }
    
    // Sort by severity then hours
    usort($readyStale, fn($a, $b) => $b['hours_waiting'] <=> $a['hours_waiting']);
    usort($deliveryOverdue, fn($a, $b) => $b['hours_overdue'] <=> $a['hours_overdue']);
    usort($unresolvedIssues, fn($a, $b) => $b['hours_open'] <=> $a['hours_open']);
    
    return [
        'ready_stale' => $readyStale,
        'delivery_overdue' => $deliveryOverdue,
        'unresolved_issues' => $unresolvedIssues,
        'counts' => [
            'ready_stale' => count($readyStale),
            'delivery_overdue' => count($deliveryOverdue),
            'unresolved_issues' => count($unresolvedIssues),
            'total' => count($readyStale) + count($deliveryOverdue) + count($unresolvedIssues),
        ],
    ];
}
