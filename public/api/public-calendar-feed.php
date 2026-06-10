<?php
/**
 * Combined calendar feed: events + program sessions (API key)
 * Query: start=YYYY-MM-DD&end=YYYY-MM-DD
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
ini_set('display_errors', 0);

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\OrganizationApiKeyService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Database::getInstance($config['database']);
$db = Database::getInstance();

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
if (!$apiKey) {
    jsonResponse(['success' => false, 'message' => 'API key required'], 401);
}

$org = OrganizationApiKeyService::verifyKey($db, $apiKey);
if (!$org) {
    jsonResponse(['success' => false, 'message' => 'Invalid API key'], 401);
}

$organizationId = (int) $org['id'];
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t', strtotime('+2 months'));

$items = [];

// Events
$events = $db->query(
    "SELECT e.id, e.title, e.banner_image, e.event_date AS start_date, e.start_time, e.end_time, e.location, e.category
     FROM events e
     WHERE e.organization_id = :org AND e.status = 'published'
     AND e.event_date >= :start AND e.event_date <= :end
     ORDER BY e.event_date, e.start_time",
    ['org' => $organizationId, 'start' => $start, 'end' => $end]
);
foreach ($events as $e) {
    $startDt = $e['start_date'] . ' ' . ($e['start_time'] ?: '00:00:00');
    $endDt = $e['start_date'] . ' ' . ($e['end_time'] ?: '23:59:59');
    $items[] = [
        'type' => 'event',
        'id' => (int) $e['id'],
        'title' => $e['title'],
        'banner_image' => $e['banner_image'],
        'start' => $startDt,
        'end' => $endDt,
        'category' => $e['category'],
        'color_hint' => '#4f46e5',
    ];
}

// Program sessions
if ($db->hasColumn('program_sessions', 'id')) {
    $sessions = $db->query(
        "SELECT s.id, s.session_date, s.start_time, s.end_time, p.id AS program_id, p.title, p.banner_image, pc.name AS category_name
         FROM program_sessions s
         INNER JOIN programs p ON p.id = s.program_id
         LEFT JOIN program_categories pc ON pc.id = p.category_id
         WHERE p.organization_id = :org AND p.status = 'published' AND p.show_on_public_site = 1
         AND s.session_date >= :start AND s.session_date <= :end AND s.status = 'scheduled'",
        ['org' => $organizationId, 'start' => $start, 'end' => $end]
    );
    foreach ($sessions as $s) {
        $sd = $s['session_date'];
        $st = $s['start_time'] ?: '09:00:00';
        $et = $s['end_time'] ?: '10:00:00';
        $items[] = [
            'type' => 'program',
            'id' => (int) $s['program_id'],
            'session_id' => (int) $s['id'],
            'title' => $s['title'],
            'banner_image' => $s['banner_image'],
            'start' => $sd . ' ' . $st,
            'end' => $sd . ' ' . $et,
            'category' => $s['category_name'],
            'color_hint' => '#059669',
        ];
    }
}

usort($items, function ($a, $b) {
    return strcmp($a['start'], $b['start']);
});

jsonResponse(['success' => true, 'items' => $items]);
