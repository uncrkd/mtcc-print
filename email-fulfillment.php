<?php
/**
 * Fulfillment Email Notifications
 * MTCC Print Services
 * 
 * Sends email notifications for fulfillment workflow events:
 * - price_submitted: Vendor submitted a price → notify admin
 * - price_approved: Admin approved a price → notify vendor
 * - price_rejected: Admin rejected a price → notify vendor
 * - order_confirmed: Vendor confirmed the order → notify admin
 * - order_ready: Vendor marked order ready → notify admin
 * 
 * Location: /email-fulfillment.php (root directory, post-migration)
 * Requires: smtp-config.php, email-status-notifications.php (for sendEmailSMTP)
 */

// Admin notification email (God Mode users monitor this)
define('FULFILLMENT_ADMIN_EMAIL', 'orders@printstuff.ca');
define('FULFILLMENT_ADMIN_NAME', 'Print Stuff Orders');

/**
 * Send a fulfillment notification email.
 *
 * @param string $type One of: price_submitted, price_approved, price_rejected, order_confirmed, order_ready
 * @param string $refCode Order reference code
 * @param array $data Context data (vendor_name, vendor_email, pricing, reason, etc.)
 * @return bool True if email sent
 */
function sendFulfillmentEmail($type, $refCode, $data = []) {
 $vendorName = $data['vendor_name'] ?? 'Vendor';
 $vendorEmail = $data['vendor_email'] ?? '';
 $pricing = $data['pricing'] ?? [];
 $reason = $data['reason'] ?? '';
 $adminName = $data['admin_name'] ?? 'Admin';

 switch ($type) {
 case 'price_submitted':
 $to = FULFILLMENT_ADMIN_EMAIL;
 $subject = "Price Submitted: #{$refCode} by {$vendorName}";
 $html = buildPriceSubmittedEmail($refCode, $vendorName, $pricing);
 break;

 case 'price_approved':
 if (empty($vendorEmail)) return false;
 $to = $vendorEmail;
 $subject = "Price Approved: #{$refCode}";
 $html = buildPriceApprovedEmail($refCode, $vendorName, $pricing, $adminName);
 break;

 case 'price_rejected':
 if (empty($vendorEmail)) return false;
 $to = $vendorEmail;
 $subject = "Price Rejected: #{$refCode} - Action Required";
 $html = buildPriceRejectedEmail($refCode, $vendorName, $pricing, $reason, $adminName);
 break;

 case 'order_confirmed':
 $to = FULFILLMENT_ADMIN_EMAIL;
 $subject = "Order Confirmed: #{$refCode} by {$vendorName}";
 $html = buildOrderConfirmedEmail($refCode, $vendorName, $data);
 break;

 case 'order_ready':
 $to = FULFILLMENT_ADMIN_EMAIL;
 $subject = "Ready for Pickup: #{$refCode}";
 $html = buildOrderReadyEmail($refCode, $vendorName, $data);
 break;

 default:
 error_log("Fulfillment Email: Unknown type '{$type}' for {$refCode}");
 return false;
 }

 // Use existing SMTP function
 if (function_exists('sendEmailSMTP')) {
 return sendEmailSMTP($to, $subject, $html, $refCode, 'orders');
 }

 // Fallback: try PHP mail()
 error_log("Fulfillment Email: sendEmailSMTP not available, trying mail() for {$refCode}");
 $headers = "MIME-Version: 1.0\r\n";
 $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
 $headers .= "From: " . FULFILLMENT_ADMIN_NAME . " <" . FULFILLMENT_ADMIN_EMAIL . ">\r\n";
 return mail($to, $subject, $html, $headers);
}

/**
 * Look up vendor email from vendors.json by vendor_id
 */
function getVendorEmailById($vendorId) {
 $paths = [
 __DIR__ . '/data/vendors.json',
 __DIR__ . '/../data/vendors.json',
 ];
 foreach ($paths as $path) {
 if (file_exists($path)) {
 $data = json_decode(file_get_contents($path), true);
 foreach ($data['vendors'] ?? [] as $v) {
 if ($v['id'] === $vendorId) {
 return [
 'email' => $v['email'] ?? '',
 'email_cc' => $v['email_cc'] ?? '',
 'name' => $v['business_name'] ?? 'Vendor',
 ];
 }
 }
 }
 }
 return null;
}

// ============================================================
// EMAIL TEMPLATE BUILDERS
// ============================================================

