/**
 * Haptic & Audio Feedback Module for Dispatch Scanner
 * Works on all devices - uses vibration on Android, audio on iOS/desktop
 * 
 * Add to dispatch/index.php before </body>:
 * <script src="dispatch-haptics.js"></script>
 */

const haptic = {
    // Check if vibration is supported
    vibrateSupported: 'vibrate' in navigator,
    
    // Audio context for generating beeps
    audioCtx: null,
    audioEnabled: true,
    
    // Initialize audio context (must be called after user interaction)
    initAudio: function() {
        if (!this.audioCtx) {
            try {
                this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            } catch (e) {
                console.warn('Audio not supported:', e);
                this.audioEnabled = false;
            }
        }
        // Resume if suspended (iOS requirement)
        if (this.audioCtx && this.audioCtx.state === 'suspended') {
            this.audioCtx.resume();
        }
    },
    
    // Play a beep tone
    beep: function(frequency = 800, duration = 100, volume = 0.3) {
        if (!this.audioEnabled || !this.audioCtx) return;
        
        try {
            const oscillator = this.audioCtx.createOscillator();
            const gainNode = this.audioCtx.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(this.audioCtx.destination);
            
            oscillator.frequency.value = frequency;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(volume, this.audioCtx.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, this.audioCtx.currentTime + duration / 1000);
            
            oscillator.start(this.audioCtx.currentTime);
            oscillator.stop(this.audioCtx.currentTime + duration / 1000);
        } catch (e) {
            console.warn('Beep failed:', e);
        }
    },
    
    // Light tap - for button presses, keypad
    tap: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(15);
        }
        // No audio for taps (would be annoying)
    },
    
    // Success - scan found, login success, status updated
    success: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(100);
        }
        // High-pitched short beep
        this.beep(1200, 150, 0.3);
    },
    
    // Error - scan failed, order not found, login failed
    error: function() {
        if (this.vibrateSupported) {
            navigator.vibrate([100, 50, 100]);
        }
        // Two low beeps
        this.beep(400, 150, 0.4);
        setTimeout(() => this.beep(300, 200, 0.4), 200);
    },
    
    // Warning - permission denied, validation error
    warning: function() {
        if (this.vibrateSupported) {
            navigator.vibrate([50, 30, 50, 30, 50]);
        }
        // Three quick beeps
        this.beep(600, 80, 0.3);
        setTimeout(() => this.beep(600, 80, 0.3), 120);
        setTimeout(() => this.beep(600, 80, 0.3), 240);
    },
    
    // Confirm - status change confirmed (most satisfying)
    confirm: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(200);
        }
        // Rising two-tone success sound
        this.beep(800, 100, 0.3);
        setTimeout(() => this.beep(1200, 150, 0.3), 100);
    },
    
    // Selection - very light feedback
    select: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(10);
        }
        // Soft click
        this.beep(1000, 30, 0.1);
    },
    
    // Scan detected - immediate feedback when barcode detected
    scanDetected: function() {
        if (this.vibrateSupported) {
            navigator.vibrate(50);
        }
        // Quick high beep
        this.beep(1500, 80, 0.25);
    }
};

// Initialize on first user interaction (required for iOS audio)
document.addEventListener('DOMContentLoaded', function() {
    // Initialize audio on first touch/click
    const initOnInteraction = function() {
        haptic.initAudio();
        document.removeEventListener('touchstart', initOnInteraction);
        document.removeEventListener('click', initOnInteraction);
    };
    
    document.addEventListener('touchstart', initOnInteraction, { once: true });
    document.addEventListener('click', initOnInteraction, { once: true });
    
    // Add tap feedback to PIN keypad buttons
    document.querySelectorAll('.key-btn').forEach(btn => {
        btn.addEventListener('touchstart', () => {
            haptic.initAudio(); // Ensure audio is ready
            haptic.tap();
        });
        btn.addEventListener('click', () => {
            haptic.initAudio();
            haptic.tap();
        });
    });
    
    // Add tap feedback to all other buttons
    document.querySelectorAll('.btn, .btn-suggested, .btn-status, .btn-back, .btn-logout').forEach(btn => {
        btn.addEventListener('touchstart', () => {
            haptic.initAudio();
            haptic.tap();
        });
    });
});

