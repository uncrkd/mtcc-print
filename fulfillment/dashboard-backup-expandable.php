<?php
/**
 * Vendor Fulfillment Dashboard - V3
 * Location: /fulfillment/dashboard.php
 *
 * Standalone design - no admin CSS dependencies.
 * Admin sidebar loaded conditionally for admin viewers only.
 */

require_once 'vendor-auth.php';
$iconsPath = __DIR__ . '/../includes/icons.php';
if (file_exists($iconsPath)) require_once $iconsPath;

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in']) {
    $adminAuthPath = __DIR__ . '/../admin-auth.php';
    if (file_exists($adminAuthPath) && !function_exists('renderAdminNav')) {
        require_once $adminAuthPath;
    }
}

requireVendorLogin();

$isAdmin = isAdminViewer();
$isGodMode = isAdminGodMode();
$canAct = canPerformVendorActions();
$vendorId = getCurrentVendorId();
$vendorName = getCurrentVendorName();

// ============================================
// DATA
// ============================================
$basePath = __DIR__ . '/../';
$preflightLogFile = $basePath . 'data/preflight-log.json';
$statusesFile = $basePath . 'data/statuses.json';
$ordersDir = $basePath . 'uploads/orders/';

function loadJson($file) {
    if (!file_exists($file)) return [];
    return json_decode(file_get_contents($file), true) ?: [];
}

$preflightLog = loadJson($preflightLogFile);
$statuses = loadJson($statusesFile);

$orderIndex = [];
foreach (glob($ordersDir . '*-order.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if ($data && isset($data['referenceCode'])) {
        $orderIndex[$data['referenceCode']] = $data;
    }
}

$allVendors = [];
if ($isAdmin) {
    $vendorsData = loadVendorsData();
    foreach ($vendorsData['vendors'] ?? [] as $v) {
        $allVendors[$v['id']] = $v['business_name'];
    }
}

$vendorOrders = [];
foreach ($preflightLog['entries'] ?? [] as $refCode => $entry) {
    $entryVendorId = $entry['vendor_id'] ?? '';
    if ($vendorId !== 'all' && $entryVendorId !== $vendorId) continue;

    $entry['reference_code'] = $refCode;
    $entry['current_status'] = $statuses[$refCode] ?? 'unknown';

    $orderData = $orderIndex[$refCode] ?? null;
    if ($orderData) {
        $entry['dimensions'] = $orderData['dimensions'] ?? ['width' => 0, 'height' => 0];
        $entry['material'] = $orderData['material'] ?? 'paper';
        $entry['due_date'] = $orderData['selectedDate'] ?? null;
        $entry['delivery_time'] = $orderData['deliveryTime'] ?? 'anytime';
        $entry['tier'] = $orderData['pricing']['tier'] ?? 'standard';
        $entry['file_name'] = $orderData['uploadedFile']['originalName'] ?? null;
        $entry['file_size'] = $orderData['uploadedFile']['size'] ?? 0;
    } else {
        $entry['dimensions'] = $entry['dimensions'] ?? ['width' => 0, 'height' => 0];
        $entry['material'] = $entry['material'] ?? 'unknown';
        $entry['due_date'] = $entry['due_date'] ?? null;
        $entry['delivery_time'] = $entry['delivery_time'] ?? 'anytime';
        $entry['tier'] = $entry['tier'] ?? 'standard';
        $entry['file_name'] = $entry['file_name'] ?? $entry['original_filename'] ?? null;
        $entry['file_size'] = $entry['file_size'] ?? 0;
    }

    $entry['is_downloaded'] = ($entry['vendor_downloads'] ?? 0) > 0;
    $entry['vendor_notes_list'] = $entry['vendor_notes'] ?? [];
    $entry['vendor_name_display'] = $allVendors[$entryVendorId] ?? $entry['vendor_name'] ?? 'Unknown';
    $vendorOrders[$refCode] = $entry;
}

$tabs = ['new' => [], 'printing' => [], 'completed' => [], 'issues' => []];
$completedStatuses = ['ready', 'ready_to_ship', 'shipped', 'delivered', 'pickedup'];
foreach ($vendorOrders as $refCode => $order) {
    $status = $order['current_status'];
    if ($status === 'preflight' && empty($order['confirmed_at'])) $tabs['new'][] = $order;
    elseif ($status === 'printing') $tabs['printing'][] = $order;
    elseif (in_array($status, $completedStatuses)) $tabs['completed'][] = $order;
    elseif ($status === 'file_issue') $tabs['issues'][] = $order;
}
usort($tabs['new'], fn($a, $b) => strtotime($a['due_date'] ?? '2099-01-01') <=> strtotime($b['due_date'] ?? '2099-01-01'));
usort($tabs['printing'], fn($a, $b) => strtotime($a['due_date'] ?? '2099-01-01') <=> strtotime($b['due_date'] ?? '2099-01-01'));
usort($tabs['completed'], fn($a, $b) => strtotime($b['confirmed_at'] ?? '0') <=> strtotime($a['confirmed_at'] ?? '0'));
usort($tabs['issues'], fn($a, $b) => strtotime($b['pushed_at'] ?? '0') <=> strtotime($a['pushed_at'] ?? '0'));

$counts = [
    'new' => count($tabs['new']), 'printing' => count($tabs['printing']),
    'completed' => count($tabs['completed']), 'issues' => count($tabs['issues']),
    'total' => count($vendorOrders)
];

// Count orders due today
$dueToday = 0;
foreach ($vendorOrders as $o) {
    if (!empty($o['due_date']) && daysUntilDue($o['due_date']) === 0) $dueToday++;
}

