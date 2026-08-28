<?php
/**
 * Public coupon validation for paid event RSVP, program registration, and facility booking.
 * GET/POST action=validate — no admin auth. Logged-in members are checked against per-user limits.
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
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Services\CouponService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'valid' => false, 'message' => 'Configuration not found'], 500);
}

$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    jsonResponse(['success' => false, 'valid' => false, 'message' => 'Database initialization failed'], 500);
}

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    jsonResponse(['success' => false, 'valid' => false, 'message' => 'Method not allowed'], 405);
}

$input = json_decode((string) @file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$input = array_merge($_GET, $_POST, $input);

$action = strtolower(trim((string) ($input['action'] ?? 'validate')));
if ($action !== 'validate') {
    jsonResponse(['success' => false, 'valid' => false, 'message' => 'Unknown action'], 400);
}

$code = strtoupper(trim((string) ($input['code'] ?? $input['coupon_code'] ?? '')));
$type = strtolower(trim((string) ($input['type'] ?? $input['entity_type'] ?? '')));
$entityId = (int) ($input['id'] ?? $input['entity_id'] ?? 0);

if ($code === '') {
    jsonResponse([
        'success' => true,
        'valid' => false,
        'empty' => true,
        'message' => 'Enter a coupon code',
    ]);
}

if (!in_array($type, ['event', 'program', 'facility'], true) || $entityId <= 0) {
    jsonResponse(['success' => false, 'valid' => false, 'message' => 'Missing booking details'], 400);
}

$db = Database::getInstance();
$orgId = 0;
$userId = null;
if (PortalAuthMiddleware::isAuthenticated()) {
    $orgId = (int) (PortalAuthMiddleware::getOrganizationId() ?? 0);
    $memberId = PortalAuthMiddleware::getMemberId();
    $userId = $memberId ? (int) $memberId : null;
}
if ($orgId <= 0) {
    $orgId = (int) (headcount_resolve_portal_organization_id(null, $config, $db) ?? 0);
}
if ($orgId <= 0) {
    jsonResponse(['success' => false, 'valid' => false, 'message' => 'Organization not configured'], 400);
}

$svc = new CouponService();
$result = $svc->validate($orgId, $code, $type, $entityId, $userId);
if (empty($result['valid']) || empty($result['coupon'])) {
    jsonResponse([
        'success' => true,
        'valid' => false,
        'message' => (string) ($result['message'] ?? 'Invalid code'),
    ]);
}

$public = CouponService::publicDiscount($result['coupon']);
$label = $public['label'];
jsonResponse([
    'success' => true,
    'valid' => true,
    'code' => $public['code'],
    'percent_off' => $public['percent_off'],
    'amount_off' => $public['amount_off'],
    'label' => $label,
    'message' => $label !== '' ? ('Coupon applied: ' . $label) : 'Coupon applied',
]);
