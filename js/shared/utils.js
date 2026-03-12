/**
 * Shared Utility Functions
 * MTCC Print Services
 *
 * Canonical versions of commonly-used functions.
 * Include this file BEFORE any page-specific JS that uses these functions.
 *
 * Functions provided:
 *   - formatFileSize(bytes)
 *   - escapeHtml(text)
 *   - showNotification(message, type)
 *   - hideNotification(notification)
 */

// ===== FILE SIZE FORMATTING =====
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ===== HTML ESCAPING UTILITY =====
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ===== NOTIFICATION SYSTEM =====
function showNotification(message, type = 'success') {
    // Remove any existing notifications
    const existingNotifications = document.querySelectorAll('.notification-slider');
    existingNotifications.forEach(notification => notification.remove());

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification-slider notification-${type}`;
    notification.innerHTML = `
        <div class="notification-content">
            <div class="notification-icon">
                ${type === 'success' ? '&#10004;' : '&#10060;'}
            </div>
            <div class="notification-message">${message}</div>
            <button class="notification-close" onclick="hideNotification(this.parentElement.parentElement)">&#10006;</button>
        </div>
    `;

    // Add to body
    document.body.appendChild(notification);

    // Trigger slide-in animation
    setTimeout(() => {
        notification.classList.add('show');
    }, 100);

    // Auto-hide after 5 seconds
    setTimeout(() => {
        hideNotification(notification);
    }, 5000);
}

function hideNotification(notification) {
    if (notification) {
        notification.classList.remove('show');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.parentElement.removeChild(notification);
            }
        }, 300);
    }
}
