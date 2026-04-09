<?php
/**
 * MTCC Icon Library - Master Icon Definitions
 * ============================================
 * 
 * This file contains all icons and symbols used across the MTCC Poster System.
 * Icons are defined as HTML numeric entities to prevent encoding corruption.
 * 
 * USAGE:
 *   1. Include this file: require_once 'includes/icons.php';
 *   2. Use constants in HTML: <?= ICON_CALENDAR ?>
 *   3. Use in PHP strings: echo "Date: " . ICON_CALENDAR;
 *   4. In JavaScript: <?php outputIconsScript(); ?> then use ICONS.CALENDAR
 * 
 * Last Updated: January 2026
 */

// ============================================================================
// BASE ICON DEFINITIONS - Unique icons
// ============================================================================

// --- Events & Activities ---
define('ICON_CIRCUS_TENT',      '&#127914;');         // ðŸŽª
define('ICON_CALENDAR',         '&#128197;');         // ðŸ“…
define('ICON_CLOCK',            '&#128336;');         // ðŸ•
define('ICON_ROCKET',           '&#128640;');         // ðŸš€
define('ICON_TARGET',           '&#127919;');         // ðŸŽ¯
define('ICON_STAR',             '&#11088;');          // â­
define('ICON_CHART_UP',         '&#128200;');         // ðŸ“ˆ
define('ICON_PARTY',            '&#127881;');         // ðŸŽ‰

// --- Documents & Files ---
define('ICON_CLIPBOARD',        '&#128203;');         // ðŸ“‹
define('ICON_MEMO',             '&#128221;');         // ðŸ“
define('ICON_DOCUMENT',         '&#128209;');         // ðŸ“‘
define('ICON_FOLDER',           '&#128194;');         // ðŸ“‚
define('ICON_FOLDER_DIVIDERS',  '&#128450;&#65039;'); // ðŸ—‚ï¸
define('ICON_TRIANGULAR_RULER', '&#128208;');         // ðŸ“
define('ICON_FRAME',            '&#128444;&#65039;'); // ðŸ–¼ï¸
define('ICON_INBOX',            '&#128229;');         // ðŸ“¥

// --- Communication ---
define('ICON_ENVELOPE',         '&#9993;&#65039;');   // âœ‰ï¸
define('ICON_ENVELOPE_ARROW',   '&#128232;');         // ðŸ“¨
define('ICON_MAILBOX',          '&#128236;');         // ðŸ“¬
define('ICON_BELL',             '&#128276;');         // ðŸ””
define('ICON_INFO',             '&#8505;&#65039;');   // â„¹ï¸
define('ICON_LINK',             '&#128279;');         // ðŸ”—

// --- People & Users ---
define('ICON_USER',             '&#128100;');         // ðŸ‘¤
define('ICON_USERS',            '&#128101;');         // ðŸ‘¥
define('ICON_WAVE',             '&#128075;');         // ðŸ‘‹
define('ICON_THUMBS_UP',        '&#128077;');         // ðŸ‘
define('ICON_WRITING_HAND',     '&#9997;&#65039;');   // âœï¸
define('ICON_EYE',              '&#128065;&#65039;'); // ðŸ‘ï¸
define('ICON_MTCC_STAFF',       '&#128119;');         // ðŸ‘·

// --- Transport & Delivery ---
define('ICON_TRUCK',            '&#128666;');         // ðŸšš
define('ICON_PACKAGE',          '<svg width=”1em” height=”1em” viewBox=”0 0 24 24” fill=”none” stroke=”currentColor” stroke-width=”2” style=”display:inline;vertical-align:-0.125em;”><path d=”M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z”/><polyline points=”3.27 6.96 12 12.01 20.73 6.96”/><line x1=”12” y1=”22.08” x2=”12” y2=”12”/></svg>'); // 3D box
define('ICON_COURIER',          '&#128690;');         // ðŸš²

// --- Money & Finance ---
define('ICON_MONEY_BAG',        '&#128176;');         // ðŸ’°
define('ICON_MONEY_WINGS',      '&#128184;');         // ðŸ’¸
define('ICON_DOLLAR',           '&#128181;');         // ðŸ’µ
define('ICON_PAYMENT_LINK',     '&#128179;');         // ðŸ’³

// --- Status & Alerts ---
define('ICON_WARNING',          '&#9888;&#65039;');   // âš ï¸
define('ICON_PROHIBITED',       '&#128683;');         // ðŸš«
define('ICON_SIREN',            '&#128680;');         // ðŸš¨
define('ICON_FIRE',             '&#128293;');         // ðŸ”¥
define('ICON_SKULL',            '&#128128;');         // ðŸ’€
define('ICON_HOURGLASS',        '&#9203;');           // â³
define('ICON_TRAFFIC_LIGHT',    '&#128678;');         // ðŸš¦
define('ICON_FLAG',             '&#128681;');         // ðŸš©
define('ICON_LIGHTNING',        '&#9889;');           // âš¡
define('ICON_RUNNER',           '&#127939;');         // ðŸƒ
define('ICON_STOP',             '&#128721;');         // ðŸ›‘
define('ICON_SUCCESS',          '&#127942;');         // ðŸ†

