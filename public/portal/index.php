<?php

/**
 * Portal Router
 * Routes requests to appropriate portal pages
 */

// Enable error reporting and display for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display, but log
ini_set('log_errors', 1);

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Security;

// Define base paths
if (!defined('BASE_PATH')) {
    define('BASE_PATH', HC_PROJECT_ROOT);
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', dirname(__DIR__));
}

// Load configuration
$configFile = BASE_PATH . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found. Please run the installation wizard.");
}

$config = require $configFile;

// Initialize database
use Headcount\Helpers\Database;

try {
    Security::configureSession();
    
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    Security::setSecurityHeaders();
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Portal initialization error: " . $e->getMessage());
    error_log("Portal initialization error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo "<h1>500 - Internal Server Error</h1>";
    echo "<p>System initialization failed. Please check your configuration.</p>";
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            echo "<p><strong>Database details:</strong> " . htmlspecialchars($prev->getMessage()) . "</p>";
        }
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    exit;
} catch (\Throwable $e) {
    error_log("Portal initialization fatal error: " . $e->getMessage());
    error_log("Portal initialization fatal error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo "<h1>500 - Internal Server Error</h1>";
    echo "<p>System initialization failed. Please check your configuration.</p>";
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        $prevT = $e->getPrevious();
        if ($prevT instanceof \Throwable) {
            echo "<p><strong>Database details:</strong> " . htmlspecialchars($prevT->getMessage()) . "</p>";
        }
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    exit;
}

// Set up shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        error_log("Portal fatal error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        
        // Clear any output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>500 Error</title></head><body>";
        echo "<h1>500 - Internal Server Error</h1>";
        echo "<p>A fatal error occurred.</p>";
        
        // Try to load config for debug mode
        $configFile = HC_PROJECT_ROOT . '/config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (isset($config['app']['debug']) && $config['app']['debug']) {
                echo "<p><strong>Error:</strong> " . htmlspecialchars($error['message']) . "</p>";
                echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . ":" . $error['line'] . "</p>";
            }
        } else {
            // Show error even if config not available
            echo "<p><strong>Error:</strong> " . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . ":" . $error['line'] . "</p>";
        }
        echo "</body></html>";
        exit;
    }
});

// Get request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);

// Normalize path - handle /portal and /portal/ the same way
// Ensure path ends with /portal/ for consistent processing
if (rtrim($path, '/') === '/portal') {
    $path = '/portal/';
}

// Remove base path if needed
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/portal/index.php';
$basePath = dirname($scriptName);
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
} else {
    $basePath = rtrim($basePath, '/');
}

// Remove base path from request path
if (!empty($basePath) && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// Normalize path - ensure it starts with /
if (empty($path) || ($path[0] !== '/' && $path[0] !== '\\')) {
    $path = '/' . $path;
}

// Extract page from path
$path = trim($path, '/');
$segments = empty($path) ? [] : explode('/', $path);
// Filter out empty segments
$segments = array_filter($segments, function($seg) { return !empty($seg); });
$segments = array_values($segments); // Re-index array

// Remove 'portal' from segments if present
if (!empty($segments[0]) && $segments[0] === 'portal') {
    array_shift($segments);
    // Re-index after shift
    $segments = array_values($segments);
}

// Get page name - default to 'events' if empty
$page = !empty($segments[0]) ? $segments[0] : 'events';

// Remove .php extension if present
$page = str_replace('.php', '', $page);

// Calculate base URL for assets
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
// Normalize - ensure path ends with /portal or /portal/
if ($requestPath === '/portal') {
    $requestPath = '/portal/';
}
// Handle both /portal/ and /portal cases
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');
// Ensure baseUrlPath is not empty - default to root if empty
if (empty($baseUrlPath)) {
    $baseUrlPath = '';
}
$cssBase = $baseUrlPath . '/public/css/';
$jsBase = $baseUrlPath . '/public/js/';
$assetsBase = $baseUrlPath . '/public/assets/';

// Route to appropriate page
$pageFile = __DIR__ . '/' . $page . '.php';

// Special handling for verify (magic link verification)
if ($page === 'verify') {
    require_once __DIR__ . '/verify.php';
    exit;
}

// Check if page requires authentication
$publicPages = [
    'events', 'event-details', 'login', 'register', 'forgot-password', 'reset-password', 'magic-link-sent', 'verify', 'debug_auth',
    'facilities', 'facility-details', 'facility-book-guest', 'facility-booking-success', 'facility-booking-cancel', 'programs', 'program-details',
];
$requiresAuth = !in_array($page, $publicPages);

if ($requiresAuth && !PortalAuthMiddleware::isAuthenticated()) {
    // Redirect to login
    $loginUrl = $baseUrlPath . '/portal/login.php';
    header('Location: ' . $loginUrl);
    exit;
}

// If already logged in and trying to access login/register, redirect to dashboard
if (in_array($page, ['login', 'register']) && PortalAuthMiddleware::isAuthenticated()) {
    $dashboardUrl = $baseUrlPath . '/portal/dashboard.php';
    header('Location: ' . $dashboardUrl);
    exit;
}

// Load page if exists
if (file_exists($pageFile) && is_file($pageFile)) {
    try {
        // Log that we're about to load the page
        error_log("Portal: Loading page '$page' from file '$pageFile'");
        
        // Include the page file
        require $pageFile;
    } catch (\Throwable $e) {
        error_log("Portal page error loading '$pageFile': " . $e->getMessage());
        error_log("Portal page error trace: " . $e->getTraceAsString());
        
        // Clear any output that might have been sent
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>500 Error</title></head><body>";
        echo "<h1>500 - Internal Server Error</h1>";
        echo "<p>An error occurred while loading the page: " . htmlspecialchars($page) . "</p>";
        if (isset($config['app']['debug']) && $config['app']['debug']) {
            echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
            echo "<p><strong>Page File:</strong> " . htmlspecialchars($pageFile) . "</p>";
            echo "<p><strong>Request URI:</strong> " . htmlspecialchars($requestUri) . "</p>";
            echo "<p><strong>Path:</strong> " . htmlspecialchars($path) . "</p>";
            echo "<p><strong>Segments:</strong> " . htmlspecialchars(print_r($segments, true)) . "</p>";
        }
        echo "<p><a href='" . $baseUrlPath . "/portal/events.php'>Go to Events</a></p>";
        echo "</body></html>";
        exit;
    }
} else {
    // 404 - Page not found
    error_log("Portal page not found: '$pageFile' (page: '$page', segments: " . print_r($segments, true) . ")");
    http_response_code(404);
    echo "<h1>404 - Page Not Found</h1>";
    echo "<p>The page you're looking for doesn't exist: " . htmlspecialchars($page) . "</p>";
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo "<p><strong>Page File:</strong> " . htmlspecialchars($pageFile) . "</p>";
        echo "<p><strong>Request URI:</strong> " . htmlspecialchars($requestUri) . "</p>";
        echo "<p><strong>Path:</strong> " . htmlspecialchars($path) . "</p>";
        echo "<p><strong>Segments:</strong> " . htmlspecialchars(print_r($segments, true)) . "</p>";
    }
    echo "<p><a href='" . $baseUrlPath . "/portal/events.php'>Go to Events</a></p>";
}
