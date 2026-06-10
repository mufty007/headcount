<?php
/**
 * Export everyone checked in for a single event as CSV (includes walk-ins without an RSVP).
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
    "SELECT id, title FROM events WHERE id = :id AND organization_id = :org_id",
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain');
    echo 'Event not found';
    exit;
}

$attCols = $db->query("SHOW COLUMNS FROM attendance");
$attFieldNames = array_column($attCols, 'Field');
$hasGuestsCheckedIn = in_array('guests_checked_in', $attFieldNames);

$guestsSelect = $hasGuestsCheckedIn ? ', a.guests_checked_in' : '';

$sql = "SELECT a.checked_in_at, a.checked_in_by{$guestsSelect},
        u.first_name, u.last_name, u.email, u.phone, u.password_hash,
        checker.first_name AS checker_first, checker.last_name AS checker_last,
        (SELECT r2.status FROM rsvps r2 WHERE r2.event_id = a.event_id AND r2.user_id = a.user_id LIMIT 1) AS rsvp_status
        FROM attendance a
        INNER JOIN users u ON a.user_id = u.id
        LEFT JOIN users checker ON a.checked_in_by = checker.id
        WHERE a.event_id = :event_id AND a.checked_in_at IS NOT NULL
        ORDER BY a.checked_in_at DESC";

try {
    $rows = $db->query($sql, ['event_id' => $eventId]);
} catch (\Exception $e) {
    error_log("event-checkin-export: " . $e->getMessage());
    $rows = [];
}

$safeTitle = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $event['title'] ?? 'event');
$safeTitle = trim($safeTitle, '-');
$filename = 'event-' . $eventId . '-checkins-' . ($safeTitle !== '' ? $safeTitle . '-' : '') . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM for Excel

$header = ['Name', 'Email', 'Phone', 'Type', 'RSVP', 'Checked in at', 'Checked in by'];
if ($hasGuestsCheckedIn) {
    $header[] = 'Guests at check-in';
}
fputcsv($output, $header);

foreach ($rows as $r) {
    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $userType = (!empty($r['password_hash'])) ? 'Member' : 'Guest';
    $rsvp = $r['rsvp_status'] ?? '';
    $rsvpLabel = $rsvp !== '' && $rsvp !== null ? ucfirst(strtolower((string)$rsvp)) : 'Walk-in';
    $checker = trim(($r['checker_first'] ?? '') . ' ' . ($r['checker_last'] ?? ''));
    if ($checker === '') {
        $checker = '—';
    }
    $checkedAt = !empty($r['checked_in_at']) ? $r['checked_in_at'] : '';

    $row = [
        $name,
        $r['email'] ?? '',
        $r['phone'] ?? '',
        $userType,
        $rsvpLabel,
        $checkedAt,
        $checker,
    ];
    if ($hasGuestsCheckedIn) {
        $row[] = (int)($r['guests_checked_in'] ?? 0);
    }
    fputcsv($output, $row);
}

fclose($output);
exit;
