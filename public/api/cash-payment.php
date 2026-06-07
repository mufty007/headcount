<?php

/**
 * Cash Payment API
 * POST: create or update cash payment for an attendee at check-in.
 * Auth: admin or coordinator.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\ActivityLogger;

if (!headers_sent()) {
    header('Content-Type: application/json');
}

AuthMiddleware::requireAdminOrCoordinator();

if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$db = Database::getInstance();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? 'create'; // 'create' | 'update' | 'delete'

// Check that payments table supports cash (migration 025)
$columns = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
if (empty($columns)) {
    jsonResponse(['success' => false, 'message' => 'Cash payments not configured. Run migration 025.'], 503);
}

if ($action === 'delete') {
    $paymentId = isset($input['payment_id']) ? (int) $input['payment_id'] : 0;
    if (!$paymentId) {
        jsonResponse(['success' => false, 'message' => 'payment_id is required'], 400);
    }
    $payment = $db->queryOne("SELECT p.*, e.organization_id FROM payments p JOIN events e ON p.event_id = e.id WHERE p.id = :id", ['id' => $paymentId]);
    if (!$payment || (int) $payment['organization_id'] !== (int) $organizationId) {
        jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
    }
    $paymentMethod = $payment['payment_method'] ?? 'stripe';
    if ($paymentMethod !== 'cash') {
        jsonResponse(['success' => false, 'message' => 'Only cash payments can be deleted here'], 400);
    }
    $db->execute("DELETE FROM payments WHERE id = :id", ['id' => $paymentId]);
    $logger = new ActivityLogger($organizationId, $userId);
    $logger->logCashPaymentDeleted($paymentId, (int) $payment['event_id'], (int) $payment['user_id'], (float) $payment['amount']);
    jsonResponse(['success' => true, 'message' => 'Cash payment deleted']);
}

if ($action === 'update') {
    $paymentId = isset($input['payment_id']) ? (int) $input['payment_id'] : 0;
    $amount = isset($input['amount']) ? (float) $input['amount'] : 0;
    if (!$paymentId || $amount <= 0) {
        jsonResponse(['success' => false, 'message' => 'payment_id and positive amount are required'], 400);
    }
    $payment = $db->queryOne("SELECT p.*, e.organization_id FROM payments p JOIN events e ON p.event_id = e.id WHERE p.id = :id", ['id' => $paymentId]);
    if (!$payment || (int) $payment['organization_id'] !== (int) $organizationId) {
        jsonResponse(['success' => false, 'message' => 'Payment not found'], 404);
    }
    $paymentMethod = $payment['payment_method'] ?? 'stripe';
    if ($paymentMethod !== 'cash') {
        jsonResponse(['success' => false, 'message' => 'Only cash payments can be edited here'], 400);
    }
    $previousAmount = (float) $payment['amount'];
    $db->execute("UPDATE payments SET amount = :amount, updated_at = NOW() WHERE id = :id", [
        'amount' => round($amount, 2),
        'id' => $paymentId
    ]);
    $logger = new ActivityLogger($organizationId, $userId);
    $logger->logCashPaymentUpdated(
        $paymentId,
        (int) $payment['event_id'],
        (int) $payment['user_id'],
        round($amount, 2),
        $previousAmount
    );
    jsonResponse(['success' => true, 'message' => 'Cash payment updated', 'amount' => round($amount, 2)]);
}

// Create
$eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
$targetUserId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
$amount = isset($input['amount']) ? (float) $input['amount'] : 0;
$attendanceId = isset($input['attendance_id']) ? (int) $input['attendance_id'] : null;

if (!$eventId || !$targetUserId || $amount <= 0) {
    jsonResponse(['success' => false, 'message' => 'event_id, user_id, and positive amount are required'], 400);
}

$event = $db->queryOne("SELECT id FROM events WHERE id = :id AND organization_id = :org_id", [
    'id' => $eventId,
    'org_id' => $organizationId
]);
if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

$user = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
    'id' => $targetUserId,
    'org_id' => $organizationId
]);
if (!$user) {
    jsonResponse(['success' => false, 'message' => 'User not found'], 404);
}

$amount = round($amount, 2);

// Existing cash payment for this event+user?
$existing = $db->queryOne(
    "SELECT id, amount FROM payments WHERE event_id = :event_id AND user_id = :user_id AND (payment_method = 'cash' OR (payment_method IS NULL AND stripe_payment_intent_id IS NULL))",
    ['event_id' => $eventId, 'user_id' => $targetUserId]
);

if ($existing) {
    $paymentId = (int) $existing['id'];
    $previousAmount = (float) $existing['amount'];
    $db->execute("UPDATE payments SET amount = :amount, recorded_by = :recorded_by, attendance_id = COALESCE(:attendance_id, attendance_id), updated_at = NOW() WHERE id = :id", [
        'amount' => $amount,
        'recorded_by' => $userId,
        'attendance_id' => $attendanceId,
        'id' => $paymentId
    ]);
    $logger = new ActivityLogger($organizationId, $userId);
    $logger->logCashPaymentUpdated($paymentId, $eventId, $targetUserId, $amount, $previousAmount);
    jsonResponse(['success' => true, 'message' => 'Cash payment updated', 'payment_id' => $paymentId, 'amount' => $amount]);
}

$insertData = [
    'event_id' => $eventId,
    'user_id' => $targetUserId,
    'amount' => $amount,
    'currency' => 'USD',
    'status' => 'paid',
    'payment_method' => 'cash',
    'recorded_by' => $userId,
];
if ($attendanceId) {
    $insertData['attendance_id'] = $attendanceId;
}
$paymentId = $db->insert('payments', $insertData);

$logger = new ActivityLogger($organizationId, $userId);
$logger->logCashPayment($eventId, $targetUserId, $amount, $paymentId);

jsonResponse(['success' => true, 'message' => 'Cash payment recorded', 'payment_id' => $paymentId, 'amount' => $amount]);
