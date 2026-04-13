<?php
/**
 * Shared Email Template System
 * MTCC Print Services
 *
 * Location: /includes/email-template.php
 *
 * Unified email template matching the payment-success.php confirmation page:
 *   Logo (outside card) → card: colored band with badge → heading + subtitle → body → contact → footer
 *
 * Font: Montserrat via Google Fonts (renders in Apple Mail, iOS, Outlook.com; falls back to system sans-serif in Gmail)
 * Card: 480px, 16px radius, purple-tinted shadow — exact match to success page
 */

// Status band color presets (CSS gradient stops)
define('EMAIL_BAND_SUCCESS', '#10b981 0%, #059669 100%');
define('EMAIL_BAND_INFO',    '#7c3aed 0%, #6d28d9 100%');
define('EMAIL_BAND_WARNING', '#f59e0b 0%, #d97706 100%');
define('EMAIL_BAND_ERROR',   '#ef4444 0%, #dc2626 100%');
define('EMAIL_BAND_NEUTRAL', '#6b7280 0%, #4b5563 100%');
define('EMAIL_BAND_BLUE',    '#3b82f6 0%, #2563eb 100%');

// Heading color presets
define('EMAIL_HEADING_SUCCESS', '#059669');
define('EMAIL_HEADING_INFO',    '#7c3aed');
define('EMAIL_HEADING_WARNING', '#d97706');
define('EMAIL_HEADING_ERROR',   '#dc2626');
define('EMAIL_HEADING_NEUTRAL', '#4b5563');
define('EMAIL_HEADING_BLUE',    '#2563eb');

// Button color presets
define('EMAIL_BTN_PRIMARY', '#7c3aed');
define('EMAIL_BTN_SUCCESS', '#10b981');
define('EMAIL_BTN_WARNING', '#f59e0b');

/**
 * Build a complete email HTML document.
 *
 * @param string $bandGradient   CSS gradient stops (EMAIL_BAND_*)
 * @param string $badgeText      Short label in frosted pill on the band
 * @param string $heading        Big heading below band
 * @param string $headingColor   Heading color (EMAIL_HEADING_*)
 * @param string $subtitle       Subtitle text (HTML allowed)
 * @param string $bodyContent    HTML body content
 * @return string Complete HTML email
 */
function emailTemplate($bandGradient, $badgeText, $heading, $headingColor, $subtitle, $bodyContent) {
    $year = date('Y');
    $logoUrl = 'https://mtcc.print-stuff.ca/mtcc-ps-logo.png';
    $fontStack = "'Montserrat', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif";

    return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body style="font-family: ' . $fontStack . '; margin: 0; padding: 20px; background: linear-gradient(135deg, #faf8ff 0%, #ede9fe 100%); -webkit-text-size-adjust: 100%;">

<table cellpadding="0" cellspacing="0" style="width: 100%;">
<tr><td align="center">

    <!-- Logo — outside the card -->
    <table cellpadding="0" cellspacing="0" style="margin-bottom: 20px;">
    <tr>
    <td align="center">
        <img src="' . $logoUrl . '" alt="MTCC + Print Stuff" style="max-width: 360px; width: 90%; height: auto;" />
    </td>
    </tr>
    </table>

    <!-- Card -->
    <table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 480px; background-color: #ffffff; border-radius: 16px; box-shadow: 0 8px 32px rgba(124,58,237,0.15); overflow: hidden;">
    <tr><td>

        <!-- Status band -->
        <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, ' . $bandGradient . ');">
        <tr>
        <td style="padding: 28px 32px; text-align: center;">
            <div style="display: inline-block; background: rgba(255,255,255,0.2); color: #ffffff; padding: 8px 22px; border-radius: 20px; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px;">' . $badgeText . '</div>
        </td>
        </tr>
        </table>

        <!-- Card body -->
        <table cellpadding="0" cellspacing="0" style="width: 100%;">
        <tr>
        <td style="padding: 32px 32px 28px; text-align: center;">

            <div style="color: ' . $headingColor . '; font-size: 28px; font-weight: 700; margin-bottom: 8px; font-family: ' . $fontStack . ';">' . $heading . '</div>

            <div style="color: #374151; font-size: 15px; line-height: 1.6; margin-bottom: 24px;">' . $subtitle . '</div>

            <div style="text-align: left;">
            ' . $bodyContent . '
            </div>

            <!-- Contact -->
            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 13px;">
                <div style="font-weight: 600; margin-bottom: 4px;">Questions? We&#8217;re here.</div>
                <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; text-decoration: none; font-weight: 600;">orders@printstuff.ca</a>
                <span style="display: inline-block; width: 1px; height: 12px; background: #d1d5db; vertical-align: middle; margin: 0 6px;"></span>
                <a href="tel:4378828822" style="color: #7c3aed; text-decoration: none; font-weight: 600;">(437) 882-8822</a>
                <span style="display: inline-block; width: 1px; height: 12px; background: #d1d5db; vertical-align: middle; margin: 0 6px;"></span>
                <a href="https://tawk.to/chat/69bcadcf600a121c36fa7a4b/1jk4gdsmg" style="color: #7c3aed; text-decoration: none; font-weight: 600;">Live Chat</a>
            </div>

        </td>
        </tr>
        </table>

        <!-- Footer -->
        <table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #fafbfc 0%, #ffffff 100%); border-top: 1px solid #f3f4f6;">
        <tr>
        <td style="padding: 16px 32px; text-align: center; color: #9ca3af; font-size: 11px;">
            &copy; ' . $year . ' Print Stuff &middot; Big or small, we print it all.
        </td>
        </tr>
        </table>

    </td></tr>
    </table>

