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
    <title>Reset Password — Headcount</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="h-full antialiased bg-gray-50 dark:bg-gray-900">
    <?php require __DIR__ . '/includes/auth-theme-controls.php'; ?>
    <div class="flex min-h-screen items-center justify-center px-4 py-12">
        <div class="w-full max-w-md">
            <div class="mb-8 text-center">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/logo.svg" alt="Headcount" class="mx-auto mb-4 h-12 w-auto" width="48" height="48">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white">Set new password</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Enter your new password below.</p>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-gray-700 dark:bg-gray-800">
                <?php if ($success): ?>
                    <div class="flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800 dark:border-green-800/40 dark:bg-green-900/20 dark:text-green-300 mb-5" role="status">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="btn-primary w-full py-3 text-base font-semibold text-center block">Go to sign in</a>
                <?php elseif ($invalidToken): ?>
                    <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-300 mb-5" role="alert">
                        <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                        Invalid or expired reset link. Please request a new one.
                    </div>
                    <div class="flex gap-3">
                        <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=forgot-password" class="btn-primary flex-1 text-center">Request new link</a>
                        <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="btn-secondary flex-1 text-center">Sign in</a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="" class="space-y-5">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <?php if ($error): ?>
                            <div class="flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800/40 dark:bg-red-900/20 dark:text-red-300" role="alert">
                                <svg class="mt-0.5 h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <label class="ta-label mb-1.5" for="password">New password</label>
                            <input type="password" id="password" name="password" class="ta-input w-full" placeholder="••••••••" required minlength="8" autofocus>
                            <p class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">8+ characters with mixed case and numbers.</p>
                        </div>

                        <div>
                            <label class="ta-label mb-1.5" for="password_confirm">Confirm password</label>
                            <input type="password" id="password_confirm" name="password_confirm" class="ta-input w-full" placeholder="••••••••" required minlength="8">
                        </div>

                        <button type="submit" class="btn-primary w-full py-3 text-base font-semibold">
                            Reset password
                        </button>
                    </form>

                    <p class="mt-6 text-center text-sm text-gray-600 dark:text-gray-400">
                        <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">&larr; Back to sign in</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

