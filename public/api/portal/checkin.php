<?php

/**
 * Portal Check-In API
 * Handles QR code check-in (requires admin authentication for scanning)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Services\EventEligibilityService;
use Headcount\Services\QRCodeService;
use Headcount\Middleware\AuthMiddleware; // Admin auth for scanning
use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

// Get request method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Get input data
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($_POST, $input);

$qrService = new QRCodeService();

try {
    // POST /api/portal/checkin/qr - Check in via QR code scan (requires admin auth)
    if ($method === 'POST') {
        // Require admin authentication for scanning
        // Note: This uses admin auth, not portal auth
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if admin or coordinator is logged in (for QR scan check-in)
        $role = $_SESSION['role'] ?? null;
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($role, ['admin', 'coordinator'], true)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Admin or coordinator authentication required']);
            exit;
        }
        
        // Use AuthMiddleware for consistent authentication
        $adminId = AuthMiddleware::getUserId();
        $organizationId = AuthMiddleware::getOrganizationId();
        
        if (!$adminId || !$organizationId) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Admin or coordinator authentication required']);
            exit;
        }
        
        $qrCode = $data['qr_code'] ?? '';
        $eventId = $data['event_id'] ?? 0;

        if (empty($qrCode)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'QR code is required']);
            exit;
        }

        if (empty($eventId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID is required']);
            exit;
        }
        
        // Get database instance
        $db = Database::getInstance();
        
        // Verify event belongs to organization and get event details for timing validation
        $event = $db->queryOne('SELECT * FROM events WHERE id = :id AND organization_id = :org_id', [
            'id' => $eventId,
            'org_id' => $organizationId
        ]);

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }
        
        // Validate check-in timing (same logic as regular check-in)
        $org = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $organizationId]);
        $timezone = OrgTimeZone::resolve(is_array($org) ? ($org['timezone'] ?? null) : null);
        $tz = new \DateTimeZone($timezone);
        
        $now = new \DateTime('now', $tz);
        $eventDate = new \DateTime($event['event_date'], $tz);
        $today = new \DateTime('today', $tz);
        
        // Check if it's the day of the event
        if ($eventDate->format('Y-m-d') !== $today->format('Y-m-d')) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Check-in is only allowed on the day of the event']);
            exit;
        }
        
        // Check check-in window
        $canCheckIn = false;
        $windowMessage = '';
        
        if ($event['checkin_window_start'] && $event['checkin_window_end']) {
            $windowStart = new \DateTime($event['event_date'] . ' ' . $event['checkin_window_start'], $tz);
            $windowEnd = new \DateTime($event['event_date'] . ' ' . $event['checkin_window_end'], $tz);
            $canCheckIn = ($now >= $windowStart && $now <= $windowEnd);
            if (!$canCheckIn) {
                $windowMessage = 'Check-in is only allowed between ' . $windowStart->format('g:i A') . ' and ' . $windowEnd->format('g:i A');
            }
        } else if ($event['start_time']) {
            $eventStart = new \DateTime($event['event_date'] . ' ' . $event['start_time'], $tz);
            $checkinStart = clone $eventStart;
            
            if ($event['end_time']) {
                $eventEnd = new \DateTime($event['event_date'] . ' ' . $event['end_time'], $tz);
            } else {
                $eventEnd = clone $eventStart;
                $eventEnd->modify('+2 hours');
            }
            
            $canCheckIn = ($now >= $checkinStart && $now <= $eventEnd);
            if (!$canCheckIn) {
                if ($now < $checkinStart) {
                    $windowMessage = 'Check-in opens at ' . $checkinStart->format('g:i A') . ' (event start)';
                } else {
                    $windowMessage = 'Check-in closed. The event has ended.';
                }
            }
        } else {
            $canCheckIn = true;
        }
        
        if (!$canCheckIn) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $windowMessage ?: 'Check-in is not allowed at this time']);
            exit;
        }

        $hasFmAtt = false;
        try {
            $hasFmAtt = $db->hasColumn('attendance', 'family_member_id');
        } catch (\Exception $e) {
            $hasFmAtt = false;
        }
        $eligibilitySvc = new EventEligibilityService($db);

        // Validate QR code
        $user = $qrService->validateQRCode($qrCode);
        
        if (!$user) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or expired QR code'
            ]);
            exit;
        }

        // Verify user belongs to same organization
        if ($user['organization_id'] != $organizationId) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'User does not belong to this organization'
            ]);
            exit;
        }

        // Check if family member check-in is requested
        $familyMemberId = $data['family_member_id'] ?? null;
        
        // Store the scanned user ID for family member check-ins
        $scannedUserId = $user['id'];
        
        if ($familyMemberId) {
            // Verify family member belongs to the scanned user
            $familyMember = $db->queryOne(
                "SELECT * FROM family_members 
                 WHERE id = :id AND parent_user_id = :user_id",
                [
                    'id' => $familyMemberId,
                    'user_id' => $scannedUserId
                ]
            );
            
            if (!$familyMember) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Invalid family member'
                ]);
                exit;
            }

            if (!empty($event['enforce_restrictions_at_checkin'])) {
                $checkUserIdElig = $familyMember['linked_user_id'] ?? null;
                if ($checkUserIdElig) {
                    $linkedProfile = $db->queryOne(
                        'SELECT id, first_name, last_name, date_of_birth, gender FROM users WHERE id = ? AND status != ?',
                        [(int) $checkUserIdElig, 'deleted']
                    );
                    $chk = is_array($linkedProfile) ? $eligibilitySvc->checkEligibility($event, $linkedProfile, null) : ['ok' => false, 'message' => 'Profile not found.'];
                } else {
                    $chk = $eligibilitySvc->checkEligibility($event, null, $familyMember);
                }
                if (empty($chk['ok'])) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => $chk['message'] ?? 'Check-in blocked by event eligibility rules.']);
                    exit;
                }
            }
            
            // Check if family member already checked in (via linked user if exists)
            $checkUserId = $familyMember['linked_user_id'] ?? null;
            if ($checkUserId) {
                $sqlEx = 'SELECT * FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
                $parEx = [
                    'event_id' => $eventId,
                    'user_id' => $checkUserId,
                ];
                if ($hasFmAtt) {
                    $sqlEx .= ' AND IFNULL(family_member_id, 0) = 0';
                }
                $existing = $db->queryOne($sqlEx, $parEx);
                
                if ($existing) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Already checked in',
                        'user' => [
                            'id' => $checkUserId,
                            'name' => trim($familyMember['first_name'] . ' ' . $familyMember['last_name']),
                            'is_family_member' => true
                        ],
                        'already_checked_in' => true
                    ]);
                    exit;
                }
                
                // Create attendance record for linked user
                $checkedInAt = date('Y-m-d H:i:s');
                $attendanceId = $db->insert('attendance', [
                    'event_id' => $eventId,
                    'user_id' => $checkUserId,
                    'checked_in_by' => $adminId,
                    'checked_in_at' => $checkedInAt
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Family member check-in successful',
                    'user' => [
                        'id' => $checkUserId,
                        'name' => trim($familyMember['first_name'] . ' ' . $familyMember['last_name']),
                        'first_name' => $familyMember['first_name'],
                        'last_name' => $familyMember['last_name'],
                        'is_family_member' => true
                    ],
                    'attendance_id' => $attendanceId,
                    'checked_in_at' => $checkedInAt,
                    'checked_in_time' => date('g:i A', strtotime($checkedInAt))
                ]);
                exit;
            } else {
                $sqlUn = 'SELECT * FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
                $parUn = ['event_id' => $eventId, 'user_id' => $user['id']];
                if ($hasFmAtt) {
                    $sqlUn .= ' AND family_member_id = :fmid';
                    $parUn['fmid'] = (int) $familyMember['id'];
                }
                $existingUnlinked = $db->queryOne($sqlUn, $parUn);
                if ($existingUnlinked) {
                    echo json_encode([
                        'success' => true,
                        'message' => 'Already checked in',
                        'user' => [
                            'id' => $user['id'],
                            'name' => trim($familyMember['first_name'] . ' ' . $familyMember['last_name']),
                            'is_family_member' => true,
                        ],
                        'already_checked_in' => true,
                    ]);
                    exit;
                }
                $checkedInAt = date('Y-m-d H:i:s');
                $ins = [
                    'event_id' => $eventId,
                    'user_id' => $user['id'],
                    'checked_in_by' => $adminId,
                    'checked_in_at' => $checkedInAt,
                    'notes' => 'Family member: ' . trim($familyMember['first_name'] . ' ' . $familyMember['last_name']),
                ];
                if ($hasFmAtt) {
                    $ins['family_member_id'] = (int) $familyMember['id'];
                }
                $attendanceId = $db->insert('attendance', $ins);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Family member check-in successful',
                    'user' => [
                        'id' => $user['id'],
                        'name' => trim($familyMember['first_name'] . ' ' . $familyMember['last_name']),
                        'first_name' => $familyMember['first_name'],
                        'last_name' => $familyMember['last_name'],
                        'is_family_member' => true,
                        'parent_name' => $user['name']
                    ],
                    'attendance_id' => $attendanceId,
                    'checked_in_at' => $checkedInAt,
                    'checked_in_time' => date('g:i A', strtotime($checkedInAt))
                ]);
                exit;
            }
        }
        
        // Regular user check-in
        if (!empty($event['enforce_restrictions_at_checkin'])) {
            $uProf = $db->queryOne(
                'SELECT id, first_name, last_name, date_of_birth, gender FROM users WHERE id = ? AND status != ?',
                [(int) $user['id'], 'deleted']
            );
            $chk = is_array($uProf) ? $eligibilitySvc->checkEligibility($event, $uProf, null) : ['ok' => false, 'message' => 'Profile not found.'];
            if (empty($chk['ok'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $chk['message'] ?? 'Check-in blocked by event eligibility rules.']);
                exit;
            }
        }
        $sqlReg = 'SELECT * FROM attendance WHERE event_id = :event_id AND user_id = :user_id';
        $parReg = [
            'event_id' => $eventId,
            'user_id' => $user['id'],
        ];
        if ($hasFmAtt) {
            $sqlReg .= ' AND IFNULL(family_member_id, 0) = 0';
        }
        $existing = $db->queryOne($sqlReg, $parReg);

        if ($existing) {
            echo json_encode([
                'success' => true,
                'message' => 'Already checked in',
                'user' => $user,
                'already_checked_in' => true,
                'family_members' => $user['family_members'] ?? []
            ]);
            exit;
        }

        // Create attendance record
        $checkedInAt = date('Y-m-d H:i:s');
        $attendanceId = $db->insert('attendance', [
            'event_id' => $eventId,
            'user_id' => $user['id'],
            'checked_in_by' => $adminId,
            'checked_in_at' => $checkedInAt
        ]);

        // Get user's first and last name for response
        $nameParts = explode(' ', $user['name'], 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        echo json_encode([
            'success' => true,
            'message' => 'Check-in successful',
            'user' => array_merge($user, [
                'first_name' => $firstName,
                'last_name' => $lastName
            ]),
            'attendance_id' => $attendanceId,
            'checked_in_at' => $checkedInAt,
            'checked_in_time' => date('g:i A', strtotime($checkedInAt)),
            'family_members' => $user['family_members'] ?? []
        ]);
        exit;
    }

    // 405 - Method not allowed
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal check-in API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
