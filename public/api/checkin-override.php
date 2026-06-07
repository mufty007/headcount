<?php

/**
 * Post-event / staff check-in correction API
 * POST /api/checkin-override.php
 * Body: { event_id, user_id, action: checkin|undo|update, reason, checked_in_at?, guests_checked_in?, family_member_id? }
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\ActivityLogger;

if (!headers_sent()) {
    header('Content-Type: application/json');
}

AuthMiddleware::requireCanCorrectCheckins();

if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$organizationId = AuthMiddleware::getOrganizationId();
$checkedInBy = AuthMiddleware::getUserId();

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    jsonResponse(['success' => false, 'message' => 'Invalid JSON in request body'], 400);
}

$eventId = isset($input['event_id']) ? (int) $input['event_id'] : 0;
$userId = isset($input['user_id']) ? (int) $input['user_id'] : 0;
$action = isset($input['action']) ? strtolower(trim((string) $input['action'])) : '';
$reason = isset($input['reason']) ? trim((string) $input['reason']) : '';
$familyMemberId = isset($input['family_member_id']) ? (int) $input['family_member_id'] : 0;
$guestsCheckedIn = isset($input['guests_checked_in']) ? max(0, min(20, (int) $input['guests_checked_in'])) : 0;
$clientCheckedInAt = $input['checked_in_at'] ?? null;

if ($eventId <= 0 || $userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'event_id and user_id are required'], 400);
}
if (!in_array($action, ['checkin', 'undo', 'update'], true)) {
    jsonResponse(['success' => false, 'message' => 'action must be checkin, undo, or update'], 400);
}
if (strlen($reason) < 3) {
    jsonResponse(['success' => false, 'message' => 'A reason is required (at least 3 characters)'], 400);
}

try {
$event = $db->queryOne(
    'SELECT id, title, event_date, start_time FROM events WHERE id = :id AND organization_id = :org_id',
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

$user = $db->queryOne(
    'SELECT id, first_name, last_name FROM users WHERE id = :id AND organization_id = :org_id',
    ['id' => $userId, 'org_id' => $organizationId]
);
if (!$user) {
    jsonResponse(['success' => false, 'message' => 'User not found'], 404);
}

$org = $db->queryOne('SELECT timezone FROM organizations WHERE id = :id', ['id' => $organizationId]);
$timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
$tz = new \DateTimeZone($timezone);
$eventDay = substr((string) $event['event_date'], 0, 10);

$familyMemberRow = null;
if ($familyMemberId > 0) {
    $familyMemberRow = $db->queryOne(
        'SELECT * FROM family_members WHERE id = :id AND parent_user_id = :uid',
        ['id' => $familyMemberId, 'uid' => $userId]
    );
    if (!$familyMemberRow) {
        jsonResponse(['success' => false, 'message' => 'Invalid family member for this member'], 400);
    }
}

$hasFmCol = false;
try {
    $hasFmCol = $db->hasColumn('attendance', 'family_member_id');
} catch (\Exception $e) {
    $hasFmCol = false;
}
$partySlot = $familyMemberId > 0 ? $familyMemberId : 0;

$memberName = trim($user['first_name'] . ' ' . $user['last_name']);
if ($familyMemberRow) {
    $memberName = trim($familyMemberRow['first_name'] . ' ' . $familyMemberRow['last_name']);
}
$eventTitle = $event['title'] ?? null;
$activityLogger = new ActivityLogger($organizationId, $checkedInBy);

/**
 * @return string Y-m-d H:i:s
 */
$resolveCheckedInAt = static function () use ($clientCheckedInAt, $event, $tz, $eventDay): string {
    if (!empty($clientCheckedInAt)) {
        $parsed = \DateTime::createFromFormat(\DateTime::ATOM, (string) $clientCheckedInAt, $tz);
        if (!$parsed) {
            $parsed = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $clientCheckedInAt, $tz);
        }
        if (!$parsed) {
            $parsed = new \DateTime((string) $clientCheckedInAt, $tz);
        }
        if ($parsed && $parsed->format('Y-m-d') === $eventDay) {
            return $parsed->format('Y-m-d H:i:s');
        }
        if ($parsed) {
            throw new \InvalidArgumentException('Check-in time must be on the event date (' . $eventDay . ')');
        }
    }
    $start = trim((string) ($event['start_time'] ?? ''));
    if ($start !== '') {
        $dt = new \DateTime($eventDay . ' ' . $start, $tz);
        return $dt->format('Y-m-d H:i:s');
    }
    return $eventDay . ' 12:00:00';
};

$findExisting = static function () use ($db, $hasFmCol, $eventId, $userId, $partySlot): ?array {
    $existingSql = 'SELECT id, checked_in_at FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
    $existingParams = ['event_id' => $eventId, 'user_id' => $userId];
    if ($hasFmCol) {
        $existingSql .= ' AND IFNULL(family_member_id, 0) = :fmslot';
        $existingParams['fmslot'] = $partySlot;
    }
    $row = $db->queryOne($existingSql, $existingParams);
    return is_array($row) ? $row : null;
};

