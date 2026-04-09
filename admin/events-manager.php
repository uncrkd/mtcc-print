<?php
/**
 * Event Manager - MTCC Poster System
 * Manages events for poster printing operations
 */
require_once '../admin-auth.php';

// Require at least view permission for events
requireAnyPermission(['events_edit', 'events_view']);

// Permission helper variables for template use
$canEditEvents = hasPermission('events_edit');
$canViewAnalytics = hasPermission('events_analytics');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Manager - MTCC Poster System</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin-base.css">
    <link rel="stylesheet" href="../css/admin-layout.css">
    <link rel="stylesheet" href="events-styles.css">
    
    <style>
        /* =====================================================
           UNIFIED DROPDOWN SYSTEM
           ===================================================== */
        
        /* Dropdown Container */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        
        /* Dropdown Trigger - Base */
        .dropdown-trigger {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            border: none;
            font-family: inherit;
            transition: all 0.15s ease;
        }
        
        /* Dropdown Trigger - Sandwich Menu Style */
        .dropdown-trigger.sandwich {
            width: 32px;
            height: 32px;
            padding: 0.375rem;
            background: white;
            color: #64748b;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        .dropdown-trigger.sandwich:hover {
            background: #f8fafc;
            color: #475569;
            border-color: #cbd5e1;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
        }
        
        /* Dropdown Trigger - Priority Badge Style */
        .dropdown-trigger.priority {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        .dropdown-trigger.priority:hover:not(:disabled) {
            filter: brightness(0.92);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
        }
        .dropdown-trigger.priority:disabled {
            cursor: default;
            opacity: 0.7;
        }
        .dropdown-trigger.priority:disabled:hover {
            filter: none;
            box-shadow: none;
        }
        .dropdown-trigger.priority-high {
            background: #fee2e2;
            color: #991b1b;
        }
        .dropdown-trigger.priority-standard {
            background: #e0e7ff;
            color: #3730a3;
        }
        .dropdown-trigger.priority-low {
            background: #f3f4f6;
            color: #374151;
        }
        
        /* Dropdown Menu */
        .dropdown-menu {
            position: absolute;
            top: 100%;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            min-width: 140px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-8px) scale(0.95);
            transition: all 0.15s ease;
            padding: 4px;
            margin-top: 4px;
        }
        .dropdown-menu.right {
            right: 0;
        }
        .dropdown-menu.left {
            left: 0;
        }
        .dropdown-menu.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        
        /* Dropdown Menu Items */
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            color: #475569;
            cursor: pointer;
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-size: 0.8rem;
            font-family: inherit;
            font-weight: 500;
            transition: all 0.12s ease;
            border-radius: 6px;
            text-decoration: none;
        }
        .dropdown-item:hover {
            background: #f1f5f9;
            color: #334155;
        }
        .dropdown-item.selected {
            background: #f1f5f9;
        }
        .dropdown-item.danger {
            color: #ef4444;
        }
        .dropdown-item.danger:hover {
            background: #fef2f2;
            color: #dc2626;
        }
        
        /* Priority Dot Indicator */
        .priority-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        .priority-dot.high { background: #dc2626; }
        .priority-dot.standard { background: #4f46e5; }
        .priority-dot.low { background: #6b7280; }
    </style>
<link rel="stylesheet" href="../css/admin-sidebar.css">
</head>
<body>
<?php require_once __DIR__ . '/../includes/admin-sidebar.php'; renderSidebar('events'); ?>
<script src="../js/admin-sidebar.js"></script>
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-header-left">
                <h1 class="page-title">Event Management</h1>
                <div class="page-welcome">
                    <span class="welcome-text">Welcome Admin! &#128075;</span>
                    <span class="welcome-date">Today is <span id="current-date"></span></span>
                </div>
            </div>
        </div>

        <?php if ($canViewAnalytics): ?>
        <!-- Statistics Dashboard -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">&#128176;</div>
                    <div class="stat-title">Total Revenue</div>
                </div>
                <div class="stat-number" id="totalRevenue">$0
                    <div class="stat-comparison positive"><span>base:</span> <span id="baseRevenueText">$0</span></div>
                </div>            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">&#128181;</div>
                    <div class="stat-title">MTCC Venue Fee</div>
                </div>
                <div class="stat-number" id="totalVenueFee">$0
                    <div class="stat-comparison positive"><span>10%</span> <span>of base</span></div>
                </div>
			</div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon"><?= ICON_PACKAGE ?></div>
                    <div class="stat-title">Total Orders</div>
                </div>
                <div class="stat-number" id="totalOrdersCount">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">&#128640;</div>
                    <div class="stat-title">Active Events</div>
                </div>
                <div class="stat-number" id="activeEventsCount">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">&#9203;</div>
                    <div class="stat-title">Upcoming Events</div>
                </div>
                <div class="stat-number" id="upcomingEventsCount">0</div>
            </div>
            <div class="stat-card">
                <div class="stat-header">
                    <div class="stat-icon">&#128200;</div>
                    <div class="stat-title">Events This Year</div>
                </div>
                <div class="stat-number" id="showsThisYear">0</div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Messages -->
        <div id="messageContainer"></div>

        <!-- Active Events Section -->
        <div class="events-section">
            <div class="section-header active-section">
                <div class="section-header-left">
                    <h2 class="section-title">&#127919; Active Events</h2>
                </div>
                <div class="section-header-right">
                    <span class="last-updated-info">Last updated: <span id="lastUpdated">Just now</span></span>
                    <div class="vertical-divider"></div>
                    <?php if ($canEditEvents): ?>
                    <button onclick="showAddEventModal()" class="header-btn header-btn-primary">
                        <span style="color: var(--primary);">&#10133;</span> Add New Event
                    </button>
                    <?php endif; ?>
                    <div class="dropdown">
                        <button onclick="toggleDropdown('headerMenu')" class="dropdown-trigger sandwich">&#8942;</button>
                        <div id="headerMenu" class="dropdown-menu right" style="min-width: 160px!important;">
                            <?php if ($canEditEvents): ?>
                            <button onclick="archiveExpiredEvents()" class="dropdown-item">&#128194; Archive Expired</button>
                            <?php endif; ?>
                            <button onclick="exportEvents()" class="dropdown-item">&#128228; Export Data</button>
                            <button onclick="loadEvents()" class="dropdown-item">&#8634; Refresh</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="section-body">
                <div id="activeEventsContainer"></div>
            </div>
        </div>

        <!-- Archived Events Section -->
        <div class="events-section">
            <div class="section-header archived-section">
                <h2 class="section-title">&#128450;&#65039; Archived Events</h2>
            </div>
            <div class="section-body">
                <div id="archivedEventsContainer"></div>
            </div>
        </div>
    </div>


<!-- Event Modal -->
<div id="eventModal" class="modal-overlay">
    <div class="modal" style="width: 600px; max-width: 95%;">
        <div class="modal-header">
            <h2 class="modal-title" id="modalTitle">Add New Event</h2>
        </div>
        <form id="eventForm">
            <div class="modal-body">
                <!-- Row 1: Event Name + Acronym -->
                <div class="form-row" style="display: flex; gap: 1rem;">
                    <div class="form-group" style="flex: 2;">
                        <label class="form-label" for="eventName">Event Name *</label>
                        <input type="text" id="eventName" class="form-control" placeholder="e.g., Fan Expo Canada" required>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="eventAcronym">Acronym *</label>
                        <input type="text" id="eventAcronym" class="form-control" placeholder="e.g., FANEXPO" required style="text-transform: uppercase;">
                        <div class="form-help">Used for order numbers</div>
                    </div>
                </div>
                <!-- Row 2: Building + Priority -->
                <div class="form-row" style="display: flex; gap: 1rem;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="eventBuilding">MTCC Building *</label>
                        <select id="eventBuilding" class="form-control" required>
                            <option value="">Select building</option>
                            <option value="north">North Building (255 Front St W)</option>
                            <option value="south">South Building (222 Bremner Blvd)</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="eventPriority">Priority *</label>
                        <select id="eventPriority" class="form-control" required>
                            <option value="">Select priority</option>
                            <option value="high">High</option>
                            <option value="standard">Standard</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <!-- Row 3: Event Dates -->
                <div class="form-group">
                    <label class="form-label">Event Dates *</label>
                    <div class="date-inputs" style="display: flex; gap: 1rem;">
                        <div style="flex: 1;">
                            <label class="form-label" for="eventStartDate">Start Date</label>
                            <input type="date" id="eventStartDate" class="form-control" required>
                        </div>
                        <div style="flex: 1;">
                            <label class="form-label" for="eventEndDate">End Date</label>
                            <input type="date" id="eventEndDate" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-help">End date is used for automatic archiving</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeEventModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn" style="background: var(--primary); color: white;">
                    <span id="saveButtonText">&#10003; Create Event</span>
                    <span id="saveLoader" class="loading hidden"></span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// =============================================================================
// APPLICATION STATE
// =============================================================================
let eventsData = { active: [], archived: [], metadata: {} };
let currentEditingEvent = null;

// =============================================================================
// INITIALIZATION
// =============================================================================
document.addEventListener('DOMContentLoaded', function() {
    const options = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
    document.getElementById('current-date').textContent = new Date().toLocaleDateString('en-US', options);
    loadEvents();
});

// =============================================================================
// DATA LOADING & SAVING
// =============================================================================
async function loadEvents() {
    try {
        showMessage('Loading events...', 'info');
        const response = await fetch('get-events.php');
        const data = await response.json();
        
        if (data.success) {
            eventsData = data.data;
            eventsData.active.sort((a, b) => new Date(b.startDate || b.endDate) - new Date(a.startDate || a.endDate));
            renderEvents();
            updateStats();
            updateLastUpdated();
            showMessage('Events loaded successfully!', 'success');
        } else {
            throw new Error(data.message || 'Failed to load events');
        }
    } catch (error) {
        console.error('Error loading events:', error);
        showMessage('Error loading events: ' + error.message, 'error');
        eventsData = { active: [], archived: [], metadata: {} };
        renderEvents();
        updateStats();
    }
}

async function saveEvents() {
    try {
        const response = await fetch('save-events.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(eventsData)
        });
        const result = await response.json();
        if (!result.success) throw new Error(result.message || 'Failed to save events');
        updateLastUpdated();
        return true;
    } catch (error) {
        console.error('Error saving events:', error);
        showMessage('Error saving events: ' + error.message, 'error');
        return false;
    }
}

function updateLastUpdated() {
    document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString();
}

// =============================================================================
// RENDERING
// =============================================================================
function renderEvents() {
    renderActiveEvents();
    renderArchivedEvents();
}

function renderActiveEvents() {
    const container = document.getElementById('activeEventsContainer');
    
    if (eventsData.active.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">&#128200;</div>
                <h3>No active events found</h3>
                <p>Click "Add New Event" to get started</p>
            </div>`;
        return;
    }
    
    container.innerHTML = `
        <table class="events-table" id="activeEventsTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable('name')">Event <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('dates')">Dates <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('status')">Status <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('orders')">Orders <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('revenue')">Revenue <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('venue_fee')">Venue Fee <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('priority')">Priority <span class="sort-indicator">&#8597;</span></th>
                    <th style="text-align: right; padding-right: 2rem;"></th>
                </tr>
            </thead>
            <tbody>
                ${eventsData.active.map(event => createEventRow(event, 'active')).join('')}
            </tbody>
        </table>`;
}

function renderArchivedEvents() {
    const container = document.getElementById('archivedEventsContainer');
    
    if (eventsData.archived.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <div class="empty-state-icon">&#128450;</div>
                <h3>No archived events</h3>
                <p>Completed events will appear here</p>
            </div>`;
        return;
    }
    
    container.innerHTML = `
        <table class="events-table" id="archivedEventsTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable('name', 'archived')">Event <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('dates', 'archived')">Dates <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('status', 'archived')">Status <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('orders', 'archived')">Orders <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('revenue', 'archived')">Revenue <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('venue_fee', 'archived')">Venue Fee <span class="sort-indicator">&#8597;</span></th>
                    <th class="sortable" onclick="sortTable('priority', 'archived')">Priority <span class="sort-indicator">&#8597;</span></th>
                    <th style="text-align: right; padding-right: 2rem;"></th>
                </tr>
            </thead>
            <tbody>
                ${eventsData.archived.map(event => createEventRow(event, 'archived')).join('')}
            </tbody>
        </table>`;
}

function createEventRow(event, type) {
    const today = new Date();
    const startDate = new Date(event.startDate + 'T12:00:00');
    const endDate = new Date(event.endDate + 'T12:00:00');
    
    let statusClass, statusText;
    if (type === 'archived') {
        statusClass = 'status-expired';
        statusText = 'Ended';
    } else if (today < startDate) {
        statusClass = 'status-upcoming';
        statusText = 'Upcoming';
    } else if (today > endDate) {
        statusClass = 'status-expired';
        statusText = 'Ended';
    } else {
        statusClass = 'status-active';
        statusText = 'Active';
    }
    
    const orderCount = event.orderCount || 0;
    const totalRevenue = event.totalRevenue || 0;
    const baseRevenue = event.baseRevenue || 0;
    const venueFee = baseRevenue * 0.1;
    const priority = event.priority || 'standard';
    
    const actionButtons = type === 'active' 
        ? `<button onclick="editEvent('${event.id}')" class="dropdown-item">&#9999; Edit</button>
           <button onclick="archiveEvent('${event.id}')" class="dropdown-item">&#128194; Archive</button>
           <button onclick="deleteEvent('${event.id}')" class="dropdown-item danger">&#128465; Delete</button>`
        : `<button onclick="restoreEvent('${event.id}')" class="dropdown-item">&#8617; Restore</button>
           <button onclick="deleteEvent('${event.id}', true)" class="dropdown-item danger">&#128465; Delete</button>`;
    
    const priorityMenu = type === 'active' 
        ? `<div id="priority-${event.id}" class="dropdown-menu left">
               <button class="dropdown-item ${priority === 'high' ? 'selected' : ''}" onclick="selectPriority('${event.id}', 'high', '${type}')">
                   <span class="priority-dot high"></span> High
               </button>
               <button class="dropdown-item ${priority === 'standard' ? 'selected' : ''}" onclick="selectPriority('${event.id}', 'standard', '${type}')">
                   <span class="priority-dot standard"></span> Standard
               </button>
               <button class="dropdown-item ${priority === 'low' ? 'selected' : ''}" onclick="selectPriority('${event.id}', 'low', '${type}')">
                   <span class="priority-dot low"></span> Low
               </button>
           </div>`
        : '';
    
    return `
        <tr>
            <td>
                <div class="event-info">
                    <div class="event-name">${event.name}</div>
                    <div class="event-code">CODE: ${event.acronym}</div>
                </div>
            </td>
            <td>
                <div class="event-dates">${event.dates}</div>
                <div class="event-detail">Ends: ${formatDate(event.endDate)}</div>
            </td>
            <td><span class="status-badge ${statusClass}">${statusText}</span></td>
            <td><div class="order-count">${orderCount}</div></td>
            <td>
                <div class="revenue-amount">$${totalRevenue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;">
                    base: $${baseRevenue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}
                </div>
            </td>
            <td>
                <div class="revenue-amount">$${venueFee.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
                <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.25rem;">10% of base</div>
            </td>
            <td>
                <div class="dropdown">
                    <button class="dropdown-trigger priority priority-${priority}" 
                            onclick="${type === 'active' ? `toggleDropdown('priority-${event.id}')` : ''}"
                            ${type === 'archived' ? 'disabled' : ''}>
                        ${priority.toUpperCase()}
                    </button>
                    ${priorityMenu}
                </div>
            </td>
            <td style="text-align: right; padding-right: 2rem;">
                <div class="dropdown">
                    <button onclick="toggleDropdown('actions-${event.id}')" class="dropdown-trigger sandwich">&#8942;</button>
                    <div id="actions-${event.id}" class="dropdown-menu right">${actionButtons}</div>
                </div>
            </td>
        </tr>`;
}

// =============================================================================
// STATISTICS
// =============================================================================
function updateStats() {
    const today = new Date();
    const allEvents = [...eventsData.active, ...eventsData.archived];
    
    const activeCount = eventsData.active.filter(event => {
        const start = new Date(event.startDate + 'T12:00:00');
        const end = new Date(event.endDate + 'T12:00:00');
        return today >= start && today <= end;
    }).length;
    
    const upcomingCount = eventsData.active.filter(event => {
        return today < new Date(event.startDate + 'T12:00:00');
    }).length;
    
    const totalOrders = allEvents.reduce((sum, e) => sum + (e.orderCount || 0), 0);
    const totalRevenue = allEvents.reduce((sum, e) => sum + (e.totalRevenue || 0), 0);
    const baseRevenue = allEvents.reduce((sum, e) => sum + (e.baseRevenue || 0), 0);
    const totalVenueFee = baseRevenue * 0.1;
    
    const currentYear = new Date().getFullYear();
    const showsThisYear = allEvents.filter(e => {
        return new Date(e.startDate || e.endDate).getFullYear() === currentYear;
    }).length;
    
    document.getElementById('activeEventsCount').textContent = activeCount;
    document.getElementById('upcomingEventsCount').textContent = upcomingCount;
    document.getElementById('totalOrdersCount').textContent = totalOrders;
    document.getElementById('showsThisYear').textContent = showsThisYear;
    document.getElementById('totalRevenue').firstChild.textContent = `$${totalRevenue.toLocaleString()}`;
    document.getElementById('baseRevenueText').textContent = `$${baseRevenue.toLocaleString()}`;
    document.getElementById('totalVenueFee').firstChild.textContent = `$${totalVenueFee.toLocaleString()}`;
}

// =============================================================================
// SORTING
// =============================================================================
// Track sort state separately for each table
let activeSortColumn = null;
let activeSortDirection = 'asc';
let archivedSortColumn = null;
let archivedSortDirection = 'asc';

function sortTable(column, tableType = 'active') {
    // Determine which table we're sorting
    const isArchived = tableType === 'archived';
    const tableId = isArchived ? 'archivedEventsTable' : 'activeEventsTable';
    const table = document.getElementById(tableId);
    
    // Clear sort indicators only for this table
    if (table) {
        table.querySelectorAll('.sort-indicator').forEach(ind => {
            ind.classList.remove('active');
            ind.textContent = '\u2195';
        });
    }
    
    // Get/set the correct sort state
    let currentDirection;
    if (isArchived) {
        if (archivedSortColumn === column) {
            archivedSortDirection = archivedSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            archivedSortDirection = 'asc';
            archivedSortColumn = column;
        }
        currentDirection = archivedSortDirection;
    } else {
        if (activeSortColumn === column) {
            activeSortDirection = activeSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            activeSortDirection = 'asc';
            activeSortColumn = column;
        }
        currentDirection = activeSortDirection;
    }
    
    // Update the clicked indicator
    const indicator = event.target.querySelector('.sort-indicator');
    if (indicator) {
        indicator.classList.add('active');
        indicator.textContent = currentDirection === 'asc' ? '\u2191' : '\u2193';
    }
    
    // Get the correct array to sort
    const arrayToSort = isArchived ? eventsData.archived : eventsData.active;
    const sortDir = currentDirection;
    
    arrayToSort.sort((a, b) => {
        let aVal, bVal;
        switch(column) {
            case 'name':
                aVal = a.name.toLowerCase();
                bVal = b.name.toLowerCase();
                break;
            case 'dates':
                aVal = new Date(a.endDate);
                bVal = new Date(b.endDate);
                break;
            case 'status':
                const today = new Date();
                aVal = new Date(a.endDate) < today ? 1 : 0;
                bVal = new Date(b.endDate) < today ? 1 : 0;
                break;
            case 'orders':
                aVal = a.orderCount || 0;
                bVal = b.orderCount || 0;
                break;
            case 'revenue':
                aVal = a.totalRevenue || 0;
                bVal = b.totalRevenue || 0;
                break;
            case 'venue_fee':
                aVal = (a.baseRevenue || 0) * 0.1;
                bVal = (b.baseRevenue || 0) * 0.1;
                break;
            case 'priority':
                const order = { high: 3, standard: 2, low: 1 };
                aVal = order[a.priority || 'standard'];
                bVal = order[b.priority || 'standard'];
                break;
            default:
                return 0;
        }
        if (aVal < bVal) return sortDir === 'asc' ? -1 : 1;
        if (aVal > bVal) return sortDir === 'asc' ? 1 : -1;
        return 0;
    });
    
    // Render the correct table
    if (isArchived) {
        renderArchivedEvents();
    } else {
        renderActiveEvents();
    }
}

// =============================================================================
// UNIFIED DROPDOWN SYSTEM
// =============================================================================
function toggleDropdown(menuId) {
    const menu = document.getElementById(menuId);
    const allMenus = document.querySelectorAll('.dropdown-menu');
    
    // Close all other menus
    allMenus.forEach(m => {
        if (m.id !== menuId) m.classList.remove('show');
    });
    
    menu.classList.toggle('show');
}

// Close all dropdowns on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    }
});

