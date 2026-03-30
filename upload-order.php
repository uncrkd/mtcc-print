<?php
require_once __DIR__ . '/includes/icons.php';
// FIXED VERSION - HTTP 500 Error Resolution
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include required utilities
if (file_exists(__DIR__ . '/includes/utilities.php')) {
    require_once __DIR__ . '/includes/utilities.php';
}
require_once __DIR__ . '/includes/delivery-validation.php';

// ===== BASIC UTILITY FUNCTIONS =====

function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    return round($bytes, $precision) . ' ' . $units[$i];
}

function validateEnhancedPhone($phoneNumber, $countryCode = null) {
    if (empty($phoneNumber)) {
        return false;
    }
    
    // Remove all non-digit characters except + for validation
    $cleaned = preg_replace('/[^\d+]/', '', $phoneNumber);
    
    // Basic validation - between 7-15 digits
    $digitCount = strlen(preg_replace('/[^\d]/', '', $cleaned));
    return $digitCount >= 7 && $digitCount <= 15;
}

function validateEventAcronym($eventAcronym) {
    if (empty($eventAcronym)) {
        return false;
    }
    
    try {
        // Check if events file exists
        $eventsFile = __DIR__ . '/admin/events.json';
        if (!file_exists($eventsFile)) {
            error_log('Events file not found at: ' . $eventsFile);
            // Fallback validation - accept any reasonable acronym
            return preg_match('/^[A-Z0-9]{2,10}$/', $eventAcronym);
        }
        
        $eventsContent = file_get_contents($eventsFile);
        $eventsData = json_decode($eventsContent, true);
        
        if (!$eventsData || !isset($eventsData['active']) || !is_array($eventsData['active'])) {
            error_log('Invalid events data structure');
            return preg_match('/^[A-Z0-9]{2,10}$/', $eventAcronym);
        }
        
        // Check if the event acronym exists in active events
        foreach ($eventsData['active'] as $event) {
            if (isset($event['acronym']) && $event['acronym'] === $eventAcronym) {
                return true;
            }
        }
        
        error_log("Event acronym '$eventAcronym' not found in active events");
        return false;
        
    } catch (Exception $e) {
        error_log('Error validating event acronym: ' . $e->getMessage());
        // Fallback validation
        return preg_match('/^[A-Z0-9]{2,10}$/', $eventAcronym);
    }
}

function getEventName($eventAcronym) {
    try {
        $eventsFile = __DIR__ . '/admin/events.json';
        if (!file_exists($eventsFile)) {
            return $eventAcronym; // Return acronym as fallback
        }
        
        $eventsContent = file_get_contents($eventsFile);
        $eventsData = json_decode($eventsContent, true);
        
        if (!$eventsData || !isset($eventsData['active']) || !is_array($eventsData['active'])) {
            return $eventAcronym;
        }
        
        // Find the event name
        foreach ($eventsData['active'] as $event) {
            if (isset($event['acronym']) && $event['acronym'] === $eventAcronym) {
                return $event['name'] ?? $eventAcronym;
            }
        }
        
        return $eventAcronym; // Fallback to acronym if not found
        
    } catch (Exception $e) {
        error_log('Error getting event name: ' . $e->getMessage());
        return $eventAcronym;
    }
}

function generateReferenceCode($eventAcronym) {
    $counterFile = __DIR__ . '/data/order_counter.txt';
    
    // Read current counters
    $counters = [];
    if (file_exists($counterFile) && is_readable($counterFile)) {
        $content = file_get_contents($counterFile);
        if (!empty($content)) {
            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $counters = $decoded;
            }
        }
    }
    
    // Get current counter for this event (default to 0 if not exists)
    $currentCount = isset($counters[$eventAcronym]) && is_numeric($counters[$eventAcronym]) ? (int)$counters[$eventAcronym] : 0;
    $newCount = $currentCount + 1;
    
    // Generate reference code with event prefix
    $referenceCode = $eventAcronym . '-' . str_pad($newCount, 3, '0', STR_PAD_LEFT);
    
    // Update and save counters
    $counters[$eventAcronym] = $newCount;
    
    // Check if directory is writable
    $dir = dirname($counterFile);
    if (!is_writable($dir)) {
        error_log("Directory not writable: $dir");
        chmod($dir, 0755); // Try to make it writable
    }
    
    file_put_contents($counterFile, json_encode($counters, JSON_PRETTY_PRINT));
    
    return $referenceCode;
}

