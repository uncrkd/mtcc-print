<?php
/**
 * Admin Order Creation Form
 * Allows administrators to create orders on behalf of customers
 */

// Include shared authentication
require_once 'admin-auth.php';

// Require orders_create permission
requirePermission('orders_create');

// Include required files
require_once 'includes/utilities.php';
require_once 'includes/admin-order-handlers.php';
require_once 'email-order-confirmation.php';
require_once 'includes/icons.php';

// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Process form submission
$showSuccess = false;
$showError = false;
$errorMessage = '';
$createdOrderRef = '';

if (isset($_POST['create_order'])) {
    $result = handleAdminOrderCreation($_POST, $_FILES);
    if ($result['success']) {
        $showSuccess = true;
        $createdOrderRef = $result['reference_code'];
    } else {
        $showError = true;
        $errorMessage = $result['error'];
    }
}

// Load status configuration
$statusConfig = [
    'unpaid' => ['label' => 'Unpaid', 'color' => '#eab308'],
    'paid' => ['label' => 'Paid', 'color' => '#059669'],
    'preflight' => ['label' => 'Preflight', 'color' => '#8b5cf6'],
    'file_issue' => ['label' => 'File Issue', 'color' => '#ea580c'],
    'printing' => ['label' => 'Printing', 'color' => '#0284c7'],
    'ready' => ['label' => 'Ready', 'color' => '#0d9488'],
    'dispatched' => ['label' => 'Dispatched', 'color' => '#7c3aed'],
    'shipped' => ['label' => 'Shipped', 'color' => '#14b8a6'],
    'delivered' => ['label' => 'Delivered', 'color' => '#92400e'],
    'pickedup' => ['label' => 'Picked Up', 'color' => '#22c55e'],
    'unclaimed' => ['label' => 'Unclaimed', 'color' => '#ec4899'],
    'missing' => ['label' => 'Missing', 'color' => '#dc2626'],
    'cancelled' => ['label' => 'Cancelled', 'color' => '#6b7280'],
    'refunded' => ['label' => 'Refunded', 'color' => '#dc2626']
];
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create New Order - Admin Panel</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- CSS Files -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-base.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-layout.css">
    <link rel="stylesheet" href="css/admin-responsive.css">
    <!-- Icon Library for JavaScript -->
    <?php outputIconsScript(); ?>
