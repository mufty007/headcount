/**
 * Combined Main Calendar page.
 */
function mainCalendarPage(config) {
    const C = config || {};
    const apiUrl = (C.apiUrl || '').replace(/\/+$/, '');

    return {
        panelOpen: false,
        panelItem: null,
        calendar: null,
        toast: '',
        toastError: false,

        init() {
            this.$nextTick(() => this.initCalendar());
        },

        closePanel() {
            this.panelOpen = false;
            this.panelItem = null;
        },

        panelTitle() {
            const t = this.panelItem?.item_type || '';
            if (t === 'program') return 'Program';
            if (t === 'facility') return 'Facility';
            return 'Event';
        },

        detailUrl() {
            const item = this.panelItem || {};
            const kind = item.detail_kind || item.item_type || 'event';
            if (kind === 'program' && item.program_id) {
                return (C.programDetailsBase || '') + item.program_id;
            }
            if (kind === 'facility' && item.facility_id) {
                return (C.facilityDetailsBase || '') + item.facility_id;
            }
            const id = item.id || item.event_id;
            return (C.eventDetailsBase || '') + id;
        },

        async initCalendar() {
            const el = document.getElementById('main-calendar-el');
            if (!el || this.calendar || !window.HeadcountAdminCalendar) return;
            try {
                this.calendar = await window.HeadcountAdminCalendar.create(el, {
                    initialView: 'dayGridMonth',
                    headerToolbar: {
                        left: 'prev,next today',
                        center: 'title',
                        right: 'dayGridMonth,timeGridWeek',
                    },
                    events: (info, success, failure) => {
                        const start = info.startStr.slice(0, 10);
                        const end = info.endStr.slice(0, 10);
                        const url = `${apiUrl}?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
                        fetch(url, { credentials: 'same-origin' })
                            .then((r) => r.json())
                            .then((data) => {
                                if (!data.success) {
                                    failure(new Error(data.message || 'Failed'));
                                    return;
                                }
                                success(data.events || []);
                            })
                            .catch(failure);
                    },
                    eventClick: (info) => {
                        info.jsEvent.preventDefault();
                        this.panelItem = Object.assign({ id: info.event.id }, info.event.extendedProps || {}, {
                            title: info.event.title,
                            start: info.event.start,
                            end: info.event.end,
                        });
                        this.panelOpen = true;
                    },
                });
            } catch (e) {
                this.toast = (e && e.message) ? e.message : 'Calendar failed to load';
                this.toastError = true;
            }
        },
    };
}