// =============================================================================
// PRIORITY QUICK-CHANGE
// =============================================================================
async function selectPriority(eventId, newPriority, type) {
    // Close menu
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    
    const array = type === 'archived' ? eventsData.archived : eventsData.active;
    const event = array.find(e => e.id === eventId);
    if (!event) return;
    
    const oldPriority = event.priority;
    event.priority = newPriority;
    
    if (await saveEvents()) {
        showMessage(`Priority updated to ${newPriority.toUpperCase()}`, 'success');
        renderEvents();
    } else {
        event.priority = oldPriority;
        renderEvents();
    }
}

// =============================================================================
// MODAL HANDLING
// =============================================================================
function showAddEventModal() {
    currentEditingEvent = null;
    document.getElementById('modalTitle').textContent = 'Add New Event';
    document.getElementById('saveButtonText').textContent = '\u2713 Create Event';
    document.getElementById('eventForm').reset();
    document.getElementById('eventModal').style.display = 'flex';
}

function editEvent(eventId) {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    
    const event = eventsData.active.find(e => e.id === eventId);
    if (!event) return;
    
    currentEditingEvent = event;
    document.getElementById('modalTitle').textContent = 'Edit Event';
    document.getElementById('saveButtonText').textContent = '\u270F Update Event';
    
    document.getElementById('eventAcronym').value = event.acronym;
    document.getElementById('eventName').value = event.name;
    document.getElementById('eventPriority').value = event.priority || 'standard';
    document.getElementById('eventBuilding').value = event.building || 'north';
    document.getElementById('eventStartDate').value = event.startDate;
    document.getElementById('eventEndDate').value = event.endDate;
    
    document.getElementById('eventModal').style.display = 'flex';
}

