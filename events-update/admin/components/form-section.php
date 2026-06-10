<?php
/**
 * Form layout section — TailAdmin Form Layout pattern
 * Expects: $formSectionTitle (string),
 *          $formSectionSubtitle (string, optional),
 *          $formSectionContent (string — HTML for fields),
 *          $formSectionCols (int 1|2, default 1),
 *          $formSectionClass (string, optional extra classes)
 */
if (!isset($formSectionTitle) || !isset($formSectionContent)) { return; }
$formSectionSubtitle = $formSectionSubtitle ?? '';
$formSectionCols     = (int) ($formSectionCols ?? 1);
$formSectionClass    = $formSectionClass ?? '';
$gridClass = $formSectionCols === 2 ? 'grid grid-cols-1 gap-5 lg:grid-cols-2' : 'space-y-5';
?>
<div class="ta-form-section mb-6 <?= e($formSectionClass) ?>">
    <div class="ta-form-section-title">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90"><?= e($formSectionTitle) ?></h3>
        <?php if ($formSectionSubtitle !== ''): ?>
            <p class="mt-1 text-theme-sm font-normal text-gray-500 dark:text-gray-400"><?= e($formSectionSubtitle) ?></p>
        <?php endif; ?>
    </div>
    <div class="<?= $gridClass ?>">
        <?= $formSectionContent ?>
    </div>
</div>
