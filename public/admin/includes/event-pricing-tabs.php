<?php
/**
 * Ticket types + group/tier pricing (tabbed) for event-create / event-edit.
 *
 * Expected: $formData, $ticketTypesRowsForTemplate, $hasPersistedNamedTicketTypesFromDb
 * Optional: $headcountTiersInitial (array) for edit / validation repost
 */
use Headcount\Services\EventHeadcountPricingService;
use Headcount\Helpers\EventTicketTypesPersistence;

$headcountPricingTiersJsonValue = '[]';
if (isset($headcountTiersInitial) && is_array($headcountTiersInitial)) {
    $headcountPricingTiersJsonValue = headcount_json_for_script($headcountTiersInitial);
}

$eventPricingActiveTab = 'group-tier';
if (!empty($hasPersistedNamedTicketTypesFromDb)) {
    $eventPricingActiveTab = 'ticket-types';
} else {
    foreach ($ticketTypesRowsForTemplate as $_pricingTabTt) {
        if (trim((string) ($_pricingTabTt['name'] ?? '')) !== '') {
            $eventPricingActiveTab = 'ticket-types';
            break;
        }
    }
}

$pricingTabBtnBase = 'pricing-tab-trigger flex-1 px-4 py-3 text-sm font-semibold border-b-2 transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/40 focus-visible:ring-inset';
$pricingTabBtnActive = 'border-indigo-600 text-indigo-700 bg-white';
$pricingTabBtnInactive = 'border-transparent text-gray-500 hover:text-gray-700 hover:bg-gray-50/80';
?>
<div id="event-pricing-tabs" class="mb-6 border border-gray-200 rounded-xl overflow-hidden bg-white shadow-sm" data-active-tab="<?= e($eventPricingActiveTab) ?>">
    <div class="flex border-b border-gray-200 bg-gray-50/90" role="tablist" aria-label="Pricing options">
        <button
            type="button"
            role="tab"
            id="pricing-tab-trigger-group-tier"
            aria-selected="<?= $eventPricingActiveTab === 'group-tier' ? 'true' : 'false' ?>"
            aria-controls="pricing-tab-panel-group-tier"
            data-pricing-tab="group-tier"
            class="<?= e($pricingTabBtnBase) ?> <?= $eventPricingActiveTab === 'group-tier' ? $pricingTabBtnActive : $pricingTabBtnInactive ?>"
        >Group/Tier</button>
        <button
            type="button"
            role="tab"
            id="pricing-tab-trigger-ticket-types"
            aria-selected="<?= $eventPricingActiveTab === 'ticket-types' ? 'true' : 'false' ?>"
            aria-controls="pricing-tab-panel-ticket-types"
            data-pricing-tab="ticket-types"
            class="<?= e($pricingTabBtnBase) ?> <?= $eventPricingActiveTab === 'ticket-types' ? $pricingTabBtnActive : $pricingTabBtnInactive ?>"
        >Ticket Types</button>
    </div>

    <div
        id="pricing-tab-panel-group-tier"
        role="tabpanel"
        aria-labelledby="pricing-tab-trigger-group-tier"
        class="pricing-tab-panel p-4 sm:p-5<?= $eventPricingActiveTab === 'group-tier' ? '' : ' hidden' ?>"
    >
        <p class="text-xs text-gray-600 mb-3">Flat package price by total headcount (registrant + guests). Tiers start at 1, must not overlap, and connect without gaps. Leave max blank on the last tier for &ldquo;and up.&rdquo; <strong>Not used</strong> when named ticket types are present.</p>
        <div class="flex flex-wrap gap-4 mb-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="pricing_model" value="<?= e(EventHeadcountPricingService::MODEL_PER_PERSON) ?>" <?= $formData['pricing_model'] === EventHeadcountPricingService::MODEL_PER_PERSON ? 'checked' : '' ?> class="headcount-pricing-model-radio">
                <span class="text-sm font-medium text-gray-800">Per person</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="radio" name="pricing_model" value="<?= e(EventHeadcountPricingService::MODEL_HEADCOUNT_TIER) ?>" <?= $formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER ? 'checked' : '' ?> class="headcount-pricing-model-radio" <?= $hasPersistedNamedTicketTypesFromDb ? 'disabled' : '' ?>>
                <span class="text-sm font-medium text-gray-800">Tiered packages</span>
            </label>
        </div>
        <div id="headcount-tier-editor-wrap" class="space-y-2" style="display: <?= $formData['pricing_model'] === EventHeadcountPricingService::MODEL_HEADCOUNT_TIER ? 'block' : 'none' ?>;">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b border-gray-200">
                        <th class="pb-2 pr-2">Min heads</th>
                        <th class="pb-2 pr-2">Max heads</th>
                        <th class="pb-2 pr-2">Package ($)</th>
                        <th class="pb-2 w-10"></th>
                    </tr>
                </thead>
                <tbody id="headcount-tier-rows"></tbody>
            </table>
            <button type="button" id="headcount-tier-add" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">+ Add tier</button>
        </div>
    </div>

    <div
        id="pricing-tab-panel-ticket-types"
        role="tabpanel"
        aria-labelledby="pricing-tab-trigger-ticket-types"
        class="pricing-tab-panel p-4 sm:p-5 bg-indigo-50/30<?= $eventPricingActiveTab === 'ticket-types' ? '' : ' hidden' ?>"
    >
        <p class="text-xs text-indigo-900/80 mb-3">Named tickets support <strong>sale start/end</strong> (early bird) and <strong>package group</strong> (same group = one option per checkout). When any ticket name is filled, <strong>Tiered packages</strong> on Group/Tier is turned off.</p>
        <div id="event-ticket-type-rows" class="space-y-2">
            <?php foreach ($ticketTypesRowsForTemplate as $tti => $tt) :
                $ttName = (string) ($tt['name'] ?? '');
                $ttPrice = isset($tt['price']) && $tt['price'] !== '' && $tt['price'] !== null
                    ? number_format((float) $tt['price'], 2, '.', '')
                    : '';
                $ttLimit = isset($tt['quantity_limit']) && $tt['quantity_limit'] !== '' && $tt['quantity_limit'] !== null
                    ? (string) (int) $tt['quantity_limit']
                    : '';
                $ttSaleStart = EventTicketTypesPersistence::formatForDatetimeLocalInput(
                    isset($tt['sale_starts_at']) ? (string) $tt['sale_starts_at'] : null
                );
                $ttSaleEnd = EventTicketTypesPersistence::formatForDatetimeLocalInput(
                    isset($tt['sale_ends_at']) ? (string) $tt['sale_ends_at'] : null
                );
                $ttPkg = isset($tt['package_group']) ? (string) $tt['package_group'] : '';
                ?>
            <div class="event-ticket-type-row mb-3 p-3 rounded-xl border border-indigo-100/80 bg-white space-y-2">
                <div class="flex flex-wrap items-end gap-2">
                    <input type="text" name="ticket_types[<?= (int) $tti ?>][name]" value="<?= e($ttName) ?>" placeholder="Name (e.g. Beginner — Early bird)" class="headcount-ticket-type-name flex-1 min-w-[140px] border border-gray-200 rounded-lg px-3 py-2 text-sm">
                    <div class="relative w-28">
                        <span class="absolute left-2 top-1/2 -translate-y-1/2 text-gray-400 text-xs">$</span>
                        <input type="number" name="ticket_types[<?= (int) $tti ?>][price]" step="0.01" min="0" value="<?= e($ttPrice) ?>" placeholder="0" class="w-full border border-gray-200 rounded-lg pl-5 pr-2 py-2 text-sm">
                    </div>
                    <input type="number" name="ticket_types[<?= (int) $tti ?>][quantity_limit]" min="0" value="<?= e($ttLimit) ?>" placeholder="Limit" class="w-24 border border-gray-200 rounded-lg px-2 py-2 text-sm" title="Max qty (optional)">
                    <button type="button" class="event-ticket-type-remove text-rose-600 text-sm font-medium hover:underline px-2">Remove</button>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <div>
                        <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Sale starts</label>
                        <input type="datetime-local" name="ticket_types[<?= (int) $tti ?>][sale_starts_at]" value="<?= e($ttSaleStart) ?>" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                    </div>
                    <div>
                        <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Sale ends</label>
                        <input type="datetime-local" name="ticket_types[<?= (int) $tti ?>][sale_ends_at]" value="<?= e($ttSaleEnd) ?>" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                    </div>
                    <div>
                        <label class="block text-[10px] font-semibold text-gray-500 uppercase tracking-wide mb-0.5">Package group</label>
                        <input type="text" name="ticket_types[<?= (int) $tti ?>][package_group]" value="<?= e($ttPkg) ?>" maxlength="64" placeholder="e.g. track" class="w-full border border-gray-200 rounded-lg px-2 py-1.5 text-xs">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="event-ticket-type-add" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium mt-2">+ Add ticket type</button>
    </div>
</div>

<input type="hidden" name="headcount_pricing_tiers_json" id="headcount_pricing_tiers_json" value="<?= e($headcountPricingTiersJsonValue) ?>">
