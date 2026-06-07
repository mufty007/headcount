<!-- Reusable Modal Component — TailAdmin pattern -->
<div x-show="<?= $modalName ?>"
     x-cloak
     @keydown.escape.window="<?= $modalName ?> = false"
     class="fixed inset-0 flex items-center justify-center p-5 overflow-y-auto"
     style="display: none; z-index: 99999;">

    <!-- Backdrop (TailAdmin: bg-gray-400/50 backdrop-blur) -->
    <div class="fixed inset-0 h-full w-full bg-gray-400/50 backdrop-blur-[4px] transition-opacity dark:bg-gray-950/70"
         @click="<?= $modalName ?> = false"
         style="z-index: 1;"></div>

    <!-- Modal shell (TailAdmin: rounded-3xl, no border) -->
    <div class="no-scrollbar relative flex w-full max-w-<?= $maxWidth ?? '2xl' ?> flex-col overflow-y-auto rounded-3xl bg-white shadow-theme-xl dark:bg-gray-800"
         style="z-index: 2; max-height: 90vh;"
         @click.stop>

        <!-- Header -->
        <div class="flex items-start justify-between px-6 pt-6 pb-0 sm:px-8 sm:pt-8">
            <div class="pr-10">
                <h4 class="text-xl font-semibold text-gray-800 dark:text-white sm:text-2xl">
                    <?php if (isset($modalTitleDynamic) && $modalTitleDynamic): ?>
                        <span x-text="<?= $modalTitleDynamic ?>"></span>
                    <?php elseif (isset($modalTitleRaw) && $modalTitleRaw): ?>
                        <?= $modalTitle ?>
                    <?php else: ?>
                        <?= htmlspecialchars($modalTitle ?? 'Modal') ?>
                    <?php endif; ?>
                </h4>
                <?php if (!empty($modalSubtitle)): ?>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($modalSubtitle) ?></p>
                <?php endif; ?>
            </div>
            <button type="button"
                    @click="<?= $modalName ?> = false"
                    class="ta-modal-close flex-shrink-0">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <!-- Content (scrollable) -->
        <div class="flex-1 overflow-y-auto px-6 py-5 sm:px-8 sm:py-6">
            <?= $modalContent ?>
        </div>
    </div>
</div>