<link rel="stylesheet" href="css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/includes/admin-sidebar.php'; renderSidebar('orders'); ?>
<script src="js/admin-sidebar.js"></script>
    <!-- SUCCESS/ERROR MESSAGES -->
    <?php if ($showSuccess): ?>
    <div class="success-message" style="background: linear-gradient(90deg, #10b981 0%, #059669 100%); color: white; padding: 20px; text-align: center; font-weight: 600; font-size: 1.1rem;">
        <?= ICON_CHECK_GREEN ?> Order created successfully! 
        Reference: <strong><?= htmlspecialchars($createdOrderRef) ?></strong>
        <script>
            setTimeout(() => {
                window.location.href = 'admin-orders.php?view=<?= urlencode($createdOrderRef) ?>';
            }, 3000);
        </script>
    </div>
    <?php endif; ?>
    
    <?php if ($showError): ?>
    <div class="error-message">
        <?= ICON_WARNING ?> <?= htmlspecialchars($errorMessage) ?>
    </div>
    <?php endif; ?>

    <!-- CONTAINER WRAPPER -->
    <div class="container" style="max-width: 1400px;">
        <!-- Page Header - Green for Create -->
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin: 0; background: linear-gradient(90deg, #059669 0%, #10b981 100%); box-shadow:var(--shadow-md); outline: 2px dashed rgba(176, 223, 180, 0.7);   outline-offset: -5px;">
          <div class="page-header-left">
            <h1 class="page-title">Create New Order</h1>
            <div class="page-welcome">
              <span class="welcome-text">New order form <?= ICON_CLIPBOARD ?></span>
              <span class="welcome-date">Today is <?= date('l, F j,  Y') ?></span>
            </div>
          </div>
          <div class="page-header-right">
            <a href="admin-orders.php" style="display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); border-radius: 8px; color: white; text-decoration: none; font-weight: 600; font-size: 0.875rem;"><?= SYMBOL_ARROW_LEFT ?> Back to Orders</a>
          </div>
        </div>


        <!-- Header with dynamic fields and left border -->
        <div class="header" id="orderHeader" style="border-left: 6px solid #059669;">
            <div class="header-top-row">
                <div class="submitted-info">
                    Submission Date: 
                    <div class="date-input-wrapper">
                        <input type="datetime-local" id="submission_datetime" name="submission_datetime" 
                               value="<?= date('Y-m-d\TH:i') ?>" 
                               style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer;"
                               onchange="updateSubmissionDisplay(); updatePriorityTierDynamic();">
                        <div style="background: white; border: 1px solid #d1d5db; color: #374151; padding: 8px 12px; border-radius: 6px; font-size: 0.9rem; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; min-width: 220px;">
                            <span id="submission_display"><?= date('l, F j, Y g:i A') ?></span>
                            <span style="color: #6b7280; margin-left: 8px;"><?= ICON_CALENDAR ?></span>
                        </div>
                    </div>
                </div>
                <div style="display: flex; align-items: center; gap: 12px;">
                    <span style="font-size: 0.9rem; color: #6b7280; font-weight: 500;">Priority Tier:</span>
                    <span class="priority-tier-badge priority-tier-standard" id="priority_tier_badge"><?= ICON_CALENDAR ?> Standard (5 Days)</span>
                </div>
            </div>
            
            <div class="header-main-row" style="display: grid; grid-template-columns: 35% 13% 37% 13%; gap: 8px; align-items: end; width: 100%; max-width: 100%; overflow: hidden; box-sizing: border-box; padding: 0 4px;">
                <div style="min-width: 0; overflow: hidden;">
                    <div class="order-section-header">Event</div>
                    <select id="event_select" name="event_select" 
                            style="background: white; border: 1px solid #d1d5db; color: #374151; font-size: 0.9rem; padding: 10px 8px; border-radius: 6px; width: 100%; height: 42px; box-sizing: border-box;"
                            onchange="updateEventSelection()" required>
                        <option value="">Select an event...</option>
                    </select>
                </div>
                <div class="order-number-section" style="min-width: 0; overflow: hidden;">
                    <div class="order-section-header">Order #</div>
                    <input type="text" id="order_reference" name="order_reference" 
                           placeholder="Select event" 
                           style="background: white; border: 1px solid #d1d5db; color: #374151; font-size: 0.9rem; font-weight: 600; padding: 10px 8px; border-radius: 6px; width: 100%; height: 42px; box-sizing: border-box;"
                           readonly>
                </div>
                <div class="due-date-section" style="min-width: 0; overflow: hidden;">
                    <div class="order-section-header">Due Date</div>
                    <div style="display: flex; gap: 8px; align-items: stretch; height: 42px;">
                        <div class="date-input-wrapper" style="flex: 1;">
                            <input type="date" id="due_date" name="due_date" 
                                   style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; z-index: 2;"
                                   onchange="updateDueDateDisplay(); updatePriorityTierDynamic(); updateDeliveryTimeAvailability();" min="<?= date('Y-m-d') ?>" required>
                            <div style="background: white; border: 1px solid #d1d5db; color: #374151; font-size: 0.9rem; padding: 10px 8px; border-radius: 6px; width: 100%; height: 42px; cursor: pointer; display: flex; align-items: center; gap: 8px; box-sizing: border-box;">
                                <span style="color: #6b7280;"><?= ICON_CALENDAR ?></span>
                                <span id="due_date_display" style="flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">Select due date</span>
                                <span style="color: #6b7280; font-size: 0.6rem;"><?= SYMBOL_DROPDOWN ?></span>
                            </div>
                        </div>
                        <div style="position: relative; min-width: 130px;">
                            <span style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; z-index: 1; color: #6b7280;"><?= ICON_CLOCK ?></span>
                            <select id="delivery_time" name="delivery_time" 
                                    style="background: white; border: 1px solid #d1d5db; color: #374151; font-size: 0.9rem; padding: 10px 28px 10px 32px; border-radius: 6px; width: 100%; height: 42px; box-sizing: border-box; cursor: pointer; appearance: none; -webkit-appearance: none;">
                                <option value="anytime">Anytime</option>
                                <option value="9am">By 9:00am</option>
                                <option value="12pm">By 12:00pm</option>
                                <option value="3pm">By 3:00pm</option>
                                <option value="6pm">By 6:00pm</option>
                            </select>
                            <span style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); pointer-events: none; color: #6b7280; font-size: 0.6rem;"><?= SYMBOL_DROPDOWN ?></span>
                        </div>
                    </div>
                </div>
                <div class="status-section" style="min-width: 0; overflow: hidden;">
                    <div class="order-section-header">Status</div>
                    <select id="order_status" name="order_status" 
                            style="background: white; border: 1px solid #d1d5db; color: #374151; font-size: 0.9rem; padding: 10px 8px; border-radius: 6px; width: 100%; height: 42px; box-sizing: border-box;"
                            onchange="updateHeaderBorder()">
                        <?php foreach ($statusConfig as $key => $config): ?>
                        <option value="<?= $key ?>" <?= $key === 'unpaid' ? 'selected' : '' ?>>
                            <?= $config['label'] ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- MAIN FORM -->
        <form method="POST" action="admin-create-order.php" enctype="multipart/form-data" id="adminOrderForm">
            <input type="hidden" name="create_order" value="1">
            <input type="hidden" id="form_event_select" name="event_select" value="">
            <input type="hidden" id="form_due_date" name="due_date" value="">
            <input type="hidden" id="form_delivery_time" name="delivery_time" value="anytime">
            <input type="hidden" id="form_order_reference" name="order_reference" value="">
            <input type="hidden" id="form_submission_datetime" name="submission_datetime" value="">
            <input type="hidden" id="form_priority_tier" name="priority_tier" value="">
            <input type="hidden" id="form_order_status" name="order_status" value="">
            <input type="hidden" id="form_country_code" name="country_code" value="">
            <input type="hidden" id="hidden_submission_datetime" name="submission_datetime" value="<?= date('Y-m-d H:i:s') ?>">
            <input type="hidden" id="hidden_priority_tier" name="priority_tier" value="Standard (5 Days)">
            
            <div class="info-grid">
                <!-- Card 1: Customer & Delivery Information -->
                <div class="card card-compact">
                    <div class="section-header">
                        <span class="card-icon"><?= ICON_USER ?></span> Customer & Delivery Information
                    </div>
                    
                    <!-- Customer Information Subsection -->
                    <div class="subsection-header">Customer Information</div>
                    <div class="grid-2">
                        <div class="field">
                            <label for="customer_name">Name *</label>
                            <input type="text" id="customer_name" name="customer_name" class="field-control" required>
                        </div>
                        <div class="field">
                            <label for="customer_company">Company/Organization</label>
                            <input type="text" id="customer_company" name="customer_company" class="field-control">
                        </div>
                    </div>
                    
                    <!-- Email and Phone in same row -->
                    <div class="grid-2">
                        <div class="field">
                            <label for="customer_email">Email Address *</label>
                            <input type="email" id="customer_email" name="customer_email" class="field-control" required>
                        </div>
                        <div class="field">
                            <label for="customer_phone">Phone Number *</label>
                            <div class="phone-input-container">
                                <div class="country-selector" id="countrySelector">
                                    <div class="selected-country" id="selectedCountry">
                                        <img src="https://flagcdn.com/20x15/ca.png" alt="Canada" class="country-flag" id="countryFlag">
                                        <span class="country-code" id="countryCode">+1</span>
                                        <span class="dropdown-arrow"><?= SYMBOL_DROPDOWN ?></span>
                                    </div>
                                    <div class="country-dropdown" id="countryDropdown" style="display: none;">
                                        <!-- Countries will be populated by JavaScript -->
                                    </div>
                                </div>
                                <input type="tel" id="customer_phone" name="customer_phone" class="field-control phone-input" 
                                       placeholder="(000) 000-0000" required>
                                <input type="hidden" id="country_code" name="country_code" value="+1">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Customer Notes -->
                    <div class="field">
                        <label for="customer_notes">Customer Notes</label>
                        <textarea id="customer_notes" name="customer_notes" class="field-control" 
                                  rows="3" placeholder="Notes visible to customer"></textarea>
                    </div>
                    
                    <!-- Delivery Details -->
                    <div class="subsection-header" style="margin-top: var(--space-lg);">Delivery Details</div>
                    <div class="field">
                        <label for="delivery_option">Delivery Method *</label>
                        <select id="delivery_option" name="delivery_option" class="field-control" required onchange="toggleDeliveryFields(this.value)">
                            <option value="">Select delivery method</option>
                            <option value="mtcc_north">MTCC North Building</option>
                            <option value="mtcc_south">MTCC South Building</option>
                            <option value="office">Address Delivery (+$10)</option>
                        </select>
                    </div>
                    
                    <!-- MTCC Pickup Address (shown when MTCC selected) -->
                    <div id="mtcc-address" style="display: none; margin-top: var(--space-md); padding: var(--space-md); background: #f0fdf4; border-radius: 8px; border: 1px solid #bbf7d0;">
                        <div style="display: flex; align-items: flex-start; gap: 12px;">
                            <span style="font-size: 1.5rem;">&#127464;&#127462;</span>
                            <div>
                                <div style="font-weight: 600; color: #166534; margin-bottom: 4px;">Pickup Location</div>
                                <div id="mtcc-location-text" style="color: #374151; line-height: 1.5;">
                                    <strong>Metro Toronto Convention Centre</strong><br>
                                    North Building<br>
                                    255 Front Street West<br>
                                    Toronto, ON M5V 2W6
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Address Fields (hidden by default) -->
                    <div id="address-fields" style="display: none; margin-top: var(--space-md); padding-top: var(--space-md); border-top: 1px solid #f1f5f9;">
                        <div class="grid-2">
                            <div class="field">
                                <label for="delivery_attn">Attention To</label>
                                <input type="text" id="delivery_attn" name="delivery_attn" class="field-control">
                            </div>
                            <div class="field">
                                <label for="delivery_company">Company</label>
                                <input type="text" id="delivery_company" name="delivery_company" class="field-control">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="field">
                                <label for="delivery_address">Address *</label>
                                <input type="text" id="delivery_address" name="delivery_address" class="field-control">
                            </div>
                            <div class="field">
                                <label for="delivery_unit">Unit/Suite</label>
                                <input type="text" id="delivery_unit" name="delivery_unit" class="field-control">
                            </div>
                        </div>
                        <div class="grid-3">
                            <div class="field">
                                <label for="delivery_city">City *</label>
                                <input type="text" id="delivery_city" name="delivery_city" class="field-control">
                            </div>
                            <div class="field">
                                <label for="delivery_province">Province *</label>
                                <input type="text" id="delivery_province" name="delivery_province" class="field-control" value="ON">
                            </div>
                            <div class="field">
                                <label for="delivery_postal">Postal Code *</label>
                                <input type="text" id="delivery_postal" name="delivery_postal" class="field-control">
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Card 2: Poster Details & Pricing -->
                <div class="card card-compact">
                    <div class="section-header">
                        <span class="card-icon"><?= ICON_CLIPBOARD ?></span> Poster Details & Pricing
                    </div>
                    
                    <!-- Poster Specifications -->
                    <div class="subsection-header">Poster Specifications</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                        <div class="field">
                            <label for="width">Width (in)</label>
                            <input type="number" id="width" name="width" class="field-control" 
                                   min="1" max="120" step="0.1" required onchange="updatePricing()" placeholder="48">
                        </div>
                        <div class="field">
                            <label for="height">Height (in)</label>
                            <input type="number" id="height" name="height" class="field-control" 
                                   min="1" max="120" step="0.1" required onchange="updatePricing()" placeholder="48">
                        </div>
                        <div class="field">
                            <label>Material</label>
                            <select id="selected_material" name="selected_material" class="field-control" onchange="selectMaterial(this.value)">
                                <option value="poster">Poster Paper</option>
                                <option value="fabric">Fabric</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- File Management -->
                    <div class="subsection-header">File Management</div>
                    <div class="field">
                        <div class="upload-zone" id="uploadZone" onclick="document.getElementById('fileInput').click()" style="display: flex; align-items: center; gap: 20px; text-align: left; padding: 24px;">
                            <div class="upload-icon" style="font-size: 3rem; margin: 0; opacity: 0.7; flex-shrink: 0;"><?= ICON_CLIPBOARD ?></div>
                            <div style="flex: 1;">
                                <div style="font-weight: 600; color: #374151; margin-bottom: 4px; font-size: 0.95rem;">Click to upload or drag your file here</div>
                                <div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 2px;">PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX</div>
                                <div style="font-size: 0.75rem; color: #9ca3af;">Max file size: 100MB</div>
                            </div>
                        </div>
                        <input type="file" id="fileInput" name="file" 
                               accept=".pdf,.ai,.eps,.psd,.png,.jpg,.jpeg,.tiff,.tif,.webp,.gif,.bmp,.svg,.pptx,.indd" 
                               style="display: none;" required>
                    </div>
                    
                    <!-- Pricing Information -->
                    <div class="subsection-header" style="margin-top: 24px;">Pricing Information <span style="font-size: 0.75rem; color: #6b7280; font-weight: normal;">(edit values to recalculate)</span></div>
                    <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.75rem;">
                        <div class="field">
                            <label for="base_price">Base Price</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #e5e7eb; padding: 8px 10px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #374151; font-weight: 600; flex-shrink: 0;">$</span>
                                <input type="number" step="0.01" id="base_price" name="base_price" class="field-control" min="0" onchange="updateTotal()" value="90" style="border-radius: 0 6px 6px 0; border-left: none; flex: 1; min-width: 0; width: 100%; padding-left: 6px; padding-right: 6px;">
                            </div>
                        </div>
                        <div class="field">
                            <label for="delivery_fee">Delivery</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #e5e7eb; padding: 8px 10px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #374151; font-weight: 600; flex-shrink: 0;">$</span>
                                <input type="number" step="0.01" id="delivery_fee" name="delivery_fee" class="field-control" min="0" value="0" onchange="updateTotal()" style="border-radius: 0 6px 6px 0; border-left: none; flex: 1; min-width: 0; width: 100%; padding-left: 6px; padding-right: 6px;">
                            </div>
                        </div>
                        <div class="field">
                            <label for="conversion_fee">Conversion</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #e5e7eb; padding: 8px 10px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #374151; font-weight: 600; flex-shrink: 0;">$</span>
                                <input type="number" step="0.01" id="conversion_fee" name="conversion_fee" class="field-control" min="0" value="5" onchange="updateTotal()" style="border-radius: 0 6px 6px 0; border-left: none; flex: 1; min-width: 0; width: 100%; padding-left: 6px; padding-right: 6px;">
                            </div>
                        </div>
                        <div class="field">
                            <label for="tax">Tax (13%)</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #f3f4f6; padding: 8px 10px; border-radius: 6px 0 0 6px; border: 1px solid #d1d5db; border-right: none; color: #9ca3af; font-weight: 600; flex-shrink: 0;">$</span>
                                <input type="number" step="0.01" id="tax" name="tax" class="field-control" min="0" readonly style="border-radius: 0 6px 6px 0; border-left: none; background: #f8f9fa; color: #6b7280; flex: 1; min-width: 0; width: 100%; padding-left: 6px; padding-right: 6px;" value="12.35">
                            </div>
                        </div>
                        <div class="field">
                            <label for="total" style="color: var(--primary); font-weight: 600;">Total</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: var(--primary); padding: 8px 10px; border-radius: 6px 0 0 6px; border: 1px solid var(--primary); border-right: none; color: white; font-weight: 700; flex-shrink: 0;">$</span>
                                <input type="number" step="0.01" id="total" name="total" class="field-control" min="0" readonly style="border-radius: 0 6px 6px 0; border-left: none; font-weight: 700; background: #ecfdf5; color: var(--primary); border-color: var(--primary); flex: 1; min-width: 0; width: 100%; padding-left: 6px; padding-right: 6px;" value="107.35">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Card 3: Internal Notes -->
            <div class="card card-compact" style="margin-top: var(--space-lg);">
                <div class="section-header">
                    <span class="card-icon"><?= ICON_CLIPBOARD ?></span> Internal Notes & Settings
                </div>
                
                <!-- Internal Notes Only -->
                <div class="field" style="max-width: 600px;">
                    <label for="internal_notes">Internal Notes</label>
                    <textarea id="internal_notes" name="internal_notes" class="field-control" 
                              rows="3" placeholder="Internal notes (not visible to customer)"></textarea>
                </div>
                
                <!-- Email Notification -->
                <div class="field" style="max-width: 400px;">
                    <label class="checkbox-label">
                        <input type="checkbox" id="send_notification" name="send_notification" value="1" checked>
                        <span class="checkbox-text">Send business notification email</span>
                    </label>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons-container">
                <button type="submit" class="action-btn-large" style="background: #059669; border-color: #059669;"><?= ICON_CHECK_GREEN ?> Create Order</button>
                <a href="admin-orders.php" class="action-btn-large cancel"><?= ICON_WARNING ?> Cancel</a>
            </div>
        </form>
    </div><!-- End Container -->

