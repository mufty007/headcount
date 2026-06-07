<?php

/**
 * Admin Forgot Password Page
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
    <title>Forgot Password - Headcount Events</title>
    <?php require __DIR__ . '/includes/auth-head.php'; ?>
</head>
<body class="flex min-h-full flex-col antialiased">
    <?php require __DIR__ . '/includes/auth-theme-controls.php'; ?>
    <div class="flex flex-1 flex-col justify-center px-4 py-10 sm:px-6">
        <div class="mx-auto w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 shadow-card-lg dark:border-slate-700 dark:bg-slate-800 sm:p-10">
            <div class="mb-8 text-center">
                <img src="<?php echo htmlspecialchars($assetsBase); ?>images/logo.svg" alt="Headcount Events Logo" class="mx-auto mb-4 h-14 w-auto" width="56" height="56">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white">Forgot password</h1>
                <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Enter your email and we&apos;ll send you a reset link.</p>
            </div>

            <form method="POST" action="">
                <?php if ($error): ?>
                    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="alert">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <div class="mb-6">
                    <label class="mb-2 block text-sm font-medium text-gray-700 dark:text-slate-300" for="email">Email address</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2.5 text-sm shadow-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100"
                        value="<?php echo $emailValue; ?>"
                        required
                        autofocus
                    >
                </div>

                <button
                    type="submit"
                    class="w-full btn-primary "
                >
                    Send reset link
                </button>
            </form>

            <p class="mt-6 text-center text-sm text-gray-500 dark:text-slate-400">
                <a href="<?php echo htmlspecialchars($basePath); ?>/admin/?page=login" class="font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">Back to login</a>
            </p>
        </div>
    </div>
    <?php require __DIR__ . '/../footer-public.php'; ?>
</body>
</html>

