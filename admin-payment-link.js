/**
 * Admin Payment Link Functions
 * Handles sending payment links to customers for unpaid orders
 * 
 * Include this in admin-orders.php after other JS files
 */

// Send Payment Link to Customer
async function sendPaymentLink(referenceCode, sendEmail = true) {
    if (!referenceCode) {
        showNotification('No order selected', 'error');
        return;
    }
    
    // Confirm action
    const action = sendEmail ? 'send a payment link email to the customer' : 'generate a payment link';
    if (!confirm(`Are you sure you want to ${action} for order ${referenceCode}?`)) {
        return;
    }
    
    // Show loading state
    const btn = document.querySelector(`[onclick*="sendPaymentLink"][onclick*="${referenceCode}"]`);
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.innerHTML = '<span class="spinner-small"></span> Generating...';
        btn.disabled = true;
    }
    
    try {
        const response = await fetch('send-payment-link.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                referenceCode: referenceCode,
                sendEmail: sendEmail
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show success notification
            let message = result.message || 'Payment link created';
            showNotification(message, 'success');
            
            // Show the payment link for copying
            if (result.paymentUrl) {
                showPaymentLinkModal(referenceCode, result.paymentUrl, result.emailSent, result.expiresAt);
            }
            
            // Refresh the order view to show updated status
            if (typeof loadOrderDetail === 'function') {
                setTimeout(() => loadOrderDetail(referenceCode), 1000);
            }
        } else {
            showNotification(result.error || 'Failed to create payment link', 'error');
        }
        
    } catch (error) {
        console.error('Payment link error:', error);
        showNotification('Error creating payment link: ' + error.message, 'error');
    } finally {
        // Restore button state
        if (btn) {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
}

// Generate Payment Link Without Sending Email
async function generatePaymentLinkOnly(referenceCode) {
    return sendPaymentLink(referenceCode, false);
}

// Show Payment Link Modal
function showPaymentLinkModal(referenceCode, paymentUrl, emailSent, expiresAt) {
    // Remove existing modal if any
    const existingModal = document.getElementById('paymentLinkModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    const modal = document.createElement('div');
    modal.id = 'paymentLinkModal';
    modal.className = 'modal-overlay';
    modal.style.cssText = 'position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 10000;';
    
    const emailStatus = emailSent 
        ? '<div style="background: #ecfdf5; color: #059669; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9rem;">&#10004; Payment link emailed to customer</div>'
        : '<div style="background: #fef3c7; color: #92400e; padding: 10px; border-radius: 6px; margin-bottom: 15px; font-size: 0.9rem;">&#9888; Email not sent - share link manually</div>';
    
    modal.innerHTML = `
        <div style="background: white; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,0.2);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 style="margin: 0; color: #7c3aed; font-size: 1.3rem;">&#128279; Payment Link Created</h3>
                <button onclick="closePaymentLinkModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #9ca3af;">&times;</button>
            </div>
            
            ${emailStatus}
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; font-size: 0.85rem; color: #6b7280; margin-bottom: 6px;">Payment Link for Order ${referenceCode}:</label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="paymentLinkUrl" value="${paymentUrl}" readonly 
                           style="flex: 1; padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; font-size: 0.85rem; background: #f9fafb;">
                    <button onclick="copyPaymentLink()" 
                            style="padding: 10px 16px; background: #7c3aed; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        &#128203; Copy
                    </button>
                </div>
            </div>
            
            <div style="font-size: 0.8rem; color: #9ca3af; margin-bottom: 20px;">
                &#9200; Link expires: ${expiresAt || '24 hours from now'}
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button onclick="window.open('${paymentUrl}', '_blank')" 
                        style="padding: 10px 20px; background: #f3f4f6; color: #374151; border: none; border-radius: 6px; cursor: pointer; font-weight: 500;">
                    &#128279; Open Link
                </button>
                <button onclick="closePaymentLinkModal()" 
                        style="padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                    Done
                </button>
            </div>
        </div>
    `;
    
    document.body.appendChild(modal);
    
    // Close on backdrop click
    modal.addEventListener('click', (e) => {
        if (e.target === modal) {
            closePaymentLinkModal();
        }
    });
}

// Close Payment Link Modal
function closePaymentLinkModal() {
    const modal = document.getElementById('paymentLinkModal');
    if (modal) {
        modal.remove();
    }
}

// Copy Payment Link to Clipboard
function copyPaymentLink() {
    const input = document.getElementById('paymentLinkUrl');
    if (input) {
        input.select();
        document.execCommand('copy');
        
        // Show feedback
        showNotification('Payment link copied to clipboard!', 'success');
    }
}

// Add CSS for spinner
(function() {
    if (!document.getElementById('paymentLinkStyles')) {
        const style = document.createElement('style');
        style.id = 'paymentLinkStyles';
        style.textContent = `
            .spinner-small {
                display: inline-block;
                width: 14px;
                height: 14px;
                border: 2px solid #ffffff;
                border-radius: 50%;
                border-top-color: transparent;
                animation: spin 0.8s linear infinite;
                vertical-align: middle;
                margin-right: 6px;
            }
            
            @keyframes spin {
                to { transform: rotate(360deg); }
            }
            
            .payment-link-btn {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 8px 16px;
                background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 0.85rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .payment-link-btn:hover {
                background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);
                transform: translateY(-1px);
                box-shadow: 0 4px 12px rgba(124, 58, 237, 0.3);
            }
            
            .payment-link-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none;
            }
            
            .payment-link-btn-outline {
                background: white;
                color: #7c3aed;
                border: 2px solid #7c3aed;
            }
            
            .payment-link-btn-outline:hover {
                background: #faf5ff;
            }
        `;
        document.head.appendChild(style);
    }
})();

// Helper: Check if order needs payment link button
function shouldShowPaymentLinkButton(status) {
    const unpaidStatuses = ['unpaid', 'file_issue'];
    return unpaidStatuses.includes(status?.toLowerCase());
}

// Helper: Generate payment link button HTML
function getPaymentLinkButtonHTML(referenceCode) {
    return `
        <button class="payment-link-btn" onclick="sendPaymentLink('${referenceCode}', true)">
            &#128231; Send Payment Link
        </button>
        <button class="payment-link-btn payment-link-btn-outline" onclick="sendPaymentLink('${referenceCode}', false)" title="Generate link without emailing">
            &#128279; Copy Link Only
        </button>
    `;
}

console.log('[Payment Links] Admin payment link functions loaded');
