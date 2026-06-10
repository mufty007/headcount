<?php

/**
 * API Router
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Controllers\EventController;
use Headcount\Controllers\MemberController;
use Headcount\Controllers\AttendanceController;
use Headcount\Controllers\AuthController;
use Headcount\Controllers\CategoryController;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Core\RateLimiter;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Initialize system if not already done
if (!defined('BASE_PATH')) {
    define('BASE_PATH', HC_PROJECT_ROOT);
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', dirname(__DIR__));
}

// Load configuration and initialize database
$config = [];
$configFile = CONFIG_PATH . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    
    try {
        // Configure session BEFORE starting it
        Security::configureSession();
        
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Initialize database (singleton pattern - will only initialize once)
        Database::getInstance($config['database']);
    } catch (\Exception $e) {
        error_log("API initialization error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'System initialization failed',
            'data' => null,
            'errors' => []
        ]);
        exit;
    }
}

// Check if this is a portal QR code image endpoint - skip JSON header
// We need to check early before setting JSON header
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$pathForCheck = parse_url($requestUri, PHP_URL_PATH);
if (preg_match('#/api/portal/qr-?code/image#i', $pathForCheck)) {
    define('SKIP_JSON_HEADER', true);
}

// Set JSON response header (skip for image endpoint)
if (!defined('SKIP_JSON_HEADER')) {
    header('Content-Type: application/json');
}

// Ensure we never redirect from API endpoints - always return JSON
// Remove any Location header that might have been set
if (function_exists('header_remove')) {
    header_remove('Location');
}

// Initialize security logger
use Headcount\Core\SecurityLogger;
SecurityLogger::init();

// Handle CORS — explicit allowlist only (no origin reflection)
$allowedOrigins = array_filter([
    rtrim($config['app']['url'] ?? '', '/'),
    rtrim($config['app']['portal_url'] ?? '', '/'),
]);
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$origin}");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    }
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Requested-With, Authorization');
    exit(0);
}

// Get request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

// Find /api in the path and extract everything after it
// This handles both /headcount/api/... and /headcount/public/api/... patterns
$apiPos = strpos($path, '/api');
if ($apiPos !== false) {
    // Extract path after /api (skip the '/api' part)
    $path = substr($path, $apiPos + 4); // +4 to skip '/api'
    // Remove query string if present
    $path = strtok($path, '?');
} else {
    // If /api not found in path, the path might already be relative to /api
    // Or we need to extract from script name
    $scriptDir = dirname($scriptName);
    if (strpos($scriptDir, '/api') !== false) {
        $apiPos = strpos($scriptDir, '/api');
        $basePath = substr($scriptDir, 0, $apiPos);
        if (!empty($basePath) && strpos($path, $basePath) === 0) {
            $path = substr($path, strlen($basePath));
        }
        if (strpos($path, '/api') === 0) {
            $path = substr($path, 4);
        }
    } elseif (strpos($path, '/api') === 0) {
        // Path starts with /api directly
        $path = substr($path, 4);
    }
}

$path = trim($path, '/');
$segments = empty($path) ? [] : explode('/', $path);
// Filter out empty segments
$segments = array_filter($segments, function($seg) { return !empty($seg); });
$segments = array_values($segments); // Re-index array

// Check if this is a direct file request (e.g., search-members.php, checkin.php, image.php, public-events.php, etc.)
// If the first segment ends with .php, try to load it directly
if (!empty($segments[0]) && strpos($segments[0], '.php') !== false) {
    $directFile = PUBLIC_PATH . '/api/' . $segments[0];
    if (file_exists($directFile) && is_file($directFile)) {
        // For image.php, skip JSON header
        if ($segments[0] === 'image.php') {
            define('SKIP_JSON_HEADER', true);
        }
        // Load the direct API file
        require $directFile;
        exit;
    }
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($_POST, $input);

// Route requests
try {
    // Apply API rate limiting (except for webhooks and authenticated portal endpoints)
    // Portal endpoints require authentication, so they don't need strict rate limiting
    $isPortalEndpoint = isset($segments[0]) && $segments[0] === 'portal';
    $isWebhook = ($segments[0] ?? '') === 'payments' && ($segments[1] ?? '') === 'webhook';
    
    // Check if this is a portal auth endpoint that should skip CSRF check
    // Portal auth endpoints handle CSRF verification themselves (or skip it for logout)
    $isPortalAuth = $isPortalEndpoint && isset($segments[1]) && $segments[1] === 'auth';
    $isPortalAuthLogin = $isPortalAuth && isset($segments[2]) && $segments[2] === 'login';
    $isPortalAuthLogout = $isPortalAuth && isset($segments[2]) && $segments[2] === 'logout';
    
    if (!$isPortalEndpoint && !$isWebhook) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        RateLimiter::checkApiRateLimit($ip);
    }
    
    // Verify CSRF for POST/PUT/DELETE (except webhooks and portal auth endpoints)
    // Portal auth endpoints handle CSRF verification themselves
    // Logout should always be allowed without CSRF to prevent users from being stuck
    $shouldSkipCSRF = $isWebhook || $isPortalAuth || $isPortalAuthLogout;
    
    if (in_array($method, ['POST', 'PUT', 'DELETE']) && !$shouldSkipCSRF) {
        // Only verify CSRF if it's not a webhook or portal auth endpoint
        // Portal auth endpoints (including logout) handle their own CSRF or skip it
        // Pass the input data so middleware can check JSON body for CSRF token
        CsrfMiddleware::verify($input);
    }

    // Authentication endpoints
    if (isset($segments[0]) && $segments[0] === 'auth') {
        $controller = new AuthController();
        
        if ($segments[1] === 'login' && $method === 'POST') {
            $result = $controller->login(
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['remember_me'] ?? false
            );
            echo json_encode($result);
            exit;
        }
        
        if ($segments[1] === 'logout' && $method === 'POST') {
            $result = $controller->logout();
            echo json_encode($result);
            exit;
        }
    }

    // Events endpoints
    if (isset($segments[0]) && $segments[0] === 'events') {
        $controller = new EventController();
        
        if (isset($segments[1]) && $segments[1] === 'duplicate' && $method === 'POST') {
            $controller->duplicate($data['event_id'] ?? 0);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'GET') {
            $controller->show($segments[1]);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'PUT') {
            $controller->update($segments[1], $data);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'DELETE') {
            $controller->delete($segments[1]);
        } elseif ((!isset($segments[1]) || $segments[1] === '') && $method === 'GET') {
            // GET /api/events - list all events
            $controller->index($_GET, $_GET['page'] ?? 1);
        } elseif ((!isset($segments[1]) || $segments[1] === '') && $method === 'POST') {
            // POST /api/events - create new event
            $controller->create($data);
        }
    }

    // Members endpoints
    if (isset($segments[0]) && $segments[0] === 'members') {
        // Check for relationships route FIRST before numeric ID check
        // This prevents /members/128/relationships from being caught by the show() route
        if (isset($segments[1]) && is_numeric($segments[1]) && isset($segments[2]) && $segments[2] === 'relationships') {
            // Pass segments to relationships.php (it will use them if available)
            $GLOBALS['api_segments'] = $segments;
            require_once __DIR__ . '/relationships.php';
            exit;
        }
        
        $controller = new MemberController();
        
        // Check import route first (before empty check)
        if (isset($segments[1]) && $segments[1] === 'import' && $method === 'POST') {
            $controller->import($_FILES['file'] ?? null, $data['mapping'] ?? [], $data['options'] ?? []);
        } elseif (isset($segments[1]) && $segments[1] === 'search' && $method === 'GET') {
            $controller->search($_GET['q'] ?? '');
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'GET') {
            $controller->show($segments[1]);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'PUT') {
            $controller->update($segments[1], $data);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'DELETE') {
            $controller->delete($segments[1]);
        } elseif ((!isset($segments[1]) || $segments[1] === '') && $method === 'GET') {
            // GET /api/members - list all members
            $controller->index($_GET, $_GET['page'] ?? 1);
        } elseif ((!isset($segments[1]) || $segments[1] === '') && $method === 'POST') {
            // POST /api/members - create new member
            $controller->create($data);
        }
    }

    // Relationships endpoints (direct routes like /api/relationships/{id})
    // Note: /api/members/{id}/relationships is handled in the members section above
    if (isset($segments[0]) && $segments[0] === 'relationships') {
        require_once __DIR__ . '/relationships.php';
        exit;
    }

    // Attendance endpoints
    if (isset($segments[0]) && $segments[0] === 'attendance') {
        $controller = new AttendanceController();
        
        if ($segments[1] === 'search' && $method === 'POST') {
            $controller->search($data['event_id'] ?? 0, $data['query'] ?? '');
        } elseif ($segments[1] === 'checkin' && $method === 'POST') {
            $controller->checkIn($data['event_id'] ?? 0, $data['user_id'] ?? 0);
        } elseif ($segments[1] === 'bulk-checkin' && $method === 'POST') {
            $controller->bulkCheckIn($data['event_id'] ?? 0, $data['user_ids'] ?? []);
        } elseif ($segments[1] === 'undo' && $method === 'POST') {
            $controller->undoCheckIn($data['event_id'] ?? 0, $data['user_id'] ?? 0);
        } elseif (is_numeric($segments[1]) && $method === 'GET') {
            $controller->getEventAttendance($segments[1]);
        }
    }

    // Categories endpoints
    if (isset($segments[0]) && $segments[0] === 'categories') {
        $controller = new CategoryController();
        
        if (isset($segments[1]) && is_numeric($segments[1]) && $method === 'GET') {
            $controller->show($segments[1]);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'PUT') {
            $controller->update($segments[1], $data);
        } elseif (isset($segments[1]) && is_numeric($segments[1]) && $method === 'DELETE') {
            $controller->delete($segments[1]);
        } elseif ((!isset($segments[1]) || $segments[1] === '') && $method === 'GET') {
            // GET /api/categories - list all categories
            $controller->index();
        } elseif ((!isset($segments[1]) || $segments[1] === '') && $method === 'POST') {
            // POST /api/categories - create new category
            $controller->create($data);
        }
    }

    // Portal API routes
    if (isset($segments[0]) && $segments[0] === 'portal') {
        // Remove 'portal' from segments
        array_shift($segments);
        // Re-index array after shift
        $segments = array_values($segments);
        $portalAction = $segments[0] ?? '';
        // Normalize: strip trailing .php so /api/portal/guest-rsvp.php still finds guest-rsvp.php
        if (substr($portalAction, -4) === '.php') {
            $portalAction = substr($portalAction, 0, -4);
        }
        
        // Handle hyphenated endpoints (e.g., qr-code)
        // Try exact match first
        $portalApiFile = PUBLIC_PATH . '/api/portal/' . $portalAction . '.php';
        
        if (file_exists($portalApiFile)) {
            // Pass remaining segments to the portal API file via global variable
            // This allows auth.php to know that /api/portal/auth/login means action='login'
            $GLOBALS['portal_api_segments'] = $segments;
            require $portalApiFile;
            exit;
        }
        
        // If not found and action contains hyphen, try with underscore
        if (strpos($portalAction, '-') !== false) {
            $portalActionUnderscore = str_replace('-', '_', $portalAction);
            $portalApiFile = PUBLIC_PATH . '/api/portal/' . $portalActionUnderscore . '.php';
            if (file_exists($portalApiFile)) {
                $GLOBALS['portal_api_segments'] = $segments;
                require $portalApiFile;
                exit;
            }
        }
        
        // 404 for portal API
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Portal API endpoint not found: ' . $portalAction,
            'data' => null,
            'errors' => []
        ]);
        exit;
    }

    // CSRF token endpoint
    if (isset($segments[0]) && $segments[0] === 'csrf-token' && $method === 'GET') {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $token = Security::generateCSRFToken();
        echo json_encode([
            'success' => true,
            'token' => $token
        ]);
        exit;
    }

    // 404 Not Found
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Endpoint not found',
        'data' => null,
        'errors' => []
    ]);

} catch (\Throwable $e) {
    $code = $e->getCode();
    if (!is_int($code) || $code < 400) {
        $code = 500;
    }
    http_response_code($code);
    error_log('API error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage() ?: 'An error occurred. Please try again.',
        'data' => null,
        'errors' => []
    ]);
}