// ============================================
// HELPERS
// ============================================
function getTierLabel($t) { return ['early'=>'Early Bird','standard'=>'Standard','3days'=>'3-Day Rush','2days'=>'2-Day Rush','nextday'=>'Next Day','sameday'=>'Same Day'][$t??''] ?? ucfirst($t??''); }
function getTierClass($t) { return ['sameday'=>'fp-tier-sameday','nextday'=>'fp-tier-nextday','2days'=>'fp-tier-rush','3days'=>'fp-tier-rush','standard'=>'fp-tier-standard','early'=>'fp-tier-early'][$t??''] ?? 'fp-tier-standard'; }
function getTierSortOrder($t) { return ['sameday'=>1,'nextday'=>2,'2days'=>3,'3days'=>4,'standard'=>5,'early'=>6][$t??''] ?? 99; }
function formatFileSize($b) { if(!$b||$b<=0)return ''; $k=1024;$s=['B','KB','MB','GB'];$i=floor(log($b)/log($k));return round($b/pow($k,$i),1).' '.$s[$i]; }
function daysUntilDue($d) { if(!$d)return null; return (int)((strtotime($d)-strtotime('today'))/86400); }
function getDueClass($d) { $days=daysUntilDue($d); if($days===null)return ''; if($days<0)return 'due-overdue'; if($days===0)return 'due-today'; if($days===1)return 'due-tomorrow'; if($days<=3)return 'due-soon'; return ''; }
function getStatusLabel($s) { return ['preflight'=>'Awaiting','printing'=>'Printing','ready_to_ship'=>'Ready','shipped'=>'Shipped','delivered'=>'Delivered','pickedup'=>'Picked Up','file_issue'=>'Issue'][$s??''] ?? ucfirst($s??''); }
function calcArea($d) { return ($d['width']??0)*($d['height']??0); }

function renderDueCompact($dueDate, $deliveryTime = 'anytime') {
    if (!$dueDate) return '<span class="vp-muted">&mdash;</span>';
    $_dtl = ['9am' => '9:00', '12pm' => '12:00', '3pm' => '15:00', '6pm' => '18:00'];
    $_dtlDisplay = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $dueTimeStr = ($deliveryTime && $deliveryTime !== 'anytime' && isset($_dtl[$deliveryTime]))
        ? $_dtl[$deliveryTime] : '23:59';
    $dueTs = strtotime($dueDate . ' ' . $dueTimeStr . ':59');
    $timeDisplay = ($deliveryTime && $deliveryTime !== 'anytime')
        ? ' &middot; ' . ($_dtlDisplay[$deliveryTime] ?? $deliveryTime) : '';
    $dateDisplay = date('D, M j', strtotime($dueDate)) . $timeDisplay;
    return '<div class="vp-due-wrap"><span class="vp-due-date">' . $dateDisplay . '</span><span class="vp-timer" data-due-ts="' . $dueTs . '"></span></div>';
}

function renderDateTimeFull($dt) {
    if (!$dt) return '&mdash;';
    return date('M j, Y &\m\i\d\d\o\t; g:ia', strtotime($dt));
}

function renderTimeInState($dt, $label = 'Waiting') {
    if (!$dt) return '';
    $diff = time() - strtotime($dt);
    if ($diff < 60) { $text = '<1m'; $cls = 'vp-ts-ok'; }
    elseif ($diff < 3600) { $text = floor($diff/60).'m ago'; $cls = 'vp-ts-ok'; }
    elseif ($diff < 14400) { $text = floor($diff/3600).'h ago'; $cls = 'vp-ts-ok'; }
    elseif ($diff < 28800) { $text = floor($diff/3600).'h ago'; $cls = 'vp-ts-warn'; }
    else { $text = floor($diff/86400) > 0 ? floor($diff/86400).'d ago' : floor($diff/3600).'h ago'; $cls = 'vp-ts-alert'; }
    return '<span class="vp-ts ' . $cls . '">' . $text . '</span>';
}

$compactCols = $isAdmin ? 8 : 7;

function renderDetailPanel($order, $canAct, $isAdmin = false) {
    $ref = htmlspecialchars($order['reference_code']);
    $fileName = htmlspecialchars($order['file_name'] ?? 'No file');
    $fileSize = formatFileSize($order['file_size'] ?? 0);
    $w = $order['dimensions']['width'] ?? 0;
    $h = $order['dimensions']['height'] ?? 0;
    $mat = ucfirst($order['material'] ?? 'Unknown');
    $matClass = strtolower($order['material'] ?? '') === 'fabric' ? 'vp-mat-fabric' : 'vp-mat-paper';

    $html = '<div class="vp-ticket">';

    // ── Block 1: File & Download ──
    $html .= '<div class="vp-ticket-block vp-ticket-file">';
    $html .= '<div class="vp-ticket-label">File</div>';
    $html .= '<a href="api.php?action=download&amp;ref=' . urlencode($ref) . '" class="vp-ticket-dl-btn">';
    $html .= '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>';
    $html .= ' Download File</a>';
    $html .= '<div class="vp-ticket-filename">' . $fileName . '</div>';
    if ($fileSize) $html .= '<div class="vp-ticket-meta">' . $fileSize . '</div>';
    $dl = $order['vendor_downloads'] ?? 0;
    if ($dl > 0) {
        $html .= '<div class="vp-ticket-meta vp-ticket-downloaded">&#10003; Downloaded ' . $dl . 'x</div>';
    }
    $html .= '</div>';

    // ── Block 2: Print Specs ──
    $html .= '<div class="vp-ticket-block vp-ticket-specs">';
    $html .= '<div class="vp-ticket-label">Print Specs</div>';
    $html .= '<div class="vp-ticket-chips">';
    $html .= '<span class="vp-chip">' . $w . '&quot; &times; ' . $h . '&quot;</span>';
    $html .= '<span class="vp-chip ' . $matClass . '">' . $mat . '</span>';
    $area = $w * $h;
    if ($area > 0) $html .= '<span class="vp-chip vp-chip-area">' . number_format($area) . ' sq&quot;</span>';
    $html .= '</div>';
    $html .= '<div class="vp-ticket-timeline">';
    if (!empty($order['pushed_at']))
        $html .= '<div class="vp-tl-item"><span class="vp-tl-dot vp-tl-done"></span><span class="vp-tl-text">Received ' . date('M j, g:ia', strtotime($order['pushed_at'])) . '</span>' . renderTimeInState($order['pushed_at']) . '</div>';
    if (!empty($order['confirmed_at']))
        $html .= '<div class="vp-tl-item"><span class="vp-tl-dot vp-tl-done"></span><span class="vp-tl-text">Accepted ' . date('M j, g:ia', strtotime($order['confirmed_at'])) . '</span>' . renderTimeInState($order['confirmed_at']) . '</div>';
    if (!empty($order['ready_at']))
        $html .= '<div class="vp-tl-item"><span class="vp-tl-dot vp-tl-done"></span><span class="vp-tl-text">Ready ' . date('M j, g:ia', strtotime($order['ready_at'])) . '</span></div>';
    $html .= '</div>';
    $html .= '</div>';

    // ── Block 3: Instructions & Notes ──
    $html .= '<div class="vp-ticket-block vp-ticket-notes">';
    $html .= '<div class="vp-ticket-label">Instructions &amp; Notes</div>';
    $hasNotes = false;
    if (!empty($order['notes'])) {
        $html .= '<div class="vp-note vp-note-customer"><span class="vp-note-from">Customer</span>' . htmlspecialchars($order['notes']) . '</div>';
        $hasNotes = true;
    }
    foreach ($order['vendor_notes_list'] ?? [] as $idx => $vn) {
        $by = htmlspecialchars($vn['by'] ?? 'Vendor');
        $time = !empty($vn['timestamp']) ? date('M j, g:ia', strtotime($vn['timestamp'])) : '';
        $del = $canAct ? '<button class="vp-note-del" onclick="deleteNote(\'' . $ref . '\',' . $idx . ')">&times;</button>' : '';
        $html .= '<div class="vp-note vp-note-vendor"><span class="vp-note-from">' . $by . '</span>' . htmlspecialchars($vn['text'] ?? '') . '<span class="vp-note-time">' . $time . '</span>' . $del . '</div>';
        $hasNotes = true;
    }
    if (!empty($order['file_issue_reason'])) {
        $html .= '<div class="vp-note vp-note-issue"><span class="vp-note-from">Issue</span>' . htmlspecialchars($order['file_issue_reason']) . '</div>';
        $hasNotes = true;
    }
    if (!$hasNotes) $html .= '<p class="vp-muted">No instructions or notes yet.</p>';
    if ($canAct) $html .= '<button class="vp-btn vp-btn-ghost vp-ticket-add-note" onclick="showNoteModal(\'' . $ref . '\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg> Add Note</button>';
    $html .= '</div>';

    $html .= '</div>';
    return $html;
}

