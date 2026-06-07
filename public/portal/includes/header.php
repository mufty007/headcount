<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<?php
$hcRoot = defined('HC_PROJECT_ROOT') ? HC_PROJECT_ROOT : dirname(__DIR__, 3);
// Include helper functions
if (!function_exists('e')) {
    $helpersPath = $hcRoot . '/src/helpers.php';
    if (file_exists($helpersPath)) {
        require_once $helpersPath;
    } else {
        function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
    }
}

// Load PortalAuthMiddleware if not already loaded
if (!class_exists('Headcount\Middleware\PortalAuthMiddleware')) {
    require_once $hcRoot . '/vendor/autoload.php';
}

use Headcount\Middleware\PortalAuthMiddleware;

// Check if user is authenticated (if not already checked)
if (!isset($isLoggedIn)) {
    $isLoggedIn = PortalAuthMiddleware::isAuthenticated();
}

// Ensure $member is available for the header
if (!isset($member)) {
    if ($isLoggedIn) {
        $member = PortalAuthMiddleware::getMember();
    } else {
        $member = null;
    }
}

// If member is still not set, try to get from session
if (!$member && $isLoggedIn) {
    $firstName = $_SESSION['first_name'] ?? '';
    $lastName = $_SESSION['last_name'] ?? '';
    $fullName = trim($firstName . ' ' . $lastName) ?: 'Member';
    $member = [
        'name' => $fullName,
        'email' => $_SESSION['email'] ?? 'member@headcount.local',
        'first_name' => $firstName ?: 'Member',
        'last_name' => $lastName
    ];
} elseif ($member) {
    // Ensure member has all required fields
    if (!isset($member['name'])) {
        $member['name'] = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) ?: 'Member';
    }
    if (!isset($member['first_name'])) {
        $member['first_name'] = explode(' ', $member['name'])[0] ?? 'Member';
    }
}

// Ensure $APP_NAME is available
if (!isset($APP_NAME)) {
    $APP_NAME = 'Headcount';
}

// Calculate base path if not set
if (!isset($basePath)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/portal/';
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Try to determine base path from script name first (more reliable)
    if (stripos($scriptName, '/headcount/') !== false) {
        $basePath = preg_replace('#/portal/.*$#i', '', $scriptName);
        $basePath = rtrim($basePath, '/');
    } else {
        $basePath = preg_replace('#/portal/.*$#i', '', $requestPath);
        $basePath = rtrim($basePath, '/');
    }
    
    // If base path is empty or just '/', check if we're in a subdirectory
    if (empty($basePath) || $basePath === '/') {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
        
        if (strpos($scriptDir, $docRoot) === 0) {
            $relativePath = str_replace($docRoot, '', dirname($scriptDir));
            if (!empty($relativePath) && $relativePath !== '/') {
                $basePath = str_replace('\\', '/', $relativePath);
                $basePath = rtrim($basePath, '/');
            }
        }
    }
    
    if (!empty($basePath) && $basePath[0] !== '/') {
        $basePath = '/' . $basePath;
    }
}

// Ensure $portalBase is available
if (!isset($portalBase)) {
    $portalBase = $basePath . '/portal';
}

// Ensure $assetsBase is available
if (!isset($assetsBase)) {
    if (strpos($basePath, '/public') !== false) {
        $assetsBase = $basePath . '/assets/';
    } else {
        $assetsBase = $basePath . '/public/assets/';
    }
}

// Ensure $currentPage is available
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'], '.php');
}

// Ensure $navUrls is available for sidebar links
if (!isset($navUrls)) {
    $navUrls = [
        'dashboard' => $portalBase . '/dashboard.php',
        'events' => $portalBase . '/events.php',
        'programs' => $portalBase . '/programs.php',
        'my-programs' => $portalBase . '/my-programs.php',
        'facilities' => $portalBase . '/facilities.php',
        'my-facility-bookings' => $portalBase . '/my-facility-bookings.php',
        'my-rsvps' => $portalBase . '/my-rsvps.php',
        'payments' => $portalBase . '/payments.php',
        'profile' => $portalBase . '/profile.php',
        'qr-code' => $portalBase . '/qr-code.php',
        'family' => $portalBase . '/family.php',
        'feedback' => $portalBase . '/feedback.php'
    ];
}
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?> - <?php echo htmlspecialchars($APP_NAME); ?> Portal</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="<?= e($assetsBase) ?>images/logo.svg">
    <link rel="icon" type="image/x-icon" href="<?= e($basePath ?: '') ?>/favicon.ico">
    
    <!-- Fonts & Utilities -->
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <?php
    // Calculate paths for both CSS files
    if (empty($basePath) || $basePath === '/') {
        $tailwindPath = '/public/css/tailwind-output.css';
        $modernPath = '/public/css/modern-design.css';
    } else {
        $basePathFormatted = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
        $basePathFormatted = rtrim($basePathFormatted, '/');
        $tailwindPath = $basePathFormatted . '/public/css/tailwind-output.css';
        $modernPath = $basePathFormatted . '/public/css/modern-design.css';
    }
    // Remove double slashes and ensure paths start with /
    $tailwindPath = preg_replace('#/+#', '/', $tailwindPath);
    $modernPath = preg_replace('#/+#', '/', $modernPath);
    if ($tailwindPath[0] !== '/') $tailwindPath = '/' . $tailwindPath;
    if ($modernPath[0] !== '/') $modernPath = '/' . $modernPath;
    
    // Check if compiled Tailwind exists
    $tailwindFile = $_SERVER['DOCUMENT_ROOT'] . $tailwindPath;
    if (file_exists($tailwindFile)) {
        // Production: Use compiled CSS (includes all Tailwind utilities + custom classes)
        echo '<link rel="stylesheet" href="' . e($tailwindPath) . '">' . "\n    ";
    } else {
        // Development: Fallback to CDN + modern-design.css
        echo '<script src="https://cdn.tailwindcss.com"></script>' . "\n    ";
        echo '<link rel="stylesheet" href="' . e($modernPath) . '">' . "\n    ";
    }
    
    // Helper function to build CSS path
    function buildCssPath($basePath, $filename) {
        if (empty($basePath) || $basePath === '/') {
            $cssPath = '/public/css/' . $filename;
        } else {
            // Normalize basePath
            $cssPath = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
            $cssPath = rtrim($cssPath, '/');
            
            // Check if basePath already ends with /public
            if (substr($cssPath, -7) === '/public') {
                // Base path already includes /public, just add /css/
                $cssPath .= '/css/' . $filename;
            } else {
                // Base path doesn't include /public, add it
                $cssPath .= '/public/css/' . $filename;
            }
        }
        $cssPath = preg_replace('#/+#', '/', $cssPath);
        if ($cssPath[0] !== '/') {
            $cssPath = '/' . $cssPath;
        }
        return $cssPath;
    }
    
    // Always include modal.css for confirm dialogs
    echo '<link rel="stylesheet" href="' . e(buildCssPath($basePath, 'modal.css')) . '">' . "\n    ";
    
    // Allow pages to add additional CSS files
    if (isset($additionalCSS) && is_array($additionalCSS)) {
        foreach ($additionalCSS as $css) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">' . "\n    ";
        }
    }
    ?>
    
    <?php
    if (isset($additionalCSS) && is_array($additionalCSS)) {
        foreach ($additionalCSS as $css) {
            echo '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">' . "\n    ";
        }
    }
    ?>
