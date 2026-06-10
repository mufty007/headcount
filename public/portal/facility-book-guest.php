<?php

/**

 * Guest facility booking (no login)

 */

require_once __DIR__ . '/bootstrap.php';

require_once HC_PROJECT_ROOT . '/vendor/autoload.php';



use Headcount\Helpers\Database;

use Headcount\Helpers\Security;

use Headcount\Middleware\CsrfMiddleware;

use Headcount\Services\FacilityService;
use Headcount\Services\FacilityPaymentService;



$configFile = HC_PROJECT_ROOT . '/config/config.php';

$config = require $configFile;

Security::configureSession();

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

$db = Database::getInstance($config['database']);



$orgId = headcount_resolve_portal_organization_id(null, $config, $db);

$slug = trim((string) ($_GET['facility'] ?? ''));



$svc = new FacilityService();

$facility = ($orgId && $slug) ? $svc->getBySlugForOrg($slug, $orgId) : null;

if (!$facility || empty($facility['allow_guest_booking'])) {

    header('Location: facilities.php');

    exit;

}



$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);

$pos = strpos($requestPath, '/portal');

$baseUrlPath = $pos !== false ? rtrim(substr($requestPath, 0, $pos), '/') : '';



$apiBase = $baseUrlPath . '/api/portal/guest-facility-booking.php';
$availabilityApi = $baseUrlPath . '/api/portal/facility-bookings.php';
$checkoutApiBase = $baseUrlPath . '/api/portal/guest-facility-booking-checkout.php';
$paySvc = new FacilityPaymentService();
$requiresCheckout = !empty($facility['is_paid'])
    && (float) ($facility['hourly_rate'] ?? 0) > 0
    && $paySvc->facilityPaymentsEnabled();

$csrfToken = CsrfMiddleware::getToken();

$registerBase = $baseUrlPath . '/portal/register.php';



$pageTitle = 'Book as guest';

$currentPage = 'facility-book-guest';

$isLoggedIn = false;

$inputClass = 'w-full border border-gray-200 dark:border-gray-700 rounded-xl px-4 py-2.5 bg-white dark:bg-gray-800 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all';

require __DIR__ . '/includes/header.php';

?>



<div class="max-w-xl mx-auto px-4 py-8" x-data="facilityBookGuest()" x-init="init()">

    <a href="facility-details.php?facility=<?= e(urlencode($facility['slug'])) ?>" class="text-indigo-600 dark:text-indigo-300 text-sm font-semibold hover:underline">&larr; Back to facility</a>

    <h1 class="text-2xl font-extrabold text-gray-900 dark:text-white mt-4"><?= e($facility['name']) ?></h1>

    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Book as a guest — no account required</p>

    <?php if (!empty($facility['is_paid']) && (float) ($facility['hourly_rate'] ?? 0) > 0): ?>

    <p class="text-sm font-semibold text-indigo-700 dark:text-indigo-300 mt-2">$<?= number_format((float) $facility['hourly_rate'], 2) ?> per hour<?php if (!empty($facility['discount_percent']) && (float) $facility['discount_percent'] > 0): ?> (<?= number_format((float) $facility['discount_percent'], 0) ?>% discount applied)<?php endif; ?></p>

    <?php endif; ?>



    <div class="mt-4 p-4 bg-sky-50 dark:bg-sky-500/15 border border-sky-100 dark:border-sky-500/30 rounded-xl text-sm text-sky-900">

        <strong>Manage your booking online:</strong> You can request a booking without an account.

        To view, change, or cancel your booking yourself, you will need to

        <a href="<?= e($registerBase) ?>" class="font-semibold underline">complete your profile and become a member</a>.

    </div>



    <form @submit.prevent="submit" class="mt-6 space-y-4 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-2xl p-6" x-show="!success">

        <div class="grid grid-cols-2 gap-4">

            <div>

                <label class="block text-sm font-medium mb-1">First name *</label>

                <input type="text" x-model="form.first_name" required class="<?= e($inputClass) ?>">

            </div>

            <div>

                <label class="block text-sm font-medium mb-1">Last name *</label>

                <input type="text" x-model="form.last_name" required class="<?= e($inputClass) ?>">

            </div>

        </div>

        <div>

            <label class="block text-sm font-medium mb-1">Email *</label>

            <input type="email" x-model="form.email" required class="<?= e($inputClass) ?>">

        </div>

        <div>

            <label class="block text-sm font-medium mb-1">Phone</label>

            <input type="tel" x-model="form.phone" class="<?= e($inputClass) ?>">

        </div>

        <div>

            <label class="block text-sm font-medium mb-1" for="guest-booking-event-title">Event title *</label>

            <input id="guest-booking-event-title" type="text" x-model="form.title" required maxlength="255" class="<?= e($inputClass) ?>"

                   placeholder="e.g. Community iftar, Youth halaqa">

            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Name of the event or the community / group it is for.</p>

        </div>

        <div>

            <label class="block text-sm font-medium mb-1" for="guest-booking-event-purpose">Event / purpose *</label>

            <textarea id="guest-booking-event-purpose" x-model="form.purpose" required rows="5" maxlength="5000"

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

                <label class="block text-sm font-medium mb-1">Start *</label>

                <input type="time" x-model="form.start_time" required class="<?= e($inputClass) ?>"
                       @change="checkSlotReserved()">

            </div>

            <div>

                <label class="block text-sm font-medium mb-1">End *</label>

                <input type="time" x-model="form.end_time" required class="<?= e($inputClass) ?>"
                       @change="checkSlotReserved()">

            </div>

        </div>

        <p x-show="slotReservedMessage" x-text="slotReservedMessage" x-cloak
           class="text-sm text-amber-800 dark:text-amber-300 bg-amber-50 dark:bg-amber-500/15 border border-amber-200 dark:border-amber-500/30 rounded-xl p-3"></p>

        <div x-show="isPaid" x-cloak class="text-sm bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-3">

            <span class="font-semibold text-gray-800 dark:text-gray-100">Estimated total:</span>

            <span x-text="priceLabel" class="text-indigo-700 dark:text-indigo-300 font-bold ml-1"></span>

        </div>

        <?php if ($requiresCheckout): ?>
        <p class="text-sm text-sky-800 dark:text-sky-300 bg-sky-50 dark:bg-sky-500/15 border border-sky-100 dark:border-sky-500/30 rounded-xl p-3">You will authorize payment on the next screen. Your card is only charged if staff approves. If not approved, the hold is released automatically.</p>
        <?php endif; ?>
        <p x-show="error" x-text="error" class="text-red-600 dark:text-red-300 text-sm"></p>

        <button type="submit" :disabled="loading || wordCount > maxWords || !!slotReservedMessage" class="w-full py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 disabled:opacity-50">
            <span x-text="loading ? 'Please wait…' : (requiresCheckout ? 'Continue to payment' : 'Submit request')"></span>
        </button>

        <p class="text-center text-sm text-gray-500 dark:text-gray-400">Already a member? <a href="login.php" class="text-indigo-600 dark:text-indigo-300 font-semibold">Log in to book</a></p>

    </form>



    <div x-show="success" class="mt-6 p-6 bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 rounded-2xl">

        <h2 class="font-bold text-green-900">Request submitted — pending approval</h2>

        <p class="text-sm text-green-800 dark:text-green-300 mt-2">We will email you when your booking has been reviewed.</p>

        <p class="text-sm text-sky-900 mt-4 p-3 bg-sky-50 dark:bg-sky-500/15 rounded-lg">

            To manage this booking online, <a :href="registerUrl" class="font-semibold underline">complete your profile and become a member</a>.

        </p>

    </div>

