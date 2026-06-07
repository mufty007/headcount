<?php
/**
 * Stat / Metric card with trend badge — TailAdmin metric-group-01
 * Expects: $statLabel, $statValue,
 *          $statTrend (float|null — positive=up, negative=down, null=hide),
 *          $statTrendLabel (string, optional — e.g. "Vs last month"),
 *          $statAccent (brand|success|warning|sky|rose|gray),
 *          $statIcon (calendar|users|chart|currency|ticket|mail|layers)
 */
if (!isset($statLabel) || !isset($statValue)) { return; }
$statTrend      = $statTrend ?? null;
$statTrendLabel = $statTrendLabel ?? 'Vs last month';
$statAccent     = $statAccent ?? 'brand';
$statIcon       = $statIcon ?? '';

$iconBgs = [
    'brand'   => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
    'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
    'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
    'sky'     => 'bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400',
    'rose'    => 'bg-rose-50 text-rose-600 dark:bg-rose-500/15 dark:text-rose-400',
    'gray'    => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
    'indigo'  => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
    'emerald' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
    'amber'   => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
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

$trendUp = $statTrend !== null && (float) $statTrend >= 0;
$trendDisplay = $statTrend !== null ? abs((float) $statTrend) : 0;
?>
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
    <?php if ($path !== ''): ?>
        <div class="flex h-12 w-12 items-center justify-center rounded-xl <?= $iconBg ?>">
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= e($path) ?>"></path>
            </svg>
        </div>
    <?php endif; ?>

    <div class="mt-5 flex items-end justify-between gap-3">
        <div class="min-w-0">
            <span class="text-sm text-gray-500 dark:text-gray-400"><?= e($statLabel) ?></span>
            <h4 class="mt-2 text-title-sm font-bold text-gray-800 dark:text-white/90"><?= e((string) $statValue) ?></h4>
            <?php if ($statTrendLabel !== '' && $statTrend === null): ?>
                <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500"><?= e($statTrendLabel) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($statTrend !== null): ?>
            <div class="flex flex-col items-end gap-1 shrink-0">
                <span class="<?= $trendUp ? 'ta-trend-up' : 'ta-trend-down' ?>">
                    <?php if ($trendUp): ?>
                        <svg class="h-3 w-3" viewBox="0 0 12 12" fill="currentColor"><path d="M5.565 1.624a.75.75 0 011.118 0l3 3a.75.75 0 01-1.06 1.06L6.873 3.932V10.125a.75.75 0 01-1.5 0V3.936L3.655 5.653a.75.75 0 11-1.06-1.06l3 3z"/></svg>
                    <?php else: ?>
                        <svg class="h-3 w-3" viewBox="0 0 12 12" fill="currentColor"><path d="M5.315 10.376a.75.75 0 001.118 0l3-3a.75.75 0 00-1.06-1.06L6.623 8.068V1.875a.75.75 0 00-1.5 0v6.189L3.405 6.347a.75.75 0 00-1.06 1.06l3 3z"/></svg>
                    <?php endif; ?>
                    <?= e(number_format($trendDisplay, 1)) ?>%
                </span>
                <?php if ($statTrendLabel !== ''): ?>
                    <span class="text-[10px] font-medium text-gray-400 dark:text-gray-500 text-right leading-tight max-w-[7rem]"><?= e($statTrendLabel) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
