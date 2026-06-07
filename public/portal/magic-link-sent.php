<?php

/**
 * Magic Link Sent Confirmation Page
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize session
try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (\Exception $e) {
    // Continue anyway
}

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';

$email = $_GET['email'] ?? '';

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Your Email - Member Portal</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="flex min-h-full flex-col bg-gray-50 dark:bg-slate-900">
    <div class="flex-1 flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-lg p-8 text-center">
                <div class="mb-4">
                    <svg class="mx-auto h-16 w-16 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-gray-900 mb-2">Check Your Email</h1>
                <p class="text-gray-600 mb-4">
                    We've sent a magic link to 
                    <?php if ($email): ?>
                        <strong><?php echo htmlspecialchars($email); ?></strong>
                    <?php else: ?>
                        your email address
                    <?php endif; ?>
                </p>
                <p class="text-sm text-gray-500 mb-6">
                    Click the link in the email to log in. The link will expire in 15 minutes.
                </p>
                <div class="space-y-2">
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" 
                       class="block px-4 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                        Back to Login
                    </a>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php" 
                       class="block px-4 py-2 text-blue-600 hover:text-blue-700">
                        Browse Events
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
