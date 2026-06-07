<?php
/**
 * Stat / Metric card — TailAdmin Metric Group pattern
 * Expects: $statLabel (string), $statValue (string|int),
 *          $statSublabel (string, optional),
 *          $statTrend (float|null, optional — if set, renders trend badge via stat-card-trend),
 *          $statTrendLabel (string, optional),
 *          $statAccent (string: brand|success|warning|sky|rose|gray — default brand),
 *          $statIcon (string: 'calendar'|'users'|'chart'|'currency'|'ticket'|'mail'|'layers')
 */
if (!isset($statLabel) || !isset($statValue)) { return; }
if (isset($statTrend)) {
    $statTrendLabel = $statTrendLabel ?? ($statSublabel ?? 'Vs last month');
    require __DIR__ . '/stat-card-trend.php';
    return;
}
$statSublabel = $statSublabel ?? '';
$statAccent   = $statAccent   ?? 'brand';
$statIcon     = $statIcon     ?? '';

$iconBgs = [
    'brand'   => 'bg-brand-50 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300',
    'success' => 'bg-success-50 text-success-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    'warning' => 'bg-warning-50 text-warning-600 dark:bg-amber-950/40 dark:text-amber-300',
    'sky'     => 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    'rose'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    'gray'    => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    // Legacy aliases
    'indigo'  => 'bg-brand-50 text-brand-600 dark:bg-brand-900/40 dark:text-brand-300',
    'emerald' => 'bg-success-50 text-success-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    'amber'   => 'bg-warning-50 text-warning-600 dark:bg-amber-950/40 dark:text-amber-300',
];
$iconBg = $iconBgs[$statAccent] ?? $iconBgs['brand'];

$iconPaths = [
    'calendar' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'users'    => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
    'chart'    => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    'currency' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    'ticket'   => 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
    'mail'     => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
    'layers'   => 'M4 7l8-4 8 4M4 7v10l8 4 8-4V7M4 7l8 4 8-4M12 21V11',
];
$path = $iconPaths[$statIcon] ?? '';
?>
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-gray-700 dark:bg-gray-800 md:p-6">
    <div class="flex flex-col gap-4">
    <?php if ($path !== ''): ?>
        <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl <?= $iconBg ?>">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= e($path) ?>"></path>
            </svg>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <span class="text-sm text-gray-500 dark:text-gray-400"><?= e($statLabel) ?></span>
            <h4 class="mt-1 text-2xl font-bold tracking-tight text-gray-800 dark:text-white md:text-3xl"><?= e((string)$statValue) ?></h4>
        </div>
        <?php if ($statSublabel !== ''): ?>
            <span class="inline-flex max-w-full items-center gap-1 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                <span class="truncate"><?= e($statSublabel) ?></span>
            </span>
        <?php endif; ?>
    </div>
    </div>
</div>
