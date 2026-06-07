<?php
/**
 * JSON list of everyone checked in for an event (includes walk-ins).
 * GET /api/event-checkins.php?event_id=X
 * Auth: admin or coordinator; event must belong to organization.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\EventSeriesHelper;

header('Content-Type: application/json');

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
if (!$eventId) {
    jsonResponse(['success' => false, 'message' => 'event_id required'], 400);
}

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$event = $db->queryOne(
    "SELECT id, title, event_date, parent_event_id FROM events WHERE id = :id AND organization_id = :org_id",
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

$rsvpLookupEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
$parentForAttendance = !empty($event['parent_event_id']) ? (int) $event['parent_event_id'] : 0;

$orgTzRow = $db->queryOne('SELECT timezone FROM organizations WHERE id = :id', ['id' => $organizationId]);
$orgTimezone = OrgTimeZone::resolve(is_array($orgTzRow) ? ($orgTzRow['timezone'] ?? null) : null);

$attCols = $db->query("SHOW COLUMNS FROM attendance");
$attFieldNames = array_column($attCols, 'Field');
$hasGuestsCheckedIn = in_array('guests_checked_in', $attFieldNames);

$guestsSelect = $hasGuestsCheckedIn ? ', a.guests_checked_in' : '';

$sql = "SELECT u.id AS user_id, a.checked_in_at, a.checked_in_by{$guestsSelect},
        u.first_name, u.last_name, u.email, u.phone, u.password_hash,
        checker.first_name AS checker_first, checker.last_name AS checker_last,
        (SELECT r2.status FROM rsvps r2 WHERE r2.event_id = :rsvp_lookup AND r2.user_id = a.user_id LIMIT 1) AS rsvp_status
        FROM attendance a
        INNER JOIN users u ON a.user_id = u.id
        LEFT JOIN users checker ON a.checked_in_by = checker.id
        WHERE a.checked_in_at IS NOT NULL
        AND DATE(a.checked_in_at) = :event_date
        AND a.event_id IN (:event_id, :parent_id)
        ORDER BY a.checked_in_at DESC";

try {
    $rows = $db->query($sql, [
        'event_id' => $eventId,
        'parent_id' => $parentForAttendance,
        'event_date' => $event['event_date'],
        'rsvp_lookup' => $rsvpLookupEventId,
    ]);
} catch (\Exception $e) {
    error_log("event-checkins: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Could not load check-ins'], 500);
}

$checkins = [];
foreach ($rows as $r) {
    $rsvp = $r['rsvp_status'] ?? '';
    $rsvpLabel = $rsvp !== '' && $rsvp !== null ? ucfirst(strtolower((string)$rsvp)) : 'Walk-in';
    $checker = trim(($r['checker_first'] ?? '') . ' ' . ($r['checker_last'] ?? ''));
    $checkedAt = !empty($r['checked_in_at']) ? $r['checked_in_at'] : '';
    $item = [
        'user_id' => (int)$r['user_id'],
        'first_name' => $r['first_name'] ?? '',
        'last_name' => $r['last_name'] ?? '',
        'email' => $r['email'] ?? '',
        'phone' => $r['phone'] ?? '',
        'user_type' => (!empty($r['password_hash'])) ? 'Member' : 'Guest',
        'rsvp_label' => $rsvpLabel,
        'checked_in_at' => $checkedAt,
        'checked_in_time' => $checkedAt ? formatAttendanceLocalTimeForOrganization($checkedAt, $orgTimezone) : '',
        'checked_in_by' => $checker !== '' ? $checker : null,
    ];
    if ($hasGuestsCheckedIn) {
        $item['guests_checked_in'] = (int)($r['guests_checked_in'] ?? 0);
    }
    $checkins[] = $item;
}

jsonResponse([
    'success' => true,
    'checkins' => $checkins,
    'total' => count($checkins),
]);
