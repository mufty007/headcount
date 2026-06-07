<?php
/** @var array $revenueStats */
/** @var list<array<string, mixed>> $revenueByEventList */
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <div class="rounded-2xl border border-green-400/20 bg-gradient-to-r from-green-500 to-green-600 p-6 text-white shadow-card">
        <p class="text-green-100 text-sm font-medium">Total revenue (period)</p>
        <p class="text-4xl font-bold mt-2">$<?= number_format((float) $revenueStats['total_revenue'], 2) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Paid registrations</p>
        <p class="text-3xl font-black text-gray-900 mt-2"><?= (int) $revenueStats['paid_count'] ?></p>
    </div>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Revenue by event</h2>
        <div id="revenueByEventChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="Revenue by event"></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Monthly trend</h2>
        <div id="revenueMonthlyChart" class="reports-apex-chart w-full min-h-[280px]" role="img" aria-label="Monthly revenue"></div>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800">
    <div class="border-b border-gray-100 p-6 dark:border-slate-700"><h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Revenue by event</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Event</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Date</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Revenue</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Paid count</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($revenueByEventList)): ?>
                    <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-slate-400">No revenue data</td></tr>
                <?php else: ?>
                    <?php foreach ($revenueByEventList as $ev): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4 font-medium"><?= e((string) $ev['title']) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= formatDate($ev['event_date']) ?></td>
                            <td class="px-6 py-4 text-sm text-right font-medium">$<?= number_format((float) $ev['revenue'], 2) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $ev['paid_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
