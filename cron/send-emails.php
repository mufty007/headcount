<?php

/**
 * Send Queued Emails Cron Job
 * Processes email queue and sends emails
 * Run every 5 minutes: */5 * * * *
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\EmailService;
use Headcount\Integrations\SMTP2GOService;

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = Database::getInstance($config['database']);

// Initialize email service if configured
$emailService = null;
if (!empty($config['smtp2go']['api_key'])) {
    $smtpService = new SMTP2GOService(
        $config['smtp2go']['api_key'],
        $config['smtp2go']['from_email'] ?? null,
        $config['smtp2go']['from_name'] ?? null,
        $config['smtp2go']['reply_to'] ?? null
    );
    $emailService = new EmailService($smtpService, $db, $config);
}

if (!$emailService) {
    echo "Email service not configured\n";
    exit(1);
}

// Get pending emails from queue (if email_queue table exists)
$sql = "SELECT * FROM email_queue 
        WHERE status = 'pending' 
        AND attempts < 3
        ORDER BY created_at ASC 
        LIMIT 50";

try {
    $emails = $db->query($sql);
} catch (Exception $e) {
    // Table might not exist yet
    echo "Email queue table not found\n";
    exit(0);
}

$sent = 0;
$failed = 0;

foreach ($emails as $email) {
    try {
        $emailService->sendEmail(
            $email['to_email'],
            $email['subject'],
            $email['body'],
            $email['template'] ?? null
        );

        // Mark as sent
        $db->execute(
            "UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = :id",
            ['id' => $email['id']]
        );
        $sent++;
    } catch (Exception $e) {
        // Increment attempts
        $attempts = $email['attempts'] + 1;
        $status = $attempts >= 3 ? 'failed' : 'pending';
        
        $db->execute(
            "UPDATE email_queue SET attempts = :attempts, status = :status, error_message = :error WHERE id = :id",
            [
                'id' => $email['id'],
                'attempts' => $attempts,
                'status' => $status,
                'error' => $e->getMessage()
            ]
        );
        $failed++;
        error_log("Failed to send email {$email['id']}: " . $e->getMessage());
    }
}

echo "Email queue processed: {$sent} sent, {$failed} failed\n";
