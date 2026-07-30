<?php

/**
 * Public Event Listing Page
 * No authentication required
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;

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
    $dbPortal = Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

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
$portalEventsApiUrl = rtrim($baseUrlPath, '/') . '/api/portal/events.php';
if ($portalEventsApiUrl === '/api/portal/events.php' || strpos($portalEventsApiUrl, '/') !== 0) {
    $portalEventsApiUrl = '/' . ltrim($portalEventsApiUrl, '/');
}

// Get filters
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Check if user is logged in and get member data if available
$isLoggedIn = PortalAuthMiddleware::isAuthenticated();
if ($isLoggedIn) {
    $member = PortalAuthMiddleware::getMember();
} else {
    $member = null;
}

$portalOrganizationIdForApi = headcount_resolve_portal_organization_id(
    $isLoggedIn ? PortalAuthMiddleware::getOrganizationId() : null,
    $config,
    $dbPortal
);

// Set page title
$pageTitle = 'Upcoming Events';

// Include header
require __DIR__ . '/includes/header.php';
?>
<style>
/* Event card hero: guaranteed min height (10rem = 160px at 16px root) — works even if Tailwind purge misses arbitrary classes */
.portal-event-card__media {
    flex-shrink: 0;
    box-sizing: border-box;
    min-height: 10rem;
    height: 12rem;
}
@media (min-width: 640px) {
    .portal-event-card__media {
        min-height: 11rem;
        height: 13rem;
    }
}
</style>

<div x-data="eventsViewApp()" x-init="init()">
<div class="mb-5 md:mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Upcoming Events</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Discover and book your next experience.</p>
        </div>
        <div class="flex items-center gap-3 self-start sm:self-auto">
            <div class="flex items-center gap-1 bg-gray-100 dark:bg-gray-800 rounded-xl p-1">
                <button
                    type="button"
                    @click="viewMode = 'card'; saveViewPreference('card'); updateView()"
                    :class="viewMode === 'card' ? 'bg-white text-indigo-600 shadow-sm dark:bg-gray-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="portal-touch-target px-3 py-1.5 rounded-lg transition-all font-bold text-sm"
                    title="Grid View"
                    aria-label="Grid view"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                    </svg>
                </button>
                <button 
                    type="button"
                    @click="viewMode = 'list'; saveViewPreference('list'); updateView()"
                    :class="viewMode === 'list' ? 'bg-white text-indigo-600 shadow-sm dark:bg-gray-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                    class="portal-touch-target px-3 py-1.5 rounded-lg transition-all font-bold text-sm"
                    title="List View"
                    aria-label="List view"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>

