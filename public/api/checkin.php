<?php

/**
 * Check-In API Endpoint
 * POST /api/checkin.php
 * Body: { "event_id": 1, "user_id": 2 }
 */

// Start output buffering to prevent any accidental output
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
use Headcount\Helpers\OrgTimeZone;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\ActivityLogger;
use Headcount\Services\EventEligibilityService;

$config = require HC_PROJECT_ROOT . '/config/config.php';
Database::getInstance($config['database']);

// Set JSON header early
if (!headers_sent()) {
    header('Content-Type: application/json');
} else {
    error_log("Check-in API - WARNING: Headers already sent!");
}

// Require admin or coordinator authentication
AuthMiddleware::requireAdminOrCoordinator();

if (!isPost()) {
    jsonResponse(['success' => false, 'message' => 'Invalid request method'], 405);
}

$organizationId = AuthMiddleware::getOrganizationId();
$checkedInBy = AuthMiddleware::getUserId();
$db = Database::getInstance();

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("Check-in API - JSON decode error: " . json_last_error_msg() . " | Input: " . file_get_contents('php://input'));
    jsonResponse(['success' => false, 'message' => 'Invalid JSON in request body'], 400);
}

$eventId = $input['event_id'] ?? null;
$userId = $input['user_id'] ?? null;
$familyMemberId = isset($input['family_member_id']) ? (int) $input['family_member_id'] : 0;
$clientCheckedInAt = $input['checked_in_at'] ?? null; // optional; for offline sync (Option B)

if (!$eventId || !$userId) {
    error_log("Check-in API - Missing required fields. event_id: " . ($eventId ?: 'null') . ", user_id: " . ($userId ?: 'null'));
    jsonResponse(['success' => false, 'message' => 'Missing required fields: event_id and user_id are required'], 400);
}

// Verify event belongs to organization and get event details
$event = $db->queryOne("SELECT * FROM events WHERE id = :id AND organization_id = :org_id", [
    'id' => $eventId,
    'org_id' => $organizationId
]);

if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

// Get organization timezone
$org = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $organizationId]);
$timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);

// Validate check-in timing (using organization's timezone)
$windowCheck = headcount_validate_live_checkin_window($event, $timezone);
if (!$windowCheck['ok']) {
    $errorMessage = $windowCheck['message'] ?: 'Check-in is not allowed at this time';
    error_log("Check-in API - Check-in window validation failed: " . $errorMessage . " | Event ID: " . $eventId);
    jsonResponse(['success' => false, 'message' => $errorMessage], 400);
}

$guestsCheckedIn = headcount_default_guests_checked_in_from_rsvp(
    $db,
    (int) $eventId,
    (int) $userId,
    isset($input['guests_checked_in']) ? max(0, min(20, (int) $input['guests_checked_in'])) : 0
);

// Verify user belongs to organization
$user = $db->queryOne("SELECT id, first_name, last_name, date_of_birth, gender FROM users WHERE id = :id AND organization_id = :org_id", [
    'id' => $userId,
    'org_id' => $organizationId
]);

if (!$user) {
    jsonResponse(['success' => false, 'message' => 'User not found'], 404);
}

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

if (!empty($event['enforce_restrictions_at_checkin'])) {
    $elig = new EventEligibilityService($db);
    if ($familyMemberRow) {
        $chk = $elig->checkEligibility($event, null, $familyMemberRow);
    } else {
        $chk = $elig->checkEligibility($event, $user, null);
    }
    if (empty($chk['ok'])) {
        jsonResponse(['success' => false, 'message' => $chk['message'] ?? 'Check-in blocked by event eligibility rules.'], 400);
    }
}

$hasFmCol = false;
try {
    $hasFmCol = $db->hasColumn('attendance', 'family_member_id');
} catch (\Exception $e) {
    $hasFmCol = false;
}
$partySlot = $familyMemberId > 0 ? $familyMemberId : 0;

try {
    // Check if attendance record exists (primary slot vs family_member row)
    $existingSql = 'SELECT id, checked_in_at FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
    $existingParams = [
        'event_id' => $eventId,
        'user_id' => $userId,
    ];
    if ($hasFmCol) {
        $existingSql .= ' AND IFNULL(family_member_id, 0) = :fmslot';
        $existingParams['fmslot'] = $partySlot;
    }
    $existing = $db->queryOne($existingSql, $existingParams);
    
    // Match checkin-rsvps.php: one row per (event_id, user_id), but "checked in for this session"
    // only when DATE(checked_in_at) equals this event's event_date (multi-session / series rows).
    if ($existing && !empty($existing['checked_in_at'])) {
        if (headcount_attendance_on_event_date($existing['checked_in_at'], (string) $event['event_date'])) {
            jsonResponse(['success' => false, 'message' => 'Already checked in'], 400);
        }
    }
    
    // Use client timestamp if provided (offline sync), else org-local now
    $checkedInAt = headcount_parse_checkin_timestamp_for_org($clientCheckedInAt, $timezone);
    $checkedInTime = formatAttendanceLocalTimeForOrganization($checkedInAt, $timezone);
    
    $hasGuestsCol = false;
    try {
        $attCols = $db->query("SHOW COLUMNS FROM attendance");
        $hasGuestsCol = in_array('guests_checked_in', array_column($attCols, 'Field'));
    } catch (\Exception $e) { /* ignore */ }

    if ($existing) {
        // Update existing record
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
        // Create new record
        $insertData = [
            'event_id' => $eventId,
            'user_id' => $userId,
            'checked_in_by' => $checkedInBy,
            'checked_in_at' => $checkedInAt
        ];
        if ($hasGuestsCol) {
            $insertData['guests_checked_in'] = $guestsCheckedIn;
        }
        if ($hasFmCol && $familyMemberId > 0) {
            $insertData['family_member_id'] = $familyMemberId;
            $insertData['notes'] = 'Family member: ' . trim($familyMemberRow['first_name'] . ' ' . $familyMemberRow['last_name']);
        }
        $db->insert('attendance', $insertData);
    }
    
    $memberName = trim($user['first_name'] . ' ' . $user['last_name']);
    if ($familyMemberRow) {
        $memberName = trim($familyMemberRow['first_name'] . ' ' . $familyMemberRow['last_name']);
    }
    
    // Get event title for activity log
    $eventTitle = $db->queryOne("SELECT title FROM events WHERE id = :id", ['id' => $eventId])['title'] ?? null;
    
    // Log activity
    $activityLogger = new ActivityLogger($organizationId, $checkedInBy);
    $activityLogger->logCheckIn($eventId, $userId, $memberName, $eventTitle);
    
    jsonResponse([
        'success' => true,
        'member_name' => $memberName,
        'checked_in_time' => $checkedInTime,
        'checked_in_at' => $checkedInAt,
        'guests_checked_in' => $guestsCheckedIn,
    ], 200);
    
} catch (\Exception $e) {
    error_log("Check-in error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Check-in failed: ' . $e->getMessage()], 500);
}
