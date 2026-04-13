<?php
/**
 * Dispatch Email Functions with SMTP Support
 * Handles shipping status notification emails to customers
 * Location: /email-status-notifications.php (root directory)
 * Renamed from: dispatch-email-functions.php
 * 
 * Uses PHPMailer for SMTP - install via: composer require phpmailer/phpmailer
 * Falls back to PHP mail() if PHPMailer is not installed
 */

/**
 * Send email notification based on status change
 * Called from dispatch/api.php or admin-orders.php when status is updated
 */
function sendDispatchNotification($order, $newStatus, $scannedBy = 'Courier') {
 // Only send emails for specific statuses
 // printing = order is in production, no more changes allowed
 // delivered = ready for pickup at MTCC or delivered to address
 // pickedup = only for MTCC pickup confirmations
 // cancelled/refunded = important account notifications
 $notifyStatuses = ['printing', 'delivered', 'pickedup', 'cancelled', 'refunded'];

 if (!in_array($newStatus, $notifyStatuses)) {
 return false;
 }
 
 // Get customer email
 $customerEmail = $order['email'] ?? $order['customerInfo']['email'] ?? null;
 if (!$customerEmail) {
 error_log("Dispatch Email: No customer email for order " . ($order['referenceCode'] ?? 'unknown'));
 return false;
 }
 
 $referenceCode = $order['referenceCode'] ?? 'Unknown';
 $customerName = $order['name'] ?? $order['customerInfo']['name'] ?? 'Customer';
 $deliveryMethod = $order['deliveryMethod'] ?? $order['deliveryOption'] ?? 'mtcc';
 
 // Normalize delivery method
 $deliveryMethod = strtolower(trim($deliveryMethod));
 $isMTCC = ($deliveryMethod === 'mtcc' || $deliveryMethod === 'pickup' || strpos($deliveryMethod, 'mtcc') !== false);
 
 // Generate appropriate email
 switch ($newStatus) {
 case 'printing':
 $subject = "Your Poster is Being Printed - {$referenceCode}";
 $html = generatePrintingEmailHTML($order, $referenceCode, $customerName);
 break;

 case 'delivered':
 if ($isMTCC) {
 $subject = "Order {$referenceCode} - Ready for Pickup at MTCC";
 $html = generateDeliveredMTCCEmailHTML($order, $referenceCode, $customerName);
 } else {
 $subject = "Order {$referenceCode} - Delivered";
 $html = generateDeliveredAddressEmailHTML($order, $referenceCode, $customerName);
 }
 break;

 case 'pickedup':
 $subject = "Order {$referenceCode} - Complete";
 $html = generatePickedUpEmailHTML($order, $referenceCode, $customerName);
 break;

 case 'cancelled':
 $subject = "Order {$referenceCode} - Cancelled";
 $html = generateCancelledEmailHTML($order, $referenceCode, $customerName);
 break;

 case 'refunded':
 $subject = "Order {$referenceCode} - Refund Processed";
 $html = generateRefundedEmailHTML($order, $referenceCode, $customerName);
 break;

 default:
 return false;
 }
 
 // Send the email (uses noreply@ for status updates)
 return sendEmailSMTP($customerEmail, $subject, $html, $referenceCode, 'noreply');
}

/**
 * Send email via SMTP using PHPMailer
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $html HTML body
 * @param string $referenceCode Order reference for logging
 * @param string $sender Which sender to use: 'noreply' or 'orders'
 */
