<?php
// Calculate base path once (project root)
$basePath = dirname(dirname(__DIR__));

// Ensure APP_NAME is available
if (!defined('APP_NAME')) {
    $configPath = $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
    
    if (file_exists($configPath)) {
        $config = require $configPath;
        $appName = $config['app']['name'] ?? 'IMCA';
        if (stripos((string) $appName, 'headcount') !== false) {
            $appName = 'IMCA';
        }
        define('APP_NAME', $appName);
    } else {
        // Fallback if config file doesn't exist
        define('APP_NAME', 'IMCA');
    }
}

// Load helpers if not already loaded
if (!function_exists('e')) {
    $helpersPath = $basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helpers.php';
    
    if (file_exists($helpersPath)) {
        require_once $helpersPath;
    }
}

// Public legal pages live under /public/
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$webBase = preg_replace('#/(?:public|portal|admin)/.*$#i', '', (string) $requestPath);
$webBase = rtrim((string) $webBase, '/');
$publicLegalBase = ($webBase === '' ? '' : $webBase) . '/public';
$privacyUrl = $publicLegalBase . '/privacy.php';
$termsUrl = $publicLegalBase . '/terms.php';
$supportUrl = $publicLegalBase . '/support.php';
?>
    <!-- Public Footer (for login, etc.) -->
    <footer class="mt-auto py-6">
        <div class="text-center text-sm text-gray-600">
            <p class="mb-2">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
            <div class="flex justify-center items-center space-x-4">
                <a href="<?= e($privacyUrl) ?>" class="hover:text-blue-600">Privacy Policy</a>
                <span>•</span>
                <a href="<?= e($termsUrl) ?>" class="hover:text-blue-600">Terms of Service</a>
                <span>•</span>
                <a href="<?= e($supportUrl) ?>" class="hover:text-blue-600">Support</a>
            </div>
        </div>
    </footer>

</body>
</html>
