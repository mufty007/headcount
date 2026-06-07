<?php
/**
 * Chart card shell — TailAdmin chart-01 pattern
 * Expects: $chartCardTitle (string),
 *          $chartCardId (string — DOM id for ApexCharts mount),
 *          $chartCardSubtitle (string, optional),
 *          $chartCardActions (string, optional HTML),
 *          $chartCardTabs (array of ['id'=>'week', 'label'=>'Week', 'active'=>true], optional),
 *          $chartCardHeight (string, default '320px'),
 *          $chartCardColSpan (string, optional wrapper class)
 */
if (!isset($chartCardTitle) || !isset($chartCardId)) { return; }
$chartCardSubtitle = $chartCardSubtitle ?? '';
$chartCardActions  = $chartCardActions ?? '';
$chartCardTabs     = $chartCardTabs ?? [];
$chartCardHeight   = $chartCardHeight ?? '320px';
$chartCardColSpan  = $chartCardColSpan ?? '';
?>
<div class="ta-chart-card px-5 pt-5 sm:px-6 sm:pt-6 <?= e($chartCardColSpan) ?>">
    <div class="ta-card-header mb-4">
        <div class="min-w-0">
            <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90"><?= e($chartCardTitle) ?></h3>
            <?php if ($chartCardSubtitle !== ''): ?>
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400"><?= e($chartCardSubtitle) ?></p>
            <?php endif; ?>
        </div>
        <div class="flex shrink-0 items-center gap-3">
            <?php if (!empty($chartCardTabs)): ?>
                <?php
                $cardTabs = $chartCardTabs;
                $cardTabsVar = 'chartTab_' . preg_replace('/[^a-z0-9]/i', '_', $chartCardId);
                require __DIR__ . '/card-tabs.php';
                unset($cardTabs, $cardTabsVar);
                ?>
            <?php endif; ?>
            <?php if ($chartCardActions !== ''): ?>
                <div class="flex items-center gap-2"><?= $chartCardActions ?></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="max-w-full overflow-x-auto custom-scrollbar">
        <div id="<?= e($chartCardId) ?>" class="reports-apex-chart payment-transfers-apex-chart w-full" style="min-height:<?= e($chartCardHeight) ?>"></div>
    </div>
</div>
