// MTCC Poster Order Form - Complete Script
// Version: v33 - Delivery Time Enforcement, Weekend Rules, Countdown Escalation
// Fixed: Delivery time gates, weekend pricing rules, date picker restrictions, timer colors

// ===== GLOBAL CONFIGURATION =====
const config = {
  maxFileSize: 100 * 1024 * 1024, // 100MB
  allowedTypes: ['.pdf', '.ai', '.eps', '.psd', '.png', '.jpg', '.jpeg', '.tiff', '.tif', '.webp', '.gif', '.bmp', '.svg', '.pptx', '.indd'],
  taxRate: 0.13,
  // SIMPLIFIED: Removed timezone objects, using simple hour values
  tiers: [
    { key: 'early', cls: 'early', icon: '&#128077;', label: 'Best Value', days: '10+ Days Turnaround', lead: 10, cutoffHour: 17 }, // 5:00pm
    { key: 'standard', cls: 'standard', icon: '&#128197;', label: 'Standard', days: '5 Days Turnaround', lead: 5, cutoffHour: 17 }, // 5:00pm
    { key: '3days', cls: 'rush', icon: '&#127939;', label: 'Rush', days: '3 Days Turnaround', lead: 3, cutoffHour: 17 }, // 5:00pm
    { key: '2days', cls: 'urgent', icon: '&#128293;', label: 'Express', days: '2 Days Turnaround', lead: 2, cutoffHour: 17 }, // 5:00pm
    { key: 'nextday', cls: 'critical', icon: '&#128680;', label: 'Priority', days: 'Next Day Turnaround', lead: 1, cutoffHour: 15 }, // 3:00pm
    { key: 'sameday', cls: 'lastminute', icon: '&#128128;', label: "We'll Get It Done", days: 'Same-Day Turnaround', lead: 0, cutoffHour: 15 } // 3:00pm
  ],
  // Updated fallback data to match CSV column names exactly
  fallbackPricingData: {
    poster: [
      { min: 0, max: 500, early: 25, standard: 35, '3days': 50, '2days': 75, nextday: 100, sameday: 150 },
      { min: 501, max: 1000, early: 35, standard: 45, '3days': 65, '2days': 95, nextday: 125, sameday: 200 },
      { min: 1001, max: 2000, early: 45, standard: 60, '3days': 85, '2days': 120, nextday: 160, sameday: 250 }
    ],
    fabric: [
      { min: 0, max: 500, early: 35, standard: 50, '3days': 70, '2days': 105, nextday: 140, sameday: 210 },
      { min: 501, max: 1000, early: 50, standard: 65, '3days': 95, '2days': 135, nextday: 175, sameday: 280 },
      { min: 1001, max: 2000, early: 65, standard: 85, '3days': 120, '2days': 170, nextday: 225, sameday: 350 }
    ]
  },
  // Delivery time options with gate rules
  deliveryTimeOptions: [
    { value: 'anytime', label: 'Anytime',      hour: 18, gateContext: 'same_day',     gateHour: 15, aliasOf: '6pm' },
    { value: '9am',     label: 'By 9:00am',    hour: 9,  gateContext: 'previous_day', gateHour: 15 },
    { value: '12pm',    label: 'By 12:00pm',   hour: 12, gateContext: 'same_day',     gateHour: 9 },
    { value: '3pm',     label: 'By 3:00pm',    hour: 15, gateContext: 'same_day',     gateHour: 12 },
    { value: '6pm',     label: 'By 6:00pm',    hour: 18, gateContext: 'same_day',     gateHour: 15 }
  ],
  // Countdown timer color thresholds (minutes)
  countdownWarningMin: 30,
  countdownCriticalMin: 10
};

// ===== GLOBAL STATE =====
const state = {
  selectedEvent: null,
  selectedDate: null,
  dimensions: { width: null, height: null },
  selectedMaterial: 'poster',
  uploadedFile: null,
  deliveryOption: null,
  pricingData: null,
  pricingLoaded: false,
  countdownInterval: null,
  availableEvents: [],
  selectedDeliveryTime: 'anytime',
  previousBestTierKey: null,
  tierExpiredByCountdown: false
};

// ===== ELEMENT REFERENCES =====
const elements = {
  eventSelect: null,
  width: null,
  height: null,
  date: null,
  dateDisplay: null,
  uploadZone: null,
  fileInput: null,
  customerPhone: null,
  submitButton: null,
  pricing: null,
  materialToggle: null,
  posterOption: null,
  fabricOption: null,
  materialSlider: null,
  deliveryTime: null
};

// ===== COUNTRY DATA =====
const countries = [
  { code: 'CA', name: 'Canada', dialCode: '+1', flag: 'https://flagcdn.com/20x15/ca.png' },
  { code: 'US', name: 'United States', dialCode: '+1', flag: 'https://flagcdn.com/20x15/us.png' },
  { code: 'GB', name: 'United Kingdom', dialCode: '+44', flag: 'https://flagcdn.com/20x15/gb.png' },
  { code: 'AU', name: 'Australia', dialCode: '+61', flag: 'https://flagcdn.com/20x15/au.png' },
  { code: 'DE', name: 'Germany', dialCode: '+49', flag: 'https://flagcdn.com/20x15/de.png' },
  { code: 'FR', name: 'France', dialCode: '+33', flag: 'https://flagcdn.com/20x15/fr.png' },
  { code: 'JP', name: 'Japan', dialCode: '+81', flag: 'https://flagcdn.com/20x15/jp.png' },
  { code: 'KR', name: 'South Korea', dialCode: '+82', flag: 'https://flagcdn.com/20x15/kr.png' },
  { code: 'CN', name: 'China', dialCode: '+86', flag: 'https://flagcdn.com/20x15/cn.png' },
  { code: 'IN', name: 'India', dialCode: '+91', flag: 'https://flagcdn.com/20x15/in.png' },
  { code: 'IT', name: 'Italy', dialCode: '+39', flag: 'https://flagcdn.com/20x15/it.png' },
  { code: 'ES', name: 'Spain', dialCode: '+34', flag: 'https://flagcdn.com/20x15/es.png' },
  { code: 'NL', name: 'Netherlands', dialCode: '+31', flag: 'https://flagcdn.com/20x15/nl.png' },
  { code: 'BE', name: 'Belgium', dialCode: '+32', flag: 'https://flagcdn.com/20x15/be.png' },
  { code: 'CH', name: 'Switzerland', dialCode: '+41', flag: 'https://flagcdn.com/20x15/ch.png' },
  { code: 'AT', name: 'Austria', dialCode: '+43', flag: 'https://flagcdn.com/20x15/at.png' },
  { code: 'SE', name: 'Sweden', dialCode: '+46', flag: 'https://flagcdn.com/20x15/se.png' },
  { code: 'NO', name: 'Norway', dialCode: '+47', flag: 'https://flagcdn.com/20x15/no.png' },
  { code: 'DK', name: 'Denmark', dialCode: '+45', flag: 'https://flagcdn.com/20x15/dk.png' },
  { code: 'FI', name: 'Finland', dialCode: '+358', flag: 'https://flagcdn.com/20x15/fi.png' },
  { code: 'PL', name: 'Poland', dialCode: '+48', flag: 'https://flagcdn.com/20x15/pl.png' },
  { code: 'CZ', name: 'Czech Republic', dialCode: '+420', flag: 'https://flagcdn.com/20x15/cz.png' },
  { code: 'HU', name: 'Hungary', dialCode: '+36', flag: 'https://flagcdn.com/20x15/hu.png' },
  { code: 'PT', name: 'Portugal', dialCode: '+351', flag: 'https://flagcdn.com/20x15/pt.png' },
  { code: 'IE', name: 'Ireland', dialCode: '+353', flag: 'https://flagcdn.com/20x15/ie.png' },
  { code: 'GR', name: 'Greece', dialCode: '+30', flag: 'https://flagcdn.com/20x15/gr.png' },
  { code: 'TR', name: 'Turkey', dialCode: '+90', flag: 'https://flagcdn.com/20x15/tr.png' },
  { code: 'RU', name: 'Russia', dialCode: '+7', flag: 'https://flagcdn.com/20x15/ru.png' },
  { code: 'UA', name: 'Ukraine', dialCode: '+380', flag: 'https://flagcdn.com/20x15/ua.png' },
  { code: 'SG', name: 'Singapore', dialCode: '+65', flag: 'https://flagcdn.com/20x15/sg.png' },
  { code: 'MY', name: 'Malaysia', dialCode: '+60', flag: 'https://flagcdn.com/20x15/my.png' },
  { code: 'TH', name: 'Thailand', dialCode: '+66', flag: 'https://flagcdn.com/20x15/th.png' },
  { code: 'PH', name: 'Philippines', dialCode: '+63', flag: 'https://flagcdn.com/20x15/ph.png' },
  { code: 'ID', name: 'Indonesia', dialCode: '+62', flag: 'https://flagcdn.com/20x15/id.png' },
  { code: 'VN', name: 'Vietnam', dialCode: '+84', flag: 'https://flagcdn.com/20x15/vn.png' },
  { code: 'NZ', name: 'New Zealand', dialCode: '+64', flag: 'https://flagcdn.com/20x15/nz.png' },
  { code: 'ZA', name: 'South Africa', dialCode: '+27', flag: 'https://flagcdn.com/20x15/za.png' },
  { code: 'BR', name: 'Brazil', dialCode: '+55', flag: 'https://flagcdn.com/20x15/br.png' },
  { code: 'MX', name: 'Mexico', dialCode: '+52', flag: 'https://flagcdn.com/20x15/mx.png' },
  { code: 'AR', name: 'Argentina', dialCode: '+54', flag: 'https://flagcdn.com/20x15/ar.png' },
  { code: 'IL', name: 'Israel', dialCode: '+972', flag: 'https://flagcdn.com/20x15/il.png' },
  { code: 'OTHER', name: 'Other +', dialCode: '+', flag: 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMTUiIHZpZXdCb3g9IjAgMCAyMCAxNSIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjIwIiBoZWlnaHQ9IjE1IiBmaWxsPSIjZjNmNGY2IiBzdHJva2U9IiNkMWQ1ZGIiLz4KPHN2ZyB4PSI2IiB5PSI0IiB3aWR0aD0iOCIgaGVpZ2h0PSI3IiB2aWV3Qm94PSIwIDAgOCA3IiBmaWxsPSJub25lIj4KPHN2ZyB3aWR0aD0iOCIgaGVpZ2h0PSI3IiB2aWV3Qm94PSIwIDAgOCA3IiBmaWxsPSJub25lIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPgo8cGF0aCBkPSJNNCAwLjc1TDcuNDY0IDZIMS0uNTM2IDZMNCA5LjIxWiIgZmlsbD0iIzZiNzI4MCIvPgo8L3N2Zz4KPC9zdmc+Cjwvc3ZnPgo=' }
];

let selectedCountry = countries[0]; // Default to Canada

// ===== PHONE VALIDATION FUNCTIONS =====
function validateSimplePhone(phoneNumber) {
  if (!phoneNumber || phoneNumber.trim() === '') return false;
  const cleaned = phoneNumber.replace(/[^\d+]/g, '');
  if (cleaned.length < 7) return false;
  return /^[\+]?[1-9][\d\-\s\(\)\.]{6,20}$/.test(phoneNumber);
}

function getCountryValidationRules(countryCode) {
  const validationRules = {
    'CA': { min: 10, max: 10, format: '(XXX) XXX-XXXX' },
    'US': { min: 10, max: 10, format: '(XXX) XXX-XXXX' },
    'GB': { min: 10, max: 11, format: 'XX XXXX XXXX' },
    'AU': { min: 9, max: 10, format: 'X XXXX XXXX' },
    'DE': { min: 10, max: 12, format: 'XX XXXXXXXX' },
    'FR': { min: 10, max: 10, format: 'X XX XX XX XX' },
    'OTHER': { min: 7, max: 15, format: 'International format' }
  };
  return validationRules[countryCode] || validationRules['OTHER'];
}

function updatePhoneValidationUI(status, message) {
  const phoneInput = document.getElementById('customerPhone');
  if (!phoneInput) return;
  
  const phoneContainer = phoneInput.closest('.phone-input-container');
  if (!phoneContainer) return;
  
  phoneInput.classList.remove('phone-error', 'phone-valid');
  phoneContainer.classList.remove('phone-error', 'phone-valid');
  
  const existingMessage = phoneContainer.parentNode.querySelector('.phone-validation-message');
  if (existingMessage) existingMessage.remove();
  
  if (status === 'valid') {
    phoneInput.classList.add('phone-valid');
    phoneContainer.classList.add('phone-valid');
    
    const validMessage = document.createElement('div');
    validMessage.className = 'phone-validation-message success show';
    validMessage.innerHTML = '<span style="margin-right: 4px;">&#10004;</span>' + message;
    phoneContainer.parentNode.appendChild(validMessage);
    
  } else if (status === 'error') {
    phoneInput.classList.add('phone-error');
    phoneContainer.classList.add('phone-error');
    
    const errorMessage = document.createElement('div');
    errorMessage.className = 'phone-validation-message error show';
    errorMessage.innerHTML = '<span style="margin-right: 4px;">!</span>' + message;
    phoneContainer.parentNode.appendChild(errorMessage);
  }
}

function validatePhoneNumber(showUI = false) {
  const phoneNumber = elements.customerPhone ? elements.customerPhone.value.trim() : '';
  
  if (!phoneNumber) {
    if (showUI) updatePhoneValidationUI('error', 'Phone number is required');
    return false;
  }
  
  const rules = getCountryValidationRules(selectedCountry.code);
  const digitsOnly = phoneNumber.replace(/[^\d]/g, '');
  
  if (selectedCountry.code === 'OTHER') {
    const isValid = validateSimplePhone(phoneNumber);
    if (showUI) {
      if (isValid) {
        updatePhoneValidationUI('valid', 'Phone number looks good');
      } else {
        updatePhoneValidationUI('error', 'Please enter a valid international phone number');
      }
    }
    return isValid;
  }
  
  if (digitsOnly.length < rules.min || digitsOnly.length > rules.max) {
    if (showUI) {
      updatePhoneValidationUI('error', `Phone number should be ${rules.min}-${rules.max} digits for ${selectedCountry.name}`);
    }
    return false;
  }
  
  if (showUI) {
    updatePhoneValidationUI('valid', `Valid ${selectedCountry.name} phone number`);
  }
  return true;
}

function formatPhoneNumber(phoneNumber, countryCode) {
  const digitsOnly = phoneNumber.replace(/[^\d]/g, '');
  
  if (countryCode === 'CA' || countryCode === 'US') {
    if (digitsOnly.length === 10) {
      return `(${digitsOnly.slice(0,3)}) ${digitsOnly.slice(3,6)}-${digitsOnly.slice(6)}`;
    }
  }
  
  return phoneNumber;
}

// ===== COUNTRY SELECTOR =====
function initializeCountrySelector() {
  const countrySelector = document.getElementById('countrySelector');
  if (!countrySelector) return;
  
  const countryList = countrySelector.querySelector('.country-list');
  if (!countryList) return;
  
  // Populate country list
  countryList.innerHTML = '';
  countries.forEach(function(country) {
    const countryItem = document.createElement('div');
    countryItem.className = 'country-option';
    countryItem.setAttribute('data-country-code', country.code);
    countryItem.innerHTML = `
      <img src="${country.flag}" alt="${country.name}" class="country-flag">
      <span class="country-name">${country.name}</span>
      <span class="country-dial-code">${country.dialCode}</span>
    `;
    countryItem.addEventListener('click', function() {
      selectCountry(country);
    });
    countryList.appendChild(countryItem);
  });
  
  // Toggle functionality
  const selectedCountryElement = countrySelector.querySelector('.selected-country');
  if (selectedCountryElement) {
    selectedCountryElement.addEventListener('click', function(e) {
      e.preventDefault();
      countrySelector.classList.toggle('open');
    });
  }
  
  // Close when clicking outside
  document.addEventListener('click', function(e) {
    if (!countrySelector.contains(e.target)) {
      countrySelector.classList.remove('open');
    }
  });
  
  // Initialize with default country
  updateSelectedCountry(selectedCountry);
}

function selectCountry(country) {
  selectedCountry = country;
  updateSelectedCountry(country);
  
  const countrySelector = document.getElementById('countrySelector');
  if (countrySelector) {
    countrySelector.classList.remove('open');
  }
  
  if (elements.customerPhone) {
    elements.customerPhone.focus();
  }
}

function updateSelectedCountry(country) {
  if (!country || typeof country !== 'object') return;
  
  const countryFlag = document.getElementById('countryFlag');
  const countryCode = document.getElementById('countryCode');
  
  if (countryFlag && countryCode) {
    countryFlag.src = country.flag || '';
    countryFlag.alt = country.name || '';
    countryCode.textContent = country.dialCode || '';
    updatePhonePlaceholder(country);
  }
}

function updatePhonePlaceholder(country) {
  const placeholders = {
    'CA': '(416) 555-0123',
    'US': '(555) 123-4567',
    'GB': '20 7946 0958',
    'AU': '2 1234 5678',
    'DE': '30 12345678',
    'FR': '1 23 45 67 89',
    'OTHER': 'Your phone number',
    'default': '000-000-0000'
  };
  
  if (elements.customerPhone) {
    elements.customerPhone.placeholder = placeholders[country.code] || placeholders.default;
  }
}

// ===== EVENT LOADING =====
async function loadEventsFromServer() {
  
  try {
    const response = await fetch('admin/get-events.php?active_only=true', {
      method: 'GET',
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache'
      }
    });
    
    if (!response.ok) {
      throw new Error('HTTP ' + response.status + ': ' + response.statusText);
    }
    
    const data = await response.json();
    
    if (data.success && Array.isArray(data.data) && data.data.length > 0) {
      state.availableEvents = data.data;
      populateEventSelect();
      return true;
    } else {
      throw new Error('No events returned or invalid data structure');
    }
    
  } catch (error) {
    console.error('Event loading failed:', error);
    console.warn('Using fallback event data');
    
    // Fallback to hardcoded events (use dynamic dates so form stays usable)
    const fallbackStart = new Date();
    const fallbackEnd = new Date();
    fallbackEnd.setDate(fallbackEnd.getDate() + 30);
    const fmtDate = (d) => d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    state.availableEvents = [
      { id: 1, acronym: 'GENERAL', name: 'General Order', startDate: fmtDate(fallbackStart), endDate: fmtDate(fallbackEnd) }
    ];
    populateEventSelect();
    return false;
  }
}

