<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Keeps recurring_events + generated instances in sync when admins save via event-create / event-edit.
 * Mirrors the rules in public/api/events.php (POST/PUT).
 */
class AdminEventRecurrenceService
{
    /**
     * Build recurrence payload from admin form POST (same keys as JSON API).
     *
     * @return array<string, mixed>
     */
    public static function inputFromPost(): array
    {
        $days = [];
        if (isset($_POST['recurrence_days']) && is_array($_POST['recurrence_days'])) {
            $days = array_values(array_map('intval', $_POST['recurrence_days']));
        }

        $parsedDates = self::parseCustomDatesText(isset($_POST['custom_session_dates_text']) ? (string) $_POST['custom_session_dates_text'] : '');

        return [
            'is_recurring' => $_POST['is_recurring'] ?? null,
            'recurrence_type' => isset($_POST['recurrence_type']) ? trim((string) $_POST['recurrence_type']) : 'weekly',
            'recurrence_interval' => isset($_POST['recurrence_interval']) ? (int) $_POST['recurrence_interval'] : 1,
            'recurrence_days' => $days,
            'recurrence_week_of_month' => isset($_POST['recurrence_week_of_month']) ? trim((string) $_POST['recurrence_week_of_month']) : '',
            'recurrence_end_type' => isset($_POST['recurrence_end_type']) ? trim((string) $_POST['recurrence_end_type']) : 'never',
            'recurrence_end_after_count' => isset($_POST['recurrence_end_after_count']) ? trim((string) $_POST['recurrence_end_after_count']) : '',
            'recurrence_end_date' => isset($_POST['recurrence_end_date']) ? trim((string) $_POST['recurrence_end_date']) : '',
            'custom_session_dates' => $parsedDates['dates'],
            'custom_session_dates_text_error' => $parsedDates['error'],
        ];
    }

