<?php
/** @var list<array<string, mixed>> $memberEngagementList */
/** @var list<array{month: string, new_count: int, cumulative: int, is_baseline?: bool}> $memberGrowthMonthly */
/** @var string $primaryColor */
$memberEngagementList = $memberEngagementList ?? [];
$memberGrowthMonthly = $memberGrowthMonthly ?? [];
$primaryColor = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($primaryColor ?? '')) ? $primaryColor : '#3B82F6';

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

$growthChartRows = $memberGrowthMonthly;
$maxNew = 1;
$maxCum = 1;
foreach ($growthChartRows as $row) {
    $maxNew = max($maxNew, (int) ($row['new_count'] ?? 0));
    $maxCum = max($maxCum, (int) ($row['cumulative'] ?? 0));
}

$chartW = 760;
$chartH = 300;
$padL = 52;
$padR = 56;
$padT = 28;
$padB = 44;
$plotW = $chartW - $padL - $padR;
$plotH = $chartH - $padT - $padB;
$n = count($growthChartRows);
$barColor = '#10B981';
$lineColor = $primaryColor;

// Attendance rate histogram (server-rendered)
$rateBuckets = [
    ['label' => '0–24%', 'count' => 0],
    ['label' => '25–49%', 'count' => 0],
    ['label' => '50–74%', 'count' => 0],
    ['label' => '75–99%', 'count' => 0],
    ['label' => '100%', 'count' => 0],
];
foreach ($memberEngagementList as $m) {
    $r = (float) ($m['attendance_rate'] ?? 0);
    if ($r >= 100) {
        $idx = 4;
    } elseif ($r >= 75) {
        $idx = 3;
    } elseif ($r >= 50) {
        $idx = 2;
    } elseif ($r >= 25) {
        $idx = 1;
    } else {
        $idx = 0;
    }
    $rateBuckets[$idx]['count']++;
}
$maxRateBucket = 1;
foreach ($rateBuckets as $b) {
    $maxRateBucket = max($maxRateBucket, (int) $b['count']);
}

