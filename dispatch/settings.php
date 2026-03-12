<?php
/**
 * Dispatch Settings
 * Configure pricing, weather thresholds, urgency, and notification settings
 * Location: /dispatch/settings.php
 */

require_once __DIR__ . '/../admin-auth.php';
requirePermission('dispatch');

require_once __DIR__ . '/dispatch-functions.php';

// ============================================
// HANDLE SAVE
// ============================================
$saveMessage = '';
$saveError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_section'])) {
    $settings = dispatch_loadSettings();
    $section = $_POST['save_section'];

    switch ($section) {
        case 'pricing':
            $settings['pricing']['base_rate'] = max(0, floatval($_POST['base_rate'] ?? 30));
            $settings['pricing']['modifiers']['additional_stop'] = max(0, floatval($_POST['mod_additional_stop'] ?? 10));
            $settings['pricing']['modifiers']['per_km_over_threshold'] = max(0, floatval($_POST['mod_per_km'] ?? 0.35));
            $settings['pricing']['modifiers']['rush'] = max(0, floatval($_POST['mod_rush'] ?? 8));
            $settings['pricing']['modifiers']['extended_distance'] = max(0, floatval($_POST['mod_extended'] ?? 8));
            $settings['pricing']['modifiers']['bad_weather'] = max(0, floatval($_POST['mod_bad_weather'] ?? 5));
            $settings['pricing']['distance_threshold_km'] = max(1, intval($_POST['distance_threshold'] ?? 50));
            $settings['pricing']['extended_distance_threshold_km'] = max(1, intval($_POST['extended_threshold'] ?? 35));
            $saveMessage = 'Pricing settings saved.';
            break;

        case 'weather':
            $settings['weather']['rain_threshold_mm'] = max(0, floatval($_POST['rain_threshold'] ?? 0.5));
            $settings['weather']['snow_threshold_mm'] = max(0, floatval($_POST['snow_threshold'] ?? 0.5));
            $settings['weather']['wind_threshold_kmh'] = max(0, intval($_POST['wind_threshold'] ?? 40));
            $settings['weather']['temp_threshold_c'] = intval($_POST['temp_threshold'] ?? -10);
            $settings['weather']['auto_toggle_enabled'] = isset($_POST['auto_toggle']);
            $saveMessage = 'Weather settings saved.';
            break;

        case 'urgency':
            $settings['urgency']['urgent_hours'] = max(1, intval($_POST['urgent_hours'] ?? 3));
            $settings['urgency']['priority_hours'] = max(1, intval($_POST['priority_hours'] ?? 5));
            $settings['urgency']['auto_bonus_urgent'] = max(0, floatval($_POST['bonus_urgent'] ?? 10));
            $settings['urgency']['auto_bonus_priority'] = max(0, floatval($_POST['bonus_priority'] ?? 8));

            if ($settings['urgency']['priority_hours'] <= $settings['urgency']['urgent_hours']) {
                $saveError = 'Priority hours must be greater than urgent hours.';
                break;
            }
            $saveMessage = 'Urgency settings saved.';
            break;

        case 'notifications':
            $settings['notifications']['poll_interval_seconds'] = max(10, intval($_POST['poll_interval'] ?? 30));
            $settings['notifications']['max_notifications'] = max(10, intval($_POST['max_notifications'] ?? 50));
            $saveMessage = 'Notification settings saved.';
            break;
    }

    if ($saveMessage && !$saveError) {
        dispatch_saveSettings($settings);
        if (function_exists('logAdminActivity')) {
            logAdminActivity('Dispatch Settings Updated', ['section' => $section], 'settings');
        }
    }
}

