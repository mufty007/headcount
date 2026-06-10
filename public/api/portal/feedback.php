<?php

/**
 * Portal Feedback API
 * Handles event feedback and ratings (requires authentication)
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
if (is_numeric($action) && count($pathSegments) >= 2 && $pathSegments[count($pathSegments) - 2] === 'event') {
    $eventId = (int)$action;
    $action = 'event';
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
    // POST /api/portal/feedback - Submit feedback (requires auth)
    if (empty($action) && $method === 'POST') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Require authentication
        PortalAuthMiddleware::requireAuth();
        $memberId = PortalAuthMiddleware::getMemberId();
        
        $errors = [];

        $eventId = $data['event_id'] ?? 0;
        $rating = isset($data['rating']) ? (int)$data['rating'] : 0;
        $feedbackText = $data['feedback_text'] ?? '';

        if (empty($eventId)) {
            $errors[] = 'Event ID is required';
        }

        if ($rating < 1 || $rating > 5) {
            $errors[] = 'Rating must be between 1 and 5';
        }

        // Verify user attended the event
        if (empty($errors)) {
            $attendance = $db->queryOne(
                "SELECT * FROM attendance WHERE event_id = :event_id AND user_id = :user_id",
                ['event_id' => $eventId, 'user_id' => $memberId]
            );

            if (!$attendance) {
                $errors[] = 'You can only provide feedback for events you attended';
            }
        }

        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'errors' => $errors]);
            exit;
        }

        // Check if feedback already exists
        $existing = $db->queryOne(
            "SELECT * FROM event_feedback WHERE event_id = :event_id AND user_id = :user_id",
            ['event_id' => $eventId, 'user_id' => $memberId]
        );

        if ($existing) {
            // Update existing feedback
            $db->update('event_feedback', $existing['id'], [
                'rating' => $rating,
                'feedback_text' => !empty($feedbackText) ? trim($feedbackText) : null
            ]);
        } else {
            // Create new feedback
            $db->insert('event_feedback', [
                'event_id' => $eventId,
                'user_id' => $memberId,
                'rating' => $rating,
                'feedback_text' => !empty($feedbackText) ? trim($feedbackText) : null
            ]);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Feedback submitted successfully'
        ]);
        exit;
    }

    // GET /api/portal/feedback/event/{id} - Get feedback for event (public)
    if ($action === 'event' && $eventId && $method === 'GET') {
        $feedback = $db->query(
            "SELECT f.*, u.first_name, u.last_name
             FROM event_feedback f
             JOIN users u ON f.user_id = u.id
             WHERE f.event_id = :event_id
             ORDER BY f.created_at DESC",
            ['event_id' => $eventId]
        );

        // Calculate average rating
        $avgRating = $db->queryOne(
            "SELECT AVG(rating) as avg_rating, COUNT(*) as count
             FROM event_feedback
             WHERE event_id = :event_id",
            ['event_id' => $eventId]
        );

        echo json_encode([
            'success' => true,
            'feedback' => $feedback,
            'average_rating' => round($avgRating['avg_rating'] ?? 0, 2),
            'rating_count' => (int)($avgRating['count'] ?? 0)
        ]);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal feedback API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}
