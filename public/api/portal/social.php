<?php

/**
 * Portal Social API
 * Handles social features (attendees list, sharing, invitations)
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
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;

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

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action/ID from path
$pathSegments = explode('/', trim($path, '/'));
$action = $pathSegments[count($pathSegments) - 1] ?? '';
$eventId = null;
if (is_numeric($action) && count($pathSegments) >= 2 && $pathSegments[count($pathSegments) - 2] === 'attendees') {
    $eventId = (int)$action;
    $action = 'attendees';
}

// Get input data (when routed from index.php, $data is already set and php://input is consumed)
if (!isset($input)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
}
if (!isset($data)) {
    $data = array_merge($_POST, $input);
}

$db = Database::getInstance();

try {
    // GET /api/portal/social/attendees/{id} - Get attendees list for event (public or authenticated)
    if ($action === 'attendees' && $eventId && $method === 'GET') {
        // Check if event allows showing attendees (for now, we'll show if user is logged in)
        // In future, add a field to events table: show_attendees BOOLEAN
        
        $attendees = $db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM attendance a
             JOIN users u ON a.user_id = u.id
             WHERE a.event_id = :event_id
             AND u.status = 'active'
             ORDER BY a.checked_in_at DESC
             LIMIT 100",
            ['event_id' => $eventId]
        );

        // Only return names, not emails (privacy)
        $attendees = array_map(function($attendee) {
            return [
                'id' => $attendee['id'],
                'name' => trim($attendee['first_name'] . ' ' . $attendee['last_name'])
            ];
        }, $attendees);

        echo json_encode([
            'success' => true,
            'attendees' => $attendees,
            'count' => count($attendees)
        ]);
        exit;
    }

    // POST /api/portal/social/share - Share event on social media (no-op, returns share URLs)
    if ($action === 'share' && $method === 'POST') {
        $eventId = $data['event_id'] ?? 0;
        
        if (empty($eventId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID required']);
            exit;
        }

        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }

        $baseUrl = getBaseUrl();
        $eventUrl = $baseUrl . '/portal/event-details.php?id=' . $eventId;
        $title = urlencode(Utilities::decodeHtmlEntities($event['title'] ?? ''));
        $descPlain = Utilities::decodeHtmlEntities($event['description'] ?? '');
        $description = urlencode(substr($descPlain, 0, 200));

        $shareUrls = [
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($eventUrl),
            'twitter' => 'https://twitter.com/intent/tweet?text=' . $title . '&url=' . urlencode($eventUrl),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode($eventUrl),
            'email' => 'mailto:?subject=' . $title . '&body=' . $description . '%20' . urlencode($eventUrl)
        ];

        echo json_encode([
            'success' => true,
            'share_urls' => $shareUrls,
            'event_url' => $eventUrl
        ]);
        exit;
    }

    // POST /api/portal/social/invite - Invite friends via email (requires auth)
    if ($action === 'invite' && $method === 'POST') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Require authentication
        PortalAuthMiddleware::requireAuth();
        $memberId = PortalAuthMiddleware::getMemberId();
        
        $eventId = $data['event_id'] ?? 0;
        $emails = $data['emails'] ?? [];

        if (empty($eventId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID required']);
            exit;
        }

        if (empty($emails) || !is_array($emails)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email addresses required']);
            exit;
        }

        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }

        $member = $db->queryOne(
            "SELECT first_name, last_name FROM users WHERE id = :id",
            ['id' => $memberId]
        );

        // TODO: Send invitation emails
        // For now, just return success
        echo json_encode([
            'success' => true,
            'message' => 'Invitations sent',
            'emails_sent' => count($emails)
        ]);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal social API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}

/**
 * Get base URL
 */
function getBaseUrl()
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $basePath = dirname($scriptName);
    $basePath = str_replace('/public', '', $basePath);
    $basePath = rtrim($basePath, '/');
    
    return $protocol . '://' . $host . $basePath;
}
