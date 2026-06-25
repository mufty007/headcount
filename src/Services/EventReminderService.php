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
}
