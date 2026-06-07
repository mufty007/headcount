<?php
/** @var array $facilityStats */
/** @var list<array<string, mixed>> $facilityPerformanceList */
?>
<div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total bookings</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $facilityStats['total_bookings'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Pending</p>
        <p class="text-2xl font-black text-amber-700 mt-1"><?= (int) $facilityStats['pending'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Approved</p>
        <p class="text-2xl font-black text-emerald-700 mt-1"><?= (int) $facilityStats['approved'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Rejected</p>
        <p class="text-2xl font-black text-rose-700 mt-1"><?= (int) $facilityStats['rejected'] ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Cancelled</p>
        <p class="text-2xl font-black text-gray-600 mt-1"><?= (int) $facilityStats['cancelled'] ?></p>
    </div>
    <div class="rounded-2xl border border-green-400/20 bg-gradient-to-r from-green-500 to-green-600 p-5 text-white shadow-card">
        <p class="text-green-100 text-xs font-medium uppercase tracking-wider">Captured revenue</p>
        <p class="text-2xl font-bold mt-1">$<?= number_format((float) $facilityStats['revenue'], 2) ?></p>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800 mb-8">
    <div class="border-b border-gray-100 p-6 dark:border-slate-700">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">By facility</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500">Facility</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Bookings</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Approved</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Pending</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Hours</th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase text-gray-500">Revenue</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($facilityPerformanceList)): ?>
                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No facility booking data for this period.</td></tr>
                <?php else: ?>
                    <?php foreach ($facilityPerformanceList as $row): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4 font-medium"><?= e((string) $row['name']) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['booking_count'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['approved_count'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= (int) ($row['pending_count'] ?? 0) ?></td>
                            <td class="px-6 py-4 text-sm text-right"><?= number_format((float) ($row['hours_booked'] ?? 0), 1) ?></td>
                            <td class="px-6 py-4 text-sm text-right font-medium">$<?= number_format((float) ($row['revenue'] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($facilityPerformanceList)): ?>
<div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
    <h2 class="mb-2 text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Bookings by facility</h2>
    <div id="facilityBookingsChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="Facility bookings chart"></div>
</div>
<?php endif; ?>
