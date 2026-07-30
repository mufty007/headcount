<?php
/**
 * Programs listing (members only)
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$isLoggedIn = PortalAuthMiddleware::isAuthenticated();

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/programs.php';
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$pageTitle = 'Programs';
require __DIR__ . '/includes/header.php';
?>
<style>
/* Match events grid: hero media min height */
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

<div x-data="programsPage()" x-init="init()">
    <div class="mb-5 md:mb-8">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Programs</h1>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Browse classes, halaqahs, and ongoing offerings.</p>
            </div>
            <?php if (!$isLoggedIn): ?>
            <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/login.php?redirect=<?= urlencode($baseUrlPath . '/portal/programs.php') ?>"
                   class="flex-1 sm:flex-none text-center min-h-[44px] inline-flex items-center justify-center px-5 py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all text-sm">Sign in</a>
                <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/register.php"
                   class="flex-1 sm:flex-none text-center min-h-[44px] inline-flex items-center justify-center px-5 py-2.5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 text-gray-800 dark:text-gray-100 font-bold rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-all text-sm">Create account</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mb-8">
        <form method="get" action="" class="portal-filters mb-6 space-y-3">
            <div class="space-y-1.5">
                <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-400">Search</label>
                <div class="relative">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                           placeholder="Program name…"
                           class="w-full pl-10 min-h-[44px] rounded-lg border border-gray-300 bg-white dark:border-gray-700 dark:bg-gray-900">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 dark:text-gray-500">
                        <svg width="20" height="20" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                </div>
            </div>
            <div class="flex flex-col sm:flex-row gap-2">
                <button type="submit" class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95">
                    Search
                </button>
                <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/programs.php"
                   class="w-full sm:w-auto min-h-[44px] px-6 py-2.5 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-bold rounded-xl hover:bg-gray-200 transition-all text-center inline-flex items-center justify-center">
                    Clear
                </a>
            </div>
        </form>

        <template x-if="loading">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6">
                <template x-for="n in [1,2,3,4,5,6]" :key="n">
                    <div class="bento-card !p-0 overflow-hidden animate-pulse">
                        <div class="portal-event-card__media bg-gray-200"></div>
                        <div class="p-6 space-y-3">
                            <div class="h-4 bg-gray-200 rounded w-full"></div>
                            <div class="h-3 bg-gray-200 rounded w-2/3"></div>
                            <div class="h-10 bg-gray-200 rounded w-full mt-4"></div>
                        </div>
                    </div>
                </template>
            </div>
        </template>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6" x-show="!loading && programs.length > 0">
            <template x-for="p in programs" :key="p.id">
                <div class="bento-card group flex flex-col h-full min-h-0 !p-0 overflow-hidden hover:border-indigo-200 transition-all duration-300">
                    <div class="portal-event-card__media relative overflow-hidden p-6"
                         :class="p.banner_image_url ? '' : 'bg-gradient-to-br from-indigo-500 to-purple-600'">
                        <template x-if="p.banner_image_url">
                            <div>
                                <img :src="p.banner_image_url" :alt="p.title" class="absolute inset-0 w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" loading="lazy">
                                <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
                            </div>
                        </template>
                        <div class="absolute top-0 right-0 p-4 z-10 flex flex-wrap gap-1 justify-end">
                            <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-white/20 backdrop-blur-md text-white rounded-lg border border-white/30" x-show="p.category_name" x-text="p.category_name || 'Program'"></span>
                        </div>
                        <div class="absolute bottom-0 left-0 p-6 w-full z-10 pr-4">
                            <h3 class="text-lg sm:text-xl font-bold text-white text-balance leading-snug line-clamp-3 drop-shadow-sm" x-text="p.title"></h3>
                        </div>
                        <template x-if="!p.banner_image_url">
                            <svg width="96" height="96" class="absolute -right-4 -bottom-4 w-24 h-24 text-white/10" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"></path></svg>
                        </template>
                    </div>
                    <div class="p-6 flex-1 flex flex-col min-h-0 min-w-0">
                        <div class="mb-3" x-show="registrationBadgeLabel(p)">
                            <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border"
                                  :class="registrationBadgeClass(p)" x-text="registrationBadgeLabel(p)"></span>
                        </div>
                        <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 leading-relaxed text-pretty break-words" x-text="descPreview(p)"></p>

                        <div class="space-y-3 mb-6">
                            <div class="flex items-start gap-3 text-sm font-medium text-gray-700 dark:text-gray-300 min-w-0">
                                <div class="w-8 h-8 rounded-lg bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center flex-shrink-0 text-indigo-600 dark:text-indigo-300">
                                    <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                </div>
                                <span class="min-w-0 pt-1" x-text="formatNextSessionLine(p)"></span>
                            </div>
                            <div class="flex items-start gap-3 text-sm font-medium text-gray-700 dark:text-gray-300 min-w-0">
                                <div class="w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-500/15 flex items-center justify-center flex-shrink-0 text-emerald-600 dark:text-emerald-300">
                                    <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                </div>
                                <span class="min-w-0 flex-1 leading-snug line-clamp-3 break-words" x-text="locationDisplay(p)"></span>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-violet-100 dark:bg-violet-500/15 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-500/30 flex-shrink-0" x-show="isVirtual(p)">Virtual</span>
                            </div>
                        </div>

                        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between pt-4 border-t border-gray-100 dark:border-gray-800 flex-shrink-0 gap-3 mt-auto">
                            <div class="flex flex-col">
                                <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest leading-none mb-1">Price</span>
                                <span class="text-base font-bold text-gray-900 dark:text-white" x-text="priceLabel(p)"></span>
                            </div>
                            <a :href="detailUrl(p)"
                               class="w-full sm:w-auto text-center min-h-[44px] inline-flex items-center justify-center px-5 py-2.5 bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-100 font-bold rounded-xl transition-all active:scale-95">
                                Details
                            </a>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <template x-if="!loading && programs.length === 0">
            <div class="col-span-full text-center py-20 bg-gray-50 dark:bg-gray-800 rounded-3xl border-2 border-dashed border-gray-200 dark:border-gray-700">
                <div class="p-4 bg-white dark:bg-gray-800 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm">
                    <svg width="32" height="32" class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">No programs found</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Try adjusting your search or check back later.</p>
            </div>
        </template>
    </div>
