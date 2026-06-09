<?php
/**
 * Portal stat card — label + value + optional icon. Dark-mode aware.
 * Expects: $statLabel (string), $statValue (string|int),
 *          $statIcon  (string SVG path, optional),
 *          $statTone  (string: 'brand'|'success'|'warning'|'error', optional) icon tint
 * Reset vars after include when reusing in a loop.
 */
if (!isset($statLabel)) { return; }
$statValue = $statValue ?? '';
$statIcon  = $statIcon  ?? '';
$statTone  = $statTone  ?? 'brand';
$tones = [
    'brand'   => 'bg-brand-50 text-brand-600 dark:bg-brand-950/40 dark:text-brand-300',
    'success' => 'bg-green-50 text-green-600 dark:bg-green-950/30 dark:text-green-400',
    'warning' => 'bg-amber-50 text-amber-600 dark:bg-amber-950/30 dark:text-amber-400',
    'error'   => 'bg-rose-50 text-rose-600 dark:bg-rose-950/30 dark:text-rose-400',
];
$iconCls = $tones[$statTone] ?? $tones['brand'];
?>
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="truncate text-sm font-medium text-gray-500 dark:text-gray-400"><?= e($statLabel) ?></p>
            <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white"><?= e((string)$statValue) ?></p>
        </div>
        <?php if ($statIcon !== ''): ?>
            <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl <?= $iconCls ?>">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= e($statIcon) ?>"></path></svg>
            </div>
        <?php endif; ?>
    </div>
</div>
