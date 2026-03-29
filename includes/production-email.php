<?php
/**
 * Production Email - Vendor Order Email Templates
 * MTCC Print Services
 *
 * Location: /includes/production-email.php
 * Extracted from: admin/production.php
 */

function sendVendorOrderEmail($vendor, $order, $notes, $basePath, $token = null) {
    $to = $vendor['email'];
    $cc = !empty($vendor['email_cc']) ? $vendor['email_cc'] : null;

    $refCode = $order['referenceCode'];
    $subject = "Print Order: {$refCode} - Print Stuff";

    // Build email body with token for secure access
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
    $customerName = $order['customerInfo']['name'] ?? 'Customer';
    $width = $order['dimensions']['width'] ?? 0;
    $height = $order['dimensions']['height'] ?? 0;
    $material = ucfirst($order['material'] ?? 'paper');
    $dueDate = isset($order['selectedDate']) ? date('D, M j', strtotime($order['selectedDate'])) : 'TBD';
    $_etl = ['9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $_edt = $order['deliveryTime'] ?? 'anytime';
    $dueDate .= ($_edt && $_edt !== 'anytime') ? ' at ' . ($_etl[$_edt] ?? $_edt) : ' at anytime';
    $tier = $order['pricing']['tier'] ?? 'standard';

    // File info
    $origName = $order['uploadedFile']['originalName'] ?? 'No file';
    $fileName = function_exists('getDisplayFileName') ? getDisplayFileName($refCode, $origName) : $origName;
    $fileSize = isset($order['uploadedFile']['size']) ? formatFileSizeEmail($order['uploadedFile']['size']) : '';

    // Build secure portal link with token
    $portalLink = $token
        ? "https://mtcc.print-stuff.ca/vendor-portal.php?token=" . urlencode($token)
        : "https://mtcc.print-stuff.ca/admin-orders.php?download=" . urlencode($refCode);

    $downloadLink = $token
        ? "https://mtcc.print-stuff.ca/vendor-download.php?token=" . urlencode($token)
        : "https://mtcc.print-stuff.ca/admin-orders.php?download=" . urlencode($refCode);

    $notesSection = '';
    if (!empty($notes)) {
        $notesSection = "
        <tr>
            <td style='padding: 15px 20px; background: #fef3c7; border-left: 4px solid #f59e0b;'>
                <strong style='color: #92400e;'>Special Instructions:</strong><br>
                <span style='color: #78350f;'>" . nl2br(htmlspecialchars($notes)) . "</span>
            </td>
        </tr>";
    }

    $urgencyBadge = '';
    if ((strpos(strtolower($tier), 'same') !== false || strpos(strtolower($tier), 'last minute') !== false || strpos(strtolower($tier), 'critical') !== false || strpos(strtolower($tier), 'next') !== false)) {
        $urgencyBadge = "<span style='background: #dc2626; color: white; padding: 4px 12px; border-radius: 4px; font-weight: bold; text-transform: uppercase;'>URGENT - " . strtoupper($tier) . "</span>";
    }

    // Portal section for confirmation (only if token provided)
    $portalSection = '';
    if ($token) {
        $portalSection = "
        <tr>
            <td style='padding: 25px; text-align: center; background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);'>
                <p style='color: #166534; margin: 0 0 15px 0; font-weight: 600;'>
                    ✅ Please confirm receipt of this order
                </p>
                <a href='{$portalLink}' style='display: inline-block; background: #7c3aed; color: white; padding: 16px 32px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;'>
                    View Order & Confirm
                </a>
                <p style='color: #6b7280; margin: 15px 0 0 0; font-size: 12px;'>
                    This link expires in 7 days
                </p>
            </td>
        </tr>";
    }

    $html = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Print Order: {$refCode}</title>
</head>
<body style='font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f3f4f6;'>
    <table cellpadding='0' cellspacing='0' style='max-width: 600px; margin: 0 auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <tr>
            <td style='background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); padding: 30px; text-align: center;'>
                <h1 style='color: white; margin: 0 0 10px 0; font-size: 24px;'>New Print Order</h1>
                <div style='background: rgba(255,255,255,0.2); display: inline-block; padding: 8px 20px; border-radius: 20px;'>
                    <span style='color: white; font-size: 18px; font-weight: bold;'>#{$refCode}</span>
                </div>
                " . ($urgencyBadge ? "<div style='margin-top: 15px;'>{$urgencyBadge}</div>" : "") . "
            </td>
        </tr>

        <tr>
            <td style='padding: 25px;'>
                <table cellpadding='0' cellspacing='0' style='width: 100%;'>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Due Date:</strong>
                            <span style='float: right; color: #111827; font-weight: 600;'>{$dueDate}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Dimensions:</strong>
                            <span style='float: right; color: #111827;'>{$width}\" × {$height}\"</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Material:</strong>
                            <span style='float: right; color: #111827;'>{$material}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; border-bottom: 1px solid #e5e7eb;'>
                            <strong style='color: #6b7280;'>Priority:</strong>
                            <span style='float: right; color: #111827; text-transform: capitalize;'>{$tier}</span>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>

        {$notesSection}

        {$portalSection}

        <tr>
            <td style='padding: 25px; background: #f8fafc;'>
                <div style='margin-bottom: 15px;'>
                    <strong style='color: #374151;'>File:</strong> {$fileName} " . ($fileSize ? "({$fileSize})" : "") . "
                </div>
                <a href='{$downloadLink}' style='display: inline-block; background: #10b981; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                    ⬇️ Download Print File
                </a>
            </td>
        </tr>

        <tr>
            <td style='padding: 20px; background: #f3f4f6; text-align: center; border-top: 1px solid #e5e7eb;'>
                <p style='color: #6b7280; margin: 0 0 10px 0; font-size: 13px;'>
                    Questions? Reply to this email or call (437) 882-8822
                </p>
                <p style='color: #9ca3af; margin: 0; font-size: 12px;'>
                    Print Stuff • Metro Toronto Convention Centre
                </p>
            </td>
        </tr>
    </table>
</body>
</html>";

    return $html;
}

function formatFileSizeEmail($bytes) {
    if ($bytes === 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 1) . ' ' . $sizes[$i];
}
