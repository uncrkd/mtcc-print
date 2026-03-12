<?php
/**
 * Admin Order Handlers - FIXED VERSION
 * Processing functions for admin-created orders
 * 
 * FIXES:
 * - Field name mismatches between form and validation
 * - Improved error handling and debugging
 * - More flexible validation for optional fields
 */

function handleAdminOrderCreation($postData, $fileData) {
    try {
        error_log("=== ADMIN ORDER CREATION START ===");
        
        // STEP 1: Normalize field names to match validation expectations
        $normalizedData = normalizeAdminFormData($postData);
        
        // STEP 2: Enhanced logging for debugging
        error_log("Original POST data keys: " . implode(", ", array_keys($postData)));
        error_log("Normalized data keys: " . implode(", ", array_keys($normalizedData)));
        
        // STEP 3: Validate form data with improved validation
        $validation = validateAdminOrderData($normalizedData);
        if (!$validation['valid']) {
            error_log("Validation failed: " . $validation['error']);
            return ['success' => false, 'error' => $validation['error']];
        }

        // STEP 4: Process file upload
        $referenceCode = !empty($normalizedData['order_reference']) ? 
            $normalizedData['order_reference'] : 
            generateAdminOrderReference($normalizedData['event']);
            
        error_log("Generated reference code: " . $referenceCode);
            
        $fileResult = processAdminFileUpload($fileData, $referenceCode);
        if (!$fileResult['success']) {
            error_log("File upload failed: " . $fileResult['error']);
            return ['success' => false, 'error' => $fileResult['error']];
        }

        // STEP 5: Assemble order data
        $orderData = assembleAdminOrderData($normalizedData, $fileResult['file_info'], $referenceCode);

        // STEP 6: Save order
        $saveResult = saveAdminOrder($orderData);
        if (!$saveResult['success']) {
            error_log("Order save failed: " . $saveResult['error']);
            return ['success' => false, 'error' => $saveResult['error']];
        }

        // STEP 7: Send email notification if requested
        if (isset($normalizedData['send_notification']) && $normalizedData['send_notification'] === '1') {
            try {
                sendAdminOrderBusinessNotification($orderData, $referenceCode);
                error_log("Business notification sent successfully");
            } catch (Exception $e) {
                error_log("Failed to send business notification: " . $e->getMessage());
                // Don't fail the order creation if email fails
            }
        }

        error_log("=== ADMIN ORDER CREATION SUCCESS ===");
        return [
            'success' => true,
            'reference_code' => $referenceCode,
            'message' => 'Order created successfully'
        ];

    } catch (Exception $e) {
        error_log('Admin order creation exception: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return ['success' => false, 'error' => 'An unexpected error occurred: ' . $e->getMessage()];
    }
}

/**
 * FIXED: Enhanced validation with better field name handling
 */
function validateAdminOrderData($data) {
    $errors = [];
    
    // Enhanced debugging
    error_log("=== VALIDATION DEBUG START ===");
    error_log("Received data keys: " . implode(", ", array_keys($data)));
    
    // Log key fields for debugging
    $keyFields = ['event', 'due_date', 'customer_name', 'customer_email', 'customer_phone'];
    foreach ($keyFields as $field) {
        $value = isset($data[$field]) ? $data[$field] : 'NOT SET';
        error_log("Field '{$field}': {$value}");
    }

    // Customer information validation
    if (empty($data['customer_name']) || strlen(trim($data['customer_name'])) < 2) {
        $errors[] = 'Customer name is required and must be at least 2 characters';
    }

    if (empty($data['customer_email']) || !filter_var($data['customer_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid customer email is required';
    }

    if (empty($data['customer_phone']) || strlen(trim($data['customer_phone'])) < 7) {
        $errors[] = 'Valid customer phone number is required';
    }

    // Event validation - FIXED: Check multiple possible field names
    $eventField = getFieldValue($data, ['event', 'event_select', 'eventSelect']);
    if (empty($eventField)) {
        $errors[] = 'Event selection is required';
        error_log("Event validation failed - no event field found");
    } else {
        // Validate event exists
        if (!validateEventExists($eventField)) {
            $errors[] = 'Selected event does not exist: ' . $eventField;
            error_log("Event validation failed - event does not exist: " . $eventField);
        }
    }

    // Due date validation - FIXED: Check multiple possible field names
    $dueDateField = getFieldValue($data, ['due_date', 'selectedDate', 'd']);
    if (empty($dueDateField)) {
        $errors[] = 'Due date is required';
        error_log("Due date validation failed - no due date field found");
    } else {
        $dueDate = strtotime($dueDateField);
        if (!$dueDate) {
            $errors[] = 'Invalid due date format: ' . $dueDateField;
            error_log("Due date validation failed - invalid format: " . $dueDateField);
        } else {
            // Check if due date is not in the past
            if ($dueDate < strtotime('today')) {
                $errors[] = 'Due date cannot be in the past';
            }
        }
    }

    // Dimensions validation - FIXED: More flexible validation
    $width = getFieldValue($data, ['width', 'poster_width']);
    $height = getFieldValue($data, ['height', 'poster_height']);
    
    if (!empty($width)) {
        if (!is_numeric($width) || $width < 1 || $width > 999) {
            $errors[] = 'Width must be between 1 and 999 inches';
        }
    }

    if (!empty($height)) {
        if (!is_numeric($height) || $height < 1 || $height > 999) {
            $errors[] = 'Height must be between 1 and 999 inches';
        }
    }

    // Delivery validation - FIXED: More flexible validation
    $deliveryOption = getFieldValue($data, ['delivery_option', 'deliveryOption']);
    if (!empty($deliveryOption)) {
        if (!in_array($deliveryOption, ['mtcc', 'office', 'pickup', 'shipping'])) {
            $errors[] = 'Valid delivery option is required';
        }

        if ($deliveryOption === 'office' || $deliveryOption === 'shipping') {
            $requiredAddressFields = [
                'delivery_address' => 'Delivery address',
                'delivery_city' => 'Delivery city',
                'delivery_province' => 'Delivery province',
                'delivery_postal' => 'Delivery postal code'
            ];
            
            foreach ($requiredAddressFields as $field => $label) {
                if (empty($data[$field])) {
                    $errors[] = $label . ' is required for office/shipping delivery';
                }
            }
        }
    }

    // Pricing validation - FIXED: More lenient for admin orders
    $basePrice = getFieldValue($data, ['base_price', 'basePrice', 'price']);
    if (!empty($basePrice) && (!is_numeric($basePrice) || $basePrice < 0)) {
        $errors[] = 'Valid base price is required';
    }

    $deliveryFee = getFieldValue($data, ['delivery_fee', 'deliveryFee', 'shipping_cost']);
    if (!empty($deliveryFee) && (!is_numeric($deliveryFee) || $deliveryFee < 0)) {
        $errors[] = 'Valid delivery fee is required';
    }

    $total = getFieldValue($data, ['total', 'totalPrice', 'total_amount']);
    if (!empty($total) && (!is_numeric($total) || $total < 0)) {
        $errors[] = 'Valid total amount is required';
    }

    // Log validation results
    if (!empty($errors)) {
        error_log("Validation errors: " . implode("; ", $errors));
    } else {
        error_log("Validation passed successfully");
    }
    error_log("=== VALIDATION DEBUG END ===");

    if (!empty($errors)) {
        return ['valid' => false, 'error' => implode('; ', $errors)];
    }

    return ['valid' => true];
}

/**
 * NEW: Helper function to get field value from multiple possible field names
 */
function getFieldValue($data, $fieldNames) {
    foreach ($fieldNames as $fieldName) {
        if (!empty($data[$fieldName])) {
            return trim($data[$fieldName]);
        }
    }
    return null;
}

/**
 * NEW: Function to normalize form field names
 */
function normalizeAdminFormData($postData) {
    $normalized = $postData;
    
    // Map alternative field names to expected names
    $fieldMappings = [
        'event_select' => 'event',
        'eventSelect' => 'event',
        'selectedDate' => 'due_date',
        'd' => 'due_date',
        'deliveryOption' => 'delivery_option',
        'customerName' => 'customer_name',
        'customerEmail' => 'customer_email',
        'customerPhone' => 'customer_phone',
        'basePrice' => 'base_price',
        'deliveryFee' => 'delivery_fee',
        'totalPrice' => 'total',
        'poster_width' => 'width',
        'poster_height' => 'height'
    ];
    
    foreach ($fieldMappings as $actualField => $expectedField) {
        if (isset($normalized[$actualField]) && !isset($normalized[$expectedField])) {
            $normalized[$expectedField] = $normalized[$actualField];
            error_log("Mapped field '{$actualField}' to '{$expectedField}': " . $normalized[$actualField]);
        }
    }
    
    return $normalized;
}

function validateEventExists($eventAcronym) {
    try {
        // Check active events
        $eventsFile = __DIR__ . '/../events.json';
        if (file_exists($eventsFile)) {
            $eventsData = json_decode(file_get_contents($eventsFile), true);
            if (isset($eventsData['active']) && is_array($eventsData['active'])) {
                foreach ($eventsData['active'] as $event) {
                    if (isset($event['acronym']) && $event['acronym'] === $eventAcronym) {
                        error_log("Event found in active events: " . $eventAcronym);
                        return true;
                    }
                }
            }
        }

        // Check admin events
        $adminEventsFile = __DIR__ . '/../admin/events.json';
        if (file_exists($adminEventsFile)) {
            $eventsData = json_decode(file_get_contents($adminEventsFile), true);
            if (isset($eventsData['active']) && is_array($eventsData['active'])) {
                foreach ($eventsData['active'] as $event) {
                    if (isset($event['acronym']) && $event['acronym'] === $eventAcronym) {
                        error_log("Event found in admin events: " . $eventAcronym);
                        return true;
                    }
                }
            }
        }

        // Check archived events
        $archiveFile = __DIR__ . '/../admin/events-archive.json';
        if (file_exists($archiveFile)) {
            $archiveData = json_decode(file_get_contents($archiveFile), true);
            if (isset($archiveData['archived']) && is_array($archiveData['archived'])) {
                foreach ($archiveData['archived'] as $event) {
                    if (isset($event['acronym']) && $event['acronym'] === $eventAcronym) {
                        error_log("Event found in archived events: " . $eventAcronym);
                        return true;
                    }
                }
            }
        }

        error_log("Event not found in any event files: " . $eventAcronym);
        return false;

    } catch (Exception $e) {
        error_log("Error validating event: " . $e->getMessage());
        return false;
    }
}

function processAdminFileUpload($fileData, $referenceCode) {
    if (empty($fileData) || !isset($fileData['fileInput']) || $fileData['fileInput']['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => false, 'error' => 'No file uploaded'];
    }

    $file = $fileData['fileInput'];

    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (exceeds server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (exceeds form limit)',
            UPLOAD_ERR_PARTIAL => 'File upload incomplete',
            UPLOAD_ERR_NO_TMP_DIR => 'Server configuration error',
            UPLOAD_ERR_CANT_WRITE => 'Server write error',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by server extension'
        ];
        
        $errorMessage = isset($errorMessages[$file['error']]) ? 
            $errorMessages[$file['error']] : 
            'Unknown upload error';
            
        return ['success' => false, 'error' => $errorMessage];
    }

    // Validate file type
    $allowedTypes = [
        'application/pdf',
        'image/jpeg',
        'image/jpg', 
        'image/png',
        'image/gif',
        'image/bmp',
        'image/tiff',
        'application/postscript', // .ai files
        'application/illustrator'
    ];

    $mimeType = mime_content_type($file['tmp_name']);
    if (!in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Invalid file type. Please upload PDF, JPG, PNG, GIF, BMP, TIFF, or AI files only.'];
    }

    // Validate file size (50MB limit)
    if ($file['size'] > 50 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large. Maximum size is 50MB.'];
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $timestamp = time();
    $filename = $referenceCode . '_' . $timestamp . '.' . $extension;

    // Ensure uploads directory exists
    $uploadDir = __DIR__ . '/../uploads/files/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            return ['success' => false, 'error' => 'Unable to create upload directory'];
        }
    }

    $filePath = $uploadDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file'];
    }

    error_log("File uploaded successfully: " . $filePath);

    return [
        'success' => true,
        'file_info' => [
            'original_name' => $file['name'],
            'stored_name' => $filename,
            'size' => $file['size'],
            'type' => $mimeType,
            'path' => $filePath
        ]
    ];
}

function generateAdminOrderReference($eventAcronym) {
    $counterFile = __DIR__ . '/../order_counter.txt';
    
    // File locking for concurrent access
    $lockFile = $counterFile . '.lock';
    $lockHandle = fopen($lockFile, 'w');
    
    if (!$lockHandle || !flock($lockHandle, LOCK_EX)) {
        throw new Exception('Unable to acquire file lock for counter');
    }

    try {
        // Read current counters
        $counters = [];
        if (file_exists($counterFile)) {
            $content = file_get_contents($counterFile);
            $counters = json_decode($content, true) ?: [];
        }

        // Get next number for this event
        $currentCount = isset($counters[$eventAcronym]) ? (int)$counters[$eventAcronym] : 0;
        $newCount = $currentCount + 1;

        // Generate reference code
        $referenceCode = $eventAcronym . '-' . str_pad($newCount, 3, '0', STR_PAD_LEFT);

        // Update counter
        $counters[$eventAcronym] = $newCount;
        file_put_contents($counterFile, json_encode($counters, JSON_PRETTY_PRINT));

        error_log("Generated reference code: " . $referenceCode);
        return $referenceCode;

    } finally {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
}

function assembleAdminOrderData($postData, $fileInfo, $referenceCode) {
    // Get event name
    $eventAcronym = getFieldValue($postData, ['event', 'event_select', 'eventSelect']);
    $eventName = getEventNameByAcronym($eventAcronym);

    // Assemble order data in same format as customer orders
    $orderData = [
        'referenceCode' => $referenceCode,
        'submittedAt' => $postData['submission_datetime'] ?: date('Y-m-d H:i:s'),
        'createdBy' => 'admin', // Flag to identify admin-created orders
        'status' => $postData['order_status'] ?: 'unpaid',
        
        // Customer information
        'customerInfo' => [
            'name' => trim($postData['customer_name']),
            'company' => trim($postData['customer_company'] ?: ''),
            'email' => trim($postData['customer_email']),
            'phone' => trim($postData['customer_phone']),
            'countryCode' => $postData['country_code'] ?: '+1'
        ],
        
        // Event information
        'event' => $eventAcronym,
        'eventName' => $eventName,
        
        // Order details
        'selectedDate' => getFieldValue($postData, ['due_date', 'selectedDate', 'd']),
        'deliveryTime' => getFieldValue($postData, ['delivery_time', 'deliveryTime']) ?: 'anytime',
        'dimensions' => [
            'width' => getFieldValue($postData, ['width', 'poster_width']) ?: 24,
            'height' => getFieldValue($postData, ['height', 'poster_height']) ?: 36
        ],
        'material' => $postData['material'] ?: 'poster',
        'selectedMaterial' => $postData['selected_material'] ?: 'poster',
        
        // Delivery information
        'deliveryOption' => getFieldValue($postData, ['delivery_option', 'deliveryOption']) ?: 'mtcc',
        'deliveryAddress' => $postData['delivery_address'] ?: '',
        'deliveryCity' => $postData['delivery_city'] ?: '',
        'deliveryProvince' => $postData['delivery_province'] ?: '',
        'deliveryPostal' => $postData['delivery_postal'] ?: '',
        'deliveryCountry' => $postData['delivery_country'] ?: 'Canada',
        
        // Pricing information
        'pricing' => [
            'tier' => $postData['priority_tier'] ?: 'Standard (5 Days)',
            'basePrice' => floatval($postData['base_price'] ?: 0),
            'deliveryFee' => floatval($postData['delivery_fee'] ?: 0),
            'conversionFee' => floatval($postData['conversion_fee'] ?: 0),
            'tax' => floatval($postData['tax'] ?: 0),
            'total' => floatval($postData['total'] ?: 0)
        ],
        
        // File information
        'uploadedFile' => [
            'originalName' => $fileInfo['original_name'],
            'storedName' => $fileInfo['stored_name'],
            'size' => $fileInfo['size'],
            'type' => $fileInfo['type'],
            'path' => $fileInfo['path']
        ],
        
        // Notes
        'customerNotes' => $postData['customer_notes'] ?: '',
        'internalNotes' => $postData['internal_notes'] ?: '',
        
        // Admin-specific fields
        'createdByAdmin' => true,
        'adminNotes' => 'Order created via admin panel'
    ];

    error_log("Assembled order data for: " . $referenceCode);
    return $orderData;
}

function saveAdminOrder($orderData) {
    try {
        // Ensure orders directory exists
        $ordersDir = __DIR__ . '/../uploads/orders/';
        if (!is_dir($ordersDir)) {
            if (!mkdir($ordersDir, 0755, true)) {
                return ['success' => false, 'error' => 'Unable to create orders directory'];
            }
        }

        // Generate filename
        $filename = $orderData['referenceCode'] . '-order.json';
        $filePath = $ordersDir . $filename;

        // Save order data
        $jsonData = json_encode($orderData, JSON_PRETTY_PRINT);
        if (file_put_contents($filePath, $jsonData) === false) {
            return ['success' => false, 'error' => 'Failed to save order data'];
        }

        // Update status file
        $statusFile = __DIR__ . '/../statuses.json';
        $statuses = [];
        if (file_exists($statusFile)) {
            $statuses = json_decode(file_get_contents($statusFile), true) ?: [];
        }
        
        $statuses[$orderData['referenceCode']] = $orderData['status'];
        file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT));

        error_log("Order saved successfully: " . $filePath);
        return ['success' => true];

    } catch (Exception $e) {
        error_log("Error saving order: " . $e->getMessage());
        return ['success' => false, 'error' => 'Failed to save order: ' . $e->getMessage()];
    }
}

