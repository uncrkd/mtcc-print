<?php
/**
 * Email Template Preview Page
 * Admin-only page to view all email templates with sample data.
 *
 * Location: /admin/email-preview.php
 */

require_once __DIR__ . '/../includes/icons.php';

// Sample order data for previewing templates
$sampleOrder = [
    'referenceCode' => 'SAMPLE-001',
    'customerInfo' => [
        'name' => 'Sarah Johnson',
        'email' => 'sarah@university.ca',
        'phone' => '+1 (416) 555-0123',
        'company' => 'University of Toronto'
    ],
    'event' => [
        'acronym' => 'CPMA',
        'name' => 'Canadian Produce Marketing Association'
    ],
    'dimensions' => ['width' => 48, 'height' => 36],
    'material' => 'poster',
    'selectedDate' => date('Y-m-d', strtotime('+5 days')),
    'deliveryTime' => '3pm',
    'deliveryOption' => 'mtcc',
    'pricing' => [
        'basePrice' => 85.00,
        'deliveryFee' => 0,
        'tax' => 11.05,
        'total' => 96.05,
        'tier' => 'Standard'
    ],
    'submittedAt' => date('Y-m-d H:i:s'),
    'paidAt' => date('Y-m-d H:i:s'),
    'uploadedFile' => [
        'originalName' => 'research-poster-final.pdf',
        'size' => 5242880
    ],
    'status' => 'paid',
    'refund' => [
        'refundAmount' => 96.05,
        'refundType' => 'full',
        'refundReason' => 'customer_request'
    ]
];

// Load template functions
require_once __DIR__ . '/../email-order-confirmation.php';
require_once __DIR__ . '/../email-status-notifications.php';

// Generate all email previews
$templates = [];

// 1. Order Confirmation (customer)
if (function_exists('generateCustomerEmailHTML')) {
    // This function is defined in payment-success.php, not email-order-confirmation.php
    // We'll handle it separately
}

// Try to get the order confirmation template
ob_start();
if (function_exists('generateOrderConfirmationHTML')) {
    $templates['order_confirmation'] = [
        'name' => 'Order Confirmation',
        'recipient' => 'Customer',
        'trigger' => 'After payment or admin resend',
        'html' => generateOrderConfirmationHTML($sampleOrder)
    ];
}
ob_end_clean();

// Status notification templates
$statusTemplates = [
    'printing' => ['name' => 'Printing Status', 'recipient' => 'Customer', 'trigger' => 'Status → printing', 'func' => 'generatePrintingEmailHTML'],
    'delivered_mtcc' => ['name' => 'Ready for Pickup (MTCC)', 'recipient' => 'Customer', 'trigger' => 'Status → delivered (MTCC)', 'func' => 'generateDeliveredMTCCEmailHTML'],
    'delivered_address' => ['name' => 'Delivered to Address', 'recipient' => 'Customer', 'trigger' => 'Status → delivered (address)', 'func' => 'generateDeliveredAddressEmailHTML'],
    'pickedup' => ['name' => 'Order Complete', 'recipient' => 'Customer', 'trigger' => 'Status → pickedup', 'func' => 'generatePickedUpEmailHTML'],
    'cancelled' => ['name' => 'Order Cancelled', 'recipient' => 'Customer', 'trigger' => 'Status → cancelled', 'func' => 'generateCancelledEmailHTML'],
    'refunded' => ['name' => 'Refund Processed', 'recipient' => 'Customer', 'trigger' => 'Status → refunded', 'func' => 'generateRefundedEmailHTML'],
];

foreach ($statusTemplates as $key => $info) {
    if (function_exists($info['func'])) {
        $templates[$key] = [
            'name' => $info['name'],
            'recipient' => $info['recipient'],
            'trigger' => $info['trigger'],
            'html' => call_user_func($info['func'], $sampleOrder)
        ];
    }
}

