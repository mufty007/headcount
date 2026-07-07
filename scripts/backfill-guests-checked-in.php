<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This script is CLI-only. Run: php scripts/backfill-guests-checked-in.php --dry-run' . PHP_EOL;
    exit(1);
}

/**
 * Backfill attendance.guests_checked_in from RSVP guest_count for check-ins that were
 * recorded before guest counts were saved (guests_checked_in = 0 but RSVP had guests).
 *
 * Assumption: everyone listed on the RSVP as a guest actually attended. Review dry-run
 * output before applying. Use event details → Edit check-in to correct individual rows.
 *
 * Usage (from project root):
 *   php scripts/backfill-guests-checked-in.php --dry-run
 *   php scripts/backfill-guests-checked-in.php --apply
 *   php scripts/backfill-guests-checked-in.php --event-id=42 --dry-run
 *   php scripts/backfill-guests-checked-in.php --organization-id=1 --apply
 */

$opts = getopt('', ['dry-run', 'apply', 'event-id:', 'organization-id:', 'help']);
if (isset($opts['help'])) {
    echo <<<TXT
backfill-guests-checked-in.php

  --dry-run              Show planned updates (default if --apply not passed)
  --apply                Write guests_checked_in from RSVP guest_count
  --event-id=N           Limit to one event (session) id
  --organization-id=N    Limit to one organization

TXT;
    exit(0);
}

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\EventSeriesHelper;

$config = require $base . '/config/config.php';
$db = Database::getInstance($config['database']);

$dryRun = !isset($opts['apply']);
$filterEventId = isset($opts['event-id']) ? (int) $opts['event-id'] : 0;
$filterOrgId = isset($opts['organization-id']) ? (int) $opts['organization-id'] : 0;

try {
    $attCols = $db->query('SHOW COLUMNS FROM attendance');
    $attNames = array_column($attCols, 'Field');
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not read attendance table: {$e->getMessage()}\n");
    exit(1);
}

if (!in_array('guests_checked_in', $attNames, true)) {
    fwrite(STDERR, "Column attendance.guests_checked_in does not exist. Run migrations first.\n");
    exit(1);
}

$rsvpHasGuest = false;
try {
    $rsvpCols = $db->query('SHOW COLUMNS FROM rsvps');
    $rsvpHasGuest = in_array('guest_count', array_column($rsvpCols, 'Field'), true);
} catch (\Throwable $e) {
    $rsvpHasGuest = false;
}

if (!$rsvpHasGuest) {
    fwrite(STDERR, "Column rsvps.guest_count does not exist. Nothing to backfill.\n");
    exit(0);
}

$sql = "SELECT a.id AS attendance_id, a.event_id, a.user_id,
               COALESCE(a.guests_checked_in, 0) AS guests_checked_in,
               a.checked_in_at,
               u.first_name, u.last_name, u.email,
               e.title AS event_title, e.event_date, e.organization_id
        FROM attendance a
        INNER JOIN events e ON e.id = a.event_id
        INNER JOIN users u ON u.id = a.user_id
        WHERE a.checked_in_at IS NOT NULL
          AND COALESCE(a.guests_checked_in, 0) = 0";
$params = [];

if ($filterEventId > 0) {
    $sql .= ' AND a.event_id = :event_id';
    $params['event_id'] = $filterEventId;
}
if ($filterOrgId > 0) {
    $sql .= ' AND e.organization_id = :org_id';
    $params['org_id'] = $filterOrgId;
}

$sql .= ' ORDER BY e.event_date DESC, a.checked_in_at DESC';

$rows = $params === [] ? $db->query($sql) : $db->query($sql, $params);

$planned = [];
$skipped = 0;

foreach ($rows as $row) {
    $eventId = (int) ($row['event_id'] ?? 0);
    $userId = (int) ($row['user_id'] ?? 0);
    if ($eventId <= 0 || $userId <= 0) {
        $skipped++;
        continue;
    }

    $rsvpSourceId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
    $rsvpCols = ['status'];
    try {
        $colRows = $db->query('SHOW COLUMNS FROM rsvps');
        $colNames = array_column($colRows, 'Field');
        foreach (['guest_count', 'potluck_party_adults', 'potluck_party_children'] as $c) {
            if (in_array($c, $colNames, true)) {
                $rsvpCols[] = $c;
            }
        }
    } catch (\Throwable $e) {
        $rsvpCols[] = 'guest_count';
    }
    $rsvp = $db->queryOne(
        'SELECT ' . implode(', ', $rsvpCols) . ' FROM rsvps WHERE event_id = :eid AND user_id = :uid LIMIT 1',
        ['eid' => $rsvpSourceId, 'uid' => $userId]
    );

    if (!$rsvp || strtolower((string) ($rsvp['status'] ?? '')) !== 'yes') {
        $skipped++;
        continue;
    }

    $guestCount = headcount_rsvp_guests_for_checkin($rsvp);
    if ($guestCount <= 0) {
        $skipped++;
        continue;
    }

    $planned[] = [
        'attendance_id' => (int) $row['attendance_id'],
        'event_id' => $eventId,
        'user_id' => $userId,
        'guests' => $guestCount,
        'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
        'email' => (string) ($row['email'] ?? ''),
        'event_title' => (string) ($row['event_title'] ?? ''),
        'event_date' => substr((string) ($row['event_date'] ?? ''), 0, 10),
        'checked_in_at' => (string) ($row['checked_in_at'] ?? ''),
    ];
}

echo ($dryRun ? '[DRY RUN] ' : '[APPLY] ') . 'Backfill guests_checked_in from RSVP guest_count' . PHP_EOL;
echo 'Rows with guests_checked_in=0 scanned: ' . count($rows) . PHP_EOL;
echo 'Skipped (no yes RSVP with guests): ' . $skipped . PHP_EOL;
echo 'To update: ' . count($planned) . PHP_EOL . PHP_EOL;

if ($planned === []) {
    echo "Nothing to do.\n";
    exit(0);
}

$totalHeadsAdded = 0;
foreach ($planned as $p) {
    $totalHeadsAdded += $p['guests'];
    echo sprintf(
        "  attendance #%d | %s | %s on %s | guests_checked_in: 0 → %d (+%d heads)\n",
        $p['attendance_id'],
        $p['name'] !== '' ? $p['name'] : $p['email'],
        $p['event_title'],
        $p['event_date'],
        $p['guests'],
        $p['guests']
    );
}

echo PHP_EOL . 'Additional guest heads counted if applied: ' . $totalHeadsAdded . PHP_EOL;

if ($dryRun) {
    echo PHP_EOL . 'Re-run with --apply to save these values.' . PHP_EOL;
    exit(0);
}

$updated = 0;
foreach ($planned as $p) {
    $db->execute(
        'UPDATE attendance SET guests_checked_in = :guests WHERE id = :id',
        ['guests' => $p['guests'], 'id' => $p['attendance_id']]
    );
    $updated++;
}

echo PHP_EOL . "Updated {$updated} attendance row(s).\n";
