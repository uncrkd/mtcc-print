/**
 * Dispatch Hub JavaScript
 * Location: /dispatch/dispatch-hub.js
 * 
 * Handles: tab switching, bulk selection, courier assignment, 
 * bad weather toggle, auto-refresh, toast notifications
 */

// ============================================
// Tab Switching
// ============================================

function switchDispatchTab(tab) {
    // Hide all tab contents
    document.querySelectorAll('.dispatch-tab-content').forEach(function(el) {
        el.style.display = 'none';
    });
    
    // Deactivate all tabs
    document.querySelectorAll('.dispatch-tab').forEach(function(el) {
        el.classList.remove('active');
    });
    
    // Show selected tab content
    var tabMap = {
        'ready': 'tabReady',
        'active': 'tabActive',
        'completed': 'tabCompleted',
        'issues': 'tabIssues'
    };
    
    var contentEl = document.getElementById(tabMap[tab]);
    if (contentEl) contentEl.style.display = '';
    
    // Activate tab button
    var tabs = document.querySelectorAll('.dispatch-tab');
    var tabIndex = { 'ready': 0, 'active': 1, 'completed': 2, 'issues': 3 };
    if (tabs[tabIndex[tab]]) {
        tabs[tabIndex[tab]].classList.add('active');
    }
    
    // Hide bulk bar when switching away from ready queue
    if (tab !== 'ready') {
        clearSelection();
    }
    
    // Update URL without reload
    var url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

// ============================================
// Checkbox & Bulk Selection
// ============================================

function toggleSelectAll(masterCheckbox) {
    var checkboxes = document.querySelectorAll('.order-check');
    checkboxes.forEach(function(cb) {
        cb.checked = masterCheckbox.checked;
    });
    updateBulkBar();
}

function updateBulkBar() {
    var checked = document.querySelectorAll('.order-check:checked');
    var bulkBar = document.getElementById('bulkActionBar');
    var bulkCount = document.getElementById('bulkCount');
    var selectAll = document.getElementById('selectAll');
    
    if (checked.length > 0) {
        bulkBar.style.display = 'flex';
        bulkCount.textContent = checked.length;
    } else {
        bulkBar.style.display = 'none';
    }
    
    // Update select-all checkbox state
    var allCheckboxes = document.querySelectorAll('.order-check');
    if (selectAll) {
        selectAll.checked = allCheckboxes.length > 0 && checked.length === allCheckboxes.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < allCheckboxes.length;
    }
}

function clearSelection() {
    document.querySelectorAll('.order-check').forEach(function(cb) {
        cb.checked = false;
    });
    var selectAll = document.getElementById('selectAll');
    if (selectAll) {
        selectAll.checked = false;
        selectAll.indeterminate = false;
    }
    updateBulkBar();
}

function getSelectedRefs() {
    var refs = [];
    document.querySelectorAll('.order-check:checked').forEach(function(cb) {
        refs.push(cb.value);
    });
    return refs;
}

// ============================================
// Courier Assignment
// ============================================

function quickAssign(selectEl) {
    var ref = selectEl.getAttribute('data-ref');
    var courierId = selectEl.value;
    
    if (!courierId) return;
    
    var courierName = selectEl.options[selectEl.selectedIndex].getAttribute('data-name');
    
    if (!confirm('Assign ' + ref + ' to ' + courierName + '?\n\nThis will change the status to Dispatched.')) {
        selectEl.value = '';
        return;
    }
    
    assignCourier(ref, courierId, courierName, function(success) {
        if (success) {
            // Remove row from ready queue with animation
            var row = selectEl.closest('tr');
            if (row) {
                row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(20px)';
                setTimeout(function() {
                    row.remove();
                    updateSummaryCounts();
                    checkEmptyQueue();
                }, 300);
            }
            showToast('Assigned ' + ref + ' to ' + courierName, 'success');
        } else {
            selectEl.value = '';
            showToast('Failed to assign courier', 'error');
        }
    });
}

function assignAllSelected() {
    var select = document.getElementById('bulkAssignCourier');
    var courierId = select.value;
    if (!courierId) {
        showToast('Select a courier first', 'error');
        return;
    }
    
    var courierName = select.options[select.selectedIndex].getAttribute('data-name');
    var refs = getSelectedRefs();
    
    if (refs.length === 0) {
        showToast('No orders selected', 'error');
        return;
    }
    
    if (!confirm('Assign ' + refs.length + ' order(s) to ' + courierName + '?')) {
        return;
    }
    
    var completed = 0;
    var errors = 0;
    
    refs.forEach(function(ref) {
        assignCourier(ref, courierId, courierName, function(success) {
            if (success) {
                completed++;
                // Remove row
                var row = document.querySelector('tr[data-ref="' + ref + '"]');
                if (row) {
                    row.style.transition = 'opacity 0.3s ease';
                    row.style.opacity = '0';
                    setTimeout(function() { row.remove(); }, 300);
                }
            } else {
                errors++;
            }
            
            if (completed + errors === refs.length) {
                clearSelection();
                select.value = '';
                updateSummaryCounts();
                setTimeout(checkEmptyQueue, 400);
                
                if (errors > 0) {
                    showToast(completed + ' assigned, ' + errors + ' failed', 'error');
                } else {
                    showToast(completed + ' order(s) assigned to ' + courierName, 'success');
                }
            }
        });
    });
}

function assignCourier(ref, courierId, courierName, callback) {
    var formData = new FormData();
    formData.append('ajax_action', 'assign_courier');
    formData.append('reference_code', ref);
    formData.append('courier_id', courierId);
    formData.append('courier_name', courierName);
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        callback(data.success);
    })
    .catch(function() {
        callback(false);
    });
}

