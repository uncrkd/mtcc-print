/**
 * Admin Create Order - JavaScript
 * MTCC Print Services
 *
 * Extracted from admin-create-order.php
 * Requires: js/shared/utils.js (escapeHtml)
 * Requires: js/admin-utilities.js
 * Requires: CREATE_ORDER_CONFIG global (set by PHP inline script)
 *   - CREATE_ORDER_CONFIG.icons.calendar
 *   - CREATE_ORDER_CONFIG.icons.checkGreen
 *   - CREATE_ORDER_CONFIG.icons.download
 */

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
    loadEvents();
    loadPricingData();
    initializeCountrySelector();
    initializeFormHandlers();
    initializeDateInputs();

    // Hook file upload to existing conversion fee logic
    const fileInput = document.getElementById('fileInput');
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                updateFilePreview(file);

                // Auto-calculate conversion fee based on file type
                const fileName = file.name.toLowerCase();
                const isPDF = fileName.endsWith('.pdf');
                const conversionFeeInput = document.getElementById('conversion_fee');

                if (conversionFeeInput) {
                    conversionFeeInput.value = isPDF ? '0.00' : '5.00';
                    updateTotal();

                    if (!isPDF) {
                        showCreateOrderNotification('File conversion fee applied: $5.00 for non-PDF files', 'info');
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

    if (submissionInput) {
        submissionInput.addEventListener('change', function() {
            updateSubmissionDisplay();
            updatePriorityTierDynamic();
        });
    }

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
                    try { input.showPicker(); } catch (err) { input.focus(); input.click(); }
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
                    try { input.showPicker(); } catch (err) { input.focus(); input.click(); }
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

    // Tier configuration - icons use HTML entities (safe for innerHTML)
    const tiers = [
        { key: 'early', label: 'Early', days: '10+ Days', minDays: 10, icon: '&#128077;' },
        { key: 'standard', label: 'Standard', days: '5 Days', minDays: 5, icon: CREATE_ORDER_CONFIG.icons.calendar },
        { key: 'rush', label: 'Rush', days: '3 Days', minDays: 3, icon: '&#127939;' },
        { key: 'urgent', label: 'Urgent', days: '2 Days', minDays: 2, icon: '&#128293;' },
        { key: 'critical', label: 'Critical', days: 'Next Day', minDays: 1, icon: '&#128640;' },
        { key: 'lastminute', label: 'Last Minute', days: 'Same Day', minDays: 0, icon: '&#128165;' }
    ];

    // Find the appropriate tier
    let selectedTier = tiers[tiers.length - 1]; // Default to last minute

    for (let i = 0; i < tiers.length; i++) {
        if (diffDays >= tiers[i].minDays) {
            selectedTier = tiers[i];
            break;
        }
    }

    // Update the priority tier display
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

// Page-specific notification function (uses inline styles, no CSS dependency)
function showCreateOrderNotification(message, type) {
    type = type || 'info';
    const notification = document.createElement('div');
    notification.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10000;' +
        'padding: 12px 16px; border-radius: 6px; color: white; font-weight: 500;' +
        'background: ' + (type === 'error' ? '#dc2626' : type === 'success' ? '#10b981' : '#3b82f6') + ';' +
        'box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
    notification.textContent = message;
    document.body.appendChild(notification);

    setTimeout(function() {
        notification.remove();
    }, 3000);
}

// Override the shared showNotification for this page's simpler style
function showNotification(message, type) {
    showCreateOrderNotification(message, type);
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
        const response = await fetch('admin/get-events.php');

        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }

        const result = await response.json();

        // get-events.php returns { success: true, data: { active: [], archived: [] } }
        const eventsData = result.data || result;

        const eventSelect = document.getElementById('event_select');
        if (!eventSelect) {
            throw new Error('Event select element not found');
        }

        eventSelect.innerHTML = '<option value="">Select an event...</option>';

        // Add active events
        if (eventsData.active && Array.isArray(eventsData.active)) {
            eventsData.active.forEach(function(event) {
                const option = document.createElement('option');
                option.value = event.acronym;
                option.textContent = event.name + ' (' + event.acronym + ')';
                eventSelect.appendChild(option);
            });
        }

        // Add archived events
        if (eventsData.archived && Array.isArray(eventsData.archived)) {
            const optgroup = document.createElement('optgroup');
            optgroup.label = 'Archived Events';
            eventsData.archived.forEach(function(event) {
                const option = document.createElement('option');
                option.value = event.acronym;
                option.textContent = event.name + ' (' + event.acronym + ') - ARCHIVED';
                optgroup.appendChild(option);
            });
            if (eventsData.archived.length > 0) {
                eventSelect.appendChild(optgroup);
            }
        }
    } catch (error) {
        showNotification('Failed to load events: ' + error.message, 'error');

        // Create fallback events directly
        const eventSelect = document.getElementById('event_select');
        if (eventSelect) {
            eventSelect.innerHTML = '<option value="">Select an event...</option>';

            const fallbackEvents = [
                { acronym: 'DEMO', name: 'Demo Conference' },
            ];

            fallbackEvents.forEach(function(event) {
                const option = document.createElement('option');
                option.value = event.acronym;
                option.textContent = event.name + ' (' + event.acronym + ')';
                eventSelect.appendChild(option);
            });
        }
    }
}

