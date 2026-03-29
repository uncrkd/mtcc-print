<?php
/**
 * Problem Data - Shared Detection Logic
 * Loads and detects all problem orders (production + dispatch)
 * 
 * Location: /includes/problem-data.php
 * 
 * Returns: $prodProblems, $dispatchProblems, $prodTotal, $prodCritical,
 *          $dispatchTotal, $dispatchCritical, $grandTotal, $grandCritical,
 */

$basePath = '../';
$ordersDir = $basePath . 'uploads/orders/';
$statusesFile = $basePath . 'data/statuses.json';
$preflightLogFile = $basePath . 'data/preflight-log.json';
$vendorsFile = $basePath . 'data/vendors.json';
$tokensFile = $basePath . 'data/vendor-tokens.json';
$reminderLogFile = $basePath . 'data/reminder-log.json';
$eventsFile = $basePath . 'admin/events.json';
$issuesFile = $basePath . 'data/delivery-issues.json';
$notesFile = $basePath . 'data/problem-notes.json';
$snapshotFile = $basePath . 'data/problem-snapshot.json';
$resolutionFile = $basePath . 'data/problem-resolutions.json';

// Since-last-visit tracking (capture previous, update to now)
$lastVisitTime = $_SESSION['problem_last_visit'] ?? 0;
$_SESSION['problem_last_visit'] = time();

// Load notes for display
$allNotes = file_exists($notesFile) ? json_decode(file_get_contents($notesFile), true) : [];

// Load previous problem snapshot for resolution tracking
$prevSnapshot = file_exists($snapshotFile) ? json_decode(file_get_contents($snapshotFile), true) : [];

// Config
$problemConfig = [
    'confirmation_overdue_hours' => 4,
    'confirmation_critical_hours' => 8,
    'past_due_warning_hours' => 24,
    'stuck_status_days' => 3,
    'uncollected_days' => 2,
    'max_reminders_threshold' => 3
];

// Event filter from query string
$eventFilter = $_GET['event'] ?? '';

// Load helpers
function probLoadJson($file) {
    if (!file_exists($file)) return null;
    return json_decode(file_get_contents($file), true);
}

$statuses = probLoadJson($statusesFile) ?? [];
$preflightLog = probLoadJson($preflightLogFile) ?? ['entries' => []];
$vendors = probLoadJson($vendorsFile) ?? ['vendors' => []];
$tokens = probLoadJson($tokensFile) ?? ['tokens' => []];
$reminderLog = probLoadJson($reminderLogFile) ?? ['reminders' => []];
$eventsData = probLoadJson($eventsFile) ?? ['events' => []];

$vendorLookup = [];
foreach ($vendors['vendors'] ?? [] as $v) {
    $vendorLookup[$v['id']] = $v;
}

$eventLookup = [];
foreach ($eventsData['events'] ?? [] as $e) {
    $eventLookup[$e['code']] = $e;
}

// Build available events list (for filter dropdown)
$availableEvents = [];
foreach ($eventsData['events'] ?? [] as $e) {
    $availableEvents[$e['code']] = $e['name'] ?? $e['code'];
}
asort($availableEvents);

// ============================================
// HELPER FUNCTIONS
// ============================================

function tierBadge($tier) {
    $t = strtolower($tier);
    $cls = 'priority-tier-standard';
    if (strpos($t, 'last minute') !== false || strpos($t, 'sameday') !== false || $t === 'same day') $cls = 'priority-tier-lastminute';
    elseif (strpos($t, 'critical') !== false || strpos($t, 'nextday') !== false || $t === 'next day') $cls = 'priority-tier-critical';
    elseif (strpos($t, 'urgent') !== false || strpos($t, '2days') !== false || $t === '2 days') $cls = 'priority-tier-urgent';
    elseif (strpos($t, 'rush') !== false || strpos($t, '3days') !== false || $t === '3 days') $cls = 'priority-tier-rush';
    elseif (strpos($t, 'early') !== false) $cls = 'priority-tier-early';
    return '<span class="priority-tier-badge ' . $cls . '">' . htmlspecialchars($tier) . '</span>';
}