function emailWrapper($headerBg, $headerTitle, $headerSub, $bodyContent) {
 $year = date('Y');
 return '<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">
<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr><td>

 <!-- Header -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, ' . $headerBg . '); border-radius: 12px 12px 0 0;">
 <tr><td style="padding: 30px; text-align: center;">
 <div style="color: #ffffff; font-size: 22px; font-weight: 700; margin-bottom: 6px;">' . $headerTitle . '</div>
 <div style="color: rgba(255,255,255,0.8); font-size: 14px;">' . $headerSub . '</div>
 </td></tr>
 </table>

 <!-- Body -->
 <table cellpadding="0" cellspacing="0" style="width: 100%;">
 <tr><td style="padding: 30px;">
 ' . $bodyContent . '
 </td></tr>
 </table>

 <!-- Footer -->
 <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
 <tr><td style="padding: 20px; text-align: center;">
 <div style="color: #6b7280; font-size: 13px; margin-bottom: 8px;">Questions? Contact <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none;">orders@printstuff.ca</a></div>
 <div style="color: #9ca3af; font-size: 11px;">&copy; ' . $year . ' Print Stuff - MTCC Print Services</div>
 </td></tr>
 </table>

</td></tr>
</table>
</body>
</html>';
}

function pricingTable($pricing) {
 $base = number_format(floatval($pricing['base_price'] ?? 0), 2);
 $packing = number_format(floatval($pricing['packing_price'] ?? 0), 2);
 $tax = number_format(floatval($pricing['tax_amount'] ?? 0), 2);
 $total = number_format(floatval($pricing['total'] ?? 0), 2);
 
 $rows = "
 <tr><td style='padding: 8px 12px; color: #6b7280; font-size: 14px;'>Item Price</td>
 <td style='padding: 8px 12px; text-align: right; font-weight: 600; color: #374151;'>\${$base}</td></tr>
 <tr><td style='padding: 8px 12px; color: #6b7280; font-size: 14px;'>Packing Fee</td>
 <td style='padding: 8px 12px; text-align: right; font-weight: 600; color: #374151;'>\${$packing}</td></tr>";
 
 // Additional fees
 if (!empty($pricing['additional_fees']) && is_array($pricing['additional_fees'])) {
 foreach ($pricing['additional_fees'] as $fee) {
 $fLabel = htmlspecialchars($fee['label'] ?? 'Fee');
 $fAmount = number_format(floatval($fee['amount'] ?? 0), 2);
 $rows .= "
 <tr><td style='padding: 8px 12px; color: #6b7280; font-size: 14px;'>{$fLabel}</td>
 <td style='padding: 8px 12px; text-align: right; font-weight: 600; color: #374151;'>\${$fAmount}</td></tr>";
 }
 }
 
 $rows .= "
 <tr><td style='padding: 8px 12px; color: #6b7280; font-size: 14px;'>Tax (13%)</td>
 <td style='padding: 8px 12px; text-align: right; font-weight: 600; color: #374151;'>\${$tax}</td></tr>
 <tr style='border-top: 2px solid #e5e7eb;'>
 <td style='padding: 10px 12px; font-weight: 700; font-size: 15px; color: #1a1625;'>Total</td>
 <td style='padding: 10px 12px; text-align: right; font-weight: 700; font-size: 15px; color: #7c3aed;'>\${$total}</td></tr>";

 return "<table cellpadding='0' cellspacing='0' style='width: 100%; background: #f9fafb; border-radius: 8px; border: 1px solid #e5e7eb; margin: 16px 0;'>{$rows}</table>";
}

// ---- PRICE SUBMITTED (→ Admin) ----
function buildPriceSubmittedEmail($refCode, $vendorName, $pricing) {
 $total = '$' . number_format(floatval($pricing['total'] ?? 0), 2);
 $body = "
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 <strong>" . htmlspecialchars($vendorName) . "</strong> has submitted a price of <strong style='color: #7c3aed;'>{$total}</strong> for order <strong>" . htmlspecialchars($refCode) . "</strong>.
 </p>
 
 <table cellpadding='0' cellspacing='0' style='width: 100%; background-color: #fffbeb; border-radius: 8px; border-left: 4px solid #f59e0b; margin: 16px 0;'>
 <tr><td style='padding: 16px;'>
 <div style='color: #92400e; font-size: 14px; font-weight: 600;'>Action Required</div>
 <div style='color: #78350f; font-size: 13px; margin-top: 4px;'>Review and approve or reject this price in the fulfillment portal.</div>
 </td></tr>
 </table>
 
 " . pricingTable($pricing) . "
 
 <div style='margin-top: 24px; text-align: center;'>
 <a href='https://mtcc.print-stuff.ca/fulfillment/' style='display: inline-block; padding: 14px 32px; background-color: #7c3aed; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px;'>
 Review Price
 </a>
 </div>";

 return emailWrapper('#f59e0b 0%, #d97706 100%', 'Price Submitted', 'Order ' . htmlspecialchars($refCode), $body);
}

