<?php /** @var list<array<string, mixed>> $memberEngagementList */ ?>
<div class="mb-8 grid grid-cols-1 gap-6 lg:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Attendance rate distribution';
        $chartCardId = 'membersRateHistogram';
        $chartCardHeight = '300px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Top engaged members';
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