function populateEventSelect() {
  if (!elements.eventSelect) return;
  
  elements.eventSelect.innerHTML = '<option value="placeholder">Select your event...</option>';
  
  state.availableEvents.forEach(function(event) {
    const option = document.createElement('option');
    option.value = JSON.stringify(event);
    option.textContent = event.name + ' (' + event.acronym + ')';
    elements.eventSelect.appendChild(option);
  });
  
}

// ===== PRICING DATA LOADING =====
async function loadPricingFromServer() {
  
  try {
    const response = await fetch('get-pricing.php?material=both', {
      method: 'GET',
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache'
      }
    });
    
    if (!response.ok) {
      throw new Error('HTTP ' + response.status + '&#10006;' + response.statusText);
    }
    
    const data = await response.json();
    
    if (data.success && data.data) {
      state.pricingData = data.data;
      state.pricingLoaded = true;
      return true;
    } else {
      throw new Error('Invalid pricing data structure: ' + (data.error || 'Unknown error'));
    }
    
  } catch (error) {
    console.error('Pricing loading failed:', error);
    console.warn('Falling back to hard-coded pricing data');
    
    // Fallback to hard-coded data
    state.pricingData = config.fallbackPricingData;
    state.pricingLoaded = true;
    
    // Show user notification about fallback
    showPricingFallbackNotification();
    
    return false;
  }
}

function showPricingFallbackNotification() {
  // Create a temporary notification to inform user about fallback pricing
  const notification = document.createElement('div');
  notification.style.cssText = `
    position: fixed;
    top: 20px;
    right: 20px;
    background: #fbbf24;
    color: #92400e;
    padding: 12px 16px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    z-index: 10000;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border-left: 4px solid #f59e0b;
  `;
  notification.innerHTML = '&#9888;️ Using backup pricing - current rates may vary';
  document.body.appendChild(notification);
  
  // Remove after 5 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.parentNode.removeChild(notification);
    }
  }, 5000);
}

function getCurrentPricingData(material) {
  if (!state.pricingLoaded || !state.pricingData) {
    console.warn('Pricing data not loaded, using fallback');
    return config.fallbackPricingData[material] || [];
  }
  
  return state.pricingData[material] || [];
}

// ===== EVENT HANDLING =====
function handleEventSelection() {
  const selectedValue = elements.eventSelect.value;
  
  if (selectedValue && selectedValue !== 'placeholder') {
    try {
      state.selectedEvent = JSON.parse(selectedValue);
      
      // Update hidden fields
      const hiddenEventAcronym = document.getElementById('hiddenEventAcronym');
      const hiddenEventName = document.getElementById('hiddenEventName');
      
      if (hiddenEventAcronym) {
        hiddenEventAcronym.value = state.selectedEvent.acronym || '';
      }
      if (hiddenEventName) {
        hiddenEventName.value = state.selectedEvent.name || '';
      }
      
      // Set date picker max to event end date
      updateDatePickerRestrictions();

      // Update MTCC delivery details based on event building
      updateMTCCDeliveryDetails(state.selectedEvent.building);

    } catch (e) {
      console.error('Error parsing event data:', e);
      state.selectedEvent = null;
      clearDatePickerRestrictions();
    }
  } else {
    state.selectedEvent = null;
    
    // Clear hidden fields
    const hiddenEventAcronym = document.getElementById('hiddenEventAcronym');
    const hiddenEventName = document.getElementById('hiddenEventName');
    
    if (hiddenEventAcronym) {
      hiddenEventAcronym.value = '';
    }
    if (hiddenEventName) {
      hiddenEventName.value = '';
    }
    
    // Reset date picker
    clearDatePickerRestrictions();
    
    // Clear selected date since no event is selected
    if (elements.date) {
      elements.date.value = '';
      state.selectedDate = null;
      updateDateDisplay();
    }
  }
  
  update();
  updateSubmitButtonState();
}

// Update MTCC delivery details based on event building (north/south)
function updateMTCCDeliveryDetails(building) {
  var locations = window.MTCC_LOCATIONS;
  if (!locations || !building) return;

  var loc = locations[building];
  if (!loc) return;

  // Parse address components: "255 Front Street West, Toronto, ON M5V 2W6"
  var addressParts = loc.address.split(',');
  var street = (addressParts[0] || '').trim();
  var postalMatch = loc.address.match(/[A-Z]\d[A-Z]\s?\d[A-Z]\d/);
  var postal = postalMatch ? postalMatch[0] : '';

  // Parse pickup instructions: "Business Centre, 300 Level, outside Hall C"
  var pickupParts = loc.pickup_instructions.split(',');
  var levelMatch = loc.pickup_instructions.match(/(\d+)\s*Level/i);
  var level = levelMatch ? 'Level ' + levelMatch[1] : '';

  var buildingName = building === 'south' ? 'South Building' : 'North Building';

  // Update the DOM elements
  var nameEl = document.getElementById('mtccBuildingName');
  var levelEl = document.getElementById('mtccBuildingLevel');
  var streetEl = document.getElementById('mtccStreetAddress');
  var postalEl = document.getElementById('mtccPostalCode');

  if (nameEl) nameEl.textContent = buildingName;
  if (levelEl) levelEl.textContent = level;
  if (streetEl) streetEl.textContent = street;
  if (postalEl) postalEl.textContent = postal;
}

// ===== FLATPICKR DATE PICKER =====
// Custom calendar with purple available dates and grey unavailable dates.
// Replaces native <input type="date"> for visual control on all devices.
var flatpickrInstance = null;

function initFlatpickr() {
  if (!elements.date || typeof flatpickr === 'undefined') return;
  if (flatpickrInstance) return; // already initialized

  flatpickrInstance = flatpickr(elements.date, {
    dateFormat: 'Y-m-d',
    disableMobile: true, // force custom calendar on mobile too
    appendTo: document.querySelector('.date-input-container'),
    minDate: 'today',
    static: true,
    onChange: function (selectedDates, dateStr) {
      state.selectedDate = dateStr || null;
      updateDeliveryTimeOptions();
      updateDateDisplay();
      updatePricingVisibility();
      updateOrderSummary();
      updateSubmitButtonState();
      generateAlternativeSizes();
    },
    onDayCreate: function (dObj, dStr, fp, dayElem) {
      // Style available vs unavailable days
      var dateStr = dayElem.dateObj.getFullYear() + '-' +
        String(dayElem.dateObj.getMonth() + 1).padStart(2, '0') + '-' +
        String(dayElem.dateObj.getDate()).padStart(2, '0');

      if (!dayElem.classList.contains('flatpickr-disabled') &&
          !dayElem.classList.contains('prevMonthDay') &&
          !dayElem.classList.contains('nextMonthDay')) {
        dayElem.classList.add('fp-available');
      }
    }
  });
}

function updateDatePickerRestrictions() {
  if (!elements.date || !state.selectedEvent) return;

  var today = new Date();
  var minDate = today.getFullYear() + '-' +
    String(today.getMonth() + 1).padStart(2, '0') + '-' +
    String(today.getDate()).padStart(2, '0');
  var maxDate = state.selectedEvent.endDate || '';

  // Initialize Flatpickr on first call
  initFlatpickr();

  if (flatpickrInstance) {
    flatpickrInstance.set('minDate', minDate);
    if (maxDate) flatpickrInstance.set('maxDate', maxDate);
    flatpickrInstance.redraw();
  } else {
    // Fallback: native input
    elements.date.min = minDate;
    elements.date.max = maxDate;
  }

  // If current selection is out of range, clear it
  if (state.selectedDate) {
    if (state.selectedDate < minDate || (maxDate && state.selectedDate > maxDate)) {
      if (flatpickrInstance) flatpickrInstance.clear();
      else elements.date.value = '';
      state.selectedDate = null;
      updateDateDisplay();
    }
  }
}

function clearDatePickerRestrictions() {
  if (flatpickrInstance) {
    flatpickrInstance.set('minDate', null);
    flatpickrInstance.set('maxDate', null);
  } else if (elements.date) {
    elements.date.min = '';
    elements.date.max = '';
  }
}

function handleDateChange() {
  
  state.selectedDate = elements.date?.value || null;
  
  // Check if selected date is available (all delivery times might be disabled)
  if (state.selectedDate && !isDateAvailable(state.selectedDate)) {
  }
  
  // Update delivery time options FIRST (affects pricing tier calculation)
  updateDeliveryTimeOptions();
  
  updateDateDisplay();
  update();
  updateSubmitButtonState();
  
  // Force pricing update since date is a key dependency
  updatePricingVisibility();
}

function updateDateDisplay() {
  
  if (elements.dateDisplay) {
    if (state.selectedDate) {
      try {
        const dateObj = parseSelectedDate(state.selectedDate);
        const formattedDate = dateObj.toLocaleDateString('en-US', { 
          weekday: 'long', 
          month: 'long', 
          day: 'numeric' 
        });
        
        // Update the text content of the placeholder span
        const placeholderSpan = elements.dateDisplay.querySelector('.date-placeholder');
        if (placeholderSpan) {
          placeholderSpan.textContent = formattedDate;
          placeholderSpan.style.color = 'var(--primary)';
        } else {
          elements.dateDisplay.innerHTML = `
            <span class="date-placeholder" style="color: var(--primary);">${formattedDate}</span>
            <span class="date-icon">ðŸ“…Â¦</span>
          `;
        }
        
        elements.dateDisplay.classList.add('has-date');
      } catch (error) {
        console.error('Error formatting date:', error);
        const placeholderSpan = elements.dateDisplay.querySelector('.date-placeholder');
        if (placeholderSpan) {
          placeholderSpan.textContent = 'Invalid date';
        }
      }
    } else {
      // Reset to placeholder
      const placeholderSpan = elements.dateDisplay.querySelector('.date-placeholder');
      if (placeholderSpan) {
        placeholderSpan.textContent = 'Select in-hand date';
        placeholderSpan.style.color = 'var(--subtext)';
      } else {
        elements.dateDisplay.innerHTML = `
          <span class="date-placeholder">Select in-hand date</span>
          <span class="date-icon">ðŸ“…Â¦</span>
        `;
      }
      elements.dateDisplay.classList.remove('has-date');
    }
  } else {
    console.error('dateDisplay element not found');
  }
}

function handleDimensionChange() {
  const w = elements.width ? elements.width.value : '';
  const h = elements.height ? elements.height.value : '';
  state.dimensions.width = w ? parseFloat(w) : null;
  state.dimensions.height = h ? parseFloat(h) : null;
  
  
  // Clear popular size selections when manually typing
  document.querySelectorAll('.popular-size-button-card').forEach(function(button) {
    button.classList.remove('selected');
  });
  
  // Remove size highlight when manually typing custom dimensions
  if (elements.width) elements.width.classList.remove('size-selected');
  if (elements.height) elements.height.classList.remove('size-selected');
  
  update();
  generateAlternativeSizes();
  updateSubmitButtonState();
}

