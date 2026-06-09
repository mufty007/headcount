<?php
// Load config if not already loaded (from index.php)
if (!isset($config)) {
    $configFile = __DIR__ . '/../../config/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
    } else {
        die('Configuration file not found. Please run the installation.');
    }
}

// Use autoloader if available
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

// Define paths if not already defined
if (!defined('SRC_PATH')) {
    define('SRC_PATH', __DIR__ . '/../../src');
}

// Load helpers if not autoloaded
if (!class_exists('Headcount\Helpers\Database')) {
    require_once SRC_PATH . '/Helpers/Database.php';
}
if (!class_exists('Headcount\Helpers\Auth')) {
    require_once SRC_PATH . '/Helpers/Auth.php';
}
if (!class_exists('Headcount\Middleware\CsrfMiddleware')) {
    require_once SRC_PATH . '/Middleware/CsrfMiddleware.php';
}
if (!function_exists('e')) {
    require_once SRC_PATH . '/helpers.php';
}

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\OrganizationApiKeyService;

// Settings page is admin-only (coordinators cannot manage organization or users).
// A non-super admin may additionally be restricted from Settings via permissions.
AuthMiddleware::requireAdmin();
AuthMiddleware::requireCan('settings.access');

// Initialize database (singleton - safe to call even if already initialized)
$db = Database::getInstance($config['database']);

// Get CSRF token
$csrfToken = CsrfMiddleware::getToken();

// Calculate API base URL
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
$apiBase = $basePath . '/public/api';

// Get current user from session (AuthMiddleware already verified authentication)
$userId = AuthMiddleware::getUserId();
$organizationId = AuthMiddleware::getOrganizationId();

// Fetch user data from database to ensure we have correct information
$currentUser = $db->queryOne(
    "SELECT id, first_name, last_name, email, role, organization_id, is_super_admin FROM users WHERE id = :id LIMIT 1",
    ['id' => $userId]
);

$isSuperAdmin = AuthMiddleware::isSuperAdmin();

// Build user array with database data, fallback to session, then defaults
$user = [
    'id' => $userId,
    'name' => $currentUser ? trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? '')) : ($_SESSION['name'] ?? 'Admin'),
    'email' => $currentUser['email'] ?? $_SESSION['email'] ?? 'admin@headcount.local',
    'role' => $currentUser['role'] ?? AuthMiddleware::getRole() ?? 'admin',
    'organization_id' => $currentUser['organization_id'] ?? $organizationId,
    'is_super_admin' => !empty($currentUser['is_super_admin']),
];

// Get organization settings
$org = $db->queryOne("SELECT * FROM organizations WHERE id = ?", [$organizationId]) ?: [];
$org['api_key_configured'] = OrganizationApiKeyService::hasApiKey($db, (int) $organizationId);

// Get categories
$categories = $db->query(
    "SELECT * FROM categories WHERE organization_id = ? ORDER BY name",
    [$organizationId]
) ?: [];

// Get admin users
$admins = $db->query(
    "SELECT id, first_name, last_name, email, is_super_admin, created_at FROM users WHERE role = 'admin' AND organization_id = ? ORDER BY created_at DESC",
    [$organizationId]
) ?: [];

// Get coordinators (same organization as current user)
$coordinators = $db->query("SELECT id, first_name, last_name, email, created_at FROM users WHERE role = 'coordinator' AND organization_id = ? ORDER BY created_at DESC", [$organizationId]) ?: [];

// ---- Kiosk display settings (owner-only tab) -------------------------------
$kioskSlug = (string) ($org['slug'] ?? '');
$kioskEnabled = !array_key_exists('kiosk_enabled', $org) ? 1 : (int) $org['kiosk_enabled'];
$kioskMode = (($org['kiosk_mode'] ?? 'board') === 'slideshow') ? 'slideshow' : 'board';
$kioskDays = isset($org['kiosk_days']) ? (int) $org['kiosk_days'] : 7;
$kioskInterval = isset($org['kiosk_interval']) ? (int) $org['kiosk_interval'] : 8;
// Build the public kiosk URL from the current request so it is correct on both
// local (/Headcount/public/...) and production (docroot = public/).
$kioskScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$kioskHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$kioskPublicBase = preg_replace('#/admin(/.*)?$#', '', str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''));
$kioskPublicBase = rtrim((string) $kioskPublicBase, '/');
$kioskUrl = $kioskSlug !== ''
    ? $kioskScheme . '://' . $kioskHost . $kioskPublicBase . '/portal/kiosk.php?org=' . rawurlencode($kioskSlug)
    : '';

$pageTitle = 'Settings';
$currentPage = 'settings';
include __DIR__ . '/includes/header.php';
?>

