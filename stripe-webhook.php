<?php
require_once __DIR__ . '/includes/icons.php';
/**
 * Stripe Webhook Handler
 * 
 * This provides a BACKUP payment confirmation in case the user closes their
 * browser before being redirected back to payment-success.php
 * 
 * Stripe will call this endpoint directly when payment events occur.
 * 
 * SETUP INSTRUCTIONS:
 * 1. Go to Stripe Dashboard > Developers > Webhooks
 * 2. Click "Add endpoint"
 * 3. Enter your URL: https://yourdomain.com/stripe-webhook.php
 * 4. Select event: checkout.session.completed
 * 5. Copy the "Signing secret" (starts with whsec_)
 * 6. Add it to your stripe-config.php as STRIPE_WEBHOOK_SECRET
 */

// Disable output buffering
ob_end_clean();

// Set response headers
header('Content-Type: application/json');

// Load Stripe
require_once 'vendor/autoload.php';
require_once 'stripe-config.php';

// Set Stripe API key
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Get the webhook payload
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Log incoming webhook for debugging
error_log("Stripe Webhook received at " . date('Y-m-d H:i:s'));

try {
    // Verify webhook signature if secret is configured
    if (!empty(STRIPE_WEBHOOK_SECRET)) {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, STRIPE_WEBHOOK_SECRET
            );
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            error_log("Webhook signature verification failed: " . $e->getMessage());
            http_response_code(400);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    } else {
        // No webhook secret configured - parse payload directly (less secure)
        $event = json_decode($payload);
        if (!$event) {
            throw new Exception('Invalid payload');
        }
        error_log("Warning: STRIPE_WEBHOOK_SECRET not configured - webhook signature not verified");
    }
    
    // Handle the event
    switch ($event->type) {
        case 'checkout.session.completed':
            $session = $event->data->object;
            handleCheckoutCompleted($session);
            break;
            
        case 'payment_intent.succeeded':
            // Optional: Handle payment intent success
            error_log("Payment intent succeeded: " . $event->data->object->id);
            break;
            
        default:
            error_log("Unhandled webhook event type: " . $event->type);
    }
    
    // Return success response
    http_response_code(200);
    echo json_encode(['received' => true]);
    
} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Handle checkout.session.completed event
 * This is the backup in case payment-success.php wasn't reached
 */
