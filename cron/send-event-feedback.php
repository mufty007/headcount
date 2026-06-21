<?php

/**
 * Post-Event Feedback Request
 * Sends feedback form emails to checked-in attendees one day after events end.
 *
 * Schedule: run daily (e.g. 9:00 AM): 0 9 * * * php /path/to/cron/send-event-feedback.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Services\EmailService;

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    error_log('Event feedback cron: Configuration not found');
    headcount_cron_exit(1);
}

$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log('Event feedback cron: Database initialization failed: ' . $e->getMessage());
    headcount_cron_exit(1);
}

$db = Database::getInstance();

if (!headcount_db_has_column($db, 'events', 'collect_feedback')) {
    echo "Event feedback cron: collect_feedback column missing — run migration 070.\n";
    headcount_cron_exit(0);
}

$getOrgEmailConfig = function ($organizationId) use ($db, $config) {
    $org = $db->queryOne(
        'SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to, name FROM organizations WHERE id = ?',
        [$organizationId]
    );
    if (!$org || empty($org['smtp_from_email'])) {
        return null;
    }
    $apiKey = null;
    if (!empty($org['smtp_api_key'])) {
        $apiKey = base64_decode($org['smtp_api_key'], true);
    }
    if (($apiKey === false || $apiKey === '') && !empty($org['smtp_api_key_encrypted']) && !empty($config['security']['encryption_key'])) {
        $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $config['security']['encryption_key']);
    }
    if (empty($apiKey) && !empty($config['smtp2go']['api_key'])) {
        $apiKey = $config['smtp2go']['api_key'];
    }
    if (empty($apiKey)) {
        return null;
    }
    return [
        'api_key' => $apiKey,
        'from_email' => $org['smtp_from_email'],
        'from_name' => $org['smtp_from_name'] ?? null,
        'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
        'organization_name' => $org['name'] ?? '',
    ];
};

$now = time();
$windowStart = $now - (48 * 3600);
$windowEnd = $now - (24 * 3600);

$events = $db->query(
    "SELECT e.* FROM events e
     WHERE e.status = 'published'
       AND e.collect_feedback = 1
       AND e.event_date <= CURDATE()"
);

$sentTotal = 0;
$errorTotal = 0;

foreach ($events as $event) {
    $endTime = !empty($event['end_time']) ? $event['end_time'] : '23:59:59';
    $eventEndTs = strtotime($event['event_date'] . ' ' . $endTime);
    if ($eventEndTs === false) {
        continue;
    }

    // Send when event ended between 24h and 48h ago (daily cron tolerance)
    if ($eventEndTs > $windowEnd || $eventEndTs < $windowStart) {
        continue;
    }

    $alreadySent = $db->queryOne(
        "SELECT id FROM reminders WHERE event_id = ? AND reminder_type = 'feedback_1day' AND status = 'sent' LIMIT 1",
        [$event['id']]
    );
    if ($alreadySent) {
        continue;
    }

    $orgId = (int) $event['organization_id'];
    $emailConfig = $getOrgEmailConfig($orgId);
    if (!$emailConfig && !empty($config['smtp2go']['api_key'])) {
        $emailConfig = $config['smtp2go'];
    }
    if (!$emailConfig) {
        error_log("Event feedback cron: No email config for org {$orgId}, event {$event['id']}");
        continue;
    }

    $template = $db->queryOne(
        "SELECT subject, body_html FROM email_templates WHERE organization_id = ? AND template_type = 'event_feedback' LIMIT 1",
        [$orgId]
    );
    if (!$template) {
        $template = $db->queryOne(
            "SELECT subject, body_html FROM email_templates WHERE is_default = 1 AND template_type = 'event_feedback' LIMIT 1"
        );
    }

    $subjectTpl = $template['subject'] ?? 'How was {event_name}?';
    $bodyTpl = $template['body_html'] ?? null;
    if ($bodyTpl === null || $bodyTpl === '') {
        $bodyTpl = '<h2>We value your feedback</h2><p>Hi {first_name},</p><p>Thank you for attending <strong>{event_name}</strong>! Please take a moment to share your experience.</p><p><a href="{feedback_link}" style="background-color: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; display: inline-block;">Share Feedback</a></p><p>Best regards,<br>{organization_name}</p>';
    }

    $eventDateFormatted = date('F j, Y', strtotime($event['event_date']));
    $eventTimeFormatted = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
    $orgName = $emailConfig['organization_name'] ?? 'Our Organization';

    $attendees = $db->query(
        "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email
         FROM attendance a
         JOIN users u ON u.id = a.user_id
         WHERE a.event_id = ?
           AND u.status = 'active'
           AND u.email IS NOT NULL
           AND u.email != ''
           AND NOT EXISTS (
               SELECT 1 FROM email_logs el
               WHERE el.event_id = a.event_id
                 AND el.recipient_user_id = u.id
                 AND el.email_type = 'event_feedback'
                 AND el.status = 'sent'
           )",
        [$event['id']]
    );

    if (empty($attendees)) {
        continue;
    }

    $recipients = [];
    foreach ($attendees as $attendee) {
        $recipients[] = [
            'id' => $attendee['id'],
            'email' => $attendee['email'],
            'first_name' => $attendee['first_name'],
            'last_name' => $attendee['last_name'],
            'event_name' => $event['title'],
            'event_date' => $eventDateFormatted,
            'event_time' => $eventTimeFormatted,
            'event_location' => $event['location'] ?? '',
            'location' => $event['location'] ?? '',
            'feedback_link' => headcount_event_feedback_portal_url($config, (int) $event['id'], (int) $attendee['id']),
            'organization_name' => $orgName,
        ];
    }

    try {
        $emailService = new EmailService($emailConfig);
        $results = $emailService->sendBulk(
            $recipients,
            $subjectTpl,
            $bodyTpl,
            $orgId,
            [
                'email_type' => 'event_feedback',
                'event_id' => $event['id'],
            ]
        );

        $sentTotal += $results['sent'];
        $errorTotal += $results['failed'];

        if ($results['sent'] > 0) {
            try {
                $db->insert('reminders', [
                    'event_id' => $event['id'],
                    'reminder_type' => 'feedback_1day',
                    'scheduled_for' => date('Y-m-d H:i:s'),
                    'status' => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                ]);
            } catch (\Exception $e) {
                error_log('Event feedback cron: could not record reminder for event ' . $event['id'] . ': ' . $e->getMessage());
            }
        }

        echo 'Sent ' . $results['sent'] . ' feedback requests for event: ' . $event['title'] . "\n";
    } catch (\Exception $e) {
        error_log('Event feedback cron error for event ' . $event['id'] . ': ' . $e->getMessage());
        $errorTotal += count($recipients);
    }
}

echo "Total feedback emails sent: {$sentTotal}, Errors: {$errorTotal}\n";
headcount_cron_exit(0);
