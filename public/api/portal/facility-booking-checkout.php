<?php
/**
 * Member facility booking — start Stripe Checkout (manual capture hold).
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
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityPaymentService;
use Headcount\Services\FacilityService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Configuration not found'], 500);
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Database initialization failed'], 500);
}

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

PortalAuthMiddleware::requireAuth();

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$input = array_merge($_POST, $input);
CsrfMiddleware::verify($input);

$organizationId = PortalAuthMiddleware::getOrganizationId();
$memberId = PortalAuthMiddleware::getMemberId();

$facSvc = new FacilityService();
if (!$facSvc->tableExists()) {
    jsonResponse(['success' => false, 'message' => 'Facilities not available'], 503);
}

$facilityId = (int) ($input['facility_id'] ?? 0);
$facility = $facSvc->getByIdForOrg($facilityId, $organizationId);
if (!$facility || empty($facility['allow_member_booking'])) {
    jsonResponse(['success' => false, 'message' => 'Facility not available for booking'], 400);
}

$bookingData = [
    'facility_id' => $facilityId,
    'title' => $input['title'] ?? '',
    'purpose' => $input['purpose'] ?? '',
    'start_datetime' => $input['start_datetime'] ?? '',
    'end_datetime' => $input['end_datetime'] ?? '',
];

$paySvc = new FacilityPaymentService();
$res = $paySvc->startCheckout($organizationId, $memberId, $bookingData, 'member', 'portal');

if (!$res['success']) {
    jsonResponse($res, !empty($res['code']) ? (int) $res['code'] : 400);
}

jsonResponse($res);
