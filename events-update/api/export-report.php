<?php
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\AdminReportService;
use Headcount\Services\ReportFilterSet;
use Headcount\Services\ReportInsightsBuilder;
use Headcount\Services\ReportPdfService;

/**
 * @param array<string, mixed> $params
 */
function hc_export_event_filter_sql(AdminReportService $exportSvc, string $alias, array &$params): string
{
    $rf = $exportSvc->getFilters();
    $sql = '';
    if ($rf->eventId !== null) {
        $sql .= " AND {$alias}.id = :_ex_event_id";
        $params['_ex_event_id'] = $rf->eventId;
    }
    if ($rf->categories !== []) {
        $ph = [];
        foreach ($rf->categories as $i => $cat) {
            $k = "_ex_cat_{$i}";
            $ph[] = ':' . $k;
            $params[$k] = $cat;
        }
        $sql .= ' AND ' . $alias . '.category IN (' . implode(',', $ph) . ')';
    }
    if ($rf->searchQuery !== '') {
        $sql .= " AND {$alias}.title LIKE :_ex_title";
        $params['_ex_title'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $rf->searchQuery) . '%';
    }

    return $sql;
}

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$config = require __DIR__ . '/../../config/config.php';
Database::getInstance($config['database']);
$db = Database::getInstance();

$filters = ReportFilterSet::fromGet($_GET, $db, $organizationId);
$startDate = $filters->startDate;
$endDate = $filters->endDate;
$exportSvc = new AdminReportService($db, $organizationId, $filters);

$type = get('type', 'attendance');
$format = get('format', 'csv');
$allowedTypes = ['attendance', 'events', 'members', 'rsvp_detail', 'revenue'];
if (!in_array($type, $allowedTypes, true)) {
    $type = 'attendance';
}

if ($format === 'pdf') {
    require_once __DIR__ . '/export-report-pdf.php';
    exit;
}

