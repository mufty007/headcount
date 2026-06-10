<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Recurring series: resolve parent root and published session IDs for RSVP rules.
 */
class EventSeriesHelper
{
    public const MODE_INDEPENDENT = 'independent';
    public const MODE_CHOOSE_ONE = 'choose_one';
    public const MODE_ALL_SESSIONS = 'all_sessions';

    /** @var array<int,int|null> */
    private static $rootIdCache = [];

    /** @var array<int,string> */
    private static $modeCache = [];
    /** @var bool|null */
    private static $hasParentEventIdColumn = null;

    public static function clearRequestCache(): void
    {
        self::$rootIdCache = [];
        self::$modeCache = [];
        self::$hasParentEventIdColumn = null;
    }

    private static function hasParentEventIdColumn(Database $db): bool
    {
        if (self::$hasParentEventIdColumn !== null) {
            return self::$hasParentEventIdColumn;
        }
        try {
            $cols = $db->query("SHOW COLUMNS FROM events");
            self::$hasParentEventIdColumn = in_array('parent_event_id', array_column($cols, 'Field'), true);
        } catch (\Exception $e) {
            self::$hasParentEventIdColumn = false;
        }
        return self::$hasParentEventIdColumn;
    }

    public static function columnExists(Database $db): bool
    {
        try {
            $cols = $db->query("SHOW COLUMNS FROM events");
            return in_array('session_registration_mode', array_column($cols, 'Field'), true);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Recurring child instances may omit RSVP/policy columns; copy them from the series parent row.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    public static function mergeSeriesParentPolicyFields(Database $db, array $event): array
    {
        $eid = (int) ($event['id'] ?? 0);
        if ($eid <= 0) {
            return $event;
        }
        try {
            $seriesRootId = self::getSeriesRootId($db, $eid);
            if ($seriesRootId === null || (int) $seriesRootId === $eid) {
                return $event;
            }
            $policyCols = [
                'allow_guest_rsvp',
                'allow_bring_guests',
                'visibility',
                'registration_deadline',
                'min_age',
                'max_age',
                'gender_restriction',
                'enforce_restrictions_at_checkin',
                'potluck_allowed_slugs',
            ];
            $selectCols = [];
            foreach ($policyCols as $pc) {
                if ($db->hasColumn('events', $pc)) {
                    $selectCols[] = '`' . str_replace('`', '``', $pc) . '`';
                }
            }
            if ($selectCols === []) {
                return $event;
            }
            $policyRow = $db->queryOne(
                'SELECT ' . implode(', ', $selectCols) . ' FROM events WHERE id = ?',
                [$seriesRootId]
            );
            if (!is_array($policyRow)) {
                return $event;
            }
            foreach ($policyRow as $k => $v) {
                $event[$k] = $v;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return $event;
    }

    /**
     * Series root event id, or null if this event is not part of a recurring series.
     */
    public static function getSeriesRootId(Database $db, int $eventId): ?int
    {
        if (array_key_exists($eventId, self::$rootIdCache)) {
            return self::$rootIdCache[$eventId];
        }
        try {
            if (self::hasParentEventIdColumn($db)) {
                $row = $db->queryOne(
                    "SELECT id, parent_event_id FROM events WHERE id = :id",
                    ['id' => $eventId]
                );
            } else {
                $row = $db->queryOne(
                    "SELECT id FROM events WHERE id = :id",
                    ['id' => $eventId]
                );
            }
        } catch (\Exception $e) {
            self::$rootIdCache[$eventId] = null;
            return null;
        }
        if (!$row) {
            self::$rootIdCache[$eventId] = null;
            return null;
        }
        if (!empty($row['parent_event_id'])) {
            self::$rootIdCache[$eventId] = (int) $row['parent_event_id'];
            return self::$rootIdCache[$eventId];
        }
        try {
            $rec = $db->queryOne(
                "SELECT 1 AS ok FROM recurring_events WHERE parent_event_id = :id LIMIT 1",
                ['id' => $eventId]
            );
            if (!empty($rec)) {
                self::$rootIdCache[$eventId] = (int) $row['id'];
                return self::$rootIdCache[$eventId];
            }
        } catch (\Exception $e) {
            // recurring_events may not exist
        }
        self::$rootIdCache[$eventId] = null;
        return null;
    }

    /**
     * Event row that holds rsvps rows for this session (parent for all_sessions instances).
     */
    public static function getRsvpSourceEventId(Database $db, int $eventId): int
    {
        try {
            if (self::hasParentEventIdColumn($db)) {
                $row = $db->queryOne('SELECT id, parent_event_id FROM events WHERE id = :id', ['id' => $eventId]);
            } else {
                $row = $db->queryOne('SELECT id FROM events WHERE id = :id', ['id' => $eventId]);
            }
        } catch (\Exception $e) {
            return $eventId;
        }
        if (!$row) {
            return $eventId;
        }
        $pid = !empty($row['parent_event_id']) ? (int) $row['parent_event_id'] : 0;
        if ($pid !== 0 && self::getSessionRegistrationMode($db, $eventId) === self::MODE_ALL_SESSIONS) {
            return $pid;
        }

        return (int) $row['id'];
    }

    public static function getSessionRegistrationMode(Database $db, int $eventId): string
    {
        if (array_key_exists($eventId, self::$modeCache)) {
            return self::$modeCache[$eventId];
        }
        if (!self::columnExists($db)) {
            self::$modeCache[$eventId] = self::MODE_INDEPENDENT;
            return self::MODE_INDEPENDENT;
        }
        $rootId = self::getSeriesRootId($db, $eventId);
        if (!$rootId) {
            self::$modeCache[$eventId] = self::MODE_INDEPENDENT;
            return self::MODE_INDEPENDENT;
        }
        $root = $db->queryOne(
            "SELECT session_registration_mode FROM events WHERE id = :id",
            ['id' => $rootId]
        );
        $mode = $root['session_registration_mode'] ?? self::MODE_INDEPENDENT;
        if (!in_array($mode, [self::MODE_INDEPENDENT, self::MODE_CHOOSE_ONE, self::MODE_ALL_SESSIONS], true)) {
            $mode = self::MODE_INDEPENDENT;
        }
        self::$modeCache[$eventId] = $mode;
        return $mode;
    }

    /**
     * Published events in the series: parent + instances, ordered by date/time.
     *
     * @return int[]
     */
    public static function getPublishedSeriesEventIds(Database $db, int $rootId): array
    {
        return headcount_published_series_event_ids($db, $rootId);
    }

    /**
     * Load all sessions in a series (root row + children) for one root, ordered by date/time.
     *
     * @return list<array<string,mixed>>
     */
    public static function fetchSeriesSessionsOrderedForRoot(
        Database $db,
        int $rootId,
        int $organizationId,
        ?string $exactStatusFilter
    ): array {
        if ($rootId <= 0) {
            return [];
        }
        $params = [$organizationId, $rootId, $rootId];
        $sql = "SELECT e.id, e.parent_event_id, e.event_date, e.start_time, e.status
            FROM events e
            WHERE e.organization_id = ?
              AND (e.id = ? OR e.parent_event_id = ?)";
        if ($exactStatusFilter !== null && $exactStatusFilter !== '' && strtolower($exactStatusFilter) !== 'all') {
            $sql .= ' AND e.status = ?';
            $params[] = $exactStatusFilter;
        }
        $sql .= ' ORDER BY e.event_date ASC, COALESCE(e.start_time, \'00:00:00\') ASC, e.id ASC';
        try {
            $rows = $db->query($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Batch-load sessions for many series roots (admin events list surface). Keys are root ids.
     *
     * @param int[] $rootIds
     * @return array<int, list<array<string,mixed>>>
     */
    public static function fetchSeriesSessionsGroupedForRoots(
        Database $db,
        array $rootIds,
        ?int $organizationId,
        bool $hasOrganizationFilter,
        string $statusFilter
    ): array {
        $rootIds = array_values(array_unique(array_filter(array_map('intval', $rootIds))));
        if ($rootIds === []) {
            return [];
        }
        $ph = implode(',', $rootIds);
        $params = [];
        $sql = "SELECT e.id, e.parent_event_id, e.event_date, e.start_time, e.status,
            (CASE WHEN e.parent_event_id IS NULL OR e.parent_event_id = 0 THEN e.id ELSE e.parent_event_id END) AS series_root
            FROM events e
            WHERE (e.id IN ({$ph}) OR e.parent_event_id IN ({$ph}))";
        if ($hasOrganizationFilter && $organizationId !== null && (int) $organizationId > 0) {
            $sql .= ' AND e.organization_id = :surf_org_id';
            $params['surf_org_id'] = (int) $organizationId;
        }
        if ($statusFilter !== '' && strtolower($statusFilter) !== 'all') {
            $sql .= ' AND e.status = :surf_status';
            $params['surf_status'] = $statusFilter;
        }
        $sql .= ' ORDER BY series_root ASC, e.event_date ASC, COALESCE(e.start_time, \'00:00:00\') ASC, e.id ASC';
        try {
            $flat = $db->query($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
        $grouped = [];
        foreach ($flat as $sr) {
            if (!is_array($sr)) {
                continue;
            }
            $root = (int) ($sr['series_root'] ?? 0);
            if ($root <= 0) {
                continue;
            }
            if (!isset($grouped[$root])) {
                $grouped[$root] = [];
            }
            $grouped[$root][] = $sr;
        }

        return $grouped;
    }

    /**
     * Prefer first upcoming session (date, then same-day start time); else latest row in the ordered list.
     *
     * @param list<array<string,mixed>> $orderedRows
     * @return array<string,mixed>|null
     */
    public static function pickPreferredSeriesSessionRow(array $orderedRows, string $todayYmd, string $nowHi): ?array
    {
        $picked = null;
        foreach ($orderedRows as $r) {
            $d = substr((string) ($r['event_date'] ?? ''), 0, 10);
            if ($d === '') {
                continue;
            }
            if ($d > $todayYmd) {
                $picked = $r;
                break;
            }
            if ($d === $todayYmd) {
                $st = (string) ($r['start_time'] ?? '');
                if ($st === '' || strcmp($st, $nowHi) > 0) {
                    $picked = $r;
                    break;
                }
            }
        }
        if ($picked === null) {
            foreach ($orderedRows as $r) {
                $picked = $r;
            }
        }

        return $picked;
    }

    /**
     * Admin event-details landing when opening the series root URL: prefer upcoming published,
     * else any upcoming non-cancelled, else latest session (past).
     *
     * @return array{id: int, event_date: mixed, start_time: mixed, status: mixed}|null
     */
    public static function pickPreferredSeriesSessionForDetailsLanding(
        Database $db,
        int $rootId,
        int $organizationId
    ): ?array {
        $rows = self::fetchSeriesSessionsOrderedForRoot($db, $rootId, $organizationId, null);
        $filtered = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $st = strtolower(trim((string) ($r['status'] ?? '')));
            if ($st === 'cancelled') {
                continue;
            }
            $filtered[] = $r;
        }
        if ($filtered === []) {
            return null;
        }
        $todayYmd = date('Y-m-d');
        $nowHi = date('H:i:s');
        $upcoming = [];
        foreach ($filtered as $r) {
            $d = substr((string) ($r['event_date'] ?? ''), 0, 10);
            if ($d === '') {
                continue;
            }
            $isUp = ($d > $todayYmd) || ($d === $todayYmd && ((string) ($r['start_time'] ?? '') === '' || strcmp((string) ($r['start_time'] ?? ''), $nowHi) > 0));
            if ($isUp) {
                $upcoming[] = $r;
            }
        }
        if ($upcoming !== []) {
            foreach ($upcoming as $r) {
                if (strtolower(trim((string) ($r['status'] ?? ''))) === 'published') {
                    return $r;
                }
            }

            return $upcoming[0];
        }

        return self::pickPreferredSeriesSessionRow($filtered, $todayYmd, $nowHi);
    }

    /**
     * @param int[] $userIds
     * @param int[] $seriesEventIds
     */
    public static function clearYesRsvpsExcept(Database $db, array $userIds, array $seriesEventIds, int $keepEventId): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $seriesEventIds = array_values(array_unique(array_filter(array_map('intval', $seriesEventIds))));
        if ($userIds === [] || $seriesEventIds === []) {
            return;
        }
        $otherIds = array_values(array_filter($seriesEventIds, function ($id) use ($keepEventId) {
            return $id !== $keepEventId;
        }));
        if ($otherIds === []) {
            return;
        }
        $uPh = implode(',', array_fill(0, count($userIds), '?'));
        $ePh = implode(',', array_fill(0, count($otherIds), '?'));
        $params = array_merge($userIds, $otherIds);
        $db->execute(
            "UPDATE rsvps SET status = 'no' WHERE user_id IN ($uPh) AND status = 'yes' AND event_id IN ($ePh)",
            $params
        );
    }
}
