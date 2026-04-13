<?php
/**
 * Centralized Status Configuration
 * MTCC Print Services
 *
 * Location: /includes/status-config.php
 *
 * SINGLE SOURCE OF TRUTH for all status labels, colors, and icons.
 * Every PHP file that renders statuses should use these functions.
 *
 * Roles: admin, courier, mtcc_staff, vendor, customer
 */

/**
 * Master status definitions.
 * Each status has: color (hex), phase, and role-specific labels.
 */
function getStatusConfig() {
    return [
        'unpaid' => [
            'color' => '#eab308',
            'phase' => 'payment',
            'labels' => [
                'admin'      => 'Unpaid',
                'courier'    => null,
                'mtcc_staff' => null,
                'vendor'     => null,
                'customer'   => 'Awaiting Payment',
            ],
        ],
        'paid' => [
            'color' => '#ca8a04',
            'phase' => 'payment',
            'labels' => [
                'admin'      => 'Paid',
                'courier'    => null,
                'mtcc_staff' => null,
                'vendor'     => null,
                'customer'   => 'Payment Received',
            ],
        ],
        'preflight' => [
            'color' => '#8b5cf6',
            'phase' => 'production',
            'labels' => [
                'admin'      => 'Sent to Vendor',
                'courier'    => null,
                'mtcc_staff' => 'In Production',
                'vendor'     => 'Awaiting Review',
                'customer'   => 'In Production',
            ],
        ],
        'file_issue' => [
            'color' => '#ea580c',
            'phase' => 'production',
            'labels' => [
                'admin'      => 'File Issue',
                'courier'    => null,
                'mtcc_staff' => 'In Production',
                'vendor'     => 'File Issue',
                'customer'   => 'In Production',
            ],
        ],
        'printing' => [
            'color' => '#6366f1',
            'phase' => 'production',
            'labels' => [
                'admin'      => 'Printing',
                'courier'    => null,
                'mtcc_staff' => 'In Production',
                'vendor'     => 'Printing',
                'customer'   => 'Printing',
            ],
        ],
        'ready' => [
            'color' => '#d97706',
            'phase' => 'dispatch',
            'labels' => [
                'admin'      => 'Ready to Ship',
                'courier'    => 'Available',
                'mtcc_staff' => 'Preparing to Ship',
                'vendor'     => 'Ready to Ship',
                'customer'   => 'Preparing to Ship',
            ],
        ],
        'dispatched' => [
            'color' => '#7c3aed',
            'phase' => 'dispatch',
            'labels' => [
                'admin'      => 'Courier Assigned',
                'courier'    => 'Accepted',
                'mtcc_staff' => 'On the Way',
                'vendor'     => null,
                'customer'   => 'On the Way',
            ],
        ],
        'shipped' => [
            'color' => '#14b8a6',
            'phase' => 'delivery',
            'labels' => [
                'admin'      => 'Shipped',
                'courier'    => 'In Transit',
                'mtcc_staff' => 'On the Way',
                'vendor'     => 'Shipped',
                'customer'   => 'On the Way',
            ],
        ],
        'delivered' => [
            'color' => '#059669',
            'phase' => 'completion',
            'labels' => [
                'admin'      => 'Delivered',
                'courier'    => 'Delivered',
                'mtcc_staff' => 'Ready for Pickup',
                'vendor'     => null,
                'customer'   => 'Ready for Pickup',
            ],
        ],
        'pickedup' => [
            'color' => '#22c55e',
            'phase' => 'completion',
            'labels' => [
                'admin'      => 'Picked Up',
                'courier'    => null,
                'mtcc_staff' => 'Picked Up',
                'vendor'     => null,
                'customer'   => 'Complete',
            ],
        ],
        'missing' => [
            'color' => '#dc2626',
            'phase' => 'exception',
            'labels' => [
                'admin'      => 'Missing',
                'courier'    => 'Missing',
                'mtcc_staff' => 'Missing',
                'vendor'     => null,
                'customer'   => 'Under Investigation',
            ],
        ],
        'unclaimed' => [
            'color' => '#e11d48',
            'phase' => 'exception',
            'labels' => [
                'admin'      => 'Unclaimed',
                'courier'    => null,
                'mtcc_staff' => 'Unclaimed',
                'vendor'     => null,
                'customer'   => 'Unclaimed',
            ],
        ],
        'cancelled' => [
            'color' => '#64748b',
            'phase' => 'terminal',
            'labels' => [
                'admin'      => 'Cancelled',
                'courier'    => null,
                'mtcc_staff' => null,
                'vendor'     => 'Cancelled',
                'customer'   => 'Cancelled',
            ],
        ],
        'refunded' => [
            'color' => '#9ca3af',
            'phase' => 'terminal',
            'labels' => [
                'admin'      => 'Refunded',
                'courier'    => null,
                'mtcc_staff' => null,
                'vendor'     => null,
                'customer'   => 'Refunded',
            ],
        ],
    ];
}

/**
 * Get the display label for a status, specific to a role.
 * Returns null if the status should not be visible to that role.
 *
 * @param string $status Internal status code
 * @param string $role   One of: admin, courier, mtcc_staff, vendor, customer
 * @return string|null Display label or null if not visible
 */
function getStatusLabel($status, $role = 'admin') {
    $config = getStatusConfig();
    if (!isset($config[$status])) return $status;
    return $config[$status]['labels'][$role] ?? null;
}

/**
 * Get the color for a status.
 * @param string $status Internal status code
 * @return string Hex color
 */
function getStatusColor($status) {
    $config = getStatusConfig();
    return $config[$status]['color'] ?? '#6b7280';
}

/**
 * Get all status labels for a role (excluding null/hidden statuses).
 * @param string $role One of: admin, courier, mtcc_staff, vendor, customer
 * @return array Map of status_code => display_label
 */
function getStatusLabelsForRole($role = 'admin') {
    $config = getStatusConfig();
    $labels = [];
    foreach ($config as $code => $def) {
        $label = $def['labels'][$role] ?? null;
        if ($label !== null) {
            $labels[$code] = $label;
        }
    }
    return $labels;
}

/**
 * Get all status colors as a flat map.
 * @return array Map of status_code => hex_color
 */
function getStatusColors() {
    $config = getStatusConfig();
    $colors = [];
    foreach ($config as $code => $def) {
        $colors[$code] = $def['color'];
    }
    return $colors;
}

/**
 * Output the status config as a JavaScript object for frontend use.
 * Call this in PHP pages that need status rendering in JS.
 */
function outputStatusConfigScript($role = 'admin') {
    $config = getStatusConfig();
    $jsLabels = [];
    $jsColors = [];
    foreach ($config as $code => $def) {
        // Use role-specific label, falling back to admin label so JS always has a label for every status
        $label = $def['labels'][$role] ?? $def['labels']['admin'] ?? $code;
        $jsLabels[$code] = $label;
        $jsColors[$code] = $def['color'];
    }
    echo '<script>';
    echo 'window.STATUS_LABELS=' . json_encode($jsLabels) . ';';
    echo 'window.STATUS_COLORS=' . json_encode($jsColors) . ';';
    echo '</script>';
}
