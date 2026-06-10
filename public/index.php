<?php

/**
 * Front Controller
 * Routes all requests to appropriate controllers
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1); // Show errors for debugging
ini_set('log_errors', 1);

// Define base paths. Resolve the project root by walking up to vendor/autoload.php
// so this works whether served from public/ or flattened into a host docroot.
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
if (!defined('BASE_PATH')) {
    define('BASE_PATH', HC_PROJECT_ROOT);
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', __DIR__);
}
if (!defined('SRC_PATH')) {
    define('SRC_PATH', BASE_PATH . '/src');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}

// Load Composer autoloader (also loads src/helpers.php once — do not require helpers before this)
require_once BASE_PATH . '/vendor/autoload.php';
headcount_try_serve_admin_js_bundle();

// Enable output compression if available
if (extension_loaded('zlib') && !ob_get_level() && !headers_sent()) {
    ob_start('ob_gzhandler');
}

// Calculate base URL for redirects (needed early)
$baseUrl = dirname($_SERVER['SCRIPT_NAME']);
if ($baseUrl === '/' || $baseUrl === '\\') {
    $baseUrl = '';
}

// Load configuration
$configFile = CONFIG_PATH . '/config.php';
if (!file_exists($configFile)) {
    // Redirect to installation if config doesn't exist
    header('Location: ' . $baseUrl . '/install/');
    exit;
}

$config = require $configFile;

// Initialize database connection
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

try {
    // Configure session BEFORE starting it
    Security::configureSession();
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Set security headers
    Security::setSecurityHeaders();
    
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Initialization error: " . $e->getMessage());
    error_log("Initialization error trace: " . $e->getTraceAsString());
    
    // Clear any output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Send proper HTTP response
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    
    echo "<!DOCTYPE html><html><head><title>500 Error</title></head><body>";
    echo "<h1>500 - Internal Server Error</h1>";
    echo "<p>System initialization failed. Please check your configuration.</p>";
    
    // Show error details if debug mode is enabled
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            echo "<p><strong>Database details:</strong> " . htmlspecialchars($prev->getMessage()) . "</p>";
        }
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    echo "</body></html>";
    exit;
} catch (\Throwable $e) {
    error_log("Initialization fatal error: " . $e->getMessage());
    error_log("Initialization fatal error trace: " . $e->getTraceAsString());
    
    // Clear any output buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    // Send proper HTTP response
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    
    echo "<!DOCTYPE html><html><head><title>500 Error</title></head><body>";
    echo "<h1>500 - Internal Server Error</h1>";
    echo "<p>System initialization failed. Please check your configuration.</p>";
    
    // Try to load config for debug mode
    $configFile = CONFIG_PATH . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (isset($config['app']['debug']) && $config['app']['debug']) {
            echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
            $prevT = $e->getPrevious();
            if ($prevT instanceof \Throwable) {
                echo "<p><strong>Database details:</strong> " . htmlspecialchars($prevT->getMessage()) . "</p>";
            }
            echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        }
    } else {
        // Show error even if config not available
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    echo "</body></html>";
    exit;
}

// Set up shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR])) {
        error_log("Fatal error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        
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

// Simple routing
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove query string
$path = parse_url($requestUri, PHP_URL_PATH);

// Remove base path if needed (calculate first for static file check)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$basePath = dirname($scriptName);
// Normalize base path
if ($basePath === '/' || $basePath === '\\' || $basePath === '.') {
    $basePath = '';
} else {
    $basePath = rtrim($basePath, '/');
}

// Check if this is a request for a static file (CSS, JS, images, etc.)
// This must happen AFTER basePath is calculated
if (preg_match('#\.(css|js|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot)$#i', $path)) {
    // Try to find the file - handle both with and without basePath
    $filePath = null;
    
    // First, try with the full path (if it includes basePath)
    if (!empty($basePath) && strpos($path, $basePath) === 0) {
        $relativePath = substr($path, strlen($basePath));
        if (str_starts_with($relativePath, '/public/')) {
            $filePath = PUBLIC_PATH . substr($relativePath, 7);
        } else {
            $filePath = PUBLIC_PATH . $relativePath;
        }
    } else {
        // Try without basePath
        $filePath = PUBLIC_PATH . $path;
    }

    // Path contains /public/ after an app prefix (e.g. /Headcount/public/admin/js/…)
    if ((!isset($filePath) || !file_exists($filePath)) && preg_match('#/public/(.+)$#', $path, $publicTail)) {
        $filePath = PUBLIC_PATH . '/' . $publicTail[1];
    }
    
    // Also try if path starts with /public/
    if ((!isset($filePath) || !file_exists($filePath)) && strpos($path, '/public/') === 0) {
        $filePath = PUBLIC_PATH . substr($path, 7); // Remove /public/ prefix
    }
    
    // Also try the root uploads directory
    if (!file_exists($filePath) && strpos($path, '/uploads/') === 0) {
        $filePath = BASE_PATH . $path;
    }
    // Also try the root uploads directory without /public/ prefix if requested via base path
    if (!file_exists($filePath) && strpos($path, $basePath . '/uploads/') === 0) {
        $filePath = BASE_PATH . substr($path, strlen($basePath));
    }
    
    if ($filePath && file_exists($filePath) && is_file($filePath)) {
        // Set appropriate content type
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject'
        ];
        
        if (isset($mimeTypes[$ext])) {
            header('Content-Type: ' . $mimeTypes[$ext]);
        }
        
        readfile($filePath);
        exit;
    }
}

// Remove base path from request path
if (!empty($basePath) && strpos($path, $basePath) === 0) {
    $path = substr($path, strlen($basePath));
}

// Also check if path starts with /public/ and remove it
if (strpos($path, '/public/') === 0) {
    $path = substr($path, 7); // Remove '/public' prefix
}

// Remove app root directory name from path if present
// This handles URLs like /Headcount/api/... when the app is in a subdirectory
$appRootName = basename(BASE_PATH);
$routeNames = ['api', 'admin', 'portal', 'install', 'public'];

// Refined Routing Logic
// Only remove the first segment if it's not a known route name
if (preg_match('#^/([^/]+)/#', $path, $matches)) {
    $firstSegment = $matches[1];
    
    // If the first segment is NOT a known route, but the SECOND one is,
    // then the first segment is likely a subdirectory (e.g. /Headcount/api/...)
    if (!in_array($firstSegment, $routeNames)) {
        if (preg_match('#^/[^/]+/(' . implode('|', $routeNames) . ')/#', $path)) {
            $path = preg_replace('#^/[^/]+/#', '/', $path, 1);
        }
    }
    // Otherwise, if it explicitly matches the app root folder name, remove it
    elseif (strpos($path, '/' . $appRootName . '/') === 0) {
        $path = substr($path, strlen('/' . $appRootName));
    }
}

$path = trim($path, '/');
$segments = empty($path) ? [] : explode('/', $path);

// Handle direct file access in public directory (e.g., /public/admin/login.php or /public/login.php)
if (count($segments) > 0 && $segments[0] === 'public') {
    // Remove 'public' segment
    array_shift($segments);
    
    if (count($segments) > 0) {
        // If it's just login.php, redirect to admin login
        if (count($segments) === 1 && $segments[0] === 'login.php') {
            header('Location: ' . $baseUrl . '/admin/?page=login');
            exit;
        }
        
        // Check if it's an admin file
        if ($segments[0] === 'admin') {
            require_once PUBLIC_PATH . '/admin/index.php';
            exit;
        }
        
        // Check if file exists in public directory
        $filePath = PUBLIC_PATH . '/' . implode('/', $segments);
        if (file_exists($filePath) && is_file($filePath)) {
            require $filePath;
            exit;
        }
    }
}

// Debug: Log routing info (remove in production)
if (isset($config['app']['debug']) && $config['app']['debug']) {
    error_log("Routing Debug - Path: " . $path . ", Segments: " . print_r($segments, true) . ", BasePath: " . $basePath);
}

// Route to appropriate handler
if (empty($segments[0]) || $segments[0] === 'index.php' || $segments[0] === '') {
    // Check if this is an events subdomain - route to portal instead of admin
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $isEventsSubdomain = strpos($host, 'events.') === 0 || strpos($host, '.events.') !== false;
    
    if ($isEventsSubdomain) {
        // Events subdomain: redirect to portal events page
        // Use absolute path to avoid redirect loops
        header('Location: /portal/', true, 302);
        exit;
    } else {
        // Default: redirect to admin login
        header('Location: ' . $baseUrl . '/admin/?page=login', true, 302);
        exit;
    }
} elseif ($segments[0] === 'admin') {
    // Admin routes
    // Handle direct .php file access (e.g., /admin/login.php -> /admin/?page=login)
    $adminEarlyPage = $_GET['page'] ?? '';
    $adminAssetRequest = is_string($adminEarlyPage)
        && ($adminEarlyPage === 'admin-js'
            || $adminEarlyPage === 'quill-asset'
            || str_starts_with($adminEarlyPage, 'admin-js')
            || str_starts_with($adminEarlyPage, 'quill-asset'));
    if (count($segments) > 1 && strpos($segments[1], '.php') !== false && !$adminAssetRequest) {
        // /admin/index.php?page=… — load admin router; do not redirect to ?page=index
        if ($segments[1] === 'index.php') {
            require_once PUBLIC_PATH . '/admin/index.php';
            exit;
        }
        $pageName = str_replace('.php', '', $segments[1]);
        // Calculate redirect URL properly
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
        $redirectBase = preg_replace('#/admin/.*$#', '', $requestPath);
        $redirectBase = rtrim($redirectBase, '/');
        if (empty($redirectBase)) {
            $redirectBase = '';
        }
        // Preserve query string if present
        $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
        $redirectUrl = $redirectBase . '/admin/?page=' . urlencode($pageName) . $queryString;
        header('Location: ' . $redirectUrl);
        exit;
    }
    require_once PUBLIC_PATH . '/admin/index.php';
    exit;
} elseif ($segments[0] === 'api') {
    // API routes
    require_once PUBLIC_PATH . '/api/index.php';
    exit;
} elseif ($segments[0] === 'portal') {
    // Member portal routes
    require_once PUBLIC_PATH . '/portal/index.php';
    exit;
} elseif ($segments[0] === 'install') {
    // Installation wizard
    require_once BASE_PATH . '/install/index.php';
    exit;
} else {
    // 404 Not Found - but first check if it might be a direct API file request
    // This handles cases where routing didn't catch it
    
    // Check if segments[0] is 'api' and segments[1] is a PHP file
    if (count($segments) >= 2 && $segments[0] === 'api' && strpos($segments[1] ?? '', '.php') !== false) {
        // Try to load the API file directly
        $apiFile = PUBLIC_PATH . '/api/' . $segments[1];
        if (file_exists($apiFile) && is_file($apiFile)) {
            require $apiFile;
            exit;
        }
    }
    
    // Check if segments[0] is app root name, segments[1] is 'api', and segments[2] is a PHP file
    if (count($segments) >= 3) {
        $appRootName = basename(BASE_PATH);
        if ($segments[0] === $appRootName && $segments[1] === 'api' && strpos($segments[2] ?? '', '.php') !== false) {
            $apiFile = PUBLIC_PATH . '/api/' . $segments[2];
            if (file_exists($apiFile) && is_file($apiFile)) {
                require $apiFile;
                exit;
            }
        }
    }
    
    // Also check if segments[0] might be the app root name and segments[1] is 'api'
    if (count($segments) >= 3 && $segments[0] === basename(BASE_PATH) && $segments[1] === 'api' && strpos($segments[2] ?? '', '.php') !== false) {
        $apiFile = PUBLIC_PATH . '/api/' . $segments[2];
        if (file_exists($apiFile) && is_file($apiFile)) {
            require $apiFile;
            exit;
        }
    }
    
    // 404 Not Found
    http_response_code(404);
    $originalPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    echo "404 - Page Not Found: " . htmlspecialchars($originalPath);
    // Enable debug temporarily to see what's happening
    $debug = true; // Set to false in production
    if ($debug || (isset($config['app']['debug']) && $config['app']['debug'])) {
        echo "<br><strong>Debug Info:</strong>";
        echo "<br>Original Request URI: " . htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A');
        echo "<br>Processed Path: " . htmlspecialchars($path);
        echo "<br>Segments: " . htmlspecialchars(print_r($segments, true));
        echo "<br>Base Path: " . htmlspecialchars($basePath);
        echo "<br>App Root Name: " . htmlspecialchars(basename(BASE_PATH));
        echo "<br>Script Name: " . htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? 'N/A');
    }
}
