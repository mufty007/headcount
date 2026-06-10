<?php
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Middleware\PortalAuthMiddleware;

PortalAuthMiddleware::requireAuth();

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
$apiBase = $baseUrlPath . '/api/portal/programs.php';
$pageTitle = 'Program';
require __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-8" x-data="pd(<?= (int) $id ?>)" x-init="load()">
    <div class="mb-6 flex items-center justify-between">
        <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/programs.php"
           class="inline-flex items-center gap-2 text-sm font-medium text-gray-500 dark:text-gray-400 hover:text-indigo-600 transition-colors group">
            <div class="p-2 rounded-lg bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-sm group-hover:border-indigo-100 group-hover:bg-indigo-50 transition-all">
                <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
            </div>
            <span>Back to Programs</span>
        </a>
    </div>

    <template x-if="loading">
        <div class="animate-pulse space-y-8">
            <div class="h-64 md:h-80 bg-gray-200 rounded-[2.5rem]"></div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-4">
                    <div class="h-10 bg-gray-200 rounded-lg w-3/4"></div>
                    <div class="h-4 bg-gray-200 rounded-lg w-full"></div>
                    <div class="h-4 bg-gray-200 rounded-lg w-5/6"></div>
                </div>
                <div class="h-64 bg-gray-200 rounded-3xl"></div>
            </div>
        </div>
    </template>

    <template x-if="!loading && notFound">
        <div class="bento-card text-center py-16 px-6">
            <div class="text-4xl mb-4">😕</div>
            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Program not found</h2>
            <p class="text-gray-500 dark:text-gray-400 mt-2">This program may have been removed or is no longer published.</p>
            <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/programs.php" class="inline-block mt-6 text-indigo-600 dark:text-indigo-300 font-semibold hover:underline">Browse programs</a>
        </div>
    </template>

    <template x-if="!loading && program">
        <div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2 space-y-8">
                    <div class="relative overflow-hidden rounded-[2.5rem] shadow-2xl h-64 md:h-80 group">
                        <template x-if="program.banner_image_url">
                            <img :src="program.banner_image_url" :alt="program.title" class="absolute inset-0 w-full h-full object-cover group-hover:scale-105 transition-transform duration-700">
                        </template>
                        <template x-if="!program.banner_image_url">
                            <div class="absolute inset-0 bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-500"></div>
                        </template>
                        <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"></div>
                        <div class="absolute bottom-0 left-0 right-0 p-6 md:p-8">
                            <div class="flex flex-wrap gap-2 mb-2" x-show="program.category_name">
                                <span class="px-3 py-1 rounded-full bg-white/20 backdrop-blur-md text-white text-xs font-bold uppercase tracking-wider border border-white/30" x-text="program.category_name"></span>
                            </div>
                            <h1 class="text-2xl md:text-4xl font-extrabold text-white leading-tight drop-shadow-md" x-text="program.title"></h1>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bento-card flex items-start gap-4 p-5 hover:border-indigo-200 transition-colors">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center shrink-0">
                                <svg width="24" height="24" class="w-6 h-6 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            </div>
                            <div>
                                <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">Next session</h3>
                                <p class="font-bold text-gray-900 dark:text-white leading-tight" x-text="formatNextSession(program.next_session) || 'TBA'"></p>
                            </div>
                        </div>
                        <div class="bento-card flex items-start gap-4 p-5 hover:border-indigo-200 transition-colors">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-50 dark:bg-indigo-500/15 flex items-center justify-center shrink-0">
                                <svg width="24" height="24" class="w-6 h-6 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <template x-if="program.is_virtual == 1 || program.is_virtual === true">
                                    <div>
                                        <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">Virtual</h3>
                                        <template x-if="program.location && String(program.location).trim()">
                                            <a :href="(String(program.location).match(/^https?:\/\//i) ? program.location : 'https://' + program.location)" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 dark:text-indigo-300 font-bold hover:underline break-all" x-text="program.location"></a>
                                        </template>
                                        <template x-if="!program.location || !String(program.location).trim()">
                                            <span class="text-gray-500 dark:text-gray-400">Link TBA</span>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!(program.is_virtual == 1 || program.is_virtual === true)">
                                    <div>
                                        <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-1">Where</h3>
                                        <p class="font-bold text-gray-900 dark:text-white leading-tight break-words" x-text="(program.location && String(program.location).trim()) ? program.location : 'TBA'"></p>
                                        <template x-if="program.location && String(program.location).trim() && !(program.is_virtual == 1)">
                                            <a :href="'https://maps.google.com/?q=' + encodeURIComponent(program.location)" target="_blank" class="text-xs text-indigo-600 dark:text-indigo-300 font-bold hover:underline mt-1 inline-block">View on Maps</a>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="bento-card p-8" x-show="program.presenters && program.presenters.length">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
                            <svg width="20" height="20" class="w-5 h-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                            Presenters
                        </h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <template x-for="(pr, idx) in (program.presenters || [])" :key="idx">
                                <article class="flex gap-4 rounded-2xl border border-gray-100 dark:border-gray-800 bg-gray-50/50 p-4">
                                    <template x-if="pr.image_url">
                                        <img :src="pr.image_url" :alt="pr.display_name || ''" class="w-16 h-16 rounded-xl object-cover shrink-0" width="64" height="64">
                                    </template>
                                    <template x-if="!pr.image_url">
                                        <div class="w-16 h-16 rounded-xl bg-indigo-100 dark:bg-indigo-500/15 shrink-0 flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-lg" aria-hidden="true" x-text="(pr.display_name && pr.display_name.charAt(0)) ? pr.display_name.charAt(0) : '?'"></div>
                                    </template>
                                    <div class="min-w-0">
                                        <h3 class="font-bold text-gray-900 dark:text-white" x-text="pr.display_name || ''"></h3>
                                        <p class="text-sm text-gray-600 dark:text-gray-300 mt-0.5" x-show="pr.title" x-text="pr.title"></p>
                                    </div>
                                </article>
                            </template>
                        </div>
                    </div>

                    <div class="bento-card p-8">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6 flex items-center gap-2">
                            <svg width="20" height="20" class="w-5 h-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            About this program
                        </h2>
                        <div class="prose max-w-none text-gray-600 dark:text-gray-300 leading-relaxed space-y-4" x-html="safeDescriptionHtml()"></div>
                    </div>
                </div>

                <div class="lg:sticky lg:top-24 h-fit">
                    <div class="bento-card overflow-hidden shadow-xl border-indigo-100 dark:border-indigo-500/30 p-6 md:p-8 space-y-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-sm font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Entry</h3>
                                <p class="text-3xl font-black text-gray-900 dark:text-white" x-text="(program.pricing_type || 'free') === 'free' ? 'Free' : ('$' + (program.price_amount != null ? Number(program.price_amount).toFixed(2) : '0.00'))"></p>
                            </div>
                            <div class="w-14 h-14 rounded-full bg-green-50 dark:bg-green-500/15 flex items-center justify-center text-2xl" x-show="(program.pricing_type || 'free') === 'free'">🎁</div>
                            <div class="w-14 h-14 rounded-full bg-amber-50 dark:bg-amber-500/15 flex items-center justify-center text-2xl" x-show="(program.pricing_type || 'free') !== 'free'">💰</div>
                        </div>
                        <div class="h-px bg-gray-100 dark:bg-gray-700"></div>

                        <div class="rounded-2xl border border-green-100 dark:border-green-500/30 bg-green-50 dark:bg-green-500/15 p-4 text-center" x-show="reg && reg.status === 'active'">
                            <div class="w-10 h-10 bg-green-500 text-white rounded-full flex items-center justify-center mx-auto mb-2 shadow-sm">
                                <svg width="24" height="24" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            </div>
                            <p class="text-green-800 dark:text-green-300 font-bold">You&apos;re registered</p>
                            <a href="<?= htmlspecialchars($baseUrlPath) ?>/portal/my-programs.php" class="text-indigo-600 dark:text-indigo-300 text-sm font-semibold mt-2 inline-block hover:underline">View My Programs</a>
                        </div>

                        <div class="space-y-4" x-show="!reg || reg.status === 'pending'">
                            <template x-for="q in (program.questions || [])" :key="q.id">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300" x-text="q.question_text + (q.is_required == 1 || q.is_required === true ? ' *' : '')"></label>
                                    <template x-if="(q.question_type || 'short_text') === 'text'">
                                        <textarea class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" rows="3" x-model="answers[q.id]"></textarea>
                                    </template>
                                    <template x-if="(q.question_type || 'short_text') === 'short_text' || (q.question_type || 'short_text') === 'number'">
                                        <input class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" :type="(q.question_type || 'short_text') === 'number' ? 'number' : 'text'" x-model="answers[q.id]">
                                    </template>
                                    <template x-if="(q.question_type || 'short_text') === 'checkbox'">
                                        <label class="mt-2 flex items-center gap-2 cursor-pointer">
                                            <input type="checkbox" class="rounded border-gray-300 text-indigo-600 dark:text-indigo-300 focus:ring-indigo-500"
                                                   :checked="answers[q.id] === '1' || answers[q.id] === true"
                                                   @change="answers[q.id] = $event.target.checked ? '1' : ''">
                                            <span class="text-sm text-gray-600 dark:text-gray-300">Yes</span>
                                        </label>
                                    </template>
                                    <template x-if="(q.question_type || 'short_text') === 'radio'">
                                        <div class="mt-2 space-y-2">
                                            <template x-for="opt in (q.options || [])" :key="opt.id">
                                                <label class="flex items-center gap-2 cursor-pointer">
                                                    <input type="radio" class="text-indigo-600 dark:text-indigo-300 focus:ring-indigo-500" :name="'pq_' + q.id" :value="opt.option_label" x-model="answers[q.id]">
                                                    <span class="text-sm text-gray-800 dark:text-gray-100" x-text="opt.option_label"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="(q.question_type || 'short_text') === 'dropdown'">
                                        <select class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white dark:bg-gray-800" x-model="answers[q.id]">
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
                                                    <input type="checkbox" class="rounded border-gray-300 text-indigo-600 dark:text-indigo-300 focus:ring-indigo-500"
                                                           :value="opt.option_label" x-model="answers[q.id]">
                                                    <span class="text-sm text-gray-800 dark:text-gray-100" x-text="opt.option_label"></span>
                                                </label>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3 space-y-2" x-show="program && program.waiver && program.waiver.enabled">
                                <label class="flex items-start gap-2 cursor-pointer">
                                    <input type="checkbox" x-model="waiverAccepted" class="mt-0.5 w-4 h-4 text-indigo-600 dark:text-indigo-300 rounded border-gray-300 shrink-0">
                                    <span class="text-sm text-gray-700 dark:text-gray-300" x-text="program.waiver ? program.waiver.checkbox_label : ''"></span>
                                </label>
                                <button type="button" @click="showWaiverModal = true" class="text-xs font-semibold text-indigo-600 dark:text-indigo-300 hover:text-indigo-800 underline text-left">Read full waiver</button>
                            </div>
                            <div x-show="(program.pricing_type || 'free') !== 'free'">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Coupon code (optional)</label>
                                <input type="text" x-model="coupon" class="mt-1 w-full border border-gray-200 dark:border-gray-700 rounded-xl px-3 py-2.5 text-sm" placeholder="CODE">
                            </div>
                            <button type="button" @click="submit" :disabled="busy" class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-bold shadow-lg shadow-indigo-100 disabled:opacity-50 disabled:cursor-not-allowed transition-all" x-text="(program.pricing_type || 'free') === 'free' ? 'Register' : 'Continue to payment'"></button>
                            <p class="text-sm text-red-600 dark:text-red-300 text-center" x-show="err" x-text="err"></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="lg:hidden fixed bottom-0 left-0 right-0 p-4 bg-white/90 backdrop-blur-xl border-t border-gray-100 dark:border-gray-800 z-40" style="margin-bottom: var(--bottom-nav-height, 64px)" x-show="program && (!reg || reg.status === 'pending')">
                <button type="button" @click="submit" :disabled="busy" class="w-full py-3.5 bg-indigo-600 text-white rounded-xl font-bold shadow-lg shadow-indigo-200 disabled:opacity-50" x-text="(program.pricing_type || 'free') === 'free' ? 'Register' : 'Continue to payment'"></button>
            </div>
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
function pd(id) {
    return {
        program: null,
        reg: null,
        loading: true,
        notFound: false,
        busy: false,
        err: '',
        waiverAccepted: false,
        showWaiverModal: false,
        answers: {},
        coupon: '',
        initAnswersFromProgram() {
            const next = {};
            const qs = (this.program && this.program.questions) ? this.program.questions : [];
            for (const q of qs) {
                const qid = q.id;
                const t = q.question_type || 'short_text';
                if (t === 'multi_checkbox') {
                    next[qid] = [];
                } else {
                    next[qid] = '';
                }
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
        sanitizeDescription(html) {
            if (html == null || html === '') {
                return '';
            }
            return String(html).replace(/<script\b[^>]*>[\s\S]*?<\/script\s*>/gi, '').trim();
        },
        safeDescriptionHtml() {
            const p = this.program;
            if (!p) {
                return '';
            }
            const s = this.sanitizeDescription(p.description);
            return s || '<p class="text-gray-400 dark:text-gray-500 italic">No description provided for this program.</p>';
        },
        formatNextSession(ns) {
            if (!ns || !ns.session_date) {
                return '';
            }
            const raw = ns.session_date;
            const parts = String(raw).split('-').map(Number);
            if (parts.length === 3 && !parts.some(isNaN)) {
                const d = new Date(parts[0], parts[1] - 1, parts[2]);
                return d.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
            }
            return raw;
        },
        async getCsrf() {
            const base = '<?= htmlspecialchars($baseUrlPath, ENT_QUOTES) ?>';
            const r = await fetch(base + '/api/csrf-token', { credentials: 'same-origin' });
            const j = await r.json();
            return j.token || j.csrf_token || '';
        },
        async load() {
            if (!id) {
                this.loading = false;
                this.notFound = true;
                return;
            }
            const r = await fetch('<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>?id=' + id, { credentials: 'same-origin' });
            const j = await r.json();
            this.loading = false;
            if (j.success && j.program) {
                this.program = j.program;
                this.reg = j.program.registration || null;
                this.notFound = false;
                this.initAnswersFromProgram();
            } else {
                this.notFound = true;
            }
        },
        async submit() {
            this.busy = true;
            this.err = '';
            if (!this.validateRegistrationAnswers()) {
                this.err = 'Please answer all required questions.';
                this.busy = false;
                return;
            }
            if (this.program && this.program.waiver && this.program.waiver.enabled && !this.waiverAccepted) {
                this.err = 'You must accept the liability waiver to continue.';
                this.busy = false;
                return;
            }
            const csrf = await this.getCsrf();
            const waiverPayload = (this.program && this.program.waiver && this.program.waiver.enabled) ? { waiver_accepted: true } : {};
            if ((this.program.pricing_type || 'free') === 'free') {
                const r = await fetch('<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({ action: 'register_free', program_id: id, answers: this.answers, csrf_token: csrf }, waiverPayload)),
                });
                const j = await r.json();
                this.busy = false;
                if (j.success) {
                    await this.load();
                } else {
                    this.err = j.message || 'Failed';
                }
            } else {
                const r = await fetch('<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({ action: 'checkout', program_id: id, answers: this.answers, coupon_code: this.coupon, csrf_token: csrf }, waiverPayload)),
                });
                const j = await r.json();
                this.busy = false;
                if (j.success && j.checkout_url) {
                    window.location.href = j.checkout_url;
                } else {
                    this.err = j.message || 'Checkout failed';
                }
            }
        },
    };
}
</script>
<style>
    .prose p { margin-bottom: 1rem; }
    .prose strong { color: #111827; font-weight: 700; }
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
