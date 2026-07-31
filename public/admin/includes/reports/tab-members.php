<?php
/** @var list<array<string, mixed>> $memberEngagementList */
/** @var list<array{month: string, new_count: int, cumulative: int}> $memberGrowthMonthly */
$memberGrowthMonthly = $memberGrowthMonthly ?? [];
$memberGrowthTableRows = array_values(array_filter(
    $memberGrowthMonthly,
    static fn ($row) => empty($row['is_baseline'])
));
$newMembersInRange = 0;
foreach ($memberGrowthTableRows as $row) {
    $newMembersInRange += (int) ($row['new_count'] ?? 0);
}
$endingTotal = $memberGrowthMonthly !== []
    ? (int) ($memberGrowthMonthly[array_key_last($memberGrowthMonthly)]['cumulative'] ?? 0)
    : 0;
?>
<div class="mb-8 rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
    <?php
    $chartCardTitle = 'Member growth';
    $chartCardSubtitle = 'New signups per month and cumulative active members (by account created date)';
    $chartCardId = 'membersGrowthChart';
    $chartCardHeight = '320px';
    require __DIR__ . '/../../components/chart-card.php';
    ?>
</div>

<?php if ($memberGrowthTableRows !== []): ?>
<div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">New members in range</p>
        <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white"><?= (int) $newMembersInRange ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Active members (end of range)</p>
        <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white"><?= (int) $endingTotal ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-theme-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Months shown</p>
        <p class="mt-2 text-2xl font-semibold text-gray-900 dark:text-white"><?= count($memberGrowthTableRows) ?></p>
    </div>
</div>

<?php
$tableTitle = 'New members by month';
$tableActions = '<span class="text-theme-xs text-gray-500 dark:text-gray-400">Based on account created date</span>';
$tableColumns = [
    ['key' => 'month', 'label' => 'Month', 'type' => 'text'],
    ['key' => 'new_count', 'label' => 'New members', 'class' => 'text-right'],
    ['key' => 'cumulative', 'label' => 'Cumulative active', 'class' => 'text-right'],
];
$tableRows = [];
foreach ($memberGrowthTableRows as $row) {
    $tableRows[] = [
        'month' => (string) ($row['month'] ?? '—'),
        'new_count' => (string) (int) ($row['new_count'] ?? 0),
        'cumulative' => (string) (int) ($row['cumulative'] ?? 0),
    ];
}
$tableEmptyMessage = 'No member signups in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
<?php endif; ?>

<div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Attendance rate distribution';
        $chartCardSubtitle = null;
        $chartCardId = 'membersRateHistogram';
        $chartCardHeight = '300px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Top engaged members';
        $chartCardSubtitle = null;
        $chartCardId = 'membersTopBarChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
</div>

<?php
$tableTitle = 'Member engagement';
$tableActions = '<span class="text-theme-xs text-gray-500 dark:text-gray-400">Max 200 with activity</span>';
$tableColumns = [
    ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
    ['key' => 'email', 'label' => 'Email', 'type' => 'text'],
    ['key' => 'events_attended', 'label' => 'Attended', 'class' => 'text-right'],
    ['key' => 'events_rsvpd', 'label' => "RSVP'd", 'class' => 'text-right'],
    ['key' => 'no_shows', 'label' => 'No-shows', 'class' => 'text-right'],
    ['key' => 'attendance_rate', 'label' => 'Rate %', 'class' => 'text-right'],
    ['key' => 'last_attended', 'label' => 'Last attended', 'type' => 'text'],
];
$tableRows = [];
foreach ($memberEngagementList as $m) {
    $tableRows[] = [
        'name' => trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')),
        'email' => (string) ($m['email'] ?? '—'),
        'events_attended' => (string) (int) ($m['events_attended'] ?? 0),
        'events_rsvpd' => (string) (int) ($m['events_rsvpd'] ?? 0),
        'no_shows' => (string) (int) ($m['no_shows'] ?? 0),
        'attendance_rate' => e((string) ($m['attendance_rate'] ?? 0)) . '%',
        'last_attended' => !empty($m['last_attended']) ? formatDate($m['last_attended']) : '—',
    ];
}
$tableEmptyMessage = 'No member activity in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
