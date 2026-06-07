<?php
/** @var \Headcount\Services\ReportFilterSet $filters */
/** @var list<array{id: int, title: string, event_date: string}> $eventPickerList */
/** @var string $reportsBaseUrl */
/** @var string $reportType */

$allowedCategories = \Headcount\Services\ReportFilterSet::loadAllowedCategories($db, $organizationId);
$reportFacilities = \Headcount\Services\ReportFilterSet::loadFacilities($db, $organizationId);
$reportPrograms = \Headcount\Services\ReportFilterSet::loadPrograms($db, $organizationId);
$reportProgramCategories = \Headcount\Services\ReportFilterSet::loadProgramCategories($db, $organizationId);
$filterOpen = !empty($filters->categories) || $filters->eventId !== null || $filters->searchQuery !== ''
    || $filters->minRsvpYes !== null || $filters->minNoShowPct !== null
    || ($filters->revenueStatus !== 'paid' && in_array($reportType, ['revenue', 'facilities', 'programs'], true))
    || $filters->facilityId !== null || $filters->programId !== null || $filters->programCategoryId !== null;
?>
<div class="admin-filter-card mb-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-card dark:border-gray-700 dark:bg-gray-800 sm:p-6" x-data="{ open: <?= $filterOpen ? 'true' : 'false' ?> }">
    <button type="button" @click="open = !open" class="flex w-full items-center justify-between rounded-lg text-left transition-colors hover:bg-gray-50/80 dark:hover:bg-gray-700/50">
        <span class="text-sm font-semibold text-gray-900 dark:text-white">Filters</span>
        <span class="text-xs text-gray-500 dark:text-gray-400" x-text="open ? 'Hide' : 'Show'"></span>
    </button>
    <div x-show="open" class="mt-4 space-y-4">
        <form method="GET" action="<?= e($reportsBaseUrl) ?>" class="space-y-4">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="report" value="<?= e($reportType) ?>">
            <input type="hidden" name="start_date" value="<?= e($filters->startDate) ?>">
            <input type="hidden" name="end_date" value="<?= e($filters->endDate) ?>">
            <?php if ($filters->compare): ?><input type="hidden" name="compare" value="1"><?php endif; ?>

            <?php if ($allowedCategories !== []): ?>
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Event categories</label>
                <div class="flex flex-wrap gap-3">
                    <?php foreach ($allowedCategories as $cat): ?>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="categories[]" value="<?= e($cat) ?>" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-500" <?= in_array($cat, $filters->categories, true) ? 'checked' : '' ?>>
                            <?= e($cat) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Focus event</label>
                    <select name="event_id" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All events</option>
                        <?php foreach ($eventPickerList as $ev): ?>
                            <option value="<?= (int) $ev['id'] ?>" <?= $filters->eventId === (int) $ev['id'] ? 'selected' : '' ?>><?= e($ev['title']) ?> — <?= e($ev['event_date']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Search title</label>
                    <input type="search" name="q" value="<?= e($filters->searchQuery) ?>" placeholder="Contains…" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
            </div>

            <?php if (in_array($reportType, ['facilities', 'programs', 'overview'], true) && $reportFacilities !== []): ?>
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Facility</label>
                <select name="facility_id" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All facilities</option>
                    <?php foreach ($reportFacilities as $fac): ?>
                        <option value="<?= (int) $fac['id'] ?>" <?= $filters->facilityId === (int) $fac['id'] ? 'selected' : '' ?>><?= e($fac['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (in_array($reportType, ['programs', 'overview'], true)): ?>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php if ($reportPrograms !== []): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Program</label>
                    <select name="program_id" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All programs</option>
                        <?php foreach ($reportPrograms as $prog): ?>
                            <option value="<?= (int) $prog['id'] ?>" <?= $filters->programId === (int) $prog['id'] ? 'selected' : '' ?>><?= e($prog['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($reportProgramCategories !== []): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Program category</label>
                    <select name="program_category_id" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All categories</option>
                        <?php foreach ($reportProgramCategories as $pc): ?>
                            <option value="<?= (int) $pc['id'] ?>" <?= $filters->programCategoryId === (int) $pc['id'] ? 'selected' : '' ?>><?= e($pc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php if (in_array($reportType, ['events', 'rsvp'], true)): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Min RSVP yes (tables)</label>
                    <input type="number" name="min_rsvp_yes" min="0" value="<?= $filters->minRsvpYes !== null ? (int) $filters->minRsvpYes : '' ?>" placeholder="Any" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Min no-show % (tables)</label>
                    <input type="number" name="min_no_show_pct" min="0" max="100" step="0.1" value="<?= $filters->minNoShowPct !== null ? e((string) $filters->minNoShowPct) : '' ?>" placeholder="Any" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                </div>
                <?php endif; ?>
                <?php if (in_array($reportType, ['revenue', 'facilities', 'programs'], true)): ?>
                <div>
                    <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Revenue scope</label>
                    <select name="revenue_status" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                        <option value="paid" <?= $filters->revenueStatus === 'paid' ? 'selected' : '' ?>>Paid only</option>
                        <option value="all" <?= $filters->revenueStatus === 'all' ? 'selected' : '' ?>>Paid + pending</option>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                <input type="checkbox" name="compare" value="1" class="rounded border-gray-300 text-brand-600 focus:ring-brand-500 dark:border-gray-500" <?= $filters->compare ? 'checked' : '' ?>>
                Compare KPIs to previous period (same length)
            </label>

            <div class="flex flex-wrap gap-2">
                <button type="submit" class="btn-primary">Apply filters</button>
                <a href="<?= e(hc_reports_url($reportsBaseUrl, ['page' => 'reports', 'report' => $reportType, 'start_date' => $filters->startDate, 'end_date' => $filters->endDate])) ?>" class="btn-secondary">Clear filters</a>
            </div>
        </form>
    </div>
</div>

<?php
$activeChips = [];
foreach ($filters->categories as $c) {
    $activeChips[] = 'Category: ' . $c;
}
if ($filters->eventId !== null) {
    $activeChips[] = 'Single event';
}
if ($filters->searchQuery !== '') {
    $activeChips[] = 'Title: “' . $filters->searchQuery . '”';
}
if ($filters->minRsvpYes !== null && in_array($reportType, ['events', 'rsvp'], true)) {
    $activeChips[] = 'Min RSVP: ' . $filters->minRsvpYes;
}
if ($filters->minNoShowPct !== null && in_array($reportType, ['events', 'rsvp'], true)) {
    $activeChips[] = 'Min no-show %: ' . $filters->minNoShowPct;
}
if ($filters->revenueStatus !== 'paid' && in_array($reportType, ['revenue', 'facilities', 'programs'], true)) {
    $activeChips[] = 'Revenue: paid + pending';
}
if ($filters->facilityId !== null) {
    $activeChips[] = 'Facility filter';
}
if ($filters->programId !== null) {
    $activeChips[] = 'Program filter';
}
if ($filters->programCategoryId !== null) {
    $activeChips[] = 'Program category filter';
}
if ($filters->compare) {
    $activeChips[] = 'Compare on';
}
?>
<?php if ($activeChips !== []): ?>
<div class="flex flex-wrap gap-2 mb-6 items-center">
    <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Active:</span>
    <?php foreach ($activeChips as $chip): ?>
        <span class="rounded-full border border-brand-100 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-800 dark:border-brand-800/50 dark:bg-brand-950/40 dark:text-brand-200"><?= e($chip) ?></span>
    <?php endforeach; ?>
</div>
<?php endif; ?>