// Load current settings
$settings = dispatch_loadSettings();
$pricing = $settings['pricing'] ?? [];
$weather = $settings['weather'] ?? [];
$urgency = $settings['urgency'] ?? [];
$notif = $settings['notifications'] ?? [];
$meta = $settings['metadata'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dispatch Settings - MTCC Print Services</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-components.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="dispatch-hub.css">
    <style>
        .settings-page-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: var(--radius);
            padding: 14px 24px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm);
            border: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .settings-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #374151;
            margin: 0;
        }
        .settings-subtitle {
            font-size: 0.78rem;
            color: var(--subtext);
            margin-top: 2px;
        }
        .settings-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #374151;
            background: #f3f4f6;
            border: 1px solid #e5e7eb;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .settings-back:hover {
            background: #e5e7eb;
            color: var(--primary);
        }

        .settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 900px) {
            .settings-grid { grid-template-columns: 1fr; }
        }

        .settings-card {
            background: #fff;
            border-radius: var(--radius);
            border: 1px solid #e5e7eb;
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .settings-card-header {
            padding: 14px 20px;
            background: #f8fafc;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .settings-card-icon {
            font-size: 1.2rem;
        }
        .settings-card-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #374151;
            margin: 0;
        }
        .settings-card-desc {
            font-size: 0.72rem;
            color: var(--subtext);
            margin: 0;
        }
        .settings-card-body {
            padding: 16px 20px;
        }

        .setting-group {
            margin-bottom: 14px;
        }
        .setting-group:last-child {
            margin-bottom: 0;
        }
        .setting-group-label {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--subtext);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 8px;
        }
        .setting-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 0;
            border-bottom: 1px solid #f8fafc;
            gap: 12px;
        }
        .setting-row:last-child {
            border-bottom: none;
        }
        .setting-label {
            font-size: 0.82rem;
            font-weight: 500;
            color: #374151;
            flex: 1;
        }
        .setting-hint {
            font-size: 0.68rem;
            color: #9ca3af;
            display: block;
            margin-top: 1px;
        }
        .setting-input {
            width: 100px;
            padding: 6px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.82rem;
            font-weight: 500;
            text-align: right;
            background: #fff;
            font-family: 'Montserrat', sans-serif;
        }
        .setting-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-light);
        }
        .setting-input-sm {
            width: 72px;
        }
        .setting-unit {
            font-size: 0.72rem;
            color: var(--subtext);
            font-weight: 500;
            min-width: 36px;
        }

        .setting-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .setting-toggle input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }

        .settings-card-footer {
            padding: 12px 20px;
            border-top: 1px solid #e5e7eb;
            background: #fafafa;
            text-align: right;
        }
        .settings-save-btn {
            padding: 8px 20px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
            background: var(--primary);
            color: #fff;
            transition: all 0.15s ease;
        }
        .settings-save-btn:hover {
            background: var(--primary-dark);
        }

        .settings-flash {
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 500;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .settings-flash.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .settings-flash.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .settings-meta {
            text-align: center;
            padding: 12px;
            font-size: 0.72rem;
            color: #9ca3af;
        }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('dispatch_settings'); ?>
<script src="../js/admin-sidebar.js"></script>
<div style="margin: 0 auto; padding: 0 20px;">

    <div class="settings-page-header">
        <div>
            <h1 class="settings-title">&#9881;&#65039; Dispatch Settings</h1>
            <div class="settings-subtitle">Configure pricing, weather, urgency thresholds, and notifications</div>
        </div>
        <a href="./" class="settings-back">&#8592; Back to Hub</a>
    </div>

    <?php if ($saveMessage): ?>
    <div class="settings-flash success">&#9989; <?= htmlspecialchars($saveMessage) ?></div>
    <?php endif; ?>
    <?php if ($saveError): ?>
    <div class="settings-flash error">&#10060; <?= htmlspecialchars($saveError) ?></div>
    <?php endif; ?>

    <div class="settings-grid">

        <!-- ===== PRICING ===== -->
        <form method="POST" class="settings-card">
            <input type="hidden" name="save_section" value="pricing">
            <div class="settings-card-header">
                <span class="settings-card-icon">&#128176;</span>
                <div>
                    <h2 class="settings-card-title">Courier Pricing</h2>
                    <p class="settings-card-desc">Base rates and delivery modifiers</p>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="setting-group">
                    <div class="setting-group-label">Base Rate</div>
                    <div class="setting-row">
                        <div class="setting-label">
                            Base delivery rate
                            <span class="setting-hint">Starting price per delivery</span>
                        </div>
                        <span class="setting-unit">$</span>
                        <input type="number" name="base_rate" class="setting-input" step="0.01" min="0"
                               value="<?= htmlspecialchars($pricing['base_rate'] ?? 30) ?>">
                    </div>
                </div>

                <div class="setting-group">
                    <div class="setting-group-label">Modifiers</div>
                    <div class="setting-row">
                        <span class="setting-label">Additional stop</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="mod_additional_stop" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($pricing['modifiers']['additional_stop'] ?? 10) ?>">
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Per km over threshold</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="mod_per_km" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($pricing['modifiers']['per_km_over_threshold'] ?? 0.35) ?>">
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Rush surcharge</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="mod_rush" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($pricing['modifiers']['rush'] ?? 8) ?>">
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Extended distance</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="mod_extended" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($pricing['modifiers']['extended_distance'] ?? 8) ?>">
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Bad weather bonus</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="mod_bad_weather" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($pricing['modifiers']['bad_weather'] ?? 5) ?>">
                    </div>
                </div>

                <div class="setting-group">
                    <div class="setting-group-label">Distance Thresholds</div>
                    <div class="setting-row">
                        <span class="setting-label">Standard distance threshold</span>
                        <input type="number" name="distance_threshold" class="setting-input setting-input-sm" min="1"
                               value="<?= htmlspecialchars($pricing['distance_threshold_km'] ?? 50) ?>">
                        <span class="setting-unit">km</span>
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Extended distance threshold</span>
                        <input type="number" name="extended_threshold" class="setting-input setting-input-sm" min="1"
                               value="<?= htmlspecialchars($pricing['extended_distance_threshold_km'] ?? 35) ?>">
                        <span class="setting-unit">km</span>
                    </div>
                </div>
            </div>
            <div class="settings-card-footer">
                <button type="submit" class="settings-save-btn">Save Pricing</button>
            </div>
        </form>

        <!-- ===== WEATHER ===== -->
        <form method="POST" class="settings-card">
            <input type="hidden" name="save_section" value="weather">
            <div class="settings-card-header">
                <span class="settings-card-icon">&#127782;&#65039;</span>
                <div>
                    <h2 class="settings-card-title">Weather Thresholds</h2>
                    <p class="settings-card-desc">Triggers for bad weather alerts and courier bonuses</p>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="setting-group">
                    <div class="setting-group-label">Alert Thresholds</div>
                    <div class="setting-row">
                        <span class="setting-label">Rain threshold</span>
                        <input type="number" name="rain_threshold" class="setting-input setting-input-sm" step="0.1" min="0"
                               value="<?= htmlspecialchars($weather['rain_threshold_mm'] ?? 0.5) ?>">
                        <span class="setting-unit">mm</span>
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Snow threshold</span>
                        <input type="number" name="snow_threshold" class="setting-input setting-input-sm" step="0.1" min="0"
                               value="<?= htmlspecialchars($weather['snow_threshold_mm'] ?? 0.5) ?>">
                        <span class="setting-unit">mm</span>
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Wind threshold</span>
                        <input type="number" name="wind_threshold" class="setting-input setting-input-sm" min="0"
                               value="<?= htmlspecialchars($weather['wind_threshold_kmh'] ?? 40) ?>">
                        <span class="setting-unit">km/h</span>
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Temperature threshold</span>
                        <input type="number" name="temp_threshold" class="setting-input setting-input-sm"
                               value="<?= htmlspecialchars($weather['temp_threshold_c'] ?? -10) ?>">
                        <span class="setting-unit">&deg;C</span>
                    </div>
                </div>

                <div class="setting-group">
                    <div class="setting-group-label">Automation</div>
                    <div class="setting-row">
                        <span class="setting-label">
                            Auto-toggle weather bonus
                            <span class="setting-hint">Automatically enable bonus when thresholds exceeded</span>
                        </span>
                        <div class="setting-toggle">
                            <input type="checkbox" name="auto_toggle" id="autoToggle"
                                   <?= ($weather['auto_toggle_enabled'] ?? true) ? 'checked' : '' ?>>
                            <label for="autoToggle" style="font-size:0.78rem;color:#374151;cursor:pointer;">Enabled</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="settings-card-footer">
                <button type="submit" class="settings-save-btn">Save Weather</button>
            </div>
        </form>

        <!-- ===== URGENCY ===== -->
        <form method="POST" class="settings-card">
            <input type="hidden" name="save_section" value="urgency">
            <div class="settings-card-header">
                <span class="settings-card-icon">&#9200;</span>
                <div>
                    <h2 class="settings-card-title">Urgency Levels</h2>
                    <p class="settings-card-desc">Time thresholds and automatic bonus amounts</p>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="setting-group">
                    <div class="setting-group-label">Time Windows</div>
                    <div class="setting-row">
                        <div class="setting-label">
                            Urgent threshold
                            <span class="setting-hint">Orders due within this many hours show as URGENT</span>
                        </div>
                        <input type="number" name="urgent_hours" class="setting-input setting-input-sm" min="1"
                               value="<?= htmlspecialchars($urgency['urgent_hours'] ?? 3) ?>">
                        <span class="setting-unit">hrs</span>
                    </div>
                    <div class="setting-row">
                        <div class="setting-label">
                            Priority threshold
                            <span class="setting-hint">Orders due within this many hours show as PRIORITY</span>
                        </div>
                        <input type="number" name="priority_hours" class="setting-input setting-input-sm" min="1"
                               value="<?= htmlspecialchars($urgency['priority_hours'] ?? 5) ?>">
                        <span class="setting-unit">hrs</span>
                    </div>
                </div>

                <div class="setting-group">
                    <div class="setting-group-label">Auto-Bonus</div>
                    <div class="setting-row">
                        <span class="setting-label">Urgent delivery bonus</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="bonus_urgent" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($urgency['auto_bonus_urgent'] ?? 10) ?>">
                    </div>
                    <div class="setting-row">
                        <span class="setting-label">Priority delivery bonus</span>
                        <span class="setting-unit">+$</span>
                        <input type="number" name="bonus_priority" class="setting-input setting-input-sm" step="0.01" min="0"
                               value="<?= htmlspecialchars($urgency['auto_bonus_priority'] ?? 8) ?>">
                    </div>
                </div>
            </div>
            <div class="settings-card-footer">
                <button type="submit" class="settings-save-btn">Save Urgency</button>
            </div>
        </form>

        <!-- ===== NOTIFICATIONS ===== -->
        <form method="POST" class="settings-card">
            <input type="hidden" name="save_section" value="notifications">
            <div class="settings-card-header">
                <span class="settings-card-icon">&#128276;</span>
                <div>
                    <h2 class="settings-card-title">Notifications</h2>
                    <p class="settings-card-desc">Polling frequency and notification limits</p>
                </div>
            </div>
            <div class="settings-card-body">
                <div class="setting-group">
                    <div class="setting-row">
                        <div class="setting-label">
                            Poll interval
                            <span class="setting-hint">How often the Hub checks for updates</span>
                        </div>
                        <input type="number" name="poll_interval" class="setting-input setting-input-sm" min="10"
                               value="<?= htmlspecialchars($notif['poll_interval_seconds'] ?? 30) ?>">
                        <span class="setting-unit">sec</span>
                    </div>
                    <div class="setting-row">
                        <div class="setting-label">
                            Max notifications
                            <span class="setting-hint">Maximum stored notifications before oldest are removed</span>
                        </div>
                        <input type="number" name="max_notifications" class="setting-input setting-input-sm" min="10"
                               value="<?= htmlspecialchars($notif['max_notifications'] ?? 50) ?>">
                        <span class="setting-unit"></span>
                    </div>
                </div>
            </div>
            <div class="settings-card-footer">
                <button type="submit" class="settings-save-btn">Save Notifications</button>
            </div>
        </form>

    </div>

    <div class="settings-meta">
        Settings v<?= htmlspecialchars($meta['version'] ?? '1.0') ?>
        &middot; Last updated: <?= !empty($meta['updated_at']) ? date('M j, Y g:i A', strtotime($meta['updated_at'])) : 'Never' ?>
    </div>

</div>
</body>
</html>