function closeEventModal() {
    document.getElementById('eventModal').style.display = 'none';
    currentEditingEvent = null;
}

// =============================================================================
// FORM SUBMISSION
// =============================================================================
document.getElementById('eventForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const acronym = document.getElementById('eventAcronym').value.trim().toUpperCase();
    const name = document.getElementById('eventName').value.trim();
    const priority = document.getElementById('eventPriority').value;
    const building = document.getElementById('eventBuilding').value;
    const startDate = document.getElementById('eventStartDate').value;
    const endDate = document.getElementById('eventEndDate').value;
    
    if (!acronym || !name || !priority || !building || !startDate || !endDate) {
        showMessage('Please fill in all required fields.', 'error');
        return;
    }
    
    if (new Date(startDate) > new Date(endDate)) {
        showMessage('Start date cannot be after end date.', 'error');
        return;
    }
    
    if (!currentEditingEvent || currentEditingEvent.acronym !== acronym) {
        const exists = [...eventsData.active, ...eventsData.archived].some(e => e.acronym === acronym);
        if (exists) {
            showMessage('An event with this acronym already exists.', 'error');
            return;
        }
    }
    
    document.getElementById('saveButtonText').classList.add('hidden');
    document.getElementById('saveLoader').classList.remove('hidden');
    
    const eventData = {
        id: acronym.toLowerCase(),
        acronym: acronym,
        name: name,
        priority: priority,
        building: building,
        dates: formatDateRange(startDate, endDate),
        startDate: startDate,
        endDate: endDate,
        fullName: `${acronym} - ${name} (${formatDateRange(startDate, endDate)})`,
        orderCount: currentEditingEvent?.orderCount || 0,
        totalRevenue: currentEditingEvent?.totalRevenue || 0,
        baseRevenue: currentEditingEvent?.baseRevenue || 0
    };
    
    try {
        if (currentEditingEvent) {
            const index = eventsData.active.findIndex(e => e.id === currentEditingEvent.id);
            if (index !== -1) eventsData.active[index] = eventData;
        } else {
            eventsData.active.push(eventData);
        }
        
        if (await saveEvents()) {
            closeEventModal();
            renderEvents();
            updateStats();
            showMessage(currentEditingEvent ? 'Event updated successfully!' : 'Event added successfully!', 'success');
        }
    } catch (error) {
        showMessage('Error saving event: ' + error.message, 'error');
    } finally {
        document.getElementById('saveButtonText').classList.remove('hidden');
        document.getElementById('saveLoader').classList.add('hidden');
    }
});

