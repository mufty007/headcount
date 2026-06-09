<?php
/**
 * Kiosk / Digital Signage Display  (PUBLIC — no authentication)
 *
 * Full-screen board of upcoming PUBLISHED events for one organization, addressed
 * by its public slug. Designed to run unattended on a lobby TV / kiosk: it polls
 * for fresh data, can rotate as a slideshow, and self-heals on transient errors.
 *
 *   /portal/kiosk.php?org=<slug>[&mode=board|slideshow][&days=7][&interval=8]
 *
 * Press "m" to toggle board/slideshow, "f" for fullscreen.
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
require_once __DIR__ . '/includes/kiosk-data.php';

use Headcount\Helpers\Database;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    die('Configuration not found.');
}
$config = require $configFile;

try {
    $db = Database::getInstance($config['database']);
} catch (\Throwable $e) {
    http_response_code(500);
    die('System initialization failed.');
}

$slug = isset($_GET['org']) ? trim((string) $_GET['org']) : '';

// URL params are optional overrides of the org's saved kiosk defaults.
$reqMode = (isset($_GET['mode']) && in_array($_GET['mode'], ['board', 'slideshow'], true)) ? $_GET['mode'] : null;
$reqDays = isset($_GET['days']) ? max(1, min(60, (int) $_GET['days'])) : null;
$reqInterval = isset($_GET['interval']) ? max(3, min(60, (int) $_GET['interval'])) : null;

$org = headcount_kiosk_org_by_slug($db, $slug);

// ---- CSS URL resolution (same approach as the portal header) ----------------
$hcCssDir = realpath(__DIR__ . '/../css');
$hcDocRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
$hcCssWebBase = null;
if ($hcCssDir && $hcDocRoot) {
    $cn = str_replace('\\', '/', $hcCssDir);
    $rn = str_replace('\\', '/', $hcDocRoot);
    if (strpos($cn, $rn) === 0) {
        $hcCssWebBase = '/' . trim(substr($cn, strlen($rn)), '/');
    }
}
$cssUrl = static function (string $file) use ($hcCssDir, $hcCssWebBase): string {
    $url = ($hcCssWebBase !== null) ? ($hcCssWebBase . '/' . $file) : ('/public/css/' . $file);
    $url = preg_replace('#/+#', '/', $url);
    if ($url[0] !== '/') {
        $url = '/' . $url;
    }
    $fs = $hcCssDir ? $hcCssDir . DIRECTORY_SEPARATOR . $file : '';
    $v = ($fs && is_file($fs)) ? (int) @filemtime($fs) : 0;
    return $url . ($v ? '?v=' . $v : '');
};
$tailwindOnDisk = $hcCssDir && is_file($hcCssDir . DIRECTORY_SEPARATOR . 'tailwind-output.css');

// ---- API base for the live feed --------------------------------------------
// Derive from the ACTUAL request path (not SCRIPT_NAME): when this page is served
// through the front controller, SCRIPT_NAME points at index.php, but REQUEST_URI
// always contains "/portal/kiosk...". This keeps the feed/image base consistent
// with however the page itself was reached.
$reqPath = str_replace('\\', '/', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/portal/kiosk.php', PHP_URL_PATH));
$apiBaseWeb = preg_replace('#/portal/[^/]*$#', '', $reqPath);
$apiBaseWeb = rtrim((string) $apiBaseWeb, '/');
$feedUrl = $apiBaseWeb . '/api/portal/kiosk-events.php';

// ---- Not found --------------------------------------------------------------
if (!$org) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Display not found</title>
        <style>
            html,body{height:100%;margin:0}
            body{display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#111827;
                 font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:center;padding:2rem}
            h1{font-size:1.5rem;margin:0 0 .5rem}p{color:#6b7280;margin:0}
            code{background:#e5e7eb;padding:.15rem .4rem;border-radius:.3rem}
        </style>
    </head>
    <body>
        <div>
            <h1>Display not found</h1>
            <p>Add your organization slug to the address, e.g. <code>?org=your-org</code>.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Saved kiosk settings (org owner controls these in Settings -> Kiosk).
$kiosk = headcount_kiosk_settings($db, $org);
$mode = $reqMode ?? $kiosk['mode'];
$days = $reqDays ?? $kiosk['days'];
$interval = $reqInterval ?? $kiosk['interval'];

$timezone = $org['timezone'] ?: 'America/New_York';
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $org['primary_color']) ? $org['primary_color'] : '#465fff';

// Public display turned off by the org owner: show a neutral "off" screen.
if (!$kiosk['enabled']) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($org['name']) ?></title>
    <style>html,body{height:100%;margin:0}body{display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#6b7280;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;text-align:center;padding:2rem}h1{color:#111827;font-size:1.4rem;margin:0 0 .4rem}</style>
    </head><body><div><h1><?= htmlspecialchars($org['name']) ?></h1><p>The events display is currently turned off.</p></div></body></html>
    <?php
    exit;
}

$events = headcount_kiosk_load_events($db, (int) $org['id'], $timezone, $days);
$logoUrl = headcount_kiosk_banner_url($org['logo_path'] ?? null);

$initial = [
    'org' => [
        'name'          => $org['name'],
        'slug'          => $org['slug'],
        'primary_color' => $accent,
        'timezone'      => $timezone,
        'logo_url'      => $logoUrl,
    ],
    'mode'     => $mode,
    'interval' => $interval,
    'days'     => $days,
    'feedUrl'  => $feedUrl,
    'events'   => $events,
];
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($org['name']) ?> — Upcoming Events</title>
    <?php if ($tailwindOnDisk): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($cssUrl('tailwind-output.css')) ?>">
    <?php else: ?>
        <script src="https://cdn.tailwindcss.com"></script>
    <?php endif; ?>
    <style>
        :root { --accent: <?= htmlspecialchars($accent) ?>; }
        html, body { height: 100%; margin: 0; background: #f1f5f9; color-scheme: light; }
        body { font-family: 'Outfit', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; overflow: hidden; color: #111827; }
        .kiosk-bg {
            background:
                radial-gradient(1200px 600px at 100% -10%, color-mix(in srgb, var(--accent) 12%, transparent), transparent),
                radial-gradient(1000px 500px at -10% 110%, color-mix(in srgb, var(--accent) 8%, transparent), transparent),
                #f1f5f9;
        }
        .accent { color: var(--accent); }
        .accent-bg { background: var(--accent); }
        .accent-soft { background: color-mix(in srgb, var(--accent) 12%, #ffffff); }
        .card-banner::after {
            content: ''; position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(255,255,255,.55) 0%, rgba(255,255,255,.92) 100%);
        }
        /* Vertical auto-scroll for an overflowing board */
        @keyframes kioskScroll { from { transform: translateY(0); } to { transform: translateY(var(--scroll-dist, 0)); } }
        .kiosk-scroll { animation: kioskScroll var(--scroll-dur, 30s) linear infinite alternate; }
        .fade-in { animation: kioskFade .6s ease both; }
        @keyframes kioskFade { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        .slide-enter { animation: kioskSlide .7s cubic-bezier(.22,1,.36,1) both; }
        @keyframes kioskSlide { from { opacity: 0; transform: scale(.98); } to { opacity: 1; transform: none; } }
        [hidden] { display: none !important; }
    </style>
</head>
<body class="h-full text-gray-900">
    <div class="kiosk-bg flex h-full flex-col">

        <!-- Header -->
        <header class="flex shrink-0 items-center justify-between border-b border-gray-200 px-8 py-5 lg:px-12 lg:py-6">
            <div class="flex items-center gap-4">
                <?php if ($logoUrl): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="" class="h-12 w-auto max-w-[180px] object-contain lg:h-14">
                <?php else: ?>
                    <div class="accent-bg flex h-12 w-12 items-center justify-center rounded-2xl text-2xl font-black text-white lg:h-14 lg:w-14">
                        <?= htmlspecialchars(strtoupper(substr($org['name'], 0, 1))) ?>
                    </div>
                <?php endif; ?>
                <div>
                    <p class="text-xl font-bold leading-tight text-gray-900 lg:text-2xl"><?= htmlspecialchars($org['name']) ?></p>
                    <p class="text-sm font-medium uppercase tracking-widest text-gray-400">Upcoming Events</p>
                </div>
            </div>
            <div class="text-right">
                <p id="kioskClock" class="text-3xl font-bold tabular-nums text-gray-900 lg:text-4xl">--:--</p>
                <p id="kioskDate" class="text-sm font-medium text-gray-400 lg:text-base">&nbsp;</p>
            </div>
        </header>

        <!-- Content -->
        <main id="kioskRoot" class="relative min-h-0 flex-1 px-8 pb-8 lg:px-12 lg:pb-12">
            <!-- JS renders here. Server-rendered fallback below for no-JS / first paint. -->
            <noscript>
                <div class="grid grid-cols-2 gap-6">
                    <?php foreach ($events as $ev): ?>
                        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                            <p class="accent text-sm font-bold uppercase tracking-wider"><?= htmlspecialchars($ev['day_label']) ?> · <?= htmlspecialchars($ev['time_pretty']) ?></p>
                            <p class="mt-1 text-2xl font-bold text-gray-900"><?= htmlspecialchars($ev['title']) ?></p>
                            <?php if ($ev['location'] !== ''): ?>
                                <p class="mt-1 text-gray-500"><?= htmlspecialchars($ev['location']) ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($events)): ?>
                        <p class="text-gray-500">No upcoming events this week.</p>
                    <?php endif; ?>
                </div>
            </noscript>
        </main>

        <!-- Footer ticker -->
        <footer class="flex shrink-0 items-center justify-between border-t border-gray-200 px-8 py-3 text-xs text-gray-400 lg:px-12">
            <span><span id="kioskCount">0</span> upcoming · next <?= (int) $days ?> days</span>
            <span id="kioskUpdated">&nbsp;</span>
        </footer>
    </div>

    <script>
    (function () {
        'use strict';
        var DATA = <?= json_encode($initial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var tz = DATA.org.timezone || 'America/New_York';
        var mode = DATA.mode;
        var events = DATA.events || [];
        var root = document.getElementById('kioskRoot');
        var slideIndex = 0, slideTimer = null;

        // ---- live clock (in the org's timezone) -----------------------------
        function tick() {
            var now = new Date();
            try {
                document.getElementById('kioskClock').textContent =
                    new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit', hour12: true }).format(now);
                document.getElementById('kioskDate').textContent =
                    new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'long', month: 'long', day: 'numeric' }).format(now);
            } catch (e) {}
        }

        function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

        // ---- renderers ------------------------------------------------------
        function renderEmpty() {
            return '<div class="flex h-full flex-col items-center justify-center text-center fade-in">' +
                '<svg class="mb-6 h-20 w-20 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>' +
                '<p class="text-3xl font-bold text-gray-900">No upcoming events this week</p>' +
                '<p class="mt-2 text-gray-500">Check back soon — new events appear here automatically.</p></div>';
        }

        function cardBoard(ev) {
            var banner = ev.banner_url
                ? '<div class="card-banner absolute inset-0"><img src="' + esc(ev.banner_url) + '" alt="" class="h-full w-full object-cover opacity-25"></div>'
                : '';
            var loc = ev.location ? '<div class="mt-2 flex items-center gap-2 text-gray-500"><svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg><span class="truncate">' + esc(ev.location) + '</span></div>' : '';
            return '<div class="relative overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm fade-in">' + banner +
                '<div class="relative flex items-stretch gap-5 p-6 lg:p-7">' +
                    '<div class="accent-soft flex w-20 shrink-0 flex-col items-center justify-center rounded-2xl py-3">' +
                        '<span class="text-xs font-bold uppercase tracking-wider accent">' + esc(ev.month_short) + '</span>' +
                        '<span class="text-4xl font-black leading-none accent">' + esc(ev.day_num) + '</span>' +
                        '<span class="text-xs font-semibold text-gray-500">' + esc(ev.weekday_short) + '</span>' +
                    '</div>' +
                    '<div class="min-w-0 flex-1">' +
                        '<span class="inline-block rounded-full accent-bg px-3 py-1 text-xs font-bold uppercase tracking-wider text-white">' + esc(ev.day_label) + ' · ' + esc(ev.time_pretty) + '</span>' +
                        '<p class="mt-2 truncate text-2xl font-bold leading-snug text-gray-900 lg:text-3xl">' + esc(ev.title) + '</p>' +
                        loc +
                    '</div>' +
                '</div></div>';
        }

        function renderBoard() {
            if (!events.length) { root.innerHTML = renderEmpty(); return; }
            var cols = events.length > 6 ? 'lg:grid-cols-3' : 'lg:grid-cols-2';
            var html = '<div class="grid h-full content-start gap-5 sm:grid-cols-2 ' + cols + '">';
            for (var i = 0; i < events.length; i++) html += cardBoard(events[i]);
            html += '</div>';
            root.innerHTML = html;
        }

        function renderSlide() {
            if (!events.length) { root.innerHTML = renderEmpty(); return; }
            if (slideIndex >= events.length) slideIndex = 0;
            var ev = events[slideIndex];
            var banner = ev.banner_url
                ? '<div class="card-banner absolute inset-0"><img src="' + esc(ev.banner_url) + '" alt="" class="h-full w-full object-cover opacity-30"></div>'
                : '';
            var loc = ev.location ? '<div class="mt-4 flex items-center justify-center gap-3 text-xl text-gray-500"><svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>' + esc(ev.location) + '</div>' : '';
            var dots = '';
            for (var i = 0; i < events.length; i++) {
                dots += '<span class="h-2.5 rounded-full transition-all ' + (i === slideIndex ? 'w-8 accent-bg' : 'w-2.5 bg-gray-300') + '"></span>';
            }
            root.innerHTML =
                '<div class="relative flex h-full items-center justify-center overflow-hidden rounded-3xl border border-gray-200 bg-white shadow-sm">' + banner +
                    '<div class="slide-enter relative px-8 text-center">' +
                        '<span class="inline-block rounded-full accent-bg px-5 py-2 text-lg font-bold uppercase tracking-widest text-white">' + esc(ev.day_label) + '</span>' +
                        '<p class="mx-auto mt-6 max-w-5xl text-5xl font-black leading-tight text-gray-900 lg:text-7xl">' + esc(ev.title) + '</p>' +
                        '<p class="mt-5 text-2xl font-semibold text-gray-600 lg:text-3xl">' + esc(ev.date_pretty) + ' · ' + esc(ev.time_pretty) + '</p>' +
                        loc +
                    '</div>' +
                    '<div class="absolute inset-x-0 bottom-6 flex items-center justify-center gap-2">' + dots + '</div>' +
                '</div>';
        }

        function render() {
            if (mode === 'slideshow') renderSlide(); else renderBoard();
            document.getElementById('kioskCount').textContent = events.length;
        }

        function startSlide() {
            clearInterval(slideTimer);
            if (mode === 'slideshow' && events.length > 1) {
                slideTimer = setInterval(function () { slideIndex = (slideIndex + 1) % events.length; renderSlide(); }, (DATA.interval || 8) * 1000);
            }
        }

        // ---- live refresh ---------------------------------------------------
        function refresh() {
            var url = DATA.feedUrl + '?org=' + encodeURIComponent(DATA.org.slug) + '&days=' + (DATA.days || 7) + '&_=' + Date.now();
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.success) return;            // keep last good data on error
                    events = d.events || [];
                    if (slideIndex >= events.length) slideIndex = 0;
                    render();
                    var t = new Date();
                    document.getElementById('kioskUpdated').textContent = 'Updated ' +
                        new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit' }).format(t);
                })
                .catch(function () { /* offline: keep showing what we have */ });
        }

        // ---- controls -------------------------------------------------------
        document.addEventListener('keydown', function (e) {
            if (e.key === 'm' || e.key === 'M') {
                mode = (mode === 'slideshow') ? 'board' : 'slideshow';
                slideIndex = 0; render(); startSlide();
            } else if (e.key === 'f' || e.key === 'F') {
                if (!document.fullscreenElement) { document.documentElement.requestFullscreen && document.documentElement.requestFullscreen(); }
                else { document.exitFullscreen && document.exitFullscreen(); }
            }
        });

        // ---- boot -----------------------------------------------------------
        tick(); setInterval(tick, 1000 * 15);
        render(); startSlide();
        setInterval(refresh, 60 * 1000);                       // poll for new data
        setTimeout(function () { location.reload(); }, 60 * 60 * 1000); // 1h self-heal reload
    })();
    </script>
</body>
</html>
