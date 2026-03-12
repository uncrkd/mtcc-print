<?php
/**
 * Google Maps API Configuration
 * MTCC Print Services — Courier App
 * 
 * Two keys for security:
 *   BROWSER key — HTTP referrer restricted, used in JavaScript (Maps JS API)
 *   SERVER key  — IP restricted, used in PHP cURL (Routes, Geocoding, Static Maps)
 * 
 * APIs enabled:
 *   - Routes API (distance, duration, waypoint optimization)
 *   - Maps JavaScript API (interactive maps)
 *   - Maps Static API (route preview images)
 *   - Geocoding API (address → coordinates)
 *   - Places API (New) (address autocomplete)
 *   - Maps Embed API (iframe maps, free)
 */

// Browser-side key (HTTP referrer restricted to mtcc.print-stuff.ca)
define('GOOGLE_MAPS_BROWSER_KEY', 'AIzaSyDtsKlcP439gjDYjDOTbd-nd4spGM77fYg');

// Server-side key (IP restricted, used by PHP for Routes/Geocoding/Static Maps)
define('GOOGLE_MAPS_SERVER_KEY', 'AIzaSyD2yXFhEaIYIMywhKuE2OL5mIjyJcr9KQ0');

// Legacy alias — points to server key for backwards compatibility
define('GOOGLE_MAPS_API_KEY', GOOGLE_MAPS_SERVER_KEY);

// Rate limiting: track daily usage to avoid surprise bills
// Google gives $200/month free credit (~40K direction requests)
define('GOOGLE_MAPS_DAILY_LIMIT', 500);
define('GOOGLE_MAPS_CACHE_DIR', __DIR__ . '/../data/maps-cache');