function severityBadge($severity) {
    $cls = $severity === 'critical' ? 'status-cancelled' : 'status-file_issue';
    $label = $severity === 'critical' ? 'Critical' : 'Warning';
    return '<span class="status-badge ' . $cls . '">' . $label . '</span>';
}

function timerCell($timestamp, $entry = null) {
    $ts = is_numeric($timestamp) ? (int)$timestamp : strtotime($timestamp ?? '');
    if (!$ts) return '<td>&mdash;</td>';
    return '<td><span class="prob-timer" data-ts="' . $ts . '">loading...</span></td>';
}

function actionButtons($refCode, $showReminder = false, $noteCount = 0) {
    $searchUrl = '../admin-orders.php?search=' . urlencode($refCode);
    $html = '<td class="prob-actions">';
    $html .= noteIcon($refCode, $noteCount);
    $html .= '<a href="' . $searchUrl . '" class="prob-action-btn" title="View Order">View</a>';
    if ($showReminder) {
        $html .= '<a href="' . $searchUrl . '&action=remind" class="prob-action-btn reminder" title="Send Reminder">Remind</a>';
    }
    $html .= '</td>';
    return $html;
}

function rowSeverityClass($entry) {
    $hours = $entry['hours_waiting'] ?? $entry['hours_past_due'] ?? $entry['hours_open'] ?? 0;
    $days = $entry['days_in_status'] ?? $entry['days_uncollected'] ?? $entry['days_past_due'] ?? 0;
    if ($hours >= 24 || $days >= 3) return 'row-critical';
    if ($hours >= 8 || $days >= 1) return 'row-warning';
    return '';
}

function checkboxCell($refCode) {
    return '<td class="prob-checkbox-cell"><input type="checkbox" class="prob-row-checkbox" value="' . htmlspecialchars($refCode) . '"></td>';
}

function noteIcon($refCode, $noteCount) {
    $badge = $noteCount > 0 ? '<span class="note-badge">' . $noteCount . '</span>' : '';
    $cls = $noteCount > 0 ? 'has-notes' : '';
    return '<button class="prob-note-btn ' . $cls . '" onclick="toggleNotes(\'' . htmlspecialchars($refCode) . '\', this)" title="Notes">'
         . '&#128221;' . $badge . '</button>';
}

function escalationClass($entry) {
    $esc = $entry['escalation'] ?? 'none';
    if ($esc === 'none') return '';
    return 'escalation-' . $esc;
}

function newBadge($entry) {
    if (!empty($entry['is_new'])) {
        return '<span class="prob-new-badge">NEW</span>';
    }
    return '';
}

function renderNewProblemsBanner($count) {
    if ($count <= 0) return '';
    $s = $count === 1 ? '' : 's';
    return '<div class="prob-new-banner" id="newProblemsBanner">'
         . '<span class="prob-new-banner-icon">&#128308;</span>'
         . '<span><strong>' . $count . '</strong> new problem' . $s . ' since your last visit</span>'
         . '<button class="prob-new-banner-dismiss" onclick="this.parentElement.remove()">Dismiss</button>'
         . '</div>';
}

function renderResolutionBar($recentResolutions) {
    $count = count($recentResolutions);
    if ($count === 0) return '';
    $totalHours = array_sum(array_column($recentResolutions, 'duration_hours'));
    $avgHours = round($totalHours / $count, 1);
    if ($avgHours >= 24) {
        $avgDisplay = round($avgHours / 24, 1) . 'd';
    } else {
        $avgDisplay = $avgHours . 'h';
    }
    return '<div class="prob-resolution-bar">'
         . '<span class="prob-resolution-icon">&#9989;</span>'
         . '<span class="prob-resolution-text"><strong>' . $count . '</strong> problem' . ($count !== 1 ? 's' : '') . ' resolved in the last 24h</span>'
         . '<span class="prob-resolution-avg">Avg resolution: <strong>' . $avgDisplay . '</strong></span>'
         . '</div>';
}

