<?php
/**
 * Courier App
 * Mobile-first delivery management for couriers, MTCC staff, and admins
 * Location: /courier/index.php
 * Access: mtcc.print-stuff.ca/courier/
 */
session_start();
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
$cacheBust = time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#7c3aed">
    <title>MTCC Courier</title>
    <link rel="manifest" href="manifest.json">
    <link rel="icon" type="image/png" href="../assets/logo.png">
    <link rel="apple-touch-icon" href="../assets/logo.png">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="app.css?v=<?= $cacheBust ?>">
    <link rel="stylesheet" href="courier-issues.css?v=<?= $cacheBust ?>">
    <!-- QuaggaJS for barcode scanning -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/quagga/0.12.1/quagga.min.js"></script>
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyDtsKlcP439gjDYjDOTbd-nd4spGM77fYg&libraries=places,marker&loading=async&callback=Function.prototype"></script>
</head>
<body>

<!-- ============================================ -->
<!-- LOGIN SCREEN                                 -->
<!-- ============================================ -->
<div id="loginScreen" class="screen active">
    <div class="login-wrapper">
        <div class="login-header">
            <img src="../logo.png" alt="MTCC Print Services" class="login-logo" onerror="this.style.display='none'">
            <h1 class="login-title">MTCC Courier</h1>
            <p class="login-subtitle">Enter your PIN to sign in</p>
        </div>
        
        <div class="pin-display" id="pinDisplay">
            <div class="pin-dot" id="pinDot0" data-index="0"></div>
            <div class="pin-dot" id="pinDot1" data-index="1"></div>
            <div class="pin-dot" id="pinDot2" data-index="2"></div>
            <div class="pin-dot" id="pinDot3" data-index="3"></div>
            <div class="pin-dot" id="pinDot4" data-index="4"></div>
            <div class="pin-dot" id="pinDot5" data-index="5"></div>
        </div>
        
        <div class="pin-error" id="pinError"></div>
        
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
            <button class="key-btn key-fn" onclick="clearPin()">Clear</button>
            <button class="key-btn" onclick="enterPin('0')">0</button>
            <button class="key-btn key-fn" onclick="backspacePin()">&#9003;</button>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- APP SCREEN                                   -->
