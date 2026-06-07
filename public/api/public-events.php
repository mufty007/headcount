<?php
/**
 * Public Events API
 * This endpoint allows WordPress and other external systems to fetch published events
 * Authentication is done via API key
 */

// Start output buffering
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Disable error display
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    if (!(error_reporting() & $errno)) return false;
    
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Internal Server Error']);
    exit;
}, E_ALL);

// Set exception handler
set_exception_handler(function($exception) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $exception->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;

try {
    // Load config
    $config = require __DIR__ . '/../../config/config.php';
    
    // Initialize database
    Database::getInstance($config['database']);
    
    // Clear any output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    header('Access-Control-Allow-Origin: *'); // Allow cross-origin requests
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    
    // Handle OPTIONS request (CORS preflight)
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
    
    // Only allow GET requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $db = Database::getInstance();
    
    // Get API key from header or query parameter
    $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;
    
    if (!$apiKey) {
        jsonResponse(['success' => false, 'message' => 'API key required'], 401);
    }
    
    // Verify API key and get organization
    $org = $db->queryOne("SELECT id, name FROM organizations WHERE api_key = ?", [$apiKey]);
    
    if (!$org) {
        jsonResponse(['success' => false, 'message' => 'Invalid API key'], 401);
    }
    
    $organizationId = $org['id'];
    
    // Get query parameters
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
    $category = $_GET['category'] ?? null;
    $eventId = isset($_GET['id']) ? (int)$_GET['id'] : null;
    $layout = $_GET['layout'] ?? 'list';
    
    // Build query (public WordPress feed: only published + public visibility)
    $where = ["e.organization_id = :org_id", "e.status = 'published'"];
    $params = ['org_id' => $organizationId];
    try {
        $hasVis = $db->hasColumn('events', 'visibility');
    } catch (\Throwable $e) {
        $hasVis = false;
    }
    if ($hasVis) {
        $where[] = "(e.visibility IS NULL OR e.visibility = '' OR e.visibility = 'public')";
    }
    
    // Filter by specific event ID
    if ($eventId) {
        $where[] = "e.id = :event_id";
        $params['event_id'] = $eventId;
    }
    
    // Filter by category
    if ($category) {
        // Try to find category by slug or name
        $categoryRecord = $db->queryOne(
            "SELECT id FROM categories WHERE (slug = :category OR name = :category) AND organization_id = :org_id",
            ['category' => $category, 'org_id' => $organizationId]
        );
        
        if ($categoryRecord) {
            // Use event_categories table if it exists
            try {
                $where[] = "EXISTS (
                    SELECT 1 FROM event_categories ec 
                    WHERE ec.event_id = e.id AND ec.category_id = :category_id
                )";
                $params['category_id'] = $categoryRecord['id'];
            } catch (Exception $e) {
                // Fallback to legacy category field
                $where[] = "e.category = :category_legacy";
                $params['category_legacy'] = $category;
            }
        } else {
            // Fallback to legacy category field
            $where[] = "e.category = :category_legacy";
            $params['category_legacy'] = $category;
        }
    }
    
    // Only show upcoming or current events
    $where[] = "(e.event_date > CURDATE() OR (e.event_date = CURDATE() AND (e.end_time IS NULL OR e.end_time > CURTIME())))";
    
    $whereClause = implode(' AND ', $where);
    
    // Get events with category names (recurring instances inherit parent banner when own is empty)
    $sql = "SELECT 
                e.id,
                e.title,
                e.description,
                COALESCE(NULLIF(TRIM(e.banner_image), ''), e_parent.banner_image) AS banner_image,
                e.event_date,
                e.start_time,
                e.end_time,
                e.location,
                e.category,
                COALESCE(
                    (SELECT c.name 
                     FROM event_categories ec 
                     INNER JOIN categories c ON ec.category_id = c.id 
                     WHERE ec.event_id = e.id 
                     LIMIT 1),
                    e.category
                ) as category_name,
                e.capacity,
                e.ticket_price,
                e.registration_required,
                (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id) as attendance_count
            FROM events e
            LEFT JOIN events e_parent ON e.parent_event_id = e_parent.id
            WHERE {$whereClause}
            ORDER BY e.event_date ASC, e.start_time ASC
            LIMIT {$limit}";
    
    unset($params['limit']);
    
    $events = $db->query($sql, $params);
    
    // Format events for output
    $formattedEvents = [];
    foreach ($events as $event) {
        // Format banner image URL
        $bannerImage = null;
        if (!empty($event['banner_image'])) {
            // If it's already a full URL, use it as-is
            if (filter_var($event['banner_image'], FILTER_VALIDATE_URL)) {
                $bannerImage = $event['banner_image'];
            } else {
                // Serve via image.php (same as portal); not a direct /uploads/ URL
                $imagePath = ltrim($event['banner_image'], '/');
                $bannerImage = hc_public_api_image_url($imagePath);
            }
        }
        
        $formattedEvents[] = [
            'id' => (int)$event['id'],
            'title' => Utilities::decodeHtmlEntities($event['title'] ?? ''),
            'description' => Utilities::decodeHtmlEntities($event['description'] ?? ''),
            'banner_image' => $bannerImage,
            'event_date' => $event['event_date'],
            'start_time' => $event['start_time'],
            'end_time' => $event['end_time'],
            'location' => Utilities::decodeHtmlEntities($event['location'] ?? ''),
            'category' => Utilities::decodeHtmlEntities((string) ($event['category_name'] ?? $event['category'] ?? '')), // Use category_name if available
            'capacity' => $event['capacity'] ? (int)$event['capacity'] : null,
            'ticket_price' => $event['ticket_price'] ? (float)$event['ticket_price'] : 0.00,
            'registration_required' => (bool)$event['registration_required'],
            'attendance_count' => (int)$event['attendance_count']
        ];
    }
    
    jsonResponse([
        'success' => true,
        'events' => $formattedEvents,
        'count' => count($formattedEvents),
        'organization' => $org['name']
    ]);
    
} catch (Exception $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $response['error'] = [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ];
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Error $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json', true);
    header('X-Content-Type-Options: nosniff', true);
    http_response_code(500);
    
    $response = [
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ];
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
