<?php
/**
 * Data table — TailAdmin table-01 (avatar, badge, actions columns)
 * Expects: $tableColumns (key, label, class, type: text|avatar|badge|actions|raw, options for badge colors),
 *          $tableRows, $tableTitle, $tableActions, $tableEmptyMessage, $tableEmptyAction, $tableFilterBtn (bool)
 */
if (!isset($tableColumns) || !is_array($tableColumns)) { return; }
$tableRows         = $tableRows ?? [];
$tableTitle        = $tableTitle ?? '';
$tableActions      = $tableActions ?? '';
$tableEmptyMessage = $tableEmptyMessage ?? 'No data found.';
$tableEmptyAction  = $tableEmptyAction ?? '';
$tableFilterBtn    = $tableFilterBtn ?? false;

$badgeVariants = [
    'success' => 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400',
    'warning' => 'bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400',
    'error'   => 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400',
    'brand'   => 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400',
    'gray'    => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
];

function hc_data_table_avatar(string $name, string $email = '', string $img = ''): string {
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        if ($part !== '') { $initials .= strtoupper($part[0]); }
        if (strlen($initials) >= 2) break;
    }
    if ($initials === '' && $email !== '') {
        $initials = strtoupper($email[0]);
    }
    $accent = ['bg-brand-100 text-brand-700', 'bg-success-100 text-success-700', 'bg-warning-100 text-warning-700', 'bg-sky-100 text-sky-700'];
    $color = $accent[crc32($name ?: $email) % count($accent)];
    $imgHtml = $img !== '' ? '<img src="' . e($img) . '" alt="" class="ta-avatar ta-avatar-sm object-cover">' : '';
    $inner = $imgHtml !== '' ? $imgHtml : '<span class="ta-avatar ta-avatar-sm ' . $color . '">' . e($initials) . '</span>';
    $sub = $email !== '' ? '<span class="block text-theme-xs text-gray-500 dark:text-gray-400">' . e($email) . '</span>' : '';
    return '<div class="flex items-center gap-3">' . $inner . '<div class="min-w-0"><span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90">' . e($name) . '</span>' . $sub . '</div></div>';
}
?>
<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
    <?php if ($tableTitle !== '' || $tableActions !== '' || $tableFilterBtn): ?>
        <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <?php if ($tableTitle !== ''): ?>
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90"><?= e($tableTitle) ?></h3>
            <?php else: ?><div></div><?php endif; ?>
            <div class="flex items-center gap-3">
                <?php if ($tableFilterBtn): ?>
                    <button type="button" class="btn-secondary py-2.5 text-theme-sm shadow-theme-xs">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                        Filter
                    </button>
                <?php endif; ?>
                <?= $tableActions ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="w-full overflow-x-auto custom-scrollbar">
        <table class="min-w-full">
            <thead>
                <tr class="border-y border-gray-100 dark:border-gray-800">
                    <?php foreach ($tableColumns as $col): ?>
                        <th class="py-3 pr-4 text-left <?= e($col['class'] ?? '') ?>">
                            <p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400"><?= e($col['label']) ?></p>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                <?php if (empty($tableRows)): ?>
                    <tr>
                        <td colspan="<?= count($tableColumns) ?>" class="py-12 text-center">
                            <p class="text-sm text-gray-500 dark:text-gray-400"><?= e($tableEmptyMessage) ?></p>
                            <?php if ($tableEmptyAction !== ''): ?><div class="mt-3"><?= $tableEmptyAction ?></div><?php endif; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($tableRows as $row): ?>
                        <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                            <?php foreach ($tableColumns as $col):
                                $key  = $col['key'] ?? '';
                                $type = $col['type'] ?? 'text';
                                $val  = $key !== '' ? ($row[$key] ?? '') : '';
                            ?>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300 <?= e($col['class'] ?? '') ?>">
                                    <?php if ($type === 'avatar'):
                                        $name  = $row[$col['avatar_name'] ?? $key] ?? (string) $val;
                                        $email = $row[$col['avatar_email'] ?? 'email'] ?? '';
                                        $img   = $row[$col['avatar_img'] ?? 'avatar'] ?? '';
                                        echo hc_data_table_avatar((string) $name, (string) $email, (string) $img);
                                    elseif ($type === 'badge'):
                                        $badgeVal = (string) $val;
                                        $variant  = $row[$col['badge_variant_key'] ?? ($key . '_variant')] ?? ($col['badge_variant'] ?? 'gray');
                                        $cls = $badgeVariants[$variant] ?? $badgeVariants['gray'];
                                    ?>
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium <?= $cls ?>"><?= e($badgeVal) ?></span>
                                    <?php elseif ($type === 'actions' && isset($row[$col['actions_key'] ?? 'actions_html'])): ?>
                                        <?= $row[$col['actions_key'] ?? 'actions_html'] ?>
                                    <?php elseif (!empty($col['raw']) && isset($row[$col['raw_key'] ?? $key])): ?>
                                        <?= $row[$col['raw_key'] ?? $key] ?>
                                    <?php else: ?>
                                        <?= e((string) $val) ?>
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
