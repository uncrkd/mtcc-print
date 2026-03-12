<?php
/**
 * Customer Order Status Page
 * Public-facing page for customers to track their order status
 * 
 * Accepts:
 * - Reference code (e.g., NFS-005)
 * - Tracking number (e.g., MTCCNFS00525010)
 * - URL parameter: ?ref=NFS-005
 */

error_reporting(0); // Hide errors from public
ini_set('display_errors', 0);

// Include utilities if available
if (file_exists(__DIR__ . '/utilities.php')) {
    require_once __DIR__ . '/utilities.php';
}

// Include icon library
if (file_exists(__DIR__ . '/includes/icons.php')) {
    require_once __DIR__ . '/includes/icons.php';
}

// Status configuration with customer-friendly messages
$statusConfig = [
    'unpaid' => [
        'label' => 'Awaiting Payment',
        'icon' => '&#9203;',
        'color' => '#eab308',
        'message' => 'Your order is awaiting payment. Please complete payment to proceed.',
        'step' => 1
    ],
    'paid' => [
        'label' => 'Payment Received',
        'icon' => '&#128176;',
        'color' => '#059669',
        'message' => 'Payment confirmed! Your order is in the queue.',
        'step' => 2
    ],
    'preflight' => [
        'label' => 'Processing',
        'icon' => '&#128065;',
        'color' => '#8b5cf6',
        'message' => 'Your file is being reviewed and prepared for printing.',
        'step' => 2
    ],
    'file_issue' => [
        'label' => 'File Review',
        'icon' => '&#128065;',
        'color' => '#ea580c',
        'message' => 'We\'re reviewing your file. We\'ll contact you if there are any issues.',
        'step' => 2
    ],
    'printing' => [
        'label' => 'Printing',
        'icon' => '&#128424;',
        'color' => '#0284c7',
        'message' => 'Your poster is being printed!',
        'step' => 3
    ],
    'ready' => [
        'label' => 'Printed',
        'icon' => '&#128230;',
        'color' => '#0d9488',
        'message' => 'Your poster has been printed and is ready for delivery.',
        'step' => 3
    ],
    'dispatched' => [
        'label' => 'Out for Delivery',
        'icon' => '&#128666;',
        'color' => '#7c3aed',
        'message' => 'Your poster is on its way to the delivery location.',
        'step' => 4
    ],
    'shipped' => [
        'label' => 'Out for Delivery',
        'icon' => '&#128666;',
        'color' => '#14b8a6',
        'message' => 'Your order is on its way to the delivery location.',
        'step' => 4
    ],
    'delivered' => [
        'label' => 'Ready for Pickup',
        'icon' => '&#128230;',
        'color' => '#92400e',
        'message' => 'Your poster has arrived and is ready for pickup at the delivery location.',
        'step' => 5
    ],
    'pickedup' => [
        'label' => 'Picked Up',
        'icon' => '&#9989;',
        'color' => '#22c55e',
        'message' => 'Your order has been picked up. Thank you!',
        'step' => 5
    ],
    'unclaimed' => [
        'label' => 'Ready for Pickup',
        'icon' => '&#128236;',
        'color' => '#ec4899',
        'message' => 'Your order is ready and waiting for pickup.',
        'step' => 4
    ],
    'missing' => [
        'label' => 'Attention Required',
        'icon' => '&#9888;',
        'color' => '#dc2626',
        'message' => 'Please contact us regarding your order.',
        'step' => 0
    ],
    'cancelled' => [
        'label' => 'Cancelled',
        'icon' => '&#10006;',
        'color' => '#6b7280',
        'message' => 'This order has been cancelled.',
        'step' => 0
    ],
    'refunded' => [
        'label' => 'Refunded',
        'icon' => '&#128683;',
        'color' => '#dc2626',
        'message' => 'This order has been refunded.',
        'step' => 0
    ]
];

// Progress steps for timeline
$progressSteps = [
    1 => ['label' => 'Order Placed', 'icon' => '&#128269;'],
    2 => ['label' => 'Processing', 'icon' => '&#9881;'],
    3 => ['label' => 'Printing', 'icon' => '&#128424;'],
    4 => ['label' => 'Ready/Shipped', 'icon' => '&#128230;'],
    5 => ['label' => 'Complete', 'icon' => '&#9989;']
];

/**
 * Find order by reference code or tracking number
 */