// ===== FORM VALIDATION =====
function updateSubmitButtonState() {
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  const customerNameEl = document.getElementById('customerName');
  const customerEmailEl = document.getElementById('customerEmail');
  
  const customerName = customerNameEl ? customerNameEl.value.trim() : '';
  const customerEmail = customerEmailEl ? customerEmailEl.value.trim() : '';
  const phoneValid = validatePhoneNumber();
  
  const hasValidEvent = state.selectedEvent;
  const hasValidDimensions = width && height && width >= 12 && height >= 12;
  const hasValidDate = state.selectedDate;
  const hasValidCustomerInfo = customerName && customerEmail && phoneValid;
  const hasDeliveryOption = state.deliveryOption;
  const hasFile = state.uploadedFile;
  
  let hasValidAddress = true;
  if (state.deliveryOption === 'office') {
    const deliveryAttnEl = document.getElementById('deliveryAttn');
    const deliveryAddressEl = document.getElementById('deliveryAddress');
    const deliveryCityEl = document.getElementById('deliveryCity');
    const deliveryProvinceEl = document.getElementById('deliveryProvince');
    const deliveryPostalEl = document.getElementById('deliveryPostal');
    
    const deliveryAttn = deliveryAttnEl ? deliveryAttnEl.value.trim() : '';
    const deliveryAddress = deliveryAddressEl ? deliveryAddressEl.value.trim() : '';
    const deliveryCity = deliveryCityEl ? deliveryCityEl.value.trim() : '';
    const deliveryProvince = deliveryProvinceEl ? deliveryProvinceEl.value.trim() : '';
    const deliveryPostal = deliveryPostalEl ? deliveryPostalEl.value.trim() : '';
    
    hasValidAddress = deliveryAttn && deliveryAddress && deliveryCity && deliveryProvince && deliveryPostal;
  }
  // For MTCC delivery, address is always valid (not required)
  
  const isFormComplete = hasValidEvent && hasValidDimensions && hasValidDate && hasValidCustomerInfo && hasDeliveryOption && hasValidAddress && hasFile;
  
  updateStatusBadge('step1Status', hasValidEvent && hasValidDate);
  updateStatusBadge('step2Status', hasValidDimensions);
  updateStatusBadge('step3Status', hasFile);
  updateStatusBadge('step4Status', hasDeliveryOption && hasValidAddress);
  updateStatusBadge('step5Status', hasValidCustomerInfo);

  // Sync progress bar
  updateProgressBar([
    hasValidEvent && hasValidDate,
    hasValidDimensions,
    hasFile,
    hasDeliveryOption && hasValidAddress,
    hasValidCustomerInfo
  ]);
  
  const submitButton = elements.submitButton;
  const formStatus = document.getElementById('formStatus');
  
  if (isFormComplete) {
    // Also check if a valid delivery time is selected
    if (!state.selectedDeliveryTime || state.selectedDeliveryTime === 'none') {
      submitButton.disabled = true;
      submitButton.innerHTML = '<div class="submit-button-content"><span class="submit-button-title">Proceed to Payment</span><span class="submit-button-subtitle">No delivery times available for selected date</span></div>';
      return;
    }
    submitButton.disabled = false;
    submitButton.innerHTML = '<div class="submit-button-content"><span class="submit-button-title">Proceed to Payment</span><span class="submit-button-subtitle">Ready to checkout!</span></div>';
    if (formStatus) {
      formStatus.innerHTML = '<span style="color: var(--green);">&#10004;</span><span style="color: var(--green); font-weight: 600;">Form is complete - proceed to secure payment</span>';
    }
  } else {
    submitButton.disabled = true;
    submitButton.innerHTML = '<div class="submit-button-content"><span class="submit-button-title">Proceed to Payment</span><span class="submit-button-subtitle">Complete required fields above</span></div>';
    if (formStatus) {
      formStatus.innerHTML = '<span>!</span><span>Complete the form to proceed to payment</span>';
    }
  }
}
// Lucide circle-check-big SVG — used for the completed-state form status badges
// Color is hardcoded green (var(--green) = #059669) rather than `currentColor`
// because some mobile browsers (Chrome) don't reliably inherit currentColor on
// SVGs injected via innerHTML.
var LUCIDE_CIRCLE_CHECK_BIG =
  '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" ' +
  'fill="none" stroke="#059669" stroke-width="2.5" stroke-linecap="round" ' +
  'stroke-linejoin="round" class="lucide lucide-circle-check-big">' +
  '<path d="M21.801 10A10 10 0 1 1 17 3.335"/>' +
  '<path d="m9 11 3 3L22 4"/></svg>';

function updateStatusBadge(badgeId, isComplete) {
  const badge = document.getElementById(badgeId);
  if (!badge) return;

  const indicator = badge.querySelector('.status-indicator');
  if (!indicator) return;

  if (isComplete) {
    badge.classList.add('completed');
    badge.classList.remove('incomplete');
    indicator.innerHTML = LUCIDE_CIRCLE_CHECK_BIG;
  } else {
    badge.classList.add('incomplete');
    badge.classList.remove('completed');
    indicator.innerHTML = '<span class="status-icon-modern">&#10006;</span>';
  }
}

// ===== PROGRESS BAR =====

// Track previous completion state for auto-scroll
var _prevStepStates = [false, false, false, false, false];

function updateProgressBar(stepStates) {
  // Update dots and connectors
  for (var i = 0; i < stepStates.length; i++) {
    var step = document.getElementById('progStep' + (i + 1));
    if (!step) continue;

    if (stepStates[i]) {
      step.classList.add('completed');
      step.classList.remove('active');
    } else {
      step.classList.remove('completed');
    }

    // Update connector before this step (connector between step i-1 and i)
    if (i > 0) {
      var connectors = document.querySelectorAll('.progress-connector');
      if (connectors[i - 1]) {
        if (stepStates[i - 1]) {
          connectors[i - 1].classList.add('completed');
        } else {
          connectors[i - 1].classList.remove('completed');
        }
      }
    }
  }

  // Find first incomplete step and mark as active
  for (var j = 0; j < stepStates.length; j++) {
    var activeStep = document.getElementById('progStep' + (j + 1));
    if (!stepStates[j] && activeStep) {
      activeStep.classList.add('active');
      break;
    }
  }

  // Auto-scroll: if a step just became complete, scroll to next incomplete section
  var sectionTargets = ['eventSelect', 'w', 'uploadZone', 'mtccOption', 'customerName'];
  for (var k = 0; k < stepStates.length; k++) {
    if (stepStates[k] && !_prevStepStates[k]) {
      // Step k just completed — find next incomplete step
      for (var n = k + 1; n < stepStates.length; n++) {
        if (!stepStates[n]) {
          var targetEl = document.getElementById(sectionTargets[n]);
          if (targetEl) {
            setTimeout(function(el) {
              el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }.bind(null, targetEl), 300);
          }
          break;
        }
      }
      break;
    }
  }

  _prevStepStates = stepStates.slice();
}

// Progress dot click — scroll to section
document.addEventListener('click', function(e) {
  var step = e.target.closest('.progress-step');
  if (!step) return;
  var stepNum = parseInt(step.dataset.step);
  var targets = ['eventSelect', 'w', 'uploadZone', 'mtccOption', 'customerName'];
  if (targets[stepNum - 1]) {
    var el = document.getElementById(targets[stepNum - 1]);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
});

// ===== POPULAR SIZES =====
function selectPopularSize(width, height) {
  
  document.querySelectorAll('.popular-size-button-card').forEach(function(button) {
    button.classList.remove('selected');
    const buttonWidth = parseInt(button.dataset.width);
    const buttonHeight = parseInt(button.dataset.height);
    if (buttonWidth === width && buttonHeight === height) {
      button.classList.add('selected');
    }
  });
  
  document.querySelectorAll('.alternative-size-button').forEach(function(button) {
    button.classList.remove('selected');
  });
  
  state.dimensions.width = width;
  state.dimensions.height = height;
  if (elements.width) {
    elements.width.value = width;
    elements.width.classList.add('size-selected');
  }
  if (elements.height) {
    elements.height.value = height;
    elements.height.classList.add('size-selected');
  }
  
  update();
  generateAlternativeSizes();
  updateSubmitButtonState();
}

// ===== UPDATED SIMILAR SIZES WITH PROPER CONSTRAINTS =====
function generateAlternativeSizes() {
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  const popularSizesContainer = document.getElementById('popularSizes');
  
  if (!width || !height || !popularSizesContainer) {
    if (popularSizesContainer) {
      popularSizesContainer.innerHTML = '<div class="similar-placeholder">Select a size in step 2A to see proportionally scaled poster sizes</div>';
    }
    return;
  }
  
  
  // Determine longer and shorter dimensions for proportional scaling
  const longerDim = Math.max(width, height);
  const shorterDim = Math.min(width, height);
  const isLandscape = width > height;
  const aspectRatio = longerDim / shorterDim;
  
  const alternatives = [];
  
  // Generate larger sizes (4" increments on longer dimension)
  const largerSizes = [];
  for (let increment = 4; longerDim + increment <= 48; increment += 4) {
    const newLongerDim = longerDim + increment;
    const newShorterDim = Math.ceil(newLongerDim / aspectRatio); // Round up to whole number
    
    // Ensure both dimensions are within constraints
    if (newShorterDim <= 48 && newShorterDim >= 12) {
      if (isLandscape) {
        largerSizes.push({ width: newLongerDim, height: newShorterDim, direction: 'up' });
      } else {
        largerSizes.push({ width: newShorterDim, height: newLongerDim, direction: 'up' });
      }
    }
  }
  
  // Generate smaller sizes (2" decrements on longer dimension)
  const smallerSizes = [];
  for (let decrement = 2; longerDim - decrement >= 12; decrement += 2) {
    const newLongerDim = longerDim - decrement;
    const newShorterDim = Math.ceil(newLongerDim / aspectRatio); // Round up to whole number
    
    // Ensure both dimensions are within constraints
    if (newShorterDim >= 12 && newShorterDim <= 48) {
      if (isLandscape) {
        smallerSizes.push({ width: newLongerDim, height: newShorterDim, direction: 'down' });
      } else {
        smallerSizes.push({ width: newShorterDim, height: newLongerDim, direction: 'down' });
      }
    }
  }
  
  // Combine larger sizes first, then smaller sizes, limit to 5 total
  const allSizes = [...largerSizes, ...smallerSizes].slice(0, 5);
  
  // Generate HTML with vertical arrows (up/down) instead of diagonal
  if (allSizes.length > 0) {
    const alternativesHTML = allSizes.map(alt => {
      const arrow = alt.direction === 'up' ? '↑' : '↓'; // Using vertical arrows
      return `
        <div class="alternative-size-button" onclick="selectPopularSize(${alt.width}, ${alt.height})">
          <div class="alt-size-text">${arrow} ${alt.width}" × ${alt.height}"</div>
        </div>
      `;
    }).join('');
    
    popularSizesContainer.innerHTML = alternativesHTML;
  } else {
    popularSizesContainer.innerHTML = '<div class="similar-placeholder">No proportional sizes available within constraints</div>';
  }
  
}

// ===== MATERIAL HANDLING =====
function toggleMaterial() {
  state.selectedMaterial = state.selectedMaterial === 'poster' ? 'fabric' : 'poster';
  var hiddenMaterial = document.getElementById('hiddenMaterial');
  if (hiddenMaterial) {
    hiddenMaterial.value = state.selectedMaterial;
  }
  updateMaterialDisplay();
  update();
  generateAlternativeSizes();
  updateMaterialDelta();
}

function updateMaterialDisplay() {
  var isFabric = state.selectedMaterial === 'fabric';

  // Size section toggle
  if (elements.posterOption) {
    elements.posterOption.classList.toggle('active', !isFabric);
  }
  if (elements.fabricOption) {
    elements.fabricOption.classList.toggle('active', isFabric);
  }
  if (elements.materialSlider) {
    elements.materialSlider.classList.toggle('fabric', isFabric);
  }

  // Pricing header toggle (synced duplicate)
  var posterOptP = document.getElementById('posterOptionPricing');
  var fabricOptP = document.getElementById('fabricOptionPricing');
  var sliderP = document.getElementById('materialSliderPricing');
  if (posterOptP) posterOptP.classList.toggle('active', !isFabric);
  if (fabricOptP) fabricOptP.classList.toggle('active', isFabric);
  if (sliderP) sliderP.classList.toggle('fabric', isFabric);
}

// Calculate and display the price difference on the inactive material option
function updateMaterialDelta() {
  var fabricBadge = document.getElementById('fabricDeltaBadge');
  var posterBadge = document.getElementById('posterDeltaBadge');
  if (!fabricBadge || !posterBadge) return;

  var width = state.dimensions.width;
  var height = state.dimensions.height;
  var fabricBadgeP = document.getElementById('fabricDeltaBadgePricing');
  var posterBadgeP = document.getElementById('posterDeltaBadgePricing');

  if (!width || !height || !state.pricingLoaded) {
    [fabricBadge, posterBadge, fabricBadgeP, posterBadgeP].forEach(function(b) { if (b) b.classList.remove('visible'); });
    return;
  }

  var area = width * height;
  var posterData = getCurrentPricingData('poster');
  var fabricData = getCurrentPricingData('fabric');

  var posterRow = posterData.find(function(r) { return area >= r.min && area <= r.max; });
  var fabricRow = fabricData.find(function(r) { return area >= r.min && area <= r.max; });

  if (!posterRow || !fabricRow) {
    [fabricBadge, posterBadge, fabricBadgeP, posterBadgeP].forEach(function(b) { if (b) b.classList.remove('visible'); });
    return;
  }

  // Use the best available tier for the delta, or fall back to 'standard'
  var tierKey = 'standard';
  if (state.selectedDate) {
    var inDate = parseSelectedDate(state.selectedDate);
    var deliveryTimeValue = state.selectedDeliveryTime || 'anytime';
    var now = new Date();
    for (var i = 0; i < config.tiers.length; i++) {
      var t = config.tiers[i];
      var cutoff = getCutoffDate(inDate, t.lead, t.cutoffHour, deliveryTimeValue);
      var isBlocked = isTierBlockedByDeliveryTime(t.key, inDate, deliveryTimeValue);
      if (cutoff > now && !isBlocked && posterRow[t.key] > 0) {
        tierKey = t.key;
        break;
      }
    }
  }

  var posterPrice = posterRow[tierKey] || 0;
  var fabricPrice = fabricRow[tierKey] || 0;

  if (posterPrice <= 0 || fabricPrice <= 0 || fabricPrice === posterPrice) {
    [fabricBadge, posterBadge, fabricBadgeP, posterBadgeP].forEach(function(b) { if (b) b.classList.remove('visible'); });
    return;
  }

  var diff = fabricPrice - posterPrice;

  // Apply to both size-section and pricing-header badges
  var allFabricBadges = [fabricBadge, fabricBadgeP];
  var allPosterBadges = [posterBadge, posterBadgeP];

  if (state.selectedMaterial === 'poster') {
    allFabricBadges.forEach(function(b) {
      if (b) { b.textContent = '+$' + diff.toFixed(0); b.classList.remove('delta-savings'); b.classList.add('visible'); }
    });
    allPosterBadges.forEach(function(b) {
      if (b) { b.classList.remove('visible', 'delta-savings'); }
    });
  } else {
    allPosterBadges.forEach(function(b) {
      if (b) { b.textContent = '-$' + diff.toFixed(0); b.classList.add('visible', 'delta-savings'); }
    });
    allFabricBadges.forEach(function(b) {
      if (b) { b.classList.remove('visible'); }
    });
  }
}

// ===== FILE HANDLING =====

// Show inline error in the upload zone (replaces alert() for better UX)
function showUploadError(message) {
  var zone = elements.uploadZone;
  if (!zone) return;
  var existing = zone.querySelector('.upload-inline-error');
  if (existing) existing.remove();
  var el = document.createElement('div');
  el.className = 'upload-inline-error';
  el.innerHTML = '&#9888;&#65039; ' + message;
  zone.appendChild(el);
  // Auto-dismiss after 8 seconds
  setTimeout(function () { if (el.parentNode) el.remove(); }, 8000);
}

function handleFiles(files) {
  if (files.length === 0) return;

  const file = files[0];
  const fileExt = '.' + file.name.split('.').pop().toLowerCase();

  if (file.size > config.maxFileSize) {
    showUploadError('File is too large. Maximum size is 100MB.');
    return false;
  }

  if (!config.allowedTypes.includes(fileExt)) {
    showUploadError('File format not supported. Accepted: PDF, AI, EPS, PSD, PNG, JPG, TIFF, SVG, PPTX');
    return false;
  }

  state.uploadedFile = file;

  updateFileDisplay(file);
  updateOrderSummary();
  updateSubmitButtonState();
  
  return true;
}

// ===== FILE DISPLAY WITH DIMENSION DETECTION =====

// Vector file types (resolution-independent)
var VECTOR_TYPES = ['pdf', 'ai', 'eps', 'svg'];
var IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'tiff', 'tif'];

