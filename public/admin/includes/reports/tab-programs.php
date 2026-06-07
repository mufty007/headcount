<?php
/** @var array $programStats */
/** @var list<array<string, mixed>> $programPerformanceList */
?>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Published programs</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $programStats['active_programs'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Registrations</p>
        <p class="text-2xl font-black text-indigo-700 mt-1"><?= (int) $programStats['registrations'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Sessions held</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $programStats['sessions_held'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Attendance records</p>
        <p class="text-2xl font-black text-emerald-700 mt-1"><?= (int) $programStats['attendance_records'] ?></p>
    </div>
    <div class="rounded-2xl border border-green-400/20 bg-gradient-to-r from-green-500 to-green-600 p-5 text-white shadow-card">
        <p class="text-green-100 text-xs font-medium uppercase tracking-wider">Registration revenue</p>
        <p class="text-2xl font-bold mt-1">$<?= number_format((float) $programStats['revenue'], 2) ?></p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800">
    <div class="border-b border-gray-100 p-6 dark:border-slate-700">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">By program</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Program</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Registrations</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Active</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Sessions</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Attendance</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Rate %</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($programPerformanceList)): ?>
                    <tr><td colspan="7" class="px-6 py-8 text-center text-gray-500">No program data for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($programPerformanceList as $row): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4 font-medium"><?= e((string) $row['title']) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['registrations'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['active_registrations'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['sessions_held'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['attendance_records'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= number_format((float) ($row['attendance_rate'] ?? 0), 1) ?>%</td>
                            <td class="px-6 py-4 text-sm text-right font-medium">$<?= number_format((float) ($row['revenue'] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
