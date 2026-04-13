<?php
/**
 * Production Email - Vendor Order Email Templates
 * MTCC Print Services
 *
 * Location: /includes/production-email.php
 */

require_once __DIR__ . '/email-template.php';

function sendVendorOrderEmail($vendor, $order, $notes, $basePath, $token = null) {
    $to = $vendor['email'];
    $cc = !empty($vendor['email_cc']) ? $vendor['email_cc'] : null;

    $refCode = $order['referenceCode'];
    $subject = "Print Order: {$refCode} - Print Stuff";

    $html = generateVendorEmailHTML($vendor, $order, $notes, $basePath, $token);

    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Print Stuff Orders <orders@printstuff.ca>',
        'Reply-To: orders@printstuff.ca'
    ];

    if ($cc) {
        $headers[] = "Cc: {$cc}";
    }

    return mail($to, $subject, $html, implode("\r\n", $headers));
}

function generateVendorEmailHTML($vendor, $order, $notes, $basePath, $token = null) {
    $refCode = $order['referenceCode'];
    $rc = htmlspecialchars($refCode);
    $width = $order['dimensions']['width'] ?? 0;
    $height = $order['dimensions']['height'] ?? 0;
    $material = ucfirst($order['material'] ?? 'paper');
    $dueDate = isset($order['selectedDate']) ? date('D, M j', strtotime($order['selectedDate'])) : 'TBD';
    $_etl = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $_edt = $order['deliveryTime'] ?? 'anytime';
    $dueDate .= ($_edt && $_edt !== 'anytime') ? ' at ' . ($_etl[$_edt] ?? $_edt) : ' at anytime';
    $tier = $order['pricing']['tier'] ?? 'standard';

    $origName = $order['uploadedFile']['originalName'] ?? 'No file';
    $fileName = function_exists('getDisplayFileName') ? getDisplayFileName($refCode, $origName) : $origName;
    $fileSize = isset($order['uploadedFile']['size']) ? formatFileSizeEmail($order['uploadedFile']['size']) : '';

    $portalLink = $token
        ? "https://mtcc.print-stuff.ca/vendor-portal.php?token=" . urlencode($token)
        : "https://mtcc.print-stuff.ca/admin-orders.php?download=" . urlencode($refCode);

    $downloadLink = $token
        ? "https://mtcc.print-stuff.ca/vendor-download.php?token=" . urlencode($token)
        : "https://mtcc.print-stuff.ca/admin-orders.php?download=" . urlencode($refCode);

    // Urgency badge — only for rush tiers
    $body = '';
    if ((strpos(strtolower($tier), 'same') !== false || strpos(strtolower($tier), 'last minute') !== false || strpos(strtolower($tier), 'critical') !== false || strpos(strtolower($tier), 'next') !== false)) {
        $body .= '<div style="text-align: center; margin-bottom: 16px;"><span style="display: inline-block; background: #dc2626; color: white; padding: 6px 16px; border-radius: 6px; font-weight: 700; text-transform: uppercase; font-size: 12px; letter-spacing: 0.5px;">URGENT &#8212; ' . strtoupper(htmlspecialchars($tier)) . '</span></div>';
    }

    // Order details — the essentials
    $body .= emailSummaryBox(
        emailDetailRow('Due Date', $dueDate)
        . emailDetailRow('Dimensions', $width . '" x ' . $height . '"')
        . emailDetailRow('Material', $material)
        . emailDetailRow('Priority', ucfirst($tier))
    );

    // Notes only if present
    if (!empty($notes)) {
        $body .= emailCalloutWarning('Special Instructions', nl2br(htmlspecialchars($notes)));
    }

    // Confirm button (token-gated)
    if ($token) {
        $body .= emailButton('View Order & Confirm', $portalLink, EMAIL_BTN_PRIMARY);
        $body .= '<div style="text-align: center; color: #6b7280; font-size: 11px; margin-top: -12px; margin-bottom: 16px;">This link expires in 7 days</div>';
    }

    // File download
    $body .= emailSummaryBox(
        '<div style="margin-bottom: 12px;">'
        . '<strong style="color: #374151;">File:</strong> '
        . htmlspecialchars($fileName) . ($fileSize ? ' (' . $fileSize . ')' : '')
        . '</div>'
        . '<a href="' . htmlspecialchars($downloadLink) . '" style="display: inline-block; background: #10b981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 10px; font-weight: 700; font-size: 14px; box-shadow: 0 2px 8px rgba(16,185,129,0.25);">Download Print File</a>'
    );

    return emailTemplate(
        EMAIL_BAND_INFO, 'New Order',
        'New Print Order', EMAIL_HEADING_INFO,
        'Order <strong style="color: #7c3aed;">#' . $rc . '</strong>',
        $body
    );
}

function formatFileSizeEmail($bytes) {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 1) . ' ' . $sizes[$i];
}
