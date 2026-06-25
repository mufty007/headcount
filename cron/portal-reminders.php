<?php

/**
 * Portal Event Reminders (Automated)
 * Sends 1-week and 1-day reminder emails to members who RSVP'd "yes",
 * using each organization's email templates (Admin > Email > Templates).
 *
 * Schedule: run daily (e.g. 8:00 AM): 0 8 * * * php /path/to/cron/portal-reminders.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Security;
use Headcount\Services\EmailService;
use Headcount\Services\EventReminderService;

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    error_log("Portal reminders: Configuration not found");
    headcount_cron_exit(1);
}

$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Portal reminders: Database initialization failed: " . $e->getMessage());
    headcount_cron_exit(1);
}

$db = Database::getInstance();

// Build org SMTP config (API key from org or global fallback)
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

// Template type in DB (email_templates) vs reminder_type in reminders table
$templateTypeByReminder = [
    '1week' => 'reminder_1week',
    '1day'  => 'reminder_1day',
    '2hours' => 'reminder_2hours',
];
// Custom reminder types use reminder_1day template as fallback
$templateTypeForCustom = 'reminder_1day';

// Coarse window; per-event org-local date determines 1week / 1day / 2hours
$events = $db->query(
    "SELECT e.*, o.timezone AS org_timezone
     FROM events e
     INNER JOIN organizations o ON o.id = e.organization_id
     WHERE e.status = 'published'
       AND e.event_date >= CURDATE()
       AND e.event_date <= CURDATE() + INTERVAL 8 DAY"
);
if (!is_array($events)) {
    $events = [];
}

$sentCount = 0;
$errorCount = 0;

foreach ($events as $event) {
    $reminderType = EventReminderService::resolveAutomatedReminderType($event, true);
    if (empty($reminderType)) {
        continue;
    }

    if (EventReminderService::hasSentReminder($db, (int) $event['id'], $reminderType)) {
        continue;
    }

    $orgId = (int) $event['organization_id'];

    // Respect org automation settings (Admin > Email > Automation)
    try {
        $orgFlags = $db->queryOne(
            "SELECT email_reminders_enabled, reminder_1week, reminder_1day, reminder_2hours FROM organizations WHERE id = ?",
            [$orgId]
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
        if ($orgFlags && $reminderType === '2hours' && empty($orgFlags['reminder_2hours'])) {
            continue;
        }
    } catch (\Exception $e) {
        // Columns may not exist yet; proceed with sending
    }

    $emailConfig = $getOrgEmailConfig($orgId);
    if (!$emailConfig) {
        if (!empty($config['smtp2go']['api_key'])) {
            $emailConfig = $config['smtp2go'];
        }
    }
    if (!$emailConfig) {
        error_log("Portal reminders: No email config for org {$orgId}, event {$event['id']}");
        continue;
    }

    $templateType = $templateTypeByReminder[$reminderType] ?? 'reminder_1day';
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
    $subjectTpl = $template['subject'] ?? 'Reminder: {event_name}';
    $bodyTpl = $template['body_html'] ?? null;
    if ($bodyTpl === null || $bodyTpl === '') {
        $bodyTpl = '<h2>Event Reminder</h2><p>Hello {first_name},</p><p>This is a reminder about your upcoming event:</p><p><strong>{event_name}</strong></p><p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p><p>We look forward to seeing you there!</p>';
    }

    $eventDateFormatted = EventReminderService::formatEventDateForEmail((string) ($event['event_date'] ?? ''));
    $eventTimeFormatted = EventReminderService::formatEventTimeForEmail($event['start_time'] ?? null);

    $rsvps = EventReminderService::getRsvpYesRecipients($db, (int) $event['id'], true);

    $eventSent = 0;
    $emailService = new EmailService($emailConfig);

    foreach ($rsvps as $rsvp) {
        $joinLink = (!empty($event['is_virtual']) && !empty($event['location'])) ? $event['location'] : '';
        $recipientData = [
            'first_name' => $rsvp['first_name'] ?? '',
            'last_name' => $rsvp['last_name'] ?? '',
            'email' => $rsvp['email'] ?? '',
            'event_name' => $event['title'] ?? '',
            'event_date' => $eventDateFormatted,
            'event_time' => $eventTimeFormatted,
            'event_location' => $event['location'] ?? '',
            'location' => $event['location'] ?? '',
            'join_link' => $joinLink,
            'event_description' => $event['description'] ?? '',
        ];

        $subject = $emailService->processTemplate($subjectTpl, $recipientData);
        $body = $emailService->processTemplate($bodyTpl, $recipientData);

        try {
            $emailService->sendEmail(
                $rsvp['email'],
                $subject,
                $body,
                $orgId,
                [
                    'template' => 'reminder_' . $reminderType,
                    'email_type' => 'reminder_' . $reminderType,
                    'event_id' => $event['id'],
                    'user_id' => $rsvp['user_id'],
                ]
            );
            $eventSent++;
            $sentCount++;
        } catch (\Exception $e) {
            error_log("Failed to send reminder to {$rsvp['email']}: " . $e->getMessage());
            $errorCount++;
        }
    }

    if ($eventSent > 0) {
        EventReminderService::markReminderSent($db, (int) $event['id'], $reminderType);
    }
}

// Custom reminder times (per-org schedule: X days or X hours before)
$customCol = $db->query("SHOW COLUMNS FROM organizations LIKE 'reminder_custom_schedule'");
if (!empty($customCol)) {
    $orgsWithCustom = $db->query(
        "SELECT id, timezone, reminder_custom_schedule FROM organizations WHERE email_reminders_enabled = 1 AND reminder_custom_schedule IS NOT NULL AND reminder_custom_schedule != '' AND reminder_custom_schedule != '[]' AND reminder_custom_schedule != 'null'"
    );
    foreach ($orgsWithCustom as $orgRow) {
        $orgId = (int) $orgRow['id'];
        $orgTz = OrgTimeZone::resolve($orgRow['timezone'] ?? null);
        $orgToday = OrgTimeZone::todayYmd($orgTz);
        $decoded = json_decode($orgRow['reminder_custom_schedule'], true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $entry) {
            $value = isset($entry['value']) ? (int) $entry['value'] : 0;
            $unit = isset($entry['unit']) ? $entry['unit'] : '';
            if ($value < 1) {
                continue;
            }
            if ($unit === 'days') {
                $reminderType = 'custom_days_' . $value;
                $targetDate = OrgTimeZone::addDaysYmd($orgToday, $value, $orgTz);
                $customEvents = $db->query(
                    "SELECT e.*, ? AS org_timezone FROM events e
                     WHERE e.organization_id = ? AND e.status = 'published' AND e.event_date = ?",
                    [$orgTz, $orgId, $targetDate]
                );
            } elseif ($unit === 'hours') {
                $reminderType = 'custom_hours_' . $value;
                $customEvents = $db->query(
                    "SELECT * FROM events WHERE organization_id = ? AND status = 'published' AND event_date >= CURDATE()
                     AND CONCAT(event_date, ' ', COALESCE(start_time, '00:00:00')) >= NOW() + INTERVAL ? HOUR
                     AND CONCAT(event_date, ' ', COALESCE(start_time, '00:00:00')) < NOW() + INTERVAL (? + 1) HOUR",
                    [$orgId, $value, $value]
                );
            } else {
                continue;
            }
            foreach ($customEvents as $event) {
                if (EventReminderService::hasSentReminder($db, (int) $event['id'], $reminderType)) {
                    continue;
                }
                $emailConfig = $getOrgEmailConfig($orgId);
                if (!$emailConfig && !empty($config['smtp2go']['api_key'])) {
                    $emailConfig = $config['smtp2go'];
                }
                if (!$emailConfig) {
                    continue;
                }
                $template = $db->queryOne("SELECT subject, body_html FROM email_templates WHERE organization_id = ? AND template_type = ? LIMIT 1", [$orgId, $templateTypeForCustom]);
                if (!$template) {
                    $template = $db->queryOne("SELECT subject, body_html FROM email_templates WHERE is_default = 1 AND template_type = ? LIMIT 1", [$templateTypeForCustom]);
                }
                $subjectTpl = $template['subject'] ?? 'Reminder: {event_name}';
                $bodyTpl = $template['body_html'] ?? null;
                if ($bodyTpl === null || $bodyTpl === '') {
                    $bodyTpl = '<h2>Event Reminder</h2><p>Hello {first_name},</p><p>This is a reminder about your upcoming event:</p><p><strong>{event_name}</strong></p><p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p><p>We look forward to seeing you there!</p>';
                }
                $eventDateFormatted = EventReminderService::formatEventDateForEmail((string) ($event['event_date'] ?? ''));
                $eventTimeFormatted = EventReminderService::formatEventTimeForEmail($event['start_time'] ?? null);
                $rsvps = EventReminderService::getRsvpYesRecipients($db, (int) $event['id'], true);
                $eventSent = 0;
                $emailService = new EmailService($emailConfig);
                foreach ($rsvps as $rsvp) {
                    $joinLink = (!empty($event['is_virtual']) && !empty($event['location'])) ? $event['location'] : '';
                    $recipientData = [
                        'first_name' => $rsvp['first_name'] ?? '',
                        'last_name' => $rsvp['last_name'] ?? '',
                        'email' => $rsvp['email'] ?? '',
                        'event_name' => $event['title'] ?? '',
                        'event_date' => $eventDateFormatted,
                        'event_time' => $eventTimeFormatted,
                        'event_location' => $event['location'] ?? '',
                        'location' => $event['location'] ?? '',
                        'join_link' => $joinLink,
                        'event_description' => $event['description'] ?? '',
                    ];
                    $subject = $emailService->processTemplate($subjectTpl, $recipientData);
                    $body = $emailService->processTemplate($bodyTpl, $recipientData);
                    try {
                        $emailService->sendEmail($rsvp['email'], $subject, $body, $orgId, [
                            'template' => $templateTypeForCustom,
                            'email_type' => 'reminder_custom',
                            'event_id' => $event['id'],
                            'user_id' => $rsvp['user_id'],
                        ]);
                        $eventSent++;
                        $sentCount++;
                    } catch (\Exception $e) {
                        error_log("Failed to send custom reminder to {$rsvp['email']}: " . $e->getMessage());
                        $errorCount++;
                    }
                }
                if ($eventSent > 0) {
                    EventReminderService::markReminderSent($db, (int) $event['id'], $reminderType);
                }
            }
        }
    }
}

echo "Portal reminders sent: {$sentCount}, Errors: {$errorCount}\n";
headcount_cron_exit(0);
