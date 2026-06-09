<?php

/**
 * Member Portal - Reset Password (linked from forgot-password email)
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Controllers\AuthController;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die('Configuration not found.');
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Portal reset-password: " . $e->getMessage());
    die('System initialization failed.');
}

Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';

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
        error_log("Portal reset password error: " . $e->getMessage());
        $result = ['success' => false, 'message' => $error];
    }

    if (isset($result) && $result['success']) {
        $success = $result['message'];
        $token = '';
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Reset Password - Headcount Member Portal</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>tailwind-output.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>modern-design.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .glass-bg { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
    </style>
</head>
<body class="bg-[#F9FAFB] h-full flex flex-col font-jakarta">
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute -top-[10%] -left-[10%] w-[40%] h-[40%] bg-brand-500/10 rounded-full blur-[120px] animate-pulse"></div>
        <div class="absolute -bottom-[10%] -right-[10%] w-[40%] h-[40%] bg-purple-500/10 rounded-full blur-[120px] animate-pulse" style="animation-delay: 2s;"></div>
    </div>

    <div class="flex-1 flex flex-col items-center justify-center p-6 sm:p-8 lg:p-12 relative z-10">
        <div class="w-full max-w-[440px]">
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-white rounded-2xl shadow-xl shadow-brand-100 mb-6 border border-gray-100">
                    <img src="<?php echo htmlspecialchars($baseUrlPath); ?>/public/assets/images/logo.svg" alt="Headcount" class="w-10 h-10">
                </div>
                <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Reset Password</h1>
                <p class="text-gray-500 mt-2 font-medium">Enter your new password below.</p>
            </div>

            <div class="glass-bg rounded-3xl p-8 md:p-10 shadow-2xl shadow-brand-100/50">
                <?php if ($success): ?>
                    <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-xl text-sm font-medium mb-6">
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                    <p class="text-center">
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" class="text-brand-600 hover:text-brand-700 font-bold">Sign In</a>
                    </p>
                <?php elseif ($invalidToken): ?>
                    <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm font-medium mb-6">
                        Invalid or missing reset link. Please request a new one.
                    </div>
                    <p class="text-center space-x-4">
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/forgot-password.php" class="text-brand-600 hover:text-brand-700 font-bold">Request new link</a>
                        <span class="text-gray-400">|</span>
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" class="text-brand-600 hover:text-brand-700 font-bold">Back to Sign In</a>
                    </p>
                <?php else: ?>
                    <form method="POST" action="" class="space-y-6">
                        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                        <?php if ($error): ?>
                            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm font-medium">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest" for="password">New Password</label>
                            <input type="password" id="password" name="password" required minlength="8"
                                   class="w-full pl-11" placeholder="••••••••" autofocus>
                            <p class="text-xs text-gray-500 mt-1">8+ characters with mixed case and numbers.</p>
                        </div>

                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-bold text-gray-400 uppercase tracking-widest" for="password_confirm">Confirm Password</label>
                            <input type="password" id="password_confirm" name="password_confirm" required minlength="8"
                                   class="w-full pl-11" placeholder="••••••••">
                        </div>

                        <button type="submit"
                                class="w-full bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl shadow-lg shadow-brand-100 transition-all active:scale-[0.98]">
                            Reset Password
                        </button>
                    </form>

                    <p class="mt-8 text-center text-sm font-medium text-gray-500">
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" class="text-brand-600 hover:text-brand-700 font-bold">Back to Sign In</a>
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
