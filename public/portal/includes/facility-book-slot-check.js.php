<?php
/**
 * Shared Alpine helpers for facility booking slot / IMCA event block checks.
 * Expects component with: facilityId, availabilityApi, form { date, start_time, end_time }, error.
 */
?>
        blocks: [],
        blocksLoading: false,
        slotReservedMessage: '',

        async loadBlocks() {
            if (!this.form.date || !this.availabilityApi) {
                this.blocks = [];
                this.checkSlotReserved();
                return;
            }
            this.blocksLoading = true;
            const start = this.form.date;
            const end = this.form.date;
            try {
                const url = this.availabilityApi
                    + '?action=availability&facility_id=' + encodeURIComponent(this.facilityId)
                    + '&start=' + encodeURIComponent(start)
                    + '&end=' + encodeURIComponent(end);
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json();
                this.blocks = data.success && Array.isArray(data.blocks) ? data.blocks : [];
            } catch (e) {
                this.blocks = [];
            } finally {
                this.blocksLoading = false;
                this.checkSlotReserved();
            }
        },

        checkSlotReserved() {
            this.slotReservedMessage = '';
            if (!this.form.date || !this.form.start_time || !this.form.end_time) {
                return;
            }
            const start = new Date(this.form.date + 'T' + this.form.start_time).getTime();
            const end = new Date(this.form.date + 'T' + this.form.end_time).getTime();
            if (isNaN(start) || isNaN(end) || end <= start) {
                return;
            }
            for (const b of this.blocks) {
                const rawStart = String(b.start_datetime || '').replace(' ', 'T');
                const rawEnd = String(b.end_datetime || '').replace(' ', 'T');
                const bs = new Date(rawStart).getTime();
                const be = new Date(rawEnd).getTime();
                if (isNaN(bs) || isNaN(be)) {
                    continue;
                }
                if (start < be && end > bs) {
                    const label = b.title || 'Reserved';
                    const isEvent = b.status === 'blocked' || String(b.id || '').indexOf('event-') === 0;
                    this.slotReservedMessage = isEvent
                        ? 'This time is reserved for an IMCA event: ' + label + '. Please choose another slot.'
                        : 'This time overlaps with an existing reservation. Please choose another slot.';
                    return;
                }
            }
        },

        slotCheckBeforeSubmit() {
            this.checkSlotReserved();
            if (this.slotReservedMessage) {
                this.error = this.slotReservedMessage;
                return false;
            }
            return true;
        },