</td></tr>
</table>

</body>
</html>';
}

/**
 * CTA button — matches .btn from success page (12px radius, 700 weight, purple shadow).
 */
function emailButton($text, $url, $color = '#7c3aed') {
    return '<div style="margin: 20px 0; text-align: center;">
        <a href="' . htmlspecialchars($url) . '" style="display: inline-block; padding: 14px 32px; background-color: ' . $color . '; color: #ffffff; font-size: 15px; font-weight: 700; text-decoration: none; border-radius: 10px; box-shadow: 0 2px 8px rgba(124,58,237,0.25);">'
        . htmlspecialchars($text) .
        '</a>
    </div>';
}

/**
 * Callout box with left border accent.
 */
function emailCallout($title, $message, $bgColor, $borderColor, $titleColor, $textColor) {
    return '<table cellpadding="0" cellspacing="0" style="width: 100%; background-color: ' . $bgColor . '; border-radius: 10px; border-left: 3px solid ' . $borderColor . '; margin: 16px 0;">
    <tr>
    <td style="padding: 14px 16px;">
        ' . ($title ? '<div style="color: ' . $titleColor . '; font-size: 13px; font-weight: 700; margin-bottom: 4px;">' . $title . '</div>' : '') . '
        <div style="color: ' . $textColor . '; font-size: 14px; line-height: 1.5;">' . $message . '</div>
    </td>
    </tr>
    </table>';
}

function emailCalloutSuccess($title, $message) {
    return emailCallout($title, $message, '#f0fdf4', '#10b981', '#065f46', '#047857');
}
function emailCalloutWarning($title, $message) {
    return emailCallout($title, $message, '#fffbeb', '#f59e0b', '#92400e', '#78350f');
}
function emailCalloutError($title, $message) {
    return emailCallout($title, $message, '#fef2f2', '#ef4444', '#991b1b', '#b91c1c');
}
function emailCalloutInfo($title, $message) {
    return emailCallout($title, $message, '#eef2ff', '#6366f1', '#4f46e5', '#4338ca');
}
function emailCalloutNeutral($title, $message) {
    return emailCallout($title, $message, '#f3f4f6', '#9ca3af', '#374151', '#6b7280');
}
function emailCalloutPurple($title, $message) {
    return emailCallout($title, $message, '#faf5ff', '#7c3aed', '#6b21a8', '#7e22ce');
}

/**
 * Summary box — matches .order-summary / .next-steps from success page.
 */
function emailSummaryBox($content, $title = '') {
    $titleHtml = $title
        ? '<div style="font-size: 13px; font-weight: 700; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px;">' . $title . '</div>'
        : '';
    return '<table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border: 1px solid #e5e7eb; border-radius: 10px; margin: 16px 0;">
    <tr>
    <td style="padding: 20px;">
        ' . $titleHtml . $content . '
    </td>
    </tr>
    </table>';
}

