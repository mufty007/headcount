<?php

/**
 * Portal Calendar API
 * Handles calendar integration (ICS files, Google Calendar, Apple Calendar)
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
use Headcount\Helpers\Security;
use Headcount\Helpers\CalendarHelper;

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

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action/ID from path
$pathSegments = explode('/', trim($path, '/'));
$lastSegment = $pathSegments[count($pathSegments) - 1] ?? '';
$secondLast = count($pathSegments) >= 2 ? $pathSegments[count($pathSegments) - 2] : '';

// Check for .ics extension
$eventId = null;
$action = '';
if (strpos($lastSegment, '.ics') !== false) {
    $eventId = (int)str_replace('.ics', '', $lastSegment);
    $action = 'ics';
} elseif (is_numeric($lastSegment) && $secondLast === 'event') {
    $eventId = (int)$lastSegment;
    $action = 'ics';
} else {
    $action = $lastSegment;
    if ($secondLast === 'google' || $secondLast === 'apple') {
        $eventId = is_numeric($lastSegment) ? (int)$lastSegment : null;
    }
}

$db = Database::getInstance();

try {
    // GET /api/portal/calendar/event/{id}.ics - Download ICS file
    if ($eventId && ($action === 'ics' || strpos($path, '.ics') !== false) && $method === 'GET') {
        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo "Event not found";
            exit;
        }

        // Generate ICS content
        $icsContent = CalendarHelper::generateICS($event);

        // Set headers for ICS file download
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="event-' . $eventId . '.ics"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

        echo $icsContent;
        exit;
    }

    // GET /api/portal/calendar/google/{id} - Redirect to Google Calendar
    if ($secondLast === 'google' && $eventId && $method === 'GET') {

        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }

        $googleUrl = CalendarHelper::getGoogleCalendarLink($event);
        header('Location: ' . $googleUrl);
        exit;
    }

    // GET /api/portal/calendar/apple/{id} - Redirect to Apple Calendar
    if ($secondLast === 'apple' && $eventId && $method === 'GET') {

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
        $appleUrl = CalendarHelper::getAppleCalendarLink($event, $baseUrl);
        header('Location: ' . $appleUrl);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal calendar API error: " . $e->getMessage());
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
