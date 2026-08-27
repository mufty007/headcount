<?php
/** @var list<array<string,mixed>> $facilityOptions */
/** @var list<int> $selectedFacilityIds */
$selectedFacilityIds = isset($selectedFacilityIds) && is_array($selectedFacilityIds) ? $selectedFacilityIds : [];
$selectedMap = array_flip(array_map('intval', $selectedFacilityIds));
?>
<div class="mb-4">
    <span class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Link to facilities (optional)</span>
    <div class="space-y-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
        <?php foreach ($facilityOptions as $fac):
            $fid = (int) ($fac['id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            ?>
            <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-200">
                <input type="checkbox" name="facility_ids[]" value="<?= $fid ?>"
                       class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                       <?= isset($selectedMap[$fid]) ? 'checked' : '' ?>>
                <span><?= e($fac['name'] ?? 'Facility') ?></span>
            </label>
        <?php endforeach; ?>
        <?php if (empty($facilityOptions)): ?>
            <p class="text-sm text-gray-500 dark:text-gray-400">No active facilities yet.</p>
        <?php endif; ?>
    </div>
    <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">When this is published, each linked facility is blocked for the same date and time so members cannot book those rooms.</p>
</div>
