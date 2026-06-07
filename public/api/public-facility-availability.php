<?php
/**
 * Public facility availability (API key) — approved bookings for calendar
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
ini_set('display_errors', 0);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Services\FacilityService;
use Headcount\Services\FacilityBookingService;

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

$configFile = __DIR__ . '/../../config/config.php';
$config = require $configFile;
Database::getInstance($config['database']);
$db = Database::getInstance();

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
if (!$apiKey) {
    jsonResponse(['success' => false, 'message' => 'API key required'], 401);
}

$org = $db->queryOne('SELECT id FROM organizations WHERE api_key = ?', [$apiKey]);
if (!$org) {
    jsonResponse(['success' => false, 'message' => 'Invalid API key'], 401);
}

$organizationId = (int) $org['id'];
$facilityId = (int) ($_GET['facility_id'] ?? 0);
$slug = trim((string) ($_GET['facility'] ?? ''));

$facSvc = new FacilityService();
if (!$facSvc->tableExists()) {
    jsonResponse(['success' => true, 'blocks' => []]);
}

if ($facilityId <= 0 && $slug !== '') {
    $f = $facSvc->getBySlugForOrg($slug, $organizationId);
    $facilityId = $f ? (int) $f['id'] : 0;
}

if ($facilityId <= 0) {
    jsonResponse(['success' => false, 'message' => 'facility_id or facility slug required'], 400);
}

$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t', strtotime('+2 months'));

$bookSvc = new FacilityBookingService();
$blocks = $bookSvc->getAvailability($facilityId, $start, $end, false);

$items = [];
foreach ($blocks as $b) {
    $isEvent = ($b['status'] ?? '') === 'blocked' || (is_string($b['id'] ?? null) && str_starts_with((string) $b['id'], 'event-'));
    $items[] = [
        'id' => $isEvent ? (string) $b['id'] : ('fb_' . $b['id']),
        'type' => $isEvent ? 'imca_event' : 'facility_booking',
        'title' => $b['title'],
        'start_date' => substr($b['start_datetime'], 0, 10),
        'start_time' => substr($b['start_datetime'], 11, 8),
        'end_time' => substr($b['end_datetime'], 11, 8),
        'status' => $b['status'],
        'start_datetime' => $b['start_datetime'],
        'end_datetime' => $b['end_datetime'],
    ];
}

jsonResponse(['success' => true, 'blocks' => $items, 'facility_id' => $facilityId]);
