<?php
/**
 * Customer Live Tracking Page
 * URL: /courier/track.php?ref=TECH-003
 * Shows courier's real-time location on a map for the customer or MTCC staff
 */

$ref = $_GET['ref'] ?? '';
if (empty($ref)) { echo '<h1>Invalid tracking link</h1>'; exit; }

// Load order data
$ordersDir = dirname(__DIR__) . '/uploads/orders/';
$orderFile = null;
foreach (glob($ordersDir . '*.json') as $f) {
    $data = json_decode(file_get_contents($f), true);
    if (($data['referenceCode'] ?? '') === $ref) { $orderFile = $f; break; }
}
if (!$orderFile) { echo '<h1>Order not found</h1>'; exit; }

$order = json_decode(file_get_contents($orderFile), true);

// Use dispatch helpers for consistent destination resolution
require_once dirname(__DIR__) . '/dispatch/dispatch-functions.php';
$destInfo = dispatch_getDestination($order);
$status = $order['dispatch']['status'] ?? ($order['status'] ?? 'unknown');
$dest = $destInfo['label'] ?? '';
$destAddr = $destInfo['address'] ?? '';
$destInstructions = $destInfo['instructions'] ?? '';

// Try to get destination coordinates from route_info or MTCC locations
$destLat = $order['route_info']['dropoff_coords']['lat'] ?? $order['dispatch']['destination_coords']['lat'] ?? null;
$destLng = $order['route_info']['dropoff_coords']['lng'] ?? $order['dispatch']['destination_coords']['lng'] ?? null;

// If no coords, try geocoding the address
if (!$destLat && $destAddr) {
    require_once __DIR__ . '/routes-api.php';
    $routes = new RoutesAPI();
    $coords = $routes->geocodeAddress($destAddr);
    if ($coords) { $destLat = $coords['lat']; $destLng = $coords['lng']; }
}

