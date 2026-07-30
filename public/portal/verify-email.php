<?php

/**
 * Email verification landing page (from verification link in email)
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Controllers\PortalAuthController;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die('Configuration not found.');
}

$config = require $configFile;

try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die('System initialization failed.');
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';
$basePath = $baseUrlPath;
require_once __DIR__ . '/includes/branding.php';

$token = $_GET['token'] ?? '';
$error = '';
$success = false;

if ($token !== '') {
    $controller = new PortalAuthController();
    $result = $controller->verifyEmail($token);
    if (!empty($result['success'])) {
        $success = true;
    } else {
        $error = $result['message'] ?? 'Invalid or expired verification link';
    }
} else {
    $error = 'No verification token provided';
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Verify Email - <?= e($APP_NAME) ?></title>
    <?php include __DIR__ . '/includes/auth-dark.php'; ?>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
    </style>
</head>
<body class="bg-[#F9FAFB] h-full flex flex-col font-jakarta dark:bg-gray-900">
    <div class="flex-1 flex flex-col items-center justify-center p-6 sm:p-8 lg:p-12 relative z-10">
        <div class="w-full max-w-[480px]">
            <div class="glass-bg rounded-3xl p-8 md:p-10 shadow-2xl shadow-brand-100/50 text-center">
                <?php if ($success): ?>
                    <div class="mb-4">
                        <svg class="mx-auto h-12 w-12 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                    <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white mb-2">Email verified</h1>
                    <p class="text-gray-600 dark:text-gray-300 mb-6">Your account is ready. You can now sign in.</p>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php"
                       class="inline-flex w-full items-center justify-center bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl">
                        Sign In
                    </a>
                <?php else: ?>
                    <div class="mb-4">
                        <svg class="mx-auto h-12 w-12 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </div>
                    <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white mb-2">Verification failed</h1>
                    <p class="text-gray-600 dark:text-gray-300 mb-6"><?php echo htmlspecialchars($error); ?></p>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/verify-email-sent.php"
                       class="inline-flex w-full items-center justify-center bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl mb-3">
                        Resend verification email
                    </a>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php"
                       class="text-sm font-bold text-brand-600 hover:text-brand-700">Back to sign in</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php require __DIR__ . '/includes/auth-sw.php'; ?>
</body>
</html>
