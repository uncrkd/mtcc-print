/**
 * Kanban Board View for Order Dashboard
 * Renders orders as cards in status columns with drag-and-drop
 */

var KanbanBoard = (function() {
  // Status columns in workflow order
  var COLUMNS = [
    { key: 'unpaid', label: 'Unpaid', color: '#eab308' },
    { key: 'paid', label: 'Paid', color: '#ca8a04' },
    { key: 'preflight', label: 'Sent to Vendor', color: '#8b5cf6' },
    { key: 'file_issue', label: 'File Issue', color: '#ea580c' },
    { key: 'printing', label: 'Printing', color: '#6366f1' },
    { key: 'ready', label: 'Ready to Ship', color: '#d97706' },
    { key: 'dispatched', label: 'Courier Assigned', color: '#7c3aed' },
    { key: 'shipped', label: 'Shipped', color: '#14b8a6' },
    { key: 'delivered', label: 'Delivered', color: '#059669' },
    { key: 'pickedup', label: 'Picked Up', color: '#22c55e' }
  ];

  var container = null;
  var isVisible = false;

  function init() {
    container = document.getElementById('kanbanContainer');
    if (!container) return;

    // Build columns
    var html = '<div class="kanban-board">';
    COLUMNS.forEach(function(col) {
      html += '<div class="kanban-column" data-status="' + col.key + '">' +
        '<div class="kanban-column-header" style="border-top-color: ' + col.color + '">' +
          '<span class="kanban-col-title">' + col.label + '</span>' +
          '<span class="kanban-col-count" id="kanbanCount_' + col.key + '">0</span>' +
        '</div>' +
        '<div class="kanban-column-body" id="kanbanCol_' + col.key + '" ' +
          'ondragover="KanbanBoard.handleDragOver(event)" ' +
          'ondrop="KanbanBoard.handleDrop(event, \'' + col.key + '\')">' +
        '</div>' +
      '</div>';
    });
    html += '</div>';
    container.innerHTML = html;
  }

  function render() {
    if (!window.dashboardData || !window.dashboardData.orders) return;

    var orders = window.dashboardData.orders;

    // Clear all columns
    COLUMNS.forEach(function(col) {
      var colBody = document.getElementById('kanbanCol_' + col.key);
      if (colBody) colBody.innerHTML = '';
    });

    // Count per column
    var counts = {};
    COLUMNS.forEach(function(col) { counts[col.key] = 0; });

    // Sort orders by due date (soonest first)
    var sorted = orders.slice().sort(function(a, b) {
      var da = a.selectedDate || '9999';
      var db = b.selectedDate || '9999';
      return da.localeCompare(db);
    });

    sorted.forEach(function(order) {
      var status = order.status || 'unpaid';
      var colBody = document.getElementById('kanbanCol_' + status);
      if (!colBody) return; // Skip terminal statuses not in columns

      counts[status] = (counts[status] || 0) + 1;

      var ref = order.referenceCode || 'Unknown';
      var name = (order.customerInfo && order.customerInfo.name) ? order.customerInfo.name : 'Unknown';
      var dueDate = '';
      if (order.selectedDate) {
        var d = new Date(order.selectedDate + 'T00:00:00');
        dueDate = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        var timeLabels = { '9am': '9:00 AM', '12pm': '12:00 PM', '3pm': '3:00 PM', '6pm': '6:00 PM', 'anytime': 'Anytime' };
        var deliveryTime = order.deliveryTime || 'anytime';
        dueDate += ' @ ' + (timeLabels[deliveryTime] || deliveryTime);
      }
      var total = order.pricing && order.pricing.total ? '$' + parseFloat(order.pricing.total).toFixed(2) : '';
      var size = (order.dimensions && order.dimensions.width && order.dimensions.height) ? order.dimensions.width + '" x ' + order.dimensions.height + '"' : '';

      // Priority class
      var priority = 'standard';
      if (order.pricing && order.pricing.tier) {
        var tier = order.pricing.tier.toLowerCase();
        if (tier.indexOf('last minute') !== -1) priority = 'lastminute';
        else if (tier.indexOf('critical') !== -1) priority = 'critical';
        else if (tier.indexOf('urgent') !== -1) priority = 'urgent';
        else if (tier.indexOf('rush') !== -1) priority = 'rush';
        else if (tier.indexOf('early') !== -1) priority = 'early';
      }

      var card = document.createElement('div');
      card.className = 'kanban-card';
      card.draggable = true;
      card.dataset.reference = ref;
      card.dataset.status = status;
      card.ondragstart = function(e) { handleDragStart(e, ref); };

      card.innerHTML =
        '<div class="kanban-card-header">' +
          '<a href="?view=' + encodeURIComponent(ref) + '" class="kanban-card-ref" onclick="if(typeof OrderSlideout!==\'undefined\'){OrderSlideout.open(\'' + escapeHtml(ref) + '\');return false;}">' + escapeHtml(ref) + '</a>' +
          '<span class="kanban-card-priority priority-indicator ' + priority + '">' + priority.charAt(0).toUpperCase() + '</span>' +
        '</div>' +
        '<div class="kanban-card-line">' + escapeHtml(name) + '</div>' +
        (dueDate ? '<div class="kanban-card-line kanban-card-date">' + dueDate + '</div>' : '') +
        (size ? '<div class="kanban-card-line">' + size + '</div>' : '') +
        (total ? '<div class="kanban-card-line kanban-card-price">' + total + '</div>' : '');

      colBody.appendChild(card);
    });

    // Update counts
    COLUMNS.forEach(function(col) {
      var countEl = document.getElementById('kanbanCount_' + col.key);
      if (countEl) countEl.textContent = counts[col.key] || 0;
    });
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function handleDragStart(e, ref) {
    e.dataTransfer.setData('text/plain', ref);
    e.dataTransfer.effectAllowed = 'move';
    e.target.classList.add('dragging');
  }

  function handleDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    e.currentTarget.classList.add('drag-over');
  }

  function handleDrop(e, newStatus) {
    e.preventDefault();
    e.currentTarget.classList.remove('drag-over');

    var ref = e.dataTransfer.getData('text/plain');
    if (!ref) return;

    // Remove dragging class from all cards
    document.querySelectorAll('.kanban-card.dragging').forEach(function(c) {
      c.classList.remove('dragging');
    });

    // Find the card's current status
    var card = document.querySelector('.kanban-card[data-reference="' + ref + '"]');
    if (!card || card.dataset.status === newStatus) return;

    // AJAX status update (same as table view)
    var formData = new FormData();
    formData.append('update_status', '1');
    formData.append('reference_code', ref);
    formData.append('status', newStatus);

    fetch('admin-orders.php', {
      method: 'POST',
      body: formData
    }).then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success) {
        // Update the card's status and move it
        card.dataset.status = newStatus;
        var targetCol = document.getElementById('kanbanCol_' + newStatus);
        if (targetCol) targetCol.appendChild(card);

        // Update dashboardData
        if (window.dashboardData && window.dashboardData.orders) {
          var order = window.dashboardData.orders.find(function(o) { return o.referenceCode === ref; });
          if (order) order.status = newStatus;
        }

        // Update counts
        render();

        if (typeof showNotification === 'function') {
          showNotification(ref + ' moved to ' + newStatus, 'success');
        }
      } else {
        if (typeof showNotification === 'function') {
          showNotification('Failed to update status: ' + (data.error || 'Unknown error'), 'error');
        }
      }
    }).catch(function(err) {
      if (typeof showNotification === 'function') {
        showNotification('Error updating status', 'error');
      }
    });
  }

  function show() {
    if (!container) init();
    render();
    container.style.display = '';
    var tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.style.display = 'none';
    // Hide pagination
    var pagination = document.getElementById('paginationNav');
    if (pagination) pagination.style.display = 'none';
    isVisible = true;
  }

  function hide() {
    if (container) container.style.display = 'none';
    var tableContainer = document.querySelector('.orders-table-container');
    if (tableContainer) tableContainer.style.display = '';
    var pagination = document.getElementById('paginationNav');
    if (pagination) pagination.style.display = '';
    isVisible = false;
  }

  function toggle() {
    if (isVisible) hide();
    else show();
  }

  return {
    init: init,
    render: render,
    show: show,
    hide: hide,
    toggle: toggle,
    handleDragOver: handleDragOver,
    handleDrop: handleDrop,
    isVisible: function() { return isVisible; }
  };
})();