// ===== EMAIL FUNCTIONS =====

function sendOrderEmails($orderData, $uploadedFile) {
    $fromEmail = 'orders@printstuff.ca';
    $fromName = 'Print Stuff Orders';
    $toBusinessEmail = 'orders@printstuff.ca';
    $customerEmail = $orderData['customerInfo']['email'];
    
    $orderRef = $orderData['referenceCode'];
    
    try {
        // Send order notification to business
        $businessEmailSent = sendBusinessNotification($orderData, $uploadedFile, $fromEmail, $fromName, $toBusinessEmail, $orderRef);
        
        // Send confirmation to customer
        $customerEmailSent = sendCustomerConfirmation($orderData, $fromEmail, $fromName, $customerEmail, $orderRef);
        
        return [
            'success' => $businessEmailSent && $customerEmailSent,
            'business_email_sent' => $businessEmailSent,
            'customer_email_sent' => $customerEmailSent,
            'order_reference' => $orderRef
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'order_reference' => $orderRef
        ];
    }
}

function sendBusinessNotification($orderData, $uploadedFile, $fromEmail, $fromName, $toEmail, $orderRef) {
    $subject = "New Poster Order: {$orderRef} - {$orderData['event']['name']}";
    
    $message = generateBusinessEmailHTML($orderData, $uploadedFile, $orderRef);
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        "From: {$fromName} <{$fromEmail}>",
        "Reply-To: {$fromEmail}",
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($toEmail, $subject, $message, implode("\r\n", $headers));
}

function sendCustomerConfirmation($orderData, $fromEmail, $fromName, $customerEmail, $orderRef) {
    $subject = "Order Confirmation: {$orderRef} - Your Poster Printing Request";
    
    $message = generateCustomerEmailHTML($orderData, $orderRef);
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        "From: {$fromName} <{$fromEmail}>",
        "Reply-To: {$fromEmail}",
        'X-Mailer: PHP/' . phpversion()
    ];
    
    return mail($customerEmail, $subject, $message, implode("\r\n", $headers));
}

