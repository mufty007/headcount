<?php
/**
 * Guest facility booking — start Stripe Checkout (no login).
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityBookingService;
use Headcount\Services\FacilityPaymentService;
use Headcount\Services\FacilityService;

$configFile = __DIR__ . '/../../../config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Configuration not found'], 500);
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Database initialization failed'], 500);
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$input = array_merge($_POST, $input);
CsrfMiddleware::verify($input);

$db = Database::getInstance();
$organizationId = headcount_resolve_portal_organization_id(null, $config, $db);
if (!empty($input['organization_id'])) {
    $organizationId = (int) $input['organization_id'];
}
if (!$organizationId) {
    jsonResponse(['success' => false, 'message' => 'Organization not configured'], 400);
}

$facSvc = new FacilityService();
if (!$facSvc->tableExists()) {
    jsonResponse(['success' => false, 'message' => 'Facilities not available'], 503);
}

$facilityId = (int) ($input['facility_id'] ?? 0);
$facility = $facSvc->getByIdForOrg($facilityId, $organizationId);
if (!$facility || empty($facility['allow_guest_booking'])) {
    jsonResponse(['success' => false, 'message' => 'This facility does not accept guest bookings.'], 400);
}

$guestData = [
    'first_name' => $input['first_name'] ?? '',
    'last_name' => $input['last_name'] ?? '',
    'email' => $input['email'] ?? '',
    'phone' => $input['phone'] ?? '',
];

$bookingData = [
    'facility_id' => $input['facility_id'] ?? 0,
    'title' => $input['title'] ?? '',
    'purpose' => $input['purpose'] ?? '',
    'start_datetime' => $input['start_datetime'] ?? '',
    'end_datetime' => $input['end_datetime'] ?? '',
];

$bookSvc = new FacilityBookingService();
$guestResult = $bookSvc->resolveGuestUser($organizationId, $guestData);
if (!$guestResult['success']) {
    jsonResponse($guestResult, 400);
}

$userId = (int) $guestResult['user_id'];
$email = trim(strtolower((string) ($guestData['email'] ?? '')));

$paySvc = new FacilityPaymentService();
$res = $paySvc->startCheckout($organizationId, $userId, $bookingData, 'guest', 'guest', $email);

if (!$res['success']) {
    jsonResponse($res, !empty($res['code']) ? (int) $res['code'] : 400);
}

$res['is_new_user'] = !empty($guestResult['is_new_user']);
jsonResponse($res);