// Fulfillment templates
if (file_exists(__DIR__ . '/../email-fulfillment.php')) {
    require_once __DIR__ . '/../email-fulfillment.php';

    $fulfillmentTemplates = [
        'price_submitted' => ['name' => 'Price Submitted (to Admin)', 'recipient' => 'Admin', 'trigger' => 'Vendor submits price', 'func' => 'buildPriceSubmittedEmail'],
        'price_approved' => ['name' => 'Price Approved (to Vendor)', 'recipient' => 'Vendor', 'trigger' => 'Admin approves price', 'func' => 'buildPriceApprovedEmail'],
        'price_rejected' => ['name' => 'Price Rejected (to Vendor)', 'recipient' => 'Vendor', 'trigger' => 'Admin rejects price', 'func' => 'buildPriceRejectedEmail'],
        'order_confirmed' => ['name' => 'Order Confirmed (to Admin)', 'recipient' => 'Admin', 'trigger' => 'Vendor confirms order', 'func' => 'buildOrderConfirmedEmail'],
        'order_ready' => ['name' => 'Order Ready (to Admin)', 'recipient' => 'Admin', 'trigger' => 'Vendor marks ready', 'func' => 'buildOrderReadyEmail'],
    ];

    $sampleFulfillmentData = [
        'referenceCode' => 'SAMPLE-001',
        'vendorName' => 'FastPrint Toronto',
        'basePrice' => 45.00,
        'packingFee' => 5.00,
        'additionalFees' => 0,
        'tax' => 6.50,
        'totalPrice' => 56.50,
        'reason' => 'Price exceeds budget. Please revise and resubmit.',
    ];

    foreach ($fulfillmentTemplates as $key => $info) {
        if (function_exists($info['func'])) {
            $templates[$key] = [
                'name' => $info['name'],
                'recipient' => $info['recipient'],
                'trigger' => $info['trigger'],
                'html' => call_user_func($info['func'], $sampleFulfillmentData)
            ];
        }
    }
}

// Payment success emails (defined in payment-success.php)
// These functions are inside payment-success.php so we need to include carefully
$paymentSuccessFuncs = __DIR__ . '/../payment-success.php';
// We can't safely include payment-success.php (it has side effects), so we'll
// define the template inline for preview purposes

// Payment link email
if (file_exists(__DIR__ . '/../send-payment-link.php')) {
    // Can't include directly — has side effects. Preview note added instead.
}

// Vendor order email
if (file_exists(__DIR__ . '/../includes/production-email.php')) {
    require_once __DIR__ . '/../includes/production-email.php';
    if (function_exists('generateVendorEmailHTML')) {
        $templates['vendor_order'] = [
            'name' => 'Vendor Order Assignment',
            'recipient' => 'Vendor',
            'trigger' => 'Admin pushes to preflight',
            'html' => generateVendorEmailHTML($sampleOrder, 'https://mtcc.print-stuff.ca/fulfillment/?token=SAMPLE_TOKEN')
        ];
    }
}

// Vendor reminder email
if (file_exists(__DIR__ . '/../send-reminders.php')) {
    // Can't safely include — has cron logic. Note added.
}

