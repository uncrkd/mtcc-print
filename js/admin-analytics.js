/**
 * Admin Analytics Module - Interactive Charts and Graphs
 * Handles Chart.js integration and data visualization
 */

class AnalyticsManager {
    constructor() {
        this.charts = {};
        this.chartColors = {
            primary: '#7c3aed',
            primaryLight: '#a855f7',
            primaryDark: '#6d28d9',
            secondary: '#64748b',
            green: '#059669',
            blue: '#0284c7',
            yellow: '#eab308',
            orange: '#ea580c',
            red: '#dc2626',
            gray: '#6b7280'
        };
        this.currentTimePeriod = 'weekly';
        this.eventPrefixMode = 'orders'; // 'orders' or 'revenue'
    }

    // Initialize all charts
init(analyticsData) {
    this.data = analyticsData;
    this.currentEventsMode = 'active';
    
    // Check if Chart.js is loaded
    if (typeof Chart === 'undefined') {
        console.error('Chart.js is not loaded. Analytics will not function.');
        return;
    }
    
    // Initialize charts in sequence with error handling
    setTimeout(() => {
        try {
            this.initTimelineChart();
        } catch (error) {
            console.error('Failed to initialize timeline chart:', error);
        }
        
        try {
            this.initTopSizesPieChart();
        } catch (error) {
            console.error('Failed to initialize top sizes chart:', error);
        }
        
        try {
            this.initEventPrefixPieChart();
        } catch (error) {
            console.error('Failed to initialize event prefix chart:', error);
        }
        
        try {
            this.initTurnaroundChart();
        } catch (error) {
            console.error('Failed to initialize turnaround chart:', error);
        }
        
        try {
            this.initPercentageBars();
            this.setupEventListeners();
            
            // Apply initial active events filter
            setTimeout(() => {
                this.recalculateForEventsMode('active');
            }, 200);
        } catch (error) {
            console.error('Failed to initialize percentage bars or event listeners:', error);
        }
    }, 100);
}

    // Process data for different time periods
    processTimelineData(period = 'yearly') {
        const orders = this.data.orders || [];
        const now = new Date();
        let groupedData = {};
        let labels = [];

        switch(period) {
            case 'weekly':
				
                // Last 7 days
                for(let i = 6; i >= 0; i--) {
                    const date = new Date(now);
                    date.setDate(date.getDate() - i);
                    const key = date.toISOString().split('T')[0];
                    const label = date.toLocaleDateString('en-US', { weekday: 'short' });
                    groupedData[key] = { orders: 0, revenue: 0, label };
                    labels.push(label);
                }
                
                orders.forEach(order => {
                    const orderDate = new Date(order.submittedAt).toISOString().split('T')[0];
                    if(groupedData[orderDate]) {
                        groupedData[orderDate].orders++;
                        groupedData[orderDate].revenue += parseFloat(order.pricing?.total || 0);
                    }
                });
                break;

            case 'monthly':
                // Current year months
                const currentYear = now.getFullYear();
                for(let month = 0; month < 12; month++) {
                    const date = new Date(currentYear, month, 1);
                    const key = `${currentYear}-${String(month + 1).padStart(2, '0')}`;
                    const label = date.toLocaleDateString('en-US', { month: 'short' });
                    groupedData[key] = { orders: 0, revenue: 0, label };
                    labels.push(label);
                }

                orders.forEach(order => {
                    const orderDate = new Date(order.submittedAt);
                    if(orderDate.getFullYear() === currentYear) {
                        const key = `${currentYear}-${String(orderDate.getMonth() + 1).padStart(2, '0')}`;
                        if(groupedData[key]) {
                            groupedData[key].orders++;
                            groupedData[key].revenue += parseFloat(order.pricing?.total || 0);
                        }
                    }
                });
                break;

            case 'yearly':
            default:
                // Group by year
                const years = {};
                orders.forEach(order => {
                    const year = new Date(order.submittedAt).getFullYear();
                    if(!years[year]) {
                        years[year] = { orders: 0, revenue: 0 };
                    }
                    years[year].orders++;
                    years[year].revenue += parseFloat(order.pricing?.total || 0);
                });

                // Sort years and create labels
                const sortedYears = Object.keys(years).sort();
                sortedYears.forEach(year => {
                    groupedData[year] = { ...years[year], label: year };
                    labels.push(year);
                });
                break;
        }

        const ordersData = labels.map((label, index) => {
            const data = Object.values(groupedData)[index];
            return data ? data.orders : 0;
        });

        const revenueData = labels.map((label, index) => {
            const data = Object.values(groupedData)[index];
            return data ? data.revenue : 0;
        });

        return { labels, ordersData, revenueData };
    }

    // Initialize combined orders and revenue timeline chart
    initTimelineChart() {
        const ctx = document.getElementById('timelineChart');
        if (!ctx) return;

        const data = this.processTimelineData(this.currentTimePeriod);

        this.charts.timeline = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Orders',
                    data: data.ordersData,
                    borderColor: this.chartColors.primary,
                    backgroundColor: this.chartColors.primary + '20',
                    yAxisID: 'y',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'Revenue ($)',
                    data: data.revenueData,
                    borderColor: this.chartColors.green,
                    backgroundColor: this.chartColors.green + '20',
                    yAxisID: 'y1',
                    tension: 0.4,
                    fill: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 1500,
                    easing: 'easeInOutQuart'
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    x: {
                        display: true,
                        title: {
                            display: true,
                            text: this.getTimelineTitle()
                        }
                    },
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Orders'
                        },
                        ticks: {
                            stepSize: 1
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Revenue ($)'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            afterLabel: function(context) {
                                if (context.datasetIndex === 1) {
                                    return '$' + context.parsed.y.toLocaleString();
                                }
                                return '';
                            }
                        }
                    }
                }
            }
        });
    }

