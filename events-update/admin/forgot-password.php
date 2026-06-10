<?php

/**
 * Admin Forgot Password Page
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Controllers\AuthController;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}

$configFile = CONFIG_PATH . '/config.php';
if (!file_exists($configFile)) {
    header('Location: /install/');
    exit;
}

$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Database initialization error: " . $e->getMessage());
    die("System initialization failed. Please check your configuration.");
}

Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$cssBase = $basePath . '/public/css/';
$assetsBase = $basePath . '/public/assets/';

$error = '';
$success = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $emailValue = htmlspecialchars($email);

    try {
        $authController = new AuthController();
        $result = $authController->forgotPassword($email);
    } catch (\Exception $e) {
        $error = 'An error occurred. Please try again.';
        error_log("Forgot password error: " . $e->getMessage());
        $result = ['success' => false, 'message' => $error];
    }

    if (isset($result) && $result['success']) {
        $success = $result['message'];
    } else {
        $error = $result['message'] ?? 'An error occurred.';
    }
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Headcount</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="h-full antialiased bg-gray-50 dark:bg-gray-900">
    <?php require __DIR__ . '/includes/auth-theme-controls.php'; ?>
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/logo.svg" alt="Headcount" class="mx-auto mb-4 h-12 w-auto" width="48" height="48">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Reset your password</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Enter your email and we&apos;ll send you a reset link.</p>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-gray-700 dark:bg-gray-800">
                <form method="POST" action="" class="space-y-5">
                    <?php if ($error): ?>
                        <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-300" role="alert">
                            <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800/40 dark:bg-green-900/20 dark:text-green-300" role="status">
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
                            value="<?php echo $emailValue; ?>"
                            placeholder="you@organization.com"
                            required
                            autofocus
                        >
                    </div>

                    <button type="submit" class="btn-primary w-full py-3 text-base font-semibold">
                        Send reset link
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                    <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">&larr; Back to sign in</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>

