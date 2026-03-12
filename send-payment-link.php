<?php
/**
 * Send Payment Link for Existing Orders
 * Creates a Stripe checkout session for an admin-created order
 * and optionally sends the payment link to the customer via email
 * 
 * Location: /send-payment-link.php (root directory)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Start session
session_start();

// Simple admin check - must be logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Load Stripe
require_once 'vendor/autoload.php';
require_once 'stripe-config.php';

\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    // Get request data
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $data = $_POST;
    }
    
    $referenceCode = $data['referenceCode'] ?? '';
    $sendEmail = isset($data['sendEmail']) ? (bool)$data['sendEmail'] : true;
    
    if (empty($referenceCode)) {
        throw new Exception('Reference code is required');
    }
    
    // Find the order file
    $orderDir = __DIR__ . '/uploads/orders/';
    $orderData = null;
    $orderFile = null;
    
    if (is_dir($orderDir)) {
        $files = glob($orderDir . '*.json');
        foreach ($files as $file) {
            if (strpos($file, '_history.json') !== false) continue;
            
            $content = file_get_contents($file);
            $order = json_decode($content, true);
            
            if ($order && isset($order['referenceCode']) && $order['referenceCode'] === $referenceCode) {
                $orderData = $order;
                $orderFile = $file;
                break;
            }
        }
    }
    
    if (!$orderData) {
        throw new Exception('Order not found: ' . $referenceCode);
    }
    
    // Check order status - should be unpaid
    $statusFile = __DIR__ . '/data/statuses.json';
    $statuses = [];
    if (file_exists($statusFile)) {
        $statuses = json_decode(file_get_contents($statusFile), true) ?? [];
    }
    
    $currentStatus = $statuses[$referenceCode] ?? $orderData['status'] ?? 'unpaid';
    
    // Allow sending payment link for unpaid orders only
    if ($currentStatus === 'paid') {
        throw new Exception('This order has already been paid');
    }
    
    // Get customer info
    $customerEmail = $orderData['email'] ?? $orderData['customerInfo']['email'] ?? null;
    $customerName = $orderData['name'] ?? $orderData['customerInfo']['name'] ?? 'Customer';
    
    if (!$customerEmail) {
        throw new Exception('Customer email not found in order');
    }
    
    // Get pricing info
    $pricing = $orderData['pricing'] ?? [];
    $total = $pricing['total'] ?? 0;
    
    if ($total <= 0) {
        throw new Exception('Invalid order total. Please set pricing before sending payment link.');
    }
    
    // Get event/order details
    $event = $orderData['event'] ?? [];
    $eventName = $event['name'] ?? $orderData['eventName'] ?? 'Print Order';
    $eventAcronym = $event['acronym'] ?? '';
    
    $dimensions = $orderData['dimensions'] ?? [];
    $width = $dimensions['width'] ?? $orderData['width'] ?? '';
    $height = $dimensions['height'] ?? $orderData['height'] ?? '';
    $material = $orderData['material'] ?? 'paper';
    
    // Build line items for Stripe
    $lineItems = [];
    
    $posterDescription = sprintf(
        '%s - %s" x %s" %s Poster',
        $eventName,
        $width,
        $height,
        ucfirst($material)
    );
    
    // Base price
    $basePrice = $pricing['basePrice'] ?? 0;
    if ($basePrice > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'Poster Printing - ' . ($pricing['tier'] ?? 'Standard'),
                    'description' => $posterDescription,
                ],
                'unit_amount' => round($basePrice * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Delivery fee
    $deliveryFee = $pricing['deliveryFee'] ?? 0;
    if ($deliveryFee > 0) {
        $deliveryOption = $orderData['deliveryOption'] ?? $orderData['deliveryMethod'] ?? 'pickup';
        $deliveryLabel = ($deliveryOption === 'office' || $deliveryOption === 'address') ? 'Office Delivery' : 'Convention Delivery';
        
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => $deliveryLabel,
                ],
                'unit_amount' => round($deliveryFee * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Conversion fee
    $conversionFee = $pricing['conversionFee'] ?? 0;
    if ($conversionFee > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'File Conversion Fee',
                ],
                'unit_amount' => round($conversionFee * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Tax
    $tax = $pricing['tax'] ?? 0;
    if ($tax > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'HST (13%)',
                ],
                'unit_amount' => round($tax * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Fallback: single line item with total
    if (empty($lineItems)) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'Poster Printing Order',
                    'description' => 'Order #' . $referenceCode,
                ],
                'unit_amount' => round($total * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Get base URL for redirects
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    
    // Create Stripe Checkout Session
    $checkoutSession = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => $lineItems,
        'mode' => 'payment',
        'customer_email' => $customerEmail,
        'success_url' => $baseUrl . '/payment-success.php?ref=' . urlencode($referenceCode) . '&existing=1',
        'cancel_url' => $baseUrl . '/payment-cancelled.php?ref=' . urlencode($referenceCode),
        'metadata' => [
            'existing_order' => 'true',
            'reference_code' => $referenceCode,
            'event_acronym' => $eventAcronym,
            'event_name' => $eventName,
            'poster_size' => $width . 'x' . $height,
            'material' => $material,
            'customer_name' => $customerName,
        ],
        'expires_at' => time() + (24 * 60 * 60), // Link expires in 24 hours
    ]);
    
    $paymentUrl = $checkoutSession->url;
    
    // Store the session ID for verification (keyed by reference code for existing orders)
    $_SESSION['stripe_payment_' . $referenceCode] = $checkoutSession->id;
    
    // Also store in a file for cross-session verification
    $paymentSessionsFile = __DIR__ . '/data/payment-sessions.json';
    $paymentSessions = [];
    if (file_exists($paymentSessionsFile)) {
        $paymentSessions = json_decode(file_get_contents($paymentSessionsFile), true) ?? [];
    }
    $paymentSessions[$referenceCode] = [
        'sessionId' => $checkoutSession->id,
        'createdAt' => date('Y-m-d H:i:s'),
        'expiresAt' => date('Y-m-d H:i:s', time() + (24 * 60 * 60)),
    ];
    file_put_contents($paymentSessionsFile, json_encode($paymentSessions, JSON_PRETTY_PRINT));
    
    // Update order with payment link info
    $orderData['paymentLink'] = [
        'stripeSessionId' => $checkoutSession->id,
        'url' => $paymentUrl,
        'createdAt' => date('Y-m-d H:i:s'),
        'expiresAt' => date('Y-m-d H:i:s', time() + (24 * 60 * 60)),
    ];
    
    file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT));
    
    // Log to order history
    logOrderHistory($referenceCode, 'payment_link_created', 'Payment link generated');
    
    // Send email if requested
    $emailSent = false;
    if ($sendEmail) {
        $emailSent = sendPaymentLinkEmail($orderData, $paymentUrl, $referenceCode);
        if ($emailSent) {
            logOrderHistory($referenceCode, 'payment_link_sent', "Payment link emailed to {$customerEmail}");
        }
    }
    
    echo json_encode([
        'success' => true,
        'paymentUrl' => $paymentUrl,
        'sessionId' => $checkoutSession->id,
        'emailSent' => $emailSent,
        'expiresAt' => date('Y-m-d H:i:s', time() + (24 * 60 * 60)),
        'message' => $sendEmail 
            ? ($emailSent ? "Payment link sent to {$customerEmail}" : "Payment link created but email failed")
            : "Payment link created"
    ]);
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Payment service error: ' . $e->getMessage()
    ]);
    error_log('Stripe API Error (Payment Link): ' . $e->getMessage());
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log('Payment Link Error: ' . $e->getMessage());
}

/**
 * Log activity to order history
 */
