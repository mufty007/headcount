<?php
/**
 * Kiosk events feed (public, no auth).
 *
 * GET /api/portal/kiosk-events.php?org=<slug>[&days=7]
 * Returns the organization's published events in the forward window for the
 * digital-signage display to poll. Read-only; exposes no member or secret data.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/helpers.php';
require_once __DIR__ . '/../../portal/includes/kiosk-data.php';

use Headcount\Helpers\Database;

header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');

try {
    $config = require __DIR__ . '/../../../config/config.php';
    $db = Database::getInstance($config['database']);

    $slug = isset($_GET['org']) ? trim((string) $_GET['org']) : '';
    $days = isset($_GET['days']) ? (int) $_GET['days'] : 7;

    $org = headcount_kiosk_org_by_slug($db, $slug);
    if (!$org) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Display not found']);
        exit;
    }

    $kiosk = headcount_kiosk_settings($db, $org);
    if (!$kiosk['enabled']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'disabled' => true, 'message' => 'Display is turned off']);
        exit;
    }

    $timezone = $org['timezone'] ?: 'America/New_York';
    $events = headcount_kiosk_load_events($db, (int) $org['id'], $timezone, $days);

    echo json_encode([
        'success'  => true,
        'org'      => [
            'name'          => $org['name'],
            'slug'          => $org['slug'],
            'primary_color' => $org['primary_color'] ?: '#465fff',
            'timezone'      => $timezone,
        ],
        'days'       => max(1, min(60, $days)),
        'count'      => count($events),
        'events'     => $events,
        'server_now' => (new \DateTime('now', new \DateTimeZone($timezone)))->format('c'),
    ]);
} catch (\Throwable $e) {
    error_log('Kiosk feed error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Feed unavailable']);
}
