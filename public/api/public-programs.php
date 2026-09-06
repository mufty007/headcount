<?php
/**
 * Public programs feed for WordPress (API key)
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
use Headcount\Services\ProgramService;

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
$limit = isset($_GET['limit']) ? min(100, (int) $_GET['limit']) : 50;
$programId = isset($_GET['id']) ? (int) $_GET['id'] : null;

try {
    $db->queryOne('SELECT 1 FROM programs LIMIT 1');
} catch (\Throwable $e) {
    jsonResponse(['success' => false, 'message' => 'Programs not installed'], 503);
}

$where = 'p.organization_id = :org AND p.status = \'published\' AND p.show_on_public_site = 1';
$params = ['org' => $organizationId];
if ($programId) {
    $where .= ' AND p.id = :pid';
    $params['pid'] = $programId;
}

$sql = "SELECT p.id, p.title, p.description, p.banner_image, p.location, p.is_virtual,
        p.pricing_type, p.price_amount, p.billing_interval, p.recurrence_type,
        p.session_start_time, p.session_end_time, p.starts_on, p.ends_on,
        pc.name AS category_name, pc.slug AS category_slug
        FROM programs p
        LEFT JOIN program_categories pc ON pc.id = p.category_id
        WHERE {$where}
        ORDER BY p.title ASC
        LIMIT " . (int) $limit;

$programs = $db->query($sql, $params);

$progSvc = new ProgramService();
$pidsPub = array_map(static function ($row) {
    return (int) ($row['id'] ?? 0);
}, $programs);
$presentersByProgram = $progSvc->listPresentersForPrograms($pidsPub);
$orgTz = null;
try {
    $orgTzRow = $db->queryOne('SELECT timezone FROM organizations WHERE id = :id', ['id' => $organizationId]);
    $orgTz = is_array($orgTzRow) ? ($orgTzRow['timezone'] ?? null) : null;
} catch (\Throwable $e) {
    $orgTz = null;
}
$nextById = $progSvc->nextUpcomingSessionsByProgramIds($pidsPub, $orgTz);

foreach ($programs as &$p) {
    $banner = $p['banner_image'] ?? '';
    if ($banner && filter_var($banner, FILTER_VALIDATE_URL)) {
        $p['banner_image_url'] = $banner;
    } elseif ($banner) {
        $p['banner_image_url'] = hc_public_api_image_url(ltrim($banner, '/'));
    } else {
        $p['banner_image_url'] = null;
    }
    $p['next_session'] = $nextById[(int) ($p['id'] ?? 0)] ?? null;
    $pidPub = (int) ($p['id'] ?? 0);
    $p['presenters'] = [];
    foreach ($presentersByProgram[$pidPub] ?? [] as $pr) {
        $ip = isset($pr['image_path']) ? trim((string) $pr['image_path']) : '';
        $imageUrl = null;
        if ($ip !== '') {
            $imageUrl = filter_var($ip, FILTER_VALIDATE_URL) ? $ip : hc_public_api_image_url($ip);
        }
        $p['presenters'][] = [
            'display_name' => $pr['display_name'] ?? '',
            'title' => isset($pr['title']) && $pr['title'] !== null && trim((string) $pr['title']) !== ''
                ? trim((string) $pr['title'])
                : null,
            'image_url' => $imageUrl,
        ];
    }
}
unset($p);

jsonResponse([
    'success' => true,
    'programs' => $programs,
    'count' => count($programs),
]);
