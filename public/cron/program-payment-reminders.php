<?php
/**
 * Daily cron: remind members who started paid program checkout but have not paid.
 * Run via server cron, e.g.:
 *   php /path/to/Headcount/public/cron/program-payment-reminders.php
 * Optional: set HEADCOUNT_CRON_SECRET in environment and pass ?key=...
 */
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

$configFile = dirname(__DIR__, 2) . '/config/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "No config\n");
    headcount_cron_exit(1);
}
$config = require $configFile;

$secret = getenv('HEADCOUNT_CRON_SECRET');
if ($secret && (($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '') !== $secret) && php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

use Headcount\Helpers\Database;
use Headcount\Services\EmailService;
use Headcount\Services\ProgramService;

Database::getInstance($config['database']);
$db = Database::getInstance();

if (!$db->hasColumn('program_registrations', 'payment_reminder_sent_at')) {
    echo json_encode(['ok' => true, 'message' => 'Migration 078 not applied']);
    headcount_cron_exit(0);
}

$smtp = $config['smtp2go'] ?? [];
if (empty($smtp['api_key'])) {
    echo json_encode(['ok' => false, 'message' => 'SMTP not configured']);
    headcount_cron_exit(0);
}

$svc = new ProgramService();
$email = new EmailService($smtp);
$rows = $svc->listPendingRegistrationsNeedingPaymentReminder(2);

$sent = 0;
$failed = 0;
$errors = [];

foreach ($rows as $row) {
    $registrationId = (int) ($row['registration_id'] ?? 0);
    $programId = (int) ($row['program_id'] ?? 0);
    $orgId = (int) ($row['organization_id'] ?? 0);
    if ($registrationId <= 0 || $programId <= 0 || $orgId <= 0) {
        continue;
    }
    try {
        $portalUrl = headcount_program_portal_url($config, $programId);
        $result = $email->sendProgramPaymentReminderEmail($row, $portalUrl, $orgId, []);
        $sent += (int) ($result['sent'] ?? 0);
        $failed += (int) ($result['failed'] ?? 0);
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $err) {
                $errors[] = (string) $err;
            }
        }
        if ((int) ($result['sent'] ?? 0) > 0) {
            $svc->markPaymentReminderSent($registrationId);
        }
    } catch (\Throwable $e) {
        $failed++;
        $errors[] = $e->getMessage();
    }
}

$out = ['ok' => true, 'candidates' => count($rows), 'emails_sent' => $sent, 'failed' => $failed, 'errors' => $errors];
if (php_sapi_name() === 'cli') {
    echo json_encode($out) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($out);
}