function findOrderForTracking($input) {
    $input = strtoupper(trim($input));
    
    // Remove common separators and clean up
    $cleaned = preg_replace('/[^A-Z0-9]/', '', $input);
    
    // Check if it looks like a tracking number (starts with MTCC)
    if (strpos($cleaned, 'MTCC') === 0) {
        // Extract reference code from tracking number
        // Format: MTCC + EVENT + ORDER_NUM + DATE (e.g., MTCCNFS00525010)
        // Try to find order by matching the middle part
        $orderDir = 'uploads/orders/';
        if (is_dir($orderDir)) {
            $files = glob($orderDir . '*-order.json');
            foreach ($files as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data && isset($data['referenceCode'])) {
                    // Generate tracking number for this order and compare
                    $orderTracking = generateTrackingForComparison($data);
                    if ($orderTracking === $cleaned) {
                        return $data;
                    }
                }
            }
        }
    }
    
    // Try as reference code
    // Add hyphen if missing (NFS005 -> NFS-005)
    $refCode = $input;
    if (preg_match('/^([A-Z]+)(\d+)$/', $cleaned, $matches)) {
        $refCode = $matches[1] . '-' . $matches[2];
    }
    
    // Search for order file
    $orderDir = 'uploads/orders/';
    if (is_dir($orderDir)) {
        $files = glob($orderDir . '*-order.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['referenceCode'])) {
                $orderRef = strtoupper($data['referenceCode']);
                $searchRef = strtoupper($refCode);
                
                // Exact match or match without hyphen
                if ($orderRef === $searchRef || 
                    str_replace('-', '', $orderRef) === str_replace('-', '', $searchRef)) {
                    return $data;
                }
            }
        }
    }
    
    return null;
}

/**
 * Generate tracking number for comparison
 */
function generateTrackingForComparison($order) {
    $eventPrefix = '';
    if (isset($order['event']['acronym'])) {
        $eventPrefix = $order['event']['acronym'];
    }
    
    $orderNumber = '001';
    if (preg_match('/(\d+)$/', $order['referenceCode'], $matches)) {
        $orderNumber = str_pad($matches[1], 3, '0', STR_PAD_LEFT);
    }
    
    $dateStr = '';
    if (isset($order['selectedDate'])) {
        $dateStr = date('ymd', strtotime($order['selectedDate']));
    }
    
    return 'MTCC' . $eventPrefix . $orderNumber . $dateStr;
}

/**
 * Get order status from statuses.json
 */
function getOrderStatus($referenceCode) {
    $statusFile = 'data/statuses.json';
    if (file_exists($statusFile)) {
        $statuses = json_decode(file_get_contents($statusFile), true);
        return $statuses[$referenceCode] ?? 'unpaid';
    }
    return 'unpaid';
}

/**
 * Get delivery location display text
 */
function getDeliveryLocationText($order) {
    $option = $order['deliveryOption'] ?? 'pickup';
    
    if ($option === 'pickup' || $option === 'mtcc') {
        $building = $order['mtccBuilding'] ?? 'North';
        return "MTCC $building Building - Business Center, Level 300";
    }if ($option === 'pickup' || $option === 'mtcc2') {
        $building = $order['mtccBuilding'] ?? 'South';
        return "MTCC $building Building - Business Center, Level 800";
    } elseif ($option === 'office' && isset($order['deliveryAddress'])) {
        $addr = $order['deliveryAddress'];
        return ($addr['company'] ?? '') . ' - ' . ($addr['city'] ?? 'Toronto');
    }
    
    return 'Metro Toronto Convention Centre';
}

// Handle lookup
$order = null;
$error = null;
$searchInput = '';

if (isset($_GET['ref']) && !empty($_GET['ref'])) {
    $searchInput = $_GET['ref'];
    $order = findOrderForTracking($searchInput);
    if (!$order) {
        $error = 'Order not found. Please check your reference code and try again.';
    }
} elseif (isset($_POST['lookup']) && !empty($_POST['code'])) {
    $searchInput = $_POST['code'];
    $order = findOrderForTracking($searchInput);
    if (!$order) {
        $error = 'Order not found. Please check your reference code and try again.';
    }
}

