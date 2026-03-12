<?php
/**
 * Google Maps Routes API Wrapper
 * MTCC Print Services — Courier App
 * 
 * Handles all Google Maps API interactions:
 *   - Geocoding (address → lat/lng with vendor caching)
 *   - Distance/duration calculation
 *   - Multi-stop route optimization
 *   - Static map image URLs
 *   - Directions deep links
 * 
 * Server path: /courier/routes-api.php
 */

require_once __DIR__ . '/google-maps-config.php';

class RoutesAPI {
    
    private $serverKey;
    private $browserKey;
    private $cacheDir;
    
    public function __construct() {
        $this->serverKey = defined('GOOGLE_MAPS_SERVER_KEY') ? GOOGLE_MAPS_SERVER_KEY : GOOGLE_MAPS_API_KEY;
        $this->browserKey = defined('GOOGLE_MAPS_BROWSER_KEY') ? GOOGLE_MAPS_BROWSER_KEY : GOOGLE_MAPS_API_KEY;
        $this->cacheDir = GOOGLE_MAPS_CACHE_DIR;
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }
    
    // ============================================
    // GEOCODING
    // ============================================
    
    /**
     * Convert address string to lat/lng coordinates.
     * Results are cached to avoid repeated API calls.
     */
    public function geocodeAddress($address) {
        if (empty($address)) return null;
        
        // Clean address for consistent caching
        $cleanAddr = $this->cleanAddress($address);
        
        // Check cache first
        $cached = $this->getCache('geocode', md5($cleanAddr));
        if ($cached) return $cached;
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query([
            'address' => $cleanAddr,
            'region' => 'ca',
            'key' => $this->serverKey
        ]);
        
        $response = $this->httpGet($url);
        if (!$response) return null;
        
        $data = json_decode($response, true);
        if (empty($data['results'][0]['geometry']['location'])) return null;
        
        $location = $data['results'][0]['geometry']['location'];
        $result = [
            'lat' => $location['lat'],
            'lng' => $location['lng'],
            'formatted_address' => $data['results'][0]['formatted_address'] ?? $cleanAddr,
            'place_id' => $data['results'][0]['place_id'] ?? null
        ];
        
        // Cache result
        $this->setCache('geocode', md5($cleanAddr), $result);
        
        return $result;
    }
    
