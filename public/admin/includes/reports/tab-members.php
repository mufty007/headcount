<?php /** @var list<array<string, mixed>> $memberEngagementList */ ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Attendance rate distribution</h2>
        <div id="membersRateHistogram" class="reports-apex-chart w-full min-h-[300px]" role="img" aria-label="Histogram of attendance rate"></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Top engaged members</h2>
        <div id="membersTopBarChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="Top members by events attended"></div>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800">
    <div class="flex flex-wrap justify-between gap-4 border-b border-gray-100 p-6 dark:border-slate-700">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Member engagement</h2>
        <span class="text-xs text-gray-500 dark:text-slate-400">Max 200 with activity</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Email</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Attended</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">RSVP'd</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">No-shows</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Rate %</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Last attended</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($memberEngagementList)): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500 dark:text-slate-400">No member activity</td></tr>
                <?php else: ?>
                    <?php foreach ($memberEngagementList as $m): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4 font-medium"><?= e(trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''))) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= e((string) ($m['email'] ?? '—')) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $m['events_attended'] ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $m['events_rsvpd'] ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) $m['no_shows'] ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= e((string) $m['attendance_rate']) ?>%</td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= !empty($m['last_attended']) ? formatDate($m['last_attended']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
