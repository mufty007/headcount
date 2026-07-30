<?php

/**
 * Magic Link Verification Page
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Controllers\PortalAuthController;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';
$basePath = $baseUrlPath;
require_once __DIR__ . '/includes/branding.php';

// Get token from query string
$token = $_GET['token'] ?? '';

$error = '';
$success = false;

if (!empty($token)) {
    $controller = new PortalAuthController();
    $result = $controller->verifyMagicLink($token);
    
    if ($result['success']) {
        $success = true;
        // Redirect to dashboard after 2 seconds
        header('Refresh: 2; url=' . $baseUrlPath . '/portal/dashboard.php');
    } else {
        $error = $result['message'] ?? 'Invalid or expired token';
    }
} else {
    $error = 'No token provided';
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Verifying Login - <?= e($APP_NAME) ?></title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="flex min-h-full flex-col bg-gray-50 dark:bg-slate-900">
    <div class="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
                <?php if ($success): ?>
                    <div class="mb-4">
                        <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Login Successful!</h1>
                    <p class="text-gray-600 mb-4">Redirecting to your dashboard...</p>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/dashboard.php" 
                       class="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Go to Dashboard
                    </a>
                <?php else: ?>
                    <div class="mb-4">
                        <svg class="mx-auto h-12 w-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900 mb-2">Verification Failed</h1>
                    <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($error); ?></p>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" 
                       class="inline-block px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Back to Login
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/includes/auth-sw.php'; ?>
</body>
</html>
