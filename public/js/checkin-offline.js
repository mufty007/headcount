/**
 * Offline check-in: IndexedDB cache + action queue, flush on online.
 * DB: headcount_checkin_v1
 * Stores: eventCache (key: org_eventId), actionQueue (key: id)
 */
(function () {
    'use strict';

    const DB_NAME = 'headcount_checkin_v1';
    const DB_VERSION = 1;
    const STORE_EVENT_CACHE = 'eventCache';
    const STORE_ACTION_QUEUE = 'actionQueue';

    function cacheKey(organizationId, eventId) {
        return organizationId + '_' + eventId;
    }

    function openDB() {
        return new Promise(function (resolve, reject) {
            const req = indexedDB.open(DB_NAME, DB_VERSION);
            req.onerror = function () { reject(req.error); };
            req.onsuccess = function () { resolve(req.result); };
            req.onupgradeneeded = function (e) {
                const db = e.target.result;
                if (!db.objectStoreNames.contains(STORE_EVENT_CACHE)) {
                    db.createObjectStore(STORE_EVENT_CACHE, { keyPath: 'cacheKey' });
                }
                if (!db.objectStoreNames.contains(STORE_ACTION_QUEUE)) {
                    db.createObjectStore(STORE_ACTION_QUEUE, { keyPath: 'id', autoIncrement: true });
                }
            };
        });
    }

    /**
     * @returns {Promise<{ event, rsvps, checkedInIds, lastFetched }|null>}
     */
    function getEventCache(organizationId, eventId) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_EVENT_CACHE, 'readonly');
                const store = tx.objectStore(STORE_EVENT_CACHE);
                const req = store.get(cacheKey(organizationId, eventId));
                req.onsuccess = function () {
                    const row = req.result;
                    resolve(row ? { event: row.event, rsvps: row.rsvps || [], checkedInIds: row.checkedInIds || [], lastFetched: row.lastFetched } : null);
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    /**
     * @param {number} organizationId
     * @param {number} eventId
     * @param {{ event?: object, rsvps: array, checkedInIds?: number[], lastFetched?: number }} data
     */
    function setEventCache(organizationId, eventId, data) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_EVENT_CACHE, 'readwrite');
                const store = tx.objectStore(STORE_EVENT_CACHE);
                const key = cacheKey(organizationId, eventId);
                const lastFetched = data.lastFetched != null ? data.lastFetched : Date.now();
                const row = {
                    cacheKey: key,
                    organizationId: organizationId,
                    eventId: eventId,
                    event: data.event || null,
                    rsvps: data.rsvps || [],
                    checkedInIds: data.checkedInIds || [],
                    lastFetched: lastFetched
                };
                const req = store.put(row);
                req.onsuccess = function () { resolve(); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    /**
     * @param {number} [guestsCheckedIn] - optional; for checkin type, how many guests came with this user
     * @returns {Promise<number>} queue item id
     */
    function enqueueAction(organizationId, eventId, type, userId, clientTs, guestsCheckedIn) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_ACTION_QUEUE, 'readwrite');
                const store = tx.objectStore(STORE_ACTION_QUEUE);
                const item = {
                    event_id: eventId,
                    organization_id: organizationId,
                    type: type,
                    user_id: userId,
                    client_ts: clientTs || null,
                    created_at: Date.now()
                };
                if (type === 'checkin' && typeof guestsCheckedIn === 'number' && guestsCheckedIn >= 0) {
                    item.guests_checked_in = guestsCheckedIn;
                }
                const req = store.add(item);
                req.onsuccess = function () { resolve(req.result); };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    /**
     * @returns {Promise<Array<{ id, event_id, type, user_id, client_ts, created_at }>>}
     */
    function getQueueForEvent(eventId) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_ACTION_QUEUE, 'readonly');
                const store = tx.objectStore(STORE_ACTION_QUEUE);
                const req = store.openCursor();
                const out = [];
                req.onsuccess = function (e) {
                    const cursor = e.target.result;
                    if (cursor) {
                        if (cursor.value.event_id === eventId) {
                            var o = { id: cursor.value.id, event_id: cursor.value.event_id, type: cursor.value.type, user_id: cursor.value.user_id, client_ts: cursor.value.client_ts, created_at: cursor.value.created_at };
                            if (cursor.value.guests_checked_in !== undefined) o.guests_checked_in = cursor.value.guests_checked_in;
                            out.push(o);
                        }
                        cursor.continue();
                    } else {
                        out.sort(function (a, b) { return a.created_at - b.created_at; });
                        resolve(out);
                    }
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    /**
     * @returns {Promise<number[]>} unique event IDs that have queued actions
     */
    function getQueuedEventIds() {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_ACTION_QUEUE, 'readonly');
                const store = tx.objectStore(STORE_ACTION_QUEUE);
                const req = store.openCursor();
                const ids = {};
                req.onsuccess = function (e) {
                    const cursor = e.target.result;
                    if (cursor) {
                        ids[cursor.value.event_id] = true;
                        cursor.continue();
                    } else {
                        resolve(Object.keys(ids).map(Number));
                    }
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    /**
     * @returns {Promise<number>} count of queued items for event (or all if eventId null)
     */
    function getQueueCount(eventId) {
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_ACTION_QUEUE, 'readonly');
                const store = tx.objectStore(STORE_ACTION_QUEUE);
                if (eventId == null) {
                    const req = store.count();
                    req.onsuccess = function () { resolve(req.result); };
                    req.onerror = function () { reject(req.error); };
                    return;
                }
                let n = 0;
                const req = store.openCursor();
                req.onsuccess = function (e) {
                    const cursor = e.target.result;
                    if (cursor) {
                        if (cursor.value.event_id === eventId) n++;
                        cursor.continue();
                    } else {
                        resolve(n);
                    }
                };
                req.onerror = function () { reject(req.error); };
            });
        });
    }

    function removeQueueItems(ids) {
        if (!ids.length) return Promise.resolve();
        return openDB().then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(STORE_ACTION_QUEUE, 'readwrite');
                const store = tx.objectStore(STORE_ACTION_QUEUE);
                ids.forEach(function (id) { store.delete(id); });
                tx.oncomplete = function () { resolve(); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function isOnline() {
        return typeof navigator !== 'undefined' && !!navigator.onLine;
    }

    function onOnline(callback) {
        if (typeof window === 'undefined') return;
        window.addEventListener('online', callback);
    }

    function onOffline(callback) {
        if (typeof window === 'undefined') return;
        window.addEventListener('offline', callback);
    }

    /**
     * Build actions array from queue items (same shape as checkin-sync.php expects)
     */
    function queueItemsToActions(items) {
        return items.map(function (item) {
            var a = { type: item.type, user_id: item.user_id };
            if (item.type === 'checkin' && item.client_ts) a.client_ts = item.client_ts;
            if (item.type === 'checkin' && typeof item.guests_checked_in === 'number') a.guests_checked_in = item.guests_checked_in;
            return a;
        });
    }

    /**
     * Flush queue for one event. Returns { success, applied, rsvps, results, error? }.
     * @param {string} apiBase - e.g. '/api'
     * @param {number} organizationId
     * @param {number} eventId
     * @param {RequestCredentials} credentials - 'same-origin' or 'include'
     */
    function flushQueueForEvent(apiBase, organizationId, eventId, credentials) {
        credentials = credentials || 'same-origin';
        return getQueueForEvent(eventId).then(function (items) {
            if (!items.length) {
                return { success: true, applied: 0, skipped: true, results: [] };
            }
            var actions = queueItemsToActions(items);
            var url = (apiBase.replace(/\/$/, '') + '/checkin-sync.php');
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id: eventId, actions: actions }),
                credentials: credentials
            }).then(function (res) {
                if (res.status === 401) {
                    return res.json().catch(function () { return {}; }).then(function (body) {
                        return { success: false, error: 'Session expired', status: 401, body: body };
                    });
                }
                return res.json().then(function (data) {
                    if (data.success) {
                        return removeQueueItems(items.map(function (i) { return i.id; })).then(function () {
                            return {
                                success: true,
                                applied: data.applied || 0,
                                rsvps: data.rsvps || [],
                                results: data.results || [],
                                total_rsvps: data.total_rsvps,
                                total_heads: data.total_heads,
                                not_checked_in_heads: data.not_checked_in_heads,
                                total_registrants_yes: data.total_registrants_yes,
                                checked_in: data.checked_in,
                                not_checked_in: data.not_checked_in
                            };
                        });
                    }
                    return { success: false, error: data.message || 'Sync failed', body: data };
                });
            }).catch(function (err) {
                return { success: false, error: err.message || 'Network error', network: true };
            });
        });
    }

    /**
     * Apply optimistic check-in to cached rsvps (mutates rsvps and updates checkedInIds).
     * Call after enqueueAction('checkin', ...) to update local view.
     */
    function applyOptimisticCheckIn(rsvps, checkedInIds, userId, checkedInTime) {
        checkedInTime = checkedInTime || new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        if (checkedInIds.indexOf(userId) >= 0) return;
        checkedInIds.push(userId);
        for (var i = 0; i < rsvps.length; i++) {
            if (rsvps[i].id === userId) {
                rsvps[i].checked_in = true;
                rsvps[i].checked_in_at = new Date().toISOString().slice(0, 19).replace('T', ' ');
                rsvps[i].checked_in_time = checkedInTime;
                break;
            }
        }
    }

    /**
     * Apply optimistic undo to cached rsvps.
     */
    function applyOptimisticUndo(rsvps, checkedInIds, userId) {
        var idx = checkedInIds.indexOf(userId);
        if (idx >= 0) checkedInIds.splice(idx, 1);
        for (var i = 0; i < rsvps.length; i++) {
            if (rsvps[i].id === userId) {
                rsvps[i].checked_in = false;
                rsvps[i].checked_in_at = null;
                rsvps[i].checked_in_time = null;
                break;
            }
        }
    }

    window.HeadcountCheckinOffline = {
        getEventCache: getEventCache,
        setEventCache: setEventCache,
        enqueueAction: enqueueAction,
        getQueueForEvent: getQueueForEvent,
        getQueuedEventIds: getQueuedEventIds,
        getQueueCount: getQueueCount,
        removeQueueItems: removeQueueItems,
        isOnline: isOnline,
        onOnline: onOnline,
        onOffline: onOffline,
        flushQueueForEvent: flushQueueForEvent,
        applyOptimisticCheckIn: applyOptimisticCheckIn,
        applyOptimisticUndo: applyOptimisticUndo,
        openDB: openDB
    };
})();
