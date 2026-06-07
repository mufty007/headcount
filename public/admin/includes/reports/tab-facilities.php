<?php
/** @var array $facilityStats */
/** @var list<array<string, mixed>> $facilityPerformanceList */
?>
<div class="mb-8 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6 md:gap-6">
    <?php
    $facilityMetrics = [
        ['label' => 'Total bookings', 'value' => (int) $facilityStats['total_bookings'], 'accent' => 'brand', 'icon' => 'layers'],
        ['label' => 'Pending', 'value' => (int) $facilityStats['pending'], 'accent' => 'warning', 'icon' => 'chart'],
        ['label' => 'Approved', 'value' => (int) $facilityStats['approved'], 'accent' => 'success', 'icon' => 'calendar'],
        ['label' => 'Rejected', 'value' => (int) $facilityStats['rejected'], 'accent' => 'rose', 'icon' => 'ticket'],
        ['label' => 'Cancelled', 'value' => (int) $facilityStats['cancelled'], 'accent' => 'gray', 'icon' => 'chart'],
        ['label' => 'Captured revenue', 'value' => '$' . number_format((float) $facilityStats['revenue'], 2), 'accent' => 'success', 'icon' => 'currency'],
    ];
    foreach ($facilityMetrics as $fm):
        $statLabel = $fm['label'];
        $statValue = is_numeric($fm['value']) ? number_format((float) $fm['value']) : $fm['value'];
        $statTrend = null;
        $statTrendLabel = 'In selected period';
        $statAccent = $fm['accent'];
        $statIcon = $fm['icon'];
        require __DIR__ . '/../../components/stat-card-trend.php';
    endforeach;
    ?>
</div>

<?php
$tableTitle = 'By facility';
$tableColumns = [
    ['key' => 'name', 'label' => 'Facility'],
    ['key' => 'booking_count', 'label' => 'Bookings', 'class' => 'text-right'],
    ['key' => 'approved_count', 'label' => 'Approved', 'class' => 'text-right'],
    ['key' => 'pending_count', 'label' => 'Pending', 'class' => 'text-right'],
    ['key' => 'hours_booked', 'label' => 'Hours', 'class' => 'text-right'],
    ['key' => 'revenue', 'label' => 'Revenue', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($facilityPerformanceList as $row) {
    $tableRows[] = [
        'name' => (string) ($row['name'] ?? ''),
        'booking_count' => (string) (int) ($row['booking_count'] ?? 0),
        'approved_count' => (string) (int) ($row['approved_count'] ?? 0),
        'pending_count' => (string) (int) ($row['pending_count'] ?? 0),
        'hours_booked' => number_format((float) ($row['hours_booked'] ?? 0), 1),
        'revenue' => '$' . number_format((float) ($row['revenue'] ?? 0), 2),
    ];
}
$tableEmptyMessage = 'No facility booking data for this period.';
require __DIR__ . '/../../components/data-table.php';
?>

<?php if (!empty($facilityPerformanceList)):
    $facRows = array_values(array_filter($facilityPerformanceList, static fn ($r) => ((int) ($r['booking_count'] ?? 0)) > 0));
    usort($facRows, static fn ($a, $b) => ((int) ($b['booking_count'] ?? 0)) <=> ((int) ($a['booking_count'] ?? 0)));
    $facSlice = array_slice($facRows, 0, 10);
    $maxBk = 1;
    foreach ($facSlice as $r) {
        $maxBk = max($maxBk, (int) ($r['booking_count'] ?? 0));
    }
?>
<div class="mt-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Bookings by facility';
        $chartCardId = 'facilityBookingsChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div>
        <?php
        $progressListTitle = 'Booking share';
        $progressListItems = [];
        foreach ($facSlice as $r) {
            $cnt = (int) ($r['booking_count'] ?? 0);
            $progressListItems[] = [
                'label' => (string) ($r['name'] ?? ''),
                'value' => (string) $cnt,
                'percent' => ($cnt / $maxBk) * 100,
                'color' => 'brand',
            ];
        }
        require __DIR__ . '/../../components/progress-list.php';
        ?>
    </div>
</div>
<?php endif; ?>
