<?php
/**
 * Portal empty state – icon, message, optional CTA. Dark-mode aware.
 * Expects: $emptyMessage (string),
 *          $emptyIcon (string, optional: 'calendar'|'users'|'inbox'|'folder'|'ticket'|'star'),
 *          $emptySubtext (string, optional),
 *          $emptyAction (string, optional HTML for button/link)
 */
if (!isset($emptyMessage)) { return; }
$emptyIcon    = $emptyIcon    ?? 'folder';
$emptySubtext = $emptySubtext ?? '';
$emptyAction  = $emptyAction  ?? '';

$icons = [
    'calendar' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    'users'    => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    'inbox'    => 'M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4',
    'folder'   => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
    'ticket'   => 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z',
    'star'     => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.196-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.783-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
];
$path = $icons[$emptyIcon] ?? $icons['folder'];
?>
<div class="rounded-2xl border border-gray-200 bg-white px-6 py-12 text-center shadow-sm dark:border-gray-700 dark:bg-gray-800">
    <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-700">
        <svg class="h-8 w-8 text-gray-400 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= e($path) ?>"></path></svg>
    </div>
    <p class="font-semibold text-gray-700 dark:text-gray-200"><?= e($emptyMessage) ?></p>
    <?php if ($emptySubtext !== ''): ?>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400"><?= e($emptySubtext) ?></p>
    <?php endif; ?>
    <?php if ($emptyAction !== ''): ?>
        <div class="mt-5"><?= $emptyAction ?></div>
    <?php endif; ?>
</div>
