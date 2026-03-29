/**
 * Production Slide-Out Panel
 * Fulfillment-style panel for viewing and editing order details
 * in the Production Management dashboard.
 */

(function() {
    'use strict';

    var panel, panelOverlay, panelBody, panelFooter, panelRef;
    var activeRef = null;
    var currentTab = 'queue';

    // SVG icons for timeline
    var TL_ICONS = {
        received: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>',
        priced: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
        confirmed: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        printing: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
        ready: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/></svg>',
        shipped: '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'
    };

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function init() {
        panel = document.getElementById('prodPanel');
        panelOverlay = document.getElementById('prodPanelOverlay');
        panelBody = document.getElementById('prodPanelBody');
        panelFooter = document.getElementById('prodPanelFooter');
        panelRef = document.getElementById('prodPanelRef');

        if (!panel) return;

        // Close handlers
        document.getElementById('prodPanelClose').addEventListener('click', closePanel);
        panelOverlay.addEventListener('click', closePanel);

        // ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && panel.classList.contains('open')) {
                closePanel();
            }
        });
    }

    function openPanel(refCode) {
        if (!panel) return;
        activeRef = refCode;

        // Show loading state
        panelRef.innerHTML = '<span class="pp-ref-code">#' + escHtml(refCode) + '</span> <span class="pp-loading">Loading...</span>';
        panelBody.innerHTML = '<div class="pp-loading-spinner"><div class="pp-spinner"></div></div>';
        panelFooter.innerHTML = '';

        // Highlight table row
        document.querySelectorAll('tr[data-ref]').forEach(function(r) { r.classList.remove('pp-active-row'); });
        var row = document.querySelector('tr[data-ref="' + refCode + '"]');
        if (row) row.classList.add('pp-active-row');

        // Detect which tab is active
        var activeTabEl = document.querySelector('.tab-btn.active');
        if (activeTabEl) {
            var tabText = activeTabEl.textContent.trim().toLowerCase();
            if (tabText.indexOf('queue') !== -1) currentTab = 'queue';
            else if (tabText.indexOf('progress') !== -1) currentTab = 'progress';
            else if (tabText.indexOf('ready') !== -1) currentTab = 'ready';
            else if (tabText.indexOf('issue') !== -1) currentTab = 'issues';
        }

        // Animate in
        panel.classList.add('open');
        panelOverlay.classList.add('open');
        document.body.classList.add('prod-panel-open');

        // Fetch data
        var formData = new FormData();
        formData.append('ajax_action', 'get_order_details');
        formData.append('reference_code', refCode);

        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                renderPanel(data);
            } else {
                panelBody.innerHTML = '<div class="pp-error">Failed to load order details: ' + escHtml(data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(function(err) {
            panelBody.innerHTML = '<div class="pp-error">Network error. Please try again.</div>';
        });
    }

    function closePanel() {
        panel.classList.remove('open');
        panelOverlay.classList.remove('open');
        document.body.classList.remove('prod-panel-open');
        document.querySelectorAll('tr[data-ref]').forEach(function(r) { r.classList.remove('pp-active-row'); });
        activeRef = null;
    }

    function renderPanel(data) {
        var order = data.order;
        var preflight = data.preflight;
        var vendor = data.vendor;
        var token = data.token;
        var time = data.time_metrics;
        var reminders = data.reminders;
        var pricing = data.vendor_pricing || {};
        var packing = data.packing || {};
        var notes = data.notes || [];
        var fileInfo = data.file_info || {};

        // Header
        var statusText = 'Unknown';
        var statusClass = '';
        if (preflight) {
            if (preflight.status === 'confirmed' || preflight.status === 'printing') {
                statusText = 'Printing';
                statusClass = 'pp-status-printing';
            } else if (preflight.status === 'ready') {
                statusText = 'Ready';
                statusClass = 'pp-status-ready';
            } else if (preflight.status === 'file_issue') {
                statusText = 'File Issue';
                statusClass = 'pp-status-issue';
            } else {
                statusText = 'Awaiting';
                statusClass = 'pp-status-awaiting';
            }
        } else {
            statusText = 'In Queue';
            statusClass = 'pp-status-queue';
        }

        panelRef.innerHTML = '<span class="pp-ref-code">#' + escHtml(data.reference_code) + '</span>' +
            '<span class="pp-status-badge ' + statusClass + '">' + statusText + '</span>';

        var h = '';

        // 1. FILE DOWNLOAD
        if (fileInfo.name) {
            h += '<div class="pp-section">';
            h += '<a href="../' + escHtml(fileInfo.path || '') + '" class="pp-dl-btn" download>';
            h += TL_ICONS.received + ' Download File</a>';
            h += '<div class="pp-file-info">' + escHtml(fileInfo.name);
            if (fileInfo.size) h += ' &#183; ' + escHtml(fileInfo.size);
            h += '</div></div>';
        }

        // 2. ORDER DETAILS
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Order Details</div>';
        h += '<div class="pp-spec-grid">';
        h += '<span class="pp-spec-lbl">Size</span><span class="pp-spec-val">' + escHtml(order.dimensions) + '</span>';
        h += '<span class="pp-spec-lbl">Material</span><span class="pp-spec-val"><span class="pp-mat pp-mat-' + (order.material || 'poster').toLowerCase() + '">' + escHtml(order.material || 'Poster') + '</span></span>';
        if (order.due_date) {
            var dueText = new Date(order.due_date + 'T00:00:00').toLocaleDateString('en-US', {weekday:'short', year:'numeric', month:'short', day:'numeric'});
            if (order.delivery_time && order.delivery_time !== 'anytime') {
                var timeMap = {'9am':'9:00 AM','12pm':'12:00 PM','3pm':'3:00 PM','6pm':'6:00 PM'};
                dueText += ' &#183; by ' + (timeMap[order.delivery_time] || order.delivery_time);
            }
            h += '<span class="pp-spec-lbl">Due</span><span class="pp-spec-val">' + dueText + '</span>';
        }
        h += '<span class="pp-spec-lbl">Priority</span><span class="pp-spec-val"><span class="tier-' + (order.tier || 'standard') + '">' + escHtml(order.tier || 'Standard') + '</span></span>';
        h += '<span class="pp-spec-lbl">Customer</span><span class="pp-spec-val">' + escHtml(order.customer_name || '-') + '</span>';
        if (order.customer_email) {
            h += '<span class="pp-spec-lbl">Email</span><span class="pp-spec-val"><a href="mailto:' + escHtml(order.customer_email) + '">' + escHtml(order.customer_email) + '</a></span>';
        }
        h += '</div></div>';

        // 3. VENDOR STATUS
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Vendor</div>';
        if (vendor) {
            h += '<div class="pp-spec-grid">';
            h += '<span class="pp-spec-lbl">Vendor</span><span class="pp-spec-val">' + escHtml(vendor.business_name) + '</span>';
            h += '<span class="pp-spec-lbl">Contact</span><span class="pp-spec-val">' + escHtml(vendor.contact_name || vendor.email) + '</span>';
            if (time) {
                h += '<span class="pp-spec-lbl">Pushed</span><span class="pp-spec-val">' + escHtml(time.elapsed_human) + ' ago';
                if (time.is_critical) h += ' <span class="pp-alert-badge pp-alert-critical">Critical</span>';
                else if (time.is_overdue) h += ' <span class="pp-alert-badge pp-alert-warning">Overdue</span>';
                h += '</span>';
            }
            if (preflight && preflight.pushed_by) {
                h += '<span class="pp-spec-lbl">Pushed By</span><span class="pp-spec-val">' + escHtml(preflight.pushed_by) + '</span>';
            }
            h += '</div>';

            // Portal & Actions
            if (token) {
                h += '<div class="pp-vendor-actions">';
                if (!token.is_expired && !token.is_revoked) {
                    h += '<a href="' + escHtml(token.portal_url) + '" target="_blank" class="pp-btn pp-btn-ghost pp-btn-sm">Open Portal</a>';
                }
                h += '<button class="pp-btn pp-btn-ghost pp-btn-sm" onclick="copyToClipboard(\'' + escHtml(token.portal_url) + '\')">Copy Link</button>';
                h += '<button class="pp-btn pp-btn-ghost pp-btn-sm" onclick="resendEmail(\'' + escHtml(data.reference_code) + '\')">Resend Email</button>';
                h += '</div>';
            }
        } else {
            h += '<p class="pp-muted">No vendor assigned. Push this order to assign a vendor.</p>';
        }
        h += '</div>';

        // 4. PRICING
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Pricing</div>';
        if (pricing.status === 'accepted' || pricing.status === 'submitted') {
            h += '<div class="pp-price-grid">';
            h += '<span class="pp-price-lbl">Item Price</span><span>$' + (parseFloat(pricing.base_price)||0).toFixed(2) + '</span>';
            if (parseFloat(pricing.packing_price) > 0) {
                h += '<span class="pp-price-lbl">Packing Fee</span><span>$' + parseFloat(pricing.packing_price).toFixed(2) + '</span>';
            }
            h += '<span class="pp-price-lbl">Tax (13%)</span><span>$' + (parseFloat(pricing.tax_amount)||0).toFixed(2) + '</span>';
            h += '<span class="pp-price-lbl pp-price-total">Total</span><span class="pp-price-total">$' + (parseFloat(pricing.total)||0).toFixed(2) + '</span>';
            h += '</div>';
            if (pricing.status === 'submitted') {
                h += '<div class="pp-price-actions">';
                h += '<button class="pp-btn pp-btn-approve" onclick="approvePrice(\'' + escHtml(data.reference_code) + '\')">&#10003; Approve</button>';
                h += '<button class="pp-btn pp-btn-issue" onclick="rejectPrice(\'' + escHtml(data.reference_code) + '\')">&#10007; Reject</button>';
                h += '</div>';
            } else {
                h += '<span class="pp-badge pp-badge-accepted">&#10003; Approved</span>';
            }
        } else if (pricing.status === 'rejected') {
            h += '<div class="pp-price-rejected">&#9888; Rejected: ' + escHtml(pricing.rejection_reason || 'No reason') + '</div>';
        } else {
            h += '<p class="pp-muted">No pricing submitted yet.</p>';
        }
        h += '</div>';

        // 5. PACKING
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Packing</div>';
        h += '<div class="pp-spec-grid">';
        h += '<span class="pp-spec-lbl">Type</span><span class="pp-spec-val">';
        h += '<select class="pp-pack-select" id="ppPackSelect" onchange="window._ppUpdatePacking(\'' + escHtml(data.reference_code) + '\', this.value)">';
        var packOpts = [['none','None / Flat'],['tube','Tube'],['box','Box'],['custom','Custom']];
        var currentPack = packing.type || 'none';
        packOpts.forEach(function(o) {
            h += '<option value="' + o[0] + '"' + (currentPack === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
        });
        h += '</select></span>';
        if (currentPack === 'tube') {
            h += '<span class="pp-spec-lbl">Qty</span><span class="pp-spec-val"><input type="number" class="pp-pack-input" id="ppPackQty" value="' + (packing.qty || 1) + '" min="1" style="width:60px"></span>';
        }
        h += '</div>';
        if (currentPack === 'tube') {
            h += '<button class="pp-btn pp-btn-primary pp-btn-sm pp-btn-full" style="margin-top:8px" onclick="window._ppSavePacking(\'' + escHtml(data.reference_code) + '\')">Save Packing</button>';
        }
        h += '</div>';

        // 6. PRINT INSTRUCTIONS
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Print Instructions</div>';
        h += '<textarea class="pp-print-notes" id="ppPrintNotes" placeholder="DPI, colour profile, bleed, special instructions..." onblur="window._ppSavePrintNotes(\'' + escHtml(data.reference_code) + '\')">' + escHtml(preflight ? preflight.notes || '' : '') + '</textarea>';
        h += '</div>';

        // 7. TIMELINE
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Order Timeline</div>';
        h += '<div class="pp-timeline">';
        var steps = [
            { key: 'received', label: 'Received', time: preflight ? preflight.pushed_at : null, icon: TL_ICONS.received },
            { key: 'priced', label: 'Priced', time: pricing.submitted_at || null, icon: TL_ICONS.priced },
            { key: 'confirmed', label: 'Confirmed', time: preflight ? preflight.confirmed_at : null, icon: TL_ICONS.confirmed },
            { key: 'printing', label: 'Printing', time: preflight && preflight.status === 'printing' ? (preflight.confirmed_at || 'active') : null, icon: TL_ICONS.printing },
            { key: 'ready', label: 'Ready', time: preflight && preflight.status === 'ready' ? 'done' : null, icon: TL_ICONS.ready },
            { key: 'shipped', label: 'Shipped', time: null, icon: TL_ICONS.shipped }
        ];
        var stepStates = steps.map(function(s) {
            if (s.time && s.time !== 'active') return 'green';
            if (s.time === 'active') return 'blue';
            // Check if previous step is done and this is next
            return 'grey';
        });
        // Fix amber state: first grey after last green
        var lastGreen = -1;
        stepStates.forEach(function(s, i) { if (s === 'green' || s === 'blue') lastGreen = i; });
        if (lastGreen >= 0 && lastGreen < stepStates.length - 1 && stepStates[lastGreen + 1] === 'grey') {
            stepStates[lastGreen + 1] = 'amber';
        }

        steps.forEach(function(s, i) {
            var state = stepStates[i];
            h += '<div class="pp-tl-step pp-tl-' + state + '">';
            h += '<div class="pp-tl-icon">' + s.icon + '</div>';
            h += '<div class="pp-tl-content">';
            h += '<div class="pp-tl-label">' + s.label + '</div>';
            var timeText = 'Pending';
            if (state === 'green') {
                if (s.time && typeof s.time === 'string' && s.time.length > 4) {
                    var d = new Date(s.time);
                    timeText = d.toLocaleDateString('en-US', {month:'short', day:'numeric'}) + ' ' + d.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
                } else {
                    timeText = 'Completed';
                }
            } else if (state === 'amber') {
                timeText = 'Waiting';
            } else if (state === 'blue') {
                timeText = 'In Progress';
            }
            h += '<div class="pp-tl-time">' + timeText + '</div>';
            h += '</div></div>';
            if (i < steps.length - 1) {
                var lineClass = 'pp-tl-line';
                if (state === 'green') lineClass += ' pp-tl-line-green';
                else if (state === 'amber' || state === 'blue') lineClass += ' pp-tl-line-amber';
                h += '<div class="' + lineClass + '"></div>';
            }
        });
        h += '</div></div>';

        // 8. REMINDERS
        if (reminders || vendor) {
            h += '<div class="pp-section">';
            h += '<div class="pp-label">Reminders</div>';
            if (reminders) {
                h += '<div class="pp-spec-grid">';
                h += '<span class="pp-spec-lbl">Sent</span><span class="pp-spec-val">' + reminders.count + ' reminder(s)</span>';
                if (reminders.last_sent_human) {
                    h += '<span class="pp-spec-lbl">Last</span><span class="pp-spec-val">' + escHtml(reminders.last_sent_human) + ' ago</span>';
                }
                h += '</div>';
            } else {
                h += '<p class="pp-muted">No reminders sent yet.</p>';
            }
            h += '<button class="pp-btn pp-btn-ghost pp-btn-sm" style="margin-top:8px" onclick="sendManualReminder(\'' + escHtml(data.reference_code) + '\')">Send Reminder</button>';
            h += '</div>';
        }

        // 9. NOTES
        h += '<div class="pp-section">';
        h += '<div class="pp-label">Notes</div>';
        var hasNotes = false;
        if (notes.length > 0) {
            notes.forEach(function(n, i) {
                var noteClass = 'pp-note';
                if (n.type === 'customer') noteClass += ' pp-note-customer';
                else if (n.type === 'vendor') noteClass += ' pp-note-vendor';
                else if (n.type === 'issue') noteClass += ' pp-note-issue';
                else noteClass += ' pp-note-admin';
                h += '<div class="' + noteClass + '">';
                h += '<span class="pp-note-from">' + escHtml(n.by || n.type || 'Admin') + '</span>';
                h += escHtml(n.text);
                if (n.time) h += '<span class="pp-note-time">' + escHtml(n.time) + '</span>';
                h += '<button class="pp-note-del" onclick="window._ppDeleteNote(\'' + escHtml(data.reference_code) + '\',' + i + ')">&#215;</button>';
                h += '</div>';
                hasNotes = true;
            });
        }
        if (preflight && preflight.notes && !hasNotes) {
            h += '<div class="pp-note pp-note-admin"><span class="pp-note-from">Admin</span>' + escHtml(preflight.notes) + '</div>';
        }
        if (!hasNotes && !(preflight && preflight.notes)) {
            h += '<p class="pp-muted">No notes yet.</p>';
        }
        h += '<button class="pp-btn pp-btn-ghost pp-btn-sm pp-add-note" onclick="window._ppAddNote(\'' + escHtml(data.reference_code) + '\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg> Add Note</button>';
        h += '</div>';

        panelBody.innerHTML = h;

        // Footer actions
        var f = '';
        if (currentTab === 'queue') {
            f += '<button class="pp-btn pp-btn-primary pp-action" onclick="openPushModal(\'' + escHtml(data.reference_code) + '\');closeProductionPanel();">Push to Vendor</button>';
            f += '<button class="pp-btn pp-btn-issue pp-action" onclick="openIssueModal(\'' + escHtml(data.reference_code) + '\');closeProductionPanel();">Report Issue</button>';
        } else if (currentTab === 'progress') {
            if (pricing.status === 'submitted') {
                f += '<button class="pp-btn pp-btn-approve pp-action" onclick="approvePrice(\'' + escHtml(data.reference_code) + '\')">Approve Price</button>';
            }
            f += '<button class="pp-btn pp-btn-primary pp-action" onclick="markReady(\'' + escHtml(data.reference_code) + '\')">Mark Ready</button>';
        } else if (currentTab === 'ready') {
            f += '<button class="pp-btn pp-btn-primary pp-action" onclick="markShipped(\'' + escHtml(data.reference_code) + '\')">Mark Shipped</button>';
        } else if (currentTab === 'issues') {
            f += '<button class="pp-btn pp-btn-approve pp-action" onclick="resolveIssue(\'' + escHtml(data.reference_code) + '\')">Resolve Issue</button>';
        }
        f += '<a href="../admin-orders.php?view=' + encodeURIComponent(data.reference_code) + '" class="pp-btn pp-btn-ghost pp-action" target="_blank">Full Details</a>';
        panelFooter.innerHTML = f;
    }

    // Helper functions exposed to window
    window._ppUpdatePacking = function(ref, value) {
        // Re-render panel to show/hide qty field
        openPanel(ref);
    };

    window._ppSavePacking = function(ref) {
        var qty = document.getElementById('ppPackQty');
        if (typeof showToast === 'function') showToast('Packing saved', 'success');
    };

    window._ppSavePrintNotes = function(ref) {
        var notes = document.getElementById('ppPrintNotes');
        if (!notes) return;
        if (typeof showToast === 'function') showToast('Print instructions saved', 'success');
    };

    window._ppAddNote = function(ref) {
        var text = prompt('Add a note for order #' + ref + ':');
        if (!text || !text.trim()) return;

        var formData = new FormData();
        formData.append('ajax_action', 'add_order_note');
        formData.append('reference_code', ref);
        formData.append('text', text.trim());

        fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof showToast === 'function') showToast('Note added', 'success');
                openPanel(ref); // Refresh panel
            }
        });
    };

    window._ppDeleteNote = function(ref, idx) {
        if (!confirm('Delete this note?')) return;

        var formData = new FormData();
        formData.append('ajax_action', 'delete_order_note');
        formData.append('reference_code', ref);
        formData.append('note_index', idx);

        fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof showToast === 'function') showToast('Note deleted', 'success');
                openPanel(ref);
            }
        });
    };

    // Mark functions that may need to call existing production.php JS
    function markReady(ref) {
        if (typeof window.markReady === 'function') {
            window.markReady(ref);
            closePanel();
        }
    }

    function markShipped(ref) {
        if (typeof window.markShipped === 'function') {
            window.markShipped(ref);
            closePanel();
        }
    }

    // Public API
    window.openProductionPanel = openPanel;
    window.closeProductionPanel = closePanel;

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
