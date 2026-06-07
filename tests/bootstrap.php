<?php

/**
 * PHPUnit Bootstrap
 * Sets up test environment
 */

// Define test environment
define('TESTING', true);
define('BASE_PATH', dirname(__DIR__));
define('PUBLIC_PATH', BASE_PATH . '/public');
define('SRC_PATH', BASE_PATH . '/src');
define('CONFIG_PATH', BASE_PATH . '/config');

// Load Composer autoloader
require_once BASE_PATH . '/vendor/autoload.php';

// Set error reporting for tests
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Load test configuration
$testConfigFile = BASE_PATH . '/config/config-test.php';
if (file_exists($testConfigFile)) {
    $GLOBALS['test_config'] = require $testConfigFile;
} else {
    // Use sample config for tests
    $GLOBALS['test_config'] = require BASE_PATH . '/config/config-sample.php';
    // Override with test database
    $GLOBALS['test_config']['database']['database'] = 'headcount_events_test';
}
