// MTCC Poster Order Form - Complete Script
// Version: v33 - Delivery Time Enforcement, Weekend Rules, Countdown Escalation
// Fixed: Delivery time gates, weekend pricing rules, date picker restrictions, timer colors

// ===== GLOBAL CONFIGURATION =====
const config = {
  maxFileSize: 100 * 1024 * 1024, // 100MB
  allowedTypes: ['.pdf', '.ai', '.eps', '.psd', '.png', '.jpg', '.jpeg', '.tiff', '.tif', '.webp', '.gif', '.bmp', '.svg', '.pptx', '.indd'],
  freeConversionTypes: ['.pdf'],
  conversionFee: 5.00,
  taxRate: 0.13,
  // SIMPLIFIED: Removed timezone objects, using simple hour values
  tiers: [
    { key: 'early', cls: 'early', icon: '&#128077;', label: 'Early', days: '10+ Days', lead: 10, cutoffHour: 17 }, // 5:00pm
    { key: 'standard', cls: 'standard', icon: '&#128197;', label: 'Standard', days: '5 Days', lead: 5, cutoffHour: 17 }, // 5:00pm
    { key: '3days', cls: 'rush', icon: '&#127939;', label: 'Rush', days: '3 Days', lead: 3, cutoffHour: 17 }, // 5:00pm
    { key: '2days', cls: 'urgent', icon: '&#128293;', label: 'Urgent', days: '2 Days', lead: 2, cutoffHour: 17 }, // 5:00pm
    { key: 'nextday', cls: 'critical', icon: '&#128680;', label: 'Critical', days: 'Next Day', lead: 1, cutoffHour: 15 }, // 3:00pm
    { key: 'sameday', cls: 'lastminute', icon: '&#128128;', label: 'Last Minute', days: 'Same Day', lead: 0, cutoffHour: 15 } // 3:00pm
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
  conversionFee: 0,
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
  conversionFeeRow: null,
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

// Set date picker min (today) and max (event end date)
function updateDatePickerRestrictions() {
  if (!elements.date || !state.selectedEvent) return;
  
  // Min date: today
  var today = new Date();
  var minDate = today.getFullYear() + '-' + 
    String(today.getMonth() + 1).padStart(2, '0') + '-' + 
    String(today.getDate()).padStart(2, '0');
  
  // Max date: event end date
  var maxDate = state.selectedEvent.endDate || '';
  
  elements.date.min = minDate;
  elements.date.max = maxDate;
  
  
  // If current selection is out of range, clear it
  if (state.selectedDate) {
    if (state.selectedDate < minDate || (maxDate && state.selectedDate > maxDate)) {
      elements.date.value = '';
      state.selectedDate = null;
      updateDateDisplay();
    }
  }
}

function clearDatePickerRestrictions() {
  if (!elements.date) return;
  elements.date.min = '';
  elements.date.max = '';
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
function updateStatusBadge(badgeId, isComplete) {
  const badge = document.getElementById(badgeId);
  if (!badge) return;
  
  if (isComplete) {
    badge.classList.add('completed');
    badge.classList.remove('incomplete');
    const icon = badge.querySelector('.status-icon-modern');
    if (icon) icon.textContent = '&#10004;';
  } else {
    badge.classList.add('incomplete');
    badge.classList.remove('completed');
    const icon = badge.querySelector('.status-icon-modern');
    if (icon) icon.textContent = '!';
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
function handleFiles(files) {
  if (files.length === 0) return;
  
  const file = files[0];
  const fileExt = '.' + file.name.split('.').pop().toLowerCase();
  
  if (file.size > config.maxFileSize) {
    alert('File is too large. Maximum size is 100MB.');
    return false;
  }
  
  if (!config.allowedTypes.includes(fileExt)) {
    alert('File format not supported. Please use: ' + config.allowedTypes.join(', '));
    return false;
  }
  
  state.uploadedFile = file;
  
  checkConversionFee(file);
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
  var needsConversion = !config.freeConversionTypes.includes('.' + fileExt);
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

  // Conversion fee row
  var convRow = needsConversion
    ? '<div class="file-detail-row"><span class="file-detail-label">Note:</span><span class="file-detail-value file-detail-conversion">+$' + config.conversionFee.toFixed(2) + ' conversion fee (non-PDF)</span></div>'
    : '';

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
            convRow +
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

  checkConversionFee(null);
  updateOrderSummary();
  updateSubmitButtonState();
}

function checkConversionFee(file) {
  if (!file) {
    state.conversionFee = 0;
    const hiddenConversionFee = document.getElementById('hiddenConversionFee');
    if (hiddenConversionFee) {
      hiddenConversionFee.value = '0';
    }
    if (elements.conversionFeeRow) {
      elements.conversionFeeRow.style.display = 'none';
    }
    return 0;
  }
  
  const fileExt = '.' + file.name.split('.').pop().toLowerCase();
  const needsConversion = !config.freeConversionTypes.includes(fileExt);
  
  if (needsConversion) {
    state.conversionFee = config.conversionFee;
    const hiddenConversionFee = document.getElementById('hiddenConversionFee');
    if (hiddenConversionFee) {
      hiddenConversionFee.value = config.conversionFee.toString();
    }
    if (elements.conversionFeeRow) {
      elements.conversionFeeRow.style.display = 'flex';
    }
  } else {
    state.conversionFee = 0;
    const hiddenConversionFee = document.getElementById('hiddenConversionFee');
    if (hiddenConversionFee) {
      hiddenConversionFee.value = '0';
    }
    if (elements.conversionFeeRow) {
      elements.conversionFeeRow.style.display = 'none';
    }
  }
  
  return state.conversionFee;
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
  const conversionFee = state.conversionFee || 0;
  const subtotal = basePrice + deliveryFee + conversionFee;
  const tax = subtotal * config.taxRate;
  const total = subtotal + tax;
  
  return {
    basePrice: basePrice,
    deliveryFee: deliveryFee,
    conversionFee: conversionFee,
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

// Weekend-aware cutoff date calculation
// Returns the cutoff datetime for a given tier and delivery date
function getCutoffDate(inDate, leadDays, cutoffHour, deliveryTimeValue) {
  const cutoff = new Date(inDate);
  const deliveryDay = cutoff.getDay(); // 0=Sun, 6=Sat
  
  // === SAME-DAY (lead=0) ===
  if (leadDays === 0) {
    // For weekend delivery dates, cutoff is Friday 3 PM
    if (deliveryDay === 0 || deliveryDay === 6) {
      // Walk back to Friday
      while (cutoff.getDay() !== 5) {
        cutoff.setDate(cutoff.getDate() - 1);
      }
      cutoff.setHours(15, 0, 0, 0); // Friday 3 PM
      return cutoff;
    }
    // Monday delivery: check if delivery time is 9am
    if (deliveryDay === 1 && deliveryTimeValue === '9am') {
      // Monday 9am needs Friday production - cutoff is Friday 3 PM
      const friday = new Date(cutoff);
      friday.setDate(friday.getDate() - 3); // Monday - 3 = Friday
      friday.setHours(15, 0, 0, 0);
      return friday;
    }
    // Normal weekday same-day: use the gate hour for the selected delivery time
    // The "hard wall" is 3 PM (cutoffHour=15) - no same-day after 3 PM
    cutoff.setHours(cutoffHour, 0, 0, 0);
    return cutoff;
  }
  
  // === NEXT-DAY (lead=1) ===
  if (leadDays === 1) {
    // For Monday delivery ordered from weekend context
    if (deliveryDay === 1) {
      // Cutoff is Friday 3 PM for Monday next-day
      const friday = new Date(cutoff);
      friday.setDate(friday.getDate() - 3);
      friday.setHours(cutoffHour, 0, 0, 0);
      return friday;
    }
    // Weekend delivery with 1-day lead = not realistic (would be day before = Fri/Sat)
    if (deliveryDay === 0 || deliveryDay === 6) {
      // Walk back to the last weekday before delivery
      const prevDay = new Date(cutoff);
      prevDay.setDate(prevDay.getDate() - 1);
      while (prevDay.getDay() === 0 || prevDay.getDay() === 6) {
        prevDay.setDate(prevDay.getDate() - 1);
      }
      prevDay.setHours(cutoffHour, 0, 0, 0);
      return prevDay;
    }
    // Normal weekday next-day
    cutoff.setDate(cutoff.getDate() - 1);
    // Skip weekends going backwards
    while (cutoff.getDay() === 0 || cutoff.getDay() === 6) {
      cutoff.setDate(cutoff.getDate() - 1);
    }
    cutoff.setHours(cutoffHour, 0, 0, 0);
    return cutoff;
  }
  
  // === 2+ DAY TIERS ===
  let remainingDays = leadDays;
  while (remainingDays > 0) {
    cutoff.setDate(cutoff.getDate() - 1);
    const dow = cutoff.getDay();
    if (dow !== 0 && dow !== 6) {
      remainingDays--;
    }
  }
  cutoff.setHours(cutoffHour, 0, 0, 0);
  return cutoff;
}

// Helper: is a given date a weekend day?
function isWeekendDay(date) {
  const d = date.getDay();
  return d === 0 || d === 6;
}

// Helper: get the previous business day (Friday if Sat/Sun/Mon)
function getPreviousBusinessDay(date) {
  const prev = new Date(date);
  prev.setDate(prev.getDate() - 1);
  while (prev.getDay() === 0 || prev.getDay() === 6) {
    prev.setDate(prev.getDate() - 1);
  }
  return prev;
}

// Determine if a tier should be BLOCKED (greyed out) for a given delivery date + time
// Only blocks tiers when the delivery is close enough that normal lead-time cutoffs
// don't adequately capture the constraint. Far-out deliveries are never blocked here
// (the getCutoffDate expiry mechanism handles those).
function isTierBlockedByDeliveryTime(tierKey, deliveryDate, deliveryTimeValue) {
  const now = new Date();
  const currentHour = now.getHours();
  const deliveryDay = deliveryDate.getDay();
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const deliveryMidnight = new Date(deliveryDate);
  deliveryMidnight.setHours(0, 0, 0, 0);
  const daysDiff = Math.round((deliveryMidnight - today) / (1000 * 60 * 60 * 24));
  const nowDay = now.getDay();
  
  // Same-day tier is never blocked by delivery time rules (only by time-based cutoffs)
  if (tierKey === 'sameday') return false;
  
  // === NEXT-DAY TIER BLOCKING ===
  if (tierKey === 'nextday') {
    // 9am delivery on literal next business day: overnight turnaround not realistic
    // Only block when delivery is genuinely tomorrow (or next Mon from Fri)
    if (deliveryTimeValue === '9am') {
      if (daysDiff <= 1) return true;
      // Friday ordering for Monday 9am: next-day means produce Fri afternoon, deliver Mon 9am
      if (nowDay === 5 && deliveryDay === 1 && daysDiff <= 3) return true;
    }
    // After 3 PM, next-day only blocked for literal next-day delivery
    if (currentHour >= 15 && daysDiff <= 1) return true;
    return false;
  }
  
  // === WEEKEND DELIVERY BLOCKING ===
  // Weekend delivery (Sat/Sun) = only same-day tier available, block everything else
  if (deliveryDay === 0 || deliveryDay === 6) {
    return true;
  }
  
  // === MONDAY RULES ===
  // Monday 9am from the preceding Fri/Sat/Sun: overnight turnaround, only sameday realistic
  if (deliveryDay === 1 && deliveryTimeValue === '9am' && daysDiff > 0 && daysDiff <= 3) {
    return true;
  }
  
  // Ordering from Sat/Sun for the IMMEDIATELY NEXT Monday only (not future Mondays)
  if (deliveryDay === 1 && (nowDay === 0 || nowDay === 6) && daysDiff > 0 && daysDiff <= 2) {
    return true;
  }
  
  // Monday before 9am for same-day Monday delivery
  if (deliveryDay === 1 && nowDay === 1 && currentHour < 9 && daysDiff === 0) {
    return true;
  }
  
  // All other combinations: tier is not blocked
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
      elements.pricing.innerHTML = '<div class="pricing-placeholder"><div class="placeholder-icon">&#128176;</div><div class="placeholder-title">Loading pricing data...</div><div class="placeholder-subtitle">Please wait while we fetch current rates</div></div>';
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
  
  // Find best available tier for countdown
  let bestAvailableTier = null;
  let closestTime = Infinity;
  
  config.tiers.forEach(function(tier) {
    const cutoff = getCutoffDate(inDate, tier.lead, tier.cutoffHour, deliveryTimeValue);
    const isExpired = cutoff <= now;
    const priceValue = row[tier.key] || 0;
    // Check if this tier is blocked by delivery time rules
    const isBlocked = isTierBlockedByDeliveryTime(tier.key, inDate, deliveryTimeValue);
    
    if (!isExpired && !isBlocked && cutoff.getTime() < closestTime && priceValue > 0) {
      closestTime = cutoff.getTime();
      bestAvailableTier = tier;
    }
  });
  
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

  // Delivery date anchor header — full format, plain text
  const fullMonthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  const deliveryMonth = monthNames[inDate.getMonth()];
  const deliveryFullMonth = fullMonthNames[inDate.getMonth()];
  const deliveryDay = inDate.getDate();
  const deliveryYear = inDate.getFullYear();
  const deliveryWeekday = dayNames[inDate.getDay()];
  var deliveryTimeDisplay = deliveryTimeValue === 'anytime' ? '' : ' @ ' + deliveryTimeValue.replace(/^(\d+)(am|pm)$/i, '$1:00 $2');
  // Shortened delivery anchor
  var deliveryShortDisplay = deliveryWeekday.substring(0, 3) + ', ' + deliveryMonth + ' ' + deliveryDay + deliveryTimeDisplay;
  pricingHTML += '<div class="pricing-delivery-anchor">';
  pricingHTML += 'Prices for <strong>' + deliveryShortDisplay + '</strong> delivery &mdash; order earlier for a lower price';
  pricingHTML += ' <span class="pricing-anchor-info" tabindex="0" role="button" aria-label="Pricing info">?</span>';
  pricingHTML += '<div class="pricing-anchor-tooltip">All options deliver on the same date &mdash; the only difference is when you order.</div>';
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
      // Active card — date section + price-only action + submit-by footer
      pricingHTML += '<div class="pricing-card-date-section">';
      pricingHTML += '<div class="pricing-card-month">' + cutoffMonth + '</div>';
      pricingHTML += '<div class="pricing-card-day">' + cutoffDay + '</div>';
      pricingHTML += '<div class="pricing-card-weekday">' + cutoffWeekday + '</div>';
      pricingHTML += '</div>';
      pricingHTML += '<div class="pricing-card-divider"></div>';
      pricingHTML += '<div class="pricing-card-action-section">';
      pricingHTML += '<div class="pricing-card-price ' + tier.cls + '">' + priceText + '</div>';
      pricingHTML += '</div>';
    }

    pricingHTML += '</div>'; // close .pricing-card-body

    // Footer — submit-by for active, empty for expired
    if (isUnavailable) {
      pricingHTML += '<div class="pricing-card-footer pricing-card-footer-expired"><div class="pricing-card-cutoff">&nbsp;</div></div>';
    } else {
      pricingHTML += '<div class="pricing-card-footer">';
      pricingHTML += '<div class="pricing-card-cutoff">Submit by ' + cutoffTimeStr + ' (EST)</div>';
      pricingHTML += '</div>';
    }

    pricingHTML += '</div>'; // close .pricing-card
  });

  pricingHTML += '</div>';

  // Unified countdown timer (below all cards) — tier name + color matched
  if (bestAvailableTier) {
    pricingHTML += '<div class="pricing-countdown ' + bestAvailableTier.cls + '" id="pricingCountdown"><strong>' + bestAvailableTier.label + '</strong> price expires in: calculating...</div>';
  }

  if (elements.pricing) {
    elements.pricing.innerHTML = pricingHTML;
  }

  if (bestAvailableTier) {
    startCountdown(bestAvailableTier, inDate);
  }
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

      const countdownText = '<strong>' + tier.label + '</strong> price expires in: ' + formatCountdown(timeLeft);
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
// Determines if a delivery time option is available for a given date
function isDeliveryTimeAvailable(timeOption, selectedDate) {
  if (!selectedDate) return true;
  
  var now = new Date();
  var deliveryDate = parseSelectedDate(selectedDate);
  var today = new Date();
  today.setHours(0, 0, 0, 0);
  var deliveryMidnight = new Date(deliveryDate);
  deliveryMidnight.setHours(0, 0, 0, 0);
  
  var daysDiff = Math.round((deliveryMidnight - today) / (1000 * 60 * 60 * 24));
  var deliveryDay = deliveryDate.getDay(); // 0=Sun, 6=Sat
  var currentHour = now.getHours();
  var nowDay = now.getDay();
  
  // 2+ days out: all delivery times available (no gate restrictions)
  // But check weekend rules first
  if (daysDiff >= 2) {
    // Weekend delivery date: only available if ordered before Friday 3 PM
    if (deliveryDay === 0 || deliveryDay === 6) {
      return isBeforeFridayCutoff(now, deliveryDate);
    }
    // Monday delivery, 9am: needs Friday production
    if (deliveryDay === 1 && timeOption.value === '9am') {
      return isBeforeFridayCutoff(now, deliveryDate);
    }
    return true;
  }
  
  // Same-day delivery (daysDiff === 0)
  if (daysDiff === 0) {
    // Weekend today: can't order same-day (vendor closed)
    if (nowDay === 0 || nowDay === 6) return false;
    
    // Apply the gate rules
    if (timeOption.gateContext === 'previous_day') {
      // 9am: disabled if past 3 PM previous business day
      // On the delivery day itself, the previous day's 3 PM has always passed
      return false;
    }
    // same_day gate: disabled after gateHour today
    return currentHour < timeOption.gateHour;
  }
  
  // Next-day delivery (daysDiff === 1)
  if (daysDiff === 1) {
    // If tomorrow is weekend: only available if today is Friday before 3 PM
    if (deliveryDay === 0 || deliveryDay === 6) {
      return isBeforeFridayCutoff(now, deliveryDate);
    }
    // Monday delivery from Sunday: check if 9am (needs Friday production, too late)
    if (deliveryDay === 1 && nowDay === 0) {
      if (timeOption.value === '9am') return false; // Can't produce Sunday for Monday 9am
      return true; // Other times available, same-day pricing
    }
    // Normal next-day: 9am disabled after 3 PM today (previous_day gate)
    if (timeOption.gateContext === 'previous_day') {
      return currentHour < timeOption.gateHour;
    }
    // 12pm, 3pm, 6pm, anytime: always available for next-day
    return true;
  }
  
  // Negative daysDiff (past date) - shouldn't happen but guard against it
  return false;
}

// Helper: check if current time is before Friday 3 PM relative to a delivery date
function isBeforeFridayCutoff(now, deliveryDate) {
  var nowDay = now.getDay();
  var currentHour = now.getHours();
  
  // Find the Friday before or on the delivery date
  // If it's currently Friday and before 3 PM, weekend delivery is available
  if (nowDay === 5 && currentHour < 15) return true;
  // If it's Mon-Thu, we haven't reached Friday yet, so Friday cutoff hasn't passed
  if (nowDay >= 1 && nowDay <= 4) return true;
  // If it's Friday after 3 PM, Saturday, or Sunday - too late
  return false;
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
      optionElement.disabled = !isAvailable;
      optionElement.textContent = isAvailable ? option.label : option.label + ' (not available)';
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
      elements.date.focus();
      if (elements.date.showPicker) {
        elements.date.showPicker();
      }
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
      if (elements.date) {
        elements.date.focus();
        if (elements.date.showPicker) {
          elements.date.showPicker();
        }
      }
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
        formData.set('conversionFee', state.conversionFee || 0);
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
  elements.conversionFeeRow = document.querySelector('.conversion-fee-row');
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
      checkConversionFee(null);
      updatePricingVisibility();
      update();
      updateSubmitButtonState();
      
      // Set current year if element exists
      const yearElement = document.getElementById('yr');
      if (yearElement) {
        yearElement.textContent = new Date().getFullYear();
      }
      
      
    } catch (error) {
      console.error('??  Error during initialization:', error);
    }
  }
  
  // Start initialization
  init();
});

