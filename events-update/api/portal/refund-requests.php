<?php

/**
 * Portal Refund Requests API
 * GET: list current user's refund requests
 * POST: create a refund request (event_id, reason)
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
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Security;

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
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');
PortalAuthMiddleware::requireAuth();

$memberId = PortalAuthMiddleware::getMemberId();
$organizationId = PortalAuthMiddleware::getOrganizationId();
$db = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// GET: list my refund requests
if ($method === 'GET') {
    $tableExists = $db->query("SHOW TABLES LIKE 'refund_requests'");
    if (empty($tableExists)) {
        jsonResponse(['success' => true, 'requests' => []]);
    }
    $rows = $db->query(
        "SELECT rr.*, e.title as event_title, e.event_date 
         FROM refund_requests rr 
         JOIN events e ON rr.event_id = e.id 
         WHERE rr.user_id = :user_id 
         ORDER BY rr.created_at DESC",
        ['user_id' => $memberId]
    );
    jsonResponse(['success' => true, 'requests' => $rows]);
}

// POST: create refund request
if ($method !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$tableExists = $db->query("SHOW TABLES LIKE 'refund_requests'");
if (empty($tableExists)) {
    jsonResponse(['success' => false, 'message' => 'Refund requests are not available'], 503);
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$eventId = isset($input['event_id']) ? (int)$input['event_id'] : 0;
$reason = trim($input['reason'] ?? '');

if (!$eventId || $reason === '') {
    jsonResponse(['success' => false, 'message' => 'event_id and reason are required'], 400);
}

$event = $db->queryOne(
    "SELECT id, title, organization_id, event_date FROM events WHERE id = :id AND organization_id = :org_id",
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

$org = $db->queryOne("SELECT refund_request_days_after_event FROM organizations WHERE id = :id", ['id' => $organizationId]);
$refundDays = isset($org['refund_request_days_after_event']) && $org['refund_request_days_after_event'] !== null && $org['refund_request_days_after_event'] !== '' ? (int)$org['refund_request_days_after_event'] : null;
if ($refundDays !== null) {
    $eventDate = strtotime($event['event_date'] . ' 23:59:59');
    $deadline = strtotime("+{$refundDays} days", $eventDate);
    if (time() > $deadline) {
        jsonResponse(['success' => false, 'message' => 'Refund requests are only accepted within ' . $refundDays . ' days of the event'], 400);
    }
}

$rsvp = $db->queryOne(
    "SELECT id FROM rsvps WHERE event_id = :event_id AND user_id = :user_id",
    ['event_id' => $eventId, 'user_id' => $memberId]
);
if (!$rsvp) {
    jsonResponse(['success' => false, 'message' => 'You do not have an RSVP for this event'], 400);
}

$attendance = $db->queryOne(
    "SELECT id FROM attendance WHERE event_id = :event_id AND user_id = :user_id AND checked_in_at IS NOT NULL",
    ['event_id' => $eventId, 'user_id' => $memberId]
);
if ($attendance) {
    jsonResponse(['success' => false, 'message' => 'You attended this event and are not eligible for a refund'], 400);
}

$existing = $db->queryOne(
    "SELECT id FROM refund_requests WHERE event_id = :event_id AND user_id = :user_id AND status = 'pending'",
    ['event_id' => $eventId, 'user_id' => $memberId]
);
if ($existing) {
    jsonResponse(['success' => false, 'message' => 'You already have a pending refund request for this event'], 400);
}

$payment = $db->queryOne(
    "SELECT id FROM payments WHERE event_id = :event_id AND user_id = :user_id AND status = 'paid'",
    ['event_id' => $eventId, 'user_id' => $memberId]
);
$paymentId = $payment ? (int)$payment['id'] : null;

$db->insert('refund_requests', [
    'organization_id' => $organizationId,
    'event_id' => $eventId,
    'user_id' => $memberId,
    'payment_id' => $paymentId,
    'reason' => $reason,
    'status' => 'pending',
]);

jsonResponse(['success' => true, 'message' => 'Refund request submitted. You will be notified when it is reviewed.']);