<!-- JavaScript -->
<script src="js/shared/utils.js"></script>
<script src="js/admin-utilities.js"></script>
<script>
// Status configuration for dynamic border colors
const statusColors = {
    'unpaid': '#eab308',
    'paid': '#059669',
    'file_issue': '#ea580c',
    'printing': '#0284c7',
    'shipped': '#14b8a6',
    'delivered': '#92400e',
    'pickedup': '#22c55e',
    'unclaimed': '#ec4899',
    'missing': '#dc2626',
    'cancelled': '#6b7280',
    'refunded': '#dc2626'
};

// Initialize form functionality - ADMIN VERSION
document.addEventListener('DOMContentLoaded', function() {
    console.log('Admin Create Order form initializing...');
    loadEvents();
    loadPricingData();
    initializeCountrySelector();
    initializeFormHandlers();
    
    // Initialize date input handlers
    initializeDateInputs();
    
    // Hook file upload to existing conversion fee logic
    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                console.log('File selected:', file.name);
                updateFilePreview(file);
                
                // Auto-calculate conversion fee based on file type
                const fileName = file.name.toLowerCase();
                const isPDF = fileName.endsWith('.pdf');
                const conversionFeeInput = document.getElementById('conversion_fee');
                
                if (conversionFeeInput) {
                    conversionFeeInput.value = isPDF ? '0.00' : '5.00';
                    updateTotal();
                    
                    if (!isPDF) {
                        showNotification('File conversion fee applied: $5.00 for non-PDF files', 'info');
                    }
                }
            }
        });
    }
});

