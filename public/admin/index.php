<?php

/**
 * Admin Dashboard Router
 */

// Serve admin JS before DB/bootstrap (otherwise missing handler loads dashboard HTML as JS).
$rawPageEarly = $_GET['page'] ?? '';
if ($rawPageEarly === 'admin-js' || (is_string($rawPageEarly) && str_starts_with($rawPageEarly, 'admin-js'))) {
    if (!isset($_GET['f']) && is_string($rawPageEarly) && preg_match('/\?f=([^&]+)/', $rawPageEarly, $jsFm)) {
        $_GET['f'] = rawurldecode($jsFm[1]);
    }
    // Discard any output buffered by public/index.php (e.g. ob_gzhandler) so no
    // HTML leaks in front of the JS response, which would cause a syntax error.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $adminJsHandler = __DIR__ . '/admin-js.php';
    if (is_file($adminJsHandler)) {
        require $adminJsHandler;
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'admin-js handler missing';
    exit;
}

// Serve Quill assets before DB/bootstrap (same reason as admin-js).
if ($rawPageEarly === 'quill-asset' || (is_string($rawPageEarly) && str_starts_with($rawPageEarly, 'quill-asset'))) {
    if (!isset($_GET['f']) && is_string($rawPageEarly) && preg_match('/\?f=([^&]+)/', $rawPageEarly, $quillFm)) {
        $_GET['f'] = rawurldecode($quillFm[1]);
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $quillHandler = __DIR__ . '/quill-asset.php';
    if (is_file($quillHandler)) {
        require $quillHandler;
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'quill-asset handler missing';
    exit;
}

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
headcount_try_serve_admin_js_bundle();

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;
use Headcount\Controllers\AuthController;

// Initialize system if not already done
if (!defined('BASE_PATH')) {
    define('BASE_PATH', HC_PROJECT_ROOT);
}
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', dirname(__DIR__));
}
if (!defined('SRC_PATH')) {
    define('SRC_PATH', BASE_PATH . '/src');
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}

// Load configuration and initialize database
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
        
        // Set Permissions-Policy for checkin pages BEFORE security headers
        // This ensures camera access is allowed for QR scanning
        $rawPageParam = $_GET['page'] ?? 'dashboard';
        $page = (is_string($rawPageParam) && $rawPageParam !== '' && preg_match('/^[a-zA-Z0-9_-]+$/', $rawPageParam))
            ? $rawPageParam
            : 'dashboard';
        // Recover when rewrite merges query wrong: ?page=quill-asset?f=quill.snow.css
        if (is_string($rawPageParam) && str_starts_with($rawPageParam, 'admin-js')) {
            $page = 'admin-js';
            if (!isset($_GET['f']) && preg_match('/\?f=([^&]+)/', $rawPageParam, $jsFm)) {
                $_GET['f'] = rawurldecode($jsFm[1]);
            }
        }
        if (is_string($rawPageParam) && str_starts_with($rawPageParam, 'quill-asset')) {
            $page = 'quill-asset';
            if (!isset($_GET['f']) && preg_match('/\?f=([^&]+)/', $rawPageParam, $quillFm)) {
                $_GET['f'] = rawurldecode($quillFm[1]);
            }
        }
        $quillQs = $_SERVER['QUERY_STRING'] ?? '';
        if ($page !== 'quill-asset' && $quillQs !== '' && preg_match('/(?:^|&)page=quill-asset\?f=([^&]+)/', $quillQs, $quillQm)) {
            $page = 'quill-asset';
            $_GET['f'] = rawurldecode($quillQm[1]);
        }
        if ($page === 'checkin' && !headers_sent()) {
            header('Permissions-Policy: camera=self, microphone=self, geolocation=()', true);
        }
        
        // Set security headers (will detect existing Permissions-Policy and not override it)
        Security::setSecurityHeaders();
        
        // Initialize database (singleton pattern - will only initialize once)
        Database::getInstance($config['database']);
    } catch (\Exception $e) {
        error_log("Admin initialization error: " . $e->getMessage());
        $detail = $e->getMessage();
        if (!empty($config['app']['debug'])) {
            $prev = $e->getPrevious();
            if ($prev instanceof \Throwable) {
                $detail .= ' — ' . $prev->getMessage();
            }
        }
        die("System initialization failed. Please check your configuration. Error: " . htmlspecialchars($detail));
    }
} else {
    // Redirect to installation if config doesn't exist
    $baseUrl = dirname($_SERVER['SCRIPT_NAME']);
    if ($baseUrl === '/' || $baseUrl === '\\') {
        $baseUrl = '';
    }
    header('Location: ' . $baseUrl . '/install/');
    exit;
}

