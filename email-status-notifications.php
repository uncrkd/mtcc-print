<?php
/**
 * Status Email Notifications with SMTP Support
 * Handles status notification emails to customers
 * Location: /email-status-notifications.php (root directory)
 *
 * Uses PHPMailer for SMTP - falls back to PHP mail() if not installed
 */

require_once __DIR__ . '/includes/email-template.php';

/**
 * Send email notification based on status change
 */
function sendDispatchNotification($order, $newStatus, $scannedBy = 'Courier') {
 $notifyStatuses = ['printing', 'delivered', 'pickedup', 'cancelled', 'refunded'];

 if (!in_array($newStatus, $notifyStatuses)) {
 return false;
 }

 $customerEmail = $order['email'] ?? $order['customerInfo']['email'] ?? null;
 if (!$customerEmail) {
 error_log("Dispatch Email: No customer email for order " . ($order['referenceCode'] ?? 'unknown'));
 return false;
 }

 $referenceCode = $order['referenceCode'] ?? 'Unknown';
 $customerName = $order['name'] ?? $order['customerInfo']['name'] ?? 'Customer';
 $deliveryMethod = $order['deliveryMethod'] ?? $order['deliveryOption'] ?? 'mtcc';

 $deliveryMethod = strtolower(trim($deliveryMethod));
 $isMTCC = ($deliveryMethod === 'mtcc' || $deliveryMethod === 'pickup' || strpos($deliveryMethod, 'mtcc') !== false);

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

 return sendEmailSMTP($customerEmail, $subject, $html, $referenceCode, 'noreply');
}

/**
 * Send email via SMTP using PHPMailer
 */
function sendEmailSMTP($to, $subject, $html, $referenceCode, $sender = 'noreply') {
 $configPath = __DIR__ . '/smtp-config.php';
 if (!file_exists($configPath)) {
 error_log("Dispatch Email: smtp-config.php not found, falling back to mail()");
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }

 require_once $configPath;

 if (!defined('SMTP_ENABLED') || !SMTP_ENABLED) {
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }

 $autoloadPath = __DIR__ . '/vendor/autoload.php';
 if (!file_exists($autoloadPath)) {
 error_log("Dispatch Email: PHPMailer not installed, falling back to mail()");
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }

 require_once $autoloadPath;

 if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
 error_log("Dispatch Email: PHPMailer class not found, falling back to mail()");
 return sendEmailFallback($to, $subject, $html, $referenceCode, $sender);
 }

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
 $mail->isSMTP();
 $mail->Host = SMTP_HOST;
 $mail->SMTPAuth = SMTP_AUTH;
 $mail->Username = $fromEmail;
 $mail->Password = $fromPassword;
 $mail->SMTPSecure = SMTP_SECURE;
 $mail->Port = SMTP_PORT;

 $mail->setFrom($fromEmail, $fromName);
 $mail->addAddress($to);
 $mail->addReplyTo($fromEmail, $fromName);

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
 * Fallback to PHP mail()
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

// ============================================================
// CUSTOMER-FACING STATUS TEMPLATES
// ============================================================

/**
 * PRINTING — one message: "we're on it, here's when to expect it"
 */
function generatePrintingEmailHTML($order, $referenceCode, $customerName) {
 $rc = htmlspecialchars($referenceCode);
 $eventName = $order['event']['name'] ?? $order['eventName'] ?? '';
 $dueDate = isset($order['selectedDate']) ? date('l, F j', strtotime($order['selectedDate'])) : '';

 // Due date is the only thing the customer cares about
 $body = '';
 if ($dueDate) {
 $dueDateContent = '<div style="color: #065f46; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Expected Ready By</div>'
 . '<div style="color: #059669; font-size: 20px; font-weight: 700;">' . htmlspecialchars($dueDate) . '</div>';
 if ($eventName) {
 $dueDateContent .= '<div style="color: #065f46; font-size: 13px; margin-top: 8px;">For: ' . htmlspecialchars($eventName) . '</div>';
 }
 $body .= emailHighlightBox($dueDateContent, '#f0fdf4', '1px solid #bbf7d0');
 }

 $body .= emailParagraph('We&#8217;ll email you as soon as it&#8217;s ready. Nothing to do on your end.')
 . emailButton('Track Your Order', 'https://mtcc.print-stuff.ca/status?ref=' . urlencode($referenceCode), EMAIL_BTN_SUCCESS);

 return emailTemplate(
 EMAIL_BAND_INFO, 'In Production',
 'We&#8217;ve Got It From Here', EMAIL_HEADING_INFO,
 'Your poster <strong style="color: #7c3aed;">' . $rc . '</strong> is being printed now.',
 $body
 );
}

/**
 * DELIVERED to MTCC — one message: where to go and what to bring
 */
