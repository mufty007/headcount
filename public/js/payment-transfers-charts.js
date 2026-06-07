/**
 * Payment Transfers admin — ApexCharts. Expects window.PAYMENT_TRANSFERS_CHART_DATA from PHP.
 */
(function () {
    'use strict';

    function isDark() {
        return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    }

    function hexToRgb(hex) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
        if (!m) return { r: 59, g: 130, b: 246 };
        return { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) };
    }

    function chartTheme(primaryHex) {
        var rgb = hexToRgb(primaryHex);
        var primary = 'rgb(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ')';
        return {
            primary: primary,
            palette: [primary, '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#64748B'],
            fontFamily: 'Inter, system-ui, sans-serif'
        };
    }

    function destroyAll() {
        (window.__headcountPtChartInstances || []).forEach(function (ch) {
            try {
                if (ch && typeof ch.destroy === 'function') {
                    ch.destroy();
                }
            } catch (e) {}
        });
        window.__headcountPtChartInstances = [];
        ['ptStatusDonut', 'ptTopEventsBar', 'ptPaidTrend'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.innerHTML = '';
        });
    }

    function mount() {
        if (typeof ApexCharts === 'undefined') {
            console.error('ApexCharts failed to load.');
            return;
        }
        var cfg = window.PAYMENT_TRANSFERS_CHART_DATA;
        if (!cfg) return;

        destroyAll();
        window.__headcountPtChartInstances = window.__headcountPtChartInstances || [];

        var theme = chartTheme(cfg.primaryColor || '#3B82F6');
        var fontFamily = theme.fontFamily;
        var fore = isDark() ? '#cbd5e1' : '#374151';
        var grid = isDark() ? '#334155' : '#e5e7eb';

        var optsBase = {
            fontFamily: fontFamily,
            foreColor: fore,
            animations: { enabled: false },
            redrawOnParentResize: true,
            toolbar: { show: true, tools: { download: true } }
        };

        try {
            var elDonut = document.getElementById('ptStatusDonut');
            if (elDonut && cfg.status && cfg.status.labels && cfg.status.series) {
                var sumStatus = cfg.status.series.reduce(function (a, b) {
                    return a + (Number(b) || 0);
                }, 0);
                if (sumStatus > 0) {
                var donut = new ApexCharts(elDonut, {
                    chart: Object.assign({}, optsBase, {
                        id: 'ptStatusDonutChart',
                        type: 'donut',
                        height: 320
                    }),
                    labels: cfg.status.labels,
                    series: cfg.status.series,
                    colors: theme.palette,
                    legend: { position: 'bottom', fontFamily: fontFamily },
                    plotOptions: {
                        pie: {
                            donut: {
                                labels: {
                                    show: true,
                                    total: {
                                        show: true,
                                        label: 'Payments',
                                        fontFamily: fontFamily
                                    }
                                }
                            }
                        }
                    },
                    dataLabels: { enabled: true, dropShadow: { enabled: false } },
                    theme: { mode: isDark() ? 'dark' : 'light' }
                });
                donut.render();
                window.__headcountPtChartInstances.push(donut);
                }
            }

            var elBar = document.getElementById('ptTopEventsBar');
            if (elBar && cfg.topEvents && cfg.topEvents.categories && cfg.topEvents.categories.length > 0 && cfg.topEvents.amounts && cfg.topEvents.amounts.length > 0) {
                var bar = new ApexCharts(elBar, {
                    chart: Object.assign({}, optsBase, {
                        id: 'ptTopEventsBarChart',
                        type: 'bar',
                        height: 320
                    }),
                    plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '70%' } },
                    series: [{ name: 'Collected', data: cfg.topEvents.amounts }],
                    xaxis: {
                        categories: cfg.topEvents.categories,
                        labels: { style: { fontFamily: fontFamily } }
                    },
                    yaxis: { labels: { style: { fontFamily: fontFamily } } },
                    grid: { borderColor: grid },
                    colors: [theme.primary],
                    dataLabels: {
                        enabled: true,
                        formatter: function (val) {
                            return '$' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 0 });
                        }
                    },
                    theme: { mode: isDark() ? 'dark' : 'light' }
                });
                bar.render();
                window.__headcountPtChartInstances.push(bar);
            }

            var elLine = document.getElementById('ptPaidTrend');
            if (elLine && cfg.paidTrend && cfg.paidTrend.labels && cfg.paidTrend.labels.length > 0 && cfg.paidTrend.amounts && cfg.paidTrend.amounts.length > 0) {
                var line = new ApexCharts(elLine, {
                    chart: Object.assign({}, optsBase, {
                        id: 'ptPaidTrendChart',
                        type: 'area',
                        height: 280,
                        zoom: { enabled: false }
                    }),
                    stroke: { curve: 'smooth', width: 2 },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            shadeIntensity: 0.4,
                            opacityFrom: 0.45,
                            opacityTo: 0.05,
                            stops: [0, 90, 100]
                        }
                    },
                    series: [{ name: 'Paid revenue', data: cfg.paidTrend.amounts }],
                    xaxis: {
                        categories: cfg.paidTrend.labels,
                        labels: { rotate: -45, style: { fontFamily: fontFamily } }
                    },
                    yaxis: {
                        labels: {
                            style: { fontFamily: fontFamily },
                            formatter: function (val) {
                                return '$' + Number(val).toLocaleString();
                            }
                        }
                    },
                    grid: { borderColor: grid },
                    colors: [theme.primary],
                    dataLabels: { enabled: false },
                    theme: { mode: isDark() ? 'dark' : 'light' }
                });
                line.render();
                window.__headcountPtChartInstances.push(line);
            }
        } catch (e) {
            console.error('Payment transfers charts error:', e);
        }

        window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 50);
    }

    function scheduleMount() {
        window.setTimeout(mount, 0);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleMount);
    } else {
        scheduleMount();
    }

    window.addEventListener('headcount-theme-change', function () {
        scheduleMount();
    });
})();
