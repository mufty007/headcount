<?php
/**
 * Empty state card – icon, message, optional CTA
 * Expects: $emptyMessage (string), $emptyIcon (string, optional: 'calendar'|'users'|'inbox'|'folder'),
 *          $emptyAction (string, optional HTML for button/link)
 */
if (!isset($emptyMessage)) {
    return;
}
$emptyIcon = $emptyIcon ?? 'folder';
$emptyAction = $emptyAction ?? '';

$icons = [
    'calendar' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'users' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    'inbox' => 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4',
    'folder' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
];
$path = $icons[$emptyIcon] ?? $icons['folder'];
?>
<div class="rounded-2xl border border-gray-200 bg-white px-6 py-12 text-center shadow-card dark:border-gray-700 dark:bg-gray-800">
    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
        <svg class="h-8 w-8 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= e($path) ?>"></path></svg>
    </div>
    <p class="font-medium text-gray-700 dark:text-gray-200"><?= e($emptyMessage) ?></p>
    <?php if ($emptyAction !== ''): ?>
        <div class="mt-4"><?= $emptyAction ?></div>
    <?php endif; ?>
</div>