// Initialize top sizes pie chart
initTopSizesPieChart() {
    const ctx = document.getElementById('topSizesChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (this.charts.topSizes) {
        this.charts.topSizes.destroy();
    }

    const sizes = this.data.analytics.size_breakdown || {};
    const labels = Object.keys(sizes);
    const data = Object.values(sizes);
    const total = data.reduce((sum, val) => sum + val, 0);

    if (total === 0) {
        return;
    }

    const colors = [
        this.chartColors.primary,
        this.chartColors.primaryLight,
        this.chartColors.blue,
        this.chartColors.green,
        this.chartColors.orange
    ];

    this.charts.topSizes = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels.map(size => size + '"'),
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                animateRotate: true,
                duration: 2000
            },
            plugins: {
                legend: {
                    display: false // Hide default legend
                }
            }
        }
    });

    // Create custom legend
    this.createCustomLegend('topSizesLegend', labels.map(size => size + '"'), data, colors, total);
}

    // Initialize event prefix pie chart
    initEventPrefixPieChart() {
        const ctx = document.getElementById('eventPrefixChart');
        if (!ctx) return;

        this.updateEventPrefixChart();
    }

   // Update event prefix chart based on current mode
updateEventPrefixChart() {
    const allOrders = this.data.orders || [];
    const activeEventPrefixes = this.data.activeEventPrefixes || [];
    
    // Filter orders based on current events mode
    const orders = this.currentEventsMode === 'active'
        ? allOrders.filter(order => {
            const prefix = order.referenceCode.split('-')[0].toUpperCase();
            return activeEventPrefixes.includes(prefix);
        })
        : allOrders;
    
    const prefixData = {};

    orders.forEach(order => {
        if (order.status === 'cancelled' || order.status === 'refunded') return;
        const prefix = order.referenceCode.split('-')[0].toUpperCase();
        if (!prefixData[prefix]) {
            prefixData[prefix] = { orders: 0, revenue: 0 };
        }
        prefixData[prefix].orders++;
        prefixData[prefix].revenue += parseFloat(order.pricing?.total || 0);
    });

    // Sort and get top 5 + Other
    const sortedPrefixes = Object.entries(prefixData)
        .sort((a, b) => {
            const valA = this.eventPrefixMode === 'orders' ? a[1].orders : a[1].revenue;
            const valB = this.eventPrefixMode === 'orders' ? b[1].orders : b[1].revenue;
            return valB - valA;
        });
    
    let labels = [];
    let data = [];
    let displayPrefixData = {};
    let otherTotal = { orders: 0, revenue: 0 };
    
    sortedPrefixes.forEach((item, i) => {
        const [prefix, vals] = item;
        if (i < 5) {
            labels.push(prefix);
            data.push(this.eventPrefixMode === 'orders' ? vals.orders : vals.revenue);
            displayPrefixData[prefix] = vals;
        } else {
            otherTotal.orders += vals.orders;
            otherTotal.revenue += vals.revenue;
        }
    });
    
    if (sortedPrefixes.length > 5) {
        labels.push('Other');
        data.push(this.eventPrefixMode === 'orders' ? otherTotal.orders : otherTotal.revenue);
        displayPrefixData['Other'] = otherTotal;
    }

    if (this.charts.eventPrefix) {
        this.charts.eventPrefix.destroy();
    }

    const ctx = document.getElementById('eventPrefixChart');
    if (!ctx) return;

    const legendColors = [
        this.chartColors.primary,
        this.chartColors.green,
        this.chartColors.blue,
        this.chartColors.orange,
        this.chartColors.red,
        this.chartColors.yellow
    ];

    this.charts.eventPrefix = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: legendColors.slice(0, labels.length),
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                animateRotate: true,
                duration: 2000
            },
            plugins: {
                legend: {
                    display: false // Hide default legend
                }
            }
        }
    });

    // Create custom legend for event prefix
    this.createCustomEventLegend('eventPrefixLegend', labels, data, legendColors.slice(0, labels.length), displayPrefixData);
}
	
// Initialize turnaround breakdown list (replaces chart)
initTurnaroundChart() {
    // This now initializes a list instead of a chart
    this.turnaroundMode = 'orders'; // 'orders' or 'revenue'
    this.updateTurnaroundList();
}