// --- Actions & Tools ---
define('ICON_PENCIL',           '&#9999;&#65039;');   // âœï¸
define('ICON_PRINTER',          '&#128424;&#65039;'); // ðŸ–¨ï¸
define('ICON_LABEL',            '&#127991;&#65039;'); // ðŸ·ï¸
define('ICON_TRASH',            '&#128465;&#65039;'); // ðŸ—‘ï¸
define('ICON_GEAR',             '&#9881;&#65039;');   // âš™ï¸
define('ICON_SEARCH',           '&#128269;');         // ðŸ”
define('ICON_CYCLE',            '&#128260;');         // ðŸ”„
define('ICON_DOOR',             '&#128682;');         // ðŸšª
define('ICON_KEY',              '&#128273;');         // ðŸ”‘
define('ICON_SAVE',             '&#128190;');         // ðŸ’¾
define('ICON_CAMERA',           '&#128247;');         // ðŸ“·
define('ICON_SCAN',             '&#128290;');         // ðŸ”¢

// --- File Operations ---
define('ICON_UPLOAD',           '&#128228;');         // ðŸ“¤
define('ICON_DOWNLOAD',         '&#128229;');         // ðŸ“¥
define('ICON_PLUS',             '&#10133;');          // âž•

// --- Checkmarks & Status Indicators ---
define('ICON_CHECK_GREEN',      '&#9989;');           // âœ…
define('ICON_CHECK_MARK',       '&#10004;');          // âœ”
define('ICON_CROSS',            '&#10006;&#65039;');  // âœ–ï¸

// --- Symbols & Arrows ---
define('SYMBOL_ARROW_UP',       '&#8593;');           // â†‘
define('SYMBOL_ARROW_DOWN',     '&#8595;');           // â†“
define('SYMBOL_ARROW_LEFT',     '&#8592;');           // â†
define('SYMBOL_ARROW_RIGHT',    '&#8594;');           // â†’
define('SYMBOL_ARROW_UPDOWN',   '&#8597;');           // â†•
define('SYMBOL_ARROW_UPRIGHT',  '&#8599;&#65039;');   // â†—ï¸
define('SYMBOL_ARROW_CYCLE',    '&#8634;');           // â†º
define('SYMBOL_REFRESH',        '&#8634;');           // â†º
define('SYMBOL_DROPDOWN',       '&#9660;');           // â–¼
define('SYMBOL_CARET_DOWN',     '&#9660;');           // â–¼
define('SYMBOL_MULTIPLY',       '&#215;');            // Ã—
define('SYMBOL_BULLET',         '&#8226;');           // â€¢
define('SYMBOL_EMDASH',         '&#8212;');           // â€”
define('SYMBOL_COPYRIGHT',      '&#169;');            // Â©
define('SYMBOL_CIRCLE_EMPTY',   '&#9675;');           // â—‹
define('SYMBOL_MENU_DOTS',      '&#8942;');           // â‹®
define('SYMBOL_DOTS_VERTICAL',  '&#8942;');           // â‹®


// ============================================================================
// CONTEXTUAL ALIASES - Readable names for specific use cases
// ============================================================================

// --- Order Form: Event, Sizing & Pricing Section ---
define('ICON_TITLE_EVENT',      ICON_CIRCUS_TENT);    // ðŸŽª
define('ICON_DELIVERY_DATE',    ICON_CALENDAR);       // ðŸ“…
define('ICON_DELIVERY_TIME',    ICON_CLOCK);          // ðŸ•
define('ICON_DELIVERY_PHOTO',   ICON_CAMERA);         // ðŸ“·

// --- Order Form: Poster Size Section ---
define('ICON_POSTER_SIZE',      ICON_TRIANGULAR_RULER); // ðŸ“
define('ICON_PREVIEW',          ICON_FRAME);          // ðŸ–¼ï¸
define('ICON_FILE_UPLOAD',      ICON_UPLOAD);         // ðŸ“¤
define('ICON_UPLOAD_SUCCESS',   ICON_CHECK_GREEN);    // âœ…
define('ICON_SIZE_WARNING',     ICON_WARNING);        // âš ï¸
define('ICON_SIZE_DOWN',        SYMBOL_ARROW_DOWN);   // â†“
define('ICON_SIZE_UP',          SYMBOL_ARROW_UP);     // â†‘

// --- Order Form: Pricing Section ---
define('ICON_PRICE',            ICON_MONEY_BAG);      // ðŸ’°

