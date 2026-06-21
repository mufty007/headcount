<?php
/**
 * HTTP cron dispatcher — one URL for all scheduled jobs (Hostinger-friendly).
 *
 * Examples (docroot = project root, site at events.example.org):
 *   GET https://events.example.org/public/api/cron-run.php?job=event-feedback&key=YOUR_SECRET
 *   GET https://events.example.org/public/api/cron-run.php?job=stripe-reconcile&key=YOUR_SECRET
 *
 * If docroot is public/, drop /public from the path:
 *   GET https://events.example.org/api/cron-run.php?job=event-feedback&key=YOUR_SECRET
 *
 * Set cron.http_secret in config/config.php (or cron.stripe_reconcile_secret for legacy).
 */
require_once __DIR__ . '/includes/cron-http-bootstrap.php';

use Headcount\Helpers\Database;
use Headcount\Services\FacilityPaymentService;
use Headcount\Services\PortalPaymentService;

$job = strtolower(trim((string) ($_GET['job'] ?? '')));
if ($job === '') {
    jsonResponse([
        'success' => false,
        'message' => 'Missing job parameter',
        'jobs' => array_keys(headcount_cron_http_jobs()),
    ], 400);
    exit;
}

$jobs = headcount_cron_http_jobs();
if (!isset($jobs[$job])) {
    jsonResponse([
        'success' => false,
        'message' => 'Unknown job: ' . $job,
        'jobs' => array_keys($jobs),
    ], 404);
    exit;
}

try {
    $result = ($jobs[$job])($config);
    if (is_string($result)) {
        jsonResponse(['success' => true, 'job' => $job, 'output' => $result]);
        exit;
    }
    if (is_array($result)) {
        jsonResponse(array_merge(['success' => true, 'job' => $job], $result));
        exit;
    }
    jsonResponse(['success' => true, 'job' => $job]);
} catch (\Throwable $e) {
    error_log('cron-run.php [' . $job . ']: ' . $e->getMessage());
    jsonResponse(['success' => false, 'job' => $job, 'message' => $e->getMessage()], 500);
}

/**
 * @return array<string, callable(array): array<string, mixed>|string>
 */
function headcount_cron_http_jobs(): array
{
    $root = HC_PROJECT_ROOT;

    return [
        'stripe-reconcile' => static function (array $config): array {
            Database::getInstance($config['database']);
            $pps = new PortalPaymentService();

            return $pps->reconcilePendingPaymentsGlobally();
        },
        'event-feedback' => static function () use ($root): string {
            return headcount_cron_run_script($root . '/cron/send-event-feedback.php');
        },
        'portal-reminders' => static function () use ($root): string {
            return headcount_cron_run_script($root . '/cron/portal-reminders.php');
        },
        'post-event-followup' => static function () use ($root): string {
            return headcount_cron_run_script($root . '/cron/post-event-followup.php');
        },
        'send-campaigns' => static function () use ($root): string {
            return headcount_cron_run_script($root . '/cron/send-scheduled-campaigns.php');
        },
        'recurring-events' => static function () use ($root): string {
            return headcount_cron_run_script($root . '/cron/generate-recurring-events.php');
        },
        'facility-holds' => static function (array $config): array {
            Database::getInstance($config['database']);
            $paySvc = new FacilityPaymentService();
            $total = 0;
            $orgs = Database::getInstance()->query('SELECT id FROM organizations');
            foreach ($orgs as $org) {
                $res = $paySvc->processExpiredHolds((int) $org['id']);
                $total += (int) ($res['processed'] ?? 0);
            }

            return ['processed' => $total];
        },
        'program-reminders' => static function () use ($root): string {
            return headcount_cron_run_script($root . '/public/cron/program-reminders.php');
        },
    ];
}