</div>



<script>

function facilityBookGuest() {

    return {

        facilityId: <?= (int) $facility['id'] ?>,

        orgId: <?= (int) $orgId ?>,

        isPaid: <?= !empty($facility['is_paid']) ? 'true' : 'false' ?>,

        hourlyRate: <?= (float) ($facility['hourly_rate'] ?? 0) ?>,

        discountPercent: <?= (float) ($facility['discount_percent'] ?? 0) ?>,

        apiBase: <?= json_encode($apiBase) ?>,
        availabilityApi: <?= json_encode($availabilityApi) ?>,
        checkoutApiBase: <?= json_encode($checkoutApiBase) ?>,
        requiresCheckout: <?= $requiresCheckout ? 'true' : 'false' ?>,

        csrf: <?= json_encode($csrfToken) ?>,

        registerBase: <?= json_encode($registerBase) ?>,

        minDate: new Date().toISOString().slice(0, 10),

        maxWords: 200,

        wordCount: 0,

        form: { first_name: '', last_name: '', email: '', phone: '', title: '', purpose: '', date: '', start_time: '09:00', end_time: '10:00' },

        loading: false,

        error: '',

        success: false,

        registerUrl: <?= json_encode($registerBase) ?>,

        get priceLabel() {

            if (!this.isPaid || !this.form.date || !this.form.start_time || !this.form.end_time) return '—';

            const start = new Date(this.form.date + 'T' + this.form.start_time);

            const end = new Date(this.form.date + 'T' + this.form.end_time);

            if (isNaN(start) || isNaN(end) || end <= start) return '—';

            const hours = (end - start) / 3600000;

            const subtotal = hours * this.hourlyRate;

            const total = subtotal * (1 - this.discountPercent / 100);

            return '$' + total.toFixed(2) + ' (' + hours.toFixed(1) + ' hrs)';

        },

        init() {

            this.updateWordCount();

            this.loadBlocks();

        },

<?php require __DIR__ . '/includes/facility-book-slot-check.js.php'; ?>

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

            this.loading = true;

            this.error = '';

            const start = this.form.date + ' ' + this.form.start_time + ':00';

            const end = this.form.date + ' ' + this.form.end_time + ':00';

            try {
                const payload = {
                    csrf_token: this.csrf,
                    organization_id: this.orgId,
                    facility_id: this.facilityId,
                    first_name: this.form.first_name,
                    last_name: this.form.last_name,
                    email: this.form.email,
                    phone: this.form.phone,
                    title: this.form.title.trim(),
                    purpose: this.form.purpose.trim(),
                    start_datetime: start,
                    end_datetime: end,
                };
                const url = this.requiresCheckout ? this.checkoutApiBase : this.apiBase;
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();
                if (!data.success) {
                    this.error = data.message || 'Could not submit';
                    return;
                }
                if (this.requiresCheckout && data.checkout_url) {
                    window.location.href = data.checkout_url;
                    return;
                }
                this.registerUrl = this.registerBase + '?email=' + encodeURIComponent(this.form.email);
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

