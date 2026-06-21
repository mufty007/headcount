<?php
/**
 * Shared bootstrap for HTTP cron endpoints (Hostinger / URL-triggered jobs).
 */
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}

require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Configuration not found'], 500);
    exit;
}

$config = require $configFile;
headcount_cron_verify_http_access($config);

if (!defined('HEADCOUNT_CRON_HTTP_INCLUDE')) {
    define('HEADCOUNT_CRON_HTTP_INCLUDE', true);
}

if (ob_get_level() > 0) {
    ob_end_clean();
}
