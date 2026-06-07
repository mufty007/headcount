<?php

/**
 * Admin Login Page
 */

// Enable error reporting for debugging (REMOVE IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Controllers\AuthController;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;
use Headcount\Helpers\Security;
use Headcount\Helpers\Database;

// Define base paths if not already defined
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}

// Load configuration and initialize database
$configFile = CONFIG_PATH . '/config.php';
if (!file_exists($configFile)) {
    // Redirect to installation if config doesn't exist
    header('Location: /install/');
    exit;
}

$config = require $configFile;

// Initialize database connection
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Database initialization error: " . $e->getMessage());
    $detail = $e->getMessage();
    if (!empty($config['app']['debug'])) {
        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            $detail .= ' — ' . $prev->getMessage();
        }
    }
    die("System initialization failed. Please check your configuration. Error: " . htmlspecialchars($detail));
}

// Initialize session
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in (but not if we just logged out)
if (!isset($_GET['logged_out']) && AuthMiddleware::getUserId()) {
    // Calculate base path for redirect
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    
    // Start session to check role
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $userRole = $_SESSION['role'] ?? 'member';
    
    if ($userRole === 'admin' || $userRole === 'coordinator') {
        // Admin and coordinator go to admin area
        Utilities::redirect($basePath . '/admin/?page=dashboard');
    } else {
        // Member users go to portal dashboard
        Utilities::redirect($basePath . '/portal/dashboard.php');
    }
}

$error = '';
$success = '';
$emailValue = '';

// Show success message if user just logged out
if (isset($_GET['logged_out']) && $_GET['logged_out'] == '1') {
    $success = 'You have been successfully logged out.';
}

// Calculate base URL for assets
// When accessed via /headcount/admin/?page=login, we need /headcount/public/css/
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
// Remove /admin/ from path to get base
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
// Assets are in public directory relative to base
$cssBase = $basePath . '/public/css/';
$jsBase = $basePath . '/public/js/';
$assetsBase = $basePath . '/public/assets/';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);
    
    $emailValue = htmlspecialchars($email);

    try {
        // Verify database is initialized
        $db = Database::getInstance();
        if ($db === null) {
            throw new \Exception("Database not initialized. Please check your configuration.");
        }

        $authController = new AuthController();
        $result = $authController->login($email, $password, $rememberMe);
    } catch (\Exception $e) {
        $error = "Login error: " . $e->getMessage();
        error_log("Login error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        $result = ['success' => false, 'message' => $error];
    }

    if (isset($result) && $result['success']) {
        // Calculate base path for redirect
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
        $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
        $basePath = rtrim($basePath, '/');
        
        // Redirect based on user role
        // Start session to check role
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $userRole = $_SESSION['role'] ?? 'member';
        
        if ($userRole === 'admin' || $userRole === 'coordinator') {
            // Admin and coordinator go to admin area
            Utilities::redirect($basePath . '/admin/?page=dashboard');
        } else {
            // Member users go to portal dashboard
            Utilities::redirect($basePath . '/portal/dashboard.php');
        }
    } else {
        $error = $result['message'] ?? 'Invalid email or password';
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Headcount Events</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="flex min-h-full flex-col antialiased">
    <?php require __DIR__ . '/includes/auth-theme-controls.php'; ?>
    <div class="flex flex-1 flex-col justify-center px-4 py-10 sm:px-6">
        <div class="mx-auto w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-card-lg dark:border-slate-700 dark:bg-slate-800 sm:p-10">
            <div class="mb-8 text-center">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/logo.svg" alt="Headcount Events Logo" class="mx-auto mb-4 h-14 w-auto" width="56" height="56">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Headcount</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Event &amp; attendance management</p>
            </div>

            <form method="POST" action="" id="login-form">
                <?php if ($error): ?>
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" aria-live="polite">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status" aria-live="polite">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="mb-4">
                    <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-slate-300" for="email">
                        Email address
                    </label>
                    <input 
                        type="email" 
                        id="email" 
                        name="email" 
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                        value="<?php echo htmlspecialchars($emailValue); ?>"
                        required
                        autofocus
                    >
                </div>
                
                <div class="mb-6">
                    <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-slate-300" for="password">
                        Password
                    </label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                        required
                    >
                    <p class="mt-2 text-sm">
                        <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=forgot-password" class="font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">Forgot password?</a>
                    </p>
                </div>
                
                <button 
                    type="submit" 
                    class="w-full btn-primary "
                >
                    Sign in
                </button>
            </form>
        </div>
    </div>
    <?php require __DIR__ . '/../footer-public.php'; ?>

