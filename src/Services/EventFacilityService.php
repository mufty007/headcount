<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Many-to-many event/program ↔ facility links and overlap checks.
 */
class EventFacilityService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function eventPivotExists(): bool
    {
        try {
            return $this->db->tableExists('event_facilities');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function programPivotExists(): bool
    {
        try {
            return $this->db->tableExists('program_facilities');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param mixed $raw facility_id, facility_ids array, or JSON string
     * @return list<int>|false false when any id is invalid
     */
    public function resolveIds(int $organizationId, $raw)
    {
        $ids = [];
        if (is_string($raw) && $raw !== '' && ($raw[0] === '[' || $raw[0] === '{')) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if ($v === '' || $v === null) {
                    continue;
                }
                $ids[] = (int) $v;
            }
        } elseif ($raw !== null && $raw !== '' && $raw !== 0 && $raw !== '0') {
            $ids[] = (int) $raw;
        }

        $ids = array_values(array_unique(array_filter($ids, static fn ($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $valid = [];
        foreach ($ids as $id) {
            $row = $this->db->queryOne(
                "SELECT id FROM facilities WHERE id = :id AND organization_id = :org AND status = 'active' LIMIT 1",
                ['id' => $id, 'org' => $organizationId]
            );
            if (empty($row)) {
                return false;
            }
            $valid[] = $id;
        }
        return $valid;
    }

    /**
     * @return list<int>
     */
    public function idsForEvent(int $eventId): array
    {
        if (!$this->eventPivotExists()) {
            $row = $this->db->queryOne('SELECT facility_id FROM events WHERE id = :id', ['id' => $eventId]);
            $fid = (int) ($row['facility_id'] ?? 0);
            return $fid > 0 ? [$fid] : [];
        }
        $rows = $this->db->query(
            'SELECT facility_id FROM event_facilities WHERE event_id = :id ORDER BY facility_id',
            ['id' => $eventId]
        ) ?: [];
        return array_map(static fn ($r) => (int) $r['facility_id'], $rows);
    }

    /**
     * @return list<int>
     */
    public function idsForProgram(int $programId): array
    {
        if (!$this->programPivotExists()) {
            return [];
        }
        $rows = $this->db->query(
            'SELECT facility_id FROM program_facilities WHERE program_id = :id ORDER BY facility_id',
            ['id' => $programId]
        ) ?: [];
        return array_map(static fn ($r) => (int) $r['facility_id'], $rows);
    }

    /**
     * @param list<int> $facilityIds
     */
    public function syncEvent(int $eventId, int $organizationId, array $facilityIds): void
    {
        $ids = $this->resolveIds($organizationId, $facilityIds);
        if ($ids === false) {
            $ids = [];
        }
        if ($this->eventPivotExists()) {
            $this->db->execute('DELETE FROM event_facilities WHERE event_id = :id', ['id' => $eventId]);
            foreach ($ids as $fid) {
                $this->db->insert('event_facilities', [
                    'event_id' => $eventId,
                    'facility_id' => $fid,
                ]);
            }
        }
        if ($this->db->hasColumn('events', 'facility_id')) {
            $primary = $ids[0] ?? null;
            $this->db->execute(
                'UPDATE events SET facility_id = :fid WHERE id = :id',
                ['fid' => $primary, 'id' => $eventId]
            );
        }
    }

    /**
     * @param list<int> $facilityIds
     */
    public function syncProgram(int $programId, int $organizationId, array $facilityIds): void
    {
        $ids = $this->resolveIds($organizationId, $facilityIds);
        if ($ids === false) {
            $ids = [];
        }
        if (!$this->programPivotExists()) {
            return;
        }
        $this->db->execute('DELETE FROM program_facilities WHERE program_id = :id', ['id' => $programId]);
        foreach ($ids as $fid) {
            $this->db->insert('program_facilities', [
                'program_id' => $programId,
                'facility_id' => $fid,
            ]);
        }
    }

    /**
     * @param list<int> $facilityIds
     * @return list<string>
     */
    public function conflictMessages(
        int $organizationId,
        array $facilityIds,
        string $eventDate,
        string $startTime,
        string $endTime,
        int $excludeEventId = 0,
        int $excludeProgramId = 0
    ): array {
        if ($facilityIds === [] || $eventDate === '' || $startTime === '' || $endTime === '') {
            return [];
        }
        $startTs = strtotime($eventDate . ' ' . $startTime);
        $endTs = strtotime($eventDate . ' ' . $endTime);
        if ($startTs === false || $endTs === false || $endTs <= $startTs) {
            return [];
        }

        $messages = [];
        foreach ($facilityIds as $fid) {
            $name = $this->facilityName($fid);

            $bookings = $this->db->query(
                "SELECT id, title, start_datetime, end_datetime FROM facility_bookings
                 WHERE facility_id = :fid AND organization_id = :org
                   AND status IN ('pending','approved')
                   AND start_datetime < :end AND end_datetime > :start",
                [
                    'fid' => $fid,
                    'org' => $organizationId,
                    'start' => date('Y-m-d H:i:s', $startTs),
                    'end' => date('Y-m-d H:i:s', $endTs),
                ]
            ) ?: [];
            foreach ($bookings as $b) {
                $label = trim((string) ($b['title'] ?? '')) ?: 'a booking';
                $messages[] = $name . ' is already booked for ' . $label . ' at this time.';
            }

            $eventSql = "SELECT e.id, e.title FROM events e
                 WHERE e.organization_id = :org
                   AND e.id != :exclude
                   AND LOWER(TRIM(e.status)) = 'published'
                   AND e.event_date = :d
                   AND e.start_time IS NOT NULL AND e.end_time IS NOT NULL
                   AND e.start_time < :end AND e.end_time > :start";
            $eventParams = [
                'org' => $organizationId,
                'exclude' => $excludeEventId,
                'd' => $eventDate,
                'start' => $startTime,
                'end' => $endTime,
            ];
            if ($this->eventPivotExists()) {
                $eventSql .= ' AND EXISTS (SELECT 1 FROM event_facilities ef WHERE ef.event_id = e.id AND ef.facility_id = :fid)';
                $eventParams['fid'] = $fid;
            } elseif ($this->db->hasColumn('events', 'facility_id')) {
                $eventSql .= ' AND e.facility_id = :fid';
                $eventParams['fid'] = $fid;
            } else {
                $eventSql = '';
            }
            if ($eventSql !== '') {
                foreach ($this->db->query($eventSql, $eventParams) ?: [] as $ev) {
                    $label = trim((string) ($ev['title'] ?? '')) ?: 'another event';
                    $messages[] = $name . ' is already used by ' . $label . ' at this time.';
                }
            }

            if ($this->programPivotExists() && $this->db->tableExists('program_sessions')) {
                $progSql = "SELECT p.id, p.title FROM program_sessions s
                     INNER JOIN programs p ON p.id = s.program_id
                     INNER JOIN program_facilities pf ON pf.program_id = p.id
                     WHERE p.organization_id = :org
                       AND p.id != :excludep
                       AND LOWER(TRIM(p.status)) = 'published'
                       AND pf.facility_id = :fid
                       AND s.session_date = :d
                       AND (s.status IS NULL OR s.status <> 'cancelled')
                       AND s.start_time IS NOT NULL AND s.end_time IS NOT NULL
                       AND s.start_time < :end AND s.end_time > :start";
                $progRows = $this->db->query($progSql, [
                    'org' => $organizationId,
                    'excludep' => $excludeProgramId,
                    'fid' => $fid,
                    'd' => $eventDate,
                    'start' => $startTime,
                    'end' => $endTime,
                ]) ?: [];
                foreach ($progRows as $p) {
                    $label = trim((string) ($p['title'] ?? '')) ?: 'a program';
                    $messages[] = $name . ' is already used by program ' . $label . ' at this time.';
                }
            }
        }

        return array_values(array_unique($messages));
    }

    private function facilityName(int $facilityId): string
    {
        $row = $this->db->queryOne('SELECT name FROM facilities WHERE id = :id', ['id' => $facilityId]);
        $name = trim((string) ($row['name'] ?? ''));
        return $name !== '' ? $name : 'A facility';
    }
}