function logOrderHistory($referenceCode, $action, $details) {
    $historyFile = __DIR__ . '/uploads/orders/' . $referenceCode . '_history.json';
    
    $history = [];
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true) ?: [];
    }
    
    $history[] = [
        'id' => uniqid('hist_'),
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action,
        'details' => $details,
        'user' => 'Admin'
    ];
    
    file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
}

/**
 * Send payment link email to customer
 */
function sendPaymentLinkEmail($orderData, $paymentUrl, $referenceCode) {
    $customerEmail = $orderData['email'] ?? $orderData['customerInfo']['email'] ?? null;
    $customerName = $orderData['name'] ?? $orderData['customerInfo']['name'] ?? 'Customer';
    
    if (!$customerEmail) {
        return false;
    }
    
    // Get order details
    $pricing = $orderData['pricing'] ?? [];
    $total = number_format($pricing['total'] ?? 0, 2);
    $event = $orderData['event'] ?? [];
    $eventName = $event['name'] ?? $orderData['eventName'] ?? '';
    
    $dimensions = $orderData['dimensions'] ?? [];
    $width = $dimensions['width'] ?? $orderData['width'] ?? '';
    $height = $dimensions['height'] ?? $orderData['height'] ?? '';
    
    $currentYear = date('Y');
    
    $subject = "Payment Required - Order {$referenceCode}";
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Required</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

    <!-- Header -->
    <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); border-radius: 12px 12px 0 0;">
    <tr>
    <td style="padding: 30px; text-align: center;">
        <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Payment Required</div>
        <div style="color: #ddd6fe; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
    </td>
    </tr>
    </table>

    <!-- Content -->
    <table cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
    <td style="padding: 30px;">
        
        <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
            Hi ' . htmlspecialchars($customerName) . ',
        </p>
        
        <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
            Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> is ready for payment.
        </p>
        
        <!-- Order Summary Box -->
        <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; margin: 20px 0;">
        <tr>
        <td style="padding: 20px;">
            <div style="color: #374151; font-size: 14px; font-weight: 600; margin-bottom: 12px;">Order Summary</div>
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Order #:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . htmlspecialchars($referenceCode) . '</td>
            </tr>';
    
    if ($eventName) {
        $html .= '
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Event:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . htmlspecialchars($eventName) . '</td>
            </tr>';
    }
    
    if ($width && $height) {
        $html .= '
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Size:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . htmlspecialchars($width) . '" x ' . htmlspecialchars($height) . '"</td>
            </tr>';
    }
    
    $html .= '
            <tr>
                <td style="color: #7c3aed; font-size: 16px; font-weight: 700; padding: 12px 0 4px 0; border-top: 1px solid #e5e7eb;">Total:</td>
                <td style="color: #7c3aed; font-size: 16px; font-weight: 700; padding: 12px 0 4px 0; text-align: right; border-top: 1px solid #e5e7eb;">$' . $total . ' CAD</td>
            </tr>
            </table>
        </td>
        </tr>
        </table>
        
        <!-- Pay Now Button -->
        <table cellpadding="0" cellspacing="0" style="width: 100%; margin: 30px 0;">
        <tr>
        <td style="text-align: center;">
            <a href="' . htmlspecialchars($paymentUrl) . '" style="display: inline-block; background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 16px 40px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 16px;">Pay Now - $' . $total . '</a>
        </td>
        </tr>
        </table>
        
        <p style="color: #6b7280; font-size: 13px; text-align: center; margin: 20px 0;">
            This payment link expires in 24 hours.
        </p>
        
        <p style="color: #374151; font-size: 14px; line-height: 1.6; margin: 20px 0;">
            Once payment is received, we will begin processing your order right away.
        </p>
        
    </td>
    </tr>
    </table>
    
    <!-- Footer -->
    <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
    <tr>
    <td style="padding: 20px; text-align: center;">
        <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact us at <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a></div>
        <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $currentYear . ' Print Stuff - Professional Poster Printing</div>
    </td>
    </tr>
    </table>

</td>
</tr>
</table>

</body>
</html>';
    
    // Try to use dispatch email functions if available
    if (file_exists(__DIR__ . '/email-status-notifications.php')) {
        require_once __DIR__ . '/email-status-notifications.php';
        if (function_exists('sendEmailSMTP')) {
            return sendEmailSMTP($customerEmail, $subject, $html, $referenceCode, 'orders');
        }
    }
    
    // Fallback to mail()
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Print Stuff Orders <orders@printstuff.ca>',
        'Reply-To: orders@printstuff.ca',
    ];
    
    $sent = mail($customerEmail, $subject, $html, implode("\r\n", $headers));
    
    if ($sent) {
        error_log("Payment Link Email: Sent to {$customerEmail} for {$referenceCode}");
    } else {
        error_log("Payment Link Email: Failed for {$referenceCode}");
    }
    
    return $sent;
}