if ($format === 'pdf_binary') {
    $reportType = match ($type) {
        'attendance' => 'overview',
        'events' => 'events',
        'members' => 'members',
        'rsvp_detail' => 'rsvp',
        'revenue' => 'revenue',
        default => 'overview',
    };

    $stats = $exportSvc->getCoreStats();
    $revenueStats = $exportSvc->getRevenueStats();
    $ns = $exportSvc->getNoShowStats((int) $stats['total_rsvps']);
    $prevStats = $exportSvc->getPrevPeriodStats();
    $categoryData = $exportSvc->getCategoryData();
    $eventPerformanceList = $reportType === 'events' ? $exportSvc->getEventPerformanceList() : [];
    $rsvpReportEvents = $reportType === 'rsvp' ? $exportSvc->getRsvpReportEvents() : [];
    $memberEngagementList = $reportType === 'members' ? $exportSvc->getMemberEngagementList() : [];
    $revenueByEventList = $reportType === 'revenue' ? $exportSvc->getRevenueByEventList() : [];

    $insights = ReportInsightsBuilder::build(
        $reportType,
        $filters,
        $stats,
        $prevStats,
        $categoryData,
        (int) $ns['count'],
        (float) $ns['rate'],
        $revenueStats,
        $eventPerformanceList,
        $rsvpReportEvents,
        $memberEngagementList,
        $revenueByEventList
    );
    $insightLines = array_map(static fn ($i) => ['title' => $i['title'], 'body' => $i['body']], $insights);

    $orgRow = $db->queryOne('SELECT name, logo_path, primary_color FROM organizations WHERE id = :id', ['id' => $organizationId]);
    $orgName = $orgRow['name'] ?? 'Organization';
    $primaryColor = is_string($orgRow['primary_color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $orgRow['primary_color'])
        ? $orgRow['primary_color']
        : '#3B82F6';
    $logoDataUri = null;
    if (!empty($orgRow['logo_path'])) {
        $lp = (string) $orgRow['logo_path'];
        $local = null;
        if (strpos($lp, 'http') === 0) {
            // skip remote for dompdf without remote enabled
            $logoDataUri = null;
        } else {
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/\\');
            $candidates = [
                $docRoot . '/public/' . ltrim($lp, '/'),
                dirname(__DIR__, 2) . '/public/' . ltrim($lp, '/'),
            ];
            foreach ($candidates as $cand) {
                if (is_readable($cand)) {
                    $local = $cand;
                    break;
                }
            }
            if ($local !== null) {
                $bin = @file_get_contents($local);
                if ($bin !== false) {
                    $ext = strtolower(pathinfo($local, PATHINFO_EXTENSION));
                    $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');
                    $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode($bin);
                }
            }
        }
    }

    $pdfBinary = ReportPdfService::buildPdf($exportSvc, $type, [
        'name' => $orgName,
        'primary_color' => $primaryColor,
        'logo_data_uri' => $logoDataUri,
    ], $filters, $insightLines);

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $type . '-report-' . $startDate . '-to-' . $endDate . '.pdf"');
    echo $pdfBinary;
    exit;
}

$filename = $type . '-report-' . $startDate . '-to-' . $endDate . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

if ($type === 'attendance') {
    fputcsv($output, ['Attendance Report', 'From: ' . $startDate, 'To: ' . $endDate]);
    fputcsv($output, []);

    $events = $exportSvc->getAttendanceExportRows();
    $totalEvents = count($events);
    $totalAttendance = array_sum(array_map(static fn ($ev) => (int) $ev['attendance'], $events));
    $paramsU = [
        'start_datetime' => $startDate . ' 00:00:00',
        'end_datetime' => $endDate . ' 23:59:59',
        'org_id' => $organizationId,
    ];
    $efU = hc_export_event_filter_sql($exportSvc, 'e', $paramsU);
    $uniqueAttendees = $db->queryOne(
        'SELECT COUNT(DISTINCT a.user_id) as count FROM attendance a INNER JOIN events e ON a.event_id = e.id
         WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id' . $efU,
        $paramsU
    );
    fputcsv($output, ['Summary', 'Total Events', 'Total Attendance', 'Unique Attendees']);
    fputcsv($output, ['', $totalEvents, $totalAttendance, $uniqueAttendees['count'] ?? 0]);
    fputcsv($output, []);

    fputcsv($output, ['Event', 'Date', 'Location', 'Attendance']);
    foreach ($events as $ev) {
        fputcsv($output, [$ev['title'], $ev['event_date'], $ev['location'] ?? '', $ev['attendance']]);
    }
} elseif ($type === 'events') {
    fputcsv($output, ['Event Performance Report', 'From: ' . $startDate, 'To: ' . $endDate]);
    fputcsv($output, []);

    $eventPerformanceList = $exportSvc->getEventPerformanceList();
    fputcsv($output, ['Event', 'Date', 'Category', 'Capacity', 'Primary Yes', 'Addl Guests', 'Total Expected', 'Checked In', 'No-shows', 'No-show %', 'Utilization %']);
    foreach ($eventPerformanceList as $ev) {
        $rsvpYes = (int) $ev['rsvp_yes'];
        $addlGuests = (int) ($ev['additional_guests'] ?? 0);
        $totalExpected = $rsvpYes + $addlGuests;
        $checkedIn = (int) $ev['checked_in'];
        $noShowCount = (int) $ev['no_show_count'];
        $noShowPct = $rsvpYes > 0 ? round(($noShowCount / $rsvpYes) * 100, 1) : 0;
        $utilPct = isset($ev['capacity']) && $ev['capacity'] > 0 ? round(($checkedIn / $ev['capacity']) * 100, 1) : '';
        fputcsv($output, [
            $ev['title'],
            $ev['event_date'],
            $ev['category'] ?? '',
            $ev['capacity'] !== null ? (int) $ev['capacity'] : '',
            $rsvpYes,
            $addlGuests,
            $totalExpected,
            $checkedIn,
            $noShowCount,
            $noShowPct,
            $utilPct,
        ]);
    }
} elseif ($type === 'members') {
    fputcsv($output, ['Member Engagement Report', 'From: ' . $startDate, 'To: ' . $endDate]);
    fputcsv($output, []);

    $memberEngagementList = $exportSvc->getMemberEngagementList();
    fputcsv($output, ['Name', 'Email', 'Events Attended', 'Events RSVP\'d', 'No-shows', 'Attendance Rate %', 'Last Attended']);
    foreach ($memberEngagementList as $m) {
        $attended = (int) $m['events_attended'];
        $rsvpd = (int) $m['events_rsvpd'];
        $noShows = (int) $m['no_shows'];
        $rate = $rsvpd > 0 ? round(($attended / $rsvpd) * 100, 1) : ($attended > 0 ? 100 : 0);
        fputcsv($output, [
            trim($m['first_name'] . ' ' . $m['last_name']),
            $m['email'] ?? '',
            $attended,
            $rsvpd,
            $noShows,
            $rate,
            !empty($m['last_attended']) ? $m['last_attended'] : '',
        ]);
    }
} elseif ($type === 'rsvp_detail') {
    fputcsv($output, ['RSVP Detail Report', 'From: ' . $startDate, 'To: ' . $endDate]);
    fputcsv($output, []);

    try {
        $rsvpRows = $exportSvc->getRsvpDetailExportRows();
        fputcsv($output, ['Event', 'Event Date', 'Member Name', 'Email', 'Type', 'Guests', 'RSVP Status', 'Response Date', 'Checked In']);
        foreach ($rsvpRows as $row) {
            $userType = !empty($row['password_hash']) ? 'Member' : 'Guest';
            $guestCount = $row['guest_count'] ?? (isset($row['notes']) && strpos((string) $row['notes'], 'Guests:') !== false ? preg_replace('/[^0-9]/', '', (string) $row['notes']) : 0);
            fputcsv($output, [
                $row['event_title'],
                $row['event_date'],
                trim($row['first_name'] . ' ' . $row['last_name']),
                $row['email'] ?? '',
                $userType,
                $guestCount,
                $row['rsvp_status'],
                $row['created_at'],
                !empty($row['has_attendance']) ? 'Yes' : 'No',
            ]);
        }
    } catch (\Exception $e) {
        error_log('RSVP detail export error: ' . $e->getMessage());
    }
} elseif ($type === 'revenue') {
    fputcsv($output, ['Revenue Report', 'From: ' . $startDate, 'To: ' . $endDate]);
    fputcsv($output, []);

    try {
        $revStats = $exportSvc->getRevenueStats();
        fputcsv($output, ['Summary', 'Total revenue (scoped)', 'Paid / pending count']);
        fputcsv($output, ['', $revStats['total_revenue'] ?? 0, $revStats['paid_count'] ?? 0]);
        fputcsv($output, []);

        $revenueByEventList = $exportSvc->getRevenueByEventList();
        fputcsv($output, ['Event', 'Date', 'Revenue', 'Paid count']);
        foreach ($revenueByEventList as $ev) {
            fputcsv($output, [
                $ev['title'],
                $ev['event_date'],
                (float) ($ev['revenue'] ?? 0),
                (int) ($ev['paid_count'] ?? 0),
            ]);
        }
    } catch (\Exception $e) {
        error_log('Revenue export error: ' . $e->getMessage());
    }
}

fputcsv($output, []);
fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);

fclose($output);
exit;
