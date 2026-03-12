<?php
/**
 * Payment Actions API
 * MTCC Print Services
 * 
 * Handles payment-related operations:
 * - Mark as Paid (Cash/E-transfer/Other)
 * - Process Refund (Full/Partial)
 * - Deactivate Payment Link
 */

require_once 'admin-auth.php';
requireAdminLogin();

require_once __DIR__ . '/includes/refund-utilities.php';

header('Content-Type: application/json');

// Get logged-in admin username
$adminUsername = $_SESSION['admin_username'] ?? 'Admin';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

$response = [
    'success' => false,
    'error' => null,
    'data' => null
];

try {
    switch ($action) {
        
        // =========================================================================
        // MARK AS PAID (Non-Stripe Payment)
        // =========================================================================
        case 'mark_paid':
            $referenceCode = $input['referenceCode'] ?? '';
            $paymentMethod = $input['paymentMethod'] ?? '';
            $notes = $input['notes'] ?? '';
            
            if (empty($referenceCode)) {
                throw new Exception('Reference code is required');
            }
            
            if (empty($paymentMethod)) {
                throw new Exception('Payment method is required');
            }
            
            // Use the refund-utilities function (use session username)
            $result = markPaidNonStripe($referenceCode, $paymentMethod, $adminUsername, 'uploads/orders/');
            
            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Failed to mark order as paid');
            }
            
            // Add notes if provided
            if (!empty($notes)) {
                addPaymentNote($referenceCode, $notes, $adminUsername, $paymentMethod);
            }
            
            // Log the action
            logPaymentAction('mark_paid', $referenceCode, [
                'paymentMethod' => $paymentMethod,
                'processedBy' => $adminUsername,
                'notes' => $notes,
                'paymentLinkDeactivated' => $result['payment_link_deactivated']
            ]);
            
            $response['success'] = true;
            $response['data'] = [
                'message' => 'Order marked as paid',
                'paymentMethod' => $paymentMethod,
                'paymentLinkDeactivated' => $result['payment_link_deactivated']
            ];
            break;
            
        // =========================================================================
        // PROCESS REFUND
        // =========================================================================
        case 'process_refund':
            $referenceCode = $input['referenceCode'] ?? '';
            $refundType = $input['refundType'] ?? 'full'; // 'full' or 'partial'
            $refundAmount = isset($input['refundAmount']) ? (float)$input['refundAmount'] : null;
            $reason = $input['reason'] ?? '';
            $notes = $input['notes'] ?? '';
            
            if (empty($referenceCode)) {
                throw new Exception('Reference code is required');
            }
            
            if (empty($reason)) {
                throw new Exception('Refund reason is required');
            }
            
            // Load the order
            $order = findOrder($referenceCode);
            if (!$order) {
                throw new Exception('Order not found: ' . $referenceCode);
            }
            
            // Validate order status
            $currentStatus = getOrderStatus($referenceCode);
            if ($currentStatus === 'refunded') {
                throw new Exception('Order has already been refunded');
            }
            if ($currentStatus === 'cancelled') {
                throw new Exception('Cannot refund a cancelled order');
            }
            if (!in_array($currentStatus, ['paid', 'preflight', 'printing', 'ready_to_ship', 'shipped', 'delivered', 'pickedup'])) {
                throw new Exception('Order must be paid before it can be refunded');
            }
            
            // Determine refund amount
            $orderTotal = (float)($order['pricing']['total'] ?? 0);
            if ($refundType === 'full') {
                $refundAmount = $orderTotal;
            } elseif ($refundAmount === null || $refundAmount <= 0) {
                throw new Exception('Refund amount is required for partial refunds');
            } elseif ($refundAmount > $orderTotal) {
                throw new Exception('Refund amount cannot exceed order total ($' . number_format($orderTotal, 2) . ')');
            }
            
            // Process the refund (use session username)
            $refundResult = processRefund($order, $reason, $refundAmount, $notes, $adminUsername);
            
            if (!$refundResult['success']) {
                throw new Exception($refundResult['error'] ?? 'Failed to process refund');
            }
            
            // Apply refund to order
            $applyResult = applyRefundToOrder($referenceCode, $refundResult['refund_data'], 'uploads/orders/');
            
            if (!$applyResult['success']) {
                throw new Exception($applyResult['error'] ?? 'Failed to save refund data');
            }
            
            // Log the action
            logPaymentAction('refund', $referenceCode, [
                'refundType' => $refundType,
                'refundAmount' => $refundAmount,
                'reason' => $reason,
                'processedBy' => $adminUsername,
                'stripeRefundId' => $refundResult['stripe_refund_id'],
                'notes' => $notes
            ]);
            
            $response['success'] = true;
            $response['data'] = [
                'message' => 'Refund processed successfully',
                'refundAmount' => $refundAmount,
                'refundType' => $refundType,
                'stripeRefundId' => $refundResult['stripe_refund_id']
            ];
            break;
            
        // =========================================================================
        // DEACTIVATE PAYMENT LINK
        // =========================================================================
        case 'deactivate_link':
            $referenceCode = $input['referenceCode'] ?? '';
            $reason = $input['reason'] ?? 'Manual deactivation';
            
            if (empty($referenceCode)) {
                throw new Exception('Reference code is required');
            }
            
            // Load the order
            $order = findOrder($referenceCode);
            if (!$order) {
                throw new Exception('Order not found: ' . $referenceCode);
            }
            
            // Check if there's a payment link
            $paymentLinkId = $order['paymentLink']['paymentLinkId'] ?? null;
            if (empty($paymentLinkId)) {
                throw new Exception('No active payment link found for this order');
            }
            
            // Check if already deactivated
            if (isset($order['paymentLink']['active']) && $order['paymentLink']['active'] === false) {
                throw new Exception('Payment link is already deactivated');
            }
            
            // Deactivate the link
            $deactivateResult = deactivatePaymentLink($paymentLinkId);
            
            if (!$deactivateResult['success']) {
                throw new Exception($deactivateResult['error'] ?? 'Failed to deactivate payment link');
            }
            
            // Update order with deactivation info (use session username)
            updateOrderPaymentLink($referenceCode, [
                'active' => false,
                'deactivatedAt' => date('Y-m-d H:i:s'),
                'deactivatedBy' => $adminUsername,
                'deactivationReason' => $reason
            ]);
            
            // Log the action
            logPaymentAction('deactivate_link', $referenceCode, [
                'paymentLinkId' => $paymentLinkId,
                'processedBy' => $adminUsername,
                'reason' => $reason
            ]);
            
            $response['success'] = true;
            $response['data'] = [
                'message' => 'Payment link deactivated',
                'deactivatedAt' => $deactivateResult['deactivated_at']
            ];
            break;
            
        // =========================================================================
        // GET REFUND REASONS
        // =========================================================================
        case 'get_refund_reasons':
            $response['success'] = true;
            $response['data'] = getRefundReasons();
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action);
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = $e->getMessage();
    error_log('Payment action error: ' . $e->getMessage());
}