// ============================================
// Batch Builder Modal
// ============================================

var pendingBatchRefs = [];

function batchSelected() {
    var refs = getSelectedRefs();
    if (refs.length < 2) {
        showToast('Select at least 2 orders to create a batch', 'error');
        return;
    }
    
    pendingBatchRefs = refs;
    openBatchModal(refs);
}

function openBatchModal(refs) {
    var modal = document.getElementById('batchModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Show loading state
    document.getElementById('batchModalOrders').innerHTML = '<div style="text-align:center;padding:20px;color:#9ca3af;">Loading order details...</div>';
    document.getElementById('batchModalDests').innerHTML = '';
    document.getElementById('batchModalCount').textContent = refs.length;
    
    // Reset form
    document.getElementById('batchModalCourier').value = '';
    document.getElementById('batchModalNotes').value = '';
    
    // Fetch preview data from server
    var formData = new FormData();
    formData.append('ajax_action', 'batch_preview');
    formData.append('refs', JSON.stringify(refs));
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (!result.success) {
            showToast('Failed to load batch preview', 'error');
            closeBatchModal();
            return;
        }
        
        var data = result.data;
        
        // Set batch ID
        document.getElementById('batchModalId').textContent = data.next_batch_id;
        document.getElementById('batchModalCount').textContent = data.order_count;
        
        // Render destinations
        var destsHtml = '';
        data.destinations.forEach(function(dest) {
            var typeClass = dest.type === 'mtcc' ? 'dest-mtcc' : 'dest-office';
            destsHtml += '<span class="batch-dest-tag ' + typeClass + '">';
            destsHtml += escapeHtml(dest.label);
            if (dest.count > 1) {
                destsHtml += ' <span class="batch-dest-count">&times;' + dest.count + '</span>';
            }
            destsHtml += '</span>';
        });
        document.getElementById('batchModalDests').innerHTML = destsHtml;
        
        // Render orders
        var ordersHtml = '';
        data.orders.forEach(function(order) {
            var urgencyClass = order.is_urgent ? ' batch-order-urgent' : (order.is_priority ? ' batch-order-priority' : '');
            ordersHtml += '<div class="batch-modal-order' + urgencyClass + '">';
            ordersHtml += '<span class="ref-code">' + escapeHtml(order.ref) + '</span>';
            if (order.event) ordersHtml += '<span class="batch-order-event">' + escapeHtml(order.event) + '</span>';
            ordersHtml += '<span class="batch-order-customer">' + escapeHtml(order.customer_name) + '</span>';
            ordersHtml += '<span class="batch-order-dest">' + escapeHtml(order.destination) + '</span>';
            ordersHtml += '<span class="batch-order-due">' + escapeHtml(order.due_date) + ' ' + escapeHtml(order.due_time) + '</span>';
            ordersHtml += '<button class="batch-order-remove" onclick="removeBatchOrder(\'' + escapeHtml(order.ref) + '\')" title="Remove">&times;</button>';
            ordersHtml += '</div>';
        });
        document.getElementById('batchModalOrders').innerHTML = ordersHtml;
        
        // Update submit button text
        updateBatchSubmitText();
    })
    .catch(function(err) {
        showToast('Error loading batch data', 'error');
        closeBatchModal();
    });
}

