<?php
/**
 * Weather API Wrapper — Open-Meteo (free, no key required)
 * MTCC Print Services — Courier App
 * 
 * Provides:
 *   - Current conditions (temp, wind, precipitation, weather code)
 *   - 5-day forecast
 *   - Bad weather auto-detection based on dispatch-settings thresholds
 *   - Auto-toggle of bad_weather_active flag
 * 
 * Server path: /courier/weather-api.php
 */

class WeatherAPI {
    
    // MTCC North coordinates (default)
    private $lat = 43.6445;
    private $lng = -79.3871;
    private $timezone = 'America/Toronto';
    private $cacheFile;
    private $cacheTTL = 900; // 15 minutes
    
    public function __construct() {
        $cacheDir = __DIR__ . '/../data/maps-cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
        $this->cacheFile = $cacheDir . '/weather_cache.json';
    }
    
    /**
     * Get current weather + 5-day forecast.
     * Returns cached data if fresh enough.
     */
    public function getWeather() {
        // Check cache
        $cached = $this->getCache();
        if ($cached) return $cached;
        
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $this->lat,
            'longitude' => $this->lng,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,rain,snowfall,weather_code,wind_speed_10m,wind_gusts_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_sum,precipitation_probability_max,wind_speed_10m_max',
            'timezone' => $this->timezone,
            'forecast_days' => 5
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) return null;
        
        $data = json_decode($response, true);
        if (empty($data['current'])) return null;
        
        $result = $this->formatWeatherData($data);
        
        // Cache result
        $this->setCache($result);
        