    /**
     * @return array{dates: list<string>, error: ?string}
     */
    public static function parseCustomDatesText(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return ['dates' => [], 'error' => null];
        }
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $line)) {
                return [
                    'dates' => [],
                    'error' => 'Invalid date "' . $line . '". Use YYYY-MM-DD (for example, June 14, 2026 is 2026-06-14).',
                ];
            }
            if (!RecurringEventService::isValidStrictYmd($line)) {
                return [
                    'dates' => [],
                    'error' => 'Invalid calendar date "' . $line . '". Use YYYY-MM-DD (for example, June 14, 2026 is 2026-06-14).',
                ];
            }
            $out[] = $line;
        }

        return ['dates' => array_values(array_unique($out)), 'error' => null];
    }

    /**
     * @param array<string, mixed> $input Same shape as API body: is_recurring, recurrence_*, custom_session_dates (array of Y-m-d)
     * @return array{ok: bool, generated?: int, error?: string, recurrence_pruned_deleted_ids?: list<int>, recurrence_prune_skipped?: list<array{id: int, event_date: string, reason: string}>}
     */
    public static function sync(Database $db, int $organizationId, int $eventId, string $eventDateYmd, array $input, bool $isRecurringInstance): array
    {
        if ($isRecurringInstance) {
            return ['ok' => true, 'generated' => 0];
        }

        $isRecurringRequest = \requestBoolFromInput($input, 'is_recurring', false);

        if (!$isRecurringRequest) {
            try {
                $db->execute('DELETE FROM recurring_events WHERE parent_event_id = :id', ['id' => $eventId]);
            } catch (\Throwable $e) {
                // table may not exist
            }

            return ['ok' => true, 'generated' => 0];
        }

        $rt = isset($input['recurrence_type']) ? trim(strtolower((string) $input['recurrence_type'])) : 'weekly';
        if ($rt === 'weekly' && !\recurrenceDaysProvided($input)) {
            return ['ok' => false, 'error' => 'Select at least one weekday for weekly recurring events (Sunday counts as a weekday).'];
        }

        $svcPath = __DIR__ . '/RecurringEventService.php';
        if (!is_file($svcPath)) {
            return ['ok' => false, 'error' => 'RecurringEventService not available.'];
        }
        require_once $svcPath;

        try {
            $weekOfMonth = null;
            if (isset($input['recurrence_week_of_month']) && $input['recurrence_week_of_month'] !== '' && $input['recurrence_week_of_month'] !== null) {
                $v = (int) $input['recurrence_week_of_month'];
                if ($v >= 1 && $v <= 5) {
                    $weekOfMonth = $v;
                }
            }
            $daysOfWeek = null;
            if (!empty($input['recurrence_days'])) {
                $daysOfWeek = is_array($input['recurrence_days'])
                    ? implode(',', array_map('intval', $input['recurrence_days']))
                    : trim((string) $input['recurrence_days']);
            }
            $customDatesJson = null;
            if ($rt === 'custom') {
                if (!empty($input['custom_session_dates_text_error'])) {
                    return ['ok' => false, 'error' => (string) $input['custom_session_dates_text_error']];
                }
                if (!$db->hasColumn('recurring_events', 'custom_dates')) {
                    return ['ok' => false, 'error' => 'Specific dates require database migration 037 (recurring_events.custom_dates).'];
                }
                $encRes = RecurringEventService::encodeCustomDatesFromInputResult($input, $eventDateYmd);
                if (!empty($encRes['error'])) {
                    return ['ok' => false, 'error' => $encRes['error']];
                }
                $customDatesJson = $encRes['json'] ?? null;
                if ($customDatesJson === null) {
                    return ['ok' => false, 'error' => 'For “Specific dates”, add at least one additional session date (besides the main event date).'];
                }
            }

            $recurrenceData = [
                'parent_event_id' => $eventId,
                'organization_id' => $organizationId,
                'recurrence_type' => $rt,
                'interval' => (int) ($input['recurrence_interval'] ?? 1),
                'end_type' => isset($input['recurrence_end_type']) ? trim((string) $input['recurrence_end_type']) : 'never',
                'end_after_count' => !empty($input['recurrence_end_after_count']) ? (int) $input['recurrence_end_after_count'] : null,
                'end_date' => !empty($input['recurrence_end_date']) ? (string) $input['recurrence_end_date'] : null,
                'days_of_week' => $daysOfWeek !== '' ? $daysOfWeek : null,
                'week_of_month' => $weekOfMonth,
            ];
            if ($db->hasColumn('recurring_events', 'custom_dates')) {
                $recurrenceData['custom_dates'] = $rt === 'custom' ? $customDatesJson : null;
            }

            $existing = $db->queryOne(
                'SELECT id FROM recurring_events WHERE parent_event_id = :eid LIMIT 1',
                ['eid' => $eventId]
            );
            if ($existing) {
                $db->update('recurring_events', (int) $existing['id'], $recurrenceData);
            } else {
                $db->insert('recurring_events', $recurrenceData);
            }

            $recurringService = new RecurringEventService();
            $generatePayload = [
                'recurrence_type' => $recurrenceData['recurrence_type'],
                'interval' => $recurrenceData['interval'],
                'end_type' => $recurrenceData['end_type'],
                'end_after_count' => $recurrenceData['end_after_count'],
                'end_date' => $recurrenceData['end_date'],
                'days_of_week' => $recurrenceData['days_of_week'],
            ];
            if ($recurrenceData['week_of_month'] !== null) {
                $generatePayload['week_of_month'] = $recurrenceData['week_of_month'];
            }
            if ($rt === 'custom' && $customDatesJson !== null) {
                $generatePayload['custom_dates'] = $customDatesJson;
            }
            $generatedIds = $recurringService->generateInstances($eventId, $generatePayload);

            $pruneDeleted = [];
            $pruneSkipped = [];
            if ($rt === 'custom' && $customDatesJson !== null) {
                $pr = RecurringEventService::pruneStaleCustomSeriesChildren($db, $eventId, $customDatesJson);
                $pruneDeleted = $pr['deleted_ids'] ?? [];
                $pruneSkipped = $pr['skipped'] ?? [];
            }

            return [
                'ok' => true,
                'generated' => count($generatedIds),
                'recurrence_pruned_deleted_ids' => $pruneDeleted,
                'recurrence_prune_skipped' => $pruneSkipped,
            ];
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            error_log('AdminEventRecurrenceService::sync: ' . $errMsg);
            if (strpos($errMsg, 'recurrence_type') !== false || strpos($errMsg, 'Data truncated') !== false || strpos($errMsg, 'ENUM') !== false || strpos($errMsg, "doesn't exist") !== false) {
                return ['ok' => false, 'error' => 'Recurring settings could not be saved. For “Monthly (e.g. last Friday)” run migrations 004 and 019. Details: ' . $errMsg];
            }

            return ['ok' => false, 'error' => 'Recurring settings could not be saved: ' . $errMsg];
        }
    }
}
