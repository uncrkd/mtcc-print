<?php
/**
 * Production Handlers - Fulfillment Batch Operations
 * MTCC Print Services
 *
 * Location: /includes/production-handlers.php
 * Extracted from: admin/production.php
 */

function loadFulfillmentBatches($file) {
    if (!file_exists($file)) return ['batches' => [], 'metadata' => ['last_batch_number' => 0, 'last_updated' => null, 'version' => '1.0']];
    $data = json_decode(file_get_contents($file), true);
    return $data ?: ['batches' => [], 'metadata' => ['last_batch_number' => 0, 'last_updated' => null, 'version' => '1.0']];
}

function saveFulfillmentBatches($data, $file) {
    $data['metadata']['last_updated'] = date('c');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function createFulfillmentBatch($postData, $file) {
    $refs = json_decode($postData['order_refs'] ?? '[]', true);
    $label = trim($postData['label'] ?? '');
    $notes = trim($postData['notes'] ?? '');

    if (empty($refs) || count($refs) < 1) return ['success' => false, 'error' => 'Select at least one order'];
    if (empty($label)) return ['success' => false, 'error' => 'Batch label is required'];

    $data = loadFulfillmentBatches($file);

    // Check for orders already in a batch
    $alreadyBatched = [];
    foreach ($data['batches'] as $batch) {
        if ($batch['status'] === 'cancelled') continue;
        foreach ($batch['order_refs'] as $existingRef) {
            if (in_array($existingRef, $refs)) $alreadyBatched[] = $existingRef;
        }
    }
    if (!empty($alreadyBatched)) {
        return ['success' => false, 'error' => 'Orders already in a batch: ' . implode(', ', $alreadyBatched)];
    }

    $num = ($data['metadata']['last_batch_number'] ?? 0) + 1;
    $data['metadata']['last_batch_number'] = $num;
    $batchId = 'FB-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    $data['batches'][] = [
        'batch_id' => $batchId,
        'label' => $label,
        'notes' => $notes,
        'order_refs' => $refs,
        'order_count' => count($refs),
        'created_at' => date('c'),
        'created_by' => getCurrentAdminName() ?? 'Admin',
        'status' => 'created',
        'vendor_id' => null,
        'vendor_name' => null,
        'pushed_at' => null,
    ];

    saveFulfillmentBatches($data, $file);

    if (function_exists('logAdminActivity')) {
        logAdminActivity('Batch Created', ['batch_id' => $batchId, 'label' => $label, 'orders' => count($refs)]);
    }

    return ['success' => true, 'message' => 'Batch "' . $label . '" created with ' . count($refs) . ' order(s)', 'batch_id' => $batchId];
}