// New function to update the turnaround list
updateTurnaroundList() {
    const container = document.getElementById('turnaroundList');
    if (!container) return;

    const allOrders = this.data.orders || [];
    const activeEventPrefixes = this.data.activeEventPrefixes || [];
    
    // Filter orders based on current events mode
    const orders = this.currentEventsMode === 'active'
        ? allOrders.filter(order => {
            const prefix = order.referenceCode.split('-')[0].toUpperCase();
            return activeEventPrefixes.includes(prefix);
        })
        : allOrders;
    
    const breakdown = {};
    
    // Calculate both orders and revenue for each priority
    orders.forEach(order => {
        if (order.status === 'cancelled' || order.status === 'refunded') return; // Exclude cancelled and refunded orders
        
        const tier = order.pricing?.tier || 'Standard';
        let priority = 'Standard';
        
        // More flexible priority detection
        const tierLower = tier.toLowerCase();
        if (tierLower.includes('last minute') || tierLower.includes('same day')) {
            priority = 'Last Minute';
        } else if (tierLower.includes('critical') || tierLower.includes('next day')) {
            priority = 'Critical';
        } else if (tierLower.includes('urgent') || tierLower.includes('2 day')) {
            priority = 'Urgent';
        } else if (tierLower.includes('rush') || tierLower.includes('3 day')) {
            priority = 'Rush';
        } else if (tierLower.includes('standard') || tierLower.includes('5 day')) {
            priority = 'Standard';
		} else if (tierLower.includes('early') || tierLower.includes('10+ day')) {
            priority = 'Early';
        }
        
        if (!breakdown[priority]) {
            breakdown[priority] = { orders: 0, revenue: 0 };
        }
        
        breakdown[priority].orders++;
        breakdown[priority].revenue += parseFloat(order.pricing?.total || 0);
    });

    // DEBUG: Now we can safely log breakdown

    const orderedTiers = ['Early', 'Standard', 'Rush', 'Urgent', 'Critical', 'Last Minute'];
    
    // Calculate totals for percentages
    const totals = {
        orders: Object.values(breakdown).reduce((sum, item) => sum + item.orders, 0),
        revenue: Object.values(breakdown).reduce((sum, item) => sum + item.revenue, 0)
    };


    container.innerHTML = '';

    orderedTiers.forEach(tier => {
    const data = breakdown[tier] || { orders: 0, revenue: 0 }; // Provide default values
    // REMOVED: if (!data || (data.orders === 0 && data.revenue === 0)) return;

    const count = data.orders;
    const revenue = data.revenue;

    const isRevenue = this.turnaroundMode === 'revenue';
    const value = isRevenue ? revenue : count;
    const total = isRevenue ? totals.revenue : totals.orders;
    const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
    const progressWidth = total > 0 ? (value / total) * 100 : 0;

    const priorityClass = this.getTierClass(tier);
    const displayValue = isRevenue ? `$${revenue.toLocaleString()}` : count.toString();

    const item = document.createElement('div');
    item.className = 'turnaround-item';
    
    item.innerHTML = `
        <div class="turnaround-label">${tier}</div>
        <div class="turnaround-progress-container">
            <div class="turnaround-progress-bar ${priorityClass}" style="width: ${progressWidth}%"></div>
        </div>
        <div class="turnaround-value">${displayValue} (${percentage}%)</div>
    `;

    container.appendChild(item);
});

}