<!-- ============================================ -->
<div id="appScreen" class="screen">
    
    <!-- App Header -->
    <header class="app-header">
        <div class="app-header-left">
            <img src="../logo.png" alt="MTCC" class="header-logo-img" onerror="this.style.display='none'">
        </div>
        <div class="app-header-right">
            <span class="role-pill" id="headerRole"></span>
        </div>
    </header>
    
    <!-- Weather Bar -->
    <div id="weatherBar" class="weather-bar" style="display:none;">
        <div class="weather-bar-inner">
            <span class="weather-icon" id="weatherIcon"></span>
            <span class="weather-temp" id="weatherTemp"></span>
            <span class="weather-desc" id="weatherDesc"></span>
            <span class="weather-wind" id="weatherWind"></span>
        </div>
        <div id="weatherBadge" class="weather-badge" style="display:none;"></div>
    </div>
    
    <!-- Pull to Refresh indicator -->
    <div class="pull-indicator" id="pullIndicator">
        <div class="pull-spinner"></div>
        <span>Release to refresh</span>
    </div>
    
    <!-- Tab Content Area -->
    <main class="app-content" id="appContent">

        <!-- HOME TAB (Courier) -->
        <div class="tab-pane" id="tab-home">
            <div class="tab-body" id="homeContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading...</span></div>
            </div>
        </div>

        <!-- MY DELIVERIES TAB (Courier) -->
        <div class="tab-pane" id="tab-deliveries">
            <div class="tab-header">
                <h2>My Deliveries</h2>

            </div>
            <div class="tab-body" id="deliveriesContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading deliveries...</span></div>
            </div>
        </div>
        
        <!-- AVAILABLE TAB (Courier) — List + Map views -->
        <div class="tab-pane" id="tab-available">
            <div class="tab-header">
                <h2>Available Orders</h2>
                <div class="view-mode-toggle" id="viewModeToggle">
                    <button class="view-mode-btn active" data-mode="list" onclick="setAvailableMode('list')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    </button>
                    <button class="view-mode-btn" data-mode="map" onclick="setAvailableMode('map')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    </button>
                </div>
            </div>
            <!-- List view -->
            <div class="tab-body available-list-view" id="availableContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading orders...</span></div>
            </div>
            <!-- Map view (hidden by default) -->
            <div class="tab-body available-map-view" id="availableMapView" style="display:none;">
                <div id="nearbyMap" class="nearby-map"></div>
                <div id="nearbyList" class="nearby-list"></div>
            </div>
        </div>
        
        <!-- SCAN TAB (All roles) -->
        <div class="tab-pane" id="tab-scan">
            <div class="tab-header">
                <h2>Scan Order</h2>
            </div>
            <div class="tab-body" id="scanContent">
                <div class="scan-section">
                    <!-- Camera Scanner -->
                    <div class="scanner-viewport" id="scannerViewport">
                        <div id="scannerTarget"></div>
                        <div class="scanner-frame">
                            <div class="frame-corner tl"></div>
                            <div class="frame-corner tr"></div>
                            <div class="frame-corner bl"></div>
                            <div class="frame-corner br"></div>
                            <div class="scan-line"></div>
                        </div>
                        <button class="scanner-toggle-btn" id="scannerToggleBtn" onclick="toggleScanner()">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                            <span>Starting...</span>
                        </button>
                    </div>
                    
                    <!-- Manual Entry -->
                    <div class="manual-entry">
                        <div class="manual-divider"><span>or enter manually</span></div>
                        <div class="manual-input-group">
                            <input type="text" id="manualTracking" class="manual-input" placeholder="Tracking number or reference code" autocomplete="off" spellcheck="false">
                            <button class="manual-submit-btn" onclick="submitManualScan()">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Scan Result -->
                    <div class="scan-result" id="scanResult" style="display:none;"></div>
                </div>
            </div>
        </div>
        
        <!-- PICKUP QUEUE TAB (MTCC Staff / Admin) -->
        <div class="tab-pane" id="tab-pickup">
            <div class="tab-header">
                <h2>Pickup Queue</h2>
            </div>
            <div class="tab-body" id="pickupContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading queue...</span></div>
            </div>
        </div>
        
        <!-- NEARBY TAB removed — merged into Available tab as map view -->
        
        <!-- ACCOUNT TAB (Courier) -->
        <div class="tab-pane" id="tab-account">
            <div class="tab-body" id="accountContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading...</span></div>
            </div>
        </div>

        <!-- EARNINGS SUB-VIEW (opened from Account) -->
        <div class="tab-pane" id="tab-earnings">
            <div class="tab-header">
                <button class="back-to-account" onclick="switchTab('account')"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg> Account</button>
                <h2>Earnings & Performance</h2>
            </div>
            <div class="tab-body" id="earningsContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading earnings...</span></div>
            </div>
        </div>
        
        <!-- ACTIVITY TAB (Admin) -->
        <div class="tab-pane" id="tab-activity">
            <div class="tab-header">
                <h2>Today's Activity</h2>
            </div>
            <div class="tab-body" id="activityContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading activity...</span></div>
            </div>
        </div>

        <!-- MTCC DASHBOARD TAB -->
        <div class="tab-pane" id="tab-mtcc_dashboard">
            <div class="tab-header">
                <h2>Dashboard</h2>
            </div>
            <div class="tab-body" id="mtccDashboardContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading dashboard...</span></div>
            </div>
        </div>

        <!-- UPCOMING TAB (MTCC - pipeline orders) -->
        <div class="tab-pane" id="tab-upcoming_mtcc">
            <div class="tab-header">
                <h2>Upcoming Orders</h2>
            </div>
            <div class="tab-body" id="upcomingMtccContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading orders...</span></div>
            </div>
        </div>

        <!-- COMPLETE TAB (MTCC - picked up orders) -->
        <div class="tab-pane" id="tab-complete">
            <div class="tab-header">
                <h2>Completed Pickups</h2>
            </div>
            <div class="tab-body" id="completeContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading completed orders...</span></div>
            </div>
        </div>
        
        <!-- HISTORY TAB (Courier) -->
        <div class="tab-pane" id="tab-history">
            <div class="tab-header">
                <h2>Delivery History</h2>
            </div>
            <div class="tab-body" id="historyContent">
                <div class="loading-state"><div class="spinner-ring"></div><span>Loading history...</span></div>
            </div>
        </div>
        
    </main>
    
    <!-- Order Detail Slide-Up Panel -->
    <div class="detail-overlay" id="detailOverlay" onclick="closeDetailPanel()"></div>
    <div class="detail-panel" id="detailPanel">
        <div class="detail-panel-header" id="detailPanelHeader"></div>
        <div class="detail-content" id="detailContent"></div>
    </div>
    
    <!-- Full-Screen Photo Capture -->
    <div class="photo-overlay" id="photoOverlay">
        <div class="photo-header">
            <button class="photo-close-btn" onclick="closePhotoCapture()">Cancel</button>
            <span class="photo-title">Delivery Photo</span>
            <span></span>
        </div>
        <video id="photoVideo" autoplay playsinline></video>
        <canvas id="photoCanvas" style="display:none;"></canvas>
        <div class="photo-preview" id="photoPreview" style="display:none;">
            <img id="photoPreviewImg" src="" alt="Preview">
        </div>
        <div class="photo-controls">
            <button class="photo-btn retake" id="photoRetakeBtn" onclick="retakePhoto()" style="display:none;">Retake</button>
            <button class="photo-btn capture" id="photoCaptureBtn" onclick="capturePhoto()">
                <div class="shutter-ring"><div class="shutter-dot"></div></div>
            </button>
            <button class="photo-btn use" id="photoUseBtn" onclick="usePhoto()" style="display:none;">Use Photo</button>
        </div>
    </div>
    
    <!-- Bottom Navigation -->
    <nav class="bottom-nav" id="bottomNav"></nav>
    
</div>

<!-- Toast Notifications -->
<div class="toast-container" id="toastContainer"></div>

<!-- Full-Page Transit View -->
<div id="transitView"></div>

<script src="app.js?v=<?= $cacheBust ?>"></script>
<script src="courier-issues.js?v=<?= $cacheBust ?>"></script>
</body>
</html>
