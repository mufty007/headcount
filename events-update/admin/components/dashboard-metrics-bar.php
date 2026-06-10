<?php
/**
 * Dashboard metrics — single bar with vertical dividers (Daisy-style layout).
 * Expects: $dashboardMetrics as array of up to 4 items, each:
 *   ['label' => string, 'value' => string|int, 'sublabel' => string (optional), 'accent' => string, 'icon' => string]
 */
if (!isset($dashboardMetrics) || !is_array($dashboardMetrics) || count($dashboardMetrics) < 1) {
    return;
}

$iconBgs = [
    'brand'   => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
    'success' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
    'sky'     => 'bg-sky-50 text-sky-600 dark:bg-sky-950/40 dark:text-sky-300',
    'rose'    => 'bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-300',
    'gray'    => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
    'indigo'  => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
    'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-300',
    'amber'   => 'bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300',
];

$iconPaths = [
    'calendar' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'users'    => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
    'chart'    => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    'currency' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z',
    'ticket'   => 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
];

$renderStat = static function (array $m) use ($iconBgs, $iconPaths): void {
    $accent = $m['accent'] ?? 'brand';
    $icon = $m['icon'] ?? '';
    $iconBg = $iconBgs[$accent] ?? $iconBgs['brand'];
    $path = $iconPaths[$icon] ?? '';
    $sublabel = isset($m['sublabel']) ? (string) $m['sublabel'] : '';
    echo '<div class="flex min-w-0 flex-1 flex-col gap-4 sm:px-3 lg:px-5">';
    echo '<div class="flex min-w-0 items-center gap-2 text-gray-900 dark:text-white">';
    if ($path !== '') {
        echo '<div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ' . e($iconBg) . '">';
        echo '<svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">';
        echo '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . e($path) . '"></path>';
        echo '</svg></div>';
    }
    echo '<h3 class="truncate text-base font-medium sm:text-lg">' . e($m['label']) . '</h3>';
    echo '</div>';
    echo '<div>';
    echo '<div class="text-xl font-semibold tracking-tight text-gray-900 tabular-nums dark:text-white sm:text-2xl">' . e((string) $m['value']) . '</div>';
    if ($sublabel !== '') {
        echo '<div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-gray-500 dark:text-gray-400">';
        echo '<span class="font-medium">' . e($sublabel) . '</span></div>';
    }
    echo '</div></div>';
};

$n = count($dashboardMetrics);
?>
<section class="mb-8 rounded-2xl bg-gray-100 p-6 dark:bg-gray-900/50 sm:p-8 lg:p-10" aria-label="Organization metrics">
    <div class="mx-auto flex w-full max-w-[87rem] flex-col gap-0 rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-gray-700 dark:bg-gray-800 lg:flex-row lg:items-stretch">
        <?php
        for ($i = 0; $i < $n; $i++) {
            if ($i > 0) {
                echo '<div class="h-px w-full shrink-0 bg-gray-200 dark:bg-gray-600 lg:hidden" role="separator" aria-hidden="true"></div>';
                echo '<div class="hidden w-px shrink-0 self-stretch bg-gray-200 dark:bg-gray-600 lg:block" role="separator" aria-hidden="true"></div>';
            }
            $renderStat($dashboardMetrics[$i]);
        }
        ?>
    </div>
</section>