// Status-led upload preview with dimension detection
function updateFileDisplay(file) {
  if (window.uploadProgressRunning) return;

  var fileExt = file.name.split('.').pop().toLowerCase();
  var canPreview = IMAGE_TYPES.indexOf(fileExt) !== -1 || fileExt === 'pdf';

  // Hide original upload zone content
  var uploadContent = elements.uploadZone.querySelector('.upload-content-preload');
  if (uploadContent) uploadContent.style.display = 'none';
  var oldSuccess = elements.uploadZone.querySelector('.upload-content-success-compact');
  if (oldSuccess) oldSuccess.style.display = 'none';

  var previewElement = elements.uploadZone.querySelector('.file-preview-display');
  if (!previewElement) {
    previewElement = document.createElement('div');
    previewElement.className = 'file-preview-display';
    elements.uploadZone.appendChild(previewElement);
  }

  // Thumbnail
  var thumbnailHTML = canPreview
    ? '<div class="file-thumb-container" id="fileThumbContainer"><div class="file-thumb-loading"><div class="file-thumb-spinner"></div></div></div>'
    : '<div class="file-thumb-container file-thumb-icon-only"><div class="file-thumb-type-label">' + fileExt.toUpperCase() + '</div></div>';

  previewElement.innerHTML =
    '<div class="file-preview-card" id="filePreviewCard">' +
      '<div class="file-preview-card-inner">' +
        '<div class="file-thumb-column">' +
          thumbnailHTML +
          '<button type="button" class="file-change-btn" onclick="changeFile()">&#8635; Change file</button>' +
        '</div>' +
        '<div class="file-thumb-divider"></div>' +
        '<div class="file-preview-right">' +
          '<div class="file-preview-details">' +
            '<div class="file-detail-row"><span class="file-detail-label">File:</span><span class="file-detail-value file-detail-name">' + file.name + '</span></div>' +
            '<div class="file-detail-row"><span class="file-detail-label">Format:</span><span class="file-detail-value">' + fileExt.toUpperCase() + '</span></div>' +
            '<div class="file-detail-row"><span class="file-detail-label">Size:</span><span class="file-detail-value">' + formatFileSize(file.size) + '</span></div>' +
            '<div class="file-detail-row" id="fileDimsRow" style="display:none"><span class="file-detail-label">Dimensions:</span><span class="file-detail-value" id="fileDimsValue"></span></div>' +
          '</div>' +
          '<div class="file-preview-status" id="filePreviewStatus">' +
            '<div class="file-status-loading">Analysing file...</div>' +
          '</div>' +
          '<div id="filePreviewActions" class="file-preview-actions"></div>' +
        '</div>' +
      '</div>' +
    '</div>';

  elements.uploadZone.classList.add('has-file');

  if (IMAGE_TYPES.indexOf(fileExt) !== -1) {
    detectImageDimensions(file);
  } else if (fileExt === 'pdf') {
    detectPDFDimensions(file);
  } else if (fileExt === 'pptx') {
    detectPPTXDimensions(file);
  } else {
    showFileStatus(null, null, null, VECTOR_TYPES.indexOf(fileExt) !== -1);
  }
}

// Change file — clear and reopen picker
function changeFile() {
  removeFile();
  if (elements.fileInput) {
    elements.fileInput.click();
  }
}

// ===== STATUS DISPLAY =====

function showFileStatus(widthInches, heightInches, dpiInfo, isVector) {
  var statusEl = document.getElementById('filePreviewStatus');
  var actionsEl = document.getElementById('filePreviewActions');
  var card = document.getElementById('filePreviewCard');
  var dimsRow = document.getElementById('fileDimsRow');
  var dimsValue = document.getElementById('fileDimsValue');
  if (!statusEl || !card) return;

  window._detectedFileDims = { w: widthInches, h: heightInches, dpi: dpiInfo, isVector: isVector };

  var posterW = parseInt(document.getElementById('w') ? document.getElementById('w').value : 0) || 0;
  var posterH = parseInt(document.getElementById('h') ? document.getElementById('h').value : 0) || 0;
  var hasPosterSize = posterW >= 12 && posterH >= 12;

  // Could not detect dimensions
  if (widthInches === null) {
    card.className = 'file-preview-card status-neutral';
    statusEl.innerHTML = '<div class="file-status-msg status-msg-neutral">We will review your file for print compatibility</div>';
    return;
  }

  // Show dimensions in the detail row
  var dimsText = widthInches.toFixed(2) + '" \u00d7 ' + heightInches.toFixed(2) + '"';
  if (dimsRow) dimsRow.style.display = '';
  if (dimsValue) {
    dimsValue.innerHTML = '<strong>' + dimsText + '</strong>' +
      '';
  }

  var statusHTML = '';
  var actionHTML = '';
  var statusClass = '';

  if (!hasPosterSize) {
    statusClass = 'status-info';
    statusHTML = '<div class="file-status-msg status-msg-info">Select a poster size above to check compatibility</div>';

    var roundW = Math.round(widthInches);
    var roundH = Math.round(heightInches);
    if (roundW >= 12 && roundH >= 12 && roundW <= 96 && roundH <= 48) {
      actionHTML = '<button type="button" class="file-autofill-btn" onclick="applyFileDimensions(' + roundW + ',' + roundH + ')">' +
        'Use file size: ' + roundW + '" \u00d7 ' + roundH + '"</button>';
    }

  } else {
    var dpiWarning = '';
    if (dpiInfo && !isVector) {
      var effectiveDPI = Math.min(dpiInfo.pixelW / posterW, dpiInfo.pixelH / posterH);
      if (effectiveDPI < 100) {
        dpiWarning = '<div class="file-status-msg status-msg-warn">Low resolution (' + Math.round(effectiveDPI) + ' DPI at print size) \u2014 text and details may appear blurry. We recommend uploading a higher resolution file (150+ DPI).</div>';
      } else if (effectiveDPI < 150) {
        dpiWarning = '<div class="file-status-msg status-msg-caution">Acceptable quality (' + Math.round(effectiveDPI) + ' DPI at print size) \u2014 may show slight softness in fine details</div>';
      }
    }

    var fileRatio = widthInches / heightInches;
    var posterRatio = posterW / posterH;
    var ratioMatch = Math.abs(fileRatio - posterRatio) / posterRatio < 0.03;

    if (ratioMatch && !dpiWarning) {
      statusClass = 'status-good';
      statusHTML = '<div class="file-status-msg status-msg-good">&#10004; Ready to print \u2014 matches your selected size</div>';

    } else if (ratioMatch && dpiWarning) {
      statusClass = 'status-warn';
      statusHTML = dpiWarning;

    } else {
      statusClass = dpiWarning ? 'status-warn' : 'status-caution';
      statusHTML = '<div class="file-status-msg status-msg-caution">&#9888; Your file is ' +
        widthInches.toFixed(1) + '" \u00d7 ' + heightInches.toFixed(1) + '" but your selected poster size is ' +
        posterW + '" \u00d7 ' + posterH + '". Printing at a different aspect ratio may stretch or crop your design.</div>';
      if (dpiWarning) statusHTML += dpiWarning;

      // Generate proportional size options, favouring larger sizes
      var options = [];
      var seen = {};
      var posterArea = posterW * posterH;

      // Strategy: generate candidates by anchoring to common widths AND heights
      // then sort by area descending (largest first), filtering valid range
      var anchorWidths = [96, 72, 60, 54, 48, 42, 36, 30, 24, 18];
      var anchorHeights = [48, 44, 42, 40, 36, 33, 30, 24, 18];

      // From anchor widths: calculate proportional height
      for (var aw = 0; aw < anchorWidths.length; aw++) {
        var cw = anchorWidths[aw];
        var ch = Math.round(cw / fileRatio);
        if (ch >= 12 && ch <= 48 && cw >= 12 && cw <= 96) {
          var key = cw + 'x' + ch;
          if (!seen[key]) { seen[key] = true; options.push({ w: cw, h: ch, area: cw * ch }); }
        }
      }
      // From anchor heights: calculate proportional width
      for (var ah = 0; ah < anchorHeights.length; ah++) {
        var ch2 = anchorHeights[ah];
        var cw2 = Math.round(ch2 * fileRatio);
        if (cw2 >= 12 && cw2 <= 96 && ch2 >= 12 && ch2 <= 48) {
          var key2 = cw2 + 'x' + ch2;
          if (!seen[key2]) { seen[key2] = true; options.push({ w: cw2, h: ch2, area: cw2 * ch2 }); }
        }
      }

      // Remove the exact selected size if it appears
      options = options.filter(function(o) { return !(o.w === posterW && o.h === posterH); });

      // Sort: larger sizes first
      options.sort(function(a, b) { return b.area - a.area; });

      // Pick up to 3 options: prefer sizes >= selected area, then fill with smaller
      var larger = options.filter(function(o) { return o.area >= posterArea; });
      var smaller = options.filter(function(o) { return o.area < posterArea; });

      // Reverse larger so closest-to-selected is first among the large ones
      larger.sort(function(a, b) { return a.area - b.area; });

      var finalOptions = [];
      // Add up to 2 larger options (closest first)
      for (var li = 0; li < Math.min(2, larger.length); li++) {
        finalOptions.push(larger[li]);
      }
      // Add 1 smaller option if we have room
      if (finalOptions.length < 3 && smaller.length > 0) {
        finalOptions.push(smaller[0]);
      }
      // If no larger options at all, show top 2 smaller
      if (finalOptions.length === 0) {
        finalOptions = smaller.slice(0, 2);
      }

      if (finalOptions.length > 0) {
        var suggestHTML = '<div class="file-size-suggest">To avoid stretching, use one of these sizes that match your file\u2019s proportions: ';
        for (var fi = 0; fi < finalOptions.length; fi++) {
          suggestHTML += '<button type="button" class="file-suggest-btn" onclick="applyFileDimensions(' +
            finalOptions[fi].w + ',' + finalOptions[fi].h + ')">' +
            finalOptions[fi].w + '" \u00d7 ' + finalOptions[fi].h + '"</button>';
        }
        suggestHTML += '</div>';
        statusHTML += suggestHTML;
      }
    }
  }

  card.className = 'file-preview-card ' + statusClass;
  statusEl.innerHTML = statusHTML;
  if (actionsEl) actionsEl.innerHTML = actionHTML;
}

