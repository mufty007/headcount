<?php
// Calculate base path once (project root)
$basePath = dirname(dirname(__DIR__));

// Ensure APP_NAME is available
if (!defined('APP_NAME')) {
    $configPath = $basePath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.php';
    
    if (file_exists($configPath)) {
        $config = require $configPath;
        $appName = $config['app']['name'] ?? 'Headcount';
        define('APP_NAME', $appName);
    } else {
        // Fallback if config file doesn't exist
        define('APP_NAME', 'Headcount');
    }
}

// Load helpers if not already loaded
if (!function_exists('e')) {
    $helpersPath = $basePath . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'helpers.php';
    
    if (file_exists($helpersPath)) {
        require_once $helpersPath;
    }
}
?>
    <!-- Public Footer (for login, etc.) -->
    <footer class="mt-auto py-6">
        <div class="text-center text-sm text-gray-600">
            <p class="mb-2">&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
            <div class="flex justify-center items-center space-x-4">
                <a href="#" class="hover:text-blue-600">Privacy Policy</a>
                <span>•</span>
                <a href="#" class="hover:text-blue-600">Terms of Service</a>
                <span>•</span>
                <a href="mailto:support@headcount.app" class="hover:text-blue-600">Support</a>
            </div>
        </div>
    </footer>

</body>
</html>
