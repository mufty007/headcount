<?php
/**
 * Portal modal shell (Alpine-driven). Dark-mode aware.
 * Toggle by setting the Alpine boolean named in $modalState to true/false.
 *
 * Expects: $modalState (string)  Alpine boolean expression controlling visibility (e.g. 'showRefund')
 *          $modalTitle (string)
 *          $modalBody  (string)  HTML for the body
 *          $modalFooter(string, optional) HTML for the footer (buttons)
 *          $modalMax   (string, optional) max-width class (default 'max-w-lg')
 *
 * Renders nothing unless $modalState + $modalTitle are set. The including page must
 * provide the Alpine scope (x-data) that owns the $modalState boolean.
 */
if (!isset($modalState, $modalTitle)) { return; }
$modalBody   = $modalBody   ?? '';
$modalFooter = $modalFooter ?? '';
$modalMax    = $modalMax    ?? 'max-w-lg';
?>
<div x-show="<?= e($modalState) ?>" x-cloak
     @keydown.escape.window="<?= e($modalState) ?> = false"
     class="fixed inset-0 z-[10000] flex items-center justify-center p-4 overflow-y-auto" style="display:none;">
    <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="<?= e($modalState) ?> = false"></div>
    <div class="relative w-full <?= e($modalMax) ?> rounded-2xl border border-gray-200 bg-white shadow-xl dark:border-gray-700 dark:bg-gray-800">
        <div class="flex items-center justify-between border-b border-gray-200 p-5 dark:border-gray-700">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white"><?= e($modalTitle) ?></h3>
            <button type="button" @click="<?= e($modalState) ?> = false" aria-label="Close"
                class="rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-gray-200">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-5"><?= $modalBody ?></div>
        <?php if ($modalFooter !== ''): ?>
            <div class="flex justify-end gap-3 border-t border-gray-200 p-5 dark:border-gray-700"><?= $modalFooter ?></div>
        <?php endif; ?>
    </div>
</div>
