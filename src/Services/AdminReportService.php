<?php

declare(strict_types=1);

namespace Headcount\Services;

use Headcount\Helpers\Database;

final class AdminReportService
{
    public function __construct(
        private readonly Database $db,
        private readonly int $organizationId,
        private readonly ReportFilterSet $filters,
    ) {
    }

    public function getDb(): Database
    {
        return $this->db;
    }

    public function getOrganizationId(): int
    {
        return $this->organizationId;
    }

    public function getFilters(): ReportFilterSet
    {
        return $this->filters;
    }

    /**
     * Events for focus dropdown (respects category + title filters; ignores single-event filter).
     *
     * @return list<array{id: int, title: string, event_date: string}>
     */
    public function getEventPickerList(int $limit = 80): array
    {
        $params = [
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
            'org_id' => $this->organizationId,
        ];
        $sql = '';
        if ($this->filters->categories !== []) {
            $ph = [];
            foreach ($this->filters->categories as $i => $cat) {
                $k = "_pick_cat_{$i}";
                $ph[] = ':' . $k;
                $params[$k] = $cat;
            }
            $sql .= ' AND e.category IN (' . implode(',', $ph) . ')';
        }
        if ($this->filters->searchQuery !== '') {
            $sql .= ' AND e.title LIKE :_pick_title';
            $params['_pick_title'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->filters->searchQuery) . '%';
        }
        try {
            return $this->db->query(
                "SELECT e.id, e.title, e.event_date FROM events e
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$sql}
                 ORDER BY e.event_date DESC, e.id DESC LIMIT " . (int) $limit,
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function eventFilterSql(string $alias, array &$params): string
    {
        $sql = '';
        if ($this->filters->eventId !== null) {
            $sql .= " AND {$alias}.id = :_rf_event_id";
            $params['_rf_event_id'] = $this->filters->eventId;
        }
        if ($this->filters->categories !== []) {
            $ph = [];
            foreach ($this->filters->categories as $i => $cat) {
                $k = "_rf_cat_{$i}";
                $ph[] = ':' . $k;
                $params[$k] = $cat;
            }
            $sql .= ' AND ' . $alias . '.category IN (' . implode(',', $ph) . ')';
        }
        if ($this->filters->searchQuery !== '') {
            $sql .= " AND {$alias}.title LIKE :_rf_title";
            $params['_rf_title'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $this->filters->searchQuery) . '%';
        }

        return $sql;
    }

    private function paymentAmountExpr(): string
    {
        if ($this->filters->revenueStatus === 'all') {
            return "CASE WHEN p.status IN ('paid','pending') THEN p.amount ELSE 0 END";
        }

        return "CASE WHEN p.status = 'paid' THEN p.amount ELSE 0 END";
    }

    private function paymentCountSumExpr(): string
    {
        if ($this->filters->revenueStatus === 'all') {
            return "SUM(CASE WHEN p.status IN ('paid','pending') THEN 1 ELSE 0 END)";
        }

        return "SUM(CASE WHEN p.status = 'paid' THEN 1 ELSE 0 END)";
    }

    /**
     * @return array<string, int|float>
     */
    public function getCoreStats(): array
    {
        $start = $this->filters->startDate;
        $end = $this->filters->endDate;
        $org = $this->organizationId;
        $params = [
            'start_date' => $start,
            'end_date' => $end,
            'org_id' => $org,
            'start_datetime' => $start . ' 00:00:00',
            'end_datetime' => $end . ' 23:59:59',
        ];
        $ef = $this->eventFilterSql('e', $params);

        $stats = [
            'total_events' => 0,
            'total_attendance' => 0,
            'unique_attendees' => 0,
            'avg_attendance' => 0.0,
            'total_members' => 0,
            'total_rsvps' => 0,
        ];

        try {
            $r = $this->db->queryOne(
                "SELECT COUNT(*) as c FROM events e WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}",
                $params
            );
            $stats['total_events'] = $r ? (int) $r['c'] : 0;
        } catch (\Throwable) {
        }

        try {
            $r = $this->db->queryOne(
                "SELECT COUNT(*) as c FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}",
                $params
            );
            $stats['total_attendance'] = $r ? (int) $r['c'] : 0;
        } catch (\Throwable) {
        }

        try {
            $r = $this->db->queryOne(
                "SELECT COUNT(DISTINCT a.user_id) as c FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}",
                $params
            );
            $stats['unique_attendees'] = $r ? (int) $r['c'] : 0;
        } catch (\Throwable) {
        }

        if ($stats['total_events'] > 0) {
            $stats['avg_attendance'] = round($stats['total_attendance'] / $stats['total_events'], 1);
        }

        try {
            $r = $this->db->queryOne(
                'SELECT COUNT(*) as c FROM users WHERE role = \'member\' AND status = \'active\' AND organization_id = :org_id',
                ['org_id' => $org]
            );
            $stats['total_members'] = $r ? (int) $r['c'] : 0;
        } catch (\Throwable) {
        }

        try {
            $r = $this->db->queryOne(
                "SELECT COUNT(*) as c FROM rsvps r INNER JOIN events e ON r.event_id = e.id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id AND r.status = 'yes'{$ef}",
                $params
            );
            $stats['total_rsvps'] = $r ? (int) $r['c'] : 0;
        } catch (\Throwable) {
        }

        return $stats;
    }

    /**
     * @return array{total_revenue: float, paid_count: int}|array<string, mixed>
     */
    public function getRevenueStats(): array
    {
        $start = $this->filters->startDate;
        $end = $this->filters->endDate;
        $params = [
            'start_datetime' => $start . ' 00:00:00',
            'end_datetime' => $end . ' 23:59:59',
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        $amt = $this->paymentAmountExpr();
        $cntSum = $this->paymentCountSumExpr();
        try {
            $r = $this->db->queryOne(
                "SELECT COALESCE(SUM({$amt}), 0) as total_revenue, {$cntSum} as paid_count
                 FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 LEFT JOIN payments p ON a.id = p.attendance_id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}",
                $params
            );
        } catch (\Throwable) {
            return ['total_revenue' => 0.0, 'paid_count' => 0];
        }

        return [
            'total_revenue' => $r ? (float) $r['total_revenue'] : 0.0,
            'paid_count' => $r ? (int) $r['paid_count'] : 0,
        ];
    }

    /**
     * @return array{count: int, rate: float}
     */
    public function getNoShowStats(int $totalRsvpYes): array
    {
        $start = $this->filters->startDate;
        $end = $this->filters->endDate;
        $params = ['start_date' => $start, 'end_date' => $end, 'org_id' => $this->organizationId];
        $ef = $this->eventFilterSql('e', $params);
        try {
            $r = $this->db->queryOne(
                "SELECT COUNT(*) as cnt FROM rsvps r INNER JOIN events e ON r.event_id = e.id
                 LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id
                 AND r.status = 'yes' AND a.id IS NULL{$ef}",
                $params
            );
            $cnt = $r ? (int) $r['cnt'] : 0;
        } catch (\Throwable) {
            return ['count' => 0, 'rate' => 0.0];
        }
        $rate = $totalRsvpYes > 0 ? round(($cnt / $totalRsvpYes) * 100, 1) : 0.0;

        return ['count' => $cnt, 'rate' => $rate];
    }

    /**
     * @return array<string, int>|null
     */
    public function getPrevPeriodStats(): ?array
    {
        if (!$this->filters->compare) {
            return null;
        }
        $start = $this->filters->prevStartDate;
        $end = $this->filters->prevEndDate;
        $org = $this->organizationId;
        $params = [
            'start_date' => $start,
            'end_date' => $end,
            'org_id' => $org,
            'start_datetime' => $start . ' 00:00:00',
            'end_datetime' => $end . ' 23:59:59',
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            $pTotalEvents = $this->db->queryOne(
                "SELECT COUNT(*) as count FROM events e WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}",
                $params
            );
            $pTotalAtt = $this->db->queryOne(
                "SELECT COUNT(*) as count FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}",
                $params
            );
            $pUnique = $this->db->queryOne(
                "SELECT COUNT(DISTINCT a.user_id) as count FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}",
                $params
            );
            $pRsvps = $this->db->queryOne(
                "SELECT COUNT(*) as count FROM rsvps r INNER JOIN events e ON r.event_id = e.id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id AND r.status = 'yes'{$ef}",
                $params
            );
            $prev = [
                'total_events' => $pTotalEvents ? (int) $pTotalEvents['count'] : 0,
                'total_attendance' => $pTotalAtt ? (int) $pTotalAtt['count'] : 0,
                'unique_attendees' => $pUnique ? (int) $pUnique['count'] : 0,
                'total_rsvps' => $pRsvps ? (int) $pRsvps['count'] : 0,
            ];
            $prev['avg_attendance'] = $prev['total_events'] > 0 ? round($prev['total_attendance'] / $prev['total_events'], 1) : 0;
            $pNoShow = $this->db->queryOne(
                "SELECT COUNT(*) as count FROM rsvps r INNER JOIN events e ON r.event_id = e.id
                 LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id
                 AND r.status = 'yes' AND a.id IS NULL{$ef}",
                $params
            );
            $prev['no_show_count'] = $pNoShow ? (int) $pNoShow['count'] : 0;

            return $prev;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTopEvents(): array
    {
        $params = [
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            return $this->db->query(
                "SELECT e.title, e.event_date, COUNT(a.id) as attendance_count
                 FROM events e LEFT JOIN attendance a ON e.id = a.event_id
                   AND a.checked_in_at IS NOT NULL
                   AND a.checked_in_at BETWEEN :start_datetime AND :end_datetime
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.id ORDER BY attendance_count DESC LIMIT 10",
                array_merge($params, [
                    'start_datetime' => $this->filters->startDate . ' 00:00:00',
                    'end_datetime' => $this->filters->endDate . ' 23:59:59',
                ])
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCategoryData(): array
    {
        $params = [
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            return $this->db->query(
                "SELECT COALESCE(e.category, 'Uncategorized') as category, COUNT(a.id) as attendance_count
                 FROM events e LEFT JOIN attendance a ON e.id = a.event_id
                   AND a.checked_in_at IS NOT NULL
                   AND a.checked_in_at BETWEEN :start_datetime AND :end_datetime
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.category ORDER BY attendance_count DESC",
                array_merge($params, [
                    'start_datetime' => $this->filters->startDate . ' 00:00:00',
                    'end_datetime' => $this->filters->endDate . ' 23:59:59',
                ])
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    public function getTrendData(): array
    {
        $startDate = $this->filters->startDate;
        $endDate = $this->filters->endDate;
        $params = [
            'start_datetime' => $startDate . ' 00:00:00',
            'end_datetime' => $endDate . ' 23:59:59',
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            $raw = $this->db->query(
                "SELECT DATE(a.checked_in_at) as date, COUNT(a.id) as count
                 FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}
                 GROUP BY DATE(a.checked_in_at) ORDER BY date ASC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $dataMap = [];
        foreach ($raw as $row) {
            $dataMap[$row['date']] = (int) $row['count'];
        }
        $trendData = [];
        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $current = clone $start;
        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $trendData[] = ['date' => $dateStr, 'count' => $dataMap[$dateStr] ?? 0];
            $current->modify('+1 day');
        }

        return $trendData;
    }

    /**
     * @return list<array{date: string, rsvp_count: int, attendance_count: int}>
     */
    public function getRsvpVsAttendanceTrend(): array
    {
        $startDate = $this->filters->startDate;
        $endDate = $this->filters->endDate;
        $org = $this->organizationId;
        $params = ['start_date' => $startDate, 'end_date' => $endDate, 'org_id' => $org];
        $ef = $this->eventFilterSql('e', $params);
        $out = [];
        try {
            $rsvpByDate = $this->db->query(
                "SELECT e.event_date as date, COUNT(r.id) as rsvp_count FROM events e
                 LEFT JOIN rsvps r ON r.event_id = e.id AND r.status = 'yes'
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.event_date",
                $params
            ) ?: [];
            $attByEventDate = $this->db->query(
                "SELECT e.event_date as date, COUNT(a.id) as att_count FROM events e
                 LEFT JOIN attendance a ON a.event_id = e.id AND a.checked_in_at IS NOT NULL
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.event_date",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        $rsvpMap = [];
        foreach ($rsvpByDate as $row) {
            $rsvpMap[$row['date']] = (int) $row['rsvp_count'];
        }
        $attMap = [];
        foreach ($attByEventDate as $row) {
            $attMap[$row['date']] = (int) $row['att_count'];
        }
        $startDt = new \DateTime($startDate);
        $endDt = new \DateTime($endDate);
        $cur = clone $startDt;
        while ($cur <= $endDt) {
            $d = $cur->format('Y-m-d');
            $out[] = [
                'date' => $d,
                'rsvp_count' => $rsvpMap[$d] ?? 0,
                'attendance_count' => $attMap[$d] ?? 0,
            ];
            $cur->modify('+1 day');
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getTopAttendees(): array
    {
        $params = [
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
            'org_id' => $this->organizationId,
            'org_id2' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            return $this->db->query(
                "SELECT u.first_name, u.last_name, u.email, COUNT(a.id) as attendance_count
                 FROM users u JOIN attendance a ON u.id = a.user_id INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id AND u.organization_id = :org_id2{$ef}
                 GROUP BY u.id ORDER BY attendance_count DESC LIMIT 10",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{new: int, returning: int}
     */
    public function getNewVsReturningCounts(): array
    {
        $params = [
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
            'org_id' => $this->organizationId,
            'org_id2' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        $startDate = $this->filters->startDate;
        try {
            $rows = $this->db->query(
                "SELECT MIN(a.checked_in_at) as first_attendance FROM users u
                 JOIN attendance a ON u.id = a.user_id INNER JOIN events e ON a.event_id = e.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id AND u.organization_id = :org_id2{$ef}
                 GROUP BY u.id",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return ['new' => 0, 'returning' => 0];
        }
        $new = 0;
        foreach ($rows as $attendee) {
            if (strtotime((string) $attendee['first_attendance']) >= strtotime($startDate)) {
                $new++;
            }
        }
        $total = count($rows);

        return ['new' => $new, 'returning' => $total - $new];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getEventPerformanceList(): array
    {
        $params = [
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            $rsvpCols = $this->db->query('SHOW COLUMNS FROM rsvps');
            $hasGuestCount = in_array('guest_count', array_column($rsvpCols, 'Field'), true);
            $guestCountSql = $hasGuestCount ? 'COALESCE(SUM(r_g.guest_count), 0)' : '0';
            $list = $this->db->query(
                "SELECT e.id, e.title, e.event_date, e.category, e.capacity,
                    (SELECT COUNT(*) FROM rsvps r2 WHERE r2.event_id = e.id AND r2.status = 'yes'
                     AND r2.created_at BETWEEN :start_datetime AND :end_datetime) as rsvp_yes,
                    (SELECT {$guestCountSql} FROM rsvps r_g WHERE r_g.event_id = e.id AND r_g.status = 'yes'
                     AND r_g.created_at BETWEEN :start_datetime AND :end_datetime) as additional_guests,
                    (SELECT COUNT(*) FROM attendance a2 WHERE a2.event_id = e.id AND a2.checked_in_at IS NOT NULL
                     AND a2.checked_in_at BETWEEN :start_datetime AND :end_datetime) as checked_in,
                    (SELECT COUNT(*) FROM rsvps r3 LEFT JOIN attendance a3 ON a3.event_id = r3.event_id AND a3.user_id = r3.user_id
                     AND a3.checked_in_at BETWEEN :start_datetime AND :end_datetime
                     WHERE r3.event_id = e.id AND r3.status = 'yes' AND r3.created_at BETWEEN :start_datetime AND :end_datetime AND a3.id IS NULL) as no_show_count
                 FROM events e
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 ORDER BY e.event_date DESC, e.id",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        foreach ($list as &$row) {
            $row['rsvp_yes'] = (int) $row['rsvp_yes'];
            $row['additional_guests'] = (int) ($row['additional_guests'] ?? 0);
            $row['total_expected'] = $row['rsvp_yes'] + $row['additional_guests'];
            $row['checked_in'] = (int) $row['checked_in'];
            $row['no_show_count'] = (int) $row['no_show_count'];
            $row['no_show_pct'] = $row['rsvp_yes'] > 0 ? round(($row['no_show_count'] / $row['rsvp_yes']) * 100, 1) : 0;
            $row['utilization_pct'] = null;
            if (isset($row['capacity']) && (int) $row['capacity'] > 0) {
                $row['utilization_pct'] = round(($row['checked_in'] / (int) $row['capacity']) * 100, 1);
            }
        }
        unset($row);

        return $this->applyThresholdFilters($list);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRsvpReportEvents(): array
    {
        $params = [
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            $rsvpCols = $this->db->query('SHOW COLUMNS FROM rsvps');
            $hasGuestCount = in_array('guest_count', array_column($rsvpCols, 'Field'), true);
            $guestCountSql = $hasGuestCount ? 'COALESCE(SUM(r2.guest_count), 0)' : '0';
            $list = $this->db->query(
                "SELECT e.id, e.title, e.event_date,
                    COUNT(r2.id) as rsvp_yes,
                    {$guestCountSql} as additional_guests,
                    (SELECT COUNT(*) FROM attendance a2 WHERE a2.event_id = e.id AND a2.checked_in_at IS NOT NULL
                     AND a2.checked_in_at BETWEEN :start_datetime AND :end_datetime) as checked_in,
                    (SELECT COUNT(*) FROM rsvps r3 LEFT JOIN attendance a3 ON a3.event_id = r3.event_id AND a3.user_id = r3.user_id
                     AND a3.checked_in_at BETWEEN :start_datetime AND :end_datetime
                     WHERE r3.event_id = e.id AND r3.status = 'yes' AND r3.created_at BETWEEN :start_datetime AND :end_datetime AND a3.id IS NULL) as no_show_count
                 FROM events e LEFT JOIN rsvps r2 ON e.id = r2.event_id AND r2.status = 'yes'
                   AND r2.created_at BETWEEN :start_datetime AND :end_datetime
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.id, e.title, e.event_date ORDER BY e.event_date DESC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        foreach ($list as &$row) {
            $row['rsvp_yes'] = (int) $row['rsvp_yes'];
            $row['additional_guests'] = (int) ($row['additional_guests'] ?? 0);
            $row['total_expected'] = $row['rsvp_yes'] + $row['additional_guests'];
            $row['checked_in'] = (int) $row['checked_in'];
            $row['no_show_count'] = (int) $row['no_show_count'];
            $row['no_show_pct'] = $row['rsvp_yes'] > 0 ? round(($row['no_show_count'] / $row['rsvp_yes']) * 100, 1) : 0;
        }
        unset($row);

        return $this->applyThresholdFilters($list);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getMemberEngagementList(): array
    {
        $start = $this->filters->startDate;
        $end = $this->filters->endDate;
        $org = $this->organizationId;
        $params = [
            'start_datetime' => $start . ' 00:00:00',
            'end_datetime' => $end . ' 23:59:59',
            'org_id' => $org,
            'start_date' => $start,
            'end_date' => $end,
            'org_id2' => $org,
            'start_date2' => $start,
            'end_date2' => $end,
            'org_id3' => $org,
            'start_datetime2' => $start . ' 00:00:00',
            'end_datetime2' => $end . ' 23:59:59',
            'org_id4' => $org,
            'org_id5' => $org,
        ];
        $fe = $this->eventFilterSql('e', $params);
        $fe2 = $this->eventFilterSql('e2', $params);

        $existsAtt = "EXISTS (SELECT 1 FROM events e WHERE e.id = a.event_id AND e.organization_id = :org_id{$fe})";

        try {
            $list = $this->db->query(
                "SELECT u.id, u.first_name, u.last_name, u.email,
                    (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.id AND a.checked_in_at BETWEEN :start_datetime AND :end_datetime
                     AND {$existsAtt}) as events_attended,
                    (SELECT COUNT(*) FROM rsvps r INNER JOIN events e ON e.id = r.event_id
                     WHERE r.user_id = u.id AND r.status = 'yes' AND e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id2{$fe}) as events_rsvpd,
                    (SELECT COUNT(*) FROM rsvps r2 INNER JOIN events e2 ON e2.id = r2.event_id
                     LEFT JOIN attendance a2 ON a2.event_id = r2.event_id AND a2.user_id = r2.user_id
                     WHERE r2.user_id = u.id AND r2.status = 'yes' AND e2.event_date BETWEEN :start_date2 AND :end_date2 AND e2.organization_id = :org_id3 AND a2.id IS NULL{$fe2}) as no_shows,
                    (SELECT MAX(e.event_date) FROM attendance a INNER JOIN events e ON e.id = a.event_id
                     WHERE a.user_id = u.id AND e.organization_id = :org_id4 AND a.checked_in_at BETWEEN :start_datetime2 AND :end_datetime2{$fe}) as last_attended
                 FROM users u
                 WHERE u.organization_id = :org_id5 AND u.role = 'member' AND u.status = 'active'
                 HAVING events_attended > 0 OR events_rsvpd > 0
                 ORDER BY events_attended DESC, events_rsvpd DESC LIMIT 200",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        foreach ($list as &$row) {
            $row['events_attended'] = (int) $row['events_attended'];
            $row['events_rsvpd'] = (int) $row['events_rsvpd'];
            $row['no_shows'] = (int) $row['no_shows'];
            $row['attendance_rate'] = $row['events_rsvpd'] > 0
                ? round(($row['events_attended'] / $row['events_rsvpd']) * 100, 1)
                : ($row['events_attended'] > 0 ? 100 : 0);
        }
        unset($row);

        return $list;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRevenueByEventList(): array
    {
        $params = ['start_date' => $this->filters->startDate, 'end_date' => $this->filters->endDate, 'org_id' => $this->organizationId];
        $ef = $this->eventFilterSql('e', $params);
        $amt = $this->paymentAmountExpr();
        $cntSum = $this->paymentCountSumExpr();
        try {
            return $this->db->query(
                "SELECT e.id, e.title, e.event_date,
                    COALESCE(SUM({$amt}), 0) as revenue,
                    {$cntSum} as paid_count
                 FROM events e
                 LEFT JOIN attendance a ON a.event_id = e.id AND a.checked_in_at IS NOT NULL
                 LEFT JOIN payments p ON p.attendance_id = a.id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.id ORDER BY revenue DESC, e.event_date DESC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function getRsvpDetailExportRows(): array
    {
        $params = ['start_date' => $this->filters->startDate, 'end_date' => $this->filters->endDate, 'org_id' => $this->organizationId];
        $ef = $this->eventFilterSql('e', $params);
        try {
            $rsvpCols = $this->db->query('SHOW COLUMNS FROM rsvps');
            $guestCountCol = in_array('guest_count', array_column($rsvpCols, 'Field'), true) ? ', r.guest_count' : '';

            return $this->db->query(
                "SELECT e.title as event_title, e.event_date, u.first_name, u.last_name, u.email, u.password_hash, r.status as rsvp_status, r.created_at, r.notes{$guestCountCol},
                 (SELECT 1 FROM attendance a WHERE a.event_id = r.event_id AND a.user_id = r.user_id LIMIT 1) as has_attendance
                 FROM rsvps r INNER JOIN events e ON e.id = r.event_id INNER JOIN users u ON u.id = r.user_id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 ORDER BY e.event_date DESC, e.id, u.last_name, u.first_name",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getAttendanceExportRows(): array
    {
        $params = [
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        try {
            return $this->db->query(
                "SELECT e.id, e.title, e.event_date, e.location, COUNT(a.id) as attendance
                 FROM events e LEFT JOIN attendance a ON e.id = a.event_id AND a.checked_in_at IS NOT NULL
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 GROUP BY e.id ORDER BY e.event_date DESC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{month: string, revenue: float}>
     */
    public function getRevenueMonthlyTrend(): array
    {
        $params = [
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
            'org_id' => $this->organizationId,
        ];
        $ef = $this->eventFilterSql('e', $params);
        $amt = $this->paymentAmountExpr();
        try {
            $rows = $this->db->query(
                "SELECT DATE_FORMAT(a.checked_in_at, '%Y-%m') as month, COALESCE(SUM({$amt}), 0) as revenue
                 FROM attendance a INNER JOIN events e ON a.event_id = e.id
                 LEFT JOIN payments p ON p.attendance_id = a.id
                 WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id{$ef}
                 GROUP BY DATE_FORMAT(a.checked_in_at, '%Y-%m') ORDER BY month ASC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn ($r) => ['month' => (string) $r['month'], 'revenue' => (float) $r['revenue']], $rows);
    }

    private function reportTableExists(string $table): bool
    {
        return $this->db->tableExists($table);
    }

    /**
     * @return array{total_bookings:int,pending:int,approved:int,rejected:int,cancelled:int,revenue:float}
     */
    public function getFacilityReportStats(): array
    {
        $defaults = [
            'total_bookings' => 0,
            'pending' => 0,
            'approved' => 0,
            'rejected' => 0,
            'cancelled' => 0,
            'revenue' => 0.0,
        ];
        if (!$this->reportTableExists('facility_bookings')) {
            return $defaults;
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
        ];
        $sql = '';
        if ($this->filters->facilityId !== null) {
            $sql .= ' AND b.facility_id = :facility_id';
            $params['facility_id'] = $this->filters->facilityId;
        }
        try {
            $rows = $this->db->query(
                "SELECT b.status, COUNT(*) AS c,
                        COALESCE(SUM(CASE WHEN b.payment_status = 'captured' THEN b.total_amount ELSE 0 END), 0) AS rev
                 FROM facility_bookings b
                 WHERE b.organization_id = :org_id
                   AND b.start_datetime BETWEEN :start_datetime AND :end_datetime{$sql}
                 GROUP BY b.status",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return $defaults;
        }
        $out = $defaults;
        foreach ($rows as $row) {
            $st = (string) ($row['status'] ?? '');
            $c = (int) ($row['c'] ?? 0);
            $out['total_bookings'] += $c;
            if (isset($out[$st])) {
                $out[$st] = $c;
            }
            $out['revenue'] += (float) ($row['rev'] ?? 0);
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getFacilityPerformanceList(): array
    {
        if (!$this->reportTableExists('facilities') || !$this->reportTableExists('facility_bookings')) {
            return [];
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_datetime' => $this->filters->startDate . ' 00:00:00',
            'end_datetime' => $this->filters->endDate . ' 23:59:59',
        ];
        $sql = '';
        if ($this->filters->facilityId !== null) {
            $sql .= ' AND f.id = :facility_id';
            $params['facility_id'] = $this->filters->facilityId;
        }
        try {
            return $this->db->query(
                "SELECT f.id, f.name, f.capacity,
                        COUNT(b.id) AS booking_count,
                        SUM(CASE WHEN b.status = 'approved' THEN 1 ELSE 0 END) AS approved_count,
                        SUM(CASE WHEN b.status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                        COALESCE(SUM(CASE WHEN b.payment_status = 'captured' THEN b.total_amount ELSE 0 END), 0) AS revenue,
                        COALESCE(SUM(CASE WHEN b.status IN ('approved','pending') THEN COALESCE(b.hours_booked, TIMESTAMPDIFF(MINUTE, b.start_datetime, b.end_datetime) / 60) ELSE 0 END), 0) AS hours_booked
                 FROM facilities f
                 LEFT JOIN facility_bookings b ON b.facility_id = f.id
                   AND b.start_datetime BETWEEN :start_datetime AND :end_datetime
                 WHERE f.organization_id = :org_id{$sql}
                 GROUP BY f.id, f.name, f.capacity
                 ORDER BY booking_count DESC, f.name ASC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{active_programs:int,registrations:int,sessions_held:int,attendance_records:int,revenue:float}
     */
    public function getProgramReportStats(): array
    {
        $defaults = [
            'active_programs' => 0,
            'registrations' => 0,
            'sessions_held' => 0,
            'attendance_records' => 0,
            'revenue' => 0.0,
        ];
        if (!$this->reportTableExists('programs')) {
            return $defaults;
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
        ];
        $progSql = '';
        if ($this->filters->programId !== null) {
            $progSql .= ' AND p.id = :program_id';
            $params['program_id'] = $this->filters->programId;
        }
        if ($this->filters->programCategoryId !== null) {
            $progSql .= ' AND p.category_id = :program_category_id';
            $params['program_category_id'] = $this->filters->programCategoryId;
        }
        try {
            $active = $this->db->queryOne(
                "SELECT COUNT(*) AS c FROM programs p
                 WHERE p.organization_id = :org_id AND p.status = 'published'{$progSql}",
                $params
            );
            $defaults['active_programs'] = $active ? (int) $active['c'] : 0;
        } catch (\Throwable) {
        }
        if (!$this->reportTableExists('program_registrations')) {
            return $defaults;
        }
        try {
            $reg = $this->db->queryOne(
                "SELECT COUNT(*) AS c,
                        COALESCE(SUM(CASE WHEN r.status = 'active' AND r.stripe_payment_intent_id IS NOT NULL AND r.stripe_payment_intent_id != ''
                            THEN COALESCE(p.price_amount, 0) ELSE 0 END), 0) AS rev
                 FROM program_registrations r
                 INNER JOIN programs p ON p.id = r.program_id
                 WHERE p.organization_id = :org_id
                   AND DATE(r.created_at) BETWEEN :start_date AND :end_date{$progSql}",
                $params
            );
            $defaults['registrations'] = $reg ? (int) $reg['c'] : 0;
            $defaults['revenue'] = $reg ? (float) $reg['rev'] : 0.0;
        } catch (\Throwable) {
        }
        if ($this->reportTableExists('program_sessions')) {
            try {
                $sess = $this->db->queryOne(
                    "SELECT COUNT(*) AS c FROM program_sessions ps
                     INNER JOIN programs p ON p.id = ps.program_id
                     WHERE p.organization_id = :org_id
                       AND ps.session_date BETWEEN :start_date AND :end_date
                       AND ps.status != 'cancelled'{$progSql}",
                    $params
                );
                $defaults['sessions_held'] = $sess ? (int) $sess['c'] : 0;
            } catch (\Throwable) {
            }
        }
        if ($this->reportTableExists('program_session_attendance')) {
            try {
                $att = $this->db->queryOne(
                    "SELECT COUNT(*) AS c FROM program_session_attendance a
                     INNER JOIN program_sessions ps ON ps.id = a.program_session_id
                     INNER JOIN programs p ON p.id = ps.program_id
                     WHERE p.organization_id = :org_id
                       AND ps.session_date BETWEEN :start_date AND :end_date{$progSql}",
                    $params
                );
                $defaults['attendance_records'] = $att ? (int) $att['c'] : 0;
            } catch (\Throwable) {
            }
        }

        return $defaults;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getProgramPerformanceList(): array
    {
        if (!$this->reportTableExists('programs') || !$this->reportTableExists('program_registrations')) {
            return [];
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
        ];
        $progSql = '';
        if ($this->filters->programId !== null) {
            $progSql .= ' AND p.id = :program_id';
            $params['program_id'] = $this->filters->programId;
        }
        if ($this->filters->programCategoryId !== null) {
            $progSql .= ' AND p.category_id = :program_category_id';
            $params['program_category_id'] = $this->filters->programCategoryId;
        }
        $sessionSub = $this->reportTableExists('program_sessions')
            ? '(SELECT COUNT(*) FROM program_sessions ps WHERE ps.program_id = p.id AND ps.session_date BETWEEN :start_date AND :end_date AND ps.status != \'cancelled\')'
            : '0';
        $attSub = ($this->reportTableExists('program_sessions') && $this->reportTableExists('program_session_attendance'))
            ? '(SELECT COUNT(*) FROM program_session_attendance a INNER JOIN program_sessions ps ON ps.id = a.program_session_id
                WHERE ps.program_id = p.id AND ps.session_date BETWEEN :start_date AND :end_date)'
            : '0';
        try {
            $list = $this->db->query(
                "SELECT p.id, p.title, p.status,
                        COUNT(r.id) AS registrations,
                        SUM(CASE WHEN r.status = 'active' THEN 1 ELSE 0 END) AS active_registrations,
                        COALESCE(SUM(CASE WHEN r.status = 'active' AND r.stripe_payment_intent_id IS NOT NULL AND r.stripe_payment_intent_id != ''
                            THEN COALESCE(p.price_amount, 0) ELSE 0 END), 0) AS revenue,
                        {$sessionSub} AS sessions_held,
                        {$attSub} AS attendance_records
                 FROM programs p
                 LEFT JOIN program_registrations r ON r.program_id = p.id
                   AND DATE(r.created_at) BETWEEN :start_date AND :end_date
                 WHERE p.organization_id = :org_id{$progSql}
                 GROUP BY p.id, p.title, p.status
                 ORDER BY registrations DESC, p.title ASC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        foreach ($list as &$row) {
            $sessions = (int) ($row['sessions_held'] ?? 0);
            $att = (int) ($row['attendance_records'] ?? 0);
            $active = (int) ($row['active_registrations'] ?? 0);
            $expected = $sessions * max(1, $active);
            $row['attendance_rate'] = $expected > 0 ? round(($att / $expected) * 100, 1) : 0.0;
        }
        unset($row);

        return $list;
    }

    /**
     * @return array{total_responses: int, avg_overall: float|null, response_rate_pct: float, events_with_feedback: int}
     */
    public function getFeedbackSummaryStats(): array
    {
        $defaults = [
            'total_responses' => 0,
            'avg_overall' => null,
            'response_rate_pct' => 0.0,
            'events_with_feedback' => 0,
        ];
        if (!$this->reportTableExists('event_feedback')) {
            return $defaults;
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
        ];
        $eventSql = $this->eventFilterSql('e', $params);
        try {
            $row = $this->db->queryOne(
                "SELECT COUNT(f.id) AS total_responses,
                        AVG(f.rating) AS avg_overall,
                        COUNT(DISTINCT f.event_id) AS events_with_feedback
                 FROM event_feedback f
                 INNER JOIN events e ON e.id = f.event_id
                 WHERE e.organization_id = :org_id
                   AND e.event_date BETWEEN :start_date AND :end_date{$eventSql}",
                $params
            );
            $checkedIn = 0;
            if (headcount_db_has_column($this->db, 'events', 'collect_feedback')) {
                $checkedIn = (int) ($this->db->queryOne(
                    "SELECT COUNT(DISTINCT a.user_id) AS c
                     FROM attendance a
                     INNER JOIN events e ON e.id = a.event_id
                     WHERE e.organization_id = :org_id
                       AND e.collect_feedback = 1
                       AND e.event_date BETWEEN :start_date AND :end_date{$eventSql}",
                    $params
                )['c'] ?? 0);
            }
            $totalResponses = (int) ($row['total_responses'] ?? 0);
            $defaults['total_responses'] = $totalResponses;
            $defaults['avg_overall'] = $row['avg_overall'] !== null ? round((float) $row['avg_overall'], 2) : null;
            $defaults['events_with_feedback'] = (int) ($row['events_with_feedback'] ?? 0);
            $defaults['response_rate_pct'] = $checkedIn > 0 ? round(($totalResponses / $checkedIn) * 100, 1) : 0.0;
        } catch (\Throwable) {
        }

        return $defaults;
    }

    /**
     * @return array<string, float|null>
     */
    public function getFeedbackQuestionAverages(): array
    {
        $keys = ['overall', 'content', 'venue', 'recommend'];
        $result = array_fill_keys($keys, null);
        if (!$this->reportTableExists('event_feedback')) {
            return $result;
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
        ];
        $eventSql = $this->eventFilterSql('e', $params);
        try {
            $rows = $this->db->query(
                "SELECT f.rating, f.rating_scores
                 FROM event_feedback f
                 INNER JOIN events e ON e.id = f.event_id
                 WHERE e.organization_id = :org_id
                   AND e.event_date BETWEEN :start_date AND :end_date{$eventSql}",
                $params
            ) ?: [];
            $sums = array_fill_keys($keys, 0);
            $counts = array_fill_keys($keys, 0);
            foreach ($rows as $row) {
                $scores = [];
                if (!empty($row['rating_scores'])) {
                    $decoded = is_string($row['rating_scores']) ? json_decode($row['rating_scores'], true) : $row['rating_scores'];
                    if (is_array($decoded)) {
                        $scores = $decoded;
                    }
                }
                if (empty($scores) && !empty($row['rating'])) {
                    $scores['overall'] = (int) $row['rating'];
                }
                foreach ($keys as $key) {
                    if (isset($scores[$key]) && (int) $scores[$key] >= 1) {
                        $sums[$key] += (int) $scores[$key];
                        $counts[$key]++;
                    }
                }
            }
            foreach ($keys as $key) {
                if ($counts[$key] > 0) {
                    $result[$key] = round($sums[$key] / $counts[$key], 2);
                }
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFeedbackTrend(): array
    {
        if (!$this->reportTableExists('event_feedback')) {
            return [];
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
        ];
        $eventSql = $this->eventFilterSql('e', $params);
        try {
            return $this->db->query(
                "SELECT DATE(f.created_at) AS day, COUNT(*) AS responses
                 FROM event_feedback f
                 INNER JOIN events e ON e.id = f.event_id
                 WHERE e.organization_id = :org_id
                   AND e.event_date BETWEEN :start_date AND :end_date{$eventSql}
                 GROUP BY DATE(f.created_at)
                 ORDER BY day ASC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getFeedbackByEventList(): array
    {
        if (!$this->reportTableExists('event_feedback')) {
            return [];
        }
        $params = [
            'org_id' => $this->organizationId,
            'start_date' => $this->filters->startDate,
            'end_date' => $this->filters->endDate,
        ];
        $eventSql = $this->eventFilterSql('e', $params);
        $hasCollect = headcount_db_has_column($this->db, 'events', 'collect_feedback');
        if (!$hasCollect) {
            return [];
        }
        try {
            $list = $this->db->query(
                "SELECT e.id, e.title, e.event_date, e.category,
                        (SELECT COUNT(DISTINCT a.user_id) FROM attendance a WHERE a.event_id = e.id) AS checked_in,
                        (SELECT COUNT(*) FROM event_feedback ef WHERE ef.event_id = e.id) AS responses,
                        (SELECT AVG(ef.rating) FROM event_feedback ef WHERE ef.event_id = e.id) AS avg_overall
                 FROM events e
                 WHERE e.organization_id = :org_id
                   AND e.collect_feedback = 1
                   AND e.event_date BETWEEN :start_date AND :end_date{$eventSql}
                 ORDER BY e.event_date DESC, e.id DESC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            return [];
        }
        foreach ($list as &$row) {
            $checkedIn = (int) ($row['checked_in'] ?? 0);
            $responses = (int) ($row['responses'] ?? 0);
            $row['response_rate_pct'] = $checkedIn > 0 ? round(($responses / $checkedIn) * 100, 1) : 0.0;
            $row['avg_overall'] = $row['avg_overall'] !== null ? round((float) $row['avg_overall'], 2) : null;
        }
        unset($row);

        return $list;
    }

    /**
     * @param list<array<string, mixed>> $list
     * @return list<array<string, mixed>>
     */
    private function applyThresholdFilters(array $list): array
    {
        $f = $this->filters;
        $out = [];
        foreach ($list as $row) {
            $rsvp = (int) ($row['rsvp_yes'] ?? 0);
            $ns = (float) ($row['no_show_pct'] ?? 0);
            if ($f->minRsvpYes !== null && $rsvp < $f->minRsvpYes) {
                continue;
            }
            if ($f->minNoShowPct !== null && $ns < $f->minNoShowPct) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }
}