function handleCheckoutCompleted($session) {
    error_log("Processing checkout.session.completed for session: " . $session->id);
    
    // Check if payment is actually complete
    if ($session->payment_status !== 'paid') {
        error_log("Session not paid yet, skipping: " . $session->id);
        return;
    }
    
    // Get our temp order reference from metadata
    $tempRef = $session->metadata->temp_order_ref ?? null;
    
    if (!$tempRef) {
        error_log("No temp_order_ref in session metadata");
        return;
    }
    
    // Check if order was already processed by payment-success.php
    // Look for existing order with this Stripe session ID
    $ordersDir = __DIR__ . '/uploads/orders/';
    if (is_dir($ordersDir)) {
        $files = glob($ordersDir . '*.json');
        foreach ($files as $file) {
            $content = file_get_contents($file);
            $orderData = json_decode($content, true);
            if ($orderData && isset($orderData['stripeSessionId']) && $orderData['stripeSessionId'] === $session->id) {
                error_log("Order already exists for session " . $session->id . " - skipping webhook processing");
                return;
            }
        }
    }
    
    // Order doesn't exist - this means user closed browser before redirect
    // We need to create the order from Stripe metadata
    error_log("Order not found - creating from webhook for session: " . $session->id);
    
    // Get metadata from session
    $eventAcronym = $session->metadata->event_acronym ?? 'ORD';
    $eventName = $session->metadata->event_name ?? '';
    $posterSize = $session->metadata->poster_size ?? '';
    $material = $session->metadata->material ?? 'paper';
    $customerName = $session->metadata->customer_name ?? '';
    $customerPhone = $session->metadata->customer_phone ?? '';
    
    // Parse poster size
    $sizeParts = explode('x', $posterSize);
    $width = $sizeParts[0] ?? 0;
    $height = $sizeParts[1] ?? 0;
    
    // Generate reference code
    $orderRef = generateWebhookReferenceCode($eventAcronym);
    
    // Calculate pricing from Stripe amount
    $totalCents = $session->amount_total ?? 0;
    $total = $totalCents / 100;
    
    // Build order data from what we have
    $orderData = [
        'referenceCode' => $orderRef,
        'stripeSessionId' => $session->id,
        'stripePaymentStatus' => 'paid',
        'stripePaymentIntent' => $session->payment_intent,
        'processedVia' => 'webhook', // Mark as webhook-processed
        'event' => [
            'acronym' => $eventAcronym,
            'name' => $eventName
        ],
        'dimensions' => [
            'width' => (float)$width,
            'height' => (float)$height
        ],
        'material' => $material,
        'customerInfo' => [
            'name' => $customerName,
            'email' => $session->customer_email ?? '',
            'phone' => $customerPhone,
        ],
        'pricing' => [
            'total' => $total,
        ],
        'submittedAt' => date('Y-m-d H:i:s'),
        'paidAt' => date('Y-m-d H:i:s'),
        'paymentMethod' => 'stripe',
        'version' => 'v1-webhook-recovery',
        'webhookNote' => 'Order created via webhook - user may have closed browser before redirect. Some details may be incomplete. Check Stripe dashboard for full details.'
    ];
    
    // Save order
    if (!is_dir($ordersDir)) {
        mkdir($ordersDir, 0755, true);
    }
    
    $orderFileName = $ordersDir . $orderRef . '_' . date('Y-m-d_H-i-s') . '-order.json';
    file_put_contents($orderFileName, json_encode($orderData, JSON_PRETTY_PRINT));
    error_log("Webhook created order file: " . $orderFileName);
    
    // Update status
    $statusFile = __DIR__ . '/data/statuses.json';
    $statuses = [];
    if (file_exists($statusFile)) {
        $statuses = json_decode(file_get_contents($statusFile), true) ?? [];
    }
    $statuses[$orderRef] = 'paid';
    file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
    
    // Send notification emails
    try {
        sendWebhookEmails($orderData, $session);
    } catch (Exception $e) {
        error_log("Webhook email error: " . $e->getMessage());
    }
    
    // Try to clean up temp file if it exists
    $tempDir = __DIR__ . '/uploads/temp/';
    if (is_dir($tempDir)) {
        $pattern = $tempDir . $tempRef . '_*';
        $tempFiles = glob($pattern);
        foreach ($tempFiles as $tempFile) {
            // Move to permanent location
            $filesDir = __DIR__ . '/uploads/files/';
            if (!is_dir($filesDir)) {
                mkdir($filesDir, 0755, true);
            }
            
            // Get original filename from temp file (format: TEMP-xxx_timestamp.ext)
            $tempBasename = basename($tempFile);
            $fileExt = pathinfo($tempFile, PATHINFO_EXTENSION);
            
            // Use poster size from metadata, format: OrderRef_WxH_recovered.ext
            // Note: Original filename not available in webhook recovery
            $newFileName = $orderRef . '_' . $posterSize . '_recovered.' . $fileExt;
            $permanentPath = $filesDir . $newFileName;
            
            if (rename($tempFile, $permanentPath)) {
                error_log("Webhook moved temp file to: " . $permanentPath);
                // Update order with file info
                $orderData['uploadedFile'] = [
                    'path' => 'uploads/files/' . $newFileName,
                    'recoveredFromTemp' => true
                ];
                file_put_contents($orderFileName, json_encode($orderData, JSON_PRETTY_PRINT));
            }
        }
    }
    
    error_log("Webhook successfully processed order: " . $orderRef);
}

/**
 * Generate reference code (same logic as payment-success.php)
 */
function generateWebhookReferenceCode($eventAcronym) {
    $counterFile = __DIR__ . '/data/order_counter.txt';
    
    // Use file locking to prevent race conditions
    $fp = fopen($counterFile, 'c+');
    if (flock($fp, LOCK_EX)) {
        $content = stream_get_contents($fp);
        $counters = [];
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $counters = $decoded;
            }
        }
        
        $currentCount = isset($counters[$eventAcronym]) ? (int)$counters[$eventAcronym] : 0;
        $newCount = $currentCount + 1;
        
        $referenceCode = $eventAcronym . '-' . str_pad($newCount, 3, '0', STR_PAD_LEFT);
        
        $counters[$eventAcronym] = $newCount;
        
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($counters, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    
    return $referenceCode;
}

/**
 * Send notification emails for webhook-recovered orders
 */
function sendWebhookEmails($orderData, $session) {
    $fromEmail = 'orders@printstuff.ca';
    $fromName = 'Print Stuff Orders';
    $businessEmail = 'orders@printstuff.ca';
    $customerEmail = $orderData['customerInfo']['email'];
    $orderRef = $orderData['referenceCode'];
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        "From: {$fromName} <{$fromEmail}>",
        "Reply-To: {$fromEmail}",
    ];
    $headerString = implode("\r\n", $headers);
    
    // Send customer email
    if (!empty($customerEmail)) {
        $customerSubject = "Payment Confirmed: Order #{$orderRef}";
        $customerMessage = generateWebhookCustomerEmail($orderData);
        @mail($customerEmail, $customerSubject, $customerMessage, $headerString);
        error_log("Webhook sent customer email to: " . $customerEmail);
    }
    
    // Send business notification - mark as WEBHOOK so you know to check details
    $total = number_format($orderData['pricing']['total'], 2);
    $businessSubject = "=?UTF-8?B?" . base64_encode("<?= ICON_WARNING ?> WEBHOOK Order: {$orderRef} - \${$total}") . "?=";
    $businessMessage = generateWebhookBusinessEmail($orderData, $session);
    @mail($businessEmail, $businessSubject, $businessMessage, $headerString);
    error_log("Webhook sent business notification");
}