// Get status if order found
$currentStatus = null;
$statusInfo = null;
if ($order) {
    $currentStatus = getOrderStatus($order['referenceCode']);
    $statusInfo = $statusConfig[$currentStatus] ?? $statusConfig['unpaid'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Order - PrintStuff.ca</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 50%, #f0fdfa 100%);
            min-height: 100vh;
            color: #1f2937;
        }
        
        .header {
            background: white;
            padding: 15px 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }
        
        .header img {
            height: 45px;
        }
        
        .header-divider {
            width: 2px;
            height: 35px;
            background: #e5e7eb;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 30px 20px;
        }
        
        .search-card {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .search-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #059669;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .search-subtitle {
            color: #6b7280;
            font-size: 0.95rem;
            margin-bottom: 20px;
        }
        
        .search-form {
            display: flex;
            gap: 10px;
        }
        
        .search-input {
            flex: 1;
            padding: 14px 18px;
            font-size: 1.1rem;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            font-family: inherit;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        
        .search-input:focus {
            outline: none;
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.1);
        }
        
        .search-input::placeholder {
            text-transform: none;
            letter-spacing: normal;
            color: #9ca3af;
        }
        
        .search-btn {
            padding: 14px 24px;
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            font-family: inherit;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 15px 20px;
            border-radius: 10px;
            margin-top: 15px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Order Status Display */
        .status-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        
        .status-header {
            padding: 25px 30px;
            color: white;
            text-align: center;
        }
        
        .status-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        .status-label {
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .status-message {
            font-size: 0.95rem;
            opacity: 0.95;
        }
        
        .order-ref {
            background: rgba(255,255,255,0.2);
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
            margin-top: 12px;
            font-weight: 600;
        }
        
        /* Progress Timeline */
        .progress-timeline {
            padding: 25px 30px;
            background: #f9fafb;
        }
        
        .timeline-title {
            font-size: 0.85rem;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .timeline {
            display: flex;
            justify-content: space-between;
            position: relative;
        }
        
        .timeline::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 25px;
            right: 25px;
            height: 3px;
            background: #e5e7eb;
        }
        
        .timeline-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            position: relative;
            z-index: 1;
            flex: 1;
        }
        
        .step-icon {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            margin-bottom: 8px;
            transition: all 0.3s;
        }
        
        .timeline-step.completed .step-icon {
            background: #10b981;
        }
        
        .timeline-step.current .step-icon {
            background: #059669;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2); }
            50% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0.1); }
        }
        
        .step-label {
            font-size: 0.7rem;
            color: #9ca3af;
            text-align: center;
            font-weight: 500;
        }
        
        .timeline-step.completed .step-label,
        .timeline-step.current .step-label {
            color: #059669;
            font-weight: 600;
        }
        
        /* Order Details */
        .details-section {
            padding: 25px 30px;
        }
        
        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .detail-item.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-label {
            font-size: 0.75rem;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .detail-value {
            font-size: 1rem;
            color: #1f2937;
            font-weight: 500;
        }
        
        .detail-value.highlight {
            color: #059669;
            font-weight: 600;
        }
        
        /* Contact Section */
        .contact-card {
            background: white;
            border-radius: 16px;
            padding: 25px 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
        }
        
        .contact-title {
            font-size: 1rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
        }
        
        .contact-info {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .contact-info a {
            color: #059669;
            text-decoration: none;
            font-weight: 600;
        }
        
        .contact-info a:hover {
            text-decoration: underline;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            padding: 30px 20px;
            color: #9ca3af;
            font-size: 0.85rem;
        }
        
        /* Mobile Responsive */
        @media (max-width: 480px) {
            .container {
                padding: 20px 15px;
            }
            
            .search-card, .status-card, .contact-card {
                padding: 20px;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-btn {
                width: 100%;
            }
            
            .status-header {
                padding: 20px;
            }
            
            .progress-timeline, .details-section {
                padding: 20px;
            }
            
            .details-grid {
                grid-template-columns: 1fr;
            }
            
            .timeline-step .step-label {
                font-size: 0.6rem;
            }
            
            .step-icon {
                width: 36px;
                height: 36px;
                font-size: 1rem;
            }
        }
    </style>
    <!-- Icon Library for JavaScript -->
    <?php if (function_exists('outputIconsScript')) outputIconsScript(); ?>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <img src="mtcc-ps-logo.png" alt="MTCC Logo" onerror="this.style.display='none'">
    </div>
    
    <div class="container">
        <!-- Search Card -->
        <div class="search-card">
            <div class="search-title">
                &#128065; Track Your Order
            </div>
            <div class="search-subtitle">
                Enter your order reference code (e.g., NFS-005)
            </div>
            <form method="POST" class="search-form">
                <input type="text" 
                       name="code" 
                       class="search-input" 
                       placeholder="Enter code..." 
                       value="<?= htmlspecialchars($searchInput) ?>"
                       autocomplete="off"
                       id="codeInput">
                <button type="submit" name="lookup" value="1" class="search-btn">
                    Track
                </button>
            </form>
            
            <?php if ($error): ?>
            <div class="error-message">
                <span>&#9888;</span> <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($order && $statusInfo): ?>
        <!-- Status Card -->
        <div class="status-card">
            <div class="status-header" style="background: linear-gradient(135deg, <?= $statusInfo['color'] ?> 0%, <?= adjustBrightness($statusInfo['color'], -20) ?> 100%);">
                <div class="status-icon"><?= $statusInfo['icon'] ?></div>
                <div class="status-label"><?= $statusInfo['label'] ?></div>
                <div class="status-message"><?= $statusInfo['message'] ?></div>
                <div class="order-ref">Order: <?= htmlspecialchars($order['referenceCode']) ?></div>
            </div>
            
            <!-- Progress Timeline -->
            <?php if ($statusInfo['step'] > 0): ?>
            <div class="progress-timeline">
                <div class="timeline-title">Order Progress</div>
                <div class="timeline">
                    <?php foreach ($progressSteps as $stepNum => $step): 
                        $isCompleted = $stepNum < $statusInfo['step'];
                        $isCurrent = $stepNum === $statusInfo['step'];
                        $stepClass = $isCompleted ? 'completed' : ($isCurrent ? 'current' : '');
                    ?>
                    <div class="timeline-step <?= $stepClass ?>">
                        <div class="step-icon"><?= $step['icon'] ?></div>
                        <div class="step-label"><?= $step['label'] ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Order Details -->
            <div class="details-section">
                <div class="details-grid">
                    <?php if (isset($order['event']['name'])): ?>
                    <div class="detail-item">
                        <div class="detail-label">Event</div>
                        <div class="detail-value"><?= htmlspecialchars($order['event']['name']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <div class="detail-label">Due Date</div>
                        <div class="detail-value highlight">
                            <?= date('l, M j, Y', strtotime($order['selectedDate'])) ?>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Poster Size</div>
                        <div class="detail-value">
                            <?= $order['dimensions']['width'] ?>" &times; <?= $order['dimensions']['height'] ?>"
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Material</div>
                        <div class="detail-value"><?= ucfirst($order['material'] ?? 'Poster Paper') ?></div>
                    </div>
                    
                    <div class="detail-item full-width">
                        <div class="detail-label">Pickup/Delivery Location</div>
                        <div class="detail-value"><?= htmlspecialchars(getDeliveryLocationText($order)) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Contact Card -->
        <div class="contact-card">
            <div class="contact-title">Need Help?</div>
            <div class="contact-info">
                Email us at <a href="mailto:orders@print-stuff.ca">orders@printstuff.ca</a>
                <br>or call us at 437-882-8822
            </div>
        </div>
    </div>
    
    <div class="footer">
        &copy; <?= date('Y') ?> PrintStuff.ca - Poster Printing at MTCC
    </div>
    
    <script>
    // Auto-format reference code input
    document.getElementById('codeInput').addEventListener('input', function(e) {
        let value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        
        // If it looks like a reference code (letters followed by numbers), add hyphen
        if (/^([A-Z]+)(\d+)$/.test(value)) {
            value = value.replace(/^([A-Z]+)(\d+)$/, '$1-$2');
        }
        
        e.target.value = value;
    });
    
    // Allow form submission on enter
    document.getElementById('codeInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.target.form.submit();
        }
    });
    </script>
</body>
</html>
<?php
/**
 * Helper function to adjust color brightness
 */
function adjustBrightness($hex, $percent) {
    $hex = ltrim($hex, '#');
    
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    
    $r = max(0, min(255, $r + ($r * $percent / 100)));
    $g = max(0, min(255, $g + ($g * $percent / 100)));
    $b = max(0, min(255, $b + ($b * $percent / 100)));
    
    return sprintf('#%02x%02x%02x', $r, $g, $b);
}
?>
