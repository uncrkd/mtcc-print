/**
 * Admin Filtering System Module
 * Enhanced filtering system with full PHP integration
 */

class OrderFilterManager {
    constructor() {
        this.activeFilters = {
            priority: new Set(),
            status: new Set(),
            duedate: new Set(),
            search: ''
        };
        this.currentSort = { column: 'submitted', direction: 'desc' };
        this.init();
    }
    
    init() {
        this.setupFilterButtons();
        this.setupSearch();
        this.setupClearFilters();
        this.setupSorting();
        this.updateDisplay();
        this.initializeDefaultSort();
    }
    
    setupFilterButtons() {
        document.querySelectorAll('.filter-btn[data-filter-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                const filterType = btn.dataset.filterType;
                const filterValue = btn.dataset.filterValue;
                
                if (btn.classList.contains('active')) {
                    this.activeFilters[filterType].delete(filterValue);
                    btn.classList.remove('active');
                } else {
                    this.activeFilters[filterType].add(filterValue);
                    btn.classList.add('active');
                }
                
                this.updateDisplay();
            });
        });
    }
    
    setupSearch() {
        const searchBox = document.getElementById('searchBox');
        if (searchBox) {
            searchBox.addEventListener('input', (e) => {
                this.activeFilters.search = e.target.value.toLowerCase();
                this.updateDisplay();
            });
        }
    }
    
    setupClearFilters() {
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                // Clear all filter sets
                Object.keys(this.activeFilters).forEach(key => {
                    if (key === 'search') {
                        this.activeFilters[key] = '';
                    } else {
                        this.activeFilters[key].clear();
                    }
                });
                
                // Clear search box
                const searchBox = document.getElementById('searchBox');
                if (searchBox) {
                    searchBox.value = '';
                }
                
                // Remove active classes
                document.querySelectorAll('.filter-btn.active').forEach(btn => {
                    btn.classList.remove('active');
                });
                
                this.updateDisplay();
            });
        }
    }
    
    setupSorting() {
        document.querySelectorAll('.sortable').forEach(header => {
            header.addEventListener('click', () => {
                const column = header.dataset.sort;
                
                if (this.currentSort.column === column) {
                    this.currentSort.direction = this.currentSort.direction === 'asc' ? 'desc' : 'asc';
                } else {
                    this.currentSort.column = column;
                    this.currentSort.direction = 'asc';
                }
                
                // Update header indicators
                document.querySelectorAll('.sortable').forEach(h => {
                    h.classList.remove('sorted-asc', 'sorted-desc');
                });
                
                header.classList.add(this.currentSort.direction === 'asc' ? 'sorted-asc' : 'sorted-desc');
                
                this.sortTable(column, this.currentSort.direction);
            });
        });
    }
    
    initializeDefaultSort() {
        const defaultHeader = document.querySelector('[data-sort="submitted"]');
        if (defaultHeader) {
            defaultHeader.classList.add('sorted-desc');
        }
    }
    
    updateDisplay() {
        this.filterRows();
        this.updateFilterCounts();
        this.updateResultsSummary();
        this.updateClearButton();
    }
    
    filterRows() {
    const rows = document.querySelectorAll('#ordersTableBody tr');
    let visibleCount = 0;
    
    rows.forEach(row => {
        let isVisible = true;
        
        // Check priority filters
        if (this.activeFilters.priority.size > 0) {
            const rowPriority = row.dataset.priority;
            if (!this.activeFilters.priority.has(rowPriority)) {
                isVisible = false;
            }
        }
        
        // Check status filters
        if (this.activeFilters.status.size > 0) {
            const rowStatus = row.dataset.status;
            if (!this.activeFilters.status.has(rowStatus)) {
                isVisible = false;
            }
        }
        
        // Check due date filters
        if (this.activeFilters.duedate.size > 0) {
            const rowDueDate = row.dataset.duedate;
            if (!this.activeFilters.duedate.has(rowDueDate)) {
                isVisible = false;
            }
        }
        
        // Check search filter
        if (this.activeFilters.search) {
            const searchText = this.activeFilters.search;
            const reference = row.dataset.reference || '';
            const customer = row.dataset.customer || '';
            const email = row.dataset.email || '';
            
            if (!reference.includes(searchText) && 
                !customer.includes(searchText) && 
                !email.includes(searchText)) {
                isVisible = false;
            }
        }
        
        // Apply visibility using multiple methods to ensure it works
        if (isVisible) {
            row.style.display = 'table-row';
            row.style.visibility = 'visible';
            row.classList.remove('hidden');
            visibleCount++;
        } else {
            row.style.display = 'none';
            row.style.visibility = 'hidden';
            row.classList.add('hidden');
        }
    });
    
    return visibleCount;
}
    
    updateFilterCounts() {
        // Update active filter count badges
        const priorityCount = this.activeFilters.priority.size;
        const statusCount = this.activeFilters.status.size;
        const dueDateCount = this.activeFilters.duedate.size;
        
        this.updateFilterCountBadge('priorityFilterCount', priorityCount);
        this.updateFilterCountBadge('statusFilterCount', statusCount);
        this.updateFilterCountBadge('dueDateFilterCount', dueDateCount);
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
    
    updateResultsSummary() {
        const visibleRows = document.querySelectorAll('#ordersTableBody tr:not(.hidden)');
        const totalRows = document.querySelectorAll('#ordersTableBody tr');
        const summary = document.getElementById('resultsSummary');
        
        if (!summary) return;
        
        const totalFilters = this.activeFilters.priority.size + 
                           this.activeFilters.status.size + 
                           this.activeFilters.duedate.size + 
                           (this.activeFilters.search ? 1 : 0);
        
        let summaryText = `Showing <strong>${visibleRows.length} of ${totalRows.length} orders</strong>`;
        
        if (totalFilters > 0) {
            summaryText += ` • <strong>${totalFilters} filter${totalFilters > 1 ? 's' : ''} applied</strong>`;
        } else {
            summaryText += ' • No filters applied';
        }
        
        summary.innerHTML = summaryText;
    }
    
    updateClearButton() {
        const clearBtn = document.getElementById('clearFiltersBtn');
        if (!clearBtn) return;
        
        const hasActiveFilters = this.activeFilters.priority.size > 0 || 
                               this.activeFilters.status.size > 0 || 
                               this.activeFilters.duedate.size > 0 || 
                               this.activeFilters.search !== '';
        
        clearBtn.disabled = !hasActiveFilters;
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
                case 'customer':
                    aVal = a.dataset.customer || '';
                    bVal = b.dataset.customer || '';
                    break;
                case 'submitted':
                    aVal = parseInt(a.dataset.submitted) || 0;
                    bVal = parseInt(b.dataset.submitted) || 0;
                    break;
                case 'deadline':
                    aVal = parseInt(a.dataset.deadline) || 0;
                    bVal = parseInt(b.dataset.deadline) || 0;
                    break;
                case 'price':
                    aVal = parseFloat(a.dataset.value) || 0;
                    bVal = parseFloat(b.dataset.value) || 0;
                    break;
                case 'status':
                    aVal = a.dataset.status || '';
                    bVal = b.dataset.status || '';
                    break;
                case 'priority':
                    const priorityOrder = {
                        'lastminute': 6,
                        'critical': 5,
                        'urgent': 4,
                        'rush': 3,
                        'standard': 2,
                        'early': 1
                    };
                    aVal = priorityOrder[a.dataset.priority] || 0;
                    bVal = priorityOrder[b.dataset.priority] || 0;
                    break;
                case 'size':
                    const aSize = a.querySelector('td:nth-child(6)')?.textContent || '';
                    const bSize = b.querySelector('td:nth-child(6)')?.textContent || '';
                    const aMatch = aSize.match(/(\d+).*?×.*?(\d+)/);
                    const bMatch = bSize.match(/(\d+).*?×.*?(\d+)/);
                    aVal = aMatch ? parseInt(aMatch[1]) * parseInt(aMatch[2]) : 0;
                    bVal = bMatch ? parseInt(bMatch[1]) * parseInt(bMatch[2]) : 0;
                    break;
                default:
                    return 0;
            }
            
            if (typeof aVal === 'string') {
                return direction === 'asc' 
                    ? aVal.localeCompare(bVal)
                    : bVal.localeCompare(aVal);
            } else {
                return direction === 'asc' 
                    ? aVal - bVal
                    : bVal - aVal;
            }
        });
        
        tbody.innerHTML = '';
        rows.forEach(row => tbody.appendChild(row));
    }
}

// Initialize filtering system when DOM is ready
function initializeFilteringSystem() {
    console.log('Initializing filtering system...');
    
    // Only initialize on pages with order tables
    if (document.querySelector('#ordersTableBody')) {
        const filterManager = new OrderFilterManager();
        console.log('Filtering system initialized');
        return filterManager;
    } else {
        console.log('No order table found, skipping filtering system');
        return null;
    }
}

console.log('Admin Filtering System Module loaded');