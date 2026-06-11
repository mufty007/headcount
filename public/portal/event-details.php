<?php

/**
 * Event Details Page
 * Public page (no authentication required to view, but required to RSVP)
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

// Get event ID
$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$eventId) {
    header('Location: events.php');
    exit;
}

// Check if user is logged in
$isLoggedIn = PortalAuthMiddleware::isAuthenticated();
$memberId = $isLoggedIn ? PortalAuthMiddleware::getMemberId() : null;

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
// Handle both /portal/ and /portal cases
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');
// Ensure baseUrlPath is not empty - default to root if empty
if (empty($baseUrlPath)) {
    $baseUrlPath = '';
}
$apiBase = $baseUrlPath . '/api/portal/';

// CSRF token from same request as page load so RSVP works when cookies are strict (e.g. mobile)
$eventCsrfToken = Security::generateCSRFToken();

// Get member data if logged in
if ($isLoggedIn) {
    $member = PortalAuthMiddleware::getMember();
} else {
    $member = null;
}

// Set page title
$pageTitle = 'Event Details';

// Include header
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <!-- Back Button -->
    <div class="mb-6 flex items-center justify-between">
        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php" 
           class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-indigo-600 transition-colors group">
            <div class="p-2 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm group-hover:border-indigo-100 group-hover:bg-indigo-50 transition-all">
                <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            </div>
            <span>Back to Events</span>
        </a>
    </div>

    <!-- Main Content Grid -->
    <div id="event-container">
        <!-- Skeleton / Loading State -->
        <div class="animate-pulse space-y-8">
            <div class="h-64 bg-gray-200 rounded-3xl"></div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-4">
                    <div class="h-10 bg-gray-200 rounded-lg w-3/4"></div>
                    <div class="h-4 bg-gray-200 rounded-lg w-full"></div>
                    <div class="h-4 bg-gray-200 rounded-lg w-5/6"></div>
                </div>
                <div class="space-y-4">
                    <div class="h-48 bg-gray-200 rounded-3xl"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const apiBase = <?php echo json_encode($apiBase); ?>;
    const baseUrl = <?php echo json_encode($baseUrlPath); ?>;
    const eventId = <?php echo json_encode($eventId); ?>;
    const isLoggedIn = <?php echo json_encode($isLoggedIn); ?>;
    const memberId = <?php echo json_encode($memberId); ?>;
    const embeddedCsrfToken = <?php echo json_encode($eventCsrfToken); ?>;

    // Load event details
    async function loadEvent() {
        clearPortalSaleCountdownTimer();
        try {
            const response = await fetch(apiBase + 'events/' + eventId);
            const data = await response.json().catch(function () { return {}; });

            if (data.success && data.event) {
                displayEvent(data.event);
            } else if (data.error_code === 'event_not_in_portal') {
                document.getElementById('event-container').innerHTML =
                    '<div class="text-center py-24 bento-card max-w-lg mx-auto"><div class="text-4xl mb-4">🔒</div><h2 class="text-xl font-bold text-gray-900 dark:text-white">Not available for RSVP here</h2><p class="text-gray-500 dark:text-gray-400 mt-2">' +
                    (data.message ? escapeHtml(String(data.message)) : 'This event is not listed in the member portal.') +
                    '</p><p class="text-sm text-gray-400 dark:text-gray-500 mt-4">If you think this is a mistake, ask your organization to set the event to <strong>Public</strong> in the admin event editor (Basics step).</p></div>';
            } else {
                document.getElementById('event-container').innerHTML = 
                    '<div class="text-center py-24 bento-card"><div class="text-4xl mb-4">😕</div><h2 class="text-xl font-bold text-gray-900 dark:text-white">Event Not Found</h2><p class="text-gray-500 dark:text-gray-400 mt-2">The event you are looking for might have been moved or cancelled.</p></div>';
            }
        } catch (error) {
            console.error('Error:', error);
            document.getElementById('event-container').innerHTML = 
                '<div class="text-center py-24 bento-card text-red-500">Error loading event details. Please try refreshing.</div>';
        }
    }

    function isTieredHeadcountEvent(ev) {
        return ev && ev.pricing_model === 'headcount_tier' && Array.isArray(ev.headcount_pricing_tiers) && ev.headcount_pricing_tiers.length > 0;
    }
    function minTierPackagePrice(ev) {
        if (!isTieredHeadcountEvent(ev)) return null;
        return Math.min(...ev.headcount_pricing_tiers.map(t => parseFloat(t.price || 0)));
    }
    function quoteTierTotalForHeads(ev, heads) {
        heads = Math.max(1, parseInt(heads, 10) || 1);
        if (!isTieredHeadcountEvent(ev)) return null;
        for (let i = 0; i < ev.headcount_pricing_tiers.length; i++) {
            const t = ev.headcount_pricing_tiers[i];
            const mn = parseInt(t.min, 10);
            const mx = (t.max === null || t.max === undefined || t.max === '') ? Infinity : parseInt(t.max, 10);
            if (heads >= mn && heads <= mx) {
                return { ok: true, amount: parseFloat(t.price) };
            }
        }
        return { ok: false };
    }

    function formatEventPeopleCards(people) {
        const list = Array.isArray(people) ? people : [];
        if (!list.length) return '';
        return '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' + list.map(function (p) {
            const name = (p && p.display_name) ? String(p.display_name) : '';
            const title = (p && p.title) ? String(p.title) : '';
            const img = (p && p.image_url) ? String(p.image_url) : '';
            const initial = name ? escapeHtml(name.charAt(0)) : '?';
            const avatar = img
                ? '<img src="' + escapeHtml(img) + '" alt="' + escapeHtml(name || 'Photo') + '" class="w-16 h-16 rounded-xl object-cover shrink-0" width="64" height="64">'
                : '<div class="w-16 h-16 rounded-xl bg-indigo-100 dark:bg-indigo-500/15 shrink-0 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-lg" aria-hidden="true">' + initial + '</div>';
            return '<article class="flex gap-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-gray-50/50 p-4">' +
                avatar +
                '<div class="min-w-0">' +
                '<h3 class="font-bold text-gray-900 dark:text-white">' + escapeHtml(name) + '</h3>' +
                (title ? '<p class="text-sm text-gray-600 dark:text-gray-300 mt-0.5">' + escapeHtml(title) + '</p>' : '') +
                '</div></article>';
        }).join('') + '</div>';
    }

    function formatSeriesSessionLabel(s) {
        const raw = s.event_date_formatted || s.event_date || '';
        const parts = String(raw).split('-').map(Number);
        let d;
        if (parts.length === 3 && !parts.some(isNaN)) {
            d = new Date(parts[0], parts[1] - 1, parts[2]);
        } else {
            d = new Date(raw);
        }
        const dateStr = isNaN(d.getTime()) ? raw : d.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
        let timeStr = '';
        if (s.start_time) {
            const [h, min] = String(s.start_time).split(':').map(Number);
            timeStr = new Date(2000, 0, 1, h, min).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        }
        return timeStr ? (dateStr + ' · ' + timeStr) : dateStr;
    }

    /** Treat API flags as booleans (PDO/JSON may use 0/1 or "0"/"1"). */
    function portalTruthyFlag(v) {
        if (v === true || v === 1) return true;
        if (v === false || v === 0 || v === null || v === undefined) return false;
        if (typeof v === 'string') {
            const t = v.trim().toLowerCase();
            return t === '1' || t === 'true' || t === 'yes';
        }
        return false;
    }

    let portalSaleCountdownTimer = null;
    function clearPortalSaleCountdownTimer() {
        if (portalSaleCountdownTimer !== null) {
            clearInterval(portalSaleCountdownTimer);
            portalSaleCountdownTimer = null;
        }
    }
    function formatPortalSaleCountdownRemaining(ms) {
        if (ms <= 0) return '0s';
        const totalSec = Math.floor(ms / 1000);
        const days = Math.floor(totalSec / 86400);
        const h = Math.floor((totalSec % 86400) / 3600);
        const m = Math.floor((totalSec % 3600) / 60);
        const s = totalSec % 60;
        const parts = [];
        if (days > 0) parts.push(days + 'd');
        if (days > 0 || h > 0) parts.push(h + 'h');
        parts.push(m + 'm');
        parts.push(s + 's');
        return parts.join(' ');
    }
    function initPortalSaleCountdownTimer() {
        clearPortalSaleCountdownTimer();
        const root = document.getElementById('portal-sale-countdown-root');
        if (!root) return;
        const targetStr = root.getAttribute('data-target-at');
        const live = document.getElementById('portal-sale-countdown-live');
        if (!targetStr || !live) return;
        const targetMs = Date.parse(targetStr);
        if (Number.isNaN(targetMs)) return;
        function tick() {
            const left = targetMs - Date.now();
            if (left <= 0) {
                live.textContent = 'Updating…';
                clearPortalSaleCountdownTimer();
                loadEvent();
                return;
            }
            live.textContent = formatPortalSaleCountdownRemaining(left);
        }
        tick();
        portalSaleCountdownTimer = setInterval(tick, 1000);
    }

    function displayEvent(event) {
        clearPortalSaleCountdownTimer();
        // Parse date as local calendar date to avoid timezone shift (new Date('YYYY-MM-DD') is UTC midnight)
        const dateForDisplay = (function() {
            if (event.event_date_formatted) {
                const [y, m, d] = event.event_date_formatted.split('-').map(Number);
                return new Date(y, m - 1, d);
            }
            const parts = (event.event_date || '').split('-');
            if (parts.length === 3) {
                const [y, m, d] = parts.map(Number);
                return new Date(y, m - 1, d);
            }
            return new Date(event.event_date);
        })();
        const dateStr = dateForDisplay.toLocaleDateString('en-US', { 
            weekday: 'long',
            month: 'long', 
            day: 'numeric', 
            year: 'numeric' 
        });
        const timeStr = event.start_time ? (function() {
            const [h, min] = event.start_time.split(':').map(Number);
            return new Date(2000, 0, 1, h, min).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        })() : '';
        const endTimeStr = event.end_time ? (function() {
            const [h, min] = event.end_time.split(':').map(Number);
            return new Date(2000, 0, 1, h, min).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
        })() : '';

        const isFull = event.is_full || false;
        const availableSpots = event.available_spots !== null ? event.available_spots : null;
        const hasTicketTypes = event.ticket_types && event.ticket_types.length > 0;
        const ticketTypesPaid = hasTicketTypes && event.ticket_types.some(tt => parseFloat(tt.price || 0) > 0);
        const isTieredPricing = isTieredHeadcountEvent(event);
        const tierMinPackage = isTieredPricing ? minTierPackagePrice(event) : null;
        const tierMinValid = tierMinPackage != null && !isNaN(tierMinPackage);
        const isPaid = ticketTypesPaid || parseFloat(event.ticket_price || 0) > 0 || isTieredPricing;
        const sessionMode = event.session_registration_mode || 'independent';
        const seriesSessions = Array.isArray(event.series_sessions) ? event.series_sessions : [];
        const portalSeriesState = event.portal_series_state || 'none';
        const multiSessionSeries = seriesSessions.length > 1;
        const multiSeriesRules = multiSessionSeries && (sessionMode === 'choose_one' || sessionMode === 'all_sessions');

        let hasRSVP = !!(event.user_rsvp && event.user_rsvp.status === 'yes');
        if (multiSeriesRules && sessionMode === 'all_sessions') {
            hasRSVP = portalSeriesState === 'going';
        } else if (multiSeriesRules && sessionMode === 'choose_one') {
            hasRSVP = portalSeriesState === 'going';
        }

        const showGoingOther = !!(isLoggedIn && multiSeriesRules && sessionMode === 'choose_one' && portalSeriesState === 'going_other');
        const showPartialAll = !!(isLoggedIn && multiSeriesRules && sessionMode === 'all_sessions' && portalSeriesState === 'partial');

        const rsvpClosedOnline = !!event.registration_closed_online;
        const eligibilityBlocked = !!(isLoggedIn && event.eligibility && event.eligibility.ok === false);

        let blockRsvpForFull = isFull;
        if (multiSeriesRules && sessionMode === 'choose_one') {
            blockRsvpForFull = seriesSessions.length > 0 && seriesSessions.every(s => s.is_full);
        }
        if (multiSeriesRules && sessionMode === 'all_sessions') {
            blockRsvpForFull = seriesSessions.some(s => s.is_full);
        }

        const showRsvpCTA = isLoggedIn && !eligibilityBlocked && !blockRsvpForFull && (!hasRSVP || showGoingOther || showPartialAll)
            && (!rsvpClosedOnline || showGoingOther || showPartialAll);
        let primaryRsvpLabel = isPaid
            ? (hasTicketTypes ? 'Choose Tickets' : (isTieredPricing ? (tierMinValid ? ('Pay from $' + tierMinPackage.toFixed(2)) : 'Pay (by group size)') : ('Pay $' + parseFloat(event.ticket_price || 0).toFixed(2))))
            : (multiSeriesRules && sessionMode === 'all_sessions' && !hasRSVP && !showPartialAll ? 'Register for all sessions' : 'Claim Free Spot');
        if (showGoingOther) primaryRsvpLabel = isPaid ? primaryRsvpLabel : 'Change session';
        if (showPartialAll) primaryRsvpLabel = isPaid ? primaryRsvpLabel : 'Complete registration';

        const seriesInfoHtml = multiSessionSeries ? `
            <div class="rounded-xl border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50/80 px-3 py-2 text-xs text-indigo-900 space-y-2">
                ${sessionMode === 'choose_one' ? '<p class="font-semibold">Pick one session</p><p class="text-indigo-800/90 mt-0.5">Choose a single date. You can only be registered for one session in this series.</p>' : ''}
                ${sessionMode === 'all_sessions' ? '<p class="font-semibold">All sessions — one RSVP</p><p class="text-indigo-800/90 mt-0.5">One RSVP registers you for every session listed below.</p>' : ''}
                ${sessionMode === 'independent' ? '<p class="font-semibold">Multiple sessions</p><p class="text-indigo-800/90 mt-0.5">RSVPs are <strong>per date</strong>. This page is for the session shown above; open another date to RSVP there separately.</p>' : ''}
            </div>
        ` : '';

        const tscRaw = event.ticket_sale_countdown;
        const tsc = tscRaw && typeof tscRaw === 'object' ? tscRaw : null;
        const saleCountdownHtml = tsc && tsc.target_at && tsc.headline
            ? `<div id="portal-sale-countdown-root" class="rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50/90 px-4 py-3" data-target-at="${escapeHtml(String(tsc.target_at))}" role="region" aria-labelledby="portal-sale-countdown-heading">
                    <p id="portal-sale-countdown-heading" class="text-xs font-bold text-amber-900 uppercase tracking-wide">${escapeHtml(String(tsc.headline))}</p>
                    <p class="text-lg font-black text-amber-950 mt-1 tabular-nums tracking-tight" id="portal-sale-countdown-live" aria-live="polite"></p>
                    ${tsc.detail ? `<p class="text-xs text-amber-900/80 mt-1">${escapeHtml(String(tsc.detail))}</p>` : ''}
               </div>`
            : '';

        const seriesSessionsListInnerBody = multiSessionSeries ? `
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                            <svg width="20" height="20" class="w-5 h-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            Sessions in this series
                        </h2>
                        <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">${sessionMode === 'all_sessions' ? 'You will be registered for all of these when you RSVP.' : sessionMode === 'choose_one' ? 'Pick one session when you RSVP (or below).' : 'Each date has its own RSVP — open a row to register for that day.'}</p>
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700 border-t border-b border-gray-200 dark:border-gray-700">
                            ${seriesSessions.map(s => {
                                const isCurrent = String(s.id) === String(event.id);
                                const label = escapeHtml(formatSeriesSessionLabel(s));
                                return `<li class="flex items-center justify-between gap-3 py-3.5 text-sm ${isCurrent ? 'bg-indigo-50/50 -mx-2 px-2 rounded-lg' : ''}">
                                    <span class="font-medium text-gray-900 dark:text-white min-w-0">${label}${isCurrent ? ' <span class="text-indigo-600 dark:text-indigo-300 font-bold">· this page</span>' : ''}</span>
                                    ${isCurrent ? '<span class="text-[10px] font-bold text-indigo-600 dark:text-indigo-300 uppercase tracking-wide shrink-0">Viewing</span>' : `<a href="${baseUrl}/portal/event-details.php?id=${s.id}" class="text-indigo-600 dark:text-indigo-300 font-bold text-xs hover:underline shrink-0">Open session</a>`}
                                </li>`;
                            }).join('')}
                        </ul>
        ` : '';

        const seriesSessionsListHtml = multiSessionSeries ? `<div class="pt-8 mt-8 border-t border-gray-200 dark:border-gray-700">${seriesSessionsListInnerBody}</div>` : '';

        const guestRsvpVisibilityOk = event.guest_rsvp_portal_allowed !== false;
        const allowGuestRsvp = portalTruthyFlag(event.allow_guest_rsvp) && guestRsvpVisibilityOk;
        const allowBringGuests = portalTruthyFlag(event.allow_bring_guests);

        const speakersList = Array.isArray(event.speakers) ? event.speakers : [];
        const organisersList = Array.isArray(event.organisers) ? event.organisers : [];
        let eventPeopleHtml = '';
        if (speakersList.length || organisersList.length) {
            const blocks = [];
            if (speakersList.length) {
                blocks.push('<h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2"><svg width="20" height="20" class="w-5 h-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"></path></svg>Speakers</h2>' + formatEventPeopleCards(speakersList));
            }
            if (organisersList.length) {
                blocks.push('<h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2"><svg width="20" height="20" class="w-5 h-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>Organisers</h2>' + formatEventPeopleCards(organisersList));
            }
            eventPeopleHtml = '<div class="space-y-10 pt-8 mt-8 border-t border-gray-200 dark:border-gray-700">' + blocks.join('') + '</div>';
        }

        const isPotluckEvent = !!(event.is_potluck);
        const potluckSignupsArr = Array.isArray(event.potluck_signups) ? event.potluck_signups : [];
        const potluckSessionNote = multiSessionSeries
            ? `<p class="text-sm text-gray-600 dark:text-gray-300 -mt-2 mb-3">Sign-ups for <span class="font-semibold text-gray-800 dark:text-gray-100">${escapeHtml(dateStr)}</span> only (this session).</p>`
            : '';
        let potluckPublicHtml = '';
        if (isPotluckEvent) {
            if (potluckSignupsArr.length) {
                potluckPublicHtml = `
                    <div class="potluck-public-wrap space-y-4 pt-8 mt-8 border-t border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                            <svg width="20" height="20" class="w-5 h-5 text-amber-600 dark:text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v12m-3-3h6M9 3h6l1 3h4v4H4V6h4l1-3z"></path></svg>
                            What people are bringing
                        </h2>
                        ${potluckSessionNote}
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700 border-t border-gray-200 dark:border-gray-700 text-sm text-gray-800 dark:text-gray-100">
                            ${potluckSignupsArr.map(s => {
                                const who = escapeHtml(s.contributor_name || 'Community member');
                                const bits = ['<li class="py-3.5"><span class="font-bold text-gray-900 dark:text-white">' + who + '</span><span class="text-gray-500 dark:text-gray-400"> · </span><span class="font-semibold text-gray-800 dark:text-gray-100">' + escapeHtml(s.category_label || '') + '</span>'];
                                if (s.item_note) bits.push('<span class="text-gray-600 dark:text-gray-300"> — ' + escapeHtml(s.item_note) + '</span>');
                                if (s.quantity != null && s.quantity !== '') bits.push(' <span class="text-gray-500 dark:text-gray-400">(Qty: ' + escapeHtml(String(s.quantity)) + ')</span>');
                                if (s.serving_side_label) bits.push(' <span class="text-gray-500 dark:text-gray-400">[' + escapeHtml(s.serving_side_label) + ']</span>');
                                bits.push('</li>');
                                return bits.join('');
                            }).join('')}
                        </ul>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Names match the member account used when they RSVP’d for this session.</p>
                    </div>`;
            } else {
                potluckPublicHtml = `
                    <div class="potluck-public-wrap text-sm text-gray-600 dark:text-gray-300 pt-8 mt-8 border-t border-gray-200 dark:border-gray-700">
                        ${potluckSessionNote}
                        <strong>Potluck:</strong> when people RSVP with what they are bringing, it will show up here with their name.
                    </div>`;
            }
        }

        const aboutSectionHtml = `
                    <div class="portal-event-about-card space-y-6">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white flex items-center gap-2">
                             <svg width="20" height="20" class="w-5 h-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                             About this Event
                        </h2>
                        <div class="prose max-w-none text-gray-600 dark:text-gray-300 leading-relaxed space-y-4">
                            ${sanitizeDescription(event.description) || '<p class="text-gray-400 dark:text-gray-500 italic">No description provided for this event.</p>'}
                        </div>
                    </div>
                    ${eventPeopleHtml}`;

        const useDetailTabs = !!(isPotluckEvent || multiSessionSeries);
        let mainDetailContentHtml = '';
        if (useDetailTabs) {
            const tabNavParts = [];
            tabNavParts.push(`<button type="button" role="tab" aria-selected="true" tabindex="0" data-tab="about" id="event-tab-btn-about"
                class="event-detail-tab px-4 py-3 text-sm font-bold border-b-2 -mb-px border-indigo-600 text-indigo-700 dark:text-indigo-300 transition-colors duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 rounded-t-lg">About</button>`);
            if (isPotluckEvent) {
                tabNavParts.push(`<button type="button" role="tab" aria-selected="false" tabindex="-1" data-tab="potluck" id="event-tab-btn-potluck"
                class="event-detail-tab px-4 py-3 text-sm font-bold border-b-2 -mb-px border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 transition-colors duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 rounded-t-lg">Potluck</button>`);
            }
            if (multiSessionSeries) {
                tabNavParts.push(`<button type="button" role="tab" aria-selected="false" tabindex="-1" data-tab="sessions" id="event-tab-btn-sessions"
                class="event-detail-tab px-4 py-3 text-sm font-bold border-b-2 -mb-px border-transparent text-gray-600 dark:text-gray-300 hover:text-gray-900 transition-colors duration-200 ease-out focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 rounded-t-lg">Sessions</button>`);
            }
            mainDetailContentHtml = `
                    <div id="event-detail-tab-root" class="event-detail-tab-root w-full">
                        <div class="event-detail-tablist flex flex-wrap gap-1 sm:gap-2 border-b border-gray-200 dark:border-gray-700" role="tablist" aria-label="Event sections">
                            ${tabNavParts.join('')}
                        </div>
                        <div class="event-detail-tab-panels pt-6 md:pt-8 pb-1">
                            <div role="tabpanel" id="event-tab-panel-about" data-panel="about" class="event-tab-panel space-y-8" aria-labelledby="event-tab-btn-about">
                                ${aboutSectionHtml}
                            </div>
                            ${isPotluckEvent ? `<div role="tabpanel" id="event-tab-panel-potluck" data-panel="potluck" class="event-tab-panel" aria-labelledby="event-tab-btn-potluck" hidden>
                                ${potluckPublicHtml}
                            </div>` : ''}
                            ${multiSessionSeries ? `<div role="tabpanel" id="event-tab-panel-sessions" data-panel="sessions" class="event-tab-panel" aria-labelledby="event-tab-btn-sessions" hidden>
                                ${seriesSessionsListInnerBody}
                            </div>` : ''}
                        </div>
                    </div>`;
        } else {
            mainDetailContentHtml = aboutSectionHtml + (isPotluckEvent ? potluckPublicHtml : '') + (multiSessionSeries ? seriesSessionsListHtml : '');
        }

        // Sticky Action Button (Mobile)
        let mobileAction = '';
        if (isLoggedIn) {
            if (hasRSVP && !showGoingOther && !showPartialAll) {
                mobileAction = '<button disabled class="w-full h-12 bg-green-500 text-white rounded-xl font-bold flex items-center justify-center gap-2"><svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>Going</button>';
            } else if (blockRsvpForFull) {
                mobileAction = '<button disabled class="w-full h-12 bg-gray-300 text-gray-500 dark:text-gray-400 rounded-xl font-bold">Sold Out</button>';
            } else if (rsvpClosedOnline && !showGoingOther && !showPartialAll) {
                mobileAction = '<button disabled class="w-full h-12 bg-gray-200 text-gray-600 dark:text-gray-300 rounded-xl font-bold">Online RSVP closed</button>';
            } else if (eligibilityBlocked && !hasRSVP && !showGoingOther && !showPartialAll) {
                mobileAction = '<button disabled class="w-full h-12 bg-rose-100 dark:bg-rose-500/15 text-rose-800 dark:text-rose-300 rounded-xl font-bold text-sm px-2">Requirements not met</button>';
            } else if (showRsvpCTA) {
                mobileAction = `<button id="rsvp-btn-mobile" class="w-full h-12 bg-indigo-600 text-white rounded-xl font-bold shadow-lg shadow-indigo-200 active:scale-95 transition-all transition-transform">${escapeHtml(primaryRsvpLabel)}</button>`;
            } else {
                mobileAction = '<button disabled class="w-full h-12 bg-gray-200 text-gray-500 dark:text-gray-400 rounded-xl font-bold">Unavailable</button>';
            }
        } else if (rsvpClosedOnline) {
            mobileAction = '<button disabled class="w-full h-12 bg-gray-200 text-gray-600 dark:text-gray-300 rounded-xl font-bold">Online RSVP closed</button>';
        } else if (allowGuestRsvp && !blockRsvpForFull && !rsvpClosedOnline) {
            const guestMobileLabel = isPaid ? 'Register as guest' : 'RSVP as Guest';
            mobileAction = `<button id="guest-rsvp-btn-mobile" class="w-full h-12 bg-indigo-600 text-white rounded-xl font-bold shadow-lg shadow-indigo-200">${escapeHtml(guestMobileLabel)}</button>`;
        } else {
            const loginLabel = isPaid ? 'Log in to pay and register' : 'Log In to RSVP';
            mobileAction = `<a href="${baseUrl}/portal/login.php?redirect=${encodeURIComponent(window.location.href)}" class="w-full h-12 bg-indigo-600 text-white rounded-xl font-bold flex items-center justify-center shadow-lg shadow-indigo-200">${escapeHtml(loginLabel)}</a>`;
        }

        const html = `
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left Column: Details -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Hero Photo / Gradient -->
                    <div class="relative overflow-hidden rounded-[2.5rem] shadow-2xl h-64 md:h-80 group">
                        ${event.banner_image_url ? `
                            <img src="${escapeHtml(event.banner_image_url)}" alt="${escapeHtml(event.title)}" class="absolute inset-0 w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
                        ` : `
                            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-500"></div>
                            <div class="absolute inset-0 opacity-20 group-hover:scale-110 transition-transform duration-700 bg-[url('https://www.transparenttextures.com/patterns/cubes.png')]"></div>
                        `}
                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                        
                        <div class="absolute bottom-0 left-0 right-0 p-8">
                            <div class="flex flex-wrap gap-2 mb-3">
                                ${event.is_recurring ? '<span class="px-3 py-1 rounded-full bg-purple-500/90 backdrop-blur-md text-white text-xs font-bold uppercase tracking-wider border border-white/30">Recurring</span>' : ''}
                                ${event.category ? `
                                    <span class="px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-white text-xs font-bold uppercase tracking-wider border border-white/30" ${event.category_color ? `style="background-color: ${event.category_color}40; border-color: ${event.category_color}60;"` : ''}>
                                        ${escapeHtml(event.category)}
                                    </span>
                                ` : ''}
                                ${isPaid ? '<span class="px-3 py-1 rounded-full bg-green-500 text-white text-xs font-bold uppercase tracking-wider shadow-sm">Paid</span>' : '<span class="px-3 py-1 rounded-full bg-blue-500 text-white text-xs font-bold uppercase tracking-wider shadow-sm">Free</span>'}
                            </div>
                            <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold text-white leading-tight drop-shadow-md">
                                ${escapeHtml(event.title)}
                            </h1>
                        </div>
                    </div>

                    <!-- Meta Information Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Date & Time Card -->
                        <div class="bento-card flex items-start gap-4 p-5 hover:border-indigo-200 transition-colors">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center shrink-0">
                                <svg width="24" height="24" class="w-6 h-6 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">When</h3>
                                <p class="font-bold text-gray-900 dark:text-white leading-tight">${dateStr}</p>
                                ${timeStr ? `<p class="text-sm text-gray-500 dark:text-gray-400 mt-1">${timeStr}${endTimeStr ? ' — ' + endTimeStr : ''}</p>` : ''}
                            </div>
                        </div>

                        <!-- Location Card -->
                        <div class="bento-card flex items-start gap-4 p-5 hover:border-indigo-200 transition-colors">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center shrink-0">
                                <svg width="24" height="24" class="w-6 h-6 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                ${event.is_virtual
                                    ? (() => {
                                        const loc = (event.location || '').trim();
                                        const joinHref = loc ? (loc.match(/^https?:\/\//i) ? loc : 'https://' + loc) : '#';
                                        return `<h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">Virtual event</h3>
                                        <p class="font-bold text-gray-900 dark:text-white leading-tight break-words mb-1">Join online</p>
                                        ${loc ? `<a href="${escapeHtml(joinHref)}" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 dark:text-indigo-300 font-bold hover:underline inline-block break-all">${escapeHtml(loc)}</a>` : '<span class="text-gray-500 dark:text-gray-400">Join link TBA</span>'}`;
                                    })()
                                    : `<h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">Where</h3>
                                        <p class="font-bold text-gray-900 dark:text-white leading-tight break-words" title="${escapeHtml(event.location || 'TBA')}">
                                            ${escapeHtml(event.location || 'TBA')}
                                        </p>
                                        <a href="https://maps.google.com/?q=${encodeURIComponent(event.location || '')}" target="_blank" class="text-xs text-indigo-600 dark:text-indigo-300 font-bold hover:underline mt-1 inline-block">View on Maps</a>`
                                }
                            </div>
                        </div>
                    </div>

                    ${mainDetailContentHtml}

                    <!-- Integrations Section -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <!-- Calendar Widget -->
                        <div class="bento-card p-6">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                                <svg width="20" height="20" class="w-5 h-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                Save to Calendar
                            </h3>
                            <div class="grid grid-cols-2 gap-2">
                                <a href="${apiBase}calendar/google/${event.id}" target="_blank" class="flex items-center justify-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-800 hover:bg-indigo-50 text-xs font-bold text-gray-700 dark:text-gray-300 hover:text-indigo-600 border border-gray-100 dark:border-gray-800 rounded-xl transition-all">
                                    Google
                                </a>
                                <a href="${apiBase}calendar/event/${event.id}.ics" class="flex items-center justify-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-800 hover:bg-indigo-50 text-xs font-bold text-gray-700 dark:text-gray-300 hover:text-indigo-600 border border-gray-100 dark:border-gray-800 rounded-xl transition-all">
                                    Outlook/iCal
                                </a>
                            </div>
                        </div>

                        <!-- Share Widget -->
                        <div class="bento-card p-6">
                            <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                                <svg width="18" height="18" class="w-4.5 h-4.5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"></path></svg>
                                Spread the Word
                            </h3>
                            <div class="flex flex-wrap gap-2">
                                <button onclick="shareEvent('facebook')" class="p-2.5 bg-blue-50 dark:bg-blue-500/15 text-blue-600 dark:text-blue-300 rounded-xl hover:bg-blue-600 hover:text-white transition-all">
                                    <svg width="20" height="20" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                                </button>
                                <button onclick="shareEvent('twitter')" class="p-2.5 bg-sky-50 dark:bg-sky-500/15 text-sky-500 rounded-xl hover:bg-sky-500 hover:text-white transition-all">
                                    <svg width="20" height="20" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.84 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg>
                                </button>
                                <button onclick="shareEvent('email')" class="p-2.5 bg-purple-50 dark:bg-purple-500/15 text-purple-600 dark:text-purple-300 rounded-xl hover:bg-purple-600 hover:text-white transition-all">
                                    <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                                </button>
                                <button onclick="copyLink()" class="p-2.5 bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded-xl hover:bg-gray-200 transition-all" title="Copy Link">
                                    <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"></path></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: RSVP Card (Desktop Sticky) -->
                <div class="lg:sticky lg:top-24 h-fit">
                    <div class="bento-card overflow-hidden !p-0 shadow-xl border-indigo-100 dark:border-indigo-500/30">
                        <div class="p-8 space-y-6">
                            ${saleCountdownHtml}
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-sm font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Entry Price</h3>
                                    <p class="text-3xl font-black text-gray-900 dark:text-white">
                                        ${!isPaid ? 'Free' : hasTicketTypes ? ('From $' + (event.ticket_types.filter(tt => parseFloat(tt.price || 0) > 0).length ? Math.min(...event.ticket_types.map(tt => parseFloat(tt.price || 0)).filter(p => p > 0)).toFixed(2) : '0.00')) : isTieredPricing ? (tierMinValid ? ('From $' + tierMinPackage.toFixed(2)) : 'By group size') : ('$' + parseFloat(event.ticket_price || 0).toFixed(2))}
                                    </p>
                                    ${isPaid && isTieredPricing && !hasTicketTypes ? '<p class="text-xs font-semibold text-gray-500 dark:text-gray-400 mt-1">Total depends on how many people you register (including you).</p>' : ''}
                                </div>
                                <div class="w-14 h-14 rounded-full bg-green-50 dark:bg-green-500/15 flex items-center justify-center text-2xl">
                                    ${isPaid ? '💰' : '🎁'}
                                </div>
                            </div>

                            <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

                            ${seriesInfoHtml}

                            <div class="space-y-4">
                                ${availableSpots !== null ? `
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400 font-medium">Availability</span>
                                        <span class="font-bold ${isFull ? 'text-red-500' : 'text-green-600 dark:text-green-300'}">
                                            ${isFull ? 'Sold Out' : availableSpots + ' spots left'}
                                        </span>
                                    </div>
                                    <div class="w-full h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                                        <div class="h-full bg-indigo-600 rounded-full" style="width: ${Math.min(100, Math.max(0, (1 - availableSpots / event.capacity) * 100))}%"></div>
                                    </div>
                                ` : `
                                    <div class="flex items-center justify-between text-sm">
                                        <span class="text-gray-500 dark:text-gray-400 font-medium">Status</span>
                                        <span class="font-bold text-indigo-600 dark:text-indigo-300 uppercase tracking-wider text-xs">Open for RSVP</span>
                                    </div>
                                `}
                            </div>

                            <div class="pt-2">
                                ${isLoggedIn ? `
                                    ${hasRSVP && !showGoingOther && !showPartialAll ? `
                                        <div class="bg-green-50 dark:bg-green-500/15 border border-green-100 dark:border-green-500/30 rounded-2xl p-4 text-center">
                                            <div class="w-10 h-10 bg-green-500 text-white rounded-full flex items-center justify-center mx-auto mb-2 shadow-sm">
                                                <svg width="24" height="24" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            </div>
                                            <p class="text-green-700 dark:text-green-300 font-bold">You're confirmed!</p>
                                            <p class="text-xs text-green-600/70 mt-1">We'll see you there.</p>
                                        </div>
                                    ` : ''}
                                    ${showGoingOther ? `
                                        <div class="bg-amber-50 dark:bg-amber-500/15 border border-amber-100 dark:border-amber-500/30 rounded-2xl p-4 text-center mb-3">
                                            <p class="text-amber-900 font-bold text-sm">You're registered for another session in this series.</p>
                                            <p class="text-xs text-amber-800/80 mt-1">You can switch to this date below.</p>
                                        </div>
                                    ` : ''}
                                    ${showPartialAll ? `
                                        <div class="bg-indigo-50 dark:bg-indigo-500/15 border border-indigo-100 dark:border-indigo-500/30 rounded-2xl p-4 text-center mb-3">
                                            <p class="text-indigo-900 font-bold text-sm">You're registered for some sessions.</p>
                                            <p class="text-xs text-indigo-800/80 mt-1">Complete registration to add the rest.</p>
                                        </div>
                                    ` : ''}
                                    ${rsvpClosedOnline && !hasRSVP && !showGoingOther && !showPartialAll ? `
                                        <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-4 text-center mb-3">
                                            <p class="text-gray-800 dark:text-gray-100 font-bold text-sm">Online RSVP is closed</p>
                                            <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">Walk-ins may still be welcome at the door if space allows.</p>
                                        </div>
                                    ` : ''}
                                    ${eligibilityBlocked && !hasRSVP && !showGoingOther && !showPartialAll ? `
                                        <div class="rounded-2xl border border-rose-100 dark:border-rose-500/30 bg-rose-50 dark:bg-rose-500/15 p-4 text-center mb-3">
                                            <p class="text-rose-900 font-bold text-sm">${escapeHtml(event.eligibility && event.eligibility.message ? event.eligibility.message : 'You do not meet the requirements for this event.')}</p>
                                            <p class="text-xs text-rose-800/80 mt-1">Update your profile (date of birth or gender) under your account, or contact the organization.</p>
                                        </div>
                                    ` : ''}
                                    ${showRsvpCTA ? `
                                        <button id="rsvp-btn-desktop" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-bold shadow-lg shadow-indigo-100 active:scale-95 transition-all text-lg mb-3">
                                            ${isPaid ? 'Secure Ticket' : escapeHtml(primaryRsvpLabel)}
                                        </button>
                                        <p class="text-[10px] text-gray-400 dark:text-gray-500 text-center uppercase tracking-widest font-bold">
                                            ${isPaid ? 'Payments secured by Stripe' : 'Quick one-click RSVP'}
                                        </p>
                                    ` : ''}
                                    ${!hasRSVP && !showGoingOther && !showPartialAll && blockRsvpForFull ? `
                                        <div class="text-center text-sm text-gray-500 dark:text-gray-400 font-medium py-2">Sold out</div>
                                    ` : ''}
                                ` : rsvpClosedOnline ? `
                                    <div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-4 text-center mb-3">
                                        <p class="text-gray-800 dark:text-gray-100 font-bold text-sm">Online RSVP is closed</p>
                                        <p class="text-xs text-gray-600 dark:text-gray-300 mt-1">Walk-ins may still be welcome at the door if space allows.</p>
                                    </div>
                                ` : allowGuestRsvp && !blockRsvpForFull && !rsvpClosedOnline ? `
                                    <button id="guest-rsvp-btn-desktop" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-bold shadow-lg shadow-indigo-100 active:scale-95 transition-all text-lg mb-3">
                                        ${isPaid ? 'Register as guest' : 'RSVP as Guest'}
                                    </button>
                                    <p class="text-[10px] text-gray-400 dark:text-gray-500 text-center uppercase tracking-widest font-bold font-inter">
                                        ${isPaid ? 'Pay with card as a guest — no password required. We will email you a link to set up your account.' : 'No account? RSVP once, we\'ll email you to complete your account.'}
                                    </p>
                                    <a href="${baseUrl}/portal/login.php?redirect=${encodeURIComponent(window.location.href)}" class="block text-center text-sm text-indigo-600 dark:text-indigo-300 hover:underline mt-2">Already have an account? Log in</a>
                                ` : `
                                    <a href="${baseUrl}/portal/login.php?redirect=${encodeURIComponent(window.location.href)}" class="w-full py-4 bg-gray-900 hover:bg-black text-white rounded-2xl font-bold flex items-center justify-center shadow-lg active:scale-95 transition-all mb-3">
                                        ${isPaid ? 'Log in to pay and register' : 'Log In to RSVP'}
                                    </a>
                                    <p class="text-[10px] text-gray-400 dark:text-gray-500 text-center uppercase tracking-widest font-bold font-inter">
                                        ${isPaid ? 'Payment is required for this event.' : 'Join over 2,000+ members'}
                                    </p>
                                `}
                            </div>
                        </div>

                        <!-- Mini Footer in Card -->
                        <div class="bg-gray-50 dark:bg-gray-800 border-t border-gray-100 dark:border-gray-800 p-4 font-inter">
                            <div class="flex items-center gap-3">
                                <div class="flex -space-x-2">
                                    <div class="w-6 h-6 rounded-full bg-indigo-100 dark:bg-indigo-500/15 border-2 border-white ring-1 ring-indigo-50"></div>
                                    <div class="w-6 h-6 rounded-full bg-purple-100 dark:bg-purple-500/15 border-2 border-white ring-1 ring-purple-50"></div>
                                    <div class="w-6 h-6 rounded-full bg-pink-100 dark:bg-pink-500/15 border-2 border-white ring-1 ring-pink-50"></div>
                                </div>
                                <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500">Join other attendees</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mobile Action Bar (Sticky Bottom) -->
            <div class="lg:hidden fixed bottom-0 left-0 right-0 p-4 bg-white/80 backdrop-blur-xl border-t border-gray-100 dark:border-gray-800 z-50 animate-fade-in" style="margin-bottom: var(--bottom-nav-height, 64px)">
               <div class="max-w-md mx-auto flex items-center gap-4 font-inter">
                   <div class="shrink-0">
                       <p class="text-[10px] font-black text-gray-400 dark:text-gray-500 uppercase tracking-widest">Entry</p>
                       <p class="text-lg font-black text-indigo-600 dark:text-indigo-300 leading-none">${!isPaid ? 'Free' : hasTicketTypes ? 'Options' : isTieredPricing ? (tierMinValid ? ('From $' + tierMinPackage.toFixed(2)) : 'Tiers') : ('$' + parseFloat(event.ticket_price || 0).toFixed(2))}</p>
                   </div>
                   <div class="flex-1">
                       ${mobileAction}
                   </div>
               </div>
            </div>
        `;

        document.getElementById('event-container').innerHTML = html;
        window.currentEvent = event;
        initEventDetailsTabs();
        initPortalSaleCountdownTimer();

        // Add RSVP handlers
        if (isLoggedIn && showRsvpCTA) {
            const rsvpDesktop = document.getElementById('rsvp-btn-desktop');
            const rsvpMobile = document.getElementById('rsvp-btn-mobile');
            
            if (rsvpDesktop) rsvpDesktop.addEventListener('click', handleRSVP);
            if (rsvpMobile) rsvpMobile.addEventListener('click', handleRSVP);
        }
        if (!isLoggedIn && allowGuestRsvp && !blockRsvpForFull && !rsvpClosedOnline) {
            const guestDesktop = document.getElementById('guest-rsvp-btn-desktop');
            const guestMobile = document.getElementById('guest-rsvp-btn-mobile');
            if (guestDesktop) guestDesktop.addEventListener('click', () => showGuestRSVPModal(event));
            if (guestMobile) guestMobile.addEventListener('click', () => showGuestRSVPModal(event));
        }
    }

    function buildDateOfBirthPartsHtml(prefix, labelText) {
        const months = [
            ['1', 'January'], ['2', 'February'], ['3', 'March'], ['4', 'April'],
            ['5', 'May'], ['6', 'June'], ['7', 'July'], ['8', 'August'],
            ['9', 'September'], ['10', 'October'], ['11', 'November'], ['12', 'December']
        ];
        const monthOpts = months.map(m => '<option value="' + m[0] + '">' + m[1] + '</option>').join('');
        const currentYear = new Date().getFullYear();
        const fieldClass = 'w-full border border-gray-300 rounded-xl px-3 py-2 text-sm bg-white dark:bg-gray-800 dark:border-gray-600';
        return '<div class="space-y-1" data-dob-group="' + escapeHtml(prefix) + '">' +
            '<p class="block text-sm font-medium text-gray-700 dark:text-gray-300">' + escapeHtml(labelText) + ' *</p>' +
            '<div class="grid grid-cols-3 gap-2">' +
            '<div><label class="sr-only" for="' + prefix + '-month">Month</label>' +
            '<select id="' + prefix + '-month" class="' + fieldClass + '" required aria-label="Birth month">' +
            '<option value="">Month</option>' + monthOpts + '</select></div>' +
            '<div><label class="sr-only" for="' + prefix + '-day">Day</label>' +
            '<input type="number" id="' + prefix + '-day" min="1" max="31" placeholder="Day" inputmode="numeric" class="' + fieldClass + '" required aria-label="Birth day"></div>' +
            '<div><label class="sr-only" for="' + prefix + '-year">Year</label>' +
            '<input type="number" id="' + prefix + '-year" min="1900" max="' + currentYear + '" placeholder="Year" inputmode="numeric" class="' + fieldClass + '" required aria-label="Birth year"></div>' +
            '</div></div>';
    }

    function readDateOfBirthIso(modal, prefix) {
        const month = parseInt((modal.querySelector('#' + prefix + '-month') || {}).value, 10);
        const day = parseInt((modal.querySelector('#' + prefix + '-day') || {}).value, 10);
        const year = parseInt((modal.querySelector('#' + prefix + '-year') || {}).value, 10);
        if (!month || !day || !year) return null;
        const dt = new Date(year, month - 1, day);
        if (dt.getFullYear() !== year || dt.getMonth() !== month - 1 || dt.getDate() !== day) {
            return { invalid: true };
        }
        if (dt > new Date()) return { invalid: true };
        return year + '-' + String(month).padStart(2, '0') + '-' + String(day).padStart(2, '0');
    }

    function buildGuestEligibilityFieldsHtml(event) {
        const r = (event && event.restriction) ? event.restriction : {};
        if (!r.enabled) return '';
        const needsDob = (parseInt(r.min_age, 10) || 0) > 0 || (parseInt(r.max_age, 10) || 0) > 0;
        const gr = String(r.gender_restriction || 'none').toLowerCase();
        const needsGender = gr && gr !== 'none';
        if (!needsDob && !needsGender) return '';
        const parts = [];
        if (needsDob) {
            let hint = 'Required to verify you meet this event\'s age requirement.';
            const minA = parseInt(r.min_age, 10) || 0;
            const maxA = parseInt(r.max_age, 10) || 0;
            if (minA > 0 && maxA > 0) hint += ' Ages ' + minA + '–' + maxA + '.';
            else if (minA > 0) hint += ' Minimum age ' + minA + '.';
            else if (maxA > 0) hint += ' Maximum age ' + maxA + '.';
            parts.push(buildDateOfBirthPartsHtml('guest-dob', 'Date of birth') +
                '<p class="text-xs text-amber-900/80">' + escapeHtml(hint) + '</p>');
        }
        if (needsGender) {
            const grLabel = gr.charAt(0).toUpperCase() + gr.slice(1);
            parts.push(
                '<div class="space-y-1">' +
                '<label class="block text-sm font-medium text-gray-700 dark:text-gray-300" for="guest-gender">Gender *</label>' +
                '<select id="guest-gender" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm bg-white dark:bg-gray-800" required>' +
                '<option value="">Select…</option>' +
                '<option value="male">Male</option>' +
                '<option value="female">Female</option>' +
                '<option value="other">Other</option>' +
                '</select>' +
                '<p class="text-xs text-amber-900/80">This event requires registrants to be ' + escapeHtml(grLabel) + '.</p>' +
                '</div>'
            );
        }
        return '<div class="rounded-xl border border-amber-200 dark:border-amber-500/30 bg-amber-50/90 p-3 space-y-3" role="group" aria-label="Eligibility verification">' +
            '<p class="text-xs font-semibold text-amber-950">Age &amp; eligibility</p>' +
            parts.join('') +
            '</div>';
    }

    function validateGuestEligibilityModal(modal, event) {
        const r = (event && event.restriction) ? event.restriction : {};
        if (!r.enabled) return null;
        const needsDob = (parseInt(r.min_age, 10) || 0) > 0 || (parseInt(r.max_age, 10) || 0) > 0;
        const gr = String(r.gender_restriction || 'none').toLowerCase();
        const needsGender = gr && gr !== 'none';
        if (needsDob) {
            const dobIso = readDateOfBirthIso(modal, 'guest-dob');
            if (!dobIso) return 'Please enter your full date of birth (month, day, and year).';
            if (dobIso.invalid) return 'Please enter a valid date of birth.';
        }
        const genderEl = modal.querySelector('#guest-gender');
        if (needsGender && (!genderEl || !genderEl.value)) {
            return 'Please select your gender.';
        }
        return null;
    }

    function showGuestRSVPModal(event) {
        const questions = (event && event.questions) ? event.questions : [];
        const questionsHtml = questions.map(q => wrapQuestionRow(q, buildQuestionHtml(q, 'gq_')));
        const sessionMode = (event && event.session_registration_mode) || 'independent';
        const seriesSessions = (event && Array.isArray(event.series_sessions)) ? event.series_sessions : [];
        const needsSessionPick = sessionMode === 'choose_one' && seriesSessions.length > 1;
        const guestEventId = String(event.id);
        const hasTicketTypes = event && event.ticket_types && event.ticket_types.length > 0;
        const ticketTypesPaid = hasTicketTypes && event.ticket_types.some(tt => parseFloat(tt.price || 0) > 0);
        const isTieredGuest = isTieredHeadcountEvent(event);
        const isPaid = ticketTypesPaid || parseFloat(event.ticket_price || 0) > 0 || isTieredGuest;
        let guestRsvpSeriesIntro = '';
        if (seriesSessions.length > 1) {
            if (sessionMode === 'all_sessions' && !needsSessionPick) {
                guestRsvpSeriesIntro = `<div class="rounded-xl border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50 dark:bg-indigo-500/15 px-3 py-2.5 text-sm text-indigo-900"><strong>All sessions:</strong> this guest RSVP registers you for all <strong>${seriesSessions.length}</strong> dates in this series.</div>`;
            } else if (sessionMode === 'independent' && !needsSessionPick) {
                const cur = seriesSessions.find(s => String(s.id) === guestEventId);
                const curLabel = cur ? formatSeriesSessionLabel(cur) : (event.event_date || '');
                guestRsvpSeriesIntro = `<div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2.5 text-sm text-gray-800 dark:text-gray-100"><strong>This session only:</strong> you are registering for <strong>${escapeHtml(curLabel)}</strong>. Other dates need a separate guest RSVP.</div>`;
            } else if (sessionMode === 'choose_one') {
                guestRsvpSeriesIntro = `<div class="rounded-xl border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50 dark:bg-indigo-500/15 px-3 py-2.5 text-sm text-indigo-900"><strong>Pick one session</strong> — choose the date you plan to attend.</div>`;
            }
        }
        const guestSessionsPickHtml = needsSessionPick ? `
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Select a session</p>
                    <div class="space-y-2 max-h-40 overflow-y-auto">
                        ${seriesSessions.map(s => `
                        <label class="flex items-center gap-3 p-2 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 ${s.is_full ? 'opacity-50 pointer-events-none' : ''}">
                            <input type="radio" name="guest-series-session-pick" value="${s.id}" class="guest-series-session-radio w-4 h-4 text-indigo-600 dark:text-indigo-300" ${String(s.id) === String(event.id) ? 'checked' : ''} ${s.is_full ? 'disabled' : ''}>
                            <span class="text-sm text-gray-900 dark:text-white">${escapeHtml(formatSeriesSessionLabel(s))}</span>
                            ${s.is_full ? '<span class="text-xs text-red-500">Full</span>' : ''}
                        </label>
                        `).join('')}
                    </div>
                </div>` : '';
        const guestTicketTypesHtml = (hasTicketTypes && isPaid) ? `
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Select tickets</p>
                    <div class="space-y-2">
                        ${buildTicketTypesSelectionHtml(event.ticket_types, { qtyClass: 'guest-rsvp-ticket-qty' })}
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">You will pay securely on the next step (Stripe).</p>
                </div>
                ` : '';
        const isPotluckGuestModal = !!(event && event.is_potluck);
        const potluckPartyGuestBlock = isPotluckGuestModal ? `
                <div class="space-y-3 rounded-xl border border-amber-100 dark:border-amber-500/30 bg-white dark:bg-gray-800 p-3">
                    <p class="text-xs font-semibold text-gray-800 dark:text-gray-100">Who is attending (your party)</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Include everyone coming with you on this RSVP.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Adults (incl. you) *</label>
                            <input type="number" id="guest-potluck-party-adults" min="1" max="50" value="1" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Children *</label>
                            <input type="number" id="guest-potluck-party-children" min="0" max="50" value="0" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                        </div>
                    </div>
                </div>
                <input type="hidden" id="guest-count" value="0">` : '';
        const guestCountBlock = !isPotluckGuestModal
            ? (((!hasTicketTypes || !isPaid) && portalTruthyFlag(event.allow_bring_guests))
                ? `
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Number of additional guests</label>
                    <input type="number" id="guest-count" min="0" max="10" value="0" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                </div>`
                : '<input type="hidden" id="guest-count" value="0">')
            : '';
        const guestTierEstimateSuffix = isPaid && isTieredGuest ? '<p id="guest-tier-estimate" class="text-sm text-indigo-700 dark:text-indigo-300 font-semibold min-h-[1.25rem]" aria-live="polite"></p>' : '';
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">${isPaid ? 'Register as guest' : 'RSVP as Guest'}</h3>
                <p class="text-sm text-gray-600 dark:text-gray-300">${isPaid ? 'Enter your details to pay and register. No password required — we will email you a receipt and a link to set up your account.' : 'Enter your details to register for this event. We\'ll email you a confirmation and a link to create an account for future events.'}</p>
                ${guestRsvpSeriesIntro}
                ${guestSessionsPickHtml}
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">First name *</label>
                    <input type="text" id="guest-first-name" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last name *</label>
                    <input type="text" id="guest-last-name" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                </div>
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email *</label>
                    <input type="email" id="guest-email" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required>
                </div>
                ${buildGuestEligibilityFieldsHtml(event)}
                ${guestTicketTypesHtml}
                ${potluckPartyGuestBlock}
                ${guestCountBlock}
                ${guestTierEstimateSuffix}
                ${potluckRsvpFormHtml(event, 'guest-potluck')}
                ${questions.length > 0 ? '<div class="space-y-3"><p class="text-sm font-medium text-gray-700 dark:text-gray-300">Additional Questions</p>' + questionsHtml.join('') + '</div>' : ''}
                ${buildWaiverBlockHtml(event && event.waiver)}
                <div class="flex gap-3 pt-4">
                    <button type="button" class="guest-modal-cancel flex-1 px-4 py-2 border border-gray-300 text-gray-700 dark:text-gray-300 rounded-xl font-medium hover:bg-gray-50 dark:hover:bg-gray-800/50">Cancel</button>
                    <button type="button" class="guest-modal-submit flex-1 px-4 py-2 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700">${isPaid ? 'Continue to payment' : 'Submit RSVP'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        bindPotluckFormHints(modal);
        bindTicketPackageExclusiveInputs(modal, '.guest-rsvp-ticket-qty');
        bindWaiverModal(modal, event && event.waiver);
        if (event && event.is_potluck) {
            bindPotluckBringingToggle(modal, 'guest-potluck');
        }
        evaluateConditionalVisibility(modal, questions);
        modal.addEventListener('change', () => evaluateConditionalVisibility(modal, questions));
        const guestTierEstEl = modal.querySelector('#guest-tier-estimate');
        if (guestTierEstEl) {
            function refreshGuestTierEst() {
                let heads;
                if (hasTicketTypes && isPaid) {
                    let t = 0;
                    modal.querySelectorAll('.guest-rsvp-ticket-qty').forEach(input => {
                        t += Math.max(0, parseInt(input.value, 10) || 0);
                    });
                    heads = t > 0 ? t : 1;
                } else if (event && event.is_potluck) {
                    const aEl = modal.querySelector('#guest-potluck-party-adults');
                    const cEl = modal.querySelector('#guest-potluck-party-children');
                    const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                    const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                    heads = adults + children;
                    const hgc = modal.querySelector('#guest-count');
                    if (hgc) hgc.value = String(Math.max(0, adults + children - 1));
                } else {
                    const gc = Math.min(10, Math.max(0, parseInt((modal.querySelector('#guest-count') || {}).value, 10) || 0));
                    heads = 1 + gc;
                }
                const q = quoteTierTotalForHeads(event, heads);
                if (q && q.ok) guestTierEstEl.textContent = 'Estimated total for ' + heads + ' attendee' + (heads === 1 ? '' : 's') + ': $' + q.amount.toFixed(2);
                else if (q && !q.ok) guestTierEstEl.textContent = 'This group size is not covered by a price tier. Try a different guest count or contact the organizer.';
                else guestTierEstEl.textContent = '';
            }
            modal.addEventListener('input', refreshGuestTierEst);
            modal.addEventListener('change', refreshGuestTierEst);
            refreshGuestTierEst();
        }
        modal.querySelector('.guest-modal-cancel').addEventListener('click', () => modal.remove());
        modal.querySelector('.guest-modal-submit').addEventListener('click', async () => {
            const firstName = (modal.querySelector('#guest-first-name') || {}).value || '';
            const lastName = (modal.querySelector('#guest-last-name') || {}).value || '';
            const email = (modal.querySelector('#guest-email') || {}).value || '';
            if (!firstName.trim() || !lastName.trim() || !email.trim()) { showErrorModal('Please fill in first name, last name, and email.'); return; }
            const guestEligErr = validateGuestEligibilityModal(modal, event);
            if (guestEligErr) { showErrorModal(guestEligErr); return; }
            evaluateConditionalVisibility(modal, questions);
            const questionAnswers = collectQuestionAnswers(modal, questions);
            if (!validateRequiredVisible(modal, questions)) { showErrorModal('Please answer all required questions.'); return; }
            const waiverErrGuest = validateWaiverInModal(modal, event && event.waiver);
            if (waiverErrGuest) { showErrorModal(waiverErrGuest); return; }
            let guestCount = 0;
            if (event && event.is_potluck) {
                const aEl = modal.querySelector('#guest-potluck-party-adults');
                const cEl = modal.querySelector('#guest-potluck-party-children');
                const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                guestCount = Math.min(10, Math.max(0, adults + children - 1));
            } else {
                guestCount = Math.min(10, Math.max(0, parseInt((modal.querySelector('#guest-count') || {}).value, 10) || 0));
            }
            let ticketSelections = [];
            if (hasTicketTypes && isPaid) {
                modal.querySelectorAll('.guest-rsvp-ticket-qty').forEach(input => {
                    const qty = Math.max(0, parseInt(input.value, 10) || 0);
                    if (qty > 0) ticketSelections.push({ ticket_type_id: parseInt(input.getAttribute('data-ticket-type-id'), 10), quantity: qty });
                });
                const totalTickets = ticketSelections.reduce((sum, t) => sum + t.quantity, 0);
                const totalAmount = ticketSelections.reduce((sum, t) => {
                    const tt = event.ticket_types.find(ty => ty.id === t.ticket_type_id);
                    return sum + (parseFloat(tt && tt.price ? tt.price : 0) * t.quantity);
                }, 0);
                if (totalTickets === 0 || totalAmount <= 0) {
                    showErrorModal('Please select at least one ticket.');
                    return;
                }
            }
            let submitGuestEventId = parseInt(event.id, 10);
            if (needsSessionPick) {
                const picked = modal.querySelector('.guest-series-session-radio:checked');
                if (!picked) {
                    showErrorModal('Please select a session.');
                    return;
                }
                if (picked.disabled) {
                    showErrorModal('That session is full.');
                    return;
                }
                submitGuestEventId = parseInt(picked.value, 10);
            }
            if (isTieredGuest) {
                let tierHeads = 1 + guestCount;
                if (hasTicketTypes && isPaid) {
                    let t = 0;
                    modal.querySelectorAll('.guest-rsvp-ticket-qty').forEach(input => {
                        t += Math.max(0, parseInt(input.value, 10) || 0);
                    });
                    tierHeads = t > 0 ? t : 1;
                } else if (event && event.is_potluck) {
                    const aEl = modal.querySelector('#guest-potluck-party-adults');
                    const cEl = modal.querySelector('#guest-potluck-party-children');
                    const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                    const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                    tierHeads = adults + children;
                }
                const q = quoteTierTotalForHeads(event, tierHeads);
                if (!q || !q.ok) {
                    showErrorModal('This group size is not covered by a price tier. Try a different guest count or contact the organizer.');
                    return;
                }
            }
            const submitBtn = modal.querySelector('.guest-modal-submit');
            const submitLabel = isPaid ? 'Continue to payment' : 'Submit RSVP';
            submitBtn.disabled = true;
            submitBtn.textContent = isPaid ? 'Redirecting...' : 'Submitting...';
            try {
                let csrfToken = typeof embeddedCsrfToken !== 'undefined' ? embeddedCsrfToken : '';
                if (!csrfToken) {
                    const csrfUrl = (baseUrl || '') + '/api/csrf-token';
                    const csrfResponse = await fetch(csrfUrl, { method: 'GET', credentials: 'same-origin' });
                    const csrfData = await csrfResponse.json();
                    csrfToken = (csrfData && csrfData.token) ? csrfData.token : '';
                }

                const body = {
                    event_id: submitGuestEventId,
                    first_name: firstName.trim(),
                    last_name: lastName.trim(),
                    email: email.trim(),
                    guest_count: guestCount,
                    question_answers: questionAnswers,
                    csrf_token: csrfToken
                };
                if (event && event.waiver && event.waiver.enabled) {
                    body.waiver_accepted = true;
                }
                const guestDobIso = readDateOfBirthIso(modal, 'guest-dob');
                if (guestDobIso && typeof guestDobIso === 'string') {
                    body.date_of_birth = guestDobIso;
                    const parts = guestDobIso.split('-');
                    if (parts.length === 3) {
                        body.dob_year = parseInt(parts[0], 10);
                        body.dob_month = parseInt(parts[1], 10);
                        body.dob_day = parseInt(parts[2], 10);
                    }
                }
                const guestGenderEl = modal.querySelector('#guest-gender');
                if (guestGenderEl && guestGenderEl.value) body.gender = guestGenderEl.value;
                if (event && event.is_potluck) {
                    const potErr = validatePotluckModalFields(modal, 'guest-potluck');
                    if (potErr) {
                        showErrorModal(potErr);
                        submitBtn.disabled = false;
                        submitBtn.textContent = submitLabel;
                        return;
                    }
                    const pr = readPotluckPayloadFromModal(modal, 'guest-potluck');
                    const aEl = modal.querySelector('#guest-potluck-party-adults');
                    const cEl = modal.querySelector('#guest-potluck-party-children');
                    const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                    const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                    body.potluck_bringing_food = pr.bringing === true;
                    if (pr.bringing === true) {
                        body.potluck_category = pr.category;
                        body.potluck_item_note = pr.note;
                        body.potluck_quantity = pr.quantity;
                        body.potluck_serving_side = pr.serving_side;
                    }
                    body.potluck_party_adults = adults;
                    body.potluck_party_children = children;
                }
                if (ticketSelections.length > 0) body.tickets = ticketSelections;

                const endpoint = isPaid ? 'guest-rsvp-checkout' : 'guest-rsvp';
                const res = await fetch(apiBase + endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(body)
                });
                let data = {};
                try {
                    data = await res.json();
                } catch (parseErr) {
                    showErrorModal(res.ok ? 'Invalid response from server.' : 'Request failed. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitLabel;
                    return;
                }
                if (isPaid && data.success && data.checkout_url) {
                    modal.remove();
                    window.location.href = data.checkout_url;
                    return;
                }
                if (data.success) {
                    modal.remove();
                    showRSVPConfirmation(false);
                    if (data.complete_account_sent) setTimeout(() => loadEvent(), 500);
                } else {
                    showErrorModal(data.message || (isPaid ? 'Could not start checkout.' : 'Failed to submit RSVP.'));
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitLabel;
                }
            } catch (e) {
                submitBtn.disabled = false;
                submitBtn.textContent = submitLabel;
                showErrorModal('Network error. Please try again.');
            }
        });
    }

    async function handleRSVP() {
        const btns = [document.getElementById('rsvp-btn-desktop'), document.getElementById('rsvp-btn-mobile')].filter(b => b);
        if (btns.length === 0 || btns[0].disabled) return;

        const eventId = new URLSearchParams(window.location.search).get('id');
        if (!eventId) {
            showErrorModal('Event ID not found');
            return;
        }

        const event = window.currentEvent || null;
        const hasQuestions = event && event.questions && event.questions.length > 0;
        const maxGuests = 10;

        try {
            let familyMembers = [];
            try {
                const familyResponse = await fetch(apiBase + 'family');
                const familyData = await familyResponse.json();
                if (familyData.success && familyData.family_members) familyMembers = familyData.family_members;
            } catch (e) { console.error('Error fetching family members:', e); }

            const showModal = hasQuestions || familyMembers.length > 0 || true;
            if (showModal) {
                showRSVPDetailsModal(eventId, event, familyMembers, btns, maxGuests);
            } else {
                await processRSVP(eventId, [], 0, {}, btns, [], null);
            }
        } catch (error) {
            console.error('RSVP error:', error);
            const msg = (error && error.message) ? error.message : 'An error occurred. Please try again.';
            showErrorModal(msg);
            resetButtons(btns);
        }
    }

    function showRSVPDetailsModal(eventId, event, familyMembers, btns, maxGuests) {
        const hasTicketTypes = event && event.ticket_types && event.ticket_types.length > 0;
        const questions = (event && event.questions) ? event.questions : [];
        const questionsHtml = questions.map(q => wrapQuestionRow(q, buildQuestionHtml(q, 'q_')));
        const sessionMode = (event && event.session_registration_mode) || 'independent';
        const seriesSessions = (event && Array.isArray(event.series_sessions)) ? event.series_sessions : [];
        const needsSessionPick = sessionMode === 'choose_one' && seriesSessions.length > 1;
        let rsvpModalSeriesIntro = '';
        if (seriesSessions.length > 1) {
            if (sessionMode === 'all_sessions' && !needsSessionPick) {
                rsvpModalSeriesIntro = `<div class="rounded-xl border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50 dark:bg-indigo-500/15 px-3 py-2.5 text-sm text-indigo-900"><strong>All sessions:</strong> this RSVP registers you for all <strong>${seriesSessions.length}</strong> dates listed on the event page.</div>`;
            } else if (sessionMode === 'independent') {
                const cur = seriesSessions.find(s => String(s.id) === String(eventId));
                const curLabel = cur ? formatSeriesSessionLabel(cur) : (event.event_date || '');
                rsvpModalSeriesIntro = `<div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 px-3 py-2.5 text-sm text-gray-800 dark:text-gray-100"><strong>This session only:</strong> you are signing up for <strong>${escapeHtml(curLabel)}</strong>. Other dates require a separate RSVP on each session’s page.</div>`;
            } else if (sessionMode === 'choose_one') {
                rsvpModalSeriesIntro = `<div class="rounded-xl border border-indigo-100 dark:border-indigo-500/30 bg-indigo-50 dark:bg-indigo-500/15 px-3 py-2.5 text-sm text-indigo-900"><strong>Pick one session</strong> — select the single date you plan to attend below.</div>`;
            }
        }
        const sessionsPickHtml = needsSessionPick ? `
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Select a session</p>
                    <div class="space-y-2 max-h-48 overflow-y-auto">
                        ${seriesSessions.map(s => `
                        <label class="flex items-center gap-3 p-2 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 ${s.is_full ? 'opacity-50 pointer-events-none' : ''}">
                            <input type="radio" name="series-session-pick" value="${s.id}" class="series-session-radio w-4 h-4 text-indigo-600 dark:text-indigo-300" ${String(s.id) === String(eventId) ? 'checked' : ''} ${s.is_full ? 'disabled' : ''}>
                            <span class="text-sm text-gray-900 dark:text-white">${escapeHtml(formatSeriesSessionLabel(s))}</span>
                            ${s.is_full ? '<span class="text-xs text-red-500">Full</span>' : ''}
                        </label>
                        `).join('')}
                    </div>
                </div>` : '';

        const ticketTypesHtml = hasTicketTypes ? `
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Select tickets</p>
                    <div class="space-y-2">
                        ${buildTicketTypesSelectionHtml(event.ticket_types, { qtyClass: 'rsvp-ticket-qty' })}
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total will be calculated at checkout.</p>
                </div>
                ` : '';

        const tieredForMemberModal = !hasTicketTypes && isTieredHeadcountEvent(event);
        const isPotluckMemberModal = !!(event && event.is_potluck);
        const tierEstimateP = tieredForMemberModal ? '<p id="rsvp-tier-estimate" class="text-sm text-indigo-700 dark:text-indigo-300 font-semibold min-h-[1.25rem]" aria-live="polite"></p>' : '';
        const potluckPartyRsvpBlock = isPotluckMemberModal ? `
                <div class="space-y-3 rounded-xl border border-amber-100 dark:border-amber-500/30 bg-white dark:bg-gray-800 p-3">
                    <p class="text-xs font-semibold text-gray-800 dark:text-gray-100">Who is attending (your party)</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Counts should match everyone you are bringing, not family members who RSVP with their own account.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Adults (incl. you) *</label>
                            <input type="number" id="rsvp-potluck-party-adults" min="1" max="50" value="${(() => { const ur = event.user_rsvp || {}; const a = ur.potluck_party_adults; if (a != null && a !== '') return parseInt(a, 10) || 1; const g = parseInt(ur.guest_count, 10) || 0; return 1 + g; })()}" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Children *</label>
                            <input type="number" id="rsvp-potluck-party-children" min="0" max="50" value="${(() => { const ur = event.user_rsvp || {}; const c = ur.potluck_party_children; return (c != null && c !== '') ? (parseInt(c, 10) || 0) : 0; })()}" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                        </div>
                    </div>
                </div>
                <input type="hidden" id="rsvp-guest-count" value="0">
        ` : '';
        const guestCountSection = hasTicketTypes
            ? potluckPartyRsvpBlock
            : (isPotluckMemberModal
                ? potluckPartyRsvpBlock + tierEstimateP
                : (event.allow_bring_guests ? `
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Number of additional guests</label>
                    <input type="number" id="rsvp-guest-count" min="0" max="${maxGuests}" value="0" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm">
                </div>
                ${tierEstimateP}
                ` : (tieredForMemberModal ? '<input type="hidden" id="rsvp-guest-count" value="0">' + tierEstimateP : '<input type="hidden" id="rsvp-guest-count" value="0">')));

        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">${hasTicketTypes ? 'Choose Tickets' : 'RSVP for Event'}</h3>
                ${rsvpModalSeriesIntro}
                ${sessionsPickHtml}
                ${ticketTypesHtml}
                ${guestCountSection}
                ${potluckRsvpFormHtml(event, 'rsvp-potluck')}
                ${familyMembers.length > 0 ? `
                <div class="space-y-2">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Family members attending</p>
                    <div class="space-y-2 max-h-40 overflow-y-auto">
                        ${familyMembers.map(fm => `
                        <label class="flex items-center gap-3 p-2 border border-gray-200 dark:border-gray-700 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer">
                            <input type="checkbox" value="${fm.id}" class="family-member-checkbox w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300">
                            <span class="text-sm font-medium text-gray-900 dark:text-white">${escapeHtml(fm.first_name + ' ' + fm.last_name)}</span>
                        </label>
                        `).join('')}
                    </div>
                </div>
                ` : ''}
                ${questions.length > 0 ? '<div class="space-y-3"><p class="text-sm font-medium text-gray-700 dark:text-gray-300">Additional Questions</p>' + questionsHtml.join('') + '</div>' : ''}
                ${buildWaiverBlockHtml(event && event.waiver)}
                <div class="flex gap-3 pt-4">
                    <button type="button" class="rsvp-modal-cancel flex-1 px-4 py-2 border border-gray-300 text-gray-700 dark:text-gray-300 rounded-xl font-medium hover:bg-gray-50 dark:hover:bg-gray-800/50">Cancel</button>
                    <button type="button" class="rsvp-modal-confirm flex-1 px-4 py-2 bg-indigo-600 text-white rounded-xl font-medium hover:bg-indigo-700">Confirm RSVP</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
        bindPotluckFormHints(modal);
        bindTicketPackageExclusiveInputs(modal, '.rsvp-ticket-qty');
        bindWaiverModal(modal, event && event.waiver);
        if (event && event.is_potluck) {
            bindPotluckBringingToggle(modal, 'rsvp-potluck');
        }
        evaluateConditionalVisibility(modal, questions);
        modal.addEventListener('change', () => evaluateConditionalVisibility(modal, questions));
        const rsvpTierEstEl = modal.querySelector('#rsvp-tier-estimate');
        if (rsvpTierEstEl) {
            function refreshRsvpTierEst() {
                let heads;
                if (event && event.is_potluck) {
                    const aEl = modal.querySelector('#rsvp-potluck-party-adults');
                    const cEl = modal.querySelector('#rsvp-potluck-party-children');
                    const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                    const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                    heads = adults + children;
                    const hgc = modal.querySelector('#rsvp-guest-count');
                    if (hgc) hgc.value = String(Math.max(0, adults + children - 1));
                } else {
                    const gcEl = modal.querySelector('#rsvp-guest-count');
                    const gc = gcEl ? Math.min(maxGuests, Math.max(0, parseInt(gcEl.value, 10) || 0)) : 0;
                    heads = 1 + gc;
                }
                const q = quoteTierTotalForHeads(event, heads);
                if (q && q.ok) rsvpTierEstEl.textContent = 'Estimated total for ' + heads + ' attendee' + (heads === 1 ? '' : 's') + ': $' + q.amount.toFixed(2);
                else if (q && !q.ok) rsvpTierEstEl.textContent = 'This group size is not covered by a price tier. Try a different guest count or contact the organizer.';
                else rsvpTierEstEl.textContent = '';
            }
            modal.addEventListener('input', refreshRsvpTierEst);
            modal.addEventListener('change', refreshRsvpTierEst);
            refreshRsvpTierEst();
        }
        modal.querySelector('.rsvp-modal-cancel').addEventListener('click', () => { modal.remove(); });
        modal.querySelector('.rsvp-modal-confirm').addEventListener('click', async () => {
            let guestCount = 0;
            if (event && event.is_potluck) {
                const aEl = modal.querySelector('#rsvp-potluck-party-adults');
                const cEl = modal.querySelector('#rsvp-potluck-party-children');
                const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                guestCount = Math.min(maxGuests, Math.max(0, adults + children - 1));
            } else {
                const guestCountEl = modal.querySelector('#rsvp-guest-count');
                guestCount = guestCountEl ? Math.min(maxGuests, Math.max(0, parseInt(guestCountEl.value, 10) || 0)) : 0;
            }
            let ticketSelections = [];
            if (hasTicketTypes) {
                modal.querySelectorAll('.rsvp-ticket-qty').forEach(input => {
                    const qty = Math.max(0, parseInt(input.value, 10) || 0);
                    if (qty > 0) ticketSelections.push({ ticket_type_id: parseInt(input.getAttribute('data-ticket-type-id'), 10), quantity: qty });
                });
                const totalTickets = ticketSelections.reduce((sum, t) => sum + t.quantity, 0);
                const totalAmount = ticketSelections.reduce((sum, t) => {
                    const tt = event.ticket_types.find(ty => ty.id === t.ticket_type_id);
                    return sum + (parseFloat(tt && tt.price ? tt.price : 0) * t.quantity);
                }, 0);
                if (totalTickets === 0 || totalAmount <= 0) {
                    showErrorModal('Please select at least one ticket.');
                    return;
                }
            }
            const familyMemberIds = Array.from(modal.querySelectorAll('.family-member-checkbox:checked')).map(cb => parseInt(cb.value));
            evaluateConditionalVisibility(modal, questions);
            const questionAnswers = collectQuestionAnswers(modal, questions);
            if (!validateRequiredVisible(modal, questions)) {
                showErrorModal('Please answer all required questions.');
                return;
            }
            const waiverErrMember = validateWaiverInModal(modal, event && event.waiver);
            if (waiverErrMember) {
                showErrorModal(waiverErrMember);
                return;
            }
            let submitEventId = parseInt(eventId, 10);
            if (needsSessionPick) {
                const picked = modal.querySelector('.series-session-radio:checked');
                if (!picked) {
                    showErrorModal('Please select a session.');
                    return;
                }
                if (picked.disabled) {
                    showErrorModal('That session is full.');
                    return;
                }
                submitEventId = parseInt(picked.value, 10);
            }
            if (!hasTicketTypes && isTieredHeadcountEvent(event)) {
                let heads = 1 + guestCount;
                if (event && event.is_potluck) {
                    const aEl = modal.querySelector('#rsvp-potluck-party-adults');
                    const cEl = modal.querySelector('#rsvp-potluck-party-children');
                    const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                    const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                    heads = adults + children;
                }
                const q = quoteTierTotalForHeads(event, heads);
                if (!q || !q.ok) {
                    showErrorModal('This group size is not covered by a price tier. Adjust the number of guests or contact the organizer.');
                    return;
                }
            }
            let potluckPayload = null;
            if (event && event.is_potluck) {
                const potErr = validatePotluckModalFields(modal, 'rsvp-potluck');
                if (potErr) {
                    showErrorModal(potErr);
                    return;
                }
                const pr = readPotluckPayloadFromModal(modal, 'rsvp-potluck');
                const aEl = modal.querySelector('#rsvp-potluck-party-adults');
                const cEl = modal.querySelector('#rsvp-potluck-party-children');
                const adults = aEl ? Math.max(1, parseInt(aEl.value, 10) || 1) : 1;
                const children = cEl ? Math.max(0, parseInt(cEl.value, 10) || 0) : 0;
                potluckPayload = {
                    bringing: pr.bringing === true,
                    category: pr.category,
                    note: pr.note,
                    quantity: pr.quantity,
                    serving_side: pr.serving_side,
                    party_adults: adults,
                    party_children: children
                };
            }
            modal.remove();
            await processRSVP(String(submitEventId), familyMemberIds, guestCount, questionAnswers, btns, ticketSelections, potluckPayload);
        });
    }

    async function processRSVP(eventId, familyMemberIds, guestCount, questionAnswers, btns, ticketSelections, potluckPayload) {
        if (guestCount == null) guestCount = 0;
        if (!questionAnswers || typeof questionAnswers !== 'object') questionAnswers = {};
        if (!ticketSelections || !Array.isArray(ticketSelections)) ticketSelections = [];
        if (!potluckPayload || typeof potluckPayload !== 'object') potluckPayload = null;
        btns.forEach(btn => {
            btn.disabled = true;
            btn.innerHTML = '<span class="flex items-center justify-center gap-2"><svg width="20" height="20" class="animate-spin w-5 h-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...</span>';
        });

        try {
            // Check if this is a paid event via API
            const eventResponse = await fetch(apiBase + 'events/' + eventId);
            const eventData = await eventResponse.json();
            
            if (eventData.success && eventData.event) {
                const ev = eventData.event;
                if (typeof ev.headcount_pricing_tiers === 'string') {
                    try {
                        const parsed = JSON.parse(ev.headcount_pricing_tiers);
                        ev.headcount_pricing_tiers = Array.isArray(parsed) ? parsed : [];
                    } catch (e) {
                        ev.headcount_pricing_tiers = [];
                    }
                } else if (!Array.isArray(ev.headcount_pricing_tiers)) {
                    ev.headcount_pricing_tiers = [];
                }
                if (!ev.pricing_model) ev.pricing_model = 'per_person';
                const hasTicketTypes = ev.ticket_types && ev.ticket_types.length > 0;
                const ticketTypesPaid = hasTicketTypes && ev.ticket_types.some(tt => parseFloat(tt.price || 0) > 0);
                const singlePrice = parseFloat(ev.ticket_price || 0);
                const isTieredEv = isTieredHeadcountEvent(ev);
                const isPaidEvent = ticketTypesPaid || singlePrice > 0 || isTieredEv;
                
                if (isPaidEvent && (ticketSelections.length > 0 || (!hasTicketTypes && (singlePrice > 0 || isTieredEv)))) {
                    if (!hasTicketTypes && isTieredEv) {
                        let heads = 1 + (guestCount || 0);
                        if (ev.is_potluck && potluckPayload && potluckPayload.party_adults != null && potluckPayload.party_children != null) {
                            heads = (parseInt(potluckPayload.party_adults, 10) || 1) + (parseInt(potluckPayload.party_children, 10) || 0);
                        }
                        const q = quoteTierTotalForHeads(ev, heads);
                        if (!q || !q.ok) {
                            showErrorModal('This group size is not covered by a price tier. Adjust the number of guests or contact the organizer.');
                            resetButtons(btns);
                            return;
                        }
                    }
                    // Paid event - redirect to Stripe checkout
                    btns.forEach(btn => btn.textContent = 'Redirecting to Stripe...');
                    
                    let csrfToken = typeof embeddedCsrfToken !== 'undefined' ? embeddedCsrfToken : '';
                    if (!csrfToken) {
                        const csrfUrl = (baseUrl || '') + '/api/csrf-token';
                        const csrfResponse = await fetch(csrfUrl, { method: 'GET', credentials: 'same-origin' });
                        const csrfData = await csrfResponse.json();
                        csrfToken = (csrfData && csrfData.token) ? csrfData.token : '';
                    }
                    const checkoutBody = {
                        event_id: parseInt(eventId, 10),
                        family_member_ids: familyMemberIds,
                        question_answers: questionAnswers || {},
                        csrf_token: csrfToken
                    };
                    if (window.currentEvent && window.currentEvent.waiver && window.currentEvent.waiver.enabled) {
                        checkoutBody.waiver_accepted = true;
                    }
                    if (ticketSelections.length > 0) {
                        checkoutBody.tickets = ticketSelections;
                    } else {
                        checkoutBody.guests = guestCount || 0;
                    }
                    if (ev.is_potluck && potluckPayload) {
                        checkoutBody.potluck_bringing_food = potluckPayload.bringing === true;
                        if (potluckPayload.bringing === true) {
                            if (potluckPayload.category) {
                                checkoutBody.potluck_category = potluckPayload.category;
                            }
                            checkoutBody.potluck_item_note = potluckPayload.note || '';
                            if (potluckPayload.quantity != null && !Number.isNaN(potluckPayload.quantity)) {
                                checkoutBody.potluck_quantity = potluckPayload.quantity;
                            }
                            if (potluckPayload.serving_side) {
                                checkoutBody.potluck_serving_side = potluckPayload.serving_side;
                            }
                        }
                        if (potluckPayload.party_adults != null) {
                            checkoutBody.potluck_party_adults = potluckPayload.party_adults;
                        }
                        if (potluckPayload.party_children != null) {
                            checkoutBody.potluck_party_children = potluckPayload.party_children;
                        }
                    }
                    
                    const checkoutResponse = await fetch(apiBase + 'payments/checkout', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify(checkoutBody)
                    });

                    let checkoutData;
                    const responseText = await checkoutResponse.text();
                    try {
                        checkoutData = responseText ? JSON.parse(responseText) : {};
                    } catch (e) {
                        showErrorModal(checkoutResponse.status === 500
                            ? 'Server error. Check that Stripe is configured in Admin → Settings → Payments (Stripe) → Configure and that the secret key is saved.'
                            : 'Invalid response from server. Please try again.');
                        resetButtons(btns);
                        return;
                    }
                    const errMsg = checkoutData.message || (checkoutResponse.ok ? null : 'Payment session failed');
                    if (checkoutData.success && checkoutData.checkout_url) {
                        window.location.href = checkoutData.checkout_url;
                    } else {
                        showErrorModal(errMsg || 'Payment session failed');
                        resetButtons(btns);
                    }
                    return;
                }
            }
            
            // Free event - create RSVP directly
            let csrfToken = typeof embeddedCsrfToken !== 'undefined' ? embeddedCsrfToken : '';
            if (!csrfToken) {
                const csrfUrl = (baseUrl || '') + '/api/csrf-token';
                const csrfResponse = await fetch(csrfUrl, { method: 'GET', credentials: 'same-origin' });
                const csrfData = await csrfResponse.json();
                csrfToken = (csrfData && csrfData.token) ? csrfData.token : '';
            }
            const response = await fetch(apiBase + 'rsvps', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(Object.assign({
                    event_id: eventId,
                    guests: guestCount || 0,
                    family_member_ids: familyMemberIds,
                    question_answers: questionAnswers || {},
                    csrf_token: csrfToken,
                    waiver_accepted: (window.currentEvent && window.currentEvent.waiver && window.currentEvent.waiver.enabled) ? true : undefined
                }, (function () {
                    const ev = window.currentEvent;
                    if (!ev || !ev.is_potluck || !potluckPayload || typeof potluckPayload !== 'object') {
                        return {};
                    }
                    const o = {
                        potluck_bringing_food: potluckPayload.bringing === true
                    };
                    if (potluckPayload.bringing === true) {
                        if (potluckPayload.category) {
                            o.potluck_category = potluckPayload.category;
                        }
                        o.potluck_item_note = potluckPayload.note || '';
                        if (potluckPayload.quantity != null && !Number.isNaN(potluckPayload.quantity)) {
                            o.potluck_quantity = potluckPayload.quantity;
                        }
                        if (potluckPayload.serving_side) {
                            o.potluck_serving_side = potluckPayload.serving_side;
                        }
                    }
                    if (potluckPayload.party_adults != null) {
                        o.potluck_party_adults = potluckPayload.party_adults;
                    }
                    if (potluckPayload.party_children != null) {
                        o.potluck_party_children = potluckPayload.party_children;
                    }
                    return o;
                })()))
            });

            let data;
            try {
                data = await response.json();
            } catch (parseErr) {
                console.error('RSVP response parse error:', parseErr);
                showErrorModal(response.ok ? 'Invalid response from server.' : (response.status === 403 ? 'Session or security error. Refresh the page and try again.' : 'Request failed (' + response.status + '). Try again.'));
                resetButtons(btns);
                return;
            }

            if (data.success) {
                showRSVPConfirmation(data.updated || false);
            } else {
                showErrorModal(data.message || 'Failed to RSVP');
                resetButtons(btns);
            }
        } catch (error) {
            console.error('RSVP error:', error);
            const msg = (error && error.message) ? error.message : 'An error occurred. Please try again.';
            showErrorModal(msg);
            resetButtons(btns);
        }
    }

    function resetButtons(btns) {
        btns.forEach(btn => {
            btn.disabled = false;
            btn.textContent = btn.id.includes('mobile') ? 'RSVP Now' : 'Claim Free Spot';
        });
    }

    function shareEvent(platform) {
        const url = window.location.href;
        const title = document.title;
        let shareUrl = '';

        switch (platform) {
            case 'facebook':
                shareUrl = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
                break;
            case 'twitter':
                shareUrl = `https://twitter.com/intent/tweet?text=${encodeURIComponent(title)}&url=${encodeURIComponent(url)}`;
                break;
            case 'email':
                shareUrl = `mailto:?subject=${encodeURIComponent(title)}&body=${encodeURIComponent('Check out this event: ' + url)}`;
                break;
        }

        if (shareUrl) {
            window.open(shareUrl, '_blank', 'width=600,height=400');
        }
    }

    async function copyLink() {
        try {
            await navigator.clipboard.writeText(window.location.href);
            // Dynamic visual feedback (could be a toast)
            alert('Link copied to clipboard!');
        } catch (err) {
            console.error('Failed to copy!', err);
        }
    }

    function showRSVPConfirmation(isUpdated = false) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4 animate-fade-in';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-[2.5rem] shadow-2xl max-w-sm w-full p-8 text-center scale-up-center">
                <div class="w-20 h-20 bg-green-100 dark:bg-green-500/15 text-green-600 dark:text-green-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg width="40" height="40" class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                </div>
                <h3 class="text-2xl font-black text-gray-900 dark:text-white mb-2">${isUpdated ? 'Updated!' : 'You\'re Going!'}</h3>
                <p class="text-gray-500 dark:text-gray-400 mb-8 leading-relaxed">
                    ${isUpdated ? 'Your preference has been saved.' : 'Successfully registered for this event. Check your email for details.'}
                </p>
                <button onclick="window.location.reload()" class="w-full py-4 bg-gray-900 text-white rounded-2xl font-bold shadow-xl active:scale-95 transition-all">
                    Awesome
                </button>
            </div>
        `;
        document.body.appendChild(modal);
    }

    function showErrorModal(message) {
        const modal = document.createElement('div');
        modal.className = 'fixed inset-0 z-[100] flex items-center justify-center bg-gray-900/60 backdrop-blur-sm p-4 animate-fade-in';
        modal.innerHTML = `
            <div class="bg-white dark:bg-gray-800 rounded-[2.5rem] shadow-2xl max-w-sm w-full p-8 text-center scale-up-center">
                <div class="w-20 h-20 bg-red-100 dark:bg-red-500/15 text-red-600 dark:text-red-300 rounded-full flex items-center justify-center mx-auto mb-6">
                    <svg width="40" height="40" class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </div>
                <h3 class="text-2xl font-black text-gray-900 dark:text-white mb-2">Wait a minute...</h3>
                <p class="text-gray-500 dark:text-gray-400 mb-8">${escapeHtml(message)}</p>
                <button onclick="this.closest('.fixed').remove()" class="w-full py-4 bg-red-600 text-white rounded-2xl font-bold active:scale-95 transition-all">
                    Dismiss
                </button>
            </div>
        `;
        document.body.appendChild(modal);
    }

    function buildWaiverBlockHtml(waiver) {
        if (!waiver || !waiver.enabled) return '';
        const label = escapeHtml(waiver.checkbox_label || 'I agree to the liability waiver and release');
        return `<div class="rsvp-waiver-block space-y-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3">
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" class="rsvp-waiver-accept mt-0.5 w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300 shrink-0">
                <span class="text-sm text-gray-700 dark:text-gray-300">${label}</span>
            </label>
            <button type="button" class="rsvp-waiver-read text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-800 underline text-left">Read full waiver</button>
        </div>`;
    }

    function bindWaiverModal(modal, waiver) {
        if (!waiver || !waiver.enabled) return;
        const readBtn = modal.querySelector('.rsvp-waiver-read');
        if (readBtn) {
            readBtn.addEventListener('click', (e) => {
                e.preventDefault();
                showWaiverFullTextModal(waiver.full_text || '');
            });
        }
    }

    function validateWaiverInModal(modal, waiver) {
        if (!waiver || !waiver.enabled) return null;
        const cb = modal.querySelector('.rsvp-waiver-accept');
        if (!cb || !cb.checked) {
            return 'You must accept the liability waiver to continue.';
        }
        return null;
    }

    function showWaiverFullTextModal(fullText) {
        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 bg-black/50 backdrop-blur-sm z-[60] flex items-center justify-center p-4';
        const text = escapeHtml(fullText || '').replace(/\n/g, '<br>');
        overlay.innerHTML = `<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-lg w-full max-h-[85vh] flex flex-col">
            <div class="p-5 border-b border-gray-100 dark:border-gray-800"><h3 class="text-lg font-bold text-gray-900 dark:text-white">Liability waiver</h3></div>
            <div class="p-5 overflow-y-auto text-sm text-gray-700 dark:text-gray-300 leading-relaxed">${text}</div>
            <div class="p-4 border-t border-gray-100 dark:border-gray-800"><button type="button" class="w-full py-2.5 bg-indigo-600 text-white rounded-xl font-semibold hover:bg-indigo-700">Close</button></div>
        </div>`;
        overlay.querySelector('button').addEventListener('click', () => overlay.remove());
        overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
        document.body.appendChild(overlay);
    }

    function buildQuestionHtml(q, namePrefix) {
        const name = namePrefix + q.id;
        const req = q.is_required ? ' required' : '';
        const label = escapeHtml(q.question_text) + (q.is_required ? ' *' : '');
        if (q.question_type === 'text') return `<div class="space-y-1"><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">${label}</label><textarea name="${name}" data-question-id="${q.id}" rows="2" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm"${req}></textarea></div>`;
        if (q.question_type === 'number') return `<div class="space-y-1"><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">${label}</label><input type="number" name="${name}" data-question-id="${q.id}" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm"${req}></div>`;
        if (q.question_type === 'checkbox') {
            const opts = (q.options && q.options.length) ? q.options : [];
            if (opts.length > 0) {
                const checks = opts.map(opt => `<label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="${name}" value="${escapeHtml(opt.option_label)}" data-question-id="${q.id}" data-multi-checkbox="1" class="w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300"><span class="text-sm text-gray-700 dark:text-gray-300">${escapeHtml(opt.option_label)}</span></label>`).join('');
                return `<div class="space-y-1"><p class="text-sm font-medium text-gray-700 dark:text-gray-300">${label}</p><div class="space-y-2">${checks}</div></div>`;
            }
            return `<label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="${name}" data-question-id="${q.id}" class="w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300"><span class="text-sm font-medium text-gray-700 dark:text-gray-300">${label}</span></label>`;
        }
        const opts = (q.options && q.options.length) ? q.options : [];
        if (q.question_type === 'radio') {
            const radios = opts.map(opt => `<label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="${name}" value="${escapeHtml(opt.option_label)}" data-question-id="${q.id}" class="w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300"><span class="text-sm text-gray-700 dark:text-gray-300">${escapeHtml(opt.option_label)}</span></label>`).join('');
            return `<div class="space-y-1"><p class="text-sm font-medium text-gray-700 dark:text-gray-300">${label}</p><div class="space-y-2">${radios}</div></div>`;
        }
        if (q.question_type === 'dropdown') {
            const options = opts.map(opt => `<option value="${escapeHtml(opt.option_label)}">${escapeHtml(opt.option_label)}</option>`).join('');
            return `<div class="space-y-1"><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">${label}</label><select name="${name}" data-question-id="${q.id}" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm"${req}><option value="">Select...</option>${options}</select></div>`;
        }
        if (q.question_type === 'multi_checkbox') {
            const checks = opts.map(opt => `<label class="flex items-center gap-2 cursor-pointer"><input type="checkbox" name="${name}" value="${escapeHtml(opt.option_label)}" data-question-id="${q.id}" data-multi-checkbox="1" class="w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300"><span class="text-sm text-gray-700 dark:text-gray-300">${escapeHtml(opt.option_label)}</span></label>`).join('');
            return `<div class="space-y-1"><p class="text-sm font-medium text-gray-700 dark:text-gray-300">${label}</p><div class="space-y-2">${checks}</div></div>`;
        }
        return `<div class="space-y-1"><label class="block text-sm font-medium text-gray-700 dark:text-gray-300">${label}</label><input type="text" name="${name}" data-question-id="${q.id}" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm"${req}></div>`;
    }

    function wrapQuestionRow(q, innerHtml) {
        const depId = (q.depends_on_question_id != null && q.depends_on_question_id !== '') ? String(q.depends_on_question_id) : '';
        const depVal = (q.depends_on_value != null && q.depends_on_value !== '') ? escapeHtml(String(q.depends_on_value)) : '';
        if (depId && depVal) return `<div class="question-row" data-question-row-id="${q.id}" data-depends-on-question-id="${depId}" data-depends-on-value="${depVal}">${innerHtml}</div>`;
        return `<div class="question-row" data-question-row-id="${q.id}">${innerHtml}</div>`;
    }

    // Select only the actual form controls for a question. The wrapping
    // .question-row div ALSO carries data-question-id, so a bare attribute
    // selector would return the wrapper first and break value reads.
    function questionFieldInputs(modal, qid) {
        return modal.querySelectorAll(
            'input[data-question-id="' + qid + '"], textarea[data-question-id="' + qid + '"], select[data-question-id="' + qid + '"]'
        );
    }

    function evaluateConditionalVisibility(modal, questions) {
        const questionMap = {};
        (questions || []).forEach(q => { questionMap[q.id] = q; });
        modal.querySelectorAll('.question-row[data-depends-on-question-id]').forEach(row => {
            const depId = row.getAttribute('data-depends-on-question-id');
            const depVal = row.getAttribute('data-depends-on-value');
            if (!depId || depVal == null) return;
            const depQ = questionMap[depId];
            const depInputs = questionFieldInputs(modal, depId);
            let depAnswer = '';
            if (depQ && depQ.question_type === 'multi_checkbox') {
                depAnswer = Array.from(depInputs).filter(el => el.checked).map(el => el.value);
            } else if (depQ && depQ.question_type === 'radio') {
                const checked = Array.from(depInputs).find(el => el.checked);
                depAnswer = (checked ? checked.value : '');
            } else if (depInputs.length) {
                const el = depInputs[0];
                depAnswer = el.type === 'checkbox' ? (el.checked ? 'Yes' : '') : (el.value || '').trim();
            }
            const match = depVal === '__any__'
                ? (Array.isArray(depAnswer) ? depAnswer.length > 0 : depAnswer !== '')
                : (Array.isArray(depAnswer) ? depAnswer.indexOf(depVal) >= 0 : depAnswer === depVal);
            row.classList.toggle('hidden', !match);
        });
    }

    function collectQuestionAnswers(modal, questions) {
        const questionAnswers = {};
        (questions || []).forEach(q => {
            const row = modal.querySelector('.question-row[data-question-row-id="' + q.id + '"]');
            if (row && row.classList.contains('hidden')) return;
            const inputs = questionFieldInputs(modal, q.id);
            if (!inputs.length) return;
            if (q.question_type === 'multi_checkbox') {
                const val = Array.from(inputs).filter(el => el.checked).map(el => el.value);
                questionAnswers[q.id] = val;
            } else if (q.question_type === 'radio') {
                const checked = Array.from(inputs).find(el => el.checked);
                questionAnswers[q.id] = checked ? checked.value : '';
            } else {
                const el = inputs[0];
                const val = el.type === 'checkbox' ? (el.checked ? 'Yes' : '') : (el.value || '').trim();
                questionAnswers[q.id] = val;
            }
        });
        return questionAnswers;
    }

    function validateRequiredVisible(modal, questions) {
        const questionMap = {};
        (questions || []).forEach(q => { questionMap[q.id] = q; });
        for (const q of questions) {
            if (!q.is_required || q.is_required === 0 || q.is_required === '0') continue;
            const row = modal.querySelector('.question-row[data-question-row-id="' + q.id + '"]');
            if (row && row.classList.contains('hidden')) continue;
            const inputs = questionFieldInputs(modal, q.id);
            let val = '';
            if (q.question_type === 'multi_checkbox') val = Array.from(inputs).filter(el => el.checked).map(el => el.value);
            else if (q.question_type === 'radio') { const checked = Array.from(inputs).find(el => el.checked); val = checked ? checked.value : ''; }
            else if (inputs.length) { const el = inputs[0]; val = el.type === 'checkbox' ? (el.checked ? 'Yes' : '') : (el.value || '').trim(); }
            const isEmpty = Array.isArray(val) ? val.length === 0 : val === '';
            if (isEmpty) return false;
        }
        return true;
    }

    function escapeHtml(text) {
        if (text == null || text === '') return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatTicketSaleEndsLabel(dt) {
        if (dt == null || dt === '') return '';
        const s = String(dt).trim().replace(' ', 'T');
        const d = new Date(s);
        if (isNaN(d.getTime())) return '';
        return d.toLocaleString(undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
    }

    function buildTicketTypesSelectionHtml(ticketTypes, opts) {
        const types = Array.isArray(ticketTypes) ? ticketTypes : [];
        const qtyClass = (opts && opts.qtyClass) ? opts.qtyClass : 'rsvp-ticket-qty';
        let lastHeaderKey = null;
        const parts = [];
        types.forEach((tt) => {
            const g = (tt.package_group && String(tt.package_group).trim()) ? String(tt.package_group).trim() : '';
            const headerKey = g || '__ungrouped__';
            if (headerKey !== lastHeaderKey) {
                lastHeaderKey = headerKey;
                const title = g ? ('Package: ' + escapeHtml(g)) : 'Other tickets';
                const marginClass = parts.length === 0 ? '' : ' mt-3';
                parts.push('<p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide' + marginClass + '">' + title + '</p>');
            }
            let saleNote = '';
            if (tt.sale_ends_at) {
                saleNote = '<span class="block text-xs text-amber-700 dark:text-amber-300 mt-0.5">Sale ends ' + escapeHtml(formatTicketSaleEndsLabel(tt.sale_ends_at)) + '</span>';
            } else if (tt.sale_starts_at) {
                saleNote = '<span class="block text-xs text-gray-500 dark:text-gray-400 mt-0.5">Available from ' + escapeHtml(formatTicketSaleEndsLabel(tt.sale_starts_at)) + '</span>';
            }
            const tid = parseInt(tt.id, 10);
            if (!tid) return;
            parts.push(
                '<div class="flex items-center justify-between gap-4 p-3 border border-gray-200 dark:border-gray-700 rounded-xl" data-ticket-package-row="1" data-package-group="' + escapeHtml(g) + '">' +
                '<div class="min-w-0">' +
                '<span class="font-medium text-gray-900 dark:text-white">' + escapeHtml(tt.name || '') + '</span>' +
                '<span class="text-sm text-gray-500 dark:text-gray-400 ml-2">$' + parseFloat(tt.price || 0).toFixed(2) + ' each</span>' +
                saleNote +
                '</div>' +
                '<input type="number" data-ticket-type-id="' + tid + '" min="0" value="0" class="' + qtyClass + ' w-20 border border-gray-300 rounded-lg px-2 py-1.5 text-sm text-right">' +
                '</div>'
            );
        });
        return parts.join('');
    }

    function bindTicketPackageExclusiveInputs(modalRoot, qtySelector) {
        if (!modalRoot || !qtySelector) return;
        modalRoot.querySelectorAll(qtySelector).forEach((inp) => {
            inp.addEventListener('input', () => {
                const row = inp.closest('[data-ticket-package-row]');
                const grp = row ? row.getAttribute('data-package-group') : null;
                if (!grp || grp === '') return;
                const val = parseInt(inp.value, 10) || 0;
                if (val <= 0) return;
                modalRoot.querySelectorAll('[data-ticket-package-row]').forEach((row2) => {
                    if (row2.getAttribute('data-package-group') !== grp) return;
                    const num = row2.querySelector(qtySelector);
                    if (num && num !== inp) num.value = '0';
                });
            });
        });
    }

    function potluckShowBringingPromptFromModal(modal, baseId) {
        const root = modal.querySelector('[data-potluck-rsvp-root="' + baseId + '"]');
        if (!root) return true;
        return root.getAttribute('data-potluck-show-bringing-prompt') !== '0';
    }

    function potluckRsvpFormHtml(event, baseId) {
        if (!event || !event.is_potluck || !Array.isArray(event.potluck_category_options) || event.potluck_category_options.length === 0) {
            return '';
        }
        const showBringingPrompt = !(event.potluck_show_bringing_prompt === false || event.potluck_show_bringing_prompt === 0 || event.potluck_show_bringing_prompt === '0');
        const ur = event.user_rsvp || {};
        const selVal = String(ur.potluck_category || '');
        const noteVal = String(ur.potluck_item_note || '');
        let qtyVal = '1';
        if (ur.potluck_quantity != null && ur.potluck_quantity !== '') {
            qtyVal = String(Math.max(1, parseInt(ur.potluck_quantity, 10) || 1));
        }
        const sideVal = String(ur.potluck_serving_side || '');
        const allowedIds = new Set(event.potluck_category_options.map(o => String(o.id)));
        let legacyOpt = '';
        if (selVal !== '' && !allowedIds.has(selVal)) {
            const legLabel = (ur.potluck_category_label && String(ur.potluck_category_label)) || selVal;
            legacyOpt = '<option value="' + escapeHtml(selVal) + '" selected>' + escapeHtml(legLabel) + ' — choose a new category below</option>';
        }
        const opts = event.potluck_category_options.map(o => {
            const id = String(o.id);
            return '<option value="' + escapeHtml(id) + '"' + (id === selVal ? ' selected' : '') + '>' + escapeHtml(o.label) + '</option>';
        }).join('');
        const bro = sideVal === 'brothers' ? ' selected' : '';
        const sis = sideVal === 'sisters' ? ' selected' : '';
        const bot = (sideVal === 'both' || sideVal === '') ? ' selected' : '';
        const catId = baseId + '-category';
        const noteId = baseId + '-note';
        const qtyId = baseId + '-quantity';
        const sideId = baseId + '-serving-side';
        const bringName = escapeHtml(baseId) + '-bringing';
        const hasDishSignup = selVal !== '';
        const hasRsvpRecord = ur.id != null || ur.status != null;
        let yesChecked = '';
        let noChecked = '';
        if (showBringingPrompt) {
            if (hasDishSignup) {
                yesChecked = ' checked';
            } else if (hasRsvpRecord && ur.status === 'yes') {
                noChecked = ' checked';
            }
        }
        const promptAttr = showBringingPrompt ? '1' : '0';
        const detailsPanelClass = 'space-y-3 rounded-xl border border-amber-100 dark:border-amber-500/30 bg-amber-50/40 p-3 potluck-details-panel';
        const detailsBlock =
            '<div class="' + detailsPanelClass + '" data-potluck-form="' + escapeHtml(baseId) + '">' +
            '<p class="text-sm font-semibold text-gray-800 dark:text-gray-100">What you\'re bringing</p>' +
            '<div class="space-y-1"><label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Food category *</label>' +
            '<select id="' + escapeHtml(catId) + '" class="potluck-category-select w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required>' +
            '<option value="">Select…</option>' + legacyOpt + opts + '</select></div>' +
            '<div class="space-y-1"><label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Quantity you\'re bringing *</label>' +
            '<input type="number" id="' + escapeHtml(qtyId) + '" min="1" max="999" value="' + escapeHtml(qtyVal) + '" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required></div>' +
            '<div class="space-y-1"><label class="block text-xs font-medium text-gray-600 dark:text-gray-300">This food is for *</label>' +
            '<select id="' + escapeHtml(sideId) + '" class="w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" required>' +
            '<option value="">Select…</option>' +
            '<option value="brothers"' + bro + '>Brothers\' side</option>' +
            '<option value="sisters"' + sis + '>Sisters\' side</option>' +
            '<option value="both"' + bot + '>Both sides</option></select></div>' +
            '<div class="space-y-1"><label class="block text-xs font-medium text-gray-600 dark:text-gray-300">Describe it <span class="potluck-note-optional">(optional)</span><span class="potluck-note-required hidden text-rose-600 dark:text-rose-300">*</span></label>' +
            '<textarea id="' + escapeHtml(noteId) + '" rows="2" class="potluck-item-note w-full border border-gray-300 rounded-xl px-3 py-2 text-sm" placeholder="Describe the dish or item">' + escapeHtml(noteVal) + '</textarea></div></div>';
        if (!showBringingPrompt) {
            return '<div class="space-y-3" data-potluck-rsvp-root="' + escapeHtml(baseId) + '" data-potluck-show-bringing-prompt="' + promptAttr + '">' +
                '<div class="space-y-2 rounded-xl border border-amber-100 dark:border-amber-500/30 bg-amber-50/40 p-3">' +
                '<p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Potluck</p>' +
                detailsBlock +
                '</div></div>';
        }
        return '<div class="space-y-3" data-potluck-rsvp-root="' + escapeHtml(baseId) + '" data-potluck-show-bringing-prompt="' + promptAttr + '">' +
            '<div class="space-y-2 rounded-xl border border-amber-100 dark:border-amber-500/30 bg-amber-50/40 p-3">' +
            '<p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Potluck</p>' +
            '<p class="text-xs text-gray-600 dark:text-gray-300" id="' + escapeHtml(baseId) + '-bringing-question" role="group" aria-labelledby="' + escapeHtml(baseId) + '-bringing-legend">'
            + '<span id="' + escapeHtml(baseId) + '-bringing-legend">Are you bringing a food item to share? <span class="text-rose-600 dark:text-rose-300" aria-hidden="true">*</span></span></p>' +
            '<div class="flex flex-wrap gap-4 pt-1" role="radiogroup" aria-required="true" aria-labelledby="' + escapeHtml(baseId) + '-bringing-legend">' +
            '<label class="inline-flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100 cursor-pointer">' +
            '<input type="radio" name="' + bringName + '" id="' + escapeHtml(baseId) + '-bringing-yes" value="yes" class="w-4 h-4 text-indigo-600 dark:text-indigo-300 border-gray-300"' + yesChecked + '>' +
            '<span>Yes</span></label>' +
            '<label class="inline-flex items-center gap-2 text-sm text-gray-800 dark:text-gray-100 cursor-pointer">' +
            '<input type="radio" name="' + bringName + '" id="' + escapeHtml(baseId) + '-bringing-no" value="no" class="w-4 h-4 text-indigo-600 dark:text-indigo-300 border-gray-300"' + noChecked + '>' +
            '<span>No</span></label>' +
            '</div></div>' +
            detailsBlock +
            '</div>';
    }

    function bindPotluckFormHints(modal) {
        const wrap = modal.querySelector('[data-potluck-form]');
        if (!wrap) return;
        const sel = wrap.querySelector('.potluck-category-select');
        const note = wrap.querySelector('.potluck-item-note');
        if (!sel || !note) return;
        function upd() {
            const isOther = String(sel.value) === 'other';
            const optSpan = wrap.querySelector('.potluck-note-optional');
            const reqSpan = wrap.querySelector('.potluck-note-required');
            if (optSpan) optSpan.classList.toggle('hidden', isOther);
            if (reqSpan) reqSpan.classList.toggle('hidden', !isOther);
            note.required = isOther;
        }
        sel.addEventListener('change', upd);
        upd();
    }

    function bindPotluckBringingToggle(modal, baseId) {
        const yes = modal.querySelector('#' + baseId + '-bringing-yes');
        const no = modal.querySelector('#' + baseId + '-bringing-no');
        const panel = modal.querySelector('[data-potluck-form="' + baseId + '"]');
        if (!yes || !no || !panel) return;
        function sync() {
            const bringing = !!yes.checked;
            panel.classList.toggle('hidden', !bringing);
            panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !bringing;
            });
        }
        yes.addEventListener('change', sync);
        no.addEventListener('change', sync);
        sync();
    }

    function readPotluckBringingChoice(modal, baseId) {
        if (!potluckShowBringingPromptFromModal(modal, baseId)) {
            return true;
        }
        const y = modal.querySelector('#' + baseId + '-bringing-yes');
        const n = modal.querySelector('#' + baseId + '-bringing-no');
        if (y && y.checked) return true;
        if (n && n.checked) return false;
        return null;
    }

    function readPotluckPayloadFromModal(modal, baseId) {
        const bringing = readPotluckBringingChoice(modal, baseId);
        const empty = {
            bringing,
            category: '',
            note: '',
            quantity: NaN,
            serving_side: ''
        };
        if (bringing !== true) {
            return empty;
        }
        const pc = (modal.querySelector('#' + baseId + '-category') || {}).value || '';
        const pn = (modal.querySelector('#' + baseId + '-note') || {}).value || '';
        const pq = (modal.querySelector('#' + baseId + '-quantity') || {}).value || '';
        const ps = (modal.querySelector('#' + baseId + '-serving-side') || {}).value || '';
        return {
            bringing: true,
            category: String(pc).trim(),
            note: String(pn).trim(),
            quantity: parseInt(pq, 10),
            serving_side: String(ps).trim()
        };
    }

    function validatePotluckModalFields(modal, baseId) {
        const bringing = readPotluckBringingChoice(modal, baseId);
        if (bringing === null) {
            return 'Please indicate whether you are bringing a food item to share.';
        }
        if (bringing === false) {
            return null;
        }
        const p = readPotluckPayloadFromModal(modal, baseId);
        if (!p.category) return 'Please select a food category.';
        if (!(p.quantity >= 1 && p.quantity <= 999)) return 'Please enter a valid quantity (1–999).';
        if (!p.serving_side || !['brothers', 'sisters', 'both'].includes(p.serving_side)) return 'Please select whether the food is for the brothers\' side, sisters\' side, or both.';
        if (p.category === 'other' && !p.note) return 'Please describe what you are bringing when you select Other.';
        return null;
    }

    /** Remove script tags and invalid regex-like content from HTML to prevent XSS and "Invalid regular expression flags" when setting innerHTML. */
    function sanitizeDescription(html) {
        if (html == null || html === '') return '';
        const s = String(html);
        return s.replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '').trim();
    }

    function initEventDetailsTabs() {
        const root = document.getElementById('event-detail-tab-root');
        if (!root) return;
        const tabs = Array.from(root.querySelectorAll('.event-detail-tab[role="tab"]'));
        const panels = Array.from(root.querySelectorAll('.event-tab-panel[role="tabpanel"]'));
        if (!tabs.length || !panels.length) return;

        let lastActiveTabId = null;

        function styleTab(tab, selected) {
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
            const setClasses = (classes, on) => classes.split(' ').forEach((c) => { if (c) tab.classList.toggle(c, on); });
            setClasses('border-indigo-600', selected);
            setClasses('text-indigo-700 dark:text-indigo-300', selected);
            setClasses('border-transparent', !selected);
            setClasses('text-gray-600 dark:text-gray-300', !selected);
            setClasses('hover:text-gray-900', !selected);
        }

        function activate(tabId) {
            const shouldAnimate = lastActiveTabId !== null && lastActiveTabId !== tabId;
            lastActiveTabId = tabId;
            panels.forEach((p) => {
                const match = p.getAttribute('data-panel') === tabId;
                p.hidden = !match;
                p.classList.remove('event-tab-panel--enter');
                if (match && shouldAnimate) {
                    requestAnimationFrame(() => {
                        void p.offsetWidth;
                        p.classList.add('event-tab-panel--enter');
                        function onEnd() {
                            p.classList.remove('event-tab-panel--enter');
                            p.removeEventListener('animationend', onEnd);
                        }
                        p.addEventListener('animationend', onEnd);
                    });
                }
            });
            tabs.forEach((t) => styleTab(t, t.getAttribute('data-tab') === tabId));
        }

        tabs.forEach((t, idx) => {
            t.addEventListener('click', () => activate(t.getAttribute('data-tab')));
            t.addEventListener('keydown', (e) => {
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                e.preventDefault();
                const dir = e.key === 'ArrowRight' ? 1 : -1;
                const next = (idx + dir + tabs.length) % tabs.length;
                activate(tabs[next].getAttribute('data-tab'));
                tabs[next].focus();
            });
        });

        activate(tabs[0].getAttribute('data-tab'));
    }

    // Load event on page load
    loadEvent();
