<?php
/**
 * Fulfillment Email Notifications
 * MTCC Print Services
 *
 * Location: /email-fulfillment.php (root directory, post-migration)
 * Requires: smtp-config.php, email-status-notifications.php (for sendEmailSMTP)
 */

require_once __DIR__ . '/includes/email-template.php';

define('FULFILLMENT_ADMIN_EMAIL', 'orders@printstuff.ca');
define('FULFILLMENT_ADMIN_NAME', 'Print Stuff Orders');

/**
 * Send a fulfillment notification email.
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

 if (function_exists('sendEmailSMTP')) {
 return sendEmailSMTP($to, $subject, $html, $refCode, 'orders');
 }

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
// FULFILLMENT TEMPLATE BUILDERS
// One message, one action per email.
// ============================================================

// ---- PRICE SUBMITTED (→ Admin) — action: approve or reject ----
function buildPriceSubmittedEmail($refCode, $vendorName, $pricing) {
 $total = '$' . number_format(floatval($pricing['total'] ?? 0), 2);
 $rc = htmlspecialchars($refCode);
 $vn = htmlspecialchars($vendorName);

 $body = emailPricingTable($pricing)
 . emailButton('Review Price', 'https://mtcc.print-stuff.ca/fulfillment/', EMAIL_BTN_PRIMARY);

 return emailTemplate(
 EMAIL_BAND_WARNING, 'Price Submitted',
 'Review Required', EMAIL_HEADING_WARNING,
 '<strong>' . $vn . '</strong> submitted <strong style="color: #7c3aed;">' . $total . '</strong> for order <strong>' . $rc . '</strong>.',
 $body
 );
}

// ---- PRICE APPROVED (→ Vendor) — action: confirm the job ----
function buildPriceApprovedEmail($refCode, $vendorName, $pricing, $adminName) {
 $rc = htmlspecialchars($refCode);

 $body = emailPricingTable($pricing)
 . emailButton('Confirm Job', 'https://mtcc.print-stuff.ca/fulfillment/', EMAIL_BTN_SUCCESS);

 return emailTemplate(
 EMAIL_BAND_SUCCESS, 'Approved',
 'Price Approved', EMAIL_HEADING_SUCCESS,
 'Your price for <strong style="color: #7c3aed;">' . $rc . '</strong> has been approved. Please confirm to begin printing.',
 $body
 );
}

// ---- PRICE REJECTED (→ Vendor) — action: resubmit ----
function buildPriceRejectedEmail($refCode, $vendorName, $pricing, $reason, $adminName) {
 $rc = htmlspecialchars($refCode);

 $body = emailCalloutError('Reason', htmlspecialchars($reason ?: 'No reason provided'))
 . emailPricingTable($pricing)
 . emailButton('Submit New Price', 'https://mtcc.print-stuff.ca/fulfillment/', EMAIL_BTN_PRIMARY);

 return emailTemplate(
 EMAIL_BAND_ERROR, 'Not Approved',
 'Revision Needed', EMAIL_HEADING_ERROR,
 'Your price for <strong style="color: #7c3aed;">' . $rc . '</strong> was not approved. Please resubmit.',
 $body
 );
}

// ---- ORDER CONFIRMED (→ Admin) — informational, no action ----
function buildOrderConfirmedEmail($refCode, $vendorName, $data) {
 $rc = htmlspecialchars($refCode);
 $vn = htmlspecialchars($vendorName);

 $body = emailCalloutInfo('', 'The vendor has downloaded the file and is actively printing this order.');

 return emailTemplate(
 EMAIL_BAND_INFO, 'Printing Started',
 'Order Confirmed', EMAIL_HEADING_INFO,
 '<strong>' . $vn . '</strong> confirmed <strong style="color: #7c3aed;">' . $rc . '</strong> &#8212; printing is underway.',
 $body
 );
}

// ---- ORDER READY (→ Admin) — action: arrange dispatch ----
function buildOrderReadyEmail($refCode, $vendorName, $data) {
 $rc = htmlspecialchars($refCode);
 $vn = htmlspecialchars($vendorName);

 $body = emailParagraph('Printed, packed, and ready for courier pickup from <strong>' . $vn . '</strong>.')
 . emailButton('Arrange Dispatch', 'https://mtcc.print-stuff.ca/fulfillment/', EMAIL_BTN_SUCCESS);

 return emailTemplate(
 EMAIL_BAND_SUCCESS, 'Ready',
 'Ready for Pickup', EMAIL_HEADING_SUCCESS,
 'Order <strong style="color: #7c3aed;">' . $rc . '</strong>',
 $body
 );
}
