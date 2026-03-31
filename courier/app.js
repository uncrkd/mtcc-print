/**
 * Courier App JavaScript
 * Mobile-first client logic for MTCC courier/staff app
 * Location: /courier/app.js
 * 
 * Haptic + Audio feedback system ported from original dispatch scanner
 */

// ============================================
// Global State
// ============================================
var currentUser = null;
var currentTab = '';
var pinCode = '';
var scannerActive = false;
var scanLocked = false;  // Lock scanner while awaiting status action
var scanExpectedRef = null; // Set when scanning from an order card (for validation)
var courierLocation = null; // Cached GPS coordinates {lat, lng}
var autoRefreshInterval = null;
var capturedPhoto = null;

// Order cache (avoids inline JSON in onclick)
var COURIER_APP_VERSION = 'v10-transit-clean';
var orderCache = {};
var batchCache = {};

// MTCC tab cached data (for client-side search/filter)
var cachedPickupOrders = [];
var cachedUpcomingMtccOrders = [];
var cachedCompleteOrders = [];
var cachedActiveEvents = []; // active event acronyms for filter pills
var mtccSearchQuery = '';
var mtccEventFilters = []; // array of selected acronyms, empty = all events
var mtccFilterDropdownOpen = false;

// Live countdown timers
var countdownInterval = null;

function startCountdownTimers() {
    if (countdownInterval) clearInterval(countdownInterval);
    countdownInterval = setInterval(updateCountdowns, 1000);
}

function updateCountdowns() {
    var els = document.querySelectorAll('[data-countdown-target]');
    els.forEach(function(el) {
        var target = parseFloat(el.getAttribute('data-countdown-target'));
        if (!target || isNaN(target)) return;
        var now = Date.now() / 1000;
        var remaining = target - now;
        if (remaining <= 0) {
            el.textContent = 'OVERDUE';
            el.classList.add('countdown-overdue');
            return;
        }
        var hrs = Math.floor(remaining / 3600);
        var mins = Math.floor((remaining % 3600) / 60);
        var secs = Math.floor(remaining % 60);
        var pad = function(n) { return n < 10 ? '0' + n : n; };
        el.textContent = pad(hrs) + 'h : ' + pad(mins) + 'm : ' + pad(secs) + 's';
    });
}

function getCountdownTarget(order) {
    if (!order.due_date) return null;
    var timeHours = { 'anytime': 18, '9am': 9, '12pm': 12, '3pm': 15, '6pm': 18 };
    var h = timeHours[order.due_time] || 18;
    var d = new Date(order.due_date + 'T00:00:00');
    d.setHours(h, 0, 0, 0);
    return d.getTime() / 1000;
}

// Delivery view state
var deliveryView = 'active'; // 'active' or 'upcoming'
var deliveryFilter = 'all'; // 'all', 'mtcc', 'office'
var cachedActive = [];
var cachedUpcoming = [];
var activeTransitRef = null; // Track in-transit order for auto-launch
var transitDismissedByUser = false; // Prevent auto-relaunch after user closes

// Support phone numbers
var SUPPORT_PHONES = {
    admin: { label: 'Print Stuff Admin', number: '437-882-8822' },
    mtcc: { label: 'MTCC Front Desk', number: '416-585-8387' }
};


// ============================================
// Haptic & Audio Feedback (from original dispatch scanner)
// Works on all devices - vibration on Android, audio beeps on iOS/desktop
// ============================================
var haptic = {
    vibrateSupported: ('vibrate' in navigator),
    audioCtx: null,
    audioEnabled: true,

    // Initialize audio context (must be called after user interaction)
    initAudio: function() {
        if (!this.audioCtx) {
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                console.log('Audio context initialized');
            } catch (e) {
                console.warn('Audio not supported:', e);
                this.audioEnabled = false;
            }
        }
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
    },

    // Play a beep tone with frequency (Hz), duration (ms), volume (0-1)
    beep: function(frequency, duration, volume) {
        frequency = frequency || 800;
        duration = duration || 100;
        volume = volume || 0.3;

        if (!this.audioEnabled || !this.audioCtx) return;

        try {
            var oscillator = this.audioCtx.createOscillator();
            var gainNode = this.audioCtx.createGain();

            oscillator.connect(gainNode);
            gainNode.connect(this.audioCtx.destination);

            oscillator.frequency.value = frequency;
            oscillator.type = 'sine';

            gainNode.gain.setValueAtTime(volume, this.audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, this.audioCtx.currentTime + duration / 1000);

            oscillator.start(this.audioCtx.currentTime);
            oscillator.stop(this.audioCtx.currentTime + duration / 1000);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    },

    // Light tap - for button presses, keypad
    tap: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(15);
        }
    },

    // Success - scan found, login success
    success: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(100);
        }
        this.beep(1200, 150, 0.3);
    },

    // Error - scan failed, order not found, login failed
    error: function() {
        if (this.vibrateSupported) {
            navigator.vibrate([100, 50, 100]);
        }
        var self = this;
        this.beep(400, 150, 0.4);
        setTimeout(function() { self.beep(300, 200, 0.4); }, 200);
    },

    // Warning - permission denied, validation error
    warning: function() {
        if (this.vibrateSupported) {
            navigator.vibrate([50, 30, 50, 30, 50]);
        }
        var self = this;
        this.beep(600, 80, 0.3);
        setTimeout(function() { self.beep(600, 80, 0.3); }, 120);
        setTimeout(function() { self.beep(600, 80, 0.3); }, 240);
    },

    // Confirm - status change confirmed (most satisfying ascending tone)
    confirm: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(200);
        }
        var self = this;
        this.beep(800, 100, 0.3);
        setTimeout(function() { self.beep(1200, 150, 0.3); }, 100);
    },

    // Scan detected - immediate feedback when barcode detected
    scanDetected: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(50);
        }
        this.beep(1500, 80, 0.25);
    },

    // Shutter sound for photo capture
    shutter: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(30);
        }
        this.beep(1000, 50, 0.2);
    }
};

// ============================================
// API Helper
// ============================================
function apiCall(action, data, callback) {
    var formData = new FormData();
    formData.append('action', action);
    if (data) {
        for (var key in data) {
            if (data.hasOwnProperty(key)) formData.append(key, data[key]);
        }
    }

    fetch('api.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.auth_required) {
                showLogin();
                return;
            }
            if (callback) callback(result);
        })
        .catch(function(err) {
            console.error('API error:', err);
            if (callback) callback({ success: false, error: 'Network error' });
        });
}

// ============================================
// PIN Login
// ============================================
function enterPin(digit) {
    haptic.tap();
    if (pinCode.length >= 6) return;
    pinCode += digit;
    updatePinDots();

    if (pinCode.length === 6) {
        setTimeout(submitPin, 150);
    }
}

function backspacePin() {
    haptic.tap();
    pinCode = pinCode.slice(0, -1);
    updatePinDots();
    clearPinError();
}

function clearPin() {
    haptic.tap();
    pinCode = '';
    updatePinDots();
    clearPinError();
}

function updatePinDots() {
    for (var i = 0; i < 6; i++) {
        var dot = document.getElementById('pinDot' + i);
        if (dot) dot.classList.toggle('filled', i < pinCode.length);
    }
}

function submitPin() {
    if (pinCode.length !== 6) return;
    var submitBtn = document.getElementById('loginSubmitBtn');
    if (submitBtn) submitBtn.disabled = true;

    apiCall('login', { pin: pinCode }, function(result) {
        if (submitBtn) submitBtn.disabled = false;
        if (result.success) {
            haptic.success();
            currentUser = result.user;
            pinCode = '';
            updatePinDots();
            showApp();
        } else {
            haptic.error();
            pinCode = '';
            updatePinDots();
            showPinError(result.error || 'Invalid PIN');
        }
    });
}

function showPinError(msg) {
    var el = document.getElementById('pinError');
    if (el) { el.textContent = msg; el.style.display = 'block'; }
    var dots = document.getElementById('pinDots');
    if (dots) { dots.classList.add('shake'); setTimeout(function() { dots.classList.remove('shake'); }, 500); }
}

function clearPinError() {
    var el = document.getElementById('pinError');
    if (el) { el.style.display = 'none'; }
}

// ============================================
// App Shell
// ============================================
function showLogin() {
    document.getElementById('loginScreen').classList.add('active');
    document.getElementById('appScreen').classList.remove('active');
    stopAutoRefresh();
    if (scannerActive) stopScanner();
}

function showApp() {
    document.getElementById('loginScreen').classList.remove('active');
    document.getElementById('appScreen').classList.add('active');

    // Set user info in header and drawer
    var roleEl = document.getElementById('headerRole');
    if (roleEl) {
        roleEl.textContent = currentUser.role_label;
        roleEl.className = 'role-pill role-' + currentUser.role;
    }

    // Populate drawer
    var drawerName = document.getElementById('drawerName');
    var drawerRole = document.getElementById('drawerRole');
    var drawerAvatar = document.getElementById('drawerAvatar');
    var availToggle = document.getElementById('availabilityToggle');
    var availDot = document.getElementById('availabilityDot');
    if (drawerName) drawerName.textContent = currentUser.name;
    if (drawerRole) drawerRole.textContent = currentUser.role_label;
    if (drawerAvatar) drawerAvatar.textContent = (currentUser.name || '?').charAt(0).toUpperCase();
    if (availToggle) availToggle.checked = true;
    if (availDot) availDot.classList.add('online');

    // Role-based drawer visibility
    var isMTCC = currentUser.role === 'mtcc_staff';
    document.querySelectorAll('.drawer-courier-only').forEach(function(el) {
        el.style.display = isMTCC ? 'none' : '';
    });
    document.querySelectorAll('.drawer-mtcc-only').forEach(function(el) {
        el.style.display = isMTCC ? '' : 'none';
    });

    // Set role-specific status labels for badge rendering
    if (currentUser.role === 'mtcc_staff') {
        statusLabels = {
            preflight: 'In Production', file_issue: 'In Production', printing: 'In Production',
            ready: 'Preparing to Ship', dispatched: 'On the Way', shipped: 'On the Way',
            delivered: 'Ready for Pickup', pickedup: 'Picked Up', missing: 'Missing', unclaimed: 'Unclaimed'
        };
    } else {
        statusLabels = {
            ready: 'Available', dispatched: 'Accepted', shipped: 'In Transit',
            delivered: 'Delivered', missing: 'Missing'
        };
    }

    // Build nav tabs
    buildNavTabs(currentUser.tabs);

    // Switch to first tab
    var firstTab = currentUser.tabs[0];
    if (firstTab) switchTab(firstTab.id);

    startAutoRefresh();
    startCountdownTimers();
    loadWeather();
    startGeoWatch();

    // Preload active events for MTCC filter pills
    if (currentUser.role === 'mtcc_staff') {
        apiCall('get_mtcc_dashboard', null, function(r) {
            if (r.success) {
                cachedActiveEvents = r.active_events || [];
                // Re-render current tab so filter pills appear
                if (currentTab && currentTab !== 'mtcc_dashboard' && currentTab !== 'scan') {
                    rerenderMTCCTab(currentTab);
                }
            }
        });
    }

    // FALLBACK: If auto-launch didn't fire from callback, try again after data loads
    setTimeout(function() {
        console.log('[Fallback] Checking for transit orders... cachedActive=' + cachedActive.length);
        if (!activeTransitRef && !transitDismissedByUser && cachedActive.length > 0) {
            autoLaunchTransit();
        }
    }, 3000);
}

// Event delegation for batch cards
document.addEventListener('click', function(e) {
    var bCard = e.target.closest('.batch-card[data-batch-id]');
    if (!bCard) return;
    e.stopPropagation();
    var batchId = bCard.getAttribute('data-batch-id');
    var mode = bCard.getAttribute('data-mode') || 'available';
    if (!batchId || !batchCache[batchId]) return;
    haptic.tap();
    showBatchDetail(batchId, mode);
});

// Event delegation for order cards — immediate (DOM ready since script is at body end)
document.addEventListener('click', function(e) {
    var card = e.target.closest('.order-card[data-ref]');
    if (!card) return;
    e.stopPropagation();
    var ref = card.getAttribute('data-ref');
    var mode = card.getAttribute('data-mode') || 'delivery';
    var isTransit = card.getAttribute('data-transit') === '1';
    console.log('[CardClick] ref=' + ref + ' mode=' + mode + ' transit=' + isTransit + ' cached=' + !!orderCache[ref]);
    if (!ref || !orderCache[ref]) { console.warn('[CardClick] ABORTED - no ref or not in cache'); return; }
    haptic.tap();
    if (isTransit) {
        console.log('[CardClick] -> showTransitView');
        showTransitView(ref, mode);
    } else {
        console.log('[CardClick] -> showOrderDetail');
        showOrderDetail(ref, mode);
    }
});

// BACKUP: Direct-attach click handlers via MutationObserver
// In case event delegation fails for any reason
var cardObserver = new MutationObserver(function(mutations) {
    mutations.forEach(function(m) {
        m.addedNodes.forEach(function(node) {
            if (node.nodeType !== 1) return;
            var cards = [];
            if (node.matches && node.matches('.order-card[data-ref]')) cards.push(node);
            if (node.querySelectorAll) {
                cards = cards.concat(Array.from(node.querySelectorAll('.order-card[data-ref]')));
            }
            cards.forEach(function(c) {
                if (c._clickBound) return;
                c._clickBound = true;
                c.addEventListener('click', function(ev) {
                    var r = c.getAttribute('data-ref');
                    var md = c.getAttribute('data-mode') || 'delivery';
                    var tr = c.getAttribute('data-transit') === '1';
                    console.log('[DirectClick] ref=' + r + ' transit=' + tr);
                    if (!r || !orderCache[r]) return;
                    haptic.tap();
                    if (tr) showTransitView(r, md);
                    else showOrderDetail(r, md);
                });
            });
        });
    });
});
cardObserver.observe(document.body, { childList: true, subtree: true });

function buildNavTabs(tabs) {
    var nav = document.getElementById('bottomNav');
    if (!nav) return;
    var html = '';
    tabs.forEach(function(tab) {
        var isScan = (tab.id === 'scan');
        html += '<button class="nav-item' + (isScan ? ' scan-tab' : '') + '" data-tab="' + tab.id + '" onclick="switchTab(\'' + tab.id + '\')">';
        html += '<div class="nav-icon">' + getTabIcon(tab.icon) + '</div>';
        html += '<span class="nav-label">' + tab.label + '</span>';
        html += '</button>';
    });
    nav.innerHTML = html;
}

function switchTab(tabId) {
    haptic.tap();
    currentTab = tabId;

    // Update nav
    document.querySelectorAll('.nav-item').forEach(function(item) {
        item.classList.toggle('active', item.dataset.tab === tabId);
    });

    // Update panes
    document.querySelectorAll('.tab-pane').forEach(function(pane) {
        pane.classList.toggle('active', pane.id === 'tab-' + tabId);
    });

    // Stop scanner if leaving scan tab
    if (tabId !== 'scan' && scannerActive) {
        stopScanner();
    }

    // Auto-start scanner when entering scan tab
    // Called directly from click handler so user-gesture context is preserved
    if (tabId === 'scan' && !scannerActive) {
        startScanner();
    }

    // Load content for other tabs
    refreshTab(tabId);
}

function refreshTab(tabId) {
    switch(tabId) {
        case 'deliveries': loadMyDeliveries(); break;
        case 'available': loadAvailable(); break;
        case 'pickup': loadPickupQueue(); break;
        case 'earnings': loadEarnings(); break;
        case 'history': loadHistory(); break;
        case 'activity': loadActivity(); break;
        case 'nearby': loadNearby(); break;
        case 'mtcc_dashboard': loadMTCCDashboard(); break;
        case 'upcoming_mtcc': loadUpcomingMTCC(); break;
        case 'complete': loadCompleted(); break;
        // scan tab: handled by switchTab directly
    }
}

// ============================================
// Tab Content Loaders
// ============================================

function loadMyDeliveries() {
    apiCall('get_my_deliveries', null, function(result) {
        if (result.success) {
            cachedActive = result.active || [];
        }
        console.log('[LoadDeliveries] Got ' + cachedActive.length + ' active orders');
        cachedActive.forEach(function(o) { console.log('  - ' + o.ref + ' status=' + o.status); });
        renderDeliveriesView();
        // Auto-launch transit view (DoorDash/Uber pattern)
        console.log('[LoadDeliveries] Calling autoLaunchTransit...');
        autoLaunchTransit();
    });
}

function renderDeliveriesView() {
    var el = document.getElementById('deliveriesContent');
    if (!el) return;
    
    var activeOrders = cachedActive;
    
    // Apply destination filter
    if (deliveryFilter !== 'all') {
        activeOrders = activeOrders.filter(function(o) {
            return matchesFilter(o, deliveryFilter);
        });
    }
    
    // Sort: in-transit (shipped) FIRST, then by urgency
    activeOrders.sort(function(a, b) {
        var aT = (a.status === 'shipped') ? 0 : 1;
        var bT = (b.status === 'shipped') ? 0 : 1;
        if (aT !== bT) return aT - bT;
        var aH = (a.hours_remaining !== null && a.hours_remaining !== undefined) ? a.hours_remaining : 9999;
        var bH = (b.hours_remaining !== null && b.hours_remaining !== undefined) ? b.hours_remaining : 9999;
        return aH - bH;
    });
    
    // Filter pills
    var html = '<div class="filter-pills">';
    html += '<button class="filter-pill' + (deliveryFilter === 'all' ? ' active' : '') + '" onclick="setDeliveryFilter(\'all\')">All</button>';
    html += '<button class="filter-pill' + (deliveryFilter === 'mtcc' ? ' active' : '') + '" onclick="setDeliveryFilter(\'mtcc\')">MTCC</button>';
    html += '<button class="filter-pill' + (deliveryFilter === 'office' ? ' active' : '') + '" onclick="setDeliveryFilter(\'office\')">Office</button>';
    html += '</div>';
    
    if (activeOrders.length > 0) {
        activeOrders.forEach(function(o) {
            if (o.type === 'batch') {
                html += renderBatchCard(o, 'delivery');
            } else {
                html += renderOrderCard(o, 'delivery');
            }
        });
    } else {
        html += '<div class="empty-state"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5a2 2 0 0 1-2 2"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></div><h3>No Active Deliveries</h3><p>Head to the Available tab to pick up orders.</p></div>';
    }
    
    el.innerHTML = html;

    // Show return banner if transit view was dismissed
    var tv = document.getElementById('transitView');
    if (activeTransitRef && tv && !tv.classList.contains('active')) {
        showReturnBanner();
    }
}

function matchesFilter(order, filter) {
    var destType = (order.destination_type || '').toLowerCase();
    var dest = (order.destination || '').toLowerCase();
    if (filter === 'mtcc') return destType === 'mtcc' || dest.indexOf('mtcc') !== -1;
    if (filter === 'office') return destType !== 'mtcc' && dest.indexOf('mtcc') === -1;
    return true;
}