function generateBusinessEmailHTML($orderData, $uploadedFile, $orderRef) {
    $deliveryDate = date('l, F j, Y', strtotime($orderData['selectedDate']));
    $deliveryTimeValue = isset($orderData['deliveryTime']) ? $orderData['deliveryTime'] : 'anytime';
    $deliveryTimeLabels = ['anytime' => 'anytime', '9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $deliveryTimeDisplay = isset($deliveryTimeLabels[$deliveryTimeValue]) ? $deliveryTimeLabels[$deliveryTimeValue] : 'anytime';
    $deliveryDateWithTime = $deliveryDate . ' at ' . $deliveryTimeDisplay;
    $submissionTime = date('F j, Y \a\t g:i A T', strtotime($orderData['submittedAt']));
    
    $deliveryInfo = '';
    if ($orderData['deliveryOption'] === 'mtcc') {
        // Get building-specific address
        $building = $orderData['event']['building'] ?? 'north';
        if ($building === 'south') {
            $buildingName = 'South Building';
            $buildingLevel = 'Level 800';
            $streetAddress = '222 Bremner Boulevard';
            $postalCode = 'M5V 3L9';
        } else {
            $buildingName = 'North Building';
            $buildingLevel = 'Level 300';
            $streetAddress = '255 Front Street West';
            $postalCode = 'M5V 2W6';
        }
        $deliveryInfo = "<strong>MTCC Delivery (FREE)</strong><br>Metro Toronto Convention Centre<br>$buildingName, $buildingLevel<br>$streetAddress,<br/>Toronto, ON $postalCode";
    } else {
        $deliveryInfo = '<strong>Address Delivery (+$10.00)</strong><br>';
        if (!empty($orderData['deliveryAddress']['company'])) {
            $deliveryInfo .= htmlspecialchars($orderData['deliveryAddress']['company']) . '<br>';
        }
        $deliveryInfo .= 'Attn: ' . htmlspecialchars($orderData['deliveryAddress']['attn']) . '<br>';
        $deliveryInfo .= htmlspecialchars($orderData['deliveryAddress']['address']);
        if (!empty($orderData['deliveryAddress']['unit'])) {
            $deliveryInfo .= ', ' . htmlspecialchars($orderData['deliveryAddress']['unit']);
        }
        $deliveryInfo .= '<br>' . htmlspecialchars($orderData['deliveryAddress']['city']) . ', ' . htmlspecialchars($orderData['deliveryAddress']['province']) . ' ' . htmlspecialchars($orderData['deliveryAddress']['postal']);
    }
    
    $phoneDisplay = $orderData['customerInfo']['phone'];
    if (isset($orderData['customerInfo']['countryCode']) && $orderData['customerInfo']['countryCode'] !== 'OTHER') {
        $phoneDisplay .= ' (' . $orderData['customerInfo']['countryCode'] . ')';
    }
    
    // Company field
    $customerCompany = '';
    if (!empty($orderData['customerInfo']['company'])) {
        $customerCompany = '<tr><td style="padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;">Company:</td><td style="padding: 8px; border-bottom: 1px solid #e5e7eb;">' . htmlspecialchars($orderData['customerInfo']['company']) . '</td></tr>';
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>MTCC - New Poster Order: {$orderRef}</title>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background: #7c3aed; color: white; padding: 20px; text-align: center; padding-bottom:20px;'>
            <h1 style='margin: 0;'>ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â New Poster Order Received</h1>
            <p style='margin: 10px 0 0 0;'>Order Reference: <strong>{$orderRef}</strong></p>
        </div>
        
        <div style='background: #ffffff; border: 1px solid #e5e7eb; padding: 30px;'>
            <h2 style='color: #7c3aed; margin-top: 0;'>Order Details</h2>
            
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 25px;'>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Submitted:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$submissionTime}</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Event:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$orderData['event']['name']} ({$orderData['event']['acronym']})</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Delivery Date:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><strong>{$deliveryDateWithTime}</strong></td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Poster Size:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$orderData['dimensions']['width']}\" ÃƒÆ’Ã¢â‚¬â€ {$orderData['dimensions']['height']}\" ({$orderData['material']})</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Pricing Tier:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$orderData['pricing']['tier']}</td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Artwork File:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$uploadedFile['originalName']} (" . formatBytes($uploadedFile['size']) . ")</td></tr>
            </table>
            
            <h3 style='color: #7c3aed; margin-bottom: 15px;'>Customer Information</h3>
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 25px;'>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Name:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$orderData['customerInfo']['name']}</td></tr>
                {$customerCompany}
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Email:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><a href='mailto:{$orderData['customerInfo']['email']}'>{$orderData['customerInfo']['email']}</a></td></tr>
                <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb; font-weight: 600;'>Phone:</td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$phoneDisplay}</td></tr>
            </table>
            
            <h3 style='color: #7c3aed; margin-bottom: 15px;'>Delivery Information</h3>
            <div style='background: #f8f9fa; padding: 15px; border-radius: 6px;'>
                {$deliveryInfo}
            </div>
            
            <div style='margin-top: 30px; padding: 20px; background: #f0f9ff; border-radius: 6px;'>
                <p style='margin: 0; font-weight: 600; color: #0284c7;'>Total: $" . number_format($orderData['pricing']['total'], 2) . "</p>
                <p style='margin: 5px 0 0 0; color: #0369a1;'>Please respond to customer within 18 minutes during business hours.</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function generateCustomerEmailHTML($orderData, $orderRef) {
    $deliveryDate = date('l, F j, Y', strtotime($orderData['selectedDate']));
    $deliveryTimeValue = isset($orderData['deliveryTime']) ? $orderData['deliveryTime'] : 'anytime';
    $deliveryTimeLabels = ['anytime' => 'anytime', '9am' => '9:00am', '12pm' => '12:00pm', '3pm' => '3:00pm', '6pm' => '6:00pm'];
    $deliveryTimeDisplay = isset($deliveryTimeLabels[$deliveryTimeValue]) ? $deliveryTimeLabels[$deliveryTimeValue] : 'anytime';
    $deliveryDateWithTime = $deliveryDate . ' at ' . $deliveryTimeDisplay;
    
    $deliveryInfo = ($orderData['deliveryOption'] === 'mtcc') 
        ? 'MTCC Delivery (Free) - Ready for pickup at ' . $deliveryTimeDisplay
        : 'Address Delivery (+$10.00) - Delivered at ' . $deliveryTimeDisplay;
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>ÃƒÂ°Ã…Â¸Ã¢â‚¬ÂÃ‚Â Order Confirmation: {$orderRef}</title>
        <!--[if mso]>
        <style type='text/css'>
            table { border-collapse: collapse; }
            .rounded { border-radius: 0 !important; }
        </style>
        <![endif]-->
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #374151; background-color: #faf8ff; margin: 0; padding: 20px;'>
        
        <!-- Main Container Table -->
        <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
        <tr>
        <td>
		
		<!-- Company Logo -->
		<table cellpadding='0' cellspacing='0' border='0' style='width: 100%; margin-top: 20px; margin-bottom: 20px; text-align:center; max-width: 400px;'>
			<tr>
				<td> <img src='https://print-stuff.ca/mtcc-ps-logo.png' alt='Print Stuff Logo' style='max-width: 400px; height: auto; text-align: center;' />
				</td>
			</tr>
		</table>
            
            <!-- Header Section -->
            <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%); background-color: #7c3aed;'>
            <tr>
            <td style='padding: 30px 25px; text-align: center; color: white;'>

                <!-- Order Confirmed with checkmark -->
                <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; margin-bottom: 15px;'>
                <tr>
                <td style='text-align: center;'>
                    <table cellpadding='0' cellspacing='0' border='0' style='display: inline-table;'>
                    <tr>
                    <td style='font-size: 1.5rem; padding-right: 15px; vertical-align: middle;'>ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦</td>
                    <td style='vertical-align: middle;'>
                        <h1 style='margin: 0; font-size: 28px; font-weight: 700; color: white;'>Order Confirmed!</h1>
                    </td>
                    </tr>
                    </table>
                </td>
                </tr>
                </table>
                
                <!-- Order Reference -->
                <table cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                <tr>
                <td style='text-align: center;'>
                    <div style='display: inline-block; background: rgba(255,255,255,0.2); padding: 12px 20px; border-radius: 25px;'>
                        <span style='font-size: 16px; font-weight: 600; color: white;'>Reference #: {$orderRef}</span>
                    </div>
                </td>
                </tr>
                </table>
                
            </td>
            </tr>
            </table>
            
            <!-- Content Section -->
            <table cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
            <tr>
            <td style='padding: 30px 25px;'>
                
                <!-- Welcome Message -->
                <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: #f8fafc; border-radius: 8px; border-left: 4px solid #7c3aed; margin-bottom: 25px;'>
                <tr>
                <td style='padding: 20px;'>
                    <p style='margin: 0 0 10px 0; font-size: 16px; color: #374151;'>Hi <strong>{$orderData['customerInfo']['name']}</strong>,</p>
                    <p style='margin: 0; color: #6b7280;'>Thank you for your poster printing order! We've received your request and our team will review it shortly.</p>
                </td>
                </tr>
                </table>
                
                <!-- What's Next Section -->
                <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0; margin-bottom: 25px;'>
                <tr>
                <td style='padding: 20px;'>
                    <table cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                    <tr>
                    <td style='vertical-align: top; width: 30px;'>
                        <span style='font-size: 1.2rem;'>ÃƒÂ°Ã…Â¸Ã…Â¡Ã¢â€šÂ¬</span>
                    </td>
                    <td style='vertical-align: top; padding-left: 8px;'>
                        <h3 style='margin: 0 0 15px 0; color: #059669; font-size: 18px;'>What happens next?</h3>
                        <p style='margin: 0; color: #166534; font-weight: 500;'><strong>Our team reviews orders within 18 minutes</strong> during business hours. We'll contact you to confirm details and provide payment instructions.</p>
                    </td>
                    </tr>
                    </table>
                </td>
                </tr>
                </table>
                
                <!-- Order Summary -->
                <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: white; border-radius: 8px; border: 1px solid #e5e7eb; margin-bottom: 25px;'>
                <tr>
                <td style='padding: 20px;'>
                    
                    <!-- Summary Header -->
                    <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; border-bottom: 1px solid #f3f4f6; margin-bottom: 20px;'>
                    <tr>
                    <td style='vertical-align: top; width: 30px; padding-bottom: 15px;'>
                        <span style='font-size: 1.2rem;'>ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“<?= ICON_COPY ?></span>
                    </td>
                    <td style='vertical-align: top; padding-left: 8px; padding-bottom: 15px;'>
                        <h3 style='margin: 0; color: #7c3aed; font-size: 18px;'>Your Order Summary</h3>
                    </td>
                    </tr>
                    </table>
                    
                    <!-- Order Details Table -->
                    <table cellpadding='0' cellspacing='0' border='0' style='width: 100%;'>
                    <tr>
                    <td style='padding: 12px 0; border-bottom: 1px solid #f3f4f6; width: 50%; vertical-align: top;'>
                        <div style='font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;'>Event</div>
                        <div style='color: #374151; font-weight: 500;'>{$orderData['event']['name']}</div>
                    </td>
                    <td style='padding: 12px 0; border-bottom: 1px solid #f3f4f6; width: 50%; vertical-align: top;'>
                        <div style='font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;'>Poster Size</div>
                        <div style='color: #374151; font-weight: 500;'>{$orderData['dimensions']['width']}\" ÃƒÆ’Ã¢â‚¬â€ {$orderData['dimensions']['height']}\" " . ucfirst($orderData['material']) . "</div>
                    </td>
                    </tr>
                    <tr>
                    <td style='padding: 12px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top;'>
                        <div style='font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;'>Delivery Date</div>
                        <div style='color: #374151; font-weight: 600;'>{$deliveryDateWithTime}</div>
                    </td>
                    <td style='padding: 12px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top;'>
                        <div style='font-size: 12px; color: #6b7280; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;'>Delivery Method</div>
                        <div style='color: #374151; font-weight: 500;'>{$deliveryInfo}</div>
                    </td>
                    </tr>
                    <tr>
                    <td colspan='2' style='padding: 15px 0 0 0;'>
                        <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: #faf5ff; border-radius: 6px; border: 1px solid #e9d5ff;'>
                        <tr>
                        <td style='padding: 15px; text-align: center;'>
                            <div style='font-size: 12px; color: #7c3aed; text-transform: uppercase; font-weight: 600; margin-bottom: 4px;'>Estimated Total</div>
                            <div style='font-size: 24px; font-weight: 700; color: #7c3aed;'>$" . number_format($orderData['pricing']['total'], 2) . "</div>
                        </td>
                        </tr>
                        </table>
                    </td>
                    </tr>
                    </table>
                    
                </td>
                </tr>
                </table>
                
                <!-- Contact Section -->
                <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: white; border-radius: 8px; border: 1px solid #e5e7eb;'>
                <tr>
                <td style='padding: 20px;'>
                    
                    <!-- Contact Header -->
                    <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; margin-bottom: 15px;'>
                    <tr>
                    <td style='vertical-align: top; width: 30px;'>
                        <span style='font-size: 1.2rem;'>ÃƒÂ°Ã…Â¸Ã¢â‚¬Ã‚Â¬</span>
                    </td>
                    <td style='vertical-align: top; padding-left: 8px;'>
                        <h3 style='margin: 0; color: #7c3aed; font-size: 18px;'>Questions?</h3>
                    </td>
                    </tr>
                    </table>
                    
                    <p style='margin: 0 0 15px 0; color: #374151;'>Contact us anytime:</p>
                    
                    <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: #f8fafc; border-radius: 6px; border-left: 4px solid #7c3aed;'>
                    <tr>
                    <td style='padding: 15px;'>
                        <div style='margin-bottom: 8px;'>
                            <strong style='color: #374151;'>ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã‚Â§ Email:</strong> 
                            <a href='mailto:orders@printstuff.ca' style='color: #7c3aed; text-decoration: none; font-weight: 500;'>orders@printstuff.ca</a>
                        </div>
                        <div style='margin-bottom: 8px;'>
                            <strong style='color: #374151;'>ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã…Â¾ Phone:</strong> 
                            <span style='color: #374151; font-weight: 500;'>(437) 882-8822</span>
                        </div>
                        <div>
                            <strong style='color: #374151;'>ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Å“ Reference:</strong> 
                            <span style='background-color: #7c3aed; color: white; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 14px;'>{$orderRef}</span>
                        </div>
                    </td>
                    </tr>
                    </table>
                    
                </td>
                </tr>
                </table>
                
            </td>
            </tr>
            </table>
            
            <!-- Footer -->
            <table cellpadding='0' cellspacing='0' border='0' style='width: 100%; background-color: #f8fafc; border-top: 1px solid #e5e7eb;'>
            <tr>
            <td style='padding: 20px 25px; text-align: center;'>
                <p style='margin: 0; color: #6b7280; font-size: 14px;'>
                    Thank you for choosing Print Stuff for your poster printing needs!
                </p>
                <p style='margin: 5px 0 0 0; color: #9ca3af; font-size: 12px;'>
                    Ãƒâ€š&copy; " . date('Y') . " Print Stuff | orders@printstuff.ca | (437) 882-8822
                </p>
            </td>
            </tr>
            </table>
            
        </td>
        </tr>
        </table>
        
    </body>
    </html>
    ";
}

// ===== MAIN PROCESSING =====

// Create upload directories if they don't exist

$uploadDirs = [
    __DIR__ . '/uploads',
    __DIR__ . '/uploads/orders',
    __DIR__ . '/uploads/files'
];

foreach ($uploadDirs as $dir) {
    if (!is_dir($dir)) {
        try {
            if (!mkdir($dir, 0755, true)) {
                error_log("Failed to create directory: $dir");
                // Try with different permissions
                mkdir($dir, 0777, true);
            }
        } catch (Exception $e) {
            error_log("Exception creating directory $dir: " . $e->getMessage());
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Debug: Log POST data
        error_log('Order submission started');
        
        // Get and validate event information
        $eventAcronym = isset($_POST['eventAcronym']) ? trim($_POST['eventAcronym']) : '';
        $eventName = isset($_POST['eventName']) ? trim($_POST['eventName']) : '';
        $eventBuilding = isset($_POST['eventBuilding']) ? trim($_POST['eventBuilding']) : 'north';
        
        if (empty($eventAcronym)) {
            throw new Exception('Event selection is required.');
        }
        
        // Validate event acronym
        if (!validateEventAcronym($eventAcronym)) {
            error_log("Event validation failed for: $eventAcronym");
            // Allow it through with warning for now
        }
        
        // Get event name if not provided
        if (empty($eventName)) {
            $eventName = getEventName($eventAcronym);
        }
        
        // Validate required fields
        $requiredFields = ['width', 'height', 'material', 'selectedDate', 'deliveryOption', 'customerName', 'customerEmail', 'customerPhone'];
        foreach ($requiredFields as $field) {
            if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
                throw new Exception("Required field missing: $field");
            }
        }
        
        // Enhanced phone number validation
        $customerPhone = trim($_POST['customerPhone']);
        $countryCode = isset($_POST['countryCode']) ? trim($_POST['countryCode']) : null;
        
        if (!validateEnhancedPhone($customerPhone, $countryCode)) {
            throw new Exception('Please provide a valid phone number.');
        }
        
        // Generate reference code
        $referenceCode = generateReferenceCode($eventAcronym);
        
        // Collect form data
        $orderData = [
            'referenceCode' => $referenceCode,
            'event' => [
                'acronym' => $eventAcronym,
                'name' => $eventName,
                'building' => $eventBuilding
            ],
            'dimensions' => [
                'width' => (float)$_POST['width'],
                'height' => (float)$_POST['height']
            ],
            'material' => trim($_POST['material']),
            'selectedDate' => trim($_POST['selectedDate']),
            'deliveryTime' => isset($_POST['deliveryTime']) ? trim($_POST['deliveryTime']) : 'anytime',
            'deliveryOption' => trim($_POST['deliveryOption']),
            'conversionFee' => isset($_POST['conversionFee']) ? (float)$_POST['conversionFee'] : 0,
            'customerInfo' => [
                'name' => trim($_POST['customerName']),
                'company' => isset($_POST['customerCompany']) ? trim($_POST['customerCompany']) : '',
                'email' => trim($_POST['customerEmail']),
                'phone' => $customerPhone,
                'countryCode' => $countryCode,
                'additionalNotes' => isset($_POST['additionalNotes']) ? trim($_POST['additionalNotes']) : ''
            ],
            'pricing' => [
                'basePrice' => isset($_POST['basePrice']) ? (float)$_POST['basePrice'] : 0,
                'deliveryFee' => isset($_POST['deliveryFee']) ? (float)$_POST['deliveryFee'] : 0,
                'conversionFee' => isset($_POST['conversionFee']) ? (float)$_POST['conversionFee'] : 0,
                'subtotal' => isset($_POST['subtotal']) ? (float)$_POST['subtotal'] : 0,
                'tax' => isset($_POST['tax']) ? (float)$_POST['tax'] : 0,
                'total' => isset($_POST['total']) ? (float)$_POST['total'] : 0,
                'tier' => isset($_POST['tier']) ? trim($_POST['tier']) : ''
            ],
            'submittedAt' => date('Y-m-d H:i:s'),
            'version' => 'v22-delivery-validation'
        ];
        
        // Add delivery address if provided
        if ($_POST['deliveryOption'] === 'office') {
            $orderData['deliveryAddress'] = [
                'company' => isset($_POST['deliveryCompany']) ? trim($_POST['deliveryCompany']) : '',
                'attn' => isset($_POST['deliveryAttn']) ? trim($_POST['deliveryAttn']) : '',
                'address' => isset($_POST['deliveryAddress']) ? trim($_POST['deliveryAddress']) : '',
                'unit' => isset($_POST['deliveryUnit']) ? trim($_POST['deliveryUnit']) : '',
                'city' => isset($_POST['deliveryCity']) ? trim($_POST['deliveryCity']) : '',
                'province' => isset($_POST['deliveryProvince']) ? trim($_POST['deliveryProvince']) : '',
                'postal' => isset($_POST['deliveryPostal']) ? trim($_POST['deliveryPostal']) : '',
                'instructions' => isset($_POST['deliveryInstructions']) ? trim($_POST['deliveryInstructions']) : ''
            ];
        }
        
        // ===== SERVER-SIDE DELIVERY & PRICING VALIDATION =====
        $validationInput = [
            'width' => $orderData['dimensions']['width'] ?? 0,
            'height' => $orderData['dimensions']['height'] ?? 0,
            'material' => $orderData['material'] ?? 'poster',
            'selectedDate' => $orderData['selectedDate'] ?? '',
            'deliveryTime' => $orderData['deliveryTime'] ?? 'anytime',
            'basePrice' => $orderData['pricing']['basePrice'] ?? 0,
            'total' => $orderData['pricing']['total'] ?? 0,
            'tier' => $orderData['pricing']['tier'] ?? '',
            'deliveryFee' => $orderData['pricing']['deliveryFee'] ?? 0,
            'conversionFee' => $orderData['pricing']['conversionFee'] ?? 0,
        ];
        
        $validation = validateOrderPricing($validationInput);
        
        if (!$validation['valid']) {
            throw new Exception($validation['message']);
        }
        
        // If server recalculated a different price, use the server price
        if ($validation['corrected']) {
            $orderData['pricing']['basePrice'] = $validation['server_price'];
            $orderData['pricing']['tier'] = $validation['server_tier']['label'] ?? $orderData['pricing']['tier'];
            $orderData['pricing']['subtotal'] = $validation['server_price'] + $orderData['pricing']['deliveryFee'] + $orderData['pricing']['conversionFee'];
            $orderData['pricing']['tax'] = round($orderData['pricing']['subtotal'] * 0.13, 2);
            $orderData['pricing']['total'] = round($orderData['pricing']['subtotal'] + $orderData['pricing']['tax'], 2);
            $orderData['pricing']['server_corrected'] = true;
            
            error_log('Upload order pricing corrected: ref=' . $referenceCode . ' new_total=$' . $orderData['pricing']['total']);
        }
        
        // Store validation data in order
        $orderData['validation'] = [
            'server_tier' => $validation['server_tier']['tier_key'] ?? null,
            'server_price' => $validation['server_price'],
            'corrected' => $validation['corrected'],
        ];
        
        // Handle file upload
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
            
            // Generate unique filename
            $timestamp = date('Y-m-d_H-i-s');
            $newFileName = $referenceCode . '_' . $timestamp . '.' . $fileExt;
            $uploadPath = __DIR__ . '/uploads/files/' . $newFileName;
            
            // Move uploaded file
            if (move_uploaded_file($uploadedFile['tmp_name'], $uploadPath)) {
                $orderData['uploadedFile'] = [
                    'originalName' => $originalName,
                    'path' => 'uploads/files/' . $newFileName,
                    'size' => $uploadedFile['size'],
                    'type' => $uploadedFile['type']
                ];
            } else {
                throw new Exception('Failed to upload file');
            }
        } else {
            throw new Exception('No file was uploaded.');
        }
        
        // Save order data
        $orderFileName = __DIR__ . '/uploads/orders/' . $referenceCode . '_' . date('Y-m-d_H-i-s') . '-order.json';
        if (!file_put_contents($orderFileName, json_encode($orderData, JSON_PRETTY_PRINT))) {
            throw new Exception('Failed to save order data');
        }
        
        // Set default status
        $statusFile = __DIR__ . '/data/statuses.json';
        $statuses = [];
        if (file_exists($statusFile) && is_readable($statusFile)) {
            $statusContent = file_get_contents($statusFile);
            if (!empty($statusContent)) {
                $decoded = json_decode($statusContent, true);
                if (is_array($decoded)) {
                    $statuses = $decoded;
                }
            }
        }
        $statuses[$referenceCode] = 'submitted';
        file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT), LOCK_EX);
        
        // Send confirmation emails (don't fail if emails fail)
        try {
            $emailResult = sendOrderEmails($orderData, $orderData['uploadedFile']);
            if (!$emailResult['success']) {
                error_log("Email delivery failed for order {$referenceCode}");
            }
        } catch (Exception $e) {
            error_log("Email error for order {$referenceCode}: " . $e->getMessage());
        }
        
        // Log successful submission
        error_log("Order successfully submitted: $referenceCode");
        
        // Redirect to success page
        header('Location: order-success.php?ref=' . urlencode($referenceCode));
        exit;
        
    } catch (Exception $e) {
        // Log the error
        error_log('Order submission error: ' . $e->getMessage());
        
        // Handle errors
        $error = htmlspecialchars($e->getMessage());
        echo "<!DOCTYPE html>
        <html>
        <head>
            <title>Upload Error</title>
            <style>
                body { font-family: Arial, sans-serif; background: #fef2f2; margin: 0; padding: 20px; }
                .container { max-width: 600px; margin: 50px auto; background: white; padding: 40px; border-radius: 12px; text-align: center; }
                .error-icon { font-size: 4rem; color: #dc2626; margin-bottom: 20px; }
                h1 { color: #dc2626; margin-bottom: 15px; }
                p { color: #6b7280; margin-bottom: 30px; line-height: 1.6; }
                .back-btn { background: #7c3aed; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: 600; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='error-icon'><?= ICON_WARNING ?></div>
                <h1>Upload Failed</h1>
                <p>There was an error processing your order: <strong>{$error}</strong></p>
                <div>
                    <a href='/' class='back-btn'><?= SYMBOL_ARROW_RIGHT ?>¬Â Ã‚Â Go Back and Try Again</a>
                </div>
            </div>
        </body>
        </html>";
        exit;
    }
} else {
    // Redirect if not POST request
    header('Location: /');
    exit;
}
?>