        return $result;
    }
    
    /**
     * Format raw Open-Meteo response into app-friendly structure.
     */
    private function formatWeatherData($data) {
        $current = $data['current'];
        $daily = $data['daily'] ?? [];
        
        $weatherCode = $current['weather_code'] ?? 0;
        $temp = $current['temperature_2m'] ?? 0;
        $feelsLike = $current['apparent_temperature'] ?? $temp;
        $windSpeed = $current['wind_speed_10m'] ?? 0;
        $windGusts = $current['wind_gusts_10m'] ?? 0;
        $precipitation = $current['precipitation'] ?? 0;
        $rain = $current['rain'] ?? 0;
        $snowfall = $current['snowfall'] ?? 0;
        
        $result = [
            'current' => [
                'temp_c' => round($temp),
                'feels_like_c' => round($feelsLike),
                'wind_kmh' => round($windSpeed),
                'wind_gusts_kmh' => round($windGusts),
                'precipitation_mm' => round($precipitation, 1),
                'rain_mm' => round($rain, 1),
                'snow_mm' => round($snowfall, 1),
                'humidity' => $current['relative_humidity_2m'] ?? 0,
                'weather_code' => $weatherCode,
                'description' => $this->weatherCodeToText($weatherCode),
                'icon' => $this->weatherCodeToIcon($weatherCode),
                'is_day' => $this->isDaytime()
            ],
            'forecast' => [],
            'fetched_at' => date('c')
        ];
        
        // 5-day forecast
        $dayCount = min(5, count($daily['time'] ?? []));
        for ($i = 0; $i < $dayCount; $i++) {
            $code = $daily['weather_code'][$i] ?? 0;
            $result['forecast'][] = [
                'date' => $daily['time'][$i] ?? '',
                'day_name' => $i === 0 ? 'Today' : date('D', strtotime($daily['time'][$i] ?? 'now')),
                'high_c' => round($daily['temperature_2m_max'][$i] ?? 0),
                'low_c' => round($daily['temperature_2m_min'][$i] ?? 0),
                'precip_mm' => round($daily['precipitation_sum'][$i] ?? 0, 1),
                'precip_prob' => $daily['precipitation_probability_max'][$i] ?? 0,
                'wind_max_kmh' => round($daily['wind_speed_10m_max'][$i] ?? 0),
                'weather_code' => $code,
                'description' => $this->weatherCodeToText($code),
                'icon' => $this->weatherCodeToIcon($code)
            ];
        }
        
        return $result;
    }
    
    /**
     * Check if current weather triggers bad weather thresholds.
     * Optionally auto-toggles the bad_weather_active flag.
     */
    public function checkBadWeather($weather = null) {
        if (!$weather) $weather = $this->getWeather();
        if (!$weather) return ['is_bad' => false, 'reasons' => []];
        
        $settingsFile = __DIR__ . '/../data/dispatch-settings.json';
        $settings = file_exists($settingsFile) ? json_decode(file_get_contents($settingsFile), true) : [];
        $thresholds = $settings['weather'] ?? [];
        
        $current = $weather['current'];
        $reasons = [];
        
        // Rain check
        if ($current['rain_mm'] >= ($thresholds['rain_threshold_mm'] ?? 0.5)) {
            $reasons[] = 'Rain: ' . $current['rain_mm'] . ' mm';
        }
        
        // Snow check
        if ($current['snow_mm'] >= ($thresholds['snow_threshold_mm'] ?? 0.5)) {
            $reasons[] = 'Snow: ' . $current['snow_mm'] . ' mm';
        }
        
        // Wind check
        if ($current['wind_kmh'] >= ($thresholds['wind_threshold_kmh'] ?? 40)) {
            $reasons[] = 'Wind: ' . $current['wind_kmh'] . ' km/h';
        }
        
        // Extreme cold check
        if ($current['temp_c'] <= ($thresholds['temp_threshold_c'] ?? -10)) {
            $reasons[] = 'Cold: ' . $current['temp_c'] . "\u{00B0}C";
        }
        
        $isBad = count($reasons) > 0;
        
        // Auto-toggle if enabled
        if (!empty($thresholds['auto_toggle_enabled'])) {
            $currentlyActive = !empty($settings['weather']['bad_weather_active']);
            
            if ($isBad && !$currentlyActive) {
                $settings['weather']['bad_weather_active'] = true;
                $settings['metadata']['updated_at'] = date('c');
                file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            } elseif (!$isBad && $currentlyActive) {
                $settings['weather']['bad_weather_active'] = false;
                $settings['metadata']['updated_at'] = date('c');
                file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
        
        return [
            'is_bad' => $isBad,
            'reasons' => $reasons,
            'bonus_active' => $isBad || !empty($settings['weather']['bad_weather_active']),
            'bonus_amount' => $settings['pricing']['modifiers']['bad_weather'] ?? 5
        ];
    }
    
    /**
     * WMO weather code → human-readable text.
     */
    private function weatherCodeToText($code) {
        $codes = [
            0 => 'Clear sky',
            1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
            45 => 'Foggy', 48 => 'Freezing fog',
            51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle',
            56 => 'Freezing drizzle', 57 => 'Heavy freezing drizzle',
            61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain',
            66 => 'Freezing rain', 67 => 'Heavy freezing rain',
            71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow',
            77 => 'Snow grains',
            80 => 'Light showers', 81 => 'Showers', 82 => 'Heavy showers',
            85 => 'Snow showers', 86 => 'Heavy snow showers',
            95 => 'Thunderstorm', 96 => 'Thunderstorm + hail', 99 => 'Thunderstorm + heavy hail'
        ];
        return $codes[$code] ?? 'Unknown';
    }
    
    /**
     * WMO weather code → emoji icon.
     */
    private function weatherCodeToIcon($code) {
        if ($code === 0) return "\u{2600}\u{FE0F}";            // ☀️
        if ($code <= 2) return "\u{26C5}";                      // ⛅
        if ($code === 3) return "\u{2601}\u{FE0F}";            // ☁️
        if ($code <= 48) return "\u{1F32B}\u{FE0F}";           // 🌫️
        if ($code <= 57) return "\u{1F327}\u{FE0F}";           // 🌧️
        if ($code <= 65) return "\u{1F327}\u{FE0F}";           // 🌧️
        if ($code <= 67) return "\u{1F9CA}";                    // 🧊
        if ($code <= 77) return "\u{1F328}\u{FE0F}";           // 🌨️
        if ($code <= 82) return "\u{1F326}\u{FE0F}";           // 🌦️
        if ($code <= 86) return "\u{1F328}\u{FE0F}";           // 🌨️
        return "\u{26A1}";                                       // ⚡
    }
    
    /**
     * Simple daytime check for Toronto.
     */
    private function isDaytime() {
        $hour = (int)date('G');
        return ($hour >= 6 && $hour < 20);
    }
    
    /**
     * Cache helpers.
     */
    private function getCache() {
        if (!file_exists($this->cacheFile)) return null;
        $data = json_decode(file_get_contents($this->cacheFile), true);
        if (!$data || !isset($data['expires_at'])) return null;
        if (time() > $data['expires_at']) return null;
        return $data['value'] ?? null;
    }
    
    private function setCache($value) {
        $data = [
            'value' => $value,
            'cached_at' => date('c'),
            'expires_at' => time() + $this->cacheTTL
        ];
        @file_put_contents($this->cacheFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }
}