<div class="mb-8" x-data="{ filtersOpen: <?= ($category || $dateFrom || $dateTo) ? 'true' : 'false' ?> }">
    <?php
    $portalFieldClass = 'w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 min-h-[44px]';
    $portalLabelClass = 'block text-[11px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-400';
    ?>
    <form method="GET" action="" class="portal-filters mb-6 space-y-3">
        <div class="space-y-1.5">
            <label class="<?= $portalLabelClass ?>">Search</label>
            <div class="relative">
                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Event name..."
                       class="w-full rounded-lg border border-gray-300 bg-white pl-10 pr-3.5 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 min-h-[44px]">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                    <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>
        </div>
        <button type="button" class="portal-filters__toggle" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen.toString()">
            <span x-text="filtersOpen ? 'Hide filters' : 'More filters'"></span>
            <svg class="h-4 w-4 transition-transform" :class="filtersOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <div class="portal-filters__extra space-y-4" :data-collapsed="filtersOpen ? '0' : '1'">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="space-y-1.5">
                    <label class="<?= $portalLabelClass ?>">Category</label>
                    <select name="category" class="<?= $portalFieldClass ?>">
                        <option value="">All Categories</option>
                        <option value="workshop" <?php echo $category === 'workshop' ? 'selected' : ''; ?>>Workshop</option>
                        <option value="meeting" <?php echo $category === 'meeting' ? 'selected' : ''; ?>>Meeting</option>
                        <option value="social" <?php echo $category === 'social' ? 'selected' : ''; ?>>Social</option>
                        <option value="other" <?php echo $category === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="space-y-1.5">
                    <label class="<?= $portalLabelClass ?>">From Date</label>
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="<?= $portalFieldClass ?>" aria-label="From date">
                </div>
                <div class="space-y-1.5">
                    <label class="<?= $portalLabelClass ?>">To Date</label>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="<?= $portalFieldClass ?>" aria-label="To date">
                </div>
            </div>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 pt-1">
            <button type="submit" class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 shadow-md shadow-brand-200 transition-all active:scale-95">
                Filter Results
            </button>
            <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php"
               class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 bg-gray-100 text-gray-700 font-bold rounded-xl hover:bg-gray-200 transition-all text-center inline-flex items-center justify-center dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                Clear
            </a>
        </div>
    </form>

    <!-- Events Container -->
    <div>
        <!-- Grid View -->
        <div x-show="viewMode === 'card'" id="events-container-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
            <div class="col-span-full py-20 text-center">
                <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
                <p class="mt-4 text-gray-500 font-medium tracking-tight">Searching for events...</p>
            </div>
        </div>
        
        <!-- List View -->
        <div x-show="viewMode === 'list'" id="events-container-list" class="bento-card p-0 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Event</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date & Time</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Location</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Price</th>
                            <?php if ($isLoggedIn): ?>
                            <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">RSVP Status</th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody id="events-list-body" class="bg-white dark:bg-transparent divide-y divide-gray-200 dark:divide-gray-700">
                        <tr>
                            <td colspan="<?php echo $isLoggedIn ? '7' : '6'; ?>" class="px-6 py-20 text-center">
                                <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-indigo-500 border-t-transparent"></div>
                                <p class="mt-4 text-gray-500 font-medium tracking-tight">Searching for events...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const portalEventsApiUrl = <?php echo json_encode($portalEventsApiUrl); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;
        const isLoggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
        const portalOrganizationIdForApi = <?php echo json_encode($portalOrganizationIdForApi); ?>;
        
        // Debug: Log API base URL
        console.log('API Base URL:', apiBase);
        console.log('Base URL:', baseUrl);
        
        // Validate API base URL
        if (!apiBase || apiBase.includes('login.php')) {
            console.error('Invalid API base URL detected:', apiBase);
        }

        // Store current events data globally for view switching
        let currentEventsData = [];

        // Alpine.js component for view management
        function eventsViewApp() {
            return {
                viewMode: 'card',
                init() {
                    // Load view preference from localStorage
                    if (typeof Storage !== 'undefined') {
                        const savedView = localStorage.getItem('portalEventsViewMode');
                        if (savedView === 'card' || savedView === 'list') {
                            this.viewMode = savedView;
                        }
                    }
                },
                saveViewPreference(mode) {
                    this.viewMode = mode;
                    if (typeof Storage !== 'undefined') {
                        localStorage.setItem('portalEventsViewMode', mode);
                    }
                },
                updateView() {
                    // Re-render events with current view mode
                    if (currentEventsData.length > 0) {
                        displayEvents(currentEventsData);
                    }
                }
            };
        }

        // Store timezone globally
        let eventTimezone = 'America/Indiana/Indianapolis';
        
        // Format date using the event's timezone
        function formatEventDate(event) {
            // Use the formatted date from server if available, otherwise parse carefully
            if (event.event_date_formatted) {
                // Parse the date string as a local date (not UTC) to avoid timezone shift
                const [year, month, day] = event.event_date_formatted.split('-').map(Number);
                const date = new Date(year, month - 1, day);
                
                const dateStr = date.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric' 
                });
                
                let timeStr = '';
                if (event.start_time) {
                    // Parse time as local time, not UTC
                    const [hours, minutes] = event.start_time.split(':').map(Number);
                    const timeDate = new Date(2000, 0, 1, hours, minutes);
                    timeStr = timeDate.toLocaleTimeString('en-US', { 
                        hour: 'numeric', 
                        minute: '2-digit',
                        hour12: true
                    });
                }
                
                return { dateStr, timeStr };
            } else {
                // Fallback: parse date string carefully to avoid timezone issues
                const dateParts = event.event_date.split('-');
                if (dateParts.length === 3) {
                    const [year, month, day] = dateParts.map(Number);
                    const date = new Date(year, month - 1, day);
                    
                    const dateStr = date.toLocaleDateString('en-US', { 
                        month: 'short', 
                        day: 'numeric', 
                        year: 'numeric' 
                    });
                    
                    let timeStr = '';
                    if (event.start_time) {
                        const [hours, minutes] = event.start_time.split(':').map(Number);
                        const timeDate = new Date(2000, 0, 1, hours, minutes);
                        timeStr = timeDate.toLocaleTimeString('en-US', { 
                            hour: 'numeric', 
                            minute: '2-digit',
                            hour12: true
                        });
                    }
                    
                    return { dateStr, timeStr };
                }
            }
            
            // Ultimate fallback
            return { dateStr: event.event_date, timeStr: event.start_time || '' };
        }
        
        // Load events
        async function loadEvents() {
            try {
                const params = new URLSearchParams(window.location.search);
                if (portalOrganizationIdForApi != null && Number(portalOrganizationIdForApi) > 0 && !params.has('organization_id')) {
                    params.set('organization_id', String(Number(portalOrganizationIdForApi)));
                }
                const queryString = params.toString() ? '?' + params.toString() : '';

                const urlsToTry = [portalEventsApiUrl, apiBase + 'events.php', apiBase + 'events']
                    .map((u) => u + queryString)
                    .filter((url, i, arr) => url && arr.indexOf(url) === i);

                let response = null;
                let data = null;

                for (const url of urlsToTry) {
                    try {
                        const res = await fetch(url, {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        });
                        const text = await res.text();
                        let parsed = null;
                        try {
                            parsed = JSON.parse(text);
                        } catch (e) {
                            continue;
                        }
                        if (parsed && typeof parsed === 'object' && 'success' in parsed) {
                            response = res;
                            data = parsed;
                            break;
                        }
                    } catch (e) {
                        // try next URL
                    }
                }

                if (!data) {
                    showError('Failed to connect to the events API. Please try again or contact support.');
                    return;
                }

                if (data.success) {
                    if (data.timezone) {
                        eventTimezone = data.timezone;
                    }
                    displayEvents(data.events || []);
                } else {
                    showError(data.message || 'Error loading events');
                }
            } catch (error) {
                console.error('Error loading events:', error);
                showError('Failed to load events. Please check your connection and try again.');
            }
        }

        function showError(message) {
            const gridContainer = document.getElementById('events-container-grid');
            const listBody = document.getElementById('events-list-body');
            
            if (gridContainer) {
                gridContainer.innerHTML = 
                    '<div class="col-span-full text-center py-12 text-red-500">' + escapeHtml(message) + '</div>';
            }
            if (listBody) {
                listBody.innerHTML = 
                    '<tr><td colspan="' + (isLoggedIn ? '7' : '6') + '" class="px-6 py-12 text-center text-red-500">' + escapeHtml(message) + '</td></tr>';
            }
        }

        function displayEvents(events) {
            // Store events data globally
            currentEventsData = events;
            
            // Get current view mode from Alpine.js or localStorage
            let viewMode = 'card';
            if (typeof Storage !== 'undefined') {
                const savedView = localStorage.getItem('portalEventsViewMode');
                if (savedView === 'card' || savedView === 'list') {
                    viewMode = savedView;
                }
            }
            
            if (events.length === 0) {
                showEmptyState();
                return;
            }

            // Render based on view mode
            if (viewMode === 'list') {
                displayEventsList(events);
            } else {
                displayEventsGrid(events);
            }
        }

        function showEmptyState() {
            const gridContainer = document.getElementById('events-container-grid');
            const listBody = document.getElementById('events-list-body');
            
            const emptyStateHtml = `
                <div class="col-span-full text-center py-20 bg-gray-50 dark:bg-gray-800/40 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700">
                    <div class="p-4 bg-white dark:bg-gray-800 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm">
                        <svg width="32" height="32" class="w-8 h-8 text-gray-300 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">No events found</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Try adjusting your filters or search terms.</p>
                </div>`;
            
            if (gridContainer) {
                gridContainer.innerHTML = emptyStateHtml;
            }
            if (listBody) {
                listBody.innerHTML = '<tr><td colspan="' + (isLoggedIn ? '7' : '6') + '" class="px-6 py-20 text-center"><div class="text-center py-20 bg-gray-50 dark:bg-gray-800/40 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700"><div class="p-4 bg-white dark:bg-gray-800 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm"><svg width="32" height="32" class="w-8 h-8 text-gray-300 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></div><h3 class="text-lg font-bold text-gray-900 dark:text-white">No events found</h3><p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Try adjusting your filters or search terms.</p></div></td></tr>';
            }
        }

        function displayEventsGrid(events) {
            const container = document.getElementById('events-container-grid');
            if (!container) return;
            
            container.innerHTML = events.map(event => {
                const { dateStr, timeStr } = formatEventDate(event);
                
                const isFull = event.is_full || (event.available_spots !== null && event.available_spots <= 0);
                const category = event.category || 'Event';
                const userRsvp = event.user_rsvp || null;
                const rsvpStatus = userRsvp ? userRsvp.status : null;
                
                // RSVP badge for authenticated users
                let rsvpBadge = '';
                if (isLoggedIn && rsvpStatus) {
                    const rsvpColors = {
                        'yes': 'bg-emerald-50 text-emerald-700 border-emerald-100',
                        'no': 'bg-rose-50 text-rose-700 border-rose-100',
                        'maybe': 'bg-amber-50 text-amber-700 border-amber-100'
                    };
                    const rsvpText = {
                        'yes': 'Registered',
                        'no': 'Declined',
                        'maybe': 'Maybe'
                    };
                    rsvpBadge = `<div class="mb-3"><span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border ${rsvpColors[rsvpStatus] || 'bg-gray-50 text-gray-700 border-gray-100'}">${rsvpText[rsvpStatus] || rsvpStatus}</span></div>`;
                }
                
                const descPlain = stripHtml(event.description || '').replace(/\s+/g, ' ').trim();
                let descPreview = descPlain || 'No description provided.';
                if (descPreview.length > 40) {
                    descPreview = descPreview.slice(0, 40) + '...';
                }
                return `
                    <div class="bento-card group flex flex-col h-full min-h-0 !p-0 overflow-hidden hover:border-indigo-200 transition-all duration-300">
                        <div class="portal-event-card__media relative overflow-hidden p-6 ${event.banner_image_url ? '' : 'bg-gradient-to-br from-indigo-500 to-purple-600'}">
                            ${event.banner_image_url ? `
                                <img src="${escapeHtml(event.banner_image_url)}" alt="" class="absolute inset-0 w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" loading="lazy" role="presentation">
                            ` : ''}
                            <div class="absolute top-0 right-0 p-4 z-10 flex flex-wrap gap-1 justify-end">
                                ${event.is_recurring ? '<span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-purple-500/80 backdrop-blur-md text-white rounded-lg border border-white/30">Recurring</span>' : ''}
                                <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-white/20 backdrop-blur-md text-white rounded-lg border border-white/30">
                                    ${escapeHtml(category)}
                                </span>
                            </div>
                            ${!event.banner_image_url ? `<svg width="96" height="96" class="absolute -right-4 -bottom-4 w-24 h-24 text-white/10" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>` : ''}
                        </div>
                        <div class="p-6 flex-1 flex flex-col min-h-0 min-w-0">
                            <h3 class="text-lg sm:text-xl font-extrabold text-gray-900 dark:text-white leading-snug line-clamp-3 text-balance mb-3 -mt-1">${escapeHtml(event.title)}</h3>
                            ${rsvpBadge}
                            ${event.is_recurring ? `<p class="text-xs font-semibold text-indigo-600 dark:text-indigo-400 mb-2 text-pretty">${(Number(event.upcoming_sessions_in_series) > 1)
                                ? `Next upcoming session — ${Number(event.upcoming_sessions_in_series) - 1} more date${Number(event.upcoming_sessions_in_series) - 1 === 1 ? '' : 's'} scheduled in this series. Open for full list and RSVP rules.`
                                : 'Part of a multi-session series — open the event to see dates and how RSVP works.'}</p>` : ''}
                            <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 leading-relaxed text-pretty break-words">${escapeHtml(descPreview)}</p>

                            <div class="space-y-3 mb-6">
                                <div class="flex items-start gap-3 text-sm font-medium text-gray-700 dark:text-gray-300 min-w-0">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center flex-shrink-0 text-indigo-600 dark:text-indigo-300">
                                        <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </div>
                                    <span class="min-w-0 pt-1">${dateStr}${timeStr ? ' at ' + timeStr : ''}</span>
                                </div>
                                <div class="flex items-start gap-3 text-sm font-medium text-gray-700 dark:text-gray-300 min-w-0">
                                    <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/15 flex items-center justify-center flex-shrink-0 text-emerald-600 dark:text-emerald-300">
                                        <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    </div>
                                    <span class="min-w-0 flex-1 leading-snug line-clamp-3 break-words">${escapeHtml(event.location || 'Online / TBA')}</span>
                                    ${event.is_virtual ? '<span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-violet-100 text-violet-700 border border-violet-200 dark:bg-violet-500/15 dark:text-violet-300 dark:border-violet-500/30 flex-shrink-0">Virtual</span>' : ''}
                                </div>
                            </div>

                            <div class="flex items-center justify-between pt-4 border-t border-gray-100 dark:border-gray-700 flex-shrink-0 gap-3">
                                <div class="flex flex-col">
                                    <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest leading-none mb-1">Price</span>
                                    <span class="text-base font-bold text-gray-900 dark:text-white">${event.ticket_price > 0 ? '$' + parseFloat(event.ticket_price).toFixed(2) : 'Free'}</span>
                                </div>
                                <a href="${baseUrl}/portal/event-details.php?id=${event.id}"
                                   class="px-5 py-2.5 ${isFull ? 'bg-gray-100 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500' : 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-100 dark:shadow-none'} font-bold rounded-xl transition-all active:scale-95">
                                    ${isFull ? 'Full' : 'Details'}
                                </a>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function displayEventsList(events) {
            const tbody = document.getElementById('events-list-body');
            if (!tbody) return;
            
            tbody.innerHTML = events.map(event => {
                const { dateStr, timeStr } = formatEventDate(event);
                const listDescRaw = stripHtml(event.description || '').replace(/\s+/g, ' ').trim();
                let listDescPreview = listDescRaw || 'No description provided.';
                if (listDescPreview.length > 40) {
                    listDescPreview = listDescPreview.slice(0, 40) + '...';
                }
                
                const isFull = event.is_full || (event.available_spots !== null && event.available_spots <= 0);
                const category = event.category || 'Event';
                const userRsvp = event.user_rsvp || null;
                const rsvpStatus = userRsvp ? userRsvp.status : null;
                
                // RSVP status cell for authenticated users
                let rsvpCell = '';
                if (isLoggedIn) {
                    if (rsvpStatus) {
                        const rsvpColors = {
                            'yes': 'bg-emerald-50 text-emerald-700 border-emerald-100',
                            'no': 'bg-rose-50 text-rose-700 border-rose-100',
                            'maybe': 'bg-amber-50 text-amber-700 border-amber-100'
                        };
                        const rsvpText = {
                            'yes': 'Registered',
                            'no': 'Declined',
                            'maybe': 'Maybe'
                        };
                        rsvpCell = `<td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border ${rsvpColors[rsvpStatus] || 'bg-gray-50 text-gray-700 border-gray-100'}">
                                ${rsvpText[rsvpStatus] || rsvpStatus}
                            </span>
                        </td>`;
                    } else {
                        rsvpCell = '<td class="px-6 py-4 whitespace-nowrap"><span class="text-xs text-gray-400 dark:text-gray-500">Not registered</span></td>';
                    }
                }
                
                return `
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-4">
                                ${event.banner_image_url ? `
                                    <div class="w-16 h-16 rounded-lg flex-shrink-0 overflow-hidden">
                                        <img src="${escapeHtml(event.banner_image_url)}" alt="${escapeHtml(event.title)}" class="w-full h-full object-cover">
                                    </div>
                                ` : `
                                    <div class="w-16 h-16 bg-gradient-to-r from-indigo-500 to-purple-600 rounded-lg flex items-center justify-center flex-shrink-0">
                                        <svg class="w-8 h-8 text-white opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                        </svg>
                                    </div>
                                `}
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-sm font-bold text-gray-900 dark:text-white line-clamp-2 text-balance mb-1">${escapeHtml(event.title)}</h3>
                                    ${event.is_recurring ? `<p class="text-[11px] font-semibold text-indigo-600 dark:text-indigo-400 mb-1">${(Number(event.upcoming_sessions_in_series) > 1)
                                        ? `Next session listed — ${Number(event.upcoming_sessions_in_series) - 1} more upcoming in this series.`
                                        : 'Multi-session — see event page for RSVP options'}</p>` : ''}
                                    <p class="text-xs text-gray-500 dark:text-gray-400 break-words">${escapeHtml(listDescPreview)}</p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900 dark:text-white">${dateStr}</div>
                            ${timeStr ? '<div class="text-xs text-gray-500 dark:text-gray-400">' + timeStr + '</div>' : ''}
                        </td>
                        <td class="px-6 py-4">
                            <div class="text-sm text-gray-900 dark:text-gray-200">${escapeHtml(event.location || 'Online / TBA')}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex flex-wrap gap-1 items-center">
                                ${event.is_virtual ? '<span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-violet-100 text-violet-700 border border-violet-200 dark:bg-violet-500/15 dark:text-violet-300 dark:border-violet-500/30">Virtual</span>' : ''}
                                ${event.is_recurring ? '<span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-purple-100 text-purple-700 border border-purple-200 dark:bg-purple-500/15 dark:text-purple-300 dark:border-purple-500/30">Recurring</span>' : ''}
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-indigo-50 text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
                                    ${escapeHtml(category)}
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-bold text-gray-900 dark:text-white">${event.ticket_price > 0 ? '$' + parseFloat(event.ticket_price).toFixed(2) : 'Free'}</div>
                        </td>
                        ${rsvpCell}
                        <td class="px-6 py-4 whitespace-nowrap text-right">
                            <a href="${baseUrl}/portal/event-details.php?id=${event.id}"
                               class="px-4 py-2 ${isFull ? 'bg-gray-100 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500' : 'bg-indigo-600 text-white hover:bg-indigo-700'} font-bold rounded-xl transition-all text-sm">
                                ${isFull ? 'Full' : 'View'}
                            </a>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function stripHtml(html) {
            if (!html) return '';
            // Create a temporary div element
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            // Get text content (strips all HTML tags)
            const text = tmp.textContent || tmp.innerText || '';
            // Trim whitespace and collapse multiple spaces
            return text.replace(/\s+/g, ' ').trim();
        }

        // Load events on page load
        loadEvents();
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