function sendEmailSMTP($to, $subject, $html, $referenceCode, $sender = 'noreply') {
 // Load SMTP config
 $configPath = __DIR__ . '/smtp-config.php';
 if (!file_exists($configPath)) {
 error_log("Dispatch Email: smtp-config.php not found, falling back to mail()");
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }
 
 require_once $configPath;
 
 // Check if SMTP is enabled
 if (!defined('SMTP_ENABLED') || !SMTP_ENABLED) {
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }
 
 // Check if PHPMailer is available
 $autoloadPath = __DIR__ . '/vendor/autoload.php';
 if (!file_exists($autoloadPath)) {
 error_log("Dispatch Email: PHPMailer not installed (vendor/autoload.php not found), falling back to mail()");
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }
 
 require_once $autoloadPath;
 
 // Check if PHPMailer class exists after autoload
 if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
 error_log("Dispatch Email: PHPMailer class not found, falling back to mail(). Run: composer update");
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }
 
 // Select sender credentials
 if ($sender === 'orders') {
 $fromEmail = SMTP_ORDERS_EMAIL;
 $fromPassword = SMTP_ORDERS_PASSWORD;
 $fromName = SMTP_ORDERS_NAME;
 } else {
 $fromEmail = SMTP_NOREPLY_EMAIL;
 $fromPassword = SMTP_NOREPLY_PASSWORD;
 $fromName = SMTP_NOREPLY_NAME;
 }
 
 $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
 
 try {
 // SMTP Configuration
 $mail->isSMTP();
 $mail->Host = SMTP_HOST;
 $mail->SMTPAuth = SMTP_AUTH;
 $mail->Username = $fromEmail;
 $mail->Password = $fromPassword;
 $mail->SMTPSecure = SMTP_SECURE;
 $mail->Port = SMTP_PORT;
 
 // Sender and recipient
 $mail->setFrom($fromEmail, $fromName);
 $mail->addAddress($to);
 $mail->addReplyTo($fromEmail, $fromName);
 
 // Content
 $mail->isHTML(true);
 $mail->CharSet = 'UTF-8';
 $mail->Subject = $subject;
 $mail->Body = $html;
 $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html));
 
 $mail->send();
 error_log("Dispatch Email (SMTP): Sent to {$to} for {$referenceCode}");
 return true;
 
 } catch (\Exception $e) {
 error_log("Dispatch Email (SMTP): FAILED for {$referenceCode} - " . $mail->ErrorInfo);
 return false;
 }
}

/**
 * Fallback to PHP mail() if SMTP is not configured
 */
function sendEmailFallback($to, $subject, $html, $referenceCode, $sender = 'noreply') {
 if ($sender === 'orders') {
 $fromEmail = 'orders@printstuff.ca';
 $fromName = 'Print Stuff Orders';
 } else {
 $fromEmail = 'noreply@printstuff.ca';
 $fromName = 'Print Stuff';
 }
 
 $headers = [
 'MIME-Version: 1.0',
 'Content-type: text/html; charset=UTF-8',
 "From: {$fromName} <{$fromEmail}>",
 "Reply-To: {$fromEmail}",
 'X-Mailer: PHP/' . phpversion()
 ];
 
 $sent = mail($to, $subject, $html, implode("\r\n", $headers));
 
 if ($sent) {
 error_log("Dispatch Email (mail): Sent to {$to} for {$referenceCode}");
 } else {
 error_log("Dispatch Email (mail): FAILED for {$referenceCode}");
 }
 
 return $sent;
}

/**
 * Generate PRINTING email HTML
 */
function generatePrintingEmailHTML($order, $referenceCode, $customerName) {
 $currentYear = date('Y');
 $eventName = $order['event']['name'] ?? $order['eventName'] ?? '';
 $dueDate = isset($order['selectedDate']) ? date('l, F j, Y', strtotime($order['selectedDate'])) : '';
 
 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Your Poster is Being Printed</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Your Poster is Being Printed</div>
 <div style="color: #fef3c7; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
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
 Great news! Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> has been sent to our print partner and is now being produced.
 </p>
 
 <!-- Status Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #fffbeb; border-radius: 8px; border-left: 4px solid #f59e0b; margin: 20px 0;">
 <tr>
 <td style="padding: 20px;">
 <div style="color: #92400e; font-size: 14px; font-weight: 600; margin-bottom: 8px;">Status: PRINTING</div>
 <div style="color: #78350f; font-size: 13px;">Your file has been reviewed and approved. Printing is underway!</div>
 </td>
 </tr>
 </table>';
 
 // Add due date if available
 if ($dueDate) {
 $html .= '
 <!-- Due Date -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f0fdf4; border-radius: 8px; margin: 20px 0;">
 <tr>
 <td style="padding: 16px; text-align: center;">
 <div style="color: #166534; font-size: 13px; font-weight: 600; margin-bottom: 4px;">Expected Ready By</div>
 <div style="color: #15803d; font-size: 16px; font-weight: 700;">' . htmlspecialchars($dueDate) . '</div>';
 
 if ($eventName) {
 $html .= '<div style="color: #166534; font-size: 12px; margin-top: 6px;">For: ' . htmlspecialchars($eventName) . '</div>';
 }
 
 $html .= '
 </td>
 </tr>
 </table>';
 }
 
 $html .= '
 <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 20px 0;">
 We\'ll send you another update once your poster has been printed and is on its way.
 </p>
 
 <!-- Track Order Button -->
 <div style="margin-top: 20px; text-align: center;">
 <a href="https://mtcc.print-stuff.ca/status?ref=' . urlencode($referenceCode) . '" 
 style="display: inline-block; padding: 14px 32px; background-color: #10b981; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
 Track Your Order
 </a>
 </div>
 
 </td>
 </tr>
 </table>
 
 <!-- Footer -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact us at <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a> or call <a href="tel:4378828822" style="color: #7c3aed; text-decoration: none;">437.882.8822</a></div>
 <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $currentYear . ' Print Stuff - Professional Poster Printing</div>
 <div style="margin-top: 6px;"><a href="mailto:orders@printstuff.ca?subject=Unsubscribe" style="color: #d1d5db; font-size: 10px; text-decoration: underline;">Unsubscribe</a></div>
 </td>
 </tr>
 </table>

</td>
</tr>
</table>

</body>
</html>';

 return $html;
}

