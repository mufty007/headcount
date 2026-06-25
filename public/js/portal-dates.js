/**
 * Portal event date helpers — parse YYYY-MM-DD as a local calendar date (no UTC shift).
 */
(function (global) {
    'use strict';

    function parseEventDate(dateStr) {
        if (!dateStr) return null;
        const raw = String(dateStr).trim();
        const datePart = raw.includes('T') ? raw.split('T')[0] : raw;
        const parts = datePart.split('-').map(Number);
        if (parts.length !== 3 || parts.some(function (n) { return Number.isNaN(n); })) {
            return null;
        }
        return new Date(parts[0], parts[1] - 1, parts[2]);
    }

    function formatEventTime(timeStr) {
        if (!timeStr) return '';
        const parts = String(timeStr).split(':').map(Number);
        if (parts.length < 2 || parts.some(function (n) { return Number.isNaN(n); })) {
            return '';
        }
        return new Date(2000, 0, 1, parts[0], parts[1]).toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }

    function formatEventDate(dateStr, options) {
        const d = parseEventDate(dateStr);
        if (!d) return '';
        return d.toLocaleDateString('en-US', options || {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }

    function formatEventDateTime(event) {
        const dateSource = (event && (event.event_date_formatted || event.event_date)) || '';
        return {
            date: parseEventDate(dateSource),
            dateStr: formatEventDate(dateSource),
            timeStr: formatEventTime(event && event.start_time)
        };
    }

    function isEventDatePast(dateStr) {
        const d = parseEventDate(dateStr);
        if (!d) return false;
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        d.setHours(0, 0, 0, 0);
        return d < today;
    }

    global.headcountParseEventDate = parseEventDate;
    global.headcountFormatEventTime = formatEventTime;
    global.headcountFormatEventDate = formatEventDate;
    global.headcountFormatEventDateTime = formatEventDateTime;
    global.headcountIsEventDatePast = isEventDatePast;
})(typeof window !== 'undefined' ? window : this);
