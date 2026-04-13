<?php
/**
 * Shared Utilities - MTCC Print Services
 * General-purpose helper functions for admin operations.
 *
 * Location: /includes/utilities.php
 */

// Load centralized data access layer (logOrderHistory, getOrderHistory, loadStatuses, etc.)
require_once __DIR__ . '/data-access.php';

// Include dispatch email functions if available (file is in root, not includes)
$emailFuncPath = dirname(__DIR__) . '/email-status-notifications.php';
if (file_exists($emailFuncPath)) {
    require_once $emailFuncPath;
}

function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

/**
 * Get standardized display/download filename: REFCODE-01-originalname.ext
 * Centralized so all pages use the same convention.
 * Item number defaults to 01 for single-item orders; increment for multi-item.
 *
 * @param string $refCode     Order reference code (e.g. MST-014)
 * @param string $originalName Original uploaded filename (e.g. logo.png)
 * @param int    $itemNum     Line item number (default 1)
 * @return string Formatted filename (e.g. MST-014-01-logo.png)
 */
function getDisplayFileName($refCode, $originalName, $itemNum = 1) {
    if (empty($originalName)) return $refCode . '-' . str_pad($itemNum, 2, '0', STR_PAD_LEFT) . '-file';
    // Don't double-prefix if original already starts with refCode
    if (strpos($originalName, $refCode) === 0) return $originalName;
    return $refCode . '-' . str_pad($itemNum, 2, '0', STR_PAD_LEFT) . '-' . $originalName;
}

// loadStatuses() is now in data-access.php

function generateMTCCTrackingNumber($order, $eventPrefix = null) {
    // Auto-determine event prefix if not provided
    if ($eventPrefix === null) {
        $eventPrefix = getEventPrefixForOrder($order);
    }
    
    // Extract order date and number
    $orderDate = new DateTime($order['selectedDate']);
    $orderNumberMatch = preg_match('/(\d+)$/', $order['referenceCode'], $matches);
    $orderNumber = $orderNumberMatch ? $matches[1] : '001';
    
    if ($eventPrefix) {
        // New format: MTCC + Event + Order + Date
        $trackingNumber = 'MTCC' . $eventPrefix . str_pad($orderNumber, 3, '0', STR_PAD_LEFT) . $orderDate->format('ymd');
    } else {
        // Legacy format: MTCC + Date + Order
        $trackingNumber = 'MTCC' . $orderDate->format('ymd') . str_pad($orderNumber, 3, '0', STR_PAD_LEFT);
    }
    
    return $trackingNumber;
}

