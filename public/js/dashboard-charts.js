/**
 * Admin dashboard — ApexCharts attendance trend.
 * Expects window.DASHBOARD_CHART_DATA from dashboard.php.
 */
(function () {
    'use strict';

    var chartInstance = null;

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function renderChart() {
        if (typeof ApexCharts === 'undefined' || !window.DASHBOARD_CHART_DATA) return;
        var el = document.getElementById('dashboard-attendance-chart');
        if (!el) return;

        if (chartInstance) {
            try { chartInstance.destroy(); } catch (e) {}
            chartInstance = null;
        }

        var data = window.DASHBOARD_CHART_DATA;
        var series = data.series || [];
        var categories = data.categories || [];

        chartInstance = new ApexCharts(el, {
            chart: {
                id: 'dashboard-attendance',
                type: 'area',
                height: 320,
                fontFamily: 'Inter, system-ui, sans-serif',
                foreColor: isDark() ? '#94a3b8' : '#6b7280',
                toolbar: { show: false },
                animations: { enabled: true, easing: 'easeinout', speed: 400 }
            },
            series: series,
            colors: ['#6366F1', '#22C55E'],
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 2 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 90, 100] }
            },
            xaxis: {
                categories: categories,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { fontSize: '11px' } }
            },
            yaxis: {
                labels: { style: { fontSize: '11px' } }
            },
            grid: {
                borderColor: isDark() ? '#334155' : '#f3f4f6',
                strokeDashArray: 4
            },
            legend: {
                position: 'top',
                horizontalAlign: 'right',
                fontSize: '12px',
                markers: { radius: 12 }
            },
            tooltip: { theme: isDark() ? 'dark' : 'light' }
        });
        chartInstance.render();
    }

    document.addEventListener('DOMContentLoaded', renderChart);
    window.addEventListener('headcount-theme-change', function () {
        setTimeout(renderChart, 50);
    });
})();
