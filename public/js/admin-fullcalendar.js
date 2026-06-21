/**
 * Shared FullCalendar loader for admin pages.
 */
(function (global) {
    'use strict';

    const FC_VERSION = '6.1.15';
    const CSS_URL = `https://cdn.jsdelivr.net/npm/fullcalendar@${FC_VERSION}/index.global.min.css`;
    const JS_URL = `https://cdn.jsdelivr.net/npm/fullcalendar@${FC_VERSION}/index.global.min.js`;

    let loadPromise = null;

    function isDark() {
        return document.documentElement.classList.contains('dark');
    }

    function loadStylesheet(href) {
        if (document.querySelector(`link[data-hc-fc="${href}"]`)) {
            return;
        }
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = href;
        link.setAttribute('data-hc-fc', href);
        document.head.appendChild(link);
    }

    function loadScript(src) {
        const existing = document.querySelector(`script[data-hc-fc="${src}"]`);
        if (existing) {
            return existing.getAttribute('data-loaded') === '1'
                ? Promise.resolve()
                : new Promise((resolve, reject) => {
                    existing.addEventListener('load', () => resolve());
                    existing.addEventListener('error', reject);
                });
        }
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.setAttribute('data-hc-fc', src);
            script.onload = () => {
                script.setAttribute('data-loaded', '1');
                resolve();
            };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function load() {
        if (global.FullCalendar) {
            return Promise.resolve(global.FullCalendar);
        }
        if (!loadPromise) {
            loadPromise = (async () => {
                loadStylesheet(CSS_URL);
                await loadScript(JS_URL);
                if (!global.FullCalendar) {
                    throw new Error('FullCalendar failed to load');
                }
                return global.FullCalendar;
            })();
        }
        return loadPromise;
    }

    /**
     * Inject the Headcount brand theme for FullCalendar once. Appended after the
     * FullCalendar CDN stylesheet (loaded in load()), so equal-specificity rules
     * win the cascade. Themes only the calendar CHROME — event pill colors stay
     * semantic (set per-event) so they keep matching the on-page legends.
     */
    function injectBrandTheme() {
        if (document.getElementById('hc-fc-brand-theme')) {
            return;
        }
        const style = document.createElement('style');
        style.id = 'hc-fc-brand-theme';
        style.textContent = `
/* All rules are scoped to .hc-admin-calendar-root (the container el) and use
   !important + explicit colors. This is order- and specificity-proof against
   FullCalendar v6, which injects its own CSS at render time AFTER this <style>.
   We scope to the root (not ".fc") because FC may apply ".fc" to the container
   itself, making a ".fc descendant" combinator fail to match. */
.hc-admin-calendar-root,
.hc-admin-calendar-root .fc {
    font-family: 'Outfit', system-ui, -apple-system, sans-serif;
}

/* Toolbar buttons → brand */
.hc-admin-calendar-root .fc-button-primary {
    background-color: #3641f5 !important;
    border-color: #3641f5 !important;
    color: #ffffff !important;
    border-radius: 0.5rem !important;
    font-weight: 600 !important;
    font-size: 0.875rem !important;
    padding: 0.5rem 0.9rem !important;
    box-shadow: none !important;
    text-transform: none !important;
    transition: background-color .15s ease, border-color .15s ease;
}
.hc-admin-calendar-root .fc-button-primary:hover {
    background-color: #2832dc !important;
    border-color: #2832dc !important;
}
.hc-admin-calendar-root .fc-button-primary:disabled {
    background-color: #a4c0ff !important;
    border-color: #a4c0ff !important;
    opacity: 1 !important;
}
.hc-admin-calendar-root .fc-button-primary:not(:disabled):active,
.hc-admin-calendar-root .fc-button-primary:not(:disabled).fc-button-active {
    background-color: #2832dc !important;
    border-color: #2832dc !important;
    box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.2) !important;
}
.hc-admin-calendar-root .fc-button-primary:focus,
.hc-admin-calendar-root .fc-button-primary:focus-visible {
    outline: none !important;
    box-shadow: 0 0 0 3px rgba(70, 95, 255, 0.35) !important;
}
.hc-admin-calendar-root .fc-button-group > .fc-button { border-radius: 0.5rem !important; }
.hc-admin-calendar-root .fc-button-group > .fc-button:not(:last-child) { margin-right: 2px; }

/* Today cell → brand-50 (direct, not via --fc-today-bg-color) */
.hc-admin-calendar-root .fc-day-today,
.hc-admin-calendar-root .fc-daygrid-day.fc-day-today,
.hc-admin-calendar-root .fc-timegrid-col.fc-day-today {
    background-color: #eef4ff !important;
}
.hc-admin-calendar-root .fc-timegrid-now-indicator-line,
.hc-admin-calendar-root .fc-timegrid-now-indicator-arrow {
    border-color: #3641f5 !important;
}

/* Typography */
.hc-admin-calendar-root .fc-toolbar-title {
    font-weight: 700 !important;
    font-size: 1.25rem !important;
    color: #111827;
}
.hc-admin-calendar-root .fc-col-header-cell-cushion {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #6b7280;
    padding: 0.55rem 0;
}
.hc-admin-calendar-root .fc-daygrid-day-number,
.hc-admin-calendar-root .fc-timegrid-slot-label-cushion { color: #374151; }

/* Event pills → rounded, no border, comfortable padding */
.hc-admin-calendar-root .fc-daygrid-event,
.hc-admin-calendar-root .fc-timegrid-event,
.hc-admin-calendar-root .fc-daygrid-dot-event {
    border-radius: 6px !important;
    font-weight: 600 !important;
    font-size: 0.75rem !important;
    border: none !important;
    padding: 2px 6px !important;
}
.hc-admin-calendar-root .fc-daygrid-event:hover { filter: brightness(0.95); }
.hc-admin-calendar-root .fc-more-link { color: #3641f5 !important; font-weight: 600; }

/* Dark mode */
.dark .hc-admin-calendar-root .fc { color: #e5e7eb; }
.dark .hc-admin-calendar-root .fc-day-today,
.dark .hc-admin-calendar-root .fc-daygrid-day.fc-day-today,
.dark .hc-admin-calendar-root .fc-timegrid-col.fc-day-today { background-color: rgba(70, 95, 255, 0.14) !important; }
.dark .hc-admin-calendar-root .fc-toolbar-title { color: #f9fafb; }
.dark .hc-admin-calendar-root .fc-col-header-cell-cushion { color: #9ca3af; }
.dark .hc-admin-calendar-root .fc-daygrid-day-number,
.dark .hc-admin-calendar-root .fc-timegrid-slot-label-cushion { color: #d1d5db; }
`;
        document.head.appendChild(style);
    }

    /**
     * @param {HTMLElement} el
     * @param {object} options FullCalendar options
     */
    async function create(el, options) {
        const FC = await load();
        injectBrandTheme();
        el.classList.add('hc-admin-calendar-root');
        if (isDark()) {
            el.classList.add('hc-admin-calendar--dark');
        }
        const defaults = {
            height: 'auto',
            expandRows: true,
            nowIndicator: true,
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay',
            },
            buttonText: { today: 'Today', month: 'Month', week: 'Week', day: 'Day' },
            dayMaxEvents: true,
            fixedWeekCount: false,
            eventDisplay: 'block',
            displayEventTime: true,
            slotMinTime: '06:00:00',
            slotMaxTime: '22:00:00',
            eventTimeFormat: { hour: 'numeric', minute: '2-digit', meridiem: 'short' },
        };
        const calendar = new FC.Calendar(el, Object.assign({}, defaults, options || {}));
        calendar.render();
        return calendar;
    }

    function blockToFcEvent(block) {
        const type = block.type || 'unknown';
        // Most types use a solid fill with white text. Internal/manual blocks use a
        // soft pastel slate fill with dark text so they read as "unavailable / muted"
        // rather than an active booking. `text` overrides the pill text color.
        const colors = {
            booking_approved: { bg: '#2563eb', border: '#1d4ed8' },
            booking_pending: { bg: '#d97706', border: '#b45309' },
            manual_block: { bg: '#e2e8f0', border: '#cbd5e1', text: '#475569' },
            headcount_event: { bg: '#7c3aed', border: '#6d28d9' },
        };
        const c = colors[type] || { bg: '#64748b', border: '#475569' };
        const start = block.start_datetime || block.start;
        const end = block.end_datetime || block.end;
        return {
            id: String(block.id ?? `${type}-${start}`),
            title: block.title || 'Reserved',
            start: start,
            end: end,
            backgroundColor: c.bg,
            borderColor: c.border,
            textColor: c.text || '#ffffff',
            extendedProps: Object.assign({}, block),
        };
    }

    function statusColor(status) {
        const map = {
            published: { bg: '#059669', border: '#047857' },
            draft: { bg: '#6b7280', border: '#4b5563' },
            scheduled: { bg: '#d97706', border: '#b45309' },
            cancelled: { bg: '#dc2626', border: '#b91c1c' },
        };
        return map[String(status || '').toLowerCase()] || { bg: '#2563eb', border: '#1d4ed8' };
    }

    global.HeadcountAdminCalendar = {
        load,
        create,
        isDark,
        blockToFcEvent,
        statusColor,
    };
})(window);
