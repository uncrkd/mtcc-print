/**
 * MTCC Haptic + Audio Module
 * Location: /js/mtcc-haptic.js
 *
 * Exposes window.mtccHaptic with the same API as courier/app.js's haptic
 * module: tap, success, error, warning, confirm, scanDetected, shutter.
 *
 * - Android: uses navigator.vibrate
 * - iOS: triggers the Taptic Engine via a hidden <input switch> / <label>
 *   (iOS 18+), which fires a native haptic when the label is clicked
 * - All platforms: WebAudio beep tones for audible feedback
 *
 * Call mtccHaptic.initAudio() from a user-gesture handler (first tap) so
 * iOS lets us resume the AudioContext.
 */
window.mtccHaptic = {
    vibrateSupported: ('vibrate' in navigator),
    audioCtx: null,
    audioEnabled: true,
    initialized: false,

    initAudio: function () {
        if (this.initialized) return;
        if (!this.audioCtx) {
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) {
                this.audioEnabled = false;
            }
        }
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
        this.initialized = true;
    },

    _ensureAudio: function () {
        if (!this.initialized) this.initAudio();
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
    },

    beep: function (frequency, duration, volume) {
        this._ensureAudio();
        frequency = frequency || 800;
        duration = duration || 100;
        volume = volume || 0.3;
        if (!this.audioEnabled || !this.audioCtx) return;
        try {
            var osc = this.audioCtx.createOscillator();
            var g = this.audioCtx.createGain();
            osc.connect(g);
            g.connect(this.audioCtx.destination);
            osc.frequency.value = frequency;
            osc.type = 'sine';
            g.gain.setValueAtTime(volume, this.audioCtx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.01, this.audioCtx.currentTime + duration / 1000);
            osc.start(this.audioCtx.currentTime);
            osc.stop(this.audioCtx.currentTime + duration / 1000);
        } catch (e) {}
    },

    // iOS Taptic Engine via switch-checkbox label trick (iOS 18+)
    _hapticInput: null,
    _hapticLabel: null,
    _hapticReady: false,
    _initHaptic: function () {
        if (this._hapticReady) return;
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.setAttribute('switch', '');
        input.id = '_mtcc_haptic_switch';
        input.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;pointer-events:none;';
        input.setAttribute('aria-hidden', 'true');
        input.setAttribute('tabindex', '-1');
        var label = document.createElement('label');
        label.setAttribute('for', '_mtcc_haptic_switch');
        label.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;pointer-events:none;';
        label.setAttribute('aria-hidden', 'true');
        document.body.appendChild(input);
        document.body.appendChild(label);
        this._hapticInput = input;
        this._hapticLabel = label;
        this._hapticReady = true;
    },
    _iosHaptic: function () {
        if (!this._hapticReady) this._initHaptic();
        if (this._hapticLabel) this._hapticLabel.click();
    },

    tap: function () {
        if (this.vibrateSupported) navigator.vibrate(10);
        else this._iosHaptic();
    },
    success: function () {
        if (this.vibrateSupported) navigator.vibrate(100);
        else this._iosHaptic();
        this.beep(1200, 150, 0.3);
    },
    error: function () {
        if (this.vibrateSupported) navigator.vibrate([100, 50, 100]);
        else {
            var self = this;
            this._iosHaptic();
            setTimeout(function () { self._iosHaptic(); }, 100);
        }
        var self = this;
        this.beep(400, 150, 0.4);
        setTimeout(function () { self.beep(300, 200, 0.4); }, 200);
    },
    warning: function () {
        if (this.vibrateSupported) navigator.vibrate([50, 30, 50, 30, 50]);
        else {
            var self = this;
            this._iosHaptic();
            setTimeout(function () { self._iosHaptic(); }, 80);
            setTimeout(function () { self._iosHaptic(); }, 160);
        }
        var s = this;
        this.beep(600, 80, 0.3);
        setTimeout(function () { s.beep(600, 80, 0.3); }, 120);
        setTimeout(function () { s.beep(600, 80, 0.3); }, 240);
    },
    confirm: function () {
        if (this.vibrateSupported) navigator.vibrate(200);
        else this._iosHaptic();
        var s = this;
        this.beep(800, 100, 0.3);
        setTimeout(function () { s.beep(1200, 150, 0.3); }, 100);
    },
    scanDetected: function () {
        if (this.vibrateSupported) navigator.vibrate(50);
        else this._iosHaptic();
        this.beep(1500, 80, 0.25);
    },
    shutter: function () {
        if (this.vibrateSupported) navigator.vibrate(30);
        this.beep(1000, 50, 0.2);
    }
};

// Unlock audio on the first user gesture (iOS requires this)
(function () {
    function unlock() {
        window.mtccHaptic.initAudio();
        document.removeEventListener('touchstart', unlock);
        document.removeEventListener('click', unlock);
    }
    document.addEventListener('touchstart', unlock, { passive: true, once: true });
    document.addEventListener('click', unlock, { once: true });
})();