// Helper function to get CSS class for priority
getTierClass(tier) {
    const tierMap = {
        'Early': 'priority-early',
        'Standard': 'priority-standard',
        'Rush': 'priority-rush',
        'Urgent': 'priority-urgent',
        'Critical': 'priority-critical',
        'Last Minute': 'priority-lastminute'
    };
    return tierMap[tier] || 'priority-standard';
}

    // Initialize percentage bars
    initPercentageBars() {
        this.updateMaterialTypeBar();
        this.updateDeliveryMethodBar();
    }

    // Update material type percentage bar
    updateMaterialTypeBar() {
        const orders = this.data.orders || [];
        const poster = orders.filter(order => order.material === 'poster').length;
        const fabric = orders.filter(order => order.material === 'fabric').length;
        const total = poster + fabric;

        if (total === 0) return;

        const posterPercent = Math.round((poster / total) * 100);
        const fabricPercent = 100 - posterPercent;

        const container = document.getElementById('materialTypeBar');
        if (container) {
            container.innerHTML = `
                <div class="percentage-bar-container">
                    <div class="percentage-label left">Poster</div>
                    <div class="percentage-bar">
                        <div class="percentage-fill primary" style="width: ${posterPercent}%"></div>
                        <div class="percentage-fill secondary" style="width: ${fabricPercent}%"></div>
                    </div>
                    <div class="percentage-label right">Fabric</div>
                </div>
                <div class="percentage-stats">
                    <span>${posterPercent}%</span>
                    <span>${fabricPercent}%</span>
                </div>
            `;
        }
    }

    // Update delivery method percentage bar
    updateDeliveryMethodBar() {
        const orders = this.data.orders || [];
        const mtcc = orders.filter(order => order.deliveryOption === 'mtcc').length;
        const office = orders.filter(order => order.deliveryOption === 'office').length;
        const total = mtcc + office;

        if (total === 0) return;

        const mtccPercent = Math.round((mtcc / total) * 100);
        const officePercent = 100 - mtccPercent;

        const container = document.getElementById('deliveryMethodBar');
        if (container) {
            container.innerHTML = `
                <div class="percentage-bar-container">
                    <div class="percentage-label left">MTCC</div>
                    <div class="percentage-bar">
                        <div class="percentage-fill primary" style="width: ${mtccPercent}%"></div>
                        <div class="percentage-fill secondary" style="width: ${officePercent}%"></div>
                    </div>
                    <div class="percentage-label right">Address</div>
                </div>
                <div class="percentage-stats">
                    <span>${mtccPercent}%</span>
                    <span>${officePercent}%</span>
                </div>
            `;
        }
    }

    // Setup event listeners for controls
    setupEventListeners() {
    // Timeline period buttons
    document.querySelectorAll('.timeline-period-btn').forEach(btn => {
        btn.addEventListener('click', (e) => {
            const period = e.target.dataset.period;
            this.updateTimelinePeriod(period);
            
            // Update active button
            document.querySelectorAll('.timeline-period-btn').forEach(b => b.classList.remove('active'));
            e.target.classList.add('active');
        });
    });

    // Event prefix toggle
    const eventToggleBtn = document.getElementById('eventPrefixToggle');
    if (eventToggleBtn) {
        eventToggleBtn.addEventListener('click', () => {
            this.eventPrefixMode = this.eventPrefixMode === 'orders' ? 'revenue' : 'orders';
            eventToggleBtn.textContent = this.eventPrefixMode === 'orders' ? 'Show Revenue' : 'Show Orders';
            this.updateEventPrefixChart();
        });
    }

    // Turnaround toggle (NEW)
    const turnaroundToggleBtn = document.getElementById('turnaroundToggle');
    if (turnaroundToggleBtn) {
        turnaroundToggleBtn.addEventListener('click', () => {
            this.turnaroundMode = this.turnaroundMode === 'orders' ? 'revenue' : 'orders';
            turnaroundToggleBtn.textContent = this.turnaroundMode === 'orders' ? 'Show Revenue' : 'Show Orders';
            this.updateTurnaroundList();
        });
    }
}

    // Update timeline chart for different periods
    updateTimelinePeriod(period) {
        if (this.charts.timeline) {
            this.currentTimePeriod = period;
            
            // Get filtered orders based on current events mode
            const allOrders = this.data.orders || [];
            const activeEventPrefixes = this.data.activeEventPrefixes || [];
            const filteredOrders = this.currentEventsMode === 'active'
                ? allOrders.filter(order => {
                    const prefix = order.referenceCode.split('-')[0].toUpperCase();
                    return activeEventPrefixes.includes(prefix);
                })
                : allOrders;
            
            // Update timeline with filtered orders
            this._updateTimelineChart(filteredOrders);
            this.charts.timeline.options.scales.x.title.text = this.getTimelineTitle();
        }
    }

    // Get timeline title based on period
    getTimelineTitle() {
        switch(this.currentTimePeriod) {
            case 'weekly': return 'Last 7 Days';
            case 'monthly': return 'Monthly (Current Year)';
            case 'yearly': return 'Yearly';
            default: return 'Timeline';
        }
    }

    // Get color for priority tier
    getTierColor(tier) {
        const colors = {
            'Early': 'rgba(5, 150, 105, 0.8)',
            'Standard': 'rgba(2, 132, 199, 0.8)',
            'Rush': 'rgba(234, 179, 8, 0.8)',
            'Urgent': 'rgba(234, 88, 12, 0.8)',
            'Critical': 'rgba(220, 38, 38, 0.8)',
            'Last Minute': 'rgba(124, 58, 237, 0.8)'
        };
        return colors[tier] || 'rgba(107, 114, 128, 0.8)';
    }

	// Create custom legend with data values
createCustomLegend(containerId, labels, data, colors, total) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';
    
    labels.forEach((label, index) => {
        if (index < data.length) {
            const percentage = total > 0 ? ((data[index] / total) * 100).toFixed(1) : 0;
            
            const legendItem = document.createElement('div');
            legendItem.className = 'legend-item';
            
            legendItem.innerHTML = `
                <div class="legend-color" style="background-color: ${colors[index] || '#gray'}"></div>
                <div class="legend-text">${label}</div>
                <div class="legend-value">${data[index]} (${percentage}%)</div>
            `;
            
            container.appendChild(legendItem);
        }
    });
}

