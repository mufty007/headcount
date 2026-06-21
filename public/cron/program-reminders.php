<?php
/**
 * Daily cron: send 24h reminders for program sessions.
 * Run via server cron, e.g.:
 *   php /path/to/Headcount/public/cron/program-reminders.php
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

Database::getInstance($config['database']);
$db = Database::getInstance();

if (!$db->hasColumn('program_sessions', 'id')) {
    echo json_encode(['ok' => true, 'message' => 'Programs not installed']);
    headcount_cron_exit(0);
}

$smtp = $config['smtp2go'] ?? [];
if (empty($smtp['api_key'])) {
    echo json_encode(['ok' => false, 'message' => 'SMTP not configured']);
    headcount_cron_exit(0);
}

$tomorrow = date('Y-m-d', strtotime('+1 day'));
$sessions = $db->query(
    "SELECT s.id, s.program_id, p.organization_id, p.title
     FROM program_sessions s
     INNER JOIN programs p ON p.id = s.program_id
     WHERE s.session_date = :d AND s.status = 'scheduled' AND p.status = 'published'",
    ['d' => $tomorrow]
);

$email = new EmailService($smtp);
$sent = 0;
$errors = [];

foreach ($sessions as $row) {
    try {
        $r = $email->sendProgramSessionReminderEmail((int) $row['id'], (int) $row['organization_id'], []);
        $sent += (int) ($r['sent'] ?? 0);
    } catch (\Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$out = ['ok' => true, 'sessions' => count($sessions), 'emails_sent' => $sent, 'errors' => $errors];
if (php_sapi_name() === 'cli') {
    echo json_encode($out) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($out);
}