function closeBatchModal(event) {
    if (event && event.target !== event.currentTarget) return;
    var modal = document.getElementById('batchModal');
    modal.style.display = 'none';
    document.body.style.overflow = '';
    pendingBatchRefs = [];
}

function removeBatchOrder(ref) {
    pendingBatchRefs = pendingBatchRefs.filter(function(r) { return r !== ref; });
    
    if (pendingBatchRefs.length < 2) {
        showToast('Need at least 2 orders for a batch', 'error');
        closeBatchModal();
        return;
    }
    
    // Refresh the modal with updated refs
    openBatchModal(pendingBatchRefs);
}

function updateBatchSubmitText() {
    var courier = document.getElementById('batchModalCourier');
    var btn = document.getElementById('batchModalSubmit');
    if (courier.value) {
        var name = courier.options[courier.selectedIndex].getAttribute('data-name');
        btn.innerHTML = '&#128666; Create Batch & Dispatch to ' + escapeHtml(name);
    } else {
        btn.innerHTML = '&#128230; Create Batch (Assign Later)';
    }
}

function submitBatch() {
    if (pendingBatchRefs.length < 2) {
        showToast('Need at least 2 orders', 'error');
        return;
    }
    
    var courierSelect = document.getElementById('batchModalCourier');
    var courierId = courierSelect.value;
    var courierName = courierId ? courierSelect.options[courierSelect.selectedIndex].getAttribute('data-name') : '';
    var notes = document.getElementById('batchModalNotes').value;
    
    // Disable submit button
    var btn = document.getElementById('batchModalSubmit');
    btn.disabled = true;
    btn.textContent = 'Creating...';
    
    var formData = new FormData();
    formData.append('ajax_action', 'create_batch');
    formData.append('refs', JSON.stringify(pendingBatchRefs));
    formData.append('courier_id', courierId);
    formData.append('courier_name', courierName);
    formData.append('notes', notes);
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            closeBatchModal();
            clearSelection();
            
            var msg = 'Batch ' + result.batch_id + ' created with ' + result.order_count + ' orders';
            if (courierName) msg += ' \u2014 dispatched to ' + courierName;
            showToast(msg, 'success');
            
            // Reload page after brief delay so Active tab + summary update
            setTimeout(function() {
                window.location.href = window.location.pathname + '?tab=active';
            }, 800);
        } else {
            showToast(result.error || 'Failed to create batch', 'error');
            btn.disabled = false;
            updateBatchSubmitText();
        }
    })
    .catch(function() {
        showToast('Network error creating batch', 'error');
        btn.disabled = false;
        updateBatchSubmitText();
    });
}