// Initialize date inputs to make them fully clickable
function initializeDateInputs() {
    const submissionInput = document.getElementById('submission_datetime');
    const dueDateInput = document.getElementById('due_date');
    
    // Update submission date display when changed
    if (submissionInput) {
        submissionInput.addEventListener('change', function() {
            updateSubmissionDisplay();
            updatePriorityTierDynamic();
        });
    }
    
    // Update due date display when changed
    if (dueDateInput) {
        dueDateInput.addEventListener('change', function() {
            updateDueDateDisplay();
            updatePriorityTierDynamic();
        });
    }
    
    // Add fallback click handlers for date inputs
    const submissionWrapper = document.querySelector('.date-input-wrapper');
    const dueDateWrapper = document.querySelector('.due-date-section .date-input-wrapper');
    
    if (submissionWrapper) {
        submissionWrapper.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const input = this.querySelector('input[type="datetime-local"]');
            if (input) {
                if (input.showPicker) {
                    try {
                        input.showPicker();
                    } catch (err) {
                        input.focus();
                        input.click();
                    }
                } else {
                    input.focus();
                    input.click();
                }
            }
        });
    }
    
    if (dueDateWrapper) {
        dueDateWrapper.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const input = this.querySelector('input[type="date"]');
            if (input) {
                if (input.showPicker) {
                    try {
                        input.showPicker();
                    } catch (err) {
                        input.focus();
                        input.click();
                    }
                } else {
                    input.focus();
                    input.click();
                }
            }
        });
    }
}

// Update submission date display and auto-apply changes
function updateSubmissionDisplay() {
    const submissionInput = document.getElementById('submission_datetime');
    const display = document.getElementById('submission_display');
    const hiddenInput = document.getElementById('hidden_submission_datetime');
    
    if (submissionInput && display && submissionInput.value) {
        const date = new Date(submissionInput.value);
        display.textContent = date.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
        
        // Auto-apply the submission date (convert to MySQL datetime format)
        if (hiddenInput) {
            const mysqlDatetime = date.getFullYear() + '-' + 
                String(date.getMonth() + 1).padStart(2, '0') + '-' + 
                String(date.getDate()).padStart(2, '0') + ' ' + 
                String(date.getHours()).padStart(2, '0') + ':' + 
                String(date.getMinutes()).padStart(2, '0') + ':' + 
                String(date.getSeconds()).padStart(2, '0');
            
            hiddenInput.value = mysqlDatetime;
        }
    }
}

