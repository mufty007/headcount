<?php
/**
 * Dynamic Web App Manifest for IMCA portal PWA.
 */
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/portal/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
require_once HC_PROJECT_ROOT . '/src/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/portal/includes/branding.php';

$base = headcount_portal_public_base_path();
$portalStart = ($base === '' ? '' : $base) . '/portal/events.php';
$portalStart = preg_replace('#/+#', '/', $portalStart);
if ($portalStart[0] !== '/') {
    $portalStart = '/' . $portalStart;
}

$iconBase = ($base === '' ? '' : $base) . '/portal/pwa-icon.php';
$iconBase = preg_replace('#/+#', '/', $iconBase);
if ($iconBase[0] !== '/') {
    $iconBase = '/' . $iconBase;
}

$theme = $themeColor ?? '#465fff';

$manifest = [
    'name' => 'IMCA',
    'short_name' => 'IMCA',
    'description' => ($orgDisplayName ?? 'IMCA') . ' — Events, programs, and community',
    'start_url' => $portalStart,
    'scope' => (($base === '' ? '' : $base) . '/portal/') ?: '/portal/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => $theme,
    'orientation' => 'portrait-primary',
    'icons' => [
        [
            'src' => $iconBase . '?size=192',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $iconBase . '?size=512',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any',
        ],
        [
            'src' => $iconBase . '?size=192&maskable=1',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
        [
            'src' => $iconBase . '?size=512&maskable=1',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ],
    ],
    'categories' => ['events', 'social', 'lifestyle'],
    'shortcuts' => [
        [
            'name' => 'Browse Events',
            'short_name' => 'Events',
            'description' => 'View upcoming events',
            'url' => preg_replace('#/+#', '/', (($base === '' ? '' : $base) . '/portal/events.php')),
            'icons' => [['src' => $iconBase . '?size=192', 'sizes' => '192x192']],
        ],
        [
            'name' => 'My RSVPs',
            'short_name' => 'RSVPs',
            'description' => 'View my RSVPs',
            'url' => preg_replace('#/+#', '/', (($base === '' ? '' : $base) . '/portal/my-rsvps.php')),
            'icons' => [['src' => $iconBase . '?size=192', 'sizes' => '192x192']],
        ],
    ],
];

// Normalize shortcut URLs to leading slash
foreach ($manifest['shortcuts'] as &$s) {
    if (($s['url'][0] ?? '') !== '/') {
        $s['url'] = '/' . ltrim($s['url'], '/');
    }
}
unset($s);

echo json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
