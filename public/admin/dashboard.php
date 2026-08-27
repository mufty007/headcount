<?php

/**
 * Admin Dashboard
 */

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
        $dashHeadsExpr = $hasAttendanceTable ? headcount_attendance_heads_sum_expr($db, 'att') : '0';
        $checkinJoin = $hasAttendanceTable
            ? "LEFT JOIN (
                SELECT att.event_id, {$dashHeadsExpr} AS checkin_count
                FROM attendance att WHERE att.checked_in_at IS NOT NULL
                GROUP BY att.event_id
            ) ac ON ac.event_id = e.id"
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

/* Quick actions — role-aware shortcuts */
$quickActions = [
    ['label' => 'Create event',  'desc' => 'Set up a new event',      'url' => $adminBase . '/?page=event-create', 'accent' => 'brand',   'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
    ['label' => 'Start check-in', 'desc' => 'Mark attendance live',    'url' => $adminBase . '/?page=checkin',       'accent' => 'success', 'icon' => 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
];
if (!empty($isCoordinator)) {
    $quickActions[] = ['label' => 'Browse events', 'desc' => 'Manage all events', 'url' => $adminBase . '/?page=events',  'accent' => 'sky',    'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'];
    $quickActions[] = ['label' => 'Reports',       'desc' => 'View analytics',     'url' => $adminBase . '/?page=reports', 'accent' => 'violet', 'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'];
} else {
    $quickActions[] = ['label' => 'Add member',   'desc' => 'Register a person',  'url' => $adminBase . '/?page=member-add',      'accent' => 'sky',    'icon' => 'M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z'];
    $quickActions[] = ['label' => 'New campaign', 'desc' => 'Email your audience', 'url' => $adminBase . '/?page=email-campaigns', 'accent' => 'violet', 'icon' => 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z'];
}
$qaAccents = [
    'brand'   => 'bg-brand-50 text-brand-600 group-hover:bg-brand-100 dark:bg-brand-500/15 dark:text-brand-400',
    'success' => 'bg-success-50 text-success-600 group-hover:bg-success-100 dark:bg-success-500/15 dark:text-success-400',
    'sky'     => 'bg-sky-50 text-sky-600 group-hover:bg-sky-100 dark:bg-sky-500/15 dark:text-sky-400',
    'violet'  => 'bg-violet-50 text-violet-600 group-hover:bg-violet-100 dark:bg-violet-500/15 dark:text-violet-400',
];
?>

<!-- Quick actions -->
<div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <?php foreach ($quickActions as $qa): ?>
    <a href="<?= e($qa['url']) ?>" class="group flex items-center gap-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-theme-md dark:border-gray-800 dark:bg-white/[0.03] dark:hover:border-brand-500/40">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl transition <?= $qaAccents[$qa['accent']] ?? $qaAccents['brand'] ?>">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="<?= e($qa['icon']) ?>"/></svg>
        </span>
        <span class="min-w-0">
            <span class="block text-sm font-semibold text-gray-900 dark:text-white/90"><?= e($qa['label']) ?></span>
            <span class="block truncate text-xs text-gray-500 dark:text-gray-400"><?= e($qa['desc']) ?></span>
        </span>
    </a>
    <?php endforeach; ?>
</div>

<div class="mb-8 grid grid-cols-2 gap-3 sm:gap-4 md:gap-6 xl:grid-cols-4">
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
        <?php if ($nextEvent):
            $neDate    = !empty($nextEvent['event_date']) ? date('D, M j, Y', strtotime($nextEvent['event_date'])) : '';
            $neTime    = !empty($nextEvent['start_time']) ? formatTime($nextEvent['start_time']) : '';
            $neLoc     = trim((string) ($nextEvent['location'] ?? ''));
            $nePeople  = (int) ($nextEvent['rsvp_head_count'] ?? 0);
            $neChecked = (int) ($nextEvent['checkin_count'] ?? 0);
            $neReg     = (int) ($nextEvent['rsvp_registrant_count'] ?? 0);
            $nePct     = $nePeople > 0 ? min(100, (int) round(100 * $neChecked / $nePeople)) : 0;
            $neCheckin = e($adminBase . '/?page=checkin&event_id=' . (int) $nextEvent['id']);
            $neDetails = e($adminBase . '/?page=event-details&id=' . (int) $nextEvent['id']);
        ?>
        <div class="h-full overflow-hidden rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800">
            <!-- Branded header -->
            <div class="relative bg-gradient-to-br from-brand-600 to-brand-500 px-5 py-5 text-white">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-white/20 px-2.5 py-1 text-[10px] font-bold uppercase tracking-widest">
                    <span class="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span> Next up
                </span>
                <h3 class="mt-3 text-xl font-bold leading-tight"><?= e($nextEvent['title'] ?? 'Untitled event') ?></h3>
                <div class="mt-2.5 flex flex-col gap-1.5 text-sm font-medium text-white/90">
                    <?php if ($neDate): ?><span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg><?= e($neDate) ?></span><?php endif; ?>
                    <?php if ($neTime): ?><span class="inline-flex items-center gap-1.5"><svg class="h-4 w-4 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><?= e($neTime) ?></span><?php endif; ?>
                    <?php if ($neLoc): ?><span class="inline-flex items-center gap-1.5 min-w-0"><svg class="h-4 w-4 shrink-0 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg><span class="truncate"><?= e($neLoc) ?></span></span><?php endif; ?>
                </div>
            </div>
            <!-- Body: check-in progress + actions -->
            <div class="flex flex-col gap-5 bg-white p-5 dark:bg-white/[0.03]">
                <div class="min-w-0">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                            <span class="text-2xl font-bold text-gray-900 dark:text-white"><?= $neChecked ?></span>
                            <span class="text-gray-400 dark:text-gray-500">/ <?= $nePeople ?> checked in</span>
                        </p>
                        <span class="shrink-0 text-sm font-bold text-brand-600 dark:text-brand-400"><?= $nePct ?>%</span>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-full rounded-full bg-brand-500 transition-all" style="width: <?= $nePct ?>%"></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400"><?= $neReg ?> <?= $neReg === 1 ? 'registrant' : 'registrants' ?> &middot; <?= $nePeople ?> total <?= $nePeople === 1 ? 'guest' : 'guests' ?></p>
                </div>
                <div class="flex flex-col gap-2">
                    <a href="<?= $neCheckin ?>" class="btn-primary justify-center">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Start check-in
                    </a>
                    <a href="<?= $neDetails ?>" class="btn-secondary justify-center">Details</a>
                </div>
            </div>
        </div>
        <?php else: ?>
        <?php
        $scheduleTitle = 'Upcoming Schedule';
        $scheduleViewAllUrl = $adminBase . '/?page=events';
        require __DIR__ . '/components/schedule-timeline.php';
        ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($nextEvent): ?>
<div class="mb-8">
    <?php
    $scheduleTitle = 'Upcoming Schedule';
    $scheduleViewAllUrl = $adminBase . '/?page=events';
    require __DIR__ . '/components/schedule-timeline.php';
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
    $tableEmptyAction = $can('events.manage')
        ? '<a href="' . e($adminBase . '/?page=event-create') . '" class="btn-primary">Create Event</a>'
        : '<a href="' . e($adminBase . '/?page=event-request-form') . '" class="btn-primary">Request Event</a>';
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