function setDeliveryView(view) {
    deliveryView = view;
    haptic.tap();
    renderDeliveriesView();
}

function setDeliveryFilter(filter) {
    deliveryFilter = filter;
    haptic.tap();
    renderDeliveriesView();
}


var availableView = 'all'; // 'all', 'active', 'upcoming'
var cachedAvailActive = [];
var cachedAvailUpcoming = [];

function loadAvailable() {
    var loaded = { active: false, upcoming: false };

    apiCall('get_available', null, function(result) {
        loaded.active = true;
        if (result.success) {
            cachedAvailActive = result.orders || [];
        }
        if (loaded.upcoming) renderAvailableView();
    });

    apiCall('get_upcoming', null, function(result) {
        loaded.upcoming = true;
        if (result.success) {
            cachedAvailUpcoming = result.orders || [];
        }
        if (loaded.active) renderAvailableView();
    });
}

function renderAvailableView() {
    var el = document.getElementById('availableContent');
    if (!el) return;
    
    var allOrders = cachedAvailActive.concat(cachedAvailUpcoming);
    
    // Sort everything by urgency (most urgent first)
    var sortByUrgency = function(a, b) {
        var aH = (a.hours_remaining !== null && a.hours_remaining !== undefined) ? a.hours_remaining : 9999;
        var bH = (b.hours_remaining !== null && b.hours_remaining !== undefined) ? b.hours_remaining : 9999;
        return aH - bH;
    };
    allOrders.sort(sortByUrgency);
    cachedAvailActive.sort(sortByUrgency);
    cachedAvailUpcoming.sort(sortByUrgency);
    
    // Toggle bar
    var html = '<div class="delivery-toggle-bar triple">';
    html += '<button class="toggle-btn' + (availableView === 'all' ? ' active' : '') + '" onclick="setAvailableView(\'all\')">All (' + allOrders.length + ')</button>';
    html += '<button class="toggle-btn' + (availableView === 'active' ? ' active' : '') + '" onclick="setAvailableView(\'active\')">Ready (' + cachedAvailActive.length + ')</button>';
    html += '<button class="toggle-btn' + (availableView === 'upcoming' ? ' active' : '') + '" onclick="setAvailableView(\'upcoming\')">Upcoming (' + cachedAvailUpcoming.length + ')</button>';
    html += '</div>';
    
    var displayOrders;
    if (availableView === 'active') displayOrders = cachedAvailActive;
    else if (availableView === 'upcoming') displayOrders = cachedAvailUpcoming;
    else displayOrders = allOrders;
    
    if (displayOrders.length === 0) {
        html += '<div class="empty-state"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div><h3>All Caught Up</h3><p>No orders right now. Check back soon!</p></div>';
    } else {
        displayOrders.forEach(function(o) {
            if (o.type === 'batch') {
                html += renderBatchCard(o, 'available');
            } else {
                var cardMode = (o.status === 'printing' || o.status === 'ready_to_ship') ? 'upcoming' : 'available';
                html += renderOrderCard(o, cardMode);
            }
        });
    }
    
    el.innerHTML = html;
}

function setAvailableView(view) {
    availableView = view;
    haptic.tap();
    renderAvailableView();
}


function loadPickupQueue() {
    apiCall('get_pickup_queue', null, function(result) {
        if (!result.success) {
            var el = document.getElementById('pickupContent');
            if (el) el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error) + '</p></div>';
            return;
        }
        cachedPickupOrders = result.orders || [];
        renderPickupFromCache();
    });
}

// ============================================
// MTCC Search & Event Filter
// ============================================

function buildSearchFilterBar(tabId) {
    var html = '<div class="mtcc-search-filter">';

    // Search bar row with filter button
    html += '<div class="mtcc-search-row">';
    html += '<div class="mtcc-search-bar">';
    html += '<svg class="mtcc-search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
    html += '<input type="text" class="mtcc-search-input" id="mtccSearch_' + tabId + '" placeholder="Search name, ID, or email..." oninput="onMTCCSearch(\'' + tabId + '\', this.value)" value="' + escapeAttr(mtccSearchQuery) + '">';
    if (mtccSearchQuery) {
        html += '<button class="mtcc-search-clear" onclick="clearMTCCSearch(\'' + tabId + '\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>';
    }
    html += '</div>';

    // Filter button (only if events exist)
    if (cachedActiveEvents.length > 0) {
        var filterCount = mtccEventFilters.length;
        html += '<button class="mtcc-filter-btn' + (filterCount > 0 ? ' has-filters' : '') + '" onclick="toggleEventFilterDropdown(\'' + tabId + '\')">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>';
        if (filterCount > 0) {
            html += '<span class="mtcc-filter-count">' + filterCount + '</span>';
        }
        html += '</button>';
    }
    html += '</div>';

    // Dropdown checklist (hidden by default)
    if (cachedActiveEvents.length > 0) {
        html += '<div class="mtcc-filter-dropdown' + (mtccFilterDropdownOpen ? ' open' : '') + '" id="mtccFilterDropdown_' + tabId + '">';
        html += '<div class="mtcc-filter-dropdown-header">';
        html += '<span class="mtcc-filter-dropdown-title">Filter by Event</span>';
        if (mtccEventFilters.length > 0) {
            html += '<button class="mtcc-filter-clear" onclick="clearAllEventFilters(\'' + tabId + '\')">Clear All</button>';
        }
        html += '</div>';
        cachedActiveEvents.forEach(function(ev) {
            var acronym = ev.acronym || ev;
            var evName = ev.name || acronym;
            var isChecked = mtccEventFilters.indexOf(acronym) !== -1;
            html += '<label class="mtcc-filter-item' + (isChecked ? ' checked' : '') + '">';
            html += '<input type="checkbox"' + (isChecked ? ' checked' : '') + ' onchange="toggleEventFilter(\'' + tabId + '\', \'' + escapeAttr(acronym) + '\', this.checked)">';
            html += '<span class="mtcc-filter-check"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></span>';
            html += '<span class="mtcc-filter-name">' + escapeHtml(evName) + '</span>';
            html += '<span class="mtcc-filter-acronym">' + escapeHtml(acronym) + '</span>';
            html += '</label>';
        });
        html += '</div>';
    }

    // Active filter tags (show selected events as removable chips)
    if (mtccEventFilters.length > 0) {
        html += '<div class="mtcc-active-filters">';
        mtccEventFilters.forEach(function(acr) {
            var ev = cachedActiveEvents.find(function(e) { return (e.acronym || e) === acr; });
            var name = ev ? (ev.name || acr) : acr;
            html += '<span class="mtcc-active-tag">' + escapeHtml(name) + '<button onclick="removeEventFilter(\'' + tabId + '\', \'' + escapeAttr(acr) + '\')">&times;</button></span>';
        });
        html += '</div>';
    }

    html += '</div>';
    return html;
}

function onMTCCSearch(tabId, query) {
    mtccSearchQuery = query.trim();
    rerenderMTCCTab(tabId);
}

function clearMTCCSearch(tabId) {
    mtccSearchQuery = '';
    var input = document.getElementById('mtccSearch_' + tabId);
    if (input) input.value = '';
    rerenderMTCCTab(tabId);
}

function toggleEventFilterDropdown(tabId) {
    mtccFilterDropdownOpen = !mtccFilterDropdownOpen;
    rerenderMTCCTab(tabId);
}

function toggleEventFilter(tabId, acronym, checked) {
    if (checked) {
        if (mtccEventFilters.indexOf(acronym) === -1) mtccEventFilters.push(acronym);
    } else {
        mtccEventFilters = mtccEventFilters.filter(function(a) { return a !== acronym; });
    }
    rerenderMTCCTab(tabId);
}

function removeEventFilter(tabId, acronym) {
    mtccEventFilters = mtccEventFilters.filter(function(a) { return a !== acronym; });
    rerenderMTCCTab(tabId);
}

function clearAllEventFilters(tabId) {
    mtccEventFilters = [];
    mtccFilterDropdownOpen = false;
    rerenderMTCCTab(tabId);
}

// Close dropdown when tapping outside
document.addEventListener('click', function(e) {
    if (mtccFilterDropdownOpen && !e.target.closest('.mtcc-filter-btn') && !e.target.closest('.mtcc-filter-dropdown')) {
        mtccFilterDropdownOpen = false;
        if (currentUser && currentUser.role === 'mtcc_staff') rerenderMTCCTab(currentTab);
    }
});

function filterMTCCOrders(orders) {
    var filtered = orders;

    // Event filter (multi-select)
    if (mtccEventFilters.length > 0) {
        filtered = filtered.filter(function(o) {
            var ref = (o.ref || '').toUpperCase();
            var evAcr = (o.event_acronym || '').toUpperCase();
            for (var i = 0; i < mtccEventFilters.length; i++) {
                var f = mtccEventFilters[i].toUpperCase();
                if (ref.indexOf(f) === 0 || evAcr === f) return true;
            }
            return false;
        });
    }

    // Search filter
    if (mtccSearchQuery) {
        var q = mtccSearchQuery.toLowerCase();
        filtered = filtered.filter(function(o) {
            return (o.ref || '').toLowerCase().indexOf(q) !== -1
                || (o.customer_name || '').toLowerCase().indexOf(q) !== -1
                || (o.customer_email || '').toLowerCase().indexOf(q) !== -1
                || (o.tracking || '').toLowerCase().indexOf(q) !== -1;
        });
    }

    return filtered;
}

function rerenderMTCCTab(tabId) {
    switch (tabId) {
        case 'pickup': renderPickupFromCache(); break;
        case 'upcoming_mtcc': renderUpcomingMTCCFromCache(); break;
        case 'complete': renderCompleteFromCache(); break;
    }
}

function renderPickupFromCache() {
    var el = document.getElementById('pickupContent');
    if (!el) return;
    var filtered = filterMTCCOrders(cachedPickupOrders);
    var html = buildSearchFilterBar('pickup');
    html += '<div class="section-label">' + filtered.length + ' order' + (filtered.length !== 1 ? 's' : '') + ' waiting for pickup' + (mtccSearchQuery || mtccEventFilters.length ? ' (filtered)' : '') + '</div>';
    if (filtered.length === 0 && (mtccSearchQuery || mtccEventFilters.length)) {
        html += '<div class="empty-state"><p>No orders match your search.</p></div>';
    } else if (filtered.length === 0) {
        html += '<div class="empty-state"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/><rect x="3" y="3" width="18" height="18" rx="2"/></svg></div><h3>Pickup Queue Empty</h3><p>No orders waiting for customer pickup.</p></div>';
    } else {
        filtered.forEach(function(o) { html += renderOrderCard(o, 'pickup'); });
    }
    el.innerHTML = html;
}

function renderUpcomingMTCCFromCache() {
    var el = document.getElementById('upcomingMtccContent');
    if (!el) return;
    var filtered = filterMTCCOrders(cachedUpcomingMtccOrders);
    var groups = {transit: [], ready: [], production: []};
    filtered.forEach(function(o) {
        if (o.status === 'shipped' || o.status === 'dispatched') groups.transit.push(o);
        else if (o.status === 'ready') groups.ready.push(o);
        else groups.production.push(o);
    });

    var html = buildSearchFilterBar('upcoming_mtcc');
    html += '<div class="section-label">' + filtered.length + ' order' + (filtered.length !== 1 ? 's' : '') + ' in the pipeline' + (mtccSearchQuery || mtccEventFilters.length ? ' (filtered)' : '') + '</div>';

    if (filtered.length === 0 && (mtccSearchQuery || mtccEventFilters.length)) {
        html += '<div class="empty-state"><p>No orders match your search.</p></div>';
    } else if (filtered.length === 0) {
        html += '<div class="empty-state"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div><h3>All Caught Up</h3><p>No orders in the pipeline right now.</p></div>';
    } else {
        if (groups.transit.length > 0) {
            html += '<div class="mtcc-group-header">On the Way (' + groups.transit.length + ')</div>';
            groups.transit.forEach(function(o) { html += renderOrderCard(o, 'upcoming_mtcc'); });
        }
        if (groups.ready.length > 0) {
            html += '<div class="mtcc-group-header">Preparing to Ship (' + groups.ready.length + ')</div>';
            groups.ready.forEach(function(o) { html += renderOrderCard(o, 'upcoming_mtcc'); });
        }
        if (groups.production.length > 0) {
            html += '<div class="mtcc-group-header">In Production (' + groups.production.length + ')</div>';
            groups.production.forEach(function(o) { html += renderOrderCard(o, 'upcoming_mtcc'); });
        }
    }
    el.innerHTML = html;
}

function renderCompleteFromCache() {
    var el = document.getElementById('completeContent');
    if (!el) return;
    var filtered = filterMTCCOrders(cachedCompleteOrders);
    var html = buildSearchFilterBar('complete');
    html += '<div class="section-label">' + filtered.length + ' order' + (filtered.length !== 1 ? 's' : '') + ' picked up' + (mtccSearchQuery || mtccEventFilters.length ? ' (filtered)' : '') + '</div>';
    if (filtered.length === 0 && (mtccSearchQuery || mtccEventFilters.length)) {
        html += '<div class="empty-state"><p>No orders match your search.</p></div>';
    } else if (filtered.length === 0) {
        html += '<div class="empty-state"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="M12 6v6l4 2"/></svg></div><h3>No Completed Pickups</h3><p>Picked up orders for active events will appear here.</p></div>';
    } else {
        filtered.forEach(function(o) { html += renderOrderCard(o, 'complete'); });
    }
    el.innerHTML = html;
}

// ============================================
// MTCC Staff Tab Loaders
// ============================================

function loadMTCCDashboard() {
    apiCall('get_mtcc_dashboard', null, function(result) {
        var el = document.getElementById('mtccDashboardContent');
        if (!el) return;
        if (!result.success) {
            el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error || 'Failed to load') + '</p></div>';
            return;
        }
        // Cache active events for filter pills
        cachedActiveEvents = result.active_events || [];

        var s = result.stats;
        var html = '';

        // Stats cards — clickable, navigate to relevant tab
        html += '<div class="mtcc-stats-grid">';
        html += '<div class="mtcc-stat-card stat-purple" onclick="switchTab(\'pickup\')"><div class="mtcc-stat-number">' + s.waiting_for_pickup + '</div><div class="mtcc-stat-label">Waiting for Pickup</div></div>';
        html += '<div class="mtcc-stat-card stat-blue" onclick="switchTab(\'upcoming_mtcc\')"><div class="mtcc-stat-number">' + s.expected_today + '</div><div class="mtcc-stat-label">Expected Today</div></div>';
        html += '<div class="mtcc-stat-card stat-green" onclick="switchTab(\'complete\')"><div class="mtcc-stat-number">' + s.picked_up_today + '</div><div class="mtcc-stat-label">Picked Up Today</div></div>';
        html += '<div class="mtcc-stat-card' + (s.open_issues > 0 ? ' stat-red' : ' stat-grey') + '"><div class="mtcc-stat-number">' + s.open_issues + '</div><div class="mtcc-stat-label">Open Issues</div></div>';
        html += '</div>';

        // Event breakdown — clickable, sets event filter and navigates to pickup
        // Build acronym → full name lookup from active events
        var eventNameMap = {};
        (result.active_events || []).forEach(function(e) {
            if (e.acronym) eventNameMap[e.acronym] = e.name || e.acronym;
        });

        var events = result.event_breakdown || {};
        var eventKeys = Object.keys(events);
        if (eventKeys.length > 0) {
            html += '<div class="mtcc-dash-section"><div class="mtcc-section-header">Waiting by Event</div>';
            html += '<div class="mtcc-event-pills">';
            eventKeys.forEach(function(ev) {
                var evName = eventNameMap[ev] || ev;
                html += '<button class="mtcc-event-pill" onclick="mtccEventFilters=[\'' + escapeAttr(ev) + '\']; switchTab(\'pickup\')">' + escapeHtml(evName) + ': <strong>' + events[ev] + '</strong></button>';
            });
            html += '</div></div>';
        }

        // Pipeline summary — clickable, navigates to upcoming
        if (s.in_production > 0 || s.in_transit > 0) {
            html += '<div class="mtcc-dash-section"><div class="mtcc-section-header">Pipeline</div>';
            html += '<div class="mtcc-pipeline" onclick="switchTab(\'upcoming_mtcc\')">';
            if (s.in_production > 0) html += '<div class="mtcc-pipeline-item"><span class="mtcc-pipeline-dot dot-amber"></span>' + s.in_production + ' in production</div>';
            if (s.in_transit > 0) html += '<div class="mtcc-pipeline-item"><span class="mtcc-pipeline-dot dot-blue"></span>' + s.in_transit + ' in transit</div>';
            html += '</div></div>';
        }

        // Upcoming deliveries — clickable rows open detail panel
        var upcoming = result.upcoming_deliveries || [];
        if (upcoming.length > 0) {
            html += '<div class="mtcc-dash-section"><div class="mtcc-section-header">Next Expected Deliveries</div>';
            html += '<div class="mtcc-dash-list">';
            upcoming.forEach(function(o) {
                orderCache[o.ref] = o;
                html += renderOrderCard(o, 'upcoming_mtcc');
            });
            html += '</div></div>';
        }

        // Recent pickups — clickable rows open detail panel
        var recent = result.recent_pickups || [];
        if (recent.length > 0) {
            html += '<div class="mtcc-dash-section"><div class="mtcc-section-header">Recent Pickups Today</div>';
            html += '<div class="mtcc-dash-list">';
            recent.forEach(function(r) {
                var pTime = r.pickedup_at ? new Date(r.pickedup_at) : null;
                var timeStr = pTime ? pTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) : '';
                html += '<div class="mtcc-recent-item" onclick="if(orderCache[\'' + escapeAttr(r.ref) + '\']) showOrderDetail(\'' + escapeAttr(r.ref) + '\', \'complete\')">';
                html += '<span class="mtcc-recent-ref">' + escapeHtml(r.ref) + '</span>';
                html += '<span class="mtcc-recent-name">' + escapeHtml(r.customer_name) + '</span>';
                html += '<span class="mtcc-recent-time">' + timeStr + '</span>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        el.innerHTML = html;
    });
}

function loadUpcomingMTCC() {
    apiCall('get_upcoming_mtcc', null, function(result) {
        if (!result.success) {
            var el = document.getElementById('upcomingMtccContent');
            if (el) el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error || 'Failed to load') + '</p></div>';
            return;
        }
        cachedUpcomingMtccOrders = result.orders || [];
        renderUpcomingMTCCFromCache();
    });
}

function loadCompleted() {
    apiCall('get_completed', null, function(result) {
        if (!result.success) {
            var el = document.getElementById('completeContent');
            if (el) el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error || 'Failed to load') + '</p></div>';
            return;
        }
        cachedCompleteOrders = result.orders || [];
        renderCompleteFromCache();
    });
}

