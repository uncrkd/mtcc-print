<?php
/**
 * One-time migration: backfill missing deliveryTime + event.building on old orders
 *
 * - deliveryTime missing or empty → set to "anytime"
 * - event.building missing/empty → look up from admin/events.json by acronym
 *
 * Run from browser: /admin/migrate-orders-backfill.php
 * Append ?apply=1 to actually write changes. Without that flag, runs in dry-run mode.
 *
 * Requires: super_admin or god_mode.
 */

require_once __DIR__ . '/../admin-auth.php';

// Restrict to high-privilege users only
if (!in_array($_SESSION['admin_role'] ?? '', ['god_mode', 'super_admin'])) {
    http_response_code(403);
    die('Access denied. super_admin required.');
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';

// Load event acronym → building map (active + archived)
$eventsFile = __DIR__ . '/events.json';
$eventsData = file_exists($eventsFile) ? (json_decode(file_get_contents($eventsFile), true) ?: []) : [];
$allEvents = array_merge($eventsData['active'] ?? [], $eventsData['archived'] ?? []);

$buildingByPrefix = [];
foreach ($allEvents as $ev) {
    $prefix = strtoupper($ev['acronym'] ?? '');
    if (!$prefix) continue;
    $b = strtolower($ev['building'] ?? '');
    if ($b) $buildingByPrefix[$prefix] = $b;
}

// Manual overrides — prefixes not in events.json but with known buildings
$manualOverrides = [
    'AAIC' => 'north',
];
foreach ($manualOverrides as $prefix => $building) {
    if (!isset($buildingByPrefix[$prefix])) {
        $buildingByPrefix[$prefix] = $building;
    }
}

// Walk order files
$orderDir = __DIR__ . '/../uploads/orders/';
$orderFiles = glob($orderDir . '*.json');

$report = [
    'total' => 0,
    'skipped' => 0,
    'updated_time' => 0,
    'normalized_time' => 0,
    'updated_building' => 0,
    'updated_both' => 0,
    'building_unknown' => 0,
    'changes' => [],
];

// Valid deliveryTime values — anything else gets normalized to "anytime"
$validTimes = ['9am', '12pm', '3pm', '6pm', 'anytime'];

foreach ($orderFiles as $file) {
    if (strpos($file, '_history.json') !== false) continue;

    $content = file_get_contents($file);
    $order = json_decode($content, true);
    if (!$order || !isset($order['referenceCode'])) continue;

    $report['total']++;
    $ref = $order['referenceCode'];
    $prefix = strtoupper(explode('-', $ref)[0]);
    $changed = [];

    // 1. Backfill or normalize deliveryTime
    $currentTime = $order['deliveryTime'] ?? null;
    if (empty($currentTime)) {
        $order['deliveryTime'] = 'anytime';
        $changed[] = 'deliveryTime=anytime (was missing)';
        $report['updated_time']++;
    } elseif (!in_array($currentTime, $validTimes, true)) {
        $order['deliveryTime'] = 'anytime';
        $changed[] = 'deliveryTime=anytime (normalized from "' . $currentTime . '")';
        $report['normalized_time']++;
    }

    // 2. Backfill event.building
    $currentBuilding = $order['event']['building'] ?? $order['building'] ?? '';
    if (empty($currentBuilding)) {
        if (isset($buildingByPrefix[$prefix])) {
            // Ensure event struct exists
            if (!isset($order['event']) || !is_array($order['event'])) $order['event'] = [];
            $order['event']['building'] = $buildingByPrefix[$prefix];
            $changed[] = 'event.building=' . $buildingByPrefix[$prefix];
            $report['updated_building']++;
        } else {
            $report['building_unknown']++;
            $changed[] = 'event.building=UNKNOWN (prefix "' . $prefix . '" not in events.json)';
        }
    }

    if (empty($changed)) {
        $report['skipped']++;
        continue;
    }
    if (count($changed) >= 2) $report['updated_both']++;

    $report['changes'][] = [
        'ref' => $ref,
        'file' => basename($file),
        'changes' => $changed,
    ];

    // Only write if --apply mode
    if ($apply) {
        // Safety: only write when building was actually resolved OR when only deliveryTime changed
        $hasUnknownBuilding = false;
        foreach ($changed as $c) {
            if (strpos($c, 'UNKNOWN') !== false) { $hasUnknownBuilding = true; break; }
        }
        if (!$hasUnknownBuilding) {
            file_put_contents($file, json_encode($order, JSON_PRETTY_PRINT), LOCK_EX);
        }
    }
}

// Output HTML report
?><!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Orders Backfill Migration</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; max-width: 960px; margin: 20px auto; padding: 0 20px; color: #1e1b2e; }
        h1 { color: #7c3aed; }
        .mode { display: inline-block; padding: 4px 12px; border-radius: 4px; font-weight: 700; }
        .mode.dry { background: #fef3c7; color: #92400e; }
        .mode.apply { background: #dcfce7; color: #166534; }
        .stats { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; margin: 20px 0; }
        .stat { background: white; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; }
        .stat-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; color: #6b7280; }
        .stat-value { font-size: 1.4rem; font-weight: 700; color: #1e1b2e; margin-top: 4px; }
        .stat-value.warn { color: #d97706; }
        .stat-value.ok { color: #059669; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-top: 16px; }
        th { text-align: left; padding: 8px 12px; background: #f9fafb; border-bottom: 2px solid #e5e7eb; font-size: 0.72rem; text-transform: uppercase; color: #6b7280; }
        td { padding: 8px 12px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .ref { font-family: monospace; font-weight: 700; color: #7c3aed; }
        .change { display: inline-block; padding: 1px 6px; background: #ede9fe; color: #5b21b6; border-radius: 3px; font-size: 0.75rem; margin: 1px 2px; }
        .change.unknown { background: #fef2f2; color: #991b1b; }
        .actions { margin: 20px 0; }
        .btn { display: inline-block; padding: 10px 20px; background: #7c3aed; color: white; border-radius: 8px; text-decoration: none; font-weight: 600; margin-right: 8px; }
        .btn.secondary { background: #6b7280; }
        .btn.danger { background: #dc2626; }
        .warning { background: #fef3c7; border-left: 4px solid #d97706; padding: 12px 16px; margin: 16px 0; border-radius: 4px; color: #78350f; }
    </style>
</head>
<body>
    <h1>Orders Backfill Migration</h1>
    <p class="mode <?= $apply ? 'apply' : 'dry' ?>">
        <?= $apply ? 'APPLIED (changes written to disk)' : 'DRY RUN (no changes made)' ?>
    </p>

    <?php if (!$apply): ?>
    <div class="warning">
        <strong>Dry run mode.</strong> The list below shows what WOULD be changed. No files have been modified.
        Review carefully, then re-run with <code>?apply=1</code> to execute.
    </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="stat-label">Orders Scanned</div><div class="stat-value"><?= $report['total'] ?></div></div>
        <div class="stat"><div class="stat-label">Already OK (skipped)</div><div class="stat-value ok"><?= $report['skipped'] ?></div></div>
        <div class="stat"><div class="stat-label">Missing deliveryTime</div><div class="stat-value"><?= $report['updated_time'] ?></div></div>
        <div class="stat"><div class="stat-label">Invalid deliveryTime</div><div class="stat-value"><?= $report['normalized_time'] ?></div></div>
        <div class="stat"><div class="stat-label">Missing Building</div><div class="stat-value"><?= $report['updated_building'] ?></div></div>
        <div class="stat"><div class="stat-label">Building Unknown</div><div class="stat-value <?= $report['building_unknown'] > 0 ? 'warn' : 'ok' ?>"><?= $report['building_unknown'] ?></div></div>
    </div>

    <div class="actions">
        <?php if ($apply): ?>
            <a href="../admin-orders.php" class="btn">← Back to Dashboard</a>
            <a href="migrate-orders-backfill.php" class="btn secondary">Re-run (Dry Run)</a>
        <?php else: ?>
            <a href="../admin-orders.php" class="btn secondary">← Cancel</a>
            <a href="migrate-orders-backfill.php?apply=1" class="btn danger" onclick="return confirm('Apply migration? This will modify order files on disk.')">Apply Migration</a>
        <?php endif; ?>
    </div>

    <h2>Orders Needing Changes (<?= count($report['changes']) ?>)</h2>

    <?php if (empty($report['changes'])): ?>
        <p>No orders need updating. All orders have deliveryTime and building set.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>File</th>
                    <th>Changes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['changes'] as $c): ?>
                <tr>
                    <td class="ref"><?= htmlspecialchars($c['ref']) ?></td>
                    <td style="font-size: 0.78rem; color: #6b7280;"><?= htmlspecialchars($c['file']) ?></td>
                    <td>
                        <?php foreach ($c['changes'] as $ch): ?>
                            <span class="change <?= strpos($ch, 'UNKNOWN') !== false ? 'unknown' : '' ?>"><?= htmlspecialchars($ch) ?></span>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($report['building_unknown'] > 0): ?>
    <div class="warning" style="margin-top: 20px;">
        <strong><?= $report['building_unknown'] ?> orders have a prefix that doesn't match any event in events.json.</strong><br>
        These orders will NOT have their building set by this migration. If you want to handle them, add the missing events to
        <code>admin/events.json</code> with their building, then re-run.
    </div>
    <?php endif; ?>

</body>
</html>
