<?php

/**
 * Check-In RSVP List API
 * GET /api/checkin-rsvps.php?event_id=X
 * Returns all RSVP'd attendees for the event with check-in status and payment info.
 * Auth: admin or coordinator.
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
use Headcount\Middleware\AuthMiddleware;

$config = require HC_PROJECT_ROOT . '/config/config.php';
Database::getInstance($config['database']);

header('Content-Type: application/json');

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$db = Database::getInstance();

$eventId = isset($_GET['event_id']) ? (int) $_GET['event_id'] : 0;
if (!$eventId) {
    jsonResponse(['success' => false, 'message' => 'event_id required'], 400);
}

$event = $db->queryOne("SELECT id, event_date FROM events WHERE id = :id AND organization_id = :org_id", [
    'id' => $eventId,
    'org_id' => $organizationId
]);
if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

$orgTzRow = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $organizationId]);
$orgTimezone = OrgTimeZone::resolve(is_array($orgTzRow) ? ($orgTzRow['timezone'] ?? null) : null);

$rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
$rsvpNames = array_column($rsvpCols, 'Field');
$guestCountCol = in_array('guest_count', $rsvpNames) ? ', r.guest_count' : '';

$fmJoin = '';
try {
    if ($db->hasColumn('attendance', 'family_member_id')) {
        $fmJoin = ' AND IFNULL(a.family_member_id, 0) = 0';
    }
} catch (\Exception $e) {
    $fmJoin = '';
}

// Only RSVP yes for check-in list (or all statuses? Plan says "all RSVP'd attendees" - so all)
// Scope attendance join to event's own date so cross-session check-ins don't bleed through
$sql = "SELECT r.id as rsvp_id, r.user_id, r.status as rsvp_status{$guestCountCol},
        u.first_name, u.last_name, u.email, u.phone,
        a.checked_in_at,
        CASE WHEN a.checked_in_at IS NOT NULL THEN 1 ELSE 0 END as checked_in
        FROM rsvps r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
            AND DATE(a.checked_in_at) = :event_date{$fmJoin}
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
            'refund_amount' => $refundAmt,
            'refunded_at' => $p['refunded_at'] ?? null,
            'is_refunded' => $refunded,
        ];
    }
}

// Load question answers for all RSVPs (if rsvp_question_answers exists)
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

// RSVP rows with a check-in (excludes walk-ins — no rsvp row)
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
        'checked_in_time' => $r['checked_in_at'] ? formatAttendanceLocalTimeForOrganization($r['checked_in_at'], $orgTimezone) : null,
        'question_answers' => $questionAnswersByRsvp[(int)$r['rsvp_id']] ?? [],
    ];
    if (isset($byUser[(int) $r['user_id']])) {
        $pu = $byUser[(int) $r['user_id']];
        $row['payment_id'] = $pu['payment_id'];
        $row['payment_amount'] = $pu['payment_amount'];
        $row['payment_method'] = $pu['payment_method'];
        $row['payment_status'] = $pu['payment_status'] ?? 'paid';
        $row['is_refunded'] = !empty($pu['is_refunded']);
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
// People on the RSVP list who have not checked in yet (walk-ins are not in this list)
$notCheckedIn = $totalRsvps - $checkedInFromRsvps;

// Total check-ins for the event (includes walk-ins with attendance but no RSVP row)
// Scoped to event's own date to prevent cross-session bleed
$totalCheckedIn = $checkedInFromRsvps;
$headsExpr = headcount_attendance_heads_sum_expr($db, 'a');
try {
    $attRow = $db->queryOne(
        "SELECT {$headsExpr} AS c FROM attendance a
         WHERE a.event_id = :event_id AND a.checked_in_at IS NOT NULL AND DATE(a.checked_in_at) = :event_date",
        ['event_id' => $eventId, 'event_date' => $event['event_date']]
    );
    $totalCheckedIn = (int)($attRow['c'] ?? $checkedInFromRsvps);
} catch (\Exception $e) {
    $totalCheckedIn = $checkedInFromRsvps;
}

jsonResponse([
    'success' => true,
    'rsvps' => $rsvps,
    'total_rsvps' => $totalRsvps,
    'total_heads' => (int) ($headStats['total_heads'] ?? 0),
    'not_checked_in_heads' => (int) ($headStats['not_checked_in_heads'] ?? 0),
    'total_registrants_yes' => (int) ($headStats['total_registrants_yes'] ?? 0),
    'checked_in' => $totalCheckedIn,
    'not_checked_in' => $notCheckedIn,
]);