// ============================================
// Earnings (Courier)
// ============================================

function loadEarnings() {
    apiCall('get_earnings', null, function(result) {
        var el = document.getElementById('earningsContent');
        if (!result.success) {
            el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error) + '</p></div>';
            return;
        }
        var s = result.summary;
        var html = '<div class="earnings-summary">';
        html += '<div class="earning-card primary"><div class="earning-label">Today</div><div class="earning-value">$' + s.today.toFixed(2) + '</div><div class="earning-sub">' + s.deliveries_today + ' deliver' + (s.deliveries_today !== 1 ? 'ies' : 'y') + '</div></div>';
        html += '<div class="earning-card"><div class="earning-label">This Week</div><div class="earning-value">$' + s.week.toFixed(2) + '</div></div>';
        html += '<div class="earning-card"><div class="earning-label">This Month</div><div class="earning-value">$' + s.month.toFixed(2) + '</div></div>';
        html += '<div class="earning-card"><div class="earning-label">All Time</div><div class="earning-value">$' + s.all_time.toFixed(2) + '</div></div>';
        html += '</div>';

        // 7-day bar chart
        if (result.daily_chart && result.daily_chart.length > 0) {
            var maxAmt = Math.max.apply(null, result.daily_chart.map(function(d) { return d.amount; }));
            if (maxAmt <= 0) maxAmt = 1;
            html += '<div class="earnings-chart-section">';
            html += '<div class="earnings-chart-title">Last 7 Days</div>';
            html += '<div class="earnings-bar-chart">';
            result.daily_chart.forEach(function(d) {
                var pct = Math.round((d.amount / maxAmt) * 100);
                var isToday = d.date === new Date().toISOString().slice(0,10);
                html += '<div class="chart-bar-col' + (isToday ? ' today' : '') + '">';
                html += '<div class="chart-bar-value">$' + d.amount.toFixed(0) + '</div>';
                html += '<div class="chart-bar-track"><div class="chart-bar-fill" style="height:' + Math.max(pct, 3) + '%"></div></div>';
                html += '<div class="chart-bar-label">' + escapeHtml(d.label) + '</div>';
                if (d.count > 0) html += '<div class="chart-bar-count">' + d.count + '</div>';
                html += '</div>';
            });
            html += '</div></div>';
        }

        html += '<div class="earnings-list-header">Recent Deliveries</div>';
        if (result.recent.length === 0) {
            html += '<div class="empty-state" style="padding:30px"><p>No earnings recorded yet. Complete deliveries to start earning!</p></div>';
        } else {
            result.recent.forEach(function(e) {
                html += '<div class="earning-entry">';
                html += '<div class="earning-entry-left"><span class="earning-entry-ref">' + escapeHtml(e.ref) + '</span><span class="earning-entry-date">' + formatDateTime(e.date) + '</span></div>';
                html += '<span class="earning-entry-amount">+$' + parseFloat(e.amount).toFixed(2) + '</span>';
                html += '</div>';
            });
        }
        el.innerHTML = html;
    });
}

// ============================================
// History Tab
// ============================================
var historyPeriod = 'all';
var historyPage = 1;

