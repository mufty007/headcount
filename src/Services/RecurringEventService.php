<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Recurring Event Service
 * Handles generation and management of recurring event instances
 */
class RecurringEventService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Generate recurring event instances
     * 
     * @param int $parentEventId The parent event ID
     * @param array $recurrenceData Recurrence configuration
     * @return array Generated event IDs
     */
    public function generateInstances($parentEventId, $recurrenceData)
    {
        $parentEvent = $this->db->queryOne(
            "SELECT * FROM events WHERE id = ?",
            [$parentEventId]
        );

        if (!$parentEvent) {
            throw new \Exception('Parent event not found');
        }

        $startDate = new \DateTime($parentEvent['event_date']);
        $dates = $this->calculateRecurrenceDates($startDate, $recurrenceData);

        $generatedIds = [];

        foreach ($dates as $date) {
            // Parent row is already the first session — never duplicate that date as a child instance
            if ($date->format('Y-m-d') === $parentEvent['event_date']) {
                continue;
            }

            // Check if instance already exists
            $existing = $this->db->queryOne(
                "SELECT id FROM events WHERE parent_event_id = ? AND event_date = ?",
                [$parentEventId, $date->format('Y-m-d')]
            );

            if ($existing) {
                continue; // Skip if already generated
            }

            // Create event instance
            $eventData = [
                'organization_id' => $parentEvent['organization_id'],
                'parent_event_id' => $parentEventId,
                'is_recurring_instance' => true,
                'title' => $parentEvent['title'],
                'description' => $parentEvent['description'],
                'event_date' => $date->format('Y-m-d'),
                'start_time' => $parentEvent['start_time'],
                'end_time' => $parentEvent['end_time'],
                'location' => $parentEvent['location'],
                'category' => $parentEvent['category'],
                'capacity' => $parentEvent['capacity'],
                'ticket_price' => $parentEvent['ticket_price'],
                'registration_required' => $parentEvent['registration_required'],
                'status' => $parentEvent['status'],
                'created_by' => $parentEvent['created_by']
            ];
            if ($this->db->hasColumn('events', 'visibility')) {
                $v = isset($parentEvent['visibility']) ? trim((string) $parentEvent['visibility']) : 'public';
                if (!in_array($v, ['public', 'internal', 'invite_only'], true)) {
                    $v = 'public';
                }
                $eventData['visibility'] = $v;
            }
            if ($this->db->hasColumn('events', 'checkin_window_start')) {
                $eventData['checkin_window_start'] = $parentEvent['checkin_window_start'] ?? null;
            }
            if ($this->db->hasColumn('events', 'checkin_window_end')) {
                $eventData['checkin_window_end'] = $parentEvent['checkin_window_end'] ?? null;
            }
            if ($this->db->hasColumn('events', 'extra_details')) {
                $eventData['extra_details'] = $parentEvent['extra_details'] ?? null;
            }
            if ($this->db->hasColumn('events', 'is_virtual')) {
                $eventData['is_virtual'] = isset($parentEvent['is_virtual']) ? (int)(bool)$parentEvent['is_virtual'] : 0;
            }
            if ($this->db->hasColumn('events', 'is_potluck')) {
                $eventData['is_potluck'] = !empty($parentEvent['is_potluck']) ? 1 : 0;
            }
            if ($this->db->hasColumn('events', 'potluck_show_bringing_prompt')) {
                $eventData['potluck_show_bringing_prompt'] = isset($parentEvent['potluck_show_bringing_prompt'])
                    ? (!empty($parentEvent['potluck_show_bringing_prompt']) ? 1 : 0)
                    : 1;
            }
            if ($this->db->hasColumn('events', 'allow_guest_rsvp')) {
                $eventData['allow_guest_rsvp'] = !empty($parentEvent['allow_guest_rsvp']) ? 1 : 0;
            }
            if ($this->db->hasColumn('events', 'allow_bring_guests')) {
                $eventData['allow_bring_guests'] = !empty($parentEvent['allow_bring_guests']) ? 1 : 0;
            }
            if ($this->db->hasColumn('events', 'registration_deadline')) {
                $eventData['registration_deadline'] = $parentEvent['registration_deadline'] ?? null;
            }
            if ($this->db->hasColumn('events', 'min_age')) {
                $eventData['min_age'] = $parentEvent['min_age'] ?? null;
            }
            if ($this->db->hasColumn('events', 'max_age')) {
                $eventData['max_age'] = $parentEvent['max_age'] ?? null;
            }
            if ($this->db->hasColumn('events', 'gender_restriction')) {
                $gr = isset($parentEvent['gender_restriction']) ? trim((string) $parentEvent['gender_restriction']) : 'none';
                if ($gr === '' || !in_array($gr, ['none', 'male', 'female', 'other'], true)) {
                    $gr = 'none';
                }
                $eventData['gender_restriction'] = $gr;
            }
            if ($this->db->hasColumn('events', 'enforce_restrictions_at_checkin')) {
                $eventData['enforce_restrictions_at_checkin'] = !empty($parentEvent['enforce_restrictions_at_checkin']) ? 1 : 0;
            }
            if ($this->db->hasColumn('events', 'facility_id') && !empty($parentEvent['facility_id'])) {
                $eventData['facility_id'] = (int) $parentEvent['facility_id'];
            }

            if ($this->db->hasColumn('events', 'banner_image')) {
                $parentBanner = isset($parentEvent['banner_image']) ? trim((string) $parentEvent['banner_image']) : '';
                if ($parentBanner !== '') {
                    $eventData['banner_image'] = $parentBanner;
                }
            }

            $eventId = $this->db->insert('events', $eventData);
            $generatedIds[] = $eventId;

            // Copy categories if event_categories table exists
            try {
                $categories = $this->db->query(
                    "SELECT category_id FROM event_categories WHERE event_id = ?",
                    [$parentEventId]
                );
                foreach ($categories as $cat) {
                    $this->db->insert('event_categories', [
                        'event_id' => $eventId,
                        'category_id' => $cat['category_id']
                    ]);
                }
            } catch (\Exception $e) {
                // event_categories table might not exist
            }
        }

        // Update last_generated_date (custom schedules are finite — advance to max date so cron does not loop forever)
        if (($recurrenceData['recurrence_type'] ?? '') === 'custom' && !empty($dates)) {
            $maxStr = $dates[0]->format('Y-m-d');
            foreach ($dates as $d) {
                $s = $d->format('Y-m-d');
                if ($s > $maxStr) {
                    $maxStr = $s;
                }
            }
            $this->db->execute(
                "UPDATE recurring_events SET last_generated_date = ? WHERE parent_event_id = ?",
                [$maxStr, $parentEventId]
            );
        } elseif (!empty($generatedIds)) {
            $lastDate = end($dates);
            $this->db->execute(
                "UPDATE recurring_events SET last_generated_date = ? WHERE parent_event_id = ?",
                [$lastDate->format('Y-m-d'), $parentEventId]
            );
        }

        return $generatedIds;
    }

    /**
     * Whether recurrence includes at least one weekday (0–6). Do not use empty() on strings:
     * empty('0') is true in PHP but 0 means Sunday.
     *
     * @param array<string,mixed> $recurrenceData
     */
    private function hasDaysOfWeek(array $recurrenceData): bool
    {
        $dow = $recurrenceData['days_of_week'] ?? null;
        if ($dow === null || $dow === false) {
            return false;
        }
        if (is_array($dow)) {
            return count($dow) > 0;
        }
        $s = trim((string) $dow);

        return $s !== '';
    }

    /**
     * Calculate recurrence dates based on pattern
     * 
     * @param \DateTime $startDate
     * @param array $recurrenceData
     * @return array Array of DateTime objects
     */
    private function calculateRecurrenceDates(\DateTime $startDate, $recurrenceData)
    {
        $dates = [];
        $interval = $recurrenceData['interval'] ?? 1;
        $type = $recurrenceData['recurrence_type'];

        if ($type === 'custom') {
            return $this->datesFromCustomField($recurrenceData['custom_dates'] ?? null);
        }

        // Weekly with no weekdays: must not fall through to generic weekly (+N weeks) which spawns
        // one instance per week up to maxOccurrences. Empty UI also means "no recurrence_days".
        if ($type === 'weekly' && !$this->hasDaysOfWeek($recurrenceData)) {
            return [];
        }

        $currentDate = clone $startDate;
        $endType = $recurrenceData['end_type'] ?? 'never';
        $maxOccurrences = 365; // Safety limit
        $count = 0;

        // Determine end date
        $endDate = null;
        if ($endType === 'on_date' && !empty($recurrenceData['end_date'])) {
            $endDate = new \DateTime($recurrenceData['end_date']);
        } elseif ($endType === 'after_count' && !empty($recurrenceData['end_after_count'])) {
            $maxOccurrences = (int)$recurrenceData['end_after_count'];
        }

        // For weekly recurrence with specific days (do not use empty(): PHP empty('0') is true for Sunday)
        if ($type === 'weekly' && $this->hasDaysOfWeek($recurrenceData)) {
            $days = is_array($recurrenceData['days_of_week']) 
                ? $recurrenceData['days_of_week'] 
                : explode(',', $recurrenceData['days_of_week']);
            
            // Convert to integers
            $days = array_map('intval', $days);
            
            // Start from the start date and find matching days
            $checkDate = clone $startDate;
            $weeksProcessed = 0;
            $maxWeeks = 104; // Max 2 years of weekly
            
            while ($count < $maxOccurrences && $weeksProcessed < $maxWeeks) {
                // Check each day of the current week
                for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
                    $testDate = clone $checkDate;
                    $testDate->modify("+{$dayOffset} days");
                    
                    $dayOfWeek = (int)$testDate->format('w');
                    
                    if (in_array($dayOfWeek, $days) && $testDate >= $startDate) {
                        if ($endDate && $testDate > $endDate) {
                            break 2; // Break both loops
                        }
                        $dates[] = clone $testDate;
                        $count++;
                        if ($count >= $maxOccurrences) {
                            break 2;
                        }
                    }
                }
                
                // Move to next week
                $checkDate->modify("+{$interval} weeks");
                $weeksProcessed++;
            }
        } elseif ($type === 'monthly_weekday' && isset($recurrenceData['week_of_month']) && $recurrenceData['week_of_month'] >= 1 && $recurrenceData['week_of_month'] <= 5) {
            // Monthly by weekday: e.g. "last Friday", "first Monday". week_of_month: 1=first, 2=second, 3=third, 4=fourth, 5=last
            $weekOfMonth = (int)$recurrenceData['week_of_month'];
            $daysRaw = $recurrenceData['days_of_week'] ?? '';
            $dayOfWeek = is_array($daysRaw) ? (int)(reset($daysRaw)) : (int)(is_string($daysRaw) ? trim(explode(',', $daysRaw)[0] ?? '') : $daysRaw);
            if ($dayOfWeek < 0 || $dayOfWeek > 6) {
                $dayOfWeek = (int)$startDate->format('w');
            }

            $monthDate = clone $startDate;
            $monthDate->modify('first day of this month');
            $monthsProcessed = 0;
            $maxMonths = 24;

            while ($count < $maxOccurrences && $monthsProcessed < $maxMonths) {
                $occurrenceDate = $this->getNthWeekdayInMonth(
                    (int)$monthDate->format('Y'),
                    (int)$monthDate->format('n'),
                    $weekOfMonth,
                    $dayOfWeek
                );
                if ($occurrenceDate && $occurrenceDate >= $startDate) {
                    if ($endDate && $occurrenceDate > $endDate) {
                        break;
                    }
                    $dates[] = clone $occurrenceDate;
                    $count++;
                }
                $monthDate->modify("+{$interval} months");
                $monthsProcessed++;
            }
        } else {
            // Standard recurrence (daily, monthly, yearly, or weekly without specific days)
            while ($count < $maxOccurrences) {
                // Check if we've passed the end date
                if ($endDate && $currentDate > $endDate) {
                    break;
                }

                if ($currentDate >= $startDate) {
                    $dates[] = clone $currentDate;
                    $count++;
                }

                // Move to next occurrence
                switch ($type) {
                    case 'daily':
                        $currentDate->modify("+{$interval} days");
                        break;
                    case 'weekly':
                        $currentDate->modify("+{$interval} weeks");
                        break;
                    case 'monthly':
                        $currentDate->modify("+{$interval} months");
                        break;
                    case 'yearly':
                        $currentDate->modify("+{$interval} years");
                        break;
                }

                // Safety check
                if ($currentDate > new \DateTime('+2 years')) {
                    break;
                }
            }
        }

        return $dates;
    }

    /**
     * True only for real calendar dates in strict Y-m-d (no month/day overflow).
     */
    public static function isValidStrictYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);

        return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $s;
    }

    /**
     * Build JSON for recurring_events.custom_dates from API input.
     *
     * @param array<string,mixed> $input
     * @return array{json: ?string, error?: string}
     *         When error is non-empty, validation failed. json null means no additional dates after filtering the parent date.
     */
    public static function encodeCustomDatesFromInputResult(array $input, ?string $parentEventDateYmd): array
    {
        $raw = $input['custom_session_dates'] ?? null;
        if ($raw === null || $raw === '') {
            return ['json' => null];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return ['json' => null];
        }
        $out = [];
        foreach ($raw as $d) {
            $d = trim((string) $d);
            if ($d === '') {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                return [
                    'json' => null,
                    'error' => 'Invalid date "' . $d . '". Use YYYY-MM-DD (for example, June 14, 2026 is 2026-06-14).',
                ];
            }
            if (!self::isValidStrictYmd($d)) {
                return [
                    'json' => null,
                    'error' => 'Invalid calendar date "' . $d . '". Use YYYY-MM-DD (for example, June 14, 2026 is 2026-06-14).',
                ];
            }
            $out[] = $d;
        }
        $out = array_values(array_unique($out));
        if ($parentEventDateYmd !== null && $parentEventDateYmd !== '') {
            $out = array_values(array_filter($out, function ($d) use ($parentEventDateYmd) {
                return $d !== $parentEventDateYmd;
            }));
        }
        sort($out);

        return ['json' => $out === [] ? null : json_encode($out)];
    }

    /**
     * @param array<string,mixed> $input
     * @throws \InvalidArgumentException when a custom session date line is invalid
     */
    public static function encodeCustomDatesFromInput(array $input, ?string $parentEventDateYmd): ?string
    {
        $r = self::encodeCustomDatesFromInputResult($input, $parentEventDateYmd);
        if (!empty($r['error'])) {
            throw new \InvalidArgumentException($r['error']);
        }

        return $r['json'];
    }

    /**
     * @param mixed $raw JSON string or list of YYYY-MM-DD (additional sessions; parent date handled separately)
     * @return \DateTime[]
     */
    private function datesFromCustomField($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $d) {
            $d = trim((string) $d);
            if (!self::isValidStrictYmd($d)) {
                continue;
            }
            $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if ($dt instanceof \DateTimeImmutable) {
                $out[$d] = new \DateTime($d);
            }
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Get the Nth occurrence of a weekday in a month (e.g. "last Friday", "first Monday").
     * 
     * @param int $year Year
     * @param int $month Month 1-12
     * @param int $weekOfMonth 1=first, 2=second, 3=third, 4=fourth, 5=last
     * @param int $dayOfWeek 0=Sunday, 6=Saturday (PHP 'w')
     * @return \DateTime|null The date or null if invalid
     */
    private function getNthWeekdayInMonth($year, $month, $weekOfMonth, $dayOfWeek)
    {
        if ($weekOfMonth === 5) {
            // Last occurrence: last day of month, then go backward to matching weekday
            $last = new \DateTime("{$year}-{$month}-01");
            $last->modify('last day of this month');
            $current = clone $last;
            while ((int)$current->format('w') !== $dayOfWeek) {
                $current->modify('-1 day');
                if ((int)$current->format('n') !== $month) {
                    return null;
                }
            }
            return $current;
        }

        // First through fourth: find first occurrence of weekday, then add (weekOfMonth - 1) weeks
        $first = new \DateTime("{$year}-{$month}-01");
        $current = clone $first;
        while ((int)$current->format('w') !== $dayOfWeek) {
            $current->modify('+1 day');
            if ((int)$current->format('n') !== $month) {
                return null;
            }
        }
        $current->modify('+' . ($weekOfMonth - 1) . ' weeks');
        if ((int)$current->format('n') !== $month) {
            return null;
        }
        return $current;
    }

    /**
     * Remove series child events whose dates are no longer listed in custom_dates (hard delete only when safe).
     *
     * @return array{deleted_ids: list<int>, skipped: list<array{id: int, event_date: string, reason: string}>}
     */
    public static function pruneStaleCustomSeriesChildren(Database $db, int $parentEventId, string $customDatesJson): array
    {
        $deletedIds = [];
        $skipped = [];
        $decoded = json_decode($customDatesJson, true);
        if (!is_array($decoded)) {
            return ['deleted_ids' => [], 'skipped' => []];
        }
        $allowed = [];
        foreach ($decoded as $d) {
            $d = trim((string) $d);
            if (self::isValidStrictYmd($d)) {
                $allowed[$d] = true;
            }
        }
        if ($allowed === []) {
            return ['deleted_ids' => [], 'skipped' => []];
        }
        $rows = $db->query(
            'SELECT id, event_date FROM events WHERE parent_event_id = :pid',
            ['pid' => $parentEventId]
        );
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $ed = isset($row['event_date']) ? trim((string) $row['event_date']) : '';
            if ($id <= 0 || $ed === '') {
                continue;
            }
            if (isset($allowed[$ed])) {
                continue;
            }
            if (self::countBlockingDependentsForEvent($db, $id) > 0) {
                $skipped[] = [
                    'id' => $id,
                    'event_date' => $ed,
                    'reason' => 'has_rsvps_attendance_or_payments',
                ];
                continue;
            }
            try {
                $db->delete('events', $id, 'id', false);
                $deletedIds[] = $id;
            } catch (\Throwable $e) {
                error_log('pruneStaleCustomSeriesChildren delete failed id=' . $id . ': ' . $e->getMessage());
                $skipped[] = [
                    'id' => $id,
                    'event_date' => $ed,
                    'reason' => 'delete_failed',
                ];
            }
        }

        return ['deleted_ids' => $deletedIds, 'skipped' => $skipped];
    }

    private static function countBlockingDependentsForEvent(Database $db, int $eventId): int
    {
        $total = 0;
        foreach (['rsvps', 'attendance', 'payments'] as $table) {
            try {
                $row = $db->queryOne("SELECT COUNT(*) AS c FROM `{$table}` WHERE event_id = ?", [$eventId]);
                $total += (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
                // Table may not exist on older installs
            }
        }

        return $total;
    }

    /**
     * Generate instances up to a certain date (for cron job)
     * 
     * @param \DateTime $upToDate Generate instances up to this date
     * @return int Number of events generated
     */
    public function generateUpcomingInstances(?\DateTime $upToDate = null)
    {
        if ($upToDate === null) {
            $upToDate = new \DateTime('+3 months');
        }

        $recurringEvents = $this->db->query(
            "SELECT re.*, e.event_date as start_date 
             FROM recurring_events re
             INNER JOIN events e ON re.parent_event_id = e.id
            WHERE re.is_active = 1 
            AND re.recurrence_type != 'custom'
            AND (re.last_generated_date IS NULL OR re.last_generated_date < ?)
            AND e.status != 'cancelled'",
            [$upToDate->format('Y-m-d')]
        );

        $totalGenerated = 0;

        foreach ($recurringEvents as $recurring) {
            try {
                $recurrenceData = [
                    'recurrence_type' => $recurring['recurrence_type'],
                    'interval' => $recurring['interval'],
                    'end_type' => $recurring['end_type'],
                    'end_after_count' => $recurring['end_after_count'],
                    'end_date' => $recurring['end_date'],
                    'days_of_week' => $recurring['days_of_week'],
                    'week_of_month' => isset($recurring['week_of_month']) ? (int)$recurring['week_of_month'] : null,
                    'custom_dates' => $recurring['custom_dates'] ?? null
                ];

                // Only generate dates up to the target date
                $generated = $this->generateInstances($recurring['parent_event_id'], $recurrenceData);
                $totalGenerated += count($generated);
            } catch (\Exception $e) {
                error_log("Error generating recurring events: " . $e->getMessage());
            }
        }

        return $totalGenerated;
    }

    /**
     * Delete all instances of a recurring event
     * 
     * @param int $parentEventId
     * @return int Number of instances deleted
     */
    public function deleteAllInstances($parentEventId)
    {
        $instances = $this->db->query(
            "SELECT id FROM events WHERE parent_event_id = ?",
            [$parentEventId]
        );

        $count = 0;
        foreach ($instances as $instance) {
            $this->db->delete('events', $instance['id'], 'id', false);
            $count++;
        }

        // Delete recurring_events record
        $this->db->execute(
            "DELETE FROM recurring_events WHERE parent_event_id = ?",
            [$parentEventId]
        );

        return $count;
    }
}