</script>

<style>
    .question-row.hidden { display: none !important; }
    .font-inter { font-family: 'Inter', sans-serif; }
    
    .scale-up-center {
        animation: scale-up-center 0.4s cubic-bezier(0.390, 0.575, 0.565, 1.000) both;
    }

    @keyframes scale-up-center {
        0% { transform: scale(0.5); opacity: 0; }
        100% { transform: scale(1); opacity: 1; }
    }

    /* Custom scroll behavior for the description */
    .prose p { margin-bottom: 1.25rem; }
    .prose strong { color: #111827; font-weight: 700; }

    /* About block (non-tabbed layout); tabbed layout uses shell below */
    .portal-event-about-card {
        padding: 20px;
        background: #fff;
        box-shadow: 0px 20px 30px rgba(0, 0, 0, 0.05);
        border-radius: 20px;
    }

    #event-detail-tab-root .portal-event-about-card {
        padding: 0;
        background: transparent;
        box-shadow: none;
        border-radius: 0;
    }

    /* Tabbed event details: white card shell */
    .event-detail-tab-root {
        background: #fff;
        padding: 20px;
        border-radius: 20px;
        box-shadow: 0px 20px 30px rgba(0, 0, 0, 0.05);
    }

    #event-detail-tab-root .event-detail-tablist[role="tablist"] {
        padding: 0;
        background: transparent;
    }

    #event-detail-tab-root [role="tabpanel"][data-panel="potluck"] .potluck-public-wrap {
        padding-top: 0;
        margin-top: 0;
        border-top: none;
    }

    @keyframes event-detail-tab-enter {
        from {
            opacity: 0;
            transform: translateY(6px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    #event-detail-tab-root .event-tab-panel.event-tab-panel--enter {
        animation: event-detail-tab-enter 0.22s ease-out;
    }

    @media (prefers-reduced-motion: reduce) {
        #event-detail-tab-root .event-tab-panel.event-tab-panel--enter {
            animation: none;
        }
    }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
