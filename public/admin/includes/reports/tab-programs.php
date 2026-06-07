<?php
/** @var array $programStats */
/** @var list<array<string, mixed>> $programPerformanceList */
?>
<div class="mb-8 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-5 md:gap-6">
    <?php
    $programMetrics = [
        ['label' => 'Published programs', 'value' => (int) $programStats['active_programs'], 'accent' => 'brand', 'icon' => 'layers'],
        ['label' => 'Registrations', 'value' => (int) $programStats['registrations'], 'accent' => 'sky', 'icon' => 'users'],
        ['label' => 'Sessions held', 'value' => (int) $programStats['sessions_held'], 'accent' => 'warning', 'icon' => 'calendar'],
        ['label' => 'Attendance records', 'value' => (int) $programStats['attendance_records'], 'accent' => 'success', 'icon' => 'chart'],
        ['label' => 'Registration revenue', 'value' => '$' . number_format((float) $programStats['revenue'], 2), 'accent' => 'success', 'icon' => 'currency'],
    ];
    foreach ($programMetrics as $pm):
        $statLabel = $pm['label'];
        $statValue = is_numeric($pm['value']) ? number_format((float) $pm['value']) : $pm['value'];
        $statTrend = null;
        $statTrendLabel = 'In selected period';
        $statAccent = $pm['accent'];
        $statIcon = $pm['icon'];
        require __DIR__ . '/../../components/stat-card-trend.php';
    endforeach;
    ?>
</div>

<?php
$tableTitle = 'By program';
$tableColumns = [
    ['key' => 'title', 'label' => 'Program'],
    ['key' => 'registrations', 'label' => 'Registrations', 'class' => 'text-right'],
    ['key' => 'active_registrations', 'label' => 'Active', 'class' => 'text-right'],
    ['key' => 'sessions_held', 'label' => 'Sessions', 'class' => 'text-right'],
    ['key' => 'attendance_records', 'label' => 'Attendance', 'class' => 'text-right'],
    ['key' => 'attendance_rate', 'label' => 'Rate %', 'class' => 'text-right'],
    ['key' => 'revenue', 'label' => 'Revenue', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($programPerformanceList as $row) {
    $tableRows[] = [
        'title' => (string) ($row['title'] ?? ''),
        'registrations' => (string) (int) ($row['registrations'] ?? 0),
        'active_registrations' => (string) (int) ($row['active_registrations'] ?? 0),
        'sessions_held' => (string) (int) ($row['sessions_held'] ?? 0),
        'attendance_records' => (string) (int) ($row['attendance_records'] ?? 0),
        'attendance_rate' => number_format((float) ($row['attendance_rate'] ?? 0), 1) . '%',
        'revenue' => '$' . number_format((float) ($row['revenue'] ?? 0), 2),
    ];
}
$tableEmptyMessage = 'No program data for this period.';
require __DIR__ . '/../../components/data-table.php';
?>
