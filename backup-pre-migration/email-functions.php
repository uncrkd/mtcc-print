<?php
require_once __DIR__ . '/includes/icons.php';
/**
 * Email Functions for Print Stuff
 * Handles order confirmation and notification emails
 */

function handleResendConfirmationEmail() {
    if (!isset($_POST['resend_email']) || !isset($_SESSION['admin_logged_in'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    try {
        $referenceCode = $_POST['reference_code'];
        
        // Load orders to find the specific order
        $order = null;
        if (is_dir('uploads/orders/')) {
            $files = glob('uploads/orders/*.json');
            foreach ($files as $file) {
                $content = file_get_contents($file);
                $orderData = json_decode($content, true);
                if ($orderData && $orderData['referenceCode'] === $referenceCode) {
                    $order = $orderData;
                    break;
                }
            }
        }
        
        if (!$order) {
            throw new Exception('Order not found');
        }
        
        $customerEmail = $order['customerInfo']['email'];
        
        // Send confirmation email
        $fromEmail = 'orders@printstuff.ca';
        $fromName = 'Print Stuff Orders';
        
        $subject = "Your Order Details: {$referenceCode}";
        $message = generateCustomerEmailHTML($order, $referenceCode);
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            "From: {$fromName} <{$fromEmail}>",
            "Reply-To: {$fromEmail}",
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $emailSent = mail($customerEmail, $subject, $message, implode("\r\n", $headers));
        
        if ($emailSent) {
            // Log to order history
            if (function_exists('logOrderHistory')) {
                logOrderHistory($referenceCode, 'email_sent', "Order details email sent to $customerEmail", 'Admin');
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => "Order details sent to {$customerEmail}"
            ]);
        } else {
            throw new Exception('Failed to send email');
        }
        
    } catch (Exception $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function generateCustomerEmailHTML($order, $referenceCode) {
    $customerName = isset($order['customerInfo']['name']) ? $order['customerInfo']['name'] : 'Customer';
    $customerEmail = isset($order['customerInfo']['email']) ? $order['customerInfo']['email'] : '';
    $customerPhone = isset($order['customerInfo']['phone']) ? $order['customerInfo']['phone'] : '';
    $customerCompany = isset($order['customerInfo']['company']) ? $order['customerInfo']['company'] : '';
    
    $eventName = isset($order['event']['name']) ? $order['event']['name'] : 'Event';
    $width = isset($order['dimensions']['width']) ? $order['dimensions']['width'] : '0';
    $height = isset($order['dimensions']['height']) ? $order['dimensions']['height'] : '0';
    $material = isset($order['material']) ? ucfirst($order['material']) : 'Standard';
    $tier = isset($order['pricing']['tier']) ? $order['pricing']['tier'] : 'Standard';
    
    $submittedDate = isset($order['submittedAt']) ? date('M j, Y', strtotime($order['submittedAt'])) : date('M j, Y');
    $dueDate = isset($order['selectedDate']) ? date('D, M j, Y', strtotime($order['selectedDate'])) : date('M j');
    
    $basePrice = isset($order['pricing']['basePrice']) ? number_format($order['pricing']['basePrice'], 2) : '0.00';
    $deliveryFee = isset($order['pricing']['deliveryFee']) ? number_format($order['pricing']['deliveryFee'], 2) : '0.00';
    $conversionFee = isset($order['pricing']['conversionFee']) ? number_format($order['pricing']['conversionFee'], 2) : '0.00';
    $tax = isset($order['pricing']['tax']) ? number_format($order['pricing']['tax'], 2) : '0.00';
    $total = isset($order['pricing']['total']) ? number_format($order['pricing']['total'], 2) : '0.00';
    
    $deliveryOption = isset($order['deliveryOption']) ? strtolower(trim($order['deliveryOption'])) : 'pickup';
    if ($deliveryOption === 'mtcc' || strpos($deliveryOption, 'mtcc') !== false || $deliveryOption === 'pickup') {
        $deliveryMethod = 'MTCC Pickup (Free)';
        $deliveryNote = 'Ready for pickup by 4:00 PM';
    } else {
        $deliveryMethod = 'Address Delivery (+$10.00)';
        $deliveryNote = 'Delivered by 4:00 PM';
    }
    
    $customerNotes = '';
    if (isset($order['customerInfo']['notes']) && !empty($order['customerInfo']['notes'])) {
        $customerNotes = $order['customerInfo']['notes'];
    } elseif (isset($order['notes']) && !empty($order['notes'])) {
        $customerNotes = $order['notes'];
    }
    
    // Enhanced delivery instructions search
    $deliveryInstructions = '';
    if (isset($order['deliveryInstructions']) && !empty($order['deliveryInstructions'])) {
        $deliveryInstructions = $order['deliveryInstructions'];
    } elseif (isset($order['delivery']['instructions']) && !empty($order['delivery']['instructions'])) {
        $deliveryInstructions = $order['delivery']['instructions'];
    } elseif (isset($order['specialInstructions']) && !empty($order['specialInstructions'])) {
        $deliveryInstructions = $order['specialInstructions'];
    } elseif (isset($order['deliveryNotes']) && !empty($order['deliveryNotes'])) {
        $deliveryInstructions = $order['deliveryNotes'];
    } elseif (isset($order['instructions']) && !empty($order['instructions'])) {
        $deliveryInstructions = $order['instructions'];
    }
    
    $currentYear = date('Y');
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmation: ' . $referenceCode . '</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #faf8ff; }
        table { border-collapse: collapse; }
    </style>
</head>
<body style="font-family: Arial, sans-serif; margin: 0; padding: 20px; background-color: #faf8ff;">

<table cellpadding="0" cellspacing="0" style="width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
<tr>
<td>

    <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #7c3aed; border-radius: 12px 12px 0 0;">
    <tr>
    <td style="padding: 25px 30px; text-align: center;">
        <div style="color: #ffffff; font-size: 28px; font-weight: 700; margin-bottom: 6px;">Order Confirmed</div>
        <div style="color: #e9d5ff; font-size: 15px; font-weight: 500; margin-bottom: 16px;">Thank you for choosing Print Stuff</div>
        <div style="background-color: rgba(255,255,255,0.2); border-radius: 20px; padding: 8px 16px; display: inline-block;">
            <span style="color: #ffffff; font-size: 16px; font-weight: 600;">#' . $referenceCode . '</span>
        </div>
    </td>
    </tr>
    </table>
    
    <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-bottom: 1px solid #e5e7eb;">
    <tr>
    <td style="padding: 16px 30px;">
        <table cellpadding="0" cellspacing="0" style="width: 100%;">
        <tr>
        <td style="width: 33.33%; text-align: center;">
            <div style="color: #6b7280; font-size: 11px; font-weight: 600; margin-bottom: 4px; text-transform: uppercase;">Submitted</div>
            <div style="color: #374151; font-size: 14px; font-weight: 600;">' . $submittedDate . '</div>
        </td>
        <td style="width: 33.33%; text-align: center; border-left: 1px solid #e5e7eb; border-right: 1px solid #e5e7eb;">
            <div style="color: #6b7280; font-size: 11px; font-weight: 600; margin-bottom: 4px; text-transform: uppercase;">Due Date</div>
            <div style="color: #374151; font-size: 14px; font-weight: 600;">' . $dueDate . '</div>
        </td>
        <td style="width: 33.33%; text-align: center;">
            <div style="color: #6b7280; font-size: 11px; font-weight: 600; margin-bottom: 4px; text-transform: uppercase;">Priority</div>
            <div style="background-color: #f0f9ff; color: #0284c7; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; text-transform: uppercase; display: inline-block;">' . $tier . '</div>
        </td>
        </tr>
        </table>
    </td>
    </tr>
    </table>
    
    <table cellpadding="0" cellspacing="0" style="width: 100%;">
    <tr>
    <td style="padding: 30px;">
    
        <!-- Order Details -->
        <div style="margin-bottom: 30px;">
            <div style="color: #7c3aed; font-size: 14px; font-weight: 600; margin-bottom: 12px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“<?= ICON_COPY ?> Your Order</div>
            <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
            <tr>
            <td style="padding: 20px;">
                <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                <td style="width: 65%; vertical-align: top;">
                    <div style="color: #374151; font-size: 16px; font-weight: 600; margin-bottom: 4px;">' . $eventName . '</div>
                    <div style="color: #6b7280; font-size: 13px; font-weight: 500; margin-bottom: 8px;">' . $width . '" ÃƒÆ’Ã¢â‚¬â€ ' . $height . '" ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ ' . $material . '</div>
                    <div style="background-color: #faf5ff; color: #7c3aed; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; display: inline-block;">Poster Print</div>
                </td>
                <td style="width: 35%; text-align: right; vertical-align: top;">
                    <div style="color: #374151; font-size: 20px; font-weight: 700;">$' . $total . '</div>
                    <div style="color: #6b7280; font-size: 12px; font-weight: 500; margin-top: 2px;">Total (incl. tax)</div>
                </td>
                </tr>
                </table>
            </td>
            </tr>
            </table>
        </div>
        
        <!-- Two Column Layout -->
        <table cellpadding="0" cellspacing="0" style="width: 100%;">
        <tr>
        
        <!-- Left Column: Customer Details -->
        <td style="width: 48%; vertical-align: top; padding-right: 15px;">
            <div style="color: #7c3aed; font-size: 14px; font-weight: 600; margin-bottom: 12px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬ËœÃ‚Â¤ Customer Details</div>
            <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
            <tr>
            <td style="padding: 20px;">
                <div style="margin-bottom: 10px;">
                    <span style="color: #6b7280; font-size: 11px; font-weight: 500;">Name: </span>
                    <span style="color: #374151; font-size: 13px; font-weight: 500;">' . $customerName . '</span>
                </div>';
    
    if (!empty($customerCompany)) {
        $html .= '<div style="margin-bottom: 10px;">
                    <span style="color: #6b7280; font-size: 11px; font-weight: 500;">Company: </span>
                    <span style="color: #374151; font-size: 13px; font-weight: 500;">' . $customerCompany . '</span>
                </div>';
    }
    
    $html .= '<div style="margin-bottom: 10px;">
                    <span style="color: #6b7280; font-size: 11px; font-weight: 500;">Email: </span>
                    <span style="color: #374151; font-size: 13px; font-weight: 500;">' . $customerEmail . '</span>
                </div>
                <div style="margin-bottom: 10px;">
                    <span style="color: #6b7280; font-size: 11px; font-weight: 500;">Phone: </span>
                    <span style="color: #374151; font-size: 13px; font-weight: 500;">' . $customerPhone . '</span>
                </div>';
    
    if (!empty($customerNotes)) {
        $html .= '<div style="margin-top: 15px; padding: 12px; background-color: #fefce8; border-left: 3px solid #eab308; border-radius: 6px;">
                    <div style="color: #a16207; font-size: 11px; font-weight: 600; margin-bottom: 4px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â Customer Note</div>
                    <div style="color: #92400e; font-size: 12px;">' . $customerNotes . '</div>
                </div>';
    }
    
    $html .= '</td>
            </tr>
            </table>
        </td>
        
        <!-- Right Column: Delivery Details -->
        <td style="width: 48%; vertical-align: top; padding-left: 15px;">
            <div style="color: #7c3aed; font-size: 14px; font-weight: 600; margin-bottom: 12px;">ÃƒÂ°Ã…Â¸Ã…Â¡Ã…Â¡ Delivery Details</div>
            <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
            <tr>
            <td style="padding: 20px;">
                
                <!-- Delivery Method -->
                <div style="margin-bottom: 15px;">
                    <span style="color: #6b7280; font-size: 11px; font-weight: 500;">Method: </span>
                    <span style="color: #374151; font-size: 13px; font-weight: 500;">' . $deliveryMethod . '</span>
                </div>
                
                <!-- Delivery Note -->
                <div style="margin-bottom: 15px; padding: 10px; background-color: #e0f2fe; border-radius: 6px;">
                    <div style="color: #0c4a6e; font-size: 12px; font-weight: 500;">' . $deliveryNote . '</div>
                </div>';

    // Add delivery address if not pickup
    if ($deliveryOption !== 'mtcc' && $deliveryOption !== 'pickup' && strpos($deliveryOption, 'mtcc') === false) {
        $html .= '
                <!-- Delivery Address -->
                <div style="margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 3px solid #0284c7; border-radius: 6px;">
                    <div style="color: #0369a1; font-size: 11px; font-weight: 600; margin-bottom: 8px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â Delivery Address</div>
                    <div style="color: #0c4a6e; font-size: 12px; line-height: 1.6;">';
        
        // Name
        $html .= $customerName . '<br>';
        
        // Company (if exists)
        if (!empty($customerCompany)) {
            $html .= $customerCompany . '<br>';
        }
        
        // Extract address components with comprehensive field checking
        $addressLines = [];
        
        // Debug: Try to access the raw order data for address fields
        if (isset($order['deliveryAddress'])) {
            if (is_array($order['deliveryAddress'])) {
                // Street/Address line
                if (!empty($order['deliveryAddress']['street'])) {
                    $addressLines[] = $order['deliveryAddress']['street'];
                } elseif (!empty($order['deliveryAddress']['address'])) {
                    $addressLines[] = $order['deliveryAddress']['address'];
                } elseif (!empty($order['deliveryAddress']['streetAddress'])) {
                    $addressLines[] = $order['deliveryAddress']['streetAddress'];
                } elseif (!empty($order['deliveryAddress']['line1'])) {
                    $addressLines[] = $order['deliveryAddress']['line1'];
                }
                
                // Unit/Suite/Apartment
                if (!empty($order['deliveryAddress']['unit'])) {
                    $addressLines[] = $order['deliveryAddress']['unit'];
                } elseif (!empty($order['deliveryAddress']['suite'])) {
                    $addressLines[] = $order['deliveryAddress']['suite'];
                } elseif (!empty($order['deliveryAddress']['apt'])) {
                    $addressLines[] = $order['deliveryAddress']['apt'];
                } elseif (!empty($order['deliveryAddress']['apartment'])) {
                    $addressLines[] = $order['deliveryAddress']['apartment'];
                } elseif (!empty($order['deliveryAddress']['line2'])) {
                    $addressLines[] = $order['deliveryAddress']['line2'];
                }
                
                // City and Province on same line
                $cityProvinceLine = '';
                if (!empty($order['deliveryAddress']['city'])) {
                    $cityProvinceLine = $order['deliveryAddress']['city'];
                    if (!empty($order['deliveryAddress']['province'])) {
                        $cityProvinceLine .= ', ' . $order['deliveryAddress']['province'];
                    } elseif (!empty($order['deliveryAddress']['state'])) {
                        $cityProvinceLine .= ', ' . $order['deliveryAddress']['state'];
                    }
                    $addressLines[] = $cityProvinceLine;
                }
                
                // Postal Code - try all possible field names
                $postalCode = '';
                if (!empty($order['deliveryAddress']['postalCode'])) {
                    $postalCode = $order['deliveryAddress']['postalCode'];
                } elseif (!empty($order['deliveryAddress']['postal_code'])) {
                    $postalCode = $order['deliveryAddress']['postal_code'];
                } elseif (!empty($order['deliveryAddress']['postcode'])) {
                    $postalCode = $order['deliveryAddress']['postcode'];
                } elseif (!empty($order['deliveryAddress']['zipcode'])) {
                    $postalCode = $order['deliveryAddress']['zipcode'];
                } elseif (!empty($order['deliveryAddress']['zip'])) {
                    $postalCode = $order['deliveryAddress']['zip'];
                } elseif (!empty($order['deliveryAddress']['postalcode'])) {
                    $postalCode = $order['deliveryAddress']['postalcode'];
                }
                
                if (!empty($postalCode)) {
                    $addressLines[] = $postalCode;
                }
                
                // If no structured data found, try to extract all non-empty values
                if (empty($addressLines)) {
                    foreach ($order['deliveryAddress'] as $key => $value) {
                        if (is_string($value) && !empty(trim($value)) && strtolower($value) !== 'array') {
                            $addressLines[] = $value;
                        }
                    }
                }
            } elseif (is_string($order['deliveryAddress']) && !empty(trim($order['deliveryAddress']))) {
                $addressLines[] = $order['deliveryAddress'];
            }
        }
        
        // Try top-level address fields as fallback
        if (empty($addressLines)) {
            if (!empty($order['street'])) {
                $addressLines[] = $order['street'];
            }
            if (!empty($order['unit'])) {
                $addressLines[] = $order['unit'];
            }
            if (!empty($order['city']) && !empty($order['province'])) {
                $addressLines[] = $order['city'] . ', ' . $order['province'];
            }
            if (!empty($order['postalCode'])) {
                $addressLines[] = $order['postalCode'];
            } elseif (!empty($order['postal_code'])) {
                $addressLines[] = $order['postal_code'];
            }
        }
        
        // Display address lines or fallback message
        if (!empty($addressLines)) {
            foreach ($addressLines as $line) {
                $html .= $line . '<br>';
            }
        } else {
            $html .= 'Address details to be confirmed<br>';
        }
        
        $html .= '
                    </div>
                </div>';
        
        // Add delivery instructions if they exist
        if (!empty($deliveryInstructions)) {
            $html .= '
                <!-- Delivery Instructions -->
                <div style="margin-bottom: 15px; padding: 12px; background-color: #f0f9ff; border-left: 3px solid #0284c7; border-radius: 6px;">
                    <div style="color: #0369a1; font-size: 11px; font-weight: 600; margin-bottom: 4px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“<?= ICON_COPY ?> Delivery Instructions</div>
                    <div style="color: #0c4a6e; font-size: 12px; line-height: 1.4;">' . $deliveryInstructions . '</div>
                </div>';
        }
    }
    
    $html .= '
            </td>
            </tr>
            </table>
        </td>
        
        </tr>
        </table>
        
        <!-- Separate Pricing Section -->
        <div style="margin-top: 30px;">
            <div style="color: #7c3aed; font-size: 14px; font-weight: 600; margin-bottom: 12px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬Ã‚Â° Pricing Breakdown</div>
            <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">
            <tr>
            <td style="padding: 20px;">
                <table cellpadding="0" cellspacing="0" style="width: 100%;">
                <tr>
                <td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;">
                    <table cellpadding="0" cellspacing="0" style="width: 100%;">
                    <tr>
                    <td style="color: #6b7280; font-size: 12px;">Base Price</td>
                    <td style="text-align: right; color: #374151; font-size: 12px;">$' . $basePrice . '</td>
                    </tr>
                    </table>
                </td>
                </tr>';
    
    // Add delivery fee if > 0
    if (isset($order['pricing']['deliveryFee']) && $order['pricing']['deliveryFee'] > 0) {
        $html .= '
                <tr>
                <td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;">
                    <table cellpadding="0" cellspacing="0" style="width: 100%;">
                    <tr>
                    <td style="color: #6b7280; font-size: 12px;">Delivery</td>
                    <td style="text-align: right; color: #374151; font-size: 12px;">$' . $deliveryFee . '</td>
                    </tr>
                    </table>
                </td>
                </tr>';
    }
    
    // Add conversion fee if > 0
    if (isset($order['pricing']['conversionFee']) && $order['pricing']['conversionFee'] > 0) {
        $html .= '
                <tr>
                <td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;">
                    <table cellpadding="0" cellspacing="0" style="width: 100%;">
                    <tr>
                    <td style="color: #6b7280; font-size: 12px;">File Conversion</td>
                    <td style="text-align: right; color: #374151; font-size: 12px;">$' . $conversionFee . '</td>
                    </tr>
                    </table>
                </td>
                </tr>';
    }
    
    $html .= '
                <tr>
                <td style="padding: 6px 0; border-bottom: 1px solid #e5e7eb;">
                    <table cellpadding="0" cellspacing="0" style="width: 100%;">
                    <tr>
                    <td style="color: #6b7280; font-size: 12px;">Tax (13%)</td>
                    <td style="text-align: right; color: #374151; font-size: 12px;">$' . $tax . '</td>
                    </tr>
                    </table>
                </td>
                </tr>
                <tr>
                <td style="padding: 10px 0; background-color: #faf5ff; border-radius: 6px;">
                    <table cellpadding="0" cellspacing="0" style="width: 100%;">
                    <tr>
                    <td style="padding: 0 12px; color: #7c3aed; font-size: 14px; font-weight: 700;">Total</td>
                    <td style="padding: 0 12px; text-align: right; color: #7c3aed; font-size: 14px; font-weight: 700;">$' . $total . '</td>
                    </tr>
                    </table>
                </td>
                </tr>
                </table>
            </td>
            </tr>
            </table>
        </div>
        
        <!-- Track Order Button -->
        <div style="margin-top: 25px; text-align: center;">
            <a href="https://mtcc.print-stuff.ca/status?ref=' . $referenceCode . '" 
               style="display: inline-block; padding: 14px 32px; background-color: #10b981; color: #ffffff; font-size: 14px; font-weight: 600; text-decoration: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);">
                ðŸ“ Track Your Order
            </a>
            <div style="color: #6b7280; font-size: 11px; margin-top: 10px;">Check your order status anytime</div>
        </div>
        
        <!-- Support Section -->
        <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb; margin-top: 30px;">
        <tr>
        <td style="padding: 20px; text-align: center;">
            <div style="color: #7c3aed; font-size: 14px; font-weight: 600; margin-bottom: 8px;">ÃƒÂ°Ã…Â¸Ã¢â‚¬Ã‚Â¬ Questions?</div>
            <div style="color: #6b7280; font-size: 13px; font-weight: 400; margin-bottom: 12px;">We are here to help with your order</div>
            <div>
                <a href="mailto:orders@printstuff.ca" style="color: #7c3aed; font-size: 13px; font-weight: 500; text-decoration: none; margin-right: 16px;">orders@printstuff.ca</a>
                <span style="color: #374151; font-size: 13px; font-weight: 500;">(437) 882-8822</span>
            </div>
        </td>
        </tr>
        </table>
        
    </td>
    </tr>
    </table>
    
    <table cellpadding="0" cellspacing="0" style="width: 100%; background-color: #f8fafc; border-radius: 0 0 12px 12px; border-top: 1px solid #e5e7eb;">
    <tr>
    <td style="padding: 20px; text-align: center;">
        <div style="color: #6b7280; font-size: 12px; font-weight: 400;">Ãƒâ€šÃ‚&copy; ' . $currentYear . ' Print Stuff ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ Professional Poster Printing</div>
        <div style="color: #6b7280; font-size: 11px; font-weight: 400; margin-top: 4px;">Reference: <span style="color: #7c3aed; font-weight: 500;">' . $referenceCode . '</span></div>
    </td>
    </tr>
    </table>

</td>
</tr>
</table>

</body>
</html>';

    return $html;
}
?>
