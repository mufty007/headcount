<?php /** @var list<array<string, mixed>> $eventPerformanceList */ ?>
<div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Top check-ins</h2>
        <p class="mb-4 text-xs text-gray-500 dark:text-slate-400">Up to 15 events by filtered set</p>
        <div id="eventsPerformanceBarChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="Events by check-ins"></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">No-show rate</h2>
        <p class="mb-4 text-xs text-gray-500 dark:text-slate-400">Events with RSVP yes</p>
        <div id="eventsNoShowColumnChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="No-show percent by event"></div>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800">
    <div class="flex flex-wrap justify-between gap-4 border-b border-gray-100 p-6 dark:border-slate-700">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Event performance</h2>
        <span class="text-xs text-gray-500 dark:text-slate-400"><?= count($eventPerformanceList) ?> events</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Event</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Category</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Capacity</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Primary Yes</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Total Headcount</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Checked In</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">No-show %</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Util %</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($eventPerformanceList)): ?>
                    <tr><td colspan="9" class="px-6 py-8 text-center text-gray-500 dark:text-slate-400">No events in this period</td></tr>
                <?php else: ?>
                    <?php foreach ($eventPerformanceList as $ev): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4 font-medium text-gray-900 dark:text-white"><?= e((string) $ev['title']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= formatDate($ev['event_date']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-500"><?= e((string) ($ev['category'] ?? '—')) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= $ev['capacity'] !== null ? (int) $ev['capacity'] : '—' ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $ev['rsvp_yes'] ?></td>
                            <td class="px-6 py-4 text-sm text-right text-indigo-700 font-bold"><?= (int) $ev['total_expected'] ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $ev['checked_in'] ?></td>
                            <td class="px-6 py-4 text-sm text-right <?= ($ev['no_show_pct'] ?? 0) > 20 ? 'text-rose-600 font-bold' : '' ?>"><?= e((string) $ev['no_show_pct']) ?>%</td>
                            <td class="px-6 py-4 text-sm text-right"><?= $ev['utilization_pct'] !== null ? e((string) $ev['utilization_pct']) . '%' : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
