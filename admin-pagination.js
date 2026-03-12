class PaginationManager {
    constructor() {
        this.currentPage = 1;
        this.perPage = 25;
        this.totalItems = 0;
        this.totalPages = 1;
        this.filteredRows = [];
        
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.updatePagination();
    }
    
    bindEvents() {
        const perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) {
            perPageSelect.addEventListener('change', (e) => {
                this.perPage = e.target.value === 'all' ? 'all' : parseInt(e.target.value);
                this.currentPage = 1;
                this.updatePagination();
            });
        }
    }
    
    setFilteredRows(rows) {
        this.filteredRows = rows;
        this.totalItems = rows.length;
        
        if (this.perPage === 'all') {
            this.totalPages = 1;
        } else {
            this.totalPages = Math.ceil(this.totalItems / this.perPage);
        }
        
        // Reset to page 1 if current page is beyond available pages
        if (this.currentPage > this.totalPages) {
            this.currentPage = 1;
        }
        
        this.updatePagination();
        this.updateDisplay();
    }
    
    updatePagination() {
        this.updateResultsSummary();
        this.createPaginationNav();
    }
    
    updateResultsSummary() {
        const summary = document.getElementById('resultsSummary');
        if (!summary) return;
        
        let summaryText;
        
        if (this.perPage === 'all') {
            summaryText = `Showing <strong>all ${this.totalItems} orders</strong>`;
        } else {
            const startItem = ((this.currentPage - 1) * this.perPage) + 1;
            const endItem = Math.min(this.currentPage * this.perPage, this.totalItems);
            summaryText = `Showing <strong>${startItem}-${endItem} of ${this.totalItems} orders</strong>`;
        }
        
        // Add filter info
        const activeFilters = window.filterManager ? window.filterManager.getActiveFilterCount() : 0;
        if (activeFilters > 0) {
            summaryText += ` • <strong>${activeFilters} filter${activeFilters > 1 ? 's' : ''} applied</strong>`;
        } else {
            summaryText += ' • No filters applied';
        }
        
        summary.innerHTML = summaryText;
    }
    
    createPaginationNav() {
        // Remove existing pagination
        const existingNav = document.getElementById('paginationNav');
        if (existingNav) {
            existingNav.remove();
        }
        
        // Don't show pagination if showing all or only one page
        if (this.perPage === 'all' || this.totalPages <= 1) {
            return;
        }
        
        const tableContainer = document.querySelector('.orders-table-container');
        if (!tableContainer) return;
        
        const nav = document.createElement('div');
        nav.id = 'paginationNav';
        nav.className = 'pagination-nav';
        
        // Previous button
        const prevBtn = this.createPaginationButton('❮ Prev', this.currentPage - 1, this.currentPage <= 1);
        nav.appendChild(prevBtn);
        
        // Page numbers
        const startPage = Math.max(1, this.currentPage - 2);
        const endPage = Math.min(this.totalPages, this.currentPage + 2);
        
        if (startPage > 1) {
            nav.appendChild(this.createPaginationButton('1', 1));
            if (startPage > 2) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.className = 'pagination-dots';
                nav.appendChild(dots);
            }
        }
        
        for (let i = startPage; i <= endPage; i++) {
            nav.appendChild(this.createPaginationButton(i.toString(), i, false, i === this.currentPage));
        }
        
        if (endPage < this.totalPages) {
            if (endPage < this.totalPages - 1) {
                const dots = document.createElement('span');
                dots.textContent = '...';
                dots.className = 'pagination-dots';
                nav.appendChild(dots);
            }
            nav.appendChild(this.createPaginationButton(this.totalPages.toString(), this.totalPages));
        }
        
        // Next button
        const nextBtn = this.createPaginationButton('Next ❯', this.currentPage + 1, this.currentPage >= this.totalPages);
        nav.appendChild(nextBtn);
        
        tableContainer.parentNode.insertBefore(nav, tableContainer.nextSibling);
    }
    
    createPaginationButton(text, page, disabled = false, active = false) {
        const btn = document.createElement('button');
        btn.textContent = text;
        btn.className = `pagination-btn ${active ? 'active' : ''}`;
        btn.disabled = disabled;
        
        if (!disabled) {
            btn.addEventListener('click', () => {
                this.currentPage = page;
                this.updatePagination();
                this.updateDisplay();
            });
        }
        
        return btn;
    }
    
    updateDisplay() {
        if (this.perPage === 'all') {
            // Show all filtered rows
            this.filteredRows.forEach(row => {
                row.style.display = '';
            });
        } else {
            // Show only current page rows
            const startIndex = (this.currentPage - 1) * this.perPage;
            const endIndex = startIndex + this.perPage;
            
            this.filteredRows.forEach((row, index) => {
                if (index >= startIndex && index < endIndex) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    }
    
    reset() {
        this.currentPage = 1;
        this.updatePagination();
    }
}

// Initialize pagination manager
document.addEventListener('DOMContentLoaded', () => {
    window.paginationManager = new PaginationManager();
});