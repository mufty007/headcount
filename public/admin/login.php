<?php

/**
 * Admin Login Page
 */

// Enable error reporting for debugging (REMOVE IN PRODUCTION)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Resilient autoloader lookup: walk up the directory tree from here until vendor/autoload.php
// is found, so the app loads whether this file sits at public/admin/ or has been flattened
// into a host's docroot (e.g. .../events/admin/). No fixed folder depth assumed.
$hcProjectRoot = null;
$hcSearchDir = __DIR__;
for ($hcDepth = 0; $hcDepth < 7; $hcDepth++) {
    if (is_file($hcSearchDir . '/vendor/autoload.php')) { $hcProjectRoot = $hcSearchDir; break; }
    $hcParentDir = dirname($hcSearchDir);
    if ($hcParentDir === $hcSearchDir) { break; }
    $hcSearchDir = $hcParentDir;
}
if ($hcProjectRoot === null) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die("Headcount could not find vendor/autoload.php. Upload the 'vendor' folder into the app directory (or a parent folder of it).");
}
require_once $hcProjectRoot . '/vendor/autoload.php';

use Headcount\Controllers\AuthController;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;
use Headcount\Helpers\Security;
use Headcount\Helpers\Database;

// Define base paths if not already defined (anchored to the located project root).
if (!defined('BASE_PATH')) {
    define('BASE_PATH', $hcProjectRoot);
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', $hcProjectRoot . '/config');
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
    <title>Sign In — IMCA</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="h-full antialiased bg-gray-50 dark:bg-gray-900">
    <?php require __DIR__ . '/includes/auth-theme-controls.php'; ?>

    <div class="flex min-h-screen">
        <!-- Left branding panel -->
        <div class="hidden lg:flex lg:w-1/2 xl:w-3/5 relative flex-col justify-between bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 p-12 overflow-hidden">
            <!-- Decorative blobs -->
            <div class="absolute inset-0 overflow-hidden" aria-hidden="true">
                <div class="absolute -top-24 -left-24 w-96 h-96 rounded-full bg-white opacity-10 dark:bg-gray-800"></div>
                <div class="absolute top-1/3 -right-32 w-80 h-80 rounded-full bg-white opacity-10 dark:bg-gray-800"></div>
                <div class="absolute -bottom-20 left-1/4 w-64 h-64 rounded-full bg-white opacity-10 dark:bg-gray-800"></div>
            </div>

            <!-- Logo -->
            <div class="relative z-10 flex items-center gap-3">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/imca-logo-white.png" alt="IMCA" class="h-12 w-12 rounded-xl object-contain bg-black" width="48" height="48">
                <span class="text-xl font-bold text-white tracking-tight">IMCA</span>
            </div>

            <!-- Headline -->
            <div class="relative z-10">
                <h2 class="text-4xl font-bold text-white leading-tight mb-4">
                    Effortless event &amp;<br>attendance management
                </h2>
                <p class="text-brand-100 text-lg max-w-md">
                    Track RSVPs, manage check-ins, and understand your audience — all in one place.
                </p>
                <div class="mt-8 flex flex-wrap gap-3">
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-medium text-white backdrop-blur-sm dark:bg-gray-800">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                        QR Check-In
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-medium text-white backdrop-blur-sm dark:bg-gray-800">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                        RSVP Tracking
                    </span>
                    <span class="inline-flex items-center gap-2 rounded-full bg-white/15 px-4 py-2 text-sm font-medium text-white backdrop-blur-sm dark:bg-gray-800">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                        Member Management
                    </span>
                </div>
            </div>

            <p class="relative z-10 text-sm text-brand-200">&copy; <?= date('Y') ?> IMCA. All rights reserved.</p>
        </div>

        <!-- Right form panel -->
        <div class="flex w-full lg:w-1/2 xl:w-2/5 flex-col justify-center px-6 py-12 sm:px-12 lg:px-16 xl:px-24">
            <!-- Mobile logo -->
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/imca-logo.png" alt="IMCA" class="h-10 w-10 rounded-xl object-contain bg-black" width="40" height="40">
                <span class="text-lg font-bold text-gray-900 dark:text-white tracking-tight">IMCA</span>
            </div>

            <div class="mb-8">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Sign in to your account</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Enter your credentials to access the dashboard</p>
            </div>

            <form method="POST" action="" id="login-form" class="space-y-5">
                <?php if ($error): ?>
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-300" role="alert" aria-live="polite">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800/40 dark:bg-green-900/20 dark:text-green-300" role="status" aria-live="polite">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="ta-label mb-1.5" for="email">Email address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="ta-input w-full"
                        value="<?php echo htmlspecialchars($emailValue); ?>"
                        placeholder="you@organization.com"
                        required
                        autofocus
                    >
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="ta-label" for="password">Password</label>
                        <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=forgot-password" class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">Forgot password?</a>
                    </div>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="ta-input w-full"
                        placeholder="••••••••"
                        required
                    >
                </div>

                <div class="flex items-center gap-2.5">
                    <input type="checkbox" id="remember_me" name="remember_me" class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700">
                    <label for="remember_me" class="text-sm text-gray-600 dark:text-gray-400">Keep me signed in for 30 days</label>
                </div>

                <button type="submit" class="btn-primary w-full py-3 text-base font-semibold">
                    Sign in
                </button>
            </form>
        </div>
    </div>
</body>
</html>