// Top engaged members (server-rendered)
$topEngaged = $memberEngagementList;
usort($topEngaged, static fn ($a, $b) => ((int) ($b['events_attended'] ?? 0)) <=> ((int) ($a['events_attended'] ?? 0)));
$topEngaged = array_slice($topEngaged, 0, 12);
$maxAttended = 1;
foreach ($topEngaged as $m) {
    $maxAttended = max($maxAttended, (int) ($m['events_attended'] ?? 0));
}
?>
<div class="mb-8 rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <div class="mb-4">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Member growth</h3>
        <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">Green bars = new signups that month (left scale). Blue line = cumulative active members (right scale).</p>
    </div>

    <?php if ($n === 0): ?>
        <p class="py-12 text-center text-sm text-gray-500 dark:text-gray-400">No member signup data for this date range.</p>
    <?php else: ?>
        <div class="w-full overflow-x-auto">
            <svg viewBox="0 0 <?= (int) $chartW ?> <?= (int) $chartH ?>" class="mx-auto h-auto w-full max-w-5xl" role="img" aria-label="Member growth chart">
                <title>Member growth</title>
                <?php for ($g = 0; $g <= 4; $g++):
                    $gy = $padT + ($plotH * $g / 4);
                    $leftVal = (int) round($maxNew * (1 - $g / 4));
                    $rightVal = (int) round($maxCum * (1 - $g / 4));
                    ?>
                    <line x1="<?= $padL ?>" y1="<?= round($gy, 1) ?>" x2="<?= $padL + $plotW ?>" y2="<?= round($gy, 1) ?>" stroke="#E5E7EB" stroke-width="1" />
                    <text x="<?= $padL - 10 ?>" y="<?= round($gy + 3, 1) ?>" text-anchor="end" font-size="10" fill="#10B981"><?= $leftVal ?></text>
                    <text x="<?= $padL + $plotW + 10 ?>" y="<?= round($gy + 3, 1) ?>" text-anchor="start" font-size="10" fill="<?= e($lineColor) ?>"><?= $rightVal ?></text>
                <?php endfor; ?>

                <?php
                $barW = $n > 0 ? max(10, min(40, ($plotW / max($n, 1)) * 0.4)) : 16;
                $points = [];
                foreach ($growthChartRows as $i => $row):
                    $cx = $padL + ($n === 1 ? $plotW / 2 : ($plotW * $i / max(1, $n - 1)));
                    $new = (int) ($row['new_count'] ?? 0);
                    $cum = (int) ($row['cumulative'] ?? 0);
                    $barH = ($new / $maxNew) * $plotH;
                    $barX = $cx - ($barW / 2);
                    $barY = $padT + $plotH - $barH;
                    $lineY = $padT + $plotH - (($cum / $maxCum) * $plotH);
                    $points[] = ['x' => round($cx, 1), 'y' => round($lineY, 1), 'cum' => $cum, 'new' => $new];
                    $monthLabel = !empty($row['is_baseline'])
                        ? (string) ($row['month'] ?? 'prior')
                        : (string) ($row['month'] ?? '');
                    ?>
                    <?php if ($new > 0 || empty($row['is_baseline'])): ?>
                        <rect x="<?= round($barX, 1) ?>" y="<?= round($barY, 1) ?>" width="<?= round($barW, 1) ?>" height="<?= round(max($barH, $new > 0 ? 3 : 0), 1) ?>" fill="<?= e($barColor) ?>" opacity="<?= !empty($row['is_baseline']) ? '0.35' : '0.9' ?>" rx="3">
                            <title><?= e($monthLabel) ?>: <?= $new ?> new members</title>
                        </rect>
                    <?php endif; ?>
                    <text x="<?= round($cx, 1) ?>" y="<?= $padT + $plotH + 18 ?>" text-anchor="middle" font-size="11" fill="#4B5563"><?= e($monthLabel) ?></text>
                <?php endforeach; ?>

                <?php if (count($points) >= 2):
                    $d = '';
                    foreach ($points as $pi => $pt) {
                        $d .= ($pi === 0 ? 'M' : 'L') . $pt['x'] . ',' . $pt['y'] . ' ';
                    }
                    ?>
                    <path d="<?= e(trim($d)) ?>" fill="none" stroke="<?= e($lineColor) ?>" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round" />
                <?php endif; ?>

                <?php foreach ($points as $pt): ?>
                    <circle cx="<?= $pt['x'] ?>" cy="<?= $pt['y'] ?>" r="4.5" fill="<?= e($lineColor) ?>" stroke="#fff" stroke-width="1.5">
                        <title>Cumulative active: <?= (int) $pt['cum'] ?></title>
                    </circle>
                    <text x="<?= $pt['x'] ?>" y="<?= max($padT + 12, $pt['y'] - 10) ?>" text-anchor="middle" font-size="10" font-weight="600" fill="<?= e($lineColor) ?>"><?= (int) $pt['cum'] ?></text>
                <?php endforeach; ?>

                <text x="<?= $padL ?>" y="<?= $chartH - 6 ?>" text-anchor="start" font-size="9" fill="#10B981">New / month →</text>
                <text x="<?= $padL + $plotW ?>" y="<?= $chartH - 6 ?>" text-anchor="end" font-size="9" fill="<?= e($lineColor) ?>">← Cumulative total</text>
            </svg>
        </div>
        <div class="mt-3 flex flex-wrap items-center gap-4 text-theme-xs text-gray-500 dark:text-gray-400">
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-sm bg-emerald-500"></span> New members / month (left)</span>
            <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full" style="background:<?= e($lineColor) ?>"></span> Cumulative active (right)</span>
        </div>
        <?php if (count($memberGrowthTableRows) <= 1): ?>
            <p class="mt-3 text-theme-xs text-amber-700 dark:text-amber-300">Tip: widen the date range (e.g. YTD) to see month-by-month growth instead of a single signup month.</p>
        <?php endif; ?>
    <?php endif; ?>
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
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Attendance rate distribution</h3>
        <?php if ($memberEngagementList === []): ?>
            <p class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">No member activity in this period.</p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($rateBuckets as $bucket):
                    $count = (int) $bucket['count'];
                    $pct = $maxRateBucket > 0 ? round(($count / $maxRateBucket) * 100) : 0;
                    ?>
                    <div>
                        <div class="mb-1 flex items-center justify-between text-xs text-gray-600 dark:text-gray-300">
                            <span class="font-medium"><?= e($bucket['label']) ?></span>
                            <span><?= $count ?> member<?= $count === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="h-3 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full transition-all" style="width:<?= (int) $pct ?>%;background:<?= e($primaryColor) ?>"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
        <h3 class="mb-4 text-lg font-semibold text-gray-800 dark:text-white/90">Top engaged members</h3>
        <?php if ($topEngaged === []): ?>
            <p class="py-10 text-center text-sm text-gray-500 dark:text-gray-400">No member activity in this period.</p>
        <?php else: ?>
            <div class="space-y-2.5">
                <?php foreach ($topEngaged as $m):
                    $name = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
                    if ($name === '') {
                        $name = (string) ($m['email'] ?? 'Member');
                    }
                    $attended = (int) ($m['events_attended'] ?? 0);
                    $pct = $maxAttended > 0 ? round(($attended / $maxAttended) * 100) : 0;
                    ?>
                    <div>
                        <div class="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span class="truncate font-medium text-gray-700 dark:text-gray-200" title="<?= e($name) ?>"><?= e($name) ?></span>
                            <span class="shrink-0 text-gray-500 dark:text-gray-400"><?= $attended ?> event<?= $attended === 1 ? '' : 's' ?></span>
                        </div>
                        <div class="h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full rounded-full bg-violet-500" style="width:<?= (int) $pct ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
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
