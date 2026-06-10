<?php
/** @var array $revenueStats */
/** @var list<array<string, mixed>> $revenueByEventList */
?>
<div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-2 md:gap-6">
    <?php
    $statLabel = 'Total revenue (period)';
    $statValue = '$' . number_format((float) $revenueStats['total_revenue'], 2);
    $statTrend = null;
    $statTrendLabel = 'Paid ticket revenue';
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

<div class="mb-8 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Revenue by event';
        $chartCardId = 'revenueByEventChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Monthly trend';
        $chartCardId = 'revenueMonthlyChart';
        $chartCardHeight = '280px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
</div>

<?php
$tableTitle = 'Revenue by event';
$tableColumns = [
    ['key' => 'title', 'label' => 'Event'],
    ['key' => 'event_date', 'label' => 'Date'],
    ['key' => 'revenue', 'label' => 'Revenue', 'class' => 'text-right'],
    ['key' => 'paid_count', 'label' => 'Paid count', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($revenueByEventList as $ev) {
    $tableRows[] = [
        'title' => (string) ($ev['title'] ?? ''),
        'event_date' => formatDate($ev['event_date']),
        'revenue' => '$' . number_format((float) ($ev['revenue'] ?? 0), 2),
        'paid_count' => (string) (int) ($ev['paid_count'] ?? 0),
    ];
}
$tableEmptyMessage = 'No revenue data in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