/**
 * Generate SHIPPED email HTML
 */
function generateShippedEmailHTML($order, $referenceCode, $customerName, $isMTCC) {
 $currentYear = date('Y');
 $destination = $isMTCC ? 'the Metro Toronto Convention Centre' : 'your delivery address';
 
 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Order Shipped</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Order Shipped</div>
 <div style="color: #bfdbfe; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
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
 Great news! Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> has been picked up by our courier and is now on its way to ' . $destination . '.
 </p>
 
 <!-- Status Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #eff6ff; border-radius: 8px; border-left: 4px solid #3b82f6; margin: 20px 0;">
 <tr>
 <td style="padding: 20px;">
 <div style="color: #1e40af; font-size: 14px; font-weight: 600; margin-bottom: 8px;">Current Status: SHIPPED</div>
 <div style="color: #1e3a8a; font-size: 13px;">Your order is in transit and will arrive soon.</div>
 </td>
 </tr>
 </table>
 
 <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 20px 0;">
 We will send you another email as soon as your order has been delivered.
 </p>
 
 <!-- Track Order Button -->
 <div style="margin-top: 20px; text-align: center;">
 <a href="https://mtcc.print-stuff.ca/status?ref=' . urlencode($referenceCode) . '" 
 style="display: inline-block; padding: 14px 32px; background-color: #10b981; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
 Track Your Order
 </a>
 </div>
 
 </td>
 </tr>
 </table>
 
 <!-- Footer -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact us at <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a> or call <a href="tel:4378828822" style="color: #7c3aed; text-decoration: none;">437.882.8822</a></div>
 <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $currentYear . ' Print Stuff - Professional Poster Printing</div>
 <div style="margin-top: 6px;"><a href="mailto:orders@printstuff.ca?subject=Unsubscribe" style="color: #d1d5db; font-size: 10px; text-decoration: underline;">Unsubscribe</a></div>
 </td>
 </tr>
 </table>

</td>
</tr>
</table>

</body>
</html>';

 return $html;
}

/**
 * Generate DELIVERED (to MTCC) email HTML
 */
