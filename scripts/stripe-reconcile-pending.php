<?php

/**
 * Reconcile pending Stripe Checkout sessions across all events (missed webhooks).
 * Run from project root on a schedule (e.g. every 2 hours or nightly).
 *
 *   php scripts/stripe-reconcile-pending.php
 *
 * Output: one JSON line to stdout; exit code 0 on completion (even if nothing updated).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "CLI only. Example: php scripts/stripe-reconcile-pending.php\n";
    exit(1);
}

$base = dirname(__DIR__);
require_once $base . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\PortalPaymentService;

$config = require $base . '/config/config.php';
Database::getInstance($config['database']);

try {
    $pps = new PortalPaymentService();
    $result = $pps->reconcilePendingPaymentsGlobally();
    echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

exit(0);
