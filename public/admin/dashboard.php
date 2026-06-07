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

$tableExists = static function (Database $db, string $tableName): bool {
    try {
        $row = $db->queryOne(
            "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name",
            ['table_name' => $tableName]
        );
        return !empty($row);
    } catch (\Throwable $e) {
        return false;
    }
};

$hasUsersTable = $tableExists($db, 'users');
$hasEventsTable = $tableExists($db, 'events');
$hasAttendanceTable = $tableExists($db, 'attendance');
$hasRsvpsTable = $tableExists($db, 'rsvps');

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
        $checkinSub = $hasAttendanceTable
            ? '(SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id AND a.checked_in_at IS NOT NULL) as checkin_count'
            : '0 as checkin_count';
        $nextEvent = $db->queryOne("
        SELECT e.*,
               (SELECT COUNT(*) FROM rsvps WHERE event_id = e.id AND status = 'yes') as rsvp_registrant_count,
               (SELECT {$headCountExpr} FROM rsvps WHERE event_id = e.id AND status = 'yes') as rsvp_head_count,
               {$checkinSub}
        FROM events e
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
        SELECT e.*,
               (SELECT COUNT(*) FROM rsvps WHERE event_id = e.id AND status = 'yes') as rsvp_registrant_count,
               (SELECT {$headCountExpr} FROM rsvps WHERE event_id = e.id AND status = 'yes') as rsvp_head_count
        FROM events e
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

$pageTitle = 'Dashboard';
$currentPage = 'dashboard';
require __DIR__ . '/includes/header.php';

$pageHeaderTitle = 'Dashboard Overview';
$pageHeaderSubtitle = 'Welcome back, ' . e(explode(' ', $user['name'])[0]) . '. Here\'s what\'s happening today.';
require __DIR__ . '/components/page-header.php';
?>

<div class="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-4 mb-8">
    <?php
    $statLabel = 'Upcoming Events';
    $statValue = number_format($stats['upcoming_events']);
    $statSublabel = 'Published · next 30 days';
    $statAccent = 'indigo';
    $statIcon = 'calendar';
    require __DIR__ . '/components/stat-card.php';
    $statLabel = 'Total Members';
    $statValue = number_format($stats['total_members']);
    $statSublabel = 'Active member accounts';
    $statAccent = 'emerald';
    $statIcon = 'users';
    require __DIR__ . '/components/stat-card.php';
    $statLabel = 'MTD Attendance';
    $statValue = number_format($stats['month_attendance']);
    $statSublabel = 'Check-ins this month';
    $statAccent = 'amber';
    $statIcon = 'chart';
    require __DIR__ . '/components/stat-card.php';
    $statLabel = 'Total Events';
    $statValue = number_format($stats['total_events']);
    $statSublabel = 'All statuses · lifetime';
    $statAccent = 'sky';
    $statIcon = 'ticket';
    require __DIR__ . '/components/stat-card.php';
    ?>
</div>

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3 lg:gap-8">
    <!-- Next Event Panel -->
    <div class="lg:col-span-2 p-0">
        <div class="dashboard-next-event-shell relative overflow-hidden border-0 bg-transparent p-0 pt-[15px] shadow-none dark:bg-transparent">
            <div class="relative z-10 px-6 pb-6 md:px-7 md:pb-7">
                <div class="mb-6 flex items-center gap-2">
                    <span class="rounded-md bg-indigo-50 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300">Next Up</span>
                    <span class="text-xs font-medium text-indigo-600 dark:text-indigo-400">Coming soon</span>
                </div>
                <?php if ($nextEvent):
                    $event = $nextEvent;
                    $eventStats = ['checked_in' => (int) ($nextEvent['checkin_count'] ?? 0), 'rsvp_yes' => (int) ($nextEvent['rsvp_registrant_count'] ?? 0)];
                    $eventActions = '<div class="dashboard-next-event-actions flex flex-wrap gap-2">'
                        . '<a href="' . e($adminBase . '/?page=checkin&event_id=' . $nextEvent['id']) . '" class="event-card-action event-card-action--primary">Start Check-In</a>'
                        . '<a href="' . e($adminBase . '/?page=event-details&id=' . $nextEvent['id']) . '" class="event-card-action event-card-action--neutral">Details</a>'
                        . '</div>';
                    $eventHeaderRootClass = 'dashboard-next-event-inner';
                    require __DIR__ . '/components/event-header.php';
                    unset($eventHeaderRootClass);
                else:
                    $emptyMessage = 'No events scheduled. Time to plan something new!';
                    $emptyIcon = 'calendar';
                    $emptyAction = '<a href="' . e($adminBase . '/?page=events&action=create') . '" class="btn-primary px-6 py-3 text-base font-semibold inline-block shadow-lg border border-indigo-600">Create Event</a>';
                    require __DIR__ . '/components/empty-state.php';
                endif; ?>
            </div>
        </div>
    </div>

    <!-- Upcoming List Panel -->
    <div class="lg:col-span-1">
        <div class="flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
            <div class="mb-6 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Upcoming</h2>
                <a href="<?= e($adminBase . '/?page=events') ?>" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">View All</a>
            </div>
            
            <div class="space-y-4 flex-1">
                <?php if (empty($upcomingEvents)):
                    $emptyMessage = 'Nothing on the horizon.';
                    $emptyIcon = 'calendar';
                    $emptyAction = '';
                    require __DIR__ . '/components/empty-state.php';
                else: ?>
                    <?php foreach ($upcomingEvents as $event): ?>
                        <div class="group flex items-center rounded-xl border border-transparent p-3 transition-colors hover:border-gray-100 hover:bg-gray-50 dark:hover:border-slate-600 dark:hover:bg-slate-700/50">
                            <div class="mr-4 flex h-10 w-10 flex-col items-center justify-center rounded-lg bg-indigo-50 transition-colors group-hover:bg-indigo-600 group-hover:text-white dark:bg-indigo-950/40 dark:text-indigo-200">
                                <span class="text-[8px] uppercase font-bold"><?= date('M', strtotime($event['event_date'])) ?></span>
                                <span class="text-sm font-bold leading-none"><?= date('d', strtotime($event['event_date'])) ?></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h4 class="truncate text-sm font-semibold text-gray-900 dark:text-white"><?= e($event['title']) ?></h4>
                                <p class="text-[10px] text-gray-500 dark:text-slate-400"><?= (int)($event['rsvp_head_count'] ?? 0) ?> people <?= "\u{2022}" ?> <?= formatTime($event['start_time']) ?></p>
                            </div>
                            <a href="<?= e($adminBase . '/?page=checkin&event_id=' . $event['id']) ?>" class="p-2 text-gray-400 opacity-0 transition-opacity hover:text-indigo-600 group-hover:opacity-100 dark:text-slate-500 dark:hover:text-indigo-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <a href="<?= e($adminBase . '/?page=events&action=create') ?>" class="mt-6 w-full rounded-xl border border-gray-200 bg-gray-50 py-3 text-center text-xs font-bold text-gray-700 transition-colors hover:bg-gray-100 dark:border-slate-600 dark:bg-slate-700/50 dark:text-slate-200 dark:hover:bg-slate-700">
                + New Event
            </a>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