<div x-data="settingsApp()" x-cloak>
    <?php
    $pageHeaderTitle = 'Settings';
    $pageHeaderSubtitle = 'Organization profile, payments, email delivery, categories, and team access.';
    $pageHeaderActions = '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="ta-settings-layout">
        <aside class="lg:w-64 shrink-0">
            <div class="mb-4 lg:hidden">
                <label for="settings-tab-jump" class="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-400">Section</label>
                <select id="settings-tab-jump" x-model="activeTab" class="ta-select w-full">
                    <option value="organization">Organization</option>
                    <option value="payments">Payments (Stripe)</option>
                    <option value="email">Email (SMTP)</option>
                    <option value="categories">Categories</option>
                    <option value="admins">Admin Users</option>
                    <option value="coordinators">Coordinators</option>
                    <option value="permissions">Permissions</option>
                    <?php if ($isSuperAdmin): ?><option value="kiosk">Kiosk Display</option><?php endif; ?>
                    <option value="shortcodes">Shortcodes</option>
                    <option value="system">System</option>
                </select>
            </div>
            <nav class="ta-settings-nav hidden lg:flex rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <?php
                $settingsTabs = [
                    'organization' => 'Organization',
                    'payments' => 'Payments (Stripe)',
                    'email' => 'Email (SMTP)',
                    'categories' => 'Categories',
                    'admins' => 'Admin Users',
                    'coordinators' => 'Coordinators',
                    'permissions' => 'Permissions',
                    'shortcodes' => 'Shortcodes',
                    'system' => 'System',
                ];
                // Kiosk display is owner-only; insert it just before Shortcodes.
                if ($isSuperAdmin) {
                    $settingsTabs = array_slice($settingsTabs, 0, 7, true)
                        + ['kiosk' => 'Kiosk Display']
                        + array_slice($settingsTabs, 7, null, true);
                }
                foreach ($settingsTabs as $tabKey => $tabLabel):
                ?>
                <button type="button"
                    @click="activeTab = '<?= e($tabKey) ?>'"
                    :class="activeTab === '<?= e($tabKey) ?>' ? 'active' : ''"
                    class="ta-settings-tab">
                    <?= e($tabLabel) ?>
                </button>
                <?php endforeach; ?>
            </nav>
        </aside>

        <div class="ta-settings-content">
    <!-- ORGANIZATION TAB -->
    <div x-show="activeTab === 'organization'" class="space-y-6">
        <div class="bento-card p-6">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Organization Details</h2>
                    <p class="text-gray-600 text-sm dark:text-gray-300">Basic information about your organization</p>
                </div>
                <button type="button" @click="openOrganizationModal()" class="btn-primary text-sm py-2 px-4">
                    Edit
                </button>
            </div>

            <div class="space-y-4">
                <div class="flex items-center space-x-4">
                    <?php if (!empty($org['logo_url'] ?? $org['logo_path'] ?? '')): ?>
                        <img src="<?= e($org['logo_url'] ?? $org['logo_path'] ?? '') ?>" alt="Logo" class="w-16 h-16 rounded-lg object-cover">
                    <?php else: ?>
                        <div class="w-16 h-16 bg-gray-200 rounded-lg flex items-center justify-center">
                            <svg class="w-8 h-8 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                        </div>
                    <?php endif; ?>
                    <div>
                        <div class="font-medium text-gray-800 dark:text-gray-100"><?= e($org['name'] ?? 'Organization') ?></div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Organization Name</div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 pt-4 border-t">
                    <div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Primary Color</div>
                        <div class="flex items-center space-x-2 mt-1">
                            <div class="w-8 h-8 rounded-lg border-2 border-gray-300" style="background-color: <?= e($org['primary_color'] ?? '#3B82F6') ?>"></div>
                            <span class="font-mono text-sm"><?= e($org['primary_color'] ?? '#3B82F6') ?></span>
                        </div>
                    </div>
                    <div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">Timezone</div>
                        <div class="font-medium mt-1"><?= e(\Headcount\Helpers\OrgTimeZone::resolve($org['timezone'] ?? null)) ?></div>
                    </div>
                </div>
            </div>

            <!-- Organization Branding (Logo) -->
            <div class="bento-card p-6 mt-6">
                <h2 class="text-xl font-bold text-gray-800 mb-2 dark:text-gray-100">Organization Branding</h2>
                <p class="text-gray-600 text-sm mb-4 dark:text-gray-300">Upload your logo to appear in the header of all outgoing emails. PNG, JPG, or SVG, max 2MB.</p>
                <div class="flex flex-wrap items-center gap-6">
                    <div class="flex items-center gap-4">
                        <div x-show="!orgForm.logo_url" class="w-20 h-20 bg-gray-100 rounded-lg flex items-center justify-center border border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <span class="text-gray-400 text-xs">No logo</span>
                        </div>
                        <img x-show="orgForm.logo_url" :src="orgForm.logo_url" alt="Logo" class="w-20 h-20 rounded-lg object-contain border border-gray-200 dark:border-gray-700">
                    </div>
                    <div class="flex flex-col gap-2">
                        <input type="file" @change="uploadLogo($event)" accept=".png,.jpg,.jpeg,.svg,image/png,image/jpeg,image/svg+xml" class="text-sm text-gray-600 file:mr-2 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 dark:text-gray-300">
                        <p class="text-xs text-gray-500 dark:text-gray-400">Max 2MB. Logo appears in email headers.</p>
                        <button type="button" @click="removeLogo()" x-show="orgForm.logo_url" class="text-sm text-rose-600 hover:text-rose-700 font-medium">Remove logo</button>
                        <button type="button" @click="showEmailPreview = true" class="text-sm text-brand-600 hover:text-brand-700 font-medium">Preview in email</button>
                    </div>
                </div>
            </div>

            <!-- Email preview modal -->
            <div x-show="showEmailPreview" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showEmailPreview = false">
                <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showEmailPreview = false"></div>
                <div class="relative flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700">
                    <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center dark:border-gray-700">
                        <h3 class="font-bold text-gray-900 dark:text-white">Email preview</h3>
                        <button type="button" @click="showEmailPreview = false" class="rounded-lg px-3 py-1.5 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:bg-gray-800 dark:text-white">Close</button>
                    </div>
                    <div class="p-4 overflow-auto flex-1 bg-gray-100 dark:bg-gray-800">
                        <div class="mx-auto max-w-[600px] rounded-xl border border-gray-200 bg-white font-sans text-sm shadow-card dark:bg-gray-800 dark:border-gray-700" style="max-width: 600px;">
                            <div x-show="orgForm.logo_url || orgForm.name" class="p-4 border-b border-gray-200 bg-gray-50 dark:bg-gray-800 dark:border-gray-700">
                                <img x-show="orgForm.logo_url" :src="orgForm.logo_url" alt="Logo" class="max-h-12 max-w-[200px] block mb-2">
                                <p x-show="orgForm.name" class="text-gray-700 mt-1 dark:text-gray-200" x-text="orgForm.name || ''"></p>
                            </div>
                            <div class="p-6">
                                <p class="text-gray-700 dark:text-gray-200">This is how your logo and organization name will appear in outgoing emails. Sample body text goes here.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- PAYMENTS TAB -->
    <div x-show="activeTab === 'payments'" class="space-y-6">
        <div class="bento-card p-6">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Stripe Integration</h2>
                    <p class="text-gray-600 text-sm dark:text-gray-300">Configure payment processing for paid events</p>
                </div>
                <button type="button" @click="openStripeModal()" class="btn-primary text-sm py-2 px-4">
                    Configure
                </button>
            </div>

            <?php if (!empty($org['stripe_publishable_key'])): ?>
                <div class="space-y-4">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="font-medium text-green-700">Stripe Connected</span>
                    </div>

                    <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                        <div class="text-sm text-gray-600 mb-2 dark:text-gray-300">Publishable Key</div>
                        <div class="font-mono text-sm text-gray-800 break-all dark:text-gray-100">
                            <?= e(substr($org['stripe_publishable_key'] ?? '', 0, 20)) ?>...
                        </div>
                    </div>

                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        Secret key is encrypted and hidden for security.
                    </div>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                    </svg>
                    <p class="text-gray-600 mb-4 dark:text-gray-300">Stripe is not configured</p>
                    <button @click="openStripeModal()" class="text-brand-600 hover:text-brand-800 font-medium">
                        Set up Stripe â†’
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- EMAIL TAB -->
    <div x-show="activeTab === 'email'" class="space-y-6">
        <div class="bento-card p-6">
            <div class="flex justify-between items-start mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Email Configuration (SMTP2GO)</h2>
                    <p class="text-gray-600 text-sm dark:text-gray-300">Configure email delivery for event notifications</p>
                </div>
                <button @click="openEmailModal()" class="btn-primary text-sm py-2 px-4">
                    Configure
                </button>
            </div>

            <?php if (!empty($org['smtp_api_key']) || !empty($org['smtp_api_key_encrypted'] ?? null)): ?>
                <div class="space-y-4">
                    <div class="flex items-center space-x-2">
                        <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        <span class="font-medium text-green-700">Email Configured</span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                            <div class="text-sm text-gray-600 mb-1 dark:text-gray-300">From Email</div>
                            <div class="font-medium text-gray-800 dark:text-gray-100"><?= e($org['smtp_from_email'] ?? '') ?></div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                            <div class="text-sm text-gray-600 mb-1 dark:text-gray-300">From Name</div>
                            <div class="font-medium text-gray-800 dark:text-gray-100"><?= e($org['smtp_from_name'] ?? '') ?></div>
                        </div>
                    </div>

                    <button @click="sendTestEmail()" class="text-brand-600 hover:text-brand-800 text-sm font-medium">
                        Send Test Email
                    </button>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                    </svg>
                    <p class="text-gray-600 mb-4 dark:text-gray-300">Email is not configured</p>
                    <button @click="openEmailModal()" class="text-brand-600 hover:text-brand-800 font-medium">
                        Set up Email â†’
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- CATEGORIES TAB -->
    <div x-show="activeTab === 'categories'" class="space-y-6">
        <div class="bento-card">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Event Categories</h2>
                        <p class="text-gray-600 text-sm dark:text-gray-300">Manage categories for organizing events</p>
                    </div>
                    <button @click="openCategoryModal()" class="btn-primary text-sm py-2 px-4">
                        + Add Category
                    </button>
                </div>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($categories as $category): ?>
                    <div class="p-4 hover:bg-gray-50 flex justify-between items-center dark:bg-gray-800">
                        <div class="flex items-center space-x-3">
                            <div class="h-3 w-3 rounded-full bg-brand-500"></div>
                            <span class="font-medium text-gray-800 dark:text-gray-100"><?= e($category['name']) ?></span>
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button"
                                    @click='openEditCategoryModal(<?= json_encode(['id' => (int)$category['id'], 'name' => $category['name']], JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                    class="text-brand-600 hover:text-brand-800 text-sm font-semibold">
                                Edit
                            </button>
                            <button type="button"
                                    @click="deleteCategory(<?= (int)$category['id'] ?>, <?= htmlspecialchars(json_encode($category['name']), ENT_QUOTES, 'UTF-8') ?>)"
                                    class="text-red-600 hover:text-red-800 text-sm">
                                Delete
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ADMIN USERS TAB -->
    <div x-show="activeTab === 'admins'" class="space-y-6">
        <div class="bento-card">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Administrator Accounts</h2>
                        <p class="text-gray-600 text-sm dark:text-gray-300">Manage users with admin access</p>
                    </div>
                    <button @click="openAdminModal()" class="btn-primary text-sm py-2 px-4">
                        + Add Admin
                    </button>
                </div>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($admins as $admin): ?>
                    <div class="p-4 hover:bg-gray-50 dark:bg-gray-800">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-medium text-gray-800 dark:text-gray-100">
                                    <?= e($admin['first_name'] . ' ' . $admin['last_name']) ?>
                                    <?php if (isset($admin['id'], $user['id']) && $admin['id'] == $user['id']): ?>
                                        <span class="text-xs text-brand-600">(You)</span>
                                    <?php endif; ?>
                                    <?php if (!empty($admin['is_super_admin'])): ?>
                                        <span class="ml-1 px-2 py-0.5 text-xs bg-amber-100 text-amber-800 rounded dark:bg-amber-900/40 dark:text-amber-200">Owner</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300"><?= e($admin['email'] ?? '') ?></div>
                                <div class="text-xs text-gray-500 mt-1 dark:text-gray-400">
                                    Added <?= formatDate($admin['created_at'] ?? '') ?>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 sm:flex-row sm:items-center sm:gap-3 shrink-0">
                                <button type="button"
                                        @click='openEditUserModal(<?= json_encode([
                                            'id' => (int)$admin['id'],
                                            'first_name' => $admin['first_name'],
                                            'last_name' => $admin['last_name'],
                                            'email' => $admin['email'],
                                            'role' => 'admin',
                                            'is_super_admin' => (int)($admin['is_super_admin'] ?? 0),
                                        ], JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                        class="text-brand-600 hover:text-brand-800 text-sm font-semibold">
                                    Edit
                                </button>
                                <?php if (isset($admin['id'], $user['id']) && $admin['id'] != $user['id'] && count($admins) > 1 && empty($admin['is_super_admin'])): ?>
                                    <button type="button"
                                            @click="deleteAdmin(<?= (int)$admin['id'] ?>, <?= htmlspecialchars(json_encode($admin['first_name']), ENT_QUOTES, 'UTF-8') ?>)"
                                            class="text-red-600 hover:text-red-800 text-sm">
                                        Remove
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Change Password -->
        <div class="bento-card p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 dark:text-gray-100">Change Your Password</h3>
            <button type="button" @click="openPasswordModal()" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200">
                Change Password
            </button>
        </div>
    </div>

    <!-- COORDINATORS TAB -->
    <div x-show="activeTab === 'coordinators'" class="space-y-6">
        <div class="bento-card">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex justify-between items-start">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Event Coordinators</h2>
                        <p class="text-gray-600 text-sm dark:text-gray-300">Coordinators can check in attendees, view attendance, and add new members at events. You can promote a coordinator to a full administrator at any time.</p>
                    </div>
                    <button @click="openCoordinatorModal()" class="btn-primary text-sm py-2 px-4">
                        + Add Coordinator
                    </button>
                </div>
            </div>

            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <?php foreach ($coordinators as $coord): ?>
                    <div class="p-4 hover:bg-gray-50 dark:bg-gray-800">
                        <div class="flex justify-between items-start">
                            <div>
                                <div class="font-medium text-gray-800 dark:text-gray-100">
                                    <?= e($coord['first_name'] . ' ' . $coord['last_name']) ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-300"><?= e($coord['email'] ?? '') ?></div>
                                <div class="text-xs text-gray-500 mt-1 dark:text-gray-400">
                                    Added <?= formatDate($coord['created_at'] ?? '') ?>
                                </div>
                            </div>
                            <div class="flex flex-col items-end gap-1 sm:flex-row sm:items-center sm:gap-3 shrink-0">
                                <button type="button"
                                        @click='openEditUserModal(<?= json_encode([
                                            'id' => (int)$coord['id'],
                                            'first_name' => $coord['first_name'],
                                            'last_name' => $coord['last_name'],
                                            'email' => $coord['email'],
                                            'role' => 'coordinator',
                                            'is_super_admin' => 0,
                                        ], JSON_HEX_APOS | JSON_HEX_TAG) ?>)'
                                        class="text-brand-600 hover:text-brand-800 text-sm font-semibold">
                                    Edit
                                </button>
                                <button type="button"
                                        @click="promoteCoordinatorToAdmin(<?= (int)$coord['id'] ?>, <?= htmlspecialchars(json_encode(trim($coord['first_name'] . ' ' . $coord['last_name'])), ENT_QUOTES, 'UTF-8') ?>)"
                                        class="text-brand-600 hover:text-brand-800 text-sm font-semibold">
                                    Make admin
                                </button>
                                <button type="button"
                                        @click="deleteCoordinator(<?= (int)$coord['id'] ?>, <?= htmlspecialchars(json_encode($coord['first_name']), ENT_QUOTES, 'UTF-8') ?>)"
                                        class="text-red-600 hover:text-red-800 text-sm">
                                    Remove
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($coordinators)): ?>
                    <div class="p-6 text-center text-gray-500 text-sm dark:text-gray-400">
                        No coordinators yet. Add one to allow them to check in attendees at events.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SHORTCODES TAB -->
    <div x-show="activeTab === 'shortcodes'" class="space-y-6">
        <div class="bento-card p-6">
            <div class="mb-6">
                <h2 class="text-xl font-bold text-gray-800 mb-2 dark:text-gray-100">WordPress Shortcodes</h2>
                <p class="text-gray-600 text-sm dark:text-gray-300">Use these shortcodes in your WordPress site to display your published events</p>
            </div>

            <!-- API Key Section -->
            <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50/80 p-4">
                <div class="flex items-start justify-between">
                    <div class="flex-1">
                        <h3 class="mb-2 font-medium text-brand-900">Your API Key</h3>
                        <p class="mb-3 text-sm text-brand-900/80">Use this API key in your WordPress shortcode plugin to authenticate requests.</p>
                        <div class="mb-3 rounded-lg border border-brand-200 bg-white p-3 dark:bg-gray-800">
                            <code class="text-sm font-mono text-gray-800 break-all dark:text-gray-100" id="apiKeyDisplay"><?= !empty($org['api_key_configured']) ? '•••••••• (configured — generate new key to view)' : 'Not generated yet' ?></code>
                        </div>
                        <button type="button" @click="generateApiKey()" class="btn-primary text-sm py-2 px-4">
                            <span x-show="!generatingKey">Generate New API Key</span>
                            <span x-show="generatingKey">Generating...</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Shortcodes Table -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Shortcode</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Description</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Example</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-800 dark:divide-gray-700">
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <code class="text-sm font-mono bg-gray-100 px-2 py-1 rounded dark:bg-gray-800">[headcount_events]</code>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                                Display all published upcoming events
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                <code class="text-xs">[headcount_events]</code>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="copyShortcode(shortcodes[0])" class="text-brand-600 hover:text-brand-800 text-sm">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <code class="text-sm font-mono bg-gray-100 px-2 py-1 rounded dark:bg-gray-800">[headcount_events limit="5"]</code>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                                Display limited number of events
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                <code class="text-xs">[headcount_events limit="5"]</code>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="copyShortcode(shortcodes[1])" class="text-brand-600 hover:text-brand-800 text-sm">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <code class="text-sm font-mono bg-gray-100 px-2 py-1 rounded dark:bg-gray-800">[headcount_events category="youth"]</code>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                                Display events by category
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                <code class="text-xs">[headcount_events category="youth"]</code>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="copyShortcode(shortcodes[2])" class="text-brand-600 hover:text-brand-800 text-sm">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <code class="text-sm font-mono bg-gray-100 px-2 py-1 rounded dark:bg-gray-800">[headcount_event id="123"]</code>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                                Display a specific event by ID
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                <code class="text-xs">[headcount_event id="123"]</code>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="copyShortcode(shortcodes[3])" class="text-brand-600 hover:text-brand-800 text-sm">Copy</button>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <code class="text-sm font-mono bg-gray-100 px-2 py-1 rounded dark:bg-gray-800">[headcount_events layout="grid"]</code>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-200">
                                Display events in grid layout (options: list, grid, calendar)
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300">
                                <code class="text-xs">[headcount_events layout="grid"]</code>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <button @click="copyShortcode(shortcodes[4])" class="text-brand-600 hover:text-brand-800 text-sm">Copy</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- WordPress Plugin Instructions -->
            <div class="mt-6 bg-gray-50 rounded-lg p-6 dark:bg-gray-800">
                <h3 class="font-medium text-gray-800 mb-3 dark:text-gray-100">WordPress Plugin Setup</h3>
                <ol class="list-decimal list-inside space-y-2 text-sm text-gray-700 dark:text-gray-200">
                    <li>Install a shortcode plugin that supports custom shortcodes (or add to your theme's functions.php)</li>
                    <li>Use the API key above to authenticate requests from WordPress</li>
                    <li>Copy any shortcode from the table above and paste it into your WordPress page or post</li>
                    <li>The shortcode will fetch and display your published events from this Headcount system</li>
                </ol>
                <div class="mt-4 p-4 bg-white rounded border border-gray-300 dark:bg-gray-800">
                    <p class="text-sm font-medium text-gray-800 mb-2 dark:text-gray-100">API Base URL (for WordPress plugin):</p>
                    <code class="text-xs font-mono bg-gray-100 px-2 py-1 rounded break-all dark:bg-gray-800"><?= e($basePath . '/api') ?></code>
                    <p class="text-xs text-gray-600 mt-2 dark:text-gray-300">Enter this URL in your WordPress plugin settings. The plugin will automatically append <code>/public-events.php</code> when making requests.</p>
                    <p class="text-xs text-gray-500 mt-2 dark:text-gray-400">Full endpoint: <code><?= e($basePath . '/api/public-events.php') ?></code></p>
                </div>
            </div>
        </div>
    </div>

    <!-- SYSTEM TAB -->
    <div x-show="activeTab === 'system'" class="space-y-6">
        <!-- System Information -->
        <div class="bento-card p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 dark:text-gray-100">System Information</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                    <div class="text-sm text-gray-600 mb-1 dark:text-gray-300">Application Version</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">1.0.0</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                    <div class="text-sm text-gray-600 mb-1 dark:text-gray-300">PHP Version</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100"><?= phpversion() ?></div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                    <div class="text-sm text-gray-600 mb-1 dark:text-gray-300">Database</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100">MySQL</div>
                </div>
                <div class="bg-gray-50 rounded-lg p-4 dark:bg-gray-800">
                    <div class="text-sm text-gray-600 mb-1 dark:text-gray-300">Timezone</div>
                    <div class="font-medium text-gray-800 dark:text-gray-100"><?= date_default_timezone_get() ?></div>
                </div>
            </div>
        </div>

        <!-- Database Backup -->
        <div class="bento-card p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 dark:text-gray-100">Database Backup</h2>
            <p class="text-gray-600 text-sm mb-4 dark:text-gray-300">Export your database for backup purposes</p>
            <button @click="downloadBackup()" class="btn-primary text-sm py-2 px-4">
                Download Backup
            </button>
        </div>

        <!-- Danger Zone -->
        <div class="rounded-xl border border-red-200 bg-red-50 p-6 dark:border-red-800/40 dark:bg-red-950/20">
            <h2 class="text-xl font-bold text-red-800 dark:text-red-300 mb-2">Danger Zone</h2>
            <p class="text-red-600 dark:text-red-400 text-sm mb-4">Irreversible and destructive actions</p>
            <button @click="clearAllData()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm">
                Clear All Event Data
            </button>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <!-- KIOSK DISPLAY TAB (owner only) -->
    <div x-show="activeTab === 'kiosk'" class="space-y-6">
        <div class="bento-card p-6">
            <div class="mb-1 flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Kiosk Display</h2>
                    <p class="text-gray-600 text-sm dark:text-gray-300">A full-screen public board of upcoming events for a lobby TV or kiosk. No login required.</p>
                </div>
                <span class="shrink-0 rounded-full px-3 py-1 text-xs font-semibold"
                      :class="kioskForm.enabled ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'"
                      x-text="kioskForm.enabled ? 'Live' : 'Off'"></span>
            </div>

            <?php if ($kioskUrl === ''): ?>
                <p class="mt-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    Your organization has no slug set, so a public kiosk URL can't be generated. Set an organization slug first.
                </p>
            <?php else: ?>
                <!-- Public URL -->
                <div class="mt-5">
                    <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Public display link</label>
                    <div class="flex flex-col gap-2 sm:flex-row">
                        <input type="text" readonly id="kioskUrlInput" value="<?= e($kioskUrl) ?>"
                               class="w-full select-all rounded-lg border border-gray-300 bg-gray-50 px-4 py-2 font-mono text-sm text-gray-700 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                        <button type="button" @click="copyKioskUrl()" class="btn-secondary shrink-0 text-sm py-2 px-4" x-text="kioskCopied ? 'Copied!' : 'Copy'"></button>
                    </div>
                    <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Open this on any screen. Anyone with the link can view your published events.</p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="<?= e($kioskUrl) ?>&mode=board" target="_blank" rel="noopener" class="btn-secondary text-sm py-2 px-4">Open board ↗</a>
                        <a href="<?= e($kioskUrl) ?>&mode=slideshow" target="_blank" rel="noopener" class="btn-secondary text-sm py-2 px-4">Open slideshow ↗</a>
                    </div>
                    <p class="text-xs text-gray-400 mt-2 dark:text-gray-500">On the display, press <kbd class="rounded border border-gray-300 px-1 dark:border-gray-600">F</kbd> for fullscreen and <kbd class="rounded border border-gray-300 px-1 dark:border-gray-600">M</kbd> to switch layouts.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Settings form -->
        <div class="bento-card p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4 dark:text-gray-100">Display settings</h3>
            <form @submit.prevent="saveKiosk()" class="space-y-5">
                <label class="flex items-center gap-3">
                    <input type="checkbox" x-model="kioskForm.enabled" class="h-5 w-5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                    <span class="text-gray-700 font-medium dark:text-gray-200">Enable the public kiosk display</span>
                </label>

                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Default layout</label>
                        <select x-model="kioskForm.mode" class="ta-select w-full">
                            <option value="board">Board (grid)</option>
                            <option value="slideshow">Slideshow (one at a time)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Days ahead</label>
                        <input type="number" min="1" max="60" x-model.number="kioskForm.days"
                               class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    </div>
                    <div :class="kioskForm.mode === 'slideshow' ? '' : 'opacity-50'">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Slideshow seconds</label>
                        <input type="number" min="3" max="60" x-model.number="kioskForm.interval" :disabled="kioskForm.mode !== 'slideshow'"
                               class="w-full rounded-lg border border-gray-300 px-4 py-2 focus:border-brand-500 focus:outline-none dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200">
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="btn-primary text-sm py-2 px-5" :disabled="kioskSaving" x-text="kioskSaving ? 'Saving…' : 'Save settings'"></button>
                    <span x-show="kioskSaved" x-transition class="text-sm font-medium text-emerald-600 dark:text-emerald-400">Saved</span>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- PERMISSIONS TAB -->
    <div x-show="activeTab === 'permissions'" class="space-y-6">
        <!-- Role defaults -->
        <div class="bento-card">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100">Role defaults</h2>
                        <p class="text-gray-600 text-sm dark:text-gray-300">Baseline capabilities for every admin and coordinator. Adjust an individual person below.</p>
                    </div>
                    <span x-show="permMsg" x-text="permMsg" x-cloak class="shrink-0 rounded-full bg-green-50 px-3 py-1 text-xs font-semibold text-green-700 dark:bg-green-950/30 dark:text-green-400"></span>
                </div>
            </div>
            <div x-show="permLoading" class="p-6 text-sm text-gray-500 dark:text-gray-400">Loading permissions…</div>
            <div x-show="!permLoading" class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-800">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Capability</th>
                            <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Admin</th>
                            <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Coordinator</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <template x-for="group in permCatalog" :key="group.label">
                            <template x-for="perm in group.permissions" :key="perm.key">
                                <tr>
                                    <td class="px-6 py-3">
                                        <div class="font-medium text-gray-800 dark:text-gray-100" x-text="perm.label"></div>
                                        <div class="text-xs text-gray-400 dark:text-gray-500" x-text="group.label"></div>
                                    </td>
                                    <td class="px-6 py-3 text-center">
                                        <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500" :checked="roleEffective('admin', perm.key)" @change="toggleRole('admin', perm.key)">
                                    </td>
                                    <td class="px-6 py-3 text-center">
                                        <input type="checkbox" class="h-4 w-4 rounded border-gray-300 text-brand-600 focus:ring-brand-500" :checked="roleEffective('coordinator', perm.key)" @change="toggleRole('coordinator', perm.key)">
                                    </td>
                                </tr>
                            </template>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Individual overrides -->
        <div class="bento-card p-6">
            <h2 class="text-xl font-bold text-gray-800 dark:text-gray-100 mb-1">Individual people</h2>
            <p class="text-gray-600 text-sm dark:text-gray-300 mb-4">Grant or revoke specific capabilities for one admin or coordinator. Overrides win over the role default.</p>

            <div class="max-w-sm mb-6">
                <label class="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1.5">Select a person</label>
                <select class="ta-select w-full" x-model="selectedUserId">
                    <option value="">— Choose —</option>
                    <template x-for="u in permUsers" :key="u.id">
                        <option :value="u.id" x-text="u.name + ' (' + u.role + ')' + (u.is_super_admin ? ' — Owner' : '')"></option>
                    </template>
                </select>
            </div>

            <template x-if="selectedUser() && selectedUser().is_super_admin">
                <div class="ta-alert ta-alert-warning">This is the organization owner. They always have full access and cannot be restricted.</div>
            </template>

            <template x-if="selectedUser() && !selectedUser().is_super_admin">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Capability</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400">Access</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <template x-for="group in permCatalog" :key="group.label">
                                <template x-for="perm in group.permissions" :key="perm.key">
                                    <tr>
                                        <td class="px-4 py-3">
                                            <div class="font-medium text-gray-800 dark:text-gray-100" x-text="perm.label"></div>
                                            <div class="text-xs text-gray-400 dark:text-gray-500">Role default: <span x-text="userInheritedLabel(perm.key)"></span></div>
                                        </td>
                                        <td class="px-4 py-3">
                                            <select class="ta-select" :value="userOverrideState(perm.key)" @change="setUserOverride(perm.key, $event.target.value)">
                                                <option value="inherit">Inherit role default</option>
                                                <option value="allow">Allow</option>
                                                <option value="deny">Deny</option>
                                            </select>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>

        </div><!-- /.ta-settings-content -->
    </div><!-- /.ta-settings-layout -->

    <!-- ORGANIZATION MODAL -->
     <div x-show="showOrgModal" 
          x-cloak
          @keydown.escape.window="showOrgModal = false"
          class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
          style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showOrgModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-2xl rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Organization Settings</h3>
                    <button type="button" @click="showOrgModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="saveOrganization()" class="p-6">
                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Organization Name</label>
                        <input 
                            type="text" 
                            x-model="orgForm.name"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Logo URL (optional)</label>
                        <input 
                            type="url" 
                            x-model="orgForm.logo_url"
                            placeholder="https://example.com/logo.png"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                        >
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Enter a URL to your logo image</p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Primary Color</label>
                        <div class="flex items-center space-x-3">
                            <input 
                                type="color" 
                                x-model="orgForm.primary_color"
                                class="h-10 w-20 border border-gray-300 rounded cursor-pointer"
                            >
                            <input 
                                type="text" 
                                x-model="orgForm.primary_color"
                                placeholder="#3B82F6"
                                class="flex-1 border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500 font-mono"
                            >
                        </div>
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Timezone</label>
                        <select 
                            x-model="orgForm.timezone"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                        >
                            <option value="America/New_York">Eastern Time (ET)</option>
                            <option value="America/Indiana/Indianapolis">Indiana — Indianapolis (ET)</option>
                            <option value="America/Chicago">Central Time (CT)</option>
                            <option value="America/Denver">Mountain Time (MT)</option>
                            <option value="America/Los_Angeles">Pacific Time (PT)</option>
                            <option value="America/Phoenix">Arizona (MST)</option>
                            <option value="America/Anchorage">Alaska Time (AKT)</option>
                            <option value="Pacific/Honolulu">Hawaii Time (HT)</option>
                        </select>
                    </div>

                    <div class="mb-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <h4 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3 dark:text-gray-400">RSVP &amp; Registration Waiver</h4>
                        <div class="mb-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="orgForm.rsvp_waiver_enabled" class="rounded border-gray-300">
                                <span class="text-gray-700 dark:text-gray-200">Require liability waiver on event RSVPs and program registration</span>
                            </label>
                            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Members and guests must accept the waiver before they can RSVP or register.</p>
                        </div>
                        <div class="mb-4" x-show="orgForm.rsvp_waiver_enabled" x-cloak>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Checkbox label</label>
                            <input type="text" maxlength="500"
                                x-model="orgForm.rsvp_waiver_checkbox_label"
                                placeholder="I agree to the liability waiver and release"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500">
                        </div>
                        <div class="mb-4" x-show="orgForm.rsvp_waiver_enabled" x-cloak>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Full waiver text</label>
                            <textarea rows="10"
                                x-model="orgForm.rsvp_waiver_full_text"
                                placeholder="Full legal waiver shown in the read-more modal..."
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 text-sm focus:outline-none focus:border-brand-500 font-mono"></textarea>
                            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Attendees can open this text from a &ldquo;Read full waiver&rdquo; link before accepting.</p>
                        </div>
                    </div>

                    <div class="mb-6 border-t border-gray-200 pt-4 dark:border-gray-700">
                        <h4 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3 dark:text-gray-400">Payments &amp; Refunds</h4>
                        <div class="mb-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="orgForm.coordinators_can_refund" class="rounded border-gray-300">
                                <span class="text-gray-700 dark:text-gray-200">Allow coordinators to process refunds</span>
                            </label>
                            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">When unchecked, only admins can approve refunds or issue refunds from Payments.</p>
                        </div>
                        <div class="mb-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="orgForm.coordinators_can_correct_checkins" class="rounded border-gray-300">
                                <span class="text-gray-700 dark:text-gray-200">Allow coordinators to correct attendance after events</span>
                            </label>
                            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">When unchecked, only admins can add, remove, or edit check-ins on past events from Event details.</p>
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Refund request window (days after event)</label>
                            <input type="number" min="0" step="1" placeholder="No limit"
                                x-model="orgForm.refund_request_days_after_event"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500">
                            <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Leave empty for no limit. Users can only submit refund requests within this many days after the event date.</p>
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Saving...' : 'Save Changes'"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showOrgModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- STRIPE MODAL -->
    <div x-show="showStripeModal" 
         x-cloak
         @keydown.escape.window="showStripeModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showStripeModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-2xl rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Stripe Configuration</h3>
                    <button type="button" @click="showStripeModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="saveStripe()" class="p-6">
                    <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50/80 p-4">
                        <p class="text-sm text-brand-900/90">
                            Get your Stripe API keys from your <a href="https://dashboard.stripe.com/apikeys" target="_blank" class="font-medium text-brand-700 underline hover:text-brand-900">Stripe Dashboard</a>
                        </p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Publishable Key</label>
                        <input 
                            type="text" 
                            x-model="stripeForm.publishable_key"
                            placeholder="pk_live_..."
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500 font-mono text-sm"
                            required
                        >
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Secret Key</label>
                        <input 
                            type="password" 
                            x-model="stripeForm.secret_key"
                            placeholder="sk_live_..."
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500 font-mono text-sm"
                            required
                        >
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Your secret key will be encrypted and stored securely</p>
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Saving...' : 'Save Configuration'"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showStripeModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- EMAIL MODAL -->
    <div x-show="showEmailModal" 
         x-cloak
         @keydown.escape.window="showEmailModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showEmailModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-2xl rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Email Configuration</h3>
                    <button type="button" @click="showEmailModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="saveEmail()" class="p-6">
                    <div class="mb-6 rounded-xl border border-brand-200 bg-brand-50/80 p-4">
                        <p class="text-sm text-brand-900/90">
                            Sign up for a free SMTP2GO account at <a href="https://www.smtp2go.com" target="_blank" class="font-medium text-brand-700 underline hover:text-brand-900">smtp2go.com</a>
                        </p>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">SMTP2GO API Key</label>
                        <input 
                            type="text" 
                            x-model="emailForm.api_key"
                            placeholder="api-..."
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500 font-mono text-sm"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">From Email</label>
                        <input 
                            type="email" 
                            x-model="emailForm.from_email"
                            placeholder="events@yourchurch.org"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">From Name</label>
                        <input 
                            type="text" 
                            x-model="emailForm.from_name"
                            placeholder="Your Church Events"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Saving...' : 'Save Configuration'"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showEmailModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- CATEGORY MODAL -->
    <div x-show="showCategoryModal" 
         x-cloak
         @keydown.escape.window="showCategoryModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showCategoryModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100" x-text="categoryForm.id ? 'Edit Category' : 'Add Category'"></h3>
                    <button type="button" @click="showCategoryModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="saveCategory()" class="p-6">
                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Category Name</label>
                        <input 
                            type="text" 
                            x-model="categoryForm.name"
                            placeholder="e.g., Youth Events"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Saving...' : (categoryForm.id ? 'Save Changes' : 'Add Category')"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showCategoryModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- EDIT USER MODAL -->
    <div x-show="showEditUserModal"
         x-cloak
         @keydown.escape.window="showEditUserModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">

        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showEditUserModal = false" style="z-index: 1;"></div>

        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;" @click.stop>

                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Edit Team Member</h3>
                    <button type="button" @click="showEditUserModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <form @submit.prevent="saveEditUser()" class="p-6">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">First Name</label>
                            <input
                                type="text"
                                x-model="editUserForm.first_name"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                                required
                            >
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Last Name</label>
                            <input
                                type="text"
                                x-model="editUserForm.last_name"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Email</label>
                        <input
                            type="email"
                            x-model="editUserForm.email"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Role</label>
                        <div class="grid grid-cols-2 gap-2" role="group" aria-label="Role">
                            <button
                                type="button"
                                @click="setEditUserRole('admin')"
                                :disabled="isRoleChangeDisabled()"
                                :class="editUserForm.role === 'admin'
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 ring-2 ring-brand-500/30 dark:bg-brand-900/30 dark:text-brand-200'
                                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                                class="rounded-lg border px-4 py-2.5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Administrator
                            </button>
                            <button
                                type="button"
                                @click="setEditUserRole('coordinator')"
                                :disabled="isRoleChangeDisabled()"
                                :class="editUserForm.role === 'coordinator'
                                    ? 'border-brand-500 bg-brand-50 text-brand-700 ring-2 ring-brand-500/30 dark:bg-brand-900/30 dark:text-brand-200'
                                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700'"
                                class="rounded-lg border px-4 py-2.5 text-sm font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                Coordinator
                            </button>
                        </div>
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400" x-show="editUserForm.id === currentUserId">You cannot change your own role.</p>
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400" x-show="Number(editUserForm.is_super_admin) === 1">Transfer ownership before changing the owner's role.</p>
                    </div>

                    <template x-if="Number(editUserForm.is_super_admin) === 1">
                        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-200">
                            This person is the organization owner and always has full access.
                        </div>
                    </template>

                    <template x-if="isSuperAdmin && editUserForm.role === 'admin' && Number(editUserForm.is_super_admin) !== 1 && editUserForm.id !== currentUserId">
                        <div class="mb-6">
                            <button
                                type="button"
                                @click="transferOwnership(editUserForm.id)"
                                class="w-full rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition-colors hover:bg-amber-100 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200 dark:hover:bg-amber-900/50"
                            >
                                Transfer ownership to this person
                            </button>
                        </div>
                    </template>

                    <div class="flex gap-4">
                        <button
                            type="submit"
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Saving...' : 'Save Changes'"
                        >
                        </button>
                        <button
                            type="button"
                            @click="showEditUserModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- ADD ADMIN MODAL -->
    <div x-show="showAdminModal" 
         x-cloak
         @keydown.escape.window="showAdminModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showAdminModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Add Administrator</h3>
                    <button type="button" @click="showAdminModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="saveAdmin()" class="p-6">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">First Name</label>
                            <input 
                                type="text" 
                                x-model="adminForm.first_name"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                                required
                            >
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Last Name</label>
                            <input 
                                type="text" 
                                x-model="adminForm.last_name"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Email</label>
                        <input 
                            type="email" 
                            x-model="adminForm.email"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Password</label>
                        <input 
                            type="password" 
                            x-model="adminForm.password"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                            minlength="8"
                        >
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Minimum 8 characters</p>
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Creating...' : 'Create Admin'"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showAdminModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- ADD COORDINATOR MODAL -->
    <div x-show="showCoordinatorModal" 
         x-cloak
         @keydown.escape.window="showCoordinatorModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showCoordinatorModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Add Coordinator</h3>
                    <button type="button" @click="showCoordinatorModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="saveCoordinator()" class="p-6">
                    <div class="grid grid-cols-2 gap-4 mb-4">
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">First Name</label>
                            <input 
                                type="text" 
                                x-model="coordinatorForm.first_name"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                                required
                            >
                        </div>
                        <div>
                            <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Last Name</label>
                            <input 
                                type="text" 
                                x-model="coordinatorForm.last_name"
                                class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                                required
                            >
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Email</label>
                        <input 
                            type="email" 
                            x-model="coordinatorForm.email"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Password</label>
                        <input 
                            type="password" 
                            x-model="coordinatorForm.password"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                            minlength="8"
                        >
                        <p class="text-sm text-gray-500 mt-1 dark:text-gray-400">Minimum 8 characters</p>
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Creating...' : 'Create Coordinator'"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showCoordinatorModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div x-show="showPasswordModal" 
         x-cloak
         @keydown.escape.window="showPasswordModal = false"
         class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
         style="display: none; z-index: 10000;">
        
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity" @click="showPasswordModal = false" style="z-index: 1;"></div>
        
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" style="z-index: 2; position: relative;">
                
                <div class="flex items-center justify-between border-b border-gray-200 p-6 dark:border-gray-700">
                    <h3 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Change Password</h3>
                    <button type="button" @click="showPasswordModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="changePassword()" class="p-6">
                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Current Password</label>
                        <input 
                            type="password" 
                            x-model="passwordForm.current"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                        >
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">New Password</label>
                        <input 
                            type="password" 
                            x-model="passwordForm.new"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                            minlength="8"
                        >
                    </div>

                    <div class="mb-6">
                        <label class="block text-gray-700 font-medium mb-2 dark:text-gray-200">Confirm New Password</label>
                        <input 
                            type="password" 
                            x-model="passwordForm.confirm"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:border-brand-500"
                            required
                            minlength="8"
                        >
                    </div>

                    <div class="flex gap-4">
                        <button 
                            type="submit" 
                            :disabled="saving"
                            class="btn-primary py-2 px-6 disabled:opacity-50"
                            x-text="saving ? 'Changing...' : 'Change Password'"
                        >
                        </button>
                        <button 
                            type="button"
                            @click="showPasswordModal = false"
                            class="rounded-lg border border-gray-300 bg-white px-6 py-2 font-semibold text-gray-700 shadow-sm transition-colors hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>

</div>

<script>
// Ensure these are available globally before Alpine initializes
window.csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';
window.apiBaseUrl = '<?php echo htmlspecialchars($apiBase); ?>';

function settingsApp() {
    return {
        activeTab: 'organization',
        saving: false,
        
        // Modals
        showOrgModal: false,
        showStripeModal: false,
        showEmailModal: false,
        showEmailPreview: false,
        showCategoryModal: false,
        showAdminModal: false,
        showCoordinatorModal: false,
        showEditUserModal: false,
        showPasswordModal: false,
        isSuperAdmin: <?= $isSuperAdmin ? 'true' : 'false' ?>,
        currentUserId: <?= (int)$userId ?>,

        // Kiosk display (owner-only tab)
        kioskForm: {
            enabled: <?= $kioskEnabled ? 'true' : 'false' ?>,
            mode: '<?= e($kioskMode) ?>',
            days: <?= (int)$kioskDays ?>,
            interval: <?= (int)$kioskInterval ?>
        },
        kioskSaving: false,
        kioskSaved: false,
        kioskCopied: false,

        // Forms
        orgForm: {
            name: '',
            logo_url: '',
            primary_color: '#3B82F6',
            timezone: 'America/Indiana/Indianapolis',
            coordinators_can_refund: true,
            coordinators_can_correct_checkins: false,
            refund_request_days_after_event: null,
            rsvp_waiver_enabled: true,
            rsvp_waiver_checkbox_label: 'I agree to the liability waiver and release',
            rsvp_waiver_full_text: ''
        },
        
        stripeForm: {
            publishable_key: '',
            secret_key: ''
        },
        
        emailForm: {
            api_key: '',
            from_email: '',
            from_name: ''
        },
        
        categoryForm: {
            id: null,
            name: ''
        },

        editUserForm: {
            id: null,
            first_name: '',
            last_name: '',
            email: '',
            role: 'admin',
            is_super_admin: 0
        },
        
        adminForm: {
            first_name: '',
            last_name: '',
            email: '',
            password: ''
        },
        
        coordinatorForm: {
            first_name: '',
            last_name: '',
            email: '',
            password: ''
        },
        
        passwordForm: {
            current: '',
            new: '',
            confirm: ''
        },
        
        shortcodes: [
            '[headcount_events]',
            '[headcount_events limit="5"]',
            '[headcount_events category="youth"]',
            '[headcount_event id="123"]',
            '[headcount_events layout="grid"]'
        ],

        // Permissions tab state
        permLoaded: false,
        permLoading: false,
        permCatalog: [],
        permRoleDefaults: { admin: {}, coordinator: {} },
        permRoleOverrides: { admin: {}, coordinator: {} },
        permUsers: [],
        permUserOverrides: {},
        selectedUserId: '',
        permMsg: '',

        init() {
            // Load organization data
            this.loadOrganization();
            this.$watch('activeTab', (v) => { if (v === 'permissions' && !this.permLoaded) this.loadPermissions(); });
        },

        // ===== Permissions methods =====
        async loadPermissions() {
            this.permLoading = true;
            try {
                const r = await fetch(`${window.apiBaseUrl}/settings.php?action=get_permissions`, { credentials: 'same-origin' });
                const d = await r.json();
                if (d.success) {
                    this.permCatalog = d.catalog || [];
                    this.permRoleDefaults = d.role_defaults || { admin: {}, coordinator: {} };
                    this.permRoleOverrides = d.role_overrides || { admin: {}, coordinator: {} };
                    this.permUsers = d.users || [];
                    this.permUserOverrides = d.user_overrides || {};
                    this.permLoaded = true;
                } else {
                    alert(d.message || 'Failed to load permissions');
                }
            } catch (e) {
                alert('Failed to load permissions');
            } finally {
                this.permLoading = false;
            }
        },
        roleEffective(role, key) {
            const ov = this.permRoleOverrides[role] || {};
            if (Object.prototype.hasOwnProperty.call(ov, key)) return !!ov[key];
            const def = this.permRoleDefaults[role] || {};
            return !!def[key];
        },
        async toggleRole(role, key) {
            const newVal = !this.roleEffective(role, key);
            if (!this.permRoleOverrides[role]) this.permRoleOverrides[role] = {};
            this.permRoleOverrides[role][key] = newVal ? 1 : 0;
            const ok = await this.postPerm('update_role_permissions', { role, permissions: { [key]: newVal ? 1 : 0 } });
            if (!ok) { await this.loadPermissions(); }
        },
        selectedUser() {
            return this.permUsers.find((u) => String(u.id) === String(this.selectedUserId)) || null;
        },
        userOverrideState(key) {
            const ov = this.permUserOverrides[this.selectedUserId] || {};
            if (Object.prototype.hasOwnProperty.call(ov, key)) return ov[key] ? 'allow' : 'deny';
            return 'inherit';
        },
        userInheritedLabel(key) {
            const u = this.selectedUser();
            if (!u) return '';
            return this.roleEffective(u.role, key) ? 'Allowed' : 'Denied';
        },
        async setUserOverride(key, state) {
            const uid = this.selectedUserId;
            if (!uid) return;
            if (!this.permUserOverrides[uid]) this.permUserOverrides[uid] = {};
            if (state === 'inherit') { delete this.permUserOverrides[uid][key]; }
            else { this.permUserOverrides[uid][key] = (state === 'allow') ? 1 : 0; }
            const ok = await this.postPerm('update_user_permissions', { user_id: Number(uid), permissions: { [key]: state } });
            if (!ok) { await this.loadPermissions(); }
        },
        async postPerm(action, payload) {
            this.permMsg = '';
            try {
                const r = await fetch(`${window.apiBaseUrl}/settings.php?action=${action}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
                    body: JSON.stringify({ ...payload, csrf_token: window.csrfToken })
                });
                const d = await r.json();
                if (!d.success) { alert(d.message || 'Failed to save'); return false; }
                this.permMsg = 'Saved';
                setTimeout(() => { this.permMsg = ''; }, 1500);
                return true;
            } catch (e) {
                alert('An error occurred while saving');
                return false;
            }
        },

        // ===== Kiosk display methods =====
        copyKioskUrl() {
            const el = document.getElementById('kioskUrlInput');
            if (!el) return;
            const done = () => { this.kioskCopied = true; setTimeout(() => { this.kioskCopied = false; }, 1500); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(el.value).then(done).catch(() => { el.select(); document.execCommand('copy'); done(); });
            } else {
                el.select(); document.execCommand('copy'); done();
            }
        },
        async saveKiosk() {
            this.kioskSaving = true; this.kioskSaved = false;
            try {
                const r = await fetch(`${window.apiBaseUrl}/settings.php?action=update_kiosk`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
                    body: JSON.stringify({
                        enabled: this.kioskForm.enabled ? 1 : 0,
                        mode: this.kioskForm.mode,
                        days: Number(this.kioskForm.days) || 7,
                        interval: Number(this.kioskForm.interval) || 8,
                        csrf_token: window.csrfToken
                    })
                });
                const d = await r.json();
                if (!d.success) { alert(d.message || 'Failed to save kiosk settings'); return; }
                this.kioskSaved = true; setTimeout(() => { this.kioskSaved = false; }, 2000);
            } catch (e) {
                alert('An error occurred while saving kiosk settings');
            } finally {
                this.kioskSaving = false;
            }
        },

        async loadOrganization() {
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=get_organization`);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('API Error:', response.status, response.statusText, errorText);
                    try {
                        const errorData = JSON.parse(errorText);
                        console.error('Error details:', errorData);
                    } catch (e) {
                        console.error('Could not parse error response as JSON');
                    }
                    return;
                }
                
                const data = await response.json();
                if (data.success) {
                    const org = data.organization || {};
                    this.orgForm = { ...this.orgForm, ...org };
                    this.orgForm.coordinators_can_refund = org.coordinators_can_refund !== undefined ? !!org.coordinators_can_refund : true;
                    this.orgForm.coordinators_can_correct_checkins = org.coordinators_can_correct_checkins !== undefined ? !!org.coordinators_can_correct_checkins : false;
                    this.orgForm.refund_request_days_after_event = org.refund_request_days_after_event != null && org.refund_request_days_after_event !== '' ? org.refund_request_days_after_event : null;
                    this.orgForm.rsvp_waiver_enabled = org.rsvp_waiver_enabled !== undefined ? !!org.rsvp_waiver_enabled : true;
                    this.orgForm.rsvp_waiver_checkbox_label = org.rsvp_waiver_checkbox_label || 'I agree to the liability waiver and release';
                    this.orgForm.rsvp_waiver_full_text = org.rsvp_waiver_full_text || '';
                    this.stripeForm.publishable_key = org.stripe_publishable_key || '';
                    this.emailForm.api_key = org.smtp_api_key || '';
                    this.emailForm.from_email = org.smtp_from_email || '';
                    this.emailForm.from_name = org.smtp_from_name || '';
                } else {
                    console.error('Failed to load organization:', data.message);
                }
            } catch (error) {
                console.error('Error loading organization:', error);
            }
        },
        
        openOrganizationModal() {
            this.showOrgModal = true;
        },
        async uploadLogo(ev) {
            const file = ev.target.files && ev.target.files[0];
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                alert('File must be 2MB or less.');
                return;
            }
            const ext = (file.name || '').split('.').pop().toLowerCase();
            if (!['png', 'jpg', 'jpeg', 'svg'].includes(ext)) {
                alert('Allowed formats: PNG, JPG, SVG.');
                return;
            }
            const form = new FormData();
            form.append('logo', file);
            form.append('csrf_token', window.csrfToken);
            try {
                const res = await fetch(window.apiBaseUrl + '/settings.php?action=upload_logo', {
                    method: 'POST',
                    body: form
                });
                const data = await res.json();
                if (data.success) {
                    this.orgForm.logo_url = data.logo_url || data.logo_path;
                    ev.target.value = '';
                } else {
                    alert(data.message || 'Upload failed');
                }
            } catch (e) {
                alert('Upload failed');
            }
        },
        async removeLogo() {
            if (!confirm('Remove the organization logo?')) return;
            try {
                const res = await fetch(window.apiBaseUrl + '/settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'remove_logo', csrf_token: window.csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    this.orgForm.logo_url = '';
                } else {
                    alert(data.message || 'Failed');
                }
            } catch (e) {
                alert('Failed');
            }
        },
        
        openStripeModal() {
            this.showStripeModal = true;
        },
        
        openEmailModal() {
            this.showEmailModal = true;
        },
        
        openCategoryModal() {
            this.categoryForm = { id: null, name: '' };
            this.showCategoryModal = true;
        },

        openEditCategoryModal(category) {
            this.categoryForm = {
                id: category.id,
                name: category.name
            };
            this.showCategoryModal = true;
        },

        isRoleChangeDisabled() {
            return Number(this.editUserForm.id) === Number(this.currentUserId)
                || Number(this.editUserForm.is_super_admin) === 1;
        },

        setEditUserRole(role) {
            if (this.isRoleChangeDisabled()) return;
            this.editUserForm.role = role;
        },

        openEditUserModal(user) {
            this.editUserForm = {
                id: Number(user.id),
                first_name: user.first_name || '',
                last_name: user.last_name || '',
                email: user.email || '',
                role: user.role === 'coordinator' ? 'coordinator' : 'admin',
                is_super_admin: Number(user.is_super_admin) === 1 ? 1 : 0
            };
            this.showEditUserModal = true;
        },
        
        openAdminModal() {
            this.adminForm = {
                first_name: '',
                last_name: '',
                email: '',
                password: ''
            };
            this.showAdminModal = true;
        },
        
        openCoordinatorModal() {
            this.coordinatorForm = {
                first_name: '',
                last_name: '',
                email: '',
                password: ''
            };
            this.showCoordinatorModal = true;
        },
        
        openPasswordModal() {
            this.passwordForm = {
                current: '',
                new: '',
                confirm: ''
            };
            this.showPasswordModal = true;
        },
        
        async saveOrganization() {
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=update_organization`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.orgForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to save');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async saveStripe() {
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=update_stripe`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.stripeForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to save');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async saveEmail() {
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=update_email`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.emailForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to save');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async saveCategory() {
            this.saving = true;
            try {
                const action = this.categoryForm.id ? 'update_category' : 'add_category';
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=${action}`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.categoryForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to save category');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },

        async saveEditUser() {
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=update_team_member`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.editUserForm, csrf_token: window.csrfToken })
                });

                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to update user');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },

        async transferOwnership(userId) {
            const displayName = `${this.editUserForm.first_name} ${this.editUserForm.last_name}`.trim();
            const confirmed = await confirmAction({
                title: 'Transfer ownership',
                message: `Make ${displayName} the organization owner? You will lose owner privileges and they will have full access that cannot be restricted.`,
                type: 'warning',
                okText: 'Transfer ownership',
                cancelText: 'Cancel'
            });
            if (!confirmed) return;

            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=transfer_super_admin`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ user_id: userId, csrf_token: window.csrfToken })
                });

                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to transfer ownership');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async deleteCategory(id, name) {
            const confirmed = await confirmAction({
                title: 'Delete Category',
                message: `Are you sure you want to delete the category "${name}"? Events linked to this category will lose the association.`,
                type: 'danger',
                okText: 'Delete',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=delete_category`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ id, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to delete category');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        async saveAdmin() {
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=add_admin`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.adminForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to add admin');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async deleteAdmin(id, name) {
            const confirmed = await confirmAction({
                title: 'Remove Admin Access',
                message: `Are you sure you want to remove admin access for ${name}?`,
                type: 'warning',
                okText: 'Remove',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=delete_admin`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ id, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to remove admin');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        async saveCoordinator() {
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=add_coordinator`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.coordinatorForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to add coordinator');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async promoteCoordinatorToAdmin(id, displayName) {
            const confirmed = await confirmAction({
                title: 'Make administrator',
                message: `Make ${displayName} a full administrator? They will have access to organization settings, all events, members, and email tools (same as you).`,
                type: 'info',
                okText: 'Make admin',
                cancelText: 'Cancel'
            });
            if (!confirmed) return;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=promote_coordinator_to_admin`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ id, csrf_token: window.csrfToken })
                });
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to promote user');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },

        async deleteCoordinator(id, name) {
            const confirmed = await confirmAction({
                title: 'Remove Coordinator',
                message: `Are you sure you want to remove ${name} as a coordinator?`,
                type: 'warning',
                okText: 'Remove',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=delete_coordinator`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ id, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to remove coordinator');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        async changePassword() {
            if (this.passwordForm.new !== this.passwordForm.confirm) {
                alert('New passwords do not match');
                return;
            }
            
            this.saving = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=change_password`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ ...this.passwordForm, csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    alert('Password changed successfully');
                    this.showPasswordModal = false;
                    this.passwordForm = { current: '', new: '', confirm: '' };
                } else {
                    alert(data.message || 'Failed to change password');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        async sendTestEmail() {
            const confirmed = await confirmAction({
                title: 'Send Test Email',
                message: 'Send a test email to your address?',
                type: 'info',
                okText: 'Send',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=send_test_email`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    alert(data.message || 'Test email sent! Check your inbox.');
                } else {
                    alert(data.message || 'Failed to send test email');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        async downloadBackup() {
            window.location.href = `${window.apiBaseUrl}/settings.php?action=download_backup`;
        },
        
        async clearAllData() {
            const confirmed = await confirmAction({
                title: 'Clear All Data',
                message: 'WARNING: This will delete ALL events and attendance data. This action cannot be undone!',
                type: 'danger',
                okText: 'Delete All',
                cancelText: 'Cancel',
                requireInput: true,
                inputPlaceholder: 'Type DELETE to confirm'
            });
            
            if (!confirmed || confirmed !== 'DELETE') {
                return;
            }
            
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=clear_data`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    alert('All event data has been cleared');
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to clear data');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        generatingKey: false,
        
        async generateApiKey() {
            this.generatingKey = true;
            try {
                const response = await fetch(`${window.apiBaseUrl}/settings.php?action=generate_api_key`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': window.csrfToken
                    },
                    body: JSON.stringify({ csrf_token: window.csrfToken })
                });
                
                const data = await response.json();
                if (data.success) {
                    document.getElementById('apiKeyDisplay').textContent = data.api_key;
                    alert('API key generated successfully!');
                } else {
                    alert(data.message || 'Failed to generate API key');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            } finally {
                this.generatingKey = false;
            }
        },
        
        async copyShortcode(shortcode) {
            try {
                await navigator.clipboard.writeText(shortcode);
                // Show toast or alert
                if (typeof showToast === 'function') {
                    showToast('Shortcode copied to clipboard!', 'success');
                } else {
                    alert('Shortcode copied to clipboard!');
                }
            } catch (err) {
                console.error('Failed to copy:', err);
                // Fallback: create a temporary textarea
                const textarea = document.createElement('textarea');
                textarea.value = shortcode;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                try {
                    document.execCommand('copy');
                    alert('Shortcode copied to clipboard!');
                } catch (e) {
                    alert('Failed to copy. Please copy manually: ' + shortcode);
                }
                document.body.removeChild(textarea);
            }
        }
    };
}


</script>

<style>
    [x-cloak] { display: none !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
