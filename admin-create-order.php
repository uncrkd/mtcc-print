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
require_once 'includes/status-config.php';

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

// Load status configuration from centralized status-config.php
$statusLabelsMap = getStatusLabelsForRole('admin');
$statusColorsMap = getStatusColors();
$statusConfig = [];
foreach ($statusLabelsMap as $code => $label) {
    $statusConfig[$code] = ['label' => $label, 'color' => $statusColorsMap[$code] ?? '#6b7280'];
}
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
        <div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin: 0; background: linear-gradient(90deg, #059669 0%, #10b981 100%); box-shadow:var(--shadow-md); outline: 2px dashed rgba(176, 223, 180, 0.7); outline-offset: -5px; border-radius: 10px; color: white;">
          <div class="page-header-left">
            <h1 class="page-title" style="color: white;">Create New Order</h1>
            <div class="page-welcome">
              <span class="welcome-text" style="color: white;">New order form <?= ICON_CLIPBOARD ?></span>
              <span class="welcome-date" style="color: rgba(255,255,255,0.85);">Today is <?= date('l, F j,  Y') ?></span>
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
<!-- PHP-injected config for external JS -->
<script>const CREATE_ORDER_CONFIG = { icons: { calendar: '<?= ICON_CALENDAR ?>', checkGreen: '<?= ICON_CHECK_GREEN ?>', download: '<?= ICON_DOWNLOAD ?>' } };</script>
<script src="js/shared/utils.js"></script>
<script src="js/admin-utilities.js"></script>
<script src="js/admin-create-order.js"></script>

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