// =============================================================================
// EVENT ACTIONS
// =============================================================================
async function archiveEvent(eventId) {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    if (!confirm('Are you sure you want to archive this event?')) return;
    
    const index = eventsData.active.findIndex(e => e.id === eventId);
    if (index === -1) return;
    
    const event = eventsData.active.splice(index, 1)[0];
    eventsData.archived.push(event);
    
    if (await saveEvents()) {
        renderEvents();
        updateStats();
        showMessage('Event archived successfully!', 'success');
    }
}

async function restoreEvent(eventId) {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    if (!confirm('Are you sure you want to restore this event to active status?')) return;
    
    const index = eventsData.archived.findIndex(e => e.id === eventId);
    if (index === -1) return;
    
    const event = eventsData.archived.splice(index, 1)[0];
    eventsData.active.push(event);
    
    if (await saveEvents()) {
        renderEvents();
        updateStats();
        showMessage('Event restored successfully!', 'success');
    }
}

async function deleteEvent(eventId, isArchived = false) {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    if (!confirm('Are you sure you want to permanently delete this event? This action cannot be undone.')) return;
    
    const array = isArchived ? eventsData.archived : eventsData.active;
    const index = array.findIndex(e => e.id === eventId);
    if (index === -1) return;
    
    array.splice(index, 1);
    
    if (await saveEvents()) {
        renderEvents();
        updateStats();
        showMessage('Event deleted successfully!', 'success');
    }
}

