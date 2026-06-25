<?php
/**
 * Export RSVPs for a single event as CSV.
 * Requires admin or coordinator; event must belong to user's organization.
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
use Headcount\Services\EventSeriesHelper;
use Headcount\Services\PotluckCategoryService;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if (!$eventId) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: text/plain');
    echo 'Event ID required';
    exit;
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$event = $db->queryOne(
    "SELECT id, title, is_potluck FROM events WHERE id = :id AND organization_id = :org_id",
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain');
    echo 'Event not found';
    exit;
}

$rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
$isPotluckEvent = !empty($event['is_potluck']);

$eventQuestions = [];
try {
    $eventQuestions = $db->query(
        "SELECT id, question_text FROM event_questions WHERE event_id = :event_id ORDER BY sort_order ASC, id ASC",
        ['event_id' => $rsvpSourceEventId]
    ) ?: [];
} catch (\Exception $e) {
    $eventQuestions = [];
}

$rows = [];
$byUser = [];
$questionAnswersByRsvp = [];
$familyByRsvp = [];
$checkedInByUser = [];
$hasPotluckCols = false;

try {
    $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
    $rsvpColNames = array_column($rsvpCols, 'Field');
    $guestCountCol = in_array('guest_count', $rsvpColNames, true) ? ', r.guest_count' : '';
    $potluckCols = '';
    if (in_array('potluck_category', $rsvpColNames, true)) {
        $potluckCols .= ', r.potluck_category';
        $hasPotluckCols = true;
    }
    if (in_array('potluck_item_note', $rsvpColNames, true)) {
        $potluckCols .= ', r.potluck_item_note';
    }
    if (in_array('potluck_quantity', $rsvpColNames, true)) {
        $potluckCols .= ', r.potluck_quantity';
    }
    if (in_array('potluck_serving_side', $rsvpColNames, true)) {
        $potluckCols .= ', r.potluck_serving_side';
    }
    if (in_array('potluck_party_adults', $rsvpColNames, true)) {
        $potluckCols .= ', r.potluck_party_adults';
    }
    if (in_array('potluck_party_children', $rsvpColNames, true)) {
        $potluckCols .= ', r.potluck_party_children';
    }
    $rows = $db->query(
        "SELECT r.id, r.user_id, r.status, r.created_at, r.notes{$guestCountCol}{$potluckCols},
                u.first_name, u.last_name, u.email, u.phone, u.password_hash
         FROM rsvps r
         JOIN users u ON r.user_id = u.id
         WHERE r.event_id = :event_id
         ORDER BY r.created_at DESC",
        ['event_id' => $rsvpSourceEventId]
    );
} catch (\Exception $e) {
    error_log("event-rsvp-export: " . $e->getMessage());
}

$hasPaymentMethod = false;
if (!empty($rows)) {
    try {
        $paymentCols = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
        $hasPaymentMethod = !empty($paymentCols);
        $userIds = array_unique(array_column($rows, 'user_id'));
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $pmSelect = $hasPaymentMethod ? 'p.payment_method' : "'stripe' AS payment_method";
        $payments = $db->query(
            "SELECT p.user_id, p.id as payment_id, p.amount as payment_amount, {$pmSelect}, p.status as payment_status, p.refund_amount
             FROM payments p
             WHERE p.event_id = ? AND p.user_id IN ($placeholders)
             ORDER BY p.user_id, CASE p.status WHEN 'paid' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END, p.id DESC",
            array_merge([$rsvpSourceEventId], $userIds)
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
                'payment_amount' => $amt,
                'payment_method' => $p['payment_method'] ?? 'stripe',
                'payment_status' => $status,
                'is_refunded' => $refunded,
            ];
        }
    } catch (\Exception $e) {
        // ignore
    }

    try {
        $rsvpIds = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($rsvpIds), '?'));
        $answersRows = $db->query(
            "SELECT rqa.rsvp_id, rqa.question_id, rqa.answer_text
             FROM rsvp_question_answers rqa
             WHERE rqa.rsvp_id IN ($placeholders)",
            $rsvpIds
        );
        foreach ($answersRows as $ar) {
            $rid = (int) $ar['rsvp_id'];
            $qid = (int) $ar['question_id'];
            if (!isset($questionAnswersByRsvp[$rid])) {
                $questionAnswersByRsvp[$rid] = [];
            }
            $questionAnswersByRsvp[$rid][$qid] = (string) ($ar['answer_text'] ?? '');
        }
    } catch (\Exception $e) {
        // Table may not exist
    }

    try {
        $rsvpIds = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($rsvpIds), '?'));
        $fmRows = $db->query(
            "SELECT rfm.rsvp_id, fm.first_name, fm.last_name
             FROM rsvp_family_members rfm
             JOIN family_members fm ON fm.id = rfm.family_member_id
             WHERE rfm.rsvp_id IN ($placeholders)
             ORDER BY fm.first_name, fm.last_name",
            $rsvpIds
        );
        foreach ($fmRows as $fm) {
            $rid = (int) $fm['rsvp_id'];
            if (!isset($familyByRsvp[$rid])) {
                $familyByRsvp[$rid] = [];
            }
            $familyByRsvp[$rid][] = trim(($fm['first_name'] ?? '') . ' ' . ($fm['last_name'] ?? ''));
        }
    } catch (\Exception $e) {
        // Table may not exist
    }

    try {
        $userIds = array_unique(array_column($rows, 'user_id'));
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $attRows = $db->query(
            "SELECT user_id FROM attendance WHERE event_id = ? AND user_id IN ($placeholders) AND checked_in_at IS NOT NULL",
            array_merge([$rsvpSourceEventId], $userIds)
        );
        foreach ($attRows as $a) {
            $checkedInByUser[(int) $a['user_id']] = true;
        }
    } catch (\Exception $e) {
        // ignore
    }
}

$filename = 'event-' . $eventId . '-rsvps-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

$header = [
    'Name', 'Email', 'Phone', 'Type', 'RSVP Status', 'Guests',
    'Party Adults', 'Party Children', 'Payment', 'Checked In',
    'Response date', 'Notes', 'Family Members',
];
if ($isPotluckEvent && $hasPotluckCols) {
    $header = array_merge($header, [
        'Bringing Food', 'Potluck Category', 'Item Description',
        'Quantity', 'Serving Side',
    ]);
}
foreach ($eventQuestions as $eq) {
    $header[] = (string) ($eq['question_text'] ?? 'Question');
}
fputcsv($output, $header);

foreach ($rows as $r) {
    $userType = (!empty($r['password_hash'])) ? 'Member' : 'Guest';
    $guestCount = isset($r['guest_count']) ? (int) $r['guest_count'] : (
        isset($r['notes']) && strpos((string) $r['notes'], 'Guests:') !== false
            ? (int) preg_replace('/[^0-9]/', '', (string) $r['notes'])
            : 0
    );
    $partyAdults = isset($r['potluck_party_adults']) && $r['potluck_party_adults'] !== null && $r['potluck_party_adults'] !== ''
        ? (int) $r['potluck_party_adults']
        : (1 + $guestCount);
    $partyChildren = isset($r['potluck_party_children']) && $r['potluck_party_children'] !== null && $r['potluck_party_children'] !== ''
        ? (int) $r['potluck_party_children']
        : 0;

    $paymentStr = '';
    if (isset($byUser[(int) $r['user_id']])) {
        $pu = $byUser[(int) $r['user_id']];
        if (!empty($pu['is_refunded'])) {
            $paymentStr = 'Refunded';
        } elseif (($pu['payment_status'] ?? '') === 'pending') {
            $paymentStr = 'Pending';
        } else {
            $method = ($pu['payment_method'] ?? '') === 'cash' ? 'Cash' : 'Card';
            $paymentStr = $method . ' $' . number_format((float) $pu['payment_amount'], 2);
        }
    }

    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $email = $r['email'] ?? '';
    $phone = $r['phone'] ?? '';
    $rsvpStatus = $r['status'] ?? '';
    $responseDate = !empty($r['created_at']) ? $r['created_at'] : '';
    $notes = $r['notes'] ?? '';
    $familyStr = implode('; ', $familyByRsvp[(int) $r['id']] ?? []);
    $checkedIn = !empty($checkedInByUser[(int) $r['user_id']]) ? 'Yes' : 'No';

    $row = [
        $name, $email, $phone, $userType, $rsvpStatus, $guestCount,
        $partyAdults, $partyChildren, $paymentStr, $checkedIn,
        $responseDate, $notes, $familyStr,
    ];

    if ($isPotluckEvent && $hasPotluckCols) {
        $pcSlug = isset($r['potluck_category']) ? trim((string) $r['potluck_category']) : '';
        $bringing = ($rsvpStatus === 'yes') ? ($pcSlug !== '' ? 'Yes' : 'No') : '';
        $row[] = $bringing;
        $row[] = $pcSlug !== '' ? (PotluckCategoryService::labelForSlug($pcSlug) ?? $pcSlug) : '';
        $row[] = $r['potluck_item_note'] ?? '';
        $row[] = isset($r['potluck_quantity']) && $r['potluck_quantity'] !== null && $r['potluck_quantity'] !== ''
            ? (int) $r['potluck_quantity'] : ($pcSlug !== '' ? 1 : '');
        $side = isset($r['potluck_serving_side']) ? trim((string) $r['potluck_serving_side']) : '';
        $row[] = $side !== '' ? (PotluckCategoryService::labelForServingSide($side) ?? $side) : '';
    }

    $answersForRsvp = $questionAnswersByRsvp[(int) $r['id']] ?? [];
    foreach ($eventQuestions as $eq) {
        $qid = (int) $eq['id'];
        $raw = $answersForRsvp[$qid] ?? '';
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $row[] = implode(', ', $decoded);
        } else {
            $row[] = $raw;
        }
    }

    fputcsv($output, $row);
}

fclose($output);
exit;
