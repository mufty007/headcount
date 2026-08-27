<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Combined admin calendar: events, program sessions, facility bookings/blocks.
 */
class MainCalendarService
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEvents(int $organizationId, string $startDate, string $endDate): array
    {
        $startDate = $this->normalizeDate($startDate) ?? date('Y-m-d');
        $endDate = $this->normalizeDate($endDate) ?? date('Y-m-d', strtotime('+30 days'));
        $out = [];

        try {
            $cal = new EventCalendarService();
            foreach ($cal->getCalendarEvents($organizationId, $startDate, $endDate, []) as $ev) {
                $props = is_array($ev['extendedProps'] ?? null) ? $ev['extendedProps'] : [];
                $props['item_type'] = 'event';
                $props['detail_kind'] = 'event';
                $ev['extendedProps'] = $props;
                $ev['backgroundColor'] = '#059669';
                $ev['borderColor'] = '#047857';
                $out[] = $ev;
            }
        } catch (\Throwable $e) {
            error_log('Main calendar events: ' . $e->getMessage());
        }

        try {
            foreach ($this->programSessionEvents($organizationId, $startDate, $endDate) as $ev) {
                $out[] = $ev;
            }
        } catch (\Throwable $e) {
            error_log('Main calendar programs: ' . $e->getMessage());
        }

        try {
            foreach ($this->facilityEvents($organizationId, $startDate, $endDate) as $ev) {
                $out[] = $ev;
            }
        } catch (\Throwable $e) {
            error_log('Main calendar facilities: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function programSessionEvents(int $organizationId, string $startDate, string $endDate): array
    {
        if (!$this->db->tableExists('program_sessions') || !$this->db->tableExists('programs')) {
            return [];
        }
        $rows = $this->db->query(
            "SELECT s.id, s.program_id, s.session_date, s.start_time, s.end_time,
                    p.title, p.status, p.location
             FROM program_sessions s
             INNER JOIN programs p ON p.id = s.program_id
             WHERE p.organization_id = :org
               AND LOWER(TRIM(p.status)) IN ('draft','published')
               AND s.session_date >= :start AND s.session_date <= :end
             ORDER BY s.session_date ASC, s.start_time ASC",
            ['org' => $organizationId, 'start' => $startDate, 'end' => $endDate]
        ) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $date = $this->normalizeDate($row['session_date'] ?? '');
            if ($date === null) {
                continue;
            }
            $startTime = $this->normalizeTime($row['start_time'] ?? null);
            $endTime = $this->normalizeTime($row['end_time'] ?? null);
            $allDay = ($startTime === null || $endTime === null);
            $title = trim(html_entity_decode((string) ($row['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title === '') {
                $title = 'Program';
            }
            $out[] = [
                'id' => 'program-' . (int) $row['id'],
                'title' => $title,
                'start' => $allDay ? $date : ($date . 'T' . $startTime . ':00'),
                'end' => $allDay ? date('Y-m-d', strtotime($date . ' +1 day')) : ($date . 'T' . $endTime . ':00'),
                'allDay' => $allDay,
                'backgroundColor' => '#7c3aed',
                'borderColor' => '#6d28d9',
                'extendedProps' => [
                    'item_type' => 'program',
                    'detail_kind' => 'program',
                    'status' => strtolower(trim((string) ($row['status'] ?? ''))),
                    'location' => trim((string) ($row['location'] ?? '')),
                    'program_id' => (int) $row['program_id'],
                    'session_id' => (int) $row['id'],
                ],
            ];
        }
        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function facilityEvents(int $organizationId, string $startDate, string $endDate): array
    {
        if (!$this->db->tableExists('facility_bookings')) {
            return [];
        }
        $bookSvc = new FacilityBookingService();
        $raw = $bookSvc->getOrgCalendarForAdmin($organizationId, $startDate, $endDate);
        $out = [];
        foreach ($raw as $ev) {
            $props = is_array($ev['extendedProps'] ?? null) ? $ev['extendedProps'] : [];
            $type = (string) ($props['type'] ?? '');
            if ($type === 'headcount_event') {
                continue;
            }
            $pending = ($type === 'booking_pending');
            $block = ($type === 'manual_block');
            $props['item_type'] = 'facility';
            $props['detail_kind'] = 'facility';
            $ev['extendedProps'] = $props;
            $ev['backgroundColor'] = $pending ? '#d97706' : ($block ? '#94a3b8' : '#2563eb');
            $ev['borderColor'] = $pending ? '#b45309' : ($block ? '#64748b' : '#1d4ed8');
            $out[] = $ev;
        }
        return $out;
    }

    private function normalizeDate($value): ?string
    {
        $s = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $s, $m)) {
            return substr($m[0], 0, 10);
        }
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d', $ts);
    }

    private function normalizeTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return sprintf('%02d:%02d', min(23, (int) $m[1]), min(59, (int) $m[2]));
        }
        return null;
    }
}
