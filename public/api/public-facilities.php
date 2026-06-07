<?php
/**
 * Public Facilities API (API key) — for WordPress embed
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
$svc = new FacilityService();
if (!$svc->tableExists()) {
    jsonResponse(['success' => true, 'facilities' => []]);
}

$role = ($_GET['audience'] ?? 'guest') === 'member' ? 'member' : 'guest';
$facilities = $svc->listBookableForRole($organizationId, $role);

// Include both guest and member bookable for public grid unless filtered
if (empty($_GET['audience'])) {
    $member = $svc->listBookableForRole($organizationId, 'member');
    $byId = [];
    foreach (array_merge($facilities, $member) as $f) {
        $byId[$f['id']] = $f;
    }
    $facilities = array_values($byId);
}

if (!empty($_GET['id'])) {
    $one = $svc->getByIdForOrg((int) $_GET['id'], $organizationId);
    if (!$one || ($one['status'] ?? '') !== 'active') {
        jsonResponse(['success' => false, 'message' => 'Not found'], 404);
    }
    jsonResponse(['success' => true, 'facility' => $one]);
}

if (!empty($_GET['slug'])) {
    $one = $svc->getBySlugForOrg($_GET['slug'], $organizationId);
    if (!$one || ($one['status'] ?? '') !== 'active') {
        jsonResponse(['success' => false, 'message' => 'Not found'], 404);
    }
    jsonResponse(['success' => true, 'facility' => $one]);
}

jsonResponse(['success' => true, 'facilities' => $facilities]);
