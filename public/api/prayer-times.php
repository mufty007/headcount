<?php
/**
 * Admin: preview prayer times for a date (Aladhan API) using organization city/country.
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
use Headcount\Services\PrayerTimesService;

header('Content-Type: application/json');

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    jsonResponse(['success' => false, 'message' => 'Config missing'], 500);
}
$config = require $configFile;
Database::getInstance($config['database']);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuthMiddleware::check();
AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    jsonResponse(['success' => false, 'message' => 'Invalid date'], 400);
}

$db = Database::getInstance();
$org = $db->queryOne('SELECT * FROM organizations WHERE id = ?', [$organizationId]);
if (!is_array($org)) {
    $org = [];
}
$city = trim((string) (($org['city'] ?? '') ?: ''));
$country = trim((string) (($org['country'] ?? '') ?: ''));

if ($city === '' || $country === '') {
    jsonResponse([
        'success' => false,
        'message' => 'Set organization city and country in Settings so prayer times can be calculated.',
    ], 400);
}

$timings = PrayerTimesService::timingsByCity($date, $city, $country);
if ($timings === null) {
    jsonResponse(['success' => false, 'message' => 'Could not load prayer times. Check city/country or try again later.'], 502);
}

jsonResponse([
    'success' => true,
    'date' => $date,
    'city' => $city,
    'country' => $country,
    'timezone' => $org['timezone'] ?? null,
    'timings' => $timings,
]);