function generateDeliveredMTCCEmailHTML($order, $referenceCode, $customerName) {
 $currentYear = date('Y');
 
 // Determine building
 $building = $order['event']['building'] ?? $order['building'] ?? 'north';
 if ($building === 'south') {
 $locationName = 'MTCC South Building';
 $locationAddress = 'Level 800, 222 Bremner Boulevard';
 } else {
 $locationName = 'MTCC North Building';
 $locationAddress = 'Level 300, 255 Front Street West';
 }
 
 $eventName = $order['event']['name'] ?? $order['eventName'] ?? '';
 
 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Ready for Pickup</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Ready for Pickup</div>
 <div style="color: #a7f3d0; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
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
 Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> is ready for pickup!
 </p>
 
 <!-- Pickup Location Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #ecfdf5; border-radius: 8px; border: 2px solid #10b981; margin: 20px 0;">
 <tr>
 <td style="padding: 24px; text-align: center;">
 <div style="color: #065f46; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 12px;">PICKUP LOCATION</div>
 <div style="color: #047857; font-size: 20px; font-weight: 700; margin-bottom: 8px;">' . htmlspecialchars($locationName) . '</div>
 <div style="color: #065f46; font-size: 14px;">' . htmlspecialchars($locationAddress) . '</div>
 <div style="color: #065f46; font-size: 14px; margin-top: 4px;">Toronto, ON M5V 3W6</div>';
 
 if ($eventName) {
 $html .= '<div style="color: #059669; font-size: 13px; margin-top: 12px; padding-top: 12px; border-top: 1px solid #a7f3d0;">Event: ' . htmlspecialchars($eventName) . '</div>';
 }
 
 $html .= '
 </td>
 </tr>
 </table>
 
 <!-- What to Bring -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #fefce8; border-radius: 8px; margin: 20px 0;">
 <tr>
 <td style="padding: 16px;">
 <div style="color: #854d0e; font-size: 14px; font-weight: 600; margin-bottom: 8px;">What to Bring</div>
 <div style="color: #713f12; font-size: 13px; line-height: 1.5;">
 Please have your <strong>order number (' . htmlspecialchars($referenceCode) . ')</strong> or <strong>name</strong> ready when picking up your poster.
 </div>
 </td>
 </tr>
 </table>
 
 </td>
 </tr>
 </table>
 
 <!-- Footer -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact us at <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a></div>
 <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $currentYear . ' Print Stuff - Professional Poster Printing</div>
 <div style="margin-top: 6px;"><a href="mailto:orders@printstuff.ca?subject=Unsubscribe" style="color: #d1d5db; font-size: 10px; text-decoration: underline;">Unsubscribe</a></div>
 </td>
 </tr>
 </table>

</td>
</tr>
</table>

</body>
</html>';

 return $html;
}

/**
 * Generate DELIVERED (to address) email HTML
 */
function generateDeliveredAddressEmailHTML($order, $referenceCode, $customerName) {
 $currentYear = date('Y');
 
 // Get delivery address
 $addr = $order['deliveryAddress'] ?? $order['address'] ?? [];
 $addressLine = '';
 if (!empty($addr)) {
 $parts = array_filter([
 $addr['address'] ?? '',
 $addr['city'] ?? '',
 $addr['province'] ?? ''
 ]);
 $addressLine = implode(', ', $parts);
 }
 
 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Order Delivered</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Delivered</div>
 <div style="color: #a7f3d0; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
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
 Great news! Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> has been successfully delivered!
 </p>
 
 <!-- Delivery Confirmation Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #ecfdf5; border-radius: 8px; border-left: 4px solid #10b981; margin: 20px 0;">
 <tr>
 <td style="padding: 20px;">
 <div style="color: #065f46; font-size: 14px; font-weight: 600; margin-bottom: 8px;">Delivery Confirmed</div>
 <div style="color: #047857; font-size: 13px;">Your order has been delivered' . ($addressLine ? ' to ' . htmlspecialchars($addressLine) : '') . '.</div>
 </td>
 </tr>
 </table>
 
 <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 20px 0;">
 Thank you for choosing Print Stuff for your poster printing needs. We hope you are happy with your order!
 </p>
 
 <!-- Feedback Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #faf5ff; border-radius: 8px; margin: 20px 0;">
 <tr>
 <td style="padding: 16px; text-align: center;">
 <div style="color: #6b21a8; font-size: 14px; font-weight: 600; margin-bottom: 8px;">How did we do?</div>
 <div style="color: #7e22ce; font-size: 13px;">We would love to hear about your experience. Reply to this email with any feedback!</div>
 </td>
 </tr>
 </table>
 
 </td>
 </tr>
 </table>
 
 <!-- Footer -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact us at <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a></div>
 <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $currentYear . ' Print Stuff - Professional Poster Printing</div>
 <div style="margin-top: 6px;"><a href="mailto:orders@printstuff.ca?subject=Unsubscribe" style="color: #d1d5db; font-size: 10px; text-decoration: underline;">Unsubscribe</a></div>
 </td>
 </tr>
 </table>

</td>
</tr>
</table>

</body>
</html>';

 return $html;
}

/**
 * Generate PICKED UP email HTML
 */
