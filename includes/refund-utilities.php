<?php
/**
 * Refund Utilities
 * MTCC Print Services - Refund Processing Functions
 * 
 * This file provides functions for processing refunds with the robust refund structure.
 * Works with Stripe API for payment link deactivation and refund processing.
 */

require_once __DIR__ . '/../stripe-config.php';

// Initialize Stripe if not already done
if (!class_exists('\Stripe\Stripe')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

/**
 * Refund reason options
 */
define('REFUND_REASONS', [
    'customer_request' => 'Customer Request',
    'duplicate_order' => 'Duplicate Order',
    'print_quality' => 'Print Quality Issue',
    'file_issue' => 'File Issue',
    'late_delivery' => 'Late Delivery',
    'damaged' => 'Damaged',
    'other' => 'Other'
]);

/**
 * Process a refund for an order
 * 
 * @param array $order The order data
 * @param string $reason Refund reason key
 * @param float|null $amount Refund amount (null for full refund)
 * @param string $notes Additional notes
 * @param string $processedBy User who processed the refund
 * @return array Result with success status and refund data
 */
function processRefund($order, $reason, $amount = null, $notes = '', $processedBy = 'Admin') {
    $result = [
        'success' => false,
        'error' => null,
        'refund_data' => null,
        'stripe_refund_id' => null
    ];
    
    try {
        // Validate reason
        if (!array_key_exists($reason, REFUND_REASONS)) {
            throw new Exception('Invalid refund reason');
        }
        
        // Determine refund amount
        $orderTotal = (float)($order['pricing']['total'] ?? 0);
        $refundAmount = $amount ?? $orderTotal;
        $refundType = ($refundAmount >= $orderTotal) ? 'full' : 'partial';
        
        if ($refundAmount <= 0) {
            throw new Exception('Refund amount must be greater than 0');
        }
        
        if ($refundAmount > $orderTotal) {
            throw new Exception('Refund amount cannot exceed order total');
        }
        
        // Process Stripe refund if payment was made through Stripe
        $stripeRefundId = null;
        if (!empty($order['stripePaymentIntent'])) {
            try {
                $stripeRefund = \Stripe\Refund::create([
                    'payment_intent' => $order['stripePaymentIntent'],
                    'amount' => (int)($refundAmount * 100), // Stripe uses cents
                    'reason' => mapRefundReasonToStripe($reason)
                ]);
                $stripeRefundId = $stripeRefund->id;
            } catch (\Stripe\Exception\ApiErrorException $e) {
                // Log error but continue - refund may need manual processing
                error_log('Stripe refund error for order ' . $order['referenceCode'] . ': ' . $e->getMessage());
                $notes .= ' [Stripe refund failed: ' . $e->getMessage() . ']';
            }
        }
        
        // Build refund data structure
        $refundData = [
            'refundedAt' => date('Y-m-d H:i:s'),
            'refundAmount' => $refundAmount,
            'refundType' => $refundType,
            'refundReason' => $reason,
            'refundReasonLabel' => REFUND_REASONS[$reason],
            'refundedBy' => $processedBy,
            'stripeRefundId' => $stripeRefundId,
            'originalTotal' => $orderTotal,
            'notes' => $notes
        ];
        
        $result['success'] = true;
        $result['refund_data'] = $refundData;
        $result['stripe_refund_id'] = $stripeRefundId;
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Map our refund reasons to Stripe's accepted reasons
 * 
 * @param string $reason Our refund reason key
 * @return string Stripe refund reason
 */
function mapRefundReasonToStripe($reason) {
    $mapping = [
        'customer_request' => 'requested_by_customer',
        'duplicate_order' => 'duplicate',
        'print_quality' => 'requested_by_customer',
        'file_issue' => 'requested_by_customer',
        'late_delivery' => 'requested_by_customer',
        'damaged' => 'requested_by_customer',
        'other' => 'requested_by_customer'
    ];
    
    return $mapping[$reason] ?? 'requested_by_customer';
}

/**
 * Deactivate a Stripe payment link
 * 
 * @param string $paymentLinkId Stripe payment link ID
 * @return array Result with success status
 */
function deactivatePaymentLink($paymentLinkId) {
    $result = [
        'success' => false,
        'error' => null
    ];
    
    if (empty($paymentLinkId)) {
        $result['error'] = 'No payment link ID provided';
        return $result;
    }
    
    try {
        $paymentLink = \Stripe\PaymentLink::update($paymentLinkId, [
            'active' => false
        ]);
        
        $result['success'] = true;
        $result['deactivated_at'] = date('Y-m-d H:i:s');
        
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $result['error'] = $e->getMessage();
        error_log('Failed to deactivate payment link ' . $paymentLinkId . ': ' . $e->getMessage());
    }
    
    return $result;
}

/**
 * Update order with refund data and change status
 * 
 * @param string $referenceCode Order reference code
 * @param array $refundData Refund data structure
 * @param string $orderDir Path to orders directory
 * @return array Result with success status
 */
function applyRefundToOrder($referenceCode, $refundData, $orderDir = 'uploads/orders/') {
    $result = [
        'success' => false,
        'error' => null
    ];
    
    try {
        // Find the order file
        $orderFiles = glob($orderDir . '*-order.json');
        $orderFile = null;
        $orderData = null;
        
        foreach ($orderFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($data['referenceCode'] ?? '') === $referenceCode) {
                $orderFile = $file;
                $orderData = $data;
                break;
            }
        }
        
        if (!$orderFile || !$orderData) {
            throw new Exception('Order not found: ' . $referenceCode);
        }
        
        // Add refund data to order
        $orderData['refund'] = $refundData;
        
        // Save order
        if (file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to save order file');
        }
        
        // Update status to refunded
        $statusFile = 'data/statuses.json';
        $statuses = [];
        if (file_exists($statusFile)) {
            $statuses = json_decode(file_get_contents($statusFile), true) ?: [];
        }
        
        $statuses[$referenceCode] = 'refunded';
        
        if (file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to update status file');
        }
        
        $result['success'] = true;
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Mark an order as paid with a non-Stripe payment method
 * This will deactivate any existing payment link
 * 
 * @param string $referenceCode Order reference code
 * @param string $paymentMethod Payment method (cash, etransfer, other)
 * @param string $processedBy User who processed the payment
 * @param string $orderDir Path to orders directory
 * @return array Result with success status
 */
function markPaidNonStripe($referenceCode, $paymentMethod, $processedBy = 'Admin', $orderDir = 'uploads/orders/') {
    $result = [
        'success' => false,
        'error' => null,
        'payment_link_deactivated' => false
    ];
    
    $validMethods = ['cash', 'etransfer', 'other'];
    if (!in_array($paymentMethod, $validMethods)) {
        $result['error'] = 'Invalid payment method';
        return $result;
    }
    
    try {
        // Find the order file
        $orderFiles = glob($orderDir . '*-order.json');
        $orderFile = null;
        $orderData = null;
        
        foreach ($orderFiles as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($data['referenceCode'] ?? '') === $referenceCode) {
                $orderFile = $file;
                $orderData = $data;
                break;
            }
        }
        
        if (!$orderFile || !$orderData) {
            throw new Exception('Order not found: ' . $referenceCode);
        }
        
        // Deactivate existing payment link if present
        if (!empty($orderData['paymentLink']['paymentLinkId'])) {
            $deactivateResult = deactivatePaymentLink($orderData['paymentLink']['paymentLinkId']);
            $result['payment_link_deactivated'] = $deactivateResult['success'];
            
            // Update payment link status in order
            $orderData['paymentLink']['active'] = false;
            $orderData['paymentLink']['deactivatedAt'] = date('Y-m-d H:i:s');
            $orderData['paymentLink']['deactivationReason'] = 'Marked paid via ' . $paymentMethod;
        }
        
        // Update order with payment info
        $orderData['paymentMethod'] = $paymentMethod;
        $orderData['paidAt'] = date('Y-m-d H:i:s');
        $orderData['paidBy'] = $processedBy;
        
        // Save order
        if (file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to save order file');
        }
        
        // Update status to paid
        $statusFile = 'data/statuses.json';
        $statuses = [];
        if (file_exists($statusFile)) {
            $statuses = json_decode(file_get_contents($statusFile), true) ?: [];
        }
        
        $statuses[$referenceCode] = 'paid';
        
        if (file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT)) === false) {
            throw new Exception('Failed to update status file');
        }
        
        $result['success'] = true;
        
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }
    
    return $result;
}

/**
 * Get refund reason options for dropdown
 * 
 * @return array Refund reasons
 */
function getRefundReasons() {
    return REFUND_REASONS;
}

/**
 * Validate refund data structure
 * 
 * @param array $refundData Refund data to validate
 * @return bool Whether the data is valid
 */
function validateRefundData($refundData) {
    $required = ['refundedAt', 'refundAmount', 'refundType', 'refundReason', 'refundedBy'];
    
    foreach ($required as $field) {
        if (!isset($refundData[$field]) || empty($refundData[$field])) {
            return false;
        }
    }
    
    if (!array_key_exists($refundData['refundReason'], REFUND_REASONS)) {
        return false;
    }
    
    if (!in_array($refundData['refundType'], ['full', 'partial'])) {
        return false;
    }
    
    return true;
}