// Create custom legend for event prefix (handles both orders and revenue)
createCustomEventLegend(containerId, labels, data, colors, prefixData) {
    const container = document.getElementById(containerId);
    if (!container) return;

    container.innerHTML = '';
    
    labels.forEach((label, index) => {
        if (index < data.length && prefixData[label]) {
            const orders = prefixData[label].orders;
            const revenue = prefixData[label].revenue;
            
            const displayValue = this.eventPrefixMode === 'orders' 
                ? `${orders} orders`
                : `$${revenue.toLocaleString()}`;
            
            const legendItem = document.createElement('div');
            legendItem.className = 'legend-item';
            
            legendItem.innerHTML = `
                <div class="legend-color" style="background-color: ${colors[index] || '#gray'}"></div>
                <div class="legend-text">${label}</div>
                <div class="legend-value">${displayValue}</div>
            `;
            
            container.appendChild(legendItem);
        }
    });
}
	
	
    // Update all charts with new data
    updateCharts(newData) {
        this.data = newData;
        
        // Update each chart
        if (this.charts.timeline) {
            const timelineData = this.processTimelineData(this.currentTimePeriod);
            this.charts.timeline.data.labels = timelineData.labels;
            this.charts.timeline.data.datasets[0].data = timelineData.ordersData;
            this.charts.timeline.data.datasets[1].data = timelineData.revenueData;
            this.charts.timeline.update();
        }

        // Update other charts
        Object.keys(this.charts).forEach(chartKey => {
            if (chartKey !== 'timeline') {
                this.charts[chartKey].update();
            }
        });

        // Update percentage bars
        this.updateMaterialTypeBar();
        this.updateDeliveryMethodBar();
    }

    // Cleanup charts
    destroy() {
        Object.values(this.charts).forEach(chart => {
            if (chart && typeof chart.destroy === 'function') {
                chart.destroy();
            }
        });
        this.charts = {};
    }
	
	
	

    // Recalculate all analytics for the given events mode
    recalculateForEventsMode(mode) {
        
        this.currentEventsMode = mode;
        
        const allOrders = this.data.orders || [];
        const activeEventPrefixes = this.data.activeEventPrefixes || [];
        
        
        // Filter orders based on mode
        const orders = mode === 'active'
            ? allOrders.filter(order => {
                const prefix = order.referenceCode.split('-')[0].toUpperCase();
                return activeEventPrefixes.includes(prefix);
            })
            : allOrders;
        
        
        // Calculate all metrics
        // Define paid statuses (post-payment)
        const paidStatuses = ['paid', 'file_issue', 'printing', 'shipped', 'delivered', 'pickedup', 'unclaimed', 'missing'];
        const pendingStatuses = ['unpaid'];
        
        let todayRevenue = 0, todayOrders = 0;
        let totalRevenue = 0, totalBaseRevenue = 0; // Now only paid+ orders
        let pendingRevenue = 0, pendingBaseRevenue = 0, pendingOrders = 0;
        let cancelledRevenue = 0, cancelledOrders = 0;
        let refundedRevenue = 0, refundedOrders = 0;
        let fileConversionRevenue = 0;
        let posterCount = 0, fabricCount = 0;
        let mtccCount = 0, officeCount = 0;
        let paidOrderCount = 0; // Count of paid+ orders
        const statusCounts = {};
        const turnaroundCounts = {};
        const turnaroundRevenue = {};
        const sizeCounts = {};
        const eventCounts = {};
        
        // Use local date (not UTC) for today comparison
        const now = new Date();
        const today = now.getFullYear() + '-' + 
                      String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                      String(now.getDate()).padStart(2, '0');
        
        
        orders.forEach(order => {
            const total = parseFloat(order.pricing?.total || 0);
            const basePrice = parseFloat(order.pricing?.basePrice || 0);
            const fileConv = parseFloat(order.pricing?.conversionFee || 0);
            const status = order.status || '';
            const orderDate = (order.submittedAt || '').split(/[T ]/)[0];
            
            // Track status counts for all orders
            statusCounts[status] = (statusCounts[status] || 0) + 1;
            
            // Pending orders (pre-payment)
            if (pendingStatuses.includes(status)) {
                pendingRevenue += total;
                pendingBaseRevenue += basePrice;
                pendingOrders++;
            }
            
            // Cancelled orders
            if (status === 'cancelled') {
                cancelledRevenue += total;
                cancelledOrders++;
            }
            
            // Refunded orders
            if (status === 'refunded') {
                refundedRevenue += total;
                refundedOrders++;
            }
            
            // PAID+ orders only - for revenue metrics
            if (paidStatuses.includes(status)) {
                totalRevenue += total;
                totalBaseRevenue += basePrice;
                fileConversionRevenue += fileConv;
                paidOrderCount++;
                
                if (orderDate === today) {
                    todayRevenue += total;
                    todayOrders++;
                }
            }
            
            // ALL VALID ORDERS (not cancelled/refunded) - for distribution metrics
            if (status !== 'cancelled' && status !== 'refunded') {
                const material = (order.material || 'poster').toLowerCase();
                if (material.includes('fabric')) fabricCount++;
                else posterCount++;
                
                const delivery = (order.deliveryOption || 'mtcc').toLowerCase();
                if (delivery.includes('office') || delivery.includes('address')) officeCount++;
                else mtccCount++;
                
                const size = (order.dimensions?.width && order.dimensions?.height) ? order.dimensions.width + 'x' + order.dimensions.height : 'Unknown';
                sizeCounts[size] = (sizeCounts[size] || 0) + 1;
                
                const tier = (order.pricing?.tier || 'Standard').toLowerCase();
                let priority = 'Standard';
                if (tier.includes('last minute') || tier.includes('same day')) priority = 'Last Minute';
                else if (tier.includes('critical') || tier.includes('next day')) priority = 'Critical';
                else if (tier.includes('urgent') || tier.includes('2 day')) priority = 'Urgent';
                else if (tier.includes('rush') || tier.includes('3 day')) priority = 'Rush';
                else if (tier.includes('early')) priority = 'Early';
                turnaroundCounts[priority] = (turnaroundCounts[priority] || 0) + 1;
                if (!turnaroundRevenue[priority]) turnaroundRevenue[priority] = 0;
                turnaroundRevenue[priority] += total;
                
                const prefix = order.referenceCode.split('-')[0].toUpperCase();
                if (!eventCounts[prefix]) eventCounts[prefix] = { orders: 0, revenue: 0 };
                eventCounts[prefix].orders++;
                eventCounts[prefix].revenue += total;
            }
        });
        
        // Calculate new performance metrics
        let turnaroundTimes = [];
        let onTimeCount = 0;
        let deliveredCount = 0;
        let fileIssueCount = 0;

        orders.forEach(order => {
            const status = order.status || '';
            if (['delivered', 'pickedup'].includes(status) && order.submittedAt) {
                const submitted = new Date(order.submittedAt).getTime();
                const modified = (order.modified || 0) * 1000;
                if (submitted && modified > submitted) {
                    turnaroundTimes.push(modified - submitted);
                }
                deliveredCount++;
                if (order.selectedDate) {
                    const dueEnd = new Date(order.selectedDate + 'T23:59:59').getTime();
                    if (modified <= dueEnd) onTimeCount++;
                }
            }
            if (status === 'file_issue') fileIssueCount++;
        });

        const avgTurnaroundMs = turnaroundTimes.length > 0 ? turnaroundTimes.reduce((a, b) => a + b, 0) / turnaroundTimes.length : 0;
        const avgTurnaroundHours = avgTurnaroundMs / 3600000;
        const avgTurnaroundDisplay = avgTurnaroundHours >= 48 ? (avgTurnaroundHours / 24).toFixed(1) + 'd' : avgTurnaroundHours.toFixed(1) + 'h';
        const onTimeRate = deliveredCount > 0 ? ((onTimeCount / deliveredCount) * 100).toFixed(1) : '0';
        const fileIssueRate = orders.length > 0 ? ((fileIssueCount / orders.length) * 100).toFixed(1) : '0';

        // Calculate percentages based on total orders in current view
        const totalOrdersInView = orders.length;
        const cancelledPercentage = totalOrdersInView > 0 ? ((cancelledOrders / totalOrdersInView) * 100).toFixed(3) : '0.000';
        const refundedPercentage = totalOrdersInView > 0 ? ((refundedOrders / totalOrdersInView) * 100).toFixed(3) : '0.000';
        
        const avgOrderValue = paidOrderCount > 0 ? totalRevenue / paidOrderCount : 0;
        const mtccVenueFee = totalBaseRevenue * 0.10;
        
        const totalMaterial = posterCount + fabricCount;
        const posterPct = totalMaterial > 0 ? Math.round((posterCount / totalMaterial) * 100) : 0;
        const fabricPct = 100 - posterPct;
        
        const totalDelivery = mtccCount + officeCount;
        const mtccPct = totalDelivery > 0 ? Math.round((mtccCount / totalDelivery) * 100) : 0;
        const officePct = 100 - mtccPct;
        
        const fmt = n => n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        
        // Calculate additional metrics
        const avgBasePrice = paidOrderCount > 0 ? totalBaseRevenue / paidOrderCount : 0;
        
        // Calculate file conversion percentage
        const fileConversionPercentage = totalRevenue > 0 ? ((fileConversionRevenue / totalRevenue) * 100).toFixed(1) : '0.0';
        
        // Update revenue cards
        const updates = {
            'todayRevenue': '$' + fmt(todayRevenue),
            'todayOrdersCount': todayOrders,
            'avgOrderValue': '$' + fmt(avgOrderValue),
            'avgBasePrice': '$' + fmt(avgBasePrice),
            'fileConversionRevenue': '$' + fmt(fileConversionRevenue),
            'fileConversionPercentage': fileConversionPercentage + '%',
            'totalRevenue': '$' + fmt(totalRevenue),
            'totalBaseRevenue': '$' + fmt(totalBaseRevenue),
            'mtccVenueFee': '$' + fmt(mtccVenueFee),
            'pendingRevenue': '$' + fmt(pendingRevenue),
            'pendingBaseRevenue': '$' + fmt(pendingBaseRevenue),
            'pendingOrdersCount': pendingOrders,
            'cancelledRevenue': '-$' + fmt(cancelledRevenue),
            'cancelledOrdersCount': cancelledOrders,
            'cancelledPercentage': cancelledPercentage + '%',
            'refundedRevenue': '-$' + fmt(refundedRevenue),
            'refundedOrdersCount': refundedOrders,
            'refundedPercentage': refundedPercentage + '%',
            'totalOrderCount': paidOrderCount,  // Now reflects paid+ orders only
            'avgTurnaround': avgTurnaroundDisplay,
            'onTimeRate': onTimeRate + '%',
            'fileIssueRate': fileIssueRate + '%',
            'fileIssueCount': fileIssueCount + ' order(s) with issues'
        };

        for (const [id, value] of Object.entries(updates)) {
            const el = document.getElementById(id);
            if (el) {
                el.textContent = value;
            } else {
            }
        }
        
        // Update Material Type card
        this._updateMaterialCard(posterPct, fabricPct);
        
        // Update Delivery Method card
        this._updateDeliveryCard(mtccPct, officePct);
        
        // Update Order Statuses card
        this._updateStatusCard(statusCounts);
        
        // Update Turnaround list
        this._updateTurnaroundCard(turnaroundCounts, turnaroundRevenue);
        
        // Update Top 5 Sizes chart
        this._updateSizesChart(sizeCounts);
        
        // Update Event Distribution chart
        this._updateEventChart(eventCounts);
        
        // Update Timeline chart
        this._updateTimelineChart(orders);
        
    }
    
    _updateMaterialCard(posterPct, fabricPct) {
        const container = document.getElementById('materialTypeContent');
        if (!container) return;
        
        container.innerHTML = '<div class="horizontal-bar-layout">' +
            '<div class="bar-side left"><div class="side-label">Poster</div><div class="side-percentage" style="color: #7c3aed;">' + posterPct + '%</div></div>' +
            '<div class="center-bar-wrapper"><div class="center-bar"><div class="center-bar-fill primary" style="width: ' + posterPct + '%"></div><div class="center-bar-fill secondary" style="width: ' + fabricPct + '%"></div></div><div class="bar-divider" style="left: ' + posterPct + '%"></div></div>' +
            '<div class="bar-side right"><div class="side-label">Fabric</div><div class="side-percentage" style="color: var(--blue);">' + fabricPct + '%</div></div>' +
            '</div>';
    }
    
    _updateDeliveryCard(mtccPct, officePct) {
        const container = document.getElementById('deliveryMethodContent');
        if (!container) return;
        
        container.innerHTML = '<div class="horizontal-bar-layout">' +
            '<div class="bar-side left"><div class="side-label">MTCC</div><div class="side-percentage" style="color: #7c3aed;">' + mtccPct + '%</div></div>' +
            '<div class="center-bar-wrapper"><div class="center-bar"><div class="center-bar-fill primary" style="width: ' + mtccPct + '%"></div><div class="center-bar-fill secondary" style="width: ' + officePct + '%"></div></div><div class="bar-divider" style="left: ' + mtccPct + '%"></div></div>' +
            '<div class="bar-side right"><div class="side-label">Address</div><div class="side-percentage" style="color: var(--blue);">' + officePct + '%</div></div>' +
            '</div>';
    }
    
    _updateStatusCard(statusCounts) {
        const container = document.getElementById('orderStatusesContent');
        if (!container) return;
        
        // Status order: Unpaid -> Paid -> File Issue -> Printing -> Shipped -> Delivered -> Picked Up
        const statusOrder = [
            { key: 'unpaid', label: 'Unpaid' },
            { key: 'paid', label: 'Paid' },
            { key: 'file_issue', label: 'File Issue' },
            { key: 'printing', label: 'Printing' },
            { key: 'shipped', label: 'Shipped' },
            { key: 'delivered', label: 'Delivered' },
            { key: 'pickedup', label: 'Picked Up' }
        ];
        let html = '';
        statusOrder.forEach(status => {
            const count = statusCounts[status.key] || 0;
            html += '<div class="status-item status-' + status.key + '"><span class="status-count">' + count + '</span><span class="status-label">' + status.label + '</span></div>';
        });
        container.innerHTML = html;
    }
    
    _updateTurnaroundCard(turnaroundCounts, turnaroundRevenue) {
        const container = document.getElementById('turnaroundList');
        if (!container) return;
        
        // Order from Early (green) to Last Minute (red) - matching original
        const priorities = ['Early', 'Standard', 'Rush', 'Urgent', 'Critical', 'Last Minute'];
        const priorityClasses = {
            'Early': 'priority-early',
            'Standard': 'priority-standard',
            'Rush': 'priority-rush',
            'Urgent': 'priority-urgent',
            'Critical': 'priority-critical',
            'Last Minute': 'priority-lastminute'
        };
        
        // Calculate total for percentage
        let totalOrders = 0;
        priorities.forEach(p => { totalOrders += (turnaroundCounts[p] || 0); });
        
        const mode = this.turnaroundMode || 'orders';
        let totalValue = 0;
        if (mode === 'orders') {
            totalValue = totalOrders;
        } else {
            priorities.forEach(p => { totalValue += (turnaroundRevenue[p] || 0); });
        }
        
        container.innerHTML = '';
        
        priorities.forEach(priority => {
            const orders = turnaroundCounts[priority] || 0;
            const revenue = turnaroundRevenue[priority] || 0;
            const displayValue = mode === 'orders' ? orders : '$' + revenue.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            const currentValue = mode === 'orders' ? orders : revenue;
            const percentage = totalValue > 0 ? Math.round((currentValue / totalValue) * 100) : 0;
            const progressWidth = Math.min(percentage, 100);
            const priorityClass = priorityClasses[priority] || 'standard';
            
            const item = document.createElement('div');
            item.className = 'turnaround-item';
            item.innerHTML = '<div class="turnaround-label">' + priority + '</div>' +
                '<div class="turnaround-progress-container">' +
                '<div class="turnaround-progress-bar ' + priorityClass + '" style="width: ' + progressWidth + '%"></div>' +
                '</div>' +
                '<div class="turnaround-value">' + displayValue + ' (' + percentage + '%)</div>';
            container.appendChild(item);
        });
    }
    
    _updateSizesChart(sizeCounts) {
        if (!this.charts.topSizes) return;
        
        const sorted = Object.entries(sizeCounts).sort((a, b) => b[1] - a[1]).slice(0, 5);
        const labels = sorted.map(s => s[0]);
        const data = sorted.map(s => s[1]);
        const total = data.reduce((sum, val) => sum + val, 0);
        
        const colors = [this.chartColors.primary, this.chartColors.green, this.chartColors.blue, this.chartColors.orange, this.chartColors.red];
        
        // Update chart data AND colors
        this.charts.topSizes.data.labels = labels;
        this.charts.topSizes.data.datasets[0].data = data;
        this.charts.topSizes.data.datasets[0].backgroundColor = colors.slice(0, labels.length);
        this.charts.topSizes.update();
        
        // Update legend with matching colors and total for percentages
        this.createCustomLegend('topSizesLegend', labels, data, colors.slice(0, labels.length), total);
    }
    
    _updateEventChart(eventCounts) {
        if (!this.charts.eventPrefix) {
            return;
        }
        
        const sorted = Object.entries(eventCounts).sort((a, b) => b[1].orders - a[1].orders);
        let labels = [], data = [], displayData = {};
        let otherTotal = { orders: 0, revenue: 0 };
        
        sorted.forEach((item, i) => {
            const [prefix, vals] = item;
            if (i < 5) {
                labels.push(prefix);
                data.push(this.eventPrefixMode === 'orders' ? vals.orders : vals.revenue);
                displayData[prefix] = vals;
            } else {
                otherTotal.orders += vals.orders;
                otherTotal.revenue += vals.revenue;
            }
        });
        
        if (sorted.length > 5) {
            labels.push('Other');
            data.push(this.eventPrefixMode === 'orders' ? otherTotal.orders : otherTotal.revenue);
            displayData['Other'] = otherTotal;
        }
        
        if (labels.length === 0) {
            const legendEl = document.getElementById('eventPrefixLegend');
            if (legendEl) legendEl.innerHTML = '<div style="text-align:center;color:#64748b;padding:20px;">No events data</div>';
            this.charts.eventPrefix.data.labels = [];
            this.charts.eventPrefix.data.datasets[0].data = [];
            this.charts.eventPrefix.update();
            return;
        }
        
        const colors = [this.chartColors.primary, this.chartColors.green, this.chartColors.blue, this.chartColors.orange, this.chartColors.red, this.chartColors.yellow];
        
        this.charts.eventPrefix.data.labels = labels;
        this.charts.eventPrefix.data.datasets[0].data = data;
        this.charts.eventPrefix.data.datasets[0].backgroundColor = colors.slice(0, labels.length);
        this.charts.eventPrefix.update();
        
        this.createCustomEventLegend('eventPrefixLegend', labels, data, colors, displayData);
    }

    _updateTimelineChart(filteredOrders) {
        if (!this.charts.timeline) {
            return;
        }
        
        const period = this.currentTimePeriod || 'yearly';
        const now = new Date();
        let groupedData = {};
        let labels = [];
        
        switch(period) {
            case 'weekly':
                for(let i = 6; i >= 0; i--) {
                    const date = new Date(now);
                    date.setDate(date.getDate() - i);
                    const key = date.toISOString().split('T')[0];
                    const label = date.toLocaleDateString('en-US', { weekday: 'short' });
                    groupedData[key] = { orders: 0, revenue: 0, label };
                    labels.push(label);
                }
                filteredOrders.forEach(order => {
                    if (order.status === 'cancelled' || order.status === 'refunded') return;
                    const orderDate = new Date(order.submittedAt).toISOString().split('T')[0];
                    if(groupedData[orderDate]) {
                        groupedData[orderDate].orders++;
                        groupedData[orderDate].revenue += parseFloat(order.pricing?.total || 0);
                    }
                });
                break;
            case 'monthly':
                const currentYear = now.getFullYear();
                for(let month = 0; month < 12; month++) {
                    const date = new Date(currentYear, month, 1);
                    const key = currentYear + '-' + String(month + 1).padStart(2, '0');
                    const label = date.toLocaleDateString('en-US', { month: 'short' });
                    groupedData[key] = { orders: 0, revenue: 0, label };
                    labels.push(label);
                }
                filteredOrders.forEach(order => {
                    if (order.status === 'cancelled' || order.status === 'refunded') return;
                    const orderDate = new Date(order.submittedAt);
                    if(orderDate.getFullYear() === currentYear) {
                        const key = currentYear + '-' + String(orderDate.getMonth() + 1).padStart(2, '0');
                        if(groupedData[key]) {
                            groupedData[key].orders++;
                            groupedData[key].revenue += parseFloat(order.pricing?.total || 0);
                        }
                    }
                });
                break;
            case 'yearly':
            default:
                const years = {};
                filteredOrders.forEach(order => {
                    if (order.status === 'cancelled' || order.status === 'refunded') return;
                    const year = new Date(order.submittedAt).getFullYear();
                    if(!years[year]) years[year] = { orders: 0, revenue: 0 };
                    years[year].orders++;
                    years[year].revenue += parseFloat(order.pricing?.total || 0);
                });
                Object.keys(years).sort().forEach(year => {
                    groupedData[year] = { ...years[year], label: year };
                    labels.push(year);
                });
                break;
        }
        const ordersData = labels.map((l, i) => Object.values(groupedData)[i]?.orders || 0);
        const revenueData = labels.map((l, i) => Object.values(groupedData)[i]?.revenue || 0);
        this.charts.timeline.data.labels = labels;
        this.charts.timeline.data.datasets[0].data = ordersData;
        this.charts.timeline.data.datasets[1].data = revenueData;
        this.charts.timeline.update();
    }

}

// Global analytics manager instance
window.analyticsManager = null;

// Initialize analytics when DOM is ready
function initializeAnalytics(analyticsData) {
    if (window.analyticsManager) {
        window.analyticsManager.destroy();
    }
    
    window.analyticsManager = new AnalyticsManager();
    window.analyticsManager.init(analyticsData);
}

// Export for use in other modules
window.initializeAnalytics = initializeAnalytics;

