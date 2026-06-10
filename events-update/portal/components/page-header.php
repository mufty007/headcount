<?php
/**
 * Portal page header — title + subtitle + optional actions. Dark-mode aware.
 * Expects: $pageHeaderTitle (string),
 *          $pageHeaderSubtitle (string, optional),
 *          $pageHeaderActions (string, optional HTML)
 */
if (!isset($pageHeaderTitle)) { return; }
$pageHeaderSubtitle = $pageHeaderSubtitle ?? '';
$pageHeaderActions  = $pageHeaderActions  ?? '';
?>
<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
    <div class="min-w-0 flex-1">
        <h1 class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-3xl"><?= e($pageHeaderTitle) ?></h1>
        <?php if ($pageHeaderSubtitle !== ''): ?>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e($pageHeaderSubtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php if ($pageHeaderActions !== ''): ?>
        <div class="flex flex-shrink-0 flex-wrap items-center justify-end gap-3"><?= $pageHeaderActions ?></div>
    <?php endif; ?>
</div>
