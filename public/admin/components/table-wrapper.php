<?php
/**
 * Table wrapper — TailAdmin table pattern
 * Expects: $tableColumns (array of ['key'=>'name', 'label'=>'Name', 'class'=>'']),
 *          $tableRows (array of associative arrays, or empty),
 *          $tableTitle (string, optional — shown above table),
 *          $tableActions (string, optional HTML — shown top-right alongside title),
 *          $tableEmptyMessage (string, optional),
 *          $tableEmptyAction (string, optional HTML for CTA when empty)
 */
if (!isset($tableColumns) || !is_array($tableColumns)) { return; }
$tableRows         = $tableRows         ?? [];
$tableTitle        = $tableTitle        ?? '';
$tableActions      = $tableActions      ?? '';
$tableEmptyMessage = $tableEmptyMessage ?? 'No data found.';
$tableEmptyAction  = $tableEmptyAction  ?? '';
?>
<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-gray-700 dark:bg-gray-800">

    <?php if ($tableTitle !== '' || $tableActions !== ''): ?>
        <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4 dark:border-gray-700 sm:px-6">
            <?php if ($tableTitle !== ''): ?>
                <h3 class="text-base font-semibold text-gray-800 dark:text-white"><?= htmlspecialchars($tableTitle) ?></h3>
            <?php endif; ?>
            <?php if ($tableActions !== ''): ?>
                <div class="flex items-center gap-2"><?= $tableActions ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="overflow-x-auto custom-scrollbar">
        <table class="w-full text-left">
            <thead>
                <tr class="border-y border-gray-100 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/60">
                    <?php foreach ($tableColumns as $col): ?>
                        <th class="px-5 py-3 sm:px-6 <?= e($col['class'] ?? '') ?>">
                            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                <?= e($col['label']) ?>
                            </p>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                <?php if (empty($tableRows)): ?>
                    <tr>
                        <td colspan="<?= count($tableColumns) ?>" class="px-6 py-12 text-center">
                            <div class="flex flex-col items-center gap-2">
                                <svg class="h-10 w-10 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                </svg>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= e($tableEmptyMessage) ?></p>
                                <?php if ($tableEmptyAction !== ''): ?>
                                    <div class="mt-2"><?= $tableEmptyAction ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tableRows as $row): ?>
                        <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/40 dark:bg-gray-800">
                            <?php foreach ($tableColumns as $col):
                                $key = $col['key'] ?? '';
                                $val = $key !== '' ? ($row[$key] ?? '') : '';
                                $raw = isset($col['raw']) && $col['raw'] && isset($row[$col['raw_key'] ?? $key]);
                            ?>
                                <td class="px-5 py-3.5 text-sm text-gray-700 dark:text-gray-300 sm:px-6 <?= e($col['class'] ?? '') ?>">
                                    <?php if ($raw && isset($row[$col['raw_key'] ?? $key])): ?>
                                        <?= $row[$col['raw_key'] ?? $key] ?>
                                    <?php else: ?>
                                        <?= e((string)$val) ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