// Update due date display
function updateDueDateDisplay() {
    const dueDateInput = document.getElementById('due_date');
    const display = document.getElementById('due_date_display');
    
    if (dueDateInput && display && dueDateInput.value) {
        const date = new Date(dueDateInput.value);
        display.textContent = date.toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
}

// Delivery time availability
const deliveryTimeConfig = [
    { value: 'anytime', label: 'Anytime', cutoffHour: 14, cutoffMinute: 0, dayBefore: false },
    { value: '9am', label: 'By 9:00am', cutoffHour: 16, cutoffMinute: 0, dayBefore: true },
    { value: '12pm', label: 'By 12:00pm', cutoffHour: 9, cutoffMinute: 30, dayBefore: false },
    { value: '3pm', label: 'By 3:00pm', cutoffHour: 12, cutoffMinute: 0, dayBefore: false },
    { value: '6pm', label: 'By 6:00pm', cutoffHour: 14, cutoffMinute: 0, dayBefore: false }
];

function updateDeliveryTimeAvailability() {
    const dueDateInput = document.getElementById('due_date');
    const deliveryTimeSelect = document.getElementById('delivery_time');
    const submissionInput = document.getElementById('submission_datetime');
    if (!dueDateInput || !deliveryTimeSelect) return;
    const dueDate = dueDateInput.value ? new Date(dueDateInput.value + 'T00:00:00') : null;
    const submissionDate = submissionInput && submissionInput.value ? new Date(submissionInput.value) : new Date();
    if (!dueDate) {
        deliveryTimeConfig.forEach(function(option) {
            const optEl = deliveryTimeSelect.querySelector('option[value="' + option.value + '"]');
            if (optEl) { optEl.disabled = false; optEl.textContent = option.label; }
        });
        return;
    }
    const submissionDateMidnight = new Date(submissionDate);
    submissionDateMidnight.setHours(0, 0, 0, 0);
    const dueDateMidnight = new Date(dueDate);
    dueDateMidnight.setHours(0, 0, 0, 0);
    const isSameDay = dueDateMidnight.getTime() === submissionDateMidnight.getTime();
    if (!isSameDay) {
        deliveryTimeConfig.forEach(function(option) {
            const optEl = deliveryTimeSelect.querySelector('option[value="' + option.value + '"]');
            if (optEl) { optEl.disabled = false; optEl.textContent = option.label; }
        });
        return;
    }
    const currentHour = submissionDate.getHours();
    const currentMinute = submissionDate.getMinutes();
    const currentTimeInMinutes = currentHour * 60 + currentMinute;
    deliveryTimeConfig.forEach(function(option) {
        const optEl = deliveryTimeSelect.querySelector('option[value="' + option.value + '"]');
        if (!optEl) return;
        let isAvailable = option.dayBefore ? false : (currentTimeInMinutes < (option.cutoffHour * 60 + option.cutoffMinute));
        optEl.disabled = !isAvailable;
        optEl.textContent = isAvailable ? option.label : option.label + ' (not available)';
    });
}


// Dynamic priority tier calculation based on submission and due dates
function updatePriorityTierDynamic() {
    const submissionInput = document.getElementById('submission_datetime');
    const dueDateInput = document.getElementById('due_date');
    
    if (!submissionInput.value || !dueDateInput.value) {
        return;
    }
    
    const submissionDate = new Date(submissionInput.value);
    const dueDate = new Date(dueDateInput.value);
    
    // Calculate the difference in milliseconds
    const diffTime = dueDate.getTime() - submissionDate.getTime();
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    // Tier configuration with correct icons
    const tiers = [
        { key: 'early', label: 'Early', days: '10+ Days', minDays: 10, icon: '&#128077;Â' },
        { key: 'standard', label: 'Standard', days: '5 Days', minDays: 5, icon: '<?= ICON_CALENDAR ?>' },
        { key: 'rush', label: 'Rush', days: '3 Days', minDays: 3, icon: 'ðŸƒ' },
        { key: 'urgent', label: 'Urgent', days: '2 Days', minDays: 2, icon: 'ðŸ”¥' },
        { key: 'critical', label: 'Critical', days: 'Next Day', minDays: 1, icon: '&#128640;' },
        { key: 'lastminute', label: 'Last Minute', days: 'Same Day', minDays: 0, icon: 'ðŸ’¥' }
    ];
    
    // Find the appropriate tier
    let selectedTier = tiers[tiers.length - 1]; // Default to last minute
    
    for (let i = 0; i < tiers.length; i++) {
        if (diffDays >= tiers[i].minDays) {
            selectedTier = tiers[i];
            break;
        }
    }
    
    // Update the priority tier display - uses pastel colors from CSS
    const priorityBadge = document.getElementById('priority_tier_badge');
    if (priorityBadge) {
        priorityBadge.textContent = `${selectedTier.icon} ${selectedTier.label} (${selectedTier.days})`;
        priorityBadge.className = `priority-tier-badge priority-tier-${selectedTier.key}`;
    }
    
    // Update hidden field
    const hiddenTier = document.getElementById('hidden_priority_tier');
    if (hiddenTier) {
        hiddenTier.value = `${selectedTier.label} (${selectedTier.days})`;
    }
    
    console.log('[INFO] Dynamic priority tier updated:', selectedTier.label, 'Days difference:', diffDays);
    
    // Update pricing if both dates are set
    if (submissionInput.value && dueDateInput.value) {
        updatePricing();
    }
}

// Update header border color based on status
function updateHeaderBorder() {
    const statusSelect = document.getElementById('order_status');
    const header = document.getElementById('orderHeader');
    
    if (statusSelect && header) {
        const selectedStatus = statusSelect.value;
        const color = statusColors[selectedStatus] || '#059669';
        header.style.borderLeftColor = color;
    }
}

// Notification function
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed; top: 20px; right: 20px; z-index: 10000;
        padding: 12px 16px; border-radius: 6px; color: white; font-weight: 500;
        background: ${type === 'error' ? '#dc2626' : type === 'success' ? '#10b981' : '#3b82f6'};
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);

}

// Global variables
let eventsData = null;
let pricingData = null;
let selectedCountry = { code: 'CA', name: 'Canada', dialCode: '+1', flag: 'https://flagcdn.com/20x15/ca.png' };

// Country data (simplified version)
const countries = [
  { code: 'CA', name: 'Canada', dialCode: '+1', flag: 'https://flagcdn.com/20x15/ca.png' },
  { code: 'US', name: 'United States', dialCode: '+1', flag: 'https://flagcdn.com/20x15/us.png' },
  { code: 'GB', name: 'United Kingdom', dialCode: '+44', flag: 'https://flagcdn.com/20x15/gb.png' },
  { code: 'AU', name: 'Australia', dialCode: '+61', flag: 'https://flagcdn.com/20x15/au.png' },
  { code: 'FR', name: 'France', dialCode: '+33', flag: 'https://flagcdn.com/20x15/fr.png' },
  { code: 'DE', name: 'Germany', dialCode: '+49', flag: 'https://flagcdn.com/20x15/de.png' },
  { code: 'JP', name: 'Japan', dialCode: '+81', flag: 'https://flagcdn.com/20x15/jp.png' },
  { code: 'CN', name: 'China', dialCode: '+86', flag: 'https://flagcdn.com/20x15/cn.png' },
  { code: 'IN', name: 'India', dialCode: '+91', flag: 'https://flagcdn.com/20x15/in.png' },
  { code: 'OTHER', name: 'Other +', dialCode: '+', flag: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMTUiIHZpZXdCb3g9IjAgMCAyMCAxNSIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjIwIiBoZWlnaHQ9IjE1IiBmaWxsPSIjZjNmNGY2IiBzdHJva2U9IiNkMWQ1ZGIiLz4KPHN2ZyB4PSI2IiB5PSI0IiB3aWR0aD0iOCIgaGVpZ2h0PSI3IiB2aWV3Qm94PSIwIDAgOCA3IiBmaWxsPSJub25lIj4KPHN2ZyB3aWR0aD0iOCIgaGVpZ2h0PSI3IiB2aWV3Qm94PSIwIDAgOCA3IiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgo8cGF0aCBkPSJNNCAwLjc1TDcuNDY0IDZIMS0uNTM2IDZMNCA5LjIxWiIgZmlsbD0iIzZiNzI4MCIvPgo8L3N2Zz4KPC9zdmc+Cjwvc3ZnPgo=' }
];

// Event loading function - Load from admin/get-events.php
async function loadEvents() {
    try {
        console.log('[INFO] Loading events from admin/get-events.php...');
        const response = await fetch('admin/get-events.php');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Raw events data:', result);
        
        // get-events.php returns { success: true, data: { active: [], archived: [] } }
        const eventsData = result.data || result;
        
        const eventSelect = document.getElementById('event_select');
        if (!eventSelect) {
            throw new Error('Event select element not found');
        }
        
        eventSelect.innerHTML = '<option value="">Select an event...</option>';
        
        // Add active events
        if (eventsData.active && Array.isArray(eventsData.active)) {
            eventsData.active.forEach(event => {
                const option = document.createElement('option');
                option.value = event.acronym;
                option.textContent = `${event.name} (${event.acronym})`;
                eventSelect.appendChild(option);
            });
        }
        
        // Add archived events
        if (eventsData.archived && Array.isArray(eventsData.archived)) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = 'Archived Events';
            eventsData.archived.forEach(event => {
                const option = document.createElement('option');
                option.value = event.acronym;
                option.textContent = `${event.name} (${event.acronym}) - ARCHIVED`;
                optgroup.appendChild(option);
            });
            if (eventsData.archived.length > 0) {
                eventSelect.appendChild(optgroup);
            }
        }
        
        console.log('[INFO] Events loaded successfully:', eventsData);
    } catch (error) {
        console.error('[INFO] Error loading events:', error);
        showNotification('Failed to load events: ' + error.message, 'error');
        
        // Create fallback events directly
        const eventSelect = document.getElementById('event_select');
        if (eventSelect) {
            eventSelect.innerHTML = '<option value="">Select an event...</option>';
            

            // Add some fallback events
            const fallbackEvents = [
                { acronym: 'DEMO', name: 'Demo Conference' },
            ];
            
            fallbackEvents.forEach(event => {
                const option = document.createElement('option');
                option.value = event.acronym;
                option.textContent = `${event.name} (${event.acronym})`;
                eventSelect.appendChild(option);
            });
            
            console.log('[INFO] Using fallback events');
        }
    }
}