// ---- PRICE APPROVED (→ Vendor) ----
function buildPriceApprovedEmail($refCode, $vendorName, $pricing, $adminName) {
 $body = "
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 Hi " . htmlspecialchars($vendorName) . ",
 </p>
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 Your price for order <strong style='color: #7c3aed;'>" . htmlspecialchars($refCode) . "</strong> has been <strong style='color: #059669;'>approved</strong>.
 </p>
 
 <table cellpadding='0' cellspacing='0' style='width: 100%; background-color: #f0fdf4; border-radius: 8px; border-left: 4px solid #10b981; margin: 16px 0;'>
 <tr><td style='padding: 16px;'>
 <div style='color: #065f46; font-size: 14px; font-weight: 600;'>Next Step: Confirm the Job</div>
 <div style='color: #047857; font-size: 13px; margin-top: 4px;'>Please log into the fulfillment portal and confirm this order to begin printing.</div>
 </td></tr>
 </table>
 
 " . pricingTable($pricing) . "
 
 <div style='margin-top: 24px; text-align: center;'>
 <a href='https://mtcc.print-stuff.ca/fulfillment/' style='display: inline-block; padding: 14px 32px; background-color: #10b981; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px;'>
 Confirm Job
 </a>
 </div>";

 return emailWrapper('#10b981 0%, #059669 100%', 'Price Approved', 'Order ' . htmlspecialchars($refCode), $body);
}

// ---- PRICE REJECTED (→ Vendor) ----
function buildPriceRejectedEmail($refCode, $vendorName, $pricing, $reason, $adminName) {
 $body = "
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 Hi " . htmlspecialchars($vendorName) . ",
 </p>
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 Your price for order <strong style='color: #7c3aed;'>" . htmlspecialchars($refCode) . "</strong> was <strong style='color: #dc2626;'>not approved</strong>.
 </p>
 
 <table cellpadding='0' cellspacing='0' style='width: 100%; background-color: #fef2f2; border-radius: 8px; border-left: 4px solid #ef4444; margin: 16px 0;'>
 <tr><td style='padding: 16px;'>
 <div style='color: #991b1b; font-size: 14px; font-weight: 600;'>Reason</div>
 <div style='color: #b91c1c; font-size: 14px; margin-top: 6px;'>" . htmlspecialchars($reason ?: 'No reason provided') . "</div>
 </td></tr>
 </table>
 
 " . pricingTable($pricing) . "
 
 <p style='color: #374151; font-size: 15px; line-height: 1.6; margin: 16px 0;'>
 Please submit a revised price in the fulfillment portal.
 </p>
 
 <div style='margin-top: 24px; text-align: center;'>
 <a href='https://mtcc.print-stuff.ca/fulfillment/' style='display: inline-block; padding: 14px 32px; background-color: #7c3aed; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px;'>
 Submit New Price
 </a>
 </div>";

 return emailWrapper('#ef4444 0%, #dc2626 100%', 'Price Not Approved', 'Order ' . htmlspecialchars($refCode), $body);
}

// ---- ORDER CONFIRMED (→ Admin) ----
function buildOrderConfirmedEmail($refCode, $vendorName, $data) {
 $body = "
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 <strong>" . htmlspecialchars($vendorName) . "</strong> has confirmed order <strong style='color: #7c3aed;'>" . htmlspecialchars($refCode) . "</strong> and printing has started.
 </p>
 
 <table cellpadding='0' cellspacing='0' style='width: 100%; background-color: #eef2ff; border-radius: 8px; border-left: 4px solid #6366f1; margin: 16px 0;'>
 <tr><td style='padding: 16px;'>
 <div style='color: #4f46e5; font-size: 14px; font-weight: 600;'>Status: Printing</div>
 <div style='color: #4338ca; font-size: 13px; margin-top: 4px;'>The vendor has downloaded the file and is actively printing this order.</div>
 </td></tr>
 </table>";

 return emailWrapper('#3b82f6 0%, #2563eb 100%', 'Order Confirmed', 'Order ' . htmlspecialchars($refCode) . ' — Printing Started', $body);
}

// ---- ORDER READY (→ Admin) ----
function buildOrderReadyEmail($refCode, $vendorName, $data) {
 $body = "
 <p style='color: #374151; font-size: 16px; line-height: 1.6; margin: 0 0 16px 0;'>
 Order <strong style='color: #7c3aed;'>" . htmlspecialchars($refCode) . "</strong> has been printed, packed, and is <strong style='color: #059669;'>ready for courier pickup</strong> from <strong>" . htmlspecialchars($vendorName) . "</strong>.
 </p>
 
 <table cellpadding='0' cellspacing='0' style='width: 100%; background-color: #f0fdf4; border-radius: 8px; border-left: 4px solid #10b981; margin: 16px 0;'>
 <tr><td style='padding: 16px;'>
 <div style='color: #065f46; font-size: 14px; font-weight: 600;'>Ready for Pickup</div>
 <div style='color: #047857; font-size: 13px; margin-top: 4px;'>Please arrange courier dispatch for this order.</div>
 </td></tr>
 </table>
 
 <div style='margin-top: 24px; text-align: center;'>
 <a href='https://mtcc.print-stuff.ca/fulfillment/' style='display: inline-block; padding: 14px 32px; background-color: #10b981; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px;'>
 View in Fulfillment
 </a>
 </div>";

 return emailWrapper('#10b981 0%, #059669 100%', 'Ready for Pickup', 'Order ' . htmlspecialchars($refCode), $body);
}
