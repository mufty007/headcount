<?php
/**
 * Export RSVPs for a single event as CSV.
 * Requires admin or coordinator; event must belong to user's organization.
 */
require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\EventSeriesHelper;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if (!$eventId) {
    header('HTTP/1.1 400 Bad Request');
    header('Content-Type: text/plain');
    echo 'Event ID required';
    exit;
}

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$event = $db->queryOne(
    "SELECT id, title FROM events WHERE id = :id AND organization_id = :org_id",
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain');
    echo 'Event not found';
    exit;
}

$rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);

$rows = [];
$byUser = [];
$questionAnswersByRsvp = [];

try {
    $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
    $rsvpColNames = array_column($rsvpCols, 'Field');
    $guestCountCol = in_array('guest_count', $rsvpColNames) ? ', r.guest_count' : '';
    $rows = $db->query(
        "SELECT r.id, r.user_id, r.created_at, r.notes{$guestCountCol},
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
        if ($hasPaymentMethod) {
            $userIds = array_unique(array_column($rows, 'user_id'));
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $payments = $db->query(
                "SELECT user_id, id as payment_id, amount as payment_amount, payment_method, status as payment_status, refund_amount FROM payments WHERE event_id = ? AND user_id IN ($placeholders)",
                array_merge([$rsvpSourceEventId], $userIds)
            );
            foreach ($payments as $p) {
                $amt = (float)$p['payment_amount'];
                $refundAmt = (float)($p['refund_amount'] ?? 0);
                $status = $p['payment_status'] ?? 'paid';
                $refunded = ($status === 'refunded') || ($refundAmt >= $amt);
                $byUser[(int)$p['user_id']] = [
                    'payment_amount' => $amt,
                    'payment_method' => $p['payment_method'] ?? 'stripe',
                    'is_refunded' => $refunded,
                ];
            }
        }
    } catch (\Exception $e) {
        // ignore
    }

    try {
        $rsvpIds = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($rsvpIds), '?'));
        $answersRows = $db->query(
            "SELECT rqa.rsvp_id, eq.question_text, rqa.answer_text
             FROM rsvp_question_answers rqa
             JOIN event_questions eq ON eq.id = rqa.question_id
             WHERE rqa.rsvp_id IN ($placeholders)
             ORDER BY eq.sort_order ASC, eq.id ASC",
            $rsvpIds
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
    } catch (\Exception $e) {
        // Table may not exist
    }
}

$filename = 'event-' . $eventId . '-rsvps-' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['Name', 'Email', 'Type', 'Guests', 'Payment', 'Response date', 'Questions']);

foreach ($rows as $r) {
    $userType = (!empty($r['password_hash'])) ? 'Member' : 'Guest';
    $guestCount = isset($r['guest_count']) ? (int)$r['guest_count'] : (isset($r['notes']) && strpos($r['notes'], 'Guests:') !== false ? (int)preg_replace('/[^0-9]/', '', $r['notes']) : 0);

    $paymentStr = '';
    if ($hasPaymentMethod && isset($byUser[(int)$r['user_id']])) {
        $pu = $byUser[(int)$r['user_id']];
        if (!empty($pu['is_refunded'])) {
            $paymentStr = 'Refunded';
        } else {
            $method = ($pu['payment_method'] ?? '') === 'cash' ? 'Cash' : 'Card';
            $paymentStr = $method . ' $' . number_format((float)$pu['payment_amount'], 2);
        }
    }

    $questions = $questionAnswersByRsvp[(int)$r['id']] ?? [];
    $questionsStr = '';
    if (!empty($questions)) {
        $parts = [];
        foreach ($questions as $qa) {
            $q = isset($qa['question_text']) ? $qa['question_text'] : '';
            $a = isset($qa['answer_text']) ? $qa['answer_text'] : '';
            $parts[] = $q . ': ' . $a;
        }
        $questionsStr = implode(' | ', $parts);
    }

    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $email = $r['email'] ?? '';
    $responseDate = !empty($r['created_at']) ? $r['created_at'] : '';

    fputcsv($output, [$name, $email, $userType, $guestCount, $paymentStr, $responseDate, $questionsStr]);
}

fclose($output);
exit;