function assignBatchCourier(selectEl) {
    var batchId = selectEl.getAttribute('data-batch-id');
    var courierId = selectEl.value;
    if (!courierId) return;
    
    var courierName = selectEl.options[selectEl.selectedIndex].getAttribute('data-name');
    
    if (!confirm('Assign batch ' + batchId + ' to ' + courierName + '?')) {
        selectEl.value = '';
        return;
    }
    
    var formData = new FormData();
    formData.append('ajax_action', 'assign_batch_courier');
    formData.append('batch_id', batchId);
    formData.append('courier_id', courierId);
    formData.append('courier_name', courierName);
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            showToast('Batch ' + batchId + ' assigned to ' + courierName, 'success');
            // Replace dropdown with courier name
            var card = selectEl.closest('.batch-card');
            var assignDiv = card.querySelector('.batch-assign-inline');
            if (assignDiv) {
                assignDiv.outerHTML = '<span class="batch-courier">&#128100; ' + escapeHtml(courierName) + '</span>';
            }
            // Update status badge
            var statusBadge = card.querySelector('.batch-status');
            if (statusBadge) {
                statusBadge.className = 'batch-status batch-status-dispatched';
                statusBadge.textContent = 'Dispatched';
            }
        } else {
            showToast(result.error || 'Failed to assign courier', 'error');
            selectEl.value = '';
        }
    })
    .catch(function() {
        showToast('Network error', 'error');
        selectEl.value = '';
    });
}

// ============================================
// Bad Weather Toggle
// ============================================

function toggleBadWeather() {
    var btn = document.getElementById('badWeatherToggle');
    var isActive = btn.classList.contains('active');
    
    btn.classList.toggle('active');
    btn.textContent = isActive ? 'OFF' : 'ON';
    
    var formData = new FormData();
    formData.append('ajax_action', 'toggle_weather_bonus');
    formData.append('active', isActive ? '0' : '1');
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success) {
            showToast('Bad weather bonus ' + (isActive ? 'disabled' : 'enabled'), 'success');
        } else {
            btn.classList.toggle('active');
            btn.textContent = isActive ? 'ON' : 'OFF';
            showToast('Failed to update weather bonus', 'error');
        }
    })
    .catch(function() {
        btn.classList.toggle('active');
        btn.textContent = isActive ? 'ON' : 'OFF';
        showToast('Network error', 'error');
    });
}

// ============================================
// Summary Count Updates
// ============================================

function updateSummaryCounts() {
    // Count remaining rows in ready queue
    var readyRows = document.querySelectorAll('.queue-row');
    var visibleRows = 0;
    readyRows.forEach(function(row) {
        if (row.style.opacity !== '0') visibleRows++;
    });
    
    var readyEl = document.getElementById('summaryReady');
    if (readyEl) {
        readyEl.textContent = visibleRows;
    }
    
    // Update tab badges
    var readyBadge = document.querySelector('.dispatch-tab:first-child .tab-badge');
    if (readyBadge) {
        if (visibleRows > 0) {
            readyBadge.textContent = visibleRows;
        } else {
            readyBadge.remove();
        }
    }
}

function checkEmptyQueue() {
    var rows = document.querySelectorAll('.queue-row');
    var visible = 0;
    rows.forEach(function(row) {
        if (row.style.display !== 'none' && row.style.opacity !== '0') visible++;
    });
    
    if (visible === 0) {
        var tabContent = document.getElementById('tabReady');
        if (tabContent) {
            tabContent.innerHTML = '<div class="empty-state">' +
                '<div class="empty-icon">&#9989;</div>' +
                '<div class="empty-text">All orders dispatched!</div>' +
                '<div class="empty-subtext">Great work — no orders waiting in the queue</div>' +
                '</div>';
        }
    }
}

// ============================================
// Auto-Refresh (polls for count updates)
// ============================================

var refreshInterval = null;

