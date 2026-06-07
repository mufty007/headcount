<?php
/**
 * Portal Facility Bookings API (member auth)
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityService;
use Headcount\Services\FacilityBookingService;
use Headcount\Services\FacilityEmailService;
use Headcount\Services\ActivityLogger;

$configFile = __DIR__ . '/../../../config/config.php';
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

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$input = array_merge($_POST, $input);
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

$facSvc = new FacilityService();
if (!$facSvc->tableExists()) {
    jsonResponse(['success' => false, 'message' => 'Facilities not available'], 503);
}

$bookSvc = new FacilityBookingService();
$emailSvc = new FacilityEmailService($config);

// Public read endpoints for facility list (guest browse page can use org from config)
if ($method === 'GET' && $action === 'facilities') {
    $db = Database::getInstance();
    $orgId = headcount_resolve_portal_organization_id(
        PortalAuthMiddleware::isAuthenticated() ? PortalAuthMiddleware::getOrganizationId() : null,
        $config,
        $db
    );
    if (!$orgId) {
        jsonResponse(['success' => false, 'message' => 'Organization not configured'], 400);
    }
    $role = PortalAuthMiddleware::isAuthenticated() ? 'member' : 'guest';
    $facilities = $facSvc->listBookableForRole($orgId, $role);
    jsonResponse(['success' => true, 'facilities' => $facilities]);
}

if ($method === 'GET' && $action === 'availability') {
    $db = Database::getInstance();
    $orgId = headcount_resolve_portal_organization_id(
        PortalAuthMiddleware::isAuthenticated() ? PortalAuthMiddleware::getOrganizationId() : null,
        $config,
        $db
    );
    if (!$orgId) {
        jsonResponse(['success' => false, 'message' => 'Organization not configured'], 400);
    }
    $facilityId = (int) ($_GET['facility_id'] ?? 0);
    $facility = $facSvc->getByIdForOrg($facilityId, $orgId);
    if (!$facility) {
        jsonResponse(['success' => false, 'message' => 'Facility not found'], 404);
    }
    $start = $_GET['start'] ?? date('Y-m-d');
    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+60 days'));
    $blocks = $bookSvc->getAvailability($facilityId, $start, $end, true);
    jsonResponse(['success' => true, 'blocks' => $blocks, 'facility' => $facility]);
}

PortalAuthMiddleware::requireAuth();
$memberId = PortalAuthMiddleware::getMemberId();
$organizationId = PortalAuthMiddleware::getOrganizationId();

try {
    if ($method === 'GET' && ($action === 'list' || $action === 'my')) {
        $bookings = $bookSvc->listForUser($memberId, $organizationId);
        jsonResponse(['success' => true, 'bookings' => $bookings]);
    }

    if ($method === 'POST' && $action === 'create') {
        CsrfMiddleware::verify($input);
        $res = $bookSvc->createBooking($organizationId, $memberId, $input, 'member', 'portal');
        if (!$res['success']) {
            jsonResponse($res, !empty($res['code']) ? (int) $res['code'] : 400);
        }
        $emailSvc->notifyAdminsPending($res['booking'], $organizationId);
        $emailSvc->sendPendingConfirmation($res['booking'], $organizationId);
        $logger = new ActivityLogger($organizationId, $memberId);
        $logger->log('facility_booking_requested', 'Member requested facility booking #' . $res['id'], 'facility_booking', $res['id']);
        jsonResponse(['success' => true, 'id' => $res['id'], 'booking' => $res['booking']]);
    }

    if ($method === 'POST' && $action === 'cancel') {
        CsrfMiddleware::verify($input);
        $id = (int) ($input['id'] ?? 0);
        $res = $bookSvc->cancelBooking($id, $organizationId, $memberId, false);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $logger = new ActivityLogger($organizationId, $memberId);
        $logger->log('facility_booking_cancelled', 'Member cancelled facility booking #' . $id, 'facility_booking', $id);
        jsonResponse(['success' => true, 'booking' => $res['booking']]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
} catch (\Throwable $e) {
    error_log('portal facility-bookings: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error'], 500);
}
