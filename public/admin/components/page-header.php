<?php
/**
 * Page header — TailAdmin pattern
 * Expects: $pageHeaderTitle (string),
 *          $pageHeaderSubtitle (string, optional),
 *          $pageHeaderBreadcrumb (array of ['label'=>'Events', 'url'=>'...'], optional),
 *          $pageHeaderActions (string, optional HTML — use .page-header-btn-primary / .btn-primary)
 */
if (!isset($pageHeaderTitle)) { return; }
$pageHeaderSubtitle   = $pageHeaderSubtitle   ?? '';
$pageHeaderActions    = $pageHeaderActions    ?? '';
$pageHeaderBreadcrumb = $pageHeaderBreadcrumb ?? [];
?>
<div class="mb-6 sm:mb-8">
    <?php if (!empty($pageHeaderBreadcrumb)): ?>
        <nav class="mb-3 flex items-center gap-1.5 text-sm" aria-label="Breadcrumb">
            <?php foreach ($pageHeaderBreadcrumb as $i => $crumb): ?>
                <?php if ($i > 0): ?>
                    <svg class="h-4 w-4 text-gray-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                <?php endif; ?>
                <?php if (!empty($crumb['url']) && $i < count($pageHeaderBreadcrumb) - 1): ?>
                    <a href="<?= e($crumb['url']) ?>" class="font-medium text-gray-500 hover:text-gray-700 dark:text-slate-400 dark:hover:text-slate-200"><?= e($crumb['label']) ?></a>
                <?php else: ?>
                    <span class="font-medium text-gray-800 dark:text-slate-200"><?= e($crumb['label']) ?></span>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-semibold tracking-tight text-gray-800 dark:text-white sm:text-3xl"><?= e($pageHeaderTitle) ?></h1>
            <?php if ($pageHeaderSubtitle !== ''): ?>
                <p class="mt-1 text-sm text-gray-500 dark:text-slate-400"><?= e($pageHeaderSubtitle) ?></p>
            <?php endif; ?>
        </div>
        <?php if ($pageHeaderActions !== ''): ?>
            <div class="page-header__actions flex flex-shrink-0 flex-wrap items-center justify-end gap-3">
                <?= $pageHeaderActions ?>
            </div>
        <?php endif; ?>
    </div>
</div>