// Pricing data loading
async function loadPricingData() {
    try {
        const response = await fetch('get-pricing.php');

        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }

        const apiResponse = await response.json();

        // Extract the actual pricing data from the API response
        let data;
        if (apiResponse.success && apiResponse.data) {
            data = apiResponse.data;
        } else if (apiResponse.poster || apiResponse.fabric) {
            data = apiResponse;
        } else {
            throw new Error('Invalid pricing API response structure');
        }

        // Check the structure of the pricing data
        if (data && typeof data === 'object') {
            pricingData = data;
        } else {
            throw new Error('Invalid pricing data structure');
        }

    } catch (error) {
        showNotification('Failed to load pricing data: ' + error.message, 'error');

        // Create fallback pricing data with correct structure
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
    }
}

// Form handler functions
function updateEventSelection() {
    const eventSelect = document.getElementById('event_select');
    const selectedEvent = eventSelect.value;
    if (selectedEvent) {
        generateNextOrderNumber(selectedEvent);
    } else {
        document.getElementById('order_reference').placeholder = 'Select event to generate';
        document.getElementById('order_reference').value = '';
    }
}

// Generate actual next order number
async function generateNextOrderNumber(eventAcronym) {
    try {
        const response = await fetch('data/order_counter.txt');
        const content = await response.text();
        const counters = JSON.parse(content);

        const currentCount = counters[eventAcronym] || 0;
        const nextNumber = currentCount + 1;
        const nextOrderNumber = eventAcronym + '-' + String(nextNumber).padStart(3, '0');

        document.getElementById('order_reference').value = nextOrderNumber;
        document.getElementById('order_reference').placeholder = 'Next: ' + nextOrderNumber;

    } catch (error) {
        const fallbackNumber = eventAcronym + '-XXX';
        document.getElementById('order_reference').value = fallbackNumber;
        document.getElementById('order_reference').placeholder = 'Will generate: ' + fallbackNumber;
    }
}

function setDimensions(width, height) {
    document.getElementById('width').value = width;
    document.getElementById('height').value = height;
    updatePricing();
}

function selectMaterial(material) {
    const materialSelect = document.getElementById('selected_material');
    if (materialSelect && materialSelect.value !== material) {
        materialSelect.value = material;
    }
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

    const priorityBadge = document.getElementById('priority_tier_badge');
    if (priorityBadge) {
        priorityBadge.textContent = tier;
        priorityBadge.className = 'priority-tier-badge ' + tierClass;
    }

    updatePricing();
}

