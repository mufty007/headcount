<?php
/**
 * Portal status badge — small pill. Dark-mode aware.
 * Expects: $badgeLabel (string),
 *          $badgeTone  (string: 'gray'|'brand'|'success'|'warning'|'error'|'info') default gray
 * Reset $badgeLabel/$badgeTone after include when reusing in a loop.
 */
if (!isset($badgeLabel)) { return; }
$badgeTone = $badgeTone ?? 'gray';
$tones = [
    'gray'    => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200',
    'brand'   => 'bg-brand-50 text-brand-700 dark:bg-brand-950/40 dark:text-brand-300',
    'success' => 'bg-green-50 text-green-700 dark:bg-green-950/30 dark:text-green-400',
    'warning' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/30 dark:text-amber-400',
    'error'   => 'bg-rose-50 text-rose-700 dark:bg-rose-950/30 dark:text-rose-400',
    'info'    => 'bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-400',
];
$cls = $tones[$badgeTone] ?? $tones['gray'];
?>
<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold <?= $cls ?>"><?= e($badgeLabel) ?></span>
