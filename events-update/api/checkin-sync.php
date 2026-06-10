<?php

/**
 * Check-In Sync API (offline batch)
 * POST /api/checkin-sync.php
 * Body: { "event_id": 1, "actions": [ { "type": "checkin", "user_id": 2, "client_ts": "2025-03-10T14:30:00" }, { "type": "undo", "user_id": 3 } ] }
 * Applies batch of offline check-in/undo actions; idempotent. Returns updated RSVP list for the event.
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

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
use Headcount\Services\ActivityLogger;

if (!headers_sent()) {
    header('Content-Type: application/json');
}

AuthMiddleware::requireAdminOrCoordinator();

if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$organizationId = AuthMiddleware::getOrganizationId();
$checkedInBy = AuthMiddleware::getUserId();
$db = Database::getInstance();

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    jsonResponse(['success' => false, 'message' => 'Invalid JSON in request body'], 400);
}

$eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
$actions = $input['actions'] ?? [];

if (!$eventId || !is_array($actions)) {
    jsonResponse(['success' => false, 'message' => 'event_id and actions array are required'], 400);
}

// Verify event belongs to organization (no check-in window for sync; Option B)
$event = $db->queryOne("SELECT id, title, event_date FROM events WHERE id = :id AND organization_id = :org_id", [
    'id' => $eventId,
    'org_id' => $organizationId
]);
if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

$activityLogger = new ActivityLogger($organizationId, $checkedInBy);
$results = [];
$applied = 0;

$hasGuestsCol = false;
$hasFmAtt = false;
try {
    $attCols = $db->query("SHOW COLUMNS FROM attendance");
    $attNames = array_column($attCols, 'Field');
    $hasGuestsCol = in_array('guests_checked_in', $attNames, true);
    $hasFmAtt = in_array('family_member_id', $attNames, true);
} catch (\Exception $e) { /* ignore */ }

foreach ($actions as $index => $action) {
    $type = $action['type'] ?? '';
    $userId = isset($action['user_id']) ? (int) $action['user_id'] : 0;
    $clientTs = $action['client_ts'] ?? null;
    $guestsCheckedIn = isset($action['guests_checked_in']) ? max(0, min(20, (int)$action['guests_checked_in'])) : 0;
    $familyMemberId = isset($action['family_member_id']) ? (int) $action['family_member_id'] : 0;
    $partySlot = $familyMemberId > 0 ? $familyMemberId : 0;

    if (!in_array($type, ['checkin', 'undo'], true) || !$userId) {
        $results[] = ['index' => $index, 'ok' => false, 'error' => 'Invalid action type or missing user_id'];
        continue;
    }

    // Verify user belongs to organization
    $user = $db->queryOne("SELECT id, first_name, last_name FROM users WHERE id = :id AND organization_id = :org_id", [
        'id' => $userId,
        'org_id' => $organizationId
    ]);
    if (!$user) {
        $results[] = ['index' => $index, 'ok' => false, 'error' => 'User not found'];
        continue;
    }

    try {
        if ($type === 'checkin') {
            $exSql = 'SELECT id, checked_in_at FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
            $exPar = ['event_id' => $eventId, 'user_id' => $userId];
            if ($hasFmAtt) {
                $exSql .= ' AND IFNULL(family_member_id, 0) = :fmslot';
                $exPar['fmslot'] = $partySlot;
            }
            $existing = $db->queryOne($exSql, $exPar);
            // Same session semantics as checkin.php / checkin-rsvps (DATE match on event_date)
            if ($existing && !empty($existing['checked_in_at'])) {
                $attDate = substr((string) $existing['checked_in_at'], 0, 10);
                $eventDay = substr((string) $event['event_date'], 0, 10);
                if ($attDate === $eventDay) {
                    $results[] = ['index' => $index, 'ok' => true, 'skipped' => 'already_checked_in'];
                    continue;
                }
            }
            // Use client time if provided and valid (Option B), else server time
            $checkedInAt = date('Y-m-d H:i:s');
            if (!empty($clientTs)) {
                $parsed = \DateTime::createFromFormat(\DateTime::ATOM, $clientTs);
                if (!$parsed) {
                    $parsed = \DateTime::createFromFormat('Y-m-d H:i:s', $clientTs);
                }
                if ($parsed) {
                    $checkedInAt = $parsed->format('Y-m-d H:i:s');
                }
            }
            if ($existing) {
                if ($hasGuestsCol) {
                    $db->execute("UPDATE attendance SET checked_in_at = :checked_in_at, checked_in_by = :checked_in_by, guests_checked_in = :guests_checked_in WHERE id = :id", [
                        'checked_in_at' => $checkedInAt,
                        'checked_in_by' => $checkedInBy,
                        'guests_checked_in' => $guestsCheckedIn,
                        'id' => $existing['id']
                    ]);
                } else {
                    $db->execute("UPDATE attendance SET checked_in_at = :checked_in_at, checked_in_by = :checked_in_by WHERE id = :id", [
                        'checked_in_at' => $checkedInAt,
                        'checked_in_by' => $checkedInBy,
                        'id' => $existing['id']
                    ]);
                }
            } else {
                $insertData = [
                    'event_id' => $eventId,
                    'user_id' => $userId,
                    'checked_in_by' => $checkedInBy,
                    'checked_in_at' => $checkedInAt
                ];
                if ($hasGuestsCol) {
                    $insertData['guests_checked_in'] = $guestsCheckedIn;
                }
                if ($hasFmAtt && $familyMemberId > 0) {
                    $insertData['family_member_id'] = $familyMemberId;
                }
                $db->insert('attendance', $insertData);
            }
            $memberName = $user['first_name'] . ' ' . $user['last_name'];
            $activityLogger->logCheckIn($eventId, $userId, $memberName, $event['title']);
            $results[] = ['index' => $index, 'ok' => true];
            $applied++;
        } else {
            $undoSql = 'DELETE FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
            $undoPar = ['event_id' => $eventId, 'user_id' => $userId];
            if ($hasFmAtt) {
                if ($familyMemberId > 0) {
                    $undoSql .= ' AND family_member_id = :fmid';
                    $undoPar['fmid'] = $familyMemberId;
                } else {
                    $undoSql .= ' AND IFNULL(family_member_id, 0) = 0';
                }
            }
            $deleted = $db->execute($undoSql, $undoPar);
            $results[] = ['index' => $index, 'ok' => true];
            $applied++;
        }
    } catch (\Exception $e) {
        error_log("Check-in sync action error: " . $e->getMessage());
        $results[] = ['index' => $index, 'ok' => false, 'error' => $e->getMessage()];
    }
}

