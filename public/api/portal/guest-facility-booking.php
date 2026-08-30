<?php
/**
 * Guest Facility Booking API (public — no authentication)
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
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityService;
use Headcount\Services\FacilityBookingService;
use Headcount\Services\FacilityEmailService;
use Headcount\Services\ActivityLogger;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
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

$bookSvc = new FacilityBookingService();
$emailSvc = new FacilityEmailService($config);

$guestData = [
    'first_name' => $input['first_name'] ?? '',
    'last_name' => $input['last_name'] ?? '',
    'email' => $input['email'] ?? '',
    'phone' => $input['phone'] ?? '',
];

$bookingData = [
    'facility_id' => $input['facility_id'] ?? 0,
    'start_datetime' => $input['start_datetime'] ?? '',
    'end_datetime' => $input['end_datetime'] ?? '',
    'title' => $input['title'] ?? '',
    'purpose' => $input['purpose'] ?? $input['notes'] ?? '',
];
$bookingData = array_merge($bookingData, headcount_facility_waiver_request_payload($input));

$res = $bookSvc->createGuestBooking($organizationId, $guestData, $bookingData);
if (!$res['success']) {
    jsonResponse($res, !empty($res['code']) ? (int) $res['code'] : 400);
}

$emailSvc->notifyAdminsPending($res['booking'], $organizationId);
$emailSvc->sendGuestPendingConfirmation($res['booking'], $organizationId, !empty($res['is_new_user']));

$logger = new ActivityLogger($organizationId, null);
$logger->log('facility_booking_guest_requested', 'Guest requested facility booking #' . $res['id'], 'facility_booking', $res['id']);

echo json_encode([
    'success' => true,
    'message' => 'Your booking request has been submitted and is pending approval.',
    'booking_id' => $res['id'],
    'complete_account_sent' => !empty($res['is_new_user']),
]);
