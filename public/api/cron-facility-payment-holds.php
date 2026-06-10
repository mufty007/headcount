<?php
/**
 * Release expired facility payment authorizations (~7 days) and cancel bookings.
 * Schedule via server cron, e.g. daily: php public/api/cron-facility-payment-holds.php
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
use Headcount\Services\FacilityPaymentService;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Config missing\n");
    exit(1);
}
$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$paySvc = new FacilityPaymentService();
$total = 0;
$orgs = Database::getInstance()->query('SELECT id FROM organizations');
foreach ($orgs as $org) {
    $res = $paySvc->processExpiredHolds((int) $org['id']);
    $total += (int) ($res['processed'] ?? 0);
}

echo json_encode(['success' => true, 'processed' => $total]) . "\n";