/**
 * Render a tab's table rows
 */
function renderOrderRow($order, $canAct, $isAdmin, $compactCols, $tabType) {
    $ref = htmlspecialchars($order['reference_code']);
    $dueClass = getDueClass($order['due_date'] ?? null);
    $area = calcArea($order['dimensions'] ?? ['width'=>0,'height'=>0]);
    $hasNotes = !empty($order['notes']) || !empty($order['vendor_notes_list']) || !empty($order['file_issue_reason']);
    
    // Sort data attrs
    $dueTs = !empty($order['due_date']) ? strtotime($order['due_date']) : 9999999999;
    $recTs = !empty($order['pushed_at']) ? strtotime($order['pushed_at']) : 0;
    $confTs = !empty($order['confirmed_at']) ? strtotime($order['confirmed_at']) : 0;
    $timeTs = ($tabType === 'printing') ? $confTs : $recTs;
    
    $html = '<tr class="vp-row ' . $dueClass . '"';
    $html .= ' data-ref="' . $ref . '"';
    $html .= ' data-file="' . htmlspecialchars($order['file_name'] ?? '') . '"';
    $html .= ' data-size="' . $area . '"';
    $html .= ' data-material="' . htmlspecialchars($order['material'] ?? '') . '"';
    $html .= ' data-due="' . $dueTs . '"';
    $html .= ' data-received="' . $timeTs . '"';
    $html .= ' data-vendor="' . htmlspecialchars($order['vendor_name_display'] ?? '') . '"';
    $html .= ' data-tier="' . getTierSortOrder($order['tier'] ?? '') . '"';
    $html .= ' data-tier-raw="' . htmlspecialchars($order['tier'] ?? '') . '">';
    
    // Checkbox
    $html .= '<td class="vp-col-check"><input type="checkbox" class="vp-checkbox order-checkbox" data-reference="' . $ref . '"></td>';
    
    // Chevron
    $html .= '<td class="vp-col-chev"><button class="vp-chevron" onclick="toggleDetail(this)" aria-label="Expand details">';
    $html .= '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>';
    $html .= '</button>';
    if ($hasNotes) $html .= '<span class="vp-dot"></span>';
    $html .= '</td>';
    
    // Order ref + inline notes preview
    $html .= '<td class="vp-col-ref"><span class="vp-ref">' . $ref . '</span>';
    if ($tabType === 'completed') {
        $html .= '<span class="vp-status-pill vp-pill-ready">' . getStatusLabel($order['current_status'] ?? '') . '</span>';
    } elseif ($tabType === 'issues') {
        $html .= '<span class="vp-status-pill vp-pill-issue">Issue</span>';
    }
    if (!empty($order['notes'])) {
        $preview = htmlspecialchars(mb_strimwidth($order['notes'], 0, 80, '...'));
        $html .= '<div class="vp-note-preview">&#128172; ' . $preview . '</div>';
    }
    $html .= '</td>';
    
    // File (clickable download)
    $html .= '<td class="vp-col-file"><a href="api.php?action=download&amp;ref=' . urlencode($order['reference_code']) . '" class="vp-file-link">';
    $html .= '<svg class="vp-dl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>';
    $html .= htmlspecialchars($order['file_name'] ?? 'No file') . '</a></td>';
    
    // Specs (size + material combined)
    $mat = ucfirst($order['material'] ?? '');
    $matClass = strtolower($order['material'] ?? '') === 'fabric' ? 'vp-mat-fabric' : 'vp-mat-paper';
    $html .= '<td class="vp-col-specs">' . ($order['dimensions']['width'] ?? 0) . '&quot;&times;' . ($order['dimensions']['height'] ?? 0) . '&quot; <span class="vp-mat ' . $matClass . '">' . $mat . '</span></td>';
    
    // Vendor (admin only)
    if ($isAdmin) {
        $html .= '<td class="vp-col-vendor">' . htmlspecialchars($order['vendor_name_display'] ?? '&mdash;') . '</td>';
    }
    
    // Due + timer
    $html .= '<td class="vp-col-due">' . renderDueCompact($order['due_date'] ?? null, $order['delivery_time'] ?? 'anytime') . '</td>';
    
    // Actions
    $html .= '<td class="vp-col-act">';
    if ($canAct) {
        $dlBtn = '<a href="api.php?action=download&amp;ref=' . urlencode($order['reference_code']) . '" class="vp-btn vp-btn-ghost vp-btn-dl">&#11015; Download</a>';
        if ($tabType === 'new') {
            $html .= '<button class="vp-btn vp-btn-approve" onclick="approveOrder(\'' . $ref . '\')">&#10003; Accept</button>';
            $html .= $dlBtn;
            $html .= '<button class="vp-btn vp-btn-issue" onclick="showIssueModal(\'' . $ref . '\')">&#9888; Issue</button>';
        } elseif ($tabType === 'printing') {
            $html .= '<button class="vp-btn vp-btn-ready" onclick="markReady(\'' . $ref . '\')">&#10003; Ready</button>';
            $html .= $dlBtn;
        } elseif ($tabType === 'completed') {
            $html .= '<button class="vp-btn vp-btn-ghost" onclick="revertToPrinting(\'' . $ref . '\')">&#8617; Reprint</button>';
            $html .= '<button class="vp-btn vp-btn-issue" onclick="showIssueModal(\'' . $ref . '\')">&#9888; Issue</button>';
        } elseif ($tabType === 'issues') {
            $html .= '<button class="vp-btn vp-btn-approve" onclick="approveOrder(\'' . $ref . '\')">&#10003; Re-accept</button>';
        }
    } else {
        $html .= '<span class="vp-muted" style="font-size:0.75rem;font-style:italic;">View only</span>';
    }
    $html .= '</td></tr>';
    
    // Detail row
    $html .= '<tr class="vp-detail-row vp-no-sort" style="display:none"><td colspan="' . $compactCols . '">';
    $html .= renderDetailPanel($order, $canAct, $isAdmin);
    $html .= '</td></tr>';
    
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isAdmin ? 'Admin View - ' : '' ?>Fulfillment Portal - Print Stuff</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="fulfillment.css">
<?php if ($isAdmin): ?>
    <link rel="stylesheet" href="../css/admin-sidebar.css">
<?php endif; ?>
</head>
<body<?= $isAdmin ? ' class="has-sidebar"' : '' ?>>

<?php if ($isAdmin): ?>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('fulfillment'); ?>
<script src="../admin-sidebar.js"></script>
<div class="vp-admin-strip">
    <strong>Admin View</strong> &mdash; <?= htmlspecialchars($_SESSION['admin_name'] ?? 'Admin') ?> (<?= $isGodMode ? 'God Mode' : 'Super Admin - View Only' ?>)
</div>
<?php endif; ?>

<div class="vp-shell">

    <!-- ===== HEADER ===== -->
    <header class="vp-header">
        <div class="vp-header-left">
            <a href="dashboard.php" class="vp-logo">
                <img src="../logo.png" alt="Print Stuff" onerror="this.style.display='none'">
                <span class="vp-logo-text">Fulfillment</span>
            </a>
        </div>
        <div class="vp-header-right">
            <?php if ($isAdmin && !empty($allVendors)): ?>
            <select class="vp-vendor-select" onchange="filterByVendor(this.value)">
                <option value="all" <?= $vendorId==='all'?'selected':'' ?>>All Vendors</option>
                <?php foreach ($allVendors as $vId => $vName): ?>
                <option value="<?= htmlspecialchars($vId) ?>" <?= $vendorId===$vId?'selected':'' ?>><?= htmlspecialchars($vName) ?></option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <span class="vp-header-vendor"><?= htmlspecialchars($vendorName) ?></span>
            <a href="?logout=1" class="vp-header-link">Sign Out</a>
        </div>
    </header>

    <!-- ===== STATUS STRIP ===== -->
    <div class="vp-status-strip">
        <span class="vp-ss-item"><strong><?= $counts['total'] ?></strong> total orders</span>
        <span class="vp-ss-sep">&middot;</span>
        <?php if ($dueToday > 0): ?>
        <span class="vp-ss-item vp-ss-alert"><strong><?= $dueToday ?></strong> due today</span>
        <span class="vp-ss-sep">&middot;</span>
        <?php endif; ?>
        <span class="vp-ss-item"><?= date('l, M j') ?></span>
    </div>

    <!-- ===== TAB BAR ===== -->
    <div class="vp-controls">
        <div class="vp-tabs">
            <button class="vp-tab active" data-tab="new">
                New<?php if($counts['new']>0):?><span class="vp-tab-count"><?=$counts['new']?></span><?php endif;?>
            </button>
            <button class="vp-tab" data-tab="printing">
                Printing<?php if($counts['printing']>0):?><span class="vp-tab-count vp-tc-blue"><?=$counts['printing']?></span><?php endif;?>
            </button>
            <button class="vp-tab" data-tab="completed">
                Ready<?php if($counts['completed']>0):?><span class="vp-tab-count vp-tc-brown"><?=$counts['completed']?></span><?php endif;?>
            </button>
            <button class="vp-tab" data-tab="issues">
                Issues<?php if($counts['issues']>0):?><span class="vp-tab-count vp-tc-red"><?=$counts['issues']?></span><?php endif;?>
            </button>
        </div>
        <div class="vp-toolbar">
            <div class="vp-search-wrap">
                <svg class="vp-search-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="fpSearch" class="vp-search" placeholder="Search orders...">
            </div>
            <div class="vp-filter-pills">
                <button class="vp-pill" data-filter="material" data-value="poster">Paper</button>
                <button class="vp-pill" data-filter="material" data-value="fabric">Fabric</button>
            </div>
            <select id="fpPerPage" class="vp-per-page">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="all">All</option>
            </select>
            <span class="vp-results" id="fpShowing"><span id="fpShowRange">0</span> of <span id="fpTotalCount">0</span></span>
        </div>
    </div>

    <!-- ===== TABLES ===== -->
    <div class="vp-table-wrap">

        <!-- TAB: New Orders -->
        <div class="vp-tab-pane active" id="tab-new">
            <?php if (empty($tabs['new'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&check;</div><h3>All caught up</h3><p>New orders will appear here when assigned.</p></div>
            <?php else: ?>
            <table class="vp-table vp-sortable" data-tab="new">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="new"></th>
                    <th class="vp-col-chev"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Specs</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable sorted-asc" data-sort="due">Due</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabs['new'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $compactCols, 'new'); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- TAB: Printing -->
        <div class="vp-tab-pane" id="tab-printing">
            <?php if (empty($tabs['printing'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&#9881;</div><h3>Nothing printing</h3><p>Accepted orders will appear here.</p></div>
            <?php else: ?>
            <table class="vp-table vp-sortable" data-tab="printing">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="printing"></th>
                    <th class="vp-col-chev"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Specs</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable sorted-asc" data-sort="due">Due</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabs['printing'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $compactCols, 'printing'); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- TAB: Ready to Ship -->
        <div class="vp-tab-pane" id="tab-completed">
            <?php if (empty($tabs['completed'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&#128230;</div><h3>No orders ready</h3><p>Finished orders will appear here.</p></div>
            <?php else: ?>
            <table class="vp-table vp-sortable" data-tab="completed">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="completed"></th>
                    <th class="vp-col-chev"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Specs</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="due">Due</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabs['completed'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $compactCols, 'completed'); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <!-- TAB: File Issues -->
        <div class="vp-tab-pane" id="tab-issues">
            <?php if (empty($tabs['issues'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&check;</div><h3>No issues</h3><p>Orders with file problems will appear here.</p></div>
            <?php else: ?>
            <table class="vp-table vp-sortable" data-tab="issues">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="issues"></th>
                    <th class="vp-col-chev"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Specs</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="due">Due</th>
                    <th>Actions</th>
                </tr></thead>
                <tbody>
                <?php foreach ($tabs['issues'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $compactCols, 'issues'); ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div id="fpPaginationNav" class="vp-pagination"></div>
    </div>
</div>

<!-- Bulk Action Bar -->
<div class="vp-bulk" id="fpBulkBar">
    <div class="vp-bulk-inner">
        <span class="vp-bulk-count" id="fpBulkCount">0</span>
        <span class="vp-bulk-label">selected</span>
        <div class="vp-bulk-actions" id="fpBulkActions"></div>
        <button class="vp-bulk-clear" onclick="fpClearSelection()">&times;</button>
    </div>
    <div class="vp-bulk-progress" id="fpBulkProgress" style="display:none;">
        <div id="fpBulkProgressBar" class="vp-bulk-progress-bar"></div>
        <span id="fpBulkProgressText" class="vp-bulk-progress-text">Processing...</span>
    </div>
</div>

<!-- Note Modal -->
<div class="vp-overlay" id="noteModal" style="display:none;">
    <div class="vp-modal">
        <div class="vp-modal-head"><h3>Add Note</h3><button class="vp-modal-x" onclick="closeNoteModal()">&times;</button></div>
        <div class="vp-modal-body">
            <p>Add a note to order <strong id="noteOrderRef"></strong></p>
            <textarea id="noteText" rows="3" placeholder="e.g., Using 200gsm satin stock, file reformatted..."></textarea>
        </div>
        <div class="vp-modal-foot">
            <button class="vp-btn vp-btn-ghost" onclick="closeNoteModal()">Cancel</button>
            <button class="vp-btn vp-btn-primary" onclick="submitNote()">Save Note</button>
        </div>
    </div>
</div>

<!-- Issue Modal -->
<div class="vp-overlay" id="issueModal" style="display:none;">
    <div class="vp-modal">
        <div class="vp-modal-head"><h3>Report File Issue</h3><button class="vp-modal-x" onclick="closeIssueModal()">&times;</button></div>
        <div class="vp-modal-body">
            <p>Describe the problem with the file for order <strong id="issueOrderRef"></strong></p>
            <textarea id="issueReason" rows="4" placeholder="e.g., File is corrupted, wrong dimensions, low resolution..."></textarea>
        </div>
        <div class="vp-modal-foot">
            <button class="vp-btn vp-btn-ghost" onclick="closeIssueModal()">Cancel</button>
            <button class="vp-btn vp-btn-issue" onclick="submitIssue()">Report Issue</button>
        </div>
    </div>
</div>

<div class="vp-toast" id="fpToast" style="display:none;"></div>

<script>
// ============================================
// CONFIG
// ============================================
var CAN_ACT = <?= $canAct ? 'true' : 'false' ?>;
var activeTab = 'new';

// ============================================
// CHEVRON EXPAND/COLLAPSE
// ============================================
function toggleDetail(btn) {
    var row = btn.closest('tr');
    var detail = row.nextElementSibling;
    if (!detail || !detail.classList.contains('vp-detail-row')) return;
    var isOpen = detail.style.display !== 'none';
    detail.style.display = isOpen ? 'none' : 'table-row';
    btn.classList.toggle('open', !isOpen);
    row.classList.toggle('vp-expanded', !isOpen);
}
function collapseAll() {
    document.querySelectorAll('.vp-detail-row').forEach(function(r) { r.style.display = 'none'; });
    document.querySelectorAll('.vp-chevron').forEach(function(c) { c.classList.remove('open'); });
    document.querySelectorAll('.vp-row').forEach(function(r) { r.classList.remove('vp-expanded'); });
}

// ============================================
// AUDIO
// ============================================
var fpAudioCtx = null;
function fpInitAudio() { if (fpAudioCtx) return; try { fpAudioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch(e) {} }
function fpPlayChime() {
    fpInitAudio(); if (!fpAudioCtx) return;
    try { var now = fpAudioCtx.currentTime;
    [523, 659].forEach(function(freq, i) { var o = fpAudioCtx.createOscillator(); var g = fpAudioCtx.createGain();
    o.frequency.value = freq; o.type = 'sine'; g.gain.setValueAtTime(0.25, now+i*0.15); g.gain.exponentialRampToValueAtTime(0.01, now+i*0.15+0.3);
    o.connect(g); g.connect(fpAudioCtx.destination); o.start(now+i*0.15); o.stop(now+i*0.15+0.3); }); } catch(e) {}
}
document.addEventListener('click', function() { fpInitAudio(); }, { once: true });

// ============================================
// VENDOR FILTER (admin)
// ============================================
function filterByVendor(v) {
    var url = new URL(window.location.href);
    if (v === 'all') url.searchParams.delete('vendor'); else url.searchParams.set('vendor', v);
    window.location.href = url.toString();
}

// ============================================
// TABS
// ============================================
document.querySelectorAll('.vp-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        activeTab = this.dataset.tab;
        collapseAll();
        document.querySelectorAll('.vp-tab').forEach(function(t) { t.classList.remove('active'); });
        this.classList.add('active');
        document.querySelectorAll('.vp-tab-pane').forEach(function(c) { c.classList.remove('active'); });
        document.getElementById('tab-' + activeTab).classList.add('active');
        fpClearSelection();
        fpApplyFilters();
    });
});

// ============================================
// SEARCH / FILTER / PAGINATION
// ============================================
var fpFilter = { search: '', material: new Set(), perPage: 25, pages: {} };

document.getElementById('fpSearch').addEventListener('input', function() { fpFilter.search = this.value.trim(); fpResetPage(); fpApplyFilters(); });
document.getElementById('fpPerPage').addEventListener('change', function() { fpFilter.perPage = this.value === 'all' ? 'all' : parseInt(this.value); fpResetPage(); fpApplyFilters(); });

document.querySelectorAll('.vp-pill').forEach(function(pill) {
    pill.addEventListener('click', function() {
        var values = this.dataset.value.split(',');
        this.classList.toggle('active');
        values.forEach(function(v) { if (fpFilter.material.has(v)) fpFilter.material.delete(v); else fpFilter.material.add(v); });
        fpResetPage(); fpApplyFilters();
    });
});

function fpResetPage() { fpFilter.pages[activeTab] = 1; }

function fpGetRowPairs(tbody) {
    var pairs = [];
    var rows = Array.from(tbody.children);
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].classList.contains('vp-no-sort') || rows[i].classList.contains('vp-detail-row')) continue;
        var pair = { main: rows[i], subs: [] };
        var j = i + 1;
        while (j < rows.length && (rows[j].classList.contains('vp-detail-row') || rows[j].classList.contains('vp-no-sort'))) { pair.subs.push(rows[j]); j++; }
        pairs.push(pair);
    }
    return pairs;
}

function fpApplyFilters() {
    var table = document.querySelector('#tab-' + activeTab + ' table');
    if (!table) { document.getElementById('fpShowRange').textContent = '0'; document.getElementById('fpTotalCount').textContent = '0'; document.getElementById('fpPaginationNav').innerHTML = ''; return; }
    var tbody = table.querySelector('tbody');
    var allPairs = fpGetRowPairs(tbody);
    var searchLower = fpFilter.search.toLowerCase();
    var filtered = allPairs.filter(function(p) {
        var r = p.main;
        if (searchLower) { var h = (r.dataset.ref + ' ' + r.dataset.file + ' ' + r.dataset.material + ' ' + r.dataset.vendor).toLowerCase(); if (h.indexOf(searchLower) === -1) return false; }
        if (fpFilter.material.size > 0 && !fpFilter.material.has(r.dataset.material)) return false;
        return true;
    });
    var total = filtered.length;
    var perPage = fpFilter.perPage;
    var totalPages = perPage === 'all' ? 1 : Math.ceil(total / perPage) || 1;
    if (!fpFilter.pages[activeTab]) fpFilter.pages[activeTab] = 1;
    if (fpFilter.pages[activeTab] > totalPages) fpFilter.pages[activeTab] = 1;
    var page = fpFilter.pages[activeTab];
    var startIdx = perPage === 'all' ? 0 : (page - 1) * perPage;
    var endIdx = perPage === 'all' ? total : Math.min(startIdx + perPage, total);
    allPairs.forEach(function(p) { p.main.style.display = 'none'; p.subs.forEach(function(s) { s.style.display = 'none'; }); });
    for (var i = startIdx; i < endIdx; i++) {
        filtered[i].main.style.display = '';
        filtered[i].subs.forEach(function(s) {
            if (s.classList.contains('vp-detail-row')) {
                var chev = filtered[i].main.querySelector('.vp-chevron');
                s.style.display = (chev && chev.classList.contains('open')) ? 'table-row' : 'none';
            } else { s.style.display = ''; }
        });
    }
    document.getElementById('fpShowRange').textContent = total === 0 ? '0' : (startIdx + 1) + '-' + endIdx;
    document.getElementById('fpTotalCount').textContent = total;
    fpRenderPagination(totalPages, page);
    fpUpdateSelectAllState();
}

function fpRenderPagination(totalPages, cur) {
    var nav = document.getElementById('fpPaginationNav');
    if (totalPages <= 1) { nav.innerHTML = ''; return; }
    var h = '<button class="vp-page" ' + (cur<=1?'disabled':'') + ' onclick="fpGoToPage(' + (cur-1) + ')">&lsaquo;</button>';
    var s = Math.max(1, cur-2), e = Math.min(totalPages, cur+2);
    if (s>1) { h += '<button class="vp-page" onclick="fpGoToPage(1)">1</button>'; if(s>2) h += '<span class="vp-page-dots">&hellip;</span>'; }
    for (var i=s; i<=e; i++) h += '<button class="vp-page' + (i===cur?' active':'') + '" onclick="fpGoToPage('+i+')">'+i+'</button>';
    if (e<totalPages) { if(e<totalPages-1) h += '<span class="vp-page-dots">&hellip;</span>'; h += '<button class="vp-page" onclick="fpGoToPage('+totalPages+')">'+totalPages+'</button>'; }
    h += '<button class="vp-page" ' + (cur>=totalPages?'disabled':'') + ' onclick="fpGoToPage(' + (cur+1) + ')">&rsaquo;</button>';
    nav.innerHTML = h;
}
function fpGoToPage(p) { fpFilter.pages[activeTab] = p; fpApplyFilters(); document.querySelector('.vp-table-wrap').scrollIntoView({ behavior:'smooth', block:'start' }); }

// ============================================
// SORTING
// ============================================
document.querySelectorAll('.vp-sortable .sortable').forEach(function(th) {
    th.addEventListener('click', function() {
        var table = this.closest('table'), tbody = table.querySelector('tbody');
        var col = this.dataset.sort, isAsc = this.classList.contains('sorted-asc');
        var dir = isAsc ? 'desc' : 'asc';
        table.querySelectorAll('.sortable').forEach(function(h) { h.classList.remove('sorted-asc','sorted-desc'); });
        this.classList.add(dir === 'asc' ? 'sorted-asc' : 'sorted-desc');
        var pairs = fpGetRowPairs(tbody);
        pairs.sort(function(a, b) {
            var av = a.main.dataset[col] || '', bv = b.main.dataset[col] || '';
            if (['size','due','received','tier'].indexOf(col) !== -1) return dir === 'asc' ? (parseFloat(av)||0)-(parseFloat(bv)||0) : (parseFloat(bv)||0)-(parseFloat(av)||0);
            var c = String(av).localeCompare(String(bv), undefined, {sensitivity:'base'}); return dir === 'asc' ? c : -c;
        });
        pairs.forEach(function(p) { tbody.appendChild(p.main); p.subs.forEach(function(s) { tbody.appendChild(s); }); });
        fpApplyFilters();
    });
});

// ============================================
// BULK SELECTION
// ============================================
var selectedOrders = new Set();
document.querySelectorAll('.vp-select-all').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var table = this.closest('table');
        var boxes = table.querySelectorAll('tbody tr:not([style*="display: none"]):not(.vp-detail-row) .order-checkbox');
        var checked = this.checked;
        boxes.forEach(function(b) { b.checked = checked; var ref = b.dataset.reference; var row = b.closest('tr');
            if (checked) { selectedOrders.add(ref); row.classList.add('selected'); } else { selectedOrders.delete(ref); row.classList.remove('selected'); }
        }); fpUpdateBulkBar();
    });
});
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('order-checkbox')) return;
    var ref = e.target.dataset.reference, row = e.target.closest('tr');
    if (e.target.checked) { selectedOrders.add(ref); row.classList.add('selected'); } else { selectedOrders.delete(ref); row.classList.remove('selected'); }
    fpUpdateSelectAllState(); fpUpdateBulkBar();
});
function fpUpdateSelectAllState() {
    var table = document.querySelector('#tab-' + activeTab + ' table'); if (!table) return;
    var vis = table.querySelectorAll('tbody tr:not([style*="display: none"]):not(.vp-detail-row) .order-checkbox');
    var chk = table.querySelectorAll('tbody tr:not([style*="display: none"]):not(.vp-detail-row) .order-checkbox:checked');
    var sa = table.querySelector('.vp-select-all'); if (!sa) return;
    if (vis.length===0||chk.length===0) { sa.checked=false; sa.indeterminate=false; }
    else if (chk.length===vis.length) { sa.checked=true; sa.indeterminate=false; }
    else { sa.checked=false; sa.indeterminate=true; }
}
function fpClearSelection() {
    selectedOrders.clear();
    document.querySelectorAll('.order-checkbox').forEach(function(c) { c.checked=false; });
    document.querySelectorAll('.vp-select-all').forEach(function(c) { c.checked=false; c.indeterminate=false; });
    document.querySelectorAll('.vp-row.selected').forEach(function(r) { r.classList.remove('selected'); });
    fpUpdateBulkBar();
}
function fpGetSelectedRefs() { return Array.from(selectedOrders); }

// ============================================
// BULK BAR
// ============================================
function fpUpdateBulkBar() {
    var bar = document.getElementById('fpBulkBar'), count = selectedOrders.size;
    document.getElementById('fpBulkCount').textContent = count;
    if (count > 0) bar.classList.add('visible'); else { bar.classList.remove('visible'); return; }
    var h = '';
    if (activeTab !== 'completed') h = '<button class="vp-bulk-btn vp-bulk-dl" onclick="fpBulkDownload()">Download (' + count + ')</button>';
    if (CAN_ACT) {
        if (activeTab==='new') h = '<button class="vp-bulk-btn vp-bulk-approve" onclick="fpBulkApprove()">Accept All (' + count + ')</button>' + h;
        else if (activeTab==='printing') h = '<button class="vp-bulk-btn vp-bulk-ready" onclick="fpBulkMarkReady()">Mark Ready (' + count + ')</button>' + h;
        else if (activeTab==='completed') h = '<button class="vp-bulk-btn vp-bulk-dl" onclick="fpBulkRevertToPrinting()">Back to Printing (' + count + ')</button>' + h;
    }
    document.getElementById('fpBulkActions').innerHTML = h;
}

function fpShowProgress(t) { document.getElementById('fpBulkProgress').style.display='flex'; document.getElementById('fpBulkProgressBar').style.width='0%'; document.getElementById('fpBulkProgressText').textContent='Processing 0 / '+t+'...'; }
function fpUpdateProgress(d,t) { document.getElementById('fpBulkProgressBar').style.width=Math.round((d/t)*100)+'%'; document.getElementById('fpBulkProgressText').textContent='Processing '+d+' / '+t+'...'; }
function fpHideProgress() { document.getElementById('fpBulkProgress').style.display='none'; }

async function fpBulkApprove() { var refs=fpGetSelectedRefs(); if(!refs.length||!confirm('Accept '+refs.length+' order(s)?')) return; fpShowProgress(refs.length); var ok=0,fail=0; for(var i=0;i<refs.length;i++){try{var r=await apiAsync('approve',{reference_code:refs[i]});if(r.success)ok++;else fail++;}catch(e){fail++;}fpUpdateProgress(i+1,refs.length);}fpHideProgress();showToast(fail===0?'Accepted '+ok+' order(s)':'Accepted '+ok+', failed '+fail,fail===0?'success':'error');fpClearSelection();setTimeout(function(){location.reload();},1500);}
async function fpBulkMarkReady() { var refs=fpGetSelectedRefs(); if(!refs.length||!confirm('Mark '+refs.length+' as ready?')) return; fpShowProgress(refs.length); var ok=0,fail=0; for(var i=0;i<refs.length;i++){try{var r=await apiAsync('mark_ready',{reference_code:refs[i]});if(r.success)ok++;else fail++;}catch(e){fail++;}fpUpdateProgress(i+1,refs.length);}fpHideProgress();showToast(fail===0?'Marked '+ok+' as ready':'Completed '+ok+', failed '+fail,fail===0?'success':'error');fpClearSelection();setTimeout(function(){location.reload();},1500);}
async function fpBulkRevertToPrinting() { var refs=fpGetSelectedRefs(); if(!refs.length||!confirm('Move '+refs.length+' back to Printing?')) return; fpShowProgress(refs.length); var ok=0,fail=0; for(var i=0;i<refs.length;i++){try{var r=await apiAsync('revert_to_printing',{reference_code:refs[i]});if(r.success)ok++;else fail++;}catch(e){fail++;}fpUpdateProgress(i+1,refs.length);}fpHideProgress();showToast(fail===0?'Moved '+ok+' back':'Moved '+ok+', failed '+fail,fail===0?'success':'error');fpClearSelection();setTimeout(function(){location.reload();},1500);}
function fpBulkDownload() { var refs=fpGetSelectedRefs(); if(!refs.length)return; if(refs.length===1){window.location.href='api.php?action=download&ref='+encodeURIComponent(refs[0]);return;} showToast('Preparing ZIP...','info'); window.location.href='api.php?action=bulk_download&refs='+encodeURIComponent(refs.join(',')); }

// ============================================
// INDIVIDUAL ACTIONS
// ============================================
function approveOrder(ref) { if(!confirm('Accept #'+ref+'?')) return; apiCall('approve',{reference_code:ref}).then(function(r){if(r.success){showToast('#'+ref+' accepted','success');setTimeout(function(){location.reload();},1200);}}); }
function markReady(ref) { if(!confirm('Mark #'+ref+' as ready to ship?')) return; apiCall('mark_ready',{reference_code:ref}).then(function(r){if(r.success){showToast('#'+ref+' ready','success');setTimeout(function(){location.reload();},1200);}}); }
function revertToPrinting(ref) { if(!confirm('Move #'+ref+' back to Printing?')) return; apiCall('revert_to_printing',{reference_code:ref}).then(function(r){if(r.success){showToast('#'+ref+' moved back','success');setTimeout(function(){location.reload();},1200);}}); }

var currentNoteRef=null;
function showNoteModal(ref){currentNoteRef=ref;document.getElementById('noteOrderRef').textContent='#'+ref;document.getElementById('noteText').value='';document.getElementById('noteModal').style.display='flex';}
function closeNoteModal(){document.getElementById('noteModal').style.display='none';currentNoteRef=null;}
function submitNote(){var t=document.getElementById('noteText').value.trim();if(!t){showToast('Enter a note','error');return;}apiCall('add_note',{reference_code:currentNoteRef,text:t}).then(function(r){if(r.success){closeNoteModal();showToast('Note added','success');setTimeout(function(){location.reload();},1200);}});}
document.getElementById('noteModal').addEventListener('click',function(e){if(e.target===this)closeNoteModal();});
function deleteNote(ref,idx){if(!confirm('Delete this note?'))return;apiCall('delete_note',{reference_code:ref,note_index:idx}).then(function(r){if(r.success){showToast('Note deleted','success');setTimeout(function(){location.reload();},1200);}});}

var currentIssueRef=null;
function showIssueModal(ref){currentIssueRef=ref;document.getElementById('issueOrderRef').textContent='#'+ref;document.getElementById('issueReason').value='';document.getElementById('issueModal').style.display='flex';}
function closeIssueModal(){document.getElementById('issueModal').style.display='none';currentIssueRef=null;}
function submitIssue(){var r=document.getElementById('issueReason').value.trim();if(!r){showToast('Describe the issue','error');return;}apiCall('flag_issue',{reference_code:currentIssueRef,reason:r}).then(function(res){if(res.success){closeIssueModal();showToast('Issue flagged','success');setTimeout(function(){location.reload();},1200);}});}
document.getElementById('issueModal').addEventListener('click',function(e){if(e.target===this)closeIssueModal();});

// ============================================
// API
// ============================================
function apiCall(action,data){return fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:action},data))}).then(function(r){return r.json();}).then(function(res){if(!res.success)showToast(res.error||'Something went wrong','error');return res;}).catch(function(){showToast('Network error','error');return{success:false};});}
function apiAsync(action,data){return fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(Object.assign({action:action},data))}).then(function(r){return r.json();});}

