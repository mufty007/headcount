<?php /** @var array<string, mixed> $feedbackSummary */ /** @var array<string, float|null> $feedbackQuestionAvgs */ /** @var list<array<string, mixed>> $feedbackByEventList */ ?>
<div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    <div class="rounded-2xl border border-gray-200 p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total responses</p>
        <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= (int) ($feedbackSummary['total_responses'] ?? 0) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Avg overall rating</p>
        <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= $feedbackSummary['avg_overall'] !== null ? e((string) $feedbackSummary['avg_overall']) . ' / 5' : '—' ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Response rate</p>
        <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= e((string) ($feedbackSummary['response_rate_pct'] ?? 0)) ?>%</p>
    </div>
    <div class="rounded-2xl border border-gray-200 p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <p class="text-xs font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Events with feedback</p>
        <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white"><?= (int) ($feedbackSummary['events_with_feedback'] ?? 0) ?></p>
    </div>
</div>

<div class="mb-8 grid grid-cols-1 gap-6 xl:grid-cols-2">
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Average rating by question';
        $chartCardSubtitle = 'Org-wide for filtered events';
        $chartCardId = 'feedbackQuestionBarChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
    <div class="rounded-2xl border border-gray-200 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php
        $chartCardTitle = 'Responses over time';
        $chartCardSubtitle = 'By submission date';
        $chartCardId = 'feedbackTrendLineChart';
        $chartCardHeight = '320px';
        require __DIR__ . '/../../components/chart-card.php';
        ?>
    </div>
</div>

<?php
$tableTitle = 'Feedback by event';
$tableActions = '<span class="text-theme-xs text-gray-500 dark:text-gray-400">' . count($feedbackByEventList) . ' events</span>';
$tableColumns = [
    ['key' => 'title', 'label' => 'Event'],
    ['key' => 'event_date', 'label' => 'Date'],
    ['key' => 'checked_in', 'label' => 'Checked in', 'class' => 'text-right'],
    ['key' => 'responses', 'label' => 'Responses', 'class' => 'text-right'],
    ['key' => 'response_rate_pct', 'label' => 'Rate %', 'class' => 'text-right'],
    ['key' => 'avg_overall', 'label' => 'Avg overall', 'class' => 'text-right'],
    ['key' => 'link', 'label' => '', 'class' => 'text-right', 'raw' => true],
];
$tableRows = [];
foreach ($feedbackByEventList as $ev) {
    $eid = (int) ($ev['id'] ?? 0);
    $tableRows[] = [
        'title' => (string) ($ev['title'] ?? ''),
        'event_date' => formatDate($ev['event_date'] ?? ''),
        'checked_in' => (string) (int) ($ev['checked_in'] ?? 0),
        'responses' => (string) (int) ($ev['responses'] ?? 0),
        'response_rate_pct' => e((string) ($ev['response_rate_pct'] ?? 0)) . '%',
        'avg_overall' => $ev['avg_overall'] !== null ? e((string) $ev['avg_overall']) : '—',
        'link' => $eid > 0
            ? '<a href="' . e($adminBase . '/index.php?page=event-details&id=' . $eid) . '" class="text-brand-600 text-xs font-semibold hover:underline">View</a>'
            : '',
    ];
}
$tableEmptyMessage = 'No events with feedback collection enabled in this period.';
require __DIR__ . '/../../components/data-table.php';
?>