function startAutoRefresh() {
    refreshInterval = setInterval(function() {
        var formData = new FormData();
        formData.append('ajax_action', 'refresh_data');
        
        fetch(window.location.pathname, {
            method: 'POST',
            body: formData
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                // Update summary counts
                var readyEl = document.getElementById('summaryReady');
                var activeEl = document.getElementById('summaryActive');
                var doneEl = document.getElementById('summaryDone');
                var transitEl = document.getElementById('summaryTransit');
                
                if (readyEl) readyEl.textContent = data.summary.ready;
                if (activeEl) activeEl.textContent = data.summary.active;
                if (doneEl) doneEl.textContent = data.summary.completed;
                if (transitEl) transitEl.textContent = data.summary.in_transit;
            }
        })
        .catch(function() {
            // Silently fail — will retry next interval
        });
    }, 60000); // Every 60 seconds
}

// ============================================
// Toast Notifications
// ============================================

// ============================================
// UNASSIGN / RELEASE / DISBAND
// ============================================

function unassignOrder(ref) {
    if (!confirm('Unassign order ' + ref + '?\n\nThis will return it to the ready queue so it can be reassigned.')) return;
    
    var reason = prompt('Optional reason (wrong courier, car trouble, etc.):') || '';
    
    var formData = new FormData();
    formData.append('ajax_action', 'unassign_order');
    formData.append('reference_code', ref);
    formData.append('reason', reason);
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                showToast(result.message || 'Order unassigned', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(result.error || 'Failed to unassign', 'error');
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        });
}

function disbandBatch(batchId) {
    if (!confirm('Disband batch ' + batchId + '?\n\nAll orders will be returned to the ready queue and can be reassigned individually or re-batched.')) return;
    
    var formData = new FormData();
    formData.append('ajax_action', 'disband_batch');
    formData.append('batch_id', batchId);
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                showToast(result.message || 'Batch disbanded', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(result.error || 'Failed to disband', 'error');
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        });
}

function releaseAllCourier(courierId, courierName) {
    if (!confirm('Release ALL orders assigned to ' + courierName + '?\n\nAll their dispatched and in-transit orders will return to the ready queue.')) return;
    
    var reason = prompt('Optional reason (car trouble, courier unavailable, etc.):') || '';
    
    var formData = new FormData();
    formData.append('ajax_action', 'release_all_courier');
    formData.append('courier_id', courierId);
    formData.append('reason', reason);
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(result) {
            if (result.success) {
                showToast(result.released_count + ' orders released from ' + courierName, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showToast(result.error || 'Failed to release', 'error');
            }
        })
        .catch(function(err) {
            showToast('Network error: ' + err.message, 'error');
        });
}

function showToast(message, type) {
    type = type || 'success';
    
    // Remove existing toasts
    document.querySelectorAll('.dispatch-toast').forEach(function(t) { t.remove(); });
    
    var toast = document.createElement('div');
    toast.className = 'dispatch-toast ' + type;
    toast.innerHTML = (type === 'success' ? '&#9989; ' : '&#10060; ') + message;
    document.body.appendChild(toast);
    
    setTimeout(function() {
        toast.style.transition = 'opacity 0.3s ease';
        toast.style.opacity = '0';
        setTimeout(function() { toast.remove(); }, 300);
    }, 3500);
}

// ============================================
// Notification System (Phase 2E/2F)
// ============================================

var lastNotifId = 0;
var notifPollInterval = null;
var notifPanelOpen = false;

function startNotifPolling() {
    var interval = (typeof DISPATCH_SETTINGS !== 'undefined' && DISPATCH_SETTINGS.notifications)
        ? (DISPATCH_SETTINGS.notifications.poll_interval_seconds || 30) * 1000
        : 30000;
    
    // Initial fetch
    fetchNotifications();
    
    notifPollInterval = setInterval(fetchNotifications, interval);
}

