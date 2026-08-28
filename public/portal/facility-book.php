<?php

/**

 * Member facility booking

 */

require_once __DIR__ . '/bootstrap.php';

require_once HC_PROJECT_ROOT . '/vendor/autoload.php';



use Headcount\Helpers\Database;

use Headcount\Helpers\Security;

use Headcount\Middleware\PortalAuthMiddleware;

use Headcount\Middleware\CsrfMiddleware;

use Headcount\Services\FacilityService;
use Headcount\Services\FacilityPaymentService;

PortalAuthMiddleware::requireAuth();



$configFile = HC_PROJECT_ROOT . '/config/config.php';

$config = require $configFile;

Security::configureSession();

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

Database::getInstance($config['database']);



$organizationId = PortalAuthMiddleware::getOrganizationId();

$slug = trim((string) ($_GET['facility'] ?? ''));



$svc = new FacilityService();

$facility = $slug ? $svc->getBySlugForOrg($slug, $organizationId) : null;

if (!$facility || empty($facility['allow_member_booking'])) {

    header('Location: facilities.php');

    exit;

}



$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);

$pos = strpos($requestPath, '/portal');

$baseUrlPath = $pos !== false ? rtrim(substr($requestPath, 0, $pos), '/') : '';



$apiBase = $baseUrlPath . '/api/portal/facility-bookings.php';
$availabilityApi = $apiBase;
$checkoutApiBase = $baseUrlPath . '/api/portal/facility-booking-checkout.php';
$paySvc = new FacilityPaymentService();
$requiresCheckout = !empty($facility['is_paid'])
    && (float) ($facility['hourly_rate'] ?? 0) > 0
    && $paySvc->facilityPaymentsEnabled();

$facilityAddons = [];
try {
    $facilityAddons = (new \Headcount\Services\FacilityAddonService())->listForFacility((int) $facility['id'], true);
} catch (\Throwable $e) {
    $facilityAddons = [];
}

$csrfToken = CsrfMiddleware::getToken();



$pageTitle = 'Book ' . ($facility['name'] ?? 'Facility');

$currentPage = 'facilities';

$inputClass = 'w-full border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 bg-white dark:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all';

require __DIR__ . '/includes/header.php';

?>



