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
        file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT));
        
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
                    'conversionFee' => (float)($pricing['conversionFee'] ?? 0),
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
            file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT));
            
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
    $conversionFee = number_format($orderData['pricing']['conversionFee'] ?? 0, 2);
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #faf8ff 0%, #f0f9ff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        
        .success-container {
            border: 2px solid #10b981;
        }
        
        .error-container {
            border: 2px solid #ef4444;
        }
        
        .icon {
            font-size: 5rem;
            margin-bottom: 20px;
        }
        
        .success-icon {
            animation: bounce 1s ease-in-out;
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-20px); }
            60% { transform: translateY(-10px); }
        }
        
        h1 {
            margin-bottom: 10px;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .success h1 { color: #10b981; }
        .error h1 { color: #ef4444; }
        
        .reference-code {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            padding: 15px 25px;
            border-radius: 12px;
            font-size: 1.5rem;
            font-weight: 700;
            margin: 30px 0;
            letter-spacing: 1px;
            box-shadow: rgba(16, 185, 129, 0.3) 0px 8px 24px;
        }
        
        .message {
            color: #374151;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        .paid-badge {
            display: inline-block;
            background: #10b981;
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        .next-steps {
            background: #f8fafc;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            text-align: left;
        }
        
        .next-steps h3 {
            color: #7c3aed;
            margin-bottom: 15px;
        }
        
        .step {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 15px;
            padding: 10px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #10b981;
        }
        
        .step-number {
            background: #10b981;
            color: white;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: 600;
            flex-shrink: 0;
        }
        
        .contact-info {
            background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%);
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 20px;
            margin: 30px 0;
        }
        
        .contact-info h3 {
            color: #059669;
            margin-bottom: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            margin: 5px;
        }
        
        .btn-primary {
            background: #7c3aed;
            color: white;
        }
        
        .btn-primary:hover {
            background: #5b21b6;
        }
        
        .footer {
            margin-top: 40px;
            color: #6b7280;
            font-size: 0.85rem;
        }
        
        @media (max-width: 600px) {
            .container { padding: 30px 25px; }
            h1 { font-size: 2rem; }
            .reference-code { font-size: 1.2rem; }
        }
    </style>
</head>
<body>
    <?php if ($paymentVerified && $orderRef): ?>
    <div class="container success-container success">
        <div class="icon success-icon"><img src="mtcc-ps-logo.png"></div>
        <div class="paid-badge">&#10004; PAYMENT SUCCESSFUL</div>
        <h1>Thank You!</h1>
        
        <div class="reference-code">
            Order #<?= htmlspecialchars($orderRef) ?>
        </div>
        
        <p class="message">
            Your payment has been processed and your poster order is confirmed! 
            You'll receive a confirmation email shortly.
        </p>
        
        <div class="next-steps">
            <h3>&#128203; What Happens Next:</h3>
            
            <div class="step">
                <div class="step-number">1</div>
                <div style="color: #374151;">
                    <strong>Confirmation email:</strong> Check your inbox for order details
                </div>
            </div>
            
            <div class="step">
                <div class="step-number">2</div>
                <div style="color: #374151;">
                    <strong>File review:</strong> Our team will check your artwork for print quality
                </div>
            </div>
            
            <div class="step">
                <div class="step-number">3</div>
                <div style="color: #374151;">
                    <strong>Production:</strong> Your poster will be printed and prepared for delivery
                </div>
            </div>
            
            <div class="step">
                <div class="step-number">4</div>
                <div style="color: #374151;">
                    <strong>Delivery:</strong> We'll deliver according to your selected timeline
                </div>
            </div>
        </div>
        
        <div class="contact-info">
            <h3>&#128222; Questions about your order?</h3>
            <p style="margin: 5px 0; color: #374151;"><strong>Email:</strong> orders@printstuff.ca</p>
            <p style="margin: 5px 0; color: #374151;"><strong>Phone:</strong> (437) 882-8822</p>
            <p style="margin: 5px 0; color: #374151;"><strong>Reference:</strong> <?= htmlspecialchars($orderRef) ?></p>
        </div>
        
        <a href="/" class="btn btn-primary">Submit Another Order</a>
        
        <div class="footer">
            <p>Thank you for choosing Print Stuff!</p>
            <p>&copy; <?= date('Y') ?> Print Stuff - Big or small, we print it all.</p>
        </div>
    </div>
    
    <?php else: ?>
    <div class="container error-container error">
        <div class="icon">&#9888;&#65039;</div>
        <h1>Payment Issue</h1>
        
        <p class="message">
            <?= htmlspecialchars($error ?? 'There was an issue processing your payment. Please try again or contact support.') ?>
        </p>
        
        <div class="contact-info" style="background: #fef2f2; border-color: #ef4444;">
            <h3 style="color: #dc2626;">Need Help?</h3>
            <p style="margin: 5px 0; color: #374151;"><strong>Email:</strong> orders@printstuff.ca</p>
            <p style="margin: 5px 0; color: #374151;"><strong>Phone:</strong> (437) 882-8822</p>
        </div>
        
        <a href="/" class="btn btn-primary">&larr; Try Again</a>
    </div>
    <?php endif; ?>
</body>
</html>