/**
 * Generate customer email for webhook orders
 */
function generateWebhookCustomerEmail($orderData) {
    $ref = htmlspecialchars($orderData['referenceCode']);
    $total = number_format($orderData['pricing']['total'], 2);
    $name = htmlspecialchars($orderData['customerInfo']['name'] ?: 'Customer');
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px;'>
        <div style='max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; padding: 40px;'>
            <div style='text-align: center; margin-bottom: 30px;'>
                <h1 style='color: #10b981; margin: 0;'>âœ“ Payment Confirmed!</h1>
            </div>
            
            <p>Hi {$name},</p>
            
            <p>Great news! Your payment of <strong>\${$total} CAD</strong> has been received.</p>
            
            <div style='background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; border-radius: 12px; text-align: center; margin: 30px 0;'>
                <div style='font-size: 14px; opacity: 0.9;'>Order Reference</div>
                <div style='font-size: 28px; font-weight: bold; letter-spacing: 2px;'>{$ref}</div>
            </div>
            
            <p>Our team will process your order shortly. If you have any questions, please contact us with your order reference.</p>
            
            <div style='background: #f8fafc; border-radius: 8px; padding: 20px; margin-top: 30px;'>
                <p style='margin: 0; color: #6b7280;'><strong>Questions?</strong> Contact us at:</p>
                <p style='margin: 10px 0 0 0;'><?= ICON_GEAR ?> orders@printstuff.ca | ðŸ“ž (437) 882-8822</p>
            </div>
            
            <p style='color: #9ca3af; font-size: 12px; text-align: center; margin-top: 40px;'>
                Thank you for choosing Print Stuff!<br>
                &copy; " . date('Y') . " Print Stuff - Professional Poster Printing
            </p>
        </div>
    </body>
    </html>";
}

/**
 * Generate business email for webhook orders - includes warning about incomplete data
 */
function generateWebhookBusinessEmail($orderData, $session) {
    $ref = htmlspecialchars($orderData['referenceCode']);
    $total = number_format($orderData['pricing']['total'], 2);
    $name = htmlspecialchars($orderData['customerInfo']['name'] ?: 'Unknown');
    $email = htmlspecialchars($orderData['customerInfo']['email'] ?: 'Unknown');
    $phone = htmlspecialchars($orderData['customerInfo']['phone'] ?: 'Unknown');
    $eventName = htmlspecialchars($orderData['event']['name'] ?: 'Unknown');
    $size = $orderData['dimensions']['width'] . '"  x  ' . $orderData['dimensions']['height'] . '"';
    $material = ucfirst($orderData['material'] ?? 'Unknown');
    $stripeSessionId = $session->id;
    $stripePaymentIntent = $session->payment_intent;
    
    return "
    <!DOCTYPE html>
    <html>
    <head><meta charset='UTF-8'></head>
    <body style='font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px;'>
        <div style='max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; padding: 40px;'>
            <div style='background: #fef3c7; border: 2px solid #f59e0b; border-radius: 8px; padding: 15px; margin-bottom: 20px;'>
                <h2 style='color: #b45309; margin: 0 0 10px 0;'><?= ICON_WARNING ?> WEBHOOK ORDER</h2>
                <p style='margin: 0; color: #92400e; font-size: 14px;'>
                    Customer closed browser before redirect. Some order details may be incomplete.
                    <strong>Check Stripe Dashboard for full details.</strong>
                </p>
            </div>
            
            <div style='background: #10b981; color: white; padding: 15px; border-radius: 8px; text-align: center; font-size: 24px; font-weight: bold;'>
                {$ref} - \${$total} CAD
            </div>
            
            <h3>Customer Information</h3>
            <p><strong>Name:</strong> {$name}<br>
            <strong>Email:</strong> {$email}<br>
            <strong>Phone:</strong> {$phone}</p>
            
            <h3>Order Details (from metadata)</h3>
            <p><strong>Event:</strong> {$eventName}<br>
            <strong>Size:</strong> {$size}<br>
            <strong>Material:</strong> {$material}</p>
            
            <h3>Stripe References</h3>
            <p><strong>Session ID:</strong> <code style='background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px;'>{$stripeSessionId}</code><br>
            <strong>Payment Intent:</strong> <code style='background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px;'>{$stripePaymentIntent}</code></p>
            
            <div style='background: #eff6ff; border: 1px solid #3b82f6; border-radius: 8px; padding: 15px; margin-top: 20px;'>
                <p style='margin: 0; color: #1e40af;'>
                    <strong>ACTION REQUIRED:</strong> Contact customer to confirm order details and file upload status.
                </p>
            </div>
        </div>
    </body>
    </html>";
}
?>
