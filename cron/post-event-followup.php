<?php

/**
 * Post-Event Follow-up
 * Sends thank-you emails to attendees after events conclude
 * Should be run via cron job
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\EmailService;

// Load config
$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    error_log("Post-event follow-up: Configuration not found");
    headcount_cron_exit(1);
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Post-event follow-up: Database initialization failed: " . $e->getMessage());
    headcount_cron_exit(1);
}

// Initialize email service
if (empty($config['smtp2go']['api_key'])) {
    error_log("Post-event follow-up: Email service not configured");
    headcount_cron_exit(1);
}

$emailService = new EmailService($config['smtp2go']);
$db = Database::getInstance();

// Get events that ended in the last 24 hours (or recently)
// We look for published events where the date is yesterday or today but already passed
$events = $db->query(
    "SELECT * FROM events 
     WHERE status = 'published' 
     AND event_date <= CURDATE()
     AND event_date >= DATE_SUB(CURDATE(), INTERVAL 2 DAY)"
);

$sentTotal = 0;
$errorTotal = 0;

foreach ($events as $event) {
    // Check if event has actually ended (considering start_time/end_time if available)
    $eventEndDateTime = strtotime($event['event_date'] . ' ' . ($event['end_time'] ?? '23:59:59'));
    if ($eventEndDateTime > time()) {
        continue; // Event hasn't ended yet
    }

    // Get the follow_up template for this organization
    $template = $db->queryOne(
        "SELECT * FROM email_templates 
         WHERE organization_id = ? AND template_type = 'follow_up'",
        [$event['organization_id']]
    );

    if (!$template) {
        // Use default if no custom template exists
        $template = $db->queryOne(
            "SELECT * FROM email_templates 
             WHERE is_default = 1 AND template_type = 'follow_up'"
        );
    }

    if (!$template) {
        error_log("Post-event follow-up: No follow_up template found for event ID " . $event['id']);
        continue;
    }

    // Get attendees (RSVP 'yes') who haven't received a follow-up for this event yet
    $attendees = $db->query(
        "SELECT u.id, u.first_name, u.last_name, u.email 
         FROM rsvps r
         JOIN users u ON r.user_id = u.id
         WHERE r.event_id = ? AND r.status = 'yes' 
         AND u.status = 'active' AND u.email IS NOT NULL AND u.email != ''
         AND NOT EXISTS (
             SELECT 1 FROM email_logs 
             WHERE event_id = ? AND user_id = u.id AND email_type = 'follow_up' AND status = 'sent'
         )",
        [$event['id'], $event['id']]
    );

    if (empty($attendees)) {
        continue;
    }

    // Prepare for bulk sending
    $recipients = [];
    foreach ($attendees as $attendee) {
        $recipients[] = [
            'id' => $attendee['id'],
            'email' => $attendee['email'],
            'first_name' => $attendee['first_name'],
            'last_name' => $attendee['last_name'],
            'event_name' => $event['title'],
            'event_date' => date('F j, Y', strtotime($event['event_date'])),
            'organization_name' => 'Our Organization' // Should ideally fetch from organization settings
        ];
    }

    try {
        $results = $emailService->sendBulk(
            $recipients,
            $template['subject'],
            $template['body_html'],
            $event['organization_id'],
            [
                'email_type' => 'follow_up',
                'event_id' => $event['id']
            ]
        );
        
        $sentTotal += $results['sent'];
        $errorTotal += $results['failed'];
        
        echo "Sent " . $results['sent'] . " follow-ups for event: " . $event['title'] . "\n";
    } catch (\Exception $e) {
        error_log("Post-event follow-up error for event " . $event['id'] . ": " . $e->getMessage());
        $errorTotal += count($recipients);
    }
}

echo "Total post-event follow-ups sent: {$sentTotal}, Errors: {$errorTotal}\n";
headcount_cron_exit(0);