// Pricing data loading
async function loadPricingData() {
    try {
        console.log('[INFO] Loading pricing data from get-pricing.php...');
        const response = await fetch('get-pricing.php');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const apiResponse = await response.json();
        console.log('[INFO] Raw pricing API response:', apiResponse);
        
        // Extract the actual pricing data from the API response
        let data;
        if (apiResponse.success && apiResponse.data) {
            data = apiResponse.data;
            console.log('[INFO] Extracted pricing data from API response');
        } else if (apiResponse.poster || apiResponse.fabric) {
            data = apiResponse;
            console.log('[INFO] Using direct pricing data structure');
        } else {
            throw new Error('Invalid pricing API response structure');
        }
        
        // Check the structure of the pricing data
        if (data && typeof data === 'object') {
            pricingData = data;
            console.log('[INFO] Pricing data loaded successfully');
            console.log('[INFO] Available pricing materials:', Object.keys(pricingData));
        } else {
            throw new Error('Invalid pricing data structure');
        }
        
    } catch (error) {
        console.error('[INFO] Error loading pricing data:', error);
        showNotification('Failed to load pricing data: ' + error.message, 'error');
        
        // Create fallback pricing data with correct structure
        console.log('[INFO] Creating fallback pricing data...');
        pricingData = {
            poster: [
                { min: 1, max: 249, early: 39, standard: 39, '3days': 42, '2days': 45, nextday: 52.5, sameday: 117 },
                { min: 250, max: 399, early: 40, standard: 43, '3days': 45.5, '2days': 52.5, nextday: 61.25, sameday: 129 },
                { min: 400, max: 649, early: 41, standard: 47, '3days': 58.5, '2days': 67.5, nextday: 78.75, sameday: 141 },
                { min: 650, max: 899, early: 42, standard: 50, '3days': 65, '2days': 75, nextday: 87.5, sameday: 150 },
                { min: 900, max: 999999, early: 45, standard: 55, '3days': 71.5, '2days': 82.5, nextday: 96.25, sameday: 165 }
            ],
            fabric: [
                { min: 1, max: 249, early: 69, standard: 85, '3days': 111.3, '2days': 127.2, nextday: 148.4, sameday: 255 },
                { min: 250, max: 399, early: 70, standard: 86, '3days': 113.4, '2days': 129.6, nextday: 151.2, sameday: 258 },
                { min: 400, max: 649, early: 72, standard: 88, '3days': 115.5, '2days': 132, nextday: 154, sameday: 264 },
                { min: 650, max: 899, early: 73, standard: 90, '3days': 117.6, '2days': 134.4, nextday: 156.8, sameday: 270 },
                { min: 900, max: 999999, early: 74, standard: 91, '3days': 119.7, '2days': 136.8, nextday: 159.6, sameday: 273 }
            ]
        };
        console.log('[INFO] Fallback pricing data created');
    }
}

// Form handler functions
function updateEventSelection() {
    const eventSelect = document.getElementById('event_select');
    const selectedEvent = eventSelect.value;
    if (selectedEvent) {
        // Generate and show actual next order number
        generateNextOrderNumber(selectedEvent);
    } else {
        document.getElementById('order_reference').placeholder = 'Select event to generate';
        document.getElementById('order_reference').value = '';
    }
}

// Generate actual next order number
async function generateNextOrderNumber(eventAcronym) {
    try {
        // Read the counter file directly
        const response = await fetch('data/order_counter.txt');
        const content = await response.text();
        const counters = JSON.parse(content);
        
        // Get current count for this event
        const currentCount = counters[eventAcronym] || 0;
        const nextNumber = currentCount + 1;
        const nextOrderNumber = eventAcronym + '-' + String(nextNumber).padStart(3, '0');
        
        document.getElementById('order_reference').value = nextOrderNumber;
        document.getElementById('order_reference').placeholder = 'Next: ' + nextOrderNumber;
        
    } catch (error) {
        console.warn('Could not fetch current counter:', error);
        // Fallback to showing pattern
        const fallbackNumber = eventAcronym + '-XXX';
        document.getElementById('order_reference').value = fallbackNumber;
        document.getElementById('order_reference').placeholder = 'Will generate: ' + fallbackNumber;
    }
}

function setDimensions(width, height) {
    console.log('[INFO] Setting dimensions:', width, 'x', height);
    
    document.getElementById('width').value = width;
    document.getElementById('height').value = height;
    updatePricing();
}

function selectMaterial(material) {
    console.log('[INFO] Material selected:', material);
    
    const materialSelect = document.getElementById('selected_material');
    if (materialSelect && materialSelect.value !== material) {
        materialSelect.value = material;
    }
    
    // Update pricing
    updatePricing();
}

