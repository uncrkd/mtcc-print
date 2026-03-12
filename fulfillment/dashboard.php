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

// Load fulfillment batches
$fbFile = __DIR__ . '/../data/fulfillment-batches.json';
$fbData = ['batches' => []];
if (file_exists($fbFile)) {
    $fbData = json_decode(file_get_contents($fbFile), true) ?: ['batches' => []];
}
$fbLookup = []; // ref → batch
foreach ($fbData['batches'] as $fb) {
    if ($fb['status'] === 'cancelled') continue;
    foreach ($fb['order_refs'] as $r) {
        $fbLookup[$r] = ['batch_id' => $fb['batch_id'], 'label' => $fb['label']];
    }
}

$orderIndex = [];
foreach (glob($ordersDir . '*-order.json') as $file) {
    $data = json_decode(file_get_contents($file), true);
    if ($data && isset($data['referenceCode'])) {
        $orderIndex[$data['referenceCode']] = $data;
    }
}

$allVendors = [];
$allVendorHours = [];
$vendorsData = loadVendorsData();
$defaultHours = ['monday'=>['open'=>'09:00','close'=>'18:00','closed'=>false],'tuesday'=>['open'=>'09:00','close'=>'18:00','closed'=>false],'wednesday'=>['open'=>'09:00','close'=>'18:00','closed'=>false],'thursday'=>['open'=>'09:00','close'=>'18:00','closed'=>false],'friday'=>['open'=>'09:00','close'=>'18:00','closed'=>false],'saturday'=>['open'=>'','close'=>'','closed'=>true],'sunday'=>['open'=>'','close'=>'','closed'=>true]];
foreach ($vendorsData['vendors'] ?? [] as $v) {
    $allVendors[$v['id']] = $v['business_name'];
    $allVendorHours[$v['id']] = $v['business_hours'] ?? $defaultHours;
}
// For non-admin vendor, get their own hours
$currentVendorHours = $defaultHours;
$currentVendorProfile = null;
if ($vendorId !== 'all' && isset($allVendorHours[$vendorId])) {
    $currentVendorHours = $allVendorHours[$vendorId];
}
if ($vendorId !== 'all') {
    foreach ($vendorsData['vendors'] ?? [] as $v) {
        if ($v['id'] === $vendorId) { $currentVendorProfile = $v; break; }
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
        // Display name: REF-01-originalname.ext (item 01 for single-item orders)
        if ($entry['file_name'] && strpos($entry['file_name'], $refCode) !== 0) {
            $entry['file_name'] = $refCode . '-01-' . $entry['file_name'];
        }
        $entry['file_size'] = $orderData['uploadedFile']['size'] ?? 0;
    } else {
        $entry['dimensions'] = $entry['dimensions'] ?? ['width' => 0, 'height' => 0];
        $entry['material'] = $entry['material'] ?? 'unknown';
        $entry['due_date'] = $entry['due_date'] ?? null;
        $entry['delivery_time'] = $entry['delivery_time'] ?? 'anytime';
        $entry['tier'] = $entry['tier'] ?? 'standard';
        $entry['file_name'] = $entry['file_name'] ?? $entry['original_filename'] ?? null;
        // Apply same naming convention for fallback entries
        if ($entry['file_name'] && strpos($entry['file_name'], $refCode) !== 0) {
            $entry['file_name'] = $refCode . '-01-' . $entry['file_name'];
        }
        $entry['file_size'] = $entry['file_size'] ?? 0;
    }

    $entry['is_downloaded'] = ($entry['vendor_downloads'] ?? 0) > 0;
    $entry['vendor_notes_list'] = $entry['vendor_notes'] ?? [];
    $entry['vendor_name_display'] = $allVendors[$entryVendorId] ?? $entry['vendor_name'] ?? 'Unknown';
    
    // Packing (set by admin when pushing to vendor)
    $entry['packing'] = $entry['packing'] ?? ($orderData['packing'] ?? 'none');
    $entry['packing_custom'] = $entry['packing_custom'] ?? ($orderData['packing_custom'] ?? '');
    
    // Print spec notes (set by admin when pushing)
    $entry['print_notes'] = $entry['print_notes'] ?? '';
    
    // Fulfillment batch (from either preflight entry or batch lookup)
    $batchInfo = $fbLookup[$refCode] ?? null;
    if (!$batchInfo && !empty($entry['fulfillment_batch'])) {
        // Fallback: stored batch ID on the preflight entry
        foreach ($fbData['batches'] as $fb) {
            if ($fb['status'] !== 'cancelled' && $fb['batch_id'] === $entry['fulfillment_batch']) {
                $batchInfo = ['batch_id' => $fb['batch_id'], 'label' => $fb['label']];
                break;
            }
        }
    }
    $entry['batch'] = $batchInfo;
    
    // Vendor pricing
    $entry['vendor_pricing'] = $entry['vendor_pricing'] ?? [
        'base_price' => null,
        'packing_price' => null,
        'tax_rate' => 0.13,
        'tax_amount' => null,
        'total' => null,
        'status' => 'none',
        'submitted_at' => null,
        'reviewed_at' => null,
        'reviewed_by' => null,
        'rejection_reason' => null,
    ];
    
    // Vendor payment status
    $entry['vendor_paid'] = !empty($entry['vendor_paid']);
    $entry['vendor_paid_at'] = $entry['vendor_paid_at'] ?? null;
    $entry['vendor_paid_by'] = $entry['vendor_paid_by'] ?? null;
    
    $vendorOrders[$refCode] = $entry;
}

$tabs = ['new' => [], 'printing' => [], 'ready' => [], 'completed' => [], 'issues' => []];
$readyStatuses = ['ready', 'ready_to_ship'];
$completedStatuses = ['shipped', 'delivered', 'pickedup'];
foreach ($vendorOrders as $refCode => $order) {
    $status = $order['current_status'];
    if ($status === 'preflight' && empty($order['confirmed_at'])) $tabs['new'][] = $order;
    elseif ($status === 'printing') $tabs['printing'][] = $order;
    elseif (in_array($status, $readyStatuses)) $tabs['ready'][] = $order;
    elseif (in_array($status, $completedStatuses)) $tabs['completed'][] = $order;
    elseif ($status === 'file_issue') $tabs['issues'][] = $order;
}
usort($tabs['new'], fn($a, $b) => strtotime($a['due_date'] ?? '2099-01-01') <=> strtotime($b['due_date'] ?? '2099-01-01'));
usort($tabs['printing'], fn($a, $b) => strtotime($a['due_date'] ?? '2099-01-01') <=> strtotime($b['due_date'] ?? '2099-01-01'));
usort($tabs['ready'], fn($a, $b) => strtotime($b['ready_at'] ?? $b['confirmed_at'] ?? '0') <=> strtotime($a['ready_at'] ?? $a['confirmed_at'] ?? '0'));
usort($tabs['completed'], fn($a, $b) => strtotime($b['shipped_at'] ?? $b['confirmed_at'] ?? '0') <=> strtotime($a['shipped_at'] ?? $a['confirmed_at'] ?? '0'));
usort($tabs['issues'], fn($a, $b) => strtotime($b['pushed_at'] ?? '0') <=> strtotime($a['pushed_at'] ?? '0'));

$counts = [
    'new' => count($tabs['new']), 'printing' => count($tabs['printing']),
    'ready' => count($tabs['ready']), 'completed' => count($tabs['completed']),
    'issues' => count($tabs['issues']),
    'total' => count($vendorOrders)
];

// Count orders due today
$dueToday = 0;
foreach ($vendorOrders as $o) {
    if (!empty($o['due_date']) && daysUntilDue($o['due_date']) === 0) $dueToday++;
}

// Vendor cost summary (completed orders with accepted pricing)
$vendorSummary = ['completed_count' => 0, 'total_base' => 0, 'total_packing' => 0, 'total_tax' => 0, 'total_cost' => 0, 'pending_price_count' => 0];
$actionCounts = ['needs_pricing' => 0, 'needs_confirm' => 0, 'printing' => 0, 'ready_pickup' => 0, 'unpaid' => 0];
foreach ($vendorOrders as $o) {
    $vp = $o['vendor_pricing'] ?? [];
    $vpStatus = $vp['status'] ?? 'none';
    if ((in_array($o['current_status'], $readyStatuses) || in_array($o['current_status'], $completedStatuses)) && $vpStatus === 'accepted') {
        $vendorSummary['completed_count']++;
        $vendorSummary['total_base'] += floatval($vp['base_price'] ?? 0);
        $vendorSummary['total_packing'] += floatval($vp['packing_price'] ?? 0);
        $vendorSummary['total_tax'] += floatval($vp['tax_amount'] ?? 0);
        $vendorSummary['total_cost'] += floatval($vp['total'] ?? 0);
    }
    if ($vpStatus === 'none' || $vpStatus === 'rejected') {
        $vendorSummary['pending_price_count']++;
        if ($o['current_status'] === 'preflight') $actionCounts['needs_pricing']++;
    }
    if ($vpStatus === 'accepted' && $o['current_status'] === 'preflight' && empty($o['confirmed_at'])) {
        $actionCounts['needs_confirm']++;
    }
    if ($o['current_status'] === 'printing') $actionCounts['printing']++;
    if (in_array($o['current_status'], $readyStatuses)) $actionCounts['ready_pickup']++;
    if (empty($o['vendor_paid'])) $actionCounts['unpaid']++;
}

// Packing label helper
function getPackingLabel($type, $custom = '') {
    if ($type === 'custom' && $custom) return htmlspecialchars($custom);
    return ['tube' => 'Tube', 'box' => 'Box', 'flat' => 'Flat', 'none' => 'None'][$type ?? 'none'] ?? ucfirst($type ?? 'None');
}
function getPackingIcon($type) {
    return ['tube' => '&#9645;', 'box' => '&#128230;', 'flat' => '&#128196;', 'none' => '&mdash;', 'custom' => '&#9881;'][$type ?? 'none'] ?? '&mdash;';
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
    $_dtlDisplay = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm', 'anytime' => 'anytime'];
    $dueTimeStr = ($deliveryTime && $deliveryTime !== 'anytime' && isset($_dtl[$deliveryTime]))
        ? $_dtl[$deliveryTime] : '23:59';
    $dueTs = strtotime($dueDate . ' ' . $dueTimeStr . ':59');
    $timeLabel = $_dtlDisplay[$deliveryTime] ?? $deliveryTime ?? 'anytime';
    $dateDisplay = date('D, M j', strtotime($dueDate)) . ' &middot; ' . $timeLabel;
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

$compactCols = $isAdmin ? 10 : 9;

/**
 * Split an array of orders into batch groups and unbatched orders
 */
function splitByBatch($orders) {
    $batched = [];
    $unbatched = [];
    foreach ($orders as $order) {
        $b = $order['batch'] ?? null;
        if ($b) {
            $bid = $b['batch_id'];
            if (!isset($batched[$bid])) $batched[$bid] = ['id' => $bid, 'label' => $b['label'], 'orders' => []];
            $batched[$bid]['orders'][] = $order;
        } else {
            $unbatched[] = $order;
        }
    }
    return ['batched' => $batched, 'unbatched' => $unbatched];
}

/**
 * Render batch group sections for a tab
 */
function renderBatchGroups($batchedGroups, $canAct, $isAdmin, $isGodMode, $compactCols, $tabType) {
    if (empty($batchedGroups)) return '';
    $html = '';
    foreach ($batchedGroups as $group) {
        $bid = htmlspecialchars($group['id']);
        $label = htmlspecialchars($group['label']);
        $count = count($group['orders']);
        
        // Count issues in this batch
        $issueCount = 0;
        $allConfirmable = true;
        $allReadyable = true;
        foreach ($group['orders'] as $o) {
            $sr = $o['status_raw'] ?? $o['current_status'] ?? '';
            if ($sr === 'file_issue') $issueCount++;
            if ($sr !== 'preflight' || !empty($o['confirmed_at'])) $allConfirmable = false;
            if ($sr !== 'printing') $allReadyable = false;
        }
        
        // Batch pricing summary
        $bGrand = 0; $bStatus = 'none'; $bAllSub = true; $bAllAcc = true; $bAnyPriced = false;
        $bRefs = [];
        foreach ($group['orders'] as $bo) {
            $bvp = $bo['vendor_pricing'] ?? [];
            $bs = $bvp['status'] ?? 'none';
            $bRefs[] = $bo['reference_code'];
            if ($bs !== 'submitted' && $bs !== 'accepted') { $bAllSub = false; $bAllAcc = false; }
            if ($bs !== 'accepted') $bAllAcc = false;
            if ($bs === 'submitted' || $bs === 'accepted') { $bAnyPriced = true; $bGrand += floatval($bvp['total'] ?? 0); }
        }
        if ($bAllAcc && $bAnyPriced) $bStatus = 'accepted';
        elseif ($bAllSub && $bAnyPriced) $bStatus = 'submitted';
        elseif ($bAnyPriced) $bStatus = 'partial';
        
        // Header CSS class based on pricing status
        $headerClass = 'vp-batch-group-header';
        
        $html .= '<div class="vp-batch-group" data-batch-id="' . $bid . '">';
        $html .= '<div class="' . $headerClass . '" onclick="toggleVpBatch(\'' . $bid . '\')">';
        $html .= '<span class="vp-batch-chevron" id="vpBatchChev_' . $bid . '">&#9660;</span>';
        $html .= '<span class="vp-batch-id">' . $bid . '</span>';
        if ($label) $html .= '<span class="vp-batch-label-sub">' . $label . '</span>';
        $html .= '<span class="vp-batch-count">' . $count . ' order' . ($count > 1 ? 's' : '') . '</span>';
        if ($issueCount > 0) {
            $html .= '<span class="vp-batch-issue-warn">&#9888; ' . $issueCount . ' issue' . ($issueCount > 1 ? 's' : '') . '</span>';
        }
        // Right side: pricing + separator + action buttons
        $html .= '<span class="vp-batch-right" onclick="event.stopPropagation()">';
        if ($bAnyPriced) {
            $html .= '<span class="vp-batch-pricing">';
            if ($bStatus === 'accepted') {
                $html .= '<span class="vp-batch-price-status vp-bps-approved">&#10003; Approved</span>';
            } elseif ($bStatus === 'submitted') {
                $html .= '<span class="vp-batch-price-status vp-bps-pending">Pending</span>';
            } elseif ($bStatus === 'partial') {
                $html .= '<span class="vp-batch-price-status vp-bps-partial">Partial</span>';
            }
            $html .= '<span class="vp-batch-header-sep"></span>';
            $html .= '<span class="vp-batch-price-total"><span class="vp-batch-price-label">Batch Total</span> $' . number_format($bGrand, 2) . '</span>';
            $html .= '</span>';
            $html .= '<span class="vp-batch-header-sep"></span>';
        }
        // Vendor batch actions (only for vendor, not admin)
        if ($canAct && !$isAdmin) {
            $html .= '<span class="vp-batch-actions" onclick="event.stopPropagation()">';
            if ($tabType === 'new') {
                // Check if any order in batch still needs pricing
                $needsPrice = false;
                foreach ($group['orders'] as $o) {
                    $vs = $o['vendor_pricing']['status'] ?? 'none';
                    if ($vs === 'none' || $vs === 'rejected') { $needsPrice = true; break; }
                }
                if ($needsPrice) {
                    $html .= '<button class="vp-btn vp-btn-accent vp-btn-sm" onclick="openBatchPriceModal(\'' . $bid . '\')">Price Batch</button>';
                }
                if ($allConfirmable) {
                    $refs = array_map(function($o) { return $o['reference_code']; }, $group['orders']);
                    $html .= '<button class="vp-btn vp-btn-confirm vp-btn-sm" onclick="batchConfirmAll(\'' . $bid . '\',' . htmlspecialchars(json_encode($refs)) . ')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Confirm All</button>';
                }
            }
            if ($tabType === 'printing' && $allReadyable) {
                $refs = array_map(function($o) { return $o['reference_code']; }, $group['orders']);
                $html .= '<button class="vp-btn vp-btn-confirm vp-btn-sm" onclick="batchReadyAll(\'' . $bid . '\',' . htmlspecialchars(json_encode($refs)) . ')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Ready All</button>';
            }
            $html .= '</span>';
        }
        // Admin batch price review
        if ($isAdmin && $isGodMode && $tabType === 'new') {
            $anyPending = false;
            foreach ($group['orders'] as $o) {
                if (($o['vendor_pricing']['status'] ?? '') === 'submitted') { $anyPending = true; break; }
            }
            if ($anyPending) {
                $html .= '<span class="vp-batch-actions" onclick="event.stopPropagation()">';
                $refs = array_map(function($o) { return $o['reference_code']; }, $group['orders']);
                $html .= '<button class="vp-btn vp-btn-primary vp-btn-sm" onclick="batchApprovePrice(\'' . $bid . '\',' . htmlspecialchars(json_encode($refs)) . ')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Approve All</button>';
                $html .= '</span>';
            }
        }
        $html .= '</span>'; // close vp-batch-right
        $html .= '</div>';
        $html .= '<div class="vp-batch-body" id="vpBatchBody_' . $bid . '">';
        $html .= '<table class="vp-table vp-batch-table vp-sortable" data-tab="' . $tabType . '">';
        $html .= '<thead><tr>';
        $html .= '<th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="' . $tabType . '_' . $bid . '"></th>';
        $html .= '<th class="sortable" data-sort="ref">Order</th>';
        $html .= '<th class="sortable" data-sort="file">File</th>';
        $html .= '<th class="sortable" data-sort="size">Size</th>';
        $html .= '<th class="sortable" data-sort="material">Material</th>';
        $html .= '<th class="sortable" data-sort="due">Due</th>';
        $html .= '<th class="sortable" data-sort="timer">Timer</th>';
        $html .= '<th class="sortable" data-sort="packing">Packing</th>';
        $html .= '<th>Vendor Ref</th>';
        if ($isAdmin) $html .= '<th class="sortable" data-sort="vendor">Vendor</th>';
        $html .= '<th class="sortable" data-sort="price">Price</th>';
        $html .= '<th class="sortable" data-sort="status">Status</th>';
        $html .= '<th class="sortable" data-sort="paid">Paid</th>';
        $html .= '<th>Action</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        foreach ($group['orders'] as $order) {
            $html .= renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, $tabType);
        }
        
        
        $html .= '</tbody></table>';
        $html .= '</div></div>';
    }
    return $html;
}

