/**
 * Fulfillment Audio Notification System
 * Location: /fulfillment/fulfillment-audio.js
 *
 * Web Audio API tone generation (no external audio files).
 * Different tones based on delivery urgency:
 *   - Triple beep (800Hz) for same-day orders
 *   - Double beep (600Hz) for next-day orders
 *   - Single beep (400Hz) for standard orders
 *   - Descending chime for file issues
 *   - Rising chime for new order (generic)
 */

var FulfillmentAudio = (function() {
    'use strict';

    var _ctx = null;
    var _muted = false;
    var MUTE_KEY = 'fp_audio_muted';

    function _init() {
        if (_ctx) return;
        try {
            _ctx = new (window.AudioContext || window.webkitAudioContext)();
        } catch(e) {
            console.warn('[Audio] Web Audio API not available');
        }
    }

    function _playTone(freq, startOffset, duration, volume) {
        if (!_ctx || _muted) return;
        try {
            var now = _ctx.currentTime;
            var osc = _ctx.createOscillator();
            var gain = _ctx.createGain();
            osc.frequency.value = freq;
            osc.type = 'sine';
            gain.gain.setValueAtTime(volume || 0.2, now + startOffset);
            gain.gain.exponentialRampToValueAtTime(0.001, now + startOffset + (duration || 0.25));
            osc.connect(gain);
            gain.connect(_ctx.destination);
            osc.start(now + startOffset);
            osc.stop(now + startOffset + (duration || 0.25));
        } catch(e) {}
    }

    // ============================================
    // PUBLIC SOUND METHODS
    // ============================================

    /** Triple beep - SAME DAY urgency */
    function playSameDay() {
        _init();
        _playTone(800, 0, 0.15, 0.25);
        _playTone(800, 0.2, 0.15, 0.25);
        _playTone(800, 0.4, 0.15, 0.25);
    }

    /** Double beep - NEXT DAY urgency */
    function playNextDay() {
        _init();
        _playTone(600, 0, 0.2, 0.2);
        _playTone(600, 0.3, 0.2, 0.2);
    }

    /** Single beep - STANDARD */
    function playStandard() {
        _init();
        _playTone(400, 0, 0.3, 0.18);
    }

    /** Descending chime - FILE ISSUE alert */
    function playIssueAlert() {
        _init();
        _playTone(700, 0, 0.2, 0.2);
        _playTone(550, 0.2, 0.2, 0.2);
        _playTone(400, 0.4, 0.3, 0.2);
    }

    /** Rising chime - GENERIC new order */
    function playNewOrder() {
        _init();
        _playTone(523, 0, 0.2, 0.2);
        _playTone(659, 0.15, 0.2, 0.2);
        _playTone(784, 0.3, 0.3, 0.2);
    }

    /** Play the appropriate sound for an order's urgency tier */
    function playForTier(tier) {
        tier = (tier || '').toLowerCase();
        if (tier === 'sameday' || tier === 'same_day') playSameDay();
        else if (tier === 'nextday' || tier === 'next_day') playNextDay();
        else playStandard();
    }

    // ============================================
    // MUTE CONTROL
    // ============================================

    function isMuted() { return _muted; }

    function setMuted(val) {
        _muted = !!val;
        try { localStorage.setItem(MUTE_KEY, _muted ? '1' : '0'); } catch(e) {}
        _updateMuteUI();
    }

    function toggleMute() {
        setMuted(!_muted);
        return _muted;
    }

    function _loadMuteState() {
        try {
            var stored = localStorage.getItem(MUTE_KEY);
            if (stored === '1') _muted = true;
        } catch(e) {}
    }

    function _updateMuteUI() {
        var btn = document.getElementById('fpMuteBtn');
        if (!btn) return;
        btn.innerHTML = _muted
            ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5L6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>'
            : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 010 7.07"/><path d="M19.07 4.93a10 10 0 010 14.14"/></svg>';
        btn.title = _muted ? 'Sound off (click to unmute)' : 'Sound on (click to mute)';
        btn.classList.toggle('muted', _muted);
    }

    // Init mute state on load
    _loadMuteState();

    return {
        init: _init,
        playSameDay: playSameDay,
        playNextDay: playNextDay,
        playStandard: playStandard,
        playIssueAlert: playIssueAlert,
        playNewOrder: playNewOrder,
        playForTier: playForTier,
        isMuted: isMuted,
        setMuted: setMuted,
        toggleMute: toggleMute
    };

})();
