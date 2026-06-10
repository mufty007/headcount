<?php

/**
 * Portal Dashboard API
 * Provides dashboard data for members (requires authentication)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = __DIR__ . '/../../../config/config.php';
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

// Require authentication
PortalAuthMiddleware::requireAuth();

$memberId = PortalAuthMiddleware::getMemberId();
$organizationId = PortalAuthMiddleware::getOrganizationId();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action from path
$pathSegments = explode('/', trim($path, '/'));
$action = $pathSegments[count($pathSegments) - 1] ?? 'stats';

$db = Database::getInstance();

try {
    // GET /api/portal/dashboard/stats - Get dashboard statistics
    if ($action === 'stats' && $method === 'GET') {
        // Total events attended
        $eventsAttended = $db->queryOne(
            "SELECT COUNT(DISTINCT event_id) as count 
             FROM attendance 
             WHERE user_id = :user_id",
            ['user_id' => $memberId]
        )['count'] ?? 0;

        // Events signed up for (RSVP yes)
        $eventsSignedUp = $db->queryOne(
            "SELECT COUNT(*) as count 
             FROM rsvps 
             WHERE user_id = :user_id AND status = 'yes'",
            ['user_id' => $memberId]
        )['count'] ?? 0;

        // Upcoming events (RSVP yes, event date in future)
        $upcomingCount = $db->queryOne(
            "SELECT COUNT(*) as count 
             FROM rsvps r
             JOIN events e ON r.event_id = e.id
             WHERE r.user_id = :user_id 
             AND r.status = 'yes'
             AND (e.event_date > CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NULL OR e.end_time > CURTIME())))",
            ['user_id' => $memberId]
        )['count'] ?? 0;

        // No-shows (RSVP yes but no attendance)
        $noShows = $db->queryOne(
            "SELECT COUNT(*) as count
             FROM rsvps r
             LEFT JOIN attendance a ON r.event_id = a.event_id AND r.user_id = a.user_id
             WHERE r.user_id = :user_id
             AND r.status = 'yes'
             AND a.id IS NULL
             AND (r.event_id IN (SELECT id FROM events WHERE event_date < CURDATE() OR (event_date = CURDATE() AND (end_time IS NOT NULL AND end_time < CURTIME()))))",
            ['user_id' => $memberId]
        )['count'] ?? 0;

        echo json_encode([
            'success' => true,
            'stats' => [
                'events_attended' => (int)$eventsAttended,
                'events_signed_up' => (int)$eventsSignedUp,
                'upcoming_count' => (int)$upcomingCount,
                'no_shows' => (int)$noShows
            ]
        ]);
        exit;
    }

    // GET /api/portal/dashboard/upcoming - Get upcoming events
    if ($action === 'upcoming' && $method === 'GET') {
        $events = $db->query(
            "SELECT e.*, r.status as rsvp_status, r.created_at as rsvp_date, r.notes as rsvp_notes
             FROM events e
             JOIN rsvps r ON e.id = r.event_id
             WHERE r.user_id = :user_id
             AND r.status = 'yes'
             AND (e.event_date > CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NULL OR e.end_time > CURTIME())))
             ORDER BY e.event_date ASC, e.start_time ASC",
            ['user_id' => $memberId]
        );

        echo json_encode([
            'success' => true,
            'events' => $events,
            'count' => count($events)
        ]);
        exit;
    }

    // GET /api/portal/dashboard/past - Get past attendance
    if ($action === 'past' && $method === 'GET') {
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $offset = ($page - 1) * $limit;

        $events = $db->query(
            "SELECT e.*, a.checked_in_at
             FROM events e
             JOIN attendance a ON e.id = a.event_id
             WHERE a.user_id = :user_id
             AND (e.event_date < CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NOT NULL AND e.end_time < CURTIME())))
             ORDER BY a.checked_in_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            [
                'user_id' => $memberId
            ]
        );

        $total = $db->queryOne(
            "SELECT COUNT(*) as count
             FROM attendance a
             JOIN events e ON a.event_id = e.id
             WHERE a.user_id = :user_id
             AND (e.event_date < CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NOT NULL AND e.end_time < CURTIME())))",
            ['user_id' => $memberId]
        )['count'] ?? 0;

        echo json_encode([
            'success' => true,
            'events' => $events,
            'count' => count($events),
            'total' => (int)$total,
            'page' => $page,
            'limit' => $limit
        ]);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal dashboard API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