$templateKeys = array_keys($templates);
$activeTemplate = $_GET['template'] ?? ($templateKeys[0] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Template Preview - Print Stuff Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: #f3f4f6;
            min-height: 100vh;
        }
        .page-header {
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: white;
            padding: 20px 32px;
        }
        .page-header h1 { font-size: 1.3rem; font-weight: 700; }
        .page-header p { font-size: 0.8rem; opacity: 0.8; margin-top: 4px; }
        .layout {
            display: flex;
            min-height: calc(100vh - 80px);
        }
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #e5e7eb;
            padding: 16px 0;
            overflow-y: auto;
            flex-shrink: 0;
        }
        .sidebar-section {
            padding: 8px 16px;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            margin-top: 12px;
        }
        .sidebar-section:first-child { margin-top: 0; }
        .sidebar-item {
            display: block;
            padding: 10px 16px;
            text-decoration: none;
            color: #374151;
            font-size: 0.82rem;
            font-weight: 500;
            border-left: 3px solid transparent;
            transition: all 0.15s ease;
        }
        .sidebar-item:hover {
            background: #f9fafb;
            border-left-color: #d1d5db;
        }
        .sidebar-item.active {
            background: #f5f3ff;
            border-left-color: #7c3aed;
            color: #7c3aed;
            font-weight: 700;
        }
        .sidebar-item .item-badge {
            display: inline-block;
            font-size: 0.6rem;
            padding: 1px 6px;
            border-radius: 4px;
            margin-left: 6px;
            font-weight: 600;
        }
        .badge-customer { background: #dcfce7; color: #059669; }
        .badge-vendor { background: #fef3c7; color: #92400e; }
        .badge-admin { background: #ede9fe; color: #7c3aed; }
        .preview-area {
            flex: 1;
            padding: 24px;
            overflow-y: auto;
        }
        .preview-info {
            background: white;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .preview-info-label { font-size: 0.75rem; color: #6b7280; font-weight: 600; }
        .preview-info-value { font-size: 0.85rem; color: #374151; font-weight: 600; }
        .preview-frame {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .preview-frame iframe {
            width: 100%;
            min-height: 700px;
            border: none;
        }
        .no-preview {
            text-align: center;
            padding: 60px;
            color: #6b7280;
        }
        .no-preview-icon { font-size: 3rem; margin-bottom: 12px; }
        .back-link {
            display: inline-block;
            margin-top: 12px;
            padding: 8px 20px;
            background: #7c3aed;
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .back-link:hover { background: #5b21b6; }
        .note-card {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 0.8rem;
            color: #92400e;
        }
        @media (max-width: 768px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100%; border-right: none; border-bottom: 1px solid #e5e7eb; }
        }
    </style>
</head>
<body>
    <div class="page-header">
        <h1><?= ICON_ENVELOPE ?> Email Template Preview</h1>
        <p><?= count($templates) ?> templates loaded &middot; Using sample order SAMPLE-001</p>
    </div>

    <div class="layout">
        <nav class="sidebar">
            <div class="sidebar-section">Customer Emails</div>
            <?php foreach ($templates as $key => $tpl): ?>
                <?php if ($tpl['recipient'] === 'Customer'): ?>
                <a href="?template=<?= $key ?>" class="sidebar-item <?= $activeTemplate === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($tpl['name']) ?>
                    <span class="item-badge badge-customer">Customer</span>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="sidebar-section">Vendor Emails</div>
            <?php foreach ($templates as $key => $tpl): ?>
                <?php if ($tpl['recipient'] === 'Vendor'): ?>
                <a href="?template=<?= $key ?>" class="sidebar-item <?= $activeTemplate === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($tpl['name']) ?>
                    <span class="item-badge badge-vendor">Vendor</span>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="sidebar-section">Admin Emails</div>
            <?php foreach ($templates as $key => $tpl): ?>
                <?php if ($tpl['recipient'] === 'Admin'): ?>
                <a href="?template=<?= $key ?>" class="sidebar-item <?= $activeTemplate === $key ? 'active' : '' ?>">
                    <?= htmlspecialchars($tpl['name']) ?>
                    <span class="item-badge badge-admin">Admin</span>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>

            <div class="sidebar-section">Not Previewable</div>
            <div class="sidebar-item" style="color: #9ca3af; font-size: 0.75rem;">
                Order Confirmation (payment-success.php)<br>
                Payment Link (send-payment-link.php)<br>
                Vendor Reminders (send-reminders.php)<br>
                Webhook Emails (stripe-webhook.php)
            </div>
        </nav>

        <main class="preview-area">
            <?php if ($activeTemplate && isset($templates[$activeTemplate])): ?>
                <?php $tpl = $templates[$activeTemplate]; ?>

                <div class="preview-info">
                    <div>
                        <div class="preview-info-label">Template</div>
                        <div class="preview-info-value"><?= htmlspecialchars($tpl['name']) ?></div>
                    </div>
                    <div>
                        <div class="preview-info-label">Recipient</div>
                        <div class="preview-info-value"><?= htmlspecialchars($tpl['recipient']) ?></div>
                    </div>
                    <div>
                        <div class="preview-info-label">Trigger</div>
                        <div class="preview-info-value"><?= htmlspecialchars($tpl['trigger']) ?></div>
                    </div>
                </div>

                <div class="note-card">
                    Preview uses sample data (SAMPLE-001, Sarah Johnson, 48" x 36" poster). Actual emails use real order data.
                </div>

                <div class="preview-frame">
                    <iframe srcdoc="<?= htmlspecialchars($tpl['html']) ?>"></iframe>
                </div>

            <?php elseif ($activeTemplate): ?>
                <div class="no-preview">
                    <div class="no-preview-icon">&#9888;&#65039;</div>
                    <p>Template "<?= htmlspecialchars($activeTemplate) ?>" not found or could not be loaded.</p>
                </div>
            <?php else: ?>
                <div class="no-preview">
                    <div class="no-preview-icon"><?= ICON_ENVELOPE ?></div>
                    <h2 style="margin-bottom: 8px; color: #374151;">Select a template</h2>
                    <p>Choose an email template from the sidebar to preview it.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
