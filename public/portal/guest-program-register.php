<?php
/**
 * Public guest program registration (no portal login).
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

$configFile = HC_PROJECT_ROOT . '/config/config.php';
$config = require $configFile;
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Database::getInstance($config['database']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
if (preg_match('#/portal#', $requestPath)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = '';
}
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/guest-program-register.php';
$checkoutApi = $baseUrlPath . '/api/portal/guest-program-register-checkout.php';
$quoteApi = $baseUrlPath . '/api/portal/guest-program-register.php';
$pageTitle = 'Register for Program';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-8" x-data="guestProgram(<?= (int) $id ?>)" x-init="load()">
    <template x-if="loading">
        <div class="animate-pulse space-y-4">
            <div class="h-10 bg-gray-200 rounded-lg w-2/3"></div>
            <div class="h-48 bg-gray-200 rounded-2xl"></div>
        </div>
    </template>

    <template x-if="!loading && notFound">
        <div class="bento-card text-center py-16">
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Program not available</h2>
            <p class="text-gray-500 mt-2">Guest registration may be disabled or this program is not published.</p>
        </div>
    </template>

    <template x-if="!loading && program">
        <div class="space-y-6">
            <div>
                <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white" x-text="program.title"></h1>
                <p class="text-sm text-gray-500 mt-1">Register without a portal account</p>
            </div>

            <div class="bento-card p-6 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">First name *</label>
                        <input type="text" x-model="guest.first_name" class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Last name *</label>
                        <input type="text" x-model="guest.last_name" class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email *</label>
                    <input type="email" x-model="guest.email" class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm">
                </div>

                <div x-show="program.registration_mode === 'select_weeks' && (program.weeks || []).length" class="space-y-2">
                    <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Select weeks *</p>
                    <template x-for="wk in (program.weeks || [])" :key="wk.id">
                        <label class="flex gap-3 rounded-xl border p-3 cursor-pointer"
                               :class="selectedWeekIds.includes(Number(wk.id)) ? 'border-indigo-400 bg-indigo-50/60' : 'border-gray-200'">
                            <input type="checkbox" class="mt-1" :value="Number(wk.id)" @change="toggleWeek(Number(wk.id), $event.target.checked)">
                            <div class="min-w-0">
                                <div class="font-semibold text-sm" x-text="wk.title"></div>
                                <div class="text-xs text-gray-500" x-text="'$' + Number(wk.price_amount || 0).toFixed(2)"></div>
                            </div>
                        </label>
                    </template>
                    <p class="text-lg font-bold text-gray-900 dark:text-white" x-show="quote && quote.total != null" x-text="'Total: $' + Number(quote.total).toFixed(2)"></p>
                    <p class="text-xs text-amber-700" x-show="quote && quote.bundle_applied">Bundle price applied — all weeks selected.</p>
                </div>

                <template x-for="q in (program.questions || [])" :key="q.id">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" x-text="q.question_text + (q.is_required == 1 || q.is_required === true ? ' *' : '')"></label>
                        <template x-if="(q.question_type || 'short_text') === 'text'">
                            <textarea class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm" rows="3" x-model="answers[q.id]"></textarea>
                        </template>
                        <template x-if="(q.question_type || 'short_text') === 'short_text' || (q.question_type || 'short_text') === 'number'">
                            <input class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm" :type="(q.question_type || 'short_text') === 'number' ? 'number' : 'text'" x-model="answers[q.id]">
                        </template>
                        <template x-if="(q.question_type || 'short_text') === 'checkbox'">
                            <label class="mt-2 flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="rounded border-gray-300 text-indigo-600"
                                       :checked="answers[q.id] === '1' || answers[q.id] === true"
                                       @change="answers[q.id] = $event.target.checked ? '1' : ''">
                                <span class="text-sm text-gray-600 dark:text-gray-300">Yes</span>
                            </label>
                        </template>
                        <template x-if="(q.question_type || 'short_text') === 'radio'">
                            <div class="mt-2 space-y-2">
                                <template x-for="opt in (q.options || [])" :key="opt.id">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" class="text-indigo-600" :name="'pq_' + q.id" :value="opt.option_label" x-model="answers[q.id]">
                                        <span class="text-sm text-gray-800 dark:text-gray-100" x-text="opt.option_label"></span>
                                    </label>
                                </template>
                            </div>
                        </template>
                        <template x-if="(q.question_type || 'short_text') === 'dropdown'">
                            <select class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm bg-white dark:bg-gray-800" x-model="answers[q.id]">
                                <option value="">Select...</option>
                                <template x-for="opt in (q.options || [])" :key="opt.id">
                                    <option :value="opt.option_label" x-text="opt.option_label"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="(q.question_type || 'short_text') === 'multi_checkbox'">
                            <div class="mt-2 space-y-2">
                                <template x-for="opt in (q.options || [])" :key="opt.id">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" class="rounded border-gray-300 text-indigo-600"
                                               :value="opt.option_label" x-model="answers[q.id]">
                                        <span class="text-sm text-gray-800 dark:text-gray-100" x-text="opt.option_label"></span>
                                    </label>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3 space-y-2" x-show="program.waiver && program.waiver.enabled">
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" x-model="waiverAccepted" class="mt-0.5 w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300 shrink-0">
                        <span class="text-sm text-gray-700 dark:text-gray-300" x-text="program.waiver.checkbox_label"></span>
                    </label>
                    <button type="button" @click="showWaiverModal = true" class="text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-800 underline text-left">Read full waiver</button>
                    <div class="max-h-40 overflow-y-auto whitespace-pre-wrap text-xs text-gray-600 dark:text-gray-400 leading-relaxed border-t border-gray-200 dark:border-gray-700 pt-2" x-text="program.waiver.full_text"></div>
                </div>

                <div x-show="(program.pricing_type || 'free') !== 'free'">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Coupon (optional)</label>
                    <input type="text" x-model="coupon" class="mt-1 w-full border border-gray-200 rounded-xl px-3 py-2.5 text-sm">
                </div>

                <button type="button" @click="submit" :disabled="busy" class="w-full py-3.5 bg-indigo-600 text-white rounded-xl font-bold disabled:opacity-50"
                        x-text="(program.pricing_type || 'free') === 'free' ? 'Register' : 'Continue to payment'"></button>
                <p class="text-sm text-red-600 text-center" x-show="err" x-text="err"></p>
                <p class="text-sm text-green-700 text-center" x-show="successMsg" x-text="successMsg"></p>
            </div>

            <p class="text-center text-sm text-gray-500">
                Already have an account?
                <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/login.php" class="text-indigo-600 font-semibold hover:underline">Log in</a>
            </p>
        </div>
    </template>

    <div x-show="showWaiverModal" x-cloak class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4" @keydown.escape.window="showWaiverModal = false">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-lg w-full max-h-[85vh] flex flex-col" @click.outside="showWaiverModal = false">
            <div class="p-5 border-b border-gray-100 dark:border-gray-800"><h3 class="text-lg font-bold text-gray-900 dark:text-white">Liability waiver</h3></div>
            <div class="p-5 overflow-y-auto text-sm text-gray-700 dark:text-gray-300 leading-relaxed whitespace-pre-wrap" x-text="program && program.waiver ? program.waiver.full_text : ''"></div>
            <div class="p-4 border-t border-gray-100 dark:border-gray-800"><button type="button" @click="showWaiverModal = false" class="w-full py-2.5 bg-indigo-600 text-white rounded-xl font-semibold hover:bg-indigo-700">Close</button></div>
        </div>
    </div>
</div>

<script>
function guestProgram(id) {
    return {
        program: null,
        loading: true,
        notFound: false,
        busy: false,
        err: '',
        successMsg: '',
        guest: { first_name: '', last_name: '', email: '' },
        answers: {},
        coupon: '',
        waiverAccepted: false,
        showWaiverModal: false,
        selectedWeekIds: [],
        quote: null,
        async getCsrf() {
            const r = await fetch('<?= htmlspecialchars($baseUrlPath, ENT_QUOTES) ?>/api/csrf-token', { credentials: 'same-origin' });
            const j = await r.json();
            return j.token || j.csrf_token || '';
        },
        async load() {
            if (!id) { this.loading = false; this.notFound = true; return; }
            const r = await fetch('<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>?program_id=' + id, { credentials: 'same-origin' });
            const j = await r.json();
            this.loading = false;
            if (j.success && j.program) {
                this.program = j.program;
                this.notFound = false;
                this.initAnswersFromProgram();
            } else {
                this.notFound = true;
            }
        },
        initAnswersFromProgram() {
            const next = {};
            const qs = (this.program && this.program.questions) ? this.program.questions : [];
            for (const q of qs) {
                const qid = q.id;
                const t = q.question_type || 'short_text';
                next[qid] = (t === 'multi_checkbox') ? [] : '';
            }
            this.answers = next;
        },
        validateRegistrationAnswers() {
            const qs = (this.program && this.program.questions) ? this.program.questions : [];
            for (const q of qs) {
                const req = q.is_required == 1 || q.is_required === true;
                if (!req) continue;
                const t = q.question_type || 'short_text';
                const v = this.answers[q.id];
                if (t === 'multi_checkbox') {
                    if (!Array.isArray(v) || v.length === 0) return false;
                    continue;
                }
                if (t === 'checkbox') {
                    if (v !== '1' && v !== true && v !== 'yes') return false;
                    continue;
                }
                if (v === null || v === undefined || String(v).trim() === '') return false;
            }
            return true;
        },
        toggleWeek(weekId, checked) {
            if (checked) {
                if (!this.selectedWeekIds.includes(weekId)) this.selectedWeekIds.push(weekId);
            } else {
                this.selectedWeekIds = this.selectedWeekIds.filter((x) => x !== weekId);
            }
            this.refreshQuote();
        },
        async refreshQuote() {
            if (!this.program || this.program.registration_mode !== 'select_weeks' || !this.selectedWeekIds.length) {
                this.quote = null;
                return;
            }
            try {
                const r = await fetch('<?= htmlspecialchars($quoteApi, ENT_QUOTES) ?>?action=quote&program_id=' + id + '&week_ids=' + encodeURIComponent(JSON.stringify(this.selectedWeekIds)), { credentials: 'same-origin' });
                const j = await r.json();
                this.quote = j.quote || null;
            } catch (e) {
                this.quote = null;
            }
        },
        async submit() {
            this.busy = true;
            this.err = '';
            this.successMsg = '';
            if (!this.guest.first_name.trim() || !this.guest.last_name.trim() || !this.guest.email.trim()) {
                this.err = 'Name and email are required.';
                this.busy = false;
                return;
            }
            if (this.program.registration_mode === 'select_weeks' && !this.selectedWeekIds.length) {
                this.err = 'Select at least one week.';
                this.busy = false;
                return;
            }
            if (this.program.waiver && this.program.waiver.enabled && !this.waiverAccepted) {
                this.err = 'You must accept the liability waiver to continue.';
                this.busy = false;
                return;
            }
            if (!this.validateRegistrationAnswers()) {
                this.err = 'Please answer all required questions.';
                this.busy = false;
                return;
            }
            const csrf = await this.getCsrf();
            const payload = {
                program_id: id,
                first_name: this.guest.first_name.trim(),
                last_name: this.guest.last_name.trim(),
                email: this.guest.email.trim(),
                answers: this.answers,
                csrf_token: csrf,
                waiver_accepted: this.program.waiver && this.program.waiver.enabled ? true : undefined,
            };
            if (this.program.registration_mode === 'select_weeks') {
                payload.week_ids = this.selectedWeekIds;
            }
            const isFree = (this.program.pricing_type || 'free') === 'free';
            const url = isFree ? '<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>' : '<?= htmlspecialchars($checkoutApi, ENT_QUOTES) ?>';
            if (!isFree) payload.coupon_code = this.coupon;
            const r = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const j = await r.json();
            this.busy = false;
            if (j.success) {
                if (j.checkout_url) {
                    window.location.href = j.checkout_url;
                } else {
                    this.successMsg = j.complete_account_url
                        ? 'Registered! Check your email to complete your account.'
                        : 'You are registered for this program.';
                }
            } else {
                this.err = j.message || 'Registration failed';
            }
        },
    };
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