// Return updated RSVP list for this event (same shape as checkin-rsvps.php) so client can refresh cache
$rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
$rsvpNames = array_column($rsvpCols, 'Field');
$guestCountCol = in_array('guest_count', $rsvpNames) ? ', r.guest_count' : '';

$fmJoinSync = $hasFmAtt ? ' AND IFNULL(a.family_member_id, 0) = 0' : '';
$sql = "SELECT r.id as rsvp_id, r.user_id, r.status as rsvp_status{$guestCountCol},
        u.first_name, u.last_name, u.email, u.phone,
        a.checked_in_at,
        CASE WHEN a.checked_in_at IS NOT NULL THEN 1 ELSE 0 END as checked_in
        FROM rsvps r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
            AND DATE(a.checked_in_at) = :event_date{$fmJoinSync}
        WHERE r.event_id = :event_id
        ORDER BY checked_in DESC, u.first_name ASC, u.last_name ASC";
$rows = $db->query($sql, ['event_id' => $eventId, 'event_date' => $event['event_date']]);

$paymentCols = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
$hasPaymentMethod = !empty($paymentCols);
$byUser = [];
if (!empty($rows)) {
    $userIds = array_unique(array_column($rows, 'user_id'));
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $pmSelect = $hasPaymentMethod ? 'p.payment_method' : "'stripe' AS payment_method";
    $payments = $db->query(
        "SELECT p.user_id, p.id as payment_id, p.amount as payment_amount, {$pmSelect}, p.status as payment_status, p.refund_amount, p.refunded_at
         FROM payments p
         WHERE p.event_id = ? AND p.user_id IN ($placeholders)
         ORDER BY p.user_id, CASE p.status WHEN 'paid' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, p.id DESC",
        array_merge([$eventId], $userIds)
    );
    foreach ($payments as $p) {
        $uid = (int) $p['user_id'];
        if (isset($byUser[$uid])) {
            continue;
        }
        $amt = (float) $p['payment_amount'];
        $refundAmt = (float) ($p['refund_amount'] ?? 0);
        $status = $p['payment_status'] ?? 'paid';
        $refunded = ($status === 'refunded') || ($refundAmt >= $amt);
        $byUser[$uid] = [
            'payment_id' => (int) $p['payment_id'],
            'payment_amount' => $amt,
            'payment_method' => $p['payment_method'] ?? 'stripe',
            'payment_status' => $status,
            'is_refunded' => $refunded,
        ];
    }
}