function loadHistory(period, page) {
    if (period !== undefined) historyPeriod = period;
    if (page !== undefined) historyPage = page;
    else historyPage = 1;

    var el = document.getElementById('historyContent');
    if (!el) return;
    el.innerHTML = '<div class="loading-spinner"><div class="spinner"></div><p>Loading history...</p></div>';

    apiCall('get_history', { period: historyPeriod, page: historyPage }, function(result) {
        if (!result.success) {
            el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error || 'Failed to load') + '</p></div>';
            return;
        }

        var perf = result.performance;
        var deliveries = result.deliveries || [];
        var pag = result.pagination || {};
        var html = '';

        // Period filter pills
        html += '<div class="history-filters">';
        ['all', '30days', '7days', 'today'].forEach(function(p) {
            var labels = { all: 'All Time', '30days': 'Month', '7days': 'Week', today: 'Today' };
            html += '<button class="hist-pill' + (historyPeriod === p ? ' active' : '') + '" onclick="loadHistory(\'' + p + '\')">' + labels[p] + '</button>';
        });
        html += '</div>';

        // Performance header
        html += '<div class="history-perf">';
        html += '<div class="perf-stat"><div class="perf-value">' + perf.total_completed + '</div><div class="perf-label">Completed</div></div>';
        html += '<div class="perf-stat"><div class="perf-value' + (perf.on_time_rate >= 90 ? ' perf-good' : perf.on_time_rate >= 70 ? ' perf-ok' : ' perf-poor') + '">' + perf.on_time_rate + '%</div><div class="perf-label">On-Time</div></div>';
        html += '<div class="perf-stat"><div class="perf-value">' + perf.streak + '</div><div class="perf-label">Streak \u{1F525}</div></div>';
        html += '<div class="perf-stat"><div class="perf-value">$' + perf.total_earned.toFixed(0) + '</div><div class="perf-label">Earned</div></div>';
        html += '</div>';

        if (deliveries.length === 0) {
            html += '<div class="empty-state" style="padding:40px"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><h3>No Deliveries</h3><p>Completed deliveries will appear here.</p></div>';
        } else {
            html += '<div class="history-list">';
            deliveries.forEach(function(d) {
                var durationStr = d.duration_seconds ? formatDurationShort(d.duration_seconds) : '';
                var dateStr = d.completed_at ? formatDateTime(d.completed_at) : '';
                html += '<div class="history-card">';
                html += '<div class="history-card-header">';
                html += '<span class="history-ref">' + escapeHtml(d.ref) + '</span>';
                html += '<div class="history-badges">';
                if (d.on_time === true) html += '<span class="hist-badge hist-ontime">\u2713 On Time</span>';
                else if (d.on_time === false) html += '<span class="hist-badge hist-late">Late</span>';
                if (d.has_issue) html += '<span class="hist-badge hist-issue">\u26A0 Issue</span>';
                html += '<span class="hist-badge hist-status">' + escapeHtml(d.status === 'pickedup' ? 'Picked Up' : 'Delivered') + '</span>';
                html += '</div></div>';

                html += '<div class="history-card-body">';
                html += '<div class="history-detail"><span class="history-detail-icon">\u{1F4CD}</span> ' + escapeHtml(d.address || d.destination || 'MTCC') + '</div>';
                html += '<div class="history-detail"><span class="history-detail-icon">\u{1F4C5}</span> ' + escapeHtml(dateStr) + '</div>';
                if (durationStr) html += '<div class="history-detail"><span class="history-detail-icon">\u23F1</span> ' + durationStr + '</div>';
                html += '<div class="history-detail"><span class="history-detail-icon">\u{1F4E6}</span> ' + escapeHtml(d.size + ' ' + (d.material || '')) + '</div>';
                html += '</div>';

                html += '<div class="history-card-footer">';
                if (d.earned !== null) html += '<span class="history-earned">+$' + d.earned.toFixed(2) + '</span>';
                if (d.photo_url) html += '<button class="hist-photo-btn" onclick="showPhotoLightbox(\'' + escapeHtml(d.photo_url) + '\')">\u{1F4F7} Photo</button>';
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';

            // Pagination
            if (pag.total_pages > 1) {
                html += '<div class="history-pagination">';
                if (pag.page > 1) html += '<button class="hist-page-btn" onclick="loadHistory(undefined, ' + (pag.page - 1) + ')">\u2190 Prev</button>';
                html += '<span class="hist-page-info">Page ' + pag.page + ' of ' + pag.total_pages + '</span>';
                if (pag.page < pag.total_pages) html += '<button class="hist-page-btn" onclick="loadHistory(undefined, ' + (pag.page + 1) + ')">Next \u2192</button>';
                html += '</div>';
            }
        }

        el.innerHTML = html;
    });
}

function formatDurationShort(seconds) {
    if (!seconds || seconds <= 0) return '';
    var h = Math.floor(seconds / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    if (h > 0) return h + 'h ' + m + 'm';
    return m + 'm';
}

function showPhotoLightbox(url) {
    var existing = document.getElementById('photoLightbox');
    if (existing) existing.remove();
    var overlay = document.createElement('div');
    overlay.id = 'photoLightbox';
    overlay.className = 'photo-lightbox';
    overlay.onclick = function() { overlay.remove(); };
    overlay.innerHTML = '<div class="lightbox-content" onclick="event.stopPropagation()"><img src="' + url + '" alt="Delivery photo" /><button class="lightbox-close" onclick="document.getElementById(\'photoLightbox\').remove()">\u2715</button></div>';
    document.body.appendChild(overlay);
}

function loadActivity() {
    apiCall('get_activity', null, function(result) {
        var el = document.getElementById('activityContent');
        if (!result.success) {
            el.innerHTML = '<div class="empty-state"><h3>Error</h3><p>' + escapeHtml(result.error) + '</p></div>';
            return;
        }
        if (!result.entries || result.entries.length === 0) {
            el.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div><h3>No Activity Yet</h3><p>Today\'s dispatch activity will appear here.</p></div>';
            return;
        }
        var statusIcons = { shipped: '\u{1F69A}', delivered: '\u{1F4E6}', pickedup: '\u2705', dispatched: '\u{1F4CB}', ready: '\u{1F514}' };
        var html = '';
        result.entries.forEach(function(e) {
            var icon = statusIcons[e.toStatus] || '\u{1F504}';
            var time = '';
            if (e.timestamp) {
                var d = new Date(e.timestamp.replace(' ', 'T'));
                time = isNaN(d) ? e.timestamp : d.toLocaleDateString('en-US', {month: 'short', day: 'numeric'}) + ', ' + d.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
            }
            html += '<div class="activity-entry">';
            html += '<div class="activity-icon ' + (e.toStatus || '') + '">' + icon + '</div>';
            html += '<div class="activity-text"><div class="activity-title">' + escapeHtml(e.referenceCode) + ' \u2192 ' + escapeHtml(e.toStatus || '') + '</div>';
            html += '<div class="activity-detail">' + escapeHtml(e.userName || '') + ' (' + escapeHtml(e.roleLabel || '') + ')' + (e.source === 'courier_app' ? ' via app' : '') + '</div></div>';
            html += '<span class="activity-time">' + time + '</span>';
            html += '</div>';
        });
        el.innerHTML = html;
    });
}

// ============================================
// Status Labels & Transitions
// ============================================
var statusLabels = {}; // populated after login based on role

/**
 * Get available status transitions based on current status AND user role.
 * Courier: ready -> dispatched ONLY (must accept before picking up)
 * Admin/Staff: ready -> dispatched OR shipped (for 3rd-party courier scenarios)
 */


// ============================================
// WEATHER BAR
// ============================================

var weatherRefreshInterval = null;

function loadWeather() {
    apiCall('get_weather', {}, function(result) {
        if (!result.success || !result.weather) return;
        renderWeatherBar(result.weather, result.bad_weather);
    });
    
    // Refresh every 15 minutes
    if (weatherRefreshInterval) clearInterval(weatherRefreshInterval);
    weatherRefreshInterval = setInterval(function() {
        apiCall('get_weather', {}, function(result) {
            if (!result.success || !result.weather) return;
            renderWeatherBar(result.weather, result.bad_weather);
        });
    }, 15 * 60 * 1000);
}

function renderWeatherBar(weather, badWeather) {
    var bar = document.getElementById('weatherBar');
    if (!bar) return;
    
    var current = weather.current;
    
    // Icon
    var iconEl = document.getElementById('weatherIcon');
    if (iconEl) iconEl.textContent = current.icon || '';
    
    // Temperature
    var tempEl = document.getElementById('weatherTemp');
    if (tempEl) tempEl.textContent = current.temp_c + '\u00b0';
    
    // Description
    var descEl = document.getElementById('weatherDesc');
    if (descEl) descEl.textContent = current.description || '';
    
    // Wind
    var windEl = document.getElementById('weatherWind');
    if (windEl) windEl.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9.59 4.59A2 2 0 1 1 11 8H2m10.59 11.41A2 2 0 1 0 14 16H2m15.73-8.27A2.5 2.5 0 1 1 19.5 12H2"/></svg> ' + current.wind_kmh + ' km/h';
    
    // Bad weather badge
    var badge = document.getElementById('weatherBadge');
    if (badge) {
        if (badWeather && badWeather.is_bad) {
            badge.textContent = '+$' + (badWeather.bonus_amount || 5) + ' weather bonus';
            badge.style.display = 'inline-flex';
            bar.classList.add('weather-bad');
        } else {
            badge.style.display = 'none';
            bar.classList.remove('weather-bad');
        }
    }
    
    // Show bar
    bar.style.display = 'flex';
    
    // Add tap to expand forecast
    if (!bar.hasAttribute('data-bound')) {
        bar.setAttribute('data-bound', '1');
        bar.addEventListener('click', function() {
            toggleForecast(weather.forecast);
        });
    }
}

function toggleForecast(forecast) {
    var existing = document.getElementById('forecastPanel');
    if (existing) {
        existing.remove();
        return;
    }
    
    if (!forecast || forecast.length === 0) return;
    
    var bar = document.getElementById('weatherBar');
    var panel = document.createElement('div');
    panel.id = 'forecastPanel';
    panel.className = 'forecast-panel';
    
    var html = '<div class="forecast-header">5-Day Forecast</div><div class="forecast-days">';
    forecast.forEach(function(day) {
        html += '<div class="forecast-day">';
        html += '<div class="forecast-day-name">' + escapeHtml(day.day_name) + '</div>';
        html += '<div class="forecast-day-icon">' + (day.icon || '') + '</div>';
        html += '<div class="forecast-day-temps">';
        html += '<span class="forecast-high">' + day.high_c + '\u00b0</span>';
        html += '<span class="forecast-low">' + day.low_c + '\u00b0</span>';
        html += '</div>';
        if (day.precip_prob > 20) {
            html += '<div class="forecast-day-precip">' + day.precip_prob + '%</div>';
        }
        html += '</div>';
    });
    html += '</div>';
    
    panel.innerHTML = html;
    bar.parentNode.insertBefore(panel, bar.nextSibling);
    
    // Auto close after 8 seconds
    setTimeout(function() {
        var p = document.getElementById('forecastPanel');
        if (p) p.remove();
    }, 8000);
}

// ============================================
// NEARBY ORDERS (Interactive Map)
// ============================================

var nearbyMap = null;
var nearbyMarkers = [];
var nearbyInfoWindow = null;
var courierMarker = null;
var courierLocation = null;
var lastSearchCenter = null;
var searchAreaBtn = null;

function loadNearby() {
    var el = document.getElementById('nearbyContent');
    if (!el) return;
    
    // Show loading state
    var listEl = document.getElementById('nearbyList');
    if (listEl) listEl.innerHTML = '<div class="loading-state"><div class="spinner-ring"></div><span>Getting your location...</span></div>';
    
    // Get courier's location
    if (!navigator.geolocation) {
        if (listEl) listEl.innerHTML = '<div class="empty-state"><p>Geolocation not supported by your browser.</p></div>';
        return;
    }
    
    navigator.geolocation.getCurrentPosition(
        function(pos) {
            courierLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
            initNearbyMap(courierLocation);
            fetchNearbyOrders(courierLocation);
        },
        function(err) {
            // Default to MTCC North if location denied
            courierLocation = { lat: 43.6445, lng: -79.3871 };
            if (listEl) listEl.innerHTML = '<div class="nearby-location-notice"><span>\u26a0\ufe0f Location unavailable — showing orders near MTCC North</span></div>';
            initNearbyMap(courierLocation);
            fetchNearbyOrders(courierLocation);
        },
        { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
    );
}

function initNearbyMap(center) {
    var mapEl = document.getElementById('nearbyMap');
    if (!mapEl) return;
    
    if (nearbyMap) {
        nearbyMap.setCenter(center);
        return;
    }
    
    nearbyMap = new google.maps.Map(mapEl, {
        center: center,
        zoom: 12,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        zoomControl: true,
        styles: [
            { featureType: 'poi', stylers: [{ visibility: 'off' }] },
            { featureType: 'transit', stylers: [{ visibility: 'simplified' }] }
        ]
    });
    
    // Courier location marker (blue dot)
    courierMarker = new google.maps.Marker({
        position: center,
        map: nearbyMap,
        title: 'Your location',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 10,
            fillColor: '#7c3aed',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 3
        },
        zIndex: 999
    });
    
    nearbyInfoWindow = new google.maps.InfoWindow();
    
    // "Search this area" button — appears when map is panned/zoomed away
    searchAreaBtn = document.createElement('div');
    searchAreaBtn.className = 'search-area-btn';
    searchAreaBtn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Search this area';
    searchAreaBtn.style.display = 'none';
    searchAreaBtn.addEventListener('click', function() {
        var mapCenter = nearbyMap.getCenter();
        var newCenter = { lat: mapCenter.lat(), lng: mapCenter.lng() };
        lastSearchCenter = newCenter;
        searchAreaBtn.style.display = 'none';
        fetchNearbyOrders(newCenter);
        haptic.tap();
    });
    mapEl.appendChild(searchAreaBtn);
    
    // Show button when user pans or zooms away from last search
    nearbyMap.addListener('idle', function() {
        if (!lastSearchCenter) return;
        var mapCenter = nearbyMap.getCenter();
        var dist = Math.sqrt(
            Math.pow((mapCenter.lat() - lastSearchCenter.lat) * 111, 2) +
            Math.pow((mapCenter.lng() - lastSearchCenter.lng) * 111 * Math.cos(lastSearchCenter.lat * Math.PI / 180), 2)
        );
        // Show if panned more than ~1.5 km
        if (dist > 1.5) {
            searchAreaBtn.style.display = 'flex';
        } else {
            searchAreaBtn.style.display = 'none';
        }
    });
}

function fetchNearbyOrders(location) {
    lastSearchCenter = location;
    var listEl = document.getElementById('nearbyList');
    if (listEl && !listEl.querySelector('.nearby-location-notice')) {
        listEl.innerHTML = '<div class="loading-state"><div class="spinner-ring"></div><span>Finding nearby orders...</span></div>';
    }
    
    apiCall('get_nearby_orders', {
        lat: location.lat,
        lng: location.lng,
        radius: 15
    }, function(result) {
        if (!result.success || !result.orders) {
            if (listEl) listEl.innerHTML = '<div class="empty-state"><p>No nearby orders found.</p></div>';
            return;
        }
        
        renderNearbyOrders(result.orders, location);
    });
}

function renderNearbyOrders(orders, courierLoc) {
    var listEl = document.getElementById('nearbyList');
    if (!listEl) return;
    
    // Clear old markers
    nearbyMarkers.forEach(function(m) { m.setMarker ? m.setMarker(null) : m.setMap(null); });
    nearbyMarkers = [];
    
    if (orders.length === 0) {
        listEl.innerHTML = '<div class="empty-state"><p>No available orders within 15 km.</p></div>';
        return;
    }
    
    var bounds = new google.maps.LatLngBounds();
    bounds.extend(courierLoc);
    
    var html = '<div class="nearby-header">' + orders.length + ' order' + (orders.length !== 1 ? 's' : '') + ' nearby</div>';
    
    orders.forEach(function(order, i) {
        // Add map marker
        if (order.pickup_coords) {
            var markerPos = { lat: order.pickup_coords.lat, lng: order.pickup_coords.lng };
            bounds.extend(markerPos);
            
            var marker = new google.maps.Marker({
                position: markerPos,
                map: nearbyMap,
                title: order.vendor_name + ' — ' + order.ref,
                label: {
                    text: String(i + 1),
                    color: '#fff',
                    fontSize: '11px',
                    fontWeight: '700'
                },
                icon: {
                    path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z',
                    fillColor: '#22c55e',
                    fillOpacity: 1,
                    strokeColor: '#ffffff',
                    strokeWeight: 2,
                    scale: 1.5,
                    anchor: new google.maps.Point(12, 24),
                    labelOrigin: new google.maps.Point(12, 9)
                }
            });
            
            // Info window on click
            (function(o, m) {
                m.addListener('click', function() {
                    nearbyInfoWindow.setContent(
                        '<div style="font-family:system-ui;padding:4px;">' +
                        '<strong>' + escapeHtml(o.ref) + '</strong><br>' +
                        '<span style="color:#666;">' + escapeHtml(o.vendor_name || 'Vendor') + '</span><br>' +
                        '<span style="color:#16a34a;font-weight:600;">' + (o.distance_from_courier_km || '?') + ' km &middot; ~' + (o.est_pickup_min || '?') + ' min</span>' +
                        '</div>'
                    );
                    nearbyInfoWindow.open(nearbyMap, m);
                });
            })(order, marker);
            
            nearbyMarkers.push(marker);
        }
        
        // Dropoff marker (smaller, red)
        if (order.dropoff_coords) {
            bounds.extend({ lat: order.dropoff_coords.lat, lng: order.dropoff_coords.lng });
        }
        
        // List card
        var urgency = getUrgencyInfo(order);
        orderCache[order.ref] = order;
        
        html += '<div class="nearby-card" data-ref="' + escapeHtml(order.ref) + '" data-mode="delivery" data-transit="0">';
        html += '<div class="nearby-card-top">';
        html += '<div class="nearby-card-num">' + (i + 1) + '</div>';
        html += '<div class="nearby-card-info">';
        html += '<div class="nearby-card-ref"><span class="ref-label">ID:</span> ' + escapeHtml(order.ref) + '</div>';
        html += '<div class="nearby-card-vendor">' + escapeHtml(order.vendor_name || 'Vendor') + '</div>';
        html += '</div>';
        html += '<div class="nearby-card-dist">';
        html += '<div class="nearby-dist-km">' + (order.distance_from_courier_km || '—') + ' <small>km</small></div>';
        html += '<div class="nearby-dist-min">~' + (order.est_pickup_min || '?') + ' min</div>';
        html += '</div>';
        html += '</div>';
        
        // Destination
        html += '<div class="nearby-card-dest">';
        html += '<span class="nearby-dest-arrow">\u2192</span> ' + escapeHtml(order.destination || 'MTCC');
        if (order.size) html += ' <span class="nearby-card-meta">\u00b7 ' + escapeHtml(order.size) + '</span>';
        html += '</div>';
        
        // Payout
        if (order.est_payout) {
            html += '<div class="nearby-card-payout">$' + parseFloat(order.est_payout).toFixed(2) + '</div>';
        }
        
        html += '</div>';
    });
    
    listEl.innerHTML = html;
    
    // Fit map to bounds
    if (nearbyMap && bounds) {
        nearbyMap.fitBounds(bounds, { padding: 40 });
    }
    
    // Make nearby cards clickable (open detail panel)
    listEl.querySelectorAll('.nearby-card').forEach(function(card) {
        card.addEventListener('click', function() {
            var ref = card.getAttribute('data-ref');
            if (ref && orderCache[ref]) {
                showOrderDetail(ref, 'delivery');
            }
        });
    });
}


function getStatusTransitions(currentStatus) {
    var role = currentUser ? currentUser.role : '';
    var allowed = currentUser ? (currentUser.allowed_statuses || []) : [];

    var transitions = {};
    if (role === 'courier') {
        // Couriers follow strict flow: accept -> pickup -> deliver
        transitions = {
            ready: ['dispatched'],
            dispatched: ['shipped'],
            shipped: ['delivered'],
        };
    } else {
        // Admin/Staff can skip dispatched for 3rd-party couriers
        transitions = {
            ready: ['dispatched', 'shipped'],
            dispatched: ['shipped'],
            shipped: ['delivered'],
            delivered: ['pickedup'],
        };
    }

    var possible = transitions[currentStatus] || [];
    return possible.filter(function(s) { return allowed.indexOf(s) !== -1; });
}

// ============================================
// Urgency Helpers
// ============================================


function fetchRouteInfo(ref) {
    apiCall('get_route_info', { ref: ref }, function(result) {
        if (!result.success || !result.route) return;
        
        // Update cache
        if (orderCache[ref]) {
            orderCache[ref].route_distance_km = result.route.distance_km;
            orderCache[ref].route_duration_min = result.route.duration_min;
            orderCache[ref].route_directions_url = result.route.directions_url;
            orderCache[ref].route_static_map = result.route.static_map_url;
        }
        
        // Update route badge in detail panel if visible
        var badge = document.querySelector('.route-info-badge');
        if (badge) {
            badge.innerHTML = '<span class="route-info-dist">Distance: ' + result.route.distance_km + ' km</span>' +
                              '<span class="route-info-sep">\u00b7</span>' +
                              '<span class="route-info-eta">Drive time: ~' + result.route.duration_min + ' min</span>';
            badge.classList.add('loaded');
        }
    });
}

function getUrgencyInfo(order) {
    var hr = order.hours_remaining;
    if (hr === null || hr === undefined || hr <= 0) {
        if (order.status === 'printing' || order.status === 'ready_to_ship') {
            return { level: 'pipeline', color: '#9ca3af', label: '', cls: 'urgency-pipeline' };
        }
        return { level: 'normal', color: '#059669', label: '', cls: 'urgency-normal' };
    }
    if (hr <= 2) return { level: 'red', color: '#dc2626', label: formatCountdown(hr), cls: 'urgency-red' };
    if (hr <= 4) return { level: 'orange', color: '#ea580c', label: formatCountdown(hr), cls: 'urgency-orange' };
    return { level: 'normal', color: '#059669', label: '', cls: 'urgency-normal' };
}

function formatCountdown(hours) {
    if (hours <= 0) return 'OVERDUE';
    if (hours < 1) return Math.round(hours * 60) + ' min left';
    if (hours < 2) {
        var h = Math.floor(hours);
        var m = Math.round((hours - h) * 60);
        return h + 'h ' + m + 'm left';
    }
    return Math.round(hours * 10) / 10 + ' hrs left';
}

function getPipelineTierClass(order) {
    var tier = order.pipeline_tier || 'later';
    if (tier === '24h') return 'pipeline-24h';
    if (tier === '48h') return 'pipeline-48h';
    if (tier === '72h') return 'pipeline-72h';
    return 'pipeline-later';
}

// ============================================
// Order Card Rendering
// ============================================

function renderOrderCard(order, mode) {
    orderCache[order.ref] = order;
    var urgency = getUrgencyInfo(order);
    var isPipeline = (mode === 'upcoming');
    var isMTCCCard = (mode === 'pickup' || mode === 'upcoming_mtcc' || mode === 'complete');
    var pipelineCls = isPipeline ? ' pipeline-card ' + getPipelineTierClass(order) : '';
    var isInTransit = (mode === 'delivery' && order.status === 'shipped');
    var transitCls = isInTransit ? ' in-transit-card' : '';
    var statusClass = 'status-' + order.status;
    var badgeClass = 'badge-' + order.status;

    var mtccCls = isMTCCCard ? ' mtcc-card' : '';
    var html = '<div class="order-card ' + statusClass + pipelineCls + transitCls + mtccCls + '" data-urgency="' + urgency.level + '" data-ref="' + escapeHtml(order.ref) + '" data-mode="' + mode + '" data-transit="' + (isInTransit ? '1' : '0') + '">';

    // Transit hero banner (purple gradient)
    if (isInTransit) {
        html += '<div class="transit-hero-banner">';
        html += '<div class="thb-left"><span class="transit-pulse"></span> <strong>IN TRANSIT</strong></div>';
        html += '<div class="thb-right">View Details \u203A</div>';
        html += '</div>';
    }

    // Due date bar flush top, color-coded
    if (order.due_date_formatted || order.due_date) {
        var dueFull = order.due_date_formatted || order.due_date;
        var timeStr = '';
        if (order.due_time_formatted && order.due_time_formatted !== 'Anytime') timeStr = order.due_time_formatted;
        var barCls = 'card-due-bar';
        if (isMTCCCard) {
            // MTCC cards: color by MTCC phase (grouped statuses share same color)
            var mtccPhase = 'default';
            if (['preflight', 'file_issue', 'printing'].indexOf(order.status) !== -1) mtccPhase = 'production';
            else if (['ready'].indexOf(order.status) !== -1) mtccPhase = 'preparing';
            else if (['dispatched', 'shipped'].indexOf(order.status) !== -1) mtccPhase = 'ontheway';
            else if (order.status === 'delivered') mtccPhase = 'ready-for-pickup';
            else if (order.status === 'pickedup') mtccPhase = 'pickedup';
            else if (order.status === 'missing') mtccPhase = 'missing';
            barCls += ' due-mtcc-' + mtccPhase;
        } else {
            // Courier cards: color by status, urgency overrides for red/orange
            if (urgency.level === 'red') barCls += ' due-urgent-red';
            else if (urgency.level === 'orange') barCls += ' due-urgent-orange';
            else barCls += ' due-status-' + order.status;
        }
        html += '<div class="' + barCls + '">';
        if (isMTCCCard) {
            // MTCC: consistent format "Due Friday, Dec 26, 2025  |  by: 3:00 PM"
            var mtccTimeLabel = timeStr || 'Anytime';
            html += '<span class="due-text-mtcc">Due ' + escapeHtml(dueFull) + '  |  by: ' + escapeHtml(mtccTimeLabel) + '</span>';
            html += '<span class="order-status-badge ' + badgeClass + ' badge-sm due-bar-badge">' + (statusLabels[order.status] || order.status) + '</span>';
        } else {
            // Courier: consistent format matching MTCC + status badge
            var courierTimeLabel = timeStr || 'Anytime';
            html += '<span class="due-text-mtcc">Due ' + escapeHtml(dueFull) + '  |  by: ' + escapeHtml(courierTimeLabel) + '</span>';
            html += '<span class="order-status-badge ' + badgeClass + ' badge-sm due-bar-badge">' + (statusLabels[order.status] || order.status) + '</span>';
        }
        html += '</div>';
    }

    // Top row: ID ref (left) + payout (right, courier only)
    html += '<div class="order-card-top">';
    html += '<div class="order-card-top-left">';
    html += '<div class="order-ref"><span class="ref-label">ID:</span> ' + escapeHtml(order.ref) + '</div>';
    html += '</div>';
    // Base pay + bonus (hide for MTCC card modes)
    if (order.est_payout && !isMTCCCard) {
        var basePay = 0, bonusTotal = 0;
        if (order.est_payout_breakdown && order.est_payout_breakdown.length > 0) {
            order.est_payout_breakdown.forEach(function(b) {
                if (b.label === 'Base rate') basePay = b.amount;
                else bonusTotal += b.amount;
            });
        }
        if (basePay === 0) basePay = order.est_payout;
        html += '<div class="card-payout-area"><div class="card-payout-big">$' + parseFloat(basePay).toFixed(2) + '</div>';
        if (bonusTotal > 0) html += '<div class="card-payout-bonus">+$' + bonusTotal.toFixed(2) + ' bonus</div>';
        html += '</div>';
    }
    html += '</div>';

    if (isMTCCCard) {
        html += '<div class="order-card-body">';
        html += '<div class="order-detail"><span class="order-detail-label">Customer</span><span class="order-detail-value">' + escapeHtml(order.customer_name) + '</span></div>';
        html += '<div class="order-detail"><span class="order-detail-label">Event</span><span class="order-detail-value">' + escapeHtml(isMTCCCard ? (order.event || order.event_acronym) : (order.event_acronym || order.event)) + (order.building ? ' \u2014 ' + escapeHtml(order.building) : '') + '</span></div>';
        if (mode === 'complete' && order.delivered_at) {
            var pDate = new Date(order.delivered_at);
            var pStr = isNaN(pDate) ? '' : pDate.toLocaleDateString('en-US', {month: 'short', day: 'numeric'}) + ', ' + pDate.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
            if (pStr) html += '<div class="order-detail"><span class="order-detail-label">Picked Up</span><span class="order-detail-value">' + pStr + '</span></div>';
        }
        html += '</div>';
    } else {
        var pickup = order.vendor_name || 'Vendor';
        var pickupAddr = (order.vendor_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
        var dropoff = order.destination || 'Destination';
        var dropoffAddr = (order.destination_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');

        html += '<div class="card-route-vertical">';
        html += '<div class="card-route-stop"><div class="card-route-dot-col"><div class="card-route-emoji">\ud83d\udce6</div><div class="card-route-line-v"></div></div>';
        html += '<div class="card-route-info"><div class="card-route-label">PICKUP</div><div class="card-route-name">' + escapeHtml(pickup) + '</div>';
        if (pickupAddr) html += '<div class="card-route-addr">' + escapeHtml(pickupAddr) + '</div>';
        html += '</div></div>';
        html += '<div class="card-route-stop"><div class="card-route-dot-col"><div class="card-route-emoji">\ud83d\udccd</div></div>';
        html += '<div class="card-route-info"><div class="card-route-label">DROPOFF</div><div class="card-route-name">' + escapeHtml(dropoff) + '</div>';
        if (dropoffAddr) html += '<div class="card-route-addr">' + escapeHtml(dropoffAddr) + '</div>';
        html += '</div></div></div>';
    }

    // Footer: pkgs · size (status badge now in due bar for all cards)
    if (!isMTCCCard) {
        html += '<div class="order-card-footer">';
        var qty = order.quantity || 1;
        html += '<span class="card-meta">\ud83d\udce6 ' + qty + '</span>';
        if (order.size) {
            html += '<span class="card-footer-dot">\u00b7</span>';
            html += '<span class="card-meta">\ud83d\udcd0 ' + escapeHtml(order.size) + '</span>';
        }
        if (order.has_issue) {
            html += '<span class="card-footer-spacer"></span>';
            html += '<span class="order-issue-badge">⚠️ Issue</span>';
        }
        html += '</div>';
    }

    html += '</div>';
    return html;
}


// ============================================
// Full-Page Transit View
// ============================================

function showTransitView(ref, mode) {
    console.log('[ShowTransit] Called for ref=' + ref + ' mode=' + mode);
    var order = orderCache[ref];
    if (!order) { console.warn('[ShowTransit] No order in cache!'); showOrderDetail(ref, mode); return; }
    haptic.tap();

    var pickup = order.vendor_name || 'Vendor';
    var pickupAddr = (order.vendor_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
    var dropoff = order.destination || 'Destination';
    var dropoffAddr = (order.destination_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
    var urgency = getUrgencyInfo(order);

    // Map embed: show destination pin only (no directions box)
    var mapQuery = '';
    if (dropoffAddr) {
        mapQuery = 'https://www.google.com/maps?q=' + encodeURIComponent(dropoffAddr) + '&z=15&output=embed';
    } else if (pickupAddr) {
        mapQuery = 'https://www.google.com/maps?q=' + encodeURIComponent(pickupAddr) + '&z=15&output=embed';
    }

    // Navigation URLs
    var destForNav = dropoffAddr || pickupAddr || '';
    var googleNavUrl = '';
    var appleNavUrl = '';
    if (destForNav) {
        googleNavUrl = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(destForNav) + '&travelmode=driving';
        appleNavUrl = 'https://maps.apple.com/?daddr=' + encodeURIComponent(destForNav) + '&dirflg=d';
    }

    // Due string
    var dueStr = '';
    if (order.due_date_formatted) {
        dueStr = order.due_date_formatted;
        if (order.due_time_formatted && order.due_time_formatted !== 'Anytime') dueStr += ' at ' + order.due_time_formatted;
    }

    // Countdown
    var countdownTarget = getCountdownTarget(order);
    var showCountdown = countdownTarget && order.hours_remaining !== null && order.hours_remaining > 0 && order.hours_remaining <= 24;

    // Urgency class
    var urgCls = '';
    if (urgency.level === 'red') urgCls = ' urgency-red';
    else if (urgency.level === 'orange') urgCls = ' urgency-orange';

    // Phone icon SVG
    var icoPhone = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>';

    // ============ BUILD HTML ============
    var html = '<div class="tv-shell">';

    // MAP (destination pin only - no directions box)
    if (mapQuery) {
        html += '<div class="tv-map"><iframe src="' + mapQuery + '" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
    } else {
        html += '<div class="tv-map tv-map-empty"><svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.12)" stroke-width="1.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg></div>';
    }

    // FLOATING TOP BAR (strong gradient for visibility)
    html += '<div class="tv-topbar">';
    html += '<button class="tv-back" onclick="closeTransitView()"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round"><polyline points="15 18 9 12 15 6"/></svg></button>';
    html += '<div class="tv-live-pill"><span class="tv-live-dot"></span><span class="tv-live-text">In Transit</span></div>';
    html += '<div class="tv-topbar-ref">' + escapeHtml(order.ref) + '</div>';
    html += '</div>';

    // BOTTOM SHEET
    html += '<div class="tv-sheet">';
    html += '<div class="tv-handle"><div class="tv-handle-bar"></div></div>';

    // Destination (no building pill)
    html += '<div class="tv-destination">';
    html += '<div class="tv-dest-overline">Delivering to</div>';
    html += '<div class="tv-dest-name">' + escapeHtml(dropoff) + '</div>';
    if (dropoffAddr) html += '<div class="tv-dest-addr">' + escapeHtml(dropoffAddr) + '</div>';
    html += '</div>';

    // Instructions (only if actual instructions exist)
    if (order.destination_instructions) {
        html += '<div class="tv-instructions"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>' + escapeHtml(order.destination_instructions) + '</div>';
    }

    // Due time bar
    if (dueStr) {
        html += '<div class="tv-due-bar' + urgCls + '">';
        html += '<div class="tv-due-info">';
        html += '<div class="tv-due-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>';
        html += '<div class="tv-due-text">Deliver by<strong>' + escapeHtml(dueStr) + '</strong></div>';
        html += '</div>';
        if (showCountdown) {
            html += '<div class="tv-due-countdown" data-countdown-target="' + countdownTarget + '">--:--</div>';
        }
        html += '</div>';
    }

    // Navigation buttons (Google Maps + Apple Maps side by side)
    if (googleNavUrl) {
        html += '<div class="tv-nav-buttons">';
        html += '<a class="tv-navigate tv-nav-google" href="' + googleNavUrl + '" target="_blank" rel="noopener">';
        html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>';
        html += ' Google Maps</a>';
        html += '<a class="tv-navigate tv-nav-apple" href="' + appleNavUrl + '" target="_blank" rel="noopener">';
        html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        html += ' Apple Maps</a>';
        html += '</div>';
    }

    // Payout (purple/brand theme - distinct from green due bar)
    if (order.est_payout) {
        html += '<div class="tv-payout">';
        html += '<span class="tv-payout-label">Estimated payout</span>';
        html += '<span class="tv-payout-amount">$' + parseFloat(order.est_payout).toFixed(2) + '</span>';
        html += '</div>';
    }

    // Contact buttons — no customer phone for MTCC deliveries
    html += '<div class="tv-contact-btns">';
    html += '<a class="tv-contact-btn tv-contact-support" href="tel:' + SUPPORT_PHONES.admin.number.replace(/[^0-9+]/g, '') + '">' + icoPhone + ' Print Stuff</a>';
    var isMTCCDest = (order.destination_type === 'mtcc' || (order.destination || '').toLowerCase().indexOf('mtcc') !== -1);
    if (order.customer_phone && !isMTCCDest) {
        html += '<a class="tv-contact-btn tv-contact-customer" href="tel:' + order.customer_phone.replace(/[^0-9+]/g, '') + '">' + icoPhone + ' Customer</a>';
    }
    html += '<a class="tv-contact-btn tv-contact-support" href="tel:' + SUPPORT_PHONES.mtcc.number.replace(/[^0-9+]/g, '') + '">' + icoPhone + ' MTCC</a>';
    html += '</div>';

    // Secondary actions
    html += '<div class="tv-secondary">';
    html += '<button class="tv-btn-details" onclick="closeTransitView(); showOrderDetail(\'' + escapeAttr(order.ref) + '\', \'delivery\')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg> Order Details & Actions</button>';
    html += '<button class="tv-btn-back" onclick="closeTransitView()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> All Deliveries</button>';
    html += '</div>';

    html += '</div>'; // tv-sheet
    html += '</div>'; // tv-shell

    // Render
    var container = document.getElementById('transitView');
    if (!container) { showOrderDetail(ref, 'delivery'); return; }
    container.innerHTML = html;
    container.style.display = 'block';
    container.classList.add('active');
    document.body.style.overflow = 'hidden';

    // Start countdown timers
    startCountdownTimers();
}

function closeTransitView() {
    transitDismissedByUser = true;
    var container = document.getElementById('transitView');
    if (container) {
        container.classList.remove('active');
        container.style.display = 'none';
        container.innerHTML = '';
    }
    document.body.style.overflow = '';
    // Show return banner if there's still an active transit order
    if (activeTransitRef && orderCache[activeTransitRef] && orderCache[activeTransitRef].status === 'shipped') {
        showReturnBanner();
    }
}

// ============================================
// AUTO-LAUNCH TRANSIT VIEW
// Like DoorDash/Uber: active delivery = the screen
// ============================================

function autoLaunchTransit() {
    console.log('[AutoLaunch] Called. cachedActive.length=' + cachedActive.length);
    var transitOrder = null;
    for (var i = 0; i < cachedActive.length; i++) {
        if (cachedActive[i].status === 'shipped') {
            transitOrder = cachedActive[i];
            break;
        }
    }
    if (!transitOrder) {
        activeTransitRef = null;
        removeReturnBanner();
        return;
    }
    console.log('[AutoLaunch] Found in-transit order: ' + transitOrder.ref);
    activeTransitRef = transitOrder.ref;
    orderCache[transitOrder.ref] = transitOrder;

    // Don't auto-open if user dismissed it or already showing
    if (transitDismissedByUser) {
        console.log('[AutoLaunch] User dismissed transit view, skipping auto-launch');
        return;
    }
    var tv = document.getElementById('transitView');
    if (tv && tv.classList.contains('active')) {
        console.log('[AutoLaunch] Transit view already open, skipping');
        return;
    }
    // Launch after a short delay to let DOM settle
    setTimeout(function() {
        console.log('[AutoLaunch] Opening transit view for ' + transitOrder.ref);
        showTransitView(transitOrder.ref, 'delivery');
    }, 200);
}

// Return-to-transit banner (shown when user closes transit view)
function showReturnBanner() {
    removeReturnBanner();
    var el = document.getElementById('deliveriesContent');
    if (!el || !activeTransitRef || !orderCache[activeTransitRef]) return;
    var order = orderCache[activeTransitRef];
    var ref = activeTransitRef;
    var banner = document.createElement('div');
    banner.id = 'returnTransitBanner';
    banner.className = 'return-transit-banner';
    // DIRECT onclick - no delegation
    banner.addEventListener('click', function() {
        console.log('[ReturnBanner] Tapped, opening transit view');
        transitDismissedByUser = false;
        haptic.tap();
        showTransitView(ref, 'delivery');
    });
    banner.innerHTML = '<div class="rtb-left">' +
        '<span class="rtb-pulse"></span>' +
        '<div class="rtb-text">' +
        '<strong>Active Delivery: ' + escapeHtml(order.ref) + '</strong>' +
        '<span>' + escapeHtml(order.destination || 'Tap to return') + '</span>' +
        '</div></div>' +
        '<div class="rtb-arrow">Open \u203A</div>';
    el.insertBefore(banner, el.firstChild);
}

function removeReturnBanner() {
    var b = document.getElementById('returnTransitBanner');
    if (b) b.remove();
}

// Re-launch on app resume (close & reopen)
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && currentUser) {
        // Refresh data - autoLaunchTransit will fire from the callback
        if (activeTransitRef) loadMyDeliveries();
    }
});


// ============================================
// Order Detail Panel (Slide-up)
// ============================================

// ============================================
// MTCC Staff Detail Panel (simplified)
// ============================================

function renderMTCCDetailPanel(order, mode) {
    var badgeClass = 'badge-' + order.status;
    var html = '';

    // Determine MTCC phase color for header
    var phaseColor = '#64748b';
    if (['preflight', 'file_issue', 'printing'].indexOf(order.status) !== -1) phaseColor = '#6366f1';
    else if (order.status === 'ready') phaseColor = '#d97706';
    else if (['dispatched', 'shipped'].indexOf(order.status) !== -1) phaseColor = '#14b8a6';
    else if (order.status === 'delivered') phaseColor = '#059669';
    else if (order.status === 'pickedup') phaseColor = '#22c55e';
    else if (order.status === 'missing') phaseColor = '#dc2626';

    // Due date header — colored by status phase (Fix 2)
    var dueStr = order.due_date_formatted || order.due_date || '';
    var timeStr = (order.due_time_formatted && order.due_time_formatted !== 'Anytime') ? order.due_time_formatted : 'Anytime';
    html += '<div class="mtcc-detail-header">';
    if (dueStr) {
        html += '<div class="mtcc-detail-due"><span class="mtcc-due-label">DUE DATE</span><span class="mtcc-due-value">' + escapeHtml(dueStr) + '  |  by: ' + escapeHtml(timeStr) + '</span></div>';
    }
    html += '<span class="order-status-badge ' + badgeClass + ' mtcc-header-badge">' + (statusLabels[order.status] || order.status) + '</span>';
    html += '</div>';

    // Order ID + barcode (Fix 6)
    html += '<div class="mtcc-detail-id-section">';
    html += '<div class="mtcc-detail-id-left">';
    html += '<div class="mtcc-detail-id-label">ORDER</div>';
    html += '<div class="mtcc-detail-id-value">' + escapeHtml(order.ref) + '</div>';
    html += '</div>';
    if (order.tracking) {
        html += '<div class="mtcc-detail-id-right">';
        html += '<div class="mtcc-detail-id-label">CODE</div>';
        html += '<div class="mtcc-detail-id-code">' + escapeHtml(order.tracking) + '</div>';
        html += '</div>';
    }
    html += '</div>';

    // Order details grid
    html += '<div class="mtcc-detail-grid">';
    html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Customer</span><span class="mtcc-detail-value">' + escapeHtml(order.customer_name) + '</span></div>';
    if (order.customer_phone) html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Phone</span><a class="mtcc-detail-value mtcc-detail-link" href="tel:' + order.customer_phone.replace(/[^0-9+]/g, '') + '">' + escapeHtml(order.customer_phone) + '</a></div>';
    if (order.customer_email) html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Email</span><a class="mtcc-detail-value mtcc-detail-link" href="mailto:' + escapeAttr(order.customer_email) + '">' + escapeHtml(order.customer_email) + '</a></div>';
    html += '<div class="mtcc-detail-divider"></div>';
    html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Event</span><span class="mtcc-detail-value">' + escapeHtml(order.event || order.event_acronym || '') + (order.building ? ' \u2014 ' + escapeHtml(order.building) : '') + '</span></div>';
    html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Material</span><span class="mtcc-detail-value">' + escapeHtml(order.material) + '</span></div>';
    html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Size</span><span class="mtcc-detail-value">' + escapeHtml(order.size) + '</span></div>';
    if (order.quantity > 1) html += '<div class="mtcc-detail-row"><span class="mtcc-detail-label">Quantity</span><span class="mtcc-detail-value">' + order.quantity + '</span></div>';
    if (order.notes) {
        html += '<div class="mtcc-detail-divider"></div>';
        html += '<div class="mtcc-detail-row mtcc-detail-notes"><span class="mtcc-detail-label">Notes</span><span class="mtcc-detail-value">' + escapeHtml(order.notes) + '</span></div>';
    }
    html += '</div>';

    // Actions (Fix 3: 50/50 buttons for pickup, Report Issue always visible)
    html += '<div class="mtcc-detail-actions">';
    if (order.status === 'delivered') {
        html += '<div class="mtcc-btn-row">';
        html += '<button class="mtcc-action-btn mtcc-btn-confirm" onclick="updateOrderStatus(\'' + escapeAttr(order.ref) + '\', \'pickedup\')">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Confirm Pick Up</button>';
        html += '<button class="mtcc-action-btn mtcc-btn-scan" onclick="scanExpectedRef=\'' + escapeAttr(order.ref) + '\'; closeDetailPanel(); switchTab(\'scan\')">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="6" y1="8" x2="6" y2="16"/><line x1="10" y1="8" x2="10" y2="16"/><line x1="14" y1="8" x2="14" y2="16"/><line x1="18" y1="8" x2="18" y2="16"/></svg> Scan to Verify</button>';
        html += '</div>';
    }
    // Report Issue — ALWAYS visible for paid+ orders (Fix 1)
    if (typeof CourierIssues !== 'undefined') {
        var showStatuses = ['paid', 'preflight', 'printing', 'ready', 'dispatched', 'shipped', 'delivered'];
        if (showStatuses.indexOf(order.status) !== -1) {
            html += '<button class="mtcc-action-btn mtcc-btn-issue" onclick="CourierIssues.open(\'' + escapeAttr(order.ref) + '\')">';
            html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Report Issue</button>';
        }
    }
    html += '</div>';

    // Support buttons — 50/50 row (Fix 5)
    html += '<div class="mtcc-support-row">';
    html += '<a class="mtcc-support-btn" href="tel:+14378828822">';
    html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>';
    html += '<span>Call Support</span></a>';
    html += '<a class="mtcc-support-btn" href="mailto:orders@printstuff.ca">';
    html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M22 4l-10 7L2 4"/></svg>';
    html += '<span>Email Support</span></a>';
    html += '</div>';

    return html;
}

// Report Missing handler for MTCC staff
function reportMissing(ref) {
    var order = orderCache[ref];
    var customerName = order ? order.customer_name : ref;
    if (!confirm('Report order ' + ref + ' (' + customerName + ') as missing?\n\nThis will flag the order for investigation by Print Stuff.')) return;

    haptic.tap();
    var data = {
        ref: ref,
        issue_type: 'missing_item',
        issue_label: 'Missing Item',
        notes: 'Reported as missing by MTCC staff. Previous status: ' + (order ? order.status : 'unknown')
    };

    // First report the issue
    apiCall('report_issue', data, function(result) {
        if (!result.success) {
            haptic.error();
            showToast(result.error || 'Failed to report', 'error');
            return;
        }
        // Then update status to missing
        apiCall('update_status', { ref: ref, status: 'missing' }, function(statusResult) {
            if (statusResult.success) {
                haptic.confirm();
                showToast('Order reported as missing', 'success');
                closeDetailPanel();
                refreshTab(currentTab);
            } else {
                haptic.warning();
                showToast('Issue reported but status update failed: ' + (statusResult.error || ''), 'error');
            }
        });
    });
}

function showOrderDetail(ref, mode) {
    var order = orderCache[ref];
    if (!order) return;
    haptic.tap();
    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    var content = document.getElementById('detailContent');

    // MTCC staff gets a simplified detail panel
    if (currentUser && currentUser.role === 'mtcc_staff') {
        // Set panel header color to match status phase
        var pc = '#64748b';
        if (['preflight', 'file_issue', 'printing'].indexOf(order.status) !== -1) pc = '#6366f1';
        else if (order.status === 'ready') pc = '#d97706';
        else if (['dispatched', 'shipped'].indexOf(order.status) !== -1) pc = '#14b8a6';
        else if (order.status === 'delivered') pc = '#059669';
        else if (order.status === 'pickedup') pc = '#22c55e';
        else if (order.status === 'missing') pc = '#dc2626';
        panel.style.setProperty('--mtcc-header-color', pc);
        panel.classList.add('mtcc-panel');
        content.innerHTML = renderMTCCDetailPanel(order, mode);
        panel.classList.add('active');
        overlay.classList.add('active');
        return;
    }
    // Set courier panel header color by status
    var courierColor = '#64748b';
    if (order.status === 'ready') courierColor = '#d97706';
    else if (order.status === 'dispatched') courierColor = '#7c3aed';
    else if (order.status === 'shipped') courierColor = '#14b8a6';
    else if (order.status === 'delivered') courierColor = '#059669';
    else if (order.status === 'pickedup') courierColor = '#22c55e';
    else if (order.status === 'missing') courierColor = '#dc2626';
    panel.style.setProperty('--mtcc-header-color', courierColor);
    panel.classList.add('mtcc-panel');

    // Fetch route info if not already loaded (courier/admin only)
    if (order && !order.route_distance_km && (mode === 'delivery' || mode === 'available')) {
        fetchRouteInfo(ref);
    }
    var isPipeline = (mode === 'upcoming');
    var urgency = getUrgencyInfo(order);
    var badgeClass = 'badge-' + order.status;
    var phoneIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>';
    var html = '';

    // Fix 1+10: Due date bar with time default + status badge in header
    if (order.due_date && !isPipeline) {
        var dueStr = order.due_date_formatted || order.due_date;
        var timeStr = order.due_time_formatted || 'Anytime';
        html += '<div class="detail-due-bar">';
        html += '<div class="detail-due-left">';
        html += '<span class="detail-due-heading">DUE DATE</span>';
        html += '<span class="detail-due-date">' + escapeHtml(dueStr) + '  |  by: ' + escapeHtml(timeStr) + '</span>';
        html += '</div>';
        html += '<span class="order-status-badge ' + badgeClass + ' mtcc-header-badge">' + (statusLabels[order.status] || order.status) + '</span>';
        html += '</div>';
    }


    // Urgency banner (red/orange)
    if (urgency.level === 'red' || urgency.level === 'orange') {
        html += '<div class="detail-urgency-banner ' + urgency.cls + '">';
        html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
        html += '<div class="detail-urgency-text">';
        html += '<strong>' + (urgency.label || 'URGENT') + '</strong>';
        var dueText = '';
        if (order.is_today) dueText = 'Due today';
        else if (order.due_date_formatted) dueText = 'Due ' + order.due_date_formatted;
        if (order.due_time_formatted && order.due_time_formatted !== 'Anytime') dueText += ' at ' + order.due_time_formatted;
        if (dueText) html += '<span>' + dueText + '</span>';
        html += '</div></div>';
    }

    // Pipeline banner
    if (isPipeline) {
        html += '<div class="detail-pipeline-banner">';
        html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>';
        html += '<div class="pipeline-banner-info">';
        html += '<strong>Expected: ' + escapeHtml(order.due_date_formatted || order.due_date || 'TBD') + '</strong>';
        if (order.due_time_formatted && order.due_time_formatted !== 'Anytime') html += ' at ' + escapeHtml(order.due_time_formatted);
        html += '<br><span>Currently being printed — you\'ll be notified when ready.</span>';
        html += '</div></div>';
    }

    // Header — order ID + tracking (badge moved to due bar)
    html += '<div class="detail-order-header">';
    html += '<div><div class="detail-order-ref">' + escapeHtml(order.ref) + '</div><div class="detail-order-tracking">' + escapeHtml(order.tracking) + '</div></div>';
    html += '</div>';

    // Route Card
    html += renderRouteCard(order);

    // Order Details Grid
    html += '<div class="detail-grid">';
    html += renderDetailField('Customer', order.customer_name);
    html += renderDetailField('Event', (order.event || order.event_acronym) + (order.building ? ' \u2014 ' + order.building : ''));
    html += renderDetailField('Material', order.material);
    html += renderDetailField('Size', order.size);
    if (order.quantity > 1) html += renderDetailField('Quantity', order.quantity);
    if (order.delivery_tier) html += renderDetailField('Tier', order.delivery_tier);
    if (order.courier_name) html += renderDetailField('Courier', order.courier_name);
    if (order.notes) html += '<div class="detail-field full-width">' + renderDetailField('Notes', order.notes) + '</div>';
    html += '</div>';

    // Payout
    // Payout section
    if (!isPipeline && order.est_payout) {
        html += '<div class="detail-payout">';
        html += '<div class="payout-total-row">';
        html += '<span class="payout-total-label">Est. Payout</span>';
        html += '<span class="payout-total-amount">$' + parseFloat(order.est_payout).toFixed(2) + '</span>';
        html += '</div>';
        if (order.est_payout_breakdown && order.est_payout_breakdown.length > 0) {
            html += '<div class="payout-items">';
            order.est_payout_breakdown.forEach(function(item) {
                var isBonus = (item.label !== 'Base rate');
                html += '<div class="payout-row' + (isBonus ? ' payout-row-bonus' : '') + '">';
                html += '<span class="payout-row-label">' + (isBonus ? '+ ' : '') + escapeHtml(item.label) + '</span>';
                html += '<span class="payout-row-amount">$' + parseFloat(item.amount).toFixed(2) + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }
        html += '</div>';
    }

    // Actions
    if (!isPipeline) {
        if (mode === 'available' && order.status === 'ready' && currentUser.role === 'courier') {
            // Accept Delivery — sticky at bottom (Fix 3)
            html += '<div class="courier-sticky-action">';
            html += '<button class="status-action-btn btn-accept" onclick="acceptDelivery(\'' + escapeAttr(order.ref) + '\', this)"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Accept Delivery</button>';
            html += '</div>';
        } else if (mode === 'delivery' && order.status === 'dispatched' && currentUser.role === 'courier') {
            // Fix 8: Scan button sticky, release+issue 50/50
            html += '<div class="courier-detail-actions">';
            html += '<div class="courier-btn-row">';
            html += '<button class="release-btn" onclick="releaseDelivery(\'' + escapeAttr(order.ref) + '\', this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release</button>';
            if (typeof CourierIssues !== 'undefined') {
                html += '<button class="release-btn" style="border-color:#d97706;color:#d97706;" onclick="CourierIssues.open(\'' + escapeAttr(order.ref) + '\')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Report Issue</button>';
            }
            html += '</div>';
            html += '<div class="courier-sticky-action">';
            html += '<button class="status-action-btn btn-scan-goto" onclick="scanExpectedRef=\'' + escapeAttr(order.ref) + '\'; closeDetailPanel(); switchTab(\'scan\')"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="6" y1="8" x2="6" y2="16"/><line x1="10" y1="8" x2="10" y2="16"/><line x1="14" y1="8" x2="14" y2="16"/><line x1="18" y1="8" x2="18" y2="16"/></svg> Scan Barcode to Confirm Pickup</button>';
            html += '</div>';
            html += '</div>';
        } else if (mode !== 'completed') {
            var transitions = getStatusTransitions(order.status);
            if (transitions.length > 0) {
                html += '<div class="detail-actions">';
                // Photo required for delivery — no checkbox needed
                transitions.forEach(function(s) {
                    var label = s, icon = '';
                    if (s === 'shipped') { label = 'Confirm Pickup'; icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> '; }
                    else if (s === 'delivered') { label = 'Mark Delivered'; icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> '; }
                    else if (s === 'pickedup') { label = 'Confirm Pick Up'; icon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> '; }
                    html += '<button class="status-action-btn btn-' + s + '" onclick="updateOrderStatus(\'' + escapeAttr(order.ref) + '\', \'' + s + '\')">' + icon + label + '</button>';
                });
                // Release + Issue row for shipped orders
                if (mode === 'delivery' && order.status === 'shipped') {
                    html += '<div class="courier-btn-row" style="margin-top:8px;">';
                    html += '<button class="release-btn" onclick="releaseDelivery(\'' + escapeAttr(order.ref) + '\', this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release</button>';
                    if (typeof CourierIssues !== 'undefined') {
                        html += '<button class="release-btn" style="border-color:#d97706;color:#d97706;" onclick="CourierIssues.open(\'' + escapeAttr(order.ref) + '\')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Report Issue</button>';
                    }
                    html += '</div>';
                }
                html += '</div>';
            }
        }
    }

    // Fix 2: Quick contacts as 3 buttons in a row (Print Stuff, Vendor, MTCC)
    html += '<div class="courier-contact-row">';
    html += '<a class="courier-contact-btn" href="tel:' + SUPPORT_PHONES.admin.number.replace(/[^0-9+]/g, '') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72"/></svg><span>Print Stuff</span></a>';
    if (order.vendor_phone) {
        html += '<a class="courier-contact-btn" href="tel:' + order.vendor_phone.replace(/[^0-9+]/g, '') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg><span>' + escapeHtml(order.vendor_name || 'Vendor') + '</span></a>';
    }
    html += '<a class="courier-contact-btn" href="tel:' + SUPPORT_PHONES.mtcc.number.replace(/[^0-9+]/g, '') + '"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg><span>MTCC</span></a>';
    html += '</div>';

    content.innerHTML = html;
    panel.classList.add('active');
    overlay.classList.add('active');
}

// --- Route Card ---
function renderRouteCard(order) {
    var pickup = order.vendor_name || 'Vendor';
    var pickupAddr = (order.vendor_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
    var dropoff = order.destination || 'Destination';
    var dropoffAddr = (order.destination_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
    var instructions = order.destination_instructions || '';

    var html = '<div class="route-card">';
    html += '<div class="route-card-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Route</div>';

    // Embedded Google Map
    var singleAddrs = [];
    if (pickupAddr) singleAddrs.push(pickupAddr);
    if (dropoffAddr) singleAddrs.push(dropoffAddr);
    if (singleAddrs.length > 0) {
        var mapQuery = buildMapEmbed(singleAddrs);
        html += '<div class="route-map-container"><iframe class="route-map-iframe" src="' + mapQuery + '" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
        // Fix 5: Route info bar
        html += '<div class="route-info-badge"><span class="route-info-loading">Calculating route...</span></div>';
    }

    // Fix 6: Consistent pickup/dropoff icons (box blue, pin green, dashed line)
    html += '<div class="route-stops">';

    // Pickup — box icon (blue)
    html += '<div class="route-stop">';
    html += '<div class="route-icon-col"><svg class="route-icon-pickup" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg><div class="route-dashed-line"></div></div>';
    html += '<div class="route-stop-info"><div class="route-stop-label">PICKUP</div><div class="route-stop-name">' + escapeHtml(pickup) + '</div>';
    if (pickupAddr) html += '<div class="route-stop-addr">' + escapeHtml(pickupAddr) + '</div>';
    html += '</div>';
    if (pickupAddr) html += '<a class="route-nav-link" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(pickupAddr) + '" target="_blank" rel="noopener">Navigate</a>';
    html += '</div>';

    // Dropoff — pin icon (green)
    html += '<div class="route-stop">';
    html += '<div class="route-icon-col"><svg class="route-icon-dropoff" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>';
    html += '<div class="route-stop-info"><div class="route-stop-label">DROPOFF</div><div class="route-stop-name">' + escapeHtml(dropoff) + '</div>';
    if (dropoffAddr) html += '<div class="route-stop-addr">' + escapeHtml(dropoffAddr) + '</div>';
    if (instructions) html += '<div class="route-stop-instructions">' + escapeHtml(instructions) + '</div>';
    html += '</div>';
    if (dropoffAddr) html += '<a class="route-nav-link" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dropoffAddr) + '" target="_blank" rel="noopener">Navigate</a>';
    html += '</div>';
    html += '</div>';

    // Open in Maps button
    var navAddrs = [];
    if (pickupAddr) navAddrs.push(pickupAddr);
    if (dropoffAddr) navAddrs.push(dropoffAddr);
    if (navAddrs.length > 0) {
        html += '<div class="route-map-buttons' + (isAppleDevice() ? '' : ' single') + '">';
        html += '<a class="route-app-btn google" href="' + buildGoogleNavUrl(navAddrs) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> Google Maps</a>';
        if (isAppleDevice()) {
            html += '<a class="route-app-btn apple" href="' + buildAppleNavUrl(navAddrs) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Apple Maps</a>';
        }
        html += '</div>';
    }

    html += '</div>';
    return html;
}

function renderPayoutCard(order) {
    if (!order.est_payout && order.est_payout !== 0) return '';
    var breakdown = order.est_payout_breakdown || [];
    if (breakdown.length === 0 && !order.est_payout) return '';

    var html = '<div class="payout-card">';
    html += '<div class="payout-header"><span class="payout-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg> Est. Payout</span><span class="payout-total">$' + parseFloat(order.est_payout).toFixed(2) + '</span></div>';
    if (breakdown.length > 0) {
        html += '<div class="payout-breakdown">';
        breakdown.forEach(function(item) {
            html += '<div class="payout-line"><span>' + escapeHtml(item.label) + '</span><span>$' + parseFloat(item.amount).toFixed(2) + '</span></div>';
        });
        html += '</div>';
    }
    html += '</div>';
    return html;
}

function renderDetailField(label, value) {
    return '<div class="detail-field"><span class="detail-field-label">' + escapeHtml(label) + '</span><span class="detail-field-value">' + escapeHtml(value || '-') + '</span></div>';
}

function closeDetailPanel() {
    var panel = document.getElementById('detailPanel');
    panel.style.transition = '';
    panel.style.transform = '';
    panel.classList.remove('active');
    panel.classList.remove('mtcc-panel');
    document.getElementById('detailOverlay').classList.remove('active');
}

// ============================================
// Accept Delivery (from Available tab detail panel)
// ============================================

function acceptDelivery(ref, btnEl) {
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.textContent = 'Accepting...';
    }

    apiCall('accept_delivery', { ref: ref }, function(result) {
        if (result.success) {
            haptic.confirm();
            showToast('Delivery accepted!', 'success');
            closeDetailPanel();
            loadAvailable();
            // Pre-load my deliveries
            setTimeout(function() { loadMyDeliveries(); }, 500);
        } else {
            haptic.error();
            showToast(result.error || 'Failed to accept', 'error');
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Accept Delivery';
            }
        }
    });
}

// ============================================
// Status Updates
// ============================================

function updateOrderStatus(ref, newStatus) {
    // Check if photo needed
    // Photo required for all deliveries
    if (newStatus === 'delivered' && currentUser.role === 'courier') {
        openPhotoCapture(function(photoData) {
            doStatusUpdate(ref, newStatus, photoData);
        });
        return;
    }

    doStatusUpdate(ref, newStatus, null);
}

function doStatusUpdate(ref, newStatus, photoData) {
    var data = { ref: ref, status: newStatus };
    if (photoData) data.photo = photoData;

    apiCall('update_status', data, function(result) {
        if (result.success) {
            haptic.confirm();
            showToast(result.message, 'success');
            closeDetailPanel();
            hideScanResult();
            refreshTab(currentTab);
        } else {
            haptic.error();
            showToast(result.error || 'Update failed', 'error');
        }
    });
}

// ============================================
// Scanner
// ============================================

function toggleScanner() {
    if (scannerActive) {
        stopScanner();
    } else {
        startScanner();
    }
}

function startScanner() {
    var target = document.getElementById('scannerTarget');
    var toggleBtn = document.getElementById('scannerToggleBtn');

    // Ensure audio is initialized (requires user interaction context)
    haptic.initAudio();

    // Update button to show starting state
    if (toggleBtn) {
        toggleBtn.querySelector('span').textContent = 'Starting...';
    }

    Quagga.init({
        inputStream: {
            name: 'Live',
            type: 'LiveStream',
            target: target,
            constraints: {
                facingMode: 'environment',
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
        },
        decoder: {
            readers: ['code_128_reader', 'code_39_reader', 'ean_reader', 'ean_8_reader'],
        },
        locate: true,
    }, function(err) {
        if (err) {
            console.error('Scanner init error:', err);
            haptic.warning();
            showToast('Camera access denied or unavailable', 'error');
            if (toggleBtn) {
                toggleBtn.querySelector('span').textContent = 'Retry Camera';
            }
            return;
        }
        Quagga.start();
        scannerActive = true;
        if (toggleBtn) {
            toggleBtn.classList.add('active');
            toggleBtn.querySelector('span').textContent = 'Camera Active';
        }
    });

    Quagga.onDetected(function(result) {
        var code = result.codeResult.code;
        if (code && !scanLocked) {
            scanLocked = true;
            haptic.scanDetected();
            processScannedCode(code);
            // Camera stays live but locked — no new scans until status updated
        }
    });
}

function stopScanner() {
    if (scannerActive) {
        try { Quagga.stop(); } catch(e) {}
        scannerActive = false;
    }
    var toggleBtn = document.getElementById('scannerToggleBtn');
    if (toggleBtn) {
        toggleBtn.classList.remove('active');
        toggleBtn.querySelector('span').textContent = 'Restart Camera';
    }
}

function submitManualScan() {
    var input = document.getElementById('manualTracking');
    var val = (input.value || '').trim();
    if (!val) {
        haptic.warning();
        showToast('Enter a tracking number', 'info');
        return;
    }
    haptic.tap();
    processScannedCode(val);
    input.value = '';
}

function processScannedCode(code) {
    apiCall('scan_order', { tracking: code }, function(result) {
        if (!result.success) {
            haptic.error();
            showScanError(result.error || 'Order not found');
            scanLocked = false; // Unlock scanner so user can try again
            return;
        }
        haptic.success();
        showScanResult(result);
    });
}

function showScanResult(result) {
    var el = document.getElementById('scanResult');
    var order = result.order;
    var statuses = result.available_statuses || [];
    var badgeClass = 'badge-' + order.status;

    var html = '<div class="scan-result-card">';

    // Warn if scanned order doesn't match the order card they came from
    if (scanExpectedRef && order.ref && order.ref.toUpperCase() !== scanExpectedRef.toUpperCase()) {
        html += '<div class="scan-warning scan-warning-wrong">';
        html += '<span class="scan-warning-icon">&#9888;</span>';
        html += '<div><strong>Wrong item scanned</strong>';
        html += '<div class="scan-warning-detail">Expected ' + escapeHtml(scanExpectedRef) + ' but scanned ' + escapeHtml(order.ref) + '</div></div></div>';
        haptic.warning();
    }

    // Warn if order is assigned to a different courier
    if (currentUser && currentUser.role === 'courier' && order.courier_pin && order.courier_pin !== currentUser.pin) {
        html += '<div class="scan-warning scan-warning-other">';
        html += '<span class="scan-warning-icon">&#128721;</span>';
        html += '<div><strong>Assigned to another courier</strong>';
        html += '<div class="scan-warning-detail">This order is assigned to ' + escapeHtml(order.courier_name || 'another courier') + '</div></div></div>';
        haptic.warning();
    }

    // Vendor behind warning
    if (result.vendor_behind && result.vendor_warning) {
        html += '<div class="scan-warning scan-warning-vendor">';
        html += '<span class="scan-warning-icon">&#9888;</span>';
        html += '<div><strong>Vendor hasn\'t updated status</strong>';
        html += '<div class="scan-warning-detail">' + escapeHtml(result.vendor_warning) + '</div></div></div>';
    }

    // Note if order is unassigned
    if (currentUser && currentUser.role === 'courier' && order.status === 'ready' && !order.courier_pin) {
        html += '<div class="scan-warning scan-warning-info">';
        html += '<span class="scan-warning-icon">&#8505;</span>';
        html += '<div><strong>Unassigned order</strong>';
        html += '<div class="scan-warning-detail">This order hasn\'t been assigned yet. Accept it to add to your deliveries.</div></div></div>';
    }

    html += '<div class="scan-result-header"><span class="scan-result-ref">' + escapeHtml(order.ref) + '</span>';
    html += '<span class="order-status-badge ' + badgeClass + '">' + (statusLabels[order.status] || order.status) + '</span></div>';

    html += '<div class="scan-result-body"><div class="scan-result-grid">';
    html += '<div class="order-detail"><span class="order-detail-label">Customer</span><span class="order-detail-value">' + escapeHtml(order.customer_name) + '</span></div>';
    html += '<div class="order-detail"><span class="order-detail-label">Destination</span><span class="order-detail-value">' + escapeHtml(order.destination || '-') + '</span></div>';
    html += '<div class="order-detail"><span class="order-detail-label">Material</span><span class="order-detail-value">' + escapeHtml(order.material) + '</span></div>';
    html += '<div class="order-detail"><span class="order-detail-label">Event</span><span class="order-detail-value">' + escapeHtml((currentUser && currentUser.role === 'mtcc_staff') ? (order.event || order.event_acronym || '-') : (order.event_acronym || order.event || '-')) + '</span></div>';
    html += '</div></div>';

    if (statuses.length > 0) {
        html += '<div class="scan-result-actions">';
        if (result.receive_mode) {
            html += '<div class="receive-label">Confirm received at MTCC:</div>';
        }
        // Photo required for delivery — no checkbox
        html += '</div>';
    }
    html += '</div>';

    el.innerHTML = html;
    el.style.display = 'block';

    // Sticky bottom action bar — large, easy-to-tap buttons
    var existingSticky = document.getElementById('scanStickyAction');
    if (existingSticky) existingSticky.remove();

    if (statuses.length > 0) {
        var stickyDiv = document.createElement('div');
        stickyDiv.id = 'scanStickyAction';
        stickyDiv.className = 'scan-sticky-action';
        var stickyHtml = '';
        statuses.forEach(function(s) {
            var label = s, icon = '';
            if (result.receive_mode && s === 'delivered') { label = 'Confirm Received at MTCC'; icon = '\u2705 '; }
            else if (s === 'shipped') { label = 'Confirm Pickup'; icon = '\ud83d\udce6 '; }
            else if (s === 'delivered') { label = 'Mark Delivered'; icon = '\ud83d\udccd '; }
            else if (s === 'pickedup') { label = 'Confirm Pick Up'; icon = '\ud83e\udd1d '; }
            else if (s === 'dispatched') { label = 'Accept Delivery'; icon = '\u2705 '; }
            stickyHtml += '<button class="scan-sticky-btn btn-' + s + '" onclick="updateScannedOrderStatus(\'' + escapeAttr(order.ref) + '\', \'' + s + '\')">' + icon + label + '</button>';
        });
        stickyDiv.innerHTML = stickyHtml;
        var scanContent = document.getElementById('scanContent') || document.getElementById('tab-scan');
        if (scanContent) scanContent.appendChild(stickyDiv);
    }
}

function showScanError(msg) {
    var el = document.getElementById('scanResult');
    el.innerHTML = '<div class="scan-result-card scan-error-card"><div class="scan-result-header scan-error-header"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg><span class="scan-result-ref">Not Found</span></div><div class="scan-result-body"><p class="scan-error-msg">' + escapeHtml(msg) + '</p></div></div>';
    el.style.display = 'block';
}

function hideScanResult() {
    var el = document.getElementById('scanResult');
    if (el) { el.style.display = 'none'; el.innerHTML = ''; }
    var sticky = document.getElementById('scanStickyAction');
    if (sticky) sticky.remove();
    scanLocked = false;
    scanExpectedRef = null;
}

function updateScannedOrderStatus(ref, newStatus) {
    // Photo required for all deliveries
    if (newStatus === 'delivered' && currentUser.role === 'courier') {
        openPhotoCapture(function(photoData) {
            doScanStatusUpdate(ref, newStatus, photoData);
        });
        return;
    }
    doScanStatusUpdate(ref, newStatus, null);
}

function doScanStatusUpdate(ref, newStatus, photoData) {
    var data = { ref: ref, status: newStatus };
    if (photoData) data.photo = photoData;

    apiCall('update_status', data, function(result) {
        if (result.success) {
            haptic.confirm();
            showToast(result.message, 'success');
            hideScanResult();
            scanLocked = false;
            refreshTab(currentTab);
        } else {
            haptic.error();
            showToast(result.error || 'Update failed', 'error');
            scanLocked = false; // Unlock scanner so user can retry
        }
    });
}

// ============================================
// Photo Capture (Full-screen)
// ============================================

var photoCallback = null;

function openPhotoCapture(callback) {
    photoCallback = callback;
    capturedPhoto = null;
    var overlay = document.getElementById('photoOverlay');
    var video = document.getElementById('photoVideo');
    var preview = document.getElementById('photoPreview');
    var captureBtn = document.getElementById('photoCaptureBtn');
    var retakeBtn = document.getElementById('photoRetakeBtn');
    var useBtn = document.getElementById('photoUseBtn');

    preview.style.display = 'none';
    video.style.display = 'block';
    captureBtn.style.display = 'flex';
    retakeBtn.style.display = 'none';
    useBtn.style.display = 'none';

    overlay.classList.add('active');

    navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment', width: { ideal: 1920 }, height: { ideal: 1080 } }
    }).then(function(stream) {
        video.srcObject = stream;
        video.play();
    }).catch(function(err) {
        haptic.error();
        showToast('Camera access denied', 'error');
        closePhotoCapture();
    });
}

function capturePhoto() {
    haptic.shutter();
    var video = document.getElementById('photoVideo');
    var preview = document.getElementById('photoPreview');
    var canvas = document.createElement('canvas');
    // Cap resolution to 1920px max dimension to keep file size manageable
    var maxDim = 1920;
    var w = video.videoWidth || 640;
    var h = video.videoHeight || 480;
    if (w > maxDim || h > maxDim) {
        var scale = maxDim / Math.max(w, h);
        w = Math.round(w * scale);
        h = Math.round(h * scale);
    }
    canvas.width = w;
    canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);
    // Start at 0.7 quality, reduce if over 2MB
    var quality = 0.7;
    capturedPhoto = canvas.toDataURL('image/jpeg', quality);
    while (capturedPhoto.length > 2 * 1024 * 1024 && quality > 0.3) {
        quality -= 0.1;
        capturedPhoto = canvas.toDataURL('image/jpeg', quality);
    }

    preview.src = capturedPhoto;
    preview.style.display = 'block';
    video.style.display = 'none';
    document.getElementById('photoCaptureBtn').style.display = 'none';
    document.getElementById('photoRetakeBtn').style.display = 'flex';
    document.getElementById('photoUseBtn').style.display = 'flex';
}

function retakePhoto() {
    haptic.tap();
    var video = document.getElementById('photoVideo');
    var preview = document.getElementById('photoPreview');
    preview.style.display = 'none';
    video.style.display = 'block';
    document.getElementById('photoCaptureBtn').style.display = 'flex';
    document.getElementById('photoRetakeBtn').style.display = 'none';
    document.getElementById('photoUseBtn').style.display = 'none';
}

function usePhoto() {
    closePhotoCapture();
    if (photoCallback) photoCallback(capturedPhoto);
}

function closePhotoCapture() {
    var overlay = document.getElementById('photoOverlay');
    var video = document.getElementById('photoVideo');
    overlay.classList.remove('active');
    if (video.srcObject) {
        video.srcObject.getTracks().forEach(function(t) { t.stop(); });
        video.srcObject = null;
    }
}

// ============================================
// Auto Refresh
// ============================================

function startAutoRefresh() {
    stopAutoRefresh();
    autoRefreshInterval = setInterval(function() {
        if (currentTab && currentTab !== 'scan' && currentTab !== 'nearby') {
            refreshTab(currentTab);
        }
    }, 30000);
}

function stopAutoRefresh() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
        autoRefreshInterval = null;
    }
}

// ============================================
// Toast Notifications
// ============================================

function showToast(message, type) {
    var container = document.getElementById('toastContainer');
    var toast = document.createElement('div');
    toast.className = 'toast ' + (type || 'info');
    toast.textContent = message;
    container.appendChild(toast);

    setTimeout(function() {
        toast.classList.add('fade-out');
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

// ============================================
// Utility Functions
// ============================================

function isAppleDevice() {
    var ua = navigator.userAgent || '';
    return /iPhone|iPad|iPod|Macintosh/.test(ua) && 'ontouchend' in document;
}

// ============================================
// BATCH CARD RENDERING
// ============================================

function renderBatchCard(batch, mode) {
    batchCache[batch.batch_id] = batch;
    var isInProgress = (batch.status === 'in_progress');
    var isAccepted = (batch.status === 'accepted');
    var isActive = isInProgress || isAccepted;
    var urgClass = '';
    if (batch.urgency === 'red') urgClass = ' batch-urgency-red';
    else if (batch.urgency === 'orange') urgClass = ' batch-urgency-orange';

    var html = '<div class="batch-card' + urgClass + (isActive ? ' batch-active' : '') + (isInProgress ? ' batch-in-progress' : '') + '" data-batch-id="' + escapeAttr(batch.batch_id) + '" data-mode="' + mode + '">';

    // Type banner — always visible, distinguishes batch from single order
    html += '<div class="batch-type-banner">';
    html += '<div class="btb-left">';
    html += '<span class="btb-icon">\ud83d\udce6</span> ';
    if (isInProgress) {
        html += '<span class="transit-pulse"></span> <strong>BATCH IN TRANSIT</strong>';
    } else {
        html += '<strong>BATCH DELIVERY</strong>';
    }
    html += '</div>';
    html += '<div class="btb-right">';
    if (batch.event_acronym) html += '<span class="btb-event">' + escapeHtml(batch.event_acronym) + '</span>';
    if (batch.est_payout && mode !== 'pickup') {
        html += '<span class="btb-payout">$' + parseFloat(batch.est_payout).toFixed(2) + '</span>';
    }
    html += '</div>';
    html += '</div>';

    // Due bar — consistent format
    if (batch.due_date_formatted) {
        var barCls = 'card-due-bar';
        if (isInProgress) barCls += ' due-status-shipped';
        else if (batch.urgency === 'red') barCls += ' due-urgent-red';
        else if (batch.urgency === 'orange') barCls += ' due-urgent-orange';
        else barCls += ' due-status-dispatched';
        var batchCardTime = batch.due_time_formatted || 'Anytime';
        html += '<div class="' + barCls + '">';
        html += '<span class="due-text-mtcc">Due ' + escapeHtml(batch.due_date_formatted) + '  |  by: ' + escapeHtml(batchCardTime) + '</span>';
        var batchBadge = isInProgress ? 'In Transit' : (batch.status === 'accepted' ? 'Accepted' : 'Pending');
        html += '<span class="order-status-badge badge-' + batch.status + ' badge-sm due-bar-badge">' + batchBadge + '</span>';
        html += '</div>';
    }

    // Top row: batch ID + order count
    html += '<div class="order-card-top" style="padding:10px 16px 0 16px;">';
    html += '<div class="order-card-top-left">';
    html += '<div class="batch-id-pill">' + escapeHtml(batch.batch_id) + '</div>';
    var totalPkgs = 0;
    if (batch.orders) batch.orders.forEach(function(o) { totalPkgs += (o.quantity || 1); });
    html += '<span class="batch-count-badge">' + batch.order_count + ' orders</span>';
    if (totalPkgs > 0) html += '<span class="batch-count-badge batch-pkg-count">' + totalPkgs + ' pkg' + (totalPkgs !== 1 ? 's' : '') + '</span>';
    html += '</div>';
    html += '</div>';

    // Multi-stop route timeline
    var stops = batch.stops || [];
    var currentIdx = batch.current_stop_index || 0;
    if (stops.length > 0) {
    html += '<div class="batch-stops-timeline">';
    stops.forEach(function(stop, idx) {
        var isDone = (stop.status === 'completed');
        var isSkipped = (stop.status === 'skipped');
        var isCurrent = (isActive && idx === currentIdx && !isDone);
        var stopCls = 'batch-stop-item';
        if (isDone) stopCls += ' done';
        if (isSkipped) stopCls += ' skipped';
        if (isCurrent) stopCls += ' current';

        html += '<div class="' + stopCls + '">';
        // Dot + connector
        html += '<div class="batch-stop-col">';
        if (isDone) {
            html += '<div class="batch-stop-dot done"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg></div>';
        } else if (stop.type === 'pickup') {
            html += '<div class="batch-stop-dot pickup"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/></svg></div>';
        } else {
            html += '<div class="batch-stop-dot dropoff"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>';
        }
        if (idx < stops.length - 1) html += '<div class="batch-stop-line"></div>';
        html += '</div>';
        // Info
        html += '<div class="batch-stop-info">';
        html += '<div class="batch-stop-label">' + stop.type.toUpperCase() + (isDone ? ' <span class="bs-done">\u2713</span>' : '') + (isSkipped ? ' <span class="bs-skipped">Skipped</span>' : '') + '</div>';
        html += '<div class="batch-stop-name">' + escapeHtml(stop.name || '') + '</div>';
        html += '</div>';
        html += '</div>';
    });
    html += '</div>';
    } else {
    // Fallback: show order summary when no stops timeline
    html += '<div class="batch-stops-timeline" style="padding:8px 16px;">';
    var orders = batch.orders || [];
    if (orders.length > 0) {
        orders.forEach(function(o) {
            var name = typeof o === 'object' ? (o.customer_name || '') : '';
            var material = typeof o === 'object' ? (o.material || '') : '';
            html += '<div style="display:flex;align-items:center;gap:8px;padding:4px 0;">';
            html += '<span style="font-size:0.8rem;font-weight:600;color:#374151;">' + escapeHtml(name || 'Order') + '</span>';
            if (material) html += '<span style="font-size:0.72rem;color:#9ca3af;">' + escapeHtml(material) + '</span>';
            html += '</div>';
        });
    } else {
        html += '<div style="font-size:0.8rem;color:#6b7280;padding:4px 0;">' + batch.order_count + ' orders in this batch</div>';
    }
    html += '</div>';
    }

    // Route summary bar
    if (batch.route && (batch.route.distance_km || batch.route.duration_min)) {
        html += '<div class="batch-route-bar">';
        html += '<span class="batch-route-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>';
        if (batch.route.distance_km) html += '<span>Distance: ' + batch.route.distance_km + ' km</span>';
        if (batch.route.duration_min) html += '<span class="batch-route-sep">\u00b7</span><span>Drive time: ~' + batch.route.duration_min + ' min</span>';
        html += '<span class="batch-route-sep">\u00b7</span><span>' + stops.length + ' stops</span>';
        html += '</div>';
    }

    // Destinations summary (if stops empty but destinations available)
    if (stops.length === 0 && batch.destinations && batch.destinations.length > 0) {
        html += '<div style="padding:4px 16px 12px;display:flex;flex-wrap:wrap;gap:4px;">';
        batch.destinations.forEach(function(d) {
            var destCls = ((d.type || '').indexOf('mtcc') !== -1) ? 'background:#dbeafe;color:#1e40af;' : 'background:#fef3c7;color:#92400e;';
            html += '<span style="display:inline-block;font-size:0.7rem;font-weight:600;padding:3px 8px;border-radius:6px;' + destCls + '">';
            html += escapeHtml(d.label || d.name || '');
            if (d.count > 1) html += ' &times;' + d.count;
            html += '</span>';
        });
        html += '</div>';
    }

    html += '</div>';
    return html;
}

// ============================================
// BATCH DETAIL PANEL — matches order detail layout
// ============================================

// ============================================
// Geolocation
// ============================================

function startGeoWatch() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function(pos) {
        courierLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    }, function() {}, { enableHighAccuracy: false, timeout: 10000 });
    // Watch for updates
    navigator.geolocation.watchPosition(function(pos) {
        courierLocation = { lat: pos.coords.latitude, lng: pos.coords.longitude };
    }, function() {}, { enableHighAccuracy: false, maximumAge: 60000 });
}

// Build Google Maps embed URL — shows courier location + all stops
function buildMapEmbed(addresses) {
    if (!addresses || addresses.length === 0) return '';
    // Prepend courier location if available
    var allAddrs = addresses.slice();
    if (courierLocation) {
        allAddrs.unshift(courierLocation.lat + ',' + courierLocation.lng);
    }
    if (allAddrs.length === 1) {
        return 'https://www.google.com/maps?q=' + encodeURIComponent(allAddrs[0]) + '&z=14&output=embed';
    }
    // Multi-stop: saddr + daddr with +to: chaining
    var saddr = allAddrs[0];
    var daddrs = allAddrs.slice(1);
    return 'https://www.google.com/maps?saddr=' + encodeURIComponent(saddr) + '&daddr=' + daddrs.map(function(a) { return encodeURIComponent(a); }).join('+to:') + '&dirflg=d&output=embed';
}

// Build Google Maps nav URL — no origin, device GPS auto-fills
function buildGoogleNavUrl(addresses) {
    if (!addresses || addresses.length === 0) return '';
    if (addresses.length === 1) {
        return 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addresses[0]) + '&travelmode=driving';
    }
    // Multi-stop: destination=last, waypoints=middle stops
    var dest = addresses[addresses.length - 1];
    var waypoints = addresses.slice(0, -1);
    return 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dest) + '&waypoints=' + waypoints.map(function(a) { return encodeURIComponent(a); }).join('|') + '&travelmode=driving';
}

// Build Apple Maps nav URL — no saddr, device GPS auto-fills
function buildAppleNavUrl(addresses) {
    if (!addresses || addresses.length === 0) return '';
    // Multiple daddr params: ?daddr=A&daddr=B&daddr=C
    var url = 'https://maps.apple.com/?';
    addresses.forEach(function(addr, i) {
        url += (i === 0 ? 'daddr=' : '&daddr=') + encodeURIComponent(addr);
    });
    url += '&dirflg=d';
    return url;
}


function showBatchDetail(batchId, mode) {
    var batch = batchCache[batchId];
    if (!batch) return;
    haptic.tap();

    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    var content = document.getElementById('detailContent');
    if (!panel || !content) return;

    // Fix 4: Batch header handle colored
    var batchColor = '#7c3aed'; // default violet
    if (batch.status === 'in_progress') batchColor = '#14b8a6';
    else if (batch.status === 'completed') batchColor = '#059669';
    panel.style.setProperty('--mtcc-header-color', batchColor);
    panel.classList.add('mtcc-panel');

    var isAvailable = (mode === 'available');
    var isActive = (batch.status === 'accepted' || batch.status === 'in_progress');
    var stops = batch.stops || [];
    var orders = batch.orders || [];
    var statusLabelsMap = { pending: 'Pending', dispatched: 'Dispatched', accepted: 'Accepted', in_progress: 'In Transit', completed: 'Completed', cancelled: 'Cancelled', suggested: 'Suggested' };
    var batchStatus = statusLabelsMap[batch.status] || batch.status;
    var badgeClass = 'badge-' + batch.status;
    var phoneIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>';

    var html = '';

    // Due date — full day name format with badge
    if (batch.due_date_formatted) {
        var batchTimeLabel = batch.due_time_formatted || 'Anytime';
        html += '<div class="detail-due-bar">';
        html += '<div class="detail-due-left">';
        html += '<span class="detail-due-heading">DUE DATE</span>';
        html += '<span class="detail-due-date">' + escapeHtml(batch.due_date_formatted) + '  |  by: ' + escapeHtml(batchTimeLabel) + '</span>';
        html += '</div>';
        html += '<span class="order-status-badge badge-' + batch.status + ' mtcc-header-badge">' + batchStatus + '</span>';
        html += '</div>';
    }

    // Header — BATCH ID label above ID, payout with label
    html += '<div class="detail-order-header">';
    html += '<div>';
    html += '<div class="batch-id-label">BATCH ID</div>';
    html += '<div class="detail-order-ref">' + escapeHtml(batch.batch_id) + '</div>';
    html += '</div>';
    if (batch.est_payout) {
        html += '<div class="batch-payout-top"><div class="batch-payout-top-label">Est. Payout</div><div class="batch-payout-top-amount">$' + parseFloat(batch.est_payout).toFixed(2) + '</div></div>';
    }
    html += '</div>';

    // Build map addresses
    var mapAddresses = [];
    stops.forEach(function(stop) {
        var addr = (stop.address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
        if (!addr && stop.name) addr = stop.name + ', Toronto, ON';
        if (addr) mapAddresses.push(addr);
    });

    // Count pickup stops only (exclude dropoff)
    var pickupCount = 0;
    stops.forEach(function(s) { if (s.type === 'pickup') pickupCount++; });

    // Map with stats bar — at top, before stops
    html += '<div class="bv7-map-block">';
    if (batch.route && (batch.route.distance_km || batch.route.duration_min)) {
        html += '<div class="bv7-stats-bar">';
        html += '<span class="bv7-stats-title">Route</span>';
        html += '<span class="bv7-stats-sep">|</span>';
        html += '<span class="bv7-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> <strong>' + (batch.route.distance_km || '?') + ' km</strong></span>';
        html += '<span class="bv7-stat-dot">\u2022</span>';
        html += '<span class="bv7-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> <strong>~' + (batch.route.duration_min || '?') + ' min</strong></span>';
        html += '<span class="bv7-stat-dot">\u2022</span>';
        html += '<span class="bv7-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> <strong>' + pickupCount + ' stop' + (pickupCount !== 1 ? 's' : '') + '</strong></span>';
        html += '</div>';
    }
    if (mapAddresses.length >= 1) {
        html += '<div class="bv7-map-embed"><iframe src="' + buildMapEmbed(mapAddresses) + '" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div>';
    }
    html += '</div>'; // end bv7-map-block
    // Map buttons outside the map block
    if (mapAddresses.length >= 2) {
        html += '<div class="route-map-buttons' + (isAppleDevice() ? '' : ' single') + '" style="margin:8px 16px 0;">';
        html += '<a class="route-app-btn google" href="' + buildGoogleNavUrl(mapAddresses) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> Google Maps</a>';
        if (isAppleDevice()) html += '<a class="route-app-btn apple" href="' + buildAppleNavUrl(mapAddresses) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Apple Maps</a>';
        html += '</div>';
    }

    // ═══ STOPS with vertical connector ═══
    html += '<div class="batch-timeline-v7">';
    var pickupNum = 0;
    stops.forEach(function(stop, idx) {
        var isDone = (stop.status === 'completed');
        var isCurrent = (isActive && idx === (batch.current_stop_index || 0) && !isDone);
        var addr = (stop.address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
        var refCount = (stop.order_refs && stop.order_refs.length) || 0;
        var isPickup = (stop.type === 'pickup');
        var isLast = (idx === stops.length - 1);
        var vendorPhone = stop.vendor_phone || '';
        if (isPickup) pickupNum++;

        html += '<div class="bv7-stop">';

        // Left column: icon + dashed line connector
        html += '<div class="bv7-left">';
        html += '<div class="bv7-icon">';
        if (isDone) {
            html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        } else if (isPickup) {
            html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        } else {
            html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        }
        html += '</div>';
        if (!isLast) html += '<div class="bv7-line"></div>';
        html += '</div>';

        // Right column: all content
        html += '<div class="bv7-right">';

        // Label + badges (Fix 2: numbered pickups)
        html += '<div class="bv7-label">';
        var stopLabel = isPickup ? 'PICKUP' + (pickupCount > 1 ? ' #' + pickupNum : '') : 'DROPOFF';
        html += '<span class="route-stop-label">' + stopLabel + '</span>';
        if (refCount > 0) html += '<span class="batch-item-badge">' + refCount + ' item' + (refCount !== 1 ? 's' : '') + '</span>';
        if (isCurrent) html += '<span class="batch-current-pill">CURRENT</span>';
        html += '</div>';

        // Name
        html += '<div class="bv7-name">' + escapeHtml(stop.name || '') + '</div>';
        if (addr) html += '<div class="bv7-addr">' + escapeHtml(addr) + '</div>';
        if (vendorPhone) html += '<a class="bv7-phone" href="tel:' + vendorPhone.replace(/[^0-9+]/g, '') + '"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72"/></svg> ' + escapeHtml(vendorPhone) + '</a>';

        // Nav button
        if (addr) html += '<a class="bv7-nav" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addr) + '" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>';

        // Items — always visible
        if (isPickup && stop.order_refs && stop.order_refs.length > 0) {
            html += '<div class="bv7-items">';
            stop.order_refs.forEach(function(oRef) {
                var o = orders.find(function(x) { return x.ref === oRef; }) || {};
                var specs = [];
                if (o.material) specs.push(o.material);
                if (o.size) specs.push(o.size);
                if (o.quantity && o.quantity > 1) specs.push(o.quantity + ' boxes');
                html += '<div class="bv7-item">';
                html += '<div class="bv7-item-row1"><span class="bv7-item-ref">' + escapeHtml(oRef) + '</span> <span class="bv7-item-name">' + escapeHtml(o.customer_name || '') + '</span></div>';
                if (specs.length) html += '<div class="bv7-item-spec">' + escapeHtml(specs.join(' \u00b7 ')) + '</div>';
                html += '<div class="bv7-item-row3"><span class="bv7-item-vref">Vendor Ref: ' + escapeHtml(o.vendor_order_number || 'N/A') + '</span>';
                if (typeof CourierIssues !== 'undefined') html += '<button class="bv7-item-issue" onclick="CourierIssues.open(\'' + escapeAttr(oRef) + '\')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue</button>';
                html += '</div></div>';
            });
            html += '</div>';
        }

        html += '</div>'; // bv7-right
        html += '</div>'; // bv7-stop
    });
    html += '</div>';
    // Divider after stops
    html += '<div style="height:1px;background:#e5e7eb;margin:12px 16px;"></div>';

    // (map already rendered above stops)

    // Payout card (Fix 4: proper card, not compact)
    if (batch.est_payout) {
        html += '<div class="detail-payout">';
        html += '<div class="payout-total-row">';
        html += '<span class="payout-total-label">Est. Payout</span>';
        html += '<span class="payout-total-amount">$' + parseFloat(batch.est_payout).toFixed(2) + '</span>';
        html += '</div>';
        if (batch.est_payout_breakdown && Array.isArray(batch.est_payout_breakdown)) {
            html += '<div class="payout-items">';
            batch.est_payout_breakdown.forEach(function(item) {
                var isBonus = (item.label !== 'Base rate');
                html += '<div class="payout-row' + (isBonus ? ' payout-row-bonus' : '') + '">';
                html += '<span class="payout-row-label">' + (isBonus ? '+ ' : '') + escapeHtml(item.label) + '</span>';
                html += '<span class="payout-row-amount">$' + parseFloat(item.amount).toFixed(2) + '</span>';
                html += '</div>';
            });
            html += '</div>';
        }
        html += '</div>';
    }

    // Bottom actions section
    html += '<div class="bt-bottom-actions">';

    // Accept batch (for available batches)
    if (isAvailable && (batch.status === 'pending' || batch.status === 'suggested')) {
        html += '<button class="status-action-btn btn-accept bt-full-btn" onclick="acceptBatch(\'' + escapeAttr(batch.batch_id) + '\', this)">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        html += ' Accept Batch (' + batch.order_count + ' orders)</button>';
    }

    // Report Issue — full width
    if (typeof CourierIssues !== 'undefined' && isActive) {
        html += '<button class="release-btn bt-full-btn" style="border-color:#d97706;color:#d97706;" onclick="CourierIssues.open(\'' + escapeAttr(orders[0] ? orders[0].ref : batch.batch_id) + '\')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Report Issue</button>';
    }

    // Contacts — horizontal buttons, phone icon left, name + number
    var phoneIconSvg = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>';
    html += '<div class="bv7-contacts">';
    html += '<a class="bv7-contact" href="tel:' + SUPPORT_PHONES.admin.number.replace(/[^0-9+]/g, '') + '">' + phoneIconSvg + '<div class="bv7-contact-info"><span class="bv7-contact-name">Print Stuff</span><span class="bv7-contact-num">' + SUPPORT_PHONES.admin.number + '</span></div></a>';
    html += '<a class="bv7-contact" href="tel:' + SUPPORT_PHONES.mtcc.number.replace(/[^0-9+]/g, '') + '">' + phoneIconSvg + '<div class="bv7-contact-info"><span class="bv7-contact-name">MTCC</span><span class="bv7-contact-num">' + SUPPORT_PHONES.mtcc.number + '</span></div></a>';
    html += '</div>';

    // Release — at very bottom
    var batchPin = batch.courier_pin || (batch.courier ? batch.courier.pin : '') || '';
    var canRelease = false;
    if (batch.status === 'accepted' || batch.status === 'dispatched') {
        if (currentUser.role === 'courier' && batchPin === currentUser.pin) canRelease = true;
        if (currentUser.role === 'admin' || currentUser.role === 'mtcc_staff') canRelease = true;
    }
    if (canRelease) {
        html += '<div class="bt-release-section">';
        html += '<button class="release-btn bt-full-btn" onclick="releaseBatch(\'' + escapeAttr(batch.batch_id) + '\', this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release Batch</button>';
        html += '</div>';
    }

    html += '</div>'; // bt-bottom-actions

    content.innerHTML = html;
    panel.classList.add('active');
    if (overlay) overlay.classList.add('active');
}

function closeBatchDetail() {
    closeDetailPanel();
}

function toggleBatchStopDetails(stopId, e) {
    if (e) e.stopPropagation();
    var el = document.getElementById(stopId);
    if (!el) return;
    var btn = el.previousElementSibling;
    if (el.style.display === 'none') {
        el.style.display = 'block';
        if (btn) btn.innerHTML = 'Hide items <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"/></svg>';
    } else {
        el.style.display = 'none';
        if (btn) btn.innerHTML = 'View items <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>';
    }
}


// ============================================
// ACCEPT BATCH
// ============================================

function acceptBatch(batchId, btnEl) {
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span class="spinner"></span> Accepting...';
    }
    apiCall('accept_batch', { batch_id: batchId }, function(result) {
        if (result.success) {
            haptic.success();
            closeBatchDetail();
            // Refresh both tabs
            loadMyDeliveries();
            loadAvailable();
            // Show confirmation
            showToast('Batch accepted! ' + (result.order_count || '') + ' orders assigned.', 'success');
        } else {
            haptic.error();
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Accept Batch';
            }
            alert('Error: ' + (result.error || 'Could not accept batch'));
        }
    });
}



// ============================================
// RELEASE / UNASSIGN
// ============================================

function releaseDelivery(ref, btnEl) {
    if (!confirm('Release this order back to the available queue?\n\nThe order will be unassigned from you and other couriers can pick it up.')) return;
    
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span class="spinner-sm"></span> Releasing...';
    }
    haptic.tap();
    
    var reason = '';
    // Optional: prompt for reason
    var reasons = ['Wrong order', 'Car trouble', 'Cannot reach location', 'Customer cancelled', 'Other'];
    var reasonIdx = prompt('Optional: Why are you releasing this order?\n\n1. Wrong order\n2. Car trouble\n3. Cannot reach location\n4. Customer cancelled\n5. Other\n\nEnter number or leave blank:');
    if (reasonIdx && parseInt(reasonIdx) >= 1 && parseInt(reasonIdx) <= 5) {
        reason = reasons[parseInt(reasonIdx) - 1];
    } else if (reasonIdx) {
        reason = reasonIdx;
    }
    
    apiCall('release_delivery', { ref: ref, reason: reason }, function(result) {
        if (result.success) {
            showToast(result.message || 'Order released', 'success');
            haptic.success();
            closeDetailPanel();
            // Refresh both tabs
            loadMyDeliveries();
            loadAvailable();
        } else {
            showToast(result.error || 'Failed to release', 'error');
            haptic.error();
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release Order';
            }
        }
    });
}

function releaseBatch(batchId, btnEl) {
    var batch = batchCache[batchId];
    var count = batch ? batch.order_count : '?';
    if (!confirm('Release this entire batch (' + count + ' orders) back to the available queue?\n\nAll orders will be unassigned.')) return;
    
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<span class="spinner-sm"></span> Releasing...';
    }
    haptic.tap();
    
    apiCall('release_batch', { batch_id: batchId, reason: 'Released by courier' }, function(result) {
        if (result.success) {
            showToast(result.message || 'Batch released', 'success');
            haptic.success();
            closeBatchDetail();
            loadMyDeliveries();
            loadAvailable();
        } else {
            showToast(result.error || 'Failed to release batch', 'error');
            haptic.error();
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release Batch';
            }
        }
    });
}