<div class="max-w-xl mx-auto px-4 py-8" x-data="facilityBookMember()" x-init="init()">

    <a href="facility-details.php?facility=<?= e(urlencode($facility['slug'])) ?>" class="text-indigo-600 dark:text-indigo-300 text-sm font-semibold hover:underline">&larr; Back to facility</a>

    <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white mt-4"><?= e($facility['name']) ?></h1>

    <?php if (!empty($facility['location'])): ?><p class="text-gray-500 dark:text-gray-400 mt-1"><?= e($facility['location']) ?></p><?php endif; ?>

    <?php if (!empty($facility['is_paid']) && (float) ($facility['hourly_rate'] ?? 0) > 0): ?>

    <p class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 mt-2">$<?= number_format((float) $facility['hourly_rate'], 2) ?> per hour<?php if (!empty($facility['discount_percent']) && (float) $facility['discount_percent'] > 0): ?> (<?= number_format((float) $facility['discount_percent'], 0) ?>% discount applied)<?php endif; ?></p>

    <?php endif; ?>



    <form @submit.prevent="submit" class="mt-8 space-y-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-6">

        <div>

            <label class="block text-sm font-medium mb-1" for="booking-event-title">Event title *</label>

            <input id="booking-event-title" type="text" x-model="form.title" required maxlength="255" class="<?= e($inputClass) ?>"

                   placeholder="e.g. Community iftar, Youth halaqa">

            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Name of the event or the community / group it is for.</p>

        </div>

        <div>

            <label class="block text-sm font-medium mb-1" for="booking-event-purpose">Event / purpose *</label>

            <textarea id="booking-event-purpose" x-model="form.purpose" required rows="5" maxlength="5000"

                      class="<?= e($inputClass) ?> resize-y min-h-[7rem]"

                      placeholder="Describe what the event is for, who will attend, and any setup needs…"

                      @input="updateWordCount()"></textarea>

            <p class="text-xs mt-1" :class="wordCount > maxWords ? 'text-red-600 dark:text-red-300 font-semibold' : 'text-gray-500 dark:text-gray-400'">

                <span x-text="wordCount"></span> / <?= (int) 200 ?> words

            </p>

        </div>

        <div>

            <label class="block text-sm font-medium mb-1">Date *</label>

            <input type="date" x-model="form.date" required class="<?= e($inputClass) ?>" :min="minDate"
                   @change="loadBlocks()">

        </div>

        <div class="grid grid-cols-2 gap-4">

            <div>

                <label class="block text-sm font-medium mb-1">Start time *</label>

                <input type="time" x-model="form.start_time" required class="<?= e($inputClass) ?>"
                       @change="checkSlotReserved()">

            </div>

            <div>

                <label class="block text-sm font-medium mb-1">End time *</label>

                <input type="time" x-model="form.end_time" required class="<?= e($inputClass) ?>"
                       @change="checkSlotReserved()">

            </div>

        </div>

        <p x-show="slotReservedMessage" x-text="slotReservedMessage" x-cloak
           class="text-sm text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/15 border border-amber-200 dark:border-amber-500/30 rounded-xl p-3"></p>

        <?php if (!empty($facilityAddons)): ?>
        <div class="space-y-2">
            <p class="text-sm font-semibold">Add-ons</p>
            <?php foreach ($facilityAddons as $addon): ?>
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" value="<?= (int) $addon['id'] ?>" @change="toggleAddon(<?= (int) $addon['id'] ?>, $event.target.checked)" class="mt-0.5 rounded">
                <span>
                    <span class="font-medium"><?= e($addon['title']) ?></span>
                    <span class="text-indigo-700"> +$<?= number_format((float) $addon['price'], 2) ?></span>
                    <?php if (!empty($addon['description'])): ?><span class="block text-xs text-gray-500"><?= e($addon['description']) ?></span><?php endif; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div x-show="isPaid" x-cloak class="space-y-1">
            <label class="block text-sm font-medium mb-1">Have a coupon?</label>
            <div class="flex gap-2">
                <input type="text" x-model="form.coupon_code" @input="couponOk = false; couponMsg = ''; couponMeta = null" class="<?= e($inputClass) ?>" placeholder="Enter code" autocomplete="off">
                <button type="button" @click="applyCoupon" :disabled="couponBusy" class="shrink-0 px-4 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-100 hover:bg-gray-200 dark:hover:bg-gray-600 disabled:opacity-50">Apply</button>
            </div>
            <p class="text-xs min-h-[1rem]" :class="couponOk ? 'text-green-700 dark:text-green-300' : 'text-red-600 dark:text-red-300'" x-show="couponMsg" x-text="couponMsg"></p>
        </div>

        <div x-show="isPaid" x-cloak class="text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">

            <span class="font-semibold text-gray-800 dark:text-gray-100">Estimated total:</span>

            <span x-text="priceLabel" class="text-indigo-700 dark:text-indigo-300 font-bold ml-1"></span>

        </div>

        <?php if ($requiresCheckout): ?>
        <p class="text-sm text-sky-800 dark:text-sky-300 bg-sky-50 dark:bg-sky-500/15 border border-sky-100 dark:border-sky-500/30 rounded-xl p-3">You will authorize payment on the next screen. Your card is only charged if staff approves your request. If not approved, the hold is released automatically.</p>
        <?php endif; ?>
        <p class="text-sm text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/15 border border-amber-100 dark:border-amber-500/30 rounded-xl p-3">Your request will be submitted for staff approval. You will receive an email when it is reviewed.</p>

        <p x-show="error" x-text="error" class="text-red-600 dark:text-red-300 text-sm"></p>

        <p x-show="success" class="text-green-700 dark:text-green-300 text-sm font-semibold">Request submitted! <a href="my-facility-bookings.php" class="underline">View my bookings</a></p>

        <button type="submit" :disabled="loading || success || wordCount > maxWords || !!slotReservedMessage" class="w-full py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 disabled:opacity-50">

            <span x-text="loading ? 'Please wait…' : (requiresCheckout ? 'Continue to payment' : 'Submit booking request')"></span>

        </button>

    </form>

</div>



<script>

