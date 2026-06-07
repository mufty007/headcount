<?php
/** @var list<array{id: string, severity: string, title: string, body: string, metric?: string}> $insights */
$severityRing = [
    'danger' => 'border-rose-200 bg-rose-50/80 dark:border-rose-900/50 dark:bg-rose-950/35',
    'warning' => 'border-amber-200 bg-amber-50/80 dark:border-amber-900/40 dark:bg-amber-950/30',
    'success' => 'border-emerald-200 bg-emerald-50/80 dark:border-emerald-900/40 dark:bg-emerald-950/30',
    'info' => 'border-slate-200 bg-slate-50/80 dark:border-slate-600 dark:bg-slate-800/80',
];
?>
<?php if (!empty($insights)): ?>
<div class="mb-8">
    <h3 class="mb-3 text-[10px] font-black uppercase tracking-widest text-gray-400 dark:text-slate-500">Insights</h3>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
        <?php foreach ($insights as $ins): ?>
            <?php $ring = $severityRing[$ins['severity']] ?? $severityRing['info']; ?>
            <div class="rounded-2xl border p-4 shadow-card dark:shadow-none <?= e($ring) ?>">
                <p class="text-xs font-bold text-gray-900 dark:text-white"><?= e($ins['title']) ?></p>
                <p class="mt-1 text-sm leading-snug text-gray-600 dark:text-slate-300"><?= e($ins['body']) ?></p>
                <?php if (!empty($ins['metric'])): ?>
                    <p class="mt-2 text-xs font-semibold text-indigo-700 dark:text-indigo-300"><?= e((string) $ins['metric']) ?></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
