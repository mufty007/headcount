<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'This script is CLI-only. Run it over SSH, for example: php scripts/fix-series-attendance.php --parent-id=YOUR_ID --dry-run';
    exit(1);
}

/**
 * Fix recurring-series attendance: attach each check-in to the session row (parent or child instance)
 * whose calendar date matches DATE(checked_in_at). Rows that already belong on the parent for the
 * parent's event_date are left unchanged.
 *
 * When the same person was checked in on two different days but only one attendance row exists
 * (later day overwrote the timestamp), use --csv to insert the missing session from a paper list.
 *
 * Usage (from project root):
 *   php scripts/fix-series-attendance.php --parent-id=8 --dry-run
 *   php scripts/fix-series-attendance.php --parent-id=8 --apply
 *
 * Optional CSV backfill (email,date per line; date = YYYY-MM-DD for that session):
 *   php scripts/fix-series-attendance.php --parent-id=8 --apply --csv=./backfill.csv
 *
 * Optional: who recorded synthetic rows (defaults to first admin in org):
 *   php scripts/fix-series-attendance.php --parent-id=8 --apply --csv=./x.csv --checked-in-by=123
 */

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';
require_once $base . '/src/helpers.php';

use Headcount\Helpers\Database;

$config = require $base . '/config/config.php';
$db = Database::getInstance($config['database']);

$opts = getopt('', ['parent-id:', 'dry-run', 'apply', 'csv:', 'checked-in-by:', 'help']);
if (isset($opts['help']) || empty($opts['parent-id'])) {
    echo <<<TXT
fix-series-attendance.php

  --parent-id=N   Series parent event id (the row that holds RSVPs for all_sessions)
  --dry-run       Show planned changes (default if neither dry-run nor apply)
  --apply         Execute updates / inserts
  --csv=path      Optional: email,YYYY-MM-DD lines to INSERT missing session attendance
  --checked-in-by=USER_ID  For CSV inserts (defaults to first admin in org)

TXT;
    exit(isset($opts['help']) ? 0 : 1);
}

$parentId = (int) $opts['parent-id'];
$dryRun = !isset($opts['apply']);
$csvPath = isset($opts['csv']) ? (string) $opts['csv'] : null;
$checkedInByOverride = isset($opts['checked-in-by']) ? (int) $opts['checked-in-by'] : null;

$parent = $db->queryOne('SELECT id, organization_id, title, event_date FROM events WHERE id = :id', ['id' => $parentId]);
if (!$parent) {
    fwrite(STDERR, "Parent event {$parentId} not found.\n");
    exit(1);
}

$orgId = (int) $parent['organization_id'];
$parentDate = substr((string) $parent['event_date'], 0, 10);

$instances = $db->query(
    'SELECT id, event_date FROM events WHERE parent_event_id = :pid ORDER BY event_date ASC',
    ['pid' => $parentId]
);

$dateToInstanceId = [];
foreach ($instances as $row) {
    $d = substr((string) $row['event_date'], 0, 10);
    $dateToInstanceId[$d] = (int) $row['id'];
}

echo "Parent event #{$parentId} — {$parent['title']}\n";
echo "Parent session date: {$parentDate}\n";
echo "Child instances: " . count($instances) . "\n";
foreach ($dateToInstanceId as $d => $iid) {
    echo "  {$d} → instance event_id={$iid}\n";
}
echo $dryRun ? "[DRY RUN — no DB writes]\n" : "[APPLY]\n";

$moves = 0;
$skippedOnParent = 0;
$deletedDuplicates = 0;
$noTarget = 0;

$rows = $db->query(
    'SELECT a.id, a.user_id, a.checked_in_at, a.checked_in_by FROM attendance a WHERE a.event_id = :pid AND a.checked_in_at IS NOT NULL',
    ['pid' => $parentId]
);

