<?php

/**
 * Admin Dashboard
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$db = Database::getInstance();

$hasUsersTable = headcount_db_table_exists($db, 'users');
$hasEventsTable = headcount_db_table_exists($db, 'events');
$hasAttendanceTable = headcount_db_table_exists($db, 'attendance');
$hasRsvpsTable = headcount_db_table_exists($db, 'rsvps');

// Get the current user for the header
$userId = AuthMiddleware::getUserId();
$userData = null;
if ($hasUsersTable) {
    try {
        $userData = $db->queryOne("SELECT first_name, last_name, email FROM users WHERE id = :id", ['id' => $userId]);
    } catch (\Throwable $e) {
        error_log('dashboard.php: user query failed: ' . $e->getMessage());
        $userData = null;
    }
}
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email']
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local'
];

// Get current user information
if (!isset($user)) {
    $user = [
        'name' => $_SESSION['user_name'] ?? $_SESSION['name'] ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Administrator',
        'email' => $_SESSION['user_email'] ?? $_SESSION['email'] ?? 'admin@headcount.local'
    ];
}

// Get statistics
$totalMembersResult = ['count' => 0];
$upcomingEventsResult = ['count' => 0];
$totalEventsResult = ['count' => 0];
$monthAttendanceResult = ['count' => 0];
try {
    if ($hasUsersTable) {
        $totalMembersResult = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE role = 'member' AND status = 'active' AND organization_id = :org_id", ['org_id' => $organizationId]) ?: ['count' => 0];
    }
    if ($hasEventsTable) {
        $upcomingEventsResult = $db->queryOne(
            "SELECT COUNT(*) as count FROM events WHERE event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'published' AND organization_id = :org_id",
            ['org_id' => $organizationId]
        ) ?: ['count' => 0];
        $totalEventsResult = $db->queryOne("SELECT COUNT(*) as count FROM events WHERE organization_id = :org_id", ['org_id' => $organizationId]) ?: ['count' => 0];
    }
    if ($hasAttendanceTable && $hasEventsTable) {
        $monthAttendanceResult = $db->queryOne("SELECT COUNT(*) as count FROM attendance a INNER JOIN events e ON a.event_id = e.id WHERE MONTH(a.checked_in_at) = MONTH(CURDATE()) AND YEAR(a.checked_in_at) = YEAR(CURDATE()) AND e.organization_id = :org_id", ['org_id' => $organizationId]) ?: ['count' => 0];
    }
} catch (\Throwable $e) {
    error_log('dashboard.php: stats query failed: ' . $e->getMessage());
}

$stats = [
    'total_members' => $totalMembersResult ? (int)$totalMembersResult['count'] : 0,
    'upcoming_events' => $upcomingEventsResult ? (int)$upcomingEventsResult['count'] : 0,
    'total_events' => $totalEventsResult ? (int)$totalEventsResult['count'] : 0,
    'month_attendance' => $monthAttendanceResult ? (int)$monthAttendanceResult['count'] : 0
];

$trends = [
    'members' => 0.0,
    'upcoming_events' => 0.0,
    'attendance' => 0.0,
    'total_events' => 0.0,
];
$chartCategories = [];
$chartAttendance = [];
$chartRsvps = [];
$recentEventRows = [];
$recentEventsRaw = [];

try {
    if ($hasUsersTable) {
        $membersThisMonth = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM users WHERE role = 'member' AND status = 'active' AND organization_id = :org_id AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $membersLastMonth = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM users WHERE role = 'member' AND status = 'active' AND organization_id = :org_id AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $trends['members'] = headcount_percent_trend($membersThisMonth, $membersLastMonth) ?? 0.0;
    }
    if ($hasEventsTable) {
        $eventsThisMonth = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM events WHERE organization_id = :org_id AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $eventsLastMonth = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM events WHERE organization_id = :org_id AND MONTH(created_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(created_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $trends['total_events'] = headcount_percent_trend($eventsThisMonth, $eventsLastMonth) ?? 0.0;

        $upcomingThis = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM events WHERE organization_id = :org_id AND status = 'published' AND event_date >= CURDATE() AND event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $upcomingLast = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM events WHERE organization_id = :org_id AND status = 'published' AND event_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND event_date < CURDATE()",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $trends['upcoming_events'] = headcount_percent_trend($upcomingThis, $upcomingLast) ?? 0.0;

        $recentEventsRaw = $db->query(
            "SELECT id, title, event_date, status FROM events WHERE organization_id = :org_id ORDER BY created_at DESC LIMIT 5",
            ['org_id' => $organizationId]
        ) ?: [];
    }
    if ($hasAttendanceTable && $hasEventsTable) {
        $attendanceThis = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM attendance a INNER JOIN events e ON a.event_id = e.id WHERE e.organization_id = :org_id AND MONTH(a.checked_in_at) = MONTH(CURDATE()) AND YEAR(a.checked_in_at) = YEAR(CURDATE())",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $attendanceLast = (int) ($db->queryOne(
            "SELECT COUNT(*) AS c FROM attendance a INNER JOIN events e ON a.event_id = e.id WHERE e.organization_id = :org_id AND MONTH(a.checked_in_at) = MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND YEAR(a.checked_in_at) = YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))",
            ['org_id' => $organizationId]
        )['c'] ?? 0);
        $trends['attendance'] = headcount_percent_trend($attendanceThis, $attendanceLast) ?? 0.0;

        for ($i = 5; $i >= 0; $i--) {
            $monthStart = date('Y-m-01', strtotime("-{$i} months"));
            $monthEnd = date('Y-m-t', strtotime("-{$i} months"));
            $chartCategories[] = date('M Y', strtotime($monthStart));
            $chartAttendance[] = (int) ($db->queryOne(
                "SELECT COUNT(*) AS c FROM attendance a INNER JOIN events e ON a.event_id = e.id WHERE e.organization_id = :org_id AND DATE(a.checked_in_at) BETWEEN :start AND :end",
                ['org_id' => $organizationId, 'start' => $monthStart, 'end' => $monthEnd]
            )['c'] ?? 0);
            if ($hasRsvpsTable) {
                $chartRsvps[] = (int) ($db->queryOne(
                    "SELECT COUNT(*) AS c FROM rsvps r INNER JOIN events e ON r.event_id = e.id WHERE e.organization_id = :org_id AND r.status = 'yes' AND DATE(r.created_at) BETWEEN :start AND :end",
                    ['org_id' => $organizationId, 'start' => $monthStart, 'end' => $monthEnd]
                )['c'] ?? 0);
            } else {
                $chartRsvps[] = 0;
            }
        }
    }
} catch (\Throwable $e) {
    error_log('dashboard.php: trends/chart query failed: ' . $e->getMessage());
}

// Check if guest_count column exists in rsvps table
$rsvpHasGuestCount = false;
try {
    if ($hasRsvpsTable) {
        $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
        $rsvpHasGuestCount = in_array('guest_count', array_column($rsvpCols, 'Field'), true);
    }
} catch (\Throwable $e) {
    // rsvps table may not exist yet
}
$headCountExpr = $rsvpHasGuestCount
    ? "COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0)"
    : "COUNT(*)";

// Get next event (RSVP counts whenever rsvps exists; check-in count only if attendance exists)
$nextEvent = null;
try {
    if ($hasEventsTable && $hasRsvpsTable) {
        $checkinJoin = $hasAttendanceTable
            ? 'LEFT JOIN (
                SELECT event_id, COUNT(*) AS checkin_count
                FROM attendance WHERE checked_in_at IS NOT NULL
                GROUP BY event_id
            ) ac ON ac.event_id = e.id'
            : '';
        $checkinSelect = $hasAttendanceTable
            ? 'COALESCE(ac.checkin_count, 0) AS checkin_count'
            : '0 AS checkin_count';
        $nextEvent = $db->queryOne("
        SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.location, e.status, e.banner_image,
               COALESCE(r.rsvp_registrant_count, 0) AS rsvp_registrant_count,
               COALESCE(r.rsvp_head_count, 0) AS rsvp_head_count,
               {$checkinSelect}
        FROM events e
        LEFT JOIN (
            SELECT event_id,
                   COUNT(*) AS rsvp_registrant_count,
                   {$headCountExpr} AS rsvp_head_count
            FROM rsvps WHERE status = 'yes'
            GROUP BY event_id
        ) r ON r.event_id = e.id
        {$checkinJoin}
        WHERE e.event_date >= CURDATE() AND e.status = 'published' AND e.organization_id = :org_id
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT 1
    ", ['org_id' => $organizationId]);
    } elseif ($hasEventsTable) {
        $nextEvent = $db->queryOne("
            SELECT e.*, 0 as rsvp_registrant_count, 0 as rsvp_head_count, 0 as checkin_count
            FROM events e
            WHERE e.event_date >= CURDATE() AND e.status = 'published' AND e.organization_id = :org_id
            ORDER BY e.event_date ASC, e.start_time ASC
            LIMIT 1
        ", ['org_id' => $organizationId]);
    }
} catch (\Exception $e) {
    error_log('dashboard.php: next event query failed: ' . $e->getMessage());
}

// Get upcoming events
$upcomingEvents = [];
try {
    if ($hasEventsTable && $hasRsvpsTable) {
        $upcomingEvents = $db->query("
        SELECT e.id, e.title, e.event_date, e.start_time, e.end_time, e.location, e.status, e.banner_image,
               COALESCE(r.rsvp_registrant_count, 0) AS rsvp_registrant_count,
               COALESCE(r.rsvp_head_count, 0) AS rsvp_head_count
        FROM events e
        LEFT JOIN (
            SELECT event_id,
                   COUNT(*) AS rsvp_registrant_count,
                   {$headCountExpr} AS rsvp_head_count
            FROM rsvps WHERE status = 'yes'
            GROUP BY event_id
        ) r ON r.event_id = e.id
        WHERE e.event_date >= CURDATE() AND e.event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND e.status = 'published' AND e.organization_id = :org_id
        ORDER BY e.event_date ASC, e.start_time ASC
        LIMIT 5
    ", ['org_id' => $organizationId]);
    } elseif ($hasEventsTable) {
        $upcomingEvents = $db->query("
            SELECT e.*, 0 as rsvp_registrant_count, 0 as rsvp_head_count
            FROM events e
            WHERE e.event_date >= CURDATE() AND e.event_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND e.status = 'published' AND e.organization_id = :org_id
            ORDER BY e.event_date ASC, e.start_time ASC
            LIMIT 5
        ", ['org_id' => $organizationId]);
    }
} catch (\Exception $e) {
    error_log('dashboard.php: upcoming events query failed: ' . $e->getMessage());
}

if ($nextEvent) {
    headcount_decode_html_entities_in_event_row($nextEvent);
}
headcount_decode_html_entities_in_event_rows($upcomingEvents);

// Calculate base path for assets (use from index.php if available)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}
$assetsBase = $basePath . '/public/assets/';

if (!empty($recentEventsRaw)) {
    foreach ($recentEventsRaw as $ev) {
        $status = (string) ($ev['status'] ?? 'draft');
        $badgeMap = ['published' => 'success', 'draft' => 'gray', 'cancelled' => 'error', 'completed' => 'brand'];
        $recentEventRows[] = [
            'title' => $ev['title'] ?? '',
            'event_date' => !empty($ev['event_date']) ? date('M j, Y', strtotime($ev['event_date'])) : '—',
            'status' => ucfirst($status),
            'status_variant' => $badgeMap[$status] ?? 'gray',
            'actions_html' => '<a href="' . e($adminBase) . '/?page=event-details&id=' . (int) $ev['id'] . '" class="text-theme-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">View</a>',
        ];
    }
}

$scheduleItems = [];
foreach ($upcomingEvents as $ev) {
    $scheduleItems[] = [
        'date' => $ev['event_date'] ? date('D, j M', strtotime($ev['event_date'])) : '',
        'time' => !empty($ev['start_time']) ? formatTime($ev['start_time']) : '',
        'title' => $ev['title'] ?? '',
        'subtitle' => ((int) ($ev['rsvp_head_count'] ?? 0)) . ' RSVPs',
        'url' => $adminBase . '/?page=event-details&id=' . (int) ($ev['id'] ?? 0),
    ];
}

$dashboardChartJson = json_encode([
    'categories' => $chartCategories,
    'series' => [
        ['name' => 'Check-ins', 'data' => $chartAttendance],
        ['name' => 'RSVPs', 'data' => $chartRsvps],
    ],
]);

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
require __DIR__ . '/includes/header.php';

$pageHeaderTitle = 'Dashboard Overview';
$pageHeaderSubtitle = 'Welcome back, ' . e(explode(' ', $user['name'])[0]) . '. Here\'s what\'s happening today.';
require __DIR__ . '/components/page-header.php';
?>

<div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
    <?php
    $statLabel = 'Upcoming Events';
    $statValue = number_format($stats['upcoming_events']);
    $statTrend = $trends['upcoming_events'];
    $statTrendLabel = 'Vs prior 30 days';
    $statAccent = 'brand';
    $statIcon = 'calendar';
    require __DIR__ . '/components/stat-card-trend.php';
    $statLabel = 'Total Members';
    $statValue = number_format($stats['total_members']);
    $statTrend = $trends['members'];
    $statTrendLabel = 'New this month';
    $statAccent = 'success';
    $statIcon = 'users';
    require __DIR__ . '/components/stat-card-trend.php';
    $statLabel = 'MTD Attendance';
    $statValue = number_format($stats['month_attendance']);
    $statTrend = $trends['attendance'];
    $statTrendLabel = 'Vs last month';
    $statAccent = 'warning';
    $statIcon = 'chart';
    require __DIR__ . '/components/stat-card-trend.php';
    $statLabel = 'Total Events';
    $statValue = number_format($stats['total_events']);
    $statTrend = $trends['total_events'];
    $statTrendLabel = 'Created this month';
    $statAccent = 'sky';
    $statIcon = 'ticket';
    require __DIR__ . '/components/stat-card-trend.php';
    ?>
</div>

<div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2">
        <?php
        $chartCardTitle = 'Attendance & RSVPs';
        $chartCardSubtitle = 'Last 6 months';
        $chartCardId = 'dashboard-attendance-chart';
        $chartCardHeight = '320px';
        require __DIR__ . '/components/chart-card.php';
        ?>
    </div>
    <div class="lg:col-span-1">
        <?php
        $scheduleTitle = 'Upcoming Schedule';
        $scheduleViewAllUrl = $adminBase . '/?page=events';
        require __DIR__ . '/components/schedule-timeline.php';
        ?>
    </div>
</div>

<?php if ($nextEvent): ?>
<div class="mb-8 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
    <div class="mb-4 flex items-center gap-2">
        <span class="rounded-md bg-brand-50 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-brand-700 dark:bg-brand-500/15 dark:text-brand-300">Next Up</span>
    </div>
    <?php
    $event = $nextEvent;
    $eventStats = ['checked_in' => (int) ($nextEvent['checkin_count'] ?? 0), 'rsvp_yes' => (int) ($nextEvent['rsvp_registrant_count'] ?? 0)];
    $eventActions = '<div class="flex flex-wrap gap-2">'
        . '<a href="' . e($adminBase . '/?page=checkin&event_id=' . $nextEvent['id']) . '" class="btn-primary">Start Check-In</a>'
        . '<a href="' . e($adminBase . '/?page=event-details&id=' . $nextEvent['id']) . '" class="btn-secondary">Details</a>'
        . '</div>';
    require __DIR__ . '/components/event-header.php';
    ?>
</div>
<?php endif; ?>

<div class="mb-4">
    <?php
    $tableTitle = 'Recent Events';
    $tableActions = '<a href="' . e($adminBase . '/?page=events') . '" class="btn-secondary py-2.5 text-theme-sm shadow-theme-xs">See all</a>';
    $tableColumns = [
        ['key' => 'title', 'label' => 'Event', 'type' => 'text'],
        ['key' => 'event_date', 'label' => 'Date', 'type' => 'text'],
        ['key' => 'status', 'label' => 'Status', 'type' => 'badge', 'badge_variant_key' => 'status_variant'],
        ['key' => 'actions', 'label' => 'Action', 'type' => 'actions', 'actions_key' => 'actions_html'],
    ];
    $tableRows = $recentEventRows;
    $tableEmptyMessage = 'No events yet.';
    $tableEmptyAction = '<a href="' . e($adminBase . '/?page=events&action=create') . '" class="btn-primary">Create Event</a>';
    require __DIR__ . '/components/data-table.php';
    ?>
</div>

<script>window.DASHBOARD_CHART_DATA = <?= $dashboardChartJson ?>;</script>
<?php
$additionalJS = $additionalJS ?? [];
$jsBase = function_exists('buildJsPath') ? buildJsPath($basePath, 'apexcharts.min.js') : ($basePath . '/public/js/apexcharts.min.js');
$additionalJS[] = $jsBase;
$additionalJS[] = (function_exists('buildJsPath') ? buildJsPath($basePath, 'dashboard-charts.js') : ($basePath . '/public/js/dashboard-charts.js'));
require __DIR__ . '/includes/footer.php';
?>

