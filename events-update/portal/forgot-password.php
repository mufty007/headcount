<?php

/**
 * Member Portal - Forgot Password
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Controllers\PortalAuthController;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

if (PortalAuthMiddleware::isAuthenticated()) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
    $basePath = preg_replace('#/portal/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    header('Location: ' . $basePath . '/portal/dashboard.php');
    exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die('Configuration not found.');
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log('Portal forgot-password: ' . $e->getMessage());
    die('System initialization failed.');
}

Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $emailValue = htmlspecialchars($email);

    try {
        $authController = new PortalAuthController();
        $result = $authController->forgotPassword($email);
    } catch (\Exception $e) {
        $error = 'An error occurred. Please try again.';
        error_log('Portal forgot-password: ' . $e->getMessage());
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Forgot Password - Headcount</title>
    <?php include __DIR__ . '/includes/auth-dark.php'; ?>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>tailwind-output.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>modern-design.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-bg { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
    </style>
</head>
<body class="bg-[#F9FAFB] h-full flex flex-col font-jakarta dark:bg-gray-900">
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute -top-[10%] -left-[10%] w-[40%] h-[40%] bg-brand-500/10 rounded-full blur-[120px] animate-pulse"></div>
        <div class="absolute -bottom-[10%] -right-[10%] w-[40%] h-[40%] bg-purple-500/10 rounded-full blur-[120px] animate-pulse" style="animation-delay: 2s;"></div>
    </div>

    <div class="flex-1 flex flex-col items-center justify-center p-6 sm:p-8 lg:p-12 relative z-10">
        <div class="w-full max-w-[440px]">
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-white dark:bg-gray-800 rounded-2xl shadow-xl shadow-brand-100 mb-6 border border-gray-100 dark:border-gray-800">
                    <img src="<?php echo htmlspecialchars($baseUrlPath); ?>/public/assets/images/logo.svg" alt="Headcount" class="w-10 h-10">
                </div>
                <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Forgot Password</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-2 font-medium">Enter your email and we'll send you a reset link.</p>
            </div>

            <div class="glass-bg rounded-3xl p-8 md:p-10 shadow-2xl shadow-brand-100/50">
                <form method="POST" action="" class="space-y-6">
                    <?php if ($error): ?>
                        <div class="bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl text-sm font-medium">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl text-sm font-medium">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>

                    <div class="space-y-1.5">
                        <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="email">Email Address</label>
                        <div class="relative">
                            <input type="email" id="email" name="email" required
                                   class="w-full pl-11"
                                   placeholder="name@company.com"
                                   value="<?php echo $emailValue; ?>">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400 dark:text-gray-500">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.206"></path></svg>
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                            class="w-full bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl shadow-lg shadow-brand-100 transition-all active:scale-[0.98] flex items-center justify-center space-x-2">
                        <span>Send Reset Link</span>
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                    </button>
                </form>

                <p class="mt-8 text-center text-sm font-medium text-gray-500 dark:text-gray-400">
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" class="text-brand-600 hover:text-brand-700 font-bold">Back to Sign In</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
