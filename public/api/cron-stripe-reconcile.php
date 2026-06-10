<?php
/**
 * HTTP entry for scheduled Stripe pending-checkout reconciliation (all orgs).
 * Set config cron.stripe_reconcile_secret to a long random value, then call:
 *   GET https://your-host/.../public/api/cron-stripe-reconcile.php
 *   Header: X-Cron-Secret: YOUR_SECRET
 * Prefer CLI (scripts/stripe-reconcile-pending.php) when possible; use this for hosted cron pings.
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

use Headcount\Helpers\Database;
use Headcount\Services\PortalPaymentService;

header('Content-Type: application/json; charset=UTF-8');

try {
    $config = require HC_PROJECT_ROOT . '/config/config.php';
    $secret = trim((string) ($config['cron']['stripe_reconcile_secret'] ?? ''));
    if ($secret === '') {
        jsonResponse([
            'success' => false,
            'message' => 'HTTP cron disabled: set cron.stripe_reconcile_secret in config or use CLI scripts/stripe-reconcile-pending.php',
        ], 503);
    }
    $headerSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? '';
    if ($headerSecret === '' || !hash_equals($secret, $headerSecret)) {
        jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
    }

    Database::getInstance($config['database']);
    $pps = new PortalPaymentService();
    $result = $pps->reconcilePendingPaymentsGlobally();
    jsonResponse($result);
} catch (\Throwable $e) {
    error_log('cron-stripe-reconcile: ' . $e->getMessage());
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=UTF-8', true, 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
