<?php
/**
 * Vendor CRUD Functions
 * Shared between production.php and vendors.php
 */

function loadVendors($vendorsFile) {
    if (!file_exists($vendorsFile)) {
        $defaultData = [
            'vendors' => [],
            'settings' => [
                'default_vendor_id' => null,
                'confirmation_timeout_same_day' => 30,
                'confirmation_timeout_standard' => 60,
                'vendor_hours_open' => '09:00',
                'vendor_hours_close' => '18:00',
                'print_buffer_hours' => 2,
                'delivery_buffer_hours' => 1
            ],
            'metadata' => [
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'version' => '1.0'
            ]
        ];
        file_put_contents($vendorsFile, json_encode($defaultData, JSON_PRETTY_PRINT), LOCK_EX);
        return $defaultData;
    }
    return json_decode(file_get_contents($vendorsFile), true) ?: [];
}

function saveVendors($data, $vendorsFile) {
    $data['metadata']['updated_at'] = date('c');
    return file_put_contents($vendorsFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function generateVendorId() {
    return 'vendor_' . bin2hex(random_bytes(6));
}

function getDefaultBusinessHours() {
    $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    $hours = [];
    foreach ($days as $day) {
        $hours[$day] = [
            'open' => in_array($day, ['saturday','sunday']) ? '' : '09:00',
            'close' => in_array($day, ['saturday','sunday']) ? '' : '18:00',
            'closed' => in_array($day, ['saturday','sunday'])
        ];
    }
    return $hours;
}

function parseBusinessHours($postData) {
    $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    $hours = getDefaultBusinessHours();
    $hasHoursData = false;
    foreach ($days as $day) {
        if (isset($postData['hours_' . $day . '_closed']) || isset($postData['hours_' . $day . '_open'])) {
            $hasHoursData = true;
            break;
        }
    }
    if (!$hasHoursData) return $hours;
    foreach ($days as $day) {
        $closed = !empty($postData['hours_' . $day . '_closed']);
        $open = trim($postData['hours_' . $day . '_open'] ?? '');
        $close = trim($postData['hours_' . $day . '_close'] ?? '');
        $hours[$day] = [
            'open' => $closed ? '' : $open,
            'close' => $closed ? '' : $close,
            'closed' => $closed
        ];
    }
    return $hours;
}

function addVendor($postData, $vendorsFile) {
    $data = loadVendors($vendorsFile);
    $vendorId = generateVendorId();
    
    // Generate PIN: use provided value or auto-generate 6-digit PIN
    $pin = trim($postData['pin'] ?? '');
    if (empty($pin)) {
        $pin = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
    if (!preg_match('/^\d{6}$/', $pin)) {
        return ['success' => false, 'error' => 'PIN must be exactly 6 digits'];
    }
    
    $newVendor = [
        'id' => $vendorId,
        'business_name' => trim($postData['business_name'] ?? ''),
        'contact_name' => trim($postData['contact_name'] ?? ''),
        'email' => trim($postData['email'] ?? ''),
        'email_cc' => trim($postData['email_cc'] ?? ''),
        'phone' => trim($postData['phone'] ?? ''),
        'address' => trim($postData['address'] ?? ''),
        'pin' => $pin,
        'notes' => trim($postData['notes'] ?? ''),
        'business_hours' => parseBusinessHours($postData),
        'active' => true,
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'stats' => [
            'total_orders' => 0,
            'total_revenue' => 0,
            'avg_confirmation_time' => 0,
            'issue_rate' => 0,
            'on_time_rate' => 100
        ]
    ];
    
    if (empty($newVendor['business_name'])) {
        return ['success' => false, 'error' => 'Business name is required'];
    }
    if (empty($newVendor['email'])) {
        return ['success' => false, 'error' => 'Email address is required'];
    }
    if (!filter_var($newVendor['email'], FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Invalid email address'];
    }
    if (count($data['vendors']) >= MAX_VENDORS) {
        return ['success' => false, 'error' => 'Maximum of ' . MAX_VENDORS . ' vendors allowed'];
    }
    
    foreach ($data['vendors'] as $vendor) {
        if (strtolower($vendor['email']) === strtolower($newVendor['email'])) {
            return ['success' => false, 'error' => 'A vendor with this email already exists'];
        }
    }
    
    $data['vendors'][] = $newVendor;
    
    if (count($data['vendors']) === 1) {
        $data['settings']['default_vendor_id'] = $vendorId;
    }
    
    if (saveVendors($data, $vendorsFile)) {
        if (function_exists('logAdminActivity')) {
            logAdminActivity('Vendor Created', ['vendor_id' => $vendorId, 'business_name' => $newVendor['business_name']]);
        }
        return ['success' => true, 'vendor' => $newVendor, 'message' => 'Vendor added successfully'];
    }
    
    return ['success' => false, 'error' => 'Failed to save vendor'];
}

function updateVendor($postData, $vendorsFile) {
    $data = loadVendors($vendorsFile);
    $vendorId = $postData['vendor_id'] ?? '';
    
    foreach ($data['vendors'] as &$vendor) {
        if ($vendor['id'] === $vendorId) {
            $newEmail = trim($postData['email'] ?? $vendor['email']);
            foreach ($data['vendors'] as $otherVendor) {
                if ($otherVendor['id'] !== $vendorId && strtolower($otherVendor['email']) === strtolower($newEmail)) {
                    return ['success' => false, 'error' => 'A vendor with this email already exists'];
                }
            }
            
            $vendor['business_name'] = trim($postData['business_name'] ?? $vendor['business_name']);
            $vendor['contact_name'] = trim($postData['contact_name'] ?? $vendor['contact_name']);
            $vendor['email'] = $newEmail;
            $vendor['email_cc'] = trim($postData['email_cc'] ?? $vendor['email_cc']);
            $vendor['phone'] = trim($postData['phone'] ?? $vendor['phone']);
            $vendor['address'] = trim($postData['address'] ?? $vendor['address']);
            $vendor['notes'] = trim($postData['notes'] ?? $vendor['notes']);
            $vendor['business_hours'] = parseBusinessHours($postData);
            
            // Update PIN only if a new value is provided
            $newPin = trim($postData['pin'] ?? '');
            if (!empty($newPin)) {
                if (!preg_match('/^\d{6}$/', $newPin)) {
                    return ['success' => false, 'error' => 'PIN must be exactly 6 digits'];
                }
                $vendor['pin'] = $newPin;
            }
            
            $vendor['updated_at'] = date('c');
            
            if (empty($vendor['business_name'])) {
                return ['success' => false, 'error' => 'Business name is required'];
            }
            
            if (saveVendors($data, $vendorsFile)) {
                if (function_exists('logAdminActivity')) {
                    logAdminActivity('Vendor Updated', ['vendor_id' => $vendorId, 'business_name' => $vendor['business_name']]);
                }
                return ['success' => true, 'vendor' => $vendor, 'message' => 'Vendor updated successfully'];
            }
            return ['success' => false, 'error' => 'Failed to save vendor'];
        }
    }
    
    return ['success' => false, 'error' => 'Vendor not found'];
}

function deleteVendor($vendorId, $vendorsFile) {
    $data = loadVendors($vendorsFile);
    
    foreach ($data['vendors'] as $index => $vendor) {
        if ($vendor['id'] === $vendorId) {
            $vendorName = $vendor['business_name'];
            array_splice($data['vendors'], $index, 1);
            
            if ($data['settings']['default_vendor_id'] === $vendorId) {
                $data['settings']['default_vendor_id'] = null;
                foreach ($data['vendors'] as $remainingVendor) {
                    if ($remainingVendor['active']) {
                        $data['settings']['default_vendor_id'] = $remainingVendor['id'];
                        break;
                    }
                }
            }
            
            if (saveVendors($data, $vendorsFile)) {
                if (function_exists('logAdminActivity')) {
                    logAdminActivity('Vendor Deleted', ['vendor_id' => $vendorId, 'business_name' => $vendorName]);
                }
                return ['success' => true, 'message' => 'Vendor deleted successfully'];
            }
            return ['success' => false, 'error' => 'Failed to delete vendor'];
        }
    }
    
    return ['success' => false, 'error' => 'Vendor not found'];
}

function toggleVendorStatus($vendorId, $vendorsFile) {
    $data = loadVendors($vendorsFile);
    
    foreach ($data['vendors'] as &$vendor) {
        if ($vendor['id'] === $vendorId) {
            $vendor['active'] = !$vendor['active'];
            $vendor['updated_at'] = date('c');
            
            if (!$vendor['active'] && $data['settings']['default_vendor_id'] === $vendorId) {
                $data['settings']['default_vendor_id'] = null;
                foreach ($data['vendors'] as $otherVendor) {
                    if ($otherVendor['id'] !== $vendorId && $otherVendor['active']) {
                        $data['settings']['default_vendor_id'] = $otherVendor['id'];
                        break;
                    }
                }
            }
            
            if (saveVendors($data, $vendorsFile)) {
                $status = $vendor['active'] ? 'activated' : 'deactivated';
                if (function_exists('logAdminActivity')) {
                    logAdminActivity('Vendor Status Changed', ['vendor_id' => $vendorId, 'new_status' => $status]);
                }
                return ['success' => true, 'active' => $vendor['active'], 'message' => "Vendor {$status} successfully"];
            }
            return ['success' => false, 'error' => 'Failed to update vendor status'];
        }
    }
    
    return ['success' => false, 'error' => 'Vendor not found'];
}

function setDefaultVendor($vendorId, $vendorsFile) {
    $data = loadVendors($vendorsFile);
    
    $found = false;
    foreach ($data['vendors'] as $vendor) {
        if ($vendor['id'] === $vendorId) {
            if (!$vendor['active']) {
                return ['success' => false, 'error' => 'Cannot set inactive vendor as default'];
            }
            $found = true;
            break;
        }
    }
    
    if (!$found) {
        return ['success' => false, 'error' => 'Vendor not found'];
    }
    
    $data['settings']['default_vendor_id'] = $vendorId;
    
    if (saveVendors($data, $vendorsFile)) {
        return ['success' => true, 'message' => 'Default vendor updated'];
    }
    
    return ['success' => false, 'error' => 'Failed to update default vendor'];
}
