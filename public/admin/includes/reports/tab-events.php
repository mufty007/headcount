<?php /** @var list<array<string, mixed>> $eventPerformanceList */ ?>
<div class="mb-8 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Top check-ins';
        $chartCardSubtitle = 'Up to 15 events by filtered set';
        $chartCardId = 'eventsPerformanceBarChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'No-show rate';
        $chartCardSubtitle = 'Events with RSVP yes';
        $chartCardId = 'eventsNoShowColumnChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
</div>

<?php
$tableTitle = 'Event performance';
$tableActions = '<span class="text-theme-xs text-gray-500 dark:text-gray-400">' . count($eventPerformanceList) . ' events</span>';
$tableColumns = [
    ['key' => 'title', 'label' => 'Event'],
    ['key' => 'event_date', 'label' => 'Date'],
    ['key' => 'category', 'label' => 'Category'],
    ['key' => 'capacity', 'label' => 'Capacity', 'class' => 'text-right'],
    ['key' => 'rsvp_yes', 'label' => 'Primary Yes', 'class' => 'text-right'],
    ['key' => 'total_expected', 'label' => 'Total Headcount', 'class' => 'text-right'],
    ['key' => 'checked_in', 'label' => 'Checked In', 'class' => 'text-right'],
    ['key' => 'no_show_pct', 'label' => 'No-show %', 'class' => 'text-right'],
    ['key' => 'utilization_pct', 'label' => 'Util %', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($eventPerformanceList as $ev) {
    $tableRows[] = [
        'title' => (string) ($ev['title'] ?? ''),
        'event_date' => formatDate($ev['event_date']),
        'category' => (string) ($ev['category'] ?? '—'),
        'capacity' => $ev['capacity'] !== null ? (string) (int) $ev['capacity'] : '—',
        'rsvp_yes' => (string) (int) ($ev['rsvp_yes'] ?? 0),
        'total_expected' => (string) (int) ($ev['total_expected'] ?? 0),
        'checked_in' => (string) (int) ($ev['checked_in'] ?? 0),
        'no_show_pct' => e((string) ($ev['no_show_pct'] ?? 0)) . '%',
        'utilization_pct' => $ev['utilization_pct'] !== null ? e((string) $ev['utilization_pct']) . '%' : '—',
    ];
}
$tableEmptyMessage = 'No events in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