function fetchNotifications() {
    var formData = new FormData();
    formData.append('ajax_action', 'get_notifications');
    formData.append('since_id', '0');
    
    fetch(window.location.pathname, {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(result) {
        if (result.success && result.data) {
            updateNotifBadge(result.data.unread_count);
            if (notifPanelOpen) {
                renderNotifPanel(result.data.notifications);
            }
            lastNotifId = result.data.last_id;
        }
    })
    .catch(function() {});
}

function updateNotifBadge(count) {
    var badge = document.getElementById('notifBadge');
    if (!badge) return;
    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}

function toggleNotifPanel() {
    var panel = document.getElementById('notifPanel');
    notifPanelOpen = !notifPanelOpen;
    
    if (notifPanelOpen) {
        panel.style.display = 'block';
        fetchNotifications();
        // Close on outside click
        setTimeout(function() {
            document.addEventListener('click', closeNotifOnOutsideClick);
        }, 10);
    } else {
        panel.style.display = 'none';
        document.removeEventListener('click', closeNotifOnOutsideClick);
    }
}

function closeNotifOnOutsideClick(e) {
    var panel = document.getElementById('notifPanel');
    var bell = document.getElementById('notifBell');
    if (panel && !panel.contains(e.target) && bell && !bell.contains(e.target)) {
        notifPanelOpen = false;
        panel.style.display = 'none';
        document.removeEventListener('click', closeNotifOnOutsideClick);
    }
}

function renderNotifPanel(notifications) {
    var list = document.getElementById('notifList');
    if (!list) return;
    
    if (!notifications || notifications.length === 0) {
        list.innerHTML = '<div class="notif-empty">No notifications</div>';
        return;
    }
    
    var html = '';
    var typeIcons = {
        'order_ready': '\u{1F4E6}',
        'batch_created': '\u{1F4E6}',
        'batch_dispatched': '\u{1F69A}',
        'courier_assigned': '\u{1F464}',
        'status_change': '\u{1F504}',
        'weather_alert': '\u{26C8}',
        'system': '\u{2699}'
    };
    
    notifications.forEach(function(n) {
        var icon = typeIcons[n.type] || '\u{1F514}';
        var readClass = n.read ? ' notif-read' : '';
        var timeAgo = getTimeAgo(n.created_at);
        
        html += '<div class="notif-item' + readClass + '" data-id="' + n.id + '"';
        if (!n.read) html += ' onclick="markNotifRead(' + n.id + ', this)"';
        html += '>';
        html += '<span class="notif-icon">' + icon + '</span>';
        html += '<div class="notif-content">';
        html += '<div class="notif-title">' + escapeHtml(n.title) + '</div>';
        if (n.message) html += '<div class="notif-message">' + escapeHtml(n.message) + '</div>';
        html += '<div class="notif-time">' + timeAgo + '</div>';
        html += '</div>';
        html += '</div>';
    });
    
    list.innerHTML = html;
}

function getTimeAgo(dateStr) {
    var now = new Date();
    var then = new Date(dateStr);
    var diff = Math.floor((now - then) / 1000);
    
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
}

function markNotifRead(id, el) {
    if (el) el.classList.add('notif-read');
    
    var formData = new FormData();
    formData.append('ajax_action', 'mark_notification_read');
    formData.append('notif_id', id);
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function() { fetchNotifications(); });
}

function markAllNotifsRead() {
    var formData = new FormData();
    formData.append('ajax_action', 'mark_notification_read');
    formData.append('notif_id', 'all');
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function() {
        fetchNotifications();
        showToast('All notifications marked as read', 'success');
    });
}

function clearAllNotifs() {
    if (!confirm('Clear all notifications?')) return;
    
    var formData = new FormData();
    formData.append('ajax_action', 'clear_notifications');
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
    .then(function(r) { return r.json(); })
    .then(function() {
        fetchNotifications();
        showToast('Notifications cleared', 'success');
    });
}

// ============================================
// Utility
// ============================================

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}


// ============================================
// Batch Suggestions (AI-assisted)
// ============================================

function loadBatchSuggestions() {
    var container = document.getElementById('batchSuggestions');
    if (!container) return;
    
    var formData = new FormData();
    formData.append('ajax_action', 'get_suggestions');
    
    fetch('', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.suggestions || data.suggestions.length === 0) {
                container.style.display = 'none';
                return;
            }
            renderSuggestions(container, data.suggestions);
        })
        .catch(function(err) {
            console.error('Suggestions error:', err);
            container.style.display = 'none';
        });
}

