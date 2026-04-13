<?php
/**
 * Delivery Configuration - Single Source of Truth
 * All delivery timing rules, tier cutoffs, and business logic
 * 
 * Used by:
 *   - index.php (inline JS injection for customer form)
 *   - script.js (via window.DELIVERY_CONFIG)
 *   - create-checkout-session.php (server-side validation)
 *   - admin-create-order.php (admin form with override)
 *   - Fulfillment/production systems (vendor deadlines)
 *
 * Location: /root/delivery-config.php
 */

return [

    // ===== PRICING TIERS =====
    // Ordered cheapest to most expensive
    'tiers' => [
        ['key' => 'early',    'cls' => 'early',      'icon' => '&#128077;', 'label' => 'Best Value',         'days' => '10+ Days Turnaround', 'lead' => 10, 'cutoffHour' => 17],
        ['key' => 'standard', 'cls' => 'standard',   'icon' => '&#128197;', 'label' => 'Standard',           'days' => '5 Days Turnaround',   'lead' => 5,  'cutoffHour' => 17],
        ['key' => '3days',    'cls' => 'rush',        'icon' => '&#127939;', 'label' => 'Rush',               'days' => '3 Days Turnaround',   'lead' => 3,  'cutoffHour' => 17],
        ['key' => '2days',    'cls' => 'urgent',      'icon' => '&#128293;', 'label' => 'Express',            'days' => '2 Days Turnaround',   'lead' => 2,  'cutoffHour' => 17],
        ['key' => 'nextday',  'cls' => 'critical',    'icon' => '&#128680;', 'label' => 'Priority',           'days' => 'Next Day Turnaround', 'lead' => 1,  'cutoffHour' => 15],
        ['key' => 'sameday',  'cls' => 'lastminute',  'icon' => '&#128128;', 'label' => "We'll Get It Done",  'days' => 'Same-Day Turnaround', 'lead' => 0,  'cutoffHour' => 15],
    ],

    // ===== DELIVERY TIME OPTIONS =====
    // Available delivery windows for customers to select
    'delivery_times' => [
        ['value' => 'anytime', 'label' => 'Anytime',       'hour' => 18, 'alias_of' => '6pm'],
        ['value' => '9am',     'label' => 'By 9:00 AM',    'hour' => 9],
        ['value' => '12pm',    'label' => 'By 12:00 PM',   'hour' => 12],
        ['value' => '3pm',     'label' => 'By 3:00 PM',    'hour' => 15],
        ['value' => '6pm',     'label' => 'By 6:00 PM',    'hour' => 18],
    ],

    // ===== DELIVERY TIME GATES =====
    // When each delivery time becomes unavailable for same-day delivery
    // Format: 'delivery_time' => ['gate_context' => 'same_day'|'previous_day', 'gate_hour' => X]
    'delivery_time_gates' => [
        '9am'     => ['gate_context' => 'previous_day', 'gate_hour' => 15],  // Disabled after 3 PM previous day
        '12pm'    => ['gate_context' => 'same_day',     'gate_hour' => 9],   // Disabled after 9 AM today
        '3pm'     => ['gate_context' => 'same_day',     'gate_hour' => 12],  // Disabled after 12 PM today
        '6pm'     => ['gate_context' => 'same_day',     'gate_hour' => 15],  // Disabled after 3 PM today
        'anytime' => ['gate_context' => 'same_day',     'gate_hour' => 15],  // Disabled after 3 PM today (same as 6pm)
    ],

    // ===== PRODUCTION DEADLINE RULES =====
    // The production deadline is "when the finished print must be ready at the vendor".
    // All tier order-cutoffs are computed by walking back business days from this deadline.
    // Lead-time business-day walks skip Sat, Sun, and any date listed in data/holidays.json.
    'production_rules' => [
        // Unified 3-hour minimum production window for same-day delivery times.
        // Production deadline for a weekday delivery = delivery time minus 3 hours.
        // (e.g. 12pm delivery → 9am deadline, 3pm delivery → 12pm deadline, 6pm → 3pm.)
        'production_window_hours' => 3,

        // 9am delivery needs overnight hold — production deadline is previous business day @ 2 PM.
        'nineam_prev_day_cutoff_hour' => 14,   // 2 PM

        // Weekend delivery (Sat/Sun) or Monday 9am delivery → Friday before @ 2 PM.
        'friday_weekend_cutoff_hour' => 14,    // 2 PM
    ],

    // ===== WEEKEND / BUSINESS RULES =====
    'business_rules' => [
        'weekend_printing' => false,           // No vendor printing on weekends
        'holidays_file' => 'data/holidays.json',
        'vendor_weekday_open' => '09:00',
        'vendor_weekday_close' => '18:00',
    ],

    // ===== GLOBAL BUFFERS =====
    'global_buffers' => [
        'pickup_buffer_standard_hours' => 2,
        'pickup_buffer_rush_hours'     => 1,
        'courier_early_arrival_min'    => 15,  // Courier arrives 15 min before vendor closing
    ],

    // ===== COUNTDOWN TIMER THRESHOLDS =====
    'countdown_colors' => [
        'normal_min'  => 30,   // > 30 min = purple (normal)
        'warning_min' => 10,   // 10-30 min = amber (warning)
                               // < 10 min = red pulse (critical)
    ],

    // ===== VENDOR NOTIFICATION URGENCY =====
    // Based on vendor's actual available production hours
    'vendor_urgency' => [
        ['max_hours' => 3,  'level' => 'urgent',   'color' => 'red',    'chime' => 'triple'],
        ['max_hours' => 6,  'level' => 'rush',     'color' => 'amber',  'chime' => 'double'],
        ['max_hours' => 12, 'level' => 'standard', 'color' => 'normal', 'chime' => 'single'],
        ['max_hours' => 999,'level' => 'normal',   'color' => 'normal', 'chime' => 'soft'],
    ],
];
