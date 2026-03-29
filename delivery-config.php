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
        ['key' => 'early',    'cls' => 'early',      'icon' => '&#128077;', 'label' => 'Early',       'days' => '10+ Days',  'lead' => 10, 'cutoffHour' => 17],
        ['key' => 'standard', 'cls' => 'standard',   'icon' => '&#128197;', 'label' => 'Standard',    'days' => '5 Days',    'lead' => 5,  'cutoffHour' => 17],
        ['key' => '3days',    'cls' => 'rush',        'icon' => '&#127939;', 'label' => 'Rush',        'days' => '3 Days',    'lead' => 3,  'cutoffHour' => 17],
        ['key' => '2days',    'cls' => 'urgent',      'icon' => '&#128293;', 'label' => 'Urgent',      'days' => '2 Days',    'lead' => 2,  'cutoffHour' => 17],
        ['key' => 'nextday',  'cls' => 'critical',    'icon' => '&#128680;', 'label' => 'Critical',    'days' => 'Next Day',  'lead' => 1,  'cutoffHour' => 15],
        ['key' => 'sameday',  'cls' => 'lastminute',  'icon' => '&#128128;', 'label' => 'Last Minute',  'days' => 'Same Day',  'lead' => 0,  'cutoffHour' => 15],
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

    // ===== PRICING RULES =====
    'pricing_rules' => [
        // Next-day 9 AM delivery always uses same-day pricing (requires previous-day production)
        'nextday_9am_tier' => 'sameday',
        // After this hour, next-day non-9AM orders switch to same-day pricing
        'nextday_sameday_switch_hour' => 15,   // 3 PM
        // Weekend delivery (Sat/Sun) and Monday 9 AM = same-day pricing
        'weekend_delivery_tier' => 'sameday',
        // Orders placed Sat/Sun or Mon before 9 AM for Monday delivery = same-day pricing
        'weekend_order_monday_tier' => 'sameday',
        'monday_sameday_cutoff_hour' => 9,     // Before 9 AM Monday = same-day pricing for Monday
    ],

    // ===== WEEKEND / BUSINESS RULES =====
    'business_rules' => [
        'weekend_printing' => false,           // No vendor printing on weekends
        'friday_cutoff_hour' => 15,            // 3 PM Friday = cutoff for weekend/Monday 9 AM orders
        'vendor_weekday_open' => '09:00',
        'vendor_weekday_close' => '18:00',
        'vendor_daily_cutoff' => '15:00',
    ],

    // ===== VENDOR FULFILLMENT BUFFERS =====
    'vendor_buffers' => [
        '9am'     => ['buffer_hours' => 0, 'previous_day_pickup' => true],   // Overnight hold
        '12pm'    => ['buffer_hours' => 1],
        '3pm'     => ['buffer_hours' => 2],
        '6pm'     => ['buffer_hours' => 2],
        'anytime' => ['buffer_hours' => 2],   // Same as 6pm
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