function removeFromFulfillmentBatch($postData, $file) {
    $ref = $postData['reference_code'] ?? '';
    if (empty($ref)) return ['success' => false, 'error' => 'Reference code required'];

    $data = loadFulfillmentBatches($file);
    foreach ($data['batches'] as &$batch) {
        if ($batch['status'] === 'cancelled') continue;
        $idx = array_search($ref, $batch['order_refs']);
        if ($idx !== false) {
            array_splice($batch['order_refs'], $idx, 1);
            $batch['order_count'] = count($batch['order_refs']);
            if (count($batch['order_refs']) === 0) $batch['status'] = 'cancelled';
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => 'Removed from batch'];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Order not found in any batch'];
}

function addToFulfillmentBatch($postData, $file) {
    $ref = $postData['reference_code'] ?? '';
    $batchId = $postData['batch_id'] ?? '';
    if (empty($ref) || empty($batchId)) return ['success' => false, 'error' => 'Reference code and batch ID required'];

    $data = loadFulfillmentBatches($file);

    // Check order isn't already in a batch
    foreach ($data['batches'] as $check) {
        if ($check['status'] === 'cancelled') continue;
        if (in_array($ref, $check['order_refs'])) {
            return ['success' => false, 'error' => $ref . ' is already in batch ' . $check['batch_id']];
        }
    }

    // Find target batch and add
    foreach ($data['batches'] as &$batch) {
        if ($batch['batch_id'] === $batchId && $batch['status'] !== 'cancelled') {
            $batch['order_refs'][] = $ref;
            $batch['order_count'] = count($batch['order_refs']);
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => $ref . ' added to ' . $batchId];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Batch not found'];
}

function deleteFulfillmentBatch($postData, $file) {
    $batchId = $postData['batch_id'] ?? '';
    if (empty($batchId)) return ['success' => false, 'error' => 'Batch ID required'];

    $data = loadFulfillmentBatches($file);
    foreach ($data['batches'] as &$batch) {
        if ($batch['batch_id'] === $batchId) {
            $batch['status'] = 'cancelled';
            $batch['cancelled_at'] = date('c');
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => 'Batch cancelled'];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Batch not found'];
}

function editFulfillmentBatch($postData, $file) {
    $batchId = $postData['batch_id'] ?? '';
    $label = trim($postData['label'] ?? '');
    $notes = trim($postData['notes'] ?? '');
    if (empty($batchId)) return ['success' => false, 'error' => 'Batch ID required'];

    $data = loadFulfillmentBatches($file);
    foreach ($data['batches'] as &$batch) {
        if ($batch['batch_id'] === $batchId && $batch['status'] !== 'cancelled') {
            $batch['label'] = $label;
            $batch['notes'] = $notes;
            $batch['updated_at'] = date('c');
            saveFulfillmentBatches($data, $file);
            return ['success' => true, 'message' => 'Batch updated'];
        }
    }
    unset($batch);
    return ['success' => false, 'error' => 'Batch not found'];
}

function getOrderBatch($ref, $batchesData) {
    foreach ($batchesData['batches'] as $batch) {
        if ($batch['status'] === 'cancelled') continue;
        if (in_array($ref, $batch['order_refs'])) return $batch;
    }
    return null;
}

function generateBatchSuggestions($unbatchedOrders) {
    if (count($unbatchedOrders) < 2) return [];

    $suggestions = [];
    $usedRefs = [];

    // Strategy 1: Same event + same due date (strongest signal)
    $byEventDate = [];
    foreach ($unbatchedOrders as $order) {
        $ref = $order['referenceCode'];
        $event = explode('-', $ref)[0] ?? '';
        $date = $order['selectedDate'] ?? '';
        if ($event && $date) {
            $key = $event . '|' . $date;
            $byEventDate[$key][] = $order;
        }
    }
    foreach ($byEventDate as $key => $orders) {
        if (count($orders) < 2) continue;
        list($event, $date) = explode('|', $key);
        $dateFormatted = date('D M j', strtotime($date));
        $refs = array_column($orders, 'referenceCode');
        $suggestions[] = [
            'type' => 'event_date',
            'icon' => '&#128197;',
            'title' => count($refs) . ' ' . $event . ' orders due ' . $dateFormatted,
            'description' => 'Same event, same deadline — batch for one vendor push',
            'refs' => $refs,
            'score' => 90 + count($refs) * 3,
            'auto_label' => $event . ' ' . $dateFormatted,
        ];
    }

    // Strategy 2: Same material (fabric vs paper)
    $byMaterial = [];
    foreach ($unbatchedOrders as $order) {
        $mat = strtolower($order['material'] ?? 'poster');
        $byMaterial[$mat][] = $order;
    }
    foreach ($byMaterial as $mat => $orders) {
        if (count($orders) < 2) continue;
        if ($mat === 'poster' || $mat === 'paper') continue; // Only suggest for non-default materials
        $refs = array_column($orders, 'referenceCode');
        // Skip if all already in an event_date suggestion
        $newRefs = array_diff($refs, $usedRefs);
        if (count($newRefs) < 2) continue;
        $suggestions[] = [
            'type' => 'material',
            'icon' => '&#127912;',
            'title' => count($refs) . ' ' . ucfirst($mat) . ' orders',
            'description' => 'Same material — send to specialist vendor for best pricing',
            'refs' => $refs,
            'score' => 75 + count($refs) * 2,
            'auto_label' => ucfirst($mat) . ' batch',
        ];
    }

    // Strategy 3: Urgency cluster (sameday + nextday)
    $urgentOrders = array_filter($unbatchedOrders, function($o) {
        return in_array($o['pricing']['tier'] ?? '', ['sameday', 'nextday']);
    });
    if (count($urgentOrders) >= 2) {
        $refs = array_column($urgentOrders, 'referenceCode');
        $suggestions[] = [
            'type' => 'urgent',
            'icon' => '&#9888;',
            'title' => count($refs) . ' urgent orders',
            'description' => 'Same-day and next-day orders — batch and push immediately',
            'refs' => $refs,
            'score' => 85 + count($refs) * 3,
            'auto_label' => 'Urgent ' . date('M j'),
        ];
    }

    // Strategy 4: Same size range (within 10" tolerance on both dimensions)
    $sizeGroups = [];
    foreach ($unbatchedOrders as $order) {
        $w = intval($order['dimensions']['width'] ?? 0);
        $h = intval($order['dimensions']['height'] ?? 0);
        // Normalize: smaller dimension first
        $dim = [min($w, $h), max($w, $h)];
        $placed = false;
        foreach ($sizeGroups as &$group) {
            $gw = $group['dim'][0]; $gh = $group['dim'][1];
            if (abs($dim[0] - $gw) <= 10 && abs($dim[1] - $gh) <= 10) {
                $group['orders'][] = $order;
                $placed = true;
                break;
            }
        }
        unset($group);
        if (!$placed) {
            $sizeGroups[] = ['dim' => $dim, 'orders' => [$order]];
        }
    }
    foreach ($sizeGroups as $group) {
        if (count($group['orders']) < 3) continue; // Only suggest size batches with 3+
        $refs = array_column($group['orders'], 'referenceCode');
        $sizeLabel = $group['dim'][0] . '"\u00d7' . $group['dim'][1] . '" range';
        $suggestions[] = [
            'type' => 'size',
            'icon' => '&#128208;',
            'title' => count($refs) . ' similar-size orders (' . $sizeLabel . ')',
            'description' => 'Same size range — efficient machine setup, less material waste',
            'refs' => $refs,
            'score' => 60 + count($refs) * 2,
            'auto_label' => 'Size batch ' . $group['dim'][0] . 'x' . $group['dim'][1],
        ];
    }

    // Deduplicate: remove orders from lower-scored suggestions if in higher ones
    usort($suggestions, function($a, $b) { return $b['score'] - $a['score']; });
    $deduped = [];
    foreach ($suggestions as $s) {
        $available = array_diff($s['refs'], $usedRefs);
        if (count($available) < 2) continue;
        if (count($available) < count($s['refs'])) {
            $s['refs'] = array_values($available);
            $s['title'] = preg_replace('/^\d+/', count($available), $s['title']);
        }
        $deduped[] = $s;
        $usedRefs = array_merge($usedRefs, $s['refs']);
    }

    return array_slice($deduped, 0, 4); // Max 4 suggestions
}