function renderSuggestions(container, suggestions) {
    var html = '<div class="suggestions-header">';
    html += '<div class="suggestions-title">';
    html += '<span class="suggestions-icon">&#9889;</span> ';
    html += 'Smart Batch Suggestions';
    html += '<span class="suggestions-count">' + suggestions.length + '</span>';
    html += '</div>';
    html += '<button class="suggestions-dismiss" onclick="dismissSuggestions()" title="Dismiss">&times;</button>';
    html += '</div>';
    
    html += '<div class="suggestions-list">';
    
    suggestions.forEach(function(s) {
        var scoreClass = s.score >= 80 ? 'score-high' : (s.score >= 60 ? 'score-med' : 'score-low');
        
        html += '<div class="suggestion-card" data-suggestion-id="' + escapeHtml(s.id) + '">';
        
        // Header: type badge + urgent + score
        html += '<div class="sg-header">';
        html += '<span class="sg-type-badge sg-type-' + s.type + '">' + getSuggestionLabel(s.type) + '</span>';
        if (s.has_urgent) {
            html += '<span class="sg-urgent-badge">&#128308; ' + s.urgent_count + ' urgent</span>';
        }
        html += '<div class="suggestion-score ' + scoreClass + '">' + s.score + '</div>';
        html += '</div>';
        
        // Route row: pickup → dropoff (horizontal)
        html += '<div class="sg-route-row">';
        html += '<div class="sg-endpoint">';
        html += '<svg class="sg-endpoint-icon sg-icon-pickup" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>';
        html += '<div class="sg-endpoint-text"><div class="sg-endpoint-label">Pickup</div>';
        html += '<div class="sg-endpoint-name">' + escapeHtml(s.vendor || 'Multiple') + '</div></div>';
        html += '</div>';
        html += '<div class="sg-route-arrow"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></div>';
        html += '<div class="sg-endpoint">';
        html += '<svg class="sg-endpoint-icon sg-icon-dropoff" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>';
        html += '<div class="sg-endpoint-text"><div class="sg-endpoint-label">Drop-off</div>';
        html += '<div class="sg-endpoint-name">' + escapeHtml(s.destination || 'Multiple') + '</div></div>';
        html += '</div>';
        html += '</div>';
        
        // Stats row: packages + refs + savings
        html += '<div class="sg-stats">';
        html += '<span class="sg-stat"><strong>' + s.order_count + '</strong> package' + (s.order_count !== 1 ? 's' : '') + '</span>';
        html += '<span class="sg-stat-divider"></span>';
        s.refs.forEach(function(ref) {
            html += '<span class="sg-ref-pill">' + escapeHtml(ref) + '</span>';
        });
        if (s.savings_est > 0) {
            html += '<span class="sg-stat-divider"></span>';
            html += '<span class="sg-stat sg-stat-savings">Save ~$' + s.savings_est.toFixed(0) + '</span>';
        }
        html += '</div>';
        
        // Action
        html += '<button class="suggestion-btn suggestion-btn-batch" onclick="batchSuggestion(&quot;' + escapeHtml(s.refs.join(',')) + '&quot;)">Create Batch</button>';
        
        html += '</div>';
    });
    
    html += '</div>';
    
    container.innerHTML = html;
    container.style.display = 'block';
}

function getSuggestionLabel(type) {
    switch (type) {
        case 'same_vendor_dest': return 'Same Route';
        case 'same_vendor':     return 'Same Pickup';
        case 'same_dest':       return 'Same Drop-off';
        case 'urgent_cluster':  return 'Urgent';
        default:                return 'Suggested';
    }
}

function getSuggestionIcon(type) {
    switch (type) {
        case 'same_vendor_dest': return '&#127919;';  // 🎯
        case 'same_vendor':     return '&#128230;';  // 📦
        case 'same_dest':       return '&#128205;';  // 📍
        case 'urgent_cluster':  return '&#9888;&#65039;';   // ⚠️
        default:                return '&#9889;';    // ⚡
    }
}

