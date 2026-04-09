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
var batchScanMode = null; // { batchId, orderRefs: [], scannedRefs: [] } when scanning batch items
var scanOrigin = null; // { type: 'batch'|'order', id: batchId|orderRef, label: string } for back navigation
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
    initialized: false,
    // Pool of reusable Audio elements for iOS compatibility
    _audioPool: [],
    _poolSize: 4,
    _poolIdx: 0,
    _clickReady: false,

    // Initialize — create audio pool + AudioContext
    initAudio: function() {
        if (this.initialized) return;
        // AudioContext for beep tones
        if (!this.audioCtx) {
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) {
                this.audioEnabled = false;
            }
        }
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
        // HTML Audio pool for click sounds (reliable on iOS)
        // Tiny 1-sample silent mp3 to unlock audio playback, then swap src
        if (!this._clickReady) {
            for (var i = 0; i < this._poolSize; i++) {
                var a = new Audio();
                a.volume = 0.3;
                // Play silence to unlock iOS audio
                a.src = 'data:audio/mp3;base64,SUQzBAAAAAAAI1RTU0UAAAAPAAADTGF2ZjU4Ljc2LjEwMAAAAAAAAAAAAAAA//tQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAABhgC7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7u7//////////////////////////////////////////////////////////////////8AAAAATGF2YzU4LjEzAAAAAAAAAAAAAAAAJAAAAAAAAAAAAYYoRwMHAAAAAAD/+1DEAAAB8ANX9AAABXiKrf8wYAAAE0AAAAATAGQAAADQAAAA0A';
                try { a.play().catch(function(){}); } catch(e) {}
                a.pause();
                a.currentTime = 0;
                this._audioPool.push(a);
            }
            this._clickReady = true;
        }
        this.initialized = true;
    },

    _ensureAudio: function() {
        if (!this.initialized) this.initAudio();
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
    },

    // Play a beep tone with frequency (Hz), duration (ms), volume (0-1)
    beep: function(frequency, duration, volume) {
        this._ensureAudio();
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
        } catch (e) {}
    },

    // iOS native haptic via <input type="checkbox" switch> + <label> trick
    // iOS 18+ fires Taptic Engine when a switch checkbox is toggled via its label
    _hapticInput: null,
    _hapticLabel: null,
    _hapticReady: false,

    _initHaptic: function() {
        if (this._hapticReady) return;
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.setAttribute('switch', '');
        input.id = '_haptic_switch';
        input.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;pointer-events:none;';
        input.setAttribute('aria-hidden', 'true');
        input.setAttribute('tabindex', '-1');

        var label = document.createElement('label');
        label.setAttribute('for', '_haptic_switch');
        label.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;pointer-events:none;';
        label.setAttribute('aria-hidden', 'true');

        document.body.appendChild(input);
        document.body.appendChild(label);
        this._hapticInput = input;
        this._hapticLabel = label;
        this._hapticReady = true;
    },

    // Trigger native iOS haptic by clicking the label (toggles the switch)
    _iosHaptic: function() {
        if (!this._hapticReady) this._initHaptic();
        if (this._hapticLabel) {
            this._hapticLabel.click();
        }
    },

    // Light tap — native haptic on iOS (switch toggle) + vibrate on Android
    tap: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(10);
        } else {
            this._iosHaptic();
        }
    },

    // Success - scan found, login success
    success: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(100);
        } else {
            this._iosHaptic();
        }
        this.beep(1200, 150, 0.3);
    },

    // Error - scan failed, order not found, login failed
    error: function() {
        if (this.vibrateSupported) {
            navigator.vibrate([100, 50, 100]);
        } else {
            var self = this;
            this._iosHaptic();
            setTimeout(function() { self._iosHaptic(); }, 100);
        }
        var self = this;
        this.beep(400, 150, 0.4);
        setTimeout(function() { self.beep(300, 200, 0.4); }, 200);
    },

    // Warning - permission denied, validation error
    warning: function() {
        if (this.vibrateSupported) {
            navigator.vibrate([50, 30, 50, 30, 50]);
        } else {
            var self = this;
            this._iosHaptic();
            setTimeout(function() { self._iosHaptic(); }, 80);
            setTimeout(function() { self._iosHaptic(); }, 160);
        }
        var self = this;
        this.beep(600, 80, 0.3);
        setTimeout(function() { self.beep(600, 80, 0.3); }, 120);
        setTimeout(function() { self.beep(600, 80, 0.3); }, 240);
    },

    // Confirm - status change confirmed
    confirm: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(200);
        } else {
            this._iosHaptic();
        }
        var self = this;
        this.beep(800, 100, 0.3);
        setTimeout(function() { self.beep(1200, 150, 0.3); }, 100);
    },

    // Scan detected - immediate feedback when barcode detected
    scanDetected: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(50);
        } else {
            this._iosHaptic();
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
// ============================================
// Offline Action Queue
// ============================================
var offlineQueue = [];
var QUEUE_STORAGE_KEY = 'mtcc_courier_offline_queue';
// Actions that should be queued when offline (critical status updates)
var QUEUEABLE_ACTIONS = ['update_status', 'accept_delivery', 'accept_batch', 'report_issue', 'update_location'];

function loadOfflineQueue() {
    try {
        var stored = localStorage.getItem(QUEUE_STORAGE_KEY);
        offlineQueue = stored ? JSON.parse(stored) : [];
    } catch (e) { offlineQueue = []; }
    updateSyncIndicator();
}

function saveOfflineQueue() {
    try {
        localStorage.setItem(QUEUE_STORAGE_KEY, JSON.stringify(offlineQueue));
    } catch (e) { /* storage full — degrade gracefully */ }
    updateSyncIndicator();
}

function queueOfflineAction(action, data) {
    offlineQueue.push({
        action: action,
        data: data,
        timestamp: Date.now(),
        id: Date.now() + '_' + Math.random().toString(36).substr(2, 5)
    });
    saveOfflineQueue();
}

function processOfflineQueue() {
    if (offlineQueue.length === 0 || !navigator.onLine) return;
    var item = offlineQueue[0];
    apiCall(item.action, item.data, function(result) {
        if (result.success || !result.offline) {
            // Processed (success or server-side error) — remove from queue
            offlineQueue.shift();
            saveOfflineQueue();
            if (result.success) {
                showToast('Synced: ' + item.action.replace(/_/g, ' '), 'success');
            }
            // Process next item
            if (offlineQueue.length > 0) {
                setTimeout(processOfflineQueue, 500);
            }
        }
        // If still offline, stop processing — will retry on reconnect
    }, true); // skipQueue flag
}

function updateSyncIndicator() {
    var el = document.getElementById('syncIndicator');
    if (offlineQueue.length > 0) {
        if (!el) {
            el = document.createElement('div');
            el.id = 'syncIndicator';
            el.className = 'sync-indicator';
            document.body.appendChild(el);
        }
        el.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.5 2v6h-6M2.5 22v-6h6M2 11.5a10 10 0 0 1 18.8-4.3M22 12.5a10 10 0 0 1-18.8 4.2"/></svg> ' + offlineQueue.length + ' pending';
        el.style.display = 'flex';
    } else if (el) {
        el.style.display = 'none';
    }
}

function apiCall(action, data, callback, skipQueue) {
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
            // Queue critical actions for retry when back online
            if (!skipQueue && QUEUEABLE_ACTIONS.indexOf(action) !== -1) {
                queueOfflineAction(action, data);
                showToast('Saved offline — will sync when connected', 'info');
                if (callback) callback({ success: true, offline_queued: true });
            } else {
                if (callback) callback({ success: false, error: 'Network error' });
            }
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

    // Start location reporting for live tracking
    startLocationReporting();

    // Switch to first tab
    var firstTab = currentUser.tabs[0];
    if (firstTab) switchTab(firstTab.id);

    startAutoRefresh();
    startCountdownTimers();
    startEarningsTickerPolling();
    startNotificationPolling();
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
    if (tabId === 'scan' && !scannerActive) {
        startScanner();
    }

    // Show/hide scan back bar
    if (tabId === 'scan') {
        renderScanBackBar();
    } else {
        removeScanBackBar();
        // Clear scan origin when leaving scan tab (unless going to a detail)
        if (tabId !== 'scan') scanOrigin = null;
    }

    // Load content for other tabs
    refreshTab(tabId);
}

function refreshTab(tabId) {
    switch(tabId) {
        case 'deliveries': loadMyDeliveries(); break;
        case 'available':
            if (availableMode === 'map') loadNearby();
            else loadAvailable();
            break;
        case 'pickup': loadPickupQueue(); break;
        case 'earnings': loadEarnings(); break;
        case 'history': loadHistory(); break;
        case 'activity': loadActivity(); break;
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
    // Show skeleton while loading
    var el = document.getElementById('deliveriesContent');
    if (el && cachedActive.length === 0) el.innerHTML = renderSkeletonCards(3);
    apiCall('get_my_deliveries', null, function(result) {
        if (result.success) {
            cachedActive = result.active || [];
        }
        console.log('[LoadDeliveries] Got ' + cachedActive.length + ' active orders');
        cachedActive.forEach(function(o) { console.log('  - ' + o.ref + ' status=' + o.status); });
        renderDeliveriesView();
        updateNavBadges();
        // Start proximity alerts if courier has in-transit orders
        var hasTransit = cachedActive.some(function(o) { return o.status === 'shipped'; });
        if (hasTransit && currentUser && currentUser.role === 'courier') startProximityWatch();
        else stopProximityWatch();
        // Request notification permission
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }
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
        // Group in-transit single orders into one Active Route card
        var inTransitSingles = activeOrders.filter(function(o) { return o.type !== 'batch' && o.status === 'shipped'; });
        var otherOrders = activeOrders.filter(function(o) { return !(o.type !== 'batch' && o.status === 'shipped'); });

        // Render Active Route card if multiple in-transit singles
        if (inTransitSingles.length > 1) {
            html += renderActiveRouteCard(inTransitSingles);
        } else if (inTransitSingles.length === 1) {
            html += renderOrderCard(inTransitSingles[0], 'delivery');
        }

        // Render remaining orders (batches + non-transit singles)
        otherOrders.forEach(function(o) {
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
var availableMode = 'list'; // 'list' or 'map'
var cachedAvailActive = [];
var cachedAvailUpcoming = [];

function loadAvailable() {
    // Show skeleton while loading
    var availEl = document.getElementById('availableContent');
    if (availEl && cachedAvailActive.length === 0 && cachedAvailUpcoming.length === 0) availEl.innerHTML = renderSkeletonCards(3);
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
        if (loaded.active) {
            renderAvailableView();
            updateNavBadges();
        }
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

function setAvailableMode(mode) {
    if (mode === availableMode) return;
    availableMode = mode;
    haptic.tap();

    var listView = document.getElementById('availableContent');
    var mapView = document.getElementById('availableMapView');

    // Toggle views
    if (mode === 'map') {
        if (listView) listView.style.display = 'none';
        if (mapView) mapView.style.display = '';
        loadNearby();
    } else {
        if (listView) listView.style.display = '';
        if (mapView) mapView.style.display = 'none';
    }

    // Update toggle buttons
    document.querySelectorAll('.view-mode-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.getAttribute('data-mode') === mode);
    });
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
                var pTime = r.picked_up_at ? new Date(r.picked_up_at) : null;
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
        var p = result.performance || {};
        // Update running earnings ticker
        updateEarningsTicker(s.today);

        var html = '';

        // Performance scorecard
        var onTimeRate = p.on_time_rate || 0;
        var rateClass = onTimeRate >= 90 ? 'perf-green' : (onTimeRate >= 70 ? 'perf-amber' : 'perf-red');
        html += '<div class="perf-scorecard">';
        html += '<div class="perf-stat"><div class="perf-stat-value">' + (p.total_completed || 0) + '</div><div class="perf-stat-label">Deliveries</div></div>';
        html += '<div class="perf-stat"><div class="perf-stat-value ' + rateClass + '">' + onTimeRate + '%</div><div class="perf-stat-label">On Time</div></div>';
        html += '<div class="perf-stat"><div class="perf-stat-value">' + (p.avg_delivery_min || '—') + '<span class="perf-unit">min</span></div><div class="perf-stat-label">Avg Time</div></div>';
        html += '</div>';

        // Earnings summary
        html += '<div class="earnings-summary">';
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
    var el = document.getElementById('availableMapView');
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

    // Group orders by vendor location (cluster overlapping pins)
    var clusters = {};
    orders.forEach(function(order) {
        orderCache[order.ref] = order;
        if (!order.pickup_coords) return;
        // Round coords to ~50m grid for clustering
        var key = (Math.round(order.pickup_coords.lat * 2000) / 2000) + ',' + (Math.round(order.pickup_coords.lng * 2000) / 2000);
        if (!clusters[key]) clusters[key] = { lat: order.pickup_coords.lat, lng: order.pickup_coords.lng, vendor: order.vendor_name || 'Vendor', orders: [] };
        clusters[key].orders.push(order);
    });

    // Create clustered markers
    Object.keys(clusters).forEach(function(key) {
        var cluster = clusters[key];
        var pos = { lat: cluster.lat, lng: cluster.lng };
        bounds.extend(pos);
        var count = cluster.orders.length;
        var labelText = count > 1 ? String(count) : cluster.orders[0].ref.split('-').pop();
        var pinColor = count > 1 ? '#7c3aed' : '#22c55e';
        var pinScale = count > 1 ? 1.8 : 1.5;

        var marker = new google.maps.Marker({
            position: pos,
            map: nearbyMap,
            title: count > 1 ? count + ' orders at ' + cluster.vendor : cluster.orders[0].ref,
            label: { text: labelText, color: '#fff', fontSize: count > 1 ? '12px' : '10px', fontWeight: '700' },
            icon: {
                path: 'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z',
                fillColor: pinColor, fillOpacity: 1, strokeColor: '#ffffff', strokeWeight: 2,
                scale: pinScale, anchor: new google.maps.Point(12, 24), labelOrigin: new google.maps.Point(12, 9)
            }
        });

        (function(c, m, clusterKey) {
            m.addListener('click', function() {
                haptic.tap();
                var infoHtml = '<div style="font-family:Montserrat,system-ui;padding:6px 8px;min-width:200px;">';
                infoHtml += '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">';
                infoHtml += '<strong style="font-size:13px;">' + escapeHtml(c.vendor) + '</strong>';
                infoHtml += '<span onclick="nearbyInfoWindow.close()" style="display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f3f4f6;cursor:pointer;font-size:14px;color:#6b7280;margin-left:8px;">&times;</span>';
                infoHtml += '</div>';
                infoHtml += '<div style="font-size:11px;color:#6b7280;margin-bottom:2px;">' + c.orders.length + ' order' + (c.orders.length !== 1 ? 's' : '') + ' at this location</div>';
                c.orders.forEach(function(o) {
                    infoHtml += '<div style="margin-top:6px;padding-top:6px;border-top:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:center;">';
                    infoHtml += '<div><span style="font-weight:700;font-size:12px;">' + escapeHtml(o.ref) + '</span><br><span style="color:#666;font-size:11px;">\u2192 ' + escapeHtml(o.destination || 'MTCC') + '</span></div>';
                    infoHtml += '<span style="color:#059669;font-weight:700;font-size:12px;">$' + parseFloat(o.est_payout || 0).toFixed(2) + '</span>';
                    infoHtml += '</div>';
                });
                infoHtml += '</div>';
                nearbyInfoWindow.setContent(infoHtml);
                nearbyInfoWindow.open(nearbyMap, m);

                // Filter card list to show only this vendor's orders
                filterNearbyByCluster(clusterKey);
            });
        })(cluster, marker, key);

        nearbyMarkers.push(marker);
    });

    // Build card list — sorted by distance
    orders.sort(function(a, b) { return (a.distance_from_courier_km || 99) - (b.distance_from_courier_km || 99); });

    var html = '<div class="nearby-header">' + orders.length + ' order' + (orders.length !== 1 ? 's' : '') + ' nearby</div>';

    orders.forEach(function(order) {
        var urgency = getUrgencyInfo(order);
        var urgCls = urgency.level === 'red' ? ' nearby-urgent-red' : (urgency.level === 'orange' ? ' nearby-urgent-orange' : '');
        var qty = order.quantity || 1;

        html += '<div class="nearby-card' + urgCls + '" data-ref="' + escapeHtml(order.ref) + '">';

        // Due bar — available status color with date AND time
        var nearbyTimeLabel = (order.due_time_formatted && order.due_time_formatted !== 'Anytime') ? order.due_time_formatted : 'Anytime';
        var barCls = 'nearby-due-bar due-available';
        if (urgency.level === 'red') barCls = 'nearby-due-bar due-red';
        else if (urgency.level === 'orange') barCls = 'nearby-due-bar due-orange';
        html += '<div class="' + barCls + '">';
        html += '<span>Due ' + escapeHtml(order.due_date_formatted || 'TBD') + '  |  by: ' + escapeHtml(nearbyTimeLabel) + '</span>';
        html += '<span class="order-status-badge badge-ready badge-sm due-bar-badge">Available</span>';
        html += '</div>';

        // Top row — ID + payout
        html += '<div class="nearby-card-top">';
        html += '<div class="nearby-card-info">';
        html += '<div class="order-ref"><span class="ref-label">ID:</span> ' + escapeHtml(order.ref) + '</div>';
        html += '</div>';
        if (order.est_payout) {
            html += '<div class="nearby-card-payout">$' + parseFloat(order.est_payout).toFixed(2) + '</div>';
        }
        html += '</div>';

        // Route — pickup to dropoff (single line)
        html += '<div class="nearby-route-row">';
        html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        html += '<span class="nearby-route-name">' + escapeHtml(order.vendor_name || 'Vendor') + '</span>';
        html += '<span class="nearby-route-arrow">\u2192</span>';
        html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        html += '<span class="nearby-route-name">' + escapeHtml(order.destination || 'MTCC') + '</span>';
        html += '</div>';

        // Footer — left: distance/time, right: package/size
        html += '<div class="nearby-card-footer">';
        html += '<div class="card-footer-left">';
        html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgb(124,58,237)" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> ' + (order.distance_from_courier_km || '?') + ' km</span>';
        html += '<span class="card-footer-dot">\u00b7</span>';
        html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgb(124,58,237)" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> ~' + (order.est_pickup_min || '?') + ' min</span>';
        html += '</div>';
        html += '<div class="card-footer-right">';
        html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgb(124,58,237)" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + qty + '</span>';
        if (order.size) {
            html += '<span class="card-footer-dot">\u00b7</span>';
            html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="rgb(124,58,237)" stroke-width="2"><path d="M10 15v-3"/><path d="M14 15v-3"/><path d="M18 15v-3"/><path d="M2 8V4"/><path d="M22 6H2"/><path d="M22 8V4"/><path d="M6 15v-3"/><rect x="2" y="12" width="20" height="8" rx="2"/></svg> ' + escapeHtml(order.size) + '</span>';
        }
        html += '</div>';
        html += '</div>';

        html += '</div>';
    });

    listEl.innerHTML = html;

    // Fit map to bounds
    if (nearbyMap && bounds) {
        nearbyMap.fitBounds(bounds, { padding: 40 });
    }

    // Store for filtering
    window._nearbyAllOrders = orders;
    window._nearbyClusters = clusters;

    // Make nearby cards clickable
    listEl.querySelectorAll('.nearby-card').forEach(function(card) {
        card.addEventListener('click', function() {
            haptic.tap();
            var ref = card.getAttribute('data-ref');
            if (ref && orderCache[ref]) showOrderDetail(ref, 'available');
        });
    });
}

// Filter nearby cards to show only orders from a specific vendor cluster
function filterNearbyByCluster(clusterKey) {
    var listEl = document.getElementById('nearbyList');
    if (!listEl || !window._nearbyClusters || !window._nearbyClusters[clusterKey]) return;
    var cluster = window._nearbyClusters[clusterKey];
    var refs = cluster.orders.map(function(o) { return o.ref; });

    // Hide cards not in this cluster, show matching ones
    var cards = listEl.querySelectorAll('.nearby-card');
    var shown = 0;
    cards.forEach(function(card) {
        var ref = card.getAttribute('data-ref');
        if (refs.indexOf(ref) !== -1) {
            card.style.display = '';
            shown++;
        } else {
            card.style.display = 'none';
        }
    });

    // Update header with filter info + show all button
    var header = listEl.querySelector('.nearby-header');
    if (header) {
        header.innerHTML = '<span>' + shown + ' order' + (shown !== 1 ? 's' : '') + ' at ' + escapeHtml(cluster.vendor) + '</span>' +
            '<button class="nearby-show-all" onclick="haptic.tap(); clearNearbyFilter()">Show All</button>';
    }
}

function clearNearbyFilter() {
    var listEl = document.getElementById('nearbyList');
    if (!listEl) return;
    // Show all cards
    listEl.querySelectorAll('.nearby-card').forEach(function(card) { card.style.display = ''; });
    // Reset header
    var total = window._nearbyAllOrders ? window._nearbyAllOrders.length : 0;
    var header = listEl.querySelector('.nearby-header');
    if (header) header.innerHTML = total + ' order' + (total !== 1 ? 's' : '') + ' nearby';
    // Close info window
    if (nearbyInfoWindow) nearbyInfoWindow.close();
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
            orderCache[ref].route_polyline = result.route.polyline;
        }

        // Update route stats in detail panel
        var statEl = document.getElementById('orderRouteStat_' + ref);
        if (statEl) {
            statEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> <strong>' + result.route.distance_km + ' km</strong>' +
                '<span class="bv7-stat-dot">\u2022</span>' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> <strong>~' + result.route.duration_min + ' min</strong>';
        }
        // Update static map with real polyline
        if (result.route.polyline) {
            var mapImg = document.getElementById('orderMapImg_' + ref);
            if (mapImg && orderCache[ref]) {
                var addrs = [];
                if (orderCache[ref].vendor_address) addrs.push(orderCache[ref].vendor_address);
                if (orderCache[ref].destination_address) addrs.push(orderCache[ref].destination_address);
                if (addrs.length > 0) mapImg.src = buildStaticMapUrl(addrs, 600, 250, result.route.polyline);
            }
        }
        // Start live ETA countdown for in-transit orders
        if (result.route.duration_min && orderCache[ref] && orderCache[ref].status === 'shipped') {
            startETACountdown(ref, result.route.duration_min);
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
        if (!isInTransit) {
            // Pickup — only shown when not yet in transit
            html += '<div class="card-route-stop"><div class="card-route-dot-col"><svg class="card-route-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg><div class="card-route-line-v"></div></div>';
            html += '<div class="card-route-info"><div class="card-route-label">PICKUP</div><div class="card-route-name">' + escapeHtml(pickup) + '</div>';
            if (pickupAddr) html += '<div class="card-route-addr">' + escapeHtml(pickupAddr) + '</div>';
            html += '</div></div>';
        }
        // Dropoff — always shown, label changes for in-transit
        var dropLabel = isInTransit ? 'DELIVERING TO' : 'DROPOFF';
        html += '<div class="card-route-stop"><div class="card-route-dot-col"><svg class="card-route-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>';
        html += '<div class="card-route-info"><div class="card-route-label">' + dropLabel + '</div><div class="card-route-name">' + escapeHtml(dropoff) + '</div>';
        if (dropoffAddr) html += '<div class="card-route-addr">' + escapeHtml(dropoffAddr) + '</div>';
        if (order.destination_instructions) html += '<div class="card-route-addr card-route-instructions">' + escapeHtml(order.destination_instructions) + '</div>';
        if (order.notes) html += '<div class="card-route-addr card-route-notes">' + escapeHtml(order.notes) + '</div>';
        html += '</div></div></div>';
    }

    // Footer: distance/duration left, qty/size right
    if (!isMTCCCard) {
        html += '<div class="order-card-footer">';
        // Left: route info (if available)
        html += '<div class="card-footer-left">';
        if (order.route_distance_km) {
            html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> ' + order.route_distance_km + ' km</span>';
            if (order.route_duration_min) html += '<span class="card-footer-dot">\u00b7</span><span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> ~' + order.route_duration_min + ' min</span>';
        }
        html += '</div>';
        // Right: qty + packing size (ruler icon)
        html += '<div class="card-footer-right">';
        var qty = order.quantity || 1;
        html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + qty + '</span>';
        // Packing size: box dims, tube shortest x 3", or print size fallback
        var packingSize = '', packingWeight = '';
        if (order.packing === 'box' && order.packing_details && order.packing_details.boxes && order.packing_details.boxes.length > 0) {
            var b = order.packing_details.boxes[0];
            packingSize = b.l + '" x ' + b.w + '" x ' + b.h + '"';
            if (b.weight) packingWeight = b.weight;
        } else if (order.packing === 'tube' && order.width && order.height) {
            var shortest = Math.min(order.width, order.height);
            packingSize = shortest + '" x 3" tube';
        } else if (order.size) {
            packingSize = order.size;
        }
        if (packingSize) html += '<span class="card-footer-dot">\u00b7</span><span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M10 15v-3"/><path d="M14 15v-3"/><path d="M18 15v-3"/><path d="M2 8V4"/><path d="M22 6H2"/><path d="M22 8V4"/><path d="M6 15v-3"/><rect x="2" y="12" width="20" height="8" rx="2"/></svg> ' + escapeHtml(packingSize) + '</span>';
        if (packingWeight) html += '<span class="card-footer-dot">\u00b7</span><span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="12" cy="5" r="3"/><path d="M6.5 8a2 2 0 0 0-1.905 1.46L2.1 18.5A2 2 0 0 0 4 21h16a2 2 0 0 0 1.925-2.54L19.4 9.5A2 2 0 0 0 17.48 8Z"/></svg> ' + escapeHtml(packingWeight) + '</span>';
        if (order.has_issue) html += '<span class="order-issue-badge">\u26a0 Issue</span>';
        html += '</div>';
        html += '</div>';
    }

    // Delivery photo thumbnail (shown on delivered orders)
    if (order.delivery_photo && (order.status === 'delivered' || order.status === 'pickedup')) {
        html += '<div class="card-photo-preview"><img src="' + escapeHtml(order.delivery_photo) + '" alt="Delivery photo" loading="lazy"><span class="card-photo-label"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> Photo</span></div>';
    }

    html += '</div>';
    return html;
}


// ============================================
// Active Route Card (grouped in-transit orders)
// ============================================

function renderActiveRouteCard(orders) {
    // Group by destination
    var destinations = {};
    var totalPkgs = 0;
    orders.forEach(function(o) {
        orderCache[o.ref] = o;
        var destKey = o.destination || 'Unknown';
        if (!destinations[destKey]) destinations[destKey] = { name: destKey, address: o.destination_address || '', instructions: o.destination_instructions || '', orders: [] };
        destinations[destKey].orders.push(o);
        totalPkgs += (o.quantity || 1);
    });
    var destList = Object.keys(destinations).map(function(k) { return destinations[k]; });

    var html = '<div class="order-card in-transit-card status-shipped" data-ref="active-route" data-mode="delivery" data-transit="1" onclick="showActiveRouteDetail()">';

    // Transit banner
    html += '<div class="transit-hero-banner">';
    html += '<div class="thb-left"><span class="transit-pulse"></span> <strong>ACTIVE ROUTE</strong></div>';
    html += '<div class="thb-right">' + orders.length + ' orders \u203A</div>';
    html += '</div>';

    // Due bar — use earliest due date
    var earliestDue = orders[0];
    if (earliestDue.due_date_formatted) {
        var timeStr = earliestDue.due_time_formatted || 'Anytime';
        html += '<div class="card-due-bar due-status-shipped">';
        html += '<span class="due-text-mtcc">Due ' + escapeHtml(earliestDue.due_date_formatted) + '  |  by: ' + escapeHtml(timeStr) + '</span>';
        html += '<span class="order-status-badge badge-shipped badge-sm due-bar-badge">In Transit</span>';
        html += '</div>';
    }

    // Destinations only (no pickups)
    html += '<div class="card-route-vertical">';
    destList.forEach(function(dest, idx) {
        var destAddr = (dest.address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
        html += '<div class="card-route-stop"><div class="card-route-dot-col"><svg class="card-route-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        if (idx < destList.length - 1) html += '<div class="card-route-line-v"></div>';
        html += '</div>';
        html += '<div class="card-route-info"><div class="card-route-label">DELIVERING TO</div><div class="card-route-name">' + escapeHtml(dest.name) + '</div>';
        if (destAddr) html += '<div class="card-route-addr">' + escapeHtml(destAddr) + '</div>';
        if (dest.instructions) html += '<div class="card-route-addr card-route-instructions">' + escapeHtml(dest.instructions) + '</div>';
        html += '<div class="card-route-addr" style="color:#6b7280;">' + dest.orders.length + ' order' + (dest.orders.length !== 1 ? 's' : '') + '</div>';
        html += '</div></div>';
    });
    html += '</div>';

    // Footer
    html += '<div class="order-card-footer">';
    html += '<div class="card-footer-left">';
    html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M18 8c0 3.613-3.869 7.429-5.393 8.795a1 1 0 0 1-1.214 0C9.87 15.429 6 11.613 6 8a6 6 0 0 1 12 0"/><circle cx="12" cy="8" r="2"/><path d="M8.714 14h-3.71a1 1 0 0 0-.948.683l-2.004 6A1 1 0 0 0 3 22h18a1 1 0 0 0 .948-1.316l-2-6a1 1 0 0 0-.949-.684h-3.712"/></svg> ' + destList.length + ' stop' + (destList.length !== 1 ? 's' : '') + '</span>';
    html += '</div>';
    html += '<div class="card-footer-right">';
    html += '<span class="card-meta"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + totalPkgs + ' pkg' + (totalPkgs !== 1 ? 's' : '') + '</span>';
    html += '</div>';
    html += '</div>';

    html += '</div>';
    return html;
}

// Store grouped in-transit orders for detail view
window._activeRouteOrders = [];

function showActiveRouteDetail() {
    var orders = cachedActive ? cachedActive.filter(function(o) { return o.type !== 'batch' && o.status === 'shipped'; }) : [];
    if (orders.length === 0) return;
    if (orders.length === 1) { showOrderDetail(orders[0].ref, 'delivery'); return; }

    haptic.tap();
    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    var content = document.getElementById('detailContent');
    var panelHeader = document.getElementById('detailPanelHeader');
    panel.style.transition = '';
    panel.style.transform = '';
    overlay.style.transition = '';
    overlay.style.opacity = '';

    var courierColor = '#14b8a6';
    setPanelGradient(panel, courierColor);
    panel.classList.add('mtcc-panel');

    // Header — fixed
    var headerHtml = '<div class="detail-due-bar" onclick="closeDetailPanel()">';
    headerHtml += '<div class="detail-due-left">';
    headerHtml += '<span class="detail-due-heading">ACTIVE ROUTE</span>';
    headerHtml += '<span class="detail-due-date">' + orders.length + ' orders in transit</span>';
    headerHtml += '</div>';
    headerHtml += '<span class="order-status-badge badge-shipped mtcc-header-badge">In Transit</span>';
    headerHtml += '</div>';
    if (panelHeader) panelHeader.innerHTML = headerHtml;

    // Content — group by destination
    var destinations = {};
    orders.forEach(function(o) {
        var destKey = o.destination || 'Unknown';
        if (!destinations[destKey]) destinations[destKey] = { name: destKey, address: o.destination_address || '', instructions: o.destination_instructions || '', orders: [] };
        destinations[destKey].orders.push(o);
    });
    var destList = Object.keys(destinations).map(function(k) { return destinations[k]; });

    var html = '';
    html += '<div style="padding-top:14px;">';

    // Optimize Route button for multiple destinations
    if (destList.length >= 2) {
        window._optimizeStops = destList.map(function(d) {
            // Use first order's destination data for coords
            var o = d.orders[0];
            return { lat: o.destination_lat || 0, lng: o.destination_lng || 0, address: d.address || '' };
        });
        html += '<div style="padding:0 0 12px;"><button class="route-optimize-btn" onclick="optimizeRoute(window._optimizeStops, this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> Optimize Route</button></div>';
    }

    destList.forEach(function(dest, dIdx) {
        var destAddr = (dest.address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
        html += '<div class="batch-timeline-v7">';
        html += '<div class="bv7-stop">';
        html += '<div class="bv7-left"><div class="bv7-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div></div>';
        html += '<div class="bv7-right">';
        html += '<div class="bv7-label"><span class="route-stop-label">DELIVERING TO</span></div>';
        html += '<div class="bv7-name">' + escapeHtml(dest.name) + '</div>';
        if (destAddr) html += '<div class="bv7-addr">' + escapeHtml(destAddr) + '</div>';
        if (dest.instructions) html += '<div class="bv7-addr" style="color:#3b82f6;font-style:italic;">' + escapeHtml(dest.instructions) + '</div>';

        // MTCC phone + hours
        html += '<div class="bv7-contact-row">';
        html += '<a class="bv7-phone" href="tel:' + SUPPORT_PHONES.mtcc.number.replace(/[^0-9+]/g, '') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg> ' + SUPPORT_PHONES.mtcc.number + '</a>';
        html += '</div>';
        if (destAddr) html += '<a class="bv7-nav" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(destAddr) + '" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>';

        // Orders for this destination
        html += '<div class="bv7-items">';
        dest.orders.forEach(function(o) {
            var qty = o.quantity || 1;
            html += '<div class="bv7-item">';
            html += '<div class="bv7-item-field"><span class="bv7-item-label">Order</span><span class="bv7-item-ref">' + escapeHtml(o.ref) + '</span></div>';
            html += '<div class="bv7-item-field"><span class="bv7-item-label">Customer</span><span class="bv7-item-val">' + escapeHtml(o.customer_name || '') + '</span></div>';
            html += '<div class="bv7-item-field"><span class="bv7-item-label">Vendor Ref</span><span class="bv7-item-vref-bold">' + escapeHtml(o.vendor_order_number || 'N/A') + '</span></div>';
            if (o.tracking) html += '<div class="bv7-item-field"><span class="bv7-item-label">Barcode</span><span class="bv7-item-val bv7-item-barcode">' + escapeHtml(o.tracking) + '</span></div>';
            html += '<div class="bv7-item-bottom-row">';
            html += '<span class="bv7-item-boxes"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + qty + ' box' + (qty !== 1 ? 'es' : '') + '</span>';
            if (o.material || o.size) html += '<span class="bv7-item-meta">' + escapeHtml(o.material || '') + (o.size ? ' \u00b7 ' + escapeHtml(o.size) : '') + '</span>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';

        html += '</div></div>'; // bv7-right, bv7-stop
        html += '</div>'; // batch-timeline-v7

        if (dIdx < destList.length - 1) html += '<div style="height:1px;background:#e5e7eb;margin:8px 0;"></div>';
    });

    html += '</div>';

    // Quick connect
    html += '<div class="bv7-quick-connect">';
    html += '<div class="bv7-qc-label"><span class="bv7-qc-name bv7-qc-name-lg">Print Stuff Support</span></div>';
    html += '<div class="bv7-qc-buttons">';
    html += '<a class="bv7-qc-btn" href="https://tawk.to/chat/69bcadcf600a121c36fa7a4b/1jk4gdsmg" target="_blank" rel="noopener" title="Live Chat"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>';
    html += '<a class="bv7-qc-btn" href="tel:' + SUPPORT_PHONES.admin.number.replace(/[^0-9+]/g, '') + '" title="Call"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg></a>';
    html += '</div>';
    html += '</div>';

    content.innerHTML = html;
    panel.classList.add('active');
    overlay.classList.add('active');
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

    // Due date header — goes into fixed panel header
    var dueStr = order.due_date_formatted || order.due_date || '';
    var timeStr = (order.due_time_formatted && order.due_time_formatted !== 'Anytime') ? order.due_time_formatted : 'Anytime';
    var headerHtml = '<div class="mtcc-detail-header" onclick="closeDetailPanel()">';
    if (dueStr) {
        headerHtml += '<div class="mtcc-detail-due"><span class="mtcc-due-label">DUE DATE</span><span class="mtcc-due-value">' + escapeHtml(dueStr) + '  |  <span class="detail-due-by">by:</span> ' + escapeHtml(timeStr) + '</span></div>';
    }
    headerHtml += '<span class="order-status-badge ' + badgeClass + ' mtcc-header-badge">' + (statusLabels[order.status] || order.status) + '</span>';
    headerHtml += '</div>';
    var ph = document.getElementById('detailPanelHeader');
    if (ph) ph.innerHTML = headerHtml;

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

    // Track Courier button — visible when order is in transit
    if (order.status === 'shipped' || order.status === 'dispatched') {
        html += '<button class="track-courier-btn" onclick="haptic.tap(); showCourierTracking(\'' + escapeAttr(order.ref) + '\')">';
        html += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        html += ' Track Courier Live</button>';
    }

    // Actions (Fix 3: 50/50 buttons for pickup, Report Issue always visible)
    html += '<div class="mtcc-detail-actions">';
    if (order.status === 'delivered') {
        html += '<div class="mtcc-btn-row">';
        html += '<button class="mtcc-action-btn mtcc-btn-confirm" onclick="updateOrderStatus(\'' + escapeAttr(order.ref) + '\', \'pickedup\')">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Confirm Pick Up</button>';
        html += '<button class="mtcc-action-btn mtcc-btn-scan" onclick="scanExpectedRef=\'' + escapeAttr(order.ref) + '\'; closeDetailPanel(); switchTab(\'scan\')">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/></svg> Scan to Verify</button>';
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

    // Quick connect — label left, icon buttons right (no phone number shown)
    html += '<div class="bv7-quick-connect">';
    html += '<div class="bv7-qc-label"><span class="bv7-qc-name bv7-qc-name-lg">Print Stuff Support</span></div>';
    html += '<div class="bv7-qc-buttons">';
    html += '<a class="bv7-qc-btn" href="https://tawk.to/chat/69bcadcf600a121c36fa7a4b/1jk4gdsmg" target="_blank" rel="noopener" title="Live Chat"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>';
    html += '<a class="bv7-qc-btn" href="tel:+14378828822" title="Call"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg></a>';
    html += '</div>';
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

function setPanelGradient(panel, color) {
    var gradientEnds = {
        '#d97706': '#b45309', '#7c3aed': '#6d28d9', '#14b8a6': '#0d9488',
        '#059669': '#047857', '#22c55e': '#16a34a', '#dc2626': '#b91c1c',
        '#6366f1': '#4f46e5', '#8b5cf6': '#7c3aed', '#64748b': '#475569',
        '#ea580c': '#c2410c', '#ca8a04': '#a16207', '#eab308': '#ca8a04',
        '#e11d48': '#be123c', '#9ca3af': '#6b7280'
    };
    panel.style.setProperty('--mtcc-header-color', color);
    panel.style.setProperty('--mtcc-header-color-end', gradientEnds[color] || color);
}

function showOrderDetail(ref, mode) {
    var order = orderCache[ref];
    if (!order) return;
    haptic.tap();
    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    var content = document.getElementById('detailContent');
    var panelHeader = document.getElementById('detailPanelHeader');
    // Clear any leftover inline styles from close animation
    panel.style.transition = '';
    panel.style.transform = '';
    overlay.style.transition = '';
    overlay.style.opacity = '';

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
        setPanelGradient(panel, pc);
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
    setPanelGradient(panel, courierColor);
    panel.classList.add('mtcc-panel');

    // Fetch route info if polyline missing (for proper map path) or distance unknown
    if (order && (!order.route_polyline || !order.route_distance_km)) {
        fetchRouteInfo(ref);
    }
    var isPipeline = (mode === 'upcoming');
    var urgency = getUrgencyInfo(order);
    var badgeClass = 'badge-' + order.status;
    var phoneIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg>';
    // Due-bar goes into fixed panel header (never scrolls)
    var headerHtml = '';
    if (order.due_date && !isPipeline) {
        var dueStr = order.due_date_formatted || order.due_date;
        var timeStr = order.due_time_formatted || 'Anytime';
        headerHtml += '<div class="detail-due-bar" onclick="closeDetailPanel()">';
        headerHtml += '<div class="detail-due-left">';
        headerHtml += '<span class="detail-due-heading">DUE DATE</span>';
        headerHtml += '<span class="detail-due-date">' + escapeHtml(dueStr) + '  |  <span class="detail-due-by">by:</span> ' + escapeHtml(timeStr) + '</span>';
        headerHtml += '</div>';
        headerHtml += '<span class="order-status-badge ' + badgeClass + ' mtcc-header-badge">' + (statusLabels[order.status] || order.status) + '</span>';
        headerHtml += '</div>';
    }
    if (panelHeader) panelHeader.innerHTML = headerHtml;

    var html = '';

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

    // Header — ORDER label + ID + payout (matching batch layout)
    html += '<div class="detail-order-header">';
    html += '<div>';
    html += '<div class="batch-id-label">ORDER</div>';
    html += '<div class="detail-order-ref">' + escapeHtml(order.ref) + '</div>';
    html += '</div>';
    if (order.est_payout) {
        html += '<div class="batch-payout-top"><div class="batch-payout-top-label">Est. Payout</div><div class="batch-payout-top-amount">$' + parseFloat(order.est_payout).toFixed(2) + '</div></div>';
    }
    html += '</div>';

    // Route Card (includes customer info inside pickup stop, matching batch layout)
    html += renderRouteCard(order, mode);

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
            // Release + issue row
            html += '<div class="courier-detail-actions">';
            html += '<div class="courier-btn-row">';
            html += '<button class="release-btn" onclick="releaseDelivery(\'' + escapeAttr(order.ref) + '\', this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release</button>';
            if (typeof CourierIssues !== 'undefined') {
                html += '<button class="release-btn" style="border-color:#d97706;color:#d97706;" onclick="CourierIssues.open(\'' + escapeAttr(order.ref) + '\')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Report Issue</button>';
            }
            html += '<button class="release-btn" style="border-color:#3b82f6;color:#3b82f6;" onclick="showQuickMessagePanel(\'' + escapeAttr(order.ref) + '\')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg> Dispatch</button>';
            html += '</div>';
            html += '</div>';
            // Scan button stored for sticky rendering after contacts
            window._orderScanRef = order.ref;
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

    // Share tracking link — visible when order is in transit
    if (order.status === 'shipped' && currentUser && currentUser.role === 'courier') {
        var trackUrl = window.location.origin + '/courier/track.php?ref=' + encodeURIComponent(order.ref);
        html += '<button class="share-tracking-btn" onclick="haptic.tap(); if(navigator.share){navigator.share({title:\'Track Delivery\',text:\'Track your delivery: ' + escapeAttr(order.ref) + '\',url:\'' + escapeAttr(trackUrl) + '\'})}else{navigator.clipboard.writeText(\'' + escapeAttr(trackUrl) + '\');showToast(\'Tracking link copied!\',\'success\')}">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><line x1="8.59" y1="13.51" x2="15.42" y2="17.49"/><line x1="15.41" y1="6.51" x2="8.59" y2="10.49"/></svg>';
        html += ' Share Tracking Link</button>';
    }

    // Quick connect — label left, icon buttons right (no phone number shown)
    html += '<div class="bv7-quick-connect">';
    html += '<div class="bv7-qc-label"><span class="bv7-qc-name bv7-qc-name-lg">Print Stuff Support</span></div>';
    html += '<div class="bv7-qc-buttons">';
    html += '<a class="bv7-qc-btn" href="https://tawk.to/chat/69bcadcf600a121c36fa7a4b/1jk4gdsmg" target="_blank" rel="noopener" title="Live Chat"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>';
    html += '<a class="bv7-qc-btn" href="tel:' + SUPPORT_PHONES.admin.number.replace(/[^0-9+]/g, '') + '" title="Call"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg></a>';
    html += '</div>';
    html += '</div>';

    // Sticky scan button for dispatched single orders (after all content)
    if (window._orderScanRef && mode === 'delivery' && order.status === 'dispatched' && currentUser.role === 'courier') {
        var _ref = window._orderScanRef;
        html += '<div class="courier-sticky-action">';
        html += '<button class="status-action-btn btn-scan-goto" onclick="scanOrigin={type:\'order\',id:\'' + escapeAttr(_ref) + '\',label:\'' + escapeAttr(_ref) + ' Details\'}; scanExpectedRef=\'' + escapeAttr(_ref) + '\'; closeDetailPanel(); switchTab(\'scan\')">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/></svg>';
        html += ' Scan to Confirm Pickup</button>';
        html += '</div>';
        window._orderScanRef = null;
    }

    content.innerHTML = html;
    panel.classList.add('active');
    overlay.classList.add('active');
}

// --- Route Card ---
function renderRouteCard(order, mode) {
    var pickup = order.vendor_name || 'Vendor';
    var pickupAddr = (order.vendor_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
    var dropoff = order.destination || 'Destination';
    var dropoffAddr = (order.destination_address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
    var instructions = order.destination_instructions || '';
    var isInTransit = (mode === 'delivery' && order.status === 'shipped');

    var html = '';
    var singleAddrs = [];
    if (!isInTransit && pickupAddr) singleAddrs.push(pickupAddr);
    if (dropoffAddr) singleAddrs.push(dropoffAddr);

    // Map block (matching batch layout)
    html += '<div class="bv7-map-block">';
    html += '<div class="bv7-stats-bar">';
    html += '<span class="bv7-stats-title">Route</span>';
    html += '<span class="bv7-stats-sep">|</span>';
    // Show route data immediately if available, otherwise show loading
    if (order.route_distance_km) {
        html += '<span class="bv7-stat" id="orderRouteStat_' + escapeAttr(order.ref) + '">';
        html += '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> <strong>' + order.route_distance_km + ' km</strong>';
        if (order.route_duration_min) html += '<span class="bv7-stat-dot">\u2022</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> <strong>~' + order.route_duration_min + ' min</strong>';
        html += '</span>';
    } else {
        html += '<span class="bv7-stat" id="orderRouteStat_' + escapeAttr(order.ref) + '"><span class="route-info-loading">Calculating...</span></span>';
    }
    html += '</div>';
    if (singleAddrs.length > 0) {
        var orderPolyline = order.route_polyline || null;
        var staticUrl = buildStaticMapUrl(singleAddrs, 600, 250, orderPolyline);
        var navUrl = singleAddrs.length >= 2 ? buildGoogleNavUrl(singleAddrs) : 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(singleAddrs[0]);
        html += '<a class="bv7-map-link" href="' + navUrl + '" target="_blank" rel="noopener"><img class="bv7-map-img" id="orderMapImg_' + escapeAttr(order.ref) + '" src="' + staticUrl + '" alt="Route" loading="lazy"></a>';
    }
    html += '</div>';
    if (singleAddrs.length > 0) {
        html += '<div class="route-map-buttons' + (isAppleDevice() ? '' : ' single') + '" style="margin:8px 0 0;">';
        html += '<a class="route-app-btn google" href="' + buildGoogleNavUrl(singleAddrs) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> Google Maps</a>';
        if (isAppleDevice()) html += '<a class="route-app-btn apple" href="' + buildAppleNavUrl(singleAddrs) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Apple Maps</a>';
        html += '</div>';
    }
    html += '<div style="height:1px;background:#e5e7eb;margin:16px 16px;"></div>';

    // Stops timeline (matching batch bv7 layout)
    html += '<div class="batch-timeline-v7">';

    // Order item info helper
    var oQty = order.quantity || 1;
    var itemHtml = '';
    itemHtml += '<div class="bv7-items">';
    itemHtml += '<div class="bv7-item">';
    itemHtml += '<div class="bv7-item-field"><span class="bv7-item-label">Customer</span><span class="bv7-item-val">' + escapeHtml(order.customer_name) + '</span></div>';
    itemHtml += '<div class="bv7-item-field"><span class="bv7-item-label">Vendor Ref</span><span class="bv7-item-vref-bold">' + escapeHtml(order.vendor_order_number || 'N/A') + '</span></div>';
    if (order.tracking) itemHtml += '<div class="bv7-item-field"><span class="bv7-item-label">Barcode</span><span class="bv7-item-val bv7-item-barcode">' + escapeHtml(order.tracking) + '</span></div>';
    itemHtml += '<div class="bv7-item-bottom-row">';
    itemHtml += '<span class="bv7-item-boxes"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + oQty + ' box' + (oQty !== 1 ? 'es' : '') + '</span>';
    if (order.material || order.size) itemHtml += '<span class="bv7-item-meta">' + escapeHtml(order.material || '') + (order.size ? ' \u00b7 ' + escapeHtml(order.size) : '') + '</span>';
    if (order.has_issue) itemHtml += '<span class="bv7-item-issue-reported"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue</span>';
    itemHtml += '</div>';
    if (order.notes) itemHtml += '<div class="bv7-item-field" style="margin-top:4px;"><span class="bv7-item-label">Notes</span><span class="bv7-item-val" style="font-style:italic;color:#6b7280;">' + escapeHtml(order.notes) + '</span></div>';
    itemHtml += '</div>';
    itemHtml += '</div>';

    // Pickup — only shown when not in transit
    if (!isInTransit) {
        html += '<div class="bv7-stop">';
        html += '<div class="bv7-left"><div class="bv7-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></div><div class="bv7-line"></div></div>';
        html += '<div class="bv7-right">';
        html += '<div class="bv7-label"><span class="route-stop-label">PICKUP</span></div>';
        html += '<div class="bv7-name">' + escapeHtml(pickup) + '</div>';
        if (pickupAddr) html += '<div class="bv7-addr">' + escapeHtml(pickupAddr) + '</div>';
        if (order.vendor_phone) {
            html += '<div class="bv7-contact-row">';
            html += '<a class="bv7-phone" href="tel:' + order.vendor_phone.replace(/[^0-9+]/g, '') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg> ' + escapeHtml(order.vendor_phone) + '</a>';
            var vDay = new Date().getDay();
            var vIsWeekday = (vDay >= 1 && vDay <= 5);
            var vNowMins = new Date().getHours() * 60 + new Date().getMinutes();
            html += '<span class="bv7-vdivider"></span>';
            if (!vIsWeekday) {
                html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Closed weekends<span class="bv7-hours-divider"></span><span class="bv7-hours-status closed">Closed</span></span>';
            } else {
                var vOpen = (vNowMins >= 540 && vNowMins < 1080);
                html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> 9:00 - 18:00<span class="bv7-hours-divider"></span><span class="bv7-hours-status ' + (vOpen ? 'open' : 'closed') + '">' + (vOpen ? 'Open' : 'Closed') + '</span></span>';
            }
            html += '</div>';
        }
        // Order item info under pickup
        html += itemHtml;
        if (pickupAddr) html += '<a class="bv7-nav" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(pickupAddr) + '" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>';
        html += '</div></div>';
    }

    // Dropoff (or "Delivering To" when in transit)
    html += '<div class="bv7-stop">';
    html += '<div class="bv7-left"><div class="bv7-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div></div>';
    html += '<div class="bv7-right">';
    html += '<div class="bv7-label"><span class="route-stop-label">' + (isInTransit ? 'DELIVERING TO' : 'DROPOFF') + '</span></div>';
    html += '<div class="bv7-name">' + escapeHtml(dropoff) + '</div>';
    if (dropoffAddr) html += '<div class="bv7-addr">' + escapeHtml(dropoffAddr) + '</div>';
    if (instructions) {
        html += '<div class="bv7-wayfinding"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg><span>' + escapeHtml(instructions) + '</span></div>';
    }
    // When in transit, show order items under dropoff instead
    if (isInTransit) html += itemHtml;
    // MTCC phone + hours (building icon for business line)
    html += '<div class="bv7-contact-row">';
    html += '<a class="bv7-phone" href="tel:' + SUPPORT_PHONES.mtcc.number.replace(/[^0-9+]/g, '') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg> ' + SUPPORT_PHONES.mtcc.number + '</a>';
    html += '<span class="bv7-vdivider"></span>';
    var mDay = new Date().getDay();
    var mIsWeekday = (mDay >= 1 && mDay <= 5);
    var mNowMins = new Date().getHours() * 60 + new Date().getMinutes();
    if (!mIsWeekday) {
        html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Closed weekends<span class="bv7-hours-divider"></span><span class="bv7-hours-status closed">Closed</span></span>';
    } else {
        var mOpen = (mNowMins >= 540 && mNowMins < 960);
        html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> 9:00 - 16:00<span class="bv7-hours-divider"></span><span class="bv7-hours-status ' + (mOpen ? 'open' : 'closed') + '">' + (mOpen ? 'Open' : 'Closed') + '</span></span>';
    }
    html += '</div>';
    if (dropoffAddr) html += '<a class="bv7-nav" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(dropoffAddr) + '" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>';
    html += '</div></div>';
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


function closeDetailPanel() {
    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    // Animate out with explicit transform — no class-based transition conflict
    panel.style.transition = 'transform 0.25s cubic-bezier(0.4, 0, 1, 1)';
    panel.style.transform = 'translateY(100%)';
    overlay.classList.remove('active');
    // Clean up AFTER animation completes
    setTimeout(function() {
        panel.classList.remove('active');
        panel.classList.remove('mtcc-panel');
        panel.style.transition = '';
        panel.style.transform = '';
        panel.style.removeProperty('--mtcc-header-color');
        panel.style.removeProperty('--mtcc-header-color-end');
        if (overlay) {
            overlay.style.transition = '';
            overlay.style.opacity = '';
        }
    }, 250);
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
    haptic.tap();
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
            // Show celebration animation for delivery/pickup completion
            if (newStatus === 'delivered' || newStatus === 'pickedup') {
                var order = orderCache[ref];
                showDeliverySuccess(ref, order ? order.customer_name : '');
            }
            showToast(result.message, 'success');
            closeDetailPanel();
            hideScanResult();
            refreshTab(currentTab);
            updateNavBadges();
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
            scanLocked = false;
            return;
        }

        // Batch scan mode — auto-confirm pickup
        if (batchScanMode) {
            var scannedRef = result.order.ref;
            // Check if this order is in the batch
            if (batchScanMode.orderRefs.indexOf(scannedRef) === -1) {
                haptic.error();
                showBatchScanFeedback(scannedRef, false, 'Not in this batch');
                scanLocked = false;
                return;
            }
            // Check if already scanned
            if (batchScanMode.scannedRefs.indexOf(scannedRef) !== -1) {
                haptic.warning();
                showBatchScanFeedback(scannedRef, false, 'Already scanned');
                scanLocked = false;
                return;
            }
            // Auto-confirm: update status to shipped
            apiCall('update_status', { ref: scannedRef, status: 'shipped' }, function(statusResult) {
                if (statusResult.success) {
                    batchScanMode.scannedRefs.push(scannedRef);
                    haptic.confirm();
                    showBatchScanFeedback(scannedRef, true, 'Picked up');
                    updateBatchScanProgress();
                    // Check if all items scanned
                    if (batchScanMode.scannedRefs.length >= batchScanMode.orderRefs.length) {
                        setTimeout(function() {
                            haptic.success();
                            showToast('All items picked up!', 'success');
                            exitBatchScan();
                            refreshTab('deliveries');
                        }, 1200);
                    } else {
                        // Unlock for next scan
                        scanLocked = false;
                    }
                } else {
                    haptic.error();
                    showBatchScanFeedback(scannedRef, false, statusResult.error || 'Update failed');
                    scanLocked = false;
                }
            });
            return;
        }

        // Normal scan mode — show result card
        haptic.success();
        showScanResult(result);
    });
}

// ============================================
// Batch Scan Mode
// ============================================

function startBatchScan(batchId, orderRefs) {
    // Set scan origin for back navigation
    scanOrigin = { type: 'batch', id: batchId, label: batchId + ' Details' };

    // Determine which refs are already shipped
    var alreadyShipped = [];
    orderRefs.forEach(function(ref) {
        var o = orderCache[ref];
        if (o && (o.status === 'shipped' || o.status === 'delivered')) {
            alreadyShipped.push(ref);
        }
    });

    batchScanMode = {
        batchId: batchId,
        orderRefs: orderRefs,
        scannedRefs: alreadyShipped.slice() // pre-fill already shipped
    };

    closeDetailPanel();
    switchTab('scan');

    // Render batch progress banner
    setTimeout(function() { updateBatchScanProgress(); }, 200);
}

function updateBatchScanProgress() {
    var banner = document.getElementById('batchScanBanner');
    if (!batchScanMode) {
        if (banner) banner.remove();
        return;
    }

    var total = batchScanMode.orderRefs.length;
    var done = batchScanMode.scannedRefs.length;
    var pct = total > 0 ? Math.round((done / total) * 100) : 0;

    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'batchScanBanner';
        banner.className = 'batch-scan-banner';
        var scanContent = document.getElementById('scanContent') || document.getElementById('tab-scan');
        if (scanContent) scanContent.insertBefore(banner, scanContent.firstChild);
    }

    var html = '<div class="bsb-header">';
    html += '<div class="bsb-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> Batch ' + escapeHtml(batchScanMode.batchId) + '</div>';
    html += '<button class="bsb-exit" onclick="exitBatchScan()">Done</button>';
    html += '</div>';
    html += '<div class="bsb-progress-bar"><div class="bsb-progress-fill" style="width:' + pct + '%"></div></div>';
    html += '<div class="bsb-count">' + done + ' of ' + total + ' items picked up</div>';

    // Show scanned items as mini checkmarks
    if (done > 0) {
        html += '<div class="bsb-items">';
        batchScanMode.scannedRefs.forEach(function(ref) {
            html += '<span class="bsb-item-done"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> ' + escapeHtml(ref) + '</span>';
        });
        html += '</div>';
    }

    banner.innerHTML = html;
}

function showBatchScanFeedback(ref, success, message) {
    var el = document.getElementById('scanResult');
    if (!el) return;

    var cls = success ? 'bsf-success' : 'bsf-error';
    var icon = success
        ? '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>'
        : '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';

    el.innerHTML = '<div class="batch-scan-feedback ' + cls + '">' + icon + '<div class="bsf-text"><strong>' + escapeHtml(ref) + '</strong><span>' + escapeHtml(message) + '</span></div></div>';
    el.style.display = 'block';

    // Auto-hide after 1.5s
    setTimeout(function() {
        if (el) { el.style.display = 'none'; el.innerHTML = ''; }
    }, 1500);
}

function exitBatchScan() {
    batchScanMode = null;
    var banner = document.getElementById('batchScanBanner');
    if (banner) banner.remove();
    hideScanResult();
    // Return to batch detail if we have origin
    if (scanOrigin && scanOrigin.type === 'batch') {
        var batchId = scanOrigin.id;
        scanOrigin = null;
        removeScanBackBar();
        switchTab('deliveries');
        setTimeout(function() {
            if (batchCache[batchId]) showBatchDetail(batchId, 'delivery');
        }, 300);
        return;
    }
    scanOrigin = null;
    removeScanBackBar();
}

// ============================================
// Scan Back Navigation Bar
// ============================================

function renderScanBackBar() {
    removeScanBackBar();
    if (!scanOrigin) return;

    var bar = document.createElement('div');
    bar.id = 'scanBackBar';
    bar.className = 'scan-back-bar';

    var backLabel = scanOrigin.label || 'Back';
    bar.innerHTML = '<button class="scan-back-btn" onclick="navigateBackFromScan()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> ' + escapeHtml(backLabel) + '</button>' +
        '<button class="scan-close-btn" onclick="closeScanAndReturn()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>';

    var scanContent = document.getElementById('scanContent') || document.getElementById('tab-scan');
    if (scanContent) scanContent.insertBefore(bar, scanContent.firstChild);
}

function removeScanBackBar() {
    var bar = document.getElementById('scanBackBar');
    if (bar) bar.remove();
}

function navigateBackFromScan() {
    if (!scanOrigin) { switchTab('deliveries'); return; }
    var origin = scanOrigin;
    scanOrigin = null;
    removeScanBackBar();
    stopScanner();
    hideScanResult();

    if (origin.type === 'batch' && batchCache[origin.id]) {
        switchTab('deliveries');
        setTimeout(function() { showBatchDetail(origin.id, 'delivery'); }, 200);
    } else if (origin.type === 'order' && orderCache[origin.id]) {
        switchTab('deliveries');
        setTimeout(function() { showOrderDetail(origin.id, 'delivery'); }, 200);
    } else {
        switchTab('deliveries');
    }
}

function closeScanAndReturn() {
    scanOrigin = null;
    batchScanMode = null;
    removeScanBackBar();
    var banner = document.getElementById('batchScanBanner');
    if (banner) banner.remove();
    stopScanner();
    hideScanResult();
    switchTab('deliveries');
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
            else if (s === 'shipped') { label = 'Confirm Pickup'; icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> '; }
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
        if (currentTab && currentTab !== 'scan') {
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

// ============================================
// Quick-Action Messages to Dispatch
// ============================================
var quickMessageTemplates = [
    { type: 'vendor_not_ready', text: 'Vendor not ready — order not available for pickup', icon: '&#x23F3;' },
    { type: 'running_late', text: 'Running late — delayed ETA', icon: '&#x1F552;' },
    { type: 'cant_find_location', text: 'Cannot find delivery location', icon: '&#x1F50D;' },
    { type: 'customer_unavailable', text: 'Customer not available at location', icon: '&#x1F6AB;' },
    { type: 'order_damaged', text: 'Order appears damaged', icon: '&#x26A0;' },
    { type: 'need_assistance', text: 'Need assistance from dispatch', icon: '&#x1F6A8;' },
];

function showQuickMessagePanel(ref) {
    // Remove existing
    var existing = document.getElementById('quickMsgPanel');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'quickMsgPanel';
    overlay.className = 'quick-msg-overlay';
    overlay.onclick = function(e) { if (e.target === overlay) closeQuickMessage(); };

    var html = '<div class="quick-msg-sheet">';
    html += '<div class="quick-msg-header"><span>Message Dispatch</span><button onclick="closeQuickMessage()">&times;</button></div>';
    if (ref) html += '<div class="quick-msg-ref">Re: ' + escapeHtml(ref) + '</div>';
    html += '<div class="quick-msg-list">';
    quickMessageTemplates.forEach(function(t) {
        html += '<button class="quick-msg-item" onclick="sendQuickMessage(\'' + escapeAttr(t.type) + '\', \'' + escapeAttr(t.text) + '\', \'' + escapeAttr(ref || '') + '\')">';
        html += '<span class="quick-msg-icon">' + t.icon + '</span>';
        html += '<span class="quick-msg-text">' + escapeHtml(t.text) + '</span>';
        html += '</button>';
    });
    html += '</div>';
    html += '<div class="quick-msg-custom">';
    html += '<input type="text" id="quickMsgCustom" class="quick-msg-input" placeholder="Add a note (optional)" maxlength="200">';
    html += '</div>';
    html += '</div>';

    overlay.innerHTML = html;
    document.body.appendChild(overlay);
    haptic.tap();
}

function sendQuickMessage(type, text, ref) {
    var customNote = '';
    var input = document.getElementById('quickMsgCustom');
    if (input) customNote = input.value.trim();

    closeQuickMessage();
    showToast('Sending to dispatch...', 'info');

    apiCall('send_quick_message', {
        message_type: type,
        message_text: text,
        ref: ref || '',
        custom_note: customNote
    }, function(result) {
        if (result.success) {
            haptic.confirm();
            showToast('Message sent to dispatch', 'success');
        } else {
            showToast(result.error || 'Failed to send', 'error');
        }
    });
}

function closeQuickMessage() {
    var el = document.getElementById('quickMsgPanel');
    if (el) el.remove();
}

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

    // Type banner — layers icon + order count, no price
    html += '<div class="batch-type-banner">';
    html += '<div class="btb-left">';
    if (isInProgress) {
        html += '<span class="transit-pulse"></span> <strong>BATCH IN TRANSIT</strong>';
    } else {
        html += '<strong>BATCH DELIVERY</strong>';
    }
    html += '</div>';
    html += '<div class="btb-right">';
    html += '<span class="btb-orders"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/></svg> ' + batch.order_count + ' orders</span>';
    html += '</div>';
    html += '</div>';

    // Divider between banner and due bar
    html += '<div class="batch-banner-divider"></div>';

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

    // Top row: batch ID (left) + payout (right) — matching single order layout
    var totalPkgs = 0;
    if (batch.orders) batch.orders.forEach(function(o) { totalPkgs += (o.quantity || 1); });
    html += '<div class="order-card-top">';
    html += '<div class="order-card-top-left">';
    html += '<div class="order-ref"><span class="ref-label">ID:</span> ' + escapeHtml(batch.batch_id) + '</div>';
    html += '</div>';
    if (batch.est_payout && mode !== 'pickup') {
        var bBasePay = 0, bBonusTotal = 0;
        if (batch.est_payout_breakdown && Array.isArray(batch.est_payout_breakdown)) {
            batch.est_payout_breakdown.forEach(function(b) {
                if (b.label === 'Base rate') bBasePay = b.amount;
                else bBonusTotal += b.amount;
            });
        }
        if (bBasePay === 0) bBasePay = batch.est_payout;
        html += '<div class="card-payout-area"><div class="card-payout-big">$' + parseFloat(bBasePay).toFixed(2) + '</div>';
        if (bBonusTotal > 0) html += '<div class="card-payout-bonus">+$' + bBonusTotal.toFixed(2) + ' bonus</div>';
        html += '</div>';
    }
    html += '</div>';

    // Multi-stop route timeline
    var stops = batch.stops || [];
    var currentIdx = batch.current_stop_index || 0;
    // Count pickups for numbering (Pickup #1, #2, etc.)
    var totalPickups = 0;
    stops.forEach(function(s) { if (s.type === 'pickup') totalPickups++; });
    var pickupNum = 0;
    if (stops.length > 0) {
    html += '<div class="card-route-vertical">';
    stops.forEach(function(stop, idx) {
        var isDone = (stop.status === 'completed');
        var isSkipped = (stop.status === 'skipped');
        var isCurrent = (isActive && idx === currentIdx && !isDone);
        var stopCls = 'batch-stop-item';
        if (isDone) stopCls += ' done';
        if (isSkipped) stopCls += ' skipped';
        if (isCurrent) stopCls += ' current';

        html += '<div class="' + stopCls + '">';
        // Same structure as single order card (card-route-*)
        html += '<div class="card-route-stop">';
        html += '<div class="card-route-dot-col">';
        if (isDone) {
            html += '<svg class="card-route-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        } else if (stop.type === 'pickup') {
            html += '<svg class="card-route-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        } else {
            html += '<svg class="card-route-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        }
        if (idx < stops.length - 1) html += '<div class="card-route-line-v"></div>';
        html += '</div>';
        html += '<div class="card-route-info">';
        // Label: number pickups when multiple
        var stopLabel = stop.type.toUpperCase();
        if (stop.type === 'pickup') {
            pickupNum++;
            if (totalPickups > 1) stopLabel = 'PICKUP #' + pickupNum;
        }
        html += '<div class="card-route-label">' + stopLabel + (isDone ? ' \u2713' : '') + '</div>';
        html += '<div class="card-route-name">' + escapeHtml(stop.name || '') + '</div>';
        // Address under name
        var stopAddr = (stop.address || '').replace(/\r\n/g, ', ').replace(/\n/g, ', ');
        if (stopAddr) html += '<div class="card-route-addr">' + escapeHtml(stopAddr) + '</div>';
        if (stop.destination_instructions) html += '<div class="card-route-addr card-route-instructions">' + escapeHtml(stop.destination_instructions) + '</div>';
        html += '</div>';
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

    // Route summary bar — icons only, no text labels (matches detail slideout stats bar)
    var hasRouteBar = batch.route && (batch.route.distance_km || batch.route.duration_min);
    html += '<div class="batch-route-bar"' + (!hasRouteBar ? ' style="justify-content:flex-end;"' : '') + '>';
    if (hasRouteBar) {
        if (batch.route.distance_km) html += '<span class="batch-route-stat"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> ' + batch.route.distance_km + ' km</span>';
        if (batch.route.duration_min) html += '<span class="batch-route-stat"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> ~' + batch.route.duration_min + ' min</span>';
        html += '<span class="batch-route-stat"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M18 8c0 3.613-3.869 7.429-5.393 8.795a1 1 0 0 1-1.214 0C9.87 15.429 6 11.613 6 8a6 6 0 0 1 12 0"/><circle cx="12" cy="8" r="2"/><path d="M8.714 14h-3.71a1 1 0 0 0-.948.683l-2.004 6A1 1 0 0 0 3 22h18a1 1 0 0 0 .948-1.316l-2-6a1 1 0 0 0-.949-.684h-3.712"/></svg> ' + stops.length + ' stop' + (stops.length !== 1 ? 's' : '') + '</span>';
    }
    // Package count — right-aligned
    if (totalPkgs > 0) html += '<span class="batch-route-stat batch-route-pkgs"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + totalPkgs + ' pkg' + (totalPkgs !== 1 ? 's' : '') + '</span>';
    html += '</div>';

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

// Build Static Map image URL with markers and optional encoded polyline
function buildStaticMapUrl(addresses, width, height, encodedPolyline) {
    if (!addresses || addresses.length === 0) return '';
    var key = 'AIzaSyDtsKlcP439gjDYjDOTbd-nd4spGM77fYg';
    var params = 'size=' + (width || 600) + 'x' + (height || 250) + '&scale=2&maptype=roadmap';
    // Markers
    addresses.forEach(function(addr, i) {
        var color = (i === addresses.length - 1) ? 'red' : 'blue';
        var label = String.fromCharCode(65 + i);
        params += '&markers=color:' + color + '%7Clabel:' + label + '%7C' + encodeURIComponent(addr);
    });
    // Route path — use encoded polyline if available, else straight lines
    if (encodedPolyline) {
        params += '&path=color:0x7c3aedff%7Cweight:4%7Cenc:' + encodeURIComponent(encodedPolyline);
    } else {
        params += '&path=color:0x7c3aedff%7Cweight:4';
        addresses.forEach(function(addr) {
            params += '%7C' + encodeURIComponent(addr);
        });
    }
    return 'https://maps.googleapis.com/maps/api/staticmap?' + params + '&key=' + key;
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
    var panelHeader = document.getElementById('detailPanelHeader');
    if (!panel || !content) return;
    // Clear any leftover inline styles from close animation
    panel.style.transition = '';
    panel.style.transform = '';

    // Fix 4: Batch header handle colored
    var batchColor = '#7c3aed'; // default violet
    if (batch.status === 'in_progress') batchColor = '#14b8a6';
    else if (batch.status === 'completed') batchColor = '#059669';
    setPanelGradient(panel, batchColor);
    panel.classList.add('mtcc-panel');

    var isAvailable = (mode === 'available');
    var isActive = (batch.status === 'accepted' || batch.status === 'dispatched' || batch.status === 'in_progress');
    var stops = batch.stops || [];
    var orders = batch.orders || [];
    var statusLabelsMap = { pending: 'Pending', dispatched: 'Dispatched', accepted: 'Accepted', in_progress: 'In Transit', completed: 'Completed', cancelled: 'Cancelled', suggested: 'Suggested' };
    var batchStatus = statusLabelsMap[batch.status] || batch.status;
    var badgeClass = 'badge-' + batch.status;
    var phoneIcon = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg>';

    // Due date — fixed header (never scrolls)
    var headerHtml = '';
    if (batch.due_date_formatted) {
        var batchTimeLabel = batch.due_time_formatted || 'Anytime';
        headerHtml += '<div class="detail-due-bar" onclick="closeDetailPanel()">';
        headerHtml += '<div class="detail-due-left">';
        headerHtml += '<span class="detail-due-heading">DUE DATE</span>';
        headerHtml += '<span class="detail-due-date">' + escapeHtml(batch.due_date_formatted) + '  |  <span class="detail-due-by">by:</span> ' + escapeHtml(batchTimeLabel) + '</span>';
        headerHtml += '</div>';
        headerHtml += '<span class="order-status-badge badge-' + batch.status + ' mtcc-header-badge">' + batchStatus + '</span>';
        headerHtml += '</div>';
    }
    if (panelHeader) panelHeader.innerHTML = headerHtml;

    var html = '';

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
        html += '<span class="bv7-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> <strong>' + (batch.route.distance_km || '?') + ' km</strong></span>';
        html += '<span class="bv7-stat-dot">\u2022</span>';
        html += '<span class="bv7-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> <strong>~' + (batch.route.duration_min || '?') + ' min</strong></span>';
        html += '<span class="bv7-stat-dot">\u2022</span>';
        html += '<span class="bv7-stat"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M18 8c0 3.613-3.869 7.429-5.393 8.795a1 1 0 0 1-1.214 0C9.87 15.429 6 11.613 6 8a6 6 0 0 1 12 0"/><circle cx="12" cy="8" r="2"/><path d="M8.714 14h-3.71a1 1 0 0 0-.948.683l-2.004 6A1 1 0 0 0 3 22h18a1 1 0 0 0 .948-1.316l-2-6a1 1 0 0 0-.949-.684h-3.712"/></svg> <strong>' + pickupCount + ' stop' + (pickupCount !== 1 ? 's' : '') + '</strong></span>';
        html += '</div>';
    }
    if (mapAddresses.length >= 1) {
        var batchPolyline = (batch.route && batch.route.polyline) ? batch.route.polyline : null;
        var staticUrl = buildStaticMapUrl(mapAddresses, 600, 250, batchPolyline);
        var navUrl = mapAddresses.length >= 2 ? buildGoogleNavUrl(mapAddresses) : 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(mapAddresses[0]);
        html += '<a class="bv7-map-link" href="' + navUrl + '" target="_blank" rel="noopener"><img class="bv7-map-img" id="batchMapImg" src="' + staticUrl + '" alt="Route map" loading="lazy"></a>';
    }
    html += '</div>'; // end bv7-map-block

    // If no polyline yet, fetch route and update map
    if (mapAddresses.length >= 2 && !(batch.route && batch.route.polyline) && courierLocation) {
        apiCall('get_batch_route', { batch_id: batch.batch_id, lat: courierLocation.lat, lng: courierLocation.lng }, function(r) {
            if (r.success && r.route && r.route.polyline) {
                batch.route = batch.route || {};
                batch.route.polyline = r.route.polyline;
                var img = document.getElementById('batchMapImg');
                if (img) img.src = buildStaticMapUrl(mapAddresses, 600, 250, r.route.polyline);
            }
        });
    }
    // Map buttons outside the map block
    if (mapAddresses.length >= 2) {
        html += '<div class="route-map-buttons' + (isAppleDevice() ? '' : ' single') + '" style="margin:8px 0 0;">';
        html += '<a class="route-app-btn google" href="' + buildGoogleNavUrl(mapAddresses) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg> Google Maps</a>';
        if (isAppleDevice()) html += '<a class="route-app-btn apple" href="' + buildAppleNavUrl(mapAddresses) + '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Apple Maps</a>';
        html += '</div>';
    }

    // Optimize Route button (only for active batches with 2+ stops)
    if (isActive && stops.length >= 2) {
        // Store stop coords for the optimize function
        window._optimizeStops = stops.filter(function(s) { return s.coords; }).map(function(s) { return { lat: s.coords.lat || s.coords[0], lng: s.coords.lng || s.coords[1], address: s.address || '' }; });
        html += '<div style="padding:8px 0;"><button class="route-optimize-btn" onclick="optimizeRoute(window._optimizeStops, this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> Optimize Route</button></div>';
    }

    // Divider between map section and stops
    html += '<div style="height:1px;background:#e5e7eb;margin:16px 16px;"></div>';

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

        // Left column: icon + connector (green when done)
        html += '<div class="bv7-left">';
        html += '<div class="bv7-icon">';
        if (isDone) {
            html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
        } else if (isPickup) {
            html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        } else {
            html += '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        }
        html += '</div>';
        if (!isLast) html += '<div class="bv7-line' + (isDone ? ' bv7-line-done' : '') + '"></div>';
        html += '</div>';

        // Right column: all content
        html += '<div class="bv7-right">';

        // Label + badges (Fix 2: numbered pickups)
        html += '<div class="bv7-label">';
        var stopLabel = isPickup ? 'PICKUP' + (pickupCount > 1 ? ' #' + pickupNum : '') : 'DROPOFF';
        html += '<span class="route-stop-label">' + stopLabel + '</span>';
        if (refCount > 0) html += '<span class="batch-item-badge">' + refCount + ' order' + (refCount !== 1 ? 's' : '') + '</span>';
        if (isCurrent) html += '<span class="batch-current-pill">CURRENT</span>';
        html += '</div>';

        // Name + address
        html += '<div class="bv7-name">' + escapeHtml(stop.name || '') + '</div>';
        if (addr) html += '<div class="bv7-addr">' + escapeHtml(addr) + '</div>';

        // Phone + hours row
        var vendorHours = stop.vendor_hours || '';
        if (vendorPhone || vendorHours || !isPickup) {
            html += '<div class="bv7-contact-row">';
            if (vendorPhone) {
                html += '<a class="bv7-phone" href="tel:' + vendorPhone.replace(/[^0-9+]/g, '') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg> ' + escapeHtml(vendorPhone) + '</a>';
            }
            if (vendorPhone && vendorHours) html += '<span class="bv7-vdivider"></span>';
            if (vendorHours) {
                // Determine if currently open
                var hoursOpenClosed = '';
                if (vendorHours === 'Closed today') {
                    hoursOpenClosed = '<span class="bv7-hours-divider"></span><span class="bv7-hours-status closed">Closed</span>';
                } else if (vendorHours.indexOf(' - ') !== -1) {
                    var parts = vendorHours.split(' - ');
                    var now = new Date();
                    var nowMins = now.getHours() * 60 + now.getMinutes();
                    var openParts = (parts[0] || '9:00').split(':');
                    var closeParts = (parts[1] || '18:00').split(':');
                    var openMins = parseInt(openParts[0]) * 60 + parseInt(openParts[1] || 0);
                    var closeMins = parseInt(closeParts[0]) * 60 + parseInt(closeParts[1] || 0);
                    if (nowMins >= openMins && nowMins < closeMins) {
                        hoursOpenClosed = '<span class="bv7-hours-divider"></span><span class="bv7-hours-status open">Open</span>';
                    } else {
                        hoursOpenClosed = '<span class="bv7-hours-divider"></span><span class="bv7-hours-status closed">Closed</span>';
                    }
                }
                html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' + escapeHtml(vendorHours) + hoursOpenClosed + '</span>';
            }
            // MTCC phone + hours for dropoff stops
            if (!isPickup) {
                html += '<a class="bv7-phone" href="tel:' + SUPPORT_PHONES.mtcc.number.replace(/[^0-9+]/g, '') + '"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg> ' + SUPPORT_PHONES.mtcc.number + '</a>';
                html += '<span class="bv7-vdivider"></span>';
                // MTCC hours: Mon-Fri 9am-4pm
                var mtccHoursText = '9:00 - 16:00';
                var mtccDay = new Date().getDay();
                var isMTCCWeekday = (mtccDay >= 1 && mtccDay <= 5);
                var mtccNow = new Date();
                var mtccNowMins = mtccNow.getHours() * 60 + mtccNow.getMinutes();
                if (!isMTCCWeekday) {
                    html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Closed weekends<span class="bv7-hours-divider"></span><span class="bv7-hours-status closed">Closed</span></span>';
                } else {
                    var mtccOpen = (mtccNowMins >= 540 && mtccNowMins < 960);
                    html += '<span class="bv7-hours"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ' + mtccHoursText + '<span class="bv7-hours-divider"></span><span class="bv7-hours-status ' + (mtccOpen ? 'open' : 'closed') + '">' + (mtccOpen ? 'Open' : 'Closed') + '</span></span>';
                }
            }
            html += '</div>';
        }

        // Nav button
        if (addr) html += '<a class="bv7-nav" href="https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(addr) + '" target="_blank" rel="noopener"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg></a>';

        // Items — always visible
        if (isPickup && stop.order_refs && stop.order_refs.length > 0) {
            html += '<div class="bv7-items">';
            stop.order_refs.forEach(function(oRef) {
                var o = orders.find(function(x) { return x.ref === oRef; }) || {};
                var qty = o.quantity || 1;
                html += '<div class="bv7-item">';
                html += '<div class="bv7-item-field"><span class="bv7-item-label">Order ID</span><span class="bv7-item-ref">' + escapeHtml(oRef) + '</span></div>';
                html += '<div class="bv7-item-field"><span class="bv7-item-label">Customer</span><span class="bv7-item-val">' + escapeHtml(o.customer_name || '') + '</span></div>';
                html += '<div class="bv7-item-field"><span class="bv7-item-label">Vendor Ref</span><span class="bv7-item-vref-bold">' + escapeHtml(o.vendor_order_number || 'N/A') + '</span></div>';
                if (o.tracking) html += '<div class="bv7-item-field"><span class="bv7-item-label">Barcode</span><span class="bv7-item-val bv7-item-barcode">' + escapeHtml(o.tracking) + '</span></div>';
                // Box count + issue on same row
                html += '<div class="bv7-item-bottom-row">';
                html += '<span class="bv7-item-boxes"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg> ' + qty + ' box' + (qty !== 1 ? 'es' : '') + '</span>';
                if (typeof CourierIssues !== 'undefined') {
                    if (o.has_issue) {
                        html += '<span class="bv7-item-issue-reported"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue Reported</span>';
                    } else {
                        html += '<button class="bv7-item-issue" onclick="CourierIssues.open(\'' + escapeAttr(oRef) + '\')"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Issue</button>';
                    }
                }
                html += '</div>';
                html += '</div>';
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

    // Release + Report Issue — same row (matching single order layout)
    var batchPin = batch.courier_pin || (batch.courier ? batch.courier.pin : '') || '';
    var canRelease = false;
    if (batch.status === 'accepted' || batch.status === 'dispatched') {
        if (currentUser.role === 'courier' && batchPin === currentUser.pin) canRelease = true;
        if (currentUser.role === 'admin' || currentUser.role === 'mtcc_staff') canRelease = true;
    }
    var hasIssueBtn = (typeof CourierIssues !== 'undefined' && isActive);
    if (canRelease || hasIssueBtn) {
        if (hasIssueBtn) {
            window._batchIssueOrders = orders.map(function(o) { return { ref: o.ref, customer_name: o.customer_name }; });
            window._batchIssueId = batch.batch_id;
        }
        html += '<div class="courier-btn-row" style="margin:8px 14px;">';
        if (canRelease) html += '<button class="release-btn" onclick="releaseBatch(\'' + escapeAttr(batch.batch_id) + '\', this)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg> Release</button>';
        if (hasIssueBtn) html += '<button class="release-btn" style="border-color:#d97706;color:#d97706;" onclick="CourierIssues.openBatch(window._batchIssueId, window._batchIssueOrders)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Report Issue</button>';
        html += '</div>';
    }

    // Quick connect — label left, icon buttons right (no phone number shown)
    html += '<div class="bv7-quick-connect">';
    html += '<div class="bv7-qc-label"><span class="bv7-qc-name bv7-qc-name-lg">Print Stuff Support</span></div>';
    html += '<div class="bv7-qc-buttons">';
    html += '<a class="bv7-qc-btn" href="https://tawk.to/chat/69bcadcf600a121c36fa7a4b/1jk4gdsmg" target="_blank" rel="noopener" title="Live Chat"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></a>';
    html += '<a class="bv7-qc-btn" href="tel:' + SUPPORT_PHONES.admin.number.replace(/[^0-9+]/g, '') + '" title="Call"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/></svg></a>';
    html += '</div>';
    html += '</div>';

    html += '</div>'; // bt-bottom-actions

    // Scan Batch Items — sticky at very bottom, above everything
    if (isActive && currentUser && currentUser.role === 'courier') {
        var batchRefs = [];
        orders.forEach(function(o) { if (o.ref) batchRefs.push(o.ref); });
        var alreadyScanned = orders.filter(function(o) { return o.status === 'shipped' || o.status === 'delivered'; }).length;
        // Store refs in global to avoid JSON escaping issues in onclick
        window._batchScanRefs = batchRefs;
        window._batchScanId = batch.batch_id;
        html += '<div class="courier-sticky-action">';
        html += '<button class="status-action-btn btn-scan-goto" onclick="startBatchScan(window._batchScanId, window._batchScanRefs)">';
        html += '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/></svg>';
        html += ' Scan Batch Items (' + alreadyScanned + '/' + batchRefs.length + ' picked up)</button>';
        html += '</div>';
    }

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
    haptic.warning();
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
    haptic.warning();
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
        scan: '<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 7v10"/><path d="M12 7v10"/><path d="M17 7v10"/></svg>',
        nearby: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        earnings: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        pickup: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
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
    // Block pull if any overlay/panel is open
    if (isPanelOpen()) return;
    // Block pull on login screen
    var loginScreen = document.getElementById('loginScreen');
    if (loginScreen && loginScreen.classList.contains('active')) return;
    // Block pull when map view is active (conflicts with map drag)
    if (currentTab === 'available' && availableMode === 'map') return;
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
// Industry-standard sheet physics:
//   - Velocity-based dismiss (fast flick = close even if short distance)
//   - Progressive resistance (harder to pull the further you go)
//   - Proportional overlay fade during drag
//   - Rubber-band upward drag
//   - Spring overshoot on snap-back
var panelSwipe = {
    startY: 0,
    startTime: 0,
    dist: 0,
    lastY: 0,
    lastTime: 0,
    velocity: 0,
    swiping: false
};

function initPanelSwipe() {
    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    var contentEl = panel ? panel.querySelector('.detail-content') : null;
    if (!panel) return;

    panel.addEventListener('touchstart', function(e) {
        if (!panel.classList.contains('active')) return;
        var headerEl = document.getElementById('detailPanelHeader');
        var isHandle = headerEl && headerEl.contains(e.target);
        var contentScrolled = contentEl && contentEl.scrollTop > 5;
        if (isHandle || !contentScrolled) {
            panelSwipe.startY = e.touches[0].clientY;
            panelSwipe.startTime = Date.now();
            panelSwipe.lastY = panelSwipe.startY;
            panelSwipe.lastTime = panelSwipe.startTime;
            panelSwipe.velocity = 0;
            panelSwipe.swiping = true;
            panelSwipe.dist = 0;
            panel.style.transition = 'none';
            if (overlay) overlay.style.transition = 'none';
        }
    }, { passive: true });

    panel.addEventListener('touchmove', function(e) {
        if (!panelSwipe.swiping) return;
        var currentY = e.touches[0].clientY;
        var now = Date.now();
        panelSwipe.dist = currentY - panelSwipe.startY;

        // Track velocity (px/ms) using last 2 points
        var dt = now - panelSwipe.lastTime;
        if (dt > 0) panelSwipe.velocity = (currentY - panelSwipe.lastY) / dt;
        panelSwipe.lastY = currentY;
        panelSwipe.lastTime = now;

        // Progressive resistance — logarithmic curve feels physical
        var maxDrag = 400;
        var elastic;
        if (panelSwipe.dist > 0) {
            // Downward: progressive resistance (starts easy, gets harder)
            elastic = maxDrag * Math.log(1 + panelSwipe.dist / maxDrag) * 1.2;
        } else {
            // Upward: strong rubber-band (only allows ~20px overshoot)
            elastic = 20 * Math.log(1 + Math.abs(panelSwipe.dist) / 80) * -1;
        }
        panel.style.transform = 'translateY(' + elastic + 'px)';

        // Proportional overlay fade (1.0 at top → 0 when near dismiss)
        if (overlay && panelSwipe.dist > 0) {
            var progress = Math.min(elastic / 200, 1);
            overlay.style.opacity = 1 - progress * 0.7;
        }
    }, { passive: true });

    panel.addEventListener('touchend', function() {
        if (!panelSwipe.swiping) return;
        panelSwipe.swiping = false;

        // Velocity-based dismiss: fast flick (>0.5px/ms) OR distance > 100px
        var shouldClose = (panelSwipe.velocity > 0.5 && panelSwipe.dist > 20) ||
                          (panelSwipe.dist > 100);

        if (shouldClose) {
            // Close: fast exit
            panel.style.transition = 'transform 0.22s cubic-bezier(0.4, 0, 1, 1)';
            if (overlay) overlay.style.transition = 'opacity 0.22s ease';
            haptic.tap();
            closeDetailPanel();
        } else {
            // Snap back: spring with slight overshoot
            panel.style.transition = 'transform 0.35s cubic-bezier(0.32, 0.72, 0, 1)';
            panel.style.transform = 'translateY(0)';
            if (overlay) {
                overlay.style.transition = 'opacity 0.3s ease';
                overlay.style.opacity = '';
            }
        }
        panelSwipe.dist = 0;
        panelSwipe.velocity = 0;
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
    // iOS audio unlock — must happen during direct user gesture
    // AudioContext for beep tones (success/error/warning/confirm)
    var audioUnlocked = false;
    var unlockAudio = function() {
        if (audioUnlocked) return;
        haptic.initAudio();
        // Create the hidden switch + label for iOS haptic
        haptic._initHaptic();
        if (haptic.audioCtx) {
            haptic.audioCtx.resume().then(function() {
                if (haptic.audioCtx.state === 'running') {
                    audioUnlocked = true;
                    document.removeEventListener('touchstart', unlockAudio);
                    document.removeEventListener('touchend', unlockAudio);
                    document.removeEventListener('click', unlockAudio);
                }
            }).catch(function(){});
        }
    };
    document.addEventListener('touchstart', unlockAudio, { passive: true });
    document.addEventListener('touchend', unlockAudio, { passive: true });
    document.addEventListener('click', unlockAudio);

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

// Initialize offline queue
loadOfflineQueue();

// ============================================
// Courier Location Reporting (for live tracking)
// ============================================
var locationReportInterval = null;

function startLocationReporting() {
    if (locationReportInterval || !navigator.geolocation) return;
    if (!currentUser || currentUser.role !== 'courier') return;

    locationReportInterval = setInterval(function() {
        // Only report if courier has active deliveries
        var hasActive = cachedActive && cachedActive.some(function(o) {
            return o.status === 'shipped' || o.status === 'dispatched';
        });
        if (!hasActive) return;

        navigator.geolocation.getCurrentPosition(function(pos) {
            apiCall('update_location', {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude
            }, function() {}); // fire and forget
        }, null, { enableHighAccuracy: true, timeout: 5000 });
    }, 30000); // every 30 seconds
}

function stopLocationReporting() {
    if (locationReportInterval) {
        clearInterval(locationReportInterval);
        locationReportInterval = null;
    }
}

// Offline/online indicator
window.addEventListener('online', function() {
    var bar = document.getElementById('offlineBar');
    if (bar) bar.style.display = 'none';
    // Process any queued offline actions
    if (offlineQueue.length > 0) {
        showToast('Back online — syncing ' + offlineQueue.length + ' pending action' + (offlineQueue.length !== 1 ? 's' : ''), 'info');
        setTimeout(processOfflineQueue, 1000);
    }
    // Refresh data when back online
    if (currentUser) refreshTab(currentTab);
});
window.addEventListener('offline', function() {
    var bar = document.getElementById('offlineBar');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'offlineBar';
        bar.className = 'offline-bar';
        bar.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/><line x1="1" y1="1" x2="23" y2="23"/></svg> You are offline — showing cached data';
        document.body.insertBefore(bar, document.body.firstChild);
    }
    bar.style.display = 'flex';
});

// ============================================
// Delivery Confirmation Animation
// ============================================

function showDeliverySuccess(ref, customerName) {
    haptic.confirm();
    var overlay = document.createElement('div');
    overlay.className = 'delivery-success-overlay';
    overlay.innerHTML = '<div class="delivery-success-icon"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>' +
        '<div class="delivery-success-text">Delivered!</div>' +
        '<div class="delivery-success-sub">' + escapeHtml(ref) + (customerName ? ' \u2022 ' + escapeHtml(customerName) : '') + '</div>';
    document.body.appendChild(overlay);
    setTimeout(function() {
        if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
    }, 2600);
}

// ============================================
// Skeleton Loading Screens
// ============================================

function renderSkeletonCards(count) {
    var html = '';
    for (var i = 0; i < (count || 3); i++) {
        html += '<div class="skeleton-card">';
        html += '<div class="skeleton skeleton-text" style="width:70%;"></div>';
        html += '<div class="skeleton skeleton-title"></div>';
        html += '<div class="skeleton skeleton-text-sm"></div>';
        html += '<div class="skeleton skeleton-block"></div>';
        html += '<div class="skeleton skeleton-text" style="width:50%;"></div>';
        html += '</div>';
    }
    return html;
}

// ============================================
// Order Count Badges on Nav Tabs
// ============================================

function updateNavBadges() {
    // Count active deliveries
    var deliveryCount = cachedActive ? cachedActive.length : 0;
    var availableCount = (cachedAvailActive ? cachedAvailActive.length : 0) + (cachedAvailUpcoming ? cachedAvailUpcoming.length : 0);

    // Update badge on deliveries tab
    var navItems = document.querySelectorAll('.nav-item');
    navItems.forEach(function(item) {
        var tab = item.getAttribute('data-tab');
        var existing = item.querySelector('.nav-badge');
        if (existing) existing.remove();

        var count = 0;
        if (tab === 'deliveries' && deliveryCount > 0) count = deliveryCount;
        else if (tab === 'available' && availableCount > 0) count = availableCount;

        if (count > 0) {
            var badge = document.createElement('span');
            badge.className = 'nav-badge';
            badge.textContent = count > 99 ? '99+' : count;
            item.appendChild(badge);
        }
    });
}

// ============================================
// Running Earnings Ticker
// ============================================

var earningsTickerEl = null;

function initEarningsTicker() {
    if (currentUser && currentUser.role !== 'courier') return;
    if (earningsTickerEl) return;
    earningsTickerEl = document.createElement('div');
    earningsTickerEl.className = 'earnings-ticker';
    earningsTickerEl.id = 'earningsTicker';
    document.body.appendChild(earningsTickerEl);
}

function updateEarningsTicker(amount) {
    if (!earningsTickerEl) initEarningsTicker();
    if (!earningsTickerEl) return;
    if (amount > 0) {
        earningsTickerEl.textContent = 'Today: $' + parseFloat(amount).toFixed(2);
        earningsTickerEl.classList.add('visible');
    } else {
        earningsTickerEl.classList.remove('visible');
    }
}

// Poll earnings ticker every 60 seconds (lightweight — only updates the ticker, not the full earnings tab)
var earningsTickerInterval = null;

function startEarningsTickerPolling() {
    if (earningsTickerInterval) return;
    if (!currentUser || currentUser.role !== 'courier') return;
    // Initial fetch
    pollEarningsTicker();
    // Poll every 60 seconds
    earningsTickerInterval = setInterval(pollEarningsTicker, 60000);
}

function pollEarningsTicker() {
    apiCall('get_earnings', null, function(result) {
        if (result.success && result.summary) {
            updateEarningsTicker(result.summary.today);
        }
    });
}

function stopEarningsTickerPolling() {
    if (earningsTickerInterval) {
        clearInterval(earningsTickerInterval);
        earningsTickerInterval = null;
    }
}

// ============================================
// Notification Polling (Push-like alerts)
// ============================================
var notifPollInterval = null;
var lastNotifTimestamp = null;
var seenNotifIds = {};

function startNotificationPolling() {
    if (notifPollInterval) return;
    if (!currentUser || currentUser.role === 'mtcc_staff') return;
    // Initial timestamp = now (don't show old notifications)
    lastNotifTimestamp = new Date().toISOString();
    // Poll every 30 seconds
    notifPollInterval = setInterval(pollNotifications, 30000);
}

function pollNotifications() {
    if (!navigator.onLine) return;
    var params = {};
    if (lastNotifTimestamp) params.since = lastNotifTimestamp;

    apiCall('get_notifications', params, function(result) {
        if (!result.success || !result.notifications) return;
        var notifs = result.notifications;
        if (notifs.length === 0) return;

        notifs.forEach(function(n) {
            if (seenNotifIds[n.id]) return;
            seenNotifIds[n.id] = true;

            // In-app toast
            showToast(n.title + (n.message ? ': ' + n.message : ''), 'info');
            haptic.tap();

            // Browser notification (if permitted)
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(n.title, {
                    body: n.message || '',
                    icon: '../assets/logo.png',
                    tag: 'notif_' + n.id
                });
            }
        });

        // Update timestamp to latest notification
        var latest = notifs[0];
        if (latest && latest.created_at) {
            lastNotifTimestamp = latest.created_at;
        }
    }, true); // skipQueue — don't queue notification polls
}

function stopNotificationPolling() {
    if (notifPollInterval) {
        clearInterval(notifPollInterval);
        notifPollInterval = null;
    }
}

// ============================================
// Live ETA Countdown
// ============================================

var etaIntervals = {};

function startETACountdown(ref, durationMin) {
    if (!durationMin || etaIntervals[ref]) return;
    var endTime = Date.now() + (durationMin * 60 * 1000);
    // Try the dedicated ETA element, or fall back to the route stat element
    var el = document.getElementById('eta_' + ref) || document.getElementById('orderRouteStat_' + ref);
    if (!el) return;

    etaIntervals[ref] = setInterval(function() {
        var remaining = endTime - Date.now();
        if (remaining <= 0) {
            el.textContent = 'Arriving';
            el.style.color = '#16a34a';
            clearInterval(etaIntervals[ref]);
            delete etaIntervals[ref];
            return;
        }
        var mins = Math.ceil(remaining / 60000);
        el.textContent = '~' + mins + ' min';
        if (mins <= 5) el.style.color = '#d97706';
        if (mins <= 2) el.style.color = '#dc2626';
    }, 15000); // update every 15s
}

function clearAllETAs() {
    Object.keys(etaIntervals).forEach(function(ref) {
        clearInterval(etaIntervals[ref]);
    });
    etaIntervals = {};
}

// ============================================
// Route Optimization (GPS-based)
// ============================================

function optimizeRoute(stops, btnEl) {
    if (!stops || stops.length < 2) {
        showToast('Need at least 2 stops to optimize', 'error');
        return;
    }
    haptic.tap();

    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg> Optimizing...';
    }

    // Get courier's current GPS position
    if (!navigator.geolocation) {
        showToast('GPS not available', 'error');
        if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = 'Optimize Route'; }
        return;
    }

    navigator.geolocation.getCurrentPosition(function(pos) {
        var stopCoords = stops.map(function(s) {
            return { lat: s.lat, lng: s.lng };
        });

        apiCall('optimize_route', {
            lat: pos.coords.latitude,
            lng: pos.coords.longitude,
            stops: JSON.stringify(stopCoords)
        }, function(result) {
            if (result.success) {
                haptic.confirm();
                showToast('Route optimized! ' + result.distance_km + ' km, ~' + result.duration_min + ' min', 'success');

                // Reorder the stops based on optimized_order
                if (result.optimized_order && result.optimized_order.length > 0) {
                    var reordered = result.optimized_order.map(function(idx) { return stops[idx]; });
                    // Update the map if visible
                    var mapImg = document.querySelector('.bv7-map-img');
                    if (mapImg && result.polyline) {
                        var addrs = reordered.map(function(s) { return s.address; }).filter(Boolean);
                        if (addrs.length > 0) mapImg.src = buildStaticMapUrl(addrs, 600, 250, result.polyline);
                    }
                    // Update stats
                    var statEl = document.querySelector('.bv7-stats-bar .bv7-stat');
                    if (statEl) {
                        statEl.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><circle cx="6" cy="19" r="3"/><path d="M9 19h8.5a3.5 3.5 0 0 0 0-7h-11a3.5 3.5 0 0 1 0-7H15"/><circle cx="18" cy="5" r="3"/></svg> <strong>' + result.distance_km + ' km</strong>' +
                            '<span class="bv7-stat-dot">\u2022</span>' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2"><line x1="10" x2="14" y1="2" y2="2"/><line x1="12" x2="15" y1="14" y2="11"/><circle cx="12" cy="14" r="8"/></svg> <strong>~' + result.duration_min + ' min</strong>';
                    }
                }

                if (btnEl) {
                    btnEl.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg> Optimized!';
                    btnEl.style.borderColor = '#059669';
                    btnEl.style.color = '#059669';
                }
            } else {
                haptic.error();
                showToast(result.error || 'Optimization failed', 'error');
                if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = 'Optimize Route'; }
            }
        });
    }, function(err) {
        haptic.error();
        showToast('Could not get your location', 'error');
        if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = 'Optimize Route'; }
    }, { enableHighAccuracy: true, timeout: 10000 });
}

// ============================================
// Smart Proximity Notifications
// ============================================

var proximityWatchId = null;
// ============================================
// In-App Courier Tracking (MTCC Staff)
// ============================================

var _trackingInterval = null;

function showCourierTracking(ref) {
    var panel = document.getElementById('detailPanel');
    var overlay = document.getElementById('detailOverlay');
    var content = document.getElementById('detailContent');
    var panelHeader = document.getElementById('detailPanelHeader');
    panel.style.transition = '';
    panel.style.transform = '';
    overlay.style.transition = '';
    overlay.style.opacity = '';

    setPanelGradient(panel, '#14b8a6');
    panel.classList.add('mtcc-panel');

    // Header
    var headerHtml = '<div class="detail-due-bar" onclick="closeDetailPanel()">';
    headerHtml += '<div class="detail-due-left">';
    headerHtml += '<span class="detail-due-heading">LIVE TRACKING</span>';
    headerHtml += '<span class="detail-due-date">' + escapeHtml(ref) + '</span>';
    headerHtml += '</div>';
    headerHtml += '<span class="order-status-badge badge-shipped mtcc-header-badge">Live</span>';
    headerHtml += '</div>';
    if (panelHeader) panelHeader.innerHTML = headerHtml;

    // Content — map + status
    var html = '<div style="padding-top:14px;">';
    html += '<div id="trackingMapContainer" style="width:100%;height:300px;border-radius:var(--radius);overflow:hidden;border:1px solid #e5e7eb;"></div>';
    html += '<div id="trackingEta" style="text-align:center;padding:16px;font-size:1rem;font-weight:700;color:#7c3aed;">Loading...</div>';
    html += '<div id="trackingStatus" style="text-align:center;padding:0 16px 8px;font-size:0.78rem;color:#6b7280;"></div>';

    // Back to order button
    html += '<button class="route-optimize-btn" style="margin-top:8px;" onclick="haptic.tap(); closeDetailPanel(); setTimeout(function(){ showOrderDetail(\'' + escapeAttr(ref) + '\', \'pickup\'); }, 300);">';
    html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Back to Order Details</button>';
    html += '</div>';

    content.innerHTML = html;
    panel.classList.add('active');
    overlay.classList.add('active');

    // Initialize map
    _initTrackingMap(ref);
}

function _initTrackingMap(ref) {
    // Stop any existing tracking
    if (_trackingInterval) { clearInterval(_trackingInterval); _trackingInterval = null; }

    var mapEl = document.getElementById('trackingMapContainer');
    if (!mapEl || typeof google === 'undefined' || !google.maps) {
        // Google Maps not loaded — load it dynamically
        var etaEl = document.getElementById('trackingEta');
        if (etaEl) etaEl.textContent = 'Map loading...';

        // Use static map as fallback
        _pollTrackingData(ref, null, null);
        _trackingInterval = setInterval(function() { _pollTrackingData(ref, null, null); }, 15000);
        return;
    }

    var map = new google.maps.Map(mapEl, {
        zoom: 13,
        center: { lat: 43.6445, lng: -79.3871 },
        disableDefaultUI: true,
        zoomControl: true,
        styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }]
    });

    var courierMarker = null;
    var destMarker = null;

    // Poll immediately then every 15s
    _pollTrackingData(ref, map, { courier: courierMarker, dest: destMarker });
    _trackingInterval = setInterval(function() {
        _pollTrackingData(ref, map, { courier: courierMarker, dest: destMarker });
    }, 15000);
}

function _pollTrackingData(ref, map, markers) {
    apiCall('get_tracking', { ref: ref }, function(data) {
        var etaEl = document.getElementById('trackingEta');
        var statusEl = document.getElementById('trackingStatus');
        if (!etaEl) { if (_trackingInterval) clearInterval(_trackingInterval); return; }

        if (!data.success) {
            etaEl.textContent = 'Unable to load tracking';
            return;
        }

        // Status display
        if (data.status === 'delivered' || data.status === 'pickedup') {
            etaEl.innerHTML = '<span style="color:#16a34a;">&#10003; Delivered</span>';
            statusEl.textContent = '';
            if (_trackingInterval) clearInterval(_trackingInterval);
            return;
        }
        if (data.status !== 'shipped') {
            etaEl.textContent = 'Courier has not picked up yet';
            statusEl.textContent = 'Status: ' + (data.status || 'unknown');
            return;
        }

        if (!data.lat || !data.lng) {
            etaEl.textContent = 'Courier is en route';
            statusEl.textContent = 'Waiting for location update...';
            return;
        }

        // Update map markers
        if (map && typeof google !== 'undefined') {
            var pos = { lat: data.lat, lng: data.lng };
            if (!markers.courier) {
                markers.courier = new google.maps.Marker({
                    position: pos, map: map,
                    icon: { url: 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="%237c3aed" stroke="white" stroke-width="1.5"><circle cx="12" cy="12" r="10"/></svg>'), scaledSize: new google.maps.Size(36, 36), anchor: new google.maps.Point(18, 18) }
                });
                map.setCenter(pos);
                map.setZoom(14);
            } else {
                markers.courier.setPosition(pos);
            }
        }

        // ETA
        if (data.eta_min !== null && data.eta_min !== undefined) {
            var text = '';
            if (data.eta_min <= 1) { text = 'Arriving now!'; etaEl.style.color = '#16a34a'; }
            else if (data.eta_min <= 5) { text = 'Almost there — ' + data.eta_min + ' min'; etaEl.style.color = '#d97706'; }
            else { text = '~' + data.eta_min + ' min away'; etaEl.style.color = '#7c3aed'; }
            if (data.distance_km) text += ' (' + data.distance_km + ' km)';
            etaEl.textContent = text;
        } else {
            etaEl.textContent = 'Courier is en route';
        }

        if (data.stale) statusEl.textContent = 'Last update: ' + new Date(data.updated_at).toLocaleTimeString();
        else if (data.courier_name) statusEl.textContent = 'Courier: ' + data.courier_name;
        else statusEl.textContent = '';
    });
}

// Clean up tracking on panel close
var _origCloseDetail = closeDetailPanel;
closeDetailPanel = function() {
    if (_trackingInterval) { clearInterval(_trackingInterval); _trackingInterval = null; }
    _origCloseDetail();
};

var notifiedProximity = {};

function startProximityWatch() {
    if (proximityWatchId || !navigator.geolocation) return;
    proximityWatchId = navigator.geolocation.watchPosition(function(pos) {
        var lat = pos.coords.latitude;
        var lng = pos.coords.longitude;

        // Check distance to each active delivery destination
        var orders = cachedActive || [];
        orders.forEach(function(o) {
            if (o.status !== 'shipped' || notifiedProximity[o.ref]) return;
            // Simple distance check (Haversine approximation)
            var destLat = o.destination_lat || 0;
            var destLng = o.destination_lng || 0;
            if (!destLat || !destLng) return;

            var dLat = (destLat - lat) * Math.PI / 180;
            var dLng = (destLng - lng) * Math.PI / 180;
            var a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(lat * Math.PI/180) * Math.cos(destLat * Math.PI/180) * Math.sin(dLng/2) * Math.sin(dLng/2);
            var distKm = 6371 * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));

            // Within 150m — show auto-arrive prompt
            if (distKm < 0.15 && !notifiedProximity[o.ref + '_arrived']) {
                notifiedProximity[o.ref + '_arrived'] = true;
                haptic.confirm();
                showArrivalPrompt(o);
            }
            // Within 500m — heads-up notification
            else if (distKm < 0.5 && !notifiedProximity[o.ref]) {
                notifiedProximity[o.ref] = true;
                haptic.tap();
                showToast('Approaching ' + (o.destination || 'destination') + ' — ' + o.ref, 'info');
                if ('Notification' in window && Notification.permission === 'granted') {
                    new Notification('Arriving Soon', { body: 'You\'re near ' + (o.destination || 'destination') + ' for ' + o.ref, icon: '../assets/logo.png' });
                }
            }
        });
    }, null, { enableHighAccuracy: true, maximumAge: 30000 });
}

function showArrivalPrompt(order) {
    // Remove any existing prompt
    var existing = document.getElementById('arrivalPrompt');
    if (existing) existing.remove();

    var prompt = document.createElement('div');
    prompt.id = 'arrivalPrompt';
    prompt.className = 'arrival-prompt';
    prompt.innerHTML = '<div class="arrival-prompt-content">' +
        '<div class="arrival-prompt-text">' +
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>' +
        '<div><strong>Arrived at ' + escapeHtml(order.destination || 'destination') + '</strong>' +
        '<span>' + escapeHtml(order.ref) + '</span></div>' +
        '</div>' +
        '<div class="arrival-prompt-actions">' +
        '<button class="arrival-btn-confirm" onclick="arrivalConfirmDelivery(\'' + escapeAttr(order.ref) + '\')">Confirm Delivery</button>' +
        '<button class="arrival-btn-dismiss" onclick="dismissArrivalPrompt()">Dismiss</button>' +
        '</div></div>';
    document.body.appendChild(prompt);

    // Auto-dismiss after 30 seconds
    setTimeout(function() {
        var el = document.getElementById('arrivalPrompt');
        if (el) el.remove();
    }, 30000);
}

function arrivalConfirmDelivery(ref) {
    dismissArrivalPrompt();
    // Open the order detail for status update
    if (orderCache[ref]) {
        showOrderDetail(ref, 'delivery');
    }
}

function dismissArrivalPrompt() {
    var el = document.getElementById('arrivalPrompt');
    if (el) el.remove();
}

function stopProximityWatch() {
    if (proximityWatchId) {
        navigator.geolocation.clearWatch(proximityWatchId);
        proximityWatchId = null;
    }
    notifiedProximity = {};
}

console.log('MTCC Courier App loaded');
