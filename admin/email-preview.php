<?php
/**
 * Email Template Preview Page
 * Location: /admin/email-preview.php
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/icons.php';
require_once __DIR__ . '/../email-status-notifications.php';

$refCode = 'SAMPLE-001';
$name = 'Sarah Johnson';
$order = [
    'referenceCode' => $refCode,
    'customerInfo' => ['name' => $name, 'email' => 'sarah@university.ca', 'phone' => '+1 416 555 0123', 'company' => 'University of Toronto'],
    'event' => ['acronym' => 'CPMA', 'name' => 'Canadian Produce Marketing Association'],
    'dimensions' => ['width' => 48, 'height' => 36],
    'material' => 'poster',
    'selectedDate' => date('Y-m-d', strtotime('+5 days')),
    'deliveryTime' => '3pm',
    'deliveryOption' => 'mtcc',
    'pricing' => ['basePrice' => 85, 'deliveryFee' => 0, 'tax' => 11.05, 'total' => 96.05, 'tier' => 'Standard'],
    'submittedAt' => date('Y-m-d H:i:s'),
    'paidAt' => date('Y-m-d H:i:s'),
    'status' => 'paid',
    'refund' => ['refundAmount' => 96.05, 'refundType' => 'full', 'refundReason' => 'customer_request', 'refundedAt' => date('Y-m-d H:i:s')]
];

// Build templates
$templates = [];
$templates['printing'] = ['name' => 'Printing Status', 'recipient' => 'Customer', 'trigger' => 'Status to printing', 'html' => generatePrintingEmailHTML($order, $refCode, $name)];
$templates['delivered_mtcc'] = ['name' => 'Ready for Pickup (MTCC)', 'recipient' => 'Customer', 'trigger' => 'Status to delivered (MTCC)', 'html' => generateDeliveredMTCCEmailHTML($order, $refCode, $name)];
$templates['delivered_address'] = ['name' => 'Delivered to Address', 'recipient' => 'Customer', 'trigger' => 'Status to delivered (address)', 'html' => generateDeliveredAddressEmailHTML($order, $refCode, $name)];
$templates['pickedup'] = ['name' => 'Order Complete', 'recipient' => 'Customer', 'trigger' => 'Status to pickedup', 'html' => generatePickedUpEmailHTML($order, $refCode, $name)];
$templates['cancelled'] = ['name' => 'Order Cancelled', 'recipient' => 'Customer', 'trigger' => 'Status to cancelled', 'html' => generateCancelledEmailHTML($order, $refCode, $name)];
$templates['refunded'] = ['name' => 'Refund Processed', 'recipient' => 'Customer', 'trigger' => 'Status to refunded', 'html' => generateRefundedEmailHTML($order, $refCode, $name)];

// Fulfillment emails
if (file_exists(__DIR__ . '/../email-fulfillment.php') && !defined('FULFILLMENT_ADMIN_EMAIL')) {
    require_once __DIR__ . '/../email-fulfillment.php';
    $vn = 'FastPrint Toronto';
    $pr = ['total' => 56.50, 'base' => 45, 'packing' => 5, 'additional' => 0, 'tax' => 6.50];
    $reason = 'Price exceeds budget. Please revise and resubmit.';
    $data = ['status' => 'confirmed'];
    if (function_exists('buildPriceSubmittedEmail')) $templates['price_submitted'] = ['name' => 'Price Submitted', 'recipient' => 'Admin', 'trigger' => 'Vendor submits price', 'html' => buildPriceSubmittedEmail($refCode, $vn, $pr)];
    if (function_exists('buildPriceApprovedEmail')) $templates['price_approved'] = ['name' => 'Price Approved', 'recipient' => 'Vendor', 'trigger' => 'Admin approves', 'html' => buildPriceApprovedEmail($refCode, $vn, $pr, 'Admin')];
    if (function_exists('buildPriceRejectedEmail')) $templates['price_rejected'] = ['name' => 'Price Rejected', 'recipient' => 'Vendor', 'trigger' => 'Admin rejects', 'html' => buildPriceRejectedEmail($refCode, $vn, $pr, $reason, 'Admin')];
    if (function_exists('buildOrderConfirmedEmail')) $templates['order_confirmed'] = ['name' => 'Order Confirmed', 'recipient' => 'Admin', 'trigger' => 'Vendor confirms', 'html' => buildOrderConfirmedEmail($refCode, $vn, $data)];
    if (function_exists('buildOrderReadyEmail')) $templates['order_ready'] = ['name' => 'Order Ready', 'recipient' => 'Admin', 'trigger' => 'Vendor marks ready', 'html' => buildOrderReadyEmail($refCode, $vn, $data)];
}

// Vendor order
if (file_exists(__DIR__ . '/../includes/production-email.php')) {
    @require_once __DIR__ . '/../includes/production-email.php';
    if (function_exists('generateVendorEmailHTML')) {
        $sampleVendor = ['name' => 'FastPrint Toronto', 'email' => 'vendor@fastprint.ca'];
        $templates['vendor_order'] = ['name' => 'Vendor Order Assignment', 'recipient' => 'Vendor', 'trigger' => 'Admin pushes to preflight', 'html' => generateVendorEmailHTML($sampleVendor, $order, 'Please prioritize this order.', 'https://mtcc.print-stuff.ca', 'PREVIEW_TOKEN')];
    }
}

$active = $_GET['template'] ?? array_key_first($templates);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Template Preview</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Montserrat',sans-serif;background:#f3f4f6;min-height:100vh}
        .hdr{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;padding:20px 28px}
        .hdr h1{font-size:1.2rem}.hdr p{font-size:.75rem;opacity:.8;margin-top:4px}
        .wrap{display:flex;min-height:calc(100vh - 72px)}
        .side{width:250px;background:#fff;border-right:1px solid #e5e7eb;padding:10px 0;overflow-y:auto;flex-shrink:0}
        .sec{padding:8px 14px 2px;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#9ca3af;margin-top:10px}
        .sec:first-child{margin-top:0}
        a.it{display:block;padding:7px 14px;text-decoration:none;color:#374151;font-size:.78rem;font-weight:500;border-left:3px solid transparent}
        a.it:hover{background:#f9fafb;border-left-color:#d1d5db}
        a.it.on{background:#f5f3ff;border-left-color:#7c3aed;color:#7c3aed;font-weight:700}
        .bg{display:inline-block;font-size:.52rem;padding:1px 5px;border-radius:3px;margin-left:4px;font-weight:600}
        .bg-c{background:#dcfce7;color:#059669}.bg-v{background:#fef3c7;color:#92400e}.bg-a{background:#ede9fe;color:#7c3aed}
        .main{flex:1;padding:20px;overflow-y:auto}
        .info{background:#fff;border-radius:10px;padding:12px 16px;margin-bottom:12px;display:flex;gap:18px;box-shadow:0 1px 3px rgba(0,0,0,.08);font-size:.78rem}
        .info .l{font-size:.65rem;color:#6b7280;font-weight:600}.info .v{color:#374151;font-weight:600}
        .note{background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:9px 14px;margin-bottom:12px;font-size:.75rem;color:#92400e}
        .fr{background:#fff;border-radius:10px;box-shadow:0 4px 16px rgba(0,0,0,.1);overflow:hidden}
        .fr iframe{width:100%;min-height:800px;border:none}
        .empty{text-align:center;padding:60px;color:#6b7280}
        .sn{padding:8px 14px;font-size:.68rem;color:#9ca3af;line-height:1.4}
        @media(max-width:768px){.wrap{flex-direction:column}.side{width:100%;border-right:none;border-bottom:1px solid #e5e7eb}}
    </style>
</head>
<body>
<div class="hdr"><h1>&#9993;&#65039; Email Template Preview</h1><p><?=count($templates)?> templates &middot; Sample: SAMPLE-001</p></div>
<div class="wrap">
<nav class="side">
<?php
$groups = ['Customer'=>[],'Vendor'=>[],'Admin'=>[]];
foreach ($templates as $k=>$t) $groups[$t['recipient']][$k]=$t;
foreach ($groups as $g=>$items):
    if(empty($items))continue;
    $bc=$g==='Customer'?'c':($g==='Vendor'?'v':'a');
?>
<div class="sec"><?=$g?> Emails</div>
<?php foreach($items as $k=>$t):?>
<a href="?template=<?=$k?>" class="it <?=$active===$k?'on':''?>"><?=htmlspecialchars($t['name'])?> <span class="bg bg-<?=$bc?>"><?=$g?></span></a>
<?php endforeach;endforeach;?>
<div class="sec">Not Previewable</div>
<div class="sn">Order Confirmation<br>Payment Link<br>Vendor Reminders<br>Webhook Emails</div>
</nav>
<main class="main">
<?php if($active && isset($templates[$active])):$t=$templates[$active];?>
<div class="info">
<div><div class="l">Template</div><div class="v"><?=htmlspecialchars($t['name'])?></div></div>
<div><div class="l">Recipient</div><div class="v"><?=$t['recipient']?></div></div>
<div><div class="l">Trigger</div><div class="v"><?=htmlspecialchars($t['trigger'])?></div></div>
</div>
<div class="note">Preview uses sample data. Actual emails use real order data.</div>
<div class="fr"><iframe srcdoc="<?=htmlspecialchars($t['html'])?>"></iframe></div>
<?php else:?>
<div class="empty"><div style="font-size:3rem;margin-bottom:12px">&#9993;&#65039;</div><h2 style="color:#374151;margin-bottom:8px">Select a template</h2><p>Choose from the sidebar to preview.</p></div>
<?php endif;?>
</main>
</div>
</body>
</html>
