<?php
/**
 * Facility booking food-safety waiver block (member + guest).
 *
 * Expects Alpine state: form.title, form.date, waiver.{enabled, checkbox_label, full_text,
 * accepted, contact_person, phone, setup_location, setup_other, applicant_signature}
 *
 * @var array{enabled: bool, checkbox_label: string, full_text: string} $facilityWaiver
 * @var string $inputClass
 */
$facilityWaiver = $facilityWaiver ?? ['enabled' => false, 'checkbox_label' => '', 'full_text' => ''];
$inputClass = $inputClass ?? 'w-full border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 bg-white dark:bg-gray-800';
if (empty($facilityWaiver['enabled'])) {
    return;
}
?>
<div class="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-5 space-y-4" x-show="waiver.enabled">
    <div class="border-b border-gray-200 dark:border-gray-700 pb-3">
        <h2 class="text-center text-base sm:text-lg font-extrabold uppercase tracking-wide text-indigo-900 dark:text-indigo-200">Facility Booking &amp; Food Safety Responsibility Waiver</h2>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
        <div>
            <p class="font-semibold text-gray-800 dark:text-gray-200">Vendor / Event Name</p>
            <p class="mt-1 border-b border-gray-300 dark:border-gray-600 min-h-[1.5rem] text-gray-700 dark:text-gray-300" x-text="form.title || '—'"></p>
        </div>
        <div>
            <p class="font-semibold text-gray-800 dark:text-gray-200">Date of Event</p>
            <p class="mt-1 border-b border-gray-300 dark:border-gray-600 min-h-[1.5rem] text-gray-700 dark:text-gray-300" x-text="form.date || '—'"></p>
        </div>
        <div>
            <label class="block font-semibold text-gray-800 dark:text-gray-200 mb-1" for="waiver-contact-person">Contact Person *</label>
            <input id="waiver-contact-person" type="text" x-model="waiver.contact_person" maxlength="255" required class="<?= e($inputClass) ?>" autocomplete="name" @input="waiverContactDirty = true">
        </div>
        <div>
            <label class="block font-semibold text-gray-800 dark:text-gray-200 mb-1" for="waiver-phone">Phone Number *</label>
            <input id="waiver-phone" type="tel" x-model="waiver.phone" maxlength="50" required class="<?= e($inputClass) ?>" autocomplete="tel" @input="waiverPhoneDirty = true">
        </div>
    </div>

    <div>
        <p class="font-semibold text-gray-800 dark:text-gray-200 mb-2">Setup Location *</p>
        <div class="space-y-2 text-sm">
            <label class="flex items-center gap-2">
                <input type="radio" name="waiver_setup_location" value="indoor_foyer" x-model="waiver.setup_location" class="text-indigo-600">
                <span>Indoor Entrance / Foyer</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="radio" name="waiver_setup_location" value="outdoor_canopy" x-model="waiver.setup_location" class="text-indigo-600">
                <span>Outdoor Canopy / Entrance</span>
            </label>
            <label class="flex items-start gap-2">
                <input type="radio" name="waiver_setup_location" value="other" x-model="waiver.setup_location" class="mt-1 text-indigo-600">
                <span class="flex-1">
                    <span>Other Space:</span>
                    <input type="text" x-model="waiver.setup_other" maxlength="255" class="<?= e($inputClass) ?> mt-1" :disabled="waiver.setup_location !== 'other'" placeholder="Describe the space">
                </span>
            </label>
        </div>
    </div>

    <div>
        <p class="font-semibold text-gray-800 dark:text-gray-200 mb-2">Undertaking &amp; Release of Liability:</p>
        <div class="max-h-48 overflow-y-auto whitespace-pre-wrap text-xs sm:text-sm text-gray-700 dark:text-gray-300 leading-relaxed rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3" x-text="waiver.full_text"></div>
    </div>

    <label class="flex items-start gap-3 text-sm">
        <input type="checkbox" x-model="waiver.accepted" class="mt-0.5 w-4 h-4 text-indigo-600 rounded border-gray-300 shrink-0">
        <span class="text-gray-800 dark:text-gray-200" x-text="waiver.checkbox_label"></span>
    </label>

    <div>
        <label class="block font-semibold text-gray-800 dark:text-gray-200 mb-1" for="waiver-applicant-signature">Applicant Signature *</label>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Type your full legal name. The date is recorded when you submit.</p>
        <input id="waiver-applicant-signature" type="text" x-model="waiver.applicant_signature" maxlength="255" required class="<?= e($inputClass) ?>" placeholder="Type your full name" autocomplete="name" @input="waiverSignatureDirty = true">
    </div>
</div>
