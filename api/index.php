<?php

/**
 * API Entry Point
 * Handles all API requests
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Core\Bootstrap;
use Headcount\Core\Logger;

// Initialize application
Bootstrap::init();

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request method and URI
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Remove base path (case-insensitive) - handles /Headcount/api, /headcount/api, etc.
$scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if (!empty($scriptDir) && $scriptDir !== '/' && $scriptDir !== '\\') {
    // Remove script directory from URI
    if (stripos($uri, $scriptDir) === 0) {
        $uri = substr($uri, strlen($scriptDir));
    }
}
// Remove /api from path if present
$uri = preg_replace('#^/api#i', '', $uri);
$uri = trim($uri, '/');
$path = explode('/', $uri);
// Filter out empty path segments
$path = array_filter($path, function($seg) { return !empty($seg); });
$path = array_values($path); // Re-index array

// Debug logging
error_log("API Route Debug - URI: $uri, Path array: " . print_r($path, true) . ", Method: $method");

// Get request body
// Check if request has JSON content type
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$isJson = strpos($contentType, 'application/json') !== false;

if ($isJson) {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
} else {
    $input = [];
}

$params = array_merge($_GET, $_POST, $input);

// Route request
try {
    $response = routeRequest($method, $path, $params);
    http_response_code(200);
    echo json_encode($response);
} catch (\Exception $e) {
    Logger::error("API Error: " . $e->getMessage(), $e);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred',
        'errors' => []
    ]);
}

/**
 * Route API requests
 */
