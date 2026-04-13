<?php
/**
 * Create Stripe Checkout Session
 * This file receives order data (including file upload) and creates a Stripe Checkout session
 */

// Error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Set JSON header
header('Content-Type: application/json');

// Load Stripe
require_once 'vendor/autoload.php';
require_once 'stripe-config.php';
require_once __DIR__ . '/includes/delivery-validation.php';

// Set Stripe API key
\Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Check if this is a multipart form submission or JSON
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'multipart/form-data') !== false) {
        // Form data submission (with file upload)
        $orderDetails = [
            'width' => $_POST['width'] ?? '',
            'height' => $_POST['height'] ?? '',
            'material' => $_POST['material'] ?? 'paper',
            'selectedDate' => $_POST['selectedDate'] ?? '',
            'deliveryTime' => $_POST['deliveryTime'] ?? 'anytime',
            'deliveryOption' => $_POST['deliveryOption'] ?? 'pickup',
        ];
        
        $customerInfo = [
            'name' => $_POST['customerName'] ?? '',
            'company' => $_POST['customerCompany'] ?? '',
            'email' => $_POST['customerEmail'] ?? '',
            'phone' => $_POST['customerPhone'] ?? '',
            'countryCode' => $_POST['countryCode'] ?? '',
            'additionalNotes' => $_POST['additionalNotes'] ?? '',
        ];
        
        $pricing = [
            'basePrice' => (float)($_POST['basePrice'] ?? 0),
            'deliveryFee' => (float)($_POST['deliveryFee'] ?? 0),
            'subtotal' => (float)($_POST['subtotal'] ?? 0),
            'tax' => (float)($_POST['tax'] ?? 0),
            'total' => (float)($_POST['total'] ?? 0),
            'tier' => $_POST['tier'] ?? '',
        ];
        
        $event = [
            'acronym' => $_POST['eventAcronym'] ?? '',
            'name' => $_POST['eventName'] ?? '',
        ];
        
        // Handle delivery address if office delivery
        if ($_POST['deliveryOption'] === 'office') {
            $orderDetails['deliveryAddress'] = [
                'company' => $_POST['deliveryCompany'] ?? '',
                'attn' => $_POST['deliveryAttn'] ?? '',
                'address' => $_POST['deliveryAddress'] ?? '',
                'unit' => $_POST['deliveryUnit'] ?? '',
                'city' => $_POST['deliveryCity'] ?? '',
                'province' => $_POST['deliveryProvince'] ?? '',
                'postal' => $_POST['deliveryPostal'] ?? '',
                'instructions' => $_POST['deliveryInstructions'] ?? '',
            ];
        }
        
    } else {
        // JSON submission
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            throw new Exception('Invalid request data');
        }
        
        $orderDetails = $data['orderDetails'] ?? [];
        $customerInfo = $data['customerInfo'] ?? [];
        $pricing = $data['pricing'] ?? [];
        $event = $data['event'] ?? [];
    }
    
    // Validate required fields
    if (empty($customerInfo['email'])) {
        throw new Exception('Customer email is required');
    }
    
    if (empty($pricing['total']) || $pricing['total'] <= 0) {
        throw new Exception('Invalid order total');
    }
    
    // ===== SERVER-SIDE DELIVERY & PRICING VALIDATION =====
    $validationInput = [
        'width' => $orderDetails['width'] ?? 0,
        'height' => $orderDetails['height'] ?? 0,
        'material' => $orderDetails['material'] ?? 'poster',
        'selectedDate' => $orderDetails['selectedDate'] ?? '',
        'deliveryTime' => $orderDetails['deliveryTime'] ?? 'anytime',
        'basePrice' => $pricing['basePrice'] ?? 0,
        'total' => $pricing['total'] ?? 0,
        'tier' => $pricing['tier'] ?? '',
        'deliveryFee' => $pricing['deliveryFee'] ?? 0,
    ];

    $validation = validateOrderPricing($validationInput);

    if (!$validation['valid']) {
        throw new Exception($validation['message']);
    }

    // If server recalculated a different price, use the server price
    if ($validation['corrected']) {
        $pricing['basePrice'] = $validation['server_price'];
        $pricing['tier'] = $validation['server_tier']['label'] ?? $pricing['tier'];

        // Recalculate totals with server price
        $pricing['subtotal'] = $pricing['basePrice'] + $pricing['deliveryFee'];
        $pricing['tax'] = round($pricing['subtotal'] * 0.13, 2);
        $pricing['total'] = round($pricing['subtotal'] + $pricing['tax'], 2);

        error_log('Order pricing corrected by server validation. New total: $' . $pricing['total']);
    }
    
    // Generate a temporary order reference for tracking
    $tempOrderRef = 'TEMP-' . uniqid();
    
    // Handle file upload if present
    $uploadedFileInfo = null;
    if (isset($_FILES['artwork']) && $_FILES['artwork']['error'] === UPLOAD_ERR_OK) {
        $uploadedFile = $_FILES['artwork'];
        $originalName = $uploadedFile['name'];
        $fileExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        
        // Validate file type
        $allowedTypes = ['pdf', 'ai', 'eps', 'psd', 'png', 'jpg', 'jpeg', 'tiff', 'tif', 'webp', 'gif', 'bmp', 'svg', 'pptx', 'indd'];
        if (!in_array($fileExt, $allowedTypes)) {
            throw new Exception('Invalid file type. Please upload a supported file format.');
        }
        
        // Validate file size (100MB limit)
        if ($uploadedFile['size'] > 100 * 1024 * 1024) {
            throw new Exception('File is too large. Maximum file size is 100MB.');
        }
        
        // Create temp upload directory
        $tempDir = __DIR__ . '/uploads/temp/';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        
        // Save to temp location
        $tempFileName = $tempOrderRef . '_' . time() . '.' . $fileExt;
        $tempPath = $tempDir . $tempFileName;
        
        if (move_uploaded_file($uploadedFile['tmp_name'], $tempPath)) {
            $uploadedFileInfo = [
                'originalName' => $originalName,
                'tempPath' => 'uploads/temp/' . $tempFileName,
                'size' => $uploadedFile['size'],
                'type' => $uploadedFile['type'],
            ];
        } else {
            throw new Exception('Failed to upload file');
        }
    }
    
    // Store order data in session for retrieval after payment
    session_start();
    $_SESSION['pending_order_' . $tempOrderRef] = [
        'orderDetails' => $orderDetails,
        'customerInfo' => $customerInfo,
        'pricing' => $pricing,
        'event' => $event,
        'uploadedFile' => $uploadedFileInfo,
        'created_at' => time(),
        'validation' => [
            'server_tier' => $validation['server_tier'],
            'corrected' => $validation['corrected'],
        ]
    ];
    
    // Build line items for Stripe
    $lineItems = [];
    
    // Main poster printing item
    $posterDescription = sprintf(
        '%s - %s" × %s" %s Poster',
        $event['name'] ?? 'Poster',
        $orderDetails['width'] ?? '',
        $orderDetails['height'] ?? '',
        ucfirst($orderDetails['material'] ?? 'Paper')
    );
    
    // Base price (poster printing)
    if (!empty($pricing['basePrice']) && $pricing['basePrice'] > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'Poster Printing - ' . ($pricing['tier'] ?? 'Standard'),
                    'description' => $posterDescription,
                ],
                'unit_amount' => round($pricing['basePrice'] * 100), // Stripe uses cents
            ],
            'quantity' => 1,
        ];
    }
    
    // Delivery fee (if applicable)
    if (!empty($pricing['deliveryFee']) && $pricing['deliveryFee'] > 0) {
        $deliveryOption = $orderDetails['deliveryOption'] ?? 'pickup';
        $deliveryLabel = $deliveryOption === 'office' ? 'Office Delivery' : 'Convention Booth Delivery';
        
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => $deliveryLabel,
                    'description' => 'Delivery to your specified location',
                ],
                'unit_amount' => round($pricing['deliveryFee'] * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Add tax as a line item if applicable
    if (!empty($pricing['tax']) && $pricing['tax'] > 0) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'HST (13%)',
                    'description' => 'Harmonized Sales Tax',
                ],
                'unit_amount' => round($pricing['tax'] * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // If no line items, create one from total
    if (empty($lineItems)) {
        $lineItems[] = [
            'price_data' => [
                'currency' => 'cad',
                'product_data' => [
                    'name' => 'Poster Printing Order',
                    'description' => $posterDescription,
                ],
                'unit_amount' => round($pricing['total'] * 100),
            ],
            'quantity' => 1,
        ];
    }
    
    // Get the base URL for redirects
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $baseUrl = $protocol . '://' . $host;
    
    // Create Stripe Checkout Session
    $checkoutSession = \Stripe\Checkout\Session::create([
        'payment_method_types' => ['card'],
        'line_items' => $lineItems,
        'mode' => 'payment',
        'customer_email' => $customerInfo['email'],
        'success_url' => $baseUrl . '/payment-success.php?sid=' . $tempOrderRef,
        'cancel_url' => $baseUrl . '/payment-cancelled.php?ref=' . $tempOrderRef,
        'metadata' => [
            'temp_order_ref' => $tempOrderRef,
            'event_acronym' => $event['acronym'] ?? '',
            'event_name' => $event['name'] ?? '',
            'poster_size' => ($orderDetails['width'] ?? '') . 'x' . ($orderDetails['height'] ?? ''),
            'material' => $orderDetails['material'] ?? '',
            'customer_name' => $customerInfo['name'] ?? '',
            'customer_phone' => $customerInfo['phone'] ?? '',
        ],
    ]);
    
    // Store the Stripe session ID in our session for verification
    $_SESSION['stripe_session_' . $tempOrderRef] = $checkoutSession->id;
    
    // Return the checkout URL
    echo json_encode([
        'success' => true,
        'checkoutUrl' => $checkoutSession->url,
        'sessionId' => $checkoutSession->id,
        'tempOrderRef' => $tempOrderRef
    ]);
    
} catch (\Stripe\Exception\ApiErrorException $e) {
    // Stripe API error
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Payment service error: ' . $e->getMessage()
    ]);
    error_log('Stripe API Error: ' . $e->getMessage());
    
} catch (Exception $e) {
    // General error
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    error_log('Checkout Session Error: ' . $e->getMessage());
}
?>
