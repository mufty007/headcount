<?php
/**
 * Admin API: Facility bookings (list, approve, reject, staff create, cancel)
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
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\FacilityBookingService;
use Headcount\Services\FacilityService;
use Headcount\Services\ActivityLogger;
use Headcount\Services\FacilityEmailService;

header('Content-Type: application/json');

$configFile = __DIR__ . '/../../config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Config missing'], 500);
}
$config = require $configFile;
Database::getInstance($config['database']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuthMiddleware::check();
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$userRole = $_SESSION['role'] ?? 'admin';
$isStaff = in_array($userRole, ['admin', 'coordinator'], true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

$facSvc = new FacilityService();
$bookSvc = new FacilityBookingService();
if (!$facSvc->tableExists()) {
    jsonResponse(['success' => false, 'message' => 'Facilities tables not installed.'], 503);
}

$emailSvc = new FacilityEmailService($config);

/**
 * @return array{success:bool,message?:string,code?:int,booking?:array}
 */
$assertBookingAccess = static function (int $bookingId) use ($facSvc, $bookSvc, $organizationId, $userId, $userRole): array {
    $booking = $bookSvc->getByIdForOrg($bookingId, $organizationId);
    if (!$booking) {
        return ['success' => false, 'message' => 'Booking not found.', 'code' => 404];
    }
    if (!$facSvc->userCanManageFacility($userId, $organizationId, (int) $booking['facility_id'], $userRole)) {
        return ['success' => false, 'message' => 'You do not have permission to manage this facility.', 'code' => 403];
    }

    return ['success' => true, 'booking' => $booking];
};

$bookingListFilters = static function (array $filters) use ($facSvc, $organizationId, $userId, $userRole): array {
    if ($userRole === 'coordinator') {
        $managed = $facSvc->getManagedFacilityIds($userId, $organizationId);
        if ($managed === []) {
            $filters['facility_ids'] = [0];
        } else {
            if (!empty($filters['facility_id'])) {
                $fid = (int) $filters['facility_id'];
                if (!in_array($fid, $managed, true)) {
                    $filters['facility_ids'] = [0];
                }
            } else {
                $filters['facility_ids'] = $managed;
            }
        }
    }

    return $filters;
};

try {
    if ($method === 'GET' && $action === 'list') {
        AuthMiddleware::requireAdminOrCoordinator();
        $filters = [];
        if (!empty($_GET['status'])) {
            $filters['status'] = $_GET['status'];
        }
        if (!empty($_GET['facility_id'])) {
            $filters['facility_id'] = (int) $_GET['facility_id'];
        }
        if (!empty($_GET['start'])) {
            $filters['start'] = $_GET['start'];
        }
        if (!empty($_GET['end'])) {
            $filters['end'] = $_GET['end'];
        }
        $filters = $bookingListFilters($filters);
        jsonResponse(['success' => true, 'bookings' => $bookSvc->listForOrg($organizationId, $filters)]);
    }

    if ($method === 'GET' && $action === 'availability') {
        AuthMiddleware::requireAdminOrCoordinator();
        $facilityId = (int) ($_GET['facility_id'] ?? 0);
        $start = $_GET['start'] ?? date('Y-m-d');
        $end = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));
        jsonResponse(['success' => true, 'blocks' => $bookSvc->getAvailability($facilityId, $start, $end, true)]);
    }

    if ($method === 'POST' && $action === 'create') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $targetUserId = !empty($input['user_id']) ? (int) $input['user_id'] : $userId;
        $res = $bookSvc->createBooking($organizationId, $targetUserId, $input, 'staff', 'admin');
        if (!$res['success']) {
            jsonResponse($res, !empty($res['code']) ? (int) $res['code'] : 400);
        }
        $emailSvc->notifyAdminsPending($res['booking'], $organizationId);
        $emailSvc->sendPendingConfirmation($res['booking'], $organizationId);
        $logger = new ActivityLogger($organizationId, $userId);
        $logger->log('facility_booking_created', 'Staff created facility booking #' . $res['id'], 'facility_booking', $res['id']);
        jsonResponse(['success' => true, 'id' => $res['id'], 'booking' => $res['booking']]);
    }

    if ($method === 'POST' && $action === 'approve') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $id = (int) ($input['id'] ?? 0);
        $access = $assertBookingAccess($id);
        if (!$access['success']) {
            jsonResponse($access, (int) ($access['code'] ?? 400));
        }
        $res = $bookSvc->approveBooking($id, $organizationId, $userId);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $emailSvc->sendApproved($res['booking'], $organizationId);
        $logger = new ActivityLogger($organizationId, $userId);
        $logger->log('facility_booking_approved', 'Approved facility booking #' . $id, 'facility_booking', $id);
        jsonResponse(['success' => true, 'booking' => $res['booking']]);
    }

    if ($method === 'POST' && $action === 'reject') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $id = (int) ($input['id'] ?? 0);
        $access = $assertBookingAccess($id);
        if (!$access['success']) {
            jsonResponse($access, (int) ($access['code'] ?? 400));
        }
        $reason = trim((string) ($input['reason'] ?? ''));
        $res = $bookSvc->rejectBooking($id, $organizationId, $userId, $reason ?: null);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $emailSvc->sendRejected($res['booking'], $organizationId, $reason);
        $logger = new ActivityLogger($organizationId, $userId);
        $logger->log('facility_booking_rejected', 'Rejected facility booking #' . $id, 'facility_booking', $id);
        jsonResponse(['success' => true, 'booking' => $res['booking']]);
    }

    if ($method === 'POST' && $action === 'cancel') {
        AuthMiddleware::requireAdminOrCoordinator();
        CsrfMiddleware::verify($input);
        $id = (int) ($input['id'] ?? 0);
        $access = $assertBookingAccess($id);
        if (!$access['success']) {
            jsonResponse($access, (int) ($access['code'] ?? 400));
        }
        $res = $bookSvc->cancelBooking($id, $organizationId, $userId, true);
        if (!$res['success']) {
            jsonResponse($res, 400);
        }
        $logger = new ActivityLogger($organizationId, $userId);
        $logger->log('facility_booking_cancelled', 'Cancelled facility booking #' . $id, 'facility_booking', $id);
        jsonResponse(['success' => true, 'booking' => $res['booking']]);
    }

    jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
} catch (\Throwable $e) {
    error_log('facility-bookings API: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Server error'], 500);
}