function applyFileDimensions(w, h) {
  var widthInput = document.getElementById('w');
  var heightInput = document.getElementById('h');
  if (widthInput) {
    widthInput.value = w;
    widthInput.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (heightInput) {
    heightInput.value = h;
    heightInput.dispatchEvent(new Event('input', { bubbles: true }));
  }
  if (typeof update === 'function') update();
  if (typeof generateAlternativeSizes === 'function') generateAlternativeSizes();
  if (window._detectedFileDims) {
    var d = window._detectedFileDims;
    showFileStatus(d.w, d.h, d.dpi, d.isVector);
  }
}

function refreshFileDimensionDisplay() {
  if (window._detectedFileDims) {
    var d = window._detectedFileDims;
    showFileStatus(d.w, d.h, d.dpi, d.isVector);
  }
}

// Update the poster preview board with the uploaded file thumbnail
function updatePosterPreviewWithFile(sourceElement) {
  var posterPreview = document.getElementById('posterPreview');
  if (!posterPreview) return;

  // Store the preview source for later (when size changes)
  if (sourceElement) {
    if (sourceElement.tagName === 'IMG') {
      window._filePreviewDataUrl = sourceElement.src;
    }
    // For canvas (PDF), render a high-res version separately
    // The thumbnail canvas is too small — don't use it for the board preview
  }

  if (!window._filePreviewDataUrl) return;

  // Only show file preview when not in placeholder state
  if (posterPreview.classList.contains('poster-preview-placeholder')) return;

  posterPreview.style.backgroundImage = 'url(' + window._filePreviewDataUrl + ')';
  posterPreview.style.backgroundSize = 'cover';
  posterPreview.style.backgroundPosition = 'center';
  posterPreview.classList.add('has-file-preview');
}

// Render a high-resolution PDF preview for the poster board (separate from thumbnail)
function renderHighResPDFPreview(file) {
  if (typeof pdfjsLib === 'undefined') return;

  var reader = new FileReader();
  reader.onload = function(e) {
    var typedArray = new Uint8Array(e.target.result);
    pdfjsLib.getDocument({ data: typedArray }).promise.then(function(pdf) {
      return pdf.getPage(1);
    }).then(function(page) {
      var viewport = page.getViewport({ scale: 1 });
      // Render at a scale that produces ~600px wide canvas (good for poster preview)
      var hiresScale = Math.min(600 / viewport.width, 800 / viewport.height);
      var hiresViewport = page.getViewport({ scale: hiresScale });

      var canvas = document.createElement('canvas');
      canvas.width = hiresViewport.width;
      canvas.height = hiresViewport.height;

      page.render({ canvasContext: canvas.getContext('2d'), viewport: hiresViewport }).promise.then(function() {
        window._filePreviewDataUrl = canvas.toDataURL('image/jpeg', 0.85);
        updatePosterPreviewWithFile(null);
      });
    }).catch(function() {});
  };
  reader.readAsArrayBuffer(file);
}

// Clear the file preview from the poster board
function clearPosterFilePreview() {
  var posterPreview = document.getElementById('posterPreview');
  if (posterPreview) {
    posterPreview.style.backgroundImage = '';
    posterPreview.style.backgroundSize = '';
    posterPreview.style.backgroundPosition = '';
    posterPreview.classList.remove('has-file-preview');
  }
  window._filePreviewDataUrl = null;
}

// ===== DIMENSION DETECTION BY FILE TYPE =====

function detectImageDimensions(file) {
  var fileExt = file.name.split('.').pop().toLowerCase();
  var container = document.getElementById('fileThumbContainer');

  if (container) {
    var objectUrl = URL.createObjectURL(file);
    var img = new Image();
    img.onload = function() {
      container.innerHTML = '';
      var thumbImg = document.createElement('img');
      thumbImg.src = objectUrl;
      thumbImg.className = 'file-thumb-image';
      thumbImg.alt = 'File preview';
      container.appendChild(thumbImg);

      var pixelW = img.naturalWidth;
      var pixelH = img.naturalHeight;
      var assumedDPI = 150;
      showFileStatus(pixelW / assumedDPI, pixelH / assumedDPI, { pixelW: pixelW, pixelH: pixelH }, false);
      updatePosterPreviewWithFile(thumbImg);
    };
    img.onerror = function() {
      URL.revokeObjectURL(objectUrl);
      if (container) container.innerHTML = '<div class="file-thumb-type-label">' + fileExt.toUpperCase() + '</div>';
      showFileStatus(null, null, null, false);
    };
    img.src = objectUrl;
  }
}

function detectPDFDimensions(file) {
  var container = document.getElementById('fileThumbContainer');

  if (typeof pdfjsLib === 'undefined') {
    if (container) container.innerHTML = '<div class="file-thumb-type-label">PDF</div>';
    showFileStatus(null, null, null, true);
    return;
  }

  pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

  var thumbTimeout = setTimeout(function() {
    if (container) container.innerHTML = '<div class="file-thumb-type-label">PDF</div>';
  }, 8000);

  var reader = new FileReader();
  reader.onload = function(e) {
    var typedArray = new Uint8Array(e.target.result);

    pdfjsLib.getDocument({ data: typedArray }).promise.then(function(pdf) {
      return pdf.getPage(1);
    }).then(function(page) {
      var viewport = page.getViewport({ scale: 1 });
      var widthInches = viewport.width / 72;
      var heightInches = viewport.height / 72;

      var thumbScale = Math.min(120 / viewport.width, 160 / viewport.height);
      var scaledViewport = page.getViewport({ scale: thumbScale });
      var canvas = document.createElement('canvas');
      canvas.width = scaledViewport.width;
      canvas.height = scaledViewport.height;
      canvas.className = 'file-thumb-canvas';

      page.render({ canvasContext: canvas.getContext('2d'), viewport: scaledViewport }).promise.then(function() {
        clearTimeout(thumbTimeout);
        if (container) { container.innerHTML = ''; container.appendChild(canvas); }
      });

      showFileStatus(widthInches, heightInches, null, true);

      // Kick off high-res render for poster board preview (separate from thumbnail)
      renderHighResPDFPreview(file);

    }).catch(function() {
      clearTimeout(thumbTimeout);
      if (container) container.innerHTML = '<div class="file-thumb-type-label">PDF</div>';
      showFileStatus(null, null, null, true);
    });
  };
  reader.onerror = function() {
    clearTimeout(thumbTimeout);
    if (container) container.innerHTML = '<div class="file-thumb-type-label">PDF</div>';
    showFileStatus(null, null, null, true);
  };
  reader.readAsArrayBuffer(file);
}

function detectPPTXDimensions(file) {
  if (typeof JSZip === 'undefined') {
    showFileStatus(13.333, 7.5, null, true);
    return;
  }

  var reader = new FileReader();
  reader.onload = function(e) {
    JSZip.loadAsync(e.target.result).then(function(zip) {
      return zip.file('ppt/presentation.xml').async('text');
    }).then(function(xml) {
      var parser = new DOMParser();
      var doc = parser.parseFromString(xml, 'text/xml');
      var sldSz = doc.getElementsByTagName('p:sldSz')[0];
      if (sldSz) {
        var cx = parseInt(sldSz.getAttribute('cx')) || 0;
        var cy = parseInt(sldSz.getAttribute('cy')) || 0;
        showFileStatus(cx / 914400, cy / 914400, null, true);
      } else {
        showFileStatus(13.333, 7.5, null, true);
      }
    }).catch(function() {
      showFileStatus(13.333, 7.5, null, true);
    });
  };
  reader.readAsArrayBuffer(file);
}

// Expose for inline JS override in index.php
window._thumbnailFileDisplay = updateFileDisplay;

// Remove uploaded file and restore upload zone
function removeFile() {
  state.uploadedFile = null;
  window._detectedFileDims = null;

  // Clear the actual file input value
  if (elements.fileInput) {
    elements.fileInput.value = '';
  }

  elements.uploadZone.classList.remove('has-file');
  clearPosterFilePreview();

  // Remove preview element
  var previewElement = elements.uploadZone.querySelector('.file-preview-display');
  if (previewElement) {
    previewElement.remove();
  }

  // Show the original upload content again
  var uploadContent = elements.uploadZone.querySelector('.upload-content-preload');
  if (uploadContent) {
    uploadContent.style.display = 'flex';
  }

  updateOrderSummary();
  updateSubmitButtonState();
}

function getFileIcon(extension) {
  var icons = {
    'pdf': '&#128196;', 'ai': '&#127912;', 'eps': '&#127912;', 'psd': '&#128444;',
    'png': '&#128444;', 'jpg': '&#128444;', 'jpeg': '&#128444;', 'tiff': '&#128444;',
    'tif': '&#128444;', 'webp': '&#128444;', 'gif': '&#128444;', 'bmp': '&#128444;',
    'svg': '&#127912;', 'pptx': '&#128196;', 'indd': '&#128444;'
  };
  return icons[extension.toLowerCase()] || '&#128196;';
}

function formatFileSize(bytes) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ===== DELIVERY OPTIONS =====
function selectDeliveryOption(option) {
  if (option === 'pickup') return;
  
  state.deliveryOption = option;
  const hiddenDeliveryOption = document.getElementById('hiddenDeliveryOption');
  if (hiddenDeliveryOption) {
    hiddenDeliveryOption.value = option;
  }
  
  document.querySelectorAll('.delivery-option').forEach(function(opt) {
    opt.classList.remove('selected');
  });
  
  const optionElement = document.getElementById(option + 'Option');
  if (optionElement) {
    optionElement.classList.add('selected');
  }
  
  const deliveryDetails = document.getElementById('deliveryDetails');
  const mtccMessage = document.getElementById('mtccMessage');
  const addressForm = document.getElementById('addressForm');
  
  // Get all delivery address fields
  const addressFields = [
    'deliveryAttn',
    'deliveryAddress', 
    'deliveryCity',
    'deliveryProvince',
    'deliveryPostal'
  ];
  
  if (deliveryDetails) {
    deliveryDetails.style.display = 'block';
  }
  
  if (option === 'mtcc') {
    if (mtccMessage) mtccMessage.style.display = 'block';
    if (addressForm) addressForm.style.display = 'none';
    
    // CRITICAL: Remove required attribute from hidden fields
    addressFields.forEach(fieldId => {
      const field = document.getElementById(fieldId);
      if (field) {
        field.removeAttribute('required');
        field.value = ''; // Clear the value too
      }
    });
    
  } else if (option === 'office') {
    if (mtccMessage) mtccMessage.style.display = 'none';
    if (addressForm) addressForm.style.display = 'block';
    
    // CRITICAL: Add required attribute to visible fields
    addressFields.forEach(fieldId => {
      const field = document.getElementById(fieldId);
      if (field) {
        field.setAttribute('required', 'required');
      }
    });
  }
  
  updateOrderSummary();
  updateSubmitButtonState();
}


// ===== PRICING CALCULATIONS =====
function calculateOrderTotal() {
  const tierData = getBestAvailableTier();
  if (!tierData) return null;

  const basePrice = tierData.price;
  const deliveryFee = state.deliveryOption === 'office' ? 10.00 : 0.00;
  const subtotal = basePrice + deliveryFee;
  const tax = subtotal * config.taxRate;
  const total = subtotal + tax;

  return {
    basePrice: basePrice,
    deliveryFee: deliveryFee,
    subtotal: subtotal,
    tax: tax,
    total: total,
    tier: tierData
  };
}

function getBestAvailableTier() {
  if (!state.dimensions.width || !state.dimensions.height || !state.selectedDate) {
    return null;
  }
  
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  const inDate = parseSelectedDate(state.selectedDate);
  const now = new Date();
  const area = width * height;
  const pricingData = getCurrentPricingData(state.selectedMaterial);
  const deliveryTimeValue = state.selectedDeliveryTime || 'anytime';
  
  const row = pricingData.find(function(r) {
    return area >= r.min && area <= r.max;
  });
  
  if (!row) {
    return null;
  }
  
  // Find best available tier considering delivery time rules
  let closestTier = null;
  let closestTime = Infinity;
  
  config.tiers.forEach(function(tier) {
    const cutoff = getCutoffDate(inDate, tier.lead, tier.cutoffHour, deliveryTimeValue);
    const isExpired = cutoff <= now;
    const priceValue = row[tier.key] || 0;
    const isBlocked = isTierBlockedByDeliveryTime(tier.key, inDate, deliveryTimeValue);
    
    if (!isExpired && !isBlocked && cutoff.getTime() < closestTime && priceValue > 0) {
      closestTime = cutoff.getTime();
      closestTier = {
        price: priceValue,
        label: tier.label,
        days: tier.days,
        key: tier.key
      };
    }
  });
  
  if (!closestTier) {
    return null;
  }
  
  return closestTier;
}

// ===== PRODUCTION DEADLINE MODEL =====
// Single source of truth for "when must this print be physically ready at the vendor".
// All tier order cutoffs derive from this by walking back business days.
//
// Rules:
//   - Weekday delivery @ 9am  → previous business day @ 2 PM
//   - Weekday delivery @ Xpm  → same day @ (X - 3h)  [3-hour production window]
//   - Sat/Sun delivery        → Friday before @ 2 PM  [no weekend printing]
//   - Monday delivery @ 9am   → Friday before @ 2 PM  [weekend hold + overnight]
//
// Business-day walks skip Sat, Sun, and any date in window.HOLIDAYS.

function isHolidayDate(date) {
  if (!window.HOLIDAYS) return false;
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return Object.prototype.hasOwnProperty.call(window.HOLIDAYS, `${y}-${m}-${d}`);
}

function isBusinessDay(date) {
  const dow = date.getDay();
  if (dow === 0 || dow === 6) return false;
  return !isHolidayDate(date);
}

function walkBackBusinessDays(fromDate, numDays) {
  const d = new Date(fromDate);
  let remaining = numDays;
  while (remaining > 0) {
    d.setDate(d.getDate() - 1);
    if (isBusinessDay(d)) remaining--;
  }
  return d;
}

function getPreviousBusinessDay(date) {
  const prev = new Date(date);
  do {
    prev.setDate(prev.getDate() - 1);
  } while (!isBusinessDay(prev));
  return prev;
}

function isWeekendDay(date) {
  const d = date.getDay();
  return d === 0 || d === 6;
}

// Given a delivery date and time, return the production deadline datetime.
// This is the "print-must-be-ready" moment — ALL tier cutoffs walk back from here.
function getProductionDeadline(deliveryDate, deliveryTimeValue) {
  const delivery = new Date(deliveryDate);
  delivery.setHours(0, 0, 0, 0);
  const deliveryDay = delivery.getDay();

  // Sat/Sun delivery → Friday before @ 2 PM
  if (deliveryDay === 0 || deliveryDay === 6) {
    const fri = new Date(delivery);
    while (fri.getDay() !== 5) {
      fri.setDate(fri.getDate() - 1);
    }
    // If that Friday is itself a holiday, walk further back to last business day
    while (!isBusinessDay(fri)) {
      fri.setDate(fri.getDate() - 1);
    }
    fri.setHours(14, 0, 0, 0);
    return fri;
  }

  // Monday delivery @ 9am → Friday before @ 2 PM (weekend hold + overnight)
  if (deliveryDay === 1 && deliveryTimeValue === '9am') {
    const fri = getPreviousBusinessDay(delivery);
    fri.setHours(14, 0, 0, 0);
    return fri;
  }

  // Weekday delivery @ 9am → previous business day @ 2 PM
  if (deliveryTimeValue === '9am') {
    const prev = getPreviousBusinessDay(delivery);
    prev.setHours(14, 0, 0, 0);
    return prev;
  }

  // Weekday delivery @ 12pm/3pm/6pm/anytime → same day @ (deliveryHour - 3h)
  // 12pm → 9am, 3pm → 12pm, 6pm/anytime → 3pm
  const deliveryHourMap = { '12pm': 12, '3pm': 15, '6pm': 18, 'anytime': 18 };
  const deliveryHour = deliveryHourMap[deliveryTimeValue] || 18;
  const deadline = new Date(delivery);
  deadline.setHours(deliveryHour - 3, 0, 0, 0);
  return deadline;
}

// Tier order-cutoff: walk back leadDays business days from production deadline,
// set tier cutoff hour. Same-day tier (lead=0) uses the production deadline itself
// (no cutoff-hour override) because the production deadline IS the last moment to order.
function getCutoffDate(inDate, leadDays, cutoffHour, deliveryTimeValue) {
  const productionDeadline = getProductionDeadline(inDate, deliveryTimeValue);

  if (leadDays === 0) {
    // Last Minute: cutoff = production deadline exactly
    return productionDeadline;
  }

  const cutoff = walkBackBusinessDays(productionDeadline, leadDays);
  cutoff.setHours(cutoffHour, 0, 0, 0);
  return cutoff;
}

// With the production-deadline model, tier availability is purely a function of
// whether the tier's order cutoff is still in the future. No more hard-coded
// "weekend blocks everything" rules — if the math works, the tier is offered.
// This function is retained for API compatibility with existing call sites but
// always returns false (never blocks). Actual availability comes from comparing
// getCutoffDate() against now in the tier rendering loop.
function isTierBlockedByDeliveryTime(tierKey, deliveryDate, deliveryTimeValue) {
  return false;
}

// SIMPLIFIED: Removed timezone parameters, use CSS classes for emphasis
function formatESTDateTime(date) {
  // Format date without timezone conversion
  const dateStr = date.toLocaleDateString('en-US', {
    weekday: 'short',
    month: 'short', 
    day: 'numeric',
    year: 'numeric'
  });
  
  // Format time without timezone conversion
  const timeStr = date.toLocaleTimeString('en-US', {
    hour: 'numeric',
    minute: '2-digit', 
    hour12: true
  });
  
  // FIXED: Use CSS classes for emphasis, keep "EST" as descriptive text
  return `<span class="cutoff-date">${dateStr}</span> by <span class="cutoff-time">${timeStr}</span> EST`;
}

// ===== ORDER SUMMARY =====
function updateOrderSummary() {
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  
  let posterDetails = [];
  
  if (state.selectedEvent) {
    posterDetails.push(state.selectedEvent.name);
  }
  
  if (width && height) {
    posterDetails.push(width + '" x ' + height + '"');
    posterDetails.push(width >= height ? 'Landscape' : 'Portrait');
  }
  posterDetails.push(state.selectedMaterial === 'fabric' ? 'Fabric' : 'Poster Paper');
  
  const costData = calculateOrderTotal();
  if (costData) {
    posterDetails.push(costData.tier.label + ' (' + costData.tier.days + ')');
  }
  
  const summaryPosterDetails = document.getElementById('summaryPosterDetails');
  if (summaryPosterDetails) {
    // Show progressively — display whatever details are available
    var displayParts = posterDetails.filter(function(p) { return p && p.length > 0; });
    if (displayParts.length > 1) {
      summaryPosterDetails.textContent = displayParts.join(' \u2022 ');
      summaryPosterDetails.style.color = 'var(--text)';
    } else if (displayParts.length === 1) {
      summaryPosterDetails.textContent = displayParts[0];
      summaryPosterDetails.style.color = 'var(--subtext)';
    } else {
      summaryPosterDetails.textContent = 'Select event, size and date above';
      summaryPosterDetails.style.color = 'var(--subtext)';
    }
  }

  const summaryDeliveryDetails = document.getElementById('summaryDeliveryDetails');
  const summaryDeliveryFee = document.getElementById('summaryDeliveryFee');
  
  if (summaryDeliveryDetails && summaryDeliveryFee) {
    if (state.deliveryOption && state.selectedDate) {
      const deliveryText = state.deliveryOption === 'mtcc' ? 'MTCC Delivery' : 'Address Delivery';
      const dateText = state.selectedDate ? parseSelectedDate(state.selectedDate).toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric' }) : '';
      summaryDeliveryDetails.textContent = deliveryText + ' • ' + dateText;
      
      if (state.deliveryOption === 'office') {
        summaryDeliveryFee.innerHTML = '<span style="color: var(--blue); font-weight: 600;">$10.00</span>';
      } else {
        summaryDeliveryFee.innerHTML = '<span style="color: var(--green); font-weight: 600;">FREE</span>';
      }
    } else {
      summaryDeliveryDetails.textContent = 'Select delivery method and date';
      summaryDeliveryFee.textContent = '$ -';
    }
  }

  const summaryBasePrice = document.getElementById('summaryBasePrice');
  const summarySubtotal = document.getElementById('summarySubtotal');
  const summaryTax = document.getElementById('summaryTax');
  const summaryTotal = document.getElementById('summaryTotal');
  
  if (!costData) {
    if (summaryBasePrice) summaryBasePrice.textContent = '$ -';
    if (summarySubtotal) summarySubtotal.textContent = '$ -';
    if (summaryTax) summaryTax.textContent = '$ -';
    if (summaryTotal) summaryTotal.textContent = '$ -';
  } else {
    if (summaryBasePrice) summaryBasePrice.textContent = '$' + costData.basePrice.toFixed(2);
    if (summarySubtotal) summarySubtotal.textContent = '$' + costData.subtotal.toFixed(2);
    if (summaryTax) summaryTax.textContent = '$' + costData.tax.toFixed(2);
    if (summaryTotal) summaryTotal.textContent = '$' + costData.total.toFixed(2);
  }
}

// ===== PRICING DISPLAY =====
function updatePricingVisibility() {
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  const hasEvent = state.selectedEvent;
  const hasDate = state.selectedDate;
  const pricingSection = document.getElementById('pricingSection');
  
  if (pricingSection) {
    pricingSection.style.display = 'block';
  }
  
  if (hasEvent && hasDate && width && height && state.pricingLoaded) {
    updatePricing();
  } else if (!state.pricingLoaded) {
    if (elements.pricing) {
      elements.pricing.innerHTML = '<div class="skeleton-pricing">' +
        '<div class="skeleton-bar" style="width:65%;height:16px;margin:0 auto 10px;"></div>' +
        '<div class="skeleton-bar" style="width:40%;height:12px;margin:0 auto 18px;"></div>' +
        '<div class="skeleton-cards-row">' +
        Array(6).fill('<div class="skeleton-card"><div class="skeleton-card-header"></div><div class="skeleton-card-body"><div class="skeleton-bar" style="width:60%;height:10px;"></div><div class="skeleton-bar" style="width:80%;height:28px;"></div><div class="skeleton-bar" style="width:50%;height:10px;"></div></div><div class="skeleton-card-footer"></div></div>').join('') +
        '</div>' +
        '<div class="skeleton-bar" style="width:75%;height:14px;margin:14px auto 0;"></div>' +
        '</div>';
    }
  } else {
    if (elements.pricing) {
      elements.pricing.innerHTML = '<div class="pricing-placeholder"><div class="placeholder-icon">&#128176;</div><div class="placeholder-title">Select your event, delivery date, and poster size to see pricing</div><div class="placeholder-subtitle">Pricing varies based on how quickly you need your poster</div></div>';
    }
  }
}

// ===== PRICING DISPLAY WITH DELIVERY TIME AWARENESS =====
function updatePricing() {
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  const inDate = parseSelectedDate(state.selectedDate);
  const deliveryTimeValue = state.selectedDeliveryTime || 'anytime';
  
  if (state.countdownInterval) {
    clearInterval(state.countdownInterval);
    state.countdownInterval = null;
  }
  
  if (!width || !height || !inDate || !state.pricingLoaded) {
    if (elements.pricing) {
      elements.pricing.innerHTML = '<div class="pricing-placeholder"><div class="placeholder-icon">&#128176;</div><div class="placeholder-title">Select your event, delivery date, and poster size to see pricing</div></div>';
    }
    return;
  }
  
  const now = new Date();
  const area = width * height;
  const pricingData = getCurrentPricingData(state.selectedMaterial);
  const row = pricingData.find(function(r) {
    return area >= r.min && area <= r.max;
  });
  
  if (!row) {
    if (elements.pricing) {
      elements.pricing.innerHTML = '<div class="pricing-placeholder"><div class="placeholder-icon">&#9888;️</div><div class="placeholder-title">Size not available for selected material</div><div class="placeholder-subtitle">Please try a different size or material</div></div>';
    }
    return;
  }
  
  // Find best available tier for countdown + most-expensive still-available tier
  // (used for active-card savings calculation)
  let bestAvailableTier = null;
  let closestTime = Infinity;
  let maxAvailableTier = null;
  let maxAvailablePrice = 0;

  config.tiers.forEach(function(tier) {
    const cutoff = getCutoffDate(inDate, tier.lead, tier.cutoffHour, deliveryTimeValue);
    const isExpired = cutoff <= now;
    const priceValue = row[tier.key] || 0;
    // Check if this tier is blocked by delivery time rules
    const isBlocked = isTierBlockedByDeliveryTime(tier.key, inDate, deliveryTimeValue);

    if (!isExpired && !isBlocked && priceValue > 0) {
      if (cutoff.getTime() < closestTime) {
        closestTime = cutoff.getTime();
        bestAvailableTier = tier;
      }
      if (priceValue > maxAvailablePrice) {
        maxAvailablePrice = priceValue;
        maxAvailableTier = tier;
      }
    }
  });

  const bestAvailablePrice = bestAvailableTier ? (row[bestAvailableTier.key] || 0) : 0;
  
  // Show toast ONLY when a tier expired via countdown (not user date/time changes)
  var newBestKey = bestAvailableTier ? bestAvailableTier.key : null;
  if (state.tierExpiredByCountdown && state.previousBestTierKey && state.previousBestTierKey !== newBestKey) {
    var oldTier = config.tiers.find(function(t) { return t.key === state.previousBestTierKey; });
    if (oldTier && bestAvailableTier) {
      showPricingToast(oldTier.label, bestAvailableTier.label);
    } else if (oldTier && !bestAvailableTier) {
      showPricingToast(oldTier.label, null);
    }
  }
  state.tierExpiredByCountdown = false;
  state.previousBestTierKey = newBestKey;
  
  // Generate pricing HTML — unified calendar cards
  const monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  let pricingHTML = '';

  // Delivery date anchor header — two-line format with date emphasis + lesson subline
  const fullMonthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const deliveryFullMonth = fullMonthNames[inDate.getMonth()];
  const deliveryDay = inDate.getDate();
  const deliveryWeekday = dayNames[inDate.getDay()];
  var deliveryTimeDisplay = deliveryTimeValue === 'anytime' ? '' : ' @ ' + deliveryTimeValue.replace(/^(\d+)(am|pm)$/i, '$1:00 $2');
  var deliveryFullDisplay = deliveryWeekday + ', ' + deliveryFullMonth + ' ' + deliveryDay + deliveryTimeDisplay;
  pricingHTML += '<div class="pricing-delivery-anchor">';
  pricingHTML += '<div class="pricing-anchor-headline">Pricing for delivery on <strong>' + deliveryFullDisplay + '</strong></div>';
  pricingHTML += '<div class="pricing-anchor-subline">Order earlier for a lower price</div>';
  pricingHTML += '</div>';

  pricingHTML += '<div class="pricing-cards-row">';

  config.tiers.forEach(function(tier, tierIndex) {
    const cutoff = getCutoffDate(inDate, tier.lead, tier.cutoffHour, deliveryTimeValue);
    const isExpired = cutoff <= now;
    const priceValue = row[tier.key] || 0;
    const isBlocked = isTierBlockedByDeliveryTime(tier.key, inDate, deliveryTimeValue);
    const isUnavailable = isExpired || priceValue === 0 || isBlocked;
    const priceText = priceValue > 0 ? '$' + priceValue.toFixed(2) : 'N/A';
    const isBest = bestAvailableTier && tier.key === bestAvailableTier.key;

    // Extract date components for calendar card
    const cutoffMonth = monthNames[cutoff.getMonth()];
    const cutoffDay = cutoff.getDate();
    const cutoffWeekday = dayNames[cutoff.getDay()];
    const cutoffTimeStr = cutoff.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

    const cardClasses = ['pricing-card', tier.cls];
    if (isUnavailable) cardClasses.push('not-available');
    if (isBest) cardClasses.push('best-available');
    if (!isUnavailable && !isBest) cardClasses.push('future-available');

    pricingHTML += '<div class="' + cardClasses.join(' ') + '">';

    // "Your Price Today" pill — only on the active card. Lives inside the
    // card so it positions relative to the card itself (works on both desktop
    // single-row and mobile wrapped grid without JS).
    if (isBest) {
      pricingHTML += '<div class="pricing-card-ribbon ' + tier.cls + '">Your Price Today</div>';
    }

    // Header — two lines: tier name + turnaround
    pricingHTML += '<div class="pricing-card-header ' + tier.cls + '">';
    pricingHTML += '<div class="pricing-card-header-label">' + tier.label + '</div>';
    pricingHTML += '<div class="pricing-card-header-turnaround">' + tier.days + '</div>';
    pricingHTML += '</div>';

    pricingHTML += '<div class="pricing-card-body">';

    if (isUnavailable) {
      // Expired card — "Expired" centered in date area, price in grey action section with strikethrough
      pricingHTML += '<div class="pricing-card-date-section pricing-card-expired-date">';
      pricingHTML += '<div class="pricing-card-expired-label">Expired</div>';
      pricingHTML += '</div>';
      pricingHTML += '<div class="pricing-card-divider"></div>';
      pricingHTML += '<div class="pricing-card-action-section pricing-card-action-expired">';
      pricingHTML += '<div class="pricing-card-price pricing-card-price-expired">' + priceText + '</div>';
      pricingHTML += '</div>';
    } else {
      // Active / future card — order-by caption + date section + price + loss-framing
      pricingHTML += '<div class="pricing-card-date-section">';
      pricingHTML += '<div class="pricing-card-order-by-label ' + tier.cls + '">Order By</div>';
      pricingHTML += '<div class="pricing-card-order-by-divider"></div>';
      pricingHTML += '<div class="pricing-card-month">' + cutoffMonth + '</div>';
      pricingHTML += '<div class="pricing-card-day">' + cutoffDay + '</div>';
      pricingHTML += '<div class="pricing-card-weekday">' + cutoffWeekday + '</div>';
      pricingHTML += '</div>';
      pricingHTML += '<div class="pricing-card-divider"></div>';
      pricingHTML += '<div class="pricing-card-action-section">';
      pricingHTML += '<div class="pricing-card-price ' + tier.cls + '">' + priceText + '</div>';

      // Loss-framing subline: active card uses the immediately-next tier delta
      // (the realistic counterfactual) but the wording stays implicit — most
      // customers naturally read "You save $15 today" against the next card.
      // Future cards show their own per-tier cost-to-wait below.
      if (isBest && bestAvailableTier) {
        var bestIdx = config.tiers.findIndex(function (t) { return t.key === bestAvailableTier.key; });
        if (bestIdx >= 0 && bestIdx < config.tiers.length - 1) {
          var nextTier = config.tiers[bestIdx + 1];
          var nextPrice = row[nextTier.key] || 0;
          var savings = Math.round(nextPrice - priceValue);
          if (savings > 0) {
            pricingHTML += '<div class="pricing-card-savings-active ' + tier.cls + '">You save $' + savings + ' today</div>';
          }
        }
      } else if (!isBest && bestAvailableTier) {
        var extra = Math.round(priceValue - bestAvailablePrice);
        if (extra > 0) {
          pricingHTML += '<div class="pricing-card-savings-future">$' + extra + ' more if you wait</div>';
        }
      }

      pricingHTML += '</div>';
    }

    pricingHTML += '</div>'; // close .pricing-card-body

    // Footer — order-by for active, empty for expired
    if (isUnavailable) {
      pricingHTML += '<div class="pricing-card-footer pricing-card-footer-expired"><div class="pricing-card-cutoff">&nbsp;</div></div>';
    } else {
      pricingHTML += '<div class="pricing-card-footer">';
      pricingHTML += '<div class="pricing-card-cutoff">Cut-off ' + cutoffTimeStr + ' (EST)</div>';
      pricingHTML += '</div>';
    }

    pricingHTML += '</div>'; // close .pricing-card
  });

  // Row-level "Prices if you order later" bracket — spans across future tiers.
  // The "Your Price Today" pill lives inside the active card itself (above).
  if (bestAvailableTier) {
    var activeIdx = config.tiers.findIndex(function(t) { return t.key === bestAvailableTier.key; });
    if (activeIdx >= 0 && activeIdx < config.tiers.length - 1) {
      pricingHTML += '<div class="pricing-row-ribbon pricing-row-ribbon-wait">';
      pricingHTML += '<span class="wait-line wait-line-left"></span>';
      pricingHTML += '<span class="wait-text">Prices if you order later</span>';
      pricingHTML += '<span class="wait-line wait-line-right"></span>';
      pricingHTML += '</div>';
    }
  }

  pricingHTML += '</div>'; // close .pricing-cards-row

  // Unified countdown timer (below all cards) — wrapped so the triangle pointer
  // can position itself relative to the active card above.
  if (bestAvailableTier) {
    pricingHTML += '<div class="pricing-countdown-wrapper">';
    pricingHTML += '<div class="pricing-countdown-pointer ' + bestAvailableTier.cls + '" id="pricingCountdownPointer"></div>';
    pricingHTML += '<div class="pricing-countdown ' + bestAvailableTier.cls + '" id="pricingCountdown">Lock in your <strong>' + bestAvailableTier.label + '</strong> rate &mdash; calculating...</div>';
    pricingHTML += '</div>';
  }

  if (elements.pricing) {
    elements.pricing.innerHTML = pricingHTML;
  }

  if (bestAvailableTier) {
    startCountdown(bestAvailableTier, inDate);
    // rAF ensures layout is flushed before we measure card rects
    requestAnimationFrame(positionPricingRibbons);
  }
}

// Position the "Prices if you order later" bracket and the countdown
// triangle pointer — both anchored to the active card's bounding rect.
// (The "Your Price Today" pill is now inside the active card itself,
// no JS positioning needed.)
function positionPricingRibbons() {
  var row = document.querySelector('.pricing-cards-row');
  var activeCard = document.querySelector('.pricing-card.best-available');
  var waitRibbon = document.querySelector('.pricing-row-ribbon-wait');
  var pointer = document.getElementById('pricingCountdownPointer');

  if (!row || !activeCard) return;

  var rowRect = row.getBoundingClientRect();
  var cardRect = activeCard.getBoundingClientRect();
  var cardRightInRow = cardRect.right - rowRect.left;

  // Stretch "Prices if you order later" ribbon from active card's right edge
  // to the row's right edge. Hide on mobile (multi-row grid layout).
  if (waitRibbon) {
    var isMultiRow = window.matchMedia('(max-width: 600px)').matches;
    if (isMultiRow) {
      waitRibbon.style.display = 'none';
    } else {
      var gap = 10;
      var leftPos = cardRightInRow + gap;
      var waitWidth = rowRect.width - leftPos - 4;
      if (waitWidth > 200) {
        waitRibbon.style.left = leftPos + 'px';
        waitRibbon.style.width = waitWidth + 'px';
        waitRibbon.style.display = '';
      } else {
        waitRibbon.style.display = 'none';
      }
    }
  }

  // Countdown triangle pointer — centered under active card, relative to countdown wrapper
  if (pointer) {
    var wrapper = pointer.parentElement;
    if (wrapper) {
      var wrapperRect = wrapper.getBoundingClientRect();
      var cardCenterInWrapper = cardRect.left + (cardRect.width / 2) - wrapperRect.left;
      pointer.style.left = cardCenterInWrapper + 'px';
    }
  }
}

// Reposition ribbons/pointer on resize (throttled)
if (typeof window !== 'undefined') {
  var pricingRibbonResizeTimer = null;
  window.addEventListener('resize', function() {
    if (pricingRibbonResizeTimer) clearTimeout(pricingRibbonResizeTimer);
    pricingRibbonResizeTimer = setTimeout(positionPricingRibbons, 100);
  });
}

// Toast notification for tier expiry
function showPricingToast(expiredTierLabel, newTierLabel) {
  var existingToast = document.querySelector('.pricing-toast');
  if (existingToast) existingToast.remove();
  
  var toast = document.createElement('div');
  toast.className = 'pricing-toast';
  if (newTierLabel) {
    toast.innerHTML = 'The <strong>' + expiredTierLabel + '</strong> rate has expired. Your price has been updated to the <strong>' + newTierLabel + '</strong> rate.';
  } else {
    toast.innerHTML = 'The <strong>' + expiredTierLabel + '</strong> rate has expired. No more delivery options available for this date.';
  }
  document.body.appendChild(toast);
  
  requestAnimationFrame(function() {
    toast.classList.add('show');
  });
  
  setTimeout(function() {
    toast.classList.remove('show');
    setTimeout(function() { toast.remove(); }, 300);
  }, 5000);
}

function startCountdown(tier, inDate) {
  const countdown = document.getElementById('pricingCountdown');
  const deliveryTimeValue = state.selectedDeliveryTime || 'anytime';

  if (countdown) {
    countdown.classList.add('show');

    function updateCountdownDisplay() {
      const targetTime = getCutoffDate(inDate, tier.lead, tier.cutoffHour, deliveryTimeValue).getTime();
      const now = new Date().getTime();
      const timeLeft = targetTime - now;

      if (timeLeft <= 0) {
        clearInterval(state.countdownInterval);
        state.tierExpiredByCountdown = true;
        updateDeliveryTimeOptions();
        setTimeout(updatePricing, 100);
        return;
      }

      const countdownText = 'Lock in your <strong>' + tier.label + '</strong> rate &mdash; ' + formatCountdown(timeLeft) + ' remaining';
      const minutesLeft = timeLeft / (1000 * 60);

      var colorClass = '';
      if (minutesLeft <= config.countdownCriticalMin) {
        colorClass = 'countdown-critical';
      } else if (minutesLeft <= config.countdownWarningMin) {
        colorClass = 'countdown-warning';
      }

      countdown.innerHTML = countdownText;
      countdown.classList.remove('countdown-warning', 'countdown-critical');
      if (colorClass) countdown.classList.add(colorClass);
    }

    updateCountdownDisplay();
    state.countdownInterval = setInterval(updateCountdownDisplay, 1000);
  }
}


function formatCountdown(timeLeft) {
  const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
  const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
  const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));
  const seconds = Math.floor((timeLeft % (1000 * 60)) / 1000);
  
  if (days > 0) {
    return `${days}d ${hours}h ${minutes}m ${seconds}s`;
  } else if (hours > 0) {
    return `${hours}h ${minutes}m ${seconds}s`;
  } else if (minutes > 0) {
    return `${minutes}m ${seconds}s`;
  } else {
    return `${seconds}s`;
  }
}