function routeRequest($method, $path, $params)
{
    $controllers = Bootstrap::getControllers();
    $organizationId = Bootstrap::getOrganizationId();
    $userId = Bootstrap::getUserId();

    // Public routes (no auth required)
    if ($path[0] === 'auth' && $path[1] === 'login') {
        if ($method === 'POST') {
            return $controllers['auth']->login(
                $params['email'] ?? '',
                $params['password'] ?? '',
                $params['remember_me'] ?? false,
                $params['organization_id'] ?? null
            );
        }
    }

    // Check authentication for all other routes
    if (!Bootstrap::isAuthenticated()) {
        http_response_code(401);
        return [
            'success' => false,
            'message' => 'Authentication required',
            'errors' => []
        ];
    }

    // Events routes
    if ($path[0] === 'events') {
        if ($method === 'GET' && empty($path[1])) {
            return $controllers['event']->listEvents($organizationId, $params);
        }
        if ($method === 'POST' && empty($path[1])) {
            return $controllers['event']->createEvent($params, $organizationId, $userId);
        }
        if ($method === 'PUT' && !empty($path[1])) {
            return $controllers['event']->updateEvent($path[1], $params, $organizationId);
        }
        if ($method === 'POST' && $path[1] === 'duplicate' && !empty($path[2])) {
            return $controllers['event']->duplicateEvent($path[2], $organizationId);
        }
    }

    // Members routes
    if (isset($path[0]) && $path[0] === 'members') {
        // Handle import route first (before checking if path[1] is empty)
        if ($method === 'POST' && isset($path[1]) && $path[1] === 'import') {
            // Handle file upload for import
            $file = $_FILES['file'] ?? null;
            $mapping = $params['mapping'] ?? [];
            $options = $params['options'] ?? [];
            return $controllers['member']->import($file, $mapping, $options);
        }
        if ($method === 'GET' && empty($path[1])) {
            return $controllers['member']->index($params, $params['page'] ?? 1);
        }
        if ($method === 'GET' && isset($path[1]) && $path[1] === 'search') {
            return $controllers['member']->search($params['q'] ?? '');
        }
        if ($method === 'GET' && isset($path[1]) && $path[1] !== 'search') {
            return $controllers['member']->show($path[1]);
        }
        if ($method === 'POST' && empty($path[1])) {
            return $controllers['member']->create($params);
        }
        if ($method === 'PUT' && isset($path[1]) && !empty($path[1])) {
            return $controllers['member']->update($path[1], $params);
        }
        if ($method === 'DELETE' && isset($path[1]) && !empty($path[1])) {
            return $controllers['member']->delete($path[1]);
        }
    }

    // Attendance routes
    if ($path[0] === 'attendance') {
        if ($method === 'GET' && $path[1] === 'search' && !empty($path[2])) {
            return $controllers['attendance']->searchMembers(
                $path[2],
                $organizationId,
                $params['q'] ?? ''
            );
        }
        if ($method === 'POST' && $path[1] === 'checkin') {
            return $controllers['attendance']->checkIn(
                $params['event_id'] ?? 0,
                $params['user_id'] ?? 0,
                $userId,
                $organizationId
            );
        }
        if ($method === 'POST' && $path[1] === 'bulk-checkin') {
            return $controllers['attendance']->bulkCheckIn(
                $params['event_id'] ?? 0,
                $params['user_ids'] ?? [],
                $userId,
                $organizationId
            );
        }
        if ($method === 'POST' && $path[1] === 'undo' && !empty($path[2])) {
            return $controllers['attendance']->undoCheckIn(
                $params['event_id'] ?? 0,
                $params['user_id'] ?? 0,
                $organizationId
            );
        }
        if ($method === 'GET' && !empty($path[1])) {
            return $controllers['attendance']->getEventAttendance($path[1], $organizationId);
        }
    }

    // Payment routes
    if ($path[0] === 'payments') {
        if ($method === 'POST' && $path[1] === 'checkout') {
            // Get event first
            $models = Bootstrap::getModels();
            $event = $models['event']->find($params['event_id'] ?? 0);
            if (!$event) {
                return [
                    'success' => false,
                    'message' => 'Event not found',
                    'errors' => []
                ];
            }
            return $controllers['payment']->createCheckout(
                $event,
                $userId,
                $params['success_url'] ?? '',
                $params['cancel_url'] ?? ''
            );
        }
        if ($method === 'POST' && $path[1] === 'webhook') {
            $payload = file_get_contents('php://input');
            $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
            return $controllers['payment']->handleWebhook($payload, $signature);
        }
        if ($method === 'POST' && $path[1] === 'refund' && !empty($path[2])) {
            return $controllers['payment']->processRefund(
                $path[2],
                $params['amount'] ?? null
            );
        }
    }

    // Auth routes
    if ($path[0] === 'auth') {
        if ($path[1] === 'logout' && $method === 'POST') {
            return $controllers['auth']->logout();
        }
        if ($path[1] === 'forgot-password' && $method === 'POST') {
            return $controllers['auth']->forgotPassword(
                $params['email'] ?? '',
                $organizationId
            );
        }
        if ($path[1] === 'reset-password' && $method === 'POST') {
            return $controllers['auth']->resetPassword(
                $params['token'] ?? '',
                $params['password'] ?? '',
                $params['password_confirm'] ?? null
            );
        }
    }

    // Settings routes
    if ($path[0] === 'settings') {
        if ($method === 'GET' && empty($path[1])) {
            return $controllers['settings']->getSettings();
        }
        if ($method === 'POST' && $path[1] === 'org') {
            // Merge files into params for organization settings
            if (isset($_FILES['logo'])) {
                $params['_files'] = ['logo' => $_FILES['logo']];
            }
            return $controllers['settings']->updateOrganization($params);
        }
        if ($method === 'POST' && $path[1] === 'email') {
            return $controllers['settings']->updateEmail($params);
        }
        if ($method === 'POST' && $path[1] === 'payments') {
            return $controllers['settings']->updatePayments($params);
        }
        if ($method === 'POST' && $path[1] === 'notifications') {
            return $controllers['settings']->updateNotifications($params);
        }
        if ($method === 'POST' && $path[1] === 'account') {
            return $controllers['settings']->updateAccount($params);
        }
        if ($method === 'POST' && $path[1] === 'test-email') {
            return $controllers['settings']->testEmail();
        }
        if ($method === 'POST' && $path[1] === 'test-stripe') {
            return $controllers['settings']->testStripe();
        }
    }

    // 404 - Route not found
    http_response_code(404);
    return [
        'success' => false,
        'message' => 'Route not found',
        'errors' => []
    ];
}