function generateDeliveredMTCCEmailHTML($order, $referenceCode, $customerName) {
 $rc = htmlspecialchars($referenceCode);

 $building = $order['event']['building'] ?? $order['building'] ?? 'north';
 if ($building === 'south') {
 $locationName = 'MTCC South Building';
 $locationAddress = 'Level 800, 222 Bremner Blvd';
 $postal = 'M5V 3L9';
 } else {
 $locationName = 'MTCC North Building';
 $locationAddress = 'Level 300, 255 Front St West';
 $postal = 'M5V 2W6';
 }

 $eventName = $order['event']['name'] ?? $order['eventName'] ?? '';

 // Location is the only thing that matters
 $locationContent = '<div style="color: #065f46; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px;">Pickup Location</div>'
 . '<div style="color: #047857; font-size: 20px; font-weight: 700; margin-bottom: 6px;">' . htmlspecialchars($locationName) . '</div>'
 . '<div style="color: #065f46; font-size: 14px;">' . htmlspecialchars($locationAddress) . '</div>'
 . '<div style="color: #065f46; font-size: 14px; margin-top: 2px;">Toronto, ON ' . $postal . '</div>';
 if ($eventName) {
 $locationContent .= '<div style="color: #059669; font-size: 12px; margin-top: 10px; padding-top: 10px; border-top: 1px solid #a7f3d0;">Event: ' . htmlspecialchars($eventName) . '</div>';
 }

 $body = emailHighlightBox($locationContent, '#ecfdf5', '2px solid #10b981')
 . emailCalloutWarning('', 'Just give your <strong>name</strong> or <strong>order number ' . $rc . '</strong> at the desk.');

 return emailTemplate(
 EMAIL_BAND_SUCCESS, 'Ready for Pickup',
 'Your Poster is Ready!', EMAIL_HEADING_SUCCESS,
 'Order <strong style="color: #7c3aed;">' . $rc . '</strong> is waiting for you.',
 $body
 );
}

/**
 * DELIVERED to address — one message: it's there
 */
function generateDeliveredAddressEmailHTML($order, $referenceCode, $customerName) {
 $rc = htmlspecialchars($referenceCode);

 $addr = $order['deliveryAddress'] ?? $order['address'] ?? [];
 $addressLine = '';
 if (!empty($addr)) {
 $parts = array_filter([$addr['address'] ?? '', $addr['city'] ?? '', $addr['province'] ?? '']);
 $addressLine = implode(', ', $parts);
 }

 $body = '';
 if ($addressLine) {
 $body .= emailCalloutSuccess('Delivered to', htmlspecialchars($addressLine));
 }

 $body .= emailParagraph('We hope you love the result. If anything doesn&#8217;t look right, just reply to this email and we&#8217;ll make it right.');

 return emailTemplate(
 EMAIL_BAND_SUCCESS, 'Delivered',
 'Your Poster Has Arrived!', EMAIL_HEADING_SUCCESS,
 'Order <strong style="color: #7c3aed;">' . $rc . '</strong>',
 $body
 );
}

/**
 * PICKED UP — one message: thank you + review ask
 */
function generatePickedUpEmailHTML($order, $referenceCode, $customerName) {
 $rc = htmlspecialchars($referenceCode);

 // Google review — the ONE action
 $reviewContent = '<div style="color: #854d0e; font-size: 15px; font-weight: 700; margin-bottom: 6px;">Loved your poster?</div>'
 . '<div style="color: #713f12; font-size: 13px; margin-bottom: 14px;">A quick review helps other conference attendees find us.</div>'
 . '<a href="https://g.page/r/CQfNMxfSvVHbEAI/review" style="display: inline-block; padding: 12px 28px; background-color: #f59e0b; color: #ffffff; font-size: 14px; font-weight: 700; text-decoration: none; border-radius: 10px; box-shadow: 0 2px 8px rgba(245,158,11,0.3);">Leave a Google Review</a>';

 $body = emailHighlightBox($reviewContent, '#fefce8', '1px solid #fcd34d')
 . emailParagraph('Thanks for choosing Print Stuff. See you at the next event!');

 return emailTemplate(
 EMAIL_BAND_SUCCESS, 'Order Complete',
 'Thank You!', EMAIL_HEADING_SUCCESS,
 'Order <strong style="color: #7c3aed;">' . $rc . '</strong> &#8212; we hope it looks amazing.',
 $body
 );
}

/**
 * CANCELLED — one message: it's cancelled, here's how to reach us
 */
function generateCancelledEmailHTML($order, $referenceCode, $customerName) {
 $rc = htmlspecialchars($referenceCode);

 $body = emailParagraph('If this was a mistake or you have questions, we&#8217;re just an email away.')
 . emailButton('Contact Us', 'mailto:orders@printstuff.ca?subject=Re: Order ' . urlencode($referenceCode) . ' Cancellation', EMAIL_BTN_PRIMARY);

 return emailTemplate(
 EMAIL_BAND_NEUTRAL, 'Cancelled',
 'Order Cancelled', EMAIL_HEADING_NEUTRAL,
 'Order <strong style="color: #7c3aed;">' . $rc . '</strong> has been cancelled.',
 $body
 );
}

/**
 * REFUNDED — one message: money is coming back, here's how long
 */
function generateRefundedEmailHTML($order, $referenceCode, $customerName) {
 $rc = htmlspecialchars($referenceCode);

 $refundAmount = '';
 if (isset($order['refund']['amount'])) {
 $refundAmount = '$' . number_format($order['refund']['amount'], 2);
 } elseif (isset($order['pricing']['total'])) {
 $refundAmount = '$' . number_format($order['pricing']['total'], 2);
 }

 $refundContent = '<div style="color: #059669; font-size: 28px; font-weight: 700;">' . htmlspecialchars($refundAmount) . '</div>'
 . '<div style="color: #6b7280; font-size: 13px; margin-top: 6px;">5&#8211;10 business days to appear on your statement</div>';

 $body = emailHighlightBox($refundContent, '#f0fdf4', '2px solid #059669')
 . emailParagraph('If you have any questions, just reply to this email.');

 return emailTemplate(
 EMAIL_BAND_NEUTRAL, 'Refund Processed',
 'Your Refund is on Its Way', EMAIL_HEADING_NEUTRAL,
 'Order <strong style="color: #7c3aed;">' . $rc . '</strong>',
 $body
 );
}
