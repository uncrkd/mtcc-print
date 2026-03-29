<?php
/**
 * MTCC Poster Order Form
 * Main customer-facing page for poster orders
 */

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');

// Include icon library
require_once 'includes/icons.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>MTCC Poster Pricing | Print Stuff</title>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
  <link rel="stylesheet" href="styles.css?v=20260318">
  <!-- PDF.js for file thumbnail previews -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
  <!-- Icon Library for JavaScript -->
  <?php outputIconsScript(); ?>
</head>
<body>
  <div class="container">
    
    <!-- Hero Banner -->
    <div class="hero-banner">
      <div class="hero-content">
        <div class="hero-logo">
          <img src="mtcc-ps-logo.png" alt="MTCC + Print Stuff" class="header-logo">
        </div>
        <div class="hero-text">
          <h1>Convention Poster Printing</h1>
          <p>For Researchers, Presenters, Exhibitors, and more!</p>
          <div class="hero-features">
            <span class="hero-badge"><span class="hero-badge-icon">&#x2728;</span> High-quality printing</span>
            <span class="hero-badge"><span class="hero-badge-icon">&#x23F1;</span> Same-day printing available</span>
            <span class="hero-badge"><span class="hero-badge-icon">&#x1F69A;</span> Free delivery to MTCC</span>
          </div>
        </div>
      </div>
    </div>

    <form id="orderForm" method="POST" action="upload-order.php" enctype="multipart/form-data">
    <!-- Hidden fields for JavaScript-controlled values -->
    <input type="hidden" id="hiddenMaterial" name="material" value="poster">
    <input type="hidden" id="hiddenDeliveryOption" name="deliveryOption" value="">
    <input type="hidden" id="hiddenConversionFee" name="conversionFee" value="0">
    <input type="hidden" id="hiddenEventAcronym" name="eventAcronym" value="">
    <input type="hidden" id="hiddenEventName" name="eventName" value="">
    <input type="hidden" id="hiddenEventBuilding" name="eventBuilding" value="">
    <input type="hidden" id="hiddenOrderNumber" name="orderNumber" value="">

    <!-- ZONE 1: Event, Sizing & Pricing -->
    <div class="card card--spaced">
      <div class="step-header">
        <span class="card-icon">&#127914;</span>
        <div class="step-header-content">
          <span class="step-title">Event, Sizing & Pricing</span>
          <div class="step-tooltip">
            <span>?</span>
            <span class="tooltiptext">Choose your event, delivery date, and poster size to see your pricing options.</span>
          </div>
        </div>
      </div>
      <div class="step-divider"></div>
      
      <!-- Event and Date Selection -->
      <div class="event-date-row-wide">
        <div class="field">
          <label for="eventSelect">Event <span class="required-field">*</span></label>
          <div class="select-wrapper">
            <select id="eventSelect" name="eventSelect" class="field-control" required>
              <option value="">Select your event...</option>
            </select>
            <span class="select-arrow">⏷</span>
          </div>
        </div>
        <div class="vertical-divider-mini"></div>
        <div class="field">
          <label for="d">In-Hand Date <span class="required-field">*</span> <span class="delivery-time-note">(earlier times may affect pricing)</span></label>
          <div class="date-time-row">
            <div class="date-input-container date-input-clickable">
              <input id="d" name="selectedDate" type="date" class="field-control date-input-hidden">
              <div id="dateDisplay" class="field-control date-display">
                <span class="date-icon">&#128197;</span>
                <span class="date-placeholder">When do you need it?</span>
                <span class="date-arrow">⏷</span>
              </div>
            </div>
            <div class="time-select-wrapper">
              <span class="time-icon">&#128336;</span>
              <select id="deliveryTime" name="deliveryTime" class="field-control time-select">
                <option value="anytime">Anytime</option>
                <option value="9am">By 9:00 AM</option>
                <option value="12pm">By 12:00 PM</option>
                <option value="3pm">By 3:00 PM</option>
                <option value="6pm">By 6:00 PM</option>
              </select>
              <span class="select-arrow">⏷</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Separator -->
      <div class="section-divider"></div>
      
      <!-- Combined Size & Upload Card - 60/40 Split -->
      <div class="size-upload-card">
        <div class="size-upload-columns">
          <!-- Left Column (60%) - Size Selection -->
          <div class="size-upload-left">
            <div class="size-upload-header">
              <span class="size-upload-icon">&#128208;</span>
              <span class="size-upload-title">Poster Size</span>
            </div>
            
            <div class="size-selection-content">
              <div class="size-row">
                <div class="size-group-popular">
                  <div class="size-group-label">Popular Sizes</div>
                  <div class="size-buttons-row">
                    <div class="popular-size-button-card" data-width="48" data-height="36" tabindex="0" role="button">
                      <div class="size-text-card">48" × 36"</div>
                    </div>
                    <div class="popular-size-button-card" data-width="47" data-height="33" tabindex="0" role="button">
                      <div class="size-text-card">47" × 33"</div>
                    </div>
                    <div class="popular-size-button-card" data-width="48" data-height="48" tabindex="0" role="button">
                      <div class="size-text-card">48" × 48"</div>
                    </div>
                    <div class="popular-size-button-card" data-width="96" data-height="48" tabindex="0" role="button">
                      <div class="size-text-card">96" × 48"</div>
                    </div>
                  </div>
                </div>
                
                <div class="size-divider-v"></div>
                
                <div class="size-group-custom">
                  <div class="size-group-label">Custom Size (W &times; H)</div>
                  <div class="size-custom-row">
                    <div class="size-input-wrapper">
                      <input id="w" name="width" type="number" min="12" max="96" placeholder="48" class="field-control-card size-custom-input" data-max="96">
                      <label for="w" class="size-input-label-below">12&ndash;96&quot;</label>
                    </div>
                    <span class="multiply-symbol-card">×</span>
                    <div class="size-input-wrapper">
                      <input id="h" name="height" type="number" min="12" max="48" placeholder="36" class="field-control-card size-custom-input" data-max="48">
                      <label for="h" class="size-input-label-below">12&ndash;48&quot;</label>
                    </div>
                  </div>
                  <div id="sizeErrorMessage" class="size-error-message" style="display: none;">
                    <span class="error-icon">&#9888;️</span>
                    <span class="error-text">Size exceeds maximum dimensions</span>
                  </div>
                </div>
              </div>
              
              <!-- Toggle button below size row -->
              <button type="button" class="size-alt-toggle" id="similarSizesToggle">
                <span>View Proportional Alternative Sizes</span>
                <span class="toggle-arrow">⏷</span>
              </button>
              
              <!-- Alternative sizes expand here -->
              <div id="similarSizesContent" class="similar-sizes-inline" style="display: none;">
                <div id="alternativeSizesSection">
                  <div id="popularSizes" class="alt-sizes-grid">
                    <div class="similar-placeholder">
                      Select a size above to see proportional alternatives
                    </div>
                  </div>
                </div>
              </div>

              <!-- Material Selection (moved from pricing) -->
              <div class="material-selection-inline" id="materialToggleInline">
                <div class="material-inline-label">Print Material</div>
                <div class="material-toggle-pricing" id="materialToggle">
                  <div class="material-slider-pricing" id="materialSlider"></div>
                  <div class="material-option-pricing tooltip-container" id="posterOption" data-material="poster">
                    <span class="material-text-pricing">Poster</span>
                    <span class="material-delta-badge" id="posterDeltaBadge"></span>
                    <div class="material-tooltip-pricing">100 lb paper, UV printing, glossy finish</div>
                  </div>
                  <div class="material-option-pricing tooltip-container" id="fabricOption" data-material="fabric">
                    <span class="material-text-pricing">Fabric</span>
                    <span class="material-delta-badge" id="fabricDeltaBadge"></span>
                    <div class="material-tooltip-pricing">7 oz wrinkle-free fabric with hemmed edges</div>
                  </div>
                </div>
              </div>

              <!-- Upload Design (moved from right column) -->
              <div class="upload-section-inline">
                <div class="size-upload-header upload-header-inline">
                  <span class="size-upload-icon">&#128194;</span>
                  <span class="size-upload-title">Upload Design</span>
                  <div class="step-tooltip">
                    ?
                    <span class="tooltiptext">Ensure your design file matches your selected poster size for best results.</span>
                  </div>
                </div>

                <div class="upload-zone-compact" id="uploadZone">
                  <!-- Pre-upload content -->
                  <div class="upload-content-preload" id="uploadPreload">
                    <div class="upload-icon-compact">&#128194;</div>
                    <div class="upload-text-compact">
                      <div class="upload-main-line"><strong>Click to upload</strong> or drag file</div>
                      <div class="upload-formats-compact"><span class="pdf-preferred">PDF preferred</span> <span class="conversion-fee-small">(+$5 non-PDF)</span></div>
                      <div class="upload-formats-small">JPG, PNG, AI, EPS, PSD, TIFF, SVG, PPTX • Max 100MB</div>
                    </div>
                  </div>

                  <!-- Upload progress (hidden by default) -->
                  <div class="upload-content-progress" id="uploadProgress" style="display: none;">
                    <div class="upload-progress-icon">&#128194;</div>
                    <div class="upload-progress-details">
                      <div class="upload-progress-filename" id="uploadProgressFilename">filename.pdf</div>
                      <div class="upload-progress-bar-container">
                        <div class="upload-progress-bar" id="uploadProgressBar"></div>
                      </div>
                      <div class="upload-progress-status">
                        <span id="uploadProgressPercent">0%</span>
                        <span id="uploadProgressSize">0 MB / 2.5 MB</span>
                      </div>
                    </div>
                  </div>

                  <!-- Success content (hidden by default) -->
                  <div class="upload-content-success-compact" id="uploadSuccess" style="display: none;">
                    <div class="upload-success-icon-compact">&#10004;️</div>
                    <div class="upload-success-details-compact">
                      <div class="upload-success-title-compact">File Uploaded</div>
                      <div class="upload-success-filename-compact" id="uploadedFileName">filename.pdf</div>
                      <div class="upload-success-meta-compact">
                        <span class="upload-file-type" id="uploadedFileType">PDF</span>
                        <span class="meta-separator">•</span>
                        <span id="uploadedFileSize">2.5 MB</span>
                      </div>
                      <button type="button" class="upload-remove-btn-compact" id="removeFileBtn">&#10006;️ Remove</button>
                    </div>
                  </div>
                </div>
                <input type="file" id="fileInput" name="artwork" accept=".pdf,.ai,.eps,.psd,.png,.jpg,.jpeg,.tiff,.tif,.webp,.gif,.bmp,.svg,.pptx,.indd" style="display: none;">
              </div>
            </div>
          </div>
          
          <!-- Vertical Divider between Size and Upload -->
          <div class="size-upload-divider"></div>
          
          <!-- Right Column (40%) - Poster Preview (always visible) -->
          <div class="size-upload-right">
            <div class="size-upload-header">
              <span class="size-upload-icon">&#128208;</span>
              <span class="size-upload-title">Poster Preview</span>
            </div>

            <div class="board-preview-section board-preview-always-visible" id="boardPreviewSection">
              <div class="board-mockup-container">
                <div class="board-label-top">MTCC Presentation Board (96" × 48")</div>
                <div class="board-frame">
                  <div class="board-surface" id="boardSurface">
                    <div class="poster-preview poster-preview-placeholder" id="posterPreview">
                      <span class="poster-preview-size" id="posterPreviewSize">Select a size to<br>see preview</span>
                    </div>
                  </div>
                </div>
                <div class="board-legs">
                  <div class="board-leg left"></div>
                  <div class="board-leg right"></div>
                </div>
              </div>
              <div class="board-coverage" id="boardCoverage" style="display: none;">Your poster covers <strong>0%</strong> of the presentation board</div>
            </div>
          </div>
        </div>
        
      </div>

      <!-- PRICING PLACEHOLDER (always visible) -->
      <div id="pricingSection" class="pricing-section">
        <div class="pricing-header-row">
          <div class="section-group-header">YOUR PRICING OPTIONS<span id="pricingSizeDisplay" class="pricing-size-display"></span></div>
          <div class="pricing-material-toggle" id="pricingMaterialToggle">
            <div class="material-inline-label">Material</div>
            <div class="material-toggle-pricing" id="materialTogglePricing">
              <div class="material-slider-pricing" id="materialSliderPricing"></div>
              <div class="material-option-pricing tooltip-container" id="posterOptionPricing" data-material="poster">
                <span class="material-text-pricing">Poster</span>
                <span class="material-delta-badge" id="posterDeltaBadgePricing"></span>
                <div class="material-tooltip-pricing">100 lb paper, UV printing, glossy finish</div>
              </div>
              <div class="material-option-pricing tooltip-container" id="fabricOptionPricing" data-material="fabric">
                <span class="material-text-pricing">Fabric</span>
                <span class="material-delta-badge" id="fabricDeltaBadgePricing"></span>
                <div class="material-tooltip-pricing">7 oz wrinkle-free fabric with hemmed edges</div>
              </div>
            </div>
          </div>
        </div>
        <div class="pricing-divider"></div>

        <div id="pricing" class="pricing-container">
          <div class="pricing-placeholder">
            <div class="placeholder-icon">&#128176;</div>
            <div class="placeholder-text">
              <div class="placeholder-title">Select your event, delivery date, and poster size to see pricing</div>
              <div class="placeholder-subtitle">Pricing varies based on size and how soon you need your poster</div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Contact Information -->
    <div class="card card--spaced">
      <div class="step-header">
        <span class="card-icon">&#128100;</span>
        <div class="step-header-content">
          <span class="step-title">Contact Information</span>
        </div>
        <!--<div class="step-note">
          <strong>ℹ️ Note:</strong> This will be used as your billing information
        </div>-->
      </div>
      <div class="step-divider"></div>
      
      <!-- Row 1: Name, Company, Phone (33/34/33) -->
      <div class="contact-row-1">
        <div class="field">
          <label for="customerName">Full Name <span class="required-field">*</span></label>
          <input id="customerName" name="customerName" type="text" placeholder="e.g. John Smith" class="field-control" required>
        </div>
        <div class="field">
          <label for="customerCompany">Company/Organization <span class="optional-field">(optional)</span></label>
          <input id="customerCompany" name="customerCompany" type="text" placeholder="e.g. University of Toronto" class="field-control">
        </div>
        <div class="field">
          <label for="customerPhone">Phone Number <span class="required-field">*</span></label>
          <div class="phone-input-container">
            <div class="country-selector" id="countrySelector">
              <div class="selected-country" id="selectedCountry">
                <img src="" alt="" class="country-flag" id="countryFlag">
                <span class="country-code" id="countryCode">+1</span>
                <svg class="dropdown-arrow" width="12" height="8" viewBox="0 0 12 8">
                  <path d="M1 1l5 5 5-5" stroke="currentColor" stroke-width="2" fill="none"/>
                </svg>
              </div>
              <div class="country-dropdown" id="countryDropdown">
                <div class="search-box">
                  <input type="text" placeholder="Search for countries" class="country-search" id="countrySearch">
                </div>
                <div class="country-list" id="countryList">
                  <!-- Countries will be populated by JavaScript -->
                </div>
              </div>
            </div>
            <input id="customerPhone" name="customerPhone" type="tel" placeholder="(416) 555-0123" class="phone-number-input" required>
            <span class="phone-valid-icon" id="phoneValidIcon">&#10013;</span>
          </div>
        </div>
      </div>
      
      <!-- Row 2: Email, Additional Notes (50/50) -->
      <div class="contact-row-2">
        <div class="field">
          <label for="customerEmail">Email Address <span class="required-field">*</span></label>
          <div class="email-input-wrapper">
            <input id="customerEmail" name="customerEmail" type="email" placeholder="e.g. john@university.ca" class="field-control" required>
            <span class="email-valid-icon" id="emailValidIcon">&#10013;</span>
          </div>
        </div>
        <div class="field">
          <label for="additionalNotes">Additional Notes <span class="optional-field">(optional)</span></label>
          <textarea id="additionalNotes" name="additionalNotes" rows="1" placeholder="Printing instructions, pickup requests, or questions." class="field-control"></textarea>
        </div>
      </div>
      
      <!-- Delivery Preference Sub-section -->
      <div class="delivery-subsection">
        <div class="subsection-header">
          <span class="subsection-icon">&#128666;</span>
          <span class="subsection-title">Delivery Preference</span>
          <div class="step-tooltip">
            ?
            <span class="tooltiptext">Choose where you'd like to receive your poster. MTCC delivery is free and convenient for convention attendees, while address delivery offers flexibility for $10.</span>
          </div>
        </div>
        
        <div class="grid-2 gap-md delivery-options">
          <div class="delivery-option" id="mtccOption" data-option="mtcc" tabindex="0" role="button">
            <div class="option-title">Deliver to MTCC</div>
            <div class="option-subtitle">
              <span class="free-delivery-badge">Free Delivery</span>
            </div>
          </div>
          <div class="delivery-option" id="officeOption" data-option="office" tabindex="0" role="button">
            <div class="option-title">Deliver to Address</div>
            <div class="option-subtitle">$10.00 flat rate</div>
          </div>
        </div>
        
        <div id="deliveryDetails" class="delivery-details">
          <div id="mtccMessage" class="delivery-message">
            <h4 class="delivery-title">MTCC Delivery Details</h4>
            <p class="delivery-text">Your order will be ready for pick-up on <strong id="d">your selected delivery date</strong> by <strong>4:00 PM</strong> at:</p>
            <div class="delivery-address" id="mtccAddressDisplay">
              <strong>Exhibitor Services / Business Centre Office</strong><br>
              Metro Toronto Convention Centre<br>
              <span id="mtccBuildingName">North Building</span>, <span id="mtccBuildingLevel">Level 300</span><br>
              <span id="mtccStreetAddress">255 Front Street West</span>, Toronto, ON <span id="mtccPostalCode">M5V 2W6</span><br>
              <br/>
              <p>Mon &ndash; Fri: 8:00 AM &ndash; 4:00 PM   |   416-585-8387   |   <a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="0b6e73636269627f647926786e797d62686e784b667f68686825686466">[email&#160;protected]</a><br/>
                </p>
            </div>
          </div>
          
          <div id="addressForm" class="address-form">
            <div class="flex-col gap-md">
              <div class="field">
                <label for="deliveryAttn">Attention to <span class="required-field">*</span></label>
                <input id="deliveryAttn" name="deliveryAttn" type="text" class="field-control">
              </div>
              <div class="grid-2 gap-md">
                <div class="field">
                  <label for="deliveryAddress">Street Address <span class="required-field">*</span></label>
                  <input id="deliveryAddress" name="deliveryAddress" type="text" class="field-control">
                </div>
                <div class="field">
                  <label for="deliveryUnit">Apt, Unit, Suite</label>
                  <input id="deliveryUnit" name="deliveryUnit" type="text" class="field-control">
                </div>
              </div>
              <div class="grid-3 gap-md">
                <div class="field">
                  <label for="deliveryCity">City <span class="required-field">*</span></label>
                  <input id="deliveryCity" name="deliveryCity" type="text" class="field-control">
                </div>
                <div class="field">
                  <label for="deliveryProvince">Province <span class="required-field">*</span></label>
                  <input id="deliveryProvince" name="deliveryProvince" type="text" class="field-control">
                </div>
                <div class="field">
                  <label for="deliveryPostal">Postal Code <span class="required-field">*</span></label>
                  <input id="deliveryPostal" name="deliveryPostal" type="text" class="field-control">
                </div>
              </div>
              <div class="field">
                <label for="deliveryInstructions">Special Instructions <span class="optional-field">(optional)</span></label>
                <textarea id="deliveryInstructions" name="deliveryInstructions" rows="3" placeholder="Building entrance, floor, suite number, etc." class="field-control"></textarea>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    
    <!-- Order Summary & Form Completion (combined card) -->
    <div class="card card--spaced">
      <div class="summary-completion-card">
        <!-- Left: Order Summary -->
        <div class="summary-completion-left">
          <div class="step-header">
            <span class="card-icon">&#128203;</span>
            <div class="step-header-content">
              <span class="step-title">Order Summary</span>
            </div>
          </div>
          <div class="step-divider"></div>

          <div class="order-summary">
            <div class="summary-row">
              <div class="summary-label">
                <div class="summary-title">Event & Poster Details:</div>
                <div class="summary-content" id="summaryPosterDetails">Select event, size and date above</div>
              </div>
              <span class="summary-price" id="summaryBasePrice">-</span>
            </div>

            <div class="summary-row">
              <div class="summary-label">
                <div class="summary-title">Delivery Details:</div>
                <div class="summary-content" id="summaryDeliveryDetails"></div>
              </div>
              <span class="summary-price" id="summaryDeliveryFee">-</span>
            </div>

            <div id="conversionFeeRow" class="summary-row conversion-fee-row">
              <div class="summary-label">
                <div class="summary-title">File Conversion:</div>
                <div class="summary-content">Non-PDF file conversion to print format</div>
              </div>
              <span class="summary-price conversion-fee">$5.00</span>
            </div>

            <div class="summary-row subtotal-row">
              <span class="subtotal-label">Subtotal</span>
              <span class="subtotal-price" id="summarySubtotal">-</span>
            </div>

            <div class="summary-row tax-row">
              <span class="tax-label">HST (13%)</span>
              <span class="tax-price" id="summaryTax">-</span>
            </div>

            <div class="summary-row total-row">
              <span class="total-label">Total (CAD)</span>
              <span class="total-price" id="summaryTotal">-</span>
            </div>
          </div>
        </div>

        <!-- Dashed Divider -->
        <div class="summary-completion-divider"></div>

        <!-- Right: Form Completion & Submit -->
        <div class="summary-completion-right">
          <div id="formValidation" class="form-validation">
            <div class="step-header validation-header">
              <span class="card-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
              <div class="step-header-content">
                <span class="step-title">Form Completion Status</span>
              </div>
            </div>
            <div class="step-divider validation-divider"></div>
            <div class="status-badges-container">
              <div id="step1Status" class="status-badge-modern">
                <div class="status-indicator">
                  <span class="status-icon-modern">&#10006;</span>
                </div>
                <span class="status-text-modern">Event & Date</span>
              </div>
              <div id="step2Status" class="status-badge-modern">
                <div class="status-indicator">
                  <span class="status-icon-modern">&#10006;</span>
                </div>
                <span class="status-text-modern">Poster Size</span>
              </div>
              <div id="step3Status" class="status-badge-modern">
                <div class="status-indicator">
                  <span class="status-icon-modern">&#10006;</span>
                </div>
                <span class="status-text-modern">File Upload</span>
              </div>
              <div id="step4Status" class="status-badge-modern">
                <div class="status-indicator">
                  <span class="status-icon-modern">&#10006;</span>
                </div>
                <span class="status-text-modern">Delivery</span>
              </div>
              <div id="step5Status" class="status-badge-modern">
                <div class="status-indicator">
                  <span class="status-icon-modern">&#10006;</span>
                </div>
                <span class="status-text-modern">Contact</span>
              </div>
            </div>
          </div>

          <button type="submit" class="submit-button" id="submitButton" disabled>
            <div class="submit-button-content" id="submitButtonContent">
              <span class="submit-button-title">Proceed to Payment</span>
              <span class="submit-button-subtitle">Free quote &ndash; no payment required</span>
            </div>
            <div class="submit-button-loading" id="submitButtonLoading" style="display: none;">
              <div class="submit-spinner"></div>
              <span class="submit-loading-text">Submitting your order...</span>
            </div>
          </button>
        </div>
      </div>
      <div class="summary-completion-footer">
        <div class="order-confidence-note">
          <strong>&#9889; Fast Response:</strong> Get artwork confirmation within <strong>18 minutes.</strong>
        </div>
        <div class="order-policy-note">
          Once your order moves to production, file changes cannot be accommodated. Please ensure your file is final before submitting.
        </div>
      </div>
    </div>
    
    </form>

    <!-- Sticky CTA Bar -->
    <div class="sticky-cta-bar" id="stickyCta">
      <div class="sticky-cta-inner">
        <div class="sticky-cta-price">
          <span class="sticky-cta-label">Total</span>
          <span class="sticky-cta-total" id="stickyTotal">$-</span>
        </div>
        <button type="button" class="sticky-cta-button" id="stickySubmitBtn" disabled>
          Proceed to Payment
        </button>
      </div>
    </div>

</div>

  <!-- Tawk.to Live Chat Widget -->
  <!--Start of Tawk.to Script-->
  <script type="text/javascript">
  var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();
  (function(){
  var s1=document.createElement("script"),s0=document.getElementsByTagName("script")[0];
  s1.async=true;
  s1.src='https://embed.tawk.to/69bcadcf600a121c36fa7a4b/1jk4gdsmg';
  s1.charset='UTF-8';
  s1.setAttribute('crossorigin','*');
  s0.parentNode.insertBefore(s1,s0);
  })();
  </script>
  <!--End of Tawk.to Script-->

  <div class="footer">© <span id="yr"></span> Print Stuff</div>

	<?php
    // Inject delivery configuration as inline JS (single source of truth)
    $deliveryConfig = require __DIR__ . '/delivery-config.php';
    echo '<script>window.DELIVERY_CONFIG = ' . json_encode($deliveryConfig) . ';</script>';

    // Inject MTCC locations data for dynamic delivery details
    $mtccLocations = json_decode(file_get_contents(__DIR__ . '/data/mtcc-locations.json'), true);
    echo '<script>window.MTCC_LOCATIONS = ' . json_encode($mtccLocations) . ';</script>';
  ?>
	<script src="script.js?v=20260226-delivery-enforcement"></script>
  <script>
    // Similar Sizes Toggle Functionality
    document.addEventListener('DOMContentLoaded', function() {
      const toggle = document.getElementById('similarSizesToggle');
      const content = document.getElementById('similarSizesContent');
      
      if (toggle && content) {
        toggle.addEventListener('click', function() {
          const isExpanded = content.style.display !== 'none';
          content.style.display = isExpanded ? 'none' : 'block';
          toggle.classList.toggle('expanded', !isExpanded);
        });
      }
      
      // Size Input Validation
      const widthInput = document.getElementById('w');
      const heightInput = document.getElementById('h');
      const errorMessage = document.getElementById('sizeErrorMessage');
      
      function validateSizeInput(input) {
        const value = parseInt(input.value);
        const max = parseInt(input.dataset.max);
        const min = parseInt(input.min);
        
        if (value > max || value < min) {
          input.classList.add('size-input-error');
          if (errorMessage) {
            const errorText = errorMessage.querySelector('.error-text');
            if (value > max) {
              errorText.textContent = `Maximum ${input.id === 'w' ? 'width is ' + max : 'height is ' + max}"`;
            } else {
              errorText.textContent = `Minimum size is ${min}"`;
            }
            errorMessage.style.display = 'flex';
          }
        } else {
          input.classList.remove('size-input-error');
          // Only hide error if both inputs are valid
          const otherInput = input.id === 'w' ? heightInput : widthInput;
          const otherValue = parseInt(otherInput.value);
          const otherMax = parseInt(otherInput.dataset.max);
          const otherMin = parseInt(otherInput.min);
          if (!otherValue || (otherValue <= otherMax && otherValue >= otherMin)) {
            if (errorMessage) errorMessage.style.display = 'none';
          }
        }
      }
      
      if (widthInput) {
        widthInput.addEventListener('input', function() { validateSizeInput(this); });
      }
      
      if (heightInput) {
        heightInput.addEventListener('input', function() { validateSizeInput(this); });
      }
      
      // File Upload Success Handling - V15 Override
      const uploadZone = document.getElementById('uploadZone');
      const fileInput = document.getElementById('fileInput');
      const uploadPreload = document.getElementById('uploadPreload');
      const uploadSuccess = document.getElementById('uploadSuccess');
      const removeFileBtn = document.getElementById('removeFileBtn');
      
      function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
      }
      
      function getFileExtension(filename) {
        return filename.split('.').pop().toUpperCase();
      }
      
      // Show file thumbnail preview (replaces old V15 success display)
      function showUploadSuccessV15(file) {
        if (window.uploadProgressRunning) return;

        // Hide preload, progress, and old success content
        uploadPreload.style.display = 'none';
        var uploadProgressEl = document.getElementById('uploadProgress');
        if (uploadProgressEl) uploadProgressEl.style.display = 'none';
        if (uploadSuccess) uploadSuccess.style.display = 'none';

        // Use the thumbnail-based updateFileDisplay from script.js
        if (typeof window._thumbnailFileDisplay === 'function') {
          window._thumbnailFileDisplay(file);
        }
        uploadZone.classList.add('upload-success');
      }

      function resetUploadV15() {
        uploadPreload.style.display = 'flex';
        if (uploadSuccess) uploadSuccess.style.display = 'none';
        var uploadProgressEl = document.getElementById('uploadProgress');
        if (uploadProgressEl) uploadProgressEl.style.display = 'none';
        uploadZone.classList.remove('upload-success');
        uploadZone.classList.remove('has-file');
        fileInput.value = '';
        window.uploadProgressRunning = false;

        // Remove thumbnail preview
        var oldPreview = uploadZone.querySelector('.file-preview-display');
        if (oldPreview) oldPreview.remove();
      }

      // Override removeFile
      window.removeFile = function() {
        resetUploadV15();
        if (window.state) {
          window.state.uploadedFile = null;
        }
        if (typeof checkConversionFee === 'function') checkConversionFee(null);
        if (typeof updateOrderSummary === 'function') updateOrderSummary();
        if (typeof updateSubmitButtonState === 'function') updateSubmitButtonState();
      };

      if (removeFileBtn) {
        removeFileBtn.addEventListener('click', function(e) {
          e.stopPropagation();
          window.removeFile();
        });
      }

      // Prevent upload zone click when file is uploaded
      if (uploadZone) {
        uploadZone.addEventListener('click', function(e) {
          if (uploadZone.classList.contains('upload-success')) {
            e.preventDefault();
            e.stopPropagation();
          }
        });
      }
      
      // Fix: Prevent time select clicks from triggering date picker
      const timeSelectWrapper = document.querySelector('.time-select-wrapper');
      if (timeSelectWrapper) {
        timeSelectWrapper.addEventListener('click', function(e) {
          e.stopPropagation();
        });
      }
      
      const deliveryTimeSelect = document.getElementById('deliveryTime');
      if (deliveryTimeSelect) {
        deliveryTimeSelect.addEventListener('click', function(e) {
          e.stopPropagation();
        });
        deliveryTimeSelect.addEventListener('mousedown', function(e) {
          e.stopPropagation();
        });
      }
      
      // Fix pricing placeholder structure when JS updates it
      const pricingContainer = document.getElementById('pricing');
      if (pricingContainer) {
        const observer = new MutationObserver(function(mutations) {
          const placeholder = pricingContainer.querySelector('.pricing-placeholder');
          if (placeholder && !placeholder.querySelector('.placeholder-text')) {
            const title = placeholder.querySelector('.placeholder-title');
            const subtitle = placeholder.querySelector('.placeholder-subtitle');
            const icon = placeholder.querySelector('.placeholder-icon');
            
            if (title && icon) {
              // Create wrapper for text
              const textWrapper = document.createElement('div');
              textWrapper.className = 'placeholder-text';
              
              // Clone and append (to avoid DOM manipulation issues)
              if (title) {
                textWrapper.appendChild(title.cloneNode(true));
                title.remove();
              }
              if (subtitle) {
                textWrapper.appendChild(subtitle.cloneNode(true));
                subtitle.remove();
              }
              
              placeholder.appendChild(textWrapper);
            }
          }
        });
        
        observer.observe(pricingContainer, { childList: true, subtree: true });
      }
      
      // Email validation with real-time feedback
      const emailInput = document.getElementById('customerEmail');
      const emailWrapper = emailInput ? emailInput.closest('.email-input-wrapper') : null;
      
      function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
      }
      
      if (emailInput && emailWrapper) {
        emailInput.addEventListener('input', function() {
          const value = this.value.trim();
          emailWrapper.classList.remove('valid', 'invalid');
          
          if (value.length > 0) {
            if (validateEmail(value)) {
              emailWrapper.classList.add('valid');
            } else if (value.includes('@')) {
              emailWrapper.classList.add('invalid');
            }
          }
        });
        
        emailInput.addEventListener('blur', function() {
          const value = this.value.trim();
          emailWrapper.classList.remove('valid', 'invalid');
          
          if (value.length > 0) {
            if (validateEmail(value)) {
              emailWrapper.classList.add('valid');
            } else {
              emailWrapper.classList.add('invalid');
            }
          }
        });
      }
      
      // Enhanced drag & drop (reuses uploadZone from above)
      if (uploadZone) {
        ['dragenter', 'dragover'].forEach(eventName => {
          uploadZone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!uploadZone.classList.contains('upload-success')) {
              uploadZone.classList.add('drag-over');
            }
          });
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
          uploadZone.addEventListener(eventName, function(e) {
            e.preventDefault();
            e.stopPropagation();
            uploadZone.classList.remove('drag-over');
          });
        });
      }
      
      // Size visualization - Presentation Board Mockup (always visible)
      const boardPreviewSection = document.getElementById('boardPreviewSection');
      const posterPreview = document.getElementById('posterPreview');
      const posterPreviewSize = document.getElementById('posterPreviewSize');
      const boardCoverage = document.getElementById('boardCoverage');
      // Reuses widthInput and heightInput from above
      
      // Board dimensions: 96" x 48" at 5px per inch = 480px x 240px
      const BOARD_WIDTH_INCHES = 96;
      const BOARD_HEIGHT_INCHES = 48;
      const SCALE = 5; // pixels per inch
      
      function updateSizeVisualization() {
        const width = parseInt(widthInput?.value) || 0;
        const height = parseInt(heightInput?.value) || 0;

        if (width >= 12 && height >= 12 && posterPreview && posterPreviewSize && boardCoverage) {
          // Calculate poster size as percentage of board (fluid scaling)
          const widthPercent = Math.min((width / BOARD_WIDTH_INCHES) * 100, 100);
          const heightPercent = Math.min((height / BOARD_HEIGHT_INCHES) * 100, 100);

          posterPreview.style.width = widthPercent + '%';
          posterPreview.style.height = heightPercent + '%';
          posterPreviewSize.textContent = width + '" × ' + height + '"';

          // Adjust font size based on poster coverage
          if (widthPercent < 25 || heightPercent < 30) {
            posterPreviewSize.style.fontSize = '0.6rem';
          } else if (widthPercent < 40 || heightPercent < 50) {
            posterPreviewSize.style.fontSize = '0.75rem';
          } else {
            posterPreviewSize.style.fontSize = '0.9rem';
          }
          
          // Calculate coverage percentage
          const boardArea = BOARD_WIDTH_INCHES * BOARD_HEIGHT_INCHES;
          const posterArea = width * height;
          const coveragePercent = Math.min(Math.round((posterArea / boardArea) * 100), 100);
          
          // Update coverage text with context
          let coverageText = '';
          if (width > BOARD_WIDTH_INCHES || height > BOARD_HEIGHT_INCHES) {
            coverageText = '&#9888;️ <strong style="color: #ef4444;">Poster exceeds board dimensions</strong> • will extend beyond presentation board';
          } else if (coveragePercent >= 80) {
            coverageText = 'Your poster covers <strong>' + coveragePercent + '%</strong> of the board • <span style="color: var(--green);">Great visibility!</span>';
          } else if (coveragePercent >= 50) {
            coverageText = 'Your poster covers <strong>' + coveragePercent + '%</strong> of the board • Good size';
          } else {
            coverageText = 'Your poster covers <strong>' + coveragePercent + '%</strong> of the board • Compact display';
          }
          boardCoverage.innerHTML = coverageText;
          boardCoverage.style.display = '';
          posterPreview.classList.remove('poster-preview-placeholder');
          // Show uploaded file in poster preview if available
          if (typeof updatePosterPreviewWithFile === 'function') updatePosterPreviewWithFile(null);
        } else if (posterPreview && posterPreviewSize && boardCoverage) {
          // No valid size — show placeholder, hide coverage
          posterPreview.style.width = '';
          posterPreview.style.height = '';
          posterPreview.classList.add('poster-preview-placeholder');
          posterPreviewSize.innerHTML = 'Select a size to<br>see preview';
          posterPreviewSize.style.fontSize = '';
          boardCoverage.style.display = 'none';
        }
      }
      
      // Always update preview on size input changes (preview is always visible)
      if (widthInput) {
        widthInput.addEventListener('input', function() {
          updateSizeVisualization();
        });
      }
      if (heightInput) {
        heightInput.addEventListener('input', function() {
          updateSizeVisualization();
        });
      }
      
      // Also update when popular sizes are clicked
      document.querySelectorAll('.popular-size-button-card').forEach(function(btn) {
        btn.addEventListener('click', function() {
          setTimeout(function() {
            updateSizeVisualization();
          }, 50);
        });
        // Keyboard support
        btn.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.click();
          }
        });
      });
      
      // Update when alternative sizes are clicked
      const alternativeSizesContainer = document.getElementById('popularSizes');
      if (alternativeSizesContainer) {
        alternativeSizesContainer.addEventListener('click', function(e) {
          const altBtn = e.target.closest('.alternative-size-button');
          if (altBtn) {
            setTimeout(function() {
              updateSizeVisualization();
            }, 50);
          }
        });
      }

      // Initialize preview with default dimensions on page load
      updateSizeVisualization();
      
      // Keyboard support for delivery options
      document.querySelectorAll('.delivery-option:not(.disabled)').forEach(function(option) {
        option.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            this.click();
          }
        });
      });
      
      // Submit button loading state - visual only, script.js handles actual submission
      const orderForm = document.getElementById('orderForm');
      const submitButton = document.getElementById('submitButton');
      const submitButtonContent = document.getElementById('submitButtonContent');
      const submitButtonLoading = document.getElementById('submitButtonLoading');
      
      if (orderForm && submitButton) {
        // Debug: Log when form submit is triggered
        orderForm.addEventListener('submit', function(e) {
          console.log('MTCC-v16 submit handler triggered');
          console.log('Button disabled state:', submitButton.disabled);
          
          // Only show loading visual - don't disable button here
          // script.js handles validation and will disable the button after its checks pass
          if (submitButtonContent) submitButtonContent.style.display = 'none';
          if (submitButtonLoading) submitButtonLoading.style.display = 'flex';
          submitButton.classList.add('loading');
          
          console.log('Loading state applied, form should submit...');
        });
        
        // Also add a click handler to debug
        submitButton.addEventListener('click', function(e) {
          console.log('Submit button clicked');
          console.log('Button disabled:', this.disabled);
          console.log('Form valid:', orderForm.checkValidity());
        });
      }
      
      // Upload progress handler (reuses formatFileSize from above)
      const uploadProgressBar = document.getElementById('uploadProgressBar');
      const uploadProgressPercent = document.getElementById('uploadProgressPercent');
      const uploadProgressSize = document.getElementById('uploadProgressSize');
      const uploadProgressFilename = document.getElementById('uploadProgressFilename');
      const uploadProgressEl = document.getElementById('uploadProgress');
      
      function simulateUploadProgress(file, onComplete) {
        const totalSize = file.size;
        const totalFormatted = formatFileSize(totalSize);
        let progress = 0;
        
        // Set flag to prevent success from showing during progress
        window.uploadProgressRunning = true;
        
        // Hide preload and success, show progress
        if (uploadPreload) uploadPreload.style.display = 'none';
        if (uploadSuccess) uploadSuccess.style.display = 'none';
        if (uploadProgressEl) uploadProgressEl.style.display = 'flex';
        if (uploadProgressFilename) uploadProgressFilename.textContent = file.name;
        
        // Reset progress bar
        if (uploadProgressBar) uploadProgressBar.style.width = '0%';
        if (uploadProgressPercent) uploadProgressPercent.textContent = '0%';
        if (uploadProgressSize) uploadProgressSize.textContent = '0 B / ' + totalFormatted;
        
        const interval = setInterval(function() {
          const increment = Math.random() * 15 + 5;
          progress = Math.min(progress + increment, 100);
          
          const loadedBytes = Math.floor((progress / 100) * totalSize);
          
          if (uploadProgressBar) uploadProgressBar.style.width = progress + '%';
          if (uploadProgressPercent) uploadProgressPercent.textContent = Math.round(progress) + '%';
          if (uploadProgressSize) uploadProgressSize.textContent = formatFileSize(loadedBytes) + ' / ' + totalFormatted;
          
          if (progress >= 100) {
            clearInterval(interval);
            setTimeout(function() {
              // Clear flag and show success
              window.uploadProgressRunning = false;
              if (uploadProgressEl) uploadProgressEl.style.display = 'none';
              
              // Now show success with the stored file info
              if (window.pendingUploadFile) {
                showUploadSuccessV15(window.pendingUploadFile);
              } else if (onComplete) {
                onComplete();
              }
            }, 300);
          }
        }, 100);
      }
      
      // Override file input change to add progress
      if (fileInput) {
        fileInput.addEventListener('change', function(e) {
          const file = e.target.files[0];
          if (file) {
            window.pendingUploadFile = file;
            simulateUploadProgress(file, function() {
              showUploadSuccessV15(file);
            });
          }
        }, true);
      }
      // Sticky CTA bar — show when user scrolls past pricing, sync total + disabled state
      var stickyCta = document.getElementById('stickyCta');
      var stickyTotal = document.getElementById('stickyTotal');
      var stickySubmitBtn = document.getElementById('stickySubmitBtn');
      var mainSubmitBtn = document.getElementById('submitButton');
      var pricingSectionEl = document.getElementById('pricingSection');

      if (stickyCta && pricingSectionEl) {
        // Show/hide on scroll
        function checkStickyVisibility() {
          var rect = pricingSectionEl.getBoundingClientRect();
          // Show sticky CTA once the top of pricing scrolls off viewport
          if (rect.top < 0) {
            stickyCta.classList.add('visible');
          } else {
            stickyCta.classList.remove('visible');
          }
        }
        window.addEventListener('scroll', checkStickyVisibility, { passive: true });

        // Sync total from order summary
        var summaryTotalEl = document.getElementById('summaryTotal');
        if (summaryTotalEl && stickyTotal) {
          var totalObserver = new MutationObserver(function() {
            stickyTotal.textContent = summaryTotalEl.textContent;
          });
          totalObserver.observe(summaryTotalEl, { childList: true, characterData: true, subtree: true });
        }

        // Sync disabled state from main submit button
        if (mainSubmitBtn && stickySubmitBtn) {
          var btnObserver = new MutationObserver(function() {
            stickySubmitBtn.disabled = mainSubmitBtn.disabled;
          });
          btnObserver.observe(mainSubmitBtn, { attributes: true, attributeFilter: ['disabled'] });

          // Click sticky button triggers main form submit
          stickySubmitBtn.addEventListener('click', function() {
            if (!mainSubmitBtn.disabled) {
              mainSubmitBtn.click();
            }
          });
        }
      }

      // Pricing header "switch to Fabric/Poster" link
      var matSwitchLink = document.getElementById('pricingMaterialSwitch');
      if (matSwitchLink) {
        matSwitchLink.addEventListener('click', function(e) {
          e.preventDefault();
          if (typeof toggleMaterial === 'function') {
            toggleMaterial();
          }
        });
      }

      // Help is now handled by Tawk.to live chat widget
    });
  </script>
</body>
</html>