// --- Order Form: Priority Tiers ---
define('ICON_TIER_EARLY',       ICON_THUMBS_UP);      // ðŸ‘
define('ICON_TIER_STANDARD',    ICON_CALENDAR);       // ðŸ“…
define('ICON_TIER_RUSH',        ICON_RUNNER);         // ðŸƒ
define('ICON_TIER_URGENT',      ICON_FIRE);           // ðŸ”¥
define('ICON_TIER_CRITICAL',    ICON_SIREN);          // ðŸš¨
define('ICON_TIER_LASTMINUTE',  ICON_SKULL);          // ðŸ’€

// --- Order Form: Contact Information Section ---
define('ICON_CONTACT',          ICON_USER);           // ðŸ‘¤
define('ICON_DELIVERY_PREF',    ICON_TRUCK);          // ðŸšš

// --- Order Form: Order Summary Section ---
define('ICON_ORDER_SUMMARY',    ICON_CLIPBOARD);      // ðŸ“‹
define('ICON_SUMMARY_INFO',     ICON_INFO);           // â„¹ï¸
define('ICON_ORDER_COMPLETE',   ICON_CHECK_GREEN);    // âœ…

// --- Order Form: Submission Section ---
define('ICON_SUBMIT_FAST',      ICON_LIGHTNING);      // âš¡
define('ICON_STATUS_CIRCLE',    SYMBOL_CIRCLE_EMPTY); // â—‹
define('ICON_STATUS_COMPLETE',  ICON_CHECK_MARK);     // âœ”

// --- Order Form: UI Elements ---
define('ICON_SELECT_ARROW',     SYMBOL_DROPDOWN);     // â–¼
define('ICON_CLOSE',            SYMBOL_MULTIPLY);     // Ã—
define('ICON_DIMENSIONS',       SYMBOL_MULTIPLY);     // Ã—
define('ICON_LIST_BULLET',      SYMBOL_BULLET);       // â€¢
define('ICON_SEPARATOR',        SYMBOL_EMDASH);       // â€”
define('ICON_BACK',             SYMBOL_ARROW_LEFT);   // â†
define('ICON_FORWARD',          SYMBOL_ARROW_RIGHT);  // â†’
define('ICON_ARROW_LEFT',       SYMBOL_ARROW_LEFT);   // â†

// --- Admin Dashboard: Top Navigation ---
define('ICON_LOGOUT',           ICON_DOOR);           // ðŸšª
define('ICON_EVENTS_NAV',       ICON_CIRCUS_TENT);    // ðŸŽª

// --- Admin Dashboard: Header ---
define('ICON_WELCOME',          ICON_WAVE);           // ðŸ‘‹
define('ICON_NEW_ORDERS',       ICON_BELL);           // ðŸ””
define('ICON_REFRESH',          SYMBOL_REFRESH);      // â†º
define('ICON_UNCOLLECTED',      ICON_WARNING);        // âš ï¸
define('ICON_SETTINGS',         ICON_GEAR);           // âš™ï¸

// --- Admin Dashboard: Analytics Cards ---
define('ICON_REVENUE_TODAY',    ICON_MONEY_WINGS);    // ðŸ’¸
define('ICON_REVENUE_AVG',      ICON_MONEY_WINGS);    // ðŸ’¸
define('ICON_CONVERSIONS',      ICON_FOLDER);         // ðŸ“‚
define('ICON_REVENUE_TOTAL',    ICON_MONEY_BAG);      // ðŸ’°
define('ICON_COMMISSION',       ICON_DOLLAR);         // ðŸ’µ
define('ICON_PENDING',          ICON_HOURGLASS);      // â³
define('ICON_CANCELLED',        ICON_CROSS);          // âœ–ï¸
define('ICON_REFUNDED',         ICON_PROHIBITED);     // ðŸš«
define('ICON_TURNAROUND',       ICON_CYCLE);          // ðŸ”„
define('ICON_ORDER_STATUS',     ICON_FLAG);           // ðŸš©
define('ICON_MAIL',             ICON_ENVELOPE);       // âœ‰ï¸

// --- Admin Dashboard: Order Table ---
define('ICON_CREATE_ORDER',     ICON_PLUS);           // âž•
define('ICON_MENU',             SYMBOL_MENU_DOTS);    // â‹®
define('ICON_SORTABLE',         SYMBOL_ARROW_UPDOWN); // â†•
define('ICON_SORT_ASC',         SYMBOL_ARROW_UP);     // â†‘
define('ICON_SORT_DESC',        SYMBOL_ARROW_DOWN);   // â†“
define('ICON_PRINT',            ICON_PRINTER);        // ðŸ–¨ï¸
define('ICON_PRINT_LABEL',      ICON_LABEL);          // ðŸ·ï¸
define('ICON_EXPORT',           SYMBOL_ARROW_UPRIGHT); // â†—ï¸
define('ICON_DELETE',           ICON_TRASH);          // ðŸ—‘ï¸
define('ICON_VIEW',             ICON_EYE);            // ðŸ‘ï¸
define('ICON_EDIT',             ICON_PENCIL);         // âœï¸
define('ICON_DOWNLOAD_FILE',    ICON_DOWNLOAD);       // ðŸ“¥