</div>

<script>
function programsPage() {
    const baseUrl = <?= json_encode($baseUrlPath) ?>;
    const apiBase = <?= json_encode($apiBase) ?>;
    const isLoggedIn = <?= json_encode($isLoggedIn) ?>;

    return {
        programs: [],
        loading: true,
        isLoggedIn,
        async init() {
            const params = new URLSearchParams(window.location.search);
            const qs = params.toString();
            const url = qs ? (apiBase + '?' + qs) : apiBase;
            const fetchOpts = isLoggedIn ? { credentials: 'same-origin' } : {};
            const r = await fetch(url, fetchOpts);
            const j = await r.json();
            this.loading = false;
            if (j.success) {
                this.programs = j.programs || [];
            }
        },
        detailUrl(p) {
            return baseUrl + '/portal/program-details.php?id=' + encodeURIComponent(p.id);
        },
        stripHtml(html) {
            if (!html) {
                return '';
            }
            const s = String(html).replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '');
            const d = document.createElement('div');
            d.innerHTML = s;
            return (d.textContent || '').replace(/\s+/g, ' ').trim();
        },
        descPreview(p) {
            let t = this.stripHtml(p && p.description ? p.description : '');
            if (!t) {
                return 'No description provided.';
            }
            if (t.length > 40) {
                return t.slice(0, 40) + '...';
            }
            return t;
        },
        isVirtual(p) {
            return p && (p.is_virtual == 1 || p.is_virtual === true);
        },
        locationDisplay(p) {
            if (!p) {
                return 'TBA';
            }
            if (this.isVirtual(p)) {
                const loc = (p.location || '').trim();
                return loc || 'Online / TBA';
            }
            return (p.location || '').trim() || 'TBA';
        },
        formatNextSessionLine(p) {
            const ns = p && p.next_session;
            if (!ns || !ns.session_date) {
                return 'TBA';
            }
            const raw = ns.session_date;
            const parts = String(raw).split('-').map(Number);
            let dateStr = raw;
            if (parts.length === 3 && !parts.some(isNaN)) {
                const d = new Date(parts[0], parts[1] - 1, parts[2]);
                dateStr = d.toLocaleDateString('en-US', {
                    month: 'short',
                    day: 'numeric',
                    year: 'numeric',
                });
            }
            let timeStr = '';
            if (ns.start_time) {
                const t = String(ns.start_time);
                const hm = t.split(':').map(Number);
                if (hm.length >= 2 && !hm.some(isNaN)) {
                    const td = new Date(2000, 0, 1, hm[0], hm[1]);
                    timeStr = td.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                }
            }
            return timeStr ? (dateStr + ' at ' + timeStr) : dateStr;
        },
        priceLabel(p) {
            if (!p) {
                return '';
            }
            if ((p.pricing_type || 'free') === 'free') {
                return 'Free';
            }
            const amt = p.price_amount != null ? Number(p.price_amount).toFixed(2) : '0.00';
            return '$' + amt;
        },
        registrationBadgeLabel(p) {
            const s = p && p.my_registration_status;
            if (s === 'active') {
                return 'Registered';
            }
            if (s === 'pending') {
                return 'Pending';
            }
            return '';
        },
        registrationBadgeClass(p) {
            const s = p && p.my_registration_status;
            if (s === 'active') {
                return 'bg-emerald-50 dark:bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 border-emerald-100 dark:border-emerald-500/30';
            }
            if (s === 'pending') {
                return 'bg-amber-50 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300 border-amber-100 dark:border-amber-500/30';
            }
            return '';
        },
    };
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
