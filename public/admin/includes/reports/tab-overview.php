<?php
/** @var array $stats */
/** @var array|null $prevStats */
/** @var array $revenueStats */
/** @var int $noShowCount */
/** @var float $noShowRate */
/** @var list<array<string, mixed>> $topEvents */
/** @var list<array<string, mixed>> $topAttendees */
if (!function_exists('hc_report_delta')) {
    function hc_report_delta($current, $previous): ?array
    {
        if ($previous === null || (float) $previous == 0.0) {
            return null;
        }
        $diff = $current - $previous;
        $pct = round(((float) $diff / (float) $previous) * 100, 1);

        return ['diff' => $diff, 'pct' => $pct];
    }
}

$overviewMetrics = [
    ['label' => 'Total Events', 'value' => (int) $stats['total_events'], 'prev' => $prevStats['total_events'] ?? null, 'accent' => 'brand', 'icon' => 'calendar'],
    ['label' => 'Attendance', 'value' => (int) $stats['total_attendance'], 'prev' => $prevStats['total_attendance'] ?? null, 'accent' => 'success', 'icon' => 'chart'],
    ['label' => 'Unique Reach', 'value' => (int) $stats['unique_attendees'], 'prev' => $prevStats['unique_attendees'] ?? null, 'accent' => 'sky', 'icon' => 'users'],
    ['label' => 'Avg / Event', 'value' => (string) $stats['avg_attendance'], 'prev' => $prevStats['avg_attendance'] ?? null, 'accent' => 'warning', 'icon' => 'ticket'],
];
?>
<div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
    <?php foreach ($overviewMetrics as $m):
        $d = $prevStats !== null ? hc_report_delta($m['value'], $m['prev']) : null;
        $statLabel = $m['label'];
        $statValue = is_numeric($m['value']) ? number_format((float) $m['value']) : $m['value'];
        $statTrend = $d ? (float) $d['pct'] : null;
        $statTrendLabel = 'Vs previous period';
        $statAccent = $m['accent'];
        $statIcon = $m['icon'];
        require __DIR__ . '/../../components/stat-card-trend.php';
    endforeach; ?>
</div>

<div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-3 md:gap-6">
    <?php
    $dRsvp = $prevStats !== null ? hc_report_delta((int) $stats['total_rsvps'], (int) $prevStats['total_rsvps']) : null;
    $statLabel = 'RSVPs (Yes)';
    $statValue = number_format((int) $stats['total_rsvps']);
    $statTrend = $dRsvp ? (float) $dRsvp['pct'] : null;
    $statTrendLabel = 'Vs previous period';
    $statAccent = 'brand';
    $statIcon = 'mail';
    require __DIR__ . '/../../components/stat-card-trend.php';

    $statLabel = 'Total Members';
    $statValue = number_format((int) $stats['total_members']);
    $statTrend = null;
    $statTrendLabel = 'Active in organization';
    $statAccent = 'success';
    $statIcon = 'users';
    require __DIR__ . '/../../components/stat-card-trend.php';

    $statLabel = 'No-shows';
    $statValue = number_format((int) $noShowCount);
    $statTrend = null;
    $statTrendLabel = e((string) $noShowRate) . '% of RSVP yes';
    $statAccent = 'rose';
    $statIcon = 'chart';
    require __DIR__ . '/../../components/stat-card-trend.php';
    ?>
</div>

<?php if (($revenueStats['total_revenue'] ?? 0) > 0): ?>
<div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
    <?php
    $statLabel = 'Total Revenue';
    $statValue = '$' . number_format((float) $revenueStats['total_revenue'], 2);
    $statTrend = null;
    $statTrendLabel = 'In selected period';
    $statAccent = 'success';
    $statIcon = 'currency';
    require __DIR__ . '/../../components/stat-card-trend.php';

    $statLabel = 'Paid registrations';
    $statValue = number_format((int) $revenueStats['paid_count']);
    $statTrend = null;
    $statTrendLabel = 'Completed payments';
    $statAccent = 'brand';
    $statIcon = 'ticket';
    require __DIR__ . '/../../components/stat-card-trend.php';
    ?>
</div>
<?php endif; ?>

<div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Attendance Trend';
        $chartCardSubtitle = 'Daily attendance in period';
        $chartCardId = 'attendanceTrendChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'By Category';
        $chartCardSubtitle = 'Attendance breakdown';
        $chartCardId = 'categoryChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
</div>

<div class="mb-8">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'RSVP vs Attendance';
        $chartCardSubtitle = 'By event date';
        $chartCardId = 'rsvpVsAttendanceChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
</div>

<div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'New vs Returning';
        $chartCardId = 'newVsReturningChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div>
        <?php
        $topSlice = array_slice($topEvents, 0, 10);
        $maxAtt = 1;
        foreach ($topSlice as $ev) {
            $maxAtt = max($maxAtt, (int) ($ev['attendance_count'] ?? 0));
        }
        $progressListTitle = 'Top Events';
        $progressListItems = [];
        foreach ($topSlice as $ev) {
            $cnt = (int) ($ev['attendance_count'] ?? 0);
            $progressListItems[] = [
                'label' => (string) ($ev['title'] ?? ''),
                'value' => (string) $cnt,
                'percent' => ($cnt / $maxAtt) * 100,
                'color' => 'brand',
            ];
        }
        require __DIR__ . '/../../components/progress-list.php';
        ?>
    </div>
</div>

<?php
$tableTitle = 'Top Attendees';
$tableColumns = [
    ['key' => 'rank', 'label' => '#', 'class' => 'w-12'],
    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
    ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
    ['key' => 'attendance_count', 'label' => 'Events', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($topAttendees as $index => $attendee) {
    $tableRows[] = [
        'rank' => (string) ($index + 1),
        'name' => trim(($attendee['first_name'] ?? '') . ' ' . ($attendee['last_name'] ?? '')),
        'email' => (string) ($attendee['email'] ?? ''),
        'attendance_count' => (string) (int) ($attendee['attendance_count'] ?? 0),
    ];
}
$tableEmptyMessage = 'No attendance data in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