// Calculate base path for URLs
$requestUri = $_SERVER['REQUEST_URI'] ?? '/admin/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';

// CRITICAL: Check for static files FIRST, before calculating basePath
// This prevents CSS/JS from being served as HTML
if (preg_match('#\.(css|js|jpg|jpeg|png|gif|svg|ico|woff|woff2|ttf|eot)$#i', $requestPath)) {
    // Handle paths like /headcount/public/css/modern-design.css
    $filePath = $requestPath;
    
    // Try multiple path variations to find the file
    $publicPath = dirname(__DIR__); // public directory
    $possiblePaths = [
        $publicPath . $filePath, // Full path as-is
        $publicPath . str_replace('/headcount', '', $filePath), // Without basePath
        $publicPath . preg_replace('#^/[^/]+#', '', $filePath), // Remove first segment
    ];
    
    // If path contains /public/, use it directly
    if (strpos($filePath, '/public/') !== false) {
        $relativePath = substr($filePath, strpos($filePath, '/public/') + 7);
        $possiblePaths[] = $publicPath . '/' . $relativePath;
    }

    // /admin/vendor/... when document root is the public/ folder
    if (preg_match('#^/admin/#', $filePath)) {
        $possiblePaths[] = $publicPath . $filePath;
    }

    // /{base}/admin/js/*.js → public/admin/js/*.js (event wizard bundles)
    if (preg_match('#/admin/js/([^/]+\.js)$#i', $filePath, $adminJsM)) {
        $possiblePaths[] = __DIR__ . '/js/' . $adminJsM[1];
    }
    
    foreach ($possiblePaths as $testPath) {
        if (file_exists($testPath) && is_file($testPath)) {
            // Set appropriate content type
            $ext = strtolower(pathinfo($testPath, PATHINFO_EXTENSION));
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
            
            readfile($testPath);
            exit;
        }
    }
}

// Try to determine base path from script name first (more reliable)
if (strpos($scriptName, '/headcount/') !== false) {
    // Extract base path from script name
    $basePath = preg_replace('#/admin/.*$#', '', $scriptName);
    $basePath = rtrim($basePath, '/');
} else {
    // Fallback: extract from request URI
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}

// If base path is empty or just '/', check if we're in a subdirectory
if (empty($basePath) || $basePath === '/') {
    // Check if DOCUMENT_ROOT suggests we're in a subdirectory
    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
    
    // If script is in a subdirectory relative to document root
    if (strpos($scriptDir, $docRoot) === 0) {
        $relativePath = str_replace($docRoot, '', dirname($scriptDir));
        if (!empty($relativePath) && $relativePath !== '/') {
            $basePath = str_replace('\\', '/', $relativePath);
            $basePath = rtrim($basePath, '/');
        }
    }
}

$adminBase = $basePath . '/admin';

// $page was sanitized inside the config block (needed early for check-in Permissions-Policy)

