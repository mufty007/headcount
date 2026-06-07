<?php
/**
 * Branded PDF export: outputs print-ready HTML. User can "Print to PDF" from browser.
 * Expects: $organizationId, $startDate, $endDate, $type, $db, $config from export-report.php
 */

if (!isset($db, $organizationId, $startDate, $endDate, $type)) {
    header('Content-Type: text/html');
    echo '<p>Invalid request.</p>';
    exit;
}

$org = $db->queryOne("SELECT name, logo_path FROM organizations WHERE id = :id", ['id' => $organizationId]);
$orgName = $org['name'] ?? 'Organization';
$appUrl = rtrim($config['app']['url'] ?? '', '/');
$logoUrl = null;
if (!empty($org['logo_path'])) {
    $logoUrl = (strpos($org['logo_path'], 'http') === 0) ? $org['logo_path'] : $appUrl . '/public/' . ltrim($org['logo_path'], '/');
}

$reportTitles = [
    'attendance' => 'Attendance Report',
    'events' => 'Event Performance Report',
    'members' => 'Member Engagement Report',
    'rsvp_detail' => 'RSVP Detail Report',
    'revenue' => 'Revenue Report',
];

$title = $reportTitles[$type] ?? 'Report';

header('Content-Type: text/html; charset=UTF-8');
header('Content-Disposition: inline; filename="report-' . $type . '-' . $startDate . '-to-' . $endDate . '.html"');

echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title>';
echo '<style>
body { font-family: sans-serif; max-width: 800px; margin: 0 auto; padding: 24px; color: #111; }
.report-header { border-bottom: 2px solid #e5e7eb; padding-bottom: 16px; margin-bottom: 24px; }
.report-header img { max-height: 48px; max-width: 200px; }
.report-header h1 { margin: 8px 0 0; font-size: 1.5rem; }
.report-meta { color: #6b7280; font-size: 0.875rem; margin-top: 4px; }
table { width: 100%; border-collapse: collapse; margin-top: 16px; }
th, td { border: 1px solid #e5e7eb; padding: 8px 12px; text-align: left; }
th { background: #f9fafb; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; }
tr:nth-child(even) { background: #f9fafb; }
.summary-row { font-weight: 600; }
.print-hint { margin-top: 24px; padding: 12px; background: #eff6ff; border-radius: 8px; font-size: 0.875rem; }
@media print { .print-hint { display: none; } }
</style></head><body>';

echo '<div class="report-header">';
if ($logoUrl) {
    echo '<img src="' . htmlspecialchars($logoUrl) . '" alt="Logo">';
}
echo '<h1>' . htmlspecialchars($orgName) . '</h1>';
echo '<p class="report-meta">' . htmlspecialchars($title) . ' &middot; ' . htmlspecialchars($startDate) . ' to ' . htmlspecialchars($endDate) . '</p>';
echo '</div>';

if ($type === 'attendance') {
    $events = isset($exportSvc) ? $exportSvc->getAttendanceExportRows() : $db->query("
        SELECT e.title, e.event_date, e.location, COUNT(a.id) as attendance
        FROM events e
        LEFT JOIN attendance a ON e.id = a.event_id AND a.checked_in_at IS NOT NULL
        WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id
        GROUP BY e.id
        ORDER BY e.event_date DESC
    ", ['start_date' => $startDate, 'end_date' => $endDate, 'org_id' => $organizationId]);
    echo '<table><thead><tr><th>Event</th><th>Date</th><th>Location</th><th>Attendance</th></tr></thead><tbody>';
    foreach ($events as $ev) {
        echo '<tr><td>' . htmlspecialchars($ev['title']) . '</td><td>' . htmlspecialchars($ev['event_date']) . '</td><td>' . htmlspecialchars($ev['location'] ?? '') . '</td><td>' . (int)$ev['attendance'] . '</td></tr>';
    }
    echo '</tbody></table>';
} elseif ($type === 'events') {
    if (isset($exportSvc)) {
        $eventPerformanceList = $exportSvc->getEventPerformanceList();
    } else {
        $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
        $hasGuestCount = in_array('guest_count', array_column($rsvpCols, 'Field'));
        $guestCountSql = $hasGuestCount ? "COALESCE(SUM(r_g.guest_count), 0)" : "0";
        $eventPerformanceList = $db->query("
            SELECT e.title, e.event_date, e.category, e.capacity,
                (SELECT COUNT(*) FROM rsvps r2 WHERE r2.event_id = e.id AND r2.status = 'yes') as rsvp_yes,
                (SELECT {$guestCountSql} FROM rsvps r_g WHERE r_g.event_id = e.id AND r_g.status = 'yes') as additional_guests,
                (SELECT COUNT(*) FROM attendance a2 WHERE a2.event_id = e.id AND a2.checked_in_at IS NOT NULL) as checked_in,
                (SELECT COUNT(*) FROM rsvps r3 LEFT JOIN attendance a3 ON a3.event_id = r3.event_id AND a3.user_id = r3.user_id
                 WHERE r3.event_id = e.id AND r3.status = 'yes' AND a3.id IS NULL) as no_show_count
            FROM events e
            WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id
            ORDER BY e.event_date DESC, e.id
        ", ['start_date' => $startDate, 'end_date' => $endDate, 'org_id' => $organizationId]);
    }
    echo '<table><thead><tr><th>Event</th><th>Date</th><th>Category</th><th>Capacity</th><th>Primary Yes</th><th>Checked In</th><th>No-show %</th></tr></thead><tbody>';
    foreach ($eventPerformanceList as $ev) {
        $rsvpYes = (int)$ev['rsvp_yes'];
        $noShowPct = $rsvpYes > 0 ? round(((int)$ev['no_show_count'] / $rsvpYes) * 100, 1) : 0;
        $util = (isset($ev['capacity']) && $ev['capacity'] > 0) ? round(((int)$ev['checked_in'] / $ev['capacity']) * 100, 1) : '';
        echo '<tr><td>' . htmlspecialchars($ev['title']) . '</td><td>' . htmlspecialchars($ev['event_date']) . '</td><td>' . htmlspecialchars($ev['category'] ?? '') . '</td><td>' . ($ev['capacity'] !== null ? (int)$ev['capacity'] : '') . '</td><td>' . $rsvpYes . '</td><td>' . (int)$ev['checked_in'] . '</td><td>' . $noShowPct . '%</td></tr>';
    }
    echo '</tbody></table>';
} elseif ($type === 'members') {
    $memberEngagementList = isset($exportSvc) ? $exportSvc->getMemberEngagementList() : $db->query("
        SELECT u.first_name, u.last_name, u.email,
            (SELECT COUNT(*) FROM attendance a WHERE a.user_id = u.id AND a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND EXISTS (SELECT 1 FROM events e WHERE e.id = a.event_id AND e.organization_id = :org_id)) as events_attended,
            (SELECT COUNT(*) FROM rsvps r INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = u.id AND r.status = 'yes' AND e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id2) as events_rsvpd,
            (SELECT COUNT(*) FROM rsvps r2 INNER JOIN events e2 ON e2.id = r2.event_id LEFT JOIN attendance a2 ON a2.event_id = r2.event_id AND a2.user_id = r2.user_id WHERE r2.user_id = u.id AND r2.status = 'yes' AND e2.event_date BETWEEN :start_date2 AND :end_date2 AND e2.organization_id = :org_id3 AND a2.id IS NULL) as no_shows
        FROM users u
        WHERE u.organization_id = :org_id5 AND u.role = 'member' AND u.status = 'active'
        HAVING events_attended > 0 OR events_rsvpd > 0
        ORDER BY events_attended DESC
        LIMIT 500
    ", [
        'start_datetime' => $startDate . ' 00:00:00', 'end_datetime' => $endDate . ' 23:59:59', 'org_id' => $organizationId,
        'start_date' => $startDate, 'end_date' => $endDate, 'org_id2' => $organizationId,
        'start_date2' => $startDate, 'end_date2' => $endDate, 'org_id3' => $organizationId,
        'org_id5' => $organizationId
    ]);
    echo '<table><thead><tr><th>Name</th><th>Email</th><th>Attended</th><th>RSVP\'d</th><th>No-shows</th><th>Rate %</th></tr></thead><tbody>';
    foreach ($memberEngagementList as $m) {
        $attended = (int)$m['events_attended'];
        $rsvpd = (int)$m['events_rsvpd'];
        $rate = $rsvpd > 0 ? round(($attended / $rsvpd) * 100, 1) : ($attended > 0 ? 100 : 0);
        echo '<tr><td>' . htmlspecialchars(trim($m['first_name'] . ' ' . $m['last_name'])) . '</td><td>' . htmlspecialchars($m['email'] ?? '') . '</td><td>' . $attended . '</td><td>' . $rsvpd . '</td><td>' . (int)$m['no_shows'] . '</td><td>' . $rate . '%</td></tr>';
    }
    echo '</tbody></table>';
} elseif ($type === 'rsvp_detail') {
    if (isset($exportSvc)) {
        $rsvpRows = $exportSvc->getRsvpDetailExportRows();
    } else {
        $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
        $guestCountCol = in_array('guest_count', array_column($rsvpCols, 'Field')) ? ', r.guest_count' : '';
        $rsvpRows = $db->query("
            SELECT e.title as event_title, e.event_date, u.first_name, u.last_name, u.email, r.status as rsvp_status, r.created_at{$guestCountCol},
                   (SELECT 1 FROM attendance a WHERE a.event_id = r.event_id AND a.user_id = r.user_id LIMIT 1) as has_attendance
            FROM rsvps r
            INNER JOIN events e ON e.id = r.event_id
            INNER JOIN users u ON u.id = r.user_id
            WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id
            ORDER BY e.event_date DESC, e.id
            LIMIT 1000
        ", ['start_date' => $startDate, 'end_date' => $endDate, 'org_id' => $organizationId]);
    }
    echo '<table><thead><tr><th>Event</th><th>Date</th><th>Member</th><th>Email</th><th>RSVP</th><th>Checked In</th></tr></thead><tbody>';
    foreach ($rsvpRows as $row) {
        echo '<tr><td>' . htmlspecialchars($row['event_title']) . '</td><td>' . htmlspecialchars($row['event_date']) . '</td><td>' . htmlspecialchars(trim($row['first_name'] . ' ' . $row['last_name'])) . '</td><td>' . htmlspecialchars($row['email'] ?? '') . '</td><td>' . htmlspecialchars($row['rsvp_status']) . '</td><td>' . (!empty($row['has_attendance']) ? 'Yes' : 'No') . '</td></tr>';
    }
    echo '</tbody></table>';
} elseif ($type === 'revenue') {
    if (isset($exportSvc)) {
        $revStats = $exportSvc->getRevenueStats();
        echo '<p><strong>Summary</strong> &middot; Total (scoped): $' . number_format((float) ($revStats['total_revenue'] ?? 0), 2) . ' &middot; Registrations: ' . (int) ($revStats['paid_count'] ?? 0) . '</p>';
        $revenueByEventList = $exportSvc->getRevenueByEventList();
    } else {
        $revSummary = $db->queryOne("
            SELECT COALESCE(SUM(p.amount), 0) as gross, COALESCE(SUM(p.refund_amount), 0) as refunds,
                   COALESCE(SUM(p.amount - COALESCE(p.refund_amount, 0)), 0) as net
            FROM attendance a INNER JOIN events e ON a.event_id = e.id
            LEFT JOIN payments p ON p.attendance_id = a.id AND p.status IN ('paid', 'refunded')
            WHERE a.checked_in_at BETWEEN :start_datetime AND :end_datetime AND e.organization_id = :org_id
        ", ['start_datetime' => $startDate . ' 00:00:00', 'end_datetime' => $endDate . ' 23:59:59', 'org_id' => $organizationId]);
        echo '<p><strong>Summary</strong> &middot; Gross: $' . number_format((float) ($revSummary['gross'] ?? 0), 2) . ' &middot; Refunds: $' . number_format((float) ($revSummary['refunds'] ?? 0), 2) . ' &middot; Net: $' . number_format((float) ($revSummary['net'] ?? 0), 2) . '</p>';
        $revenueByEventList = $db->query("
            SELECT e.title, e.event_date, COALESCE(SUM(p.amount - COALESCE(p.refund_amount, 0)), 0) as revenue, COALESCE(SUM(p.refund_amount), 0) as refunds
            FROM events e
            LEFT JOIN attendance a ON a.event_id = e.id AND a.checked_in_at IS NOT NULL
            LEFT JOIN payments p ON p.attendance_id = a.id AND p.status IN ('paid', 'refunded')
            WHERE e.event_date BETWEEN :start_date AND :end_date AND e.organization_id = :org_id
            GROUP BY e.id, e.title, e.event_date
            ORDER BY revenue DESC
        ", ['start_date' => $startDate, 'end_date' => $endDate, 'org_id' => $organizationId]);
    }
    echo '<table><thead><tr><th>Event</th><th>Date</th><th>Revenue (net)</th><th>Refunds</th></tr></thead><tbody>';
    foreach ($revenueByEventList as $ev) {
        $ref = isset($ev['refunds']) ? (float) $ev['refunds'] : 0.0;
        echo '<tr><td>' . htmlspecialchars($ev['title']) . '</td><td>' . htmlspecialchars($ev['event_date']) . '</td><td>$' . number_format((float) $ev['revenue'], 2) . '</td><td>$' . number_format($ref, 2) . '</td></tr>';
    }
    echo '</tbody></table>';
}

echo '<p class="print-hint">To save as PDF: use your browser\'s Print option (Ctrl+P / Cmd+P) and choose "Save as PDF" or "Print to PDF".</p>';
echo '</body></html>';
