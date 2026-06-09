<?php
/**
 * Portal form field — label + input/select/textarea with brand focus + dark mode.
 * Expects (all optional except label + name):
 *   $fieldLabel       (string)  visible label
 *   $fieldName        (string)  name attribute (also used as id when $fieldId unset)
 *   $fieldId          (string)  id attribute
 *   $fieldType        (string)  text|email|password|number|tel|date|textarea|select  (default text)
 *   $fieldValue       (string)  current value (textarea uses inner content)
 *   $fieldPlaceholder (string)
 *   $fieldRequired    (bool)
 *   $fieldHint        (string)  helper text under the field
 *   $fieldRows        (int)     textarea rows (default 4)
 *   $fieldOptions     (array)   for select: [value => label]
 *   $fieldSelected    (string)  for select: selected value
 *   $fieldAttrs       (string)  extra raw HTML attributes (e.g. x-model, autocomplete)
 * Reset all of these to defaults after include if reusing in a loop.
 */
if (!isset($fieldLabel, $fieldName)) { return; }
$fieldId          = $fieldId          ?? $fieldName;
$fieldType        = $fieldType        ?? 'text';
$fieldValue       = $fieldValue       ?? '';
$fieldPlaceholder = $fieldPlaceholder ?? '';
$fieldRequired    = $fieldRequired    ?? false;
$fieldHint        = $fieldHint        ?? '';
$fieldRows        = $fieldRows        ?? 4;
$fieldOptions     = $fieldOptions     ?? [];
$fieldSelected    = $fieldSelected    ?? '';
$fieldAttrs       = $fieldAttrs       ?? '';

$controlClass = 'w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500';
$req = $fieldRequired ? ' required' : '';
?>
<div class="mb-4">
    <label for="<?= e($fieldId) ?>" class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
        <?= e($fieldLabel) ?><?php if ($fieldRequired): ?><span class="text-rose-500"> *</span><?php endif; ?>
    </label>
    <?php if ($fieldType === 'textarea'): ?>
        <textarea id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" rows="<?= (int)$fieldRows ?>"
            placeholder="<?= e($fieldPlaceholder) ?>" class="<?= $controlClass ?>"<?= $req ?> <?= $fieldAttrs ?>><?= e($fieldValue) ?></textarea>
    <?php elseif ($fieldType === 'select'): ?>
        <select id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>" class="<?= $controlClass ?>"<?= $req ?> <?= $fieldAttrs ?>>
            <?php foreach ($fieldOptions as $optVal => $optLabel): ?>
                <option value="<?= e($optVal) ?>"<?= ((string)$optVal === (string)$fieldSelected) ? ' selected' : '' ?>><?= e($optLabel) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="<?= e($fieldType) ?>" id="<?= e($fieldId) ?>" name="<?= e($fieldName) ?>"
            value="<?= e($fieldValue) ?>" placeholder="<?= e($fieldPlaceholder) ?>"
            class="<?= $controlClass ?>"<?= $req ?> <?= $fieldAttrs ?>>
    <?php endif; ?>
    <?php if ($fieldHint !== ''): ?>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= e($fieldHint) ?></p>
    <?php endif; ?>
</div>