// ===== UTILITY FUNCTIONS =====

// ===== DELIVERY TIME FUNCTIONS =====
// A delivery time option is available if its production deadline is still in the
// future. This uses the same getProductionDeadline() function that drives pricing,
// so the logic is guaranteed to be consistent between tier availability and time
// option availability.
function isDeliveryTimeAvailable(timeOption, selectedDate) {
  if (!selectedDate) return true;

  var now = new Date();
  var deliveryDate = parseSelectedDate(selectedDate);
  var today = new Date();
  today.setHours(0, 0, 0, 0);
  var deliveryMidnight = new Date(deliveryDate);
  deliveryMidnight.setHours(0, 0, 0, 0);

  // Guard against past dates
  if (deliveryMidnight < today) return false;

  var productionDeadline = getProductionDeadline(deliveryDate, timeOption.value);
  return productionDeadline > now;
}

// Check if a date should be disabled in the date picker
function isDateAvailable(dateString) {
  // Check if ANY delivery time is available for this date
  var hasAvailable = false;
  config.deliveryTimeOptions.forEach(function(option) {
    if (isDeliveryTimeAvailable(option, dateString)) {
      hasAvailable = true;
    }
  });
  return hasAvailable;
}

function updateDeliveryTimeOptions() {
  var deliveryTimeSelect = elements.deliveryTime;
  if (!deliveryTimeSelect) return;
  var selectedDate = state.selectedDate;
  var anyAvailable = false;
  
  config.deliveryTimeOptions.forEach(function(option) {
    var optionElement = deliveryTimeSelect.querySelector('option[value="' + option.value + '"]');
    if (optionElement) {
      var isAvailable = isDeliveryTimeAvailable(option, selectedDate);
      // Hide unavailable options entirely instead of greying them out —
      // cleaner dropdown with only actionable choices, zero confusion.
      optionElement.style.display = isAvailable ? '' : 'none';
      optionElement.disabled = !isAvailable;
      optionElement.textContent = option.label;
      if (isAvailable) anyAvailable = true;
    }
  });
  
  // If no delivery times available, show message
  if (!anyAvailable && selectedDate) {
    // Show "no delivery times available" state
    var noTimeOption = deliveryTimeSelect.querySelector('option[value="none"]');
    if (!noTimeOption) {
      noTimeOption = document.createElement('option');
      noTimeOption.value = 'none';
      noTimeOption.textContent = 'No times available';
      noTimeOption.disabled = true;
      deliveryTimeSelect.appendChild(noTimeOption);
    }
    deliveryTimeSelect.value = 'none';
    state.selectedDeliveryTime = null;
  } else {
    // Remove the "none" option if it exists
    var noTimeOption = deliveryTimeSelect.querySelector('option[value="none"]');
    if (noTimeOption) noTimeOption.remove();
  }
  
  // Auto-select first available if current selection is unavailable
  var currentOption = config.deliveryTimeOptions.find(function(opt) { return opt.value === state.selectedDeliveryTime; });
  if (!currentOption || !isDeliveryTimeAvailable(currentOption, selectedDate)) {
    var firstAvailable = config.deliveryTimeOptions.find(function(opt) { return isDeliveryTimeAvailable(opt, selectedDate); });
    if (firstAvailable) {
      state.selectedDeliveryTime = firstAvailable.value;
      deliveryTimeSelect.value = firstAvailable.value;
    }
  }
  
  // Update pricing whenever delivery time options change (affects tier selection)
  updatePricingVisibility();
}