/**
 * Render a table row with: Order, File, Size, Material, Due, Packing, Price, Action
 */
function renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, $tabType) {
    $ref = htmlspecialchars($order['reference_code']);
    $dueClass = getDueClass($order['due_date'] ?? null);
    $area = calcArea($order['dimensions'] ?? ['width'=>0,'height'=>0]);
    $hasNotes = !empty($order['notes']) || !empty($order['vendor_notes_list']) || !empty($order['file_issue_reason']);
    
    $dueTs = !empty($order['due_date']) ? strtotime($order['due_date']) : 9999999999;
    $recTs = !empty($order['pushed_at']) ? strtotime($order['pushed_at']) : 0;
    $confTs = !empty($order['confirmed_at']) ? strtotime($order['confirmed_at']) : 0;
    $timeTs = ($tabType === 'printing') ? $confTs : $recTs;
    
    $vp = $order['vendor_pricing'] ?? [];
    $vpStatus = $vp['status'] ?? 'none';
    $vpTotal = $vp['total'] ?? null;
    
    $html = '<tr class="vp-row ' . $dueClass . '" data-ref="' . $ref . '"';
    $html .= ' data-file="' . htmlspecialchars($order['file_name'] ?? '') . '"';
    $html .= ' data-size="' . $area . '"';
    $html .= ' data-material="' . htmlspecialchars($order['material'] ?? '') . '"';
    $html .= ' data-due="' . $dueTs . '"';
    $html .= ' data-received="' . $timeTs . '"';
    $html .= ' data-vendor="' . htmlspecialchars($order['vendor_name_display'] ?? '') . '"';
    $html .= ' data-vendor-id="' . htmlspecialchars($order['vendor_id'] ?? '') . '"';
    $html .= ' data-packing="' . htmlspecialchars($order['packing'] ?? 'none') . '"';
    $html .= ' data-price="' . ($vpTotal !== null ? $vpTotal : 0) . '"';
    $html .= ' data-paid="' . (empty($order['vendor_paid']) ? '0' : '1') . '"';
    $html .= ' data-tab="' . $tabType . '">';
    
    // Checkbox
    $html .= '<td class="vp-col-check"><input type="checkbox" class="vp-checkbox order-checkbox" data-reference="' . $ref . '"></td>';
    
    // Order ref + notes indicator (clickable to open panel)
    $html .= '<td class="vp-col-ref"><a href="#" class="vp-ref-link" onclick="event.preventDefault();openPanel(\'' . $ref . '\')">' . $ref . '</a>';
    if ($hasNotes) $html .= ' <span class="vp-has-notes" title="Has notes">&#128172;</span>';
    $html .= '</td>';
    
    // File name
    // File name with hover preview
    $fileName = $order['file_name'] ?? 'No file';
    $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $isImage = in_array($fileExt, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'tiff', 'tif']);
    $dlUrl = 'api.php?action=download&amp;ref=' . urlencode($order['reference_code']);
    $html .= '<td class="vp-col-file"><a href="' . $dlUrl . '" class="vp-file-dl" title="Download file">';
    $html .= '<svg class="vp-dl-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>';
    $html .= ' <span class="vp-file-name">' . htmlspecialchars($fileName) . '</span>';
    $html .= '<span class="vp-file-tooltip">';
    if ($isImage) $html .= '<img class="vp-file-thumb" src="' . $dlUrl . '" alt="">';
    $html .= '<span class="vp-file-tooltip-name">' . htmlspecialchars($fileName) . '</span>';
    $html .= '<span class="vp-file-tooltip-ext">' . strtoupper($fileExt ?: '?') . ($order['file_size'] ? ' &middot; ' . formatFileSize($order['file_size'] ?? 0) : '') . '</span>';
    $html .= '</span>';
    $html .= '</a></td>';
    
    // Size
    $html .= '<td class="vp-col-size">' . ($order['dimensions']['width'] ?? 0) . '&quot;&times;' . ($order['dimensions']['height'] ?? 0) . '&quot;</td>';
    
    // Material
    $mat = ucfirst($order['material'] ?? '');
    $matClass = strtolower($order['material'] ?? '') === 'fabric' ? 'vp-mat-fabric' : 'vp-mat-paper';
    $html .= '<td class="vp-col-mat"><span class="vp-mat ' . $matClass . '">' . $mat . '</span></td>';
    
    // Due (date) + Timer (countdown) as separate cells
    $_dtl = ['9am' => '9:00', '12pm' => '12:00', '3pm' => '15:00', '6pm' => '18:00'];
    $_dtlD = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm', 'anytime' => 'anytime'];
    $dueDate = $order['due_date'] ?? null;
    $delTime = $order['delivery_time'] ?? 'anytime';
    $dueTimeStr = ($delTime && $delTime !== 'anytime' && isset($_dtl[$delTime])) ? $_dtl[$delTime] : '23:59';
    $dueTs = $dueDate ? strtotime($dueDate . ' ' . $dueTimeStr . ':59') : 0;
    $timeLabel = $_dtlD[$delTime] ?? $delTime ?? 'anytime';
    $dateDisplay = $dueDate ? date('D, M j', strtotime($dueDate)) . ' &middot; ' . $timeLabel : '&mdash;';
    $html .= '<td class="vp-col-due"><span class="vp-due-date">' . $dateDisplay . '</span></td>';
    $html .= '<td class="vp-col-timer">' . ($dueTs ? '<span class="vp-timer" data-due-ts="' . $dueTs . '"></span>' : '&mdash;') . '</td>';
    
    // Packing (dropdown for vendor, read-only for admin)
    $pType = $order['packing'] ?? 'none';
    $packDetails = $order['packing_details'] ?? [];

    $html .= '<td class="vp-col-pack">';
    if ($canAct && !$isAdmin && in_array($tabType, ['new', 'printing'])) {
        $html .= '<div class="vp-pack-wrap" data-ref="' . $ref . '">';
        $html .= '<span class="vp-pack-badge vp-pack-' . $pType . '" onclick="vpTogglePack(event,\'' . $ref . '\')">' . getPackingLabel($pType, $order['packing_custom'] ?? '') . '</span>';
        $html .= '<div class="vp-pack-dd" id="vpPD_' . $ref . '">';
        foreach (['none' => 'None / Flat', 'tube' => 'Tube', 'box' => 'Box', 'custom' => 'Custom'] as $pv => $pl) {
            $isCur = ($pv === $pType) ? ' current' : '';
            $html .= '<button type="button" class="vp-pack-item' . $isCur . '" data-ref="' . $ref . '" data-val="' . $pv . '"><span class="vp-pack-badge vp-pack-' . $pv . '">' . $pl . '</span></button>';
        }
        $html .= '</div></div>';
    } else {
        $html .= '<span class="vp-pack-badge vp-pack-' . $pType . '">' . getPackingLabel($pType, $order['packing_custom'] ?? '') . '</span>';
    }
    $html .= '</td>';
    
    // Vendor Ref (editable by vendor)
    $vendorRef = $order['vendor_order_number'] ?? '';
    $html .= '<td class="vp-col-vendorref">';
    if ($canAct && !$isAdmin) {
        if ($vendorRef) {
            $html .= '<span class="vp-vendorref-display" data-ref="' . $ref . '">' . htmlspecialchars($vendorRef) . ' <span class="vp-vendorref-edit" onclick="editVendorRef(\'' . $ref . '\')" title="Edit"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></span></span>';
            $html .= '<input type="text" class="vp-vendorref-input" value="' . htmlspecialchars($vendorRef) . '" data-ref="' . $ref . '" style="display:none" onblur="saveVendorRef(this)">';
        } else {
            $html .= '<input type="text" class="vp-vendorref-input" value="" placeholder="Order #" data-ref="' . $ref . '" onblur="saveVendorRef(this)">';
        }
    } else {
        $html .= $vendorRef ? htmlspecialchars($vendorRef) : '<span class="vp-muted">&mdash;</span>';
    }
    $html .= '</td>';
    
    // Vendor (admin only)
    if ($isAdmin) {
        $html .= '<td class="vp-col-vendor">' . htmlspecialchars($order['vendor_name_display'] ?? '&mdash;') . '</td>';
    }
    
    // Price
    $html .= '<td class="vp-col-price">';
    if ($vpStatus === 'none' || $vpStatus === 'rejected') {
        if ($canAct && !$isAdmin) {
            $html .= '<button class="vp-price-add" onclick="openPriceForm(\'' . $ref . '\', this)">+ Add Price</button>';
            if ($vpStatus === 'rejected') {
                $reason = htmlspecialchars($vp['rejection_reason'] ?? '');
                $html .= '<div class="vp-price-rejected" title="' . $reason . '">&#9888; Rejected</div>';
            }
        } elseif ($isAdmin) {
            if ($vpStatus === 'rejected') {
                $html .= '<span class="vp-price-badge vp-badge-rejected">Rejected</span>';
            } else {
                $html .= '<span class="vp-muted">Awaiting vendor</span>';
            }
        } else {
            $html .= '<span class="vp-muted">&mdash;</span>';
        }
    } elseif ($vpStatus === 'submitted') {
        $html .= '<span class="vp-price-val">$' . number_format($vpTotal, 2) . '</span>';
        if ($isAdmin && $isGodMode) {
            $html .= '<div class="vp-price-review">';
            $html .= '<button class="vp-pr-btn vp-pr-accept" onclick="approvePrice(\'' . $ref . '\')">&#10003;</button>';
            $html .= '<button class="vp-pr-btn vp-pr-reject" onclick="showRejectPriceModal(\'' . $ref . '\')">&#10007;</button>';
            $html .= '</div>';
            $vpPacking = $vp['packing_price'] ?? 0;
            if ($vpPacking > 0) {
                $html .= '<div class="vp-price-breakdown">$' . number_format($vpPacking, 2) . ' packing</div>';
            }
        } elseif ($canAct && !$isAdmin) {
            $html .= '<a href="#" class="vp-price-edit" onclick="event.preventDefault();openPriceForm(\'' . $ref . '\', this.closest(\'td\').querySelector(\'.vp-price-val\') || this)">' . '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>' . '</a>';
        } else {
        }
        // Packing fee breakdown
        $vpPacking = $vp['packing_price'] ?? 0;
        if ($vpPacking > 0) {
            $html .= '<div class="vp-price-breakdown">$' . number_format($vpPacking, 2) . ' packing</div>';
        }
    } elseif ($vpStatus === 'accepted') {
        $html .= '<span class="vp-price-val">$' . number_format($vpTotal, 2) . '</span>';
        $vpPacking = $vp['packing_price'] ?? 0;
        if ($vpPacking > 0) {
            $html .= '<div class="vp-price-breakdown">$' . number_format($vpPacking, 2) . ' packing</div>';
        }
    }
    $html .= '</td>';
    
    // Status
    $html .= '<td class="vp-col-status">';
    if ($vpStatus === 'submitted') {
        $html .= '<span class="vp-status-badge vp-status-pending">Pending</span>';
    } elseif ($vpStatus === 'accepted') {
        $html .= '<span class="vp-status-badge vp-status-approved">Approved</span>';
    } elseif ($vpStatus === 'rejected') {
        $html .= '<span class="vp-status-badge vp-status-rejected">Rejected</span>';
    } else {
        $html .= '<span class="vp-status-badge vp-status-none">No Price</span>';
    }
    $html .= '</td>';
    
    // Paid (vendor payment)
    $isPaid = !empty($order['vendor_paid']);
    $paidIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    if ($isPaid) {
        $html .= '<td class="vp-col-paid"><span class="vp-paid-icon vp-paid-yes" title="Paid">' . $paidIcon . '</span></td>';
    } else {
        $html .= '<td class="vp-col-paid"><span class="vp-paid-icon vp-paid-no" title="Not paid">' . $paidIcon . '</span></td>';
    }
    
    // Action
    $html .= '<td class="vp-col-act">';
    if ($canAct) {
        if ($tabType === 'new') {
            // Can only confirm if price is approved
            if ($vpStatus === 'accepted') {
                $html .= '<button class="vp-btn vp-btn-approve" onclick="confirmOrder(\'' . $ref . '\')">&#10003; Confirm</button>';
            } else {
                $html .= '<button class="vp-btn vp-btn-approve vp-btn-disabled" disabled title="Price must be approved first">&#10003; Confirm</button>';
            }
            $html .= '<button class="vp-btn vp-btn-issue" onclick="showIssueModal(\'' . $ref . '\')">&#9888; Issue</button>';
        } elseif ($tabType === 'printing') {
            $html .= '<button class="vp-btn vp-btn-ready" onclick="markReady(\'' . $ref . '\')">&#10003; Ready for Pickup</button>';
        } elseif ($tabType === 'completed') {
            $html .= '<button class="vp-btn vp-btn-reprint" onclick="revertToPrinting(\'' . $ref . '\')">&#8617; Reprint</button>';
        } elseif ($tabType === 'issues') {
            $html .= '<button class="vp-btn vp-btn-approve" onclick="confirmOrder(\'' . $ref . '\')">&#10003; Re-confirm</button>';
        }
    }
    $html .= '</td>';
    
    $html .= '</tr>';
    return $html;
}
?>
<?php
// Build JSON data for slide-out panel
$panelData = [];
foreach ($vendorOrders as $refCode => $order) {
    $panelData[$refCode] = [
        'ref' => $refCode,
        'vendor_id' => $order['vendor_id'] ?? '',
        'file_name' => $order['file_name'] ?? 'No file',
        'file_size' => formatFileSize($order['file_size'] ?? 0),
        'width' => $order['dimensions']['width'] ?? 0,
        'height' => $order['dimensions']['height'] ?? 0,
        'material' => ucfirst($order['material'] ?? ''),
        'due_date' => !empty($order['due_date']) ? date('l, M j, Y', strtotime($order['due_date'])) : null,
        'delivery_time' => $order['delivery_time'] ?? 'anytime',
        'due_ts' => !empty($order['due_date']) ? strtotime($order['due_date'] . ' ' . (['9am'=>'9:00','12pm'=>'12:00','3pm'=>'15:00','6pm'=>'18:00'][$order['delivery_time']??''] ?? '23:59') . ':59') : null,
        'status' => getStatusLabel($order['current_status'] ?? ''),
        'status_raw' => $order['current_status'] ?? '',
        'vendor_name' => $order['vendor_name_display'] ?? '',
        'pushed_at' => !empty($order['pushed_at']) ? date('M j, g:ia', strtotime($order['pushed_at'])) : null,
        'confirmed_at' => !empty($order['confirmed_at']) ? date('M j, g:ia', strtotime($order['confirmed_at'])) : null,
        'ready_at' => !empty($order['ready_at']) ? date('M j, g:ia', strtotime($order['ready_at'])) : null,
        'shipped_at' => !empty($order['shipped_at']) ? date('M j, g:ia', strtotime($order['shipped_at'])) : null,
        'downloads' => $order['vendor_downloads'] ?? 0,
        'customer_notes' => $order['notes'] ?? '',
        'vendor_notes' => array_map(function($n) {
            return ['by' => $n['by'] ?? 'Vendor', 'text' => $n['text'] ?? '', 'time' => !empty($n['timestamp']) ? date('M j, g:ia', strtotime($n['timestamp'])) : ''];
        }, $order['vendor_notes_list'] ?? []),
        'issue_reason' => $order['file_issue_reason'] ?? '',
        'packing' => $order['packing'] ?? 'none',
        'packing_custom' => $order['packing_custom'] ?? '',
        'packing_label' => getPackingLabel($order['packing'] ?? 'none', $order['packing_custom'] ?? ''),
        'packing_details' => $order['packing_details'] ?? [],
        'vendor_order_number' => $order['vendor_order_number'] ?? '',
        'print_notes' => $order['print_notes'] ?? '',
        'batch' => $order['batch'] ?? null,
        'vendor_pricing' => array_merge($order['vendor_pricing'] ?? ['status' => 'none'], [
            'submitted_at' => !empty($order['vendor_pricing']['submitted_at']) ? date('M j, g:ia', strtotime($order['vendor_pricing']['submitted_at'])) : null,
            'reviewed_at' => !empty($order['vendor_pricing']['reviewed_at']) ? date('M j, g:ia', strtotime($order['vendor_pricing']['reviewed_at'])) : null,
        ]),
        'vendor_paid' => !empty($order['vendor_paid']),
        'vendor_paid_at' => !empty($order['vendor_paid_at']) ? date('M j, g:ia', strtotime($order['vendor_paid_at'])) : null,
        'vendor_paid_by' => $order['vendor_paid_by'] ?? null,
    ];
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
            <?php if (!$isAdmin): ?>
            <button class="vp-header-link" onclick="openVendorProfile()">My Profile</button>
            <?php endif; ?>
            <a href="?logout=1" class="vp-header-link">Sign Out</a>
        </div>
    </header>

    <!-- ===== SUMMARY CARDS ===== -->
    <div class="vp-summary">
        <div class="vp-card">
            <div class="vp-card-value"><?= $counts['total'] ?></div>
            <div class="vp-card-label">Total Orders</div>
            <div class="vp-card-sub"><?= $dueToday > 0 ? '<span class="vp-card-alert">' . $dueToday . ' due today</span>' : date('l, M j') ?></div>
        </div>
        <div class="vp-card">
            <div class="vp-card-value"><?= $vendorSummary['completed_count'] ?></div>
            <div class="vp-card-label">Completed</div>
            <div class="vp-card-sub"><?= $vendorSummary['pending_price_count'] > 0 ? $vendorSummary['pending_price_count'] . ' need pricing' : 'All priced' ?></div>
        </div>
        <div class="vp-card">
            <div class="vp-card-value">$<?= number_format($vendorSummary['total_cost'], 2) ?></div>
            <div class="vp-card-label">Total Cost</div>
            <div class="vp-card-sub">Base $<?= number_format($vendorSummary['total_base'], 2) ?> &middot; Packing $<?= number_format($vendorSummary['total_packing'], 2) ?></div>
        </div>
        <div class="vp-card">
            <div class="vp-card-value">$<?= number_format($vendorSummary['total_tax'], 2) ?></div>
            <div class="vp-card-label">Tax (13%)</div>
            <div class="vp-card-sub">Included in total</div>
        </div>
    </div>

    <!-- ===== VENDOR ACTION STRIP ===== -->
    <?php
    $actionItems = [];
    if ($actionCounts['needs_pricing'] > 0) $actionItems[] = '<span class="vp-action-item vp-ai-amber">' . $actionCounts['needs_pricing'] . ' to price</span>';
    if ($actionCounts['needs_confirm'] > 0) $actionItems[] = '<span class="vp-action-item vp-ai-amber">' . $actionCounts['needs_confirm'] . ' to confirm</span>';
    if ($actionCounts['printing'] > 0) $actionItems[] = '<span class="vp-action-item vp-ai-blue">' . $actionCounts['printing'] . ' printing</span>';
    if ($actionCounts['ready_pickup'] > 0) $actionItems[] = '<span class="vp-action-item vp-ai-green">' . $actionCounts['ready_pickup'] . ' ready for pickup</span>';
    if ($dueToday > 0) $actionItems[] = '<span class="vp-action-item vp-ai-red">' . $dueToday . ' due today</span>';
    ?>
    <?php if (!empty($actionItems)): ?>
    <div class="vp-action-strip">
        <span class="vp-action-strip-label">Action needed:</span>
        <?= implode('', $actionItems) ?>
    </div>
    <?php endif; ?>

    <!-- ===== TAB BAR ===== -->
    <div class="vp-controls">
        <div class="vp-tabs">
            <button class="vp-tab active" data-tab="new">
                New<?php if($counts['new']>0):?><span class="vp-tab-count"><?=$counts['new']?></span><?php endif;?>
            </button>
            <button class="vp-tab" data-tab="printing">
                Printing<?php if($counts['printing']>0):?><span class="vp-tab-count vp-tc-blue"><?=$counts['printing']?></span><?php endif;?>
            </button>
            <button class="vp-tab" data-tab="ready">
                Ready<?php if($counts['ready']>0):?><span class="vp-tab-count vp-tc-amber"><?=$counts['ready']?></span><?php endif;?>
            </button>
            <button class="vp-tab" data-tab="completed">
                Completed<?php if($counts['completed']>0):?><span class="vp-tab-count vp-tc-green"><?=$counts['completed']?></span><?php endif;?>
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
            </div>
            <select id="fpPerPage" class="vp-per-page">
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
                <option value="all">All</option>
            </select>
            <span class="vp-results" id="fpShowing"><span id="fpShowRange">0</span> of <span id="fpTotalCount">0</span></span>
            <button class="vp-btn vp-btn-ghost vp-btn-sm vp-export-btn" onclick="exportCSV()" title="Export current tab as CSV">&#8615; Export CSV</button>
        </div>
    </div>

    <!-- ===== TABLES ===== -->
    <div class="vp-table-wrap">

        <!-- TAB: New Orders -->
        <div class="vp-tab-pane active" id="tab-new">
            <?php if (empty($tabs['new'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&check;</div><h3>All caught up</h3><p>New orders will appear here when assigned.</p></div>
            <?php else: ?>
            <?php $split = splitByBatch($tabs['new']); ?>
            <?= renderBatchGroups($split['batched'], $canAct, $isAdmin, $isGodMode, $compactCols, 'new') ?>
            <?php if (!empty($split['unbatched'])): ?>
            <div class="vp-unbatched-card"><?php if (!empty($split['batched'])): ?><div class="vp-unbatched-divider">Unbatched Orders</div><?php endif; ?>
            <table class="vp-table vp-sortable" data-tab="new">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="new"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="material">Material</th>
                    <th class="sortable sorted-asc" data-sort="due">Due</th>
                    <th class="sortable" data-sort="timer">Timer</th>
                    <th class="sortable" data-sort="packing">Packing</th>
                    <th>Vendor Ref</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="price">Price</th>
                    <th class="sortable" data-sort="status">Status</th>
                    <th class="sortable" data-sort="paid">Paid</th>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($split['unbatched'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, 'new'); ?>
                </tbody>
            </table>
            </div>
            <?php elseif (empty($split['batched'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&check;</div><h3>All caught up</h3><p>New orders will appear here when assigned.</p></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- TAB: Printing -->
        <div class="vp-tab-pane" id="tab-printing">
            <?php $split = splitByBatch($tabs['printing']); ?>
            <?= renderBatchGroups($split['batched'], $canAct, $isAdmin, $isGodMode, $compactCols, 'printing') ?>
            <?php if (!empty($split['unbatched'])): ?>
            <div class="vp-unbatched-card"><?php if (!empty($split['batched'])): ?><div class="vp-unbatched-divider">Unbatched Orders</div><?php endif; ?>
            <table class="vp-table vp-sortable" data-tab="printing">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="printing"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="material">Material</th>
                    <th class="sortable sorted-asc" data-sort="due">Due</th>
                    <th class="sortable" data-sort="timer">Timer</th>
                    <th class="sortable" data-sort="packing">Packing</th>
                    <th>Vendor Ref</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="price">Price</th>
                    <th class="sortable" data-sort="status">Status</th>
                    <th class="sortable" data-sort="paid">Paid</th>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($split['unbatched'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, 'printing'); ?>
                </tbody>
            </table>
            </div>
            <?php elseif (empty($split['batched'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&#9881;</div><h3>Nothing printing</h3><p>Confirmed orders will appear here.</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: Ready -->
        <div class="vp-tab-pane" id="tab-ready">
            <?php $split = splitByBatch($tabs['ready']); ?>
            <?= renderBatchGroups($split['batched'], $canAct, $isAdmin, $isGodMode, $compactCols, 'ready') ?>
            <?php if (!empty($split['unbatched'])): ?>
            <div class="vp-unbatched-card"><?php if (!empty($split['batched'])): ?><div class="vp-unbatched-divider">Unbatched Orders</div><?php endif; ?>
            <table class="vp-table vp-sortable" data-tab="ready">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="ready"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="material">Material</th>
                    <th class="sortable" data-sort="due">Due</th>
                    <th class="sortable" data-sort="timer">Timer</th>
                    <th class="sortable" data-sort="packing">Packing</th>
                    <th>Vendor Ref</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="price">Price</th>
                    <th class="sortable" data-sort="status">Status</th>
                    <th class="sortable" data-sort="paid">Paid</th>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($split['unbatched'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, 'ready'); ?>
                </tbody>
            </table>
            </div>
            <?php elseif (empty($split['batched'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&#128230;</div><h3>Nothing ready</h3><p>Printed orders will appear here.</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: Completed -->
        <div class="vp-tab-pane" id="tab-completed">
            <?php $split = splitByBatch($tabs['completed']); ?>
            <?= renderBatchGroups($split['batched'], $canAct, $isAdmin, $isGodMode, $compactCols, 'completed') ?>
            <?php if (!empty($split['unbatched'])): ?>
            <div class="vp-unbatched-card"><?php if (!empty($split['batched'])): ?><div class="vp-unbatched-divider">Unbatched Orders</div><?php endif; ?>
            <table class="vp-table vp-sortable" data-tab="completed">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="completed"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="material">Material</th>
                    <th class="sortable" data-sort="due">Due</th>
                    <th class="sortable" data-sort="timer">Timer</th>
                    <th class="sortable" data-sort="packing">Packing</th>
                    <th>Vendor Ref</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="price">Price</th>
                    <th class="sortable" data-sort="status">Status</th>
                    <th class="sortable" data-sort="paid">Paid</th>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($split['unbatched'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, 'completed'); ?>
                </tbody>
            </table>
            </div>
            <?php elseif (empty($split['batched'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&#10004;</div><h3>No completed orders</h3><p>Shipped orders will appear here.</p></div>
            <?php endif; ?>
        </div>

        <!-- TAB: File Issues -->
        <div class="vp-tab-pane" id="tab-issues">
            <?php $split = splitByBatch($tabs['issues']); ?>
            <?= renderBatchGroups($split['batched'], $canAct, $isAdmin, $isGodMode, $compactCols, 'issues') ?>
            <?php if (!empty($split['unbatched'])): ?>
            <div class="vp-unbatched-card"><?php if (!empty($split['batched'])): ?><div class="vp-unbatched-divider">Unbatched Orders</div><?php endif; ?>
            <table class="vp-table vp-sortable" data-tab="issues">
                <thead><tr>
                    <th class="vp-col-check"><input type="checkbox" class="vp-checkbox vp-select-all" data-tab="issues"></th>
                    <th class="sortable" data-sort="ref">Order</th>
                    <th class="sortable" data-sort="file">File</th>
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="material">Material</th>
                    <th class="sortable" data-sort="due">Due</th>
                    <th class="sortable" data-sort="timer">Timer</th>
                    <th class="sortable" data-sort="packing">Packing</th>
                    <th>Vendor Ref</th>
                    <?php if ($isAdmin): ?><th class="sortable" data-sort="vendor">Vendor</th><?php endif; ?>
                    <th class="sortable" data-sort="price">Price</th>
                    <th class="sortable" data-sort="status">Status</th>
                    <th class="sortable" data-sort="paid">Paid</th>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($split['unbatched'] as $order) echo renderOrderRow($order, $canAct, $isAdmin, $isGodMode, $compactCols, 'issues'); ?>
                </tbody>
            </table>
            </div>
            <?php elseif (empty($split['batched'])): ?>
            <div class="vp-empty"><div class="vp-empty-icon">&#9888;</div><h3>No issues</h3><p>Flagged orders will appear here.</p></div>
            <?php endif; ?>
        </div>

        <div id="fpPaginationNav" class="vp-pagination"></div>
    </div>
</div>

<!-- Slide-out Panel -->
<div class="vp-panel-overlay" id="panelOverlay"></div>
<aside class="vp-panel" id="orderPanel">
    <div class="vp-panel-header">
        <div class="vp-panel-ref" id="panelRef"></div>
        <button class="vp-panel-close" id="panelClose">&times;</button>
    </div>
    <div class="vp-panel-body" id="panelBody"></div>
    <div class="vp-panel-footer" id="panelFooter"></div>
</aside>

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

<!-- Batch Pricing Modal -->
<div class="vp-overlay" id="batchPriceModal" style="display:none;">
    <div class="vp-modal vp-modal-wide">
        <div class="vp-modal-head">
            <h3>Price Batch <span id="bpBatchId"></span></h3>
            <button class="vp-modal-x" onclick="closeBatchPriceModal()">&times;</button>
        </div>
        <div class="vp-modal-body">
            <div class="bp-top-row">
                <div class="bp-field">
                    <label>Batch Print Total</label>
                    <div class="bp-input-wrap"><span class="bp-prefix">$</span><input type="text" inputmode="decimal" id="bpBatchTotal" placeholder="0.00" oninput="bpAllocate()"></div>
                    <div class="bp-hint">Enter total and prices auto-distribute by print area, or price items individually below</div>
                </div>
                <div class="bp-tube-section">
                    <label>Packing Fee</label>
                    <div class="bp-tube-row">
                        <div class="bp-input-wrap bp-input-sm"><span class="bp-prefix">$</span><input type="text" inputmode="decimal" id="bpTubePrice" placeholder="0.00" oninput="bpRecalcTotal()"></div>
                        <span class="bp-tube-x">&times;</span>
                        <input type="number" id="bpTubeQty" min="0" value="0" class="bp-tube-qty" oninput="bpRecalcTotal()">
                        <span class="bp-tube-eq">=</span>
                        <span class="bp-tube-total" id="bpTubeTotal">$0.00</span>
                    </div>
                </div>
            </div>
            
            <div class="bp-items-header">Item Breakdown</div>
            <table class="bp-items-table">
                <thead><tr>
                    <th>Order</th>
                    <th>Size</th>
                    <th>Area</th>
                    <th>Material</th>
                    <th>Item Price</th>
                    <th style="width:30px;"></th>
                </tr></thead>
                <tbody id="bpItemsBody"></tbody>
            </table>
            
            <div class="bp-summary">
                <div class="bp-sum-line"><span>Print Subtotal</span><span id="bpPrintSub">$0.00</span></div>
                <div class="bp-sum-line"><span>Packing</span><span id="bpTubeSub">$0.00</span></div>
                <div class="bp-sum-line"><span>Tax (13%)</span><span id="bpTax">$0.00</span></div>
                <div class="bp-sum-line bp-sum-total"><span>Batch Total</span><span id="bpGrandTotal">$0.00</span></div>
            </div>
        </div>
        <div class="vp-modal-foot">
            <button class="vp-btn vp-btn-ghost" onclick="closeBatchPriceModal()">Cancel</button>
            <button class="vp-btn vp-btn-primary" onclick="submitBatchPrice()">Submit Batch Price</button>
        </div>
    </div>
</div>

<script>
// ============================================
// ORDER DATA (from PHP)
// ============================================
var orderData = <?= json_encode($panelData, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
var CAN_ACT = <?= $canAct ? 'true' : 'false' ?>;
var IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
var IS_GOD = <?= $isGodMode ? 'true' : 'false' ?>;
var VENDOR_HOURS = <?= json_encode($isAdmin ? $allVendorHours : [$vendorId => $currentVendorHours], JSON_HEX_TAG) ?>;
var DEFAULT_HOURS = <?= json_encode($defaultHours, JSON_HEX_TAG) ?>;
var activeTab = 'new';
var activeRef = null;

// ============================================
// SLIDE-OUT PANEL
// ============================================
var panel = document.getElementById('orderPanel');
var panelOverlay = document.getElementById('panelOverlay');

// SVG icons for timeline
var TL_ICONS = {
    received: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    priced: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
    confirmed: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    printing: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
    ready: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
    shipped: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'
};

/**
 * Timeline step state calculator
 * Green = done, Amber = pending/waiting, Blue = in progress, Grey = not reached
 */
function getStepState(key, d, vp) {
    var st = d.status_raw || '';
    var statusOrder = ['preflight','printing','ready','ready_to_ship','shipped','delivered','pickedup'];
    var statusIdx = statusOrder.indexOf(st);
    function pastStatus(s) { var si = statusOrder.indexOf(s); return si >= 0 && statusIdx >= si; }

    switch (key) {
        case 'received':
            return d.pushed_at ? 'green' : 'grey';
        case 'priced':
            if (vp.status === 'accepted') return 'green';
            if (vp.status === 'submitted') return 'amber';
            return 'grey';
        case 'confirmed':
            if (d.confirmed_at || pastStatus('printing')) return 'green';
            if (vp.status === 'accepted') return 'amber';
            return 'grey';
        case 'printing':
            if (d.ready_at || pastStatus('ready')) return 'green';
            if (d.confirmed_at || st === 'printing') return 'blue';
            return 'grey';
        case 'ready':
            if (d.ready_at || pastStatus('ready')) return 'green';
            return 'grey';
        case 'shipped':
            if (d.shipped_at || pastStatus('shipped')) return 'green';
            if (d.ready_at || pastStatus('ready')) return 'amber';
            return 'grey';
        default: return 'grey';
    }
}

function openPanel(ref) {
    var d = orderData[ref];
    if (!d) return;
    activeRef = ref;

    document.querySelectorAll('.vp-row').forEach(function(r) { r.classList.remove('vp-active'); });
    var row = document.querySelector('.vp-row[data-ref="'+ref+'"]');
    if (row) row.classList.add('vp-active');

    document.getElementById('panelRef').innerHTML = '<span class="vp-panel-ref-code">#' + d.ref + '</span><span class="vp-panel-status vp-ps-' + d.status_raw + '">' + d.status + '</span>';

    var tab = row ? row.dataset.tab : activeTab;
    var vp = d.vendor_pricing || {};
    var h = '';

    // ---- 1. DOWNLOAD ----
    h += '<div class="vp-ps-section">';
    h += '<a href="api.php?action=download&ref=' + encodeURIComponent(ref) + '" class="vp-ps-dl-btn">';
    h += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>';
    h += ' Download File</a>';
    h += '<div class="vp-ps-file-info">' + escHtml(d.file_name);
    if (d.file_size) h += ' &middot; ' + d.file_size;
    h += '</div></div>';

    // ---- 2. ORDER DETAILS ----
    h += '<div class="vp-ps-section">';
    h += '<div class="vp-ps-label">Order Details</div>';
    h += '<div class="vp-ps-spec-grid">';
    h += '<span class="vp-ps-spec-lbl">Size</span><span class="vp-ps-spec-val">' + d.width + '" &times; ' + d.height + '"</span>';
    var matCls = d.material.toLowerCase() === 'fabric' ? 'vp-mat-fabric' : 'vp-mat-paper';
    h += '<span class="vp-ps-spec-lbl">Material</span><span class="vp-ps-spec-val"><span class="vp-mat ' + matCls + '">' + d.material + '</span></span>';
    if (d.due_date) {
        var dueText = d.due_date;
        if (d.delivery_time && d.delivery_time !== 'anytime') dueText += ' &middot; by ' + d.delivery_time;
        h += '<span class="vp-ps-spec-lbl">Due</span><span class="vp-ps-spec-val">' + dueText + '</span>';
        if (d.due_ts) h += '<span class="vp-ps-spec-lbl"></span><span class="vp-ps-spec-val"><span class="vp-timer vp-ps-timer-block" data-due-ts="' + d.due_ts + '"></span></span>';
    }
    // Vendor Ref
    h += '<span class="vp-ps-spec-lbl vp-lbl-nowrap">Vendor Ref</span><span class="vp-ps-spec-val vp-vendorref-panel" data-ref="' + ref + '">';
    if (d.vendor_order_number) {
        h += '<span class="vp-vendorref-val">' + escHtml(d.vendor_order_number) + '</span>';
        h += ' <span class="vp-vendorref-edit" onclick="vpEditPanelRef(this)" title="Edit"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></span>';
        h += '<input type="text" class="vp-vendorref-input" value="' + escHtml(d.vendor_order_number) + '" data-ref="' + ref + '" style="display:none" onblur="vpSavePanelRef(this)">';
    } else {
        h += '<input type="text" class="vp-vendorref-input" value="" placeholder="Enter order #" data-ref="' + ref + '" onblur="vpSavePanelRef(this)">';
    }
    h += '</span>';
    h += '</div></div>';

    // ---- 3. PRICING ----
    h += '<div class="vp-ps-section">';
    h += '<div class="vp-ps-label">Pricing</div>';
    h += '<div class="vp-ps-pricing-block" id="panelPricingSection">';
    if (vp.status === 'accepted' || vp.status === 'submitted') {
        h += '<div class="vp-ps-price-grid vp-ps-price-grid-lg">';
        h += '<span class="vp-ps-price-lbl">Item Price</span><span>$' + (parseFloat(vp.base_price)||0).toFixed(2) + '</span>';
        h += '<span class="vp-ps-price-lbl">Packing Fee</span><span>$' + (parseFloat(vp.packing_price)||0).toFixed(2) + '</span>';
        if (vp.additional_fees && vp.additional_fees.length > 0) {
            vp.additional_fees.forEach(function(fee) {
                if (fee.label && fee.label.indexOf('allocated') !== -1) return;
                h += '<span class="vp-ps-price-lbl">' + escHtml(fee.label) + '</span><span>$' + (parseFloat(fee.amount)||0).toFixed(2) + '</span>';
            });
        }
        h += '<span class="vp-ps-price-lbl">Tax (13%)</span><span>$' + (parseFloat(vp.tax_amount)||0).toFixed(2) + '</span>';
        h += '<span class="vp-ps-price-lbl vp-ps-price-total">Total</span><span class="vp-ps-price-total">$' + (parseFloat(vp.total)||0).toFixed(2) + '</span>';
        h += '</div>';
        if (vp.status === 'submitted') {
            if (IS_GOD) {
                h += '<div class="vp-ps-price-actions">';
                h += '<button class="vp-btn vp-btn-approve" onclick="approvePrice(\'' + ref + '\')">&#10003; Approve</button>';
                h += '<button class="vp-btn vp-btn-issue" onclick="showRejectPriceModal(\'' + ref + '\')">&#10007; Reject</button>';
                h += '</div>';
            } else if (CAN_ACT && !IS_ADMIN) {
                h += '<span class="vp-price-badge vp-badge-pending">Under Review</span>';
                h += '<button class="vp-btn vp-btn-ghost vp-ps-edit-price" onclick="editPriceFromPanel(\'' + ref + '\')">Edit Price</button>';
            } else {
                h += '<span class="vp-price-badge vp-badge-pending">Under Review</span>';
            }
        } else {
            h += '<span class="vp-price-badge vp-badge-accepted"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:-2px"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Approved</span>';
            if (vp.reviewed_at) h += '<div class="vp-ps-price-meta">Approved ' + vp.reviewed_at + (vp.reviewed_by ? ' by ' + escHtml(vp.reviewed_by) : '') + '</div>';
        }
    } else if (vp.status === 'rejected') {
        h += '<div class="vp-ps-price-rejected">&#9888; Rejected: ' + escHtml(vp.rejection_reason || 'No reason given') + '</div>';
        if (CAN_ACT && !IS_ADMIN) h += '<button class="vp-btn vp-btn-primary vp-btn-sm" onclick="editPriceFromPanel(\'' + ref + '\')">Resubmit Price</button>';
    } else {
        if (CAN_ACT && !IS_ADMIN) {
            // Always-ready price input for vendor
            h += '<div class="vp-ps-price-quick" id="panelQuickPrice">';
            h += '<div class="vp-pf-row"><label class="vp-pf-label-lg">Item Price</label><div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" id="panelQPBase" placeholder="0.00" class="vp-pf-input-lg"></div></div>';
            h += '<div class="vp-pf-row"><label class="vp-pf-label-lg">Packing Fee</label><div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" id="panelQPPacking" placeholder="0.00" value="0.00" class="vp-pf-input-lg"></div></div>';
            h += '<div id="panelQPFees"></div>';
            h += '<button type="button" class="vp-pf-add-fee" onclick="addQPFeeRow()">+ Add Fee</button>';
            h += '<div class="vp-pf-row vp-pf-summary">';
            h += '<div class="vp-pf-line"><span>Subtotal</span><span id="panelQPSub">$0.00</span></div>';
            h += '<div class="vp-pf-line"><span>Tax (13%)</span><span id="panelQPTax">$0.00</span></div>';
            h += '<div class="vp-pf-line vp-pf-total"><span>Total</span><span id="panelQPTotal">$0.00</span></div>';
            h += '</div>';
            h += '<button class="vp-btn vp-btn-primary vp-btn-sm vp-btn-full" onclick="submitQuickPrice(\'' + ref + '\')">Submit Price</button>';
            h += '</div>';
        } else {
            h += '<p class="vp-muted">No price submitted yet.</p>';
        }
    }
    h += '</div></div>';

    // ---- 3b. PACKING ----
    h += '<div class="vp-ps-section">';
    h += '<div class="vp-ps-label">Packing</div>';
    h += '<div class="vp-ps-spec-grid">';
    h += '<span class="vp-ps-spec-lbl">Type</span><span class="vp-ps-spec-val">';
    if (CAN_ACT) {
        var packSelectId = IS_ADMIN ? 'panelPackSelect' : 'panelVendorPackSelect';
        var packHandler = IS_ADMIN ? 'updatePacking' : 'vpPanelPackChange';
        h += '<select class="vp-pack-select" id="' + packSelectId + '" onchange="' + packHandler + '(\'' + ref + '\', this)">';
        var packOpts = [['none','None / Flat'],['tube','Tube'],['box','Box'],['custom','Custom']];
        packOpts.forEach(function(o) {
            h += '<option value="' + o[0] + '"' + (d.packing === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
        });
        h += '</select>';
        if (IS_ADMIN) h += '<input type="text" class="vp-pack-custom" id="panelPackCustom" placeholder="Describe..." value="' + escHtml(d.packing_custom || '') + '"' + (d.packing !== 'custom' ? ' style="display:none"' : '') + '>';
    } else {
        h += escHtml(d.packing_label);
    }
    h += '</span>';
    h += '</div>';

    // Packing details (type-specific)
    var pd = d.packing_details || {};
    h += '<div class="vp-panel-packdet" id="vpPanelPackDet">';
    if (CAN_ACT && d.packing === 'tube') {
        h += '<div class="vp-packdet-row"><label>Qty</label><input type="number" id="vpPanelPDQty" min="1" value="' + (pd.qty || 1) + '" class="vp-packdet-input"></div>';
        h += '<button type="button" class="vp-packdet-save vp-packdet-save-sm" onclick="vpSavePanelPackDet(\'' + ref + '\')">Save</button>';
    } else if (CAN_ACT && d.packing === 'box') {
        var boxes = pd.boxes || [];
        if (boxes.length === 0) {
            if (pd.dimensions || pd.weight) {
                var oldDims = (pd.dimensions || '').split('x');
                boxes = [{ l: oldDims[0]||'', w: oldDims[1]||'', h: oldDims[2]||'', weight: pd.weight||'' }];
            } else {
                boxes = [{ l:'', w:'', h:'', weight:'' }];
            }
        }
        var hasData = boxes.some(function(b) { return b.l || b.w || b.h || b.weight; });
        if (hasData) {
            h += vpRenderBoxDisplay(boxes, ref);
        } else {
            boxes.forEach(function(box, i) { h += vpRenderBoxRow(i, box); });
            h += '<button type="button" class="vp-packdet-save vp-packdet-save-sm" onclick="vpSavePanelPackDet(\'' + ref + '\')">Save</button>';
            h += '<button type="button" class="vp-packdet-addbox vp-packdet-addbox-full" onclick="vpAddPanelBox()">+ Add Box</button>';
        }
    } else if (CAN_ACT && d.packing === 'custom') {
        h += '<textarea class="vp-pack-custom-text" id="vpPanelPackCustom" placeholder="Describe packing requirements..." onblur="vpSavePackCustom(\'' + ref + '\')">' + escHtml(d.packing_custom || '') + '</textarea>';
    }
    // none/flat: no inputs
    h += '</div>';
    h += '</div>';

    // ---- 4. PRINT INSTRUCTIONS ----
    h += '<div class="vp-ps-section">';
    h += '<div class="vp-ps-label">Print Instructions</div>';
    if (IS_ADMIN) {
        h += '<textarea class="vp-ps-print-notes-edit" id="panelPrintNotes" placeholder="Add print specs: DPI, color profile, bleed, special instructions..." onblur="savePrintNotes(\'' + ref + '\')">' + escHtml(d.print_notes || '') + '</textarea>';
    } else if (d.print_notes) {
        h += '<div class="vp-ps-print-notes">' + escHtml(d.print_notes) + '</div>';
    } else {
        h += '<p class="vp-muted">No print instructions provided.</p>';
    }
    h += '</div>';

    // ---- 5. BATCH ----
    if (d.batch) {
        h += '<div class="vp-ps-section">';
        h += '<div class="vp-ps-label">Batch</div>';
        h += '<div class="vp-ps-batch-info">';
        h += '<span class="vp-ps-batch-id">' + escHtml(d.batch.batch_id) + '</span>';
        if (d.batch.label) h += '<span class="vp-ps-batch-label">' + escHtml(d.batch.label) + '</span>';
        h += '</div></div>';
    }

    // ---- 6. TIMELINE ----
    h += '<div class="vp-ps-section">';
    h += '<div class="vp-ps-label">Order Timeline</div>';
    h += '<div class="vp-ps-timeline-v2">';
    var steps = [
        { key: 'received', label: 'Received', time: d.pushed_at, icon: TL_ICONS.received },
        { key: 'priced', label: 'Priced', time: vp.submitted_at || null, icon: TL_ICONS.priced },
        { key: 'confirmed', label: 'Confirmed', time: d.confirmed_at, icon: TL_ICONS.confirmed },
        { key: 'printing', label: 'Printing', time: d.confirmed_at ? d.confirmed_at : null, icon: TL_ICONS.printing },
        { key: 'ready', label: 'Ready', time: d.ready_at, icon: TL_ICONS.ready },
        { key: 'shipped', label: 'Shipped', time: d.shipped_at || null, icon: TL_ICONS.shipped }
    ];
    var stepStates = steps.map(function(s) { return getStepState(s.key, d, vp); });
    steps.forEach(function(s, i) {
        var state = stepStates[i];
        h += '<div class="vp-tl2-step vp-tl2-' + state + '">';
        h += '<div class="vp-tl2-icon">' + s.icon + '</div>';
        h += '<div class="vp-tl2-content">';
        h += '<div class="vp-tl2-label">' + s.label + '</div>';
        var timeText = 'Pending';
        if (state === 'green') {
            if (s.key === 'ready') timeText = 'Packed and ready';
            else if (s.key === 'printing') timeText = 'Completed';
            else timeText = s.time || 'Completed';
        }
        else if (state === 'amber') timeText = 'Waiting';
        else if (state === 'blue') timeText = 'In Progress';
        h += '<div class="vp-tl2-time">' + timeText + '</div>';
        h += '</div></div>';
        if (i < steps.length - 1) {
            var lineClass = 'vp-tl2-line';
            if (state === 'green' && stepStates[i+1] !== 'grey') lineClass += ' vp-tl2-line-green';
            else if (state === 'blue' || state === 'amber') lineClass += ' vp-tl2-line-amber';
            h += '<div class="' + lineClass + '"></div>';
        }
    });
    h += '</div></div>';

    // ---- 7. NOTES ----
    h += '<div class="vp-ps-section vp-ps-notes-section">';
    h += '<div class="vp-ps-label">Notes</div>';
    var hasContent = false;
    if (d.customer_notes) {
        h += '<div class="vp-note vp-note-customer"><span class="vp-note-from">Customer</span>' + escHtml(d.customer_notes) + '</div>';
        hasContent = true;
    }
    if (d.vendor_notes && d.vendor_notes.length > 0) {
        d.vendor_notes.forEach(function(n, i) {
            h += '<div class="vp-note vp-note-vendor"><span class="vp-note-from">' + escHtml(n.by) + '</span>' + escHtml(n.text);
            if (n.time) h += '<span class="vp-note-time">' + n.time + '</span>';
            if (CAN_ACT) h += '<button class="vp-note-del" onclick="event.stopPropagation();deleteNote(\'' + ref + '\',' + i + ')">&times;</button>';
            h += '</div>';
            hasContent = true;
        });
    }
    if (d.issue_reason) {
        h += '<div class="vp-note vp-note-issue"><span class="vp-note-from">Issue</span>' + escHtml(d.issue_reason) + '</div>';
        hasContent = true;
    }
    if (!hasContent) h += '<p class="vp-muted">No notes yet.</p>';
    if (CAN_ACT) h += '<button class="vp-btn vp-btn-ghost vp-ps-add-note" onclick="showNoteModal(\'' + ref + '\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg> Add Note</button>';
    h += '</div>';

    // ---- 8. VENDOR PAYMENT (admin only) ----
    if (IS_ADMIN) {
        h += '<div class="vp-ps-section">';
        h += '<div class="vp-ps-label">Vendor Payment</div>';
        if (d.vendor_paid) {
            h += '<div class="vp-ps-paid-status vp-ps-paid-yes"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Paid</div>';
            if (d.vendor_paid_at) h += '<div class="vp-ps-price-meta">' + d.vendor_paid_at + (d.vendor_paid_by ? ' by ' + escHtml(d.vendor_paid_by) : '') + '</div>';
            h += '<button class="vp-btn vp-btn-ghost vp-btn-sm" onclick="togglePaid(\'' + ref + '\', false)">Undo</button>';
        } else {
            h += '<div class="vp-ps-paid-status vp-ps-paid-no">Unpaid</div>';
            h += '<button class="vp-btn vp-btn-primary vp-btn-sm" onclick="togglePaid(\'' + ref + '\', true)">Mark as Paid</button>';
        }
        h += '</div>';
    }

    document.getElementById('panelBody').innerHTML = h;

    // Footer actions (respect pricing workflow)
    var f = '';
    if (CAN_ACT) {
        if (tab === 'new') {
            if (vp.status === 'accepted') {
                f += '<button class="vp-btn vp-btn-approve vp-ps-action" onclick="confirmOrder(\'' + ref + '\')">&#10003; Confirm Job</button>';
            } else {
                f += '<button class="vp-btn vp-btn-approve vp-ps-action vp-btn-disabled" disabled>&#10003; Confirm Job</button>';
            }
            f += '<button class="vp-btn vp-btn-issue vp-ps-action" onclick="showIssueModal(\'' + ref + '\')">&#9888; Report Issue</button>';
        } else if (tab === 'printing') {
            f += '<button class="vp-btn vp-btn-ready vp-ps-action" onclick="markReady(\'' + ref + '\')">&#10003; Ready for Pickup</button>';
        } else if (tab === 'ready') {
            f += '<button class="vp-btn vp-btn-reprint vp-ps-action" onclick="revertToPrinting(\'' + ref + '\')">&#8617; Reprint</button>';
            f += '<button class="vp-btn vp-btn-issue vp-ps-action" onclick="showIssueModal(\'' + ref + '\')">&#9888; Report Issue</button>';
        } else if (tab === 'completed') {
            f += '<button class="vp-btn vp-btn-reprint vp-ps-action" onclick="revertToPrinting(\'' + ref + '\')">&#8617; Reprint</button>';
        } else if (tab === 'issues') {
            f += '<button class="vp-btn vp-btn-approve vp-ps-action" onclick="confirmOrder(\'' + ref + '\')">&#10003; Re-confirm</button>';
        }
    }
    document.getElementById('panelFooter').innerHTML = f;

    panel.classList.add('open');
    panelOverlay.classList.add('open');
    document.body.classList.add('panel-open');
    updateTimers();
}

function closePanel() {
    panel.classList.remove('open');
    panelOverlay.classList.remove('open');
    document.body.classList.remove('panel-open');
    document.querySelectorAll('.vp-row').forEach(function(r) { r.classList.remove('vp-active'); });
    activeRef = null;
}

function escHtml(s) { if (!s) return ''; var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// ============================================
// GLOBAL AUTO-DECIMAL FORMATTING
// ============================================
function autoDecimal(el) {
    el.addEventListener('blur', function() {
        var v = this.value.replace(/[^0-9.]/g, '');
        if (v === '') return;
        var num = parseFloat(v);
        if (!isNaN(num)) this.value = num.toFixed(2);
    });
    el.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9.]/g, '');
        var parts = this.value.split('.');
        if (parts.length > 2) this.value = parts[0] + '.' + parts.slice(1).join('');
        // Limit decimal places to 2
        if (parts.length === 2 && parts[1].length > 2) this.value = parts[0] + '.' + parts[1].substring(0, 2);
    });
}

// ============================================
// INLINE PRICING FORM
// ============================================
var activePriceRef = null;
var activePriceEl = null;

function openPriceForm(ref, btn) {
    closePriceForm();
    activePriceRef = ref;
    activePriceEl = btn;
    
    var d = orderData[ref];
    var vp = d ? (d.vendor_pricing || {}) : {};
    var existingBase = vp.base_price ? parseFloat(vp.base_price).toFixed(2) : '';
    var existingPack = vp.packing_price ? parseFloat(vp.packing_price).toFixed(2) : '0.00';
    var existingFees = (vp.additional_fees || []).filter(function(f) { return !f.label || f.label.indexOf('allocated') === -1; });

    var form = document.createElement('div');
    form.className = 'vp-price-form';
    form.id = 'priceForm';
    form.onclick = function(e) { e.stopPropagation(); };
    form.innerHTML = '<div class="vp-pf-row">' +
        '<label>Item Price</label>' +
        '<div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" id="pfBase" placeholder="0.00" value="' + existingBase + '"></div>' +
        '</div>' +
        '<div class="vp-pf-row">' +
        '<label>Packing Fee</label>' +
        '<div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" id="pfPacking" placeholder="0.00" value="' + existingPack + '"></div>' +
        '</div>' +
        '<div id="pfFeesList"></div>' +
        '<button type="button" class="vp-pf-add-fee" onclick="addPriceFeeRow()">+ Add Fee</button>' +
        '<div class="vp-pf-row vp-pf-summary">' +
        '<div class="vp-pf-line"><span>Subtotal</span><span id="pfSubtotal">$0.00</span></div>' +
        '<div class="vp-pf-line"><span>Tax (13%)</span><span id="pfTax">$0.00</span></div>' +
        '<div class="vp-pf-line vp-pf-total"><span>Total</span><span id="pfTotal">$0.00</span></div>' +
        '</div>' +
        '<div class="vp-pf-actions">' +
        '<button class="vp-btn vp-btn-ghost vp-pf-cancel" onclick="closePriceForm()">Cancel</button>' +
        '<button class="vp-btn vp-btn-primary vp-pf-submit" onclick="submitPrice()">Submit Price</button>' +
        '</div>';

    var td = btn.closest('td');
    td.appendChild(form);

    // Restore existing fees
    existingFees.forEach(function(fee) { addPriceFeeRow(fee.label, fee.amount); });

    var baseInput = document.getElementById('pfBase');
    var packInput = document.getElementById('pfPacking');
    pfRecalc();
    baseInput.addEventListener('input', pfRecalc);
    packInput.addEventListener('input', pfRecalc);
    autoDecimal(baseInput);
    autoDecimal(packInput);
    baseInput.focus();
}

var pfFeeCounter = 0;
function addPriceFeeRow(label, amount) {
    pfFeeCounter++;
    var id = 'pfFee' + pfFeeCounter;
    var list = document.getElementById('pfFeesList');
    if (!list) return;
    var row = document.createElement('div');
    row.className = 'vp-pf-row vp-pf-fee-row';
    row.id = id + 'Row';
    row.innerHTML = '<div class="vp-pf-fee-top"><input type="text" class="vp-pf-fee-label" placeholder="Fee description (e.g. Rush surcharge)" value="' + (label || '') + '"><button type="button" class="vp-pf-fee-remove" onclick="this.closest(\'.vp-pf-fee-row\').remove();pfRecalc()">&times;</button></div>' +
        '<div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" class="vp-pf-fee-input" placeholder="0.00" value="' + (amount ? parseFloat(amount).toFixed(2) : '') + '"></div>';
    list.appendChild(row);
    var amtInput = row.querySelector('.vp-pf-fee-input');
    amtInput.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9.]/g, '');
        pfRecalc();
    });
    amtInput.addEventListener('blur', function() { var v = parseFloat(this.value); if (!isNaN(v)) this.value = v.toFixed(2); });
    pfRecalc();
}

function pfRecalc() {
    var base = parseFloat((document.getElementById('pfBase') || {}).value) || 0;
    var pack = parseFloat((document.getElementById('pfPacking') || {}).value) || 0;
    var feeTotal = 0;
    document.querySelectorAll('.vp-pf-fee-input').forEach(function(el) { feeTotal += parseFloat(el.value) || 0; });
    var sub = base + pack + feeTotal;
    var tax = sub * 0.13;
    var total = sub + tax;
    var subEl = document.getElementById('pfSubtotal'); if (subEl) subEl.textContent = '$' + sub.toFixed(2);
    var taxEl = document.getElementById('pfTax'); if (taxEl) taxEl.textContent = '$' + tax.toFixed(2);
    var totEl = document.getElementById('pfTotal'); if (totEl) totEl.textContent = '$' + total.toFixed(2);
    // Panel variants
    var pSubEl = document.getElementById('panelPfSub'); if (pSubEl) pSubEl.textContent = '$' + sub.toFixed(2);
    var pTaxEl = document.getElementById('panelPfTax'); if (pTaxEl) pTaxEl.textContent = '$' + tax.toFixed(2);
    var pTotEl = document.getElementById('panelPfTotal'); if (pTotEl) pTotEl.textContent = '$' + total.toFixed(2);
}

function getPriceFees() {
    var fees = [];
    document.querySelectorAll('.vp-pf-fee-row').forEach(function(row) {
        var label = row.querySelector('.vp-pf-fee-label').value.trim();
        var amount = parseFloat(row.querySelector('.vp-pf-fee-input').value) || 0;
        if (label && amount > 0) fees.push({ label: label, amount: amount });
    });
    return fees;
}

function closePriceForm() {
    var f = document.getElementById('priceForm');
    if (f) f.remove();
    activePriceRef = null;
    activePriceEl = null;
}


var qpFeeCounter = 0;
function addQPFeeRow(label, amount) {
    qpFeeCounter++;
    var list = document.getElementById('panelQPFees');
    if (!list) return;
    var row = document.createElement('div');
    row.className = 'vp-pf-row vp-qp-fee-row';
    row.innerHTML = '<div class="vp-pf-fee-top"><input type="text" class="vp-qp-fee-label" placeholder="Fee description" value="' + (label || '') + '"><button type="button" class="vp-pf-fee-remove" onclick="this.closest(\'.vp-qp-fee-row\').remove();qpRecalc()">&times;</button></div>' +
        '<div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" class="vp-qp-fee-amt vp-pf-input-lg" placeholder="0.00" value="' + (amount ? parseFloat(amount).toFixed(2) : '') + '"></div>';
    list.appendChild(row);
    var amtInput = row.querySelector('.vp-qp-fee-amt');
    amtInput.addEventListener('input', function() { this.value = this.value.replace(/[^0-9.]/g, ''); qpRecalc(); });
    amtInput.addEventListener('blur', function() { var v = parseFloat(this.value); if (!isNaN(v)) this.value = v.toFixed(2); });
    qpRecalc();
}
function qpRecalc() {
    var base = parseFloat((document.getElementById('panelQPBase') || {}).value) || 0;
    var pack = parseFloat((document.getElementById('panelQPPacking') || {}).value) || 0;
    var feeTotal = 0;
    document.querySelectorAll('#panelQPFees .vp-qp-fee-amt').forEach(function(el) { feeTotal += parseFloat(el.value) || 0; });
    var sub = base + pack + feeTotal;
    var tax = sub * 0.13;
    var total = sub + tax;
    var s = document.getElementById('panelQPSub'); if (s) s.textContent = '$' + sub.toFixed(2);
    var t = document.getElementById('panelQPTax'); if (t) t.textContent = '$' + tax.toFixed(2);
    var tt = document.getElementById('panelQPTotal'); if (tt) tt.textContent = '$' + total.toFixed(2);
}

function submitQuickPrice(ref) {
    var baseEl = document.getElementById('panelQPBase');
    var packEl = document.getElementById('panelQPPacking');
    if (!baseEl || !baseEl.value || parseFloat(baseEl.value) <= 0) {
        showToast('Please enter an item price', 'error');
        return;
    }
    var fees = [];
    document.querySelectorAll('#panelQPFees .vp-qp-fee-row').forEach(function(row) {
        var label = row.querySelector('.vp-qp-fee-label').value.trim();
        var amt = parseFloat(row.querySelector('.vp-qp-fee-amt').value) || 0;
        if (label && amt > 0) fees.push({ label: label, amount: amt });
    });
    apiCall('submit_price', {
        reference_code: ref,
        base_price: parseFloat(baseEl.value) || 0,
        packing_price: parseFloat(packEl.value) || 0,
        additional_fees: fees
    }).then(function(r) {
        if (r.success) {
            showToast('Price submitted', 'success');
            setTimeout(function() { openPanel(ref); }, 500);
        }
    });
}

// Auto-calc for quick price
document.addEventListener('input', function(e) {
    if (e.target.id === 'panelQPBase' || e.target.id === 'panelQPPacking' || e.target.closest('#panelQPFees')) {
        var base = parseFloat(document.getElementById('panelQPBase').value) || 0;
        var pack = parseFloat(document.getElementById('panelQPPacking').value) || 0;
        var feeTotal = 0;
        document.querySelectorAll('#panelQPFees .vp-qp-fee-amt').forEach(function(el) { feeTotal += parseFloat(el.value) || 0; });
        var sub = base + pack + feeTotal;
        var tax = sub * 0.13;
        var total = sub + tax;
        var subEl = document.getElementById('panelQPSub');
        var taxEl = document.getElementById('panelQPTax');
        var totalEl = document.getElementById('panelQPTotal');
        if (subEl) subEl.textContent = '$' + sub.toFixed(2);
        if (taxEl) taxEl.textContent = '$' + tax.toFixed(2);
        if (totalEl) totalEl.textContent = '$' + total.toFixed(2);
    }
});

function editPriceFromPanel(ref) {
    var d = orderData[ref];
    var vp = d ? (d.vendor_pricing || {}) : {};
    var existingFees = (vp.additional_fees || []).filter(function(f) { return !f.label || f.label.indexOf('allocated') === -1; });
    var container = document.getElementById('panelPricingSection');
    if (!container) return;

    container.innerHTML = '<div class="vp-ps-price-edit-form">' +
        '<div class="vp-pf-row"><label>Item Price</label><div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" id="panelPfBase" placeholder="0.00" value="' + (vp.base_price ? parseFloat(vp.base_price).toFixed(2) : '') + '"></div></div>' +
        '<div class="vp-pf-row"><label>Packing Fee</label><div class="vp-pf-input-wrap"><span class="vp-pf-prefix">$</span><input type="text" inputmode="decimal" id="panelPfPacking" placeholder="0.00" value="' + (vp.packing_price ? parseFloat(vp.packing_price).toFixed(2) : '0.00') + '"></div></div>' +
        '<div id="pfFeesList"></div>' +
        '<button type="button" class="vp-pf-add-fee" onclick="addPriceFeeRow()">+ Add Fee</button>' +
        '<div class="vp-pf-row vp-pf-summary">' +
        '<div class="vp-pf-line"><span>Subtotal</span><span id="panelPfSub">$0.00</span></div>' +
        '<div class="vp-pf-line"><span>Tax (13%)</span><span id="panelPfTax">$0.00</span></div>' +
        '<div class="vp-pf-line vp-pf-total"><span>Total</span><span id="panelPfTotal">$0.00</span></div>' +
        '</div>' +
        '<div class="vp-pf-actions">' +
        '<button class="vp-btn vp-btn-ghost" onclick="openPanel(\'' + ref + '\')">Cancel</button>' +
        '<button class="vp-btn vp-btn-primary" onclick="submitPanelPrice(\'' + ref + '\')">Update Price</button>' +
        '</div>' +
        '</div>';

    existingFees.forEach(function(fee) { addPriceFeeRow(fee.label, fee.amount); });

    var baseEl = document.getElementById('panelPfBase');
    var packEl = document.getElementById('panelPfPacking');
    baseEl.addEventListener('input', pfRecalc);
    packEl.addEventListener('input', pfRecalc);
    function autoDecFn(el) {
        el.addEventListener('blur', function() { var v = parseFloat(this.value); if (!isNaN(v)) this.value = v.toFixed(2); });
        el.addEventListener('input', function() { this.value = this.value.replace(/[^0-9.]/g, ''); var p = this.value.split('.'); if (p.length > 2) this.value = p[0] + '.' + p.slice(1).join(''); });
    }
    autoDecFn(baseEl);
    autoDecFn(packEl);
    pfRecalc();
    baseEl.focus();
}

function submitPanelPrice(ref) {
    var base = parseFloat(document.getElementById('panelPfBase').value) || 0;
    var pack = parseFloat(document.getElementById('panelPfPacking').value) || 0;
    if (base <= 0) { showToast('Enter a base price', 'error'); return; }
    var fees = getPriceFees();
    var feeTotal = fees.reduce(function(sum, f) { return sum + f.amount; }, 0);
    var sub = base + pack + feeTotal, tax = sub * 0.13, total = sub + tax;
    apiCall('submit_price', {
        reference_code: ref,
        base_price: base,
        packing_price: pack,
        additional_fees: fees,
        tax_rate: 0.13,
        tax_amount: Math.round(tax * 100) / 100,
        total: Math.round(total * 100) / 100
    }).then(function(r) {
        if (r.success) {
            showToast('Price updated', 'success');
            setTimeout(function() { location.reload(); }, 1200);
        }
    });
}

function submitPrice() {
    var base = parseFloat(document.getElementById('pfBase').value) || 0;
    var pack = parseFloat(document.getElementById('pfPacking').value) || 0;
    if (base <= 0) { showToast('Enter a base price', 'error'); return; }
    var fees = getPriceFees();
    var feeTotal = fees.reduce(function(sum, f) { return sum + f.amount; }, 0);
    var sub = base + pack + feeTotal;
    var tax = sub * 0.13;
    var total = sub + tax;
    
    apiCall('submit_price', {
        reference_code: activePriceRef,
        base_price: base,
        packing_price: pack,
        additional_fees: fees,
        tax_rate: 0.13,
        tax_amount: Math.round(tax * 100) / 100,
        total: Math.round(total * 100) / 100
    }).then(function(r) {
        if (r.success) {
            closePriceForm();
            showToast('Price submitted for review', 'success');
            setTimeout(function() { location.reload(); }, 1200);
        }
    });
}

document.getElementById('panelClose').addEventListener('click', closePanel);
panelOverlay.addEventListener('click', closePanel);
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePanel(); });

// ============================================
// ADMIN PRICE REVIEW
// ============================================
function approvePrice(ref) {
    if (!confirm('Approve vendor price for #' + ref + '?')) return;
    apiCall('accept_price', { reference_code: ref }).then(function(r) {
        if (r.success) {
            showToast('Price approved', 'success');
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
}

function togglePaid(ref, paid) {
    var msg = paid ? 'Mark #' + ref + ' as paid?' : 'Mark #' + ref + ' as unpaid?';
    if (!confirm(msg)) return;
    apiCall('toggle_paid', { reference_code: ref, paid: paid }).then(function(r) {
        if (r.success) {
            showToast(paid ? 'Marked as paid' : 'Marked as unpaid', 'success');
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
}

var rejectPriceRef = null;
function showRejectPriceModal(ref) {
    rejectPriceRef = ref;
    var overlay = document.createElement('div');
    overlay.className = 'vp-overlay';
    overlay.id = 'rejectPriceOverlay';
    overlay.innerHTML = '<div class="vp-modal">' +
        '<div class="vp-modal-head"><h3>Reject Price for #' + ref + '</h3><button class="vp-modal-x" onclick="closeRejectPriceModal()">&times;</button></div>' +
        '<div class="vp-modal-body">' +
        '<p>Provide a reason so the vendor can resubmit:</p>' +
        '<textarea id="rejectPriceReason" rows="3" placeholder="e.g. Price too high, resubmit at $X or below"></textarea>' +
        '</div>' +
        '<div class="vp-modal-foot">' +
        '<button class="vp-btn vp-btn-ghost" onclick="closeRejectPriceModal()">Cancel</button>' +
        '<button class="vp-btn vp-btn-issue" onclick="submitRejectPrice()">&#10007; Reject Price</button>' +
        '</div></div>';
    document.body.appendChild(overlay);
    setTimeout(function() { document.getElementById('rejectPriceReason').focus(); }, 50);
}

function closeRejectPriceModal() {
    var o = document.getElementById('rejectPriceOverlay');
    if (o) o.remove();
    rejectPriceRef = null;
}

function submitRejectPrice() {
    var reason = (document.getElementById('rejectPriceReason').value || '').trim();
    if (!reason) { showToast('Please provide a reason', 'error'); return; }
    apiCall('reject_price', { reference_code: rejectPriceRef, reason: reason }).then(function(r) {
        if (r.success) {
            closeRejectPriceModal();
            showToast('Price rejected — vendor will be notified', 'success');
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
}

// ============================================
// ADMIN PACKING UPDATE
// ============================================
function updatePacking(ref, selectEl) {
    var packing = selectEl.value;
    var customInput = document.getElementById('panelPackCustom');
    if (customInput) customInput.style.display = packing === 'custom' ? 'block' : 'none';
    
    // Debounce: for custom, wait for blur on text input
    if (packing === 'custom') {
        customInput.focus();
        customInput.onblur = function() { savePacking(ref); };
        return;
    }
    savePacking(ref);
}

function savePacking(ref) {
    var packing = document.getElementById('panelPackSelect').value;
    var packingCustom = (document.getElementById('panelPackCustom').value || '').trim();
    apiCall('update_packing', { reference_code: ref, packing: packing, packing_custom: packingCustom }).then(function(r) {
        if (r.success) {
            showToast(r.message, 'success');
            // Update row packing cell
            var row = document.querySelector('.vp-row[data-ref="' + ref + '"]');
            if (row) {
                row.dataset.packing = packing;
                var packTd = row.querySelector('.vp-col-pack');
                if (packTd) {
                    var icons = {'tube':'&#9645;','box':'&#128230;','flat':'&#128196;','none':'&mdash;','custom':'&#9881;'};
                    var labels = {'tube':'Tube','box':'Box','flat':'Flat','none':'None'};
                    var label = packing === 'custom' && packingCustom ? packingCustom : (labels[packing] || packing);
                    packTd.innerHTML = (icons[packing] || '&mdash;') + ' ' + label;
                }
            }
            // Update orderData
            if (orderData[ref]) {
                orderData[ref].packing = packing;
                orderData[ref].packing_custom = packingCustom;
                var labels2 = {'tube':'Tube','box':'Box','flat':'Flat','none':'None'};
                orderData[ref].packing_label = packing === 'custom' && packingCustom ? packingCustom : (labels2[packing] || packing);
            }
        }
    });
}

function savePrintNotes(ref) {
    var notes = document.getElementById('panelPrintNotes');
    if (!notes) return;
    var text = notes.value.trim();
    apiCall('update_print_notes', { reference_code: ref, print_notes: text }).then(function(r) {
        if (r.success) {
            showToast('Print instructions saved', 'success');
            if (orderData[ref]) orderData[ref].print_notes = text;
        }
    });
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
        closePanel();
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

function fpGetVisibleRows(tbody) {
    return Array.from(tbody.children).filter(function(r) { return r.classList.contains('vp-row'); });
}

function fpApplyFilters() {
    var table = document.querySelector('#tab-' + activeTab + ' table');
    if (!table) { document.getElementById('fpShowRange').textContent = '0'; document.getElementById('fpTotalCount').textContent = '0'; document.getElementById('fpPaginationNav').innerHTML = ''; return; }
    var tbody = table.querySelector('tbody');
    var allRows = fpGetVisibleRows(tbody);
    var searchLower = fpFilter.search.toLowerCase();
    var filtered = allRows.filter(function(r) {
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
    allRows.forEach(function(r) { r.style.display = 'none'; });
    for (var i = startIdx; i < endIdx; i++) { filtered[i].style.display = ''; }
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
        var rows = fpGetVisibleRows(tbody);
        rows.sort(function(a, b) {
            var av = a.dataset[col] || '', bv = b.dataset[col] || '';
            if (['size','due','received','tier','price'].indexOf(col) !== -1) return dir === 'asc' ? (parseFloat(av)||0)-(parseFloat(bv)||0) : (parseFloat(bv)||0)-(parseFloat(av)||0);
            var c = String(av).localeCompare(String(bv), undefined, {sensitivity:'base'}); return dir === 'asc' ? c : -c;
        });
        rows.forEach(function(r) { tbody.appendChild(r); });
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
        var boxes = table.querySelectorAll('tbody tr:not([style*="display: none"]) .order-checkbox');
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
    var vis = table.querySelectorAll('tbody tr:not([style*="display: none"]) .order-checkbox');
    var chk = table.querySelectorAll('tbody tr:not([style*="display: none"]) .order-checkbox:checked');
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
        if (activeTab==='new') h = '<button class="vp-bulk-btn vp-bulk-approve" onclick="fpBulkConfirm()">Confirm All (' + count + ')</button>' + h;
        else if (activeTab==='printing') h = '<button class="vp-bulk-btn vp-bulk-ready" onclick="fpBulkMarkReady()">Ready for Pickup (' + count + ')</button>' + h;
        else if (activeTab==='ready') h = '<button class="vp-bulk-btn vp-bulk-dl" onclick="fpBulkRevertToPrinting()">Back to Printing (' + count + ')</button>' + h;
    }
    document.getElementById('fpBulkActions').innerHTML = h;
}

function fpShowProgress(t) { document.getElementById('fpBulkProgress').style.display='flex'; document.getElementById('fpBulkProgressBar').style.width='0%'; document.getElementById('fpBulkProgressText').textContent='Processing 0 / '+t+'...'; }
function fpUpdateProgress(d,t) { document.getElementById('fpBulkProgressBar').style.width=Math.round((d/t)*100)+'%'; document.getElementById('fpBulkProgressText').textContent='Processing '+d+' / '+t+'...'; }
function fpHideProgress() { document.getElementById('fpBulkProgress').style.display='none'; }

async function fpBulkConfirm() { var refs=fpGetSelectedRefs(); if(!refs.length||!confirm('Confirm '+refs.length+' order(s)?')) return; fpShowProgress(refs.length); var ok=0,fail=0; for(var i=0;i<refs.length;i++){try{var r=await apiAsync('approve',{reference_code:refs[i]});if(r.success)ok++;else fail++;}catch(e){fail++;}fpUpdateProgress(i+1,refs.length);}fpHideProgress();showToast(fail===0?'Confirmed '+ok+' order(s)':'Confirmed '+ok+', failed '+fail,fail===0?'success':'error');fpClearSelection();setTimeout(function(){location.reload();},1500);}
async function fpBulkMarkReady() { var refs=fpGetSelectedRefs(); if(!refs.length||!confirm('Mark '+refs.length+' as ready?')) return; fpShowProgress(refs.length); var ok=0,fail=0; for(var i=0;i<refs.length;i++){try{var r=await apiAsync('mark_ready',{reference_code:refs[i]});if(r.success)ok++;else fail++;}catch(e){fail++;}fpUpdateProgress(i+1,refs.length);}fpHideProgress();showToast(fail===0?'Marked '+ok+' as ready':'Completed '+ok+', failed '+fail,fail===0?'success':'error');fpClearSelection();setTimeout(function(){location.reload();},1500);}
async function fpBulkRevertToPrinting() { var refs=fpGetSelectedRefs(); if(!refs.length||!confirm('Move '+refs.length+' back to Printing?')) return; fpShowProgress(refs.length); var ok=0,fail=0; for(var i=0;i<refs.length;i++){try{var r=await apiAsync('revert_to_printing',{reference_code:refs[i]});if(r.success)ok++;else fail++;}catch(e){fail++;}fpUpdateProgress(i+1,refs.length);}fpHideProgress();showToast(fail===0?'Moved '+ok+' back':'Moved '+ok+', failed '+fail,fail===0?'success':'error');fpClearSelection();setTimeout(function(){location.reload();},1500);}
function fpBulkDownload() { var refs=fpGetSelectedRefs(); if(!refs.length)return; if(refs.length===1){window.location.href='api.php?action=download&ref='+encodeURIComponent(refs[0]);return;} showToast('Preparing ZIP...','info'); window.location.href='api.php?action=bulk_download&refs='+encodeURIComponent(refs.join(',')); }

// ============================================
// INDIVIDUAL ACTIONS
// ============================================
function confirmOrder(ref) { if(!confirm('Confirm order #'+ref+'?')) return; apiCall('approve',{reference_code:ref}).then(function(r){if(r.success){showToast('#'+ref+' confirmed','success');setTimeout(function(){location.reload();},1200);}}); }
function markReady(ref) { if(!confirm('Mark #'+ref+' as ready for pickup?')) return; apiCall('mark_ready',{reference_code:ref}).then(function(r){if(r.success){showToast('#'+ref+' ready for pickup','success');setTimeout(function(){location.reload();},1200);}}); }
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
    return parts.join(' ') + (sec >= 0 ? ' left' : ' ago');
}
function updateTimers() {
    var now = Math.floor(Date.now()/1000);
    document.querySelectorAll('.vp-timer').forEach(function(el) {
        var ts = parseInt(el.getAttribute('data-due-ts'),10);
        if (!ts) return;

        // Determine vendor for this timer
        var vid = '';
        var row = el.closest('.vp-row');
        if (row) vid = row.dataset.vendorId || '';
        if (!vid) {
            // Panel timer — use active order's vendor
            var ref = activeRef;
            if (ref && orderData[ref]) vid = orderData[ref].vendor_id || '';
        }

        var bizSec = getBusinessSecondsRemaining(now, ts, vid);
        el.textContent = formatCountdown(bizSec);
        el.classList.remove('vp-timer-ok','vp-timer-warn','vp-timer-crit');

        var dueCell = el.closest('.vp-col-due');
        if (dueCell) dueCell.classList.remove('vp-due-critical','vp-due-urgent');

        if (bizSec <= 0 || bizSec <= 14400) { // ≤4 biz hours = critical
            el.classList.add('vp-timer-crit');
            if (dueCell) dueCell.classList.add('vp-due-critical');
        } else if (bizSec <= 43200) { // ≤12 biz hours = urgent
            el.classList.add('vp-timer-warn');
            if (dueCell) dueCell.classList.add('vp-due-urgent');
        } else {
            el.classList.add('vp-timer-ok');
        }
    });
}

/**
 * Calculate remaining business seconds between now and deadline.
 * Walks day-by-day, only counting hours within vendor's business hours.
 */
function getBusinessSecondsRemaining(nowTs, deadlineTs, vendorId) {
    if (deadlineTs <= nowTs) return deadlineTs - nowTs; // overdue

    var hours = VENDOR_HOURS[vendorId] || DEFAULT_HOURS;
    var dayNames = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
    var remaining = 0;
    var cursor = nowTs;
    var safety = 0;

    while (cursor < deadlineTs && safety < 400) {
        safety++;
        var d = new Date(cursor * 1000);
        var dayKey = dayNames[d.getDay()];
        var dh = hours[dayKey];

        if (!dh || dh.closed || !dh.open || !dh.close) {
            // Closed day — skip to next day midnight
            cursor = getNextDayMidnight(d);
            continue;
        }

        var openParts = dh.open.split(':');
        var closeParts = dh.close.split(':');
        var openTs = new Date(d.getFullYear(), d.getMonth(), d.getDate(), parseInt(openParts[0]), parseInt(openParts[1] || 0)).getTime() / 1000;
        var closeTs = new Date(d.getFullYear(), d.getMonth(), d.getDate(), parseInt(closeParts[0]), parseInt(closeParts[1] || 0)).getTime() / 1000;

        // Before business hours — advance to open
        if (cursor < openTs) cursor = openTs;

        // Past business hours — skip to next day
        if (cursor >= closeTs) {
            cursor = getNextDayMidnight(d);
            continue;
        }

        // Count from cursor to min(close, deadline)
        var end = Math.min(closeTs, deadlineTs);
        remaining += (end - cursor);

        if (deadlineTs <= closeTs) break;
        cursor = getNextDayMidnight(d);
    }

    return remaining;
}

function getNextDayMidnight(d) {
    return new Date(d.getFullYear(), d.getMonth(), d.getDate() + 1, 0, 0, 0).getTime() / 1000;
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
// BATCH GROUPS (collapsible)
// ============================================
// ---- Packing dropdown ----







// ---- Vendor Ref inline save ----
function vpEditPanelRef(pencilEl) {
    var panel = pencilEl.closest('.vp-vendorref-panel');
    var val = panel.querySelector('.vp-vendorref-val');
    var edit = panel.querySelector('.vp-vendorref-edit');
    var input = panel.querySelector('.vp-vendorref-input');
    if (val) val.style.display = 'none';
    if (edit) edit.style.display = 'none';
    if (input) { input.style.display = ''; input.focus(); input.select(); }
}
function vpSavePanelRef(input) {
    var ref = input.dataset.ref;
    var val = input.value.trim();
    apiCall('update_vendor_ref', { reference_code: ref, vendor_order_number: val }).then(function(r) {
        if (r.success && typeof orderData !== 'undefined' && orderData[ref]) {
            orderData[ref].vendor_order_number = val;
        }
    });
    // Update display
    var panel = input.closest('.vp-vendorref-panel');
    if (val) {
        var valEl = panel.querySelector('.vp-vendorref-val');
        var editEl = panel.querySelector('.vp-vendorref-edit');
        if (!valEl) {
            valEl = document.createElement('span');
            valEl.className = 'vp-vendorref-val';
            panel.insertBefore(valEl, input);
        }
        if (!editEl) {
            editEl = document.createElement('span');
            editEl.className = 'vp-vendorref-edit';
            editEl.setAttribute('onclick', 'vpEditPanelRef(this)');
            editEl.innerHTML = ' <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>';
            panel.insertBefore(editEl, input);
        }
        valEl.textContent = val;
        valEl.style.display = '';
        editEl.style.display = '';
        input.style.display = 'none';
    }
    // Also update the table cell
    var tableInput = document.querySelector('.vp-col-vendorref .vp-vendorref-input[data-ref="' + ref + '"]');
    if (tableInput && tableInput !== input) {
        tableInput.value = val;
        var td = tableInput.closest('td');
        var disp = td.querySelector('.vp-vendorref-display');
        if (val && disp) { disp.firstChild.textContent = val + ' '; disp.style.display = ''; tableInput.style.display = 'none'; }
    }
}

function editVendorRef(ref) {
    var td = event.target.closest('td');
    var display = td.querySelector('.vp-vendorref-display');
    var input = td.querySelector('.vp-vendorref-input');
    if (display) display.style.display = 'none';
    if (input) { input.style.display = ''; input.focus(); input.select(); }
}

async function saveVendorRef(input) {
    var ref = input.dataset.ref;
    var val = input.value.trim();
    try {
        var resp = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'update_vendor_ref', reference_code: ref, vendor_order_number: val })
        });
        var result = await resp.json();
        if (result.success && val) {
            var td = input.closest('td');
            var display = td.querySelector('.vp-vendorref-display');
            if (!display) {
                display = document.createElement('span');
                display.className = 'vp-vendorref-display';
                display.setAttribute('data-ref', ref);
                td.insertBefore(display, input);
            }
            display.innerHTML = val + ' <span class="vp-vendorref-edit" onclick="editVendorRef(\'' + ref + '\')" title="Edit"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></span>';
            display.style.display = '';
            input.style.display = 'none';
            if (typeof orderData !== 'undefined' && orderData[ref]) orderData[ref].vendor_order_number = val;
        }
    } catch(e) { console.error('Vendor ref error', e); }
}

// Packing dropdown (mirrors production pattern)
function vpTogglePack(e, ref) {
    e.stopPropagation();
    var dd = document.getElementById('vpPD_' + ref);
    if (!dd) return;
    var wasOpen = dd.classList.contains('show');
    vpCloseAllPacks();
    if (!wasOpen) {
        var rect = e.target.getBoundingClientRect();
        dd.style.top = (rect.bottom + 4) + 'px';
        dd.style.left = rect.left + 'px';
        dd.classList.add('show');
    }
}
function vpCloseAllPacks() {
    document.querySelectorAll('.vp-pack-dd.show').forEach(function(d) { d.classList.remove('show'); });
}
// Event delegation for pack item clicks
document.addEventListener('click', function(e) {
    var item = e.target.closest('.vp-pack-item');
    if (item) {
        e.stopPropagation();
        e.preventDefault();
        var ref = item.getAttribute('data-ref');
        var value = item.getAttribute('data-val');
        if (!ref || !value) return;
        vpCloseAllPacks();
        var wrap = document.querySelector('.vp-pack-wrap[data-ref="' + ref + '"]');
        if (wrap) {
            var badge = wrap.querySelector(':scope > .vp-pack-badge');
            var labels = {none:'None / Flat',tube:'Tube',box:'Box',custom:'Custom'};
            badge.textContent = labels[value] || value;
            badge.className = 'vp-pack-badge vp-pack-' + value;
        }
        apiCall('update_packing', { reference_code: ref, packing: value });
        if (typeof orderData !== 'undefined' && orderData[ref]) orderData[ref].packing = value;
        if (value === 'box') {
            setTimeout(function() {
                var qty = prompt('Number of boxes for ' + ref + ':', '1');
                if (qty) apiCall('update_packing_details', { reference_code: ref, packing_details: { qty: qty } });
            }, 200);
        }
        return;
    }
    // Close dropdowns on outside click
    if (!e.target.closest('.vp-pack-wrap')) vpCloseAllPacks();
});



// ---- Panel Packing (vendor) ----
function vpPanelPackChange(ref, selectEl) {
    var value = selectEl.value;
    apiCall('update_packing', { reference_code: ref, packing: value }).then(function(r) {
        if (r.success) {
            if (typeof orderData !== 'undefined' && orderData[ref]) {
                orderData[ref].packing = value;
                var labels = {none:'None / Flat',tube:'Tube',box:'Box',custom:'Custom'};
                orderData[ref].packing_label = labels[value] || value;
            }
            // Update table badge
            var wrap = document.querySelector('.vp-pack-wrap[data-ref="' + ref + '"]');
            if (wrap) {
                var badge = wrap.querySelector(':scope > .vp-pack-badge');
                var labels = {none:'None / Flat',tube:'Tube',box:'Box',custom:'Custom'};
                badge.textContent = labels[value] || value;
                badge.className = 'vp-pack-badge vp-pack-' + value;
            }
            // Refresh panel to show/hide details fields
            openPanel(ref);
        }
    });
}

function vpRenderBoxDisplay(boxes, ref) {
    var h = '<div class="vp-packdet-display">';
    boxes.forEach(function(box, i) {
        h += '<div class="vp-box-display-row">';
        h += '<span class="vp-box-display-num">Box ' + (i+1) + '</span>';
        h += '<span class="vp-box-display-detail">';
        var dims = [];
        if (box.l) dims.push(box.l + 'L');
        if (box.w) dims.push(box.w + 'W');
        if (box.h) dims.push(box.h + 'H');
        h += '<span class="vp-box-display-dims">' + (dims.join(' &times; ') || '&mdash;') + '</span>';
        if (box.weight) h += '<span class="vp-box-divider"></span><span class="vp-box-display-wt">' + box.weight + ' lbs</span>';
        h += '</span>';
        if (i === 0) h += '<span class="vp-packdet-edit-btn" onclick="vpEditPackDet(\'' + ref + '\')" title="Edit"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 3a2.83 2.83 0 114 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg></span>';
        h += '</div>';
    });
    h += '<button type="button" class="vp-packdet-addbox" onclick="vpEditPackDet(\'' + ref + '\', true)">+ Add Box</button>';
    h += '</div>';
    return h;
}

function vpSavePackCustom(ref) {
    var el = document.getElementById('vpPanelPackCustom');
    if (!el) return;
    var val = el.value.trim();
    apiCall('update_packing', { reference_code: ref, packing: 'custom', packing_custom: val }).then(function(r) {
        if (r.success) {
            showToast('Custom packing saved', 'success');
            if (typeof orderData !== 'undefined' && orderData[ref]) {
                orderData[ref].packing_custom = val;
            }
        }
    });
}

function vpEditPackDet(ref, addNew) {
    var d = (typeof orderData !== 'undefined' && orderData[ref]) ? orderData[ref] : {};
    var pd = d.packing_details || {};
    var container = document.getElementById('vpPanelPackDet');
    if (!container) return;
    var boxes = pd.boxes || [{ l:'', w:'', h:'', weight:'' }];
    if (addNew) boxes.push({ l:'', w:'', h:'', weight:'' });
    var h = '';
    boxes.forEach(function(box, i) { h += vpRenderBoxRow(i, box); });
    h += '<button type="button" class="vp-packdet-save vp-packdet-save-sm" onclick="vpSavePanelPackDet(\'' + ref + '\')">Save</button>';
    h += '<button type="button" class="vp-packdet-addbox vp-packdet-addbox-full" onclick="vpAddPanelBox()">+ Add Box</button>';
    container.innerHTML = h;
}

function vpRenderBoxRow(idx, box) {
    box = box || { l:'', w:'', h:'', weight:'' };
    var h = '<div class="vp-box-row" data-idx="' + idx + '">';
    h += '<div class="vp-box-inputs">';
    h += '<span class="vp-box-num">Box ' + (idx+1) + '</span>';
    h += '<span class="vp-dim-lbl">L</span><input type="number" class="vp-packdet-input vp-dim-input vp-box-l" min="0" step="0.5" value="' + (box.l||'') + '">';
    h += '<span class="vp-dim-x">&times;</span>';
    h += '<span class="vp-dim-lbl">W</span><input type="number" class="vp-packdet-input vp-dim-input vp-box-w" min="0" step="0.5" value="' + (box.w||'') + '">';
    h += '<span class="vp-dim-x">&times;</span>';
    h += '<span class="vp-dim-lbl">H</span><input type="number" class="vp-packdet-input vp-dim-input vp-box-h" min="0" step="0.5" value="' + (box.h||'') + '">';
    h += '<span class="vp-box-divider"></span>';
    h += '<input type="text" class="vp-packdet-input vp-box-wt" value="' + (box.weight||'') + '">';
    h += '<span class="vp-dim-lbl">lbs</span>';
    if (idx > 0) h += '<button type="button" class="vp-box-remove" onclick="vpRemovePanelBox(' + idx + ')">&times;</button>';
    h += '</div></div>';
    return h;
}

function vpAddPanelBox() {
    var container = document.getElementById('vpPanelPackDet');
    if (!container) return;
    var existing = container.querySelectorAll('.vp-box-row');
    var newIdx = existing.length;
    var addBtn = container.querySelector('.vp-packdet-addbox');
    addBtn.insertAdjacentHTML('beforebegin', vpRenderBoxRow(newIdx, {}));
}

function vpRemovePanelBox(idx) {
    var row = document.querySelector('.vp-box-row[data-idx="' + idx + '"]');
    if (row) row.remove();
    // Re-number
    document.querySelectorAll('#vpPanelPackDet .vp-box-row').forEach(function(r, i) {
        r.setAttribute('data-idx', i);
        r.querySelector('.vp-box-num').textContent = 'Box ' + (i+1);
        if (i === 0) { var rb = r.querySelector('.vp-box-remove'); if (rb) rb.remove(); }
    });
}

function vpSavePanelPackDet(ref) {
    var container = document.getElementById('vpPanelPackDet');
    if (!container) return;
    var details = {};
    
    // Check if multi-box
    var boxRows = container.querySelectorAll('.vp-box-row');
    if (boxRows.length > 0) {
        var boxes = [];
        boxRows.forEach(function(row) {
            boxes.push({
                l: row.querySelector('.vp-box-l') ? row.querySelector('.vp-box-l').value : '',
                w: row.querySelector('.vp-box-w') ? row.querySelector('.vp-box-w').value : '',
                h: row.querySelector('.vp-box-h') ? row.querySelector('.vp-box-h').value : '',
                weight: row.querySelector('.vp-box-wt') ? row.querySelector('.vp-box-wt').value.trim() : ''
            });
        });
        details.boxes = boxes;
        details.qty = boxes.length;
    } else {
        // Tube - just qty
        var qtyEl = document.getElementById('vpPanelPDQty');
        details.qty = qtyEl ? qtyEl.value : '1';
    }
    
    apiCall('update_packing_details', { reference_code: ref, packing_details: details }).then(function(r) {
        if (r.success) {
            showToast('Packing details saved', 'success');
            if (typeof orderData !== 'undefined' && orderData[ref]) {
                orderData[ref].packing_details = details;
            }
            // Switch to read-only display
            var container = document.getElementById('vpPanelPackDet');
            if (container && details.boxes && details.boxes.length) {
                container.innerHTML = vpRenderBoxDisplay(details.boxes, ref);
            }
        }
    });
}

// ---- Packing Details Popover ----
var vpPackDetRef = null;
var vpPackDetType = null;

function vpShowPackDetails(ref, packType) {
    vpPackDetRef = ref;
    vpPackDetType = packType;
    var pop = document.getElementById('vpPackDetPop');
    var title = document.getElementById('vpPackDetTitle');
    title.textContent = (packType === 'box' ? 'Box' : 'Tube') + ' Details';
    
    // Show/hide box-only fields
    pop.querySelectorAll('.vp-packdet-box-only').forEach(function(el) {
        el.style.display = packType === 'box' ? '' : 'none';
    });
    
    // Pre-fill from existing data
    var d = (typeof orderData !== 'undefined' && orderData[ref]) ? orderData[ref].packing_details || {} : {};
    document.getElementById('vpPackDetQty').value = d.qty || 1;
    
    // Populate box rows
    var boxContainer = document.getElementById('vpPopBoxRows');
    if (boxContainer) {
        boxContainer.innerHTML = '';
        var boxes = d.boxes || [];
        if (packType === 'box') {
            if (boxes.length === 0) boxes = [{ l:'', w:'', h:'', weight:'' }];
            boxes.forEach(function(box, i) { boxContainer.innerHTML += vpRenderBoxRow(i, box); });
        }
    }
    document.getElementById('vpPackDetWeight').value = d.weight || '';
    
    // Position near the badge
    var wrap = document.querySelector('.vp-pack-wrap[data-ref="' + ref + '"]');
    if (wrap) {
        var rect = wrap.getBoundingClientRect();
        pop.style.top = (rect.bottom + 6) + 'px';
        pop.style.left = rect.left + 'px';
    }
    pop.style.display = '';
}

function vpAddPopBox() {
    var container = document.getElementById('vpPopBoxRows');
    if (!container) return;
    var idx = container.querySelectorAll('.vp-box-row').length;
    container.innerHTML += vpRenderBoxRow(idx, {});
}

function vpClosePackDetails() {
    document.getElementById('vpPackDetPop').style.display = 'none';
    vpPackDetRef = null;
    vpPackDetType = null;
}

function vpSavePackDetails() {
    if (!vpPackDetRef) return;
    var details = {
        qty: document.getElementById('vpPackDetQty').value || '1',
        boxes: (function() {
            var rows = document.querySelectorAll('#vpPopBoxRows .vp-box-row');
            var arr = [];
            rows.forEach(function(r) {
                arr.push({ l: r.querySelector('.vp-box-l').value, w: r.querySelector('.vp-box-w').value, h: r.querySelector('.vp-box-h').value, weight: r.querySelector('.vp-box-wt') ? r.querySelector('.vp-box-wt').value : '' });
            });
            return arr;
        })()
    };
    apiCall('update_packing_details', { reference_code: vpPackDetRef, packing_details: details }).then(function(r) {
        if (r.success) {
            showToast('Packing details saved', 'success');
            // Update local data
            if (typeof orderData !== 'undefined' && orderData[vpPackDetRef]) {
                orderData[vpPackDetRef].packing_details = details;
            }

        }
        vpClosePackDetails();
    });
}

// Close popover on outside click
document.addEventListener('mousedown', function(e) {
    var pop = document.getElementById('vpPackDetPop');
    if (pop.style.display !== 'none' && !pop.contains(e.target) && !e.target.closest('.vp-pack-wrap')) {
        vpClosePackDetails();
    }
});

function toggleVpBatch(batchId) {
    var body = document.getElementById('vpBatchBody_' + batchId);
    var chev = document.getElementById('vpBatchChev_' + batchId);
    if (!body) return;
    if (body.style.display === 'none') {
        body.style.display = '';
        if (chev) chev.innerHTML = '&#9660;';
    } else {
        body.style.display = 'none';
        if (chev) chev.innerHTML = '&#9654;';
    }
}

async function batchConfirmAll(batchId, refs) {
    if (!confirm('Confirm all ' + refs.length + ' orders in batch ' + batchId + '?')) return;
    var success = 0, fail = 0;
    for (var i = 0; i < refs.length; i++) {
        try {
            var r = await apiCall('approve', { reference_code: refs[i] });
            if (r.success) success++; else fail++;
        } catch(e) { fail++; }
    }
    showToast(success + ' confirmed' + (fail > 0 ? ', ' + fail + ' failed' : ''), success > 0 ? 'success' : 'error');
    if (success > 0) setTimeout(function() { location.reload(); }, 1200);
}

async function batchReadyAll(batchId, refs) {
    if (!confirm('Mark all ' + refs.length + ' orders in batch ' + batchId + ' as ready?')) return;
    var success = 0, fail = 0;
    for (var i = 0; i < refs.length; i++) {
        try {
            var r = await apiCall('mark_ready', { reference_code: refs[i] });
            if (r.success) success++; else fail++;
        } catch(e) { fail++; }
    }
    showToast(success + ' marked ready' + (fail > 0 ? ', ' + fail + ' failed' : ''), success > 0 ? 'success' : 'error');
    if (success > 0) setTimeout(function() { location.reload(); }, 1200);
}

// ============================================
// BATCH PRICING
// ============================================
var bpActiveBatch = null;
var bpItems = []; // {ref, width, height, area, weight, price, locked}

function openBatchPriceModal(batchId) {
    bpActiveBatch = batchId;
    document.getElementById('bpBatchId').textContent = batchId;
    
    // Find all orders in this batch from orderData
    var group = document.querySelector('.vp-batch-group[data-batch-id="' + batchId + '"]');
    if (!group) return;
    var rows = group.querySelectorAll('tr[data-ref]');
    
    bpItems = [];
    var totalArea = 0;
    rows.forEach(function(row) {
        var ref = row.dataset.ref;
        var d = orderData[ref];
        if (!d) return;
        var w = parseInt(d.width) || 0;
        var h = parseInt(d.height) || 0;
        var area = w * h;
        totalArea += area;
        var existingPrice = 0;
        if (d.vendor_pricing && d.vendor_pricing.base_price) existingPrice = parseFloat(d.vendor_pricing.base_price);
        bpItems.push({ ref: ref, width: w, height: h, area: area, weight: 0, price: existingPrice, locked: false, material: d.material || 'Paper' });
    });
    
    // Calculate weights
    bpItems.forEach(function(item) { item.weight = totalArea > 0 ? item.area / totalArea : 1 / bpItems.length; });
    
    // Build items table
    var tbody = document.getElementById('bpItemsBody');
    tbody.innerHTML = '';
    bpItems.forEach(function(item, i) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><strong>' + item.ref + '</strong></td>' +
            '<td>' + item.width + '" &times; ' + item.height + '"</td>' +
            '<td>' + item.area.toLocaleString() + ' sq in <span class="bp-weight">(' + (item.weight * 100).toFixed(0) + '%)</span></td>' +
            '<td>' + item.material + '</td>' +
            '<td><div class="bp-input-wrap bp-input-sm"><span class="bp-prefix">$</span><input type="text" inputmode="decimal" class="bp-item-price" data-idx="' + i + '" value="' + (item.price > 0 ? item.price.toFixed(2) : '') + '" placeholder="0.00" oninput="bpItemEdited(' + i + ')" onblur="bpItemBlur(this)"></div></td>' +
            '<td><button class="bp-lock-btn ' + (item.locked ? 'locked' : '') + '" data-idx="' + i + '" onclick="bpToggleLock(' + i + ')" title="Lock/unlock this price">&#128274;</button></td>';
        tbody.appendChild(tr);
    });
    
    // Populate existing batch total if items already priced
    var existingTotal = bpItems.reduce(function(s, it) { return s + it.price; }, 0);
    document.getElementById('bpBatchTotal').value = existingTotal > 0 ? existingTotal.toFixed(2) : '';
    document.getElementById('bpTubePrice').value = '';
    document.getElementById('bpTubeQty').value = '0';
    bpRecalcTotal();
    
    document.getElementById('batchPriceModal').style.display = 'flex';
    
    // Apply auto-decimal to all pricing inputs
    autoDecimal(document.getElementById('bpBatchTotal'));
    autoDecimal(document.getElementById('bpTubePrice'));
    document.querySelectorAll('.bp-item-price').forEach(autoDecimal);
}

function closeBatchPriceModal() {
    document.getElementById('batchPriceModal').style.display = 'none';
    bpActiveBatch = null;
    bpItems = [];
}

// Top-down: batch total → distribute to unlocked items
function bpAllocate() {
    var total = parseFloat(document.getElementById('bpBatchTotal').value) || 0;
    
    // Calculate locked total
    var lockedTotal = 0;
    bpItems.forEach(function(it) { if (it.locked) lockedTotal += it.price; });
    
    var remaining = Math.max(0, total - lockedTotal);
    
    // Calculate total weight of unlocked items
    var unlockedWeight = 0;
    bpItems.forEach(function(it) { if (!it.locked) unlockedWeight += it.weight; });
    
    // Distribute remaining to unlocked items
    bpItems.forEach(function(it, i) {
        if (it.locked) return;
        it.price = unlockedWeight > 0 ? Math.round(remaining * (it.weight / unlockedWeight) * 100) / 100 : 0;
        var input = document.querySelector('.bp-item-price[data-idx="' + i + '"]');
        if (input) input.value = it.price > 0 ? it.price.toFixed(2) : '';
    });
    
    bpRecalcTotal();
}

// Bottom-up: item edited → recalculate batch total
function bpItemEdited(idx) {
    var input = document.querySelector('.bp-item-price[data-idx="' + idx + '"]');
    var val = parseFloat(input.value) || 0;
    bpItems[idx].price = val;
    bpItems[idx].locked = true;
    
    var lockBtn = document.querySelector('.bp-lock-btn[data-idx="' + idx + '"]');
    if (lockBtn) lockBtn.classList.add('locked');
    
    // Recalculate batch total from all item prices
    var printTotal = bpItems.reduce(function(s, it) { return s + it.price; }, 0);
    document.getElementById('bpBatchTotal').value = printTotal > 0 ? printTotal.toFixed(2) : '';
    
    bpRecalcTotal();
}

function bpItemBlur(el) {
    // Handled by autoDecimal
}

function bpToggleLock(idx) {
    bpItems[idx].locked = !bpItems[idx].locked;
    var lockBtn = document.querySelector('.bp-lock-btn[data-idx="' + idx + '"]');
    if (lockBtn) lockBtn.classList.toggle('locked');
}

function bpRecalcTotal() {
    var printSub = bpItems.reduce(function(s, it) { return s + it.price; }, 0);
    var tubePrice = parseFloat(document.getElementById('bpTubePrice').value) || 0;
    var tubeQty = parseInt(document.getElementById('bpTubeQty').value) || 0;
    var tubeSub = tubePrice * tubeQty;
    var sub = printSub + tubeSub;
    var tax = sub * 0.13;
    var grand = sub + tax;
    
    document.getElementById('bpTubeTotal').textContent = '$' + tubeSub.toFixed(2);
    document.getElementById('bpPrintSub').textContent = '$' + printSub.toFixed(2);
    document.getElementById('bpTubeSub').textContent = '$' + tubeSub.toFixed(2);
    document.getElementById('bpTax').textContent = '$' + tax.toFixed(2);
    document.getElementById('bpGrandTotal').textContent = '$' + grand.toFixed(2);
}

function submitBatchPrice() {
    var printTotal = bpItems.reduce(function(s, it) { return s + it.price; }, 0);
    if (printTotal <= 0) { showToast('Enter prices for the batch', 'error'); return; }
    
    // Validate all items have prices
    var missing = bpItems.filter(function(it) { return it.price <= 0; });
    if (missing.length > 0) { showToast(missing.length + ' item(s) have no price', 'error'); return; }
    
    var tubePrice = parseFloat(document.getElementById('bpTubePrice').value) || 0;
    var tubeQty = parseInt(document.getElementById('bpTubeQty').value) || 0;
    var tubeSub = tubePrice * tubeQty;
    var sub = printTotal + tubeSub;
    var tax = Math.round(sub * 0.13 * 100) / 100;
    var grand = Math.round((sub + tax) * 100) / 100;
    
    var itemPrices = bpItems.map(function(it) {
        return { ref: it.ref, base_price: Math.round(it.price * 100) / 100, area: it.area, weight: Math.round(it.weight * 10000) / 10000 };
    });
    
    apiCall('submit_batch_price', {
        batch_id: bpActiveBatch,
        items: itemPrices,
        batch_print_total: Math.round(printTotal * 100) / 100,
        tube_price: tubePrice,
        tube_qty: tubeQty,
        tube_total: Math.round(tubeSub * 100) / 100,
        tax_rate: 0.13,
        tax_amount: tax,
        grand_total: grand
    }).then(function(r) {
        if (r.success) {
            closeBatchPriceModal();
            showToast('Batch price submitted for review', 'success');
            setTimeout(function() { location.reload(); }, 1200);
        }
    });
}

async function batchApprovePrice(batchId, refs) {
    if (!confirm('Approve all prices in batch ' + batchId + '?')) return;
    var success = 0;
    for (var i = 0; i < refs.length; i++) {
        try {
            var r = await apiCall('accept_price', { reference_code: refs[i] });
            if (r.success) success++;
        } catch(e) {}
    }
    showToast(success + ' prices approved', 'success');
    if (success > 0) setTimeout(function() { location.reload(); }, 1200);
}

// ============================================
// VENDOR PROFILE
// ============================================
function openVendorProfile() {
    document.getElementById('vendorProfileOverlay').style.display = 'flex';
}
function closeVendorProfile() {
    document.getElementById('vendorProfileOverlay').style.display = 'none';
}
function toggleProfileDay(cb, day) {
    var inputs = document.getElementById('vpHoursInputs_' + day);
    var tag = document.getElementById('vpClosedTag_' + day);
    if (cb.checked) {
        inputs.style.opacity = '0.3'; inputs.style.pointerEvents = 'none'; tag.style.display = '';
    } else {
        inputs.style.opacity = '1'; inputs.style.pointerEvents = 'auto'; tag.style.display = 'none';
        if (!document.getElementById('vpOpen_' + day).value) document.getElementById('vpOpen_' + day).value = '09:00';
        if (!document.getElementById('vpClose_' + day).value) document.getElementById('vpClose_' + day).value = '18:00';
    }
}
function saveVendorProfile() {
    var days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    var hours = {};
    days.forEach(function(day) {
        var closedCb = document.querySelector('.vp-profile-closed-cb[data-day="' + day + '"]');
        hours[day] = {
            open: document.getElementById('vpOpen_' + day).value || '',
            close: document.getElementById('vpClose_' + day).value || '',
            closed: closedCb ? closedCb.checked : false
        };
    });
    apiCall('update_vendor_profile', {
        business_name: document.getElementById('vpBizName').value.trim(),
        contact_name: document.getElementById('vpContactName').value.trim(),
        phone: document.getElementById('vpPhone').value.trim(),
        address: document.getElementById('vpAddress').value.trim(),
        business_hours: hours
    }).then(function(r) {
        if (r.success) {
            showToast('Profile saved', 'success');
            closeVendorProfile();
            setTimeout(function() { location.reload(); }, 1000);
        }
    });
}

// ============================================
// CSV EXPORT
// ============================================
function exportCSV() {
    var table = document.querySelector('#tab-' + activeTab + ' table');
    if (!table) { showToast('No data to export', 'error'); return; }
    var rows = table.querySelectorAll('tbody tr:not([style*="display: none"])');
    if (rows.length === 0) { showToast('No visible rows to export', 'error'); return; }

    var headers = ['Order', 'File', 'Size', 'Material', 'Due', 'Packing', 'Price', 'Status', 'Paid'];
    var csvRows = [headers.join(',')];

    rows.forEach(function(row) {
        var ref = row.dataset.ref || '';
        var d = orderData[ref] || {};
        var vp = d.vendor_pricing || {};
        csvRows.push([
            ref,
            '"' + (d.file_name || '').replace(/"/g, '""') + '"',
            d.width + 'x' + d.height,
            d.material || '',
            (d.due_date || '') + (d.delivery_time && d.delivery_time !== 'anytime' ? ' ' + d.delivery_time : ''),
            '"' + (d.packing_label || '').replace(/"/g, '""') + '"',
            vp.total ? '$' + parseFloat(vp.total).toFixed(2) : '',
            d.status || '',
            d.vendor_paid ? 'Yes' : 'No'
        ].join(','));
    });

    var blob = new Blob([csvRows.join('\n')], { type: 'text/csv' });
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = 'fulfillment-' + activeTab + '-' + new Date().toISOString().slice(0,10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
    showToast('CSV exported', 'success');
}

// ============================================
// INIT
// ============================================
fpApplyFilters();
updateTimers();

// ============================================
// COLUMN RESIZER (invisible drag handles)
// ============================================
(function() {
    document.querySelectorAll('.vp-table').forEach(function(table) {
        var ths = table.querySelectorAll('thead th');
        ths.forEach(function(th) {
            if (th.classList.contains('vp-col-check')) return;
            var handle = document.createElement('div');
            handle.className = 'col-resize-handle';
            th.style.position = 'relative';
            th.appendChild(handle);
            
            var startX, startW, col;
            handle.addEventListener('mousedown', function(e) {
                e.preventDefault();
                e.stopPropagation();
                col = th;
                startX = e.pageX;
                startW = col.offsetWidth;
                document.addEventListener('mousemove', onDrag);
                document.addEventListener('mouseup', onUp);
                document.body.style.cursor = 'col-resize';
                document.body.style.userSelect = 'none';
            });
            
            function onDrag(e) {
                var w = Math.max(40, startW + (e.pageX - startX));
                col.style.width = w + 'px';
                col.style.minWidth = w + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onDrag);
                document.removeEventListener('mouseup', onUp);
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
            }
        });
    });
})();
</script>
<?php if (!$isAdmin && $currentVendorProfile): ?>
<!-- Vendor Profile Modal -->
<div class="vp-overlay" id="vendorProfileOverlay" style="display:none;">
    <div class="vp-modal vp-modal-profile">
        <div class="vp-modal-head">
            <h3>My Profile</h3>
            <button class="vp-modal-x" onclick="closeVendorProfile()">&times;</button>
        </div>
        <div class="vp-modal-body" id="vendorProfileBody">
            <div class="vp-pf-row">
                <label>Business Name</label>
                <input type="text" id="vpBizName" value="<?= htmlspecialchars($currentVendorProfile['business_name'] ?? '') ?>">
            </div>
            <div class="vp-pf-row">
                <label>Contact Name</label>
                <input type="text" id="vpContactName" value="<?= htmlspecialchars($currentVendorProfile['contact_name'] ?? '') ?>">
            </div>
            <div class="vp-pf-row">
                <label>Email</label>
                <input type="email" id="vpEmail" value="<?= htmlspecialchars($currentVendorProfile['email'] ?? '') ?>" disabled style="opacity:0.5;">
                <div style="font-size:0.7rem;color:#9ca3af;margin-top:2px;">Contact admin to change email.</div>
            </div>
            <div class="vp-pf-row">
                <label>Phone</label>
                <input type="tel" id="vpPhone" value="<?= htmlspecialchars($currentVendorProfile['phone'] ?? '') ?>">
            </div>
            <div class="vp-pf-row">
                <label>Address</label>
                <textarea id="vpAddress" rows="2"><?= htmlspecialchars($currentVendorProfile['address'] ?? '') ?></textarea>
            </div>
            <div class="vp-pf-row">
                <label>Business Hours</label>
                <div class="vp-profile-hours" id="vpProfileHours">
                    <?php
                    $dayNames = ['monday'=>'Mon','tuesday'=>'Tue','wednesday'=>'Wed','thursday'=>'Thu','friday'=>'Fri','saturday'=>'Sat','sunday'=>'Sun'];
                    $vpHours = $currentVendorProfile['business_hours'] ?? $defaultHours;
                    foreach ($dayNames as $dk => $dl):
                        $dh = $vpHours[$dk] ?? ['open'=>'09:00','close'=>'18:00','closed'=>in_array($dk,['saturday','sunday'])];
                    ?>
                    <div class="vp-profile-hours-row">
                        <label class="vp-profile-hours-cb">
                            <input type="checkbox" class="vp-profile-closed-cb" data-day="<?= $dk ?>" <?= !empty($dh['closed']) ? 'checked' : '' ?> onchange="toggleProfileDay(this,'<?= $dk ?>')">
                            <span class="vp-profile-day-label"><?= $dl ?></span>
                        </label>
                        <div class="vp-profile-hours-inputs" id="vpHoursInputs_<?= $dk ?>" style="<?= !empty($dh['closed']) ? 'opacity:0.3;pointer-events:none;' : '' ?>">
                            <input type="time" id="vpOpen_<?= $dk ?>" value="<?= htmlspecialchars($dh['open'] ?? '') ?>">
                            <span style="color:#9ca3af;font-size:0.78rem;">to</span>
                            <input type="time" id="vpClose_<?= $dk ?>" value="<?= htmlspecialchars($dh['close'] ?? '') ?>">
                        </div>
                        <span class="vp-profile-closed-tag" id="vpClosedTag_<?= $dk ?>" style="<?= empty($dh['closed']) ? 'display:none;' : '' ?>">Closed</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="vp-modal-foot">
            <button class="vp-btn vp-btn-ghost" onclick="closeVendorProfile()">Cancel</button>
            <button class="vp-btn vp-btn-primary" onclick="saveVendorProfile()">Save Profile</button>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- Packing Details Popover -->
<div class="vp-packdet-pop" id="vpPackDetPop" style="display:none">
    <div class="vp-packdet-title" id="vpPackDetTitle">Box Details</div>
    <div class="vp-packdet-fields">
        <div class="vp-packdet-row">
            <label>Qty</label>
            <input type="number" id="vpPackDetQty" min="1" value="1" class="vp-packdet-input">
        </div>
        <div id="vpPopBoxRows" class="vp-packdet-box-only"></div>
        <button type="button" class="vp-packdet-addbox vp-packdet-box-only" onclick="vpAddPopBox()">+ Add Box</button>
    </div>
    <div class="vp-packdet-actions">
        <button type="button" class="vp-packdet-save" onclick="vpSavePackDetails()">Save</button>
        <button type="button" class="vp-packdet-cancel" onclick="vpClosePackDetails()">Cancel</button>
    </div>
</div>
</body>
</html>