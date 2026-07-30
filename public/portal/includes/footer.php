<?php
// Ensure variables are available
if (!isset($APP_NAME)) {
    $APP_NAME = 'IMCA';
}

if (!isset($member)) {
    $member = [
        'name' => $_SESSION['member_name'] ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Member',
        'email' => $_SESSION['member_email'] ?? $_SESSION['email'] ?? 'member@headcount.local'
    ];
}

if (!isset($portalBase)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
    $basePath = preg_replace('#/portal/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    $portalBase = $basePath . '/portal';
}

if (!isset($assetsBase)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
    $basePath = preg_replace('#/portal/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
    $assetsBase = $basePath . '/public/assets/';
}

if (!isset($isLoggedIn)) {
    $isLoggedIn = !empty($_SESSION['user_id']) || !empty($_SESSION['member_id']);
}
if (!isset($currentPage)) {
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '', '.php');
}
if (!isset($navUrls)) {
    $navUrls = [
        'dashboard' => $portalBase . '/dashboard.php',
        'events' => $portalBase . '/events.php',
        'programs' => $portalBase . '/programs.php',
        'facilities' => $portalBase . '/facilities.php',
        'my-rsvps' => $portalBase . '/my-rsvps.php',
        'profile' => $portalBase . '/profile.php',
    ];
}

$tabEvents = $currentPage === 'events' || $currentPage === 'event-details';
$tabPrograms = in_array($currentPage, ['programs', 'program-details', 'my-programs'], true);
$tabFacilities = in_array($currentPage, ['facilities', 'facility-details', 'facility-book', 'facility-book-guest', 'my-facility-bookings'], true);
$tabRsvps = $currentPage === 'my-rsvps';
?>
            </main>
            
            <!-- Modern Footer (desktop / secondary) -->
            <footer class="portal-site-footer bg-white border-t border-gray-200 py-4 px-4 md:px-8 shrink-0 dark:bg-gray-900 dark:border-gray-800 hidden lg:block">
                <div class="flex flex-col md:flex-row justify-between items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                    <div class="text-center md:text-left text-[11px] md:text-xs">
                        &copy; <?= date('Y') ?> <strong class="text-gray-700 dark:text-gray-200"><?= e($APP_NAME) ?></strong>. All rights reserved. • Version 1.1.0
                    </div>
                    <div class="flex flex-wrap items-center justify-center gap-3 md:gap-4 text-[11px] md:text-xs">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 bg-green-500 rounded-full flex-shrink-0"></span>
                            <span class="whitespace-nowrap">System Operational</span>
                        </span>
                        <a href="#" class="hover:text-brand-600 transition-colors whitespace-nowrap dark:hover:text-brand-400">Help</a>
                        <a href="#" class="hover:text-brand-600 transition-colors whitespace-nowrap dark:hover:text-brand-400">Support</a>
                    </div>
                </div>
            </footer>
        </div>
    </div>

    <!-- Mobile bottom tab bar -->
    <nav class="portal-bottom-nav" aria-label="Primary">
        <a href="<?= e($navUrls['events']) ?>" class="portal-bottom-nav__item <?= $tabEvents ? 'is-active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            <span>Events</span>
        </a>
        <a href="<?= e($navUrls['programs']) ?>" class="portal-bottom-nav__item <?= $tabPrograms ? 'is-active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
            <span>Programs</span>
        </a>
        <a href="<?= e($navUrls['facilities']) ?>" class="portal-bottom-nav__item <?= $tabFacilities ? 'is-active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
            <span>Spaces</span>
        </a>
        <?php if ($isLoggedIn): ?>
        <a href="<?= e($navUrls['my-rsvps']) ?>" class="portal-bottom-nav__item <?= $tabRsvps ? 'is-active' : '' ?>">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span>RSVPs</span>
        </a>
        <?php else: ?>
        <a href="<?= e($portalBase . '/login.php') ?>" class="portal-bottom-nav__item">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
            <span>Log in</span>
        </a>
        <?php endif; ?>
        <button type="button" class="portal-bottom-nav__item" @click="sidebarOpen = true" aria-label="More">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            <span>More</span>
        </button>
    </nav>

    <!-- Modals & Overlays -->
    <div id="modal-container"></div>
    
    <!-- Scripts -->
    <?php
    if (isset($additionalJS) && is_array($additionalJS)) {
        foreach ($additionalJS as $js) {
            echo '<script src="' . htmlspecialchars($js) . '"></script>' . "\n    ";
        }
    }
    ?>
    <?php
    // Calculate JS paths
    if (!isset($basePath)) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/portal/';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        if (strpos($scriptName, '/headcount/') !== false) {
            $basePath = preg_replace('#/portal/.*$#', '', $scriptName);
            $basePath = rtrim($basePath, '/');
        } else {
            $basePath = preg_replace('#/portal/.*$#', '', $requestPath);
            $basePath = rtrim($basePath, '/');
        }
        
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
    
    if (!function_exists('buildJsPath')) {
        function buildJsPath($basePath, $filename) {
            if (empty($basePath) || $basePath === '/') {
                $jsPath = '/public/js/' . $filename;
            } else {
                $jsPath = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
                $jsPath = rtrim($jsPath, '/');
                if (substr($jsPath, -7) === '/public') {
                    $jsPath .= '/js/' . $filename;
                } else {
                    $jsPath .= '/public/js/' . $filename;
                }
            }
            $jsPath = preg_replace('#/+#', '/', $jsPath);
            if ($jsPath[0] !== '/') {
                $jsPath = '/' . $jsPath;
            }
            return $jsPath;
        }
    }

    $swRegisterUrl = isset($swUrl) ? $swUrl : (($basePath === '' || $basePath === '/' ? '' : $basePath) . '/sw.js');
    $swRegisterUrl = preg_replace('#/+#', '/', $swRegisterUrl);
    if ($swRegisterUrl === '' || $swRegisterUrl[0] !== '/') {
        $swRegisterUrl = '/' . ltrim((string) $swRegisterUrl, '/');
    }
    $swScope = rtrim($portalBase, '/') . '/';
    ?>
    <script src="<?= e(buildJsPath($basePath, 'modal.js')) ?>"></script>
    <script src="<?= e(buildJsPath($basePath, 'confirm.js')) ?>"></script>
    <script src="<?= e(buildJsPath($basePath, 'modern-ui.js')) ?>"></script>
    <script src="<?= e(buildJsPath($basePath, 'portal-dates.js')) ?>"></script>
    <script>
    (function () {
        if (!('serviceWorker' in navigator)) return;
        var swUrl = <?= json_encode($swRegisterUrl) ?>;
        var scope = <?= json_encode($swScope) ?>;
        window.addEventListener('load', function () {
            navigator.serviceWorker.register(swUrl, { scope: scope }).catch(function (err) {
                // Scope may be blocked if SW path is outside portal; retry without scope.
                navigator.serviceWorker.register(swUrl).catch(function () {});
            });
        });
    })();
    </script>
</body>
</html>
