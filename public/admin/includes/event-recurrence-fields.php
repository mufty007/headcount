<?php
/**
 * Recurring event fields for event-create / event-edit.
 *
 * Expected: $formData with keys is_recurring, recurrence_type, recurrence_interval, recurrence_days (int[]),
 * recurrence_week_of_month, recurrence_end_type, recurrence_end_after_count, recurrence_end_date, custom_session_dates_text.
 * Optional: $recurrence_input_class, $recurrence_label_class, $recurrence_section_class (wrapper card).
 * Optional: $formData['session_registration_mode'] — independent | choose_one | all_sessions (recurring series only).
 */
$iCls = $recurrence_input_class ?? 'w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-gray-900 focus:border-indigo-500 focus:outline-none dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100';
$lCls = $recurrence_label_class ?? 'mb-2 block font-medium text-gray-700 dark:text-slate-300';
$secCls = $recurrence_section_class ?? 'mb-4 rounded-lg border border-violet-100 bg-violet-50/60 p-4 dark:border-violet-900/40 dark:bg-violet-950/25';
$rt = $formData['recurrence_type'] ?? 'weekly';
$sessionReg = $formData['session_registration_mode'] ?? 'independent';
if (!in_array($sessionReg, ['independent', 'choose_one', 'all_sessions'], true)) {
    $sessionReg = 'independent';
}
$daysSel = isset($formData['recurrence_days']) && is_array($formData['recurrence_days'])
    ? array_map('intval', $formData['recurrence_days'])
    : [];
$dayLabels = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
?>
<div class="<?= e($secCls) ?>">
    <label class="flex items-start gap-3 cursor-pointer mb-3">
        <input type="checkbox" name="is_recurring" id="is_recurring" value="1" class="mt-1 h-4 w-4 rounded border-violet-300 text-violet-600 focus:ring-violet-500"
            <?= !empty($formData['is_recurring']) ? 'checked' : '' ?>>
        <span>
            <span class="font-semibold text-gray-900 dark:text-white">Recurring event</span>
            <span class="mt-0.5 block text-xs text-gray-600 dark:text-slate-400">Create additional sessions from this date using a schedule (same as the member app API).</span>
        </span>
    </label>

    <div id="recurrence-fields-wrap" class="space-y-4 <?= empty($formData['is_recurring']) ? 'hidden' : '' ?>">
        <div>
            <label class="<?= e($lCls) ?>" for="recurrence_type">Repeat</label>
            <select name="recurrence_type" id="recurrence_type" class="<?= e($iCls) ?> bg-white">
                <option value="daily" <?= $rt === 'daily' ? 'selected' : '' ?>>Daily</option>
                <option value="weekly" <?= $rt === 'weekly' ? 'selected' : '' ?>>Weekly (pick weekdays)</option>
                <option value="monthly" <?= $rt === 'monthly' ? 'selected' : '' ?>>Monthly (same calendar day)</option>
                <option value="monthly_weekday" <?= $rt === 'monthly_weekday' ? 'selected' : '' ?>>Monthly (e.g. first Friday)</option>
                <option value="yearly" <?= $rt === 'yearly' ? 'selected' : '' ?>>Yearly</option>
                <option value="custom" <?= $rt === 'custom' ? 'selected' : '' ?>>Specific dates</option>
            </select>
        </div>

        <div id="recurrence-interval-wrap" class="<?= $rt === 'custom' ? 'hidden' : '' ?>">
            <label class="<?= e($lCls) ?>" for="recurrence_interval">Every</label>
            <input type="number" name="recurrence_interval" id="recurrence_interval" min="1" max="99" class="<?= e($iCls) ?>"
                value="<?= e((string) max(1, (int) ($formData['recurrence_interval'] ?? 1))) ?>">
            <p class="text-xs text-gray-500 mt-1">Interval (e.g. 2 = every 2 weeks for Weekly). Not used for Specific dates.</p>
        </div>

        <div id="recurrence-weekdays-wrap" class="<?= in_array($rt, ['weekly', 'monthly_weekday'], true) ? '' : 'hidden' ?>">
            <span class="<?= e($lCls) ?>">Weekdays</span>
            <div class="flex flex-wrap gap-2">
                <?php foreach ($dayLabels as $dow => $label): ?>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm hover:border-violet-400 dark:border-slate-600 dark:bg-slate-800 dark:text-slate-200 dark:hover:border-violet-500">
                        <input type="checkbox" name="recurrence_days[]" value="<?= (int) $dow ?>"
                            <?= in_array($dow, $daysSel, true) ? 'checked' : '' ?>>
                        <?= e($label) ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500 mt-1">Sunday = 0. Required for Weekly; for “first Friday” pick one weekday.</p>
        </div>

        <div id="recurrence-week-of-month-wrap" class="<?= $rt === 'monthly_weekday' ? '' : 'hidden' ?>">
            <label class="<?= e($lCls) ?>" for="recurrence_week_of_month">Week in month</label>
            <select name="recurrence_week_of_month" id="recurrence_week_of_month" class="<?= e($iCls) ?> bg-white">
                <?php
                $wom = (string) ($formData['recurrence_week_of_month'] ?? '');
                $womOpts = ['1' => 'First', '2' => 'Second', '3' => 'Third', '4' => 'Fourth', '5' => 'Last'];
                foreach ($womOpts as $val => $lab) {
                    echo '<option value="' . e($val) . '"' . ($wom === $val ? ' selected' : '') . '>' . e($lab) . '</option>';
                }
                ?>
            </select>
        </div>

        <div id="recurrence-custom-wrap" class="<?= $rt === 'custom' ? '' : 'hidden' ?>">
            <label class="<?= e($lCls) ?>" for="custom_session_dates_text">Extra session dates</label>
            <textarea name="custom_session_dates_text" id="custom_session_dates_text" rows="4" class="<?= e($iCls) ?> font-mono text-sm"
                placeholder="YYYY-MM-DD one per line&#10;2026-05-01&#10;2026-06-15"><?= e($formData['custom_session_dates_text'] ?? '') ?></textarea>
            <p class="text-xs text-gray-500 mt-1">Main event date is the first session; list <strong>additional</strong> dates only. Use <strong>YYYY-MM-DD</strong> (for example June 14, 2026 is <code class="text-xs">2026-06-14</code>). The <strong>End date</strong> below does not add extra sessions when Repeat is Specific dates.</p>
        </div>

        <?php
        $endT = $formData['recurrence_end_type'] ?? 'never';
        ?>
        <div>
            <label class="<?= e($lCls) ?>" for="recurrence_end_type">Ends</label>
            <select name="recurrence_end_type" id="recurrence_end_type" class="<?= e($iCls) ?> bg-white">
                <option value="never" <?= $endT === 'never' ? 'selected' : '' ?>>Never (within system limits)</option>
                <option value="after_count" <?= $endT === 'after_count' ? 'selected' : '' ?>>After a number of sessions</option>
                <option value="on_date" <?= $endT === 'on_date' ? 'selected' : '' ?>>On a date</option>
            </select>
        </div>

        <div id="recurrence-end-count-wrap" class="<?= $endT === 'after_count' ? '' : 'hidden' ?>">
            <label class="<?= e($lCls) ?>" for="recurrence_end_after_count">Number of sessions</label>
            <input type="number" name="recurrence_end_after_count" id="recurrence_end_after_count" min="1" class="<?= e($iCls) ?>"
                value="<?= e((string) ($formData['recurrence_end_after_count'] ?? '')) ?>">
        </div>

        <div id="recurrence-end-date-wrap" class="<?= $endT === 'on_date' ? '' : 'hidden' ?>">
            <label class="<?= e($lCls) ?>" for="recurrence_end_date">End date</label>
            <input type="date" name="recurrence_end_date" id="recurrence_end_date" class="<?= e($iCls) ?>"
                value="<?= e($formData['recurrence_end_date'] ?? '') ?>">
        </div>

        <div>
            <label class="<?= e($lCls) ?>" for="session_registration_mode">Session registration</label>
            <select name="session_registration_mode" id="session_registration_mode" class="<?= e($iCls) ?> bg-white">
                <option value="independent" <?= $sessionReg === 'independent' ? 'selected' : '' ?>>Each session — members can RSVP to any sessions independently</option>
                <option value="choose_one" <?= $sessionReg === 'choose_one' ? 'selected' : '' ?>>Pick one session — only one session per person in this series</option>
                <option value="all_sessions" <?= $sessionReg === 'all_sessions' ? 'selected' : '' ?>>All sessions — one RSVP registers for every published session</option>
            </select>
            <p class="text-xs text-gray-500 mt-1">Applies to this recurring series (parent event and its generated dates).</p>
        </div>
    </div>
