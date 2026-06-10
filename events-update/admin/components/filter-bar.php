<?php
/**
 * Filter bar — TailAdmin input/select pattern
 * Expects: $filterBarAction (form action URL, optional),
 *          $filterBarMethod (string, optional — 'get' or 'post', default 'get'),
 *          $filterBarFields (array of ['name'=>'q', 'type'=>'search'|'select'|'text'|'date',
 *                           'label'=>'Search', 'value'=>'', 'placeholder'=>'', 'options'=>[], 'width'=>'w-48']),
 *          $filterBarSubmitLabel (string, optional, default 'Filter'),
 *          $filterBarHiddenFields (array of ['name'=>'page', 'value'=>'events'] for passthrough)
 */
if (!isset($filterBarFields) || !is_array($filterBarFields)) { return; }
$filterBarAction       = $filterBarAction       ?? '';
$filterBarMethod       = $filterBarMethod       ?? 'get';
$filterBarSubmitLabel  = $filterBarSubmitLabel  ?? 'Filter';
$filterBarHiddenFields = $filterBarHiddenFields ?? [];
?>
<div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
    <form method="<?= e($filterBarMethod) ?>" action="<?= e($filterBarAction) ?>"
          class="flex flex-wrap items-end gap-3 p-4 sm:p-5">

        <?php foreach ($filterBarHiddenFields as $hf): ?>
            <input type="hidden" name="<?= e($hf['name']) ?>" value="<?= e($hf['value']) ?>">
        <?php endforeach; ?>

        <?php foreach ($filterBarFields as $field):
            $name        = $field['name']        ?? '';
            $type        = $field['type']        ?? 'text';
            $label       = $field['label']       ?? '';
            $value       = $field['value']       ?? '';
            $placeholder = $field['placeholder'] ?? '';
            $options     = $field['options']     ?? [];
            $width       = $field['width']       ?? 'w-48';
            if ($name === '') continue;
            $id = 'filter-' . preg_replace('/[^a-z0-9]/i', '-', $name);
        ?>
            <div class="flex flex-col gap-1.5">
                <?php if ($label !== ''): ?>
                    <label for="<?= e($id) ?>" class="ta-label"><?= e($label) ?></label>
                <?php endif; ?>

                <?php if ($type === 'select'): ?>
                    <select name="<?= e($name) ?>" id="<?= e($id) ?>"
                            class="ta-select <?= $width ?> py-2 px-3">
                        <?php foreach ($options as $optValue => $optLabel): ?>
                            <option value="<?= e($optValue) ?>" <?= (string)$optValue === (string)$value ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($type === 'search'): ?>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                            <svg class="h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </span>
                        <input type="search" name="<?= e($name) ?>" id="<?= e($id) ?>"
                               value="<?= e($value) ?>" placeholder="<?= e($placeholder) ?>"
                               class="ta-input pl-9 <?= $width ?> py-2">
                    </div>

                <?php else: ?>
                    <input type="<?= e($type) ?>" name="<?= e($name) ?>" id="<?= e($id) ?>"
                           value="<?= e($value) ?>" placeholder="<?= e($placeholder) ?>"
                           class="ta-input <?= $width ?> py-2">
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary py-2 px-4">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"></path>
                </svg>
                <?= e($filterBarSubmitLabel) ?>
            </button>
            <a href="<?= e($filterBarAction) ?>" class="btn-secondary py-2 px-4">Reset</a>
        </div>
    </form>
</div>