// Load question answers for all RSVPs (same shape as checkin-rsvps.php)
$questionAnswersByRsvp = [];
try {
    $rsvpIds = array_unique(array_column($rows, 'rsvp_id'));
    if (!empty($rsvpIds)) {
        $placeholders = implode(',', array_fill(0, count($rsvpIds), '?'));
        $answersRows = $db->query(
            "SELECT rqa.rsvp_id, eq.question_text, rqa.answer_text
             FROM rsvp_question_answers rqa
             JOIN event_questions eq ON eq.id = rqa.question_id
             WHERE rqa.rsvp_id IN ($placeholders)
             ORDER BY eq.sort_order ASC, eq.id ASC",
            array_values($rsvpIds)
        );
        foreach ($answersRows as $ar) {
            $rid = (int)$ar['rsvp_id'];
            if (!isset($questionAnswersByRsvp[$rid])) {
                $questionAnswersByRsvp[$rid] = [];
            }
            $questionAnswersByRsvp[$rid][] = [
                'question_text' => $ar['question_text'],
                'answer_text' => $ar['answer_text'],
            ];
        }
    }
} catch (\Exception $e) {
    // Table may not exist
}

$checkedInFromRsvps = 0;
$rsvps = [];
foreach ($rows as $r) {
    if ($r['checked_in']) {
        $checkedInFromRsvps++;
    }
    $row = [
        'id' => (int)$r['user_id'],
        'rsvp_id' => (int)$r['rsvp_id'],
        'first_name' => $r['first_name'],
        'last_name' => $r['last_name'],
        'email' => $r['email'],
        'phone' => $r['phone'],
        'rsvp_status' => $r['rsvp_status'],
        'guest_count' => (int)($r['guest_count'] ?? 0),
        'checked_in' => (bool)$r['checked_in'],
        'checked_in_at' => $r['checked_in_at'],
        'checked_in_time' => $r['checked_in_at'] ? date('g:i A', strtotime($r['checked_in_at'])) : null,
        'question_answers' => $questionAnswersByRsvp[(int)$r['rsvp_id']] ?? [],
    ];
    if (isset($byUser[(int) $r['user_id']])) {
        $pu = $byUser[(int) $r['user_id']];
        $row['payment_id'] = $pu['payment_id'];
        $row['payment_amount'] = $pu['payment_amount'];
        $row['payment_method'] = $pu['payment_method'];
        $row['payment_status'] = $pu['payment_status'];
        $row['is_refunded'] = $pu['is_refunded'];
    } else {
        $row['payment_id'] = null;
        $row['payment_amount'] = null;
        $row['payment_method'] = null;
        $row['payment_status'] = null;
        $row['is_refunded'] = false;
    }
    $rsvps[] = $row;
}

$headStats = headcount_checkin_head_stats_from_rsvp_rows($rows);
$canonical = headcount_rsvp_yes_canonical_counts($db, $eventId);
$headStats = headcount_merge_canonical_rsvp_yes_headcounts($headStats, $canonical);
$totalRsvps = count($rsvps);
$totalCheckedIn = $checkedInFromRsvps;
try {
    $attRow = $db->queryOne(
        "SELECT COUNT(*) AS c FROM attendance WHERE event_id = :event_id AND checked_in_at IS NOT NULL AND DATE(checked_in_at) = :event_date",
        ['event_id' => $eventId, 'event_date' => $event['event_date']]
    );
    $totalCheckedIn = (int)($attRow['c'] ?? $checkedInFromRsvps);
} catch (\Exception $e) {
    $totalCheckedIn = $checkedInFromRsvps;
}

jsonResponse([
    'success' => true,
    'applied' => $applied,
    'results' => $results,
    'rsvps' => $rsvps,
    'total_rsvps' => $totalRsvps,
    'total_heads' => (int) ($headStats['total_heads'] ?? 0),
    'not_checked_in_heads' => (int) ($headStats['not_checked_in_heads'] ?? 0),
    'total_registrants_yes' => (int) ($headStats['total_registrants_yes'] ?? 0),
    'checked_in' => $totalCheckedIn,
    'not_checked_in' => $totalRsvps - $checkedInFromRsvps,
], 200);