// Handle logout before authentication check
if ($page === 'logout') {
    try {
        $authController = new AuthController();
        $authController->logout();
    } catch (\Exception $e) {
        // Log error but continue with logout process
        error_log("Logout error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        // Clear session anyway
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        session_destroy();
        // Clear remember token cookie
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', '', true, true);
            setcookie('remember_token', '', time() - 3600, '/admin/', '', true, true);
            unset($_COOKIE['remember_token']);
        }
    }
    
    // Calculate base path for redirect
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    
    // Redirect to login page with logged_out parameter to prevent redirect loop
    Utilities::redirect($basePath . '/admin/?page=login&logged_out=1');
    exit;
}

// Check authentication for all pages except login, forgot-password, reset-password, quill assets
if ($page !== 'login' && $page !== 'forgot-password' && $page !== 'reset-password' && $page !== 'quill-asset' && $page !== 'admin-js') {
    AuthMiddleware::requireAdminOrCoordinator();
}

// Event wizards use the full main column width
if (in_array($page, ['event-create', 'event-edit'], true)) {
    $adminMainFullWidth = true;
}

// Route to appropriate page (must match an existing admin PHP file)
$pageFile = __DIR__ . '/' . $page . '.php';

if (file_exists($pageFile)) {
    try {
        require $pageFile;
    } catch (\Throwable $e) {
        error_log("Admin page error ({$page}): " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        $dash = htmlspecialchars($adminBase . '/?page=dashboard', ENT_QUOTES, 'UTF-8');
        $ev = htmlspecialchars($adminBase . '/?page=events', ENT_QUOTES, 'UTF-8');
        $login = htmlspecialchars($adminBase . '/?page=login', ENT_QUOTES, 'UTF-8');
        $msg = htmlspecialchars('Error: ' . $e->getMessage(), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en" class="h-full"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>Admin page error</title>';
        echo '<script>(function(){var K="headcount-admin-theme";var t=null;try{t=localStorage.getItem(K);}catch(e){}';
        echo 'var d=t==="dark"||(t!=="light"&&typeof matchMedia!=="undefined"&&matchMedia("(prefers-color-scheme:dark)").matches);';
        echo 'document.documentElement.classList.toggle("dark",!!d);})();</script>';
        echo '<style>';
        echo ':root{--bg:#f8fafc;--card:#fff;--bd:#e2e8f0;--tx:#0f172a;--muted:#64748b;--pre:#f1f5f9;}';
        echo 'html.dark{--bg:#0f172a;--card:#1e293b;--bd:#334155;--tx:#f1f5f9;--muted:#94a3b8;--pre:#0f172a;}';
        echo 'body{margin:0;min-height:100%;font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--tx);padding:1.5rem;line-height:1.5;}';
        echo 'main{max-width:42rem;margin:0 auto;}h1{font-size:1.25rem;margin:0 0 .5rem}a{color:#4f46e5;}html.dark a{color:#a5b4fc;}';
        echo 'p{color:var(--muted);margin:0 0 1rem}pre{white-space:pre-wrap;background:var(--pre);border:1px solid var(--bd);padding:1rem;border-radius:.5rem;font-size:.8125rem;overflow:auto}';
        echo '.links{display:flex;flex-wrap:wrap;gap:.75rem 1rem;margin-top:1.25rem}';
        echo '</style></head><body><main>';
        echo '<h1>Admin page failed to load</h1>';
        echo '<p>Please try again or open another admin page.</p>';
        echo '<pre role="status">' . $msg . '</pre>';
        echo '<nav class="links" aria-label="Quick links">';
        echo '<a href="' . $dash . '">Go to Dashboard</a><a href="' . $ev . '">Go to Events</a><a href="' . $login . '">Go to Login</a>';
        echo '</nav></main></body></html>';
    }
} else {
    if (in_array($page, ['admin-js', 'quill-asset'], true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Asset handler missing on server';
        exit;
    }
    // Unknown or missing route file — load dashboard instead of a blank 404 for bad links
    if (file_exists(__DIR__ . '/dashboard.php')) {
        require __DIR__ . '/dashboard.php';
    } else {
        http_response_code(404);
        echo "Page not found";
    }
}
