<?php
/**
 * My Profile — lets the logged-in admin or coordinator manage their own account.
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

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$db = Database::getInstance();

$csrfToken = CsrfMiddleware::getToken();

if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
$apiBase = $basePath . '/public/api';

$me = $db->queryOne(
    'SELECT id, first_name, last_name, email, phone, role, created_at FROM users WHERE id = ? LIMIT 1',
    [$userId]
) ?: [];

$user = [
    'name' => trim(($me['first_name'] ?? '') . ' ' . ($me['last_name'] ?? '')) ?: 'Account',
    'email' => $me['email'] ?? 'admin@headcount.local',
    'role' => $me['role'] ?? AuthMiddleware::getRole() ?? 'admin',
];
$roleLabel = $user['role'] === 'coordinator' ? 'Coordinator' : 'Administrator';

$pageTitle = 'My Profile';
$currentPage = 'profile';
require __DIR__ . '/includes/header.php';
?>

<div x-data="profileApp()" x-cloak>
    <?php
    $pageHeaderTitle = 'My Profile';
    $pageHeaderSubtitle = 'Manage your account details and password.';
    require __DIR__ . '/components/page-header.php';
    ?>

    <!-- Toast -->
    <div x-show="toast.show" x-transition x-cloak
         class="fixed bottom-6 right-6 z-[10001] rounded-xl px-4 py-3 text-sm font-medium text-white shadow-lg"
         :class="toast.ok ? 'bg-green-600' : 'bg-rose-600'"
         x-text="toast.msg" style="display:none;"></div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <!-- Identity summary -->
        <div class="lg:col-span-1">
            <div class="bento-card p-6 text-center">
                <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-brand-100 dark:bg-brand-900/40">
                    <span class="text-2xl font-bold text-brand-600 dark:text-brand-400" x-text="(form.first_name || '<?= e($user['name']) ?>').charAt(0).toUpperCase()"></span>
                </div>
                <h2 class="mt-4 text-lg font-bold text-gray-900 dark:text-white" x-text="(form.first_name + ' ' + form.last_name).trim() || '<?= e($user['name']) ?>'"></h2>
                <p class="text-sm text-gray-500 dark:text-gray-400" x-text="form.email"></p>
                <span class="mt-3 inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-200"><?= e($roleLabel) ?></span>
                <?php if (!empty($me['created_at'])): ?>
                    <p class="mt-4 text-xs text-gray-400 dark:text-gray-500">Member since <?= e(date('M j, Y', strtotime($me['created_at']))) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Editable details + password -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Account details -->
            <div class="bento-card p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Account details</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Update your name, email, and phone number.</p>
                <form @submit.prevent="saveProfile()" class="space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">First name <span class="text-rose-500">*</span></label>
                            <input type="text" x-model="form.first_name" required class="ta-input">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Last name <span class="text-rose-500">*</span></label>
                            <input type="text" x-model="form.last_name" required class="ta-input">
                        </div>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Email <span class="text-rose-500">*</span></label>
                        <input type="email" x-model="form.email" required class="ta-input">
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                        <input type="tel" x-model="form.phone" placeholder="Optional" class="ta-input">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="savingProfile" class="btn-primary disabled:opacity-60" x-text="savingProfile ? 'Saving…' : 'Save changes'"></button>
                    </div>
                </form>
            </div>

            <!-- Change password -->
            <div class="bento-card p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Change password</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-5">Use at least 8 characters.</p>
                <form @submit.prevent="changePassword()" class="space-y-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Current password</label>
                        <input type="password" x-model="pw.current" required class="ta-input">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">New password</label>
                            <input type="password" x-model="pw.new" required minlength="8" class="ta-input">
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Confirm new password</label>
                            <input type="password" x-model="pw.confirm" required minlength="8" class="ta-input">
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" :disabled="savingPw" class="btn-primary disabled:opacity-60" x-text="savingPw ? 'Updating…' : 'Update password'"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
window.profileApiBase = '<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>';
window.profileCsrf = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';
function profileApp() {
    return {
        form: {
            first_name: <?= json_encode($me['first_name'] ?? '') ?>,
            last_name: <?= json_encode($me['last_name'] ?? '') ?>,
            email: <?= json_encode($me['email'] ?? '') ?>,
            phone: <?= json_encode($me['phone'] ?? '') ?>,
        },
        pw: { current: '', new: '', confirm: '' },
        savingProfile: false,
        savingPw: false,
        toast: { show: false, ok: true, msg: '' },
        notify(ok, msg) {
            this.toast = { show: true, ok: ok, msg: msg };
            setTimeout(() => { this.toast.show = false; }, 3000);
        },
        async post(action, payload) {
            const r = await fetch(`${window.profileApiBase}/profile.php?action=${action}`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.profileCsrf },
                body: JSON.stringify({ ...payload, csrf_token: window.profileCsrf })
            });
            return r.json();
        },
        async saveProfile() {
            this.savingProfile = true;
            try {
                const d = await this.post('update_profile', this.form);
                this.notify(!!d.success, d.message || (d.success ? 'Saved' : 'Failed'));
            } catch (e) {
                this.notify(false, 'An error occurred');
            } finally {
                this.savingProfile = false;
            }
        },
        async changePassword() {
            if (this.pw.new !== this.pw.confirm) { this.notify(false, 'Passwords do not match'); return; }
            this.savingPw = true;
            try {
                const d = await this.post('change_password', this.pw);
                this.notify(!!d.success, d.message || (d.success ? 'Password changed' : 'Failed'));
                if (d.success) { this.pw = { current: '', new: '', confirm: '' }; }
            } catch (e) {
                this.notify(false, 'An error occurred');
            } finally {
                this.savingPw = false;
            }
        }
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
