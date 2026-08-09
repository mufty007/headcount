/**
 * Facility details hub: tabs, calendar, blocks, managers, bookings.
 */
function facilityDetailsPage(config) {
    const C = config || {};
    const apiBookings = (C.apiBookings || '').replace(/\/+$/, '');
    const apiFacilities = (C.apiFacilities || '').replace(/\/+$/, '');

    return {
        facilityId: C.facilityId,
        csrfToken: C.csrfToken || '',
        isAdmin: !!C.isAdmin,
        activeTab: C.initialTab || 'calendar',
        editUrl: C.editUrl || '',
        bookingsUrl: C.bookingsUrl || '',
        eventEditBase: C.eventEditBase || '',
        eventDetailsBase: C.eventDetailsBase || '',

        scheduleBlocks: C.scheduleBlocks || [],
        manualBlocks: C.manualBlocks || [],
        bookings: C.bookings || [],
        bookingStatus: C.bookingStatus || 'all',
        managerIds: C.managerIds || [],
        eligibleManagers: C.eligibleManagers || [],
        managersSaving: false,
        managersMessage: '',

        weekDayOptions: [
            { value: 0, label: 'Sun' },
            { value: 1, label: 'Mon' },
            { value: 2, label: 'Tue' },
            { value: 3, label: 'Wed' },
            { value: 4, label: 'Thu' },
            { value: 5, label: 'Fri' },
            { value: 6, label: 'Sat' },
        ],

        blockForm: {
            repeat: 'once',
            date: '',
            start_date: '',
            end_date: '',
            days_of_week: [],
            start_time: '09:00',
            end_time: '10:00',
            reason: '',
            block_member: true,
            block_guest: true,
        },
        blockSaving: false,
        blockMessage: '',

        panelOpen: false,
        panelMode: 'view',
        panelData: null,
        panelBlockForm: {
            repeat: 'once',
            date: '',
            start_date: '',
            end_date: '',
            days_of_week: [],
            start_time: '',
            end_time: '',
            reason: '',
            block_member: true,
            block_guest: true,
        },

        calendar: null,
        calendarLoading: false,
        toast: '',
        toastError: false,

        init() {
            const params = new URLSearchParams(window.location.search);
            const tab = params.get('tab');
            if (tab && ['calendar', 'bookings', 'blocks', 'managers'].includes(tab)) {
                this.activeTab = tab;
            }
            if (this.isAdmin && this.eligibleManagers.length === 0) {
                this.loadEligibleManagers();
            }
            this.$watch('activeTab', (t) => {
                const u = new URL(window.location.href);
                u.searchParams.set('tab', t);
                window.history.replaceState({}, '', u);
                if (t === 'calendar') {
                    this.$nextTick(() => this.initCalendar());
                }
            });
            if (this.activeTab === 'calendar') {
                this.$nextTick(() => this.initCalendar());
            }
        },

        setTab(tab) {
            this.activeTab = tab;
        },

        showToast(msg, isError) {
            this.toast = msg;
            this.toastError = !!isError;
            setTimeout(() => { this.toast = ''; }, 4000);
        },

        formatRange(start, end) {
            if (!start) return '';
            const s = new Date(String(start).replace(' ', 'T'));
            const e = end ? new Date(String(end).replace(' ', 'T')) : null;
            if (Number.isNaN(s.getTime())) return String(start);
            const dOpts = { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' };
            let out = s.toLocaleString('en-US', dOpts);
            if (e && !Number.isNaN(e.getTime())) {
                out += ' – ' + e.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            }
            return out;
        },

        typeLabel(type) {
            const map = {
                booking_approved: 'Booking',
                booking_pending: 'Pending booking',
                manual_block: 'Internal block',
                headcount_event: 'Headcount event',
            };
            return map[type] || 'Reserved';
        },

        typeBadgeClass(type) {
            const map = {
                booking_approved: 'bg-sky-100 text-sky-800 dark:bg-sky-900/40 dark:text-sky-200',
                booking_pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200',
                manual_block: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                headcount_event: 'bg-violet-100 text-violet-800 dark:bg-violet-900/40 dark:text-violet-200',
            };
            return map[type] || 'bg-gray-100 text-gray-600';
        },

        async refreshSchedule() {
            const start = new Date().toISOString().slice(0, 10);
            const end = new Date(Date.now() + 120 * 86400000).toISOString().slice(0, 10);
            try {
                const res = await fetch(`${apiBookings}?action=availability&facility_id=${this.facilityId}&start=${start}&end=${end}`, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success) {
                    this.scheduleBlocks = data.blocks || [];
                }
            } catch (e) {
                console.error(e);
            }
            if (this.calendar) {
                this.calendar.refetchEvents();
            }
        },

        async initCalendar() {
            const el = document.getElementById('facility-calendar-el');
            if (!el || this.calendar || !window.HeadcountAdminCalendar) return;
            try {
                this.calendarLoading = true;
                const slotMin = C.slotMinTime || '06:00:00';
                const slotMax = C.slotMaxTime || '22:00:00';
                this.calendar = await window.HeadcountAdminCalendar.create(el, {
                    slotMinTime: slotMin,
                    slotMaxTime: slotMax,
                    selectable: this.isAdmin,
                    selectMirror: true,
                    events: (info, success, failure) => {
                        const start = info.startStr.slice(0, 10);
                        const end = info.endStr.slice(0, 10);
                        fetch(`${apiBookings}?action=availability&facility_id=${this.facilityId}&start=${start}&end=${end}`, { credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) {
                                    failure(new Error(data.message || 'Failed'));
                                    return;
                                }
                                success((data.blocks || []).map(b => window.HeadcountAdminCalendar.blockToFcEvent(b)));
                            })
                            .catch(failure);
                    },
                    dateClick: (info) => {
                        if (!this.isAdmin) return;
                        this.openBlockPanelFromSelect(info.date, info.date);
                    },
                    select: (info) => {
                        if (!this.isAdmin) return;
                        this.openBlockPanelFromSelect(info.start, info.end);
                    },
                    eventClick: (info) => {
                        info.jsEvent.preventDefault();
                        this.openViewPanel(info.event.extendedProps || {});
                    },
                });
            } catch (e) {
                console.error('Calendar init failed', e);
                this.showToast('Could not load calendar', true);
            } finally {
                this.calendarLoading = false;
            }
        },

        openBlockPanelFromSelect(startDate, endDate) {
            const pad = (n) => String(n).padStart(2, '0');
            const fmtDate = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
            const fmtTime = (d) => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
            const start = startDate instanceof Date ? startDate : new Date(startDate);
            let end = endDate instanceof Date ? endDate : new Date(endDate);
            // FullCalendar uses exclusive end; midnight end means through previous day.
            if (end.getHours() === 0 && end.getMinutes() === 0 && end.getSeconds() === 0 && end > start) {
                end = new Date(end.getTime() - 1);
            }
            if (end <= start) {
                end = new Date(start.getTime() + 3600000);
            }
            const startDay = fmtDate(start);
            const endDay = fmtDate(end);
            const isMultiDay = startDay !== endDay;
            this.panelMode = 'block';
            this.panelBlockForm = {
                repeat: isMultiDay ? 'range' : 'once',
                date: startDay,
                start_date: startDay,
                end_date: endDay,
                days_of_week: [],
                start_time: isMultiDay ? '00:00' : fmtTime(start),
                end_time: isMultiDay ? '23:59' : fmtTime(end),
                reason: '',
                block_member: true,
                block_guest: true,
            };
            this.panelData = null;
            this.panelOpen = true;
        },

        toggleFormDay(form, day, checked) {
            day = Number(day);
            if (!Array.isArray(form.days_of_week)) form.days_of_week = [];
            if (checked) {
                if (!form.days_of_week.includes(day)) form.days_of_week.push(day);
                form.days_of_week.sort((a, b) => a - b);
            } else {
                form.days_of_week = form.days_of_week.filter((d) => d !== day);
            }
        },

        applySchoolHoursPreset() {
            const start = new Date();
            const end = new Date();
            end.setMonth(end.getMonth() + 9);
            const pad = (n) => String(n).padStart(2, '0');
            const iso = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
            this.blockForm = {
                repeat: 'weekly',
                date: iso(start),
                start_date: iso(start),
                end_date: iso(end),
                days_of_week: [1, 2, 3, 4, 5],
                start_time: '08:00',
                end_time: '15:00',
                reason: 'School hours',
                block_member: true,
                block_guest: true,
            };
        },

        ruleRepeatLabel(rule) {
            const r = String((rule && rule.repeat) || 'once').toLowerCase();
            if (r === 'weekly') return 'Weekly';
            if (r === 'range') return 'Date range';
            return 'One day';
        },

        ruleSummary(rule) {
            if (!rule) return '';
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const time = `${rule.start_time || ''}–${rule.end_time || ''}`;
            const r = String(rule.repeat || 'once').toLowerCase();
            if (r === 'weekly') {
                const selected = (rule.days_of_week || []).map((d) => days[Number(d)] || d).join(', ');
                return `${rule.start_date || ''} → ${rule.end_date || ''} · ${selected || 'no days'} · ${time}`;
            }
            if (r === 'range') {
                return `${rule.start_date || ''} → ${rule.end_date || ''} · ${time}`;
            }
            return `${rule.date || rule.start_date || ''} · ${time}`;
        },

        prepareBlockPayload(form) {
            const repeat = String((form && form.repeat) || 'once').toLowerCase();
            const payload = {
                repeat,
                start_time: form.start_time,
                end_time: form.end_time,
                reason: form.reason || '',
                block_member: !!form.block_member,
                block_guest: !!form.block_guest,
                days_of_week: Array.isArray(form.days_of_week) ? form.days_of_week : [],
            };
            if (repeat === 'once') {
                payload.date = form.date || form.start_date || '';
                payload.start_date = payload.date;
                payload.end_date = payload.date;
            } else {
                payload.start_date = form.start_date || form.date || '';
                payload.end_date = form.end_date || form.start_date || form.date || '';
                payload.date = payload.start_date;
            }
            return payload;
        },

        openViewPanel(props) {
            this.panelMode = 'view';
            this.panelData = props;
            this.panelOpen = true;
        },

        closePanel() {
            this.panelOpen = false;
            this.panelData = null;
        },

        async submitPanelBlock() {
            return this.saveBlock(this.panelBlockForm, () => {
                this.closePanel();
            });
        },

        async saveBlock(form, onSuccess) {
            if (!this.isAdmin) return;
            this.blockSaving = true;
            this.blockMessage = '';
            try {
                const payload = Object.assign(
                    { facility_id: this.facilityId },
                    this.prepareBlockPayload(form)
                );
                const res = await fetch(`${apiFacilities}?action=add-block`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) {
                    this.blockMessage = data.message || 'Failed to save block';
                    this.showToast(this.blockMessage, true);
                    return;
                }
                if (Array.isArray(data.blocked_times)) {
                    this.manualBlocks = data.blocked_times;
                }
                this.showToast('Block saved');
                await this.refreshSchedule();
                if (typeof onSuccess === 'function') onSuccess();
            } catch (e) {
                this.blockMessage = 'Network error';
                this.showToast(this.blockMessage, true);
            } finally {
                this.blockSaving = false;
            }
        },

        async removeBlock(index) {
            if (!this.isAdmin || index == null) return;
            if (!confirm('Remove this block rule? All dates covered by the rule will open again.')) return;
            try {
                const res = await fetch(`${apiFacilities}?action=remove-block`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    credentials: 'same-origin',
                    body: JSON.stringify({ facility_id: this.facilityId, index }),
                });
                const data = await res.json();
                if (!data.success) {
                    this.showToast(data.message || 'Failed', true);
                    return;
                }
                if (Array.isArray(data.blocked_times)) {
                    this.manualBlocks = data.blocked_times;
                }
                this.showToast('Block removed');
                this.closePanel();
                await this.refreshSchedule();
            } catch (e) {
                this.showToast('Network error', true);
            }
        },

        toggleManager(id) {
            id = parseInt(id, 10);
            if (this.managerIds.includes(id)) {
                this.managerIds = this.managerIds.filter(x => x !== id);
            } else {
                this.managerIds.push(id);
            }
        },

        async loadEligibleManagers() {
            try {
                const res = await fetch(`${apiFacilities}?action=eligible-managers`, { credentials: 'same-origin' });
                const data = await res.json();
                if (data.success) {
                    this.eligibleManagers = data.users || [];
                }
            } catch (e) {
                console.error(e);
            }
        },

        async saveManagers() {
            if (!this.isAdmin) return;
            this.managersSaving = true;
            this.managersMessage = '';
            try {
                const res = await fetch(`${apiFacilities}?action=update-managers`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken },
                    credentials: 'same-origin',
                    body: JSON.stringify({ facility_id: this.facilityId, manager_ids: this.managerIds }),
                });
                const data = await res.json();
                if (!data.success) {
                    this.managersMessage = data.message || 'Save failed';
                    return;
                }
                this.managerIds = data.manager_ids || [];
                this.showToast('Managers updated');
            } catch (e) {
                this.managersMessage = 'Network error';
            } finally {
                this.managersSaving = false;
            }
        },

        async approveBooking(id) {
            await this.bookingAction('approve', id);
        },

        async rejectBooking(id) {
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
                window.location.reload();
            } catch (e) {
                this.showToast('Network error', true);
            }
        },

        eventEditUrl(eventId) {
            return this.eventEditBase + encodeURIComponent(eventId);
        },
    };
}
