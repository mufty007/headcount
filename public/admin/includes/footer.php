<?php
// Ensure variables are available (in case footer is included without header)
if (!isset($APP_NAME)) {
    $APP_NAME = 'Headcount';
}
if (!isset($user)) {
    $user = [
        'name' => $_SESSION['user_name'] ?? $_SESSION['name'] ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin',
        'email' => $_SESSION['user_email'] ?? $_SESSION['email'] ?? 'admin@headcount.local'
    ];
}
if (!isset($adminBase) || !isset($navUrls) || !isset($basePath)) {
    require_once __DIR__ . '/layout-vars.php';
}
?>
                </div>
            </main>
            
            <!-- Modern Footer - Removed -->
            <!--
            <footer class="bg-white border-t border-gray-200 py-4 px-8 shrink-0 w-full mt-auto">
                <div class="flex flex-col md:flex-row justify-between items-center text-xs text-gray-500 w-full">
                    <div>
                        &copy; <?= date('Y') ?> <strong><?= e($APP_NAME) ?></strong>. All rights reserved. • Version 1.1.0
                    </div>
                    <div class="flex items-center space-x-4 mt-2 md:mt-0">
                        <span class="flex items-center space-x-1">
                            <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                            <span>System Operational</span>
                        </span>
                        <a href="#" class="hover:text-indigo-600 transition-colors">Documentation</a>
                        <a href="#" class="hover:text-indigo-600 transition-colors">Support</a>
                    </div>
                </div>
            </footer>
            -->
        </div>
    </div>

    <!-- Modals & Overlays -->
    <div id="modal-container"></div>
    
    <!-- Scripts -->
    <?php
    if (isset($additionalJS) && is_array($additionalJS)) {
        foreach ($additionalJS as $js) {
            if (is_string($js) && stripos($js, 'quill') !== false) {
                continue;
            }
            echo '<script src="' . htmlspecialchars($js) . '"></script>' . "\n    ";
        }
    }
    ?>
    <?php
    // Calculate JS paths - ensure absolute paths from document root
    if (!isset($basePath)) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/admin/';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        
        // Try to determine base path from script name first (more reliable)
        if (strpos($scriptName, '/headcount/') !== false) {
            // Extract base path from script name
            $basePath = preg_replace('#/admin/.*$#', '', $scriptName);
            $basePath = rtrim($basePath, '/');
        } else {
            // Fallback: extract from request URI
            $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
            $basePath = rtrim($basePath, '/');
        }
        
        // If base path is empty or just '/', check if we're in a subdirectory
        if (empty($basePath) || $basePath === '/') {
            // Check if DOCUMENT_ROOT suggests we're in a subdirectory
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $scriptDir = dirname($_SERVER['SCRIPT_FILENAME'] ?? '');
            
            // If script is in a subdirectory relative to document root
            if (strpos($scriptDir, $docRoot) === 0) {
                $relativePath = str_replace($docRoot, '', dirname($scriptDir));
                if (!empty($relativePath) && $relativePath !== '/') {
                    $basePath = str_replace('\\', '/', $relativePath);
                    $basePath = rtrim($basePath, '/');
                }
            }
        }
        
        // Ensure basePath starts with / if it's not empty
        if (!empty($basePath) && $basePath[0] !== '/') {
            $basePath = '/' . $basePath;
        }
    }
    
    // Helper function to build JS path - always returns absolute path
    function buildJsPath($basePath, $filename) {
        if (empty($basePath) || $basePath === '/') {
            $jsPath = '/public/js/' . $filename;
        } else {
            // Ensure basePath starts with /
            $jsPath = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
            $jsPath = rtrim($jsPath, '/') . '/public/js/' . $filename;
        }
        // Remove double slashes and ensure it starts with /
        $jsPath = preg_replace('#/+#', '/', $jsPath);
        if ($jsPath[0] !== '/') {
            $jsPath = '/' . $jsPath;
        }
        return $jsPath;
    }
    ?>
    <script src="<?= e(buildJsPath($basePath, 'modal.js')) ?>"></script>
    <script src="<?= e(buildJsPath($basePath, 'confirm.js')) ?>"></script>
    <script src="<?= e(buildJsPath($basePath, 'modern-ui.js')) ?>"></script>
    <?php
    $stripeReconcileApi = '';
    if (!empty($basePath)) {
        $bp = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
        $stripeReconcileApi = preg_replace('#/+#', '/', rtrim($bp, '/') . '/public/api/payment-transfers.php');
    }
    ?>
    <script>
    (function () {
        var api = <?= json_encode($stripeReconcileApi) ?>;
        if (!api || typeof localStorage === 'undefined') return;
        var KEY = 'headcount_stripe_admin_org_reconcile_ms';
        var intervalMs = 3 * 60 * 60 * 1000;
        var last = parseInt(localStorage.getItem(KEY) || '0', 10);
        if (Date.now() - last < intervalMs) return;
        setTimeout(function () {
            fetch(api, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'reconcile_organization' })
            }).then(function () { localStorage.setItem(KEY, String(Date.now())); })
              .catch(function () { localStorage.setItem(KEY, String(Date.now())); });
        }, 6000);
    })();
    </script>
</body>
</html>