function generatePickedUpEmailHTML($order, $referenceCode, $customerName) {
 $currentYear = date('Y');
 
 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Order Complete</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Thank You!</div>
 <div style="color: #ddd6fe; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . ' Complete</div>
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
 Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> has been picked up. We hope it looks amazing!
 </p>
 
 <!-- Complete Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #faf5ff; border-radius: 8px; border: 2px solid #7c3aed; margin: 20px 0;">
 <tr>
 <td style="padding: 24px; text-align: center;">
 <div style="color: #6b21a8; font-size: 16px; font-weight: 700; margin-bottom: 8px;">Order Complete</div>
 <div style="color: #7e22ce; font-size: 14px;">Thank you for choosing Print Stuff!</div>
 </td>
 </tr>
 </table>
 
 <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 20px 0;">
 We appreciate your business and hope to serve you again at future events!
 </p>
 
 <!-- Google Review Box -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #fefce8; border-radius: 8px; margin: 20px 0;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #854d0e; font-size: 14px; font-weight: 600; margin-bottom: 10px;">Loved our service?</div>
 <div style="color: #713f12; font-size: 13px; margin-bottom: 16px;">Your feedback helps other conference attendees find us!</div>
 <a href="https://g.page/r/CQfNMxfSvVHbEAI/review" 
 style="display: inline-block; padding: 12px 28px; background-color: #f59e0b; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);">
 Leave a Google Review
 </a>
 </td>
 </tr>
 </table>
 
 </td>
 </tr>
 </table>
 
 <!-- Footer -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact us at <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a></div>
 <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $currentYear . ' Print Stuff - Professional Poster Printing</div>
 <div style="margin-top: 6px;"><a href="mailto:orders@printstuff.ca?subject=Unsubscribe" style="color: #d1d5db; font-size: 10px; text-decoration: underline;">Unsubscribe</a></div>
 </td>
 </tr>
 </table>

</td>
</tr>
</table>

</body>
</html>';

 return $html;
}

function generateCancelledEmailHTML($order, $referenceCode, $customerName) {
 $currentYear = date('Y');

 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Order Cancelled</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Order Cancelled</div>
 <div style="color: #d1d5db; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
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
 Your poster order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong> has been cancelled.
 </p>
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f3f4f6; border-radius: 8px; border: 1px solid #e5e7eb; margin: 20px 0;">
 <tr>
 <td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 14px;">If you believe this was done in error or have questions, please contact us.</div>
 </td>
 </tr>
 </table>
 <div style="text-align: center; margin: 24px 0;">
 <a href="mailto:orders@printstuff.ca?subject=Re: Order ' . htmlspecialchars($referenceCode) . ' Cancellation"
 style="display: inline-block; padding: 14px 32px; background-color: #7c3aed; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px;">
 Contact Us
 </a>
 </div>
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

 return $html;
}

function generateRefundedEmailHTML($order, $referenceCode, $customerName) {
 $currentYear = date('Y');
 $refundAmount = '';
 if (isset($order['refund']['amount'])) {
 $refundAmount = '$' . number_format($order['refund']['amount'], 2);
 } elseif (isset($order['pricing']['total'])) {
 $refundAmount = '$' . number_format($order['pricing']['total'], 2);
 }

 $html = '<!DOCTYPE html>
<html>
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0">
 <title>Refund Processed</title>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); border-radius: 12px 12px 0 0;">
 <tr>
 <td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 24px; font-weight: 700; margin-bottom: 6px;">Refund Processed</div>
 <div style="color: #a7f3d0; font-size: 14px;">Order ' . htmlspecialchars($referenceCode) . '</div>
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
 A refund has been processed for your order <strong style="color: #7c3aed;">' . htmlspecialchars($referenceCode) . '</strong>.
 </p>
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f0fdf4; border-radius: 8px; border: 2px solid #059669; margin: 20px 0;">
 <tr>
 <td style="padding: 24px; text-align: center;">
 <div style="color: #065f46; font-size: 14px; font-weight: 600; margin-bottom: 8px;">Refund Amount</div>
 <div style="color: #047857; font-size: 28px; font-weight: 700;">' . htmlspecialchars($refundAmount) . '</div>
 <div style="color: #6b7280; font-size: 13px; margin-top: 8px;">Please allow 5&ndash;10 business days for the refund to appear on your statement.</div>
 </td>
 </tr>
 </table>
 <p style="color: #374151; font-size: 16px; line-height: 1.6; margin: 20px 0 0 0;">
 If you have any questions about this refund, please don&apos;t hesitate to reach out.
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

 return $html;
}
