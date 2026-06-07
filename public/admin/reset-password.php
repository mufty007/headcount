<?php

/**
 * Admin Reset Password Page (linked from email)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

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

$token = trim($_GET['token'] ?? '');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = trim($_POST['token'] ?? $token);
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    try {
        $authController = new AuthController();
        $result = $authController->resetPassword($token, $password, $password_confirm);
    } catch (\Exception $e) {
        $error = 'An error occurred. Please try again.';
        error_log("Reset password error: " . $e->getMessage());
        $result = ['success' => false, 'message' => $error];
    }

    if (isset($result) && $result['success']) {
        $success = $result['message'];
        $token = ''; // Clear so form is not resubmitted
    } else {
        $error = $result['message'] ?? 'An error occurred.';
    }
}

$invalidToken = empty($token) && $_SERVER['REQUEST_METHOD'] !== 'POST';

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Headcount Events</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="flex min-h-full flex-col antialiased">
    <?php require __DIR__ . '/includes/auth-theme-controls.php'; ?>
    <div class="flex flex-1 flex-col justify-center px-4 py-10 sm:px-6">
        <div class="mx-auto w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-card-lg dark:border-slate-700 dark:bg-slate-800 sm:p-10">
            <div class="mb-8 text-center">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/logo.svg" alt="Headcount Events Logo" class="mx-auto mb-4 h-14 w-auto" width="56" height="56">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Reset password</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Enter your new password below.</p>
            </div>

            <?php if ($success): ?>
                <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="alert">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <p class="text-center">
                    <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">Go to login</a>
                </p>
            <?php elseif ($invalidToken): ?>
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                    Invalid or missing reset link. Please request a new one from the login page.
                </div>
                <p class="text-center">
                    <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=forgot-password" class="font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">Request new link</a>
                    <span class="text-gray-300 dark:text-slate-600"> | </span>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">Back to login</a>
                </p>
            <?php else: ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <?php if ($error): ?>
                        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="mb-4">
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-slate-300" for="password">New password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                            required
                            minlength="8"
                            autofocus
                        >
                        <p class="mt-1 text-xs text-gray-500 dark:text-slate-400">8+ characters with mixed case and numbers.</p>
                    </div>

                    <div class="mb-6">
                        <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-slate-300" for="password_confirm">Confirm password</label>
                        <input
                            type="password"
                            id="password_confirm"
                            name="password_confirm"
                            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                            required
                            minlength="8"
                        >
                    </div>

                    <button
                        type="submit"
                        class="w-full btn-primary "
                    >
                        Reset password
                    </button>
                </form>

                <p class="mt-6 text-center text-sm text-gray-600 dark:text-slate-400">
                    <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">Back to login</a>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php require __DIR__ . '/../footer-public.php'; ?>
</body>
</html>