foreach ($rows as $a) {
    $attId = (int) $a['id'];
    $userId = (int) $a['user_id'];
    $ts = (string) $a['checked_in_at'];
    $d = substr($ts, 0, 10);

    if ($d === $parentDate) {
        $skippedOnParent++;
        continue;
    }

    if (!isset($dateToInstanceId[$d])) {
        echo "WARN: attendance id={$attId} user={$userId} date={$d} — no instance with that event_date; left on parent\n";
        $noTarget++;
        continue;
    }

    $targetId = $dateToInstanceId[$d];

    $existing = $db->queryOne(
        'SELECT id FROM attendance WHERE event_id = :eid AND user_id = :uid',
        ['eid' => $targetId, 'uid' => $userId]
    );

    if ($existing) {
        echo "Duplicate: user {$userId} already has attendance on instance {$targetId}; deleting parent row {$attId}\n";
        if (!$dryRun) {
            $db->execute('DELETE FROM attendance WHERE id = :id', ['id' => $attId]);
        }
        $deletedDuplicates++;
        continue;
    }

    echo "Move attendance id={$attId} user={$userId} {$d} parent → instance event_id={$targetId}\n";
    if (!$dryRun) {
        $db->execute('UPDATE attendance SET event_id = :eid WHERE id = :id', ['eid' => $targetId, 'id' => $attId]);
    }
    $moves++;
}

echo "\nSummary (reassign from parent): moved={$moves}, kept_on_parent_date={$skippedOnParent}, deleted_duplicate={$deletedDuplicates}, no_instance_for_date={$noTarget}\n";

// --- Optional CSV backfill: INSERT missing (instance or parent) for a session date ---
if ($csvPath !== null && $csvPath !== '') {
    if (!is_readable($csvPath)) {
        fwrite(STDERR, "CSV not readable: {$csvPath}\n");
        exit(1);
    }

    $checkedInBy = $checkedInByOverride;
    if ($checkedInBy === null || $checkedInBy <= 0) {
        $admin = $db->queryOne(
            "SELECT id FROM users WHERE organization_id = :oid AND role IN ('admin','coordinator') ORDER BY id ASC LIMIT 1",
            ['oid' => $orgId]
        );
        $checkedInBy = $admin ? (int) $admin['id'] : 1;
    }

    $lines = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $inserted = 0;
    $skipped = 0;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'email') === 0) {
            continue;
        }
        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }
        $email = trim((string) $parts[0]);
        $date = trim((string) $parts[1]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            fwrite(STDERR, "Skip bad date: {$line}\n");
            continue;
        }

        $user = $db->queryOne(
            'SELECT id FROM users WHERE organization_id = :oid AND LOWER(TRIM(email)) = LOWER(TRIM(:em)) LIMIT 1',
            ['oid' => $orgId, 'em' => $email]
        );
        if (!$user) {
            fwrite(STDERR, "No user for email: {$email}\n");
            $skipped++;
            continue;
        }
        $uid = (int) $user['id'];

        if ($date === $parentDate) {
            $targetEventId = $parentId;
        } elseif (isset($dateToInstanceId[$date])) {
            $targetEventId = $dateToInstanceId[$date];
        } else {
            fwrite(STDERR, "No session for date {$date}: {$email}\n");
            $skipped++;
            continue;
        }

        $dup = $db->queryOne(
            'SELECT id FROM attendance WHERE event_id = :eid AND user_id = :uid',
            ['eid' => $targetEventId, 'uid' => $uid]
        );
        if ($dup) {
            echo "CSV skip (exists): {$email} on event {$targetEventId}\n";
            $skipped++;
            continue;
        }

        $checkedAt = $date . ' 12:00:00';
        echo "CSV insert: {$email} user_id={$uid} event_id={$targetEventId} checked_in_at={$checkedAt}\n";
        if (!$dryRun) {
            $ins = [
                'event_id' => $targetEventId,
                'user_id' => $uid,
                'checked_in_at' => $checkedAt,
                'checked_in_by' => $checkedInBy,
            ];
            try {
                if ($db->hasColumn('attendance', 'guests_checked_in')) {
                    $ins['guests_checked_in'] = 0;
                }
            } catch (\Throwable $e) {
                // ignore
            }
            $db->insert('attendance', $ins);
        }
        $inserted++;
    }

    echo "\nCSV summary: inserted={$inserted}, skipped={$skipped}\n";
}

if ($dryRun) {
    echo "\nRe-run with --apply to write changes.\n";
}

exit(0);
