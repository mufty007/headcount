<?php

/**
 * Admin Event Reminders Cron
 * Sends 1-week and 1-day reminders to members who RSVP'd "yes", using each
 * organization's email templates (Admin > Email > Templates).
 *
 * Schedule: run daily (e.g. 9:00 AM): 0 9 * * * php /path/to/cron/reminders.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\NotificationHelper;
use Headcount\Services\EmailService;

$config = require __DIR__ . '/../config/config.php';

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Reminders cron: Database initialization failed: " . $e->getMessage());
    exit(1);
}

$db = Database::getInstance();

// Build org SMTP config
$getOrgEmailConfig = function ($organizationId) use ($db, $config) {
    $org = $db->queryOne(
        "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
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
    ];
};

// Events happening in 1 day or 7 days (reminders table prevents duplicate sends)
$events = $db->query(
    "SELECT e.*, o.name AS org_name,
            CASE WHEN e.event_date = CURDATE() + INTERVAL 7 DAY THEN '1week' WHEN e.event_date = CURDATE() + INTERVAL 1 DAY THEN '1day' END AS reminder_type
     FROM events e
     JOIN organizations o ON e.organization_id = o.id
     WHERE e.status = 'published'
       AND e.event_date >= CURDATE()
       AND (e.event_date = CURDATE() + INTERVAL 7 DAY OR e.event_date = CURDATE() + INTERVAL 1 DAY)
       AND NOT EXISTS (SELECT 1 FROM reminders r WHERE r.event_id = e.id AND r.reminder_type = (CASE WHEN e.event_date = CURDATE() + INTERVAL 7 DAY THEN '1week' ELSE '1day' END) AND r.status = 'sent')"
);
if (!is_array($events)) {
    $events = [];
}

foreach ($events as $event) {
    $orgId = (int) $event['organization_id'];

    // Respect org automation settings (Admin > Email > Automation)
    try {
        $orgFlags = $db->queryOne(
            "SELECT email_reminders_enabled, reminder_1week, reminder_1day FROM organizations WHERE id = ?",
            [$orgId]
        );
        if ($orgFlags && empty($orgFlags['email_reminders_enabled'])) {
            continue;
        }
        $reminderType = $event['reminder_type'] ?? '1day';
        if ($orgFlags && $reminderType === '1week' && empty($orgFlags['reminder_1week'])) {
            continue;
        }
        if ($orgFlags && $reminderType === '1day' && empty($orgFlags['reminder_1day'])) {
            continue;
        }
    } catch (\Exception $e) {
        // Columns may not exist yet; proceed
    }

    $reminderType = $event['reminder_type'] ?? '1day';
    $emailConfig = $getOrgEmailConfig($orgId);
    if (!$emailConfig) {
        $emailConfig = !empty($config['smtp2go']['api_key']) ? $config['smtp2go'] : null;
    }
    if (!$emailConfig) {
        error_log("Reminders cron: No email config for org {$orgId}, event {$event['id']}");
        continue;
    }
    $templateType = ($reminderType === '1week') ? 'reminder_1week' : 'reminder_1day';

    $template = $db->queryOne(
        "SELECT subject, body_html FROM email_templates WHERE organization_id = ? AND template_type = ? LIMIT 1",
        [$orgId, $templateType]
    );
    if (!$template) {
        $template = $db->queryOne(
            "SELECT subject, body_html FROM email_templates WHERE is_default = 1 AND template_type = ? LIMIT 1",
            [$templateType]
        );
    }
    $options = [
        'template_type' => $templateType,
        'subject' => $template['subject'] ?? 'Reminder: {event_name}',
        'body' => $template['body_html'] ?? null,
    ];
    if ($options['body'] === null) {
        unset($options['body']);
    }

    $rsvps = $db->query(
        "SELECT u.id AS user_id
         FROM rsvps r
         JOIN users u ON r.user_id = u.id
         WHERE r.event_id = ?
           AND r.status = 'yes'
           AND u.email IS NOT NULL
           AND u.email != ''",
        [$event['id']]
    );
    $recipientIds = array_column($rsvps, 'user_id');
    if (empty($recipientIds)) {
        continue;
    }

    try {
        $emailService = new EmailService($emailConfig);
        $results = $emailService->sendEventReminder($event['id'], $orgId, $recipientIds, $options);
        $sent = $results['sent'] ?? 0;
    } catch (\Exception $e) {
        error_log("Reminders cron: Failed for event {$event['id']}: " . $e->getMessage());
        continue;
    }

    if ($sent > 0) {
        try {
            $db->insert('reminders', [
                'event_id' => $event['id'],
                'reminder_type' => $reminderType,
                'scheduled_for' => date('Y-m-d H:i:s'),
                'status' => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            // reminders table may not exist
        }
        NotificationHelper::eventReminder(
            $orgId,
            $event['id'],
            $event['title'],
            $reminderType
        );
        echo "Sent {$sent} reminders for event: {$event['title']}\n";
    }
}

echo "Reminder cron job completed\n";