$isCheckedInForSession = static function (?array $existing) use ($eventDay): bool {
    if (!$existing || empty($existing['checked_in_at'])) {
        return false;
    }
    return substr((string) $existing['checked_in_at'], 0, 10) === $eventDay;
};

    if ($action === 'undo') {
        if (!$isCheckedInForSession($findExisting())) {
            jsonResponse(['success' => false, 'message' => 'No check-in found for this event session'], 400);
        }
        $sql = 'DELETE FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
        $params = ['event_id' => $eventId, 'user_id' => $userId];
        if ($hasFmCol) {
            if ($familyMemberId > 0) {
                $sql .= ' AND family_member_id = :fmid';
                $params['fmid'] = $familyMemberId;
            } else {
                $sql .= ' AND IFNULL(family_member_id, 0) = 0';
            }
        }
        $db->execute($sql, $params);
        try {
            $activityLogger->logUndoCheckinOverride($eventId, $userId, $memberName, $reason, $eventTitle);
        } catch (\Throwable $logErr) {
            error_log('Check-in override activity log failed: ' . $logErr->getMessage());
        }
        jsonResponse(['success' => true, 'message' => 'Check-in removed'], 200);
    }

    $hasGuestsCol = false;
    try {
        $attCols = $db->query('SHOW COLUMNS FROM attendance');
        $hasGuestsCol = in_array('guests_checked_in', array_column($attCols, 'Field'), true);
    } catch (\Exception $e) {
        $hasGuestsCol = false;
    }

    if ($action === 'update') {
        $existing = $findExisting();
        if (!$isCheckedInForSession($existing)) {
            jsonResponse(['success' => false, 'message' => 'No check-in found to update for this event session'], 400);
        }
        try {
            $checkedInAt = $resolveCheckedInAt();
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
        }
        if ($hasGuestsCol) {
            $db->execute(
                'UPDATE attendance SET checked_in_at = :checked_in_at, checked_in_by = :checked_in_by, guests_checked_in = :guests_checked_in WHERE id = :id',
                [
                    'checked_in_at' => $checkedInAt,
                    'checked_in_by' => $checkedInBy,
                    'guests_checked_in' => $guestsCheckedIn,
                    'id' => $existing['id'],
                ]
            );
        } else {
            $db->execute(
                'UPDATE attendance SET checked_in_at = :checked_in_at, checked_in_by = :checked_in_by WHERE id = :id',
                [
                    'checked_in_at' => $checkedInAt,
                    'checked_in_by' => $checkedInBy,
                    'id' => $existing['id'],
                ]
            );
        }
        try {
            $activityLogger->logCheckinTimeUpdated($eventId, $userId, $memberName, $reason, $checkedInAt, $eventTitle);
        } catch (\Throwable $logErr) {
            error_log('Check-in override activity log failed: ' . $logErr->getMessage());
        }
        jsonResponse([
            'success' => true,
            'checked_in_at' => $checkedInAt,
            'checked_in_time' => formatAttendanceLocalTimeForOrganization($checkedInAt, $timezone),
        ], 200);
    }

    // action === checkin
    $existing = $findExisting();
    if ($isCheckedInForSession($existing)) {
        jsonResponse(['success' => false, 'message' => 'Already checked in for this event session'], 400);
    }
    try {
        $checkedInAt = $resolveCheckedInAt();
    } catch (\InvalidArgumentException $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
    }
    $checkedInTime = formatAttendanceLocalTimeForOrganization($checkedInAt, $timezone);

    if ($existing) {
        if ($hasGuestsCol) {
            $db->execute(
                'UPDATE attendance SET checked_in_at = :checked_in_at, checked_in_by = :checked_in_by, guests_checked_in = :guests_checked_in WHERE id = :id',
                [
                    'checked_in_at' => $checkedInAt,
                    'checked_in_by' => $checkedInBy,
                    'guests_checked_in' => $guestsCheckedIn,
                    'id' => $existing['id'],
                ]
            );
        } else {
            $db->execute(
                'UPDATE attendance SET checked_in_at = :checked_in_at, checked_in_by = :checked_in_by WHERE id = :id',
                [
                    'checked_in_at' => $checkedInAt,
                    'checked_in_by' => $checkedInBy,
                    'id' => $existing['id'],
                ]
            );
        }
    } else {
        $insertData = [
            'event_id' => $eventId,
            'user_id' => $userId,
            'checked_in_by' => $checkedInBy,
            'checked_in_at' => $checkedInAt,
        ];
        if ($hasGuestsCol) {
            $insertData['guests_checked_in'] = $guestsCheckedIn;
        }
        if ($hasFmCol && $familyMemberId > 0) {
            $insertData['family_member_id'] = $familyMemberId;
            $insertData['notes'] = 'Family member: ' . $memberName;
        }
        $db->insert('attendance', $insertData);
    }

    try {
        $activityLogger->logCheckinOverride($eventId, $userId, $memberName, $reason, $eventTitle, $checkedInAt);
    } catch (\Throwable $logErr) {
        error_log('Check-in override activity log failed: ' . $logErr->getMessage());
    }

    jsonResponse([
        'success' => true,
        'member_name' => $memberName,
        'checked_in_time' => $checkedInTime,
        'checked_in_at' => $checkedInAt,
    ], 200);
} catch (\Throwable $e) {
    error_log('Check-in override error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Correction failed: ' . $e->getMessage()], 500);
}