// ============================================
// PRODUCTION PROBLEMS
// ============================================
$prodProblems = [
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
$orderFiles = glob($ordersDir . '*.json');
$allOrders = [];
$dispatchPastDue = [];
$dispatchStuck = [];

// Age distribution - simplified to 4 groups

foreach ($orderFiles as $file) {
    $order = json_decode(file_get_contents($file), true);
    if ($order && isset($order['referenceCode'])) {
        $allOrders[$order['referenceCode']] = $order;
    }
}

foreach ($allOrders as $refCode => $order) {
    $status = $statuses[$refCode] ?? 'new';
    $pfEntry = ($preflightLog['entries'] ?? [])[$refCode] ?? null;
    $reminders = ($reminderLog['reminders'] ?? [])[$refCode] ?? [];
    $eventCode = $order['eventCode'] ?? null;
    $event = $eventCode ? ($eventLookup[$eventCode] ?? null) : null;

    // Event filter: skip non-matching events
    if ($eventFilter !== '' && $eventCode !== $eventFilter) continue;

    // Skip completed/cancelled
    if (in_array($status, ['complete', 'dispatched', 'picked_up', 'cancelled'])) continue;

    $entry = [
        'reference_code' => $refCode,
        'customer_name' => $order['customerInfo']['name'] ?? 'Unknown',
        'customer_email' => $order['customerInfo']['email'] ?? '',
        'dimensions' => ($order['dimensions']['width'] ?? 0) . '" x ' . ($order['dimensions']['height'] ?? 0) . '"',
        'material' => $order['material'] ?? 'paper',
        'due_date' => $order['selectedDate'] ?? null,
        'due_date_ts' => !empty($order['selectedDate']) ? strtotime($order['selectedDate']) : 0,
        'event_code' => $eventCode,
        'event_name' => $event['name'] ?? $eventCode ?? '',
        'status' => $status,
        'created_at' => $order['submittedAt'] ?? null,
        'tier' => $order['pricing']['tier'] ?? 'standard'
    ];

    // Track problem age (based on created_at)

    // Confirmation Overdue/Critical
    if ($status === 'preflight' && $pfEntry && empty($pfEntry['confirmed_at'])) {
        $pushedAt = strtotime($pfEntry['pushed_at'] ?? '');
        if ($pushedAt) {
            $hoursSincePush = ($now - $pushedAt) / 3600;
            $entry['hours_waiting'] = round($hoursSincePush, 1);
            $entry['pushed_at'] = $pfEntry['pushed_at'];
            $entry['pushed_at_ts'] = $pushedAt;
            $entry['vendor_id'] = $pfEntry['vendor_id'] ?? null;
            $entry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
            $entry['reminder_count'] = count($reminders);

            if ($hoursSincePush >= $problemConfig['confirmation_critical_hours']) {
                $prodProblems['confirmation_critical'][] = $entry;
            } elseif ($hoursSincePush >= $problemConfig['confirmation_overdue_hours']) {
                $prodProblems['confirmation_overdue'][] = $entry;
            }
        }
    }

    // File Issues
    if ($status === 'file_issue' || !empty($pfEntry['file_issue'])) {
        $issue = $pfEntry['file_issue'] ?? [];
        $entry['issue_type'] = $issue['type'] ?? 'unknown';
        $entry['issue_description'] = $issue['description'] ?? '';
        $entry['reported_at'] = $issue['reported_at'] ?? null;
        $entry['reported_at_ts'] = !empty($issue['reported_at']) ? strtotime($issue['reported_at']) : 0;
        $entry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
        $prodProblems['file_issues'][] = $entry;
    }

    // Past Due — split by pipeline stage
    $dispatchStatuses = ['ready', 'ready_for_pickup', 'shipped'];
    if (!empty($order['selectedDate'])) {
        $dueDate = strtotime($order['selectedDate']);
        if ($dueDate) {
            $hoursPastDue = ($now - $dueDate) / 3600;
            if ($hoursPastDue >= $problemConfig['past_due_warning_hours']) {
                $entry['hours_past_due'] = round($hoursPastDue, 1);
                $entry['days_past_due'] = round($hoursPastDue / 24, 1);
                if (in_array($status, $dispatchStatuses)) {
                    $dispatchPastDue[] = $entry;
                } else {
                    $prodProblems['past_due'][] = $entry;
                }
            }
        }
    }

    // Stuck in Status — split by pipeline stage
    $statusHistory = $order['statusHistory'] ?? [];
    if (!empty($statusHistory)) {
        $lastChange = end($statusHistory);
        $lastChangeTime = strtotime($lastChange['timestamp'] ?? $order['submittedAt'] ?? '');
        if ($lastChangeTime) {
            $daysSinceChange = ($now - $lastChangeTime) / 86400;
            if ($daysSinceChange >= $problemConfig['stuck_status_days']) {
                $entry['days_in_status'] = round($daysSinceChange, 1);
                $entry['last_change'] = $lastChange['timestamp'] ?? null;
                $entry['last_change_ts'] = $lastChangeTime;
                if (in_array($status, $dispatchStatuses)) {
                    $dispatchStuck[] = $entry;
                } else {
                    $prodProblems['stuck_status'][] = $entry;
                }
            }
        }
    }

    // Uncollected
    if ($event && !empty($event['end_date']) && in_array($status, ['ready', 'ready_for_pickup'])) {
        $eventEnd = strtotime($event['end_date']);
        if ($eventEnd) {
            $daysSinceEventEnd = ($now - $eventEnd) / 86400;
            if ($daysSinceEventEnd >= $problemConfig['uncollected_days']) {
                $entry['days_uncollected'] = round($daysSinceEventEnd, 1);
                $entry['event_end_date'] = $event['end_date'];
                $entry['event_end_ts'] = $eventEnd;
                $prodProblems['uncollected'][] = $entry;
            }
        }
    }

    // Max Reminders
    if (count($reminders) >= $problemConfig['max_reminders_threshold'] && $status === 'preflight' && $pfEntry && empty($pfEntry['confirmed_at'])) {
        $entry['reminder_count'] = count($reminders);
        $lastReminder = end($reminders);
        $entry['last_reminder'] = $lastReminder['sent_at'] ?? null;
        $entry['vendor_name'] = isset($pfEntry['vendor_id']) ? ($vendorLookup[$pfEntry['vendor_id']]['business_name'] ?? 'Unknown') : 'Unknown';
        $prodProblems['max_reminders'][] = $entry;
    }

    // Payment Pending
    if (empty($order['paymentInfo']['paid'])) {
        $tier = $order['pricing']['tier'] ?? 'standard';
        if (in_array(strtolower($tier), ['sameday', 'same day', 'nextday', 'next day', '2days', '2 days', 'last minute', 'critical', 'urgent'])) {
            $entry['amount_due'] = $order['pricing']['total'] ?? 0;
            $createdTs = !empty($order['submittedAt']) ? strtotime($order['submittedAt']) : 0;
            $entry['created_at_ts'] = $createdTs;
            $entry['hours_unpaid'] = $createdTs ? round(($now - $createdTs) / 3600, 1) : 0;
            $prodProblems['payment_pending'][] = $entry;
        }
    }
}

// Sort production problems
usort($prodProblems['confirmation_critical'], fn($a, $b) => ($b['hours_waiting'] ?? 0) <=> ($a['hours_waiting'] ?? 0));
usort($prodProblems['confirmation_overdue'], fn($a, $b) => ($b['hours_waiting'] ?? 0) <=> ($a['hours_waiting'] ?? 0));
usort($prodProblems['past_due'], fn($a, $b) => ($b['hours_past_due'] ?? 0) <=> ($a['hours_past_due'] ?? 0));
usort($prodProblems['uncollected'], fn($a, $b) => ($b['days_uncollected'] ?? 0) <=> ($a['days_uncollected'] ?? 0));
usort($prodProblems['payment_pending'], fn($a, $b) => ($b['hours_unpaid'] ?? 0) <=> ($a['hours_unpaid'] ?? 0));
usort($prodProblems['file_issues'], fn($a, $b) => ($b['hours_since_report'] ?? $b['hours_waiting'] ?? 0) <=> ($a['hours_since_report'] ?? $a['hours_waiting'] ?? 0));
usort($prodProblems['stuck_status'], fn($a, $b) => ($b['days_in_status'] ?? 0) <=> ($a['days_in_status'] ?? 0));

// Production totals
$prodTotal = 0;
$prodCritical = 0;
foreach ($prodProblems as $type => $items) {
    $prodTotal += count($items);
    if (in_array($type, ['confirmation_critical', 'file_issues', 'past_due'])) {
        $prodCritical += count($items);
    }
}

// ============================================
// DISPATCH PROBLEMS
// ============================================
require_once $basePath . 'includes/problem-detection.php';
$dispatchProblems = function_exists('getDispatchProblems')
    ? getDispatchProblems($ordersDir, $statusesFile, $issuesFile)
    : ['ready_stale' => [], 'delivery_overdue' => [], 'unresolved_issues' => [], 'counts' => ['total' => 0]];

// Enrich dispatch data with event names from lookup + apply event filter
foreach (['ready_stale', 'delivery_overdue', 'unresolved_issues'] as $key) {
    $filtered = [];
    foreach ($dispatchProblems[$key] as &$d) {
        $ec = $d['event_code'] ?? null;
        // Event filter
        if ($eventFilter !== '' && $ec !== $eventFilter) continue;
        // Add event name
        $d['event_name'] = isset($eventLookup[$ec]) ? $eventLookup[$ec]['name'] : ($ec ?? '');
        $filtered[] = $d;
    }
    unset($d);
    $dispatchProblems[$key] = $filtered;
}

// Filter dispatch past_due and stuck by event too
if ($eventFilter !== '') {
    $dispatchPastDue = array_values(array_filter($dispatchPastDue, fn($p) => ($p['event_code'] ?? '') === $eventFilter));
    $dispatchStuck = array_values(array_filter($dispatchStuck, fn($p) => ($p['event_code'] ?? '') === $eventFilter));
}

// Auto-sort dispatch sections by age (oldest first)
usort($dispatchProblems['ready_stale'], fn($a, $b) => ($b['hours_waiting'] ?? 0) <=> ($a['hours_waiting'] ?? 0));
usort($dispatchProblems['delivery_overdue'], fn($a, $b) => ($b['hours_overdue'] ?? 0) <=> ($a['hours_overdue'] ?? 0));
usort($dispatchProblems['unresolved_issues'], fn($a, $b) => ($b['hours_open'] ?? 0) <=> ($a['hours_open'] ?? 0));
usort($dispatchPastDue, fn($a, $b) => ($b['hours_past_due'] ?? $b['days_past_due'] ?? 0) <=> ($a['hours_past_due'] ?? $a['days_past_due'] ?? 0));
usort($dispatchStuck, fn($a, $b) => ($b['days_in_status'] ?? 0) <=> ($a['days_in_status'] ?? 0));

// Add dispatch-stage past_due and stuck orders
$dispatchProblems['past_due'] = $dispatchPastDue;
$dispatchProblems['stuck_status'] = $dispatchStuck;
$dispatchTotal = count($dispatchProblems['ready_stale']) + count($dispatchProblems['delivery_overdue']) 
    + count($dispatchProblems['unresolved_issues']) + count($dispatchPastDue) + count($dispatchStuck);
$dispatchProblems['counts']['total'] = $dispatchTotal;

$dispatchCritical = 0;
foreach ($dispatchProblems['delivery_overdue'] ?? [] as $d) {
    if (($d['severity'] ?? '') === 'critical') $dispatchCritical++;
}
foreach ($dispatchProblems['unresolved_issues'] ?? [] as $i) {
    if (($i['severity'] ?? '') === 'critical') $dispatchCritical++;
}

// Grand totals
$grandTotal = $prodTotal + $dispatchTotal;
$grandCritical = $prodCritical + $dispatchCritical;

// ============================================
// POST-PROCESSING: Notes, New badges, Escalation
// ============================================
$currentSnapshot = [];
$escalationThresholds = ['warning_hours' => 8, 'danger_hours' => 24, 'critical_hours' => 48];
$newProblemCount = 0;

// Process all production problems
foreach ($prodProblems as $type => &$items) {
    foreach ($items as &$entry) {
        $ref = $entry['reference_code'] ?? '';
        
        // Note count
        $entry['note_count'] = count($allNotes[$ref] ?? []);
        
        // Track in snapshot
        $snapshotKey = $ref . '|' . $type;
        $currentSnapshot[$snapshotKey] = [
            'ref' => $ref,
            'type' => $type,
            'detected_at' => $prevSnapshot[$snapshotKey]['detected_at'] ?? date('Y-m-d\TH:i:s'),
            'customer' => $entry['customer_name'] ?? '',
            'event' => $entry['event_name'] ?? ''
        ];
        
        // Determine problem age for escalation
        $ageHours = $entry['hours_waiting'] ?? $entry['hours_past_due'] ?? 0;
        $ageDays = $entry['days_in_status'] ?? $entry['days_uncollected'] ?? $entry['days_past_due'] ?? 0;
        if ($ageDays > 0 && $ageHours == 0) $ageHours = $ageDays * 24;
        $entry['escalation'] = 'none';
        if ($ageHours >= $escalationThresholds['critical_hours']) $entry['escalation'] = 'critical';
        elseif ($ageHours >= $escalationThresholds['danger_hours']) $entry['escalation'] = 'danger';
        elseif ($ageHours >= $escalationThresholds['warning_hours']) $entry['escalation'] = 'warning';
        
        // New since last visit
        $detectedTs = strtotime($currentSnapshot[$snapshotKey]['detected_at'] ?? '');
        $entry['is_new'] = ($lastVisitTime > 0 && $detectedTs > $lastVisitTime);
        if ($entry['is_new']) $newProblemCount++;
    }
    unset($entry);
}
unset($items);

// Process dispatch problems
foreach (['ready_stale', 'delivery_overdue', 'unresolved_issues', 'past_due', 'stuck_status'] as $key) {
    if (!isset($dispatchProblems[$key])) continue;
    foreach ($dispatchProblems[$key] as &$d) {
        $ref = $d['ref'] ?? $d['reference_code'] ?? '';
        
        $d['note_count'] = count($allNotes[$ref] ?? []);
        
        $snapshotKey = $ref . '|dispatch_' . $key;
        $currentSnapshot[$snapshotKey] = [
            'ref' => $ref,
            'type' => 'dispatch_' . $key,
            'detected_at' => $prevSnapshot[$snapshotKey]['detected_at'] ?? date('Y-m-d\TH:i:s'),
            'customer' => $d['customer'] ?? $d['customer_name'] ?? '',
            'event' => $d['event_name'] ?? ''
        ];
        
        $ageHours = $d['hours_waiting'] ?? $d['hours_overdue'] ?? $d['hours_open'] ?? $d['hours_past_due'] ?? 0;
        $ageDays = $d['days_in_status'] ?? $d['days_past_due'] ?? 0;
        if ($ageDays > 0 && $ageHours == 0) $ageHours = $ageDays * 24;
        $d['escalation'] = 'none';
        if ($ageHours >= $escalationThresholds['critical_hours']) $d['escalation'] = 'critical';
        elseif ($ageHours >= $escalationThresholds['danger_hours']) $d['escalation'] = 'danger';
        elseif ($ageHours >= $escalationThresholds['warning_hours']) $d['escalation'] = 'warning';
        
        $detectedTs = strtotime($currentSnapshot[$snapshotKey]['detected_at'] ?? '');
        $d['is_new'] = ($lastVisitTime > 0 && $detectedTs > $lastVisitTime);
        if ($d['is_new']) $newProblemCount++;
    }
    unset($d);
}

// ============================================
// RESOLUTION TRACKING
// ============================================
$resolutions = file_exists($resolutionFile) ? json_decode(file_get_contents($resolutionFile), true) : ['resolutions' => []];
$newResolutions = 0;

foreach ($prevSnapshot as $snapKey => $snapData) {
    if (!isset($currentSnapshot[$snapKey])) {
        // Problem resolved since last load
        $detectedAt = $snapData['detected_at'] ?? null;
        $resolvedAt = date('Y-m-d\TH:i:s');
        $durationHours = $detectedAt ? round((time() - strtotime($detectedAt)) / 3600, 1) : 0;
        
        $resolutions['resolutions'][] = [
            'ref' => $snapData['ref'],
            'type' => $snapData['type'],
            'customer' => $snapData['customer'] ?? '',
            'event' => $snapData['event'] ?? '',
            'detected_at' => $detectedAt,
            'resolved_at' => $resolvedAt,
            'duration_hours' => $durationHours,
            'resolved_by' => getCurrentAdminUsername()
        ];
        $newResolutions++;
    }
}

// Keep only last 500 resolutions
if (count($resolutions['resolutions']) > 500) {
    $resolutions['resolutions'] = array_slice($resolutions['resolutions'], -500);
}

// Save resolution log and current snapshot
if ($newResolutions > 0) {
    file_put_contents($resolutionFile, json_encode($resolutions, JSON_PRETTY_PRINT));
}
file_put_contents($snapshotFile, json_encode($currentSnapshot, JSON_PRETTY_PRINT));

// Recent resolutions for display (last 24h)
$recentResolutions = [];
$oneDayAgo = date('Y-m-d\TH:i:s', time() - 86400);
foreach ($resolutions['resolutions'] as $r) {
    if (($r['resolved_at'] ?? '') >= $oneDayAgo) {
        $recentResolutions[] = $r;
    }
}


// ============================================
// RENDER HELPERS
// ============================================

function renderFilterBar($availableEvents, $eventFilter) {
    $currentUrl = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    $html = '<div class="prob-filter-bar">';
    $html .= '<label>Filter by Event:</label>';
    $html .= '<select id="eventFilter" onchange="applyEventFilter()">';
    $html .= '<option value="">All Events</option>';
    foreach ($availableEvents as $code => $name) {
        $sel = ($eventFilter === $code) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($code) . '"' . $sel . '>' . htmlspecialchars($name) . '</option>';
    }
    $html .= '</select>';
    if ($eventFilter !== '') {
        $clearUrl = $currentUrl;
        $html .= '<span class="prob-filter-active">Showing: ' . htmlspecialchars($availableEvents[$eventFilter] ?? $eventFilter) . ' <a href="' . $clearUrl . '" title="Clear filter">&times;</a></span>';
    }
    $html .= '</div>';
    return $html;
}

function renderBulkToolbar($showRemind = false) {
    $html = '<div id="bulkToolbar" class="prob-bulk-toolbar">';
    $html .= '<button class="bulk-dismiss" onclick="dismissBulkToolbar()" title="Dismiss">&times;</button>';
    $html .= '<span class="bulk-count" id="bulkCount">0</span>';
    $html .= '<span class="bulk-label">selected</span>';
    $html .= '<div class="bulk-divider"></div>';
    $html .= '<select id="bulkStatusSelect">';
    $html .= '<option value="">Change status to...</option>';
    $html .= '<option value="unpaid">Unpaid</option>';
    $html .= '<option value="new">New</option>';
    $html .= '<option value="preflight">Preflight</option>';
    $html .= '<option value="file_issue">File Issue</option>';
    $html .= '<option value="printing">Printing</option>';
    $html .= '<option value="ready">Ready</option>';
    $html .= '<option value="shipped">Shipped</option>';
    $html .= '<option value="dispatched">Dispatched</option>';
    $html .= '<option value="delivered">Delivered</option>';
    $html .= '<option value="pickedup">Picked Up</option>';
    $html .= '<option value="missing">Missing</option>';
    $html .= '<option value="complete">Complete</option>';
    $html .= '<option value="refunded">Refunded</option>';
    $html .= '</select>';
    $html .= '<button onclick="bulkAction(\'status\')">Apply</button>';
    $html .= '<div class="bulk-divider"></div>';
    if ($showRemind) {
        $html .= '<button class="bulk-remind" onclick="bulkAction(\'remind\')">&#128276; Remind</button>';
    }
    $html .= '<button onclick="bulkAction(\'export\')">&#128229; Export</button>';
    $html .= '<button class="bulk-danger" onclick="bulkAction(\'cancel\')">&#128465; Cancel Orders</button>';
    $html .= '</div>';
    return $html;
}

function sectionHeaderCheckbox() {
    return '<th class="prob-checkbox-cell"><input type="checkbox" class="prob-select-all" onclick="toggleSelectAll(this)"></th>';
}
