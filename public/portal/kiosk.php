<?php
/**
 * Kiosk / Digital Signage Display  (PUBLIC — no authentication)
 *
 *   /portal/kiosk.php?org=<slug>[&mode=split|board|slideshow][&days=7][&interval=8]
 *
 * split     = 3-card grid slider + prayer strip (lobby default)
 * slideshow = full-page hero slider + prayer strip
 * board     = static card grid + prayer strip
 *
 * Press "m" to cycle layouts, "f" for fullscreen.
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
$reqMode = (isset($_GET['mode']) && in_array($_GET['mode'], ['split', 'board', 'slideshow'], true)) ? $_GET['mode'] : null;
$reqDays = isset($_GET['days']) ? max(1, min(60, (int) $_GET['days'])) : null;
$reqInterval = isset($_GET['interval']) ? max(3, min(60, (int) $_GET['interval'])) : null;

$org = headcount_kiosk_org_by_slug($db, $slug);

$reqPath = str_replace('\\', '/', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/portal/kiosk.php', PHP_URL_PATH));
$apiBaseWeb = preg_replace('#/portal/[^/]*$#', '', $reqPath);
$apiBaseWeb = rtrim((string) $apiBaseWeb, '/');
$feedUrl = $apiBaseWeb . '/api/portal/kiosk-events.php';

$signageCss = 'html,body{height:100%;margin:0}body{display:flex;align-items:center;justify-content:center;background:#faf9f6;color:#0a1230;font-family:Montserrat,system-ui,sans-serif;text-align:center;padding:2rem}h1{font-size:1.5rem;margin:0 0 .5rem}p{color:#6b7085;margin:0}code{background:#eceae4;padding:.15rem .4rem;border-radius:.3rem}';

if (!$org) {
    http_response_code(404);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Display not found</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;800&display=swap" rel="stylesheet">
    <style><?= $signageCss ?></style></head>
    <body><div><h1>Display not found</h1><p>Add your organization slug to the address, e.g. <code>?org=your-org</code>.</p></div></body></html>
    <?php
    exit;
}

$kiosk = headcount_kiosk_settings($db, $org);
$mode = $reqMode ?? $kiosk['mode'];
$days = $reqDays ?? $kiosk['days'];
$interval = $reqInterval ?? $kiosk['interval'];
$timezone = $org['timezone'] ?: 'America/New_York';
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $org['primary_color']) ? $org['primary_color'] : '#9a7b1f';
$genericAccents = ['#465fff', '#3b82f6', '#3B82F6', '#465FFF'];
if (in_array($accent, $genericAccents, true)) {
    $accent = '#9a7b1f';
}

if (!$kiosk['enabled']) {
    http_response_code(403);
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($org['name']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;800&display=swap" rel="stylesheet">
    <style><?= $signageCss ?></style></head>
    <body><div><h1><?= htmlspecialchars($org['name']) ?></h1><p>The events display is currently turned off.</p></div></body></html>
    <?php
    exit;
}

$events = headcount_kiosk_load_items($db, (int) $org['id'], $timezone, $days);
$prayer = headcount_kiosk_prayer_times($org, $timezone);
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
    'prayer'   => $prayer,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($org['name']) ?> — What's Happening</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0a1230;
            --gold: <?= htmlspecialchars($accent) ?>;
            --gold-hi: #c9a227;
            --paper: #faf9f6;
            --muted: #6b7085;
            --muted-2: #8a8f9c;
            --line: rgba(10,18,48,0.08);
            --ease: cubic-bezier(0.65, 0, 0.35, 1);
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; background: var(--paper); color: var(--ink); }
        body { font-family: Montserrat, system-ui, sans-serif; overflow: hidden; }
        .kiosk {
            height: 100%;
            display: flex;
            flex-direction: column;
            background: var(--paper);
            color: var(--ink);
        }
        .kiosk-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 3.75vw;
            height: 13.7vh;
            background: #fff;
            border-bottom: 1px solid rgba(10,18,48,0.09);
            flex: none;
        }
        .kiosk-brand { display: flex; align-items: center; gap: 1.25vw; min-width: 0; }
        .kiosk-logo {
            width: 7.4vh; height: 7.4vh; border-radius: 18px; object-fit: contain;
            background: #fff;
        }
        .kiosk-logo-fallback {
            width: 7.4vh; height: 7.4vh; border-radius: 18px;
            border: 2px dashed rgba(10,18,48,0.25);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8vh; font-weight: 800; color: var(--ink); background: var(--paper);
        }
        .kiosk-title { font-size: 3.9vh; font-weight: 900; letter-spacing: -0.01em; line-height: 1.05; }
        .kiosk-org {
            margin-top: 0.4vh; font-size: 1.85vh; font-weight: 600;
            letter-spacing: 0.22em; color: var(--gold); text-transform: uppercase;
        }
        .kiosk-clock { text-align: right; }
        .kiosk-clock-time { font-size: 5.2vh; font-weight: 800; line-height: 1; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
        .kiosk-clock-date { margin-top: 0.4vh; font-size: 1.85vh; font-weight: 600; letter-spacing: 0.1em; color: var(--muted); text-transform: uppercase; }
        .kiosk-stage { flex: 1; min-height: 0; display: flex; flex-direction: column; position: relative; overflow: hidden; }
        .kiosk-stage-pad { padding: 5.2vh 3.75vw 0; flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .kiosk-viewport { overflow: hidden; flex: 1; min-height: 0; container-type: inline-size; }
        .kiosk-track { display: flex; gap: 1.77vw; height: 100%; will-change: transform; }
        .kiosk-track.is-animating { transition: transform 0.7s var(--ease); }
        .kiosk-card {
            width: calc((100cqw - 3.54vw) / 3);
            flex: none;
            height: 100%;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 28px;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .kiosk-card-img {
            height: 24.8vh;
            flex: none;
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: flex-end;
            justify-content: flex-end;
            padding: 1.6vh 1.4vw;
        }
        .kiosk-card-body {
            padding: 3vh 1.77vw 3.3vh;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
        }
        .kiosk-card-meta { display: flex; align-items: center; gap: 0.7vw; }
        .kiosk-tag {
            padding: 0.85vh 0.95vw;
            border-radius: 999px;
            background: var(--ink);
            color: #fff;
            font-size: 1.55vh;
            font-weight: 800;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }
        .kiosk-tag-gold {
            display: inline-block;
            padding: 1.1vh 1.35vw;
            border-radius: 999px;
            background: var(--gold);
            color: #fff;
            font-size: 1.85vh;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .kiosk-when { font-size: 1.75vh; font-weight: 700; letter-spacing: 0.06em; color: var(--muted-2); text-transform: uppercase; }
        .kiosk-card-title {
            font-size: 3.7vh;
            font-weight: 800;
            line-height: 1.12;
            letter-spacing: -0.015em;
            margin-top: 1.85vh;
            text-wrap: pretty;
        }
        .kiosk-card-time { font-size: 3vh; font-weight: 800; color: var(--gold); margin-top: auto; padding-top: 2.2vh; }
        .kiosk-card-place { font-size: 1.95vh; font-weight: 500; color: var(--muted); margin-top: 0.75vh; line-height: 1.35; }
        .kiosk-dots { display: flex; justify-content: center; gap: 12px; padding: 3.15vh 0 2.8vh; flex: none; }
        .kiosk-dot { height: 8px; width: 8px; border-radius: 999px; background: rgba(10,18,48,0.18); transition: width 0.4s ease, background 0.4s ease; }
        .kiosk-dot.is-on { width: 56px; background: var(--gold); }
        .kiosk-prayer {
            height: 17.2vh;
            flex: none;
            background: var(--ink);
            color: #fff;
            display: flex;
            align-items: center;
            padding: 0 3.75vw;
            gap: 2.3vw;
        }
        .kiosk-prayer.is-hero { height: 18.5vh; padding: 0 4.6vw; gap: 2.5vw; }
        .kiosk-prayer-lead { flex: none; display: flex; flex-direction: column; gap: 0.55vh; }
        .kiosk-prayer-kicker { font-size: 1.67vh; font-weight: 700; letter-spacing: 0.22em; color: var(--gold-hi); }
        .kiosk-prayer-next { font-size: 2.4vh; font-weight: 700; }
        .kiosk-prayer-clock { font-size: 4.1vh; font-weight: 800; line-height: 1; letter-spacing: -0.02em; font-variant-numeric: tabular-nums; }
        .kiosk-prayer-date { font-size: 1.67vh; font-weight: 600; letter-spacing: 0.12em; color: #9aa3bd; text-transform: uppercase; }
        .kiosk-prayer-rule { width: 1px; height: 10vh; background: rgba(255,255,255,0.16); flex: none; }
        .kiosk-prayers { flex: 1; display: grid; grid-template-columns: repeat(6, 1fr); gap: 0.85vw; min-width: 0; }
        .kiosk-salah {
            padding: 1.67vh 1.15vw;
            border-radius: 16px;
            display: flex;
            flex-direction: column;
            gap: 0.55vh;
            background: rgba(255,255,255,0.07);
        }
        .kiosk-salah.is-next { background: var(--gold-hi); color: var(--ink); }
        .kiosk-salah-name { font-size: 1.67vh; font-weight: 700; letter-spacing: 0.16em; color: #9aa3bd; text-transform: uppercase; }
        .kiosk-salah.is-next .kiosk-salah-name { color: rgba(10,18,48,0.7); }
        .kiosk-salah-time { font-size: 3.15vh; font-weight: 800; letter-spacing: -0.01em; font-variant-numeric: tabular-nums; }
        .kiosk-hero { display: flex; height: 100%; width: 100%; }
        .kiosk-hero-copy {
            flex: 1; min-width: 0;
            padding: 7vh 3.3vw 6.3vh 4.6vw;
            display: flex; flex-direction: column; background: #fff;
        }
        .kiosk-hero-img {
            width: 39.6vw; flex: none; height: 100%;
            background-size: cover; background-position: center;
        }
        .kiosk-hero-title {
            font-size: 9.6vh; font-weight: 900; line-height: 1.02;
            letter-spacing: -0.03em; margin-top: 2.8vh; text-wrap: pretty;
        }
        .kiosk-hero-blurb {
            font-size: 3.15vh; font-weight: 600; color: #4a5065;
            margin-top: 2.4vh; line-height: 1.4; max-width: 47vw;
        }
        .kiosk-hero-meta { margin-top: auto; display: flex; align-items: flex-end; gap: 2.9vw; }
        .kiosk-hero-label { font-size: 1.67vh; font-weight: 700; letter-spacing: 0.2em; color: var(--muted-2); }
        .kiosk-hero-when { font-size: 4.1vh; font-weight: 800; margin-top: 0.75vh; }
        .kiosk-hero-time { font-size: 3.5vh; font-weight: 800; color: var(--gold); }
        .kiosk-hero-place { font-size: 2.8vh; font-weight: 600; margin-top: 1.1vh; color: #3d4356; line-height: 1.35; max-width: 27vw; }
        .kiosk-hero-sep { width: 1px; height: 8.9vh; background: rgba(10,18,48,0.12); }
        .kiosk-hero-bar {
            height: 6.5vh; flex: none; display: flex; align-items: center;
            justify-content: space-between; padding: 0 4.6vw;
            background: #fff; border-top: 1px solid var(--line);
        }
        .kiosk-counter { font-size: 1.75vh; font-weight: 600; letter-spacing: 0.14em; color: var(--muted-2); }
        .kiosk-hero-track { display: flex; height: 100%; will-change: transform; transition: transform 0.85s var(--ease); }
        .kiosk-hero-slide { width: 100vw; flex: none; height: 100%; }
        .kiosk-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 4vh; }
        .kiosk-empty h2 { font-size: 4vh; font-weight: 800; margin: 0; }
        .kiosk-empty p { font-size: 2vh; color: var(--muted); margin: 1.2vh 0 0; }
        .kiosk-board-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.77vw;
            align-content: start;
            height: 100%;
            overflow: hidden;
        }
        .kiosk-board-grid .kiosk-card { width: auto; height: auto; min-height: 0; }
        @media (prefers-reduced-motion: reduce) {
            .kiosk-track.is-animating, .kiosk-hero-track { transition: none; }
        }
    </style>
</head>
<body>
    <div class="kiosk" id="kioskApp">
        <noscript>
            <div class="kiosk-stage-pad">
                <p class="kiosk-title">What's Happening</p>
                <?php foreach (array_slice($events, 0, 3) as $ev): ?>
                    <p><strong><?= htmlspecialchars($ev['title']) ?></strong> — <?= htmlspecialchars($ev['time_pretty']) ?></p>
                <?php endforeach; ?>
            </div>
        </noscript>
    </div>
    <script>
    (function () {
        'use strict';
        var DATA = <?= json_encode($initial, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        var tz = DATA.org.timezone || 'America/New_York';
        var orgName = DATA.org.name || '';
        var logoUrl = DATA.org.logo_url || '';
        var mode = DATA.mode || 'split';
        var events = DATA.events || [];
        var prayer = DATA.prayer || { available: false, timings: [], note: null };
        var app = document.getElementById('kioskApp');
        var gridOff = 0, gridAnim = true, hero = 0;
        var gridTimer = null, heroTimer = null, resetT = null, reanimT = null;

        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        }
        function kindLabel(ev) {
            if (ev && ev.kind === 'program') return 'Program';
            if (ev && ev.category) return String(ev.category);
            return 'Event';
        }
        function logoHtml(sizeClass) {
            if (logoUrl) return '<img class="kiosk-logo" src="' + esc(logoUrl) + '" alt="">';
            var ch = (orgName || '?').charAt(0).toUpperCase();
            return '<div class="kiosk-logo-fallback">'+ esc(ch) +'</div>';
        }
        function imgBg(ev, tall) {
            var hue = parseInt(ev.hue, 10) || 214;
            if (ev.banner_url) {
                return 'background-image:url(' + JSON.stringify(String(ev.banner_url)) + ')';
            }
            var a = tall ? 16 : 14, b = tall ? 32 : 28;
            return 'background:repeating-linear-gradient(135deg,hsl(' + hue + ' 22% 92%) 0 ' + a + 'px,hsl(' + hue + ' 24% 88%) ' + a + 'px ' + b + 'px)';
        }
        function nowParts() {
            var now = new Date();
            var clock = new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit', hour12: true }).format(now);
            var date = new Intl.DateTimeFormat('en-US', { timeZone: tz, weekday: 'long', month: 'long', day: 'numeric' }).format(now).toUpperCase();
            var hm = new Intl.DateTimeFormat('en-US', { timeZone: tz, hour: 'numeric', minute: '2-digit', hourCycle: 'h23' }).formatToParts(now);
            var h = 0, m = 0;
            hm.forEach(function (p) {
                if (p.type === 'hour') h = parseInt(p.value, 10) || 0;
                if (p.type === 'minute') m = parseInt(p.value, 10) || 0;
            });
            return { clock: clock, date: date, minutes: h * 60 + m };
        }
        function nextPrayer(mins) {
            var rows = (prayer && prayer.timings) ? prayer.timings : [];
            var idx = -1;
            for (var i = 0; i < rows.length; i++) {
                var pm = typeof rows[i].minutes === 'number' ? rows[i].minutes : -1;
                if (pm > mins) { idx = i; break; }
            }
            if (idx < 0 && rows.length) idx = 0;
            var name = idx >= 0 ? rows[idx].name : '';
            var delta = 0;
            if (idx >= 0 && typeof rows[idx].minutes === 'number') {
                delta = rows[idx].minutes - mins;
                if (delta < 0) delta += 1440;
            }
            var cd = '';
            if (name && prayer.available) {
                cd = (delta >= 60 ? Math.floor(delta / 60) + 'h ' : '') + (delta % 60) + 'm';
            }
            return { idx: idx, name: name, countdown: cd };
        }
        function prayerCells(nextIdx) {
            var rows = (prayer && prayer.timings) ? prayer.timings : [];
            if (!prayer.available || !rows.length) {
                return '<div class="kiosk-prayer-next" style="color:#9aa3bd">' + esc(prayer.note || 'Set city in Settings') + '</div>';
            }
            var html = '<div class="kiosk-prayers">';
            for (var i = 0; i < rows.length; i++) {
                var on = i === nextIdx;
                html += '<div class="kiosk-salah' + (on ? ' is-next' : '') + '">' +
                    '<div class="kiosk-salah-name">' + esc(rows[i].name) + '</div>' +
                    '<div class="kiosk-salah-time">' + esc(rows[i].time) + '</div></div>';
            }
            return html + '</div>';
        }
        function headerHtml(showClock) {
            return '<header class="kiosk-header">' +
                '<div class="kiosk-brand">' + logoHtml() +
                    '<div><div class="kiosk-title">What\'s Happening</div>' +
                    '<div class="kiosk-org">' + esc(orgName) + '</div></div></div>' +
                (showClock ? '<div class="kiosk-clock"><div class="kiosk-clock-time" data-clock></div><div class="kiosk-clock-date" data-date></div></div>' : '') +
                '</header>';
        }
        function cardHtml(ev) {
            return '<article class="kiosk-card">' +
                '<div class="kiosk-card-img" style="' + imgBg(ev, false) + '"></div>' +
                '<div class="kiosk-card-body">' +
                    '<div class="kiosk-card-meta"><div class="kiosk-tag">' + esc(kindLabel(ev)) + '</div>' +
                    '<div class="kiosk-when">' + esc(ev.when_label || ev.day_label || '') + '</div></div>' +
                    '<div class="kiosk-card-title">' + esc(ev.title) + '</div>' +
                    '<div class="kiosk-card-time">' + esc(ev.time_pretty || '') + '</div>' +
                    '<div class="kiosk-card-place">' + esc(ev.location || '') + '</div>' +
                '</div></article>';
        }
        function emptyHtml() {
            return '<div class="kiosk-empty"><h2>Nothing coming up this week</h2><p>New events and programs appear here automatically.</p></div>';
        }
        function dotsHtml(count, active) {
            var html = '<div class="kiosk-dots">';
            for (var i = 0; i < count; i++) {
                html += '<div class="kiosk-dot' + (i === active ? ' is-on' : '') + '"></div>';
            }
            return html + '</div>';
        }
        function prayerBarGrid() {
            var p = nowParts();
            var nx = nextPrayer(p.minutes);
            var lead = prayer.available && nx.name
                ? '<div class="kiosk-prayer-kicker">TODAY\'S PRAYERS</div><div class="kiosk-prayer-next">' + esc(nx.name) + ' in ' + esc(nx.countdown) + '</div>'
                : '<div class="kiosk-prayer-kicker">TODAY\'S PRAYERS</div><div class="kiosk-prayer-next">' + esc(prayer.note || 'Set city in Settings') + '</div>';
            return '<footer class="kiosk-prayer"><div class="kiosk-prayer-lead">' + lead + '</div>' + prayerCells(nx.idx) + '</footer>';
        }
        function prayerBarHero() {
            var p = nowParts();
            var nx = nextPrayer(p.minutes);
            return '<footer class="kiosk-prayer is-hero">' +
                '<div class="kiosk-prayer-lead"><div class="kiosk-prayer-clock" data-clock>' + esc(p.clock) + '</div>' +
                '<div class="kiosk-prayer-date" data-date>' + esc(p.date) + '</div></div>' +
                '<div class="kiosk-prayer-rule"></div>' +
                prayerCells(nx.idx) + '</footer>';
        }
        function renderGrid(staticBoard) {
            if (!events.length) {
                app.innerHTML = headerHtml(true) + '<div class="kiosk-stage">' + emptyHtml() + '</div>' + prayerBarGrid();
                stampClock();
                return;
            }
            var list = staticBoard ? events.slice(0, 9) : events.concat(events);
            var cards = '';
            for (var i = 0; i < list.length; i++) cards += cardHtml(list[i]);
            var inner;
            if (staticBoard) {
                inner = '<div class="kiosk-stage-pad"><div class="kiosk-viewport"><div class="kiosk-board-grid">' + cards + '</div></div></div>';
            } else {
                inner = '<div class="kiosk-stage-pad"><div class="kiosk-viewport"><div id="kioskTrack" class="kiosk-track' + (gridAnim ? ' is-animating' : '') + '">' + cards + '</div></div>' +
                    dotsHtml(events.length, gridOff % events.length) + '</div>';
            }
            app.innerHTML = headerHtml(true) + '<div class="kiosk-stage">' + inner + '</div>' + prayerBarGrid();
            stampClock();
            if (!staticBoard) applyGridTransform();
        }
        function applyGridTransform() {
            var track = document.getElementById('kioskTrack');
            if (!track) return;
            var card = track.querySelector('.kiosk-card');
            if (!card) return;
            var gap = parseFloat(getComputedStyle(track).columnGap || getComputedStyle(track).gap) || 0;
            var step = card.getBoundingClientRect().width + gap;
            track.classList.toggle('is-animating', gridAnim);
            track.style.transform = 'translateX(-' + (gridOff * step) + 'px)';
        }
        function renderHero() {
            if (!events.length) {
                app.innerHTML = '<div class="kiosk-stage">' + emptyHtml() + '</div>' + prayerBarHero();
                stampClock();
                return;
            }
            var slides = '';
            for (var i = 0; i < events.length; i++) {
                var ev = events[i];
                slides += '<div class="kiosk-hero-slide"><div class="kiosk-hero">' +
                    '<div class="kiosk-hero-copy">' +
                        '<div class="kiosk-brand" style="gap:1.15vw">' + logoHtml() +
                            '<div class="kiosk-org" style="margin:0">WHAT\'S HAPPENING</div></div>' +
                        '<div style="margin-top:auto"><div class="kiosk-tag-gold">' + esc(kindLabel(ev)) + '</div>' +
                        '<div class="kiosk-hero-title">' + esc(ev.title) + '</div>' +
                        (ev.blurb ? '<div class="kiosk-hero-blurb">' + esc(ev.blurb) + '</div>' : '') + '</div>' +
                        '<div class="kiosk-hero-meta">' +
                            '<div><div class="kiosk-hero-label">WHEN</div><div class="kiosk-hero-when">' + esc(ev.when_label || ev.date_pretty || '') + '</div>' +
                            '<div class="kiosk-hero-time">' + esc(ev.time_pretty || '') + '</div></div>' +
                            '<div class="kiosk-hero-sep"></div>' +
                            '<div><div class="kiosk-hero-label">WHERE</div><div class="kiosk-hero-place">' + esc(ev.location || '') + '</div></div>' +
                        '</div></div>' +
                    '<div class="kiosk-hero-img" style="' + imgBg(ev, true) + '"></div>' +
                '</div></div>';
            }
            app.innerHTML =
                '<div class="kiosk-stage">' +
                    '<div class="kiosk-hero-track" id="kioskHeroTrack" style="transform:translateX(-' + (hero * 100) + 'vw)">' + slides + '</div>' +
                '</div>' +
                '<div class="kiosk-hero-bar">' + dotsHtml(events.length, hero) +
                    '<div class="kiosk-counter">' + (hero + 1) + ' / ' + events.length + '</div></div>' +
                prayerBarHero();
            stampClock();
        }
        function stampClock() {
            var p = nowParts();
            app.querySelectorAll('[data-clock]').forEach(function (el) { el.textContent = p.clock; });
            app.querySelectorAll('[data-date]').forEach(function (el) { el.textContent = p.date; });
        }
        function render() {
            if (mode === 'slideshow') renderHero();
            else renderGrid(mode === 'board');
        }
        function advanceGrid() {
            if (events.length <= 3) return;
            var n = events.length;
            var next = gridOff + 1;
            gridAnim = true;
            gridOff = next;
            applyGridTransform();
            var dots = app.querySelectorAll('.kiosk-dot');
            dots.forEach(function (d, i) { d.classList.toggle('is-on', i === (gridOff % n)); });
            if (next >= n) {
                clearTimeout(resetT);
                resetT = setTimeout(function () {
                    gridAnim = false;
                    gridOff = 0;
                    applyGridTransform();
                    clearTimeout(reanimT);
                    reanimT = setTimeout(function () { gridAnim = true; applyGridTransform(); }, 60);
                }, 720);
            }
        }
        function startTimers() {
            clearInterval(gridTimer);
            clearInterval(heroTimer);
            var ms = (DATA.interval || 8) * 1000;
            if (mode === 'split' && events.length > 3) {
                gridTimer = setInterval(advanceGrid, ms);
            } else if (mode === 'slideshow' && events.length > 1) {
                heroTimer = setInterval(function () {
                    hero = (hero + 1) % events.length;
                    renderHero();
                }, ms);
            }
        }
        function refresh() {
            var url = DATA.feedUrl + '?org=' + encodeURIComponent(DATA.org.slug) + '&days=' + (DATA.days || 7) + '&_=' + Date.now();
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d || !d.success) return;
                    events = d.events || [];
                    if (d.prayer) prayer = d.prayer;
                    if (gridOff >= events.length) gridOff = 0;
                    if (hero >= events.length) hero = 0;
                    render();
                    startTimers();
                })
                .catch(function () {});
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'm' || e.key === 'M') {
                mode = mode === 'split' ? 'board' : (mode === 'board' ? 'slideshow' : 'split');
                gridOff = 0; hero = 0; gridAnim = true;
                render(); startTimers();
            } else if (e.key === 'f' || e.key === 'F') {
                if (!document.fullscreenElement) document.documentElement.requestFullscreen && document.documentElement.requestFullscreen();
                else document.exitFullscreen && document.exitFullscreen();
            }
        });
        window.addEventListener('resize', function () {
            if (mode === 'split') applyGridTransform();
        });

        render();
        startTimers();
        setInterval(function () {
            stampClock();
            if (mode !== 'slideshow') {
                var bar = app.querySelector('.kiosk-prayer');
                if (bar && !bar.classList.contains('is-hero')) {
                    var nxt = bar.querySelector('.kiosk-prayer-next');
                    var p = nowParts();
                    var nx = nextPrayer(p.minutes);
                    if (nxt && prayer.available && nx.name) nxt.textContent = nx.name + ' in ' + nx.countdown;
                    var cells = bar.querySelectorAll('.kiosk-salah');
                    cells.forEach(function (c, i) { c.classList.toggle('is-next', i === nx.idx); });
                }
            }
        }, 1000);
        setInterval(refresh, 60 * 1000);
        setTimeout(function () { location.reload(); }, 60 * 60 * 1000);
    })();
    </script>
</body>
</html>