// --- Admin Dashboard: Filters ---
define('ICON_FILTER',           ICON_TRAFFIC_LIGHT);  // ðŸš¦
define('ICON_FILTER_EVENT',     ICON_CIRCUS_TENT);    // ðŸŽª
define('ICON_FILTER_PRIORITY',  ICON_SIREN);          // ðŸš¨
define('ICON_FILTER_STATUS',    ICON_FLAG);           // ðŸš©

// --- Order Statuses ---
define('STATUS_UNPAID',         ICON_HOURGLASS);      // â³
define('STATUS_PAID',           ICON_MONEY_BAG);      // ðŸ’°
define('STATUS_PREFLIGHT',      ICON_CLIPBOARD);      // 📋
define('STATUS_FILE_ISSUE',     ICON_SEARCH);         // ðŸ”
define('STATUS_PRINTING',       ICON_PRINTER);        // ðŸ–¨ï¸
define('STATUS_READY_TO_SHIP',  ICON_PACKAGE);        // 📦
define('STATUS_SHIPPED',        ICON_TRUCK);          // ðŸšš
define('STATUS_DELIVERED',      ICON_PACKAGE);        // ðŸ“¦
define('STATUS_PICKEDUP',       ICON_CHECK_GREEN);    // âœ…
define('STATUS_UNCLAIMED',      ICON_MAILBOX);        // ðŸ“¬
define('STATUS_MISSING',        ICON_WARNING);        // âš ï¸
define('STATUS_CANCELLED',      ICON_CROSS);          // âœ–ï¸
define('STATUS_REFUNDED',       ICON_PROHIBITED);     // ðŸš«

// --- Order Details Page ---
define('ICON_SUBMITTED',        ICON_CALENDAR);       // ðŸ“…
define('ICON_SEND_DETAILS',     ICON_ENVELOPE);       // âœ‰ï¸
define('ICON_EDIT_ORDER',       ICON_PENCIL);         // âœï¸
define('ICON_CUSTOMER_INFO',    ICON_USER);           // ðŸ‘¤
define('ICON_DELIVERY_INFO',    ICON_TRUCK);          // ðŸšš
define('ICON_POSTER_DETAILS',   ICON_TRIANGULAR_RULER); // ðŸ“
define('ICON_TRACKING',         ICON_PACKAGE);        // ðŸ“¦
define('ICON_COPY',             ICON_DOCUMENT);       // ðŸ“‘

// --- Internal Notes ---
define('ICON_NOTES',            ICON_WRITING_HAND);   // âœï¸
define('ICON_ADD_NOTE',         ICON_PLUS);           // âž•
define('ICON_NOTE_PLACEHOLDER', ICON_WRITING_HAND);   // âœï¸

// --- Order History ---
define('HISTORY_CREATED',       ICON_PACKAGE);        // ðŸ“¦
define('HISTORY_STATUS',        ICON_CYCLE);          // ðŸ”„
define('HISTORY_EDITED',        ICON_PENCIL);         // âœï¸
define('HISTORY_NOTE_ADDED',    ICON_MEMO);           // ðŸ“
define('HISTORY_NOTE_EDITED',   ICON_WRITING_HAND);   // âœï¸
define('HISTORY_NOTE_REMOVED',  ICON_TRASH);          // ðŸ—‘ï¸
define('HISTORY_EMAIL_SENT',    ICON_ENVELOPE_ARROW); // ðŸ“¨
define('HISTORY_PAYMENT',       ICON_MONEY_BAG);      // ðŸ’°
define('HISTORY_REFUND',        ICON_PROHIBITED);     // ðŸš«

// --- Event Management ---
define('ICON_TOTAL_REVENUE',    ICON_MONEY_BAG);      // ðŸ’°
define('ICON_TOTAL_COMMISSION', ICON_DOLLAR);         // ðŸ’µ
define('ICON_TOTAL_ORDERS',     ICON_PACKAGE);        // ðŸ“¦
define('ICON_ACTIVE_EVENTS',    ICON_ROCKET);         // ðŸš€
define('ICON_UPCOMING_EVENTS',  ICON_HOURGLASS);      // â³
define('ICON_EVENTS_YEAR',      ICON_CHART_UP);       // ðŸ“ˆ
define('ICON_EVENTS_ACTIVE',    ICON_TARGET);         // ðŸŽ¯
define('ICON_EVENTS_ARCHIVE',   ICON_FOLDER_DIVIDERS); // ðŸ—‚ï¸
define('ICON_ADD_EVENT',        ICON_PLUS);           // âž•

// --- Footer ---
define('ICON_COPYRIGHT',        SYMBOL_COPYRIGHT);    // Â©


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get the icon for a given order status
 * 
 * @param string $status The status key (e.g., 'paid', 'shipped')
 * @return string HTML entity for the icon
 */
function getStatusIcon($status) {
    $icons = [
        'unpaid'     => STATUS_UNPAID,      // â³
        'paid'       => STATUS_PAID,        // ðŸ’°
        'preflight'     => STATUS_PREFLIGHT,      // 📋
        'file_issue' => STATUS_FILE_ISSUE,  // ðŸ”
        'printing'   => STATUS_PRINTING,    // ðŸ–¨ï¸
        'ready'      => STATUS_READY_TO_SHIP,  // 📦
        'ready_to_ship' => STATUS_READY_TO_SHIP,  // 📦 (legacy alias)
        'dispatched' => STATUS_SHIPPED,     // 🚚
        'shipped'    => STATUS_SHIPPED,     // ðŸšš
        'delivered'  => STATUS_DELIVERED,   // ðŸ“¦
        'pickedup'   => STATUS_PICKEDUP,    // âœ…
        'unclaimed'  => STATUS_UNCLAIMED,   // ðŸ“¬
        'missing'    => STATUS_MISSING,     // âš ï¸
        'cancelled'  => STATUS_CANCELLED,   // âœ–ï¸
        'refunded'   => STATUS_REFUNDED     // ðŸš«
    ];
    return $icons[$status] ?? ICON_CLIPBOARD; // ðŸ“‹
}

/**
 * Get the icon for a given priority tier
 * 
 * @param string $tier The tier name (e.g., 'early', 'rush')
 * @return string HTML entity for the icon
 */
function getTierIcon($tier) {
    $tierLower = strtolower($tier);
    $icons = [
        'early'       => ICON_TIER_EARLY,      // ðŸ‘
        'early bird'  => ICON_TIER_EARLY,      // ðŸ‘
        'standard'    => ICON_TIER_STANDARD,   // ðŸ“…
        'rush'        => ICON_TIER_RUSH,       // ðŸƒ
        'urgent'      => ICON_TIER_URGENT,     // ðŸ”¥
        'critical'    => ICON_TIER_CRITICAL,   // ðŸš¨
        'last minute' => ICON_TIER_LASTMINUTE, // ðŸ’€
        'lastminute'  => ICON_TIER_LASTMINUTE  // ðŸ’€
    ];
    return $icons[$tierLower] ?? ICON_TIER_STANDARD; // ðŸ“…
}

/**
 * Get the icon for an order history action
 * 
 * @param string $action The action type
 * @return string HTML entity for the icon
 */
function getHistoryIcon($action) {
    $icons = [
        'order_created'  => HISTORY_CREATED,      // ðŸ“¦
        'status_change'  => HISTORY_STATUS,       // ðŸ”„
        'order_edited'   => HISTORY_EDITED,       // âœï¸
        'note_added'     => HISTORY_NOTE_ADDED,   // ðŸ“
        'note_edited'    => HISTORY_NOTE_EDITED,  // âœï¸
        'note_removed'   => HISTORY_NOTE_REMOVED, // ðŸ—‘ï¸
        'email_sent'     => HISTORY_EMAIL_SENT,   // ðŸ“¨
        'payment'        => HISTORY_PAYMENT,      // ðŸ’°
        'refund'         => HISTORY_REFUND        // ðŸš«
    ];
    return $icons[$action] ?? ICON_CLIPBOARD; // ðŸ“‹
}


// ============================================================================
// JAVASCRIPT ICONS OUTPUT FUNCTION
// ============================================================================

/**
 * Output JavaScript ICONS object for use in JS files
 * Call this in the <head> section of your HTML pages
 * 
 * Usage:
 *   <?php require_once 'includes/icons.php'; ?>
 *   <head><?php outputIconsScript(); ?></head>
 * 
 * Then in JavaScript:
 *   element.innerHTML = ICONS.CALENDAR + ' Date';  // ðŸ“… Date
 *   btn.innerHTML = ICONS.CHECK_GREEN + ' Done';   // âœ… Done
 */
function outputIconsScript() {
    ?>
<script>
window.ICONS = {
    // Events & Activities
    CIRCUS_TENT: '&#127914;',      // ðŸŽª
    CALENDAR: '&#128197;',         // ðŸ“…
    CLOCK: '&#128336;',            // ðŸ•
    ROCKET: '&#128640;',           // ðŸš€
    TARGET: '&#127919;',           // ðŸŽ¯
    STAR: '&#11088;',              // â­
    CHART_UP: '&#128200;',         // ðŸ“ˆ
    PARTY: '&#127881;',            // ðŸŽ‰
    
    // Documents & Files
    CLIPBOARD: '&#128203;',        // ðŸ“‹
    MEMO: '&#128221;',             // ðŸ“
    DOCUMENT: '&#128209;',         // ðŸ“‘
    FOLDER: '&#128194;',           // ðŸ“‚
    FOLDER_DIVIDERS: '&#128450;&#65039;', // ðŸ—‚ï¸
    TRIANGULAR_RULER: '&#128208;', // ðŸ“
    FRAME: '&#128444;&#65039;',    // ðŸ–¼ï¸
    INBOX: '&#128229;',            // ðŸ“¥
    
    // Communication
    ENVELOPE: '&#9993;&#65039;',   // âœ‰ï¸
    ENVELOPE_ARROW: '&#128232;',   // ðŸ“¨
    MAILBOX: '&#128236;',          // ðŸ“¬
    BELL: '&#128276;',             // ðŸ””
    INFO: '&#8505;&#65039;',       // â„¹ï¸
    LINK: '&#128279;',             // ðŸ”—
    
    // People & Users
    USER: '&#128100;',             // ðŸ‘¤
    USERS: '&#128101;',            // ðŸ‘¥
    WAVE: '&#128075;',             // ðŸ‘‹
    THUMBS_UP: '&#128077;',        // ðŸ‘
    WRITING_HAND: '&#9997;&#65039;', // âœï¸
    EYE: '&#128065;&#65039;',      // ðŸ‘ï¸
    MTCC_STAFF: '&#128119;',       // ðŸ‘·
    
    // Transport & Delivery
    TRUCK: '&#128666;',            // ðŸšš
    PACKAGE: '<svg width=\”1em\” height=\”1em\” viewBox=\”0 0 24 24\” fill=\”none\” stroke=\”currentColor\” stroke-width=\”2\” style=\”display:inline;vertical-align:-0.125em;\”><path d=\”M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z\”/><polyline points=\”3.27 6.96 12 12.01 20.73 6.96\”/><line x1=\”12\” y1=\”22.08\” x2=\”12\” y2=\”12\”/></svg>', // 3D box
    COURIER: '&#128690;',          // ðŸš²
    
    // Money & Finance
    MONEY_BAG: '&#128176;',        // ðŸ’°
    MONEY_WINGS: '&#128184;',      // ðŸ’¸
    DOLLAR: '&#128181;',           // ðŸ’µ
    PAYMENT_LINK: '&#128179;',     // ðŸ’³
    
    // Status & Alerts
    WARNING: '&#9888;&#65039;',    // âš ï¸
    PROHIBITED: '&#128683;',       // ðŸš«
    SIREN: '&#128680;',            // ðŸš¨
    FIRE: '&#128293;',             // ðŸ”¥
    SKULL: '&#128128;',            // ðŸ’€
    HOURGLASS: '&#9203;',          // â³
    TRAFFIC_LIGHT: '&#128678;',    // ðŸš¦
    FLAG: '&#128681;',             // ðŸš©
    LIGHTNING: '&#9889;',          // âš¡
    RUNNER: '&#127939;',           // ðŸƒ
    STOP: '&#128721;',             // ðŸ›‘
    SUCCESS: '&#127942;',          // ðŸ†
    
    // Actions & Tools
    PENCIL: '&#9999;&#65039;',     // âœï¸
    PRINTER: '&#128424;&#65039;',  // ðŸ–¨ï¸
    LABEL: '&#127991;&#65039;',    // ðŸ·ï¸
    TRASH: '&#128465;&#65039;',    // ðŸ—‘ï¸
    GEAR: '&#9881;&#65039;',       // âš™ï¸
    SEARCH: '&#128269;',           // ðŸ”
    CYCLE: '&#128260;',            // ðŸ”„
    DOOR: '&#128682;',             // ðŸšª
    KEY: '&#128273;',              // ðŸ”‘
    SAVE: '&#128190;',             // ðŸ’¾
    CAMERA: '&#128247;',           // ðŸ“·
    SCAN: '&#128290;',             // ðŸ”¢
    
    // File Operations
    UPLOAD: '&#128228;',           // ðŸ“¤
    DOWNLOAD: '&#128229;',         // ðŸ“¥
    PLUS: '&#10133;',              // âž•
    
    // Checkmarks & Status
    CHECK_GREEN: '&#9989;',        // âœ…
    CHECK_MARK: '&#10004;',        // âœ”
    CROSS: '&#10006;&#65039;',     // âœ–ï¸
    
    // Symbols & Arrows
    ARROW_UP: '&#8593;',           // â†‘
    ARROW_DOWN: '&#8595;',         // â†“
    ARROW_LEFT: '&#8592;',         // â†
    ARROW_RIGHT: '&#8594;',        // â†’
    ARROW_UPDOWN: '&#8597;',       // â†•
    ARROW_UPRIGHT: '&#8599;&#65039;', // â†—ï¸
    REFRESH: '&#8634;',            // â†º
    DROPDOWN: '&#9660;',           // â–¼
    CARET_DOWN: '&#9660;',         // â–¼
    MULTIPLY: '&#215;',            // Ã—
    BULLET: '&#8226;',             // â€¢
    EMDASH: '&#8212;',             // â€”
    COPYRIGHT: '&#169;',           // Â©
    CIRCLE_EMPTY: '&#9675;',       // â—‹
    MENU_DOTS: '&#8942;'           // â‹®
};

// Tier aliases (for convenience)
window.ICONS.TIER_EARLY = window.ICONS.THUMBS_UP;       // ðŸ‘
window.ICONS.TIER_STANDARD = window.ICONS.CALENDAR;     // ðŸ“…
window.ICONS.TIER_RUSH = window.ICONS.RUNNER;           // ðŸƒ
window.ICONS.TIER_URGENT = window.ICONS.FIRE;           // ðŸ”¥
window.ICONS.TIER_CRITICAL = window.ICONS.SIREN;        // ðŸš¨
window.ICONS.TIER_LASTMINUTE = window.ICONS.SKULL;      // ðŸ’€

// Status aliases (for convenience)
window.ICONS.STATUS_UNPAID = window.ICONS.HOURGLASS;    // â³
window.ICONS.STATUS_PAID = window.ICONS.MONEY_BAG;      // ðŸ’°
window.ICONS.STATUS_PREFLIGHT = window.ICONS.CLIPBOARD;  // 📋
window.ICONS.STATUS_FILE_ISSUE = window.ICONS.SEARCH;   // ðŸ”
window.ICONS.STATUS_PRINTING = window.ICONS.PRINTER;    // ðŸ–¨ï¸
window.ICONS.STATUS_READY_TO_SHIP = window.ICONS.PACKAGE;  // 📦
window.ICONS.STATUS_SHIPPED = window.ICONS.TRUCK;       // ðŸšš
window.ICONS.STATUS_DELIVERED = window.ICONS.PACKAGE;   // ðŸ“¦
window.ICONS.STATUS_PICKEDUP = window.ICONS.CHECK_GREEN; // âœ…
window.ICONS.STATUS_UNCLAIMED = window.ICONS.MAILBOX;   // ðŸ“¬
window.ICONS.STATUS_MISSING = window.ICONS.WARNING;     // âš ï¸
window.ICONS.STATUS_CANCELLED = window.ICONS.CROSS;     // âœ–ï¸
window.ICONS.STATUS_REFUNDED = window.ICONS.PROHIBITED; // ðŸš«
</script>
<?php
}


