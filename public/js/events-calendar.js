/**
 * Admin events calendar page.
 */
function eventsCalendarPage(config) {
    const C = config || {};
    const apiEvents = (C.apiEvents || '').replace(/\/+$/, '');

    return {
        statusFilter: C.statusFilter || 'all',
        panelOpen: false,
        panelEvent: null,
        calendar: null,
        toast: '',
        toastError: false,
        adminBase: C.adminBase || '',

        init() {
            this.$nextTick(() => this.initCalendar());
        },

        showToast(msg, isError) {
            this.toast = msg;
            this.toastError = !!isError;
            setTimeout(() => { this.toast = ''; }, 4000);
        },

        setStatus(st) {
            this.statusFilter = st;
            const u = new URL(window.location.href);
            if (st === 'all') {
                u.searchParams.delete('status');
            } else {
                u.searchParams.set('status', st);
            }
            window.history.replaceState({}, '', u);
            if (this.calendar) {
                this.calendar.refetchEvents();
            }
        },

        async initCalendar() {
            const el = document.getElementById('events-calendar-el');
            if (!el || this.calendar || !window.HeadcountAdminCalendar) return;
            try {
                this.calendar = await window.HeadcountAdminCalendar.create(el, {
                    events: (info, success, failure) => {
                        const start = info.startStr.slice(0, 10);
                        const end = info.endStr.slice(0, 10);
                        let url = `${apiEvents}?action=calendar&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
                        if (this.statusFilter && this.statusFilter !== 'all') {
                            url += '&status=' + encodeURIComponent(this.statusFilter);
                        }
                        fetch(url, { credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) {
                                    failure(new Error(data.message || 'Failed'));
                                    return;
                                }
                                const events = (data.events || []).map(ev => {
                                    const st = ev.extendedProps?.status || 'published';
                                    const c = window.HeadcountAdminCalendar.statusColor(st);
                                    return Object.assign({}, ev, {
                                        backgroundColor: c.bg,
                                        borderColor: c.border,
                                    });
                                });
                                success(events);
                            })
                            .catch(failure);
                    },
                    eventClick: (info) => {
                        info.jsEvent.preventDefault();
                        this.panelEvent = Object.assign({ id: info.event.id }, info.event.extendedProps || {}, {
                            title: info.event.title,
                            start: info.event.start,
                            end: info.event.end,
                            allDay: info.event.allDay,
                        });
                        this.panelOpen = true;
                    },
                    dateClick: (info) => {
                        const d = info.dateStr.slice(0, 10);
                        window.location.href = `${C.createEventUrl || ''}&event_date=${encodeURIComponent(d)}`;
                    },
                });
            } catch (e) {
                console.error(e);
                this.showToast('Could not load calendar', true);
            }
        },

        closePanel() {
            this.panelOpen = false;
            this.panelEvent = null;
        },

        formatEventWhen(ev) {
            if (!ev || !ev.start) return '';
            const s = ev.start instanceof Date ? ev.start : new Date(ev.start);
            if (Number.isNaN(s.getTime())) return '';
            if (ev.allDay) {
                return s.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
            }
            const e = ev.end ? (ev.end instanceof Date ? ev.end : new Date(ev.end)) : null;
            let out = s.toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            if (e && !Number.isNaN(e.getTime())) {
                out += ' – ' + e.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            }
            return out;
        },

        detailsUrl(id) {
            return (C.eventDetailsBase || '') + encodeURIComponent(id);
        },
        editUrl(id) {
            return (C.eventEditBase || '') + encodeURIComponent(id);
        },
        checkinUrl(id) {
            return (C.checkinBase || '') + encodeURIComponent(id);
        },
    };
}
