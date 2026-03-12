/**
 * MTCC Timer Utilities
 * Shared countdown/countup timer system for all admin views
 * 
 * Location: /js/timer-utils.js
 * 
 * Usage:
 *   Add data attributes to any element:
 *     data-timer-type="countdown"  data-timer-target="1740600000"   (unix timestamp)
 *     data-timer-type="countup"    data-timer-start="1740500000"    (unix timestamp)
 *   
 *   Optional attributes:
 *     data-timer-window="14400"     Total window in seconds (for urgency color calculation)
 *     data-timer-label="Due in"     Prefix label
 *     data-timer-format="hms"       Format: "hms" (02:15:33), "dhms" (1d 02:15:33), "compact" (2h 15m)
 *     data-timer-overdue-label="OVERDUE"  Custom overdue text
 *
 * Auto-init: Call MTCCTimers.init() or include this script — it auto-initializes on DOMContentLoaded.
 * Manual:    MTCCTimers.formatCountdown(targetTs) / MTCCTimers.formatCountup(startTs)
 */

var MTCCTimers = (function() {
    'use strict';

    var _interval = null;
    var _initialized = false;

    // ============================================
    // FORMAT HELPERS
    // ============================================

    /**
     * Pad number to 2 digits
     */
    function pad(n) {
        return n < 10 ? '0' + n : '' + n;
    }

    /**
     * Format seconds into display string
     * @param {number} totalSeconds - Absolute seconds value
     * @param {string} format - "hms", "dhms", or "compact"
     * @returns {string}
     */
    function formatDuration(totalSeconds, format) {
        format = format || 'dhms';
        var abs = Math.abs(Math.floor(totalSeconds));

        var days = Math.floor(abs / 86400);
        var hours = Math.floor((abs % 86400) / 3600);
        var minutes = Math.floor((abs % 3600) / 60);
        var seconds = abs % 60;

        if (format === 'hms') {
            // Always show as HH:MM:SS (hours can exceed 24)
            var totalHours = Math.floor(abs / 3600);
            return pad(totalHours) + ':' + pad(minutes) + ':' + pad(seconds);
        }

        if (format === 'compact') {
            // Short format: "2d 5h" or "3h 22m" or "15m 30s"
            if (days > 0) return days + 'd ' + hours + 'h';
            if (hours > 0) return hours + 'h ' + minutes + 'm';
            return minutes + 'm ' + seconds + 's';
        }

        // Default: dhms — "1d 02:15:33" or "02:15:33" if < 1 day
        if (days > 0) {
            return days + 'd ' + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        }
        return pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
    }

    // ============================================
    // URGENCY CLASS CALCULATION
    // ============================================

    /**
     * Get CSS class based on remaining time relative to total window
     * @param {number} remainingSeconds - Seconds remaining (negative = overdue)
     * @param {number} totalWindowSeconds - Total window for percentage calc (optional)
     * @returns {string} CSS class name
     */
    function getUrgencyClass(remainingSeconds, totalWindowSeconds) {
        if (remainingSeconds <= 0) return 'timer-overdue';

        // If no window provided, use absolute thresholds
        if (!totalWindowSeconds || totalWindowSeconds <= 0) {
            if (remainingSeconds <= 3600) return 'timer-critical';      // < 1 hour
            if (remainingSeconds <= 7200) return 'timer-warning';       // < 2 hours
            if (remainingSeconds <= 14400) return 'timer-caution';      // < 4 hours
            return 'timer-safe';
        }

        // Percentage-based thresholds
        var pct = remainingSeconds / totalWindowSeconds;
        if (pct <= 0.10) return 'timer-critical';   // < 10% remaining
        if (pct <= 0.25) return 'timer-warning';     // < 25% remaining
        if (pct <= 0.50) return 'timer-caution';     // < 50% remaining
        return 'timer-safe';
    }

    // ============================================
    // PUBLIC FORMAT FUNCTIONS
    // ============================================

    /**
     * Format a countdown timer string
     * @param {number} targetTimestamp - Unix timestamp (seconds) of deadline
     * @param {string} format - Display format
     * @returns {object} {text, cssClass, isOverdue}
     */
    function formatCountdown(targetTimestamp, format, totalWindow) {
        var now = Date.now() / 1000;
        var remaining = targetTimestamp - now;
        var isOverdue = remaining <= 0;
        var text = formatDuration(remaining, format);
        var cssClass = getUrgencyClass(remaining, totalWindow);

        if (isOverdue) {
            var overdueLabel = 'OVERDUE';
            text = overdueLabel + ' ' + formatDuration(Math.abs(remaining), format);
        }

        return {
            text: text,
            cssClass: cssClass,
            isOverdue: isOverdue,
            remainingSeconds: remaining
        };
    }

    /**
     * Format a countup timer string
     * @param {number} startTimestamp - Unix timestamp (seconds) of start time
     * @param {string} format - Display format
     * @returns {object} {text, cssClass, elapsed}
     */
    function formatCountup(startTimestamp, format, warnAfterSeconds) {
        var now = Date.now() / 1000;
        var elapsed = now - startTimestamp;
        if (elapsed < 0) elapsed = 0;

        var text = formatDuration(elapsed, format);

        // Countup urgency: optional warning thresholds
        var cssClass = 'timer-neutral';
        if (warnAfterSeconds && warnAfterSeconds > 0) {
            if (elapsed >= warnAfterSeconds * 2) cssClass = 'timer-critical';
            else if (elapsed >= warnAfterSeconds * 1.5) cssClass = 'timer-warning';
            else if (elapsed >= warnAfterSeconds) cssClass = 'timer-caution';
        }

        return {
            text: text,
            cssClass: cssClass,
            elapsedSeconds: elapsed
        };
    }

    // ============================================
    // DOM AUTO-UPDATE ENGINE
    // ============================================

    /**
     * Update all timer elements on the page
     */
    function updateAll() {
        var elements = document.querySelectorAll('[data-timer-type]');
        for (var i = 0; i < elements.length; i++) {
            updateElement(elements[i]);
        }
    }

    /**
     * Update a single timer element
     */
    function updateElement(el) {
        var type = el.getAttribute('data-timer-type');
        var format = el.getAttribute('data-timer-format') || 'dhms';
        var label = el.getAttribute('data-timer-label') || '';
        var overdueLabel = el.getAttribute('data-timer-overdue-label') || 'OVERDUE';
        var result;

        if (type === 'countdown') {
            var target = parseFloat(el.getAttribute('data-timer-target'));
            if (!target || isNaN(target)) return;

            var window_s = parseFloat(el.getAttribute('data-timer-window')) || 0;
            result = formatCountdown(target, format, window_s);

            // Custom overdue label
            if (result.isOverdue && overdueLabel !== 'OVERDUE') {
                result.text = result.text.replace('OVERDUE', overdueLabel);
            }

        } else if (type === 'countup') {
            var start = parseFloat(el.getAttribute('data-timer-start'));
            if (!start || isNaN(start)) return;

            var warnAfter = parseFloat(el.getAttribute('data-timer-warn-after')) || 0;
            result = formatCountup(start, format, warnAfter);

        } else {
            return;
        }

        // Build display text
        var displayText = label ? (label + ' ' + result.text) : result.text;
        
        // Only update DOM if text changed (performance optimization)
        if (el.textContent !== displayText) {
            el.textContent = displayText;
        }

        // Update CSS class — strip old timer classes, add new one
        var classes = el.className.split(' ').filter(function(c) {
            return c.indexOf('timer-') !== 0;
        });
        classes.push(result.cssClass);
        var newClassName = classes.join(' ');
        if (el.className !== newClassName) {
            el.className = newClassName;
        }
    }

    // ============================================
    // INITIALIZATION
    // ============================================

    /**
     * Start the timer system — discovers all [data-timer-type] elements
     * and updates them every second
     */
    function init() {
        if (_initialized) return;
        _initialized = true;

        // Initial paint
        updateAll();

        // Update every second
        _interval = setInterval(updateAll, 1000);
    }

    /**
     * Stop all timers (useful for page cleanup)
     */
    function destroy() {
        if (_interval) {
            clearInterval(_interval);
            _interval = null;
        }
        _initialized = false;
    }

    /**
     * Refresh — call after dynamically adding new timer elements
     */
    function refresh() {
        updateAll();
    }

    // ============================================
    // PHP HELPER: Generate data attributes from PHP
    // ============================================
    // 
    // Usage in PHP:
    //
    // Countdown:
    //   <span <?= timerAttrs('countdown', strtotime($dueDate), 'dhms', 'Due in') ?>>--:--:--</span>
    //
    // Countup:
    //   <span <?= timerAttrs('countup', strtotime($startedAt), 'hms', 'Elapsed') ?>>--:--:--</span>
    //
    // PHP helper function (include in your PHP file):
    //
    //   function timerAttrs($type, $timestamp, $format = 'dhms', $label = '', $window = 0, $warnAfter = 0) {
    //       $attrs = 'data-timer-type="' . $type . '"';
    //       if ($type === 'countdown') {
    //           $attrs .= ' data-timer-target="' . $timestamp . '"';
    //           if ($window > 0) $attrs .= ' data-timer-window="' . $window . '"';
    //       } else {
    //           $attrs .= ' data-timer-start="' . $timestamp . '"';
    //           if ($warnAfter > 0) $attrs .= ' data-timer-warn-after="' . $warnAfter . '"';
    //       }
    //       if ($format) $attrs .= ' data-timer-format="' . $format . '"';
    //       if ($label) $attrs .= ' data-timer-label="' . htmlspecialchars($label) . '"';
    //       return $attrs;
    //   }

    // ============================================
    // AUTO-INIT ON DOM READY
    // ============================================
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM already ready (script loaded async/deferred)
        init();
    }

    // Public API
    return {
        init: init,
        destroy: destroy,
        refresh: refresh,
        updateAll: updateAll,
        formatCountdown: formatCountdown,
        formatCountup: formatCountup,
        formatDuration: formatDuration,
        getUrgencyClass: getUrgencyClass
    };

})();