function handleDownload() {
    if (!isset($_GET['download']) || !isset($_SESSION['admin_logged_in'])) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $referenceCode = $_GET['download'];
    $orderDir = 'uploads/orders/';
    $filesDir = 'uploads/files/';

    // Find the specific order file
    $orderFiles = glob($orderDir . '*-order.json');
    $orderData = null;

    foreach ($orderFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && $data['referenceCode'] === $referenceCode) {
            $orderData = $data;
            break;
        }
    }

    if ($orderData && isset($orderData['uploadedFile'])) {
        $originalName = $orderData['uploadedFile']['originalName'] ?? 'file';
        $storedPath = $orderData['uploadedFile']['path'] ?? '';
        $storedName = $orderData['uploadedFile']['storedName'] ?? '';

        // Robust path resolution (absolute, relative, storedName, glob)
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
            $downloadName = getDisplayFileName($referenceCode, $originalName);
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
        
        if (file_put_contents('data/statuses.json', json_encode($statuses, JSON_PRETTY_PRINT)) === false) {
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
// findOrderByReference() is now in data-access.php

function handleOrderDeletion() {
    if (!isset($_POST['delete_order']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'];
        $orderInfo = findOrderByReference($referenceCode);
        
        if (!$orderInfo) {
            throw new Exception('Order not found');
        }
        
        $orderData = $orderInfo['data'];
        $orderFile = $orderInfo['filepath'];
        
        // Delete uploaded files if they exist
        if (isset($orderData['uploadedFile']) && file_exists($orderData['uploadedFile']['path'])) {
            unlink($orderData['uploadedFile']['path']);
        }
        
        // Delete order JSON file
        unlink($orderFile);
        
        // Remove from statuses
        $statuses = loadStatuses();
        if (isset($statuses[$referenceCode])) {
            unset($statuses[$referenceCode]);
            file_put_contents('data/statuses.json', json_encode($statuses, JSON_PRETTY_PRINT));
        }
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleAddInternalNote() {
    if (!isset($_POST['add_internal_note']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'];
        $username = trim($_POST['username']);
        $noteContent = trim($_POST['note_content']);
        
        if (empty($username) || empty($noteContent)) {
            throw new Exception('Username and note content are required');
        }
        
        $orderInfo = findOrderByReference($referenceCode);
        if (!$orderInfo) {
            throw new Exception('Order not found');
        }
        
        $orderData = $orderInfo['data'];
        $orderFile = $orderInfo['filepath'];
        
        // Initialize internal notes structure if it doesn't exist
        if (!isset($orderData['internalNotes'])) {
            $orderData['internalNotes'] = [];
        }
        
        // Add new note
        $newNote = [
            'id' => uniqid(),
            'username' => $username,
            'content' => $noteContent,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $orderData['internalNotes'][] = $newNote;
        
        // Save updated order
        if (file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to save note');
        }
        
        // Log to order history
        logOrderHistory($referenceCode, 'note_added', "Note added by $username", $username);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'note' => $newNote,
            'message' => 'Note added successfully'
        ]);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleEditInternalNote() {
    if (!isset($_POST['edit_internal_note']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'];
        $noteId = $_POST['note_id'];
        $username = trim($_POST['username']);
        $noteContent = trim($_POST['note_content']);
        
        if (empty($username) || empty($noteContent)) {
            throw new Exception('Username and note content are required');
        }
        
        $orderInfo = findOrderByReference($referenceCode);
        if (!$orderInfo || !isset($orderInfo['data']['internalNotes'])) {
            throw new Exception('Order or notes not found');
        }
        
        $orderData = $orderInfo['data'];
        $orderFile = $orderInfo['filepath'];
        
        // Find and update the note
        $noteUpdated = false;
        foreach ($orderData['internalNotes'] as &$note) {
            if ($note['id'] === $noteId) {
                $note['username'] = $username;
                $note['content'] = $noteContent;
                $note['editedAt'] = date('Y-m-d H:i:s');
                $noteUpdated = true;
                break;
            }
        }
        
        if (!$noteUpdated) {
            throw new Exception('Note not found');
        }
        
        // Save updated order
        if (file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to save note');
        }
        
        // Log to order history
        logOrderHistory($referenceCode, 'note_edited', "Note edited by $username", $username);
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Note updated successfully']);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleRemoveInternalNote() {
    if (!isset($_POST['remove_internal_note']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'] ?? '';
        $noteId = $_POST['note_id'] ?? '';
        
        if (empty($referenceCode) || empty($noteId)) {
            throw new Exception('Missing reference code or note ID');
        }
        
        $orderInfo = findOrderByReference($referenceCode);
        if (!$orderInfo) {
            throw new Exception('Order not found for reference: ' . $referenceCode);
        }
        
        $orderData = $orderInfo['data'];
        $orderFile = $orderInfo['filepath'];
        
        if (!isset($orderData['internalNotes']) || !is_array($orderData['internalNotes'])) {
            throw new Exception('No internal notes found for this order');
        }
        
        // Find and remove the note
        $noteRemoved = false;
        $noteIndex = -1;
        
        foreach ($orderData['internalNotes'] as $index => $note) {
            if (isset($note['id']) && $note['id'] === $noteId) {
                $noteIndex = $index;
                $noteRemoved = true;
                break;
            }
        }
        
        if (!$noteRemoved) {
            throw new Exception('Note with ID ' . $noteId . ' not found in order notes');
        }
        
        // Remove the note and re-index array
        unset($orderData['internalNotes'][$noteIndex]);
        $orderData['internalNotes'] = array_values($orderData['internalNotes']);
        
        // Save updated order
        if (file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to save updated order file');
        }
        
        // Log to order history
        logOrderHistory($referenceCode, 'note_removed', 'Note was removed', 'Admin');
        
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Note removed successfully']);
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function validateUploadedFile($file, $allowedExtensions = null, $maxFileSize = null) {
    if (!$allowedExtensions) {
        $allowedExtensions = ['.pdf', '.ai', '.eps', '.psd', '.png', '.jpg', '.jpeg', '.tiff', '.tif', '.webp', '.gif', '.bmp', '.svg', '.pptx', '.indd'];
    }
    
    if (!$maxFileSize) {
        $maxFileSize = 100 * 1024 * 1024; // 100MB
    }
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'File upload error occurred'];
    }
    
    // Check file size
    if ($file['size'] > $maxFileSize) {
        return ['valid' => false, 'error' => 'File is too large. Maximum size is ' . formatFileSize($maxFileSize)];
    }
    
    // Check file extension
    $fileExt = '.' . strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExt, $allowedExtensions)) {
        return ['valid' => false, 'error' => 'File type not supported'];
    }
    
    return ['valid' => true, 'error' => null];
}

function handleOrderSave() {
    if (!isset($_POST['save_order']) || !isset($_SESSION['admin_logged_in'])) {
        header('Location: admin-orders.php?error=unauthorized');
        exit;
    }
    
    $oldReferenceCode = $_POST['reference_code'];
    $newReferenceCode = $_POST['new_reference_code'];
    
    try {
        $orderInfo = findOrderByReference($oldReferenceCode);
        if (!$orderInfo) {
            throw new Exception('Order not found');
        }
        
        $orderData = $orderInfo['data'];
        $originalFile = $orderInfo['filepath'];
        
        // Handle file removal if requested
        if (isset($_POST['remove_file']) && $_POST['remove_file'] === '1') {
            if (isset($orderData['uploadedFile']) && file_exists($orderData['uploadedFile']['path'])) {
                unlink($orderData['uploadedFile']['path']);
            }
            unset($orderData['uploadedFile']);
        }
        
        // Handle new file upload
        if (isset($_FILES['new_file']) && $_FILES['new_file']['error'] === UPLOAD_ERR_OK) {
            $validation = validateUploadedFile($_FILES['new_file']);
            if (!$validation['valid']) {
                throw new Exception($validation['error']);
            }
            
            $uploadDir = 'uploads/files/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $fileName = time() . '-' . basename($_FILES['new_file']['name']);
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($_FILES['new_file']['tmp_name'], $uploadPath)) {
                // Remove old file if exists
                if (isset($orderData['uploadedFile']) && file_exists($orderData['uploadedFile']['path'])) {
                    unlink($orderData['uploadedFile']['path']);
                }
                
                $orderData['uploadedFile'] = [
                    'originalName' => $_FILES['new_file']['name'],
                    'path' => $uploadPath,
                    'size' => $_FILES['new_file']['size']
                ];
            } else {
                throw new Exception('Failed to upload file');
            }
        }
        
        // Update order data
        $orderData['referenceCode'] = $newReferenceCode;
        $orderData['customerInfo']['name'] = $_POST['customer_name'];
        $orderData['customerInfo']['email'] = $_POST['customer_email'];
        $orderData['customerInfo']['phone'] = $_POST['customer_phone'];
		if (isset($_POST['country_code'])) {
    	$orderData['customerInfo']['countryCode'] = $_POST['country_code'];
		}
        $orderData['customerInfo']['additionalNotes'] = $_POST['additional_notes'] ?? '';
        $orderData['dimensions']['width'] = (float)$_POST['width'];
        $orderData['dimensions']['height'] = (float)$_POST['height'];
        $orderData['material'] = $_POST['material'];
        $orderData['selectedDate'] = $_POST['delivery_date'];
        $orderData['deliveryOption'] = $_POST['delivery_option'];
        
        // Update pricing information
$orderData['pricing'] = [
    'basePrice' => (float)$_POST['base_price'],
    'deliveryFee' => (float)$_POST['delivery_fee'],
    'tax' => (float)$_POST['tax'],
    'total' => (float)$_POST['total'],
    'tier' => $_POST['priority_tier']
];
        
        // Update delivery address if applicable
        if ($_POST['delivery_option'] === 'office') {
            $orderData['deliveryAddress'] = [
                'company' => $_POST['delivery_company'] ?? '',
                'attn' => $_POST['delivery_attn'],
                'address' => $_POST['delivery_address'],
                'unit' => $_POST['delivery_unit'] ?? '',
                'city' => $_POST['delivery_city'],
                'province' => $_POST['delivery_province'],
                'postal' => $_POST['delivery_postal'],
                'instructions' => $_POST['delivery_instructions'] ?? ''
            ];
        } else {
            unset($orderData['deliveryAddress']);
        }
        
        // Update status
        $statuses = loadStatuses();
        
        // Track old status for email notification
        $oldStatus = $statuses[$oldReferenceCode] ?? 'unknown';
        $newStatus = $_POST['status'];
        
        // Remove old status entry if reference code changed
        if ($oldReferenceCode !== $newReferenceCode && isset($statuses[$oldReferenceCode])) {
            unset($statuses[$oldReferenceCode]);
        }
        
        $statuses[$newReferenceCode] = $newStatus;
        
        // Save order data
        $jsonData = json_encode($orderData, JSON_PRETTY_PRINT);
        if ($jsonData === false) {
            throw new Exception('Failed to encode order data to JSON');
        }
        
        if ($oldReferenceCode !== $newReferenceCode) {
            // Reference code changed - create new file and remove old one
            $timestamp = time();
            $newFileName = 'uploads/orders/' . $timestamp . '-order.json';
            
            // Make sure new filename doesn't already exist
            while (file_exists($newFileName)) {
                $timestamp++;
                $newFileName = 'uploads/orders/' . $timestamp . '-order.json';
            }
            
            // Write new file first
            if (file_put_contents($newFileName, $jsonData) === false) {
                throw new Exception('Failed to create new order file');
            }
            
            // Update status file
            if (file_put_contents('data/statuses.json', json_encode($statuses, JSON_PRETTY_PRINT)) === false) {
                unlink($newFileName);
                throw new Exception('Failed to update status file');
            }
            
            // Only remove old file after everything else succeeds
            if (file_exists($originalFile)) {
                unlink($originalFile);
            }
        } else {
            // Reference code unchanged - update existing file
            if (file_put_contents($originalFile, $jsonData) === false) {
                throw new Exception('Failed to update existing order file');
            }
            
            // Update status file
            if (file_put_contents('data/statuses.json', json_encode($statuses, JSON_PRETTY_PRINT)) === false) {
                throw new Exception('Failed to update status file');
            }
        }
        
        // Log order edit to history
        logOrderHistory($newReferenceCode, 'edit', 'Order details were edited', 'Admin');
        
        // Send email notification if status changed to shipped/delivered/pickedup
        // DEBUG: Log what's happening
        error_log("EMAIL DEBUG: oldStatus='$oldStatus', newStatus='$newStatus', changed=" . ($oldStatus !== $newStatus ? 'YES' : 'NO'));
        
        if ($oldStatus !== $newStatus && function_exists('sendDispatchNotification')) {
            $emailStatuses = ['shipped', 'delivered', 'pickedup'];
            error_log("EMAIL DEBUG: Checking if '$newStatus' is in emailStatuses: " . (in_array($newStatus, $emailStatuses) ? 'YES' : 'NO'));
            
            if (in_array($newStatus, $emailStatuses)) {
                error_log("EMAIL DEBUG: Calling sendDispatchNotification for $newReferenceCode");
                $emailResult = sendDispatchNotification($orderData, $newStatus, 'Admin');
                error_log("EMAIL DEBUG: sendDispatchNotification returned: " . ($emailResult ? 'TRUE' : 'FALSE'));
            }
        } else {
            if ($oldStatus === $newStatus) {
                error_log("EMAIL DEBUG: Status did not change, skipping email");
            }
            if (!function_exists('sendDispatchNotification')) {
                error_log("EMAIL DEBUG: sendDispatchNotification function not found!");
            }
        }
        
        // Success - redirect back to view mode with new reference code
        header('Location: admin-orders.php?view=' . urlencode($newReferenceCode) . '&updated=1');
        exit;
        
    } catch (Exception $e) {
        // Error occurred - redirect with error message
        error_log('Order save error: ' . $e->getMessage());
        header('Location: admin-orders.php?view=' . urlencode($oldReferenceCode) . '&error=save_failed&message=' . urlencode($e->getMessage()));
        exit;
    }
}

function getEventPrefixForOrder($order) {
    // First check for explicit event code
    if (isset($order['eventCode']) && !empty($order['eventCode'])) {
        return strtoupper($order['eventCode']);
    }
    
    // Extract from reference code as fallback
    if (isset($order['referenceCode'])) {
        $parts = explode('-', $order['referenceCode']);
        if (count($parts) > 1 && !empty($parts[0])) {
            $prefix = strtoupper($parts[0]);
            // Skip generic prefixes, use them as event codes
            return $prefix; // "AAIC" from "AAIC-001", "TECH" from "TECH-001"
        }
    }
    
    // Final fallback
    return 'GEN'; // Generic for orders without clear event association
}

function getEventOrderAnalytics() {
    $analytics = [];
    $orderDir = 'uploads/orders/';
    
    if (!is_dir($orderDir)) {
        return $analytics;
    }
    
    $orderFiles = glob($orderDir . '*.json');
    
    foreach ($orderFiles as $file) {
        $content = file_get_contents($file);
        $orderData = json_decode($content, true);
        
        if (!$orderData || !isset($orderData['referenceCode'])) {
            continue;
        }
        
        // Extract event prefix from reference code (e.g., "FANEXPO" from "FANEXPO-001")
        $parts = explode('-', $orderData['referenceCode']);
        $eventPrefix = strtoupper($parts[0]);
        
        // Initialize event analytics if not exists
        if (!isset($analytics[$eventPrefix])) {
            $analytics[$eventPrefix] = [
                'orderCount' => 0,
                'totalRevenue' => 0.0
            ];
        }
        
        // Increment order count
        $analytics[$eventPrefix]['orderCount']++;
        
        // Add to revenue
        if (isset($orderData['pricing']['total'])) {
            $analytics[$eventPrefix]['totalRevenue'] += (float)$orderData['pricing']['total'];
        }
    }
    
    return $analytics;
}
?>