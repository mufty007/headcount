<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\OrgTimeZone;

/**
 * Event reminder dedup, invalidation, and RSVP recipient resolution.
 */
class EventReminderService
{
    /**
     * Delete all reminder records for a single event.
     */
    public static function clearSentRemindersForEvent(Database $db, int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }
        try {
            $db->execute('DELETE FROM reminders WHERE event_id = ?', [$eventId]);
        } catch (\Throwable $e) {
            error_log('EventReminderService::clearSentRemindersForEvent: ' . $e->getMessage());
        }
    }

    /**
     * Clear reminders for a series root and all child instances.
     */
    public static function clearSentRemindersForSeries(Database $db, int $eventId): void
    {
        if ($eventId <= 0) {
            return;
        }

        $ids = [$eventId];
        $rootId = EventSeriesHelper::getSeriesRootId($db, $eventId);
        if ($rootId !== null && $rootId > 0) {
            $ids[] = $rootId;
            try {
                $children = $db->query(
                    'SELECT id FROM events WHERE parent_event_id = ?',
                    [$rootId]
                ) ?: [];
                foreach ($children as $row) {
                    $cid = (int) ($row['id'] ?? 0);
                    if ($cid > 0) {
                        $ids[] = $cid;
                    }
                }
            } catch (\Throwable $e) {
                error_log('EventReminderService::clearSentRemindersForSeries children: ' . $e->getMessage());
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        foreach ($ids as $id) {
            self::clearSentRemindersForEvent($db, $id);
        }
    }

    /**
     * RSVP Yes recipients for reminder emails (series-aware RSVP source event).
     *
     * @return list<array<string,mixed>>
     */
    public static function getRsvpYesRecipients(Database $db, int $eventId, bool $respectEmailPrefs = true): array
    {
        if ($eventId <= 0) {
            return [];
        }

        $rsvpSourceId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
        $rows = $db->query(
            "SELECT r.user_id, u.first_name, u.last_name, u.email, u.email_preferences
             FROM rsvps r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.event_id = ?
               AND LOWER(r.status) = 'yes'
               AND u.status = 'active'
               AND u.email IS NOT NULL
               AND TRIM(u.email) != ''",
            [$rsvpSourceId]
        ) ?: [];

        if (!$respectEmailPrefs) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $prefs = !empty($row['email_preferences'])
                ? (json_decode((string) $row['email_preferences'], true) ?: [])
                : [];
            if ($prefs === [] || !isset($prefs['event_reminders']) || $prefs['event_reminders']) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * Whether a sent reminder already exists for this event and type.
     */
    public static function hasSentReminder(Database $db, int $eventId, string $reminderType): bool
    {
        if ($eventId <= 0 || $reminderType === '') {
            return false;
        }
        try {
            $row = $db->queryOne(
                "SELECT id FROM reminders WHERE event_id = ? AND reminder_type = ? AND status = 'sent' LIMIT 1",
                [$eventId, $reminderType]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Record that a reminder type was sent for an event.
     */
    public static function markReminderSent(Database $db, int $eventId, string $reminderType): void
    {
        if ($eventId <= 0 || $reminderType === '') {
            return;
        }
        try {
            $db->insert('reminders', [
                'event_id' => $eventId,
                'reminder_type' => $reminderType,
                'scheduled_for' => date('Y-m-d H:i:s'),
                'status' => 'sent',
                'sent_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('EventReminderService::markReminderSent: ' . $e->getMessage());
        }
    }

    /**
     * Determine automated reminder type for an event using org-local today.
     *
     * @param array<string,mixed> $event Must include event_date, start_time; optional organization timezone
     * @return string|null 1week, 1day, 2hours, or null
     */
    public static function resolveAutomatedReminderType(array $event, bool $includeTwoHours = false): ?string
    {
        $eventDate = substr((string) ($event['event_date'] ?? ''), 0, 10);
        if ($eventDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            return null;
        }

        $orgTz = OrgTimeZone::resolve($event['org_timezone'] ?? $event['timezone'] ?? null);
        $orgToday = OrgTimeZone::todayYmd($orgTz);

        $today = new \DateTimeImmutable($orgToday, new \DateTimeZone($orgTz));
        $eventDay = new \DateTimeImmutable($eventDate, new \DateTimeZone($orgTz));
        $diffDays = (int) $today->diff($eventDay)->format('%r%a');

        if ($diffDays === 7) {
            return '1week';
        }
        if ($diffDays === 1) {
            return '1day';
        }

        if ($includeTwoHours && $diffDays === 0) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($orgTz));
            $startRaw = trim((string) ($event['start_time'] ?? ''));
            if ($startRaw !== '' && preg_match('/^(\d{1,2}):(\d{2})/', $startRaw, $m)) {
                $start = $today->setTime((int) $m[1], (int) $m[2], 0);
                $hoursUntil = ($start->getTimestamp() - $now->getTimestamp()) / 3600;
                if ($hoursUntil >= 2.0 && $hoursUntil < 3.0) {
                    return '2hours';
                }
            }
        }

        return null;
    }

    /**
     * Format event date for email merge tags.
     */
    public static function formatEventDateForEmail(string $eventDateYmd): string
    {
        $eventDateYmd = substr($eventDateYmd, 0, 10);
        $ts = strtotime($eventDateYmd);
        return $ts !== false ? date('F j, Y', $ts) : $eventDateYmd;
    }

    /**
     * Format event start time for email merge tags.
     */
    public static function formatEventTimeForEmail(?string $startTime): string
    {
        if ($startTime === null || trim($startTime) === '') {
            return '';
        }
        $ts = strtotime($startTime);
        return $ts !== false ? date('g:i A', $ts) : trim($startTime);
    }

    /**
     * Ensure automation template columns exist (migration 079 may not have been run).
     */
    public static function ensureAutomationSchema(Database $db): bool
    {
        try {
            $cols = $db->query("SHOW COLUMNS FROM organizations LIKE 'reminder_milestone_templates'");
            if (!empty($cols)) {
                return true;
            }
            $db->execute(
                'ALTER TABLE organizations ADD COLUMN reminder_milestone_templates JSON NULL DEFAULT NULL AFTER reminder_custom_schedule'
            );
            return true;
        } catch (\Throwable $e) {
            error_log('EventReminderService::ensureAutomationSchema: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse reminder_custom_schedule JSON (legacy flat array or wrapped object with steps + milestone_templates).
     *
     * @return array{steps: list<array<string,mixed>>, milestone_templates: array{1week: ?int, 1day: ?int, 2hours: ?int}}
     */
    public static function parseAutomationScheduleJson($json): array
    {
        $empty = ['steps' => [], 'milestone_templates' => ['1week' => null, '1day' => null, '2hours' => null]];
        if ($json === null || $json === '') {
            return $empty;
        }
        if (is_object($json)) {
            $json = json_decode(json_encode($json), true);
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($decoded)) {
            return $empty;
        }

        if (array_key_exists('steps', $decoded) && is_array($decoded['steps'])) {
            $milestones = self::parseMilestoneTemplates($decoded['milestone_templates'] ?? null);
            $steps = self::normalizeCustomScheduleSteps($decoded['steps']);
            if (!empty($decoded['step_templates']) && is_array($decoded['step_templates'])) {
                $steps = self::applyStepTemplatesMap($steps, $decoded['step_templates']);
            }
            return [
                'steps' => $steps,
                'milestone_templates' => $milestones,
            ];
        }

        if ($decoded === [] || array_is_list($decoded)) {
            return [
                'steps' => self::normalizeCustomScheduleSteps($decoded),
                'milestone_templates' => $empty['milestone_templates'],
            ];
        }

        return $empty;
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @param array{1week: ?int, 1day: ?int, 2hours: ?int} $milestoneTemplates
     */
    public static function packAutomationScheduleJson(array $steps, array $milestoneTemplates): string
    {
        $packedMilestones = [];
        foreach (['1week', '1day', '2hours'] as $key) {
            if (!empty($milestoneTemplates[$key])) {
                $packedMilestones[$key] = (int) $milestoneTemplates[$key];
            }
        }

        return json_encode([
            'v' => 2,
            'steps' => $steps,
            'milestone_templates' => $packedMilestones,
            'step_templates' => self::buildStepTemplatesMap($steps),
        ]);
    }

    public static function customStepTemplateKey(int $value, string $unit): string
    {
        return $unit . ':' . $value;
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @param array<string, int> $stepTemplates
     * @return list<array<string,mixed>>
     */
    public static function applyStepTemplatesMap(array $steps, array $stepTemplates): array
    {
        foreach ($steps as &$step) {
            if (!empty($step['template_id'])) {
                continue;
            }
            $key = self::customStepTemplateKey((int) ($step['value'] ?? 0), (string) ($step['unit'] ?? ''));
            if (!isset($stepTemplates[$key])) {
                continue;
            }
            $templateId = (int) $stepTemplates[$key];
            if ($templateId > 0) {
                $step['template_id'] = $templateId;
            }
        }
        unset($step);

        return $steps;
    }

    /**
     * @param list<array<string,mixed>> $steps
     * @return array<string, int>
     */
    public static function buildStepTemplatesMap(array $steps): array
    {
        $map = [];
        foreach ($steps as $step) {
            if (empty($step['template_id'])) {
                continue;
            }
            $value = (int) ($step['value'] ?? 0);
            $unit = (string) ($step['unit'] ?? '');
            if ($value < 1 || !in_array($unit, ['days', 'hours'], true)) {
                continue;
            }
            $map[self::customStepTemplateKey($value, $unit)] = (int) $step['template_id'];
        }

        return $map;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{value: int, unit: string, template_id?: int}>
     */
    public static function normalizeCustomScheduleSteps(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!isset($row['value'], $row['unit']) || !in_array($row['unit'], ['days', 'hours'], true)) {
                continue;
            }
            $v = (int) $row['value'];
            if ($v < 1) {
                continue;
            }
            if ($row['unit'] === 'hours' ? $v > 720 : $v > 365) {
                continue;
            }
            $entry = ['value' => $v, 'unit' => $row['unit']];
            if (array_key_exists('template_id', $row) && $row['template_id'] !== null && $row['template_id'] !== '') {
                $templateId = (int) $row['template_id'];
                if ($templateId > 0) {
                    $entry['template_id'] = $templateId;
                }
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Merge milestone template overrides from dedicated column and embedded schedule JSON.
     *
     * @return array{1week: ?int, 1day: ?int, 2hours: ?int}
     */
    public static function resolveMilestoneTemplates(?string $columnJson, $scheduleJson): array
    {
        $fromColumn = self::parseMilestoneTemplates($columnJson);
        $fromSchedule = self::parseAutomationScheduleJson($scheduleJson)['milestone_templates'];
        foreach (['1week', '1day', '2hours'] as $key) {
            if ($fromColumn[$key] === null && $fromSchedule[$key] !== null) {
                $fromColumn[$key] = $fromSchedule[$key];
            }
        }
        return $fromColumn;
    }

    /**
     * Parse org milestone template overrides from JSON column.
     *
     * @return array{1week: ?int, 1day: ?int, 2hours: ?int}
     */
    public static function parseMilestoneTemplates($json): array
    {
        $defaults = ['1week' => null, '1day' => null, '2hours' => null];
        if ($json === null || $json === '') {
            return $defaults;
        }
        if (is_object($json)) {
            $json = json_decode(json_encode($json), true);
        }
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($decoded)) {
            return $defaults;
        }
        foreach (array_keys($defaults) as $key) {
            if (!array_key_exists($key, $decoded) || $decoded[$key] === null || $decoded[$key] === '') {
                continue;
            }
            $id = (int) $decoded[$key];
            if ($id > 0) {
                $defaults[$key] = $id;
            }
        }
        return $defaults;
    }

    /**
     * Whether a template belongs to the organization.
     */
    public static function validateTemplateIdForOrg(Database $db, int $organizationId, int $templateId): bool
    {
        if ($organizationId <= 0 || $templateId <= 0) {
            return false;
        }
        try {
            $row = $db->queryOne(
                'SELECT id FROM email_templates WHERE id = ? AND (organization_id = ? OR organization_id IS NULL) LIMIT 1',
                [$templateId, $organizationId]
            );
            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Map reminder_type to default email_templates.template_type.
     */
    public static function defaultTemplateTypeForReminder(string $reminderType): string
    {
        $map = [
            '1week' => 'reminder_1week',
            '1day' => 'reminder_1day',
            '2hours' => 'reminder_2hours',
        ];
        if (isset($map[$reminderType])) {
            return $map[$reminderType];
        }

        if (preg_match('/^custom_days_(\d+)$/', $reminderType, $m)) {
            $days = (int) $m[1];
            if ($days === 7) {
                return 'reminder_1week';
            }
            if ($days === 1) {
                return 'reminder_1day';
            }
            return $days >= 3 ? 'reminder_1week' : 'reminder_1day';
        }

        if (preg_match('/^custom_hours_(\d+)$/', $reminderType, $m)) {
            $hours = (int) $m[1];
            if ($hours === 2) {
                return 'reminder_2hours';
            }
            return $hours <= 3 ? 'reminder_2hours' : 'reminder_1day';
        }

        return 'reminder_1day';
    }

    /**
     * Resolve subject/body for a reminder step.
     *
     * @return array{subject: string, body_html: string, template_type: string, template_id: ?int}
     */
    public static function resolveReminderTemplate(
        Database $db,
        int $organizationId,
        string $reminderType,
        ?int $templateIdOverride = null
    ): array {
        $defaultSubject = 'Reminder: {event_name}';
        $defaultBody = '<h2>Event Reminder</h2><p>Hello {first_name},</p><p>This is a reminder about your upcoming event:</p>'
            . '<p><strong>{event_name}</strong></p>'
            . '<p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {location}</p>'
            . '<p>We look forward to seeing you there!</p>';

        if ($templateIdOverride !== null && $templateIdOverride > 0) {
            $tpl = $db->queryOne(
                'SELECT id, subject, body_html, template_type FROM email_templates
                 WHERE id = ? AND organization_id = ? LIMIT 1',
                [$templateIdOverride, $organizationId]
            );
            if ($tpl) {
                $body = trim((string) ($tpl['body_html'] ?? ''));
                return [
                    'subject' => trim((string) ($tpl['subject'] ?? '')) !== '' ? (string) $tpl['subject'] : $defaultSubject,
                    'body_html' => $body !== '' ? $body : $defaultBody,
                    'template_type' => (string) ($tpl['template_type'] ?? self::defaultTemplateTypeForReminder($reminderType)),
                    'template_id' => (int) $tpl['id'],
                ];
            }
        }

        $templateType = self::defaultTemplateTypeForReminder($reminderType);

        $tpl = $db->queryOne(
            'SELECT id, subject, body_html FROM email_templates
             WHERE organization_id = ? AND template_type = ? LIMIT 1',
            [$organizationId, $templateType]
        );
        if ($tpl && trim((string) ($tpl['body_html'] ?? '')) !== '') {
            return [
                'subject' => trim((string) ($tpl['subject'] ?? '')) !== '' ? (string) $tpl['subject'] : $defaultSubject,
                'body_html' => (string) $tpl['body_html'],
                'template_type' => $templateType,
                'template_id' => isset($tpl['id']) ? (int) $tpl['id'] : null,
            ];
        }

        $tpl = $db->queryOne(
            'SELECT id, subject, body_html FROM email_templates
             WHERE is_default = 1 AND template_type = ? LIMIT 1',
            [$templateType]
        );
        if ($tpl && trim((string) ($tpl['body_html'] ?? '')) !== '') {
            return [
                'subject' => trim((string) ($tpl['subject'] ?? '')) !== '' ? (string) $tpl['subject'] : $defaultSubject,
                'body_html' => (string) $tpl['body_html'],
                'template_type' => $templateType,
                'template_id' => isset($tpl['id']) ? (int) $tpl['id'] : null,
            ];
        }

        return [
            'subject' => $defaultSubject,
            'body_html' => $defaultBody,
            'template_type' => $templateType,
            'template_id' => null,
        ];
    }
}
