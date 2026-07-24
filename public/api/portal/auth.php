<?php

/**
 * Portal Authentication API
 * Handles member authentication endpoints
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Controllers\PortalAuthController;
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

// Set JSON header FIRST to prevent any redirects
// This MUST be set before any output or redirects
header('Content-Type: application/json');

// Prevent any redirects - API endpoints should never redirect
// If authentication is needed, return JSON error instead
if (headers_sent()) {
    error_log("Portal Auth API - WARNING: Headers already sent!");
}

// Prevent any output buffering issues
if (ob_get_level()) {
    ob_clean();
}

// Ensure we never redirect from API endpoints
if (function_exists('header_remove')) {
    // Remove any Location header that might have been set
    header_remove('Location');
}

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Log the raw request info
error_log("Portal Auth API - Raw Request: Method=$method, URI=$requestUri, Path=$path, Script=" . ($_SERVER['SCRIPT_NAME'] ?? 'unknown'));

// Extract action from path
// First, try to use segments passed from the router (more reliable)
$action = '';
if (isset($GLOBALS['portal_api_segments']) && is_array($GLOBALS['portal_api_segments'])) {
    error_log("Portal Auth API - Using router segments: " . print_r($GLOBALS['portal_api_segments'], true));
    // Segments will be ['auth', 'login'] for /api/portal/auth/login
    // We want the segment after 'auth', which is index 1
    if (isset($GLOBALS['portal_api_segments'][1])) {
        $action = $GLOBALS['portal_api_segments'][1];
        error_log("Portal Auth API - Extracted action from segment[1]: $action");
    } elseif (isset($GLOBALS['portal_api_segments'][0]) && $GLOBALS['portal_api_segments'][0] !== 'auth') {
        // If first segment is not 'auth', use it as action (for direct access)
        $action = $GLOBALS['portal_api_segments'][0];
        error_log("Portal Auth API - Using segment[0] as action: $action");
    } else {
        error_log("Portal Auth API - Could not extract action from router segments");
    }
} else {
    error_log("Portal Auth API - No router segments found in GLOBALS");
}

// Fallback: parse REQUEST_URI if segments not available
if (empty($action)) {
    $pathSegments = explode('/', trim($path, '/'));
    
    // Find 'auth' in the path and get the next segment
    $authIndex = array_search('auth', $pathSegments);
    if ($authIndex !== false && isset($pathSegments[$authIndex + 1])) {
        $action = $pathSegments[$authIndex + 1];
        error_log("Portal Auth API - Extracted action from path segments: $action");
    } else {
        // Fallback: get last segment
        $action = $pathSegments[count($pathSegments) - 1] ?? '';
        if (!empty($action)) {
            error_log("Portal Auth API - Using last segment as action: $action");
        }
    }
}

// If action is still empty, try to get it from query string
if (empty($action)) {
    $action = $_GET['action'] ?? '';
    if (!empty($action)) {
        error_log("Portal Auth API - Using query string action: $action");
    }
}

// Special case: if action is still empty but this is a POST to /api/portal/auth/logout
// This handles cases where routing might not set segments correctly
if (empty($action) && $method === 'POST') {
    // Check if the path ends with 'logout' or contains 'logout'
    if (stripos($path, 'logout') !== false || stripos($requestUri, 'logout') !== false) {
        $action = 'logout';
        error_log("Portal Auth API - Detected logout from path/URI");
    }
}

// Fallback: if POST and path or URI contains magic-link, treat as magic-link (handles routing quirks)
if (empty($action) && $method === 'POST' && (stripos($path, 'magic-link') !== false || stripos($requestUri, 'magic-link') !== false)) {
    $action = 'magic-link';
    error_log("Portal Auth API - Detected magic-link from path/URI fallback");
}

// If action is still empty, return error
if (empty($action)) {
    error_log("Portal Auth API - Could not determine action from path: $path, URI: $requestUri, Segments: " . print_r($GLOBALS['portal_api_segments'] ?? [], true));
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request: action not specified']);
    exit;
}

// Log for debugging (remove in production)
error_log("Portal Auth API - Path: $path, Action: $action, Method: $method, Router Segments: " . print_r($GLOBALS['portal_api_segments'] ?? [], true));

// Get input data
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$data = array_merge($_POST, $input);

$controller = new PortalAuthController();

try {
    // POST /api/portal/auth/magic-link - Request magic link
    if ($action === 'magic-link' && $method === 'POST') {
        $email = trim($data['email'] ?? '');
        $organizationId = $data['organization_id'] ?? null;
        if (empty($email)) {
            echo json_encode(['success' => false, 'message' => 'Email is required']);
            exit;
        }
        try {
            $result = $controller->sendMagicLink($email, $organizationId);
            echo json_encode($result);
        } catch (\Throwable $e) {
            error_log("Portal Auth API - magic-link error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            echo json_encode(['success' => false, 'message' => 'Unable to send magic link. Please try again.']);
        }
        exit;
    }

    // GET /api/portal/auth/verify?token=XXX - Verify magic link
    if ($action === 'verify' && $method === 'GET') {
        $token = $_GET['token'] ?? '';
        
        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Token is required']);
            exit;
        }
        
        $result = $controller->verifyMagicLink($token);
        echo json_encode($result);
        exit;
    }

    // POST /api/portal/auth/register - Member registration
    if ($action === 'register' && $method === 'POST') {
        // Verify CSRF
        CsrfMiddleware::verify();
        
        $result = $controller->register($data);
        echo json_encode($result);
        exit;
    }

    // GET /api/portal/auth/verify-email?token=XXX - Confirm registration email
    if ($action === 'verify-email' && $method === 'GET') {
        $token = $_GET['token'] ?? '';
        if (empty($token)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Token is required']);
            exit;
        }
        $result = $controller->verifyEmail($token);
        echo json_encode($result);
        exit;
    }

    // POST /api/portal/auth/resend-verification - Resend verification email
    if ($action === 'resend-verification' && $method === 'POST') {
        CsrfMiddleware::verify();
        $email = trim($data['email'] ?? '');
        $result = $controller->resendVerification($email);
        echo json_encode($result);
        exit;
    }

    // POST /api/portal/auth/login - Password login
    if ($action === 'login' && $method === 'POST') {
        error_log("Portal Auth API - Processing login request");
        
        // Verify CSRF (but don't fail if token is missing - let controller handle it)
        try {
            CsrfMiddleware::verify();
        } catch (\Exception $e) {
            error_log("Portal Auth API - CSRF verification failed: " . $e->getMessage());
            // Continue anyway - some endpoints might not require CSRF
        }
        
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $rememberMe = isset($data['remember_me']) && $data['remember_me'];
        
        error_log("Portal Auth API - Calling login with email: " . (empty($email) ? 'empty' : substr($email, 0, 5) . '...'));
        
        $result = $controller->login($email, $password, $rememberMe);
        
        error_log("Portal Auth API - Login result: " . ($result['success'] ? 'success' : 'failed') . " - " . ($result['message'] ?? 'no message'));
        
        echo json_encode($result);
        exit;
    }

    // POST /api/portal/auth/logout - Logout
    // Also handle GET requests for logout (fallback)
    if ($action === 'logout' && ($method === 'POST' || $method === 'GET')) {
        // Verify CSRF if token is provided, but don't fail if missing
        // Logout should work even without CSRF to prevent users from being stuck
        try {
            CsrfMiddleware::verify();
        } catch (\Exception $e) {
            error_log("Portal Auth API - CSRF verification failed for logout, but continuing: " . $e->getMessage());
            // Continue with logout anyway - it's safer to allow logout
        }
        
        try {
            $result = $controller->logout();
            echo json_encode($result);
        } catch (\Exception $e) {
            // Log error but still return success to allow logout
            error_log("Portal logout error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            
            // Clear session anyway
            if (session_status() === PHP_SESSION_NONE) {
                Security::configureSession();
                session_start();
            }
            $_SESSION = [];
            
            // Clear remember token cookie
            if (isset($_COOKIE['portal_remember_token'])) {
                $paths = ['/', '/portal/'];
                $domains = ['', null];
                $host = $_SERVER['HTTP_HOST'] ?? '';
                if (!empty($host)) {
                    $domains[] = $host;
                    $domains[] = '.' . $host;
                }
                foreach ($paths as $path) {
                    foreach ($domains as $domain) {
                        setcookie('portal_remember_token', '', time() - 3600, $path, $domain, true, true);
                        setcookie('portal_remember_token', '', time() - 3600, $path, $domain, false, true);
                    }
                }
                unset($_COOKIE['portal_remember_token']);
            }
            
            // Clear session cookie
            $sessionName = session_name();
            if (isset($_COOKIE[$sessionName])) {
                $params = session_get_cookie_params();
                setcookie($sessionName, '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
                setcookie($sessionName, '', time() - 3600, '/', $params['domain'], $params['secure'], $params['httponly']);
                unset($_COOKIE[$sessionName]);
            }
            
            session_destroy();
            
            echo json_encode([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        }
        exit;
    }

    // 404 - Action not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    $errorMessage = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    $errorTrace = $e->getTraceAsString();
    
    error_log("Portal auth API error: " . $errorMessage . " in " . $errorFile . ":" . $errorLine);
    error_log("Stack trace: " . $errorTrace);
    
    // Only show detailed error in debug mode
    $userMessage = 'An error occurred during authentication. Please try again.';
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        $userMessage = 'An error occurred: ' . $errorMessage . ' in ' . basename($errorFile) . ':' . $errorLine;
    }
    
    echo json_encode([
        'success' => false,
        'message' => $userMessage
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    $errorMessage = $e->getMessage();
    $errorFile = $e->getFile();
    $errorLine = $e->getLine();
    
    error_log("Portal auth API fatal error: " . $errorMessage . " in " . $errorFile . ":" . $errorLine);
    error_log("Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'A fatal error occurred. Please contact support.'
    ]);
}
