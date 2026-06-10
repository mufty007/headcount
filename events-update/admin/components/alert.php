<?php
/**
 * Alert / badge component — TailAdmin pattern
 *
 * Alert: $alertType (success|error|warning|info), $alertMessage (string), $alertDismissible (bool)
 * Badge: $badgeLabel (string), $badgeVariant (brand|success|error|warning|gray)
 */
if (isset($badgeLabel) && $badgeLabel !== '') {
    $badgeVariant = $badgeVariant ?? 'brand';
    $badgeClasses = [
        'brand'   => 'bg-brand-50 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300',
        'success' => 'bg-success-50 text-success-700 dark:bg-emerald-950/50 dark:text-emerald-300',
        'error'   => 'bg-error-50 text-error-700 dark:bg-red-950/50 dark:text-red-300',
        'warning' => 'bg-warning-50 text-warning-700 dark:bg-amber-950/50 dark:text-amber-300',
        'gray'    => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    ];
    $cls = $badgeClasses[$badgeVariant] ?? $badgeClasses['brand'];
    echo '<span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium ' . $cls . '">' . e($badgeLabel) . '</span>';
    return;
}

if (!isset($alertMessage) || $alertMessage === '') {
    return;
}

$alertType = $alertType ?? 'info';
$alertDismissible = $alertDismissible ?? false;
$typeClasses = [
    'success' => 'border-success-200 bg-success-50 text-success-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200',
    'error'   => 'border-error-200 bg-error-50 text-error-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200',
    'warning' => 'border-warning-200 bg-warning-50 text-warning-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
    'info'    => 'border-brand-200 bg-brand-50 text-brand-800 dark:border-brand-800 dark:bg-brand-950/40 dark:text-brand-200',
];
$cls = $typeClasses[$alertType] ?? $typeClasses['info'];
?>
<div class="ta-alert mb-4 rounded-xl border p-4 text-sm <?= $cls ?>"<?= $alertDismissible ? ' x-data="{ show: true }" x-show="show" x-transition' : '' ?>>
    <div class="flex items-start justify-between gap-3">
        <p class="font-medium"><?= e($alertMessage) ?></p>
        <?php if ($alertDismissible): ?>
            <button type="button" @click="show = false" class="shrink-0 opacity-70 hover:opacity-100" aria-label="Dismiss">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        <?php endif; ?>
    </div>
</div>
