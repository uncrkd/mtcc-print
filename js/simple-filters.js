// Simple Filtering System - Complete with Multiple Selections + Event Prefix + Pagination + Styled Elements + Filters Toggle
class SimpleFilterManager {
    constructor() {
        this.filters = {
            priority: new Set(),
            status: new Set(),
            duedate: new Set(),
            prefix: new Set(),
            search: ''
        };
        
        // Events mode: 'active' shows only active/upcoming events, 'all' shows everything
        this.eventsMode = 'active';
        
        // Pagination properties
        this.pagination = {
            currentPage: 1,
            perPage: 25,
            totalItems: 0,
            totalPages: 1,
            filteredRows: []
        };
        
        // Sorting properties
        this.currentSort = {
            column: 'submitted',
            direction: 'desc'
        };
        
        this.init();
    }
    
    init() {
        this.setupButtons();
        this.setupSearch();
        this.setupClear();
        this.setupPagination();
        this.setupFiltersToggle();
        this.setupSorting();
        this.setupEventsToggle();
        this.styleFormElements();
        this.initializeWithAllRows();
        this.initializeDefaultSort();
        
        // Apply initial event mode filter
        this.applyFilters();
        
        // Trigger analytics recalculation after a delay to ensure analyticsManager exists
        setTimeout(() => {
            if (window.analyticsManager && typeof window.analyticsManager.recalculateForEventsMode === 'function') {
                window.analyticsManager.recalculateForEventsMode('active');
            }
        }, 2500);
        
    }
    
    setupFiltersToggle() {
    const toggleBtn = document.getElementById('filtersToggleBtn');
    const filtersContainer = document.getElementById('filtersContainer');
    
    if (toggleBtn && filtersContainer) {
        // Set initial collapsed state
        filtersContainer.classList.add('collapsed');
        toggleBtn.innerHTML = '&#127991;&#65039; Filters <span id="filtersToggleIcon">&#9660;</span>';
    }
}
    
    styleFormElements() {
        // Style the search box
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            Object.assign(searchBox.style, {
                background: 'white',
                border: '2px solid #e5e7eb',
                borderRadius: '8px',
                padding: '10px 12px',
                fontSize: '0.9rem',
                fontWeight: '500',
                color: '#374151',
                transition: 'all 0.2s ease',
                outline: 'none',
                fontFamily: 'inherit',
                boxShadow: '0 1px 2px rgba(0, 0, 0, 0.05)'
            });
            
            // Add focus effects
            searchBox.addEventListener('focus', () => {
                searchBox.style.borderColor = '#7c3aed';
                searchBox.style.background = '#faf5ff';
                searchBox.style.boxShadow = '0 0 0 3px rgba(124, 58, 237, 0.1)';
            });
            
            searchBox.addEventListener('blur', () => {
                searchBox.style.borderColor = '#e5e7eb';
                searchBox.style.background = 'white';
                searchBox.style.boxShadow = '0 1px 2px rgba(0, 0, 0, 0.05)';
            });
        }
        
