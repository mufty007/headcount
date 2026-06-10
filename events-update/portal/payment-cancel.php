<?php

/**
 * Payment Cancellation Page
 * Shown when user cancels Stripe checkout
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Require authentication
PortalAuthMiddleware::requireAuth();

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

// Get event ID
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';

$event = null;
if ($eventId) {
    $db = Database::getInstance();
    $event = $db->queryOne(
        "SELECT * FROM events WHERE id = :id",
        ['id' => $eventId]
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled - Member Portal</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="bg-gray-50 dark:bg-slate-900">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full">
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg p-8 text-center">
                <div class="mb-4">
                    <svg class="mx-auto h-16 w-16 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Payment Cancelled</h1>
                <p class="text-gray-600 dark:text-gray-300 mb-6">Your payment was cancelled. No charges were made.</p>
                
                <div class="space-y-3">
                    <?php if ($event): ?>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/event-details.php?id=<?php echo $event['id']; ?>" 
                       class="block w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Try Again
                    </a>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php" 
                       class="block w-full px-4 py-2 bg-gray-200 text-gray-800 dark:text-gray-100 rounded-lg hover:bg-gray-300">
                        Browse Events
                    </a>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/dashboard.php" 
                       class="block w-full px-4 py-2 text-blue-600 dark:text-blue-300 hover:text-blue-700">
                        Go to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