function facilityBookMember() {

    return {

        facilityId: <?= (int) $facility['id'] ?>,

        apiBase: <?= json_encode($apiBase) ?>,
        availabilityApi: <?= json_encode($availabilityApi) ?>,
        checkoutApiBase: <?= json_encode($checkoutApiBase) ?>,
        couponApiBase: <?= json_encode($baseUrlPath) ?>,
        requiresCheckout: <?= $requiresCheckout ? 'true' : 'false' ?>,
        csrf: <?= json_encode($csrfToken) ?>,
        isPaid: <?= !empty($facility['is_paid']) ? 'true' : 'false' ?>,

        hourlyRate: <?= (float) ($facility['hourly_rate'] ?? 0) ?>,

        discountPercent: <?= (float) ($facility['discount_percent'] ?? 0) ?>,

        minDate: new Date().toISOString().slice(0, 10),

        maxWords: 200,

        wordCount: 0,

        form: { title: '', purpose: '', date: '', start_time: '09:00', end_time: '10:00', coupon_code: '' },
        addonIds: [],
        addonPrices: <?= json_encode(array_column($facilityAddons, 'price', 'id')) ?>,
        couponBusy: false,
        couponMsg: '',
        couponOk: false,
        couponMeta: null,

        loading: false,

        error: '',

        success: false,

        get priceLabel() {

            if (!this.isPaid || !this.form.date || !this.form.start_time || !this.form.end_time) return '—';

            const start = new Date(this.form.date + 'T' + this.form.start_time);

            const end = new Date(this.form.date + 'T' + this.form.end_time);

            if (isNaN(start) || isNaN(end) || end <= start) return '—';

            const hours = (end - start) / 3600000;

            const subtotal = hours * this.hourlyRate;

            let extra = 0;
            (this.addonIds || []).forEach((id) => {
                extra += parseFloat((this.addonPrices && this.addonPrices[id]) || 0) || 0;
            });

            const totalBeforeCoupon = (subtotal + extra) * (1 - this.discountPercent / 100);
            const total = (window.headcountCoupon && this.couponMeta)
                ? window.headcountCoupon.applyDiscount(totalBeforeCoupon, this.couponMeta)
                : totalBeforeCoupon;

            return '$' + total.toFixed(2) + ' (' + hours.toFixed(1) + ' hrs)';

        },

        init() {

            this.updateWordCount();

            this.loadBlocks();

        },

<?php require __DIR__ . '/includes/facility-book-slot-check.js.php'; ?>

        toggleAddon(id, checked) {
            const n = parseInt(id, 10);
            if (checked && this.addonIds.indexOf(n) < 0) this.addonIds.push(n);
            if (!checked) this.addonIds = this.addonIds.filter((x) => x !== n);
        },

        async applyCoupon() {
            if (!window.headcountCoupon) return;
            await window.headcountCoupon.applyAlpine(this, { type: 'facility', id: this.facilityId, baseUrl: this.couponApiBase });
        },
        async ensureCoupon() {
            if (!this.isPaid) return true;
            if (!window.headcountCoupon) return true;
            return window.headcountCoupon.ensureAlpine(this, { type: 'facility', id: this.facilityId, baseUrl: this.couponApiBase });
        },

        countWords(text) {
            const t = String(text || '').trim().replace(/\s+/g, ' ');

            if (!t) return 0;

            return t.split(' ').filter(Boolean).length;

        },

        updateWordCount() {

            this.wordCount = this.countWords(this.form.purpose);

        },

        async submit() {

            this.updateWordCount();

            if (!this.form.title.trim()) {

                this.error = 'Event title is required.';

                return;

            }

            if (!this.form.purpose.trim()) {

                this.error = 'Event / purpose is required.';

                return;

            }

            if (this.wordCount > this.maxWords) {

                this.error = 'Event / purpose must be ' + this.maxWords + ' words or fewer.';

                return;

            }

            if (!this.slotCheckBeforeSubmit()) {

                return;

            }

            if (this.isPaid) {
                const couponOk = await this.ensureCoupon();
                if (!couponOk) {
                    this.error = this.couponMsg || 'That coupon is not valid.';
                    return;
                }
            }

            this.loading = true;

            this.error = '';

            const start = this.form.date + ' ' + this.form.start_time + ':00';

            const end = this.form.date + ' ' + this.form.end_time + ':00';

            try {
                const payload = {
                    csrf_token: this.csrf,
                    facility_id: this.facilityId,
                    title: this.form.title.trim(),
                    purpose: this.form.purpose.trim(),
                    start_datetime: start,
                    end_datetime: end,
                    addon_ids: this.addonIds,
                    coupon_code: (this.form.coupon_code || '').trim(),
                };
                const url = this.requiresCheckout
                    ? this.checkoutApiBase
                    : this.apiBase + '?action=create';
                if (!this.requiresCheckout) {
                    payload.action = 'create';
                }
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) {
                    this.error = data.message || 'Could not submit request';
                    return;
                }
                if (this.requiresCheckout && data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                this.success = true;
            } catch (e) {
                this.error = 'Network error';
            } finally {
                this.loading = false;
            }

        },

    };

}

</script>



<?php require __DIR__ . '/includes/footer.php'; ?>