async function archiveExpiredEvents() {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    const today = new Date();
    const expired = eventsData.active.filter(e => new Date(e.endDate) < today);
    
    if (expired.length === 0) {
        showMessage('No expired events to archive.', 'info');
        return;
    }
    
    if (!confirm(`Archive ${expired.length} expired event(s)?`)) return;
    
    eventsData.active = eventsData.active.filter(e => new Date(e.endDate) >= today);
    eventsData.archived.push(...expired);
    
    if (await saveEvents()) {
        renderEvents();
        updateStats();
        showMessage(`${expired.length} expired event(s) archived successfully!`, 'success');
    }
}

function exportEvents() {
    document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
    const blob = new Blob([JSON.stringify(eventsData, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `events-export-${new Date().toISOString().split('T')[0]}.json`;
    link.click();
    URL.revokeObjectURL(url);
}

// =============================================================================
// UTILITY FUNCTIONS
// =============================================================================
function formatDate(dateString) {
    return new Date(dateString + 'T12:00:00').toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatDateRange(startDate, endDate) {
    const start = new Date(startDate + 'T12:00:00');
    const end = new Date(endDate + 'T12:00:00');
    const startMonth = start.toLocaleDateString('en-US', { month: 'short' });
    const endMonth = end.toLocaleDateString('en-US', { month: 'short' });
    const year = end.getFullYear();
    
    if (startMonth === endMonth) {
        return `${startMonth} ${start.getDate()}-${end.getDate()}, ${year}`;
    }
    return `${startMonth} ${start.getDate()} - ${endMonth} ${end.getDate()}, ${year}`;
}

function showMessage(message, type) {
    const container = document.getElementById('messageContainer');
    const className = type === 'error' ? 'message-error' : type === 'info' ? 'message-info' : 'message-success';
    
    container.innerHTML = `<div class="message ${className}">${message}</div>`;
    container.classList.add('show');
    
    setTimeout(() => {
        container.classList.remove('show');
        setTimeout(() => container.innerHTML = '', 300);
    }, 5000);
}
</script>
</body>
</html>
