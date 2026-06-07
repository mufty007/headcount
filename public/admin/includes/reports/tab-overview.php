<?php
/** @var array $stats */
/** @var array|null $prevStats */
/** @var array $revenueStats */
/** @var int $noShowCount */
/** @var float $noShowRate */
/** @var list<array<string, mixed>> $topEvents */
/** @var list<array<string, mixed>> $topAttendees */
if (!function_exists('hc_report_delta')) {
    function hc_report_delta($current, $previous): ?array
    {
        if ($previous === null || (float) $previous == 0.0) {
            return null;
        }
        $diff = $current - $previous;
        $pct = round(((float) $diff / (float) $previous) * 100, 1);

        return ['diff' => $diff, 'pct' => $pct];
    }
}
?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800 flex items-center gap-4">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600 dark:bg-indigo-950/40 dark:text-indigo-300">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
        </div>
        <div>
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Total Events</p>
            <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['total_events'] ?></p>
            <?php if ($prevStats !== null): $d = hc_report_delta((int) $stats['total_events'], (int) $prevStats['total_events']); if ($d): ?>
            <p class="text-xs mt-0.5 <?= $d['diff'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $d['diff'] >= 0 ? '+' : '' ?><?= $d['diff'] ?> (<?= $d['pct'] ?>%) vs prev</p>
            <?php endif; endif; ?>
        </div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800 flex items-center gap-4">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-300">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
        </div>
        <div>
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Attendance</p>
            <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['total_attendance'] ?></p>
            <?php if ($prevStats !== null): $d = hc_report_delta((int) $stats['total_attendance'], (int) $prevStats['total_attendance']); if ($d): ?>
            <p class="text-xs mt-0.5 <?= $d['diff'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $d['diff'] >= 0 ? '+' : '' ?><?= $d['diff'] ?> (<?= $d['pct'] ?>%) vs prev</p>
            <?php endif; endif; ?>
        </div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800 flex items-center gap-4">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-50 text-purple-600 dark:bg-purple-950/40 dark:text-purple-300">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
        </div>
        <div>
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Unique Reach</p>
            <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['unique_attendees'] ?></p>
            <?php if ($prevStats !== null): $d = hc_report_delta((int) $stats['unique_attendees'], (int) $prevStats['unique_attendees']); if ($d): ?>
            <p class="text-xs mt-0.5 <?= $d['diff'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $d['diff'] >= 0 ? '+' : '' ?><?= $d['diff'] ?> (<?= $d['pct'] ?>%) vs prev</p>
            <?php endif; endif; ?>
        </div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800 flex items-center gap-4">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-300">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>
        </div>
        <div>
            <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Avg / Event</p>
            <p class="text-2xl font-black text-gray-900 mt-1"><?= e((string) $stats['avg_attendance']) ?></p>
            <?php if ($prevStats !== null): $d = hc_report_delta((float) $stats['avg_attendance'], (float) $prevStats['avg_attendance']); if ($d): ?>
            <p class="text-xs mt-0.5 <?= $d['diff'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $d['diff'] >= 0 ? '+' : '' ?><?= $d['diff'] ?> (<?= $d['pct'] ?>%) vs prev</p>
            <?php endif; endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">RSVPs (Yes)</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['total_rsvps'] ?></p>
        <?php if ($prevStats !== null): $d = hc_report_delta((int) $stats['total_rsvps'], (int) $prevStats['total_rsvps']); if ($d): ?>
        <p class="text-xs mt-0.5 <?= $d['diff'] >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>"><?= $d['diff'] >= 0 ? '+' : '' ?><?= $d['diff'] ?> vs prev</p>
        <?php endif; endif; ?>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Total Members</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $stats['total_members'] ?></p>
        <p class="text-xs text-gray-500 mt-0.5">Active in organization</p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">No-shows</p>
        <p class="text-2xl font-black text-gray-900 mt-1"><?= (int) $noShowCount ?></p>
        <p class="text-xs text-gray-500 mt-0.5"><?= e((string) $noShowRate) ?>% of RSVP yes</p>
    </div>
</div>

<?php if (($revenueStats['total_revenue'] ?? 0) > 0): ?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
    <div class="rounded-2xl border border-green-400/20 bg-gradient-to-r from-green-500 to-green-600 p-6 text-white shadow-card">
        <p class="text-green-100 text-sm font-medium">Total Revenue</p>
        <p class="text-4xl font-bold mt-2">$<?= number_format((float) $revenueStats['total_revenue'], 2) ?></p>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <p class="text-[10px] font-black text-gray-400 uppercase tracking-widest">Paid registrations</p>
        <p class="text-3xl font-black text-gray-900 mt-2"><?= (int) $revenueStats['paid_count'] ?></p>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-black text-gray-900 uppercase tracking-widest mb-6">Attendance Trend</h2>
        <div id="attendanceTrendChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="Daily attendance trend"></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-black text-gray-900 uppercase tracking-widest mb-6">By Category</h2>
        <div id="categoryChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="Attendance by category"></div>
    </div>
</div>

<div class="grid grid-cols-1 gap-8 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-black text-gray-900 uppercase tracking-widest mb-2">RSVP vs Attendance</h2>
        <p class="text-xs text-gray-500 mb-6">By event date</p>
        <div id="rsvpVsAttendanceChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="RSVP vs check-ins"></div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-black text-gray-900 uppercase tracking-widest mb-2">New vs Returning</h2>
        <div id="newVsReturningChart" class="reports-apex-chart w-full min-h-[320px]" role="img" aria-label="New versus returning"></div>
    </div>
    <div class="rounded-2xl border border-gray-200 bg-white p-8 shadow-card dark:border-slate-700 dark:bg-slate-800">
        <h2 class="text-sm font-black text-gray-900 uppercase tracking-widest mb-6">Top Events</h2>
        <div class="space-y-3">
            <?php if (empty($topEvents)): ?>
                <p class="text-gray-500 text-center py-4">No events in this period</p>
            <?php else: ?>
                <?php foreach (array_slice($topEvents, 0, 10) as $event): ?>
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="font-medium text-gray-800"><?= e($event['title']) ?></div>
                            <div class="text-xs text-gray-500"><?= formatDate($event['event_date']) ?></div>
                        </div>
                        <div class="font-bold text-gray-800"><?= (int) $event['attendance_count'] ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card dark:border-slate-700 dark:bg-slate-800">
    <div class="border-b border-gray-100 p-6 dark:border-slate-700">
        <h2 class="text-sm font-black uppercase tracking-widest text-gray-900 dark:text-white">Top Attendees</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-700 dark:bg-slate-900/60">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">#</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase text-gray-500 dark:text-slate-400">Events</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (empty($topAttendees)): ?>
                    <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500 dark:text-slate-400">No attendance data</td></tr>
                <?php else: ?>
                    <?php foreach ($topAttendees as $index => $attendee): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40">
                            <td class="px-6 py-4"><?= $index + 1 ?></td>
                            <td class="px-6 py-4 font-medium"><?= e(trim(($attendee['first_name'] ?? '') . ' ' . ($attendee['last_name'] ?? ''))) ?></td>
                            <td class="px-6 py-4 text-sm text-gray-600"><?= e((string) ($attendee['email'] ?? '')) ?></td>
                            <td class="px-6 py-4 font-bold text-indigo-600"><?= (int) $attendee['attendance_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
