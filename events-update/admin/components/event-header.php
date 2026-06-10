<?php
/**
 * Event header block – title, date, time, location, category chips, RSVP/check-in counts, actions
 * Expects: $event (array with title, event_date, start_time, end_time, location, etc.),
 *          $eventStats (array with 'checked_in', 'rsvp_yes', optional),
 *          $eventActions (string, optional HTML for buttons e.g. Start Check-In, Details),
 *          $eventHeaderStatsHtml (string, optional – custom stats block e.g. Alpine-bound; overrides default counts)
 */
if (!isset($event) || !is_array($event)) {
    return;
}
$eventStats = $eventStats ?? ['checked_in' => 0, 'rsvp_yes' => 0];
$eventActions = $eventActions ?? '';
$eventHeaderStatsHtml = $eventHeaderStatsHtml ?? null;
$eventHeaderRootClass = isset($eventHeaderRootClass) ? trim((string) $eventHeaderRootClass) : '';
$showStats = $eventHeaderStatsHtml !== null || isset($event['rsvp_count']) || isset($event['rsvp_head_count']) || isset($event['checkin_count']) || isset($eventStats['checked_in']) || isset($eventStats['rsvp_yes']);
$rsvpHead = isset($event['rsvp_head_count']) ? (int) $event['rsvp_head_count'] : (int) ($event['rsvp_count'] ?? $eventStats['rsvp_yes'] ?? 0);
$rsvpRegistrants = isset($event['rsvp_registrant_count']) ? (int) $event['rsvp_registrant_count'] : $rsvpHead;
$checkinCount = $event['checkin_count'] ?? $eventStats['checked_in'] ?? 0;
?>
<div class="flex flex-col justify-between gap-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-gray-700 dark:bg-gray-800 md:flex-row md:items-center md:p-8<?= $eventHeaderRootClass !== '' ? ' ' . e($eventHeaderRootClass) : '' ?>">
    <div class="min-w-0">
        <h1 class="text-2xl font-semibold tracking-tight text-gray-900 dark:text-white sm:text-3xl"><?= e($event['title'] ?? '') ?></h1>
        <div class="mt-3 flex flex-wrap gap-4 text-sm font-medium text-gray-500 dark:text-gray-400">
            <?php if (!empty($event['event_date'])): ?>
                <span class="flex items-center">
                    <svg class="mr-1.5 h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <?= function_exists('formatDate') ? formatDate($event['event_date']) : e($event['event_date']) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($event['start_time'])): ?>
                <span class="flex items-center">
                    <svg class="mr-1.5 h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <?= function_exists('formatTime') ? formatTime($event['start_time']) : e($event['start_time']) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($event['location'])): ?>
                <span class="flex items-center">
                    <svg class="mr-1.5 h-4 w-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path></svg>
                    <?= e($event['location'] ?? '') ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($showStats && $eventHeaderStatsHtml === null): ?>
            <div class="mt-4 flex items-center space-x-6">
                <div class="flex flex-col">
                    <span class="text-2xl font-bold text-gray-900 dark:text-white"><?= (int) $rsvpHead ?></span>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">People</span>
                    <?php if ($rsvpRegistrants > 0 && $rsvpHead !== $rsvpRegistrants): ?>
                    <span class="mt-0.5 text-[9px] text-gray-400 dark:text-gray-500"><?= $rsvpRegistrants === 1 ? '1 registrant' : ((int) $rsvpRegistrants) . ' registrants' ?></span>
                    <?php endif; ?>
                </div>
                <div class="h-8 w-px bg-gray-200 dark:bg-gray-600"></div>
                <div class="flex flex-col">
                    <span class="text-2xl font-bold text-gray-900 dark:text-white"><?= (int)$checkinCount ?></span>
                    <span class="text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">Checked In</span>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($eventHeaderStatsHtml !== null): ?>
        <div class="flex flex-wrap gap-4"><?= $eventHeaderStatsHtml ?></div>
    <?php endif; ?>
    <?php if ($eventActions !== ''): ?>
        <div class="flex flex-wrap gap-3 flex-shrink-0"><?= $eventActions ?></div>
    <?php endif; ?>
</div>
