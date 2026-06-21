<?php
/**
 * HTTP entry: release expired facility payment holds.
 *
 * GET .../public/api/cron-facility-payment-holds.php?key=YOUR_SECRET
 */
require_once __DIR__ . '/includes/cron-http-bootstrap.php';

use Headcount\Helpers\Database;
use Headcount\Services\FacilityPaymentService;

Database::getInstance($config['database']);
$paySvc = new FacilityPaymentService();
$total = 0;
$orgs = Database::getInstance()->query('SELECT id FROM organizations');
foreach ($orgs as $org) {
    $res = $paySvc->processExpiredHolds((int) $org['id']);
    $total += (int) ($res['processed'] ?? 0);
}

jsonResponse(['success' => true, 'processed' => $total]);
