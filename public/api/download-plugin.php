<?php
/**
 * WordPress Plugin Download Endpoint
 * Creates a ZIP file of the WordPress plugin for download
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Auth;

// Initialize system if not already done
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(dirname(__DIR__)));
}
if (!defined('CONFIG_PATH')) {
    define('CONFIG_PATH', BASE_PATH . '/config');
}
if (!defined('SRC_PATH')) {
    define('SRC_PATH', BASE_PATH . '/src');
}

// Load configuration
$configFile = CONFIG_PATH . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    die('Configuration file not found.');
}

$config = require $configFile;

// Initialize database
try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    die('System initialization failed.');
}

// Require authentication
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    die('Authentication required.');
}

// Check if user is admin
if (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    http_response_code(403);
    die('Admin access required.');
}

// Create temporary directory for plugin files
$tempDir = sys_get_temp_dir() . '/headcount-plugin-' . time();
if (!mkdir($tempDir, 0755, true)) {
    http_response_code(500);
    die('Failed to create temporary directory.');
}

$pluginDir = $tempDir . '/headcount';
$includesDir = $pluginDir . '/includes';
$templatesDir = $pluginDir . '/templates';
$assetsDir = $pluginDir . '/assets';
$cssDir = $assetsDir . '/css';
$jsDir = $assetsDir . '/js';

// Create directory structure
$dirs = [$pluginDir, $includesDir, $templatesDir, $assetsDir, $cssDir, $jsDir];
foreach ($dirs as $dir) {
    if (!mkdir($dir, 0755, true)) {
        http_response_code(500);
        die('Failed to create directory structure.');
    }
}

// Define plugin file paths
$pluginBasePath = BASE_PATH . '/headcount-wordpress-plugin';
$pluginFiles = [
    'headcount.php' => $pluginBasePath . '/headcount.php',
    'readme.txt' => $pluginBasePath . '/readme.txt',
    'includes/api-client.php' => $pluginBasePath . '/includes/api-client.php',
    'includes/shortcodes.php' => $pluginBasePath . '/includes/shortcodes.php',
    'includes/admin-settings.php' => $pluginBasePath . '/includes/admin-settings.php',
    'templates/events-list.php' => $pluginBasePath . '/templates/events-list.php',
    'templates/events-grid.php' => $pluginBasePath . '/templates/events-grid.php',
    'templates/event-single.php' => $pluginBasePath . '/templates/event-single.php',
    'templates/event-calendar.php' => $pluginBasePath . '/templates/event-calendar.php',
    'assets/css/headcount.css' => $pluginBasePath . '/assets/css/headcount.css',
    'assets/js/headcount.js' => $pluginBasePath . '/assets/js/headcount.js',
];

// Copy plugin files
foreach ($pluginFiles as $relativePath => $sourcePath) {
    if (!file_exists($sourcePath)) {
        // Cleanup and exit if file doesn't exist
        array_map('unlink', glob($tempDir . '/headcount/**/*'));
        array_map('rmdir', array_reverse($dirs));
        rmdir($tempDir);
        http_response_code(500);
        die('Plugin file not found: ' . $relativePath);
    }
    
    $destPath = $pluginDir . '/' . $relativePath;
    $destDir = dirname($destPath);
    
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    
    if (!copy($sourcePath, $destPath)) {
        // Cleanup and exit if copy fails
        array_map('unlink', glob($tempDir . '/headcount/**/*'));
        array_map('rmdir', array_reverse($dirs));
        rmdir($tempDir);
        http_response_code(500);
        die('Failed to copy plugin file: ' . $relativePath);
    }
}

// Create ZIP file
$zipFile = $tempDir . '/headcount.zip';

if (!class_exists('ZipArchive')) {
    // Cleanup
    array_map('unlink', glob($tempDir . '/headcount/**/*'));
    array_map('rmdir', array_reverse($dirs));
    rmdir($tempDir);
    http_response_code(500);
    die('ZipArchive class not available. Please install php-zip extension.');
}

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    // Cleanup
    array_map('unlink', glob($tempDir . '/headcount/**/*'));
    array_map('rmdir', array_reverse($dirs));
    rmdir($tempDir);
    http_response_code(500);
    die('Failed to create ZIP file.');
}

// Add files to ZIP
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        $relativePath = 'headcount/' . substr($filePath, strlen($pluginDir) + 1);
        $zip->addFile($filePath, $relativePath);
    }
}

$zip->close();

// Send ZIP file
if (!file_exists($zipFile)) {
    http_response_code(500);
    die('ZIP file was not created.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="headcount-wordpress-plugin.zip"');
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($zipFile);

// Cleanup
unlink($zipFile);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($pluginDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($files as $file) {
    if ($file->isDir()) {
        rmdir($file->getRealPath());
    } else {
        unlink($file->getRealPath());
    }
}
rmdir($pluginDir);
rmdir($tempDir);

exit;
