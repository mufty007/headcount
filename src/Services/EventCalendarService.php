<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Admin events calendar feed (FullCalendar-shaped payloads).
 */
class EventCalendarService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * @param array{status?:string,category_id?:int|string} $filters
     * @return list<array<string, mixed>>
     */
    public function getCalendarEvents(int $organizationId, string $startDate, string $endDate, array $filters = []): array
    {
        $startDate = $this->normalizeDate($startDate);
        $endDate = $this->normalizeDate($endDate);
        if ($startDate === null || $endDate === null) {
            return [];
        }

        $sql = 'SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.status, e.location,
                       e.facility_id, e.parent_event_id, e.banner_image,
                       f.name AS facility_name
                FROM events e
                LEFT JOIN facilities f ON f.id = e.facility_id
                WHERE e.organization_id = :org
                  AND e.event_date >= :start AND e.event_date <= :end';
        $params = [
            'org' => $organizationId,
            'start' => $startDate,
            'end' => $endDate,
        ];

        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && $status !== 'all') {
            $sql .= ' AND LOWER(TRIM(e.status)) = :status';
            $params['status'] = strtolower($status);
        } else {
            $sql .= " AND LOWER(TRIM(e.status)) NOT IN ('deleted', 'cancelled')";
        }

        if (!empty($filters['category_id']) && $this->db->hasColumn('events', 'category')) {
            $sql .= ' AND e.category = :cat';
            $params['cat'] = (int) $filters['category_id'];
        }

        $sql .= ' ORDER BY e.event_date ASC, e.start_time ASC, e.id ASC';
        $rows = $this->db->query($sql, $params);

        $hasRsvps = $this->db->tableExists('rsvps');
        $guestCol = '';
        if ($hasRsvps) {
            try {
                $cols = $this->db->query('SHOW COLUMNS FROM rsvps');
                if (in_array('guest_count', array_column($cols, 'Field'), true)) {
                    $guestCol = ', guest_count';
                }
            } catch (\Throwable $e) {
                $guestCol = '';
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $eventId = (int) ($row['id'] ?? 0);
            $title = trim(html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title === '') {
                $title = 'Event';
            }

            $date = $this->normalizeDate($row['event_date'] ?? '');
            if ($date === null) {
                continue;
            }

            $startTime = $this->normalizeTime($row['start_time'] ?? null);
            $endTime = $this->normalizeTime($row['end_time'] ?? null);
            $allDay = ($startTime === null || $endTime === null);

            if ($allDay) {
                $start = $date;
                $end = date('Y-m-d', strtotime($date . ' +1 day'));
            } else {
                $start = $date . 'T' . $startTime . ':00';
                $end = $date . 'T' . $endTime . ':00';
            }

            $rsvpHeads = 0;
            $checkedIn = 0;
            if ($hasRsvps && $eventId > 0) {
                $rsvpRow = $this->db->queryOne(
                    $guestCol !== ''
                        ? "SELECT COUNT(*) AS registrants, COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0) AS heads
                           FROM rsvps WHERE event_id = :eid AND status = 'yes'"
                        : "SELECT COUNT(*) AS registrants, COUNT(*) AS heads
                           FROM rsvps WHERE event_id = :eid AND status = 'yes'",
                    ['eid' => $eventId]
                );
                $rsvpHeads = (int) ($rsvpRow['heads'] ?? 0);

                if ($this->db->tableExists('attendance')) {
                    $attRow = $this->db->queryOne(
                        'SELECT COUNT(*) AS c FROM attendance WHERE event_id = :eid AND checked_in_at IS NOT NULL',
                        ['eid' => $eventId]
                    );
                    $checkedIn = (int) ($attRow['c'] ?? 0);
                }
            }

            $parentId = (int) ($row['parent_event_id'] ?? 0);
            $statusVal = strtolower(trim((string) ($row['status'] ?? '')));

            $out[] = [
                'id' => $eventId,
                'title' => $title,
                'start' => $start,
                'end' => $end,
                'allDay' => $allDay,
                'extendedProps' => [
                    'status' => $statusVal,
                    'location' => trim(html_entity_decode((string) ($row['location'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                    'facility_id' => !empty($row['facility_id']) ? (int) $row['facility_id'] : null,
                    'facility_name' => trim((string) ($row['facility_name'] ?? '')),
                    'rsvp_yes_heads' => $rsvpHeads,
                    'checked_in' => $checkedIn,
                    'is_recurring_child' => $parentId > 0,
                    'parent_event_id' => $parentId > 0 ? $parentId : null,
                    'banner_image' => trim((string) ($row['banner_image'] ?? '')),
                ],
            ];
        }

        return $out;
    }

    private function normalizeDate($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        $s = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return $s;
        }
        $ts = strtotime($s);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }

    private function normalizeTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }
        $ts = strtotime('1970-01-01 ' . $s);

        return $ts !== false ? date('H:i', $ts) : null;
    }
}