// Google Maps API key
require_once __DIR__ . '/google-maps-config.php';
$apiKey = GOOGLE_MAPS_BROWSER_KEY ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Delivery — <?= htmlspecialchars($ref) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Montserrat', sans-serif; background: #f9fafb; color: #1f2937; }
        .track-header {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            color: white; padding: 20px; text-align: center;
        }
        .track-header h1 { font-size: 1.1rem; font-weight: 700; }
        .track-header .track-ref { font-size: 0.8rem; opacity: 0.8; margin-top: 4px; }
        .track-status {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 12px; background: white; border-bottom: 1px solid #e5e7eb;
            font-size: 0.85rem; font-weight: 600;
        }
        .track-status .pulse {
            width: 10px; height: 10px; border-radius: 50%; background: #22c55e;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }
        #map { width: 100%; height: 60vh; }
        .track-info { padding: 16px; }
        .track-info h3 { font-size: 0.9rem; margin-bottom: 8px; }
        .track-dest {
            display: flex; align-items: center; gap: 8px;
            padding: 12px; background: white; border-radius: 10px;
            border: 1px solid #e5e7eb; font-size: 0.82rem;
        }
        .track-dest .icon { color: #059669; }
        .track-eta { text-align: center; padding: 16px; font-size: 1rem; font-weight: 700; color: #7c3aed; }
        .track-powered { text-align: center; padding: 16px; font-size: 0.7rem; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="track-header">
        <h1>Your Delivery is On Its Way</h1>
        <div class="track-ref"><?= htmlspecialchars($ref) ?></div>
    </div>
    <div class="track-status">
        <span class="pulse"></span>
        <span id="statusText">Courier is en route</span>
    </div>
    <div id="map"></div>
    <div class="track-info">
        <h3>Delivering to</h3>
        <div class="track-dest">
            <span class="icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></span>
            <div>
                <strong><?= htmlspecialchars($dest ?: 'MTCC') ?></strong><br>
                <span style="color:#6b7280;"><?= htmlspecialchars($destAddr) ?></span>
                <?php if ($destInstructions): ?><br><span style="color:#3b82f6;font-style:italic;font-size:0.78rem;"><?= htmlspecialchars($destInstructions) ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="track-eta" id="eta">Calculating ETA...</div>
    <div class="track-powered">Powered by Print Stuff &bull; print-stuff.ca</div>

    <script>
    var ref = '<?= addslashes($ref) ?>';
    var courierMarker, map;

    function initMap() {
        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 13,
            center: { lat: 43.6445, lng: -79.3871 },
            disableDefaultUI: true,
            zoomControl: true,
            styles: [{ featureType: 'poi', stylers: [{ visibility: 'off' }] }]
        });

        // Poll courier location every 15s
        fetchLocation();
        setInterval(fetchLocation, 15000);
    }

    var destMarker, routePath, lastEta = null;

    function fetchLocation() {
        fetch('/courier/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=get_tracking&ref=' + encodeURIComponent(ref)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var etaEl = document.getElementById('eta');
            var statusEl = document.getElementById('statusText');
            var pulseEl = document.querySelector('.pulse');

            // Handle no data / no courier location
            if (!data.success) {
                etaEl.textContent = 'Unable to load tracking data';
                return;
            }

            // Status-based display
            if (data.status === 'delivered' || data.status === 'pickedup') {
                statusEl.textContent = data.status === 'pickedup' ? 'Picked Up!' : 'Delivered!';
                pulseEl.style.background = '#22c55e';
                etaEl.innerHTML = '<span style="color:#16a34a;font-size:1.1rem;">&#10003; Your order has been delivered</span>';
                return;
            }
            if (data.status === 'ready' || data.status === 'dispatched') {
                statusEl.textContent = 'Courier assigned — pickup pending';
                pulseEl.style.background = '#d97706';
                etaEl.textContent = 'Courier will pick up your order soon';
                return;
            }
            if (data.status !== 'shipped') {
                statusEl.textContent = 'Order is being prepared';
                pulseEl.style.background = '#6366f1';
                etaEl.textContent = 'We\'ll update you when your order is on its way';
                return;
            }

            // In transit — show courier on map
            if (!data.lat || !data.lng) {
                etaEl.textContent = 'Courier is en route — waiting for location';
                return;
            }
            if (data.stale) {
                statusEl.textContent = 'Last update: ' + new Date(data.updated_at).toLocaleTimeString();
            }

            var pos = { lat: data.lat, lng: data.lng };

            // Courier marker
            if (!courierMarker) {
                courierMarker = new google.maps.Marker({
                    position: pos, map: map,
                    icon: {
                        url: 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="%237c3aed" stroke="white" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>'),
                        scaledSize: new google.maps.Size(36, 36),
                        anchor: new google.maps.Point(18, 18)
                    }
                });
                // Fit bounds to show both courier and destination
                var bounds = new google.maps.LatLngBounds();
                bounds.extend(pos);
                <?php if ($destLat && $destLng): ?>
                var destPos = { lat: <?= floatval($destLat) ?>, lng: <?= floatval($destLng) ?> };
                destMarker = new google.maps.Marker({ position: destPos, map: map, icon: { url: 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="%23059669" stroke="white" stroke-width="1.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>'), scaledSize: new google.maps.Size(32, 32), anchor: new google.maps.Point(16, 32) } });
                bounds.extend(destPos);
                <?php endif; ?>
                map.fitBounds(bounds, 60);
            } else {
                courierMarker.setPosition(pos);
            }

            // ETA display
            if (data.eta_min !== null && data.eta_min !== undefined) {
                lastEta = data.eta_min;
                var etaText = '';
                if (data.eta_min <= 1) {
                    etaText = 'Arriving now!';
                    etaEl.style.color = '#16a34a';
                } else if (data.eta_min <= 5) {
                    etaText = 'Almost there — ' + data.eta_min + ' min away';
                    etaEl.style.color = '#d97706';
                } else {
                    etaText = 'Estimated arrival: ~' + data.eta_min + ' min';
                    etaEl.style.color = '#7c3aed';
                }
                if (data.distance_km) etaText += ' (' + data.distance_km + ' km)';
                etaEl.textContent = etaText;
            } else if (lastEta === null) {
                etaEl.textContent = 'Courier is on the way';
            }
        })
        .catch(function() {
            document.getElementById('eta').textContent = 'Connection lost — retrying...';
        });
    }
    </script>
    <script src="https://maps.googleapis.com/maps/api/js?key=<?= $apiKey ?>&callback=initMap" async defer></script>
</body>
</html>