echo json_encode($response);
exit;

// =========================================================================
// HELPER FUNCTIONS
// =========================================================================

/**
 * Find an order by reference code
 */
function findOrder($referenceCode) {
    $orderDir = 'uploads/orders/';
    $orderFiles = glob($orderDir . '*-order.json');
    
    foreach ($orderFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($data['referenceCode'] ?? '') === $referenceCode) {
            return $data;
        }
    }
    
    return null;
}

/**
 * Get current order status
 */
function getOrderStatus($referenceCode) {
    $statusFile = 'data/statuses.json';
    if (file_exists($statusFile)) {
        $statuses = json_decode(file_get_contents($statusFile), true) ?: [];
        return $statuses[$referenceCode] ?? 'unpaid';
    }
    return 'unpaid';
}

/**
 * Update order's payment link data
 */
function updateOrderPaymentLink($referenceCode, $updates) {
    $orderDir = 'uploads/orders/';
    $orderFiles = glob($orderDir . '*-order.json');
    
    foreach ($orderFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($data['referenceCode'] ?? '') === $referenceCode) {
            // Merge updates into paymentLink
            if (!isset($data['paymentLink'])) {
                $data['paymentLink'] = [];
            }
            $data['paymentLink'] = array_merge($data['paymentLink'], $updates);
            
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            return true;
        }
    }
    
    return false;
}

/**
 * Add a note about payment action to the order
 */
function addPaymentNote($referenceCode, $notes, $processedBy, $paymentMethod) {
    $orderDir = 'uploads/orders/';
    $orderFiles = glob($orderDir . '*-order.json');
    
    foreach ($orderFiles as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data && ($data['referenceCode'] ?? '') === $referenceCode) {
            // Add to internal notes
            if (!isset($data['internalNotes'])) {
                $data['internalNotes'] = [];
            }
            
            $data['internalNotes'][] = [
                'timestamp' => date('Y-m-d H:i:s'),
                'user' => $processedBy,
                'content' => "Payment marked as received via {$paymentMethod}. " . $notes,
                'type' => 'payment'
            ];
            
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            return true;
        }
    }
    
    return false;
}

/**
 * Log payment action for audit trail
 */
function logPaymentAction($action, $referenceCode, $details) {
    $logFile = __DIR__ . '/logs/payment-actions.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $logEntry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'referenceCode' => $referenceCode,
        'details' => $details,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];
    
    $logLine = json_encode($logEntry) . "\n";
    file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}
