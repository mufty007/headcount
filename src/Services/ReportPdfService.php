<?php

declare(strict_types=1);

namespace Headcount\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

final class ReportPdfService
{
    /**
     * @param array{name: string, primary_color: string, logo_data_uri: ?string} $org
     * @param list<array{title: string, body: string}> $insightLines
     * @return string PDF binary
     */
    public static function buildPdf(
        AdminReportService $svc,
        string $type,
        array $org,
        ReportFilterSet $filters,
        array $insightLines,
    ): string {
        $titles = [
            'attendance' => 'Attendance Report',
            'events' => 'Event Performance Report',
            'members' => 'Member Engagement Report',
            'rsvp_detail' => 'RSVP Detail Report',
            'revenue' => 'Revenue Report',
        ];
        $title = $titles[$type] ?? 'Report';
        $html = self::wrapHtml($org, $title, $filters, $insightLines, self::bodyForType($svc, $type));

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @param array{name: string, primary_color: string, logo_data_uri: ?string} $org
     * @param list<array{title: string, body: string}> $insightLines
     */
    private static function wrapHtml(array $org, string $title, ReportFilterSet $filters, array $insightLines, string $body): string
    {
        $color = htmlspecialchars(preg_match('/^#[0-9A-Fa-f]{6}$/', $org['primary_color'] ?? '') ? $org['primary_color'] : '#3B82F6', ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($org['name'] ?? 'Organization', ENT_QUOTES, 'UTF-8');
        $range = htmlspecialchars($filters->startDate . ' to ' . $filters->endDate, ENT_QUOTES, 'UTF-8');
        $logo = !empty($org['logo_data_uri'])
            ? '<img src="' . htmlspecialchars($org['logo_data_uri'], ENT_QUOTES, 'UTF-8') . '" style="max-height:48px;max-width:200px;margin-bottom:8px;" alt="">'
            : '';
        $insHtml = '';
        foreach ($insightLines as $i) {
            $insHtml .= '<li><strong>' . htmlspecialchars($i['title'], ENT_QUOTES, 'UTF-8') . '</strong> — ' . htmlspecialchars($i['body'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $insightsBlock = $insHtml !== '' ? '<h2 style="font-size:12px;margin:16px 0 8px;">Highlights</h2><ul style="font-size:10px;margin:0 0 16px 18px;">' . $insHtml . '</ul>' : '';

        return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; margin: 24px; }
h1 { font-size: 18px; margin: 0 0 8px; }
.meta { color: #666; font-size: 10px; margin-bottom: 16px; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
th { background: ' . $color . '; color: #fff; font-size: 9px; text-transform: uppercase; }
tr:nth-child(even) { background: #f9fafb; }
.header-band { border-bottom: 3px solid ' . $color . '; padding-bottom: 12px; margin-bottom: 16px; }
</style></head><body>
<div class="header-band">' . $logo . '<h1>' . $name . '</h1>
<div class="meta">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . ' · ' . $range . '</div></div>
' . $insightsBlock . $body . '
<p class="meta" style="margin-top:24px;">Generated ' . htmlspecialchars(date('Y-m-d H:i'), ENT_QUOTES, 'UTF-8') . '</p>
</body></html>';
    }

    private static function bodyForType(AdminReportService $svc, string $type): string
    {
        return match ($type) {
            'attendance' => self::tableAttendance($svc),
            'events' => self::tableEvents($svc),
            'members' => self::tableMembers($svc),
            'rsvp_detail' => self::tableRsvpDetail($svc),
            'revenue' => self::tableRevenue($svc),
            default => self::tableAttendance($svc),
        };
    }

    private static function tableAttendance(AdminReportService $svc): string
    {
        $rows = $svc->getAttendanceExportRows();
        $h = '<table><thead><tr><th>Event</th><th>Date</th><th>Location</th><th>Attendance</th></tr></thead><tbody>';
        foreach ($rows as $ev) {
            $h .= '<tr><td>' . htmlspecialchars((string) $ev['title'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($ev['location'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $ev['attendance'] . '</td></tr>';
        }
        $h .= '</tbody></table>';

        return $h;
    }

    private static function tableEvents(AdminReportService $svc): string
    {
        $list = $svc->getEventPerformanceList();
        $h = '<table><thead><tr><th>Event</th><th>Date</th><th>Category</th><th>RSVP yes</th><th>Checked in</th><th>No-show %</th></tr></thead><tbody>';
        foreach ($list as $ev) {
            $h .= '<tr><td>' . htmlspecialchars((string) $ev['title'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($ev['category'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $ev['rsvp_yes'] . '</td><td>' . (int) $ev['checked_in'] . '</td><td>' . (float) $ev['no_show_pct'] . '%</td></tr>';
        }
        $h .= '</tbody></table>';

        return $h;
    }

    private static function tableMembers(AdminReportService $svc): string
    {
        $growth = $svc->getNewMembersMonthlyTrend();
        $h = '<h2 style="font-size:12px;margin:0 0 8px;">New members by month</h2>';
        $h .= '<table><thead><tr><th>Month</th><th>New members</th><th>Cumulative active</th></tr></thead><tbody>';
        foreach ($growth as $row) {
            $h .= '<tr><td>' . htmlspecialchars((string) ($row['month'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</td><td>' . (int) ($row['new_count'] ?? 0)
                . '</td><td>' . (int) ($row['cumulative'] ?? 0) . '</td></tr>';
        }
        if ($growth === []) {
            $h .= '<tr><td colspan="3">No member signups in this period.</td></tr>';
        }
        $h .= '</tbody></table>';

        $list = $svc->getMemberEngagementList();
        $h .= '<h2 style="font-size:12px;margin:16px 0 8px;">Member engagement</h2>';
        $h .= '<table><thead><tr><th>Name</th><th>Email</th><th>Attended</th><th>RSVP</th><th>No-shows</th><th>Rate %</th></tr></thead><tbody>';
        foreach (array_slice($list, 0, 500) as $m) {
            $h .= '<tr><td>' . htmlspecialchars(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($m['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . (int) $m['events_attended'] . '</td><td>' . (int) $m['events_rsvpd'] . '</td><td>' . (int) $m['no_shows'] . '</td><td>' . (float) $m['attendance_rate'] . '%</td></tr>';
        }
        $h .= '</tbody></table>';

        return $h;
    }

    private static function tableRsvpDetail(AdminReportService $svc): string
    {
        $db = $svc->getDb();
        $filters = $svc->getFilters();
        $params = ['start_date' => $filters->startDate, 'end_date' => $filters->endDate, 'org_id' => $svc->getOrganizationId()];
        $ef = self::eventFilterSqlStatic($filters, 'e', $params);
        try {
            $rsvpCols = $db->query('SHOW COLUMNS FROM rsvps');
            $guestCountCol = in_array('guest_count', array_column($rsvpCols, 'Field'), true) ? ', r.guest_count' : '';
            $rsvpRows = $db->query(
                "SELECT e.title as event_title, e.event_date, u.first_name, u.last_name, u.email, u.password_hash, r.status as rsvp_status, r.created_at{$guestCountCol},
                 (SELECT 1 FROM attendance a WHERE a.event_id = r.event_id AND a.user_id = r.user_id LIMIT 1) as has_attendance
                 FROM rsvps r INNER JOIN events e ON e.id = r.event_id INNER JOIN users u ON u.id = r.user_id
                 WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id{$ef}
                 ORDER BY e.event_date DESC",
                $params
            ) ?: [];
        } catch (\Throwable) {
            $rsvpRows = [];
        }
        $h = '<table><thead><tr><th>Event</th><th>Date</th><th>Name</th><th>Email</th><th>RSVP</th><th>Checked in</th></tr></thead><tbody>';
        foreach ($rsvpRows as $row) {
            $h .= '<tr><td>' . htmlspecialchars((string) $row['event_title'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $row['event_date'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row['rsvp_status'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . (!empty($row['has_attendance']) ? 'Yes' : 'No') . '</td></tr>';
        }
        $h .= '</tbody></table>';

        return $h;
    }

    private static function tableRevenue(AdminReportService $svc): string
    {
        $list = $svc->getRevenueByEventList();
        $h = '<table><thead><tr><th>Event</th><th>Date</th><th>Revenue</th><th>Paid count</th></tr></thead><tbody>';
        foreach ($list as $ev) {
            if ((float) ($ev['revenue'] ?? 0) <= 0 && (int) ($ev['paid_count'] ?? 0) === 0) {
                continue;
            }
            $h .= '<tr><td>' . htmlspecialchars((string) $ev['title'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') . '</td><td>$' . number_format((float) $ev['revenue'], 2) . '</td><td>' . (int) $ev['paid_count'] . '</td></tr>';
        }
        $h .= '</tbody></table>';

        return $h;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function eventFilterSqlStatic(ReportFilterSet $filters, string $alias, array &$params): string
    {
        $sql = '';
        if ($filters->eventId !== null) {
            $sql .= " AND {$alias}.id = :_rf_event_id";
            $params['_rf_event_id'] = $filters->eventId;
        }
        if ($filters->categories !== []) {
            $ph = [];
            foreach ($filters->categories as $i => $cat) {
                $k = "_rf_cat_{$i}";
                $ph[] = ':' . $k;
                $params[$k] = $cat;
            }
            $sql .= ' AND ' . $alias . '.category IN (' . implode(',', $ph) . ')';
        }
        if ($filters->searchQuery !== '') {
            $sql .= " AND {$alias}.title LIKE :_rf_title";
            $params['_rf_title'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filters->searchQuery) . '%';
        }

        return $sql;
    }
}