function handleDeliveryTimeChange() {
  if (elements.deliveryTime) {
    state.selectedDeliveryTime = elements.deliveryTime.value;
    // Delivery time affects which pricing tier applies, so update everything
    updatePricingVisibility();
    updateOrderSummary();
    updateSubmitButtonState();
  }
}

function getDeliveryTimeDisplay(timeValue) {
  if (!timeValue || timeValue === 'anytime') return 'at anytime';
  var option = config.deliveryTimeOptions.find(function(opt) { return opt.value === timeValue; });
  if (option) return 'at ' + option.label.replace('By ', '');
  return '';
}

function parseSelectedDate(dateString) {
  return new Date(dateString + 'T00:00:00');
}

function updatePricingSizeDisplay() {
  const sizeDisplay = document.getElementById('pricingSizeDisplay');
  if (!sizeDisplay) return;
  
  const width = state.dimensions.width;
  const height = state.dimensions.height;
  
  if (width && height) {
    sizeDisplay.innerHTML = '<span class="size-divider"> | </span><span class="size-text">Poster Size ' + width + '" x ' + height + '"</span>';
  } else {
    sizeDisplay.innerHTML = '';
  }
}

function update() {
  updateOrderSummary();
  updatePricingVisibility();
  updatePricingSizeDisplay();
  updateMaterialDelta();
  refreshFileDimensionDisplay();
}

// ===== EVENT BINDING =====
function bindEvents() {
  
  // Main form elements
  if (elements.eventSelect) {
    elements.eventSelect.addEventListener('change', handleEventSelection);
  } else {
    console.error('âŒ Event select element not found');
  }
  
  if (elements.width) {
    elements.width.addEventListener('input', handleDimensionChange);
  } else {
    console.error('âŒ Width element not found');
  }
  
  if (elements.height) {
    elements.height.addEventListener('input', handleDimensionChange);
  } else {
    console.error('âŒ Height element not found');
  }
  
  if (elements.date) {
    elements.date.addEventListener('change', handleDateChange);
  } else {
    console.error('âŒ Date element not found - looking for ID "d"');
  }
  
  // Date display click functionality - Enhanced
  const dateContainer = document.querySelector('.date-input-clickable');
  if (dateContainer && elements.date) {
    dateContainer.addEventListener('click', function(e) {
      e.preventDefault();
      if (flatpickrInstance) { flatpickrInstance.open(); }
      else { elements.date.focus(); if (elements.date.showPicker) elements.date.showPicker(); }
    });
  } else {
    console.error('âŒ Date container or date element not found', { 
      container: !!dateContainer, 
      dateElement: !!elements.date 
    });
  }
  
  if (elements.dateDisplay) {
    elements.dateDisplay.addEventListener('click', function(e) {
      e.stopPropagation();
      e.preventDefault();
      if (flatpickrInstance) { flatpickrInstance.open(); }
      else if (elements.date) { elements.date.focus(); if (elements.date.showPicker) elements.date.showPicker(); }
    });
  }
  
  // Make the entire date field container clickable
  if (elements.date) {
    const dateField = elements.date.closest('.field');
    if (dateField) {
      dateField.addEventListener('click', function(e) {
        if (e.target !== elements.date) {
          e.preventDefault();
          elements.date.focus();
          if (elements.date.showPicker) {
            elements.date.showPicker();
          }
        }
      });
    }
  }
  
  // Delivery time select
  if (elements.deliveryTime) {
    elements.deliveryTime.addEventListener('change', handleDeliveryTimeChange);
  }
  
  // Popular size buttons
  document.querySelectorAll('.popular-size-button-card').forEach(function(button) {
    button.addEventListener('click', function() {
      const width = parseInt(this.dataset.width);
      const height = parseInt(this.dataset.height);
      selectPopularSize(width, height);
    });
  });
  
  // Delivery options
  document.querySelectorAll('.delivery-option:not(.disabled)').forEach(function(option) {
    option.addEventListener('click', function() {
      const deliveryType = this.dataset.option;
      if (deliveryType) {
        selectDeliveryOption(deliveryType);
      }
    });
  });
  
  // Customer info fields
  const customerName = document.getElementById('customerName');
  const customerEmail = document.getElementById('customerEmail');
  
  if (customerName) {
    customerName.addEventListener('input', updateSubmitButtonState);
  }
  if (customerEmail) {
    customerEmail.addEventListener('input', updateSubmitButtonState);
  }
  
  // Phone input with validation
  if (elements.customerPhone) {
    elements.customerPhone.addEventListener('input', function(e) {
      if (selectedCountry.code !== 'OTHER') {
        const formatted = formatPhoneNumber(e.target.value, selectedCountry.code);
        if (formatted !== e.target.value) {
          e.target.value = formatted;
        }
      }
      
      clearTimeout(elements.customerPhone.validationTimeout);
      elements.customerPhone.validationTimeout = setTimeout(function() {
        validatePhoneNumber(true);
        updateSubmitButtonState();
      }, 300);
    });
    
    elements.customerPhone.addEventListener('blur', function() {
      validatePhoneNumber(true);
      updateSubmitButtonState();
    });
  }
  
  // Address form fields
  const addressFields = ['deliveryAttn', 'deliveryAddress', 'deliveryCity', 'deliveryProvince', 'deliveryPostal'];
  addressFields.forEach(function(fieldId) {
    const field = document.getElementById(fieldId);
    if (field) {
      field.addEventListener('input', updateSubmitButtonState);
    }
  });
  
  // Material toggle
  if (elements.materialToggle) {
    elements.materialToggle.addEventListener('click', toggleMaterial);
  }

  // Pricing header material toggle (synced duplicate)
  var pricingMatToggle = document.getElementById('materialTogglePricing');
  if (pricingMatToggle) {
    pricingMatToggle.addEventListener('click', toggleMaterial);
  }

  // File upload
 // File upload - IMPROVED binding
  if (elements.uploadZone) {
    elements.uploadZone.addEventListener('click', function(e) {
      // Only trigger file picker if clicking on upload zone itself, not on buttons
      if (!e.target.closest('button') && !e.target.closest('.file-preview-display') && elements.fileInput) {
        elements.fileInput.click();
      }
    });
    
    elements.uploadZone.addEventListener('dragover', function(e) {
      e.preventDefault();
      elements.uploadZone.classList.add('dragover');
    });
    
    elements.uploadZone.addEventListener('dragleave', function(e) {
      elements.uploadZone.classList.remove('dragover');
    });
    
    elements.uploadZone.addEventListener('drop', function(e) {
      e.preventDefault();
      elements.uploadZone.classList.remove('dragover');
      
      // Set files on the actual file input so form submission works
      if (elements.fileInput && e.dataTransfer.files.length > 0) {
        try {
          elements.fileInput.files = e.dataTransfer.files;
        } catch (err) {
          // Fallback for browsers that don't support setting files directly
          const dt = new DataTransfer();
          dt.items.add(e.dataTransfer.files[0]);
          elements.fileInput.files = dt.files;
        }
      }
      
      handleFiles(e.dataTransfer.files);
    });
  }
  
  if (elements.fileInput) {
    // CRITICAL: Only bind once and preserve the binding
    elements.fileInput.addEventListener('change', function(e) {
      handleFiles(e.target.files);
    });
  }
  
  // Form submission handler - Stripe Checkout Integration
  const orderForm = document.getElementById('orderForm');
  if (orderForm) {
    orderForm.addEventListener('submit', async function(e) {
      // Always prevent default - we'll handle submission via fetch
      e.preventDefault();
      
      
      // First check if form is valid
      if (elements.submitButton && elements.submitButton.disabled) {
        return false;
      }
      
      try {
        // Show loading state on submit button
        if (elements.submitButton) {
          elements.submitButton.innerHTML = '<div class="submit-button-content"><span class="submit-button-title">Processing...</span><span class="submit-button-subtitle">Preparing checkout</span></div>';
          elements.submitButton.disabled = true;
        }
        
        // Calculate pricing data
        const costData = calculateOrderTotal();
        
        // Create FormData from the form
        const formData = new FormData(orderForm);
        
        // Add pricing data
        if (costData && typeof costData === 'object') {
          formData.set('basePrice', costData.basePrice || 0);
          formData.set('deliveryFee', costData.deliveryFee || 0);
          formData.set('subtotal', costData.subtotal || 0);
          formData.set('tax', costData.tax || 0);
          formData.set('total', costData.total || 0);
          formData.set('tier', costData.tier ? costData.tier.label : 'Standard');
        }
        
        // Add additional data
        formData.set('deliveryOption', state.deliveryOption || 'pickup');
        formData.set('eventAcronym', state.selectedEvent?.acronym || '');
        formData.set('eventName', state.selectedEvent?.name || '');
        formData.set('countryCode', selectedCountry?.dialCode || '');
        formData.set('selectedDate', state.selectedDate || '');
        formData.set('deliveryTime', state.selectedDeliveryTime || 'anytime');
        formData.set('width', state.dimensions.width || '');
        formData.set('height', state.dimensions.height || '');
        formData.set('material', state.selectedMaterial || 'paper');
        
        
        // Send to create-checkout-session.php
        const response = await fetch('create-checkout-session.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.checkoutUrl) {
          // Clear saved form state — order is being submitted
          clearFormState();
          // Redirect to Stripe Checkout
          window.location.href = result.checkoutUrl;
        } else {
          throw new Error(result.error || 'Failed to create checkout session');
        }
        
      } catch (error) {
        console.error('Error creating checkout session:', error);
        
        // Reset button state
        if (elements.submitButton) {
          elements.submitButton.innerHTML = '<div class="submit-button-content"><span class="submit-button-title">Proceed to Payment</span><span class="submit-button-subtitle">Ready to checkout!</span></div>';
          elements.submitButton.disabled = false;
        }
        
        alert('There was an error processing your order: ' + error.message + '\n\nPlease try again or contact support.');
        return false;
      }
    });
  }
  
}

