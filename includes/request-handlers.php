<?php
/**
 * Request Handlers
 * Handles all POST/GET request processing for admin actions
 */

/**
 * Handle file download requests
 */
function handleDownload() {
    if (!isset($_GET['download']) || !isset($_SESSION['admin_logged_in'])) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $referenceCode = $_GET['download'];
    $orderData = findOrderByReference($referenceCode);
    
    if ($orderData && isset($orderData['uploadedFile'])) {
        $originalName = $orderData['uploadedFile']['originalName'] ?? 'file';
        $storedPath = $orderData['uploadedFile']['path'] ?? '';
        $storedName = $orderData['uploadedFile']['storedName'] ?? '';
        $filesDir = 'uploads/files/';

        // Robust path resolution
        $filePath = null;
        if ($storedPath && substr($storedPath, 0, 1) === '/' && file_exists($storedPath)) {
            $filePath = $storedPath;
        }
        if (!$filePath && $storedPath && file_exists($storedPath)) {
            $filePath = $storedPath;
        }
        if (!$filePath && $storedName && file_exists($filesDir . $storedName)) {
            $filePath = $filesDir . $storedName;
        }
        if (!$filePath) {
            $matches = glob($filesDir . $referenceCode . '_*');
            if (!empty($matches)) $filePath = $matches[0];
        }
        if (!$filePath) {
            $matches = glob($filesDir . $referenceCode . '-*');
            if (!empty($matches)) $filePath = $matches[0];
        }

        if ($filePath && file_exists($filePath)) {
            $downloadName = function_exists('getDisplayFileName') ? getDisplayFileName($referenceCode, $originalName) : $referenceCode . '-01-' . $originalName;
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        }
    }
    
    header('HTTP/1.0 404 Not Found');
    echo 'File not found';
    exit;
}

/**
 * Handle order deletion
 */
function handleOrderDeletion() {
    if (!isset($_POST['delete_order']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'];
        $orderData = findOrderByReference($referenceCode);
        
        if (!$orderData) {
            throw new Exception('Order not found');
        }
        
        // Delete order files
        if (!deleteOrderFiles($orderData)) {
            throw new Exception('Failed to delete order files');
        }
        
        // Remove from statuses
        $statuses = loadStatuses();
        if (isset($statuses[$referenceCode])) {
            unset($statuses[$referenceCode]);
            saveStatuses($statuses);
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Handle status updates
 */
function handleStatusUpdate() {
    if (!isset($_POST['update_status']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'];
        $newStatus = $_POST['status'];
        
        // Validate status
        $validStatuses = ['unpaid', 'paid', 'preflight', 'file_issue', 'printing', 'ready', 'dispatched', 'shipped', 'delivered', 'pickedup', 'unclaimed', 'missing', 'cancelled', 'refunded'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception('Invalid status');
        }
        
        // Update status
        $statuses = loadStatuses();
        $statuses[$referenceCode] = $newStatus;
        
        if (!saveStatuses($statuses)) {
            throw new Exception('Failed to save status file');
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => 'Status updated successfully',
            'referenceCode' => $referenceCode,
            'newStatus' => $newStatus
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/**
 * Route incoming requests to appropriate handlers
 */
function routeRequest() {
    // Handle GET requests
    if (isset($_GET['download'])) {
        handleDownload();
    }
    
    // Handle POST requests  
    if (isset($_POST['delete_order'])) {
        handleOrderDeletion();
    }
    
    if (isset($_POST['update_status'])) {
        handleStatusUpdate();
    }
    
    // Default to dashboard
    return 'dashboard';
}
?>