<?php
/** @var array $stats */
/** @var int $noShowCount */
/** @var float $noShowRate */
/** @var list<array<string, mixed>> $rsvpReportEvents */
?>
<div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-3 md:gap-6">
    <?php
    $statLabel = 'RSVP Yes';
    $statValue = number_format((int) $stats['total_rsvps']);
    $statTrend = null;
    $statTrendLabel = 'In selected period';
    $statAccent = 'brand';
    $statIcon = 'mail';
    require __DIR__ . '/../../components/stat-card-trend.php';

    $statLabel = 'Checked In';
    $statValue = number_format((int) $stats['total_attendance']);
    $statTrend = null;
    $statTrendLabel = 'Total check-ins';
    $statAccent = 'success';
    $statIcon = 'chart';
    require __DIR__ . '/../../components/stat-card-trend.php';

    $statLabel = 'No-shows';
    $statValue = number_format((int) $noShowCount);
    $statTrend = null;
    $statTrendLabel = e((string) $noShowRate) . '% of RSVP yes';
    $statAccent = 'rose';
    $statIcon = 'ticket';
    require __DIR__ . '/../../components/stat-card-trend.php';
    ?>
</div>

<div class="mb-8 rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
    <?php
    $chartCardTitle = 'Checked-in vs no-shows';
    $chartCardSubtitle = 'By event (filtered)';
    $chartCardId = 'rsvpStackedChart';
    $chartCardHeight = '320px';
    require __DIR__ . '/../../components/chart-card.php';
    ?>
</div>

<?php
$tableTitle = 'By event';
$tableColumns = [
    ['key' => 'title', 'label' => 'Event'],
    ['key' => 'event_date', 'label' => 'Date'],
    ['key' => 'rsvp_yes', 'label' => 'Primary Yes', 'class' => 'text-right'],
    ['key' => 'additional_guests', 'label' => 'Addl Guests', 'class' => 'text-right'],
    ['key' => 'total_expected', 'label' => 'Total Headcount', 'class' => 'text-right'],
    ['key' => 'checked_in', 'label' => 'Checked-in', 'class' => 'text-right'],
    ['key' => 'no_show_count', 'label' => 'No-shows', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($rsvpReportEvents as $ev) {
    $tableRows[] = [
        'title' => (string) ($ev['title'] ?? ''),
        'event_date' => formatDate($ev['event_date']),
        'rsvp_yes' => (string) (int) ($ev['rsvp_yes'] ?? 0),
        'additional_guests' => '+' . (int) ($ev['additional_guests'] ?? 0),
        'total_expected' => (string) (int) ($ev['total_expected'] ?? 0),
        'checked_in' => (string) (int) ($ev['checked_in'] ?? 0),
        'no_show_count' => (string) (int) ($ev['no_show_count'] ?? 0),
    ];
}
$tableEmptyMessage = 'No events in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