/**
 * Detail row — matches .order-detail-row from success page (14px, 500/600 weight).
 */
function emailDetailRow($label, $value, $isTotal = false) {
    if ($isTotal) {
        return '<table cellpadding="0" cellspacing="0" style="width: 100%; margin-top: 8px; border-top: 1px solid #e5e7eb;">
        <tr>
            <td style="padding: 10px 0 0; color: #6b7280; font-size: 14px; font-weight: 500;">' . htmlspecialchars($label) . '</td>
            <td style="padding: 10px 0 0; text-align: right; color: #059669; font-size: 17px; font-weight: 700;">' . htmlspecialchars($value) . '</td>
        </tr></table>';
    }
    return '<table cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
        <td style="padding: 4px 0; color: #6b7280; font-size: 14px; font-weight: 500;">' . htmlspecialchars($label) . '</td>
        <td style="padding: 4px 0; text-align: right; color: #1e1b2e; font-size: 14px; font-weight: 600;">' . htmlspecialchars($value) . '</td>
    </tr></table>';
}

/**
 * Centered highlight box (pickup locations, refund amounts, etc.)
 */
function emailHighlightBox($content, $bgColor, $border) {
    return '<table cellpadding="0" cellspacing="0" style="width: 100%; background-color: ' . $bgColor . '; border-radius: 10px; border: ' . $border . '; margin: 16px 0;">
    <tr>
    <td style="padding: 20px; text-align: center;">
        ' . $content . '
    </td>
    </tr>
    </table>';
}

/**
 * Vendor pricing breakdown table.
 */
function emailPricingTable($pricing) {
    $base = number_format(floatval($pricing['base_price'] ?? $pricing['base'] ?? 0), 2);
    $packing = number_format(floatval($pricing['packing_price'] ?? $pricing['packing'] ?? 0), 2);
    $tax = number_format(floatval($pricing['tax_amount'] ?? $pricing['tax'] ?? 0), 2);
    $total = number_format(floatval($pricing['total'] ?? 0), 2);

    $rows = '
    <tr><td style="padding: 8px 12px; color: #6b7280; font-size: 14px;">Item Price</td>
        <td style="padding: 8px 12px; text-align: right; font-weight: 600; color: #1e1b2e; font-size: 14px;">$' . $base . '</td></tr>
    <tr><td style="padding: 8px 12px; color: #6b7280; font-size: 14px;">Packing Fee</td>
        <td style="padding: 8px 12px; text-align: right; font-weight: 600; color: #1e1b2e; font-size: 14px;">$' . $packing . '</td></tr>';

    if (!empty($pricing['additional_fees']) && is_array($pricing['additional_fees'])) {
        foreach ($pricing['additional_fees'] as $fee) {
            $fLabel = htmlspecialchars($fee['label'] ?? 'Fee');
            $fAmount = number_format(floatval($fee['amount'] ?? 0), 2);
            $rows .= '
    <tr><td style="padding: 8px 12px; color: #6b7280; font-size: 14px;">' . $fLabel . '</td>
        <td style="padding: 8px 12px; text-align: right; font-weight: 600; color: #1e1b2e; font-size: 14px;">$' . $fAmount . '</td></tr>';
        }
    }

    $rows .= '
    <tr><td style="padding: 8px 12px; color: #6b7280; font-size: 14px;">Tax (13%)</td>
        <td style="padding: 8px 12px; text-align: right; font-weight: 600; color: #1e1b2e; font-size: 14px;">$' . $tax . '</td></tr>
    <tr>
        <td style="padding: 10px 12px; border-top: 1px solid #e5e7eb; font-weight: 700; font-size: 14px; color: #6b7280;">Total</td>
        <td style="padding: 10px 12px; border-top: 1px solid #e5e7eb; text-align: right; font-weight: 700; font-size: 17px; color: #059669;">$' . $total . '</td></tr>';

    return '<table cellpadding="0" cellspacing="0" style="width: 100%; background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border-radius: 10px; border: 1px solid #e5e7eb; margin: 16px 0;">' . $rows . '</table>';
}

/**
 * Paragraph — matches success page body text (15px, #374151).
 */
function emailParagraph($text) {
    return '<p style="color: #374151; font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">' . $text . '</p>';
}
