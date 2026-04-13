<?php
/**
 * Payment Success Handler (Updated)
 * This file processes the order after successful Stripe payment
 * 
 * Supports:
 * - New orders (sid parameter) - existing flow
 * - Existing orders (ref parameter with existing=1) - admin-created orders
 * 
 * Location: /payment-success.php (root directory)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// Load Stripe
require_once 'vendor/autoload.php';
require_once 'stripe-config.php';

// Set Stripe API key
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Start session to retrieve pending order data
session_start();

$error = null;
$orderRef = null;
$paymentVerified = false;

try {
    // Check if this is an existing order payment (admin-created)
    $existingOrderRef = $_GET['ref'] ?? null;
    $isExistingOrder = isset($_GET['existing']) && $_GET['existing'] == '1';
    
    if ($existingOrderRef && $isExistingOrder) {
        // ============================================================
        // EXISTING ORDER PAYMENT FLOW
        // ============================================================
        
        $orderRef = $existingOrderRef;
        
        // Get the Stripe session ID - check multiple sources
        $sessionId = $_SESSION['stripe_payment_' . $orderRef] ?? null;
        
        // Also check the file-based storage
        if (!$sessionId) {
            $paymentSessionsFile = __DIR__ . '/data/payment-sessions.json';
            if (file_exists($paymentSessionsFile)) {
                $paymentSessions = json_decode(file_get_contents($paymentSessionsFile), true) ?? [];
                if (isset($paymentSessions[$orderRef])) {
                    $sessionId = $paymentSessions[$orderRef]['sessionId'];
                }
            }
        }
        
        if (!$sessionId) {
            throw new Exception('Payment session not found. Please request a new payment link.');
        }
        
        // Verify payment with Stripe
        $session = \Stripe\Checkout\Session::retrieve($sessionId);
        
        if ($session->payment_status !== 'paid') {
            throw new Exception('Payment not completed');
        }
        
        $paymentVerified = true;
        
        // Find and update the order file
        $orderDir = __DIR__ . '/uploads/orders/';
        $orderData = null;
        $orderFile = null;
        
        if (is_dir($orderDir)) {
            $files = glob($orderDir . '*.json');
            foreach ($files as $file) {
                if (strpos($file, '_history.json') !== false) continue;
                
                $content = file_get_contents($file);
                $order = json_decode($content, true);
                
                if ($order && isset($order['referenceCode']) && $order['referenceCode'] === $orderRef) {
                    $orderData = $order;
                    $orderFile = $file;
                    break;
                }
            }
        }
        
        if (!$orderData || !$orderFile) {
            throw new Exception('Order not found');
        }
        
        // Update order with payment info
        $orderData['stripeSessionId'] = $sessionId;
        $orderData['stripePaymentStatus'] = 'paid';
        $orderData['stripePaymentIntent'] = $session->payment_intent;
        $orderData['paidAt'] = date('Y-m-d H:i:s');
        $orderData['paymentMethod'] = 'stripe';
        
        // Remove payment link info (no longer needed)
        unset($orderData['paymentLink']);
        
        // Save updated order
        file_put_contents($orderFile, json_encode($orderData, JSON_PRETTY_PRINT));
        
        // Update status to paid
        $statusFile = __DIR__ . '/data/statuses.json';
        $statuses = [];
        if (file_exists($statusFile)) {
            $statuses = json_decode(file_get_contents($statusFile), true) ?? [];
        }
        $statuses[$orderRef] = 'paid';
        file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Log to history
        $historyFile = $orderDir . $orderRef . '_history.json';
        $history = [];
        if (file_exists($historyFile)) {
            $history = json_decode(file_get_contents($historyFile), true) ?: [];
        }
        $history[] = [
            'id' => uniqid('hist_'),
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => 'payment_received',
            'details' => 'Payment received via Stripe',
            'user' => 'Customer'
        ];
        file_put_contents($historyFile, json_encode($history, JSON_PRETTY_PRINT));
        
        // Send confirmation emails
        try {
            sendOrderEmails($orderData);
        } catch (Exception $e) {
            error_log("Email error for order $orderRef: " . $e->getMessage());
        }
        
        // Clean up session data
        unset($_SESSION['stripe_payment_' . $orderRef]);
        
        // Clean up file-based session
        $paymentSessionsFile = __DIR__ . '/data/payment-sessions.json';
        if (file_exists($paymentSessionsFile)) {
            $paymentSessions = json_decode(file_get_contents($paymentSessionsFile), true) ?? [];
            unset($paymentSessions[$orderRef]);
            file_put_contents($paymentSessionsFile, json_encode($paymentSessions, JSON_PRETTY_PRINT));
        }
        
    } else {
        // ============================================================
        // NEW ORDER PAYMENT FLOW (existing behavior)
        // ============================================================
        
        // Get our temp reference from URL (short parameter to avoid ModSecurity)
        $tempRef = $_GET['sid'] ?? null;
        
        if (!$tempRef) {
            throw new Exception('No order reference found');
        }
        
        // Get the Stripe session ID from our PHP session (stored during checkout creation)
        $sessionId = $_SESSION['stripe_session_' . $tempRef] ?? null;
        
        if (!$sessionId) {
            throw new Exception('Payment session expired. Please try again.');
        }
        
        // Retrieve the Stripe session to verify payment
        $session = \Stripe\Checkout\Session::retrieve($sessionId);
        
        if ($session->payment_status !== 'paid') {
            throw new Exception('Payment not completed');
        }
        
        $paymentVerified = true;
        
        // Get the pending order data from session
        $pendingOrder = $_SESSION['pending_order_' . $tempRef] ?? null;
        
        if (!$pendingOrder) {
            // Try to reconstruct from Stripe metadata
            $orderRef = 'PAID-' . substr($sessionId, -8);
            error_log("Warning: Pending order data not found for $tempRef, using fallback reference");
        } else {
            // Process the order with the stored data
            $orderDetails = $pendingOrder['orderDetails'];
            $customerInfo = $pendingOrder['customerInfo'];
            $pricing = $pendingOrder['pricing'];
            $event = $pendingOrder['event'];
            $tempFileInfo = $pendingOrder['uploadedFile'] ?? null;
            
            // Generate the real reference code
            $orderRef = generateReferenceCode($event['acronym'] ?? 'ORD');
            
            // Handle file - move from temp to permanent location
            $finalFileInfo = null;
            if ($tempFileInfo && !empty($tempFileInfo['tempPath'])) {
                $tempFullPath = __DIR__ . '/' . $tempFileInfo['tempPath'];
                
                if (file_exists($tempFullPath)) {
                    // Create permanent upload directory
                    $filesDir = __DIR__ . '/uploads/files/';
                    if (!is_dir($filesDir)) {
                        mkdir($filesDir, 0755, true);
                    }
                    
                    // Generate permanent filename: OrderRef_WidthxHeight_OriginalName
                    $originalName = $tempFileInfo['originalName'];
                    $width = intval($orderDetails['width'] ?? 0);
                    $height = intval($orderDetails['height'] ?? 0);
                    
                    // Sanitize original filename (remove problematic characters but keep it readable)
                    $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
                    $safeName = preg_replace('/_+/', '_', $safeName); // Collapse multiple underscores
                    
                    $newFileName = $orderRef . '_' . $width . 'x' . $height . '_' . $safeName;
                    $permanentPath = $filesDir . $newFileName;
                    
                    // Move file
                    if (rename($tempFullPath, $permanentPath)) {
                        $finalFileInfo = [
                            'originalName' => $tempFileInfo['originalName'],
                            'path' => 'uploads/files/' . $newFileName,
                            'size' => $tempFileInfo['size'],
                            'type' => $tempFileInfo['type'],
                        ];
                    } else {
                        error_log("Failed to move temp file from $tempFullPath to $permanentPath");
                    }
                } else {
                    error_log("Temp file not found: $tempFullPath");
                }
            }
            
            // Build the complete order data
            $orderData = [
                'referenceCode' => $orderRef,
                'stripeSessionId' => $sessionId,
                'stripePaymentStatus' => 'paid',
                'stripePaymentIntent' => $session->payment_intent,
                'event' => [
                    'acronym' => $event['acronym'] ?? '',
                    'name' => $event['name'] ?? ''
                ],
                'dimensions' => [
                    'width' => (float)($orderDetails['width'] ?? 0),
                    'height' => (float)($orderDetails['height'] ?? 0)
                ],
                'material' => $orderDetails['material'] ?? 'paper',
                'selectedDate' => $orderDetails['selectedDate'] ?? '',
                'deliveryTime' => $orderDetails['deliveryTime'] ?? 'anytime',
                'deliveryOption' => $orderDetails['deliveryOption'] ?? 'pickup',
                'customerInfo' => [
                    'name' => $customerInfo['name'] ?? '',
                    'company' => $customerInfo['company'] ?? '',
                    'email' => $customerInfo['email'] ?? '',
                    'phone' => $customerInfo['phone'] ?? '',
                    'countryCode' => $customerInfo['countryCode'] ?? '',
                    'additionalNotes' => $customerInfo['additionalNotes'] ?? ''
                ],
                'pricing' => [
                    'basePrice' => (float)($pricing['basePrice'] ?? 0),
                    'deliveryFee' => (float)($pricing['deliveryFee'] ?? 0),
                    'subtotal' => (float)($pricing['subtotal'] ?? 0),
                    'tax' => (float)($pricing['tax'] ?? 0),
                    'total' => (float)($pricing['total'] ?? 0),
                    'tier' => $pricing['tier'] ?? ''
                ],
                'submittedAt' => date('Y-m-d H:i:s'),
                'paidAt' => date('Y-m-d H:i:s'),
                'paymentMethod' => 'stripe',
                'version' => 'v1-stripe-checkout'
            ];
            
            // Add uploaded file info
            if ($finalFileInfo) {
                $orderData['uploadedFile'] = $finalFileInfo;
            }
            
            // Add delivery address if applicable
            if (isset($orderDetails['deliveryAddress'])) {
                $orderData['deliveryAddress'] = $orderDetails['deliveryAddress'];
            }
            
            // Save order data
            $ordersDir = __DIR__ . '/uploads/orders/';
            if (!is_dir($ordersDir)) {
                mkdir($ordersDir, 0755, true);
            }
            
            $orderFileName = $ordersDir . $orderRef . '_' . date('Y-m-d_H-i-s') . '-order.json';
            file_put_contents($orderFileName, json_encode($orderData, JSON_PRETTY_PRINT));
            
            // Update status
            $statusFile = __DIR__ . '/data/statuses.json';
            $statuses = [];
            if (file_exists($statusFile)) {
                $statuses = json_decode(file_get_contents($statusFile), true) ?? [];
            }
            $statuses[$orderRef] = 'paid';
            file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
            
            // Send confirmation emails
            try {
                sendOrderEmails($orderData);
            } catch (Exception $e) {
                error_log("Email error for order $orderRef: " . $e->getMessage());
            }
            
            // Clear the pending order and Stripe session from PHP session
            unset($_SESSION['pending_order_' . $tempRef]);
            unset($_SESSION['stripe_session_' . $tempRef]);
        }
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
    error_log('Payment Success Error: ' . $e->getMessage());
}

// Helper function to generate reference code
function generateReferenceCode($eventAcronym) {
    $counterFile = __DIR__ . '/data/order_counter.txt';
    
    $counters = [];
    if (file_exists($counterFile) && is_readable($counterFile)) {
        $content = file_get_contents($counterFile);
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $counters = $decoded;
            }
        }
    }
    
    $currentCount = isset($counters[$eventAcronym]) ? (int)$counters[$eventAcronym] : 0;
    $newCount = $currentCount + 1;
    
    $referenceCode = $eventAcronym . '-' . str_pad($newCount, 3, '0', STR_PAD_LEFT);
    
    $counters[$eventAcronym] = $newCount;
    file_put_contents($counterFile, json_encode($counters, JSON_PRETTY_PRINT));
    
    return $referenceCode;
}

// Helper function to format bytes
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Email function - sends both customer and business notifications
function sendOrderEmails($orderData) {
    $fromEmail = 'orders@printstuff.ca';
    $fromName = 'Print Stuff Orders';
    
    // Get customer email - handle both formats
    $customerEmail = $orderData['customerInfo']['email'] ?? $orderData['email'] ?? null;
    if (!$customerEmail) {
        error_log("No customer email found for order " . ($orderData['referenceCode'] ?? 'unknown'));
        return false;
    }
    
    $orderRef = $orderData['referenceCode'];
    $businessEmail = 'orders@printstuff.ca';
    
    // Common headers for both emails
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        "From: {$fromName} <{$fromEmail}>",
        "Reply-To: {$fromEmail}",
        'X-Mailer: PHP/' . phpversion()
    ];
    $headerString = implode("\r\n", $headers);
    
    // Send customer email
    $customerSubject = "Payment Confirmed: Order #{$orderRef}";
    $customerMessage = generateCustomerEmailHTML($orderData);
    
    error_log("Attempting to send customer email for order $orderRef to $customerEmail");
    $customerSent = @mail($customerEmail, $customerSubject, $customerMessage, $headerString);
    
    if (!$customerSent) {
        error_log("FAILED to send customer email for order $orderRef to $customerEmail");
    } else {
        error_log("SUCCESS: Customer email sent for order $orderRef to $customerEmail");
    }
    
    // Send business notification
    $eventName = $orderData['event']['name'] ?? $orderData['eventName'] ?? 'Event';
    $tier = $orderData['pricing']['tier'] ?? 'Standard';
    $businessSubject = "Poster Order: {$tier} - {$orderRef} - {$eventName}";
    $businessMessage = generateBusinessEmailHTML($orderData);
    
    error_log("Attempting to send business email for order $orderRef to $businessEmail");
    $businessSent = @mail($businessEmail, $businessSubject, $businessMessage, $headerString);
    
    if (!$businessSent) {
        error_log("FAILED to send business notification for order $orderRef to $businessEmail");
    } else {
        error_log("SUCCESS: Business notification sent for order $orderRef");
    }
    
    return $customerSent && $businessSent;
}

function generateCustomerEmailHTML($orderData) {
    $ref = htmlspecialchars($orderData['referenceCode']);
    $total = number_format($orderData['pricing']['total'] ?? 0, 2);
    $name = htmlspecialchars($orderData['customerInfo']['name'] ?? $orderData['name'] ?? 'Customer');
    $eventName = htmlspecialchars($orderData['event']['name'] ?? $orderData['eventName'] ?? 'Event');
    
    $dimensions = $orderData['dimensions'] ?? [];
    $width = $dimensions['width'] ?? $orderData['width'] ?? '0';
    $height = $dimensions['height'] ?? $orderData['height'] ?? '0';
    $material = ucfirst($orderData['material'] ?? 'Paper');
    $tier = htmlspecialchars($orderData['pricing']['tier'] ?? 'Standard');
    
    $selectedDate = $orderData['selectedDate'] ?? $orderData['dueDate'] ?? date('Y-m-d');
    $deliveryDate = date('l, F j, Y', strtotime($selectedDate));
    $deliveryTimeValue = $orderData['deliveryTime'] ?? 'anytime';
    $deliveryTimeLabels = ['anytime' => 'Anytime', '9am' => '9:00 AM', '12pm' => '12:00 PM', '3pm' => '3:00 PM', '6pm' => '6:00 PM'];
    $deliveryTimeDisplay = $deliveryTimeLabels[$deliveryTimeValue] ?? 'Anytime';
    
    $basePrice = number_format($orderData['pricing']['basePrice'] ?? 0, 2);
    $deliveryFee = number_format($orderData['pricing']['deliveryFee'] ?? 0, 2);
    $tax = number_format($orderData['pricing']['tax'] ?? 0, 2);
    
    $deliveryOption = $orderData['deliveryOption'] ?? $orderData['deliveryMethod'] ?? 'pickup';
    $deliveryMethod = ($deliveryOption === 'mtcc' || $deliveryOption === 'pickup') 
        ? 'MTCC Pickup (Free)' 
        : 'Address Delivery (+$10.00)';
    
    $currentYear = date('Y');
    
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Confirmed</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

    <!-- Header -->
    <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px 12px 0 0;">
    <tr>
    <td style="padding: 30px; text-align: center;">
        <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Payment Confirmed</div>
        <div style="color: #a7f3d0; font-size: 14px;">Order ' . $ref . '</div>
    </td>
    </tr>
    </table>

    <!-- Content -->
    <table cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
    <td style="padding: 30px;">
        
        <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
            Hi ' . $name . ',
        </p>
        
        <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 20px 0;">
            Thank you for your payment! Your poster order <strong style="color: #7c3aed;">' . $ref . '</strong> is confirmed and being processed.
        </p>
        
        <!-- Order Summary Box -->
        <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; margin: 20px 0;">
        <tr>
        <td style="padding: 20px;">
            <div style="color: #374151; font-size: 14px; font-weight: 600; margin-bottom: 12px;">Order Summary</div>
            <table cellpadding="0" cellspacing="0" style="width: 100%;">
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Order #:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . $ref . '</td>
            </tr>
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Event:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . $eventName . '</td>
            </tr>
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Size:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . $width . '" x ' . $height . '"</td>
            </tr>
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Material:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . $material . '</td>
            </tr>
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Priority:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . $tier . '</td>
            </tr>
            <tr>
                <td style="color: #6b7280; font-size: 13px; padding: 4px 0;">Due Date:</td>
                <td style="color: #374151; font-size: 13px; padding: 4px 0; text-align: right;">' . $deliveryDate . '</td>
            </tr>
            <tr>
                <td style="color: #10b981; font-size: 16px; font-weight: 700; padding: 12px 0 4px 0; border-top: 1px solid #e5e7eb;">Total Paid:</td>
                <td style="color: #10b981; font-size: 16px; font-weight: 700; padding: 12px 0 4px 0; text-align: right; border-top: 1px solid #e5e7eb;">$' . $total . ' CAD</td>
            </tr>
            </table>
        </td>
        </tr>
        </table>
        
        <p style="color: #374151; font-size: 14px; line-height: 1.6; margin: 20px 0;">
            We will notify you when your order is ready for pickup or has been shipped.
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
}

function generateBusinessEmailHTML($orderData) {
    $ref = htmlspecialchars($orderData['referenceCode']);
    $total = number_format($orderData['pricing']['total'] ?? 0, 2);
    $name = htmlspecialchars($orderData['customerInfo']['name'] ?? $orderData['name'] ?? 'Customer');
    $email = htmlspecialchars($orderData['customerInfo']['email'] ?? $orderData['email'] ?? '');
    $phone = htmlspecialchars($orderData['customerInfo']['phone'] ?? $orderData['phone'] ?? '');
    $eventName = htmlspecialchars($orderData['event']['name'] ?? $orderData['eventName'] ?? 'Event');
    
    $dimensions = $orderData['dimensions'] ?? [];
    $width = $dimensions['width'] ?? $orderData['width'] ?? '0';
    $height = $dimensions['height'] ?? $orderData['height'] ?? '0';
    $material = ucfirst($orderData['material'] ?? 'Paper');
    $tier = htmlspecialchars($orderData['pricing']['tier'] ?? 'Standard');
    
    $selectedDate = $orderData['selectedDate'] ?? $orderData['dueDate'] ?? date('Y-m-d');
    $deliveryDate = date('l, F j, Y', strtotime($selectedDate));
    
    $currentYear = date('Y');
    
    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Order</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #f3f4f6;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px;">
<tr>
<td style="padding: 20px;">
    
    <h2 style="color: #7c3aed; margin: 0 0 20px 0;">New Order: ' . $ref . '</h2>
    
    <table cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
        <td style="padding: 8px 0; color: #6b7280; width: 120px;">Customer:</td>
        <td style="padding: 8px 0; color: #374151;">' . $name . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Email:</td>
        <td style="padding: 8px 0; color: #374151;">' . $email . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Phone:</td>
        <td style="padding: 8px 0; color: #374151;">' . $phone . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Event:</td>
        <td style="padding: 8px 0; color: #374151;">' . $eventName . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Size:</td>
        <td style="padding: 8px 0; color: #374151;">' . $width . '" x ' . $height . '" ' . $material . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Priority:</td>
        <td style="padding: 8px 0; color: #374151;">' . $tier . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #6b7280;">Due Date:</td>
        <td style="padding: 8px 0; color: #374151;">' . $deliveryDate . '</td>
    </tr>
    <tr>
        <td style="padding: 8px 0; color: #10b981; font-weight: 700;">Total:</td>
        <td style="padding: 8px 0; color: #10b981; font-weight: 700;">$' . $total . ' CAD</td>
    </tr>
    </table>
    
    <p style="margin-top: 20px;">
        <a href="https://mtcc.print-stuff.ca/admin-orders.php?view=' . $ref . '" style="display: inline-block; background: #7c3aed; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none;">View Order</a>
    </p>
    
</td>
</tr>
</table>

</body>
</html>';
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $paymentVerified ? 'Payment Successful' : 'Payment Issue'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #faf8ff 0%, #ede9fe 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        /* ── Main card ── */
        .card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(124, 58, 237, 0.15);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }
        .card::before {
            content: '';
            position: absolute;
            width: 180px; height: 180px;
            top: -60px; right: -60px;
            background: linear-gradient(135deg, rgba(5,150,105,0.06) 0%, transparent 100%);
            border-radius: 50%;
            pointer-events: none;
        }

        /* ── Logo strip — sits OUTSIDE the card, above it ── */
        .header-logos {
            text-align: center;
            margin-bottom: 20px;
        }
        .header-logos img {
            max-width: 360px;
            width: 90%;
            height: auto;
        }

        /* ── Success header band (first child of card — gets top rounded corners) ── */
        .card-header-band {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            padding: 28px 32px;
            text-align: center;
            position: relative;
            border-radius: 16px 16px 0 0;
        }
        .card-header-band::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, rgba(255,255,255,0.3), transparent);
        }
        .paid-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            backdrop-filter: blur(4px);
        }

        /* ── Card body ── */
        .card-body {
            padding: 32px 32px 28px;
            text-align: center;
        }

        h1 {
            color: #059669;
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
            animation: fadeUp 0.6s ease-out;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .subtitle {
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.6;
            margin-bottom: 24px;
        }
        .subtitle strong {
            color: #059669;
        }

        /* ── Order summary section ── */
        .order-summary {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            text-align: left;
        }
        .order-ref {
            font-size: 1.15rem;
            font-weight: 700;
            color: #059669;
            margin-bottom: 10px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        .order-details {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .order-detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.88rem;
        }
        .order-detail-label {
            color: #6b7280;
            font-weight: 500;
        }
        .order-detail-value {
            color: #1e1b2e;
            font-weight: 600;
        }
        .order-total-row {
            margin-top: 8px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
        }
        .order-total-row .order-detail-value {
            color: #059669;
            font-size: 1.1rem;
            font-weight: 700;
        }

        /* ── Email confirmation badge ── */
        .email-badge {
            display: flex;
            align-items: center;
            gap: 8px;
            justify-content: center;
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
            border: 1px solid #059669;
            border-radius: 12px;
            padding: 10px 16px;
            margin-bottom: 24px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #059669;
            box-shadow: 0 1px 2px rgba(5, 150, 105, 0.15);
        }
        .email-badge-icon {
            font-size: 1rem;
            flex-shrink: 0;
        }

        /* ── Next steps section ── */
        .next-steps {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: left;
        }
        .next-steps-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 14px;
        }
        .step {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            background: white;
            border-radius: 8px;
            border-left: 3px solid #10b981;
            margin-bottom: 8px;
            font-size: 0.88rem;
            color: #374151;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }
        .step:last-of-type { margin-bottom: 0; }
        .step-dot {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: #10b981;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .steps-closing {
            text-align: center;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid #e5e7eb;
            color: #059669;
            font-weight: 700;
            font-size: 0.9rem;
        }

        /* ── Contact ── */
        .contact {
            color: #6b7280;
            font-size: 0.82rem;
            margin-bottom: 20px;
        }
        .contact-heading {
            font-weight: 600;
            margin-bottom: 4px;
        }
        .contact a {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }
        .contact a:hover { text-decoration: underline; }
        .contact-divider {
            display: inline-block;
            width: 1px;
            height: 12px;
            background: #d1d5db;
            vertical-align: middle;
            margin: 0 6px;
        }
        /* Lucide check SVG inline */
        .lucide-check {
            display: inline-block;
            vertical-align: -3px;
        }

        /* ── CTA button (matches project btn-primary) ── */
        .btn {
            display: inline-block;
            padding: 14px 32px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 700;
            font-size: 0.95rem;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background: #7c3aed;
            color: white;
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.25);
        }
        .btn:hover {
            background: #5b21b6;
            transform: translateY(-2px);
            box-shadow: rgba(124, 58, 237, 0.4) 0px 8px 24px;
        }

        /* ── Footer ── */
        .card-footer {
            background: linear-gradient(135deg, #fafbfc 0%, #ffffff 100%);
            border-top: 1px solid #f3f4f6;
            padding: 16px 32px;
            text-align: center;
            color: #9ca3af;
            font-size: 0.75rem;
        }

        /* ── Error variant ── */
        .error-card { border-top: 4px solid #ef4444; }
        .error-card h1 { color: #ef4444; }

        /* ── Responsive ── */
        @media (max-width: 600px) {
            .card-header-band { padding: 22px 24px 20px; }
            .card-body { padding: 24px 22px 20px; }
            h1 { font-size: 1.65rem; }
            .header-logos img { max-width: 200px; }
            .order-detail-row { font-size: 0.82rem; }
            .step { font-size: 0.82rem; padding: 8px 10px; }
            .card-footer { padding: 14px 22px; }
        }
    </style>
</head>
<body>
    <?php if ($paymentVerified && $orderRef):
        // Extract order details for the success page
        $customerName = htmlspecialchars($orderData['customerInfo']['name'] ?? $orderData['name'] ?? 'there');
        $customerEmail = htmlspecialchars($orderData['customerInfo']['email'] ?? $orderData['email'] ?? '');
        $width = $orderData['dimensions']['width'] ?? '0';
        $height = $orderData['dimensions']['height'] ?? '0';
        $material = ucfirst($orderData['material'] ?? 'poster');
        $tier = htmlspecialchars($orderData['pricing']['tier'] ?? 'Standard');
        $total = number_format($orderData['pricing']['total'] ?? 0, 2);
        $selectedDate = $orderData['selectedDate'] ?? date('Y-m-d');
        $deliveryDateFull = date('l, F j', strtotime($selectedDate));
        $deliveryTimeValue = $orderData['deliveryTime'] ?? 'anytime';
        $deliveryTimeLabels = ['anytime' => '', '9am' => ' by 9:00 AM', '12pm' => ' by 12:00 PM', '3pm' => ' by 3:00 PM', '6pm' => ' by 6:00 PM'];
        $deliveryTimeDisplay = $deliveryTimeLabels[$deliveryTimeValue] ?? '';
        $deliveryOption = $orderData['deliveryOption'] ?? 'mtcc';
        $deliveryLocation = ($deliveryOption === 'mtcc' || $deliveryOption === 'pickup')
            ? 'MTCC' : 'your address';
        $eventName = htmlspecialchars($orderData['event']['name'] ?? $orderData['eventName'] ?? '');
    ?>
    <!-- ── Logos OUTSIDE the card ── -->
    <div class="header-logos">
        <img src="mtcc-ps-logo.png" alt="MTCC + Print Stuff">
    </div>

    <div class="card">

        <!-- ── Green header band (top of card, rounded corners) ── -->
        <div class="card-header-band">
            <div class="paid-badge"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="lucide-check"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg> Payment Confirmed</div>
        </div>

        <!-- ── Card body ── -->
        <div class="card-body">

            <h1>You're All Set!</h1>

            <p class="subtitle">
                Your poster is confirmed and heading to production.<br>
                <strong>We'll take it from here.</strong>
            </p>

            <!-- Order summary card -->
            <div class="order-summary">
                <div class="order-ref">Order #<?= htmlspecialchars($orderRef) ?></div>
                <div class="order-details">
                    <div class="order-detail-row">
                        <span class="order-detail-label">Customer</span>
                        <span class="order-detail-value"><?= $customerName ?></span>
                    </div>
                    <div class="order-detail-row">
                        <span class="order-detail-label">Size</span>
                        <span class="order-detail-value"><?= $width ?>" &#215; <?= $height ?>"</span>
                    </div>
                    <div class="order-detail-row">
                        <span class="order-detail-label">Material</span>
                        <span class="order-detail-value"><?= $material ?></span>
                    </div>
                    <?php if ($eventName): ?>
                    <div class="order-detail-row">
                        <span class="order-detail-label">Event</span>
                        <span class="order-detail-value"><?= $eventName ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="order-detail-row">
                        <span class="order-detail-label">Turnaround</span>
                        <span class="order-detail-value"><?= $tier ?></span>
                    </div>
                    <div class="order-detail-row">
                        <span class="order-detail-label">Delivery</span>
                        <span class="order-detail-value"><?= $deliveryDateFull ?><?= $deliveryTimeDisplay ?></span>
                    </div>
                    <div class="order-detail-row order-total-row">
                        <span class="order-detail-label">Total Paid</span>
                        <span class="order-detail-value">$<?= $total ?> CAD</span>
                    </div>
                </div>
            </div>

            <!-- Email confirmation badge -->
            <?php if ($customerEmail): ?>
            <div class="email-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="lucide-check"><path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/></svg>
                Confirmation email sent to <?= $customerEmail ?>
            </div>
            <?php endif; ?>

            <!-- What happens now — 3 confident steps -->
            <div class="next-steps">
                <div class="next-steps-title">What happens now</div>

                <div class="step">
                    <div class="step-dot">1</div>
                    <span>We review your file for print quality</span>
                </div>

                <div class="step">
                    <div class="step-dot">2</div>
                    <span>Your poster is printed and prepared</span>
                </div>

                <div class="step">
                    <div class="step-dot">3</div>
                    <span>Delivered to <?= $deliveryLocation ?> on <strong><?= $deliveryDateFull ?></strong></span>
                </div>

                <div class="steps-closing">
                    That's it. We handle everything from here.
                </div>
            </div>

            <!-- Contact — subtle, an option not a necessity -->
            <div class="contact">
                <div class="contact-heading">Questions? We're here.</div>
                <a href="mailto:orders@printstuff.ca">orders@printstuff.ca</a>
                <span class="contact-divider"></span>
                <a href="tel:4378828822">(437) 882-8822</a>
                <span class="contact-divider"></span>
                <a href="javascript:void(0)" onclick="if(window.Tawk_API)Tawk_API.maximize();">Live Chat</a>
            </div>

            <a href="/" class="btn">Submit Another Order</a>
        </div>

        <!-- ── Footer ── -->
        <div class="card-footer">
            &copy; <?= date('Y') ?> Print Stuff &#183; Big or small, we print it all.
        </div>
    </div>

    <?php else: ?>
    <div class="card error-card">
        <div style="padding: 32px; text-align: center;">
            <div style="font-size: 4rem; margin-bottom: 16px;">&#9888;&#65039;</div>
            <h1>Payment Issue</h1>
            <p class="subtitle" style="margin-bottom: 24px;">
                <?= htmlspecialchars($error ?? 'There was an issue processing your payment. Please try again or contact support.') ?>
            </p>

            <div style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 16px; margin-bottom: 24px;">
                <div style="color: #dc2626; font-weight: 700; margin-bottom: 6px;">Need Help?</div>
                <div style="color: #374151; font-size: 0.9rem;">
                    <strong>Email:</strong> <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a><br>
                    <strong>Phone:</strong> <a href="tel:4378828822" style="color: #7c3aed; text-decoration: none;">(437) 882-8822</a>
                </div>
            </div>

            <a href="/" class="btn">&#8592; Try Again</a>
        </div>
    </div>
    <?php endif; ?>

    <!--Start of Tawk.to Script-->
    <script type="text/javascript">
    var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
    (function(){
    var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
    s1.async=true;
    s1.src='https://embed.tawk.to/69bcadcf600a121c36fa7a4b/1jk4gdsmg';
    s1.charset='UTF-8';
    s1.setAttribute('crossorigin','*');
    s0.parentNode.insertBefore(s1,s0);
    })();
    </script>
    <!--End of Tawk.to Script-->
</body>
</html>
