<?php
/**
 * Upcoming schedule timeline — TailAdmin upcoming-schedule pattern
 * Expects: $scheduleTitle (string, optional),
 *          $scheduleViewAllUrl (string, optional),
 *          $scheduleItems (array of ['date'=>'Wed, 11 Jan', 'time'=>'09:20 AM', 'title'=>'...', 'subtitle'=>'...', 'url'=>'...'])
 */
$scheduleTitle      = $scheduleTitle ?? 'Upcoming Schedule';
$scheduleViewAllUrl = $scheduleViewAllUrl ?? '';
$scheduleItems      = $scheduleItems ?? [];
?>
<div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:p-6">
    <div class="ta-card-header mb-6">
        <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90"><?= e($scheduleTitle) ?></h3>
        <?php if ($scheduleViewAllUrl !== ''): ?>
            <a href="<?= e($scheduleViewAllUrl) ?>" class="text-theme-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">View All</a>
        <?php endif; ?>
    </div>

    <div class="custom-scrollbar max-w-full overflow-x-auto">
        <div class="min-w-[280px]">
            <?php if (empty($scheduleItems)): ?>
                <?php
                $emptyMessage = 'Nothing scheduled.';
                $emptyIcon = 'calendar';
                $emptyAction = '';
                require __DIR__ . '/empty-state.php';
                ?>
            <?php else: ?>
                <div class="flex flex-col gap-1">
                    <?php foreach ($scheduleItems as $item):
                        $url = $item['url'] ?? '';
                        $tag = $url !== '' ? 'a' : 'div';
                        $href = $url !== '' ? ' href="' . e($url) . '"' : '';
                    ?>
                        <<?= $tag ?><?= $href ?> class="flex cursor-pointer items-start gap-4 rounded-lg p-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03] dark:bg-gray-800">
                            <div class="flex shrink-0 flex-col text-center">
                                <span class="text-theme-xs text-gray-500 dark:text-gray-400"><?= e($item['date'] ?? '') ?></span>
                                <span class="text-theme-sm font-medium text-gray-700 dark:text-gray-300"><?= e($item['time'] ?? '') ?></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <span class="mb-0.5 block text-theme-sm font-medium text-gray-800 dark:text-gray-200"><?= e($item['title'] ?? '') ?></span>
                                <?php if (!empty($item['subtitle'])): ?>
                                    <span class="text-theme-xs text-gray-500 dark:text-gray-400"><?= e($item['subtitle']) ?></span>
                                <?php endif; ?>
                            </div>
                        </<?= $tag ?>>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
