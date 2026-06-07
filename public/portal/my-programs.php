<?php
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;

PortalAuthMiddleware::requireAuth();

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$pos = strpos($requestPath, '/portal');
$baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/programs.php';
$pageTitle = 'My Programs';
require __DIR__ . '/includes/header.php';
?>
<style>
/* Match events / programs browse: hero media min height */
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

<div class="max-w-8xl mx-auto px-4 py-8" x-data="myProgramsPage()" x-init="init()">
    <div class="mb-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 tracking-tight">My programs</h1>
                <p class="text-sm md:text-base text-gray-500 mt-1">Programs you have joined.</p>
            </div>
        </div>
    </div>

    <template x-if="loading">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
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

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6" x-show="!loading && rows.length > 0">
        <template x-for="row in rows" :key="row.id">
            <div class="bento-card group flex flex-col h-full min-h-0 !p-0 overflow-hidden hover:border-indigo-200 transition-all duration-300">
                <div class="portal-event-card__media relative overflow-hidden p-6"
                     :class="row.banner_image_url ? '' : 'bg-gradient-to-br from-indigo-500 to-purple-600'">
                    <template x-if="row.banner_image_url">
                        <div>
                            <img :src="row.banner_image_url" :alt="row.title" class="absolute inset-0 w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" loading="lazy">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent"></div>
                        </div>
                    </template>
                    <div class="absolute top-0 right-0 p-4 z-10 flex flex-wrap gap-1 justify-end">
                        <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider bg-white/20 backdrop-blur-md text-white rounded-lg border border-white/30" x-show="row.category_name" x-text="row.category_name || 'Program'"></span>
                    </div>
                    <div class="absolute bottom-0 left-0 p-6 w-full z-10 pr-4">
                        <h3 class="text-lg sm:text-xl font-bold text-white text-balance leading-snug line-clamp-3 drop-shadow-sm" x-text="row.title"></h3>
                    </div>
                    <template x-if="!row.banner_image_url">
                        <svg width="96" height="96" class="absolute -right-4 -bottom-4 w-24 h-24 text-white/10" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"></path></svg>
                    </template>
                </div>
                <div class="p-6 flex-1 flex flex-col min-h-0 min-w-0">
                    <div class="mb-3" x-show="registrationBadgeLabel(row)">
                        <span class="px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider rounded-lg border"
                              :class="registrationBadgeClass(row)" x-text="registrationBadgeLabel(row)"></span>
                    </div>
                    <p class="text-gray-600 text-sm mb-4 leading-relaxed text-pretty break-words" x-text="descPreview(row)"></p>

                    <div class="space-y-3 mb-6">
                        <div class="flex items-start gap-3 text-sm font-medium text-gray-700 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0 text-indigo-600">
                                <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                            <span class="min-w-0 pt-1" x-text="formatNextSessionLine(row)"></span>
                        </div>
                        <div class="flex items-start gap-3 text-sm font-medium text-gray-700 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center flex-shrink-0 text-emerald-600">
                                <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            </div>
                            <span class="min-w-0 flex-1 leading-snug line-clamp-3 break-words" x-text="locationDisplay(row)"></span>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider bg-violet-100 text-violet-700 border border-violet-200 flex-shrink-0" x-show="isVirtual(row)">Virtual</span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-gray-100 flex-shrink-0 gap-3 mt-auto">
                        <div class="flex flex-col">
                            <span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest leading-none mb-1">Price</span>
                            <span class="text-base font-bold text-gray-900" x-text="priceLabel(row)"></span>
                        </div>
                        <a :href="detailUrl(row)"
                           class="px-5 py-2.5 bg-indigo-600 text-white hover:bg-indigo-700 shadow-md shadow-indigo-100 font-bold rounded-xl transition-all active:scale-95">
                            Details
                        </a>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <template x-if="!loading && rows.length === 0">
        <div class="col-span-full text-center py-20 bg-gray-50 rounded-3xl border-2 border-dashed border-gray-200">
            <div class="p-4 bg-white rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4 shadow-sm">
                <svg width="32" height="32" class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <h3 class="text-lg font-bold text-gray-900">No programs yet</h3>
            <p class="text-sm text-gray-500 mt-1 max-w-md mx-auto">When you register for a class or ongoing program, it will show up here.</p>
            <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/programs.php" class="inline-flex items-center justify-center mt-6 px-5 py-2.5 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95">
                Browse programs
            </a>
        </div>
    </template>
</div>

<script>
function myProgramsPage() {
    const baseUrl = <?= json_encode($baseUrlPath) ?>;

    return {
        rows: [],
        loading: true,
        async init() {
            const r = await fetch(<?= json_encode($apiBase) ?> + '?action=mine', { credentials: 'same-origin' });
            const j = await r.json();
            this.loading = false;
            if (j.success) {
                this.rows = j.registrations || [];
            }
        },
        detailUrl(row) {
            return baseUrl + '/portal/program-details.php?id=' + encodeURIComponent(row.program_id);
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
        descPreview(row) {
            let t = this.stripHtml(row && row.description ? row.description : '');
            if (!t) {
                return 'No description provided.';
            }
            if (t.length > 40) {
                return t.slice(0, 40) + '...';
            }
            return t;
        },
        isVirtual(row) {
            return row && (row.is_virtual == 1 || row.is_virtual === true);
        },
        locationDisplay(row) {
            if (!row) {
                return 'TBA';
            }
            if (this.isVirtual(row)) {
                const loc = (row.location || '').trim();
                return loc || 'Online / TBA';
            }
            return (row.location || '').trim() || 'TBA';
        },
        formatNextSessionLine(row) {
            const ns = row && row.next_session;
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
        priceLabel(row) {
            if (!row) {
                return '';
            }
            if ((row.pricing_type || 'free') === 'free') {
                return 'Free';
            }
            const amt = row.price_amount != null ? Number(row.price_amount).toFixed(2) : '0.00';
            return '$' + amt;
        },
        registrationBadgeLabel(row) {
            const s = row && row.status;
            if (s === 'active') {
                return 'Registered';
            }
            if (s === 'pending') {
                return 'Pending';
            }
            if (s === 'waitlist') {
                return 'Waitlist';
            }
            if (s === 'cancelled') {
                return 'Cancelled';
            }
            return '';
        },
        registrationBadgeClass(row) {
            const s = row && row.status;
            if (s === 'active') {
                return 'bg-emerald-50 text-emerald-700 border-emerald-100';
            }
            if (s === 'pending') {
                return 'bg-amber-50 text-amber-700 border-amber-100';
            }
            if (s === 'waitlist') {
                return 'bg-blue-50 text-blue-700 border-blue-100';
            }
            if (s === 'cancelled') {
                return 'bg-rose-50 text-rose-700 border-rose-100';
            }
            return 'bg-gray-50 text-gray-700 border-gray-100';
        },
    };
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