function toggleDeliveryFields(method) {
    const addressFields = document.getElementById('address-fields');
    const mtccAddress = document.getElementById('mtcc-address');
    const deliveryFeeField = document.getElementById('delivery_fee');
    const mtccLocationText = document.getElementById('mtcc-location-text');
    
    if (method === 'office') {
        addressFields.style.display = 'block';
        mtccAddress.style.display = 'none';
        if (deliveryFeeField) deliveryFeeField.value = '10.00';
    } else if (method === 'mtcc_north' || method === 'mtcc_south') {
        addressFields.style.display = 'none';
        mtccAddress.style.display = 'block';
        if (deliveryFeeField) deliveryFeeField.value = '0.00';
        // Update location text based on building
        if (mtccLocationText) {
            if (method === 'mtcc_north') {
                mtccLocationText.innerHTML = '<strong>Metro Toronto Convention Centre</strong><br>North Building<br>255 Front Street West<br>Toronto, ON M5V 2W6';
            } else {
                mtccLocationText.innerHTML = '<strong>Metro Toronto Convention Centre</strong><br>South Building<br>222 Bremner Boulevard<br>Toronto, ON M5V 3L9';
            }
        }
    } else {
        addressFields.style.display = 'none';
        mtccAddress.style.display = 'none';
        if (deliveryFeeField) deliveryFeeField.value = '0.00';
    }
    updateTotal();
}

function updatePriorityTier() {
    const dueDateInput = document.getElementById('due_date');
    if (!dueDateInput.value) return;
    
    const dueDate = new Date(dueDateInput.value);

    const today = new Date();
    const diffTime = dueDate.getTime() - today.getTime();
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    
    let tier = 'Standard (5 Days)';
    let tierClass = 'priority-tier-standard';
    
    if (diffDays >= 10) {
        tier = 'Early (10+ Days)';
        tierClass = 'priority-tier-early';
    } else if (diffDays >= 5) {
        tier = 'Standard (5 Days)';
        tierClass = 'priority-tier-standard';
    } else if (diffDays >= 3) {
        tier = 'Rush (3 Days)';
        tierClass = 'priority-tier-rush';
    } else if (diffDays >= 2) {
        tier = 'Urgent (2 Days)';
        tierClass = 'priority-tier-urgent';
    } else if (diffDays >= 1) {
        tier = 'Critical (Next Day)';
        tierClass = 'priority-tier-critical';
    } else {
        tier = 'Last Minute (Same Day)';
        tierClass = 'priority-tier-lastminute';
    }
    
    document.getElementById('hidden_priority_tier').value = tier;
    
    // Update display in header
    const priorityBadge = document.getElementById('priority_tier_badge');
    if (priorityBadge) {
        priorityBadge.textContent = tier;
        // Remove all priority tier classes
        priorityBadge.className = 'priority-tier-badge ' + tierClass;
    }
    
    console.log('[INFO] Priority tier updated:', tier);
    updatePricing(); // Recalculate pricing with new tier
}

// Pricing calculation function
function updatePricing() {
    const width = parseFloat(document.getElementById('width').value) || 0;
    const height = parseFloat(document.getElementById('height').value) || 0;
    const material = document.getElementById('selected_material').value || 'poster';
    const dueDate = document.getElementById('due_date').value;
    
    console.log('[INFO] Updating pricing:', { width, height, material, dueDate });
    console.log('[INFO] Pricing data available:', !!pricingData);
    
    if (!width || !height || !dueDate) {
        console.log('[INFO] Missing dimensions or due date');
        return;
    }
    
    if (!pricingData) {
        console.log('[INFO] Pricing data not loaded yet, retrying in 500ms...');
        setTimeout(() => {
            if (pricingData) {
                console.log('[INFO] Retrying pricing calculation...');
                updatePricing();
            } else {
                console.error('[INFO] Pricing data still not available after retry');
                showNotification('Pricing data not available. Please refresh the page.', 'error');
            }
        }, 500);
        return;
    }
    
    if (pricingData && pricingData[material]) {
        const area = width * height;
        const priceRow = pricingData[material].find(row => area >= row.min && area <= row.max);
        
        if (priceRow) {
            // Determine pricing tier based on due date
            const dueDate = new Date(document.getElementById('due_date').value);
            const today = new Date();
            const diffTime = dueDate.getTime() - today.getTime();
            const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
            
            let priceKey = 'standard';
            if (diffDays >= 10) {
                priceKey = 'early';
            } else if (diffDays >= 5) {
                priceKey = 'standard';
            } else if (diffDays >= 3) {
                priceKey = '3days';
            } else if (diffDays >= 2) {
                priceKey = '2days';
            } else if (diffDays >= 1) {
                priceKey = 'nextday';
            } else {
                priceKey = 'sameday';
            }
            
            const basePrice = priceRow[priceKey] || priceRow.standard || 0;
            document.getElementById('base_price').value = basePrice.toFixed(2);
            updateTotal();
            console.log('[INFO] Pricing updated:', basePrice, 'for tier:', priceKey);
        } else {
            console.warn('âš ï¸ No pricing row found for area:', area);
        }
    } else {
        console.warn('âš ï¸ No pricing data for material:', material);
    }
}

function updateTotal() {
    const basePrice = parseFloat(document.getElementById('base_price').value) || 0;
    const deliveryFee = parseFloat(document.getElementById('delivery_fee').value) || 0;
    const conversionFee = parseFloat(document.getElementById('conversion_fee').value) || 0;
    
    const subtotal = basePrice + deliveryFee + conversionFee;
    const tax = subtotal * 0.13;
    const total = subtotal + tax;
    
    document.getElementById('tax').value = tax.toFixed(2);
    document.getElementById('total').value = total.toFixed(2);
    
    console.log('[INFO] Total updated:', total.toFixed(2));
}

function updateFilePreview(file) {
    const uploadZone = document.getElementById('uploadZone');
    const fileName = file.name;
    const fileSize = (file.size / (1024 * 1024)).toFixed(2);
    const fileExt = fileName.split('.').pop().toUpperCase();
    
    // Show file preview with condensed layout
    uploadZone.innerHTML = `
        <div style="display: flex; align-items: center; gap: 16px; background: #f0f9ff; border: 2px solid #0ea5e9; border-radius: 8px; padding: 20px; width: 100%;">
            <div style="color: #0ea5e9; font-size: 2.5rem; flex-shrink: 0;"><?= ICON_CHECK_GREEN ?></div>
            <div style="width: 1px; height: 40px; background: #0ea5e9; flex-shrink: 0;"></div>
            <div style="flex: 1;">
                <div style="font-weight: 600; color: #374151; margin-bottom: 4px;">File Uploaded Successfully</div>
                <div style="font-weight: 600; color: #374151; margin-bottom: 2px;">${fileName}</div>
                <div style="font-size: 0.8rem; color: #6b7280;">Type: ${fileExt} &#8226; Size: ${fileSize} MB</div>
                <div style="font-size: 0.75rem; color: #059669; margin-top: 4px;">File ready for upload</div>
                <button type="button" onclick="removeFile()" style="margin-top: 8px; padding: 4px 8px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 0.8rem; cursor: pointer;">Remove File</button>
            </div>
        </div>
    `;
}

