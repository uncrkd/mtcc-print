<?php
/**
 * Dispatch Scanner
 * Mobile-friendly barcode scanning for couriers and MTCC staff
 * Location: /dispatch/scanner.php
 * Access: mtcc.print-stuff.ca/dispatch/scanner.php
 * 
 * Features:
 * - PIN login with keypad
 * - Camera barcode scanning (QuaggaJS)
 * - Manual tracking number entry
 * - Haptic feedback (Android) + Audio beeps (iOS/all devices)
 * - Role-based status permissions
 * - Full-screen delivery photo capture
 */
session_start();

// Include icon library
require_once '../includes/icons.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <title>Dispatch Scanner - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dispatch-styles.css?v=3">
    <!-- QuaggaJS for barcode scanning -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
    <style>
        /* Override scanner frame to be wider and thinner for barcodes */
        .scanner-frame {
            width: 90% !important;
            height: 25% !important;
        }
        
        /* Full-screen photo capture overlay */
        .photo-fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: #000;
            z-index: 3000;
            display: none;
            flex-direction: column;
        }
        
        .photo-fullscreen.active {
            display: flex;
        }
        
        .photo-fullscreen-header {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            padding: 16px;
            background: linear-gradient(to bottom, rgba(0,0,0,0.7) 0%, transparent 100%);
            z-index: 10;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .photo-fullscreen-header h3 {
            color: white;
            font-size: 1rem;
            margin: 0;
        }
        
        .btn-close-photo {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            font-size: 1.5rem;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .photo-fullscreen-preview {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .photo-fullscreen-preview video,
        .photo-fullscreen-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-fullscreen-controls {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            padding: 20px;
            padding-bottom: max(20px, env(safe-area-inset-bottom));
            background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, transparent 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
        }
        
        .btn-shutter {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: white;
            border: 4px solid rgba(255,255,255,0.5);
            cursor: pointer;
            transition: transform 0.1s;
        }
        
        .btn-shutter:active {
            transform: scale(0.95);
        }
        
        .btn-shutter-inner {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: white;
        }
        
        .btn-photo-action {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
        }
        
        .btn-photo-action.primary {
            background: #10b981;
        }
        
        .photo-controls-capture,
        .photo-controls-review {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            width: 100%;
        }
        
        .photo-controls-review {
            display: none;
        }
        
        .photo-fullscreen.captured .photo-controls-capture {
            display: none;
        }
        
        .photo-fullscreen.captured .photo-controls-review {
            display: flex;
        }
    </style>
    <!-- Icon Library for JavaScript -->
    <?php outputIconsScript(); ?>
</head>
<body>
    <!-- Safari Recommendation Banner for iPhone Chrome Users -->
    <script>
    (function() {
        var isIPhone = /iPhone|iPod/.test(navigator.userAgent);
        var isChrome = /CriOS/.test(navigator.userAgent);
        
        if (isIPhone && isChrome) {
            document.addEventListener('DOMContentLoaded', function() {
                var banner = document.createElement('div');
                banner.id = 'safariBanner';
                banner.innerHTML = '<div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 12px 16px; text-align: center; font-family: -apple-system, sans-serif; font-size: 14px; position: fixed; top: 0; left: 0; right: 0; z-index: 9999; box-shadow: 0 2px 10px rgba(0,0,0,0.2);"><strong>Ã°Å¸â€œÂ± For best camera experience on iPhone, please use Safari</strong><br><a href="javascript:void(0)" onclick="document.getElementById(\'safariBanner\').remove(); document.body.style.paddingTop=\'0\';" style="color: white; text-decoration: underline; font-size: 12px;">Dismiss</a></div>';
                document.body.insertBefore(banner, document.body.firstChild);
                document.body.style.paddingTop = '70px';
            });
        }
    })();
    </script>

    <div class="app-container">
        
        <!-- Header -->
        <header class="app-header">
            <div class="header-logo">
                <img src="../mtccpslogo.png" alt="MTCC Print Services" onerror="this.style.display='none'">
            </div>
            <div class="header-title">Dispatch Scanner</div>
            <div class="header-user" id="headerUser" style="display: none;">
                <span id="userName"></span>
                <button id="logoutBtn" class="btn-logout" onclick="logout()">Logout</button>
            </div>
        </header>

        <!-- Login Screen -->
        <div id="loginScreen" class="screen active">
            <div class="login-container">
                <div class="login-icon">Ã°Å¸â€Â</div>
                <h1>Enter Your PIN</h1>
                <p class="login-subtitle">Authorized personnel only</p>
                
                <div class="pin-display">
                    <span class="pin-dot" id="dot1"></span>
                    <span class="pin-dot" id="dot2"></span>
                    <span class="pin-dot" id="dot3"></span>
                    <span class="pin-dot" id="dot4"></span>
                </div>
                
                <div id="loginError" class="error-message" style="display: none;"></div>
                
                <div class="pin-keypad">
                    <button class="key-btn" onclick="enterPin('1')">1</button>
                    <button class="key-btn" onclick="enterPin('2')">2</button>
                    <button class="key-btn" onclick="enterPin('3')">3</button>
                    <button class="key-btn" onclick="enterPin('4')">4</button>
                    <button class="key-btn" onclick="enterPin('5')">5</button>
                    <button class="key-btn" onclick="enterPin('6')">6</button>
                    <button class="key-btn" onclick="enterPin('7')">7</button>
                    <button class="key-btn" onclick="enterPin('8')">8</button>
                    <button class="key-btn" onclick="enterPin('9')">9</button>
                    <button class="key-btn key-clear" onclick="clearPin()">Clear</button>
                    <button class="key-btn" onclick="enterPin('0')">0</button>
                    <button class="key-btn key-back" onclick="backspacePin()">Ã¢Å’Â«</button>
                </div>
            </div>
        </div>

        <!-- Scanner Screen -->
        <div id="scannerScreen" class="screen">
            <div class="scanner-container">
                <div class="role-badge" id="roleBadge"></div>
                
                <div class="scan-area">
                    <div id="scanner" class="scanner-viewport">
                        <div class="scanner-overlay">
                            <div class="scanner-frame"></div>
                        </div>
                    </div>
                    <p class="scan-instruction">Position barcode within the frame</p>
                </div>
                
                <div class="manual-entry">
                    <p>Or enter tracking number manually:</p>
                    <div class="manual-input-group">
                        <input type="text" id="manualTracking" placeholder="e.g., MTCCNFS005260117" autocomplete="off">
                        <button onclick="lookupManual()" class="btn btn-primary">Look Up</button>
                    </div>
                </div>
                
                <div id="scannerStatus" class="scanner-status">
                    <span class="status-indicator"></span>
                    <span class="status-text">Ready to scan</span>
                </div>
            </div>
        </div>

        <!-- Order Details Screen -->
        <div id="orderScreen" class="screen">
            <div class="order-container">
                <button class="btn-back" onclick="backToScanner()">Ã¢â€ Â Back to Scanner</button>
                
                <div class="order-header">
                    <div class="order-ref" id="orderRef"></div>
                    <div class="order-status" id="orderStatus"></div>
                </div>
                
                <div class="order-details">
                    <div class="detail-section">
                        <h3>Ã°Å¸â€œÂ¦ Order Info</h3>
                        <div class="detail-row">
                            <span class="detail-label">Customer</span>
                            <span class="detail-value" id="customerName"></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Phone</span>
                            <span class="detail-value" id="customerPhone"></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Size</span>
                            <span class="detail-value" id="posterSize"></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Material</span>
                            <span class="detail-value" id="posterMaterial"></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Event</span>
                            <span class="detail-value" id="eventName"></span>
                        </div>
                    </div>
                    
                    <div class="detail-section">
                        <h3>Ã°Å¸â€œÂ Delivery Location</h3>
                        <div class="delivery-location" id="deliveryLocation"></div>
                    </div>
                    
                    <div class="detail-section notes-section" id="notesSection" style="display: none;">
                        <h3>Ã°Å¸â€œÂ Special Notes</h3>
                        <div class="notes-content" id="orderNotes"></div>
                    </div>
                </div>
                
                <!-- Status Update Section -->
                <div class="status-update-section" id="statusUpdateSection">
                    <h3>Update Status</h3>
                    
                    <div class="suggested-status" id="suggestedStatusBox" style="display: none;">
                        <p>Suggested next status:</p>
                        <button id="suggestedStatusBtn" class="btn btn-suggested" onclick="confirmStatus()"></button>
                    </div>
                    
                    <div class="other-statuses" id="otherStatusesBox">
                        <p>Or select a different status:</p>
                        <div class="status-buttons" id="statusButtons"></div>
                    </div>
                    
                    <div class="photo-capture" id="photoCaptureBox">
                        <label class="photo-toggle">
                            <input type="checkbox" id="includePhoto" onchange="togglePhotoCapture()">
                            <span>Include delivery photo</span>
                        </label>
                        <!-- Thumbnail preview when photo is captured -->
                        <div id="photoThumbnail" class="photo-thumbnail" style="display: none;">
                            <img id="thumbnailImg" style="width: 100%; max-height: 150px; object-fit: cover; border-radius: 8px; margin-top: 10px;">
                            <button class="btn btn-retake" onclick="openPhotoCapture()" style="margin-top: 10px;">Ã°Å¸â€œÂ· Retake Photo</button>
                        </div>
                    </div>
                </div>
                
                <!-- Final Status Message -->
                <div class="final-status-message" id="finalStatusMessage" style="display: none;">
                    <div class="success-icon">Ã¢Å“â€¦</div>
                    <p>This order is complete. No further action needed.</p>
                </div>
                
                <!-- No Permission Message -->
                <div class="no-permission-message" id="noPermissionMessage" style="display: none;">
                    <div class="warning-icon">Ã¢Å¡Â Ã¯Â¸Â</div>
                    <p id="noPermissionText"></p>
                </div>
            </div>
        </div>

        <!-- Confirmation Modal -->
        <div id="confirmModal" class="modal">
            <div class="modal-content">
                <h2>Confirm Status Update</h2>
                <p id="confirmMessage"></p>
                <div class="modal-buttons">
                    <button class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="executeStatusUpdate()">Confirm</button>
                </div>
            </div>
        </div>

        <!-- Success Modal -->
        <div id="successModal" class="modal">
            <div class="modal-content success">
                <div class="success-animation">Ã¢Å“â€œ</div>
                <h2>Status Updated!</h2>
                <p id="successMessage"></p>
                <button class="btn btn-primary" onclick="closeSuccessAndScan()">Scan Next Order</button>
            </div>
        </div>

        <!-- Loading Overlay -->
        <div id="loadingOverlay" class="loading-overlay">
            <div class="spinner"></div>
            <p>Processing...</p>
        </div>

    </div>
    
    <!-- Full-Screen Photo Capture Overlay -->
    <div id="photoFullscreen" class="photo-fullscreen">
        <div class="photo-fullscreen-header">
            <h3>Ã°Å¸â€œÂ· Delivery Photo</h3>
            <button class="btn-close-photo" onclick="closePhotoCapture()">Ã¢Å“â€¢</button>
        </div>
        
        <div class="photo-fullscreen-preview">
            <video id="photoVideo" autoplay playsinline></video>
            <canvas id="photoCanvas" style="display: none;"></canvas>
            <img id="capturedPhoto" style="display: none;">
        </div>
        
        <div class="photo-fullscreen-controls">
            <div class="photo-controls-capture">
                <button class="btn-shutter" onclick="capturePhoto()">
                    <div class="btn-shutter-inner"></div>
                </button>
            </div>
            <div class="photo-controls-review">
                <button class="btn-photo-action" onclick="retakePhoto()">Retake</button>
                <button class="btn-photo-action primary" onclick="usePhoto()">Use Photo</button>
            </div>
        </div>
    </div>

    <script>
        // ==========================================================================
        // HAPTIC & AUDIO FEEDBACK SYSTEM
        // Works on all devices - vibration on Android, audio beeps on iOS/desktop
        // ==========================================================================
        const haptic = {
            vibrateSupported: 'vibrate' in navigator,
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
            
            // Play a beep tone
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
            
            // Confirm - status change confirmed (most satisfying)
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

        // ==========================================================================
        // STATE
        // ==========================================================================
        let currentUser = null;
        let currentOrder = null;
        let enteredPin = '';
        let selectedStatus = null;
        let photoBlob = null;
        let photoStream = null;

        // ==========================================================================
        // INITIALIZATION
        // ==========================================================================
        document.addEventListener('DOMContentLoaded', function() {
            checkSession();
            
            // Initialize audio on first touch/click (required for iOS)
            var initOnInteraction = function() {
                haptic.initAudio();
                document.removeEventListener('touchstart', initOnInteraction);
                document.removeEventListener('click', initOnInteraction);
            };
            
            document.addEventListener('touchstart', initOnInteraction, { once: true });
            document.addEventListener('click', initOnInteraction, { once: true });
            
            // Add tap feedback to PIN keypad buttons
            document.querySelectorAll('.key-btn').forEach(function(btn) {
                btn.addEventListener('touchstart', function() {
                    haptic.initAudio();
                    haptic.tap();
                });
            });
            
            // Add tap feedback to all other buttons
            document.querySelectorAll('.btn, .btn-suggested, .btn-status, .btn-back, .btn-logout').forEach(function(btn) {
                btn.addEventListener('touchstart', function() {
                    haptic.initAudio();
                    haptic.tap();
                });
            });
        });

        async function checkSession() {
            try {
                const response = await fetch('api.php?action=check_session');
                const data = await response.json();
                
                if (data.success && data.loggedIn) {
                    currentUser = data.user;
                    showScanner();
                } else {
                    showLogin();
                }
            } catch (error) {
                console.error('Session check failed:', error);
                showLogin();
            }
        }

        // ==========================================================================
        // SCREEN NAVIGATION
        // ==========================================================================
        function showScreen(screenId) {
            document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
            document.getElementById(screenId).classList.add('active');
        }

        function showLogin() {
            document.getElementById('headerUser').style.display = 'none';
            showScreen('loginScreen');
            clearPin();
        }

        function showScanner() {
            document.getElementById('headerUser').style.display = 'flex';
            document.getElementById('userName').textContent = currentUser.name;
            document.getElementById('roleBadge').textContent = currentUser.role_label;
            document.getElementById('roleBadge').className = 'role-badge role-' + currentUser.role;
            showScreen('scannerScreen');
            startScanner();
        }

        function showOrder(order) {
            currentOrder = order;
            stopScanner();
            
            // Populate order details
            document.getElementById('orderRef').textContent = order.referenceCode;
            document.getElementById('orderStatus').textContent = formatStatus(order.currentStatus);
            document.getElementById('orderStatus').className = 'order-status status-' + order.currentStatus;
            document.getElementById('customerName').textContent = order.customerName;
            document.getElementById('customerPhone').textContent = order.customerPhone || 'N/A';
            document.getElementById('posterSize').textContent = order.posterSize;
            document.getElementById('posterMaterial').textContent = order.material;
            document.getElementById('eventName').textContent = order.eventName || 'N/A';
            document.getElementById('deliveryLocation').textContent = order.deliveryLocation;
            
            // Notes
            if (order.notes) {
                document.getElementById('notesSection').style.display = 'block';
                document.getElementById('orderNotes').textContent = order.notes;
            } else {
                document.getElementById('notesSection').style.display = 'none';
            }
            
            // Status update section
            const updateSection = document.getElementById('statusUpdateSection');
            const finalMessage = document.getElementById('finalStatusMessage');
            const noPermission = document.getElementById('noPermissionMessage');
            
            if (order.isFinalStatus) {
                updateSection.style.display = 'none';
                finalMessage.style.display = 'block';
                noPermission.style.display = 'none';
            } else if (order.allowedStatuses.length === 0) {
                updateSection.style.display = 'none';
                finalMessage.style.display = 'none';
                noPermission.style.display = 'block';
                haptic.warning();
                
                let msg = 'You do not have permission to update this order.';
                if (currentUser.role === 'mtcc_staff' && order.currentStatus !== 'delivered') {
                    msg = 'This order must be marked as "Delivered" before you can mark it as picked up.';
                } else if (currentUser.role === 'courier' && order.currentStatus === 'delivered') {
                    msg = 'This order has been delivered. MTCC staff will handle pickup confirmation.';
                }
                document.getElementById('noPermissionText').textContent = msg;
            } else {
                updateSection.style.display = 'block';
                finalMessage.style.display = 'none';
                noPermission.style.display = 'none';
                
                // Suggested status
                const suggestedBox = document.getElementById('suggestedStatusBox');
                const suggestedBtn = document.getElementById('suggestedStatusBtn');
                
                if (order.suggestedStatus && order.allowedStatuses.includes(order.suggestedStatus)) {
                    suggestedBox.style.display = 'block';
                    suggestedBtn.textContent = 'Mark as ' + formatStatus(order.suggestedStatus);
                    suggestedBtn.dataset.status = order.suggestedStatus;
                    suggestedBtn.className = 'btn btn-suggested btn-status-' + order.suggestedStatus;
                    selectedStatus = order.suggestedStatus;
                } else {
                    suggestedBox.style.display = 'none';
                    selectedStatus = order.allowedStatuses[0];
                }
                
                // Other status buttons
                const otherStatuses = order.allowedStatuses.filter(s => s !== order.suggestedStatus);
                const buttonsContainer = document.getElementById('statusButtons');
                const otherStatusesBox = document.getElementById('otherStatusesBox');
                buttonsContainer.innerHTML = '';
                
                if (otherStatuses.length > 0) {
                    otherStatusesBox.style.display = 'block';
                    
                    // Update label based on whether suggested status is shown
                    const labelEl = otherStatusesBox.querySelector('p');
                    if (suggestedBox.style.display === 'block') {
                        labelEl.textContent = 'Or select:';
                    } else {
                        labelEl.textContent = 'Select status:';
                    }
                    
                    otherStatuses.forEach(status => {
                        const btn = document.createElement('button');
                        btn.className = 'btn btn-status btn-status-' + status;
                        btn.textContent = formatStatus(status);
                        btn.onclick = () => selectStatus(status);
                        btn.addEventListener('touchstart', () => haptic.tap());
                        buttonsContainer.appendChild(btn);
                    });
                } else {
                    otherStatusesBox.style.display = 'none';
                }
            }
            
            // Reset photo capture
            document.getElementById('includePhoto').checked = false;
            document.getElementById('photoThumbnail').style.display = 'none';
            photoBlob = null;
            
            showScreen('orderScreen');
        }

        function backToScanner() {
            currentOrder = null;
            selectedStatus = null;
            stopPhotoStream();
            photoBlob = null;
            showScanner();
        }

        // ==========================================================================
        // PIN ENTRY
        // ==========================================================================
        function enterPin(digit) {
            haptic.initAudio();
            haptic.tap();
            
            if (enteredPin.length < 4) {
                enteredPin += digit;
                updatePinDisplay();
                
                if (enteredPin.length === 4) {
                    setTimeout(submitPin, 200);
                }
            }
        }

        function clearPin() {
            enteredPin = '';
            updatePinDisplay();
            document.getElementById('loginError').style.display = 'none';
        }

        function backspacePin() {
            if (enteredPin.length > 0) {
                enteredPin = enteredPin.slice(0, -1);
                updatePinDisplay();
            }
        }

        function updatePinDisplay() {
            for (let i = 1; i <= 4; i++) {
                const dot = document.getElementById('dot' + i);
                dot.classList.toggle('filled', i <= enteredPin.length);
            }
        }

        async function submitPin() {
            showLoading();
            
            try {
                const formData = new FormData();
                formData.append('pin', enteredPin);
                
                const response = await fetch('api.php?action=login', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    haptic.success();
                    currentUser = data.user;
                    showScanner();
                } else {
                    showLoginError(data.error || 'Invalid PIN');
                    clearPin();
                }
            } catch (error) {
                console.error('Login failed:', error);
                showLoginError('Connection error. Please try again.');
                clearPin();
            }
            
            hideLoading();
        }

        function showLoginError(message) {
            haptic.error();
            const errorEl = document.getElementById('loginError');
            errorEl.textContent = message;
            errorEl.style.display = 'block';
        }

        async function logout() {
            try {
                await fetch('api.php?action=logout', { method: 'POST' });
            } catch (error) {
                console.error('Logout error:', error);
            }
            
            currentUser = null;
            stopScanner();
            showLogin();
        }

        // ==========================================================================
        // BARCODE SCANNER
        // ==========================================================================
        function startScanner() {
            updateScannerStatus('Initializing camera...', 'loading');
            
            Quagga.init({
                inputStream: {
                    name: "Live",
                    type: "LiveStream",
                    target: document.querySelector('#scanner'),
                    constraints: {
                        facingMode: "environment",
                        width: { ideal: 1280 },
                        height: { ideal: 720 }
                    }
                },
                decoder: {
                    readers: ["code_128_reader", "code_39_reader"]
                },
                locate: true,
                locator: {
                    halfSample: true,
                    patchSize: "medium"
                }
            }, function(err) {
                if (err) {
                    console.error('Quagga init error:', err);
                    updateScannerStatus('Camera access denied. Use manual entry.', 'error');
                    haptic.warning();
                    return;
                }
                
                Quagga.start();
                updateScannerStatus('Ready to scan', 'ready');
            });

            Quagga.onDetected(handleBarcodeScan);
        }

        function stopScanner() {
            try {
                Quagga.offDetected(handleBarcodeScan);
                Quagga.stop();
            } catch (e) {
                // Scanner might not be running
            }
        }

        function handleBarcodeScan(result) {
            const code = result.codeResult.code;
            console.log('Scanned:', code);
            
            stopScanner();
            haptic.scanDetected();
            lookupOrder(code);
        }

        function updateScannerStatus(text, status) {
            const statusEl = document.getElementById('scannerStatus');
            statusEl.querySelector('.status-text').textContent = text;
            statusEl.className = 'scanner-status status-' + status;
        }

        // ==========================================================================
        // ORDER LOOKUP
        // ==========================================================================
        function lookupManual() {
            const tracking = document.getElementById('manualTracking').value.trim();
            if (tracking) {
                haptic.tap();
                lookupOrder(tracking);
            }
        }

        async function lookupOrder(tracking) {
            showLoading();
            updateScannerStatus('Looking up order...', 'loading');
            
            try {
                const response = await fetch('api.php?action=lookup&tracking=' + encodeURIComponent(tracking));
                const data = await response.json();
                
                if (data.success) {
                    haptic.success();
                    showOrder(data.order);
                } else {
                    haptic.error();
                    updateScannerStatus(data.error || 'Order not found', 'error');
                    setTimeout(() => {
                        updateScannerStatus('Ready to scan', 'ready');
                        startScanner();
                    }, 2000);
                }
            } catch (error) {
                console.error('Lookup failed:', error);
                haptic.error();
                updateScannerStatus('Connection error', 'error');
                setTimeout(() => {
                    updateScannerStatus('Ready to scan', 'ready');
                    startScanner();
                }, 2000);
            }
            
            hideLoading();
            document.getElementById('manualTracking').value = '';
        }

        // ==========================================================================
        // STATUS UPDATE
        // ==========================================================================
        function formatStatus(status) {
            const labels = {
                'unpaid': 'Unpaid',
                'paid': 'Paid',
                'preflight': 'Preflight',
                'file_issue': 'File Issue',
                'printing': 'Printing',
                'ready': 'Ready',
                'dispatched': 'Dispatched',
                'shipped': 'Shipped',
                'delivered': 'Delivered',
                'pickedup': 'Picked Up',
                'unclaimed': 'Unclaimed',
                'missing': 'Missing',
                'cancelled': 'Cancelled',
                'refunded': 'Refunded'
            };
            return labels[status] || status;
        }

        function selectStatus(status) {
            selectedStatus = status;
            confirmStatus();
        }

        function confirmStatus() {
            if (!selectedStatus) return;
            
            const statusLabel = formatStatus(selectedStatus);
            document.getElementById('confirmMessage').textContent = 
                `Change status from "${formatStatus(currentOrder.currentStatus)}" to "${statusLabel}"?`;
            
            document.getElementById('confirmModal').classList.add('show');
        }

        function closeModal() {
            document.getElementById('confirmModal').classList.remove('show');
        }

        async function executeStatusUpdate() {
            closeModal();
            showLoading();
            
            try {
                const formData = new FormData();
                formData.append('reference_code', currentOrder.referenceCode);
                formData.append('status', selectedStatus);
                
                if (photoBlob) {
                    formData.append('photo', photoBlob, 'delivery_photo.jpg');
                }
                
                const response = await fetch('api.php?action=update_status', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.success) {
                    haptic.confirm();
                    showSuccess(`Order ${currentOrder.referenceCode} updated to "${formatStatus(selectedStatus)}"`);
                } else {
                    haptic.error();
                    alert('Error: ' + (data.error || 'Update failed'));
                }
            } catch (error) {
                console.error('Update failed:', error);
                haptic.error();
                alert('Connection error. Please try again.');
            }
            
            hideLoading();
        }

        function showSuccess(message) {
            document.getElementById('successMessage').textContent = message;
            document.getElementById('successModal').classList.add('show');
        }

        function closeSuccessAndScan() {
            document.getElementById('successModal').classList.remove('show');
            currentOrder = null;
            selectedStatus = null;
            photoBlob = null;
            stopPhotoStream();
            showScanner();
        }

        // ==========================================================================
        // FULL-SCREEN PHOTO CAPTURE
        // ==========================================================================
        function togglePhotoCapture() {
            const checkbox = document.getElementById('includePhoto');
            
            if (checkbox.checked) {
                openPhotoCapture();
            } else {
                photoBlob = null;
                document.getElementById('photoThumbnail').style.display = 'none';
            }
        }
        
        function openPhotoCapture() {
            const overlay = document.getElementById('photoFullscreen');
            overlay.classList.add('active');
            overlay.classList.remove('captured');
            startPhotoStream();
        }
        
        function closePhotoCapture() {
            const overlay = document.getElementById('photoFullscreen');
            overlay.classList.remove('active');
            overlay.classList.remove('captured');
            stopPhotoStream();
            
            // If no photo was taken, uncheck the checkbox
            if (!photoBlob) {
                document.getElementById('includePhoto').checked = false;
            }
        }

        async function startPhotoStream() {
            try {
                photoStream = await navigator.mediaDevices.getUserMedia({
                    video: { 
                        facingMode: 'environment',
                        width: { ideal: 1920 },
                        height: { ideal: 1080 }
                    }
                });
                
                const video = document.getElementById('photoVideo');
                video.srcObject = photoStream;
                video.style.display = 'block';
                document.getElementById('capturedPhoto').style.display = 'none';
            } catch (error) {
                console.error('Camera error:', error);
                haptic.warning();
                alert('Could not access camera for photo');
                closePhotoCapture();
                document.getElementById('includePhoto').checked = false;
            }
        }

        function stopPhotoStream() {
            if (photoStream) {
                photoStream.getTracks().forEach(track => track.stop());
                photoStream = null;
            }
        }

        function capturePhoto() {
            haptic.shutter();
            
            const video = document.getElementById('photoVideo');
            const canvas = document.getElementById('photoCanvas');
            const img = document.getElementById('capturedPhoto');
            
            // Set canvas size to video size
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            
            // Draw video frame to canvas
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);
            
            // Convert to blob
            canvas.toBlob(function(blob) {
                photoBlob = blob;
                
                // Show captured image in fullscreen
                const url = URL.createObjectURL(blob);
                img.src = url;
                img.style.display = 'block';
                video.style.display = 'none';
                
                // Switch to review mode
                document.getElementById('photoFullscreen').classList.add('captured');
                
                // Stop video stream
                stopPhotoStream();
            }, 'image/jpeg', 0.85);
        }

        function retakePhoto() {
            photoBlob = null;
            document.getElementById('capturedPhoto').style.display = 'none';
            document.getElementById('photoVideo').style.display = 'block';
            document.getElementById('photoFullscreen').classList.remove('captured');
            startPhotoStream();
        }
        
        function usePhoto() {
            haptic.success();
            
            // Show thumbnail on the order page
            const thumbnail = document.getElementById('photoThumbnail');
            const thumbnailImg = document.getElementById('thumbnailImg');
            thumbnailImg.src = document.getElementById('capturedPhoto').src;
            thumbnail.style.display = 'block';
            
            // Close fullscreen
            closePhotoCapture();
            
            // Keep checkbox checked
            document.getElementById('includePhoto').checked = true;
        }

        // ==========================================================================
        // LOADING OVERLAY
        // ==========================================================================
        function showLoading() {
            document.getElementById('loadingOverlay').classList.add('show');
        }

        function hideLoading() {
            document.getElementById('loadingOverlay').classList.remove('show');
        }

        // ==========================================================================
        // KEYBOARD SUPPORT FOR PIN
        // ==========================================================================
        document.addEventListener('keydown', function(e) {
            if (document.getElementById('loginScreen').classList.contains('active')) {
                if (e.key >= '0' && e.key <= '9') {
                    enterPin(e.key);
                } else if (e.key === 'Backspace') {
                    backspacePin();
                } else if (e.key === 'Escape') {
                    clearPin();
                }
            }
        });
        
        console.log('Dispatch Scanner loaded');
        console.log('- Vibration supported:', haptic.vibrateSupported);
        console.log('- Audio will initialize on first interaction');
    </script>
</body>
</html>
