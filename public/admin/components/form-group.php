<?php
/**
 * Form group — labeled input with error state (TailAdmin)
 *
 * $fieldId, $fieldLabel, $fieldType (text|email|password|textarea|select),
 * $fieldName, $fieldValue, $fieldPlaceholder, $fieldError, $fieldRequired (bool),
 * $fieldOptions (array for select), $fieldHelp (string)
 */
if (!isset($fieldId) || !isset($fieldLabel)) {
    return;
}

$fieldType = $fieldType ?? 'text';
$fieldName = $fieldName ?? $fieldId;
$fieldValue = $fieldValue ?? '';
$fieldPlaceholder = $fieldPlaceholder ?? '';
$fieldError = $fieldError ?? '';
$fieldRequired = !empty($fieldRequired);
$fieldHelp = $fieldHelp ?? '';
$inputClass = 'form-input w-full rounded-lg border px-3 py-2 text-sm shadow-sm transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/30 dark:bg-gray-800 dark:text-white ';
$inputClass .= $fieldError !== ''
    ? 'border-error-500 focus:border-error-500'
    : 'border-gray-300 dark:border-gray-600 focus:border-brand-500';
?>
<div class="form-group mb-4">
    <label for="<?= e($fieldId) ?>" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
        <?= e($fieldLabel) ?><?= $fieldRequired ? ' <span class="text-error-500">*</span>' : '' ?>
    </label>
    <?php if ($fieldType === 'textarea'): ?>
        <textarea id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" class="<?= e($inputClass) ?>" rows="4" placeholder="<?= e($fieldPlaceholder) ?>"<?= $fieldRequired ? ' required' : '' ?>><?= e((string) $fieldValue) ?></textarea>
    <?php elseif ($fieldType === 'select'): ?>
        <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" class="<?= e($inputClass) ?>"<?= $fieldRequired ? ' required' : '' ?>>
            <?php foreach (($fieldOptions ?? []) as $optVal => $optLabel): ?>
                <option value="<?= e((string) $optVal) ?>"<?= (string) $fieldValue === (string) $optVal ? ' selected' : '' ?>><?= e((string) $optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="<?= e($fieldType) ?>" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" value="<?= e((string) $fieldValue) ?>" class="<?= e($inputClass) ?>" placeholder="<?= e($fieldPlaceholder) ?>"<?= $fieldRequired ? ' required' : '' ?>>
    <?php endif; ?>
    <?php if ($fieldHelp !== ''): ?>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= e($fieldHelp) ?></p>
    <?php endif; ?>
    <?php if ($fieldError !== ''): ?>
        <p class="mt-1 text-xs text-error-600 dark:text-error-400"><?= e($fieldError) ?></p>
    <?php endif; ?>
</div>