function removeFile() {
    const fileInput = document.getElementById('fileInput');
    const uploadZone = document.getElementById('uploadZone');
    const conversionFeeInput = document.getElementById('conversion_fee');
    
    fileInput.value = '';
    if (conversionFeeInput) {
        conversionFeeInput.value = '0.00';
        updateTotal();
    }
    
    // Restore original upload zone
    uploadZone.innerHTML = `
        <div class="upload-icon"><?= ICON_DOWNLOAD ?></div>
        <div style="width: 1px; height: 40px; background: #d1d5db; flex-shrink: 0;"></div>
        <div style="flex: 1;">
            <div style="font-weight: 600; color: #374151; margin-bottom: 4px;">Click to upload or drag your file here</div>
            <div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 2px;">PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX, </div>
            <div style="font-size: 0.75rem; color: #9ca3af;">Max file size: 100MB</div>
        </div>
    `;
}

function initializeCountrySelector() {
    const countrySelector = document.getElementById('countrySelector');
    const countryDropdown = document.getElementById('countryDropdown');
    const selectedCountryElement = document.getElementById('selectedCountry');
    
    if (!countrySelector || !countryDropdown) return;
    
    // Populate dropdown
    countries.forEach(country => {
        const div = document.createElement('div');
        div.className = 'country-option';
        div.innerHTML = `
            <img src="${country.flag}" alt="${country.name}" class="country-flag">
            <span class="country-name">${country.name}</span>
            <span class="country-dial-code">${country.dialCode}</span>
        `;
        div.onclick = () => selectCountryOption(country);
        countryDropdown.appendChild(div);
    });
    
    // Toggle dropdown
    selectedCountryElement.onclick = (e) => {
        e.stopPropagation();
        countryDropdown.style.display = countryDropdown.style.display === 'none' ? 'block' : 'none';
    };
    
    // Close dropdown when clicking outside
    document.addEventListener('click', () => {
        countryDropdown.style.display = 'none';
    });
}

function selectCountryOption(country) {
    const countryFlag = document.getElementById('countryFlag');
    const countryCode = document.getElementById('countryCode');
    const countryCodeInput = document.getElementById('country_code');
    const countryDropdown = document.getElementById('countryDropdown');
    
    if (countryFlag) countryFlag.src = country.flag;
    if (countryCode) countryCode.textContent = country.dialCode;
    if (countryCodeInput) countryCodeInput.value = country.dialCode;
    if (countryDropdown) countryDropdown.style.display = 'none';
    
    selectedCountry = country;
    console.log('[INFO] Country selected:', country.name);
}

function initializeFormHandlers() {
    const form = document.getElementById('adminOrderForm');
    if (!form) return;
    
    // Set up pricing calculation triggers
    const widthInput = document.getElementById('width');
    const heightInput = document.getElementById('height');
    const dueDateInput = document.getElementById('due_date');
    
    if (widthInput) {
        widthInput.addEventListener('input', () => {
            console.log('[INFO] Width changed:', widthInput.value);
            updatePricing();
        });
    }
    if (heightInput) {
        heightInput.addEventListener('input', () => {
            console.log('[INFO] Height changed:', heightInput.value);
            updatePricing();
        });
    }
    if (dueDateInput) {
        dueDateInput.addEventListener('change', () => {
            console.log('[INFO] Due date changed:', dueDateInput.value);
            updatePriorityTier();
            updatePricing();
        });
    }
    
    
    // Form validation before submit
    form.addEventListener('submit', function(e) {
        
        document.getElementById('form_event_select').value = document.getElementById('event_select').value;
        document.getElementById('form_due_date').value = document.getElementById('due_date').value;
        document.getElementById('form_delivery_time').value = document.getElementById('delivery_time').value;
        document.getElementById('form_order_reference').value = document.getElementById('order_reference').value;
        document.getElementById('form_order_status').value = document.getElementById('order_status').value;
        console.log('[INFO] Form submission triggered...');
        
        // Check required fields
        const requiredFields = form.querySelectorAll('[required]');
        let allValid = true;
        
        requiredFields.forEach(field => {
            if (!field.value.trim()) {
                allValid = false;
                field.style.borderColor = '#dc2626';
            } else {
                field.style.borderColor = '';
            }
        });
        
        if (!allValid) {
            e.preventDefault();
            showNotification('Please fill in all required fields', 'error');
            return;
        }
        
        // Check delivery address fields if office delivery selected
        const deliveryOption = document.getElementById('delivery_option').value;
        if (deliveryOption === 'office') {
            const addressRequired = ['delivery_address', 'delivery_city', 'delivery_province', 'delivery_postal'];
            let addressValid = true;
            
            addressRequired.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field || !field.value.trim()) {
                    addressValid = false;
                    if (field) field.style.borderColor = '#dc2626';
                }
            });
            
            if (!addressValid) {
                e.preventDefault();
                showNotification('Please fill in all delivery address fields', 'error');
                return;
            }
        }
        
        // Show loading state
        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.textContent = '&#9203; Creating Order...';
            submitBtn.disabled = true;
        }
        
        console.log('[INFO] Form validation passed, submitting...');
    });
    
    console.log('[INFO] Admin form handlers initialized');
}

</script>

<!-- Page-specific CSS - Unique styles not in shared CSS files -->
<style>
/* Date input wrapper styling */
.date-input-wrapper {
    position: relative;
    cursor: pointer !important;
    display: inline-block;
}

.date-input-wrapper:hover {
    opacity: 0.9;
}

.date-input-wrapper * {
    cursor: pointer !important;
}

/* Phone input with country selector */
.phone-input-container {
    display: flex;
    align-items: center;
    gap: 8px;
}

.country-selector {
    position: relative;
    flex-shrink: 0;
}

.selected-country {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 6px 8px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    cursor: pointer;
    background: white;
    min-width: 80px;
    height: 32px;
    box-sizing: border-box;
}

.country-flag {
    width: 20px;
    height: 15px;
}

.country-code {
    font-size: 0.8rem;
    color: #374151;
}

.dropdown-arrow {
    font-size: 0.6rem;
    color: #9ca3af;
}

.country-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    max-height: 200px;
    overflow-y: auto;
    min-width: 200px;
}

.country-option {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    cursor: pointer;
    transition: background 0.2s;
}

.country-option:hover {
    background: #f3f4f6;
}

.country-name {
    flex: 1;
    font-size: 0.8rem;
}

.country-dial-code {
    font-size: 0.8rem;
    color: #6b7280;
}

.phone-input {
    flex: 1;
}

/* Checkbox styling */
.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
}

.checkbox-text {
    font-size: 0.8rem;
    color: #374151;
}

/* Upload zone icon */
.upload-icon {
    font-size: 2.5rem;
    color: #6b7280;
    flex-shrink: 0;
}
</style>

</body>
</html>