function getEventNameByAcronym($acronym) {
    try {
        // Check events.json first
        $eventsFile = __DIR__ . '/../events.json';
        if (file_exists($eventsFile)) {
            $eventsData = json_decode(file_get_contents($eventsFile), true);
            if (isset($eventsData['active']) && is_array($eventsData['active'])) {
                foreach ($eventsData['active'] as $event) {
                    if (isset($event['acronym']) && $event['acronym'] === $acronym) {
                        return $event['name'];
                    }
                }
            }
        }

        // Check admin events second
        $adminEventsFile = __DIR__ . '/../admin/events.json';
        if (file_exists($adminEventsFile)) {
            $eventsData = json_decode(file_get_contents($adminEventsFile), true);
            if (isset($eventsData['active']) && is_array($eventsData['active'])) {
                foreach ($eventsData['active'] as $event) {
                    if (isset($event['acronym']) && $event['acronym'] === $acronym) {
                        return $event['name'];
                    }
                }
            }
        }

        // Check archived events
        $archiveFile = __DIR__ . '/../admin/events-archive.json';
        if (file_exists($archiveFile)) {
            $archiveData = json_decode(file_get_contents($archiveFile), true);
            if (isset($archiveData['archived']) && is_array($archiveData['archived'])) {
                foreach ($archiveData['archived'] as $event) {
                    if (isset($event['acronym']) && $event['acronym'] === $acronym) {
                        return $event['name'];
                    }
                }
            }
        }

        return $acronym; // Return acronym if name not found

    } catch (Exception $e) {
        error_log("Error getting event name: " . $e->getMessage());
        return $acronym;
    }
}

function sendAdminOrderBusinessNotification($orderData, $referenceCode) {
    // This function should be implemented based on your email system
    // For now, just log that it was called
    error_log("Business notification would be sent for order: " . $referenceCode);
    
    // If you have an existing email function, call it here
    if (function_exists('sendBusinessNotificationEmail')) {
        sendBusinessNotificationEmail($orderData);
    }
}

?>