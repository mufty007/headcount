<?php
/**
 * Combined Main Calendar feed (events, program sessions, facility bookings).
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\MainCalendarService;

header('Content-Type: application/json');

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Config missing'], 500);
}
$config = require $configFile;
Database::getInstance($config['database']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = (int) AuthMiddleware::getOrganizationId();

$start = trim((string) ($_GET['start'] ?? date('Y-m-d')));
$end = trim((string) ($_GET['end'] ?? date('Y-m-d', strtotime('+30 days'))));

$svc = new MainCalendarService();
$events = $svc->getEvents($organizationId, $start, $end);
jsonResponse(['success' => true, 'events' => $events]);
