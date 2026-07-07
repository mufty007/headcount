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
use Headcount\Helpers\NotificationHelper;
use Headcount\Helpers\Security;
use Headcount\Services\EmailService;
use Headcount\Services\EventReminderService;

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

$events = $db->query(
    "SELECT e.*, o.name AS org_name, o.timezone AS org_timezone
     FROM events e
     JOIN organizations o ON e.organization_id = o.id
     WHERE e.status = 'published'
       AND e.event_date >= CURDATE()
       AND e.event_date <= CURDATE() + INTERVAL 8 DAY"
);
if (!is_array($events)) {
    $events = [];
}

foreach ($events as $event) {
    $orgId = (int) $event['organization_id'];

    $reminderType = EventReminderService::resolveAutomatedReminderType($event, false);
    if ($reminderType === null || !in_array($reminderType, ['1week', '1day'], true)) {
        continue;
    }

    if (EventReminderService::hasSentReminder($db, (int) $event['id'], $reminderType)) {
        continue;
    }

    // Respect org automation settings (Admin > Email > Automation)
    $milestoneTemplateOverrides = ['1week' => null, '1day' => null, '2hours' => null];
    try {
        $orgFlags = $db->queryOne(
            "SELECT email_reminders_enabled, reminder_1week, reminder_1day, reminder_milestone_templates, reminder_custom_schedule FROM organizations WHERE id = ?",
            [$orgId]
        );
        $milestoneTemplateOverrides = EventReminderService::resolveMilestoneTemplates(
            $orgFlags['reminder_milestone_templates'] ?? null,
            $orgFlags['reminder_custom_schedule'] ?? null
        );
        if ($orgFlags && empty($orgFlags['email_reminders_enabled'])) {
            continue;
        }
        if ($orgFlags && $reminderType === '1week' && empty($orgFlags['reminder_1week'])) {
            continue;
        }
        if ($orgFlags && $reminderType === '1day' && empty($orgFlags['reminder_1day'])) {
            continue;
        }
    } catch (\Exception $e) {
        // Columns may not exist yet; proceed
    }

    $emailConfig = $getOrgEmailConfig($orgId);
    if (!$emailConfig) {
        $emailConfig = !empty($config['smtp2go']['api_key']) ? $config['smtp2go'] : null;
    }
    if (!$emailConfig) {
        error_log("Reminders cron: No email config for org {$orgId}, event {$event['id']}");
        continue;
    }
    $templateIdOverride = $milestoneTemplateOverrides[$reminderType] ?? null;
    $resolvedTemplate = EventReminderService::resolveReminderTemplate($db, $orgId, $reminderType, $templateIdOverride);
    $options = [
        'template_type' => $resolvedTemplate['template_type'],
        'subject' => $resolvedTemplate['subject'],
        'body' => $resolvedTemplate['body_html'],
    ];
    if ($options['body'] === null) {
        unset($options['body']);
    }

    $rsvps = EventReminderService::getRsvpYesRecipients($db, (int) $event['id'], true);
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
        EventReminderService::markReminderSent($db, (int) $event['id'], $reminderType);
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
