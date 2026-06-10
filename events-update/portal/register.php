<?php

/**
 * Member Registration Page
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;

// Redirect if already logged in
if (PortalAuthMiddleware::isAuthenticated()) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
    $basePath = preg_replace('#/portal/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    header('Location: ' . $basePath . '/portal/dashboard.php');
    exit;
}

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';
$jsBase = $baseUrlPath . '/public/js/';
$apiBase = $baseUrlPath . '/api/portal/';

// Get organization ID from config or default to 1
$configFile = HC_PROJECT_ROOT . '/config/config.php';
$organizationId = 1;
if (file_exists($configFile)) {
    $config = require $configFile;
    // Try to get organization ID from config or use default
    $organizationId = $config['organization_id'] ?? 1;
}

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Create Account - Headcount</title>
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
    <!-- Animated Background Elements -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="absolute -top-[10%] -right-[10%] w-[40%] h-[40%] bg-brand-500/10 rounded-full blur-[120px] animate-pulse"></div>
        <div class="absolute -bottom-[10%] -left-[10%] w-[40%] h-[40%] bg-purple-500/10 rounded-full blur-[120px] animate-pulse" style="animation-delay: 2s;"></div>
    </div>

    <div class="flex-1 flex flex-col items-center justify-center p-6 sm:p-8 lg:p-12 relative z-10">
        <div class="w-full max-w-[480px]">
            <div class="mb-6 text-center">
                <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php"
                   class="inline-flex items-center justify-center gap-2 text-sm font-semibold text-brand-600 hover:text-brand-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back to browse events
                </a>
            </div>
            <!-- Logo Section -->
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-14 h-14 bg-white dark:bg-gray-800 rounded-2xl shadow-xl shadow-brand-100 mb-6 border border-gray-100 dark:border-gray-800">
                    <img src="<?php echo htmlspecialchars($baseUrlPath); ?>/public/assets/images/logo.svg" alt="Headcount" class="w-8 h-8">
                </div>
                <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Create Account</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-2 font-medium">Join us and start RSVPing to events.</p>
            </div>

            <div class="glass-bg rounded-3xl p-8 md:p-10 shadow-2xl shadow-brand-100/50">
                <div id="error-message" class="hidden bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl mb-6 animate-fade-in text-sm font-medium"></div>
                <div id="success-message" class="hidden bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl mb-6 animate-fade-in text-sm font-medium"></div>

                <form id="register-form" class="space-y-5">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" required
                                   class="w-full pl-4"
                                   placeholder="John">
                        </div>
                        <div class="space-y-1.5">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" required
                                   class="w-full pl-4"
                                   placeholder="Doe">
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="email">Email Address</label>
                        <div class="relative">
                            <input type="email" id="email" name="email" required
                                   class="w-full pl-11"
                                   placeholder="name@company.com">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400 dark:text-gray-500">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.206"></path></svg>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="phone">Phone (Optional)</label>
                        <div class="relative">
                            <input type="tel" id="phone" name="phone"
                                   class="w-full pl-11"
                                   placeholder="(555) 000-0000">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400 dark:text-gray-500">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-1.5">
                        <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="password">Password</label>
                        <div class="relative">
                            <input type="password" id="password" name="password" required
                                   class="w-full pl-11"
                                   placeholder="••••••••"
                                   minlength="8">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400 dark:text-gray-500">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-14a4 4 0 00-8 0v4h8V7z"></path></svg>
                            </div>
                        </div>
                        <p class="text-[9px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-wider mt-1.5 leading-relaxed">8+ characters with mixed case and numbers.</p>
                    </div>
                    
                    <button type="submit" 
                            class="w-full bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl shadow-lg shadow-brand-100 transition-all active:scale-[0.98] flex items-center justify-center space-x-2 mt-4">
                        <span>Create Account</span>
                    </button>
                </form>

                <div class="mt-8 text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        Already have an account? 
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/login.php" class="text-brand-600 hover:text-brand-700 font-bold ml-1">Sign In</a>
                    </p>
                </div>
            </div>
            
            <div class="mt-8 text-center">
                <p class="text-[9px] text-gray-400 dark:text-gray-500 font-bold uppercase tracking-widest leading-loose">
                    By creating an account, you agree to our<br>
                    <a href="#" class="text-gray-500 dark:text-gray-400 hover:underline">Terms of Service</a> and <a href="#" class="text-gray-500 dark:text-gray-400 hover:underline">Privacy Policy</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;
        const organizationId = <?php echo json_encode($organizationId); ?>;

        // Get CSRF token (same URL pattern as login; must send credentials so session matches)
        async function getCSRFToken() {
            try {
                const csrfUrl = (baseUrl || '') + '/api/csrf-token';
                const response = await fetch(csrfUrl, { method: 'GET', credentials: 'same-origin' });
                if (!response.ok) return '';
                const data = await response.json();
                return (data && data.token) ? data.token : '';
            } catch (e) {
                return '';
            }
        }

        document.getElementById('register-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <div class="animate-spin rounded-full h-5 w-5 border-2 border-white/30 border-t-white"></div>
                <span>Creating account...</span>
            `;

            try {
                const csrfToken = await getCSRFToken();
                const formData = new FormData(form);
                formData.append('organization_id', organizationId);
                formData.append('csrf_token', csrfToken);

                const response = await fetch(apiBase + 'auth/register', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Account created! Redirecting to login...');
                    setTimeout(() => {
                        window.location.href = baseUrl + '/portal/login.php';
                    }, 2000);
                } else {
                    const errors = data.errors || [data.message || 'Registration failed'];
                    showError(errors.join(', '));
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });

        function showError(message) {
            const el = document.getElementById('error-message');
            el.textContent = message;
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 5000);
        }

        function showSuccess(message) {
            const el = document.getElementById('success-message');
            el.textContent = message;
            el.classList.remove('hidden');
        }
    </script>
</body>
</html>