function showToast(msg,type){var t=document.getElementById('fpToast');t.textContent=msg;t.className='vp-toast vp-toast-'+(type||'info');t.style.display='block';setTimeout(function(){t.style.display='none';},4000);}

// ============================================
// LIVE COUNTDOWN TIMERS
// ============================================
function formatCountdown(sec) {
    var abs = Math.abs(sec);
    var d = Math.floor(abs/86400), h = Math.floor((abs%86400)/3600), m = Math.floor((abs%3600)/60), s = Math.floor(abs%60);
    var parts = [];
    if (d > 0) parts.push(d + 'd');
    parts.push(h + 'h');
    parts.push(String(m).padStart(2,'0') + 'm');
    parts.push(String(s).padStart(2,'0') + 's');
    return (sec < 0 ? 'OVERDUE ' : '') + parts.join(' ') + (sec >= 0 ? ' left' : '');
}
function updateTimers() {
    var now = Math.floor(Date.now()/1000);
    document.querySelectorAll('.vp-timer').forEach(function(el) {
        var ts = parseInt(el.getAttribute('data-due-ts'),10);
        if (!ts) return;
        var rem = ts - now;
        el.textContent = formatCountdown(rem);
        el.classList.remove('vp-timer-ok','vp-timer-warn','vp-timer-crit');
        if (rem < 0) el.classList.add('vp-timer-crit');
        else if (rem < 7200) el.classList.add('vp-timer-crit');
        else if (rem < 86400) el.classList.add('vp-timer-warn');
        else el.classList.add('vp-timer-ok');
    });
}
setInterval(updateTimers, 1000);

// ============================================
// AUTO-REFRESH POLL
// ============================================
var lastCount = <?= $counts['new'] ?>;
setInterval(function() {
    fetch('api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_new'})})
    .then(function(r){return r.json();}).then(function(res){
        if(res.success && res.new_count > lastCount){fpPlayChime();showToast('You have '+res.new_count+' new order(s)!','info');lastCount=res.new_count;setTimeout(function(){location.reload();},3000);}
    }).catch(function(){});
}, 60000);

// ============================================
// INIT
// ============================================
fpApplyFilters();
updateTimers();
</script>
</body>
</html>