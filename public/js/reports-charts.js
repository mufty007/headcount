/**
 * Admin reports — ApexCharts 3.x. Expects window.REPORTS_CHART_DATA from PHP.
 */
(function () {
    'use strict';

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
            palette: [primary, '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899', '#06B6D4'],
            fontFamily: 'Inter, system-ui, sans-serif'
        };
    }

    /**
     * Unique chart.id per instance — required when multiple Apex charts share one HTML document.
     * Otherwise SVG defs (gradients, clips, filters) reuse the same id and fills resolve wrong → blank series.
     */
    function reportsChartDark() {
        return typeof document !== 'undefined' && document.documentElement.classList.contains('dark');
    }

    function apexThemeMode() {
        return reportsChartDark() ? 'dark' : 'light';
    }

    function chartBase(el, uniqueId, fontFamily, chartOpts) {
        chartOpts = chartOpts || {};
        var base = {
            id: uniqueId,
            fontFamily: fontFamily,
            foreColor: reportsChartDark() ? '#cbd5e1' : '#374151',
            animations: { enabled: false },
            redrawOnParentResize: true,
            toolbar: { show: true, tools: { download: true } }
        };
        for (var k in chartOpts) {
            if (Object.prototype.hasOwnProperty.call(chartOpts, k)) {
                base[k] = chartOpts[k];
            }
        }
        return base;
    }

    /** Apex render() returns a thenable in v3+; normalize so Promise.all works. */
    function renderChart(chart) {
        if (!chart || typeof chart.render !== 'function') {
            return Promise.resolve();
        }
        window.__headcountReportsChartInstances = window.__headcountReportsChartInstances || [];
        window.__headcountReportsChartInstances.push(chart);
        var r = chart.render();
        return r && typeof r.then === 'function' ? r : Promise.resolve();
    }

    function clearReportChartMounts() {
        (window.__headcountReportsChartInstances || []).forEach(function (ch) {
            try {
                if (ch && typeof ch.destroy === 'function') {
                    ch.destroy();
                }
            } catch (e) {}
        });
        window.__headcountReportsChartInstances = [];
        document.querySelectorAll('.reports-apex-chart').forEach(function (el) {
            el.innerHTML = '';
        });
        window.__headcountReportsChartsMounted = false;
        window.__headcountReportsChartsMounting = false;
    }

    function bumpChartsResize() {
        window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 50);
        window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 300);
        window.setTimeout(function () {
            window.dispatchEvent(new Event('resize'));
        }, 800);
    }

    function mount() {
        if (typeof ApexCharts === 'undefined') {
            console.error('ApexCharts failed to load. Charts will not render.');
            return;
        }
        var cfg = window.REPORTS_CHART_DATA;
        if (!cfg || !cfg.reportType) return;
        if (window.__headcountReportsChartsMounted) return;
        if (window.__headcountReportsChartsMounting) return;
        window.__headcountReportsChartsMounting = true;

        var theme = chartTheme(cfg.primaryColor || '#3B82F6');
        var rt = cfg.reportType;
        var pending = [];

        try {
            if (rt === 'overview') {
                pending = mountOverview(cfg, theme);
            } else if (rt === 'events') {
                pending = mountEvents(cfg, theme);
            } else if (rt === 'rsvp') {
                pending = mountRsvp(cfg, theme);
            } else if (rt === 'members') {
                pending = mountMembers(cfg, theme);
            } else if (rt === 'revenue') {
                pending = mountRevenue(cfg, theme);
            } else if (rt === 'facilities') {
                pending = mountFacilities(cfg, theme);
            }
        } catch (e) {
            console.error('Reports charts error:', e);
            window.__headcountReportsChartsMounted = false;
            window.__headcountReportsChartsMounting = false;
            return;
        }

        pending = (pending || []).filter(function (p) {
            return p && typeof p.then === 'function';
        });
        if (!pending.length) {
            window.__headcountReportsChartsMounted = true;
            window.__headcountReportsChartsMounting = false;
            bumpChartsResize();
            return;
        }
        Promise.all(pending)
            .then(function () {
                window.__headcountReportsChartsMounted = true;
                window.__headcountReportsChartsMounting = false;
                bumpChartsResize();
            })
            .catch(function (err) {
                console.error('Reports charts render failed:', err);
                window.__headcountReportsChartsMounted = false;
                window.__headcountReportsChartsMounting = false;
            });
    }

    function mountOverview(cfg, theme) {
        var trendData = cfg.trendData || [];
        var categoryData = cfg.categoryData || [];
        var rsvpVsAttData = cfg.rsvpVsAttData || [];
        var newAttendees = cfg.newAttendees || 0;
        var returningAttendees = cfg.returningAttendees || 0;
        var pending = [];

        var trendEl = document.querySelector('#attendanceTrendChart');
        if (trendEl) {
            pending.push(
                renderChart(
                    new ApexCharts(trendEl, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Attendance', data: trendData.length ? trendData.map(function (d) { return Number(d.count); }) : [0] }],
                        chart: chartBase(trendEl, 'hc-rpt-ov-trend', theme.fontFamily, { type: 'area', height: 320 }),
                        dataLabels: { enabled: false },
                        stroke: { curve: 'smooth', width: 2 },
                        fill: { type: 'solid', opacity: 0.2 },
                        colors: [theme.primary],
                        xaxis: {
                            categories: trendData.length ? trendData.map(function (d) { return d.date; }) : ['—'],
                            labels: { rotate: -45 }
                        },
                        yaxis: { min: 0, forceNiceScale: true },
                        tooltip: { y: { formatter: function (val) { return val + ' check-ins'; } } }
                    })
                )
            );
        }

        var catEl = document.querySelector('#categoryChart');
        if (catEl) {
            pending.push(
                renderChart(
                    new ApexCharts(catEl, {
                        theme: { mode: apexThemeMode() },
                        series: categoryData.length ? categoryData.map(function (d) { return Number(d.attendance_count); }) : [0],
                        chart: chartBase(catEl, 'hc-rpt-ov-category', theme.fontFamily, { type: 'donut', height: 320 }),
                        labels: categoryData.length ? categoryData.map(function (d) { return d.category || 'Uncategorized'; }) : ['No data'],
                        colors: theme.palette,
                        legend: { position: 'bottom' },
                        plotOptions: { pie: { donut: { size: '65%' } } },
                        tooltip: { y: { formatter: function (val) { return val + ' check-ins'; } } }
                    })
                )
            );
        }

        var rsvpEl = document.querySelector('#rsvpVsAttendanceChart');
        if (rsvpEl) {
            pending.push(
                renderChart(
                    new ApexCharts(rsvpEl, {
                        theme: { mode: apexThemeMode() },
                        series: [
                            { name: 'RSVP Yes', data: rsvpVsAttData.length ? rsvpVsAttData.map(function (d) { return Number(d.rsvp_count); }) : [0] },
                            { name: 'Checked In', data: rsvpVsAttData.length ? rsvpVsAttData.map(function (d) { return Number(d.attendance_count); }) : [0] }
                        ],
                        chart: chartBase(rsvpEl, 'hc-rpt-ov-rsvp-att', theme.fontFamily, { type: 'line', height: 320 }),
                        stroke: { curve: 'smooth', width: 2 },
                        colors: [theme.palette[4], theme.palette[1]],
                        xaxis: {
                            categories: rsvpVsAttData.length ? rsvpVsAttData.map(function (d) { return d.date; }) : ['—'],
                            labels: { rotate: -45 }
                        },
                        yaxis: { min: 0 },
                        legend: { position: 'top' }
                    })
                )
            );
        }

        var nvEl = document.querySelector('#newVsReturningChart');
        if (nvEl) {
            pending.push(
                renderChart(
                    new ApexCharts(nvEl, {
                        theme: { mode: apexThemeMode() },
                        series: [Number(newAttendees), Number(returningAttendees)],
                        chart: chartBase(nvEl, 'hc-rpt-ov-new-ret', theme.fontFamily, { type: 'pie', height: 320 }),
                        labels: ['New', 'Returning'],
                        colors: [theme.palette[1], theme.primary],
                        legend: { position: 'bottom' }
                    })
                )
            );
        }
        return pending;
    }

    function mountEvents(cfg, theme) {
        var pending = [];
        var el = document.querySelector('#eventsPerformanceBarChart');
        if (el && cfg.eventBarSeries) {
            pending.push(
                renderChart(
                    new ApexCharts(el, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Checked in', data: cfg.eventBarSeries.checkedIn }],
                        chart: chartBase(el, 'hc-rpt-ev-perf', theme.fontFamily, { type: 'bar', height: Math.max(320, (cfg.eventBarSeries.labels || []).length * 28) }),
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        colors: [theme.primary],
                        dataLabels: { enabled: false },
                        xaxis: { min: 0 },
                        yaxis: { categories: cfg.eventBarSeries.labels }
                    })
                )
            );
        }

        var el2 = document.querySelector('#eventsNoShowColumnChart');
        if (el2 && cfg.eventNoShowSeries) {
            pending.push(
                renderChart(
                    new ApexCharts(el2, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'No-show %', data: cfg.eventNoShowSeries.pcts }],
                        chart: chartBase(el2, 'hc-rpt-ev-noshow', theme.fontFamily, { type: 'bar', height: 320 }),
                        colors: ['#EF4444'],
                        plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
                        xaxis: { categories: cfg.eventNoShowSeries.labels, labels: { rotate: -35, maxHeight: 100 } },
                        yaxis: { min: 0, max: 100, title: { text: '%' } }
                    })
                )
            );
        }
        return pending;
    }

    function mountRsvp(cfg, theme) {
        var el = document.querySelector('#rsvpStackedChart');
        if (!el || !cfg.rsvpStacked) {
            return [];
        }
        return [
            renderChart(
                new ApexCharts(el, {
                    theme: { mode: apexThemeMode() },
                    series: [
                        { name: 'Checked in', data: cfg.rsvpStacked.checkedIn },
                        { name: 'No-shows', data: cfg.rsvpStacked.noShows }
                    ],
                    chart: chartBase(el, 'hc-rpt-rsvp-stack', theme.fontFamily, { type: 'bar', height: Math.max(340, (cfg.rsvpStacked.labels || []).length * 24), stacked: true }),
                    plotOptions: { bar: { horizontal: true, borderRadius: 2 } },
                    colors: [theme.palette[1], '#F87171'],
                    xaxis: { categories: cfg.rsvpStacked.labels },
                    yaxis: { min: 0 },
                    legend: { position: 'top' }
                })
            )
        ];
    }

    function mountMembers(cfg, theme) {
        var pending = [];
        var el = document.querySelector('#membersRateHistogram');
        if (el && cfg.memberHistogram) {
            pending.push(
                renderChart(
                    new ApexCharts(el, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Members', data: cfg.memberHistogram.counts }],
                        chart: chartBase(el, 'hc-rpt-mem-hist', theme.fontFamily, { type: 'bar', height: 300 }),
                        plotOptions: { bar: { borderRadius: 4, columnWidth: '70%' } },
                        colors: [theme.primary],
                        xaxis: { categories: cfg.memberHistogram.labels },
                        yaxis: { min: 0, title: { text: 'Count' } }
                    })
                )
            );
        }

        var el2 = document.querySelector('#membersTopBarChart');
        if (el2 && cfg.memberTopSeries) {
            pending.push(
                renderChart(
                    new ApexCharts(el2, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Events attended', data: cfg.memberTopSeries.values }],
                        chart: chartBase(el2, 'hc-rpt-mem-top', theme.fontFamily, { type: 'bar', height: 320 }),
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        colors: [theme.palette[4]],
                        dataLabels: { enabled: false },
                        xaxis: { min: 0 },
                        yaxis: { categories: cfg.memberTopSeries.labels }
                    })
                )
            );
        }
        return pending;
    }

    function mountRevenue(cfg, theme) {
        var pending = [];
        var el = document.querySelector('#revenueByEventChart');
        if (el && cfg.revenueBar) {
            pending.push(
                renderChart(
                    new ApexCharts(el, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Revenue ($)', data: cfg.revenueBar.amounts }],
                        chart: chartBase(el, 'hc-rpt-rev-events', theme.fontFamily, { type: 'bar', height: Math.max(320, (cfg.revenueBar.labels || []).length * 26) }),
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        colors: [theme.palette[1]],
                        dataLabels: { enabled: false },
                        xaxis: { categories: cfg.revenueBar.labels },
                        yaxis: { min: 0 }
                    })
                )
            );
        }

        var el2 = document.querySelector('#revenueMonthlyChart');
        if (el2 && cfg.revenueMonthly) {
            pending.push(
                renderChart(
                    new ApexCharts(el2, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Revenue ($)', data: cfg.revenueMonthly.amounts }],
                        chart: chartBase(el2, 'hc-rpt-rev-monthly', theme.fontFamily, { type: 'area', height: 280 }),
                        stroke: { curve: 'smooth', width: 2 },
                        fill: { type: 'solid', opacity: 0.2 },
                        colors: [theme.primary],
                        xaxis: { categories: cfg.revenueMonthly.labels.length ? cfg.revenueMonthly.labels : ['—'] },
                        yaxis: { min: 0 }
                    })
                )
            );
        }
        return pending;
    }

    function mountFacilities(cfg, theme) {
        var pending = [];
        var el = document.querySelector('#facilityBookingsChart');
        if (el && cfg.facilityBookingsBar) {
            pending.push(
                renderChart(
                    new ApexCharts(el, {
                        theme: { mode: apexThemeMode() },
                        series: [{ name: 'Bookings', data: cfg.facilityBookingsBar.counts }],
                        chart: chartBase(el, 'hc-rpt-fac-bookings', theme.fontFamily, { type: 'bar', height: Math.max(320, (cfg.facilityBookingsBar.labels || []).length * 26) }),
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        colors: [theme.primary],
                        dataLabels: { enabled: false },
                        xaxis: { categories: cfg.facilityBookingsBar.labels },
                        yaxis: { min: 0 }
                    })
                )
            );
        }
        return pending;
    }

    function scheduleMount() {
        requestAnimationFrame(function () {
            requestAnimationFrame(mount);
        });
    }

    window.addEventListener('pageshow', function (ev) {
        if (!ev.persisted) {
            return;
        }
        clearReportChartMounts();
        scheduleMount();
    });

    if (document.readyState === 'complete') {
        scheduleMount();
    } else {
        window.addEventListener('load', scheduleMount);
    }

    window.addEventListener('headcount-theme-change', function () {
        clearReportChartMounts();
        scheduleMount();
    });
})();
