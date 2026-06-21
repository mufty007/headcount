/**
 * Org-wide facility bookings calendar.
 */
function facilityBookingsCalendarPage(config) {
    const C = config || {};
    const apiBookings = (C.apiBookings || '').replace(/\/+$/, '');

    return {
        facilityFilter: C.facilityFilter ? String(C.facilityFilter) : '',
        csrfToken: C.csrfToken || '',
        isAdmin: !!C.isAdmin,
        panelOpen: false,
        panelItem: null,
        calendar: null,
        toast: '',
        toastError: false,

        init() {
            this.$nextTick(() => this.initCalendar());
        },

        showToast(msg, isError) {
            this.toast = msg;
            this.toastError = !!isError;
            setTimeout(() => { this.toast = ''; }, 4000);
        },

        setFacility(id) {
            this.facilityFilter = id ? String(id) : '';
            const u = new URL(window.location.href);
            if (id) {
                u.searchParams.set('facility_id', id);
            } else {
                u.searchParams.delete('facility_id');
            }
            window.history.replaceState({}, '', u);
            if (this.calendar) {
                this.calendar.refetchEvents();
            }
        },

        typeLabel(type) {
            const map = {
                booking_approved: 'Approved booking',
                booking_pending: 'Pending booking',
                manual_block: 'Internal block',
                headcount_event: 'Headcount event',
            };
            return map[type] || 'Reserved';
        },

        async initCalendar() {
            const el = document.getElementById('facility-bookings-calendar-el');
            if (!el || this.calendar || !window.HeadcountAdminCalendar) return;
            try {
                this.calendar = await window.HeadcountAdminCalendar.create(el, {
                    events: (info, success, failure) => {
                        const start = info.startStr.slice(0, 10);
                        const end = info.endStr.slice(0, 10);
                        let url = `${apiBookings}?action=calendar&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`;
                        if (this.facilityFilter) {
                            url += '&facility_id=' + encodeURIComponent(this.facilityFilter);
                        }
                        fetch(url, { credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) {
                                    failure(new Error(data.message || 'Failed'));
                                    return;
                                }
                                const events = (data.events || []).map(ev => {
                                    const type = ev.extendedProps?.type || 'unknown';
                                    const fc = window.HeadcountAdminCalendar.blockToFcEvent(
                                        Object.assign({}, ev.extendedProps, {
                                            id: ev.id,
                                            title: ev.extendedProps?.display_title || ev.title,
                                            start_datetime: (ev.start || '').replace('T', ' '),
                                            end_datetime: (ev.end || '').replace('T', ' '),
                                        })
                                    );
                                    fc.title = ev.title;
                                    fc.id = ev.id;
                                    return fc;
                                });
                                success(events);
                            })
                            .catch(failure);
                    },
                    eventClick: (info) => {
                        info.jsEvent.preventDefault();
                        this.panelItem = Object.assign({}, info.event.extendedProps || {}, {
                            title: info.event.title,
                            start: info.event.start,
                            end: info.event.end,
                        });
                        this.panelOpen = true;
                    },
                });
            } catch (e) {
                console.error(e);
                this.showToast('Could not load calendar', true);
            }
        },

        closePanel() {
            this.panelOpen = false;
            this.panelItem = null;
        },

        formatWhen(item) {
            if (!item) return '';
            const s = item.start instanceof Date ? item.start : new Date(String(item.start_datetime || item.start || '').replace(' ', 'T'));
            if (Number.isNaN(s.getTime())) return '';
            const eRaw = item.end_datetime || item.end;
            const e = eRaw ? new Date(String(eRaw).replace(' ', 'T')) : null;
            let out = s.toLocaleString('en-US', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
            if (e && !Number.isNaN(e.getTime())) {
                out += ' – ' + e.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            }
            return out;
        },

        facilityDetailsUrl(id) {
            return (C.facilityDetailsBase || '') + encodeURIComponent(id);
        },

        eventEditUrl(id) {
            return (C.eventEditBase || '') + encodeURIComponent(id);
        },

        queueUrl() {
            return C.queueUrl || '';
        },

        async approveBooking() {
            const id = this.panelItem?.source_id;
            if (!id) return;
            await this.bookingAction('approve', id);
        },

        async rejectBooking() {
            const id = this.panelItem?.source_id;
            if (!id) return;
            const reason = prompt('Rejection reason (optional):') || '';
            await this.bookingAction('reject', id, { reason });
        },

        async bookingAction(action, id, extra) {
            try {
                const res = await fetch(`${apiBookings}?action=${action}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    credentials: 'same-origin',
                    body: JSON.stringify(Object.assign({ id }, extra || {})),
                });
                const data = await res.json();
                if (!data.success) {
                    this.showToast(data.message || 'Action failed', true);
                    return;
                }
                this.showToast('Booking updated');
                this.closePanel();
                if (this.calendar) {
                    this.calendar.refetchEvents();
                }
            } catch (e) {
                this.showToast('Network error', true);
            }
        },
    };
}
