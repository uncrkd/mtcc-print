<?php
/**
 * Site Settings Helper
 * MTCC Print Services
 *
 * Location: /includes/site-settings.php
 * Reads configurable settings from data/site-settings.json
 */

/**
 * Load site settings with defaults.
 *
 * @return array Settings array
 */
function getSiteSettings() {
    static $cached = null;
    if ($cached !== null) return $cached;

    $defaults = [
        'mtcc_commission_rate' => 0.10,
        'mtcc_contact_email' => '',
        'mtcc_digest_enabled' => false,
    ];

    $paths = [
        __DIR__ . '/../data/site-settings.json',
        __DIR__ . '/data/site-settings.json',
    ];

    foreach ($paths as $path) {
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if ($data) {
                $cached = array_merge($defaults, $data);
                return $cached;
            }
        }
    }

    $cached = $defaults;
    return $cached;
}

/**
 * Save site settings.
 *
 * @param array $settings Settings to save (merged with existing)
 * @return bool Success
 */
function saveSiteSettings($settings) {
    $path = __DIR__ . '/../data/site-settings.json';
    $existing = getSiteSettings();
    $merged = array_merge($existing, $settings);
    $merged['last_updated'] = date('c');

    $result = file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT), LOCK_EX);
    if ($result !== false) {
        // Clear static cache
        $fn = new ReflectionFunction('getSiteSettings');
        // Can't clear static easily, but next request will pick it up
    }
    return $result !== false;
}
