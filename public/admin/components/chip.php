<?php
/**
 * Tag / badge chip
 * Expects: $chipLabel (string), $chipVariant (string: default|indigo|emerald|amber|rose|gray)
 * Or render multiple: pass $chips as array of ['label'=>'x', 'variant'=>'indigo']
 */
$variants = [
    'default' => 'bg-gray-100 text-gray-700 dark:bg-slate-700 dark:text-slate-200',
    'indigo' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300',
    'emerald' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    'amber' => 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300',
    'rose' => 'bg-rose-100 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300',
    'gray' => 'bg-gray-200 text-gray-600 dark:bg-slate-600 dark:text-slate-200',
];
if (isset($chips) && is_array($chips)) {
    foreach ($chips as $c):
        $label = $c['label'] ?? '';
        $variant = $c['variant'] ?? 'default';
        $class = $variants[$variant] ?? $variants['default'];
        if ($label === '') continue;
?>
        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $class ?>"><?= e($label) ?></span>
    <?php endforeach;
} elseif (isset($chipLabel)) {
    $variant = $chipVariant ?? 'default';
    $class = $variants[$variant] ?? $variants['default'];
?>
    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?= $class ?>"><?= e($chipLabel) ?></span>
<?php
}
