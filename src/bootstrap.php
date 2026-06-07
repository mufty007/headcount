<?php

/**
 * Bootstrap File
 * Initializes the application
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    die('Configuration file not found. Please copy config/config-sample.php to config/config.php and configure it.');
}

$config = require $configPath;

// Set timezone
date_default_timezone_set($config['app']['timezone']);

// Set error reporting
if ($config['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Set session configuration
ini_set('session.cookie_lifetime', $config['session']['lifetime']);
ini_set('session.cookie_name', $config['session']['cookie_name']);
ini_set('session.cookie_httponly', $config['session']['cookie_httponly'] ? 1 : 0);
ini_set('session.cookie_secure', $config['session']['cookie_secure'] ? 1 : 0);
ini_set('session.cookie_samesite', $config['session']['cookie_samesite']);

// Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'Headcount\\';
    $baseDir = __DIR__ . '/';
    
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize database connection
use Headcount\Helpers\Database;
use Headcount\Helpers\Logger;

try {
    $db = new Database($config['database']);
} catch (Exception $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// Initialize logger
$logger = new Logger(
    $config['logging']['log_path'],
    $config['logging']['enabled']
);

// Store in global/registry (simple approach)
$GLOBALS['config'] = $config;
$GLOBALS['db'] = $db;
$GLOBALS['logger'] = $logger;
