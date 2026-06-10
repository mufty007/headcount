<?php

/**
 * Member Login Page
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Controllers\PortalAuthController;
use Headcount\Helpers\Security;

// Start session early so the same session (and CSRF token) is used for login requests
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Handle logout
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    try {
        $controller = new PortalAuthController();
        $controller->logout();
    } catch (\Exception $e) {
        // Log error but continue with logout process
        error_log("Portal logout error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        // Clear session anyway
        if (session_status() === PHP_SESSION_NONE) {
            \Headcount\Helpers\Security::configureSession();
            session_start();
        }
        $_SESSION = [];
        
        // Clear remember token cookie
        if (isset($_COOKIE['portal_remember_token'])) {
            $paths = ['/', '/portal/'];
            $domains = ['', null];
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!empty($host)) {
                $domains[] = $host;
                $domains[] = '.' . $host;
            }
            foreach ($paths as $path) {
                foreach ($domains as $domain) {
                    setcookie('portal_remember_token', '', time() - 3600, $path, $domain, true, true);
                    setcookie('portal_remember_token', '', time() - 3600, $path, $domain, false, true);
                }
            }
            unset($_COOKIE['portal_remember_token']);
        }
        
        // Clear session cookie
        $sessionName = session_name();
        if (isset($_COOKIE[$sessionName])) {
            $params = session_get_cookie_params();
            setcookie($sessionName, '', time() - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            setcookie($sessionName, '', time() - 3600, '/', $params['domain'], $params['secure'], $params['httponly']);
            unset($_COOKIE[$sessionName]);
        }
        
        session_destroy();
    }
    
    // Redirect to login page with logged_out parameter to prevent redirect loop
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
    $basePath = preg_replace('#/portal/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    header('Location: ' . $basePath . '/portal/login.php?logged_out=1');
    exit;
}

// Redirect if already logged in (but not if we just logged out)
if (!isset($_GET['logged_out']) && PortalAuthMiddleware::isAuthenticated()) {
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

$error = '';
$success = '';

// Show success message if user just logged out
if (isset($_GET['logged_out']) && $_GET['logged_out'] == '1') {
    $success = 'You have been successfully logged out.';
}

// CSRF token from same request as page load so login works when cookies are strict (e.g. mobile)
$loginCsrfToken = Security::generateCSRFToken();

?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Sign In - Headcount Member Portal</title>
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
        <div class="absolute -top-[10%] -left-[10%] w-[40%] h-[40%] bg-brand-500/10 rounded-full blur-[120px] animate-pulse"></div>
        <div class="absolute -bottom-[10%] -right-[10%] w-[40%] h-[40%] bg-purple-500/10 rounded-full blur-[120px] animate-pulse" style="animation-delay: 2s;"></div>
    </div>

    <div class="flex-1 flex flex-col items-center justify-center p-6 sm:p-8 lg:p-12 relative z-10">
        <div class="w-full max-w-[440px]">
            <div class="mb-6 text-center">
                <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php"
                   class="inline-flex items-center justify-center gap-2 text-sm font-semibold text-brand-600 hover:text-brand-700 transition-colors">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back to browse events
                </a>
            </div>
            <!-- Logo Section -->
            <div class="text-center mb-10">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-white dark:bg-gray-800 rounded-2xl shadow-xl shadow-brand-100 mb-6 border border-gray-100 dark:border-gray-800">
                    <img src="<?php echo htmlspecialchars($baseUrlPath); ?>/public/assets/images/logo.svg" alt="Headcount" class="w-10 h-10">
                </div>
                <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Welcome Back</h1>
                <p class="text-gray-500 dark:text-gray-400 mt-2 font-medium">Please enter your details to sign in.</p>
            </div>

            <div class="glass-bg rounded-3xl p-8 md:p-10 shadow-2xl shadow-brand-100/50">
                <div id="error-message" class="hidden bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl mb-6 animate-fade-in text-sm font-medium"></div>
                <div id="success-message" class="hidden bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl mb-6 animate-fade-in text-sm font-medium"></div>

                <!-- Password Login Form -->
                <form id="login-form" class="space-y-6">
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
                        <div class="flex items-center justify-between">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest" for="password">Password</label>
                            <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/forgot-password.php" class="text-[10px] font-bold text-brand-600 uppercase tracking-widest hover:text-brand-700">Forgot?</a>
                        </div>
                        <div class="relative">
                            <input type="password" id="password" name="password" required
                                   class="w-full pl-11"
                                   placeholder="••••••••">
                            <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-gray-400 dark:text-gray-500">
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-14a4 4 0 00-8 0v4h8V7z"></path></svg>
                            </div>
                        </div>
                    </div>

                    <label class="flex items-center cursor-pointer group">
                        <input type="checkbox" id="remember_me" name="remember_me" class="w-5 h-5 text-brand-600 rounded-lg border-gray-300 focus:ring-brand-500 transition-all cursor-pointer">
                        <span class="ml-3 text-sm text-gray-600 dark:text-gray-300 font-medium group-hover:text-gray-900 transition-colors">Remember my account</span>
                    </label>
                    
                    <button type="submit" 
                            class="w-full bg-brand-600 hover:bg-brand-700 text-white font-extrabold py-4 px-6 rounded-2xl shadow-lg shadow-brand-100 transition-all active:scale-[0.98] flex items-center justify-center space-x-2">
                        <span>Sign In</span>
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                    </button>
                </form>

                <div class="relative my-8">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-100 dark:border-gray-800"></div>
                    </div>
                    <div class="relative flex justify-center text-[10px] font-bold uppercase tracking-widest">
                        <span class="px-4 bg-white/50 dark:bg-gray-900/60 backdrop-blur-sm text-gray-400 dark:text-gray-500">Passwordless</span>
                    </div>
                </div>

                <!-- Magic Link Form -->
                <form id="magic-link-form" class="space-y-4">
                    <button type="submit" 
                            class="w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 text-gray-900 dark:text-white font-bold py-3.5 px-6 rounded-2xl transition-all shadow-sm active:scale-[0.98] flex items-center justify-center space-x-2">
                        <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                        <span>Continue with Magic Link</span>
                    </button>
                    <input type="email" id="magic-email" name="email" class="hidden">
                </form>

                <div class="mt-10 text-center">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        New here? 
                        <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/register.php" class="text-brand-600 hover:text-brand-700 font-bold ml-1">Create Account</a>
                    </p>
                </div>
            </div>
            
            <!-- Footer Links -->
            <div class="mt-8 flex items-center justify-center space-x-6 text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">
                <a href="#" class="hover:text-gray-600 transition-colors">Support</a>
                <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                <a href="#" class="hover:text-gray-600 transition-colors">Privacy</a>
                <span class="w-1 h-1 bg-gray-300 rounded-full"></span>
                <a href="#" class="hover:text-gray-600 transition-colors">Terms</a>
            </div>
        </div>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;
        const embeddedCsrfToken = <?php echo json_encode($loginCsrfToken); ?>;

        // Show success message if user just logged out
        <?php if (!empty($success)): ?>
        document.addEventListener('DOMContentLoaded', function() {
            showSuccess(<?php echo json_encode($success); ?>);
        });
        <?php endif; ?>

        // Get CSRF token: use token embedded in page (same session as this load) so login works on mobile/strict cookies
        async function getCSRFToken() {
            if (embeddedCsrfToken) {
                return embeddedCsrfToken;
            }
            try {
                const csrfUrl = (baseUrl || '') + '/api/csrf-token';
                const response = await fetch(csrfUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    redirect: 'follow'
                });
                if (!response.ok) throw new Error('Failed to get CSRF token: ' + response.status);
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) throw new Error('Invalid response from CSRF endpoint');
                const data = await response.json();
                if (!data.success || !data.token) throw new Error('Invalid CSRF token response');
                return data.token;
            } catch (error) {
                console.error('Error getting CSRF token:', error);
                return '';
            }
        }

        // Password login
        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <div class="animate-spin rounded-full h-5 w-5 border-2 border-white/30 border-t-white"></div>
                <span>Signing in...</span>
            `;

            try {
                const csrfToken = await getCSRFToken();
                const formData = new FormData(form);
                if (csrfToken) {
                    formData.append('csrf_token', csrfToken);
                }

                const loginUrl = apiBase + 'auth/login';
                console.log('Attempting login to:', loginUrl);
                console.log('Request method: POST');
                console.log('Form data:', Object.fromEntries(formData));
                
                const response = await fetch(loginUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    redirect: 'follow', // Allow redirects but we'll check the final URL
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {})
                    },
                    body: formData
                });

                console.log('Response status:', response.status);
                console.log('Response URL:', response.url);
                console.log('Response redirected:', response.redirected);
                console.log('Response type:', response.type);

                // Check if we got redirected to a non-API endpoint (like login.php)
                if (response.redirected && response.url.includes('/login.php')) {
                    console.error('Login request was redirected to login.php! Final URL:', response.url);
                    showError('Server redirected the request incorrectly. Please check your server configuration.');
                    return;
                }

                // Check if response is not OK
                if (!response.ok && response.status !== 200) {
                    console.error('Response not OK:', response.status, response.statusText);
                    const errorText = await response.text();
                    console.error('Error response:', errorText.substring(0, 500));
                    showError('Server returned an error: ' + response.status + ' ' + response.statusText);
                    return;
                }

                // Check content type
                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response from login endpoint:', text.substring(0, 200));
                    showError('Invalid response from server. Please check your connection.');
                    return;
                }

                const data = await response.json();

                if (data.success) {
                    window.location.href = baseUrl + '/portal/dashboard.php';
                } else {
                    showError(data.message || 'Login failed');
                }
            } catch (error) {
                console.error('Login error:', error);
                showError('An error occurred. Please try again. ' + (error.message || ''));
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        });

        // Magic link
        document.getElementById('magic-link-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const emailInput = document.getElementById('email');
            const email = emailInput.value;

            if (!email) {
                showError('Please enter your email address first');
                emailInput.focus();
                return;
            }

            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = `
                <div class="animate-spin rounded-full h-4 w-4 border-2 border-brand-500 border-t-transparent"></div>
                <span>Sending link...</span>
            `;

            try {
                const csrfToken = await getCSRFToken();
                const formData = new FormData();
                formData.append('email', email);
                formData.append('csrf_token', csrfToken);

                const response = await fetch(apiBase + 'auth/magic-link', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    body: formData
                });

                const contentType = response.headers.get('content-type') || '';
                if (!contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Magic link API returned non-JSON:', response.status, text.substring(0, 200));
                    showError('Server returned an invalid response. Check the console or try again.');
                    return;
                }

                const data = await response.json();

                if (data.success) {
                    showSuccess('Magic link sent! Check your email.');
                    window.location.href = baseUrl + '/portal/magic-link-sent.php?email=' + encodeURIComponent(email);
                } else {
                    showError(data.message || 'Failed to send magic link');
                }
            } catch (error) {
                console.error('Magic link error:', error);
                showError('Could not reach the server. Check the URL or try again.');
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
            setTimeout(() => el.classList.add('hidden'), 5000);
        }
    </script>
</body>
</html>
