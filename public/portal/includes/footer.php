<?php
// Ensure variables are available
if (!isset($APP_NAME)) {
    $APP_NAME = 'Headcount';
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
?>
            </main>
            
            <!-- Modern Footer -->
            <footer class="bg-white border-t border-gray-200 py-4 px-4 md:px-8 shrink-0">
                <div class="flex flex-col md:flex-row justify-between items-center gap-3 text-xs text-gray-500">
                    <div class="text-center md:text-left text-[11px] md:text-xs">
                        &copy; <?= date('Y') ?> <strong><?= e($APP_NAME) ?></strong>. All rights reserved. • Version 1.1.0
                    </div>
                    <div class="flex flex-wrap items-center justify-center gap-3 md:gap-4 text-[11px] md:text-xs">
                        <span class="flex items-center gap-1.5">
                            <span class="w-2 h-2 bg-green-500 rounded-full flex-shrink-0"></span>
                            <span class="whitespace-nowrap">System Operational</span>
                        </span>
                        <a href="#" class="hover:text-indigo-600 transition-colors whitespace-nowrap">Help</a>
                        <a href="#" class="hover:text-indigo-600 transition-colors whitespace-nowrap">Support</a>
                    </div>
                </div>
            </footer>
        </div>
    </div>

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
    
    function buildJsPath($basePath, $filename) {
        if (empty($basePath) || $basePath === '/') {
            $jsPath = '/public/js/' . $filename;
        } else {
            // Normalize basePath
            $jsPath = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
            $jsPath = rtrim($jsPath, '/');
            
            // Check if basePath already ends with /public
            if (substr($jsPath, -7) === '/public') {
                // Base path already includes /public, just add /js/
                $jsPath .= '/js/' . $filename;
            } else {
                // Base path doesn't include /public, add it
                $jsPath .= '/public/js/' . $filename;
            }
        }
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
</body>
</html>
