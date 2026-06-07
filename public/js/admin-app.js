/**
 * Admin UI – shared Alpine factories and API helpers
 * Use x-data="adminModal()", adminFilterBar(options), offlineBanner(), etc.
 */
(function () {
    'use strict';

    if (typeof window.AdminApi !== 'undefined') return;

    // ----- API helpers -----
    window.AdminApi = {
        async fetch(url, options = {}) {
            const res = await fetch(url, { credentials: 'same-origin', ...options });
            const contentType = res.headers.get('content-type') || '';
            if (contentType.indexOf('application/json') !== -1) {
                const data = await res.json().catch(function () { return { success: false }; });
                return { ok: res.ok, status: res.status, data };
            }
            return { ok: res.ok, status: res.status, data: null };
        },
        async get(url) {
            return AdminApi.fetch(url);
        },
        async post(url, body) {
            return AdminApi.fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: typeof body === 'string' ? body : JSON.stringify(body || {})
            });
        },
        standardMessage(data) {
            return (data && data.message) ? data.message : (data && data.error) ? data.error : 'Something went wrong.';
        }
    };

    // ----- Alpine: modal manager -----
    window.adminModal = function (opts) {
        opts = opts || {};
        return {
            open: false,
            openModal: function () { this.open = true; },
            closeModal: function () { this.open = false; },
            toggle: function () { this.open = !this.open; }
        };
    };

    // ----- Alpine: filter bar (state only; form submit can be custom) -----
    window.adminFilterBar = function (opts) {
        opts = opts || {};
        return {
            status: opts.status || opts.initialStatus || 'all',
            category: opts.category || opts.initialCategory || 'all',
            search: opts.search || opts.initialSearch || '',
            dateFrom: opts.dateFrom || '',
            dateTo: opts.dateTo || '',
            setStatus: function (v) { this.status = v; },
            setCategory: function (v) { this.category = v; },
            setSearch: function (v) { this.search = v; },
            apply: function () {
                if (typeof opts.onApply === 'function') opts.onApply(this);
            }
        };
    };

    // ----- Alpine: pagination -----
    window.adminPagination = function (opts) {
        opts = opts || {};
        var current = Math.max(1, parseInt(opts.currentPage, 10) || 1);
        var total = Math.max(0, parseInt(opts.totalPages, 10) || 1);
        var baseUrl = opts.baseUrl || '';
        var param = opts.pageParam || 'p';
        return {
            currentPage: current,
            totalPages: total,
            baseUrl: baseUrl,
            pageParam: param,
            hasPrev: current > 1,
            hasNext: current < total,
            prevUrl: function () {
                var sep = baseUrl.indexOf('?') !== -1 ? '&' : '?';
                return baseUrl + sep + param + '=' + (current - 1);
            },
            nextUrl: function () {
                var sep = baseUrl.indexOf('?') !== -1 ? '&' : '?';
                return baseUrl + sep + param + '=' + (current + 1);
            },
            pageUrl: function (p) {
                var sep = baseUrl.indexOf('?') !== -1 ? '&' : '?';
                return baseUrl + sep + param + '=' + p;
            }
        };
    };

    // ----- Alpine: offline / sync banner (generic) -----
    window.offlineBanner = function (opts) {
        opts = opts || {};
        return {
            isOffline: false,
            pendingSyncCount: opts.pendingSyncCount || 0,
            syncingInProgress: false,
            init: function () {
                var self = this;
                function updateOnline() {
                    self.isOffline = !navigator.onLine;
                }
                window.addEventListener('online', updateOnline);
                window.addEventListener('offline', updateOnline);
                updateOnline();
                if (typeof opts.onInit === 'function') opts.onInit(this);
            }
        };
    };

    // ----- Alpine: async table loader -----
    window.asyncTableLoader = function (opts) {
        opts = opts || {};
        return {
            loading: false,
            error: null,
            data: opts.initialData || [],
            load: async function () {
                this.loading = true;
                this.error = null;
                try {
                    var url = typeof opts.url === 'function' ? opts.url() : opts.url;
                    if (!url) { this.loading = false; return; }
                    var res = await AdminApi.get(url);
                    if (res.ok && res.data && res.data.success !== false) {
                        this.data = (res.data.data !== undefined) ? res.data.data : (res.data.items !== undefined) ? res.data.items : (Array.isArray(res.data) ? res.data : []);
                        if (typeof opts.onLoad === 'function') opts.onLoad(this.data);
                    } else {
                        this.error = AdminApi.standardMessage(res.data);
                    }
                } catch (e) {
                    this.error = e.message || 'Failed to load';
                } finally {
                    this.loading = false;
                }
            },
            init: function () {
                if (opts.loadOnInit !== false) this.load();
            }
        };
    };

})();