        // Style the pagination dropdown
        const perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) {
            Object.assign(perPageSelect.style, {
                background: 'white',
                border: '2px solid #e5e7eb',
                borderRadius: '8px',
                padding: '8px 12px',
                fontSize: '0.9rem',
                fontWeight: '500',
                color: '#374151',
                cursor: 'pointer',
                transition: 'all 0.2s ease',
                minWidth: '100px',
                fontFamily: 'inherit',
                outline: 'none',
                boxShadow: '0 1px 2px rgba(0, 0, 0, 0.05)'
            });
            
            // Add hover and focus effects
            perPageSelect.addEventListener('mouseenter', () => {
                perPageSelect.style.borderColor = '#7c3aed';
                perPageSelect.style.background = '#faf5ff';
                perPageSelect.style.boxShadow = '0 2px 4px rgba(124, 58, 237, 0.1)';
            });
            
            perPageSelect.addEventListener('mouseleave', () => {
                if (document.activeElement !== perPageSelect) {
                    perPageSelect.style.borderColor = '#e5e7eb';
                    perPageSelect.style.background = 'white';
                    perPageSelect.style.boxShadow = '0 1px 2px rgba(0, 0, 0, 0.05)';
                }
            });
            
            perPageSelect.addEventListener('focus', () => {
                perPageSelect.style.borderColor = '#7c3aed';
                perPageSelect.style.background = '#faf5ff';
                perPageSelect.style.boxShadow = '0 0 0 3px rgba(124, 58, 237, 0.1)';
            });
            
            perPageSelect.addEventListener('blur', () => {
                perPageSelect.style.borderColor = '#e5e7eb';
                perPageSelect.style.background = 'white';
                perPageSelect.style.boxShadow = '0 1px 2px rgba(0, 0, 0, 0.05)';
            });
        }
        
        // Style the clear filters button
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (clearBtn) {
            Object.assign(clearBtn.style, {
                background: '#7c3aed',
                border: '2px solid #7c3aed',
                borderRadius: '8px',
                padding: '8px 16px',
                fontSize: '0.9rem',
                fontWeight: '600',
                color: 'white',
                cursor: 'pointer',
                transition: 'all 0.2s ease',
                fontFamily: 'inherit',
                outline: 'none',
                boxShadow: '0 1px 2px rgba(124, 58, 237, 0.2)'
            });
            
            // Add hover effects
            clearBtn.addEventListener('mouseenter', () => {
                if (!clearBtn.disabled) {
                    clearBtn.style.background = '#6d28d9';
                    clearBtn.style.borderColor = '#6d28d9';
                    clearBtn.style.transform = 'translateY(-1px)';
                    clearBtn.style.boxShadow = '0 2px 8px rgba(124, 58, 237, 0.3)';
                }
            });
            
            clearBtn.addEventListener('mouseleave', () => {
                if (!clearBtn.disabled) {
                    clearBtn.style.background = '#7c3aed';
                    clearBtn.style.borderColor = '#7c3aed';
                    clearBtn.style.transform = 'none';
                    clearBtn.style.boxShadow = '0 1px 2px rgba(124, 58, 237, 0.2)';
                }
            });
            
            // Style disabled state
            this.updateClearButtonStyle(clearBtn);
        }
    }
    
    updateClearButtonStyle(clearBtn) {
        if (clearBtn.disabled) {
            Object.assign(clearBtn.style, {
                background: '#f3f4f6',
                borderColor: '#e5e7eb',
                color: '#9ca3af',
                cursor: 'not-allowed',
                transform: 'none',
                boxShadow: 'none'
            });
        } else {
            Object.assign(clearBtn.style, {
                background: '#7c3aed',
                borderColor: '#7c3aed',
                color: 'white',
                cursor: 'pointer',
                boxShadow: '0 1px 2px rgba(124, 58, 237, 0.2)'
            });
        }
    }
    
    initializeWithAllRows() {
        // Initialize pagination with all table rows
        const allRows = Array.from(document.querySelectorAll('#ordersTableBody tr'));
        this.setFilteredRows(allRows);
    }
    
    setupButtons() {
        // Priority buttons
        document.querySelectorAll('[data-filter-type="priority"]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.toggleFilter('priority', btn.dataset.filterValue, btn);
            });
        });
        
        // Status buttons  
        document.querySelectorAll('[data-filter-type="status"]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.toggleFilter('status', btn.dataset.filterValue, btn);
            });
        });
        
        // Date buttons
        document.querySelectorAll('[data-filter-type="duedate"]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.toggleFilter('duedate', btn.dataset.filterValue, btn);
            });
        });
        
        // Prefix buttons
        document.querySelectorAll('[data-filter-type="prefix"]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.toggleFilter('prefix', btn.dataset.filterValue, btn);
            });
        });
    }
	
	toggleFilters() {
    const filtersContainer = document.getElementById('filtersContainer');
    const toggleIcon = document.getElementById('filtersToggleIcon');
    const toggleBtn = document.getElementById('filtersToggleBtn');
    
    if (!filtersContainer) return;
    
    const isCollapsed = filtersContainer.classList.contains('collapsed');
    
    if (isCollapsed) {
        filtersContainer.classList.remove('collapsed');
        if (toggleIcon) toggleIcon.textContent = '&#9661;';
        if (toggleBtn) toggleBtn.classList.add('active');
    } else {
        filtersContainer.classList.add('collapsed');
        if (toggleIcon) toggleIcon.textContent = '&#9655;';
        if (toggleBtn) toggleBtn.classList.remove('active');
    }
}
    
    setupEventsToggle() {
        const toggleContainer = document.getElementById('eventsToggle');
        if (!toggleContainer) {
            return;
        }
        
        
        const buttons = toggleContainer.querySelectorAll('.segment-btn');
        buttons.forEach(btn => {
            btn.addEventListener('click', () => {
                const mode = btn.dataset.mode;
                
                // Update button states
                buttons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                // Update mode
                this.eventsMode = mode;
                
                // Re-apply filters
                this.applyFilters();
                
                // Recalculate analytics
                if (window.analyticsManager && typeof window.analyticsManager.recalculateForEventsMode === 'function') {
                    window.analyticsManager.recalculateForEventsMode(mode);
                }
            });
        });
        
        // Problem badge click handler
        const problemBadge = document.getElementById('problemBadge');
        if (problemBadge) {
            problemBadge.style.cursor = 'pointer';
            problemBadge.addEventListener('click', () => {
                // Switch to all events and filter to delivered
                this.eventsMode = 'all';
                buttons.forEach(b => {
                    b.classList.remove('active');
                    if (b.dataset.mode === 'all') b.classList.add('active');
                });
                
                this.filters.status.clear();
                this.filters.status.add('delivered');
                document.querySelectorAll('.filter-btn[data-filter-type="status"]').forEach(b => {
                    b.classList.remove('active');
                    if (b.dataset.filterValue === 'delivered') b.classList.add('active');
                });
                
                this.applyFilters();
                
                if (window.analyticsManager && typeof window.analyticsManager.recalculateForEventsMode === 'function') {
                    window.analyticsManager.recalculateForEventsMode('all');
                }
            });
        }
    }

    setupSearch() {
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            searchBox.addEventListener('input', (e) => {
                this.filters.search = e.target.value.toLowerCase();
                this.applyFilters();
            });
        }
    }
    
    setupClear() {
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                this.clearAll();
            });
        }
    }
    
    setupPagination() {
        const perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) {
            perPageSelect.addEventListener('change', (e) => {
                const value = e.target.value;
                this.pagination.perPage = value === 'all' ? 'all' : parseInt(value);
                this.pagination.currentPage = 1;
                this.updatePaginationDisplay();
            });
        }
    }
    
    toggleFilter(type, value, button) {
        // Toggle logic for multiple selections
        if (this.filters[type].has(value)) {
            // Remove filter
            this.filters[type].delete(value);
            button.classList.remove('active');
        } else {
            // Add filter (keep existing ones)
            this.filters[type].add(value);
            button.classList.add('active');
        }
        
        this.applyFilters();
    }
    
    clearAll() {
        this.filters = {
            priority: new Set(),
            status: new Set(),
            duedate: new Set(),
            prefix: new Set(),
            search: ''
        };
        
        // Reset events mode to active
        this.eventsMode = 'active';
        const eventsToggle = document.getElementById('eventsToggle');
        if (eventsToggle) {
            eventsToggle.querySelectorAll('.segment-btn').forEach(b => {
                b.classList.remove('active');
                if (b.dataset.mode === 'active') b.classList.add('active');
            });
        }
        
        // Reset pagination
        this.pagination.currentPage = 1;
        
        // Clear UI
        document.querySelectorAll('.filter-btn.active').forEach(btn => {
            btn.classList.remove('active');
        });
        
        const searchBox = document.getElementById('searchBox');
        if (searchBox) searchBox.value = '';
        
        const perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) perPageSelect.value = '25';
        this.pagination.perPage = 25;
        
        this.applyFilters();
        this.updateFilterCounts();
    }
    
    applyFilters() {
        const allRows = document.querySelectorAll('#ordersTableBody tr');
        const filteredRows = [];
        
        allRows.forEach((row) => {
            let show = true;
            
            // Events mode filter
            if (this.eventsMode === 'active') {
                const rowEventStatus = row.dataset.eventStatus;
                if (rowEventStatus !== 'active') {
                    show = false;
                }
            }
            
            // Priority filter
            if (this.filters.priority.size > 0) {
                const rowPriority = row.dataset.priority;
                if (!this.filters.priority.has(rowPriority)) {
                    show = false;
                }
            }
            
            // Status filter
            if (this.filters.status.size > 0) {
                const rowStatus = row.dataset.status;
                if (!this.filters.status.has(rowStatus)) {
                    show = false;
                }
            }
            
            // Date filter
            if (this.filters.duedate.size > 0) {
                const rowDuedate = row.dataset.duedate;
                if (!this.filters.duedate.has(rowDuedate)) {
                    show = false;
                }
            }
            
            // Prefix filter
            if (this.filters.prefix.size > 0) {
                const reference = row.dataset.reference || '';
                const rowPrefix = reference.split('-')[0].toUpperCase();
                if (!this.filters.prefix.has(rowPrefix)) {
                    show = false;
                }
            }
            
            // Search filter
            if (this.filters.search) {
                const searchText = [
                    row.dataset.reference || '',
                    row.dataset.customer || '',
                    row.dataset.email || ''
                ].join(' ').toLowerCase();
                
                if (!searchText.includes(this.filters.search)) {
                    show = false;
                }
            }
            
            if (show) {
                filteredRows.push(row);
            }
        });
        
        
        // Update pagination with filtered results
        this.setFilteredRows(filteredRows);
        this.updateFilterCounts();
        this.updateClearButton();
    }
    
    setFilteredRows(rows) {
        this.pagination.filteredRows = rows;
        this.pagination.totalItems = rows.length;
        
        if (this.pagination.perPage === 'all') {
            this.pagination.totalPages = 1;
        } else {
            this.pagination.totalPages = Math.ceil(this.pagination.totalItems / this.pagination.perPage);
        }
        
        // Reset to page 1 if current page is beyond available pages
        if (this.pagination.currentPage > this.pagination.totalPages && this.pagination.totalPages > 0) {
            this.pagination.currentPage = 1;
        }
        
        this.updatePaginationDisplay();
    }
    
    updatePaginationDisplay() {
        this.updateSummary();
        this.createPaginationNav();
        this.showCurrentPageRows();
    }
    
    updateSummary() {
        // Update inline showing count
        const showingCount = document.getElementById('showingCount');
        const totalCount = document.getElementById('totalCount');
        
        if (showingCount) {
            if (this.pagination.perPage === 'all') {
                showingCount.textContent = this.pagination.totalItems;
            } else if (this.pagination.totalItems === 0) {
                showingCount.textContent = '0';
            } else {
                const startItem = ((this.pagination.currentPage - 1) * this.pagination.perPage) + 1;
                const endItem = Math.min(this.pagination.currentPage * this.pagination.perPage, this.pagination.totalItems);
                showingCount.textContent = `${startItem}-${endItem}`;
            }
        }
        
        if (totalCount) {
            totalCount.textContent = this.pagination.totalItems;
        }
        
        // Also update old-style summary if it exists
        const summary = document.getElementById('resultsSummary');
        if (summary && !summary.classList.contains('results-summary-inline')) {
            let summaryText;
            
            if (this.pagination.perPage === 'all') {
                summaryText = `Showing <strong>all ${this.pagination.totalItems} orders</strong>`;
            } else if (this.pagination.totalItems === 0) {
                summaryText = `Showing <strong>0 orders</strong>`;
            } else {
                const startItem = ((this.pagination.currentPage - 1) * this.pagination.perPage) + 1;
                const endItem = Math.min(this.pagination.currentPage * this.pagination.perPage, this.pagination.totalItems);
                summaryText = `Showing <strong>${startItem}-${endItem} of ${this.pagination.totalItems} orders</strong>`;
            }
            
            const activeFiltersCount = this.filters.priority.size + 
                                     this.filters.status.size + 
                                     this.filters.duedate.size + 
                                     this.filters.prefix.size +
                                     (this.filters.search ? 1 : 0);
            
            if (activeFiltersCount > 0) {
                summaryText += ` &#9679; <strong>${activeFiltersCount} filter${activeFiltersCount > 1 ? 's' : ''} applied</strong>`;
            } else {
                summaryText += ' &#9679; No filters applied';
            }
            
            summary.innerHTML = summaryText;
        }
        
        // Update filter chips
        this.updateFilterChips();
    }
    
    updateFilterChips() {
        const container = document.getElementById('activeFilterChips');
        if (!container) return;
        
        container.innerHTML = '';
        
        // Count total active filters
        const totalFilters = this.filters.status.size + 
                            this.filters.priority.size + 
                            this.filters.prefix.size + 
                            (this.filters.search ? 1 : 0);
        
        // Update count badge
        const countBadge = document.getElementById('filterCountBadge');
        if (countBadge) {
            if (totalFilters > 0) {
                countBadge.textContent = totalFilters;
                countBadge.style.display = 'inline-flex';
            } else {
                countBadge.style.display = 'none';
            }
        }
        
        // Status filters
        this.filters.status.forEach(status => {
            const label = this.getStatusLabel(status);
            container.appendChild(this.createFilterChip('status', status, label));
        });
        
        // Priority filters
        this.filters.priority.forEach(priority => {
            const label = this.getPriorityLabel(priority);
            container.appendChild(this.createFilterChip('priority', priority, label));
        });
        
        // Prefix/Event filters
        this.filters.prefix.forEach(prefix => {
            container.appendChild(this.createFilterChip('prefix', prefix, prefix));
        });
        
        // Search filter
        if (this.filters.search) {
            container.appendChild(this.createFilterChip('search', 'search', `"${this.filters.search}"`));
        }
    }
    
    createFilterChip(type, value, label) {
        const chip = document.createElement('div');
        chip.className = `filter-chip chip-${type}`;
        chip.innerHTML = `
            <span class="filter-chip-label">${label}</span>
            <button class="filter-chip-remove" title="Remove filter">x</button>
        `;
        
        chip.querySelector('.filter-chip-remove').addEventListener('click', (e) => {
            e.stopPropagation();
            this.removeFilter(type, value);
        });
        
        return chip;
    }
    
    removeFilter(type, value) {
        if (type === 'search') {
            this.filters.search = '';
            const searchBox = document.getElementById('searchBox');
            if (searchBox) searchBox.value = '';
        } else {
            this.filters[type].delete(value);
            // Also update the corresponding button state
            const btn = document.querySelector(`[data-filter-type="${type}"][data-filter-value="${value}"]`);
            if (btn) btn.classList.remove('active');
        }
        
        this.applyFilters();
        this.updateClearButton();
    }
    
    getStatusLabel(status) {
        const labels = {
            'unpaid': 'Unpaid',
            'paid': 'Paid',
            'preflight': 'Preflight',
            'file_issue': 'File Issue',
            'printing': 'Printing',
            'ready': 'Ready to Ship',
            'shipped': 'Shipped',
            'delivered': 'Delivered',
            'pickedup': 'Picked Up',
            'unclaimed': 'Unclaimed',
            'missing': 'Missing',
            'cancelled': 'Cancelled',
            'refunded': 'Refunded'
        };
        return labels[status] || status;
    }
    
    getPriorityLabel(priority) {
        const labels = {
            'early': 'Early',
            'standard': 'Standard',
            'rush': 'Rush',
            'urgent': 'Urgent',
            'critical': 'Critical',
            'lastminute': 'Last Minute'
        };
        return labels[priority] || priority;
    }
    
    createPaginationNav() {
        // Remove existing pagination
        const existingNav = document.getElementById('paginationNav');
        if (existingNav) {
            existingNav.remove();
        }
        
        // Don't show pagination if showing all or only one page
        if (this.pagination.perPage === 'all' || this.pagination.totalPages <= 1) {
            return;
        }
        
        const tableContainer = document.querySelector('.orders-table-container');
        if (!tableContainer) return;
        
        const nav = document.createElement('div');
        nav.id = 'paginationNav';
        nav.className = 'pagination-nav';
        
        // Style the container directly
        Object.assign(nav.style, {
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            gap: '6px',
            margin: '20px 0',
  
            flexWrap: 'wrap'
        });
        
        // Previous button
        const prevBtn = this.createPaginationButton('â—€ Prev', this.pagination.currentPage - 1, this.pagination.currentPage <= 1);
        nav.appendChild(prevBtn);
        
        // Page numbers
        const startPage = Math.max(1, this.pagination.currentPage - 2);
        const endPage = Math.min(this.pagination.totalPages, this.pagination.currentPage + 2);
        
        if (startPage > 1) {
            nav.appendChild(this.createPaginationButton('1', 1));
            if (startPage > 2) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                Object.assign(dots.style, {
                    padding: '0 12px',
                    color: '#6b7280',
                    fontWeight: '600',
                    fontSize: '0.9rem',
                    display: 'flex',
                    alignItems: 'center',
                    height: '38px'
                });
                nav.appendChild(dots);
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            nav.appendChild(this.createPaginationButton(i.toString(), i, false, i === this.pagination.currentPage));
        }
        
        if (endPage < this.pagination.totalPages) {
            if (endPage < this.pagination.totalPages - 1) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                Object.assign(dots.style, {
                    padding: '0 12px',
                    color: '#6b7280',
                    fontWeight: '600',
                    fontSize: '0.9rem',
                    display: 'flex',
                    alignItems: 'center',
                    height: '38px'
                });
                nav.appendChild(dots);
            }
            nav.appendChild(this.createPaginationButton(this.pagination.totalPages.toString(), this.pagination.totalPages));
        }
        
        // Next button
        const nextBtn = this.createPaginationButton('Next â–¶', this.pagination.currentPage + 1, this.pagination.currentPage >= this.pagination.totalPages);
        nav.appendChild(nextBtn);
        
        tableContainer.parentNode.insertBefore(nav, tableContainer.nextSibling);
    }
    
    createPaginationButton(text, page, disabled = false, active = false) {
        const btn = document.createElement('button');
        btn.textContent = text;
        btn.className = `pagination-btn ${active ? 'active' : ''}`;
        btn.disabled = disabled;
        
        // Apply styles directly in JavaScript to bypass CSS conflicts
        const baseStyles = {
            border: '2px solid #e5e7eb',
            borderRadius: '8px',
            padding: '8px 12px',
            fontSize: '0.85rem',
            fontWeight: '600',
            cursor: disabled ? 'not-allowed' : 'pointer',
            minWidth: '44px',
            height: '38px',
            display: 'inline-flex',
            alignItems: 'center',
            justifyContent: 'center',
            fontFamily: 'inherit',
            transition: 'all 0.2s ease',
            boxShadow: '0 1px 2px rgba(0, 0, 0, 0.05)',
            outline: 'none'
        };
        
        // Apply base styles
        Object.assign(btn.style, baseStyles);
        
        // Apply state-specific styles
        if (disabled) {
            btn.style.background = '#f9fafb';
            btn.style.color = '#9ca3af';
            btn.style.borderColor = '#e5e7eb';
        } else if (active) {
            btn.style.background = '#7c3aed';
            btn.style.borderColor = '#7c3aed';
            btn.style.color = 'white';
            btn.style.transform = 'translateY(-1px)';
            btn.style.boxShadow = '0 2px 8px rgba(124, 58, 237, 0.25)';
        } else {
            btn.style.background = 'white';
            btn.style.color = '#374151';
            btn.style.borderColor = '#e5e7eb';
        }
        
        // Enhanced styling for navigation arrows
        if (text.includes('Prev') || text.includes('Next')) {
            btn.style.fontWeight = '700';
            btn.style.padding = '8px 16px';
            btn.style.minWidth = '80px';
        }
        
        if (!disabled) {
            btn.addEventListener('click', () => {
                this.pagination.currentPage = page;
                this.updatePaginationDisplay();
            });
            
            // Add hover effects
            btn.addEventListener('mouseenter', () => {
                if (!active) {
                    btn.style.background = '#ede9fe';
                    btn.style.borderColor = '#7c3aed';
                    btn.style.color = '#6d28d9';
                    btn.style.transform = 'translateY(-1px)';
                    btn.style.boxShadow = '0 2px 4px rgba(124, 58, 237, 0.1)';
                } else {
                    btn.style.background = '#6d28d9';
                    btn.style.borderColor = '#6d28d9';
                    btn.style.transform = 'translateY(-2px)';
                    btn.style.boxShadow = '0 4px 12px rgba(124, 58, 237, 0.3)';
                }
            });
            
            btn.addEventListener('mouseleave', () => {
                if (!active) {
                    btn.style.background = 'white';
                    btn.style.borderColor = '#e5e7eb';
                    btn.style.color = '#374151';
                    btn.style.transform = 'none';
                    btn.style.boxShadow = '0 1px 2px rgba(0, 0, 0, 0.05)';
                } else {
                    btn.style.background = '#7c3aed';
                    btn.style.borderColor = '#7c3aed';
                    btn.style.transform = 'translateY(-1px)';
                    btn.style.boxShadow = '0 2px 8px rgba(124, 58, 237, 0.25)';
                }
            });
        }
        
        return btn;
    }
    
    showCurrentPageRows() {
        // First hide all rows
        const allRows = document.querySelectorAll('#ordersTableBody tr');
        allRows.forEach(row => {
            row.style.display = 'none';
            row.classList.add('filtered-out');
        });
        
        // Show only current page of filtered rows
        if (this.pagination.perPage === 'all') {
            // Show all filtered rows
            this.pagination.filteredRows.forEach(row => {
                row.style.display = '';
                row.classList.remove('filtered-out');
            });
        } else {
            // Show only current page rows
            const startIndex = (this.pagination.currentPage - 1) * this.pagination.perPage;
            const endIndex = startIndex + this.pagination.perPage;
            
            this.pagination.filteredRows.forEach((row, index) => {
                if (index >= startIndex && index < endIndex) {
                    row.style.display = '';
                    row.classList.remove('filtered-out');
                }
            });
        }
        
        // Refresh bulk selection state after table update
        if (window.bulkSelection && typeof window.bulkSelection.refreshBulkSelection === 'function') {
            window.bulkSelection.refreshBulkSelection();
        }
    }
    
    updateFilterCounts() {
        // Update active filter count badges
        const priorityCount = this.filters.priority.size;
        const statusCount = this.filters.status.size;
        const dueDateCount = this.filters.duedate.size;
        const prefixCount = this.filters.prefix.size;
        
        this.updateFilterCountBadge('priorityFilterCount', priorityCount);
        this.updateFilterCountBadge('statusFilterCount', statusCount);
        this.updateFilterCountBadge('dueDateFilterCount', dueDateCount);
        this.updateFilterCountBadge('prefixFilterCount', prefixCount);
    }
    
    updateFilterCountBadge(elementId, count) {
        const badge = document.getElementById(elementId);
        if (badge) {
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline-block';
            } else {
                badge.style.display = 'none';
            }
        }
    }
    
    updateClearButton() {
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (clearBtn) {
            const hasFilters = this.filters.priority.size > 0 || 
                             this.filters.status.size > 0 || 
                             this.filters.duedate.size > 0 || 
                             this.filters.prefix.size > 0 ||
                             this.filters.search !== '';
            
            clearBtn.disabled = !hasFilters;
            this.updateClearButtonStyle(clearBtn);
        }
    }

    
    // ===== SORTING FUNCTIONALITY =====
    setupSorting() {
        // Regular sortable columns
        document.querySelectorAll('.sortable').forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                const column = header.dataset.sort;
                
                // Normal column sorting
                if (this.currentSort.column === column) {
                    this.currentSort.direction = this.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    this.currentSort.column = column;
                    this.currentSort.direction = 'asc';
                }
                
                // Update header classes
                document.querySelectorAll('.sortable').forEach(h => {
                    h.classList.remove('sorted-asc', 'sorted-desc');
                });
                header.classList.add(this.currentSort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
                
                // Clear date toggle active states
                document.querySelectorAll('.date-sort-btn').forEach(btn => {
                    btn.classList.remove('sorted-asc', 'sorted-desc');
                });
                
                // Sort and re-apply filters
                this.sortTable(this.currentSort.column, this.currentSort.direction);
            });
        });
        
        // Date sort toggle buttons
        document.querySelectorAll('.date-sort-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const column = btn.dataset.sort;
                
                // If clicking the already active button, toggle direction
                if (this.currentSort.column === column) {
                    this.currentSort.direction = this.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    // Switching to different date sort
                    this.currentSort.column = column;
                    this.currentSort.direction = 'desc';
                }
                
                // Update button active states
                document.querySelectorAll('.date-sort-btn').forEach(b => {
                    b.classList.remove('active', 'sorted-asc', 'sorted-desc');
                });
                btn.classList.add('active');
                btn.classList.add(this.currentSort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
                
                // Clear regular header sort indicators
                document.querySelectorAll('.sortable').forEach(h => {
                    h.classList.remove('sorted-asc', 'sorted-desc');
                });
                
                // Sort and re-apply filters
                this.sortTable(column, this.currentSort.direction);
            });
        });
    }
    
    initializeDefaultSort() {
        // Default sort by submitted date, newest first
        const submittedBtn = document.querySelector('.date-sort-btn[data-sort="submitted"]');
        if (submittedBtn) {
            submittedBtn.classList.add('sorted-desc');
            this.sortTable('submitted', 'desc');
        }
    }
    
    sortTable(column, direction) {
        const tbody = document.getElementById('ordersTableBody');
        if (!tbody) return;
        
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        rows.sort((a, b) => {
            let aVal, bVal;
            
            switch(column) {
                case 'reference':
                    aVal = a.dataset.reference || '';
                    bVal = b.dataset.reference || '';
                    break;
                case 'priority':
                    // Custom priority order
                    const priorityOrder = {
                        'lastminute': 1,
                        'critical': 2,
                        'urgent': 3,
                        'rush': 4,
                        'standard': 5,
                        'early': 6
                    };
                    aVal = priorityOrder[a.dataset.priority] || 99;
                    bVal = priorityOrder[b.dataset.priority] || 99;
                    break;
                case 'customer':
                    aVal = a.dataset.customer || '';
                    bVal = b.dataset.customer || '';
                    break;
                case 'deadline':
                    aVal = parseInt(a.dataset.deadline) || 0;
                    bVal = parseInt(b.dataset.deadline) || 0;
                    break;
                case 'submitted':
                    aVal = parseInt(a.dataset.submitted) || 0;
                    bVal = parseInt(b.dataset.submitted) || 0;
                    break;
                case 'size':
                    // Extract numeric value from size
                    const aSize = a.querySelector('td:nth-child(5)')?.textContent || '';
                    const bSize = b.querySelector('td:nth-child(5)')?.textContent || '';
                    aVal = parseFloat(aSize.replace(/[^\d.]/g, '')) || 0;
                    bVal = parseFloat(bSize.replace(/[^\d.]/g, '')) || 0;
                    break;
                case 'price':
                    aVal = parseFloat(a.dataset.value) || 0;
                    bVal = parseFloat(b.dataset.value) || 0;
                    break;
                case 'status':
                    // Custom status order
                    const statusOrder = {
                        'unpaid': 1,
                        'paid': 2,
                        'preflight': 3,
                        'file_issue': 4,
                        'printing': 5,
                        'ready': 6,
                        'shipped': 7,
                        'delivered': 8,
                        'pickedup': 9,
                        'unclaimed': 10,
                        'missing': 11,
                        'cancelled': 12,
                        'refunded': 13
                    };
                    aVal = statusOrder[a.dataset.status] || 99;
                    bVal = statusOrder[b.dataset.status] || 99;
                    break;
                default:
                    aVal = a.dataset[column] || '';
                    bVal = b.dataset[column] || '';
            }
            
            // Compare values
            let comparison = 0;
            if (typeof aVal === 'number' && typeof bVal === 'number') {
                comparison = aVal - bVal;
            } else {
                comparison = String(aVal).localeCompare(String(bVal));
            }
            
            return direction === 'asc' ? comparison : -comparison;
        });
        
        // Re-append rows in sorted order
        rows.forEach(row => tbody.appendChild(row));
        
        // Re-apply filters to update pagination
        this.applyFilters();
        
    }
}