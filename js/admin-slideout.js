/**
 * Order Quick-View Slide-Out Panel
 * Hybrid design inspired by Fulfillment panel
 */
var OrderSlideout = (function() {
  var panel = null;
  var overlay = null;
  var currentRef = null;

  function init() {
    overlay = document.createElement('div');
    overlay.className = 'slideout-overlay';
    overlay.onclick = close;
    document.body.appendChild(overlay);

    panel = document.createElement('div');
    panel.className = 'slideout-panel';
    panel.id = 'orderSlideout';
    panel.innerHTML = '<div class="slideout-header" id="slideoutHeader"></div><div class="slideout-content" id="slideoutContent"></div><div class="slideout-footer" id="slideoutFooter"></div>';
    document.body.appendChild(panel);

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' && panel.classList.contains('open')) close();
    });
  }

  function open(refCode) {
    if (!panel) init();
    currentRef = refCode;
    var order = findOrder(refCode);
    if (!order) return;

    renderHeader(order);
    renderBody(order);
    renderFooter(order);

    panel.classList.add('open');
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function close() {
    if (panel) panel.classList.remove('open');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
    currentRef = null;
  }

  function findOrder(refCode) {
    if (!window.dashboardData || !window.dashboardData.orders) return null;
    return window.dashboardData.orders.find(function(o) {
      return (o.referenceCode || '').toUpperCase() === refCode.toUpperCase();
    });
  }

  function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
  function fmtDate(s) { if (!s) return 'N/A'; var d = new Date(s); return isNaN(d) ? s : d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' }); }
  function fmtDateTime(s) { if (!s) return ''; var d = new Date(s); return isNaN(d) ? s : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }); }
  function fmtMoney(v) { var n = parseFloat(v); return isNaN(n) ? '$0.00' : '$' + n.toFixed(2); }

  // Generate MTCC tracking/barcode number (mirrors dispatch/api.php generateTrackingNumber)
  // Format: MTCC + EVENT_ACRONYM + ORDER_NUMBER(3 digits) + DATE(YYMMDD)
  function genTrackingNumber(order) {
    if (!order) return '';
    var ev = order.event || {};
    var prefix = (ev.acronym || order.eventAcronym || '').toUpperCase();
    var m = (order.referenceCode || '').match(/(\d+)$/);
    var orderNum = (m ? m[1] : '001').padStart(3, '0');
    var dateStr = order.selectedDate || order.orderDate || '';
    var d = dateStr ? new Date(dateStr) : new Date();
    if (isNaN(d.getTime())) d = new Date();
    var y = String(d.getFullYear()).slice(-2);
    var mo = String(d.getMonth() + 1).padStart(2, '0');
    var da = String(d.getDate()).padStart(2, '0');
    return prefix
      ? ('MTCC' + prefix + orderNum + y + mo + da)
      : ('MTCC' + y + mo + da + orderNum);
  }

  // Use role-appropriate labels from PHP if available, otherwise fall back to admin defaults
  var STATUS_LABELS_DEFAULT = {
    unpaid:'Unpaid', paid:'Paid', preflight:'Sent to Vendor', file_issue:'File Issue',
    printing:'Printing', ready:'Ready to Ship', dispatched:'Courier Assigned',
    shipped:'Shipped', delivered:'Delivered', pickedup:'Picked Up',
    unclaimed:'Unclaimed', missing:'Missing', cancelled:'Cancelled', refunded:'Refunded'
  };
  var STATUS_LABELS = window.STATUS_LABELS || STATUS_LABELS_DEFAULT;
  var TIME_LABELS = { '9am':'9:00 AM', '12pm':'12:00 PM', '3pm':'3:00 PM', '6pm':'6:00 PM', 'anytime':'Anytime' };

  function renderHeader(order) {
    var ref = esc(order.referenceCode || '');
    var status = order.status || 'unpaid';
    var el = document.getElementById('slideoutHeader');
    el.innerHTML =
      '<div class="so-header-top">' +
        '<div class="so-header-ref">#' + ref + '</div>' +
        '<span class="status-badge status-' + status + '">' + (STATUS_LABELS[status] || status) + '</span>' +
        '<button class="so-close" onclick="OrderSlideout.close()">&times;</button>' +
      '</div>';
  }

  function renderFooter(order) {
    var ref = esc(order.referenceCode || '');
    var el = document.getElementById('slideoutFooter');
    var perms = window.PERMS || {};

    if (perms.isMtccStaff) {
      // MTCC: Print, Download, Close — no full detail page, no edit
      el.innerHTML =
        '<button class="so-footer-btn so-btn-secondary" onclick="OrderSlideout.print()" title="Print order details">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>' +
          'Print' +
        '</button>' +
        '<button class="so-footer-btn so-btn-secondary" onclick="OrderSlideout.downloadPDF()" title="Save as PDF">' +
          '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px; margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>' +
          'Download PDF' +
        '</button>' +
        '<button class="so-footer-btn so-btn-primary" onclick="OrderSlideout.close()">Close</button>';
    } else {
      el.innerHTML =
        '<a href="?view=' + encodeURIComponent(ref) + '" class="so-footer-btn so-btn-primary">View Full Details</a>' +
        '<a href="?view=' + encodeURIComponent(ref) + '&edit=1" class="so-footer-btn so-btn-secondary">Edit Order</a>';
    }
  }

  function renderBody(order) {
    var h = '';
    var ci = order.customerInfo || {};
    var dim = order.dimensions || {};
    var pricing = order.pricing || {};
    var event = order.event || {};
    var file = order.uploadedFile || {};
    var delivery = order.deliveryOption || 'mtcc';
    var deliveryTime = order.deliveryTime || 'anytime';
    var material = (order.material === 'fabric') ? 'Fabric' : 'Poster';
    var vendorName = order.vendor_name || '';
    var perms = window.PERMS || {};
    var isMtcc = perms.isMtccStaff;

    // ---- FILE DOWNLOAD (hidden for MTCC) ----
    if (!isMtcc) {
      var filePath = file.path || (file.savedName ? 'uploads/files/' + file.savedName : '');
      if (filePath) {
        h += '<div class="so-section">';
        h += '<a href="' + esc(filePath) + '" class="so-download-btn" download>';
        h += '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>';
        h += ' Download File</a>';
        h += '<div class="so-file-info">' + esc(file.originalName || file.savedName || '') + '</div>';
        h += '</div>';
      }
    }

    var trackingNum = genTrackingNumber(order);

    // ---- ORDER DETAILS ----
    h += '<div class="so-section">';
    h += '<div class="so-section-label">Order Details</div>';
    h += '<div class="so-spec-grid">';
    h += '<span class="so-spec-lbl">Customer</span><span class="so-spec-val">' + esc(ci.name || 'N/A') + '</span>';
    h += '<span class="so-spec-lbl">Size</span><span class="so-spec-val">' + (dim.width || '?') + '" &times; ' + (dim.height || '?') + '"</span>';
    h += '<span class="so-spec-lbl">Material</span><span class="so-spec-val"><span class="so-material-badge so-mat-' + material.toLowerCase() + '">' + material + '</span></span>';
    h += '<span class="so-spec-lbl">Due</span><span class="so-spec-val">' + fmtDate(order.selectedDate) + ' &middot; by ' + (TIME_LABELS[deliveryTime] || deliveryTime) + '</span>';
    h += '<span class="so-spec-lbl">Delivery</span><span class="so-spec-val">' + (delivery === 'mtcc' ? 'MTCC Pick-up' : 'Address Delivery') + '</span>';
    h += '<span class="so-spec-lbl">Event</span><span class="so-spec-val">' + esc(event.name || event.acronym || 'N/A') + '</span>';
    h += '<span class="so-spec-lbl">Tier</span><span class="so-spec-val">' + esc(pricing.tier || 'Standard') + '</span>';
    if (trackingNum) h += '<span class="so-spec-lbl">Tracking #</span><span class="so-spec-val" style="font-family: monospace; font-size: 0.82rem;">' + esc(trackingNum) + '</span>';
    if (vendorName && !isMtcc) h += '<span class="so-spec-lbl">Vendor</span><span class="so-spec-val">' + esc(vendorName) + '</span>';
    h += '</div></div>';

    // ---- CUSTOMER CONTACT (hidden for MTCC — they don't get email/phone) ----
    if (!isMtcc) {
      h += '<div class="so-section">';
      h += '<div class="so-section-label">Customer Contact</div>';
      h += '<div class="so-spec-grid">';
      h += '<span class="so-spec-lbl">Email</span><span class="so-spec-val"><a href="mailto:' + esc(ci.email || '') + '">' + esc(ci.email || 'N/A') + '</a></span>';
      h += '<span class="so-spec-lbl">Phone</span><span class="so-spec-val">' + esc(ci.phone || 'N/A') + '</span>';
      h += '</div></div>';
    }

    // ---- PICKUP RECORD (if picked up) ----
    if (order.pickup && order.pickup.picked_up_at) {
      h += '<div class="so-section">';
      h += '<div class="so-section-label">Pickup Record</div>';
      h += '<div class="so-spec-grid">';
      h += '<span class="so-spec-lbl">Picked Up</span><span class="so-spec-val">' + fmtDateTime(order.pickup.picked_up_at) + '</span>';
      h += '<span class="so-spec-lbl">By</span><span class="so-spec-val">' + esc(order.pickup.pickup_person || 'N/A');
      if (order.pickup.same_as_customer) h += ' <span style="color: #059669; font-size: 0.75rem;">(customer)</span>';
      h += '</span>';
      if (!isMtcc && order.pickup.picked_up_by_staff) {
        h += '<span class="so-spec-lbl">Staff</span><span class="so-spec-val">' + esc(order.pickup.picked_up_by_staff) + '</span>';
      }
      h += '</div></div>';
    }

    // ---- PRICING ---- (full breakdown for both admin and MTCC)
    {
      h += '<div class="so-section">';
      h += '<div class="so-section-label">Pricing</div>';
      h += '<div class="so-price-grid">';
      h += '<span>Base Price</span><span>' + fmtMoney(pricing.basePrice) + '</span>';
      if (pricing.deliveryFee > 0) h += '<span>Delivery Fee</span><span>' + fmtMoney(pricing.deliveryFee) + '</span>';
      h += '<span>Tax (HST 13%)</span><span>' + fmtMoney(pricing.tax) + '</span>';
      h += '<span class="so-price-total">Total</span><span class="so-price-total">' + fmtMoney(pricing.total) + '</span>';
      h += '</div></div>';
    }

    // ---- TIMELINE ----
    h += '<div class="so-section">';
    h += '<div class="so-section-label">Order Timeline</div>';
    h += '<div class="so-timeline">';

    var TL_ICONS = {
      submitted: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
      paid: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>',
      printing: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
      ready: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
      delivered: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>'
    };

    var statusOrder = ['unpaid', 'paid', 'preflight', 'printing', 'ready', 'shipped', 'delivered', 'pickedup'];
    var currentIdx = statusOrder.indexOf(order.status || 'unpaid');

    var timelineSteps = [
      { key: 'submitted', label: 'Submitted', time: order.submittedAt, targetIdx: 0, icon: TL_ICONS.submitted },
      { key: 'paid', label: 'Paid', time: order.paidAt, targetIdx: 1, icon: TL_ICONS.paid },
      { key: 'printing', label: 'Printing', time: null, targetIdx: 3, icon: TL_ICONS.printing },
      { key: 'ready', label: 'Ready', time: null, targetIdx: 4, icon: TL_ICONS.ready },
      { key: 'delivered', label: 'Delivered', time: null, targetIdx: 6, icon: TL_ICONS.delivered }
    ];

    timelineSteps.forEach(function(step, i) {
      var isDone = currentIdx >= step.targetIdx;
      var isCurrent = !isDone && (i === 0 || currentIdx >= timelineSteps[i-1].targetIdx);
      var stateClass = isDone ? 'so-tl-green' : isCurrent ? 'so-tl-amber' : 'so-tl-grey';

      h += '<div class="so-tl-step ' + stateClass + '">';
      h += '<div class="so-tl-icon">' + step.icon + '</div>';
      h += '<div class="so-tl-info">';
      h += '<div class="so-tl-label">' + step.label + '</div>';
      if (isDone && step.time) {
        h += '<div class="so-tl-time">' + fmtDateTime(step.time) + '</div>';
      } else if (isDone) {
        h += '<div class="so-tl-time">Completed</div>';
      } else if (isCurrent) {
        h += '<div class="so-tl-time">Waiting</div>';
      } else {
        h += '<div class="so-tl-time">Pending</div>';
      }
      h += '</div></div>';

      if (i < timelineSteps.length - 1) {
        var lineClass = 'so-tl-line';
        if (isDone) lineClass += ' so-tl-line-green';
        else if (isCurrent) lineClass += ' so-tl-line-amber';
        h += '<div class="' + lineClass + '"></div>';
      }
    });
    h += '</div></div>';

// ---- NOTES ----
    h += '<div class="so-section so-notes-section">';
    h += '<div class="so-section-label">Notes</div>';

    // Customer notes
    var custNotes = ci.additionalNotes || '';
    if (custNotes) {
      h += '<div class="so-note so-note-customer"><span class="so-note-from">Customer</span>' + esc(custNotes) + '</div>';
    }

    // Internal notes
    var internalNotes = order.internalNotes || [];
    if (internalNotes.length > 0) {
      internalNotes.forEach(function(note) {
        h += '<div class="so-note so-note-internal">';
        h += '<span class="so-note-from">' + esc(note.username || 'Admin') + '</span>';
        h += esc(note.content || '');
        if (note.timestamp) h += '<span class="so-note-time">' + fmtDateTime(note.timestamp) + '</span>';
        h += '</div>';
      });
    }

    if (!custNotes && internalNotes.length === 0) {
      h += '<div class="so-note-empty">No notes yet.</div>';
    }

    // Add note form (hidden for MTCC staff)
    if (!isMtcc) {
      h += '<div class="so-add-note" id="soAddNoteForm">';
      h += '<textarea class="so-note-input" id="soNoteInput" placeholder="Add a note..." rows="2"></textarea>';
      h += '<button class="so-note-save" onclick="OrderSlideout.saveNote()">Add Note</button>';
      h += '</div>';
    }
    h += '</div>';

    document.getElementById('slideoutContent').innerHTML = h;
  }

  function saveNote() {
    var input = document.getElementById('soNoteInput');
    if (!input || !input.value.trim() || !currentRef) return;

    var content = input.value.trim();
    var adminName = '';
    var welcomeEl = document.querySelector('.welcome-text');
    if (welcomeEl) {
      var match = welcomeEl.textContent.match(/Welcome\s+(.+?)!/);
      if (match) adminName = match[1].trim();
    }
    if (!adminName) adminName = 'Admin';

    var formData = new FormData();
    formData.append('add_internal_note', '1');
    formData.append('reference_code', currentRef);
    formData.append('username', adminName);
    formData.append('note_content', content);

    input.disabled = true;

    fetch('admin-orders.php', { method: 'POST', body: formData })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (data.success) {
          // Add note to local data
          var order = findOrder(currentRef);
          if (order) {
            if (!order.internalNotes) order.internalNotes = [];
            order.internalNotes.push({
              username: adminName,
              content: content,
              timestamp: new Date().toISOString()
            });
          }
          // Re-render
          if (order) renderBody(order);
          if (typeof showNotification === 'function') showNotification('Note added', 'success');
        } else {
          if (typeof showNotification === 'function') showNotification('Failed to save note', 'error');
          input.disabled = false;
        }
      })
      .catch(function() {
        if (typeof showNotification === 'function') showNotification('Error saving note', 'error');
        input.disabled = false;
      });
  }

  // Print the slideout contents as a clean, standalone printable page
  function printSlideout() {
    if (!currentRef) return;
    var order = findOrder(currentRef);
    if (!order) return;

    var ref = esc(order.referenceCode || '');
    var status = order.status || 'unpaid';
    var statusLabel = STATUS_LABELS[status] || status;
    var ci = order.customerInfo || {};
    var dim = order.dimensions || {};
    var pricing = order.pricing || {};
    var event = order.event || {};
    var delivery = order.deliveryOption || 'mtcc';
    var deliveryTime = order.deliveryTime || 'anytime';
    var material = (order.material === 'fabric') ? 'Fabric' : 'Poster';

    function row(lbl, val) {
      return '<tr><td class="lbl">' + lbl + '</td><td class="val">' + val + '</td></tr>';
    }

    var trackNum = genTrackingNumber(order);
    var detailsRows =
      row('Customer', esc(ci.name || 'N/A')) +
      row('Size', (dim.width || '?') + '" &times; ' + (dim.height || '?') + '"') +
      row('Material', material) +
      row('Due', fmtDate(order.selectedDate) + ' · by ' + (TIME_LABELS[deliveryTime] || deliveryTime)) +
      row('Delivery', (delivery === 'mtcc' ? 'MTCC Pick-up' : 'Address Delivery')) +
      row('Event', esc(event.name || event.acronym || 'N/A')) +
      row('Tier', esc(pricing.tier || 'Standard')) +
      (trackNum ? row('Tracking #', '<span style="font-family:monospace;">' + esc(trackNum) + '</span>') : '');

    var pricingRows = '';
    if (pricing.basePrice) pricingRows += row('Base Price', fmtMoney(pricing.basePrice));
    if (pricing.deliveryFee > 0) pricingRows += row('Delivery Fee', fmtMoney(pricing.deliveryFee));
    if (pricing.tax) pricingRows += row('Tax (HST 13%)', fmtMoney(pricing.tax));
    pricingRows += '<tr class="total-row"><td class="lbl">Total</td><td class="val">' + fmtMoney(pricing.total) + '</td></tr>';

    var pickupRows = '';
    if (order.pickup && order.pickup.picked_up_at) {
      pickupRows =
        row('Picked Up', fmtDateTime(order.pickup.picked_up_at)) +
        row('By', esc(order.pickup.pickup_person || 'N/A') + (order.pickup.same_as_customer ? ' <em>(customer)</em>' : ''));
    }

    var html =
'<!DOCTYPE html><html><head><meta charset="UTF-8">' +
'<title>Order ' + ref + '</title>' +
'<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">' +
'<style>' +
'* { box-sizing: border-box; }' +
'body { font-family: Montserrat, -apple-system, sans-serif; margin: 0; padding: 32px 40px; color: #1e1b2e; background: white; }' +
'.pp-header { display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 20px; border-bottom: 2px solid #7c3aed; margin-bottom: 24px; }' +
'.pp-logo img { max-width: 180px; height: auto; }' +
'.pp-title-block { text-align: right; }' +
'.pp-ref { font-size: 1.4rem; font-weight: 700; color: #7c3aed; letter-spacing: 0.5px; margin-bottom: 4px; }' +
'.pp-status { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 0.72rem; font-weight: 700; color: white; background: #7c3aed; text-transform: uppercase; letter-spacing: 0.5px; }' +
'.pp-section { margin-bottom: 20px; page-break-inside: avoid; }' +
'.pp-section-title { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #e5e7eb; }' +
'.pp-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }' +
'.pp-table td { padding: 6px 0; vertical-align: top; }' +
'.pp-table .lbl { width: 130px; color: #6b7280; font-weight: 500; }' +
'.pp-table .val { color: #1e1b2e; font-weight: 600; }' +
'.pp-table .total-row td { padding-top: 10px; border-top: 1.5px solid #7c3aed; font-size: 1rem; }' +
'.pp-table .total-row .val { color: #7c3aed; font-weight: 700; }' +
'.pp-footer { margin-top: 32px; padding-top: 16px; border-top: 1px solid #e5e7eb; text-align: center; font-size: 0.7rem; color: #9ca3af; letter-spacing: 0.3px; }' +
'@media print { body { padding: 20px 28px; } @page { margin: 10mm; } }' +
'</style></head><body>' +

'<div class="pp-header">' +
  '<div class="pp-logo"><img src="/mtcc-ps-logo.png" alt="MTCC + Print Stuff"></div>' +
  '<div class="pp-title-block">' +
    '<div class="pp-ref">#' + ref + '</div>' +
    '<span class="pp-status status-' + status + '">' + statusLabel + '</span>' +
  '</div>' +
'</div>' +

'<div class="pp-section">' +
  '<div class="pp-section-title">Order Details</div>' +
  '<table class="pp-table"><tbody>' + detailsRows + '</tbody></table>' +
'</div>' +

'<div class="pp-section">' +
  '<div class="pp-section-title">Pricing</div>' +
  '<table class="pp-table"><tbody>' + pricingRows + '</tbody></table>' +
'</div>' +

(pickupRows ?
  '<div class="pp-section">' +
    '<div class="pp-section-title">Pickup Record</div>' +
    '<table class="pp-table"><tbody>' + pickupRows + '</tbody></table>' +
  '</div>' : '') +

'<div class="pp-footer">Printed ' + new Date().toLocaleString() + ' &middot; Print Stuff &middot; Metro Toronto Convention Centre</div>' +

'</body></html>';

    var printWindow = window.open('', '_blank', 'width=800,height=900');
    printWindow.document.write(html);
    printWindow.document.close();
    setTimeout(function() { printWindow.print(); }, 500);
  }

  // Download PDF — triggers print dialog, user selects "Save as PDF"
  function downloadPDF() {
    printSlideout();
  }

  return { open: open, close: close, saveNote: saveNote, print: printSlideout, downloadPDF: downloadPDF };
})();