    /**
     * Geocode a vendor and save coordinates to vendors.json.
     * Returns cached coordinates if already geocoded.
     */
    public function geocodeVendor($vendorId) {
        $vendorsFile = __DIR__ . '/../data/vendors.json';
        if (!file_exists($vendorsFile)) return null;
        
        $vendorData = json_decode(file_get_contents($vendorsFile), true);
        $vendor = null;
        $vendorIdx = null;
        
        foreach ($vendorData['vendors'] as $i => $v) {
            if ($v['id'] === $vendorId) {
                $vendor = $v;
                $vendorIdx = $i;
                break;
            }
        }
        
        if (!$vendor) return null;
        
        // Return cached coordinates if they exist
        if (!empty($vendor['coordinates']['lat']) && !empty($vendor['coordinates']['lng'])) {
            return $vendor['coordinates'];
        }
        
        // Geocode the address
        $coords = $this->geocodeAddress($vendor['address'] ?? '');
        if (!$coords) return null;
        
        // Save to vendors.json
        $vendorData['vendors'][$vendorIdx]['coordinates'] = [
            'lat' => $coords['lat'],
            'lng' => $coords['lng'],
            'geocoded_at' => date('c')
        ];
        $vendorData['metadata']['updated_at'] = date('c');
        
        file_put_contents($vendorsFile, json_encode($vendorData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        
        return $coords;
    }
    
    // ============================================
    // DISTANCE & DURATION
    // ============================================
    
    /**
     * Get distance and duration between two points.
     * Uses the Routes API (computeRoutes).
     * 
     * @param array $origin  ['lat' => float, 'lng' => float] or string address
     * @param array $dest    ['lat' => float, 'lng' => float] or string address
     * @param string $mode   'DRIVE', 'BICYCLE', 'WALK', 'TRANSIT'
     * @return array|null    ['distance_m' => int, 'distance_km' => float, 'duration_s' => int, 'duration_min' => float]
     */
    public function getDistance($origin, $dest, $mode = 'DRIVE') {
        $originWaypoint = $this->toWaypoint($origin);
        $destWaypoint = $this->toWaypoint($dest);
        
        if (!$originWaypoint || !$destWaypoint) return null;
        
        // Cache key based on origin/dest
        $cacheKey = md5(json_encode([$originWaypoint, $destWaypoint, $mode]));
        $cached = $this->getCache('distance', $cacheKey);
        if ($cached) return $cached;
        
        $body = [
            'origin' => $originWaypoint,
            'destination' => $destWaypoint,
            'travelMode' => $mode,
            'routingPreference' => ($mode === 'DRIVE') ? 'TRAFFIC_AWARE' : 'ROUTING_PREFERENCE_UNSPECIFIED',
            'computeAlternativeRoutes' => false,
            'languageCode' => 'en-US',
            'units' => 'METRIC'
        ];
        
        $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';
        $headers = [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->serverKey,
            'X-Goog-FieldMask: routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline'
        ];
        
        $response = $this->httpPost($url, $body, $headers);
        if (!$response) return null;
        
        $data = json_decode($response, true);
        if (empty($data['routes'][0])) return null;
        
        $route = $data['routes'][0];
        $distanceM = $route['distanceMeters'] ?? 0;
        $durationStr = $route['duration'] ?? '0s';
        $durationS = (int)str_replace('s', '', $durationStr);
        
        $result = [
            'distance_m' => $distanceM,
            'distance_km' => round($distanceM / 1000, 1),
            'duration_s' => $durationS,
            'duration_min' => round($durationS / 60, 0),
            'polyline' => $route['polyline']['encodedPolyline'] ?? null
        ];
        
        // Cache for 1 hour
        $this->setCache('distance', $cacheKey, $result, 3600);
        
        return $result;
    }
    
    /**
     * Calculate route with multiple stops (waypoint optimization).
     * 
     * @param array $origin      Starting point
     * @param array $destination Final destination
     * @param array $waypoints   Array of intermediate stops
     * @param bool $optimize     Whether to optimize waypoint order
     * @return array|null
     */
    public function calculateRoute($origin, $destination, $waypoints = [], $optimize = true) {
        $originWP = $this->toWaypoint($origin);
        $destWP = $this->toWaypoint($destination);
        if (!$originWP || !$destWP) return null;
        
        $body = [
            'origin' => $originWP,
            'destination' => $destWP,
            'travelMode' => 'DRIVE',
            'routingPreference' => 'TRAFFIC_AWARE',
            'optimizeWaypointOrder' => $optimize,
            'languageCode' => 'en-US',
            'units' => 'METRIC'
        ];
        
        // Add intermediate waypoints
        if (!empty($waypoints)) {
            $body['intermediates'] = [];
            foreach ($waypoints as $wp) {
                $converted = $this->toWaypoint($wp);
                if ($converted) {
                    $body['intermediates'][] = $converted;
                }
            }
        }
        
        $url = 'https://routes.googleapis.com/directions/v2:computeRoutes';
        $headers = [
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $this->serverKey,
            'X-Goog-FieldMask: routes.duration,routes.distanceMeters,routes.polyline.encodedPolyline,routes.optimizedIntermediateWaypointIndex,routes.legs.duration,routes.legs.distanceMeters,routes.legs.startLocation,routes.legs.endLocation'
        ];
        
        $response = $this->httpPost($url, $body, $headers);
        if (!$response) return null;
        
        $data = json_decode($response, true);
        if (empty($data['routes'][0])) return null;
        
        $route = $data['routes'][0];
        $distanceM = $route['distanceMeters'] ?? 0;
        $durationStr = $route['duration'] ?? '0s';
        $durationS = (int)str_replace('s', '', $durationStr);
        
        // Parse legs for per-stop distances
        $legs = [];
        foreach (($route['legs'] ?? []) as $leg) {
            $legDur = (int)str_replace('s', '', $leg['duration'] ?? '0s');
            $legs[] = [
                'distance_km' => round(($leg['distanceMeters'] ?? 0) / 1000, 1),
                'duration_min' => round($legDur / 60, 0)
            ];
        }
        
        return [
            'distance_m' => $distanceM,
            'distance_km' => round($distanceM / 1000, 1),
            'duration_s' => $durationS,
            'duration_min' => round($durationS / 60, 0),
            'polyline' => $route['polyline']['encodedPolyline'] ?? null,
            'optimized_order' => $route['optimizedIntermediateWaypointIndex'] ?? null,
            'legs' => $legs
        ];
    }
    
    // ============================================
    // STATIC MAPS & LINKS
    // ============================================
    
    /**
     * Generate a Static Maps API URL showing a route.
     * 
     * @param array $stops    Array of ['lat','lng'] points
     * @param int $width      Image width
     * @param int $height     Image height
     * @param string $polyline Encoded polyline from Routes API
     * @return string URL
     */
    public function getStaticMapUrl($stops, $width = 400, $height = 200, $polyline = null) {
        $params = [
            'size' => $width . 'x' . $height,
            'maptype' => 'roadmap',
            'key' => $this->browserKey,
            'scale' => 2  // Retina
        ];
        
        $url = 'https://maps.googleapis.com/maps/api/staticmap?' . http_build_query($params);
        
        // Add markers
        $labels = 'ABCDEFGHIJ';
        foreach ($stops as $i => $stop) {
            $label = $labels[$i] ?? ($i + 1);
            $color = ($i === 0) ? 'blue' : (($i === count($stops) - 1) ? 'red' : 'green');
            $url .= '&markers=color:' . $color . '%7Clabel:' . $label . '%7C' . $stop['lat'] . ',' . $stop['lng'];
        }
        
        // Add polyline if available
        if ($polyline) {
            $url .= '&path=enc:' . urlencode($polyline);
        }
        
        return $url;
    }
    
    /**
     * Generate Google Maps directions URL (for "Open in Google Maps" button).
     */
    public function getDirectionsUrl($origin, $destination, $waypoints = []) {
        $url = 'https://www.google.com/maps/dir/?api=1';
        
        if (is_array($origin)) {
            $url .= '&origin=' . $origin['lat'] . ',' . $origin['lng'];
        } else {
            $url .= '&origin=' . urlencode($origin);
        }
        
        if (is_array($destination)) {
            $url .= '&destination=' . $destination['lat'] . ',' . $destination['lng'];
        } else {
            $url .= '&destination=' . urlencode($destination);
        }
        
        if (!empty($waypoints)) {
            $wpStrs = [];
            foreach ($waypoints as $wp) {
                if (is_array($wp)) {
                    $wpStrs[] = $wp['lat'] . ',' . $wp['lng'];
                } else {
                    $wpStrs[] = urlencode($wp);
                }
            }
            $url .= '&waypoints=' . implode('%7C', $wpStrs);
        }
        
        $url .= '&travelmode=driving';
        return $url;
    }
    
    // ============================================
    // DISTANCE-BASED PRICING
    // ============================================
    
    /**
     * Calculate distance modifier for courier payout.
     * Uses dispatch-settings.json thresholds.
     */
    public function calculateDistanceModifier($distanceKm) {
        $settingsFile = __DIR__ . '/../data/dispatch-settings.json';
        $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
        $pricing = $settings['pricing'] ?? [];
        
        $threshold = $pricing['distance_threshold_km'] ?? 50;
        $extThreshold = $pricing['extended_distance_threshold_km'] ?? 35;
        $perKm = $pricing['modifiers']['per_km_over_threshold'] ?? 0.35;
        $extBonus = $pricing['modifiers']['extended_distance'] ?? 8;
        
        $modifier = 0;
        
        // Extended distance flat bonus
        if ($distanceKm > $extThreshold) {
            $modifier += $extBonus;
        }
        
        // Per-km charge over threshold
        if ($distanceKm > $threshold) {
            $modifier += ($distanceKm - $threshold) * $perKm;
        }
        
        return round($modifier, 2);
    }
    
    // ============================================
    // NEARBY ORDERS (for courier map)
    // ============================================
    
    /**
     * Calculate distances from courier's location to all available orders.
     * Uses Haversine formula for quick estimates (no API call).
     * Full route distance fetched on demand when courier taps an order.
     */
    public function getNearbyDistances($courierLat, $courierLng, $orders) {
        $results = [];
        
        foreach ($orders as $order) {
            $vendorCoords = $this->getOrderPickupCoords($order);
            if (!$vendorCoords) continue;
            
            $straightLine = $this->haversineDistance(
                $courierLat, $courierLng,
                $vendorCoords['lat'], $vendorCoords['lng']
            );
            
            // Estimate driving distance as ~1.3x straight line
            $estDriving = round($straightLine * 1.3, 1);
            
            $results[] = [
                'ref' => $order['ref'] ?? $order['referenceCode'] ?? '',
                'vendor_lat' => $vendorCoords['lat'],
                'vendor_lng' => $vendorCoords['lng'],
                'straight_line_km' => round($straightLine, 1),
                'est_distance_km' => $estDriving,
                'est_duration_min' => max(5, round($estDriving * 2.5, 0))  // Rough Toronto avg
            ];
        }
        
        // Sort by distance
        usort($results, function($a, $b) {
            return $a['straight_line_km'] <=> $b['straight_line_km'];
        });
        
        return $results;
    }
    
    /**
     * Get pickup coordinates for an order.
     * Looks up vendor, geocodes if needed.
     */
    public function getOrderPickupCoords($order) {
        $vendorId = $order['vendor_id'] ?? $order['vendorId'] ?? null;
        
        if ($vendorId) {
            $coords = $this->geocodeVendor($vendorId);
            if ($coords) return $coords;
        }
        
        // Fallback: geocode the vendor address directly
        $addr = $order['vendor_address'] ?? '';
        if (empty($addr)) return null;
        
        return $this->geocodeAddress($addr);
    }
    
    /**
     * Get delivery (dropoff) coordinates for an order.
     */
    public function getOrderDropoffCoords($order) {
        // Check MTCC locations first
        $dest = $order['destination'] ?? $order['delivery_destination'] ?? '';
        
        $mtccFile = __DIR__ . '/../data/mtcc-locations.json';
        if (file_exists($mtccFile)) {
            $mtcc = json_decode(file_get_contents($mtccFile), true);
            $destLower = strtolower($dest);
            
            if (strpos($destLower, 'north') !== false && !empty($mtcc['north']['coordinates'])) {
                return $mtcc['north']['coordinates'];
            }
            if (strpos($destLower, 'south') !== false && !empty($mtcc['south']['coordinates'])) {
                return $mtcc['south']['coordinates'];
            }
        }
        
        // Geocode the destination address
        $addr = $order['destination_address'] ?? '';
        if (!empty($addr)) {
            return $this->geocodeAddress($addr);
        }
        
        return null;
    }
    
    // ============================================
    // HELPERS
    // ============================================
    
    /**
     * Clean address for geocoding (remove line breaks, extra spaces).
     */
    private function cleanAddress($address) {
        $addr = str_replace(["\r\n", "\r", "\n"], ', ', $address);
        $addr = preg_replace('/,\s*,/', ',', $addr);
        $addr = preg_replace('/\s+/', ' ', $addr);
        return trim($addr, ', ');
    }
    
    /**
     * Convert lat/lng or address to Routes API waypoint format.
     */
    private function toWaypoint($point) {
        if (is_array($point) && isset($point['lat']) && isset($point['lng'])) {
            return [
                'location' => [
                    'latLng' => [
                        'latitude' => (float)$point['lat'],
                        'longitude' => (float)$point['lng']
                    ]
                ]
            ];
        }
        
        if (is_string($point) && !empty($point)) {
            // Geocode the address first
            $coords = $this->geocodeAddress($point);
            if ($coords) {
                return [
                    'location' => [
                        'latLng' => [
                            'latitude' => (float)$coords['lat'],
                            'longitude' => (float)$coords['lng']
                        ]
                    ]
                ];
            }
            
            // Fallback: use address directly
            return ['address' => $this->cleanAddress($point)];
        }
        
        return null;
    }
    
    /**
     * Haversine distance (km) between two lat/lng points.
     * Used for quick estimates without API calls.
     */
    private function haversineDistance($lat1, $lng1, $lat2, $lng2) {
        $R = 6371; // Earth radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c;
    }
    
    /**
     * HTTP GET request.
     */
    private function httpGet($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Google Maps API error: HTTP $httpCode for $url");
            return null;
        }
        
        return $response;
    }
    
    /**
     * HTTP POST request (for Routes API).
     */
    private function httpPost($url, $body, $headers = []) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Google Routes API error: HTTP $httpCode — " . substr($response, 0, 500));
            return null;
        }
        
        return $response;
    }
    
    /**
     * Simple file-based cache.
     */
    private function getCache($type, $key) {
        $file = $this->cacheDir . '/' . $type . '_' . $key . '.json';
        if (!file_exists($file)) return null;
        
        $data = json_decode(file_get_contents($file), true);
        if (!$data) return null;
        
        // Check TTL
        if (!empty($data['expires_at']) && time() > $data['expires_at']) {
            @unlink($file);
            return null;
        }
        
        return $data['value'] ?? null;
    }
    
    private function setCache($type, $key, $value, $ttl = 86400) {
        $file = $this->cacheDir . '/' . $type . '_' . $key . '.json';
        $data = [
            'value' => $value,
            'cached_at' => date('c'),
            'expires_at' => time() + $ttl
        ];
        @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}