function selectSuggestionOrders(refsStr) {
    var refs = refsStr.split(',');
    
    // Clear current selection first
    document.querySelectorAll('.order-check').forEach(function(cb) {
        cb.checked = false;
    });
    
    // Select the suggested orders
    refs.forEach(function(ref) {
        var cb = document.querySelector('.order-check[value="' + ref + '"]');
        if (cb) {
            cb.checked = true;
            // Highlight the row briefly
            var row = cb.closest('tr');
            if (row) {
                row.classList.add('suggestion-highlight');
                setTimeout(function() { row.classList.remove('suggestion-highlight'); }, 2000);
            }
        }
    });
    
    updateBulkBar();
    
    // Scroll to the table
    var table = document.querySelector('.dispatch-table');
    if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function batchSuggestion(refsStr) {
    var refs = refsStr.split(',');
    
    // Select the orders first
    document.querySelectorAll('.order-check').forEach(function(cb) {
        cb.checked = false;
    });
    refs.forEach(function(ref) {
        var cb = document.querySelector('.order-check[value="' + ref + '"]');
        if (cb) cb.checked = true;
    });
    
    updateBulkBar();
    
    // Open the batch modal
    pendingBatchRefs = refs;
    openBatchModal(refs);
}

function dismissSuggestions() {
    var container = document.getElementById('batchSuggestions');
    if (container) {
        container.style.display = 'none';
    }
}

// ============================================
// Initialize
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    startAutoRefresh();
    startNotifPolling();
    loadBatchSuggestions();
    
    // Update batch submit button text when courier selection changes
    var batchCourierSelect = document.getElementById('batchModalCourier');
    if (batchCourierSelect) {
        batchCourierSelect.addEventListener('change', updateBatchSubmitText);
    }
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            var modal = document.getElementById('batchModal');
            if (modal && modal.style.display !== 'none') {
                closeBatchModal();
            }
        }
    });
});


// ============================================
// Issue Management (Issues Tab)
// ============================================

function reviewIssue(issueId) {
    var formData = new FormData();
    formData.append('ajax_action', 'review_issue');
    formData.append('issue_id', issueId);
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showDispatchToast('Issue marked as reviewing', 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showDispatchToast(data.error || 'Failed to update', 'error');
            }
        })
        .catch(function() { showDispatchToast('Network error', 'error'); });
}

function resolveIssue(issueId, resolution) {
    var notes = '';
    var retryDate = null;
    
    if (resolution === 'reprint') {
        notes = prompt('Reprint notes (optional):') || '';
    } else if (resolution === 'retry') {
        var tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        var defaultDate = tomorrow.toISOString().split('T')[0];
        retryDate = prompt('Retry delivery date (YYYY-MM-DD):', defaultDate);
        if (!retryDate) return;
        notes = prompt('Retry notes (optional):') || '';
    } else if (resolution === 'refund') {
        if (!confirm('Mark this issue for refund? This should be followed up in Stripe.')) return;
        notes = prompt('Refund notes:') || '';
    } else if (resolution === 'no_action') {
        notes = prompt('Dismiss reason:') || '';
        if (notes === '') { notes = 'No action needed'; }
    }
    
    var formData = new FormData();
    formData.append('ajax_action', 'resolve_issue');
    formData.append('issue_id', issueId);
    formData.append('resolution', resolution);
    formData.append('resolution_notes', notes);
    if (retryDate) formData.append('retry_date', retryDate);
    
    fetch(window.location.pathname, { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                showDispatchToast('Issue resolved: ' + resolution, 'success');
                setTimeout(function() { location.reload(); }, 800);
            } else {
                showDispatchToast(data.error || 'Failed to resolve', 'error');
            }
        })
        .catch(function() { showDispatchToast('Network error', 'error'); });
}