// Pricing calculation function
function updatePricing() {
    const width = parseFloat(document.getElementById('width').value) || 0;
    const height = parseFloat(document.getElementById('height').value) || 0;
    const material = document.getElementById('selected_material').value || 'poster';
    const dueDate = document.getElementById('due_date').value;

    if (!width || !height || !dueDate) {
        return;
    }

    if (!pricingData) {
        setTimeout(function() {
            if (pricingData) {
                updatePricing();
            } else {
                showNotification('Pricing data not available. Please refresh the page.', 'error');
            }
        }, 500);
        return;
    }

    if (pricingData && pricingData[material]) {
        const area = width * height;
        const priceRow = pricingData[material].find(function(row) { return area >= row.min && area <= row.max; });

        if (priceRow) {
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
        }
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
}

function updateFilePreview(file) {
    const uploadZone = document.getElementById('uploadZone');
    const fileName = file.name;
    const fileSize = (file.size / (1024 * 1024)).toFixed(2);
    const fileExt = fileName.split('.').pop().toUpperCase();

    uploadZone.innerHTML = '<div style="display: flex; align-items: center; gap: 16px; background: #f0f9ff; border: 2px solid #0ea5e9; border-radius: 8px; padding: 20px; width: 100%;">' +
        '<div style="color: #0ea5e9; font-size: 2.5rem; flex-shrink: 0;">' + CREATE_ORDER_CONFIG.icons.checkGreen + '</div>' +
        '<div style="width: 1px; height: 40px; background: #0ea5e9; flex-shrink: 0;"></div>' +
        '<div style="flex: 1;">' +
        '<div style="font-weight: 600; color: #374151; margin-bottom: 4px;">File Uploaded Successfully</div>' +
        '<div style="font-weight: 600; color: #374151; margin-bottom: 2px;">' + escapeHtml(fileName) + '</div>' +
        '<div style="font-size: 0.8rem; color: #6b7280;">Type: ' + fileExt + ' &#8226; Size: ' + fileSize + ' MB</div>' +
        '<div style="font-size: 0.75rem; color: #059669; margin-top: 4px;">File ready for upload</div>' +
        '<button type="button" onclick="removeFile()" style="margin-top: 8px; padding: 4px 8px; background: #dc2626; color: white; border: none; border-radius: 6px; font-size: 0.8rem; cursor: pointer;">Remove File</button>' +
        '</div></div>';
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
    uploadZone.innerHTML = '<div class="upload-icon">' + CREATE_ORDER_CONFIG.icons.download + '</div>' +
        '<div style="width: 1px; height: 40px; background: #d1d5db; flex-shrink: 0;"></div>' +
        '<div style="flex: 1;">' +
        '<div style="font-weight: 600; color: #374151; margin-bottom: 4px;">Click to upload or drag your file here</div>' +
        '<div style="font-size: 0.8rem; color: #6b7280; margin-bottom: 2px;">PDF, AI, EPS, PSD, PNG, JPG, TIFF, WebP, GIF, BMP, SVG, PPTX, </div>' +
        '<div style="font-size: 0.75rem; color: #9ca3af;">Max file size: 100MB</div>' +
        '</div>';
}

function initializeCountrySelector() {
    const countrySelector = document.getElementById('countrySelector');
    const countryDropdown = document.getElementById('countryDropdown');
    const selectedCountryElement = document.getElementById('selectedCountry');

    if (!countrySelector || !countryDropdown) return;

    // Populate dropdown
    countries.forEach(function(country) {
        const div = document.createElement('div');
        div.className = 'country-option';
        div.innerHTML = '<img src="' + country.flag + '" alt="' + country.name + '" class="country-flag">' +
            '<span class="country-name">' + country.name + '</span>' +
            '<span class="country-dial-code">' + country.dialCode + '</span>';
        div.onclick = function() { selectCountryOption(country); };
        countryDropdown.appendChild(div);
    });

    // Toggle dropdown
    selectedCountryElement.onclick = function(e) {
        e.stopPropagation();
        countryDropdown.style.display = countryDropdown.style.display === 'none' ? 'block' : 'none';
    };

    // Close dropdown when clicking outside
    document.addEventListener('click', function() {
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
}

function initializeFormHandlers() {
    const form = document.getElementById('adminOrderForm');
    if (!form) return;

    // Set up pricing calculation triggers
    const widthInput = document.getElementById('width');
    const heightInput = document.getElementById('height');
    const dueDateInput = document.getElementById('due_date');

    if (widthInput) {
        widthInput.addEventListener('input', function() {
            updatePricing();
        });
    }
    if (heightInput) {
        heightInput.addEventListener('input', function() {
            updatePricing();
        });
    }
    if (dueDateInput) {
        dueDateInput.addEventListener('change', function() {
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

        // Check required fields
        const requiredFields = form.querySelectorAll('[required]');
        let allValid = true;

        requiredFields.forEach(function(field) {
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

            addressRequired.forEach(function(fieldId) {
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
    });
}