// ============================================================================
// DEBUG / DOCUMENTATION HELPER
// ============================================================================

/**
 * Get all base icons as an associative array
 * Useful for debugging or generating documentation
 * 
 * @return array All base icon constants and their values
 */
function getAllIcons() {
    return [
        // Events & Activities
        'ICON_CIRCUS_TENT' => ICON_CIRCUS_TENT,           // ðŸŽª
        'ICON_CALENDAR' => ICON_CALENDAR,                 // ðŸ“…
        'ICON_CLOCK' => ICON_CLOCK,                       // ðŸ•
        'ICON_ROCKET' => ICON_ROCKET,                     // ðŸš€
        'ICON_TARGET' => ICON_TARGET,                     // ðŸŽ¯
        'ICON_STAR' => ICON_STAR,                         // â­
        'ICON_CHART_UP' => ICON_CHART_UP,                 // ðŸ“ˆ
        'ICON_PARTY' => ICON_PARTY,                       // ðŸŽ‰
        
        // Documents & Files
        'ICON_CLIPBOARD' => ICON_CLIPBOARD,               // ðŸ“‹
        'ICON_MEMO' => ICON_MEMO,                         // ðŸ“
        'ICON_DOCUMENT' => ICON_DOCUMENT,                 // ðŸ“‘
        'ICON_FOLDER' => ICON_FOLDER,                     // ðŸ“‚
        'ICON_FOLDER_DIVIDERS' => ICON_FOLDER_DIVIDERS,   // ðŸ—‚ï¸
        'ICON_TRIANGULAR_RULER' => ICON_TRIANGULAR_RULER, // ðŸ“
        'ICON_FRAME' => ICON_FRAME,                       // ðŸ–¼ï¸
        'ICON_INBOX' => ICON_INBOX,                       // ðŸ“¥
        
        // Communication
        'ICON_ENVELOPE' => ICON_ENVELOPE,                 // âœ‰ï¸
        'ICON_ENVELOPE_ARROW' => ICON_ENVELOPE_ARROW,     // ðŸ“¨
        'ICON_MAILBOX' => ICON_MAILBOX,                   // ðŸ“¬
        'ICON_BELL' => ICON_BELL,                         // ðŸ””
        'ICON_INFO' => ICON_INFO,                         // â„¹ï¸
        'ICON_LINK' => ICON_LINK,                         // ðŸ”—
        
        // People & Users
        'ICON_USER' => ICON_USER,                         // ðŸ‘¤
        'ICON_USERS' => ICON_USERS,                       // ðŸ‘¥
        'ICON_WAVE' => ICON_WAVE,                         // ðŸ‘‹
        'ICON_THUMBS_UP' => ICON_THUMBS_UP,               // ðŸ‘
        'ICON_WRITING_HAND' => ICON_WRITING_HAND,         // âœï¸
        'ICON_EYE' => ICON_EYE,                           // ðŸ‘ï¸
        'ICON_MTCC_STAFF' => ICON_MTCC_STAFF,             // ðŸ‘·
        
        // Transport & Delivery
        'ICON_TRUCK' => ICON_TRUCK,                       // ðŸšš
        'ICON_PACKAGE' => ICON_PACKAGE,                   // ðŸ“¦
        'ICON_COURIER' => ICON_COURIER,                   // ðŸš²
        
        // Money & Finance
        'ICON_MONEY_BAG' => ICON_MONEY_BAG,               // ðŸ’°
        'ICON_MONEY_WINGS' => ICON_MONEY_WINGS,           // ðŸ’¸
        'ICON_DOLLAR' => ICON_DOLLAR,                     // ðŸ’µ
        'ICON_PAYMENT_LINK' => ICON_PAYMENT_LINK,         // ðŸ’³
        
        // Status & Alerts
        'ICON_WARNING' => ICON_WARNING,                   // âš ï¸
        'ICON_PROHIBITED' => ICON_PROHIBITED,             // ðŸš«
        'ICON_SIREN' => ICON_SIREN,                       // ðŸš¨
        'ICON_FIRE' => ICON_FIRE,                         // ðŸ”¥
        'ICON_SKULL' => ICON_SKULL,                       // ðŸ’€
        'ICON_HOURGLASS' => ICON_HOURGLASS,               // â³
        'ICON_TRAFFIC_LIGHT' => ICON_TRAFFIC_LIGHT,       // ðŸš¦
        'ICON_FLAG' => ICON_FLAG,                         // ðŸš©
        'ICON_LIGHTNING' => ICON_LIGHTNING,               // âš¡
        'ICON_RUNNER' => ICON_RUNNER,                     // ðŸƒ
        'ICON_STOP' => ICON_STOP,                         // ðŸ›‘
        'ICON_SUCCESS' => ICON_SUCCESS,                   // ðŸ†
        
        // Actions & Tools
        'ICON_PENCIL' => ICON_PENCIL,                     // âœï¸
        'ICON_PRINTER' => ICON_PRINTER,                   // ðŸ–¨ï¸
        'ICON_LABEL' => ICON_LABEL,                       // ðŸ·ï¸
        'ICON_TRASH' => ICON_TRASH,                       // ðŸ—‘ï¸
        'ICON_GEAR' => ICON_GEAR,                         // âš™ï¸
        'ICON_SEARCH' => ICON_SEARCH,                     // ðŸ”
        'ICON_CYCLE' => ICON_CYCLE,                       // ðŸ”„
        'ICON_DOOR' => ICON_DOOR,                         // ðŸšª
        'ICON_KEY' => ICON_KEY,                           // ðŸ”‘
        'ICON_SAVE' => ICON_SAVE,                         // ðŸ’¾
        'ICON_CAMERA' => ICON_CAMERA,                     // ðŸ“·
        'ICON_SCAN' => ICON_SCAN,                         // ðŸ”¢
        
        // File Operations
        'ICON_UPLOAD' => ICON_UPLOAD,                     // ðŸ“¤
        'ICON_DOWNLOAD' => ICON_DOWNLOAD,                 // ðŸ“¥
        'ICON_PLUS' => ICON_PLUS,                         // âž•
        
        // Checkmarks & Status
        'ICON_CHECK_GREEN' => ICON_CHECK_GREEN,           // âœ…
        'ICON_CHECK_MARK' => ICON_CHECK_MARK,             // âœ”
        'ICON_CROSS' => ICON_CROSS,                       // âœ–ï¸
        
        // Symbols & Arrows
        'SYMBOL_ARROW_UP' => SYMBOL_ARROW_UP,             // â†‘
        'SYMBOL_ARROW_DOWN' => SYMBOL_ARROW_DOWN,         // â†“
        'SYMBOL_ARROW_LEFT' => SYMBOL_ARROW_LEFT,         // â†
        'SYMBOL_ARROW_RIGHT' => SYMBOL_ARROW_RIGHT,       // â†’
        'SYMBOL_ARROW_UPDOWN' => SYMBOL_ARROW_UPDOWN,     // â†•
        'SYMBOL_ARROW_UPRIGHT' => SYMBOL_ARROW_UPRIGHT,   // â†—ï¸
        'SYMBOL_REFRESH' => SYMBOL_REFRESH,               // â†º
        'SYMBOL_DROPDOWN' => SYMBOL_DROPDOWN,             // â–¼
        'SYMBOL_CARET_DOWN' => SYMBOL_CARET_DOWN,         // â–¼
        'SYMBOL_MULTIPLY' => SYMBOL_MULTIPLY,             // Ã—
        'SYMBOL_BULLET' => SYMBOL_BULLET,                 // â€¢
        'SYMBOL_EMDASH' => SYMBOL_EMDASH,                 // â€”
        'SYMBOL_COPYRIGHT' => SYMBOL_COPYRIGHT,           // Â©
        'SYMBOL_CIRCLE_EMPTY' => SYMBOL_CIRCLE_EMPTY,     // â—‹
        'SYMBOL_MENU_DOTS' => SYMBOL_MENU_DOTS            // â‹®
    ];
}