// ===== INITIALIZATION =====
function initializeElements() {
  
  elements.eventSelect = document.getElementById('eventSelect');
  elements.width = document.getElementById('w');
  elements.height = document.getElementById('h');
  elements.date = document.getElementById('d');
  elements.dateDisplay = document.getElementById('dateDisplay');
  elements.uploadZone = document.getElementById('uploadZone');
  elements.fileInput = document.getElementById('fileInput');
  elements.customerPhone = document.getElementById('customerPhone');
  elements.submitButton = document.querySelector('.submit-button');
  elements.pricing = document.getElementById('pricing');
  elements.materialToggle = document.getElementById('materialToggle');
  elements.posterOption = document.getElementById('posterOption');
  elements.fabricOption = document.getElementById('fabricOption');
  elements.materialSlider = document.getElementById('materialSlider');
  elements.deliveryTime = document.getElementById('deliveryTime');
  
}

// ===== DEBUG FUNCTIONS =====
function debugFormState() {
  
  // Check all required fields
  const requiredFields = ['customerName', 'customerEmail', 'customerPhone'];
  requiredFields.forEach(fieldId => {
    const field = document.getElementById(fieldId);
  });
  
}

function validateFormCompleteness() {
  const issues = [];
  
  if (!state.selectedEvent) issues.push('No event selected');
  if (!state.selectedDate) issues.push('No date selected');
  if (!state.dimensions.width || !state.dimensions.height) issues.push('Invalid dimensions');
  if (!state.uploadedFile) issues.push('No file uploaded');
  if (!state.deliveryOption) issues.push('No delivery option selected');
  
  const customerName = document.getElementById('customerName')?.value?.trim();
  const customerEmail = document.getElementById('customerEmail')?.value?.trim();
  const phoneValid = validatePhoneNumber();
  
  if (!customerName) issues.push('Missing customer name');
  if (!customerEmail) issues.push('Missing customer email');
  if (!phoneValid) issues.push('Invalid phone number');
  
  if (state.deliveryOption === 'office') {
    const deliveryAttn = document.getElementById('deliveryAttn')?.value?.trim();
    const deliveryAddress = document.getElementById('deliveryAddress')?.value?.trim();
    const deliveryCity = document.getElementById('deliveryCity')?.value?.trim();
    const deliveryProvince = document.getElementById('deliveryProvince')?.value?.trim();
    const deliveryPostal = document.getElementById('deliveryPostal')?.value?.trim();
    
    if (!deliveryAttn) issues.push('Missing delivery attention');
    if (!deliveryAddress) issues.push('Missing delivery address');
    if (!deliveryCity) issues.push('Missing delivery city');
    if (!deliveryProvince) issues.push('Missing delivery province');
    if (!deliveryPostal) issues.push('Missing delivery postal code');
  }
  
  return issues;
}

function forceUpdateAll() {
  updateDateDisplay();
  generateAlternativeSizes();
  updatePricingVisibility();
  update();
  updateSubmitButtonState();
  
}

// DEBUGGING FUNCTION FOR PRICING
function debugPricing() {
  
  const pricingData = getCurrentPricingData(state.selectedMaterial);
  
  const area = state.dimensions.width * state.dimensions.height;
  const row = pricingData.find(r => area >= r.min && area <= r.max);
  
  if (row) {
    config.tiers.forEach(tier => {
    });
  }
  
}

// ===== MAKE FUNCTIONS GLOBAL =====
window.selectPopularSize = selectPopularSize;
window.selectDeliveryOption = selectDeliveryOption;
window.removeFile = removeFile;
window.toggleMaterial = toggleMaterial;
window.debugFormState = debugFormState;
window.validateFormCompleteness = validateFormCompleteness;
window.forceUpdateAll = forceUpdateAll;
window.debugPricing = debugPricing;
window.handleDeliveryTimeChange = handleDeliveryTimeChange;
window.updateDeliveryTimeOptions = updateDeliveryTimeOptions;

// ===== APPLICATION STARTUP =====
document.addEventListener('DOMContentLoaded', function() {
  
  async function init() {
    try {
      
      // Initialize elements first
      initializeElements();
      
      // Initialize country selector
      initializeCountrySelector();
      
      // Load both events and pricing in parallel
      const [eventsLoaded, pricingLoaded] = await Promise.all([
        loadEventsFromServer(),
        loadPricingFromServer()
      ]);
      
      if (pricingLoaded) {
      } else {
      }
      
      if (eventsLoaded) {
      } else {
      }
      
      // Bind all events
      bindEvents();
      
      // Initialize display states
      updateMaterialDisplay();
      updateDateDisplay();
      updateDeliveryTimeOptions();
      updateDatePickerRestrictions();
      generateAlternativeSizes();
      updatePricingVisibility();
      update();
      updateSubmitButtonState();
      
      // Set current year if element exists
      const yearElement = document.getElementById('yr');
      if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
      }

      // Restore saved form state (localStorage) after everything is initialized
      restoreFormState();

    } catch (error) {
      console.error('??  Error during initialization:', error);
    }
  }
  
  // Start initialization
  init();

  // ===== STALE PRICING REFRESH =====
  // If the customer leaves the tab open for a long time, pricing data could
  // go stale (tier expires, countdown shows wrong time). Refresh pricing data
  // every 5 minutes AND when the tab regains focus.
  var PRICING_REFRESH_INTERVAL = 5 * 60 * 1000; // 5 minutes
  setInterval(function () {
    if (state.pricingLoaded && state.selectedDate) {
      updateDeliveryTimeOptions();
    }
  }, PRICING_REFRESH_INTERVAL);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && state.pricingLoaded && state.selectedDate) {
      updateDeliveryTimeOptions();
    }
  });
});

// ===== FORM STATE PERSISTENCE (localStorage) =====
// Saves key form fields so customers can resume after accidental tab close.
// State expires after 24 hours and is cleared on successful form submission.

var FORM_STORAGE_KEY = 'mtcc_order_form';
var FORM_STORAGE_TTL = 24 * 60 * 60 * 1000; // 24 hours

function saveFormState() {
  try {
    var data = {
      eventValue: document.getElementById('eventSelect') ? document.getElementById('eventSelect').value : '',
      date: state.selectedDate || '',
      deliveryTime: state.selectedDeliveryTime || 'anytime',
      width: state.dimensions.width || '',
      height: state.dimensions.height || '',
      material: state.selectedMaterial || 'poster',
      deliveryOption: state.deliveryOption || '',
      customerName: (document.getElementById('customerName') || {}).value || '',
      customerEmail: (document.getElementById('customerEmail') || {}).value || '',
      customerPhone: (document.getElementById('customerPhone') || {}).value || '',
      customerCompany: (document.getElementById('customerCompany') || {}).value || '',
      additionalNotes: (document.getElementById('additionalNotes') || {}).value || '',
      savedAt: Date.now()
    };
    localStorage.setItem(FORM_STORAGE_KEY, JSON.stringify(data));
  } catch (e) { /* localStorage unavailable or full — silently fail */ }
}

function restoreFormState() {
  try {
    var raw = localStorage.getItem(FORM_STORAGE_KEY);
    if (!raw) return;
    var data = JSON.parse(raw);

    // Expire after 24 hours
    if (Date.now() - data.savedAt > FORM_STORAGE_TTL) {
      localStorage.removeItem(FORM_STORAGE_KEY);
      return;
    }

    // Restore event selection
    var eventSelect = document.getElementById('eventSelect');
    if (data.eventValue && eventSelect) {
      eventSelect.value = data.eventValue;
      eventSelect.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // Restore date (after short delay so event change propagates first)
    setTimeout(function () {
      if (data.date) {
        var dateInput = document.getElementById('d');
        if (dateInput) {
          dateInput.value = data.date;
          dateInput.dispatchEvent(new Event('change', { bubbles: true }));
        }
      }

      // Restore delivery time
      if (data.deliveryTime) {
        var timeSelect = document.getElementById('deliveryTime');
        if (timeSelect) {
          timeSelect.value = data.deliveryTime;
          state.selectedDeliveryTime = data.deliveryTime;
        }
      }

      // Restore dimensions
      if (data.width) {
        var wInput = document.getElementById('w');
        if (wInput) { wInput.value = data.width; wInput.dispatchEvent(new Event('input', { bubbles: true })); }
      }
      if (data.height) {
        var hInput = document.getElementById('h');
        if (hInput) { hInput.value = data.height; hInput.dispatchEvent(new Event('input', { bubbles: true })); }
      }

      // Restore material
      if (data.material && data.material !== state.selectedMaterial) {
        toggleMaterial(data.material);
      }

      // Restore delivery option
      if (data.deliveryOption) {
        selectDeliveryOption(data.deliveryOption);
      }

      // Restore customer info fields
      var fields = {
        customerName: data.customerName,
        customerEmail: data.customerEmail,
        customerPhone: data.customerPhone,
        customerCompany: data.customerCompany,
        additionalNotes: data.additionalNotes
      };
      Object.keys(fields).forEach(function (id) {
        if (fields[id]) {
          var el = document.getElementById(id);
          if (el) el.value = fields[id];
        }
      });

      // Trigger full state update
      updatePricingVisibility();
      updateSubmitButtonState();
    }, 200);

  } catch (e) { /* silently fail */ }
}

function clearFormState() {
  try { localStorage.removeItem(FORM_STORAGE_KEY); } catch (e) {}
}

// Auto-save on any form field change (debounced)
(function () {
  var saveTimer = null;
  function debouncedSave() {
    if (saveTimer) clearTimeout(saveTimer);
    saveTimer = setTimeout(saveFormState, 1000);
  }
  document.addEventListener('change', debouncedSave);
  document.addEventListener('input', debouncedSave);
})();

// ===== SOCIAL PROOF POPUP =====
// Loads recent customer orders + fallback nudges from recent-orders.php and
// shows them as bottom-left toast popups at intervals. Desktop only.
// Capped at 4 popups per session to avoid annoyance.
(function () {
  if (typeof window === 'undefined' || typeof document === 'undefined') return;

  // Skip on mobile — bottom-left popup conflicts with mobile chrome
  if (window.matchMedia && window.matchMedia('(max-width: 600px)').matches) return;

  var SP = {
    orders: [],
    stats: [],
    loaded: false,
    shownCount: 0,
    maxShown: 3,
    initialDelay: 7000,   // first popup within 7s of page load
    interval: 90000,      // subsequent popups every 90s
    holdTime: 15000       // each popup visible for 15s
  };

  function loadSocialProof() {
    fetch('recent-orders.php')
      .then(function (r) { return r.json(); })
      .then(function (data) {
        SP.orders = (data && data.orders) || [];
        SP.stats = (data && data.stats) || [];
        SP.loaded = true;
        scheduleNext(SP.initialDelay);
      })
      .catch(function () { /* silently fail — no popup is fine */ });
  }

  function scheduleNext(delay) {
    if (SP.shownCount >= SP.maxShown) return;
    setTimeout(function () {
      showPopup();
      scheduleNext(SP.interval);
    }, delay);
  }

  function showPopup() {
    var content = pickContent();
    if (!content) return;

    // Remove any existing toast first
    var existing = document.querySelector('.social-proof-toast');
    if (existing) existing.remove();

    var toast = document.createElement('div');
    toast.className = 'social-proof-toast';
    toast.innerHTML =
      '<div class="sp-toast-icon">&#10004;</div>' +
      '<div class="sp-toast-body">' + content + '</div>' +
      '<button class="sp-toast-close" aria-label="Dismiss">&times;</button>';

    toast.querySelector('.sp-toast-close').addEventListener('click', function () {
      hidePopup(toast);
    });

    document.body.appendChild(toast);
    SP.shownCount++;

    // Trigger entrance animation on next frame
    requestAnimationFrame(function () { toast.classList.add('show'); });

    // Auto-dismiss after hold time
    setTimeout(function () { hidePopup(toast); }, SP.holdTime);
  }

  function hidePopup(toast) {
    if (!toast) return;
    toast.classList.remove('show');
    setTimeout(function () { if (toast.parentNode) toast.remove(); }, 400);
  }

  function pickContent() {
    var orders = SP.orders;
    var stats = SP.stats;

    // 3+ real orders → only show real orders, cycling
    if (orders.length >= 3) {
      return formatOrder(orders[SP.shownCount % orders.length]);
    }

    // 1-2 real orders → alternate between real and stats
    if (orders.length > 0) {
      if (SP.shownCount % 2 === 0) {
        return formatOrder(orders[SP.shownCount % orders.length]);
      }
    }

    // Fallback to stats (also used when 0 real orders)
    if (stats.length === 0) return null;
    return '<em>' + stats[SP.shownCount % stats.length] + '</em>';
  }

  function formatOrder(order) {
    if (!order) return null;
    var html = '<strong>' + escapeHtml(order.name) + '</strong> ordered a ';
    html += '<strong>' + escapeHtml(order.dimensions) + '</strong> poster';
    if (order.event) {
      html += ' for <strong>' + escapeHtml(order.event) + '</strong>';
    }
    html += ' &mdash; <span class="sp-time">' + escapeHtml(order.relativeTime) + '</span>';
    return html;
  }

  function escapeHtml(str) {
    if (typeof str !== 'string') return '';
    return str
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // Init when page is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loadSocialProof);
  } else {
    loadSocialProof();
  }
})();
