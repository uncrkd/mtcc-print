<?php
/**
 * Problem Actions API - Handles bulk operations + notes from problem pages
 * Location: /admin/problem-actions.php
 * 
 * Actions: bulk_status, bulk_cancel, bulk_remind, add_note, get_notes
 */
header('Content-Type: application/json');
require_once '../admin-auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$adminName = getCurrentAdminName();
$adminUser = getCurrentAdminUsername();

$statusFile = '../data/statuses.json';
$ordersDir = '../uploads/orders/';
$notesFile = '../data/problem-notes.json';

// ============================================
// NOTE ACTIONS (single order, not bulk)
// ============================================
if ($action === 'add_note') {
    $refCode = trim($_POST['ref'] ?? '');
    $text = trim($_POST['text'] ?? '');
    
    if (!$refCode || !$text) {
        echo json_encode(['success' => false, 'error' => 'Reference and text required']);
        exit;
    }
    
    $notes = file_exists($notesFile) ? json_decode(file_get_contents($notesFile), true) : [];
    if (!isset($notes[$refCode])) $notes[$refCode] = [];
    
    $note = [
        'text' => substr($text, 0, 500),
        'by' => $adminName,
        'by_user' => $adminUser,
        'at' => date('Y-m-d\TH:i:s')
    ];
    $notes[$refCode][] = $note;
    
    file_put_contents($notesFile, json_encode($notes, JSON_PRETTY_PRINT));
    
    echo json_encode(['success' => true, 'note' => $note, 'total' => count($notes[$refCode])]);
    exit;
}

if ($action === 'get_notes') {
    $refCode = trim($_GET['ref'] ?? '');
    if (!$refCode) {
        echo json_encode(['success' => false, 'error' => 'Reference required']);
        exit;
    }
    
    $notes = file_exists($notesFile) ? json_decode(file_get_contents($notesFile), true) : [];
    $orderNotes = $notes[$refCode] ?? [];
    
    echo json_encode(['success' => true, 'notes' => $orderNotes]);
    exit;
}

if ($action === 'delete_note') {
    $refCode = trim($_POST['ref'] ?? '');
    $noteIndex = intval($_POST['index'] ?? -1);
    
    if (!$refCode || $noteIndex < 0) {
        echo json_encode(['success' => false, 'error' => 'Reference and index required']);
        exit;
    }
    
    $notes = file_exists($notesFile) ? json_decode(file_get_contents($notesFile), true) : [];
    if (isset($notes[$refCode][$noteIndex])) {
        array_splice($notes[$refCode], $noteIndex, 1);
        if (empty($notes[$refCode])) unset($notes[$refCode]);
        file_put_contents($notesFile, json_encode($notes, JSON_PRETTY_PRINT));
    }
    
    echo json_encode(['success' => true, 'total' => count($notes[$refCode] ?? [])]);
    exit;
}

// ============================================
// BULK ACTIONS (require orders array)
// ============================================
$selectedOrders = $_POST['orders'] ?? [];

if (empty($selectedOrders) || !is_array($selectedOrders)) {
    echo json_encode(['success' => false, 'error' => 'No orders selected']);
    exit;
}

$statuses = file_exists($statusFile) ? json_decode(file_get_contents($statusFile), true) : [];
$processed = 0;
$errors = [];

switch ($action) {
    case 'bulk_status':
        $newStatus = $_POST['new_status'] ?? '';
        $validStatuses = ['unpaid', 'new', 'preflight', 'file_issue', 'printing', 'ready', 'shipped', 'dispatched', 'delivered', 'pickedup', 'missing', 'complete', 'cancelled', 'refunded'];
        if (!in_array($newStatus, $validStatuses)) {
            echo json_encode(['success' => false, 'error' => 'Invalid status: ' . $newStatus]);
            exit;
        }
        foreach ($selectedOrders as $refCode) {
            $refCode = trim($refCode);
            $oldStatus = $statuses[$refCode] ?? 'unknown';
            $statuses[$refCode] = $newStatus;
            
            $orderFiles = glob($ordersDir . '*-order.json');
            foreach ($orderFiles as $file) {
                $order = json_decode(file_get_contents($file), true);
                if ($order && ($order['referenceCode'] ?? '') === $refCode) {
                    $order['status'] = $newStatus;
                    $order['statusHistory'][] = [
                        'status' => $newStatus,
                        'timestamp' => date('Y-m-d\TH:i:s'),
                        'by' => $adminName,
                        'note' => "Bulk status change from {$oldStatus} to {$newStatus}"
                    ];
                    file_put_contents($file, json_encode($order, JSON_PRETTY_PRINT));
                    break;
                }
            }
            $processed++;
        }
        file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT));
        
        $logFile = '../data/activity-log.json';
        $log = file_exists($logFile) ? json_decode(file_get_contents($logFile), true) : ['entries' => []];
        $log['entries'][] = [
            'timestamp' => date('Y-m-d\TH:i:s'),
            'user' => $adminName,
            'action' => 'bulk_status_change',
            'details' => "Changed {$processed} orders to status: {$newStatus}",
            'orders' => $selectedOrders
        ];
        file_put_contents($logFile, json_encode($log, JSON_PRETTY_PRINT));
        break;

    case 'bulk_cancel':
        foreach ($selectedOrders as $refCode) {
            $refCode = trim($refCode);
            $statuses[$refCode] = 'cancelled';
            
            $orderFiles = glob($ordersDir . '*-order.json');
            foreach ($orderFiles as $file) {
                $order = json_decode(file_get_contents($file), true);
                if ($order && ($order['referenceCode'] ?? '') === $refCode) {
                    $order['status'] = 'cancelled';
                    $order['statusHistory'][] = [
                        'status' => 'cancelled',
                        'timestamp' => date('Y-m-d\TH:i:s'),
                        'by' => $adminName,
                        'note' => 'Bulk cancelled from Problem Orders page'
                    ];
                    file_put_contents($file, json_encode($order, JSON_PRETTY_PRINT));
                    break;
                }
            }
            $processed++;
        }
        file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT));
        break;

    case 'bulk_remind':
        $reminderLogFile = '../data/reminder-log.json';
        $reminderLog = file_exists($reminderLogFile) ? json_decode(file_get_contents($reminderLogFile), true) : ['reminders' => []];
        
        foreach ($selectedOrders as $refCode) {
            $refCode = trim($refCode);
            if (!isset($reminderLog['reminders'][$refCode])) {
                $reminderLog['reminders'][$refCode] = [];
            }
            $reminderLog['reminders'][$refCode][] = [
                'sent_at' => date('Y-m-d\TH:i:s'),
                'type' => 'manual_bulk',
                'triggered_by' => $adminName
            ];
            $processed++;
        }
        file_put_contents($reminderLogFile, json_encode($reminderLog, JSON_PRETTY_PRINT));
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
        exit;
}

echo json_encode([
    'success' => true,
    'action' => $action,
    'processed' => $processed,
    'errors' => $errors
]);
