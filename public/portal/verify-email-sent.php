<?php

/**
 * Post-registration: check your email / resend verification
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Security;

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

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
$apiBase = $baseUrlPath . '/api/portal/';

$email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verify Your Email - Headcount</title>
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
    <div class="flex-1 flex flex-col items-center justify-center p-6 sm:p-8 lg:p-12 relative z-10">
        <div class="w-full max-w-[480px]">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-14 h-14 bg-white dark:bg-gray-800 rounded-2xl shadow-xl shadow-brand-100 mb-6 border border-gray-100 dark:border-gray-800">
                    <img src="<?php echo htmlspecialchars($baseUrlPath); ?>/public/assets/images/logo.svg" alt="Headcount" class="w-8 h-8">
                </div>
                <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Check your email</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-2 font-medium">We sent a verification link to activate your account.</p>
            </div>

            <div class="glass-bg rounded-3xl p-8 md:p-10 shadow-2xl shadow-brand-100/50">
                <div id="error-message" class="hidden bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl mb-6 text-sm font-medium"></div>
                <div id="success-message" class="hidden bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl mb-6 text-sm font-medium"></div>

                <p class="text-sm text-gray-600 dark:text-gray-300 mb-6 leading-relaxed">
                    <?php if ($email !== ''): ?>
                        Look for an email sent to <strong class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($email); ?></strong>. Click the link to verify, then sign in.
                    <?php else: ?>
                        Look for the verification email we just sent. Click the link to verify, then sign in.
                    <?php endif; ?>
                </p>

                <form id="resend-form" class="space-y-4">
                    <div class="space-y-1.5">
                        <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="email">Email</label>
                        <input type="email" id="email" name="email" required
                               value="<?php echo htmlspecialchars($email); ?>"
                               class="w-full pl-4"
                               placeholder="name@company.com">
                    </div>
                    <button type="submit"
                            class="w-full bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl shadow-lg shadow-brand-100 transition-all active:scale-[0.98]">
                        Resend verification email
                    </button>
                </form>

                <div class="mt-8 text-center">
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" class="text-sm font-bold text-brand-600 hover:text-brand-700">Back to sign in</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        async function getCSRFToken() {
            try {
                const response = await fetch((baseUrl || '') + '/api/csrf-token', { method: 'GET', credentials: 'same-origin' });
                if (!response.ok) return '';
                const data = await response.json();
                return (data && data.token) ? data.token : '';
            } catch (e) {
                return '';
            }
        }

        document.getElementById('resend-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const btn = form.querySelector('button[type="submit"]');
            const original = btn.textContent;
            btn.disabled = true;
            btn.textContent = 'Sending...';
            document.getElementById('error-message').classList.add('hidden');
            document.getElementById('success-message').classList.add('hidden');

            try {
                const csrfToken = await getCSRFToken();
                const email = form.email.value.trim();
                const response = await fetch(apiBase + 'auth/resend-verification', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({ email: email, csrf_token: csrfToken })
                });
                const data = await response.json();
                if (data.success) {
                    const el = document.getElementById('success-message');
                    el.textContent = data.message || 'If that email needs verification, a new link has been sent.';
                    el.classList.remove('hidden');
                } else {
                    const el = document.getElementById('error-message');
                    el.textContent = data.message || 'Unable to resend. Please try again.';
                    el.classList.remove('hidden');
                }
            } catch (err) {
                const el = document.getElementById('error-message');
                el.textContent = 'An error occurred. Please try again.';
                el.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btn.textContent = original;
            }
        });
    </script>
</body>
</html>
