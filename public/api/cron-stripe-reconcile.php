<?php
/**
 * HTTP entry for scheduled Stripe pending-checkout reconciliation (all orgs).
 *
 * GET .../public/api/cron-stripe-reconcile.php?key=YOUR_SECRET
 * Header (optional): X-Cron-Secret: YOUR_SECRET
 *
 * Legacy URL supported after folder restructure. Prefer cron-run.php?job=stripe-reconcile
 */
require_once __DIR__ . '/includes/cron-http-bootstrap.php';

use Headcount\Helpers\Database;
use Headcount\Services\PortalPaymentService;

try {
    Database::getInstance($config['database']);
    $pps = new PortalPaymentService();
    $result = $pps->reconcilePendingPaymentsGlobally();
    jsonResponse($result);
} catch (\Throwable $e) {
    error_log('cron-stripe-reconcile: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
