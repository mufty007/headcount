<?php
/** @var array $stats */
/** @var int $noShowCount */
/** @var float $noShowRate */
/** @var list<array<string, mixed>> $rsvpReportEvents */
?>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">RSVP Yes</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['total_rsvps'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Checked In</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['total_attendance'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">No-shows</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $noShowCount ?></p>
        <p class="text-xs text-gray-500 mt-0.5"><?= e((string) $noShowRate) ?>% of RSVP yes</p>
    </div>
</div>

<div class="mb-8 rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Checked-in vs no-shows</h2>
    <p class="mb-4 text-xs text-gray-500 dark:text-slate-400">By event (filtered)</p>
    <div id="rsvpStackedChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="RSVP stacked chart"></div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800">
    <div class="border-b border-gray-100 p-6 dark:border-slate-700"><h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">By event</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Event</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Primary Yes</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Addl Guests</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Total Headcount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Checked-in</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">No-shows</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($rsvpReportEvents)): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-slate-400">No events in this period</td></tr>
                <?php else: ?>
                    <?php foreach ($rsvpReportEvents as $ev): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4 font-medium text-gray-900 dark:text-white"><?= e((string) $ev['title']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= formatDate($ev['event_date']) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $ev['rsvp_yes'] ?></td>
                            <td class="px-6 py-4 text-sm text-right text-indigo-600">+<?= (int) $ev['additional_guests'] ?></td>
                            <td class="px-6 py-4 text-sm text-right font-bold"><?= (int) $ev['total_expected'] ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $ev['checked_in'] ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $ev['no_show_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
