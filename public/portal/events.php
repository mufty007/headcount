<?php

/**
 * Public catalog: events and programs on one page with sidebar filters.
 * No authentication required.
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $dbPortal = Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');
if (empty($baseUrlPath)) {
    $baseUrlPath = '';
}

$listingsApiUrl = rtrim($baseUrlPath, '/') . '/api/portal/listings.php';
if ($listingsApiUrl === '/api/portal/listings.php' || strpos($listingsApiUrl, '/') !== 0) {
    $listingsApiUrl = '/' . ltrim($listingsApiUrl, '/');
}

$type = strtolower(trim((string) ($_GET['type'] ?? 'all')));
if (!in_array($type, ['all', 'event', 'program', 'events', 'programs'], true)) {
    $type = 'all';
}
if ($type === 'events') {
    $type = 'event';
}
if ($type === 'programs') {
    $type = 'program';
}

$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$isLoggedIn = PortalAuthMiddleware::isAuthenticated();
$member = $isLoggedIn ? PortalAuthMiddleware::getMember() : null;

$portalOrganizationIdForApi = headcount_resolve_portal_organization_id(
    $isLoggedIn ? PortalAuthMiddleware::getOrganizationId() : null,
    $config,
    $dbPortal
);

$pageTitle = 'Events & Programs';

$browseBase = $baseUrlPath . '/portal/events.php';
$browseQuery = static function (array $overrides = []) use ($type, $category, $search, $dateFrom, $dateTo): string {
    $q = [
        'type' => $type,
        'category' => $category,
        'search' => $search,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
    ];
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }
    if (($q['type'] ?? 'all') === 'all') {
        unset($q['type']);
    }
    foreach (['category', 'search', 'date_from', 'date_to', 'page'] as $k) {
        if (!isset($q[$k]) || $q[$k] === '' || $q[$k] === null) {
            unset($q[$k]);
        }
    }
    $qs = http_build_query($q);
    return $qs !== '' ? ('?' . $qs) : '';
};

$filtersOpenDefault = ($category !== '' || $dateFrom !== '' || $dateTo !== '');

require __DIR__ . '/includes/header.php';

$portalFieldClass = 'w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 min-h-[44px]';
$portalLabelClass = 'block text-[11px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-400';
$chipBase = 'inline-flex items-center justify-center min-h-[40px] px-3.5 py-1.5 rounded-xl text-sm font-bold border transition-all';
$chipOn = 'bg-indigo-600 text-white border-indigo-600 shadow-sm';
$chipOff = 'bg-white text-gray-700 border-gray-200 hover:border-indigo-200 hover:text-indigo-700 dark:bg-gray-800 dark:text-gray-200 dark:border-gray-700';
?>
<style>
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
.portal-listings-layout {
    display: grid;
    gap: 1.5rem;
}
@media (min-width: 1024px) {
    .portal-listings-layout {
        grid-template-columns: 17.5rem minmax(0, 1fr);
        align-items: start;
        gap: 2rem;
    }
}
.portal-listings-sidebar {
    position: relative;
}
@media (min-width: 1024px) {
    .portal-listings-sidebar {
        position: sticky;
        top: 5.5rem;
    }
}
.portal-type-chip {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 0.25rem 0.625rem;
    border-radius: 0.5rem;
    line-height: 1.2;
}
</style>

<div x-data="listingsApp()" x-init="init()">
    <div class="mb-5 md:mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Events &amp; Programs</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Browse upcoming events and ongoing programs in one place.</p>
            </div>
            <div class="flex items-center gap-3 self-start sm:self-auto">
                <div class="flex items-center gap-1 bg-gray-100 dark:bg-gray-800 rounded-xl p-1">
                    <button
                        type="button"
                        @click="viewMode = 'card'; saveViewPreference('card')"
                        :class="viewMode === 'card' ? 'bg-white text-indigo-600 shadow-sm dark:bg-gray-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="portal-touch-target px-3 py-1.5 rounded-lg transition-all font-bold text-sm"
                        title="Grid View"
                        aria-label="Grid view"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                    </button>
                    <button
                        type="button"
                        @click="viewMode = 'list'; saveViewPreference('list')"
                        :class="viewMode === 'list' ? 'bg-white text-indigo-600 shadow-sm dark:bg-gray-700 dark:text-indigo-300' : 'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                        class="portal-touch-target px-3 py-1.5 rounded-lg transition-all font-bold text-sm"
                        title="List View"
                        aria-label="List view"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div class="portal-listings-layout mb-8">
        <aside class="portal-listings-sidebar">
            <form method="GET" action="" class="portal-filters space-y-4" x-data="{ filtersOpen: <?= $filtersOpenDefault ? 'true' : 'false' ?> }">
                <div>
                    <span class="<?= $portalLabelClass ?> mb-2">Type</span>
                    <div class="flex flex-wrap gap-2">
                        <a href="<?= htmlspecialchars($browseBase . $browseQuery(['type' => 'all'])) ?>" class="<?= $chipBase ?> <?= $type === 'all' ? $chipOn : $chipOff ?>">All</a>
                        <a href="<?= htmlspecialchars($browseBase . $browseQuery(['type' => 'event'])) ?>" class="<?= $chipBase ?> <?= $type === 'event' ? $chipOn : $chipOff ?>">Events</a>
                        <a href="<?= htmlspecialchars($browseBase . $browseQuery(['type' => 'program'])) ?>" class="<?= $chipBase ?> <?= $type === 'program' ? $chipOn : $chipOff ?>">Programs</a>
                    </div>
                    <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
                </div>

                <div class="space-y-1.5">
                    <label class="<?= $portalLabelClass ?>">Search</label>
                    <div class="relative">
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                               placeholder="Search events and programs…"
                               class="w-full rounded-lg border border-gray-300 bg-white pl-10 pr-3.5 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500 min-h-[44px]">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                            <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </div>
                    </div>
                </div>

                <button type="button" class="portal-filters__toggle lg:!hidden" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen.toString()">
                    <span x-text="filtersOpen ? 'Hide filters' : 'More filters'"></span>
                    <svg class="h-4 w-4 transition-transform" :class="filtersOpen && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>

                <div class="space-y-4 portal-filters__extra lg:!block" :data-collapsed="filtersOpen ? '0' : '1'">
                    <div class="space-y-1.5">
                        <label class="<?= $portalLabelClass ?>">Category</label>
                        <select name="category" class="<?= $portalFieldClass ?>" x-ref="categorySelect">
                            <option value="">All categories</option>
                            <?php if ($category !== ''): ?>
                            <option value="<?= htmlspecialchars($category) ?>" selected><?= htmlspecialchars($category) ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="space-y-1.5">
                        <label class="<?= $portalLabelClass ?>">From date</label>
                        <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>" class="<?= $portalFieldClass ?>" aria-label="From date">
                    </div>
                    <div class="space-y-1.5">
                        <label class="<?= $portalLabelClass ?>">To date</label>
                        <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>" class="<?= $portalFieldClass ?>" aria-label="To date">
                    </div>
                </div>

                <div class="flex flex-col gap-2 pt-1">
                    <button type="submit" class="w-full min-h-[44px] px-6 py-2.5 bg-brand-600 text-white font-bold rounded-xl hover:bg-brand-700 shadow-md shadow-brand-200 transition-all active:scale-95">
                        Apply filters
                    </button>
                    <a href="<?= htmlspecialchars($browseBase) ?>"
                       class="w-full min-h-[44px] px-6 py-2.5 bg-gray-100 text-gray-700 font-bold rounded-xl hover:bg-gray-200 transition-all text-center inline-flex items-center justify-center dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700">
                        Clear
                    </a>
                </div>
            </form>
        </aside>

        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mb-4" x-show="!loading && !error" x-cloak>
                <span x-text="total"></span> result<span x-show="total !== 1">s</span>
            </p>

            <div x-show="loading" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 md:gap-6">
                <template x-for="n in [1,2,3,4,5,6]" :key="n">
                    <div class="bento-card !p-0 overflow-hidden animate-pulse">
                        <div class="portal-event-card__media bg-gray-200 dark:bg-gray-700"></div>
                        <div class="p-6 space-y-3">
                            <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded w-full"></div>
                            <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded w-2/3"></div>
                            <div class="h-10 bg-gray-200 dark:bg-gray-700 rounded w-full mt-4"></div>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="!loading && error" class="text-center py-16 text-red-500 font-medium" x-text="error" x-cloak></div>

            <div x-show="!loading && !error && items.length === 0" x-cloak class="text-center py-20 bg-gray-50 dark:bg-gray-800/40 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700">
                <div class="p-4 bg-white dark:bg-gray-800 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm">
                    <svg width="32" height="32" class="w-8 h-8 text-gray-300 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Nothing to show</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Try adjusting your filters or check back later.</p>
            </div>

            <div x-show="!loading && !error && viewMode === 'card' && items.length > 0" class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 md:gap-6">
                <template x-for="item in items" :key="item.type + '-' + item.id">
                    <div class="bento-card group flex flex-col h-full min-h-0 !p-0 overflow-hidden hover:border-indigo-200 transition-all duration-300">
                        <div class="portal-event-card__media relative overflow-hidden p-6"
                             :class="item.image_url ? '' : 'bg-gradient-to-br from-indigo-500 to-purple-600'">
                            <template x-if="item.image_url">
                                <div>
                                    <img :src="item.image_url" alt="" class="absolute inset-0 w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" loading="lazy" role="presentation">
                                    <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
                                </div>
                            </template>
                            <div class="absolute top-0 left-0 p-4 z-10">
                                <span class="portal-type-chip border"
                                      :class="item.type === 'program'
                                        ? 'bg-violet-600/90 text-white border-white/30'
                                        : 'bg-indigo-600/90 text-white border-white/30'"
                                      x-text="item.type === 'program' ? 'Program' : 'Event'"></span>
                            </div>
                            <div class="absolute top-0 right-0 p-4 z-10 flex flex-wrap gap-1 justify-end">
                                <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-purple-500/80 backdrop-blur-md text-white rounded-lg border border-white/30" x-show="item.is_recurring">Recurring</span>
                                <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-white/20 backdrop-blur-md text-white rounded-lg border border-white/30" x-show="item.category" x-text="item.category"></span>
                            </div>
                            <div class="absolute bottom-0 left-0 p-6 w-full z-10 pr-4">
                                <h3 class="text-lg sm:text-xl font-bold text-white text-balance leading-snug line-clamp-3 drop-shadow-sm" x-text="item.title"></h3>
                            </div>
                        </div>
                        <div class="p-6 flex-1 flex flex-col min-h-0 min-w-0">
                            <div class="mb-3" x-show="statusBadge(item)">
                                <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border"
                                      :class="statusBadgeClass(item)" x-text="statusBadge(item)"></span>
                            </div>
                            <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 leading-relaxed text-pretty break-words" x-text="descPreview(item)"></p>
                            <div class="space-y-3 mb-6">
                                <div class="flex items-start gap-3 text-sm font-medium text-gray-700 dark:text-gray-300 min-w-0">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center flex-shrink-0 text-indigo-600 dark:text-indigo-300">
                                        <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    </div>
                                    <span class="min-w-0 pt-1" x-text="item.meta_line || 'Date TBA'"></span>
                                </div>
                                <div class="flex items-start gap-3 text-sm font-medium text-gray-700 dark:text-gray-300 min-w-0" x-show="item.location">
                                    <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/15 flex items-center justify-center flex-shrink-0 text-emerald-600 dark:text-emerald-300">
                                        <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    </div>
                                    <span class="min-w-0 flex-1 leading-snug line-clamp-3 break-words" x-text="item.location"></span>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-violet-100 dark:bg-violet-500/15 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-500/30 flex-shrink-0" x-show="item.is_virtual">Virtual</span>
                                </div>
                            </div>
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pt-4 border-t border-gray-100 dark:border-gray-800 flex-shrink-0 gap-3 mt-auto">
                                <div class="flex flex-col">
                                    <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest leading-none mb-1">Price</span>
                                    <span class="text-base font-bold text-gray-900 dark:text-white" x-text="item.price_label"></span>
                                </div>
                                <a :href="detailUrl(item)"
                                   class="w-full sm:w-auto text-center min-h-[44px] inline-flex items-center justify-center px-5 py-2.5 font-bold rounded-xl transition-all active:scale-95"
                                   :class="item.is_full ? 'bg-gray-100 text-gray-400 cursor-not-allowed dark:bg-gray-800 dark:text-gray-500' : 'bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-100'">
                                    <span x-text="item.is_full ? 'Full' : 'Details'"></span>
                                </a>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div x-show="!loading && !error && viewMode === 'list' && items.length > 0" class="bento-card p-0 overflow-hidden" x-cloak>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 dark:bg-gray-800/60 border-b border-gray-200 dark:border-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Title</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">When</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Location</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Price</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-transparent divide-y divide-gray-200 dark:divide-gray-700">
                            <template x-for="item in items" :key="'l-' + item.type + '-' + item.id">
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-4">
                                            <div class="w-16 h-16 rounded-lg flex-shrink-0 overflow-hidden" :class="item.image_url ? '' : 'bg-gradient-to-r from-indigo-500 to-purple-600'">
                                                <img x-show="item.image_url" :src="item.image_url" :alt="item.title" class="w-full h-full object-cover">
                                            </div>
                                            <div class="min-w-0">
                                                <h3 class="text-sm font-bold text-gray-900 dark:text-white line-clamp-2" x-text="item.title"></h3>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5" x-text="descPreview(item)"></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white" x-text="item.meta_line || 'TBA'"></td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-200" x-text="item.location || 'TBA'"></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex flex-wrap gap-1">
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider border"
                                                  :class="item.type === 'program' ? 'bg-violet-50 text-violet-700 border-violet-200 dark:bg-violet-500/15 dark:text-violet-300' : 'bg-indigo-50 text-indigo-600 border-indigo-100 dark:bg-indigo-500/15 dark:text-indigo-300'"
                                                  x-text="item.type === 'program' ? 'Program' : 'Event'"></span>
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-gray-50 text-gray-600 dark:bg-gray-700 dark:text-gray-300" x-show="item.category" x-text="item.category"></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white" x-text="item.price_label"></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <a :href="detailUrl(item)" class="px-4 py-2 bg-indigo-600 text-white hover:bg-indigo-700 font-bold rounded-xl transition-all text-sm">View</a>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <nav class="mt-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" x-show="!loading && totalPages > 1" x-cloak aria-label="Pagination">
                <p class="text-sm text-gray-500 dark:text-gray-400">Page <span x-text="page"></span> of <span x-text="totalPages"></span></p>
                <div class="flex flex-wrap gap-2">
                    <a x-show="page > 1" :href="pageUrl(page - 1)" class="min-h-[40px] px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 font-bold text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Previous</a>
                    <template x-for="n in pageNumbers()" :key="n">
                        <a :href="pageUrl(n)"
                           class="min-h-[40px] min-w-[40px] px-3 py-2 rounded-xl font-bold text-sm text-center"
                           :class="n === page ? 'bg-indigo-600 text-white' : 'border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800'"
                           x-text="n"></a>
                    </template>
                    <a x-show="page < totalPages" :href="pageUrl(page + 1)" class="min-h-[40px] px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 font-bold text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800">Next</a>
                </div>
            </nav>
        </div>
    </div>
</div>

<script>
function listingsApp() {
    const listingsApiUrl = <?= json_encode($listingsApiUrl) ?>;
    const baseUrl = <?= json_encode($baseUrlPath) ?>;
    const orgId = <?= json_encode($portalOrganizationIdForApi) ?>;
    const isLoggedIn = <?= json_encode((bool) $isLoggedIn) ?>;
    const currentCategory = <?= json_encode($category) ?>;

    return {
        viewMode: 'card',
        items: [],
        categories: [],
        loading: true,
        error: '',
        total: 0,
        page: <?= (int) $page ?>,
        totalPages: 1,
        currentCategory,
        isLoggedIn,
        init() {
            if (typeof Storage !== 'undefined') {
                const saved = localStorage.getItem('portalEventsViewMode');
                if (saved === 'card' || saved === 'list') {
                    this.viewMode = saved;
                }
            }
            this.load();
        },
        saveViewPreference(mode) {
            this.viewMode = mode;
            if (typeof Storage !== 'undefined') {
                localStorage.setItem('portalEventsViewMode', mode);
            }
        },
        async load() {
            this.loading = true;
            this.error = '';
            try {
                const params = new URLSearchParams(window.location.search);
                if (orgId != null && Number(orgId) > 0 && !params.has('organization_id')) {
                    params.set('organization_id', String(Number(orgId)));
                }
                if (!params.has('per_page')) {
                    params.set('per_page', '12');
                }
                const url = listingsApiUrl + (params.toString() ? '?' + params.toString() : '');
                const res = await fetch(url, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();
                if (!data || !data.success) {
                    this.error = (data && data.message) ? data.message : 'Could not load listings.';
                    this.items = [];
                    return;
                }
                this.items = data.items || [];
                this.categories = data.categories || [];
                this.total = data.total || 0;
                this.page = data.page || 1;
                this.totalPages = data.total_pages || 1;
                this.syncCategorySelect();
            } catch (e) {
                this.error = 'Failed to load listings. Please try again.';
                this.items = [];
            } finally {
                this.loading = false;
            }
        },
        detailUrl(item) {
            if (!item) return '#';
            if (item.type === 'program') {
                return baseUrl + '/portal/program-details.php?id=' + encodeURIComponent(item.id);
            }
            return baseUrl + '/portal/event-details.php?id=' + encodeURIComponent(item.id);
        },
        pageUrl(n) {
            const params = new URLSearchParams(window.location.search);
            if (n <= 1) {
                params.delete('page');
            } else {
                params.set('page', String(n));
            }
            const qs = params.toString();
            return <?= json_encode($browseBase) ?> + (qs ? '?' + qs : '');
        },
        pageNumbers() {
            const total = this.totalPages;
            const cur = this.page;
            const nums = [];
            const start = Math.max(1, cur - 2);
            const end = Math.min(total, cur + 2);
            for (let i = start; i <= end; i++) nums.push(i);
            return nums;
        },
        stripHtml(html) {
            if (!html) return '';
            const tmp = document.createElement('div');
            tmp.innerHTML = String(html);
            return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
        },
        descPreview(item) {
            let t = this.stripHtml(item && item.description ? item.description : '');
            if (!t) return 'No description provided.';
            if (t.length > 40) return t.slice(0, 40) + '...';
            return t;
        },
        statusBadge(item) {
            if (!item || !this.isLoggedIn) return '';
            if (item.type === 'event' && item.user_rsvp && item.user_rsvp.status) {
                const map = { yes: 'Registered', no: 'Declined', maybe: 'Maybe' };
                return map[item.user_rsvp.status] || item.user_rsvp.status;
            }
            if (item.type === 'program' && item.my_registration_status) {
                const s = String(item.my_registration_status);
                if (s === 'active') return 'Registered';
                if (s === 'pending' || s === 'pending_payment') return 'Pending';
                return s.replace(/_/g, ' ');
            }
            return '';
        },
        syncCategorySelect() {
            const sel = this.$refs.categorySelect;
            if (!sel) return;
            const current = this.currentCategory || '';
            const keep = sel.querySelector('option[value=""]');
            sel.innerHTML = '';
            if (keep) {
                sel.appendChild(keep);
            } else {
                const all = document.createElement('option');
                all.value = '';
                all.textContent = 'All categories';
                sel.appendChild(all);
            }
            (this.categories || []).forEach((c) => {
                const opt = document.createElement('option');
                opt.value = c.value;
                opt.textContent = c.label;
                if (c.value === current) opt.selected = true;
                sel.appendChild(opt);
            });
            if (current && !Array.from(sel.options).some((o) => o.value === current)) {
                const opt = document.createElement('option');
                opt.value = current;
                opt.textContent = current;
                opt.selected = true;
                sel.appendChild(opt);
            }
        },
        statusBadgeClass(item) {
            const label = this.statusBadge(item);
            if (label === 'Registered') return 'bg-emerald-50 text-emerald-700 border-emerald-100 dark:bg-emerald-500/15 dark:text-emerald-300';
            if (label === 'Declined') return 'bg-rose-50 text-rose-700 border-rose-100';
            if (label === 'Maybe' || label === 'Pending') return 'bg-amber-50 text-amber-700 border-amber-100';
            return 'bg-gray-50 text-gray-700 border-gray-100';
        }
    };
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
