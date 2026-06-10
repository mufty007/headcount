<?php

/**
 * Undo Check-In API Endpoint
 * POST /api/undo-checkin.php
 * Body: { "event_id": 1, "user_id": 2 }
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

header('Content-Type: application/json');

AuthMiddleware::requireAdminOrCoordinator();

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$organizationId = AuthMiddleware::getOrganizationId();

$input = json_decode(file_get_contents('php://input'), true);
$eventId = $input['event_id'] ?? null;
$userId = $input['user_id'] ?? null;
$familyMemberId = isset($input['family_member_id']) ? (int) $input['family_member_id'] : 0;

if (!$eventId || !$userId) {
    jsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
}

// Verify event belongs to organization
$event = $db->queryOne(
    'SELECT id, event_date FROM events WHERE id = :id AND organization_id = :org_id',
    ['id' => $eventId, 'org_id' => $organizationId]
);

if (!$event) {
    jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
}

// Past-event undo requires correction permission (admins or coordinators when enabled)
$org = $db->queryOne('SELECT timezone FROM organizations WHERE id = :id', ['id' => $organizationId]);
$timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
$tz = new \DateTimeZone($timezone);
$today = new \DateTime('today', $tz);
$eventDate = new \DateTime(substr((string) $event['event_date'], 0, 10), $tz);
if ($eventDate < $today && !AuthMiddleware::canCorrectCheckins()) {
    jsonResponse(['success' => false, 'message' => 'You do not have permission to correct attendance for past events'], 403);
}

// Verify user belongs to organization
$user = $db->queryOne("SELECT id FROM users WHERE id = :id AND organization_id = :org_id", [
    'id' => $userId,
    'org_id' => $organizationId
]);

if (!$user) {
    jsonResponse(['success' => false, 'message' => 'User not found'], 404);
}

try {
    $hasFmCol = false;
    try {
        $hasFmCol = $db->hasColumn('attendance', 'family_member_id');
    } catch (\Exception $e) {
        $hasFmCol = false;
    }
    $sql = 'DELETE FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
    $params = [
        'event_id' => $eventId,
        'user_id' => $userId,
    ];
    if ($hasFmCol) {
        if ($familyMemberId > 0) {
            $sql .= ' AND family_member_id = :fmid';
            $params['fmid'] = $familyMemberId;
        } else {
            $sql .= ' AND IFNULL(family_member_id, 0) = 0';
        }
    }
    $db->execute($sql, $params);
    
    jsonResponse(['success' => true], 200);
    
} catch (\Exception $e) {
    error_log("Undo check-in error: " . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Undo failed: ' . $e->getMessage()], 500);
}
