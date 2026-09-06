<?php
/**
 * Combined public listings feed (API key): events + programs
 * Query: type=all|event|program, search, category, date_from, date_to, page, per_page
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
use Headcount\Services\PublicListingService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Cache-Control: public, max-age=60, must-revalidate');

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
$orgRow = $db->queryOne('SELECT timezone FROM organizations WHERE id = :id', ['id' => $organizationId]);
$timezone = is_array($orgRow) ? ($orgRow['timezone'] ?? null) : null;

$svc = new PublicListingService($db);
$result = $svc->list($organizationId, [
    'type' => $_GET['type'] ?? 'all',
    'search' => $_GET['search'] ?? '',
    'category' => $_GET['category'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    'per_page' => isset($_GET['per_page']) ? (int) $_GET['per_page'] : 12,
], [
    'audience' => 'public',
    'timezone' => $timezone,
]);

jsonResponse([
    'success' => true,
    'items' => $result['items'],
    'categories' => $result['categories'],
    'count' => count($result['items']),
    'total' => $result['total'],
    'page' => $result['page'],
    'per_page' => $result['per_page'],
    'total_pages' => $result['total_pages'],
    'timezone' => $result['timezone'],
]);
