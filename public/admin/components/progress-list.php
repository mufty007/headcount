<?php
/**
 * Progress bar list — TailAdmin CRM Estimated Revenue / Marketing Top Traffic
 * Expects: $progressListTitle (string, optional),
 *          $progressListActions (string, optional HTML),
 *          $progressListItems (array of ['label'=>'Google', 'value'=>'79', 'percent'=>79, 'color'=>'brand']),
 *          $progressListShowValues (bool, default true)
 */
$progressListTitle       = $progressListTitle ?? '';
$progressListActions     = $progressListActions ?? '';
$progressListItems       = $progressListItems ?? [];
$progressListShowValues  = $progressListShowValues ?? true;

$barColors = [
    'brand'   => 'bg-brand-500',
    'success' => 'bg-success-500',
    'warning' => 'bg-warning-500',
    'error'   => 'bg-error-500',
    'sky'     => 'bg-sky-500',
    'rose'    => 'bg-rose-500',
];
?>
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <?php if ($progressListTitle !== '' || $progressListActions !== ''): ?>
        <div class="ta-card-header mb-6">
            <?php if ($progressListTitle !== ''): ?>
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90"><?= e($progressListTitle) ?></h3>
            <?php else: ?><div></div><?php endif; ?>
            <?php if ($progressListActions !== ''): ?>
                <div class="flex items-center gap-2"><?= $progressListActions ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="space-y-5">
        <?php foreach ($progressListItems as $item):
            $label   = $item['label'] ?? '';
            $value   = $item['value'] ?? '';
            $percent = min(100, max(0, (float) ($item['percent'] ?? 0)));
            $color   = $barColors[$item['color'] ?? 'brand'] ?? $barColors['brand'];
        ?>
            <div>
                <div class="mb-2 flex items-center justify-between gap-3">
                    <span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300"><?= e($label) ?></span>
                    <?php if ($progressListShowValues && $value !== ''): ?>
                        <span class="text-theme-sm text-gray-500 dark:text-gray-400"><?= e((string) $value) ?></span>
                    <?php elseif ($progressListShowValues): ?>
                        <span class="text-theme-sm text-gray-500 dark:text-gray-400"><?= e(number_format($percent, 0)) ?>%</span>
                    <?php endif; ?>
                </div>
                <div class="ta-progress-track">
                    <div class="ta-progress-fill <?= e($color) ?>" style="width: <?= e((string) $percent) ?>%"></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (empty($progressListItems)): ?>
            <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">No data available.</p>
        <?php endif; ?>
    </div>
</div>