</div>
<script>
(function () {
    var cb = document.getElementById('is_recurring');
    var wrap = document.getElementById('recurrence-fields-wrap');
    var typeEl = document.getElementById('recurrence_type');
    var endEl = document.getElementById('recurrence_end_type');
    function toggleMain() {
        if (!wrap || !cb) return;
        wrap.classList.toggle('hidden', !cb.checked);
    }
    function toggleType() {
        var t = typeEl ? typeEl.value : '';
        var wk = document.getElementById('recurrence-weekdays-wrap');
        var wm = document.getElementById('recurrence-week-of-month-wrap');
        var cu = document.getElementById('recurrence-custom-wrap');
        var iv = document.getElementById('recurrence-interval-wrap');
        if (wk) wk.classList.toggle('hidden', t !== 'weekly' && t !== 'monthly_weekday');
        if (wm) wm.classList.toggle('hidden', t !== 'monthly_weekday');
        if (cu) cu.classList.toggle('hidden', t !== 'custom');
        if (iv) iv.classList.toggle('hidden', t === 'custom');
    }
    function toggleEnd() {
        var e = endEl ? endEl.value : '';
        var c = document.getElementById('recurrence-end-count-wrap');
        var d = document.getElementById('recurrence-end-date-wrap');
        if (c) c.classList.toggle('hidden', e !== 'after_count');
        if (d) d.classList.toggle('hidden', e !== 'on_date');
    }
    if (cb) cb.addEventListener('change', toggleMain);
    if (typeEl) typeEl.addEventListener('change', toggleType);
    if (endEl) endEl.addEventListener('change', toggleEnd);
    toggleMain();
    toggleType();
    toggleEnd();
})();
</script>
