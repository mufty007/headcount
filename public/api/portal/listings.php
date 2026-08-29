<?php
/**
 * Portal combined listings: events + programs (session / org scoped)
 * Query: type=all|event|program, search, category, date_from, date_to, page, per_page
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Services\PublicListingService;

header('Content-Type: application/json');

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}
$config = require $configFile;
Database::getInstance($config['database']);
$db = Database::getInstance();

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$isAuthenticated = PortalAuthMiddleware::isAuthenticated();
$organizationId = null;
if ($isAuthenticated) {
    $organizationId = PortalAuthMiddleware::getOrganizationId();
} else {
    $rawOrg = $_GET['organization_id'] ?? null;
    $organizationId = ($rawOrg !== null && $rawOrg !== '') ? (int) $rawOrg : null;
    if (!$organizationId) {
        $organizationId = headcount_resolve_portal_organization_id(null, $config, $db);
    }
}

if (!$organizationId) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Organization not configured for portal']);
    exit;
}

$org = $db->queryOne('SELECT timezone FROM organizations WHERE id = :id', ['id' => (int) $organizationId]);
$timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);

$memberId = $isAuthenticated ? PortalAuthMiddleware::getMemberId() : null;

$svc = new PublicListingService($db);
$result = $svc->list((int) $organizationId, [
    'type' => $_GET['type'] ?? 'all',
    'search' => $_GET['search'] ?? '',
    'category' => $_GET['category'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'page' => isset($_GET['page']) ? (int) $_GET['page'] : 1,
    'per_page' => isset($_GET['per_page']) ? (int) $_GET['per_page'] : 12,
], [
    'audience' => 'portal',
    'member_id' => $memberId,
    'timezone' => $timezone,
]);

echo json_encode([
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
