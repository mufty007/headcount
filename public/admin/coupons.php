<?php
/**
 * Admin coupons CRUD + usage.
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\CouponService;

if (!AuthMiddleware::can('coupons.manage') && !AuthMiddleware::can('payments.manage')) {
    AuthMiddleware::requireCan('payments.manage');
}
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$svc = new CouponService();
$coupons = $svc->tablesExist() ? $svc->listForOrg((int) $organizationId) : [];
$redemptions = $svc->tablesExist() ? $svc->listRedemptions((int) $organizationId) : [];

require_once __DIR__ . '/includes/layout-vars.php';
$csrfToken = CsrfMiddleware::getToken();
$pageTitle = 'Coupons';
$currentPage = 'coupons';
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in" x-data="couponsPage()">
    <?php
    $pageHeaderTitle = 'Coupons';
    $pageHeaderSubtitle = 'Percent-off codes for events, programs, and facility bookings.';
    ob_start(); ?>
    <button type="button" @click="openForm()" class="page-header-btn-primary">New coupon</button>
    <?php $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php'; ?>

    <?php if (!$svc->tablesExist()): ?>
        <div class="ta-alert ta-alert-warning">Run migration 090_unified_coupons.sql first.</div>
    <?php else: ?>
    <div class="rounded-2xl border border-gray-200 bg-white shadow-theme-sm overflow-hidden dark:border-gray-800 dark:bg-white/[0.03]">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Discount</th>
                    <th class="px-4 py-3">Applies to</th>
                    <th class="px-4 py-3">Uses</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($coupons as $c): ?>
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3 font-mono font-semibold"><?= e($c['code']) ?></td>
                    <td class="px-4 py-3"><?= !empty($c['percent_off']) ? e(rtrim(rtrim(number_format((float)$c['percent_off'], 2), '0'), '.')) . '%' : ('$' . number_format((float)($c['amount_off'] ?? 0), 2)) ?></td>
                    <td class="px-4 py-3"><?php
                        $bits = [];
                        if (!empty($c['applies_to_events'])) $bits[] = 'Events';
                        if (!empty($c['applies_to_programs'])) $bits[] = 'Programs';
                        if (!empty($c['applies_to_facilities'])) $bits[] = 'Facilities';
                        echo e(implode(', ', $bits) ?: '—');
                    ?></td>
                    <td class="px-4 py-3"><?= (int) ($c['redemptions_count'] ?? 0) ?><?= $c['remaining'] !== null ? ' / ' . ((int)$c['max_redemptions']) : '' ?></td>
                    <td class="px-4 py-3"><?= !empty($c['active']) ? 'Active' : 'Off' ?></td>
                    <td class="px-4 py-3 text-right">
                        <button type="button" class="text-brand-600 font-semibold text-sm" @click='edit(<?= json_encode($c, JSON_HEX_TAG | JSON_HEX_APOS) ?>)'>Edit</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($coupons)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No coupons yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <h2 class="mt-10 mb-3 text-lg font-bold">Recent redemptions</h2>
    <div class="rounded-2xl border border-gray-200 bg-white shadow-theme-sm overflow-hidden dark:border-gray-800 dark:bg-white/[0.03]">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs uppercase text-gray-500 dark:bg-gray-800">
                <tr>
                    <th class="px-4 py-3">When</th>
                    <th class="px-4 py-3">Code</th>
                    <th class="px-4 py-3">Who</th>
                    <th class="px-4 py-3">Type</th>
                    <th class="px-4 py-3">Discount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($redemptions as $r): ?>
                <tr class="border-t border-gray-100 dark:border-gray-800">
                    <td class="px-4 py-3"><?= e($r['used_at'] ?? '') ?></td>
                    <td class="px-4 py-3 font-mono"><?= e($r['code'] ?? '') ?></td>
                    <td class="px-4 py-3"><?= e(trim((string) ($r['user_name'] ?? '')) ?: ($r['email'] ?? '—')) ?></td>
                    <td class="px-4 py-3 capitalize"><?= e($r['entity_type'] ?? '') ?></td>
                    <td class="px-4 py-3">$<?= number_format((float) ($r['discounted_amount'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($redemptions)): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No redemptions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div x-show="open" x-cloak class="fixed inset-0 z-[9998] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-black/40" @click="open = false"></div>
        <form @submit.prevent="save()" class="relative z-10 w-full max-w-lg space-y-3 rounded-2xl bg-white p-6 shadow-xl dark:bg-gray-900">
            <h3 class="text-lg font-bold" x-text="form.id ? 'Edit coupon' : 'New coupon'"></h3>
            <input class="ta-input w-full" x-model="form.code" placeholder="CODE" required>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="text-xs">Percent off</label>
                    <input type="number" min="0" max="100" step="0.01" class="ta-input w-full" x-model="form.percent_off">
                </div>
                <div>
                    <label class="text-xs">Amount off (legacy)</label>
                    <input type="number" min="0" step="0.01" class="ta-input w-full" x-model="form.amount_off">
                </div>
            </div>
            <div class="flex flex-wrap gap-4 text-sm">
                <label><input type="checkbox" x-model="form.applies_to_events"> Events</label>
                <label><input type="checkbox" x-model="form.applies_to_programs"> Programs</label>
                <label><input type="checkbox" x-model="form.applies_to_facilities"> Facilities</label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-xs">Valid from</label><input type="date" class="ta-input w-full" x-model="form.valid_from"></div>
                <div><label class="text-xs">Valid until</label><input type="date" class="ta-input w-full" x-model="form.valid_until"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-xs">Max redemptions</label><input type="number" min="1" class="ta-input w-full" x-model="form.max_redemptions"></div>
                <div><label class="text-xs">Max per member</label><input type="number" min="1" class="ta-input w-full" x-model="form.max_per_user"></div>
            </div>
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" x-model="form.active"> Active</label>
            <p class="text-xs text-gray-500">Leave target lists empty to apply to all items of the selected types. Member-only codes can be limited later via coupon_users.</p>
            <p class="text-sm text-rose-600" x-show="error" x-text="error"></p>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn-secondary" @click="open = false">Cancel</button>
                <button type="submit" class="btn-primary" :disabled="saving" x-text="saving ? 'Saving…' : 'Save'"></button>
            </div>
        </form>
    </div>
</div>
<script>
function couponsPage() {
    return {
        open: false,
        saving: false,
        error: '',
        form: {},
        openForm() {
            this.form = { id: null, code: '', percent_off: 10, amount_off: '', applies_to_events: true, applies_to_programs: true, applies_to_facilities: true, valid_from: '', valid_until: '', max_redemptions: '', max_per_user: '', active: true };
            this.error = '';
            this.open = true;
        },
        edit(c) {
            this.form = {
                id: c.id,
                code: c.code,
                percent_off: c.percent_off || '',
                amount_off: c.amount_off || '',
                applies_to_events: !!Number(c.applies_to_events),
                applies_to_programs: !!Number(c.applies_to_programs),
                applies_to_facilities: !!Number(c.applies_to_facilities),
                valid_from: (c.valid_from || '').slice(0, 10),
                valid_until: (c.valid_until || '').slice(0, 10),
                max_redemptions: c.max_redemptions || '',
                max_per_user: c.max_per_user || '',
                active: !!Number(c.active),
            };
            this.error = '';
            this.open = true;
        },
        async save() {
            this.saving = true;
            this.error = '';
            try {
                const r = await fetch('<?= e($basePath) ?>/public/api/coupons.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ...this.form, csrf_token: '<?= e($csrfToken) ?>' }),
                });
                const j = await r.json();
                if (!j.success) { this.error = j.message || 'Save failed'; return; }
                window.location.reload();
            } finally { this.saving = false; }
        }
    };
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