</head>
<body class="h-full" x-data="{ 
    sidebarOpen: false,
    sidebarCollapsed: false,
    init() {
        if (typeof Storage !== 'undefined') {
            this.sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        }
    },
    toggleSidebar() {
        this.sidebarCollapsed = !this.sidebarCollapsed;
        if (typeof Storage !== 'undefined') {
            localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed);
        }
    }
}">
    <div class="app-container h-full overflow-hidden">
        
        <!-- Mobile Sidebar Overlay -->
        <div x-show="sidebarOpen" 
             @click="sidebarOpen = false" 
             x-cloak
             class="fixed inset-0 z-40 bg-gray-600 bg-opacity-75 lg:hidden transition-opacity duration-300 pointer-events-auto"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             style="touch-action: none;"></div>

        <!-- Sidebar Navigation -->
        <aside class="sidebar" :class="{ 'open': sidebarOpen, 'collapsed': sidebarCollapsed }">
            <div class="sidebar-header flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <img src="<?= e($assetsBase) ?>images/logo.svg" alt="Logo" class="h-8 w-8 flex-shrink-0" width="32" height="32">
                    <h1 class="sidebar-title" x-show="!sidebarCollapsed">Headcount</h1>
                </div>
                <div class="flex items-center space-x-2">
                    <button @click="toggleSidebar()" class="hidden lg:flex text-gray-500 hover:text-gray-900 p-1 rounded transition-colors" title="Toggle sidebar">
                        <svg x-show="!sidebarCollapsed" width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path></svg>
                        <svg x-show="sidebarCollapsed" width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
                    </button>
                    <button @click="sidebarOpen = false" class="lg:hidden text-gray-500 hover:text-gray-900">
                        <svg width="24" height="24" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>

            <nav class="sidebar-nav">
                <?php if ($isLoggedIn): ?>
                    <a href="<?= e($navUrls['dashboard']) ?>" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" title="Dashboard" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                        <span class="nav-label">Dashboard</span>
                    </a>
                <?php endif; ?>
                
                <a href="<?= e($navUrls['events']) ?>" class="nav-item <?= $currentPage === 'events' ? 'active' : '' ?>" title="Events" @click="sidebarOpen = false">
                    <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span class="nav-label">Browse Events</span>
                </a>

                <a href="<?= e($navUrls['facilities']) ?>" class="nav-item <?= in_array($currentPage, ['facilities', 'facility-details', 'facility-book', 'facility-book-guest'], true) ? 'active' : '' ?>" title="Facilities" @click="sidebarOpen = false">
                    <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <span class="nav-label">Facilities</span>
                </a>
                
                <?php if ($isLoggedIn): ?>
                    <a href="<?= e($navUrls['programs']) ?>" class="nav-item <?= in_array($currentPage, ['programs', 'program-details'], true) ? 'active' : '' ?>" title="Programs" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        <span class="nav-label">Programs</span>
                    </a>
                    <a href="<?= e($navUrls['my-programs']) ?>" class="nav-item <?= $currentPage === 'my-programs' ? 'active' : '' ?>" title="My Programs" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"></path></svg>
                        <span class="nav-label">My Programs</span>
                    </a>
                <?php endif; ?>
                
                <?php if ($isLoggedIn): ?>
                    <a href="<?= e($navUrls['my-rsvps']) ?>" class="nav-item <?= $currentPage === 'my-rsvps' ? 'active' : '' ?>" title="My RSVPs" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span class="nav-label">My RSVPs</span>
                    </a>

                    <a href="<?= e($navUrls['my-facility-bookings']) ?>" class="nav-item <?= $currentPage === 'my-facility-bookings' ? 'active' : '' ?>" title="My Facility Bookings" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <span class="nav-label">My Bookings</span>
                    </a>
                    
                    <a href="<?= e($navUrls['payments']) ?>" class="nav-item <?= $currentPage === 'payments' ? 'active' : '' ?>" title="Payments" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span class="nav-label">Payments</span>
                    </a>
                    
                    <a href="<?= e($navUrls['qr-code']) ?>" class="nav-item <?= $currentPage === 'qr-code' ? 'active' : '' ?>" title="QR Code" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                        <span class="nav-label">QR Code</span>
                    </a>
                    
                    <a href="<?= e($navUrls['family']) ?>" class="nav-item <?= $currentPage === 'family' ? 'active' : '' ?>" title="Family" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <span class="nav-label">Family</span>
                    </a>
                    
                    <a href="<?= e($navUrls['profile']) ?>" class="nav-item <?= $currentPage === 'profile' ? 'active' : '' ?>" title="Profile" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        <span class="nav-label">Profile</span>
                    </a>
                <?php else: ?>
                    <a href="<?= e($portalBase . '/login.php') ?>" class="nav-item" title="Log In" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                        <span class="nav-label">Log In</span>
                    </a>
                    <a href="<?= e($portalBase . '/register.php') ?>" class="nav-item" title="Sign Up" @click="sidebarOpen = false">
                        <svg width="24" height="24" class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                        <span class="nav-label">Sign Up</span>
                    </a>
                <?php endif; ?>
            </nav>

            <?php if ($isLoggedIn): ?>
            <div class="p-4 border-t border-gray-100" style="pointer-events: auto; z-index: 70;">
                <button type="button"
                   id="sign-out-btn"
                   class="w-full flex items-center p-2 text-sm text-red-600 hover:bg-red-50 rounded-lg transition-colors cursor-pointer" 
                   title="Sign Out"
                   data-logout-url="<?= e($portalBase . '/login.php?logout=1') ?>">
                    <svg width="20" height="20" class="w-5 h-5 nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span class="nav-label">Sign Out</span>
                </button>
            </div>
            <script>
                (function() {
                    const basePath = '<?= e($basePath ?? '') ?>';
                    const apiBase = basePath ? basePath + '/api/portal/' : '/api/portal/';
                    const csrfTokenUrl = basePath ? basePath + '/api/csrf-token' : '/api/csrf-token';
                    const loginUrl = '<?= e($portalBase . '/login.php') ?>';
                    
                    // Get CSRF token (optional - logout should work without it)
                    async function getCSRFToken() {
                        try {
                            const response = await fetch(csrfTokenUrl, {
                                method: 'GET',
                                credentials: 'same-origin',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest'
                                }
                            });
                            if (response.ok) {
                                const data = await response.json();
                                return data.token || '';
                            }
                        } catch (e) {
                            // CSRF token is optional for logout
                        }
                        return '';
                    }
                    
                    async function performLogout() {
                        console.log('Starting logout process...');
                        
                        // Get CSRF token if available (optional for logout)
                        const csrfToken = await getCSRFToken();
                        
                        // Try API logout first (more reliable)
                        // Use Promise.race to add timeout
                        const logoutPromise = (async () => {
                            try {
                                console.log('Attempting API logout at:', apiBase + 'auth/logout');
                                const headers = {
                                    'Content-Type': 'application/json',
                                    'X-Requested-With': 'XMLHttpRequest',
                                    'Accept': 'application/json'
                                };
                                
                                // Add CSRF token if available
                                if (csrfToken) {
                                    headers['X-CSRF-Token'] = csrfToken;
                                }
                                
                                const response = await fetch(apiBase + 'auth/logout', {
                                    method: 'POST',
                                    headers: headers,
                                    credentials: 'same-origin',
                                    body: csrfToken ? JSON.stringify({ csrf_token: csrfToken }) : '{}'
                                });
                                return response;
                            } catch (error) {
                                throw error;
                            }
                        })();
                        
                        const timeoutPromise = new Promise((_, reject) => 
                            setTimeout(() => reject(new Error('Logout timeout')), 5000)
                        );
                        
                        try {
                            const response = await Promise.race([logoutPromise, timeoutPromise]);
                            
                            console.log('Logout API response status:', response.status);
                            
                            // Try to parse response regardless of status
                            try {
                                const data = await response.json();
                                console.log('Logout API response:', data);
                                
                                // If API says success, redirect
                                if (data.success) {
                                    console.log('Logout successful, redirecting to login');
                                    window.location.replace(loginUrl + '?logged_out=1');
                                    return;
                                }
                            } catch (e) {
                                console.log('Could not parse JSON response:', e);
                                // If status is OK but no JSON, assume success
                                if (response.ok) {
                                    console.log('Status OK, redirecting to login');
                                    window.location.replace(loginUrl + '?logged_out=1');
                                    return;
                                }
                            }
                            
                            // If we get here, API call didn't work as expected
                            console.warn('API logout did not return success, using fallback');
                        } catch (error) {
                            console.error('API logout failed:', error);
                            // Continue to fallback
                        }
                        
                        // Fallback to direct redirect (this always works)
                        console.log('Using fallback logout method');
                        const signOutBtn = document.getElementById('sign-out-btn');
                        const logoutUrl = signOutBtn ? signOutBtn.getAttribute('data-logout-url') : loginUrl + '?logout=1';
                        window.location.replace(logoutUrl);
                    }
                    
                    function handleSignOutClick(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        
                        console.log('Sign out button clicked');
                        
                        // Show confirmation dialog
                        let confirmed = false;
                        
                        // Try custom dialog first
                        if (typeof confirmAction !== 'undefined') {
                            try {
                                confirmAction({
                                    title: 'Sign Out',
                                    message: 'Are you sure you want to sign out?',
                                    type: 'warning',
                                    okText: 'Sign Out',
                                    cancelText: 'Cancel'
                                }).then(function(result) {
                                    if (Boolean(result) && result !== false && result !== null && result !== '') {
                                        performLogout();
                                    }
                                }).catch(function(err) {
                                    console.error('Confirm dialog error:', err);
                                    if (confirm('Are you sure you want to sign out?')) {
                                        performLogout();
                                    }
                                });
                                return false;
                            } catch (err) {
                                console.error('Confirm dialog error:', err);
                                confirmed = confirm('Are you sure you want to sign out?');
                            }
                        } else {
                            confirmed = confirm('Are you sure you want to sign out?');
                        }
                        
                        if (confirmed) {
                            performLogout();
                        }
                        
                        return false;
                    }
                    
                    // Attach event listener
                    function attachListener() {
                        const signOutBtn = document.getElementById('sign-out-btn');
                        if (signOutBtn) {
                            signOutBtn.addEventListener('click', handleSignOutClick);
                            console.log('Sign out button listener attached');
                        } else {
                            console.warn('Sign out button not found');
                        }
                    }
                    
                    // Attach when DOM is ready
                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', attachListener);
                    } else {
                        attachListener();
                    }
                })();
            </script>
            <?php endif; ?>
        </aside>

        <!-- Main Content Wrapper -->
        <div class="main-content flex flex-col" 
             :class="{ 'sidebar-collapsed': sidebarCollapsed, 'sidebar-open': sidebarOpen }"
             :style="sidebarOpen ? 'pointer-events: none; overflow: hidden;' : ''">
            
            <!-- Context Bar -->
            <header class="h-[64px] bg-white border-b border-gray-200 shadow-sm flex items-center justify-between px-4 md:px-6 shrink-0 z-30 sticky top-0">
                <div class="flex items-center space-x-2 md:space-x-4">
                    <button @click="sidebarOpen = true" class="lg:hidden text-gray-500 hover:text-gray-900 p-2 rounded-lg hover:bg-gray-100 transition-colors">
                        <svg width="24" height="24" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    <div class="flex items-center space-x-2">
                        <img src="<?= e($assetsBase) ?>images/logo.svg" alt="Logo" class="h-8 w-8 lg:hidden" width="32" height="32">
                        <h2 class="text-lg font-bold text-gray-900 lg:hidden">Headcount</h2>
                    </div>
                    
                    <!-- Search Bar (Desktop) -->
                    <div class="hidden md:flex items-center bg-gray-100 rounded-full px-4 py-2 w-64 focus-within:ring-2 focus-within:ring-indigo-500 focus-within:bg-white transition-all">
                        <svg width="16" height="16" class="w-4 h-4 text-gray-400 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <input type="text" placeholder="Quick Search..." class="bg-transparent border-none text-sm focus:outline-none w-full p-0">
                    </div>
                </div>

                <div class="flex items-center space-x-2 md:space-x-4">
                    <?php if ($isLoggedIn && $member): ?>
                        <div class="h-8 w-px bg-gray-200 hidden sm:block"></div>
                        
                        <div class="flex items-center space-x-3 cursor-pointer group px-2 py-1 rounded-full hover:bg-gray-50 transition-colors">
                            <div class="text-right hidden md:block">
                                <div class="text-sm font-semibold text-gray-900 leading-none"><?= e($member['name']) ?></div>
                                <div class="text-xs text-gray-500 mt-1"><?= e($member['email']) ?></div>
                            </div>
                            <div class="w-9 h-9 md:w-10 md:h-10 bg-indigo-100 rounded-full flex items-center justify-center group-hover:bg-indigo-600 transition-all shadow-sm overflow-hidden">
                                <span class="text-indigo-600 font-bold group-hover:text-white transition-colors"><?= strtoupper(substr($member['name'] ?? 'M', 0, 1)) ?></span>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= e($portalBase . '/login.php') ?>" class="px-3 py-1.5 md:px-4 md:py-2 text-sm font-medium text-gray-700 hover:text-gray-900">Log In</a>
                        <a href="<?= e($portalBase . '/register.php') ?>" class="px-3 py-1.5 md:px-4 md:py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 shadow-sm transition-all active:scale-95">Sign Up</a>
                    <?php endif; ?>
                </div>
            </header>



            <!-- Scrollable Content Area -->
            <main class="flex-1 overflow-y-auto p-4 md:p-8 animate-fade-in">
                
                <!-- Flash Messages -->
                <?php $flash = getFlash(); ?>
                <?php if ($flash): ?>
                    <div class="mb-6" x-data="{ show: true }" x-show="show" x-transition>
                        <div class="p-4 rounded-lg flex items-center justify-between <?= $flash['type'] === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200' ?>">
                            <div class="flex items-center">
                                <?php if ($flash['type'] === 'success'): ?>
                                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php endif; ?>
                                <span class="text-sm font-medium"><?= e($flash['message']) ?></span>
                            </div>
                            <button @click="show = false" class="text-gray-400 hover:text-gray-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