function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function escapeAttr(str) {
    if (!str) return '';
    return str.replace(/'/g, "\\'").replace(/"/g, '&quot;');
}

function timeAgo(dateStr) {
    if (!dateStr) return '';
    var now = new Date();
    var then = new Date(dateStr);
    var diff = Math.floor((now - then) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function formatDateTime(dateStr) {
    if (!dateStr) return '';
    var d = new Date(dateStr);
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return months[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
}

function getTabIcon(iconId) {
    var icons = {
        deliveries: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><path d="M16 8h4l3 3v5a2 2 0 0 1-2 2"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        available: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        scan: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="12" x2="17" y2="12"/></svg>',
        nearby: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        earnings: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        pickup: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        activity: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
        history: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        dashboard: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        upcoming: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        complete: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
    };
    return icons[iconId] || '';
}

// ============================================
// Keyboard Support
// ============================================
document.addEventListener('keydown', function(e) {
    if (document.getElementById('loginScreen').classList.contains('active')) {
        if (e.key >= '0' && e.key <= '9') enterPin(e.key);
        else if (e.key === 'Backspace') backspacePin();
        else if (e.key === 'Escape') clearPin();
    }
    // Submit manual scan on Enter
    if (e.key === 'Enter' && document.activeElement && document.activeElement.id === 'manualTracking') {
        submitManualScan();
    }
});


// ============================================
// Pull to Refresh (elastic/bounce, two-tier)
// Short pull = refresh current tab only
// Long pull = full page reload
// ============================================
var pullState = {
    startY: 0,
    dist: 0,
    pulling: false,
    tabThreshold: 50,
    fullThreshold: 250,
    maxPull: 300
};

function elasticDist(d) {
    var max = pullState.maxPull;
    if (d <= 0) return 0;
    return max * (1 - Math.exp(-d / (max * 0.55)));
}

function isPanelOpen() {
    var dp = document.getElementById('detailPanel');
    var po = document.getElementById('photoOverlay');
    if (dp && dp.classList.contains('active')) return true;
    if (po && po.classList.contains('active')) return true;
    return false;
}

document.addEventListener('touchstart', function(e) {
    // Block pull if any overlay/panel is open (fixes Chrome iOS bug)
    if (isPanelOpen()) return;
    var appContent = document.getElementById('appContent');
    if (!appContent) return;
    var activeBody = appContent.querySelector('.tab-pane.active .tab-body');
    if (activeBody && activeBody.scrollTop > 5) return;
    pullState.startY = e.touches[0].clientY;
    pullState.pulling = true;
    pullState.dist = 0;
}, { passive: true });

document.addEventListener('touchmove', function(e) {
    if (!pullState.pulling) return;
    // Re-check panel on every move (handles race conditions on Chrome iOS)
    if (isPanelOpen()) { pullState.pulling = false; return; }
    var rawDist = e.touches[0].clientY - pullState.startY;
    if (rawDist < 0) { pullState.dist = 0; return; }
    pullState.dist = rawDist;

    var elastic = elasticDist(rawDist);
    var indicator = document.getElementById('pullIndicator');
    if (!indicator) return;

    indicator.style.height = elastic + 'px';
    indicator.style.opacity = Math.min(elastic / 35, 1);

    var spinner = indicator.querySelector('.pull-spinner');
    if (spinner) spinner.style.transform = 'rotate(' + (elastic * 3.5) + 'deg)';

    if (rawDist >= pullState.fullThreshold) {
        indicator.querySelector('span').textContent = 'Release for full reload';
        indicator.classList.add('ready', 'full');
    } else if (rawDist >= pullState.tabThreshold) {
        indicator.querySelector('span').textContent = 'Release to refresh tab';
        indicator.classList.add('ready');
        indicator.classList.remove('full');
    } else {
        indicator.querySelector('span').textContent = 'Pull to refresh';
        indicator.classList.remove('ready', 'full');
    }
}, { passive: true });

document.addEventListener('touchend', function() {
    if (!pullState.pulling) return;
    pullState.pulling = false;
    var indicator = document.getElementById('pullIndicator');
    var dist = pullState.dist;

    if (dist >= pullState.fullThreshold) {
        // LONG PULL: full page reload
        haptic.confirm();
        if (indicator) {
            indicator.classList.add('refreshing');
            indicator.querySelector('span').textContent = 'Reloading...';
            indicator.style.height = '44px';
        }
        setTimeout(function() { location.reload(); }, 350);
    } else if (dist >= pullState.tabThreshold && currentTab) {
        // SHORT PULL: refresh current tab only (stays on same tab)
        haptic.tap();
        if (indicator) {
            indicator.classList.add('refreshing');
            indicator.querySelector('span').textContent = 'Refreshing...';
            indicator.style.height = '44px';
        }
        refreshTab(currentTab);
        setTimeout(function() {
            if (indicator) {
                indicator.classList.add('bounce-back');
                indicator.style.height = '0';
                indicator.style.opacity = '0';
                setTimeout(function() {
                    indicator.classList.remove('ready', 'full', 'refreshing', 'bounce-back');
                }, 400);
            }
        }, 600);
    } else {
        // Cancel: snap back with bounce
        if (indicator) {
            indicator.classList.add('bounce-back');
            indicator.style.height = '0';
            indicator.style.opacity = '0';
            setTimeout(function() {
                indicator.classList.remove('ready', 'full', 'refreshing', 'bounce-back');
            }, 400);
        }
    }
    pullState.dist = 0;
}, { passive: true });

// ============================================
// Detail Panel Swipe-Down to Close
// ============================================
var panelSwipe = {
    startY: 0,
    dist: 0,
    swiping: false,
    threshold: 80
};

function initPanelSwipe() {
    var panel = document.getElementById('detailPanel');
    var handle = panel ? panel.querySelector('.detail-handle') : null;
    var contentEl = panel ? panel.querySelector('.detail-content') : null;
    if (!panel) return;

    // Start swipe on handle OR if content is scrolled to top
    panel.addEventListener('touchstart', function(e) {
        if (!panel.classList.contains('active')) return;
        var isHandle = handle && handle.contains(e.target);
        var contentScrolled = contentEl && contentEl.scrollTop > 5;
        // Allow swipe from handle always, or from content if at scroll top
        if (isHandle || !contentScrolled) {
            panelSwipe.startY = e.touches[0].clientY;
            panelSwipe.swiping = true;
            panelSwipe.dist = 0;
            panel.style.transition = 'none';
        }
    }, { passive: true });

    panel.addEventListener('touchmove', function(e) {
        if (!panelSwipe.swiping) return;
        panelSwipe.dist = e.touches[0].clientY - panelSwipe.startY;
        if (panelSwipe.dist < 0) { panelSwipe.dist = 0; return; }
        // Apply elastic drag
        var elastic = panelSwipe.dist * 0.6;
        panel.style.transform = 'translateY(' + elastic + 'px)';
    }, { passive: true });

    panel.addEventListener('touchend', function() {
        if (!panelSwipe.swiping) return;
        panelSwipe.swiping = false;
        panel.style.transition = '';

        if (panelSwipe.dist > panelSwipe.threshold) {
            haptic.tap();
            closeDetailPanel();
        } else {
            // Snap back
            panel.style.transform = 'translateY(0)';
        }
        panelSwipe.dist = 0;
    }, { passive: true });
}

// ============================================
// Slide-out Drawer
// ============================================

function toggleDrawer() {
    var drawer = document.getElementById('drawer');
    var overlay = document.getElementById('drawerOverlay');
    if (drawer.classList.contains('open')) {
        closeDrawer();
    } else {
        drawer.classList.add('open');
        overlay.classList.add('open');
        document.getElementById('hamburgerBtn').classList.add('open');
        document.body.style.overflow = 'hidden';
    }
}

function closeDrawer() {
    document.getElementById('drawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('hamburgerBtn').classList.remove('open');
    document.body.style.overflow = '';
}

function drawerNav(tabId) {
    closeDrawer();
    switchTab(tabId);
}

function toggleAvailability(isOnline) {
    var dot = document.getElementById('availabilityDot');
    if (dot) {
        dot.classList.toggle('online', isOnline);
        dot.classList.toggle('offline', !isOnline);
    }
    apiCall('set_availability', { status: isOnline ? 'online' : 'offline' }, function(result) {
        if (result.success) {
            showToast(isOnline ? 'You are now online' : 'You are now offline', 'success');
        }
    });
}

function doLogout() {
    closeDrawer();
    apiCall('logout', null, function() {
        currentUser = null;
        stopAutoRefresh();
        if (scannerActive) stopScanner();
        showLogin();
        showToast('Signed out', 'info');
    });
}

// ============================================
// Initialize
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Initialize audio on first touch/click (required for iOS)
    var initOnInteraction = function() {
        haptic.initAudio();
        document.removeEventListener('touchstart', initOnInteraction);
        document.removeEventListener('click', initOnInteraction);
    };
    document.addEventListener('touchstart', initOnInteraction, { once: true });
    document.addEventListener('click', initOnInteraction, { once: true });

    // Init panel swipe-to-close
    initPanelSwipe();

    // Check for existing session
    apiCall('session_check', null, function(result) {
        if (result.success && result.authenticated) {
            currentUser = result.user;
            showApp();
        } else {
            showLogin();
        }
    });
});

// Register service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function() {});
}

console.log('MTCC Courier App loaded');
console.log('- Vibration supported:', haptic.vibrateSupported);
console.log('- Audio will initialize on first interaction');